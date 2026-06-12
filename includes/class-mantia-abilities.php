<?php
/**
 * Abilities registration.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Abilities {

	private const CATEGORY = 'mantia';

	public static function register(): void {
		if ( did_action( 'wp_abilities_api_categories_init' ) ) {
			self::register_category();
		} else {
			add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		}

		if ( did_action( 'wp_abilities_api_init' ) ) {
			self::register_abilities();
		} else {
			add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		}
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Mantia', 'mantia' ),
				'description' => __( 'Abilities for the World Cup prediction game.', 'mantia' ),
			)
		);
	}

	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_prediction_ability();
		self::register_standings_ability();
		self::register_upcoming_matches_ability();
		self::register_match_result_ability();
		self::register_user_history_ability();
		self::register_join_group_ability();
		self::register_create_group_ability();
		self::register_my_groups_ability();
		self::register_set_active_group_ability();
		self::register_whatsapp_home_ability();
		self::register_finished_matches_ability();
		self::register_resolve_match_ability();
		self::register_fetch_result_ability();
		self::register_sync_fifa_fixture_ability();
		self::register_score_prediction_ability();
		if ( Mantia_Whatsapp_Flow::outbound_workflows_enabled() ) {
			self::register_reminder_targets_ability();
			self::register_daily_digest_targets_ability();
		}
	}

	/**
	 * Default permission gate for every mantia ability.
	 *
	 * Mantia abilities are designed to be invoked from the agent runner
	 * during a WhatsApp turn — at that point openclaWP promotes the request
	 * to the configured WhatsApp service user (admin), so manage_options
	 * passes. Outside that path (anonymous REST callers, untrusted plugins,
	 * etc.) the abilities require admin until widened.
	 *
	 * Filterable per-ability via `mantia_ability_permission` so a downstream
	 * site that builds, e.g., a public web form on top of `mantia/get-standings`
	 * can grant `__return_true` to that specific ability without unlocking
	 * destructive ones.
	 *
	 * @return bool|WP_Error true to allow, false/WP_Error to deny.
	 */
	public static function rest_permission( $input = null ) {
		$ability_name = '';
		if ( is_array( $input ) && isset( $input['_mantia_ability'] ) ) {
			$ability_name = (string) $input['_mantia_ability'];
		}
		// 2026-06-12: workflows fired by wp-cron run with no current
		// user, so current_user_can('manage_options') is false and
		// the scheduled `mantia/resolve-matches` workflow couldn't even
		// reach its first ability call (`get-finished-unresolved-matches`).
		// 10+ failed runs piled up between 08:11–08:20 UTC, leaderboards
		// went stale, and the eventual fallback path surfaced
		// "Unexpected Anthropic API response: Missing the 'content' key"
		// to a real user (Pablo). Allow the call in known-trusted
		// system contexts: wp-cron and WP-CLI. REST + browser requests
		// without a logged-in admin still get denied as before.
		$is_system = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		$allowed   = current_user_can( 'manage_options' ) || $is_system;
		return (bool) apply_filters( 'mantia_ability_permission', $allowed, $ability_name, $input );
	}

	/**
	 * Guard against an LLM (or other untrusted caller) acting on behalf
	 * of a user that isn't the currently authenticated one. The WA bridge
	 * calls `wp_set_current_user( $user_id )` for the verified phone
	 * BEFORE the agent loop fires, so `get_current_user_id()` inside an
	 * ability is the truthful identity.
	 *
	 * Bypassed for:
	 *   - admins (workflows, REST with manage_options, CLI eval-file)
	 *   - unauthenticated context (cron, bootstrap) — current_user_id == 0
	 *   - abilities that didn't pass a `user_phone` in args
	 *
	 * Returns true on allow, WP_Error on deny.
	 */
	/**
	 * Resolve the caller's phone from args, OR fall back to the WP
	 * current_user's META_PHONE when args don't include it. The WA
	 * bridge wp_set_current_user()s the verified phone-owner before
	 * the agent loop fires, so the current_user is the truthful
	 * identity. An LLM that omits user_phone in a tool call should
	 * NOT make the bot ask the user for their number — we already
	 * have it. Returns the resolved phone (or '' for no-current-user
	 * contexts like cron / CLI).
	 */
	private static function resolve_caller_phone( array $args ): string {
		$arg_phone = (string) ( $args['user_phone'] ?? '' );
		if ( '' !== $arg_phone ) {
			return $arg_phone;
		}
		$current_id = (int) get_current_user_id();
		if ( $current_id <= 0 ) {
			return '';
		}
		return (string) get_user_meta( $current_id, Mantia_Repository::META_PHONE, true );
	}

	private static function check_caller_phone( array $args ) {
		if ( ! isset( $args['user_phone'] ) || '' === (string) $args['user_phone'] ) {
			return true;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$current_id = (int) get_current_user_id();
		if ( $current_id <= 0 ) {
			// The WA bridge is expected to wp_set_current_user() before the
			// agent loop touches an ability. If we see a phone arg here
			// without an authenticated user, the bridge likely regressed —
			// fire an action so a sentinel/log can pick it up. We still
			// allow the call (the cron / bootstrap path needs this branch),
			// but the signal lets us catch missing wiring before it ships.
			do_action( 'mantia_phone_guard_skipped_no_uid', $args );
			return true;
		}
		$current_phone = Mantia_Repository::normalize_phone(
			(string) get_user_meta( $current_id, Mantia_Repository::META_PHONE, true )
		);
		if ( '' === $current_phone ) {
			return true; // no phone bound on this user — not a WA-bridged session.
		}
		$arg_phone = Mantia_Repository::normalize_phone( (string) $args['user_phone'] );
		if ( $current_phone === $arg_phone ) {
			return true;
		}
		return new WP_Error(
			'mantia_phone_mismatch',
			__( 'No podés actuar en nombre de otro usuario.', 'mantia' )
		);
	}

	private static function register_prediction_ability(): void {
		wp_register_ability(
			'mantia/register-prediction',
			array(
				'label'               => __( 'Register prediction', 'mantia' ),
				'description'         => __( 'Save or update a user prediction for an upcoming match. If match_id is omitted, team is used to resolve the next scheduled match for that team.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_phone'          => array( 'type' => 'string' ),
						'user_name'           => array( 'type' => 'string' ),
						'whatsapp_recipient'  => array( 'type' => 'string' ),
						'match_id'            => array( 'type' => 'integer' ),
						'team'                => array( 'type' => 'string' ),
						'group_id'            => array( 'type' => 'integer' ),
						'home_score'          => array(
							'type' => 'integer',
							'minimum' => 0,
						),
						'away_score'          => array(
							'type' => 'integer',
							'minimum' => 0,
						),
						'first_team'          => array( 'type' => 'string' ),
						'first_score'         => array(
							'type' => 'integer',
							'minimum' => 0,
						),
						'second_team'         => array( 'type' => 'string' ),
						'second_score'        => array(
							'type' => 'integer',
							'minimum' => 0,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'prediction', 'match', 'group' ),
					'properties' => array(
						'prediction' => array( 'type' => 'object' ),
						'match'      => array( 'type' => 'object' ),
						'group'      => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'register_prediction' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'destructive' => true ),
				),
			)
		);
	}

	public static function register_prediction( array $args ): array|WP_Error {
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$args['user_phone'] = self::resolve_caller_phone( $args );
		$user_id = Mantia_Repository::get_or_create_user(
			$args['user_phone'],
			(string) ( $args['user_name'] ?? '' ),
			(string) ( $args['whatsapp_recipient'] ?? '' )
		);
		if ( 0 === $user_id ) {
			return new WP_Error( 'mantia_invalid_user', __( 'Necesito un telefono valido para guardar el pronostico.', 'mantia' ) );
		}

		$match_id = isset( $args['match_id'] ) ? (int) $args['match_id'] : 0;
		if ( 0 === $match_id && ! empty( $args['first_team'] ) && ! empty( $args['second_team'] ) ) {
			$match    = Mantia_Repository::find_next_match_between( (string) $args['first_team'], (string) $args['second_team'] );
			$match_id = (int) ( $match['id'] ?? 0 );
		}
		if ( 0 === $match_id && ! empty( $args['team'] ) ) {
			$match    = Mantia_Repository::find_next_match_for_team( (string) $args['team'] );
			$match_id = (int) ( $match['id'] ?? 0 );
		}
		if ( 0 === $match_id ) {
			return new WP_Error( 'mantia_match_ambiguous', __( 'Decime para que partido es el pronostico.', 'mantia' ) );
		}

		$match  = Mantia_Repository::match_to_array( $match_id );
		$scores = self::resolve_prediction_scores( $args, $match );
		if ( is_wp_error( $scores ) ) {
			return $scores;
		}

		// Auto-route: if the caller named a specific group_id we respect
		// it (LLM may want to target one penca). Otherwise we resolve the
		// match's competition and save the prediction to every group the
		// user has in that competition — the natural multi-penca behavior
		// people expect when they have e.g. a family and an office penca
		// for the same tournament.
		//
		// Security: when group_id is explicit, verify the user is actually
		// a member of that group. Without this check a prompt-injected LLM
		// (or any other untrusted caller) could write predictions into any
		// group_id it can guess — classic IDOR.
		$group_ids = array();
		if ( isset( $args['group_id'] ) && (int) $args['group_id'] > 0 ) {
			$target_gid    = (int) $args['group_id'];
			$user_group_ids = array_map(
				'intval',
				(array) get_user_meta( $user_id, Mantia_Repository::META_GROUP_IDS, true )
			);
			if ( ! in_array( $target_gid, $user_group_ids, true ) ) {
				return new WP_Error(
					'mantia_not_a_member',
					__( 'No podés guardar pronósticos en una penca de la que no sos miembro.', 'mantia' )
				);
			}
			$group_ids = array( $target_gid );
		} else {
			$match_comp = (string) ( $match['competition_id'] ?? '' );
			if ( '' !== $match_comp ) {
				$group_ids = Mantia_Repository::user_groups_in_competition( $user_id, $match_comp );
			}
		}

		if ( empty( $group_ids ) ) {
			$match_comp = (string) ( $match['competition_id'] ?? '' );
			$comp       = '' !== $match_comp ? Mantia_Competitions::get( $match_comp ) : null;
			$comp_name  = $comp ? trim( ( $comp['emoji'] ?? '' ) . ' ' . $comp['name'] ) : __( 'esa competencia', 'mantia' );
			$create_arg = $comp ? $comp['name'] : '';
			$phone      = (string) ( $args['user_phone'] ?? '' );
			$noun       = Mantia_Vocab::word( 'noun', $phone );
			$create     = Mantia_Vocab::word( 'create', $phone );
			return new WP_Error(
				'mantia_no_group_in_competition',
				sprintf(
					/* translators: 1: competition label with emoji, 2: command suggestion to create a group (e.g. "Crear pronóstico de Mundial 2026"), 3: noun for the prediction pool (penca/pronóstico/bolão). */
					__( 'Ese partido es de %1$s, pero no estás en ninguna %3$s de ese torneo. Mandame *%2$s de %4$s* y armamos una.', 'mantia' ),
					$comp_name,
					$create,
					$noun,
					$create_arg
				)
			);
		}

		$predictions = array();
		$groups      = array();
		$errors      = array();
		foreach ( $group_ids as $gid ) {
			$pred = Mantia_Repository::register_prediction(
				$user_id,
				$match_id,
				(int) $gid,
				(int) $scores['home_score'],
				(int) $scores['away_score']
			);
			if ( is_wp_error( $pred ) ) {
				$errors[] = $pred->get_error_message();
				continue;
			}
			$predictions[] = $pred;
			$groups[]      = Mantia_Repository::group_to_array( (int) $gid );
		}

		if ( empty( $predictions ) ) {
			return new WP_Error(
				'mantia_prediction_failed',
				! empty( $errors ) ? (string) $errors[0] : __( 'No pude guardar el pronóstico.', 'mantia' )
			);
		}

		return array(
			// Singular keys for backwards compat with existing LLM responses.
			'prediction'  => $predictions[0],
			'match'       => $match,
			'group'       => $groups[0],
			// Plural keys are the source of truth — every group the
			// prediction landed in, so the agent can name them all in the reply.
			'predictions' => $predictions,
			'groups'      => $groups,
		);
	}

	private static function resolve_prediction_scores( array $args, array $match ): array|WP_Error {
		if ( isset( $args['first_team'], $args['first_score'], $args['second_team'], $args['second_score'] ) ) {
			$first  = Mantia_Repository::team_key_for_match( (string) $args['first_team'] );
			$second = Mantia_Repository::team_key_for_match( (string) $args['second_team'] );
			$home   = Mantia_Repository::team_key_for_match( (string) ( $match['home_team'] ?? '' ) );
			$away   = Mantia_Repository::team_key_for_match( (string) ( $match['away_team'] ?? '' ) );

			if ( $first === $home && $second === $away ) {
				return array(
					'home_score' => max( 0, (int) $args['first_score'] ),
					'away_score' => max( 0, (int) $args['second_score'] ),
				);
			}
			if ( $first === $away && $second === $home ) {
				return array(
					'home_score' => max( 0, (int) $args['second_score'] ),
					'away_score' => max( 0, (int) $args['first_score'] ),
				);
			}
		}

		if ( isset( $args['home_score'], $args['away_score'] ) ) {
			return array(
				'home_score' => max( 0, (int) $args['home_score'] ),
				'away_score' => max( 0, (int) $args['away_score'] ),
			);
		}

		return new WP_Error( 'mantia_prediction_scores_missing', __( 'Necesito el marcador del pronostico.', 'mantia' ) );
	}

	private static function register_standings_ability(): void {
		wp_register_ability(
			'mantia/get-standings',
			array(
				'label'               => __( 'Get standings', 'mantia' ),
				'description'         => __( 'Return the current leaderboard globally or for a group.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'group_id'    => array( 'type' => 'integer' ),
						'user_phone'  => array( 'type' => 'string' ),
						'scope'       => array(
							'type' => 'string',
							'enum' => array( 'global', 'group' ),
						),
						'limit'       => array(
							'type' => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_standings' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	public static function get_standings( array $args ): array {
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			return array( 'group_id' => 0, 'scope' => 'group', 'needs_group' => true, 'standings' => array(), 'error' => $gate->get_error_code() );
		}
		$args['user_phone'] = self::resolve_caller_phone( $args );
		$scope    = (string) ( $args['scope'] ?? 'group' );
		$group_id = isset( $args['group_id'] ) ? (int) $args['group_id'] : 0;

		if ( 'global' !== $scope && 0 === $group_id && ! empty( $args['user_phone'] ) ) {
			$user = Mantia_Repository::find_user_by_phone( (string) $args['user_phone'] );
			if ( $user ) {
				$group_id = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
			}
		}

		$limit = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 10;
		if ( 'global' !== $scope && $group_id <= 0 ) {
			return array(
				'group_id'    => 0,
				'scope'       => 'group',
				'needs_group' => true,
				'standings'   => array(),
			);
		}

		return array(
			'group_id'  => 'global' === $scope ? 0 : $group_id,
			'scope'     => 'global' === $scope ? 'global' : 'group',
			'standings' => Mantia_Leaderboard::rows( 'global' === $scope ? 0 : $group_id, $limit ),
		);
	}

	private static function register_upcoming_matches_ability(): void {
		wp_register_ability(
			'mantia/get-upcoming-matches',
			array(
				'label'               => __( 'Get upcoming matches', 'mantia' ),
				'description'         => __( 'Return upcoming matches, marking whether the user has already predicted each one.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_phone'  => array( 'type' => 'string' ),
						'hours_ahead' => array(
							'type' => 'integer',
							'minimum' => 1,
							'maximum' => 240,
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_upcoming_matches' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	public static function get_upcoming_matches( array $args ): array {
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			// Strip user_phone so we still return the public upcoming list
			// (it's broadcast info) but without per-user `has_prediction`
			// flags that would leak the other user's state.
			unset( $args['user_phone'] );
		}
		$args['user_phone'] = self::resolve_caller_phone( $args );
		$hours  = isset( $args['hours_ahead'] ) ? max( 1, min( 240, (int) $args['hours_ahead'] ) ) : 48;
		$user   = ! empty( $args['user_phone'] ) ? Mantia_Repository::find_user_by_phone( (string) $args['user_phone'] ) : null;
		$group  = $user ? Mantia_Repository::active_group_id_for_user( (int) $user->ID ) : 0;
		$matches = Mantia_Repository::upcoming_matches( $hours );

		foreach ( $matches as &$match ) {
			$match['has_prediction'] = $user ? (bool) Mantia_Repository::find_prediction( (int) $user->ID, (int) $match['id'], $group ) : false;
		}

		return array( 'matches' => $matches );
	}

	private static function register_match_result_ability(): void {
		wp_register_ability(
			'mantia/get-match-result',
			array(
				'label'               => __( 'Get match result', 'mantia' ),
				'description'         => __( 'Return match details and final result when available.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'match_id' ),
					'properties' => array( 'match_id' => array( 'type' => 'integer' ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn( array $args ): array => array( 'match' => Mantia_Repository::match_to_array( (int) ( $args['match_id'] ?? 0 ) ) ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	private static function register_user_history_ability(): void {
		wp_register_ability(
			'mantia/get-user-history',
			array(
				'label'               => __( 'Get user history', 'mantia' ),
				'description'         => __( 'Return a user prediction history with scores.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_phone' => array( 'type' => 'string' ),
						'group_id'   => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_user_history' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	/**
	 * Wrapper for the join-group repository call so the phone-binding
	 * guard runs before mutation. Without this, a prompt-injected agent
	 * could call mantia/join-group with someone else's phone and force
	 * them into an unwanted group.
	 */
	public static function join_group( array $args ): array|WP_Error {
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$args['user_phone'] = self::resolve_caller_phone( $args );
		return Mantia_Repository::join_group(
			$args['user_phone'],
			(string) ( $args['invite_code'] ?? '' ),
			(string) ( $args['user_name'] ?? '' ),
			(string) ( $args['whatsapp_recipient'] ?? '' )
		);
	}

	public static function get_user_history( array $args ): array|WP_Error {
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$phone = self::resolve_caller_phone( $args );
		$user  = Mantia_Repository::find_user_by_phone( $phone );
		if ( ! $user ) {
			return new WP_Error(
				'mantia_user_not_found',
				sprintf(
					/* translators: %s: vocab noun for the prediction pool (penca/pronóstico/bolão). */
					__( 'No encuentro ese usuario en %1$s %2$s.', 'mantia' ),
					Mantia_Vocab::word( 'article', $phone ),
					Mantia_Vocab::word( 'noun', $phone )
				)
			);
		}

		$group_id = isset( $args['group_id'] ) ? (int) $args['group_id'] : Mantia_Repository::active_group_id_for_user( (int) $user->ID );

		return array(
			'user_id' => (int) $user->ID,
			'history' => Mantia_Repository::user_history( (int) $user->ID, $group_id ),
		);
	}

	private static function register_join_group_ability(): void {
		wp_register_ability(
			'mantia/join-group',
			array(
				'label'               => __( 'Join group', 'mantia' ),
				'description'         => __( 'Join a user to a private prediction group by invite code.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'invite_code' ),
					'properties' => array(
						'user_phone'         => array( 'type' => 'string' ),
						'user_name'          => array( 'type' => 'string' ),
						'whatsapp_recipient' => array( 'type' => 'string' ),
						'invite_code'        => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'join_group' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'destructive' => true ),
				),
			)
		);
	}

	private static function register_create_group_ability(): void {
		$competition_ids = array_keys( Mantia_Competitions::all() );

		$competition_schema = array(
			'type'        => 'string',
			'description' => __( 'Competition slug (e.g. mundial-2026, libertadores-2026, libertadores-semana, sudamericana-2026, liga-uy-2026). Defaults to the configured default competition when omitted.', 'mantia' ),
		);
		if ( ! empty( $competition_ids ) ) {
			$competition_schema['enum'] = $competition_ids;
		}

		wp_register_ability(
			'mantia/create-group',
			array(
				'label'               => __( 'Create group', 'mantia' ),
				'description'         => __( 'Create a private prediction group and return the WhatsApp invite code/message. Pass competition_id to scope the group to a specific tournament; omit to use the default competition.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'group_name' ),
					'properties' => array(
						'user_phone'         => array( 'type' => 'string' ),
						'user_name'          => array( 'type' => 'string' ),
						'whatsapp_recipient' => array( 'type' => 'string' ),
						'group_name'         => array( 'type' => 'string' ),
						'invite_code'        => array( 'type' => 'string' ),
						'competition_id'     => $competition_schema,
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'create_group' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'destructive' => true ),
				),
			)
		);
	}

	public static function create_group( array $args ): array|WP_Error {
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$phone              = self::resolve_caller_phone( $args );
		$args['user_phone'] = $phone;
		$user_id = Mantia_Repository::get_or_create_user(
			$phone,
			(string) ( $args['user_name'] ?? '' ),
			(string) ( $args['whatsapp_recipient'] ?? '' )
		);
		if ( 0 === $user_id ) {
			return new WP_Error(
				'mantia_invalid_user',
				sprintf(
					/* translators: 1: definite article matching noun gender, 2: vocab noun (penca/pronóstico/bolão). */
					__( 'Necesito tu telefono de WhatsApp para crear %1$s %2$s.', 'mantia' ),
					Mantia_Vocab::word( 'article', $phone ),
					Mantia_Vocab::word( 'noun', $phone )
				)
			);
		}

		$group_name = sanitize_text_field( (string) ( $args['group_name'] ?? '' ) );
		if ( '' === $group_name ) {
			return new WP_Error(
				'mantia_group_name_required',
				sprintf(
					/* translators: 1: definite article matching noun gender, 2: vocab noun (penca/pronóstico/bolão). */
					__( 'Decime como se llama %1$s %2$s.', 'mantia' ),
					Mantia_Vocab::word( 'article', $phone ),
					Mantia_Vocab::word( 'noun', $phone )
				)
			);
		}

		$competition_id = sanitize_title( (string) ( $args['competition_id'] ?? '' ) );
		if ( '' !== $competition_id && ! Mantia_Competitions::get( $competition_id ) ) {
			return new WP_Error(
				'mantia_competition_unknown',
				sprintf(
					/* translators: %s: competition slug provided by the caller. */
					__( 'No conozco la competencia "%s". Probá con mundial-2026, libertadores-2026, sudamericana-2026 o liga-uy-2026.', 'mantia' ),
					$competition_id
				)
			);
		}

		$group_id = Mantia_Repository::create_group(
			$group_name,
			(string) ( $args['invite_code'] ?? '' ),
			'',
			$competition_id
		);
		if ( $group_id <= 0 ) {
			return new WP_Error(
				'mantia_group_create_failed',
				sprintf(
					/* translators: 1: indefinite article matching noun gender, 2: vocab noun (penca/pronóstico/bolão). */
					__( 'No pude crear es%1$s %2$s.', 'mantia' ),
					// "esa" for feminine (penca), "ese" for masculine (pronóstico/bolão).
					'a' === substr( Mantia_Vocab::word( 'article_indef', $phone ), -1 ) ? 'a' : 'e',
					Mantia_Vocab::word( 'noun', $phone )
				)
			);
		}

		$group = Mantia_Repository::group_to_array( $group_id );
		Mantia_Repository::join_group(
			(string) ( $args['user_phone'] ?? '' ),
			(string) $group['invite_code'],
			(string) ( $args['user_name'] ?? '' ),
			(string) ( $args['whatsapp_recipient'] ?? '' )
		);

		return array(
			'user_id'        => $user_id,
			'group'          => $group,
			'invite_code'    => (string) $group['invite_code'],
			'invite_message' => Mantia_Repository::group_invite_message( $group_id ),
		);
	}

	private static function register_my_groups_ability(): void {
		wp_register_ability(
			'mantia/get-my-groups',
			array(
				'label'               => __( 'Get my groups', 'mantia' ),
				'description'         => __( 'Return the prediction groups joined by the current WhatsApp user and the active group.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'user_phone' => array( 'type' => 'string' ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_my_groups' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	public static function get_my_groups( array $args ): array|WP_Error {
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$phone = self::resolve_caller_phone( $args );
		$user  = Mantia_Repository::find_user_by_phone( $phone );
		if ( ! $user ) {
			return new WP_Error(
				'mantia_user_not_found',
				sprintf(
					/* translators: 1: indefinite article (una/un/um), 2: vocab noun (penca/pronóstico/bolão). */
					__( 'Todavia no estas en ningun%1$s %2$s. Mandame un codigo de invitacion.', 'mantia' ),
					'a' === substr( Mantia_Vocab::word( 'article_indef', $phone ), -1 ) ? 'a' : '',
					Mantia_Vocab::word( 'noun', $phone )
				)
			);
		}

		$user_id = (int) $user->ID;

		return array(
			'user_id'         => $user_id,
			'active_group_id' => Mantia_Repository::active_group_id_for_user( $user_id ),
			'groups'          => Mantia_Repository::user_groups_to_array( $user_id ),
		);
	}

	private static function register_set_active_group_ability(): void {
		wp_register_ability(
			'mantia/set-active-group',
			array(
				'label'               => __( 'Set active group', 'mantia' ),
				'description'         => __( 'Switch the current WhatsApp user to one of their prediction groups. Passing an invite code joins/switches to that group.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_phone'         => array( 'type' => 'string' ),
						'user_name'          => array( 'type' => 'string' ),
						'whatsapp_recipient' => array( 'type' => 'string' ),
						'group_id'           => array( 'type' => 'integer' ),
						'invite_code'        => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'set_active_group' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'destructive' => true ),
				),
			)
		);
	}

	public static function set_active_group( array $args ): array|WP_Error {
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$args['user_phone'] = self::resolve_caller_phone( $args );
		if ( ! empty( $args['invite_code'] ) ) {
			return Mantia_Repository::join_group(
				(string) ( $args['user_phone'] ?? '' ),
				(string) $args['invite_code'],
				(string) ( $args['user_name'] ?? '' ),
				(string) ( $args['whatsapp_recipient'] ?? '' )
			);
		}

		$phone = (string) ( $args['user_phone'] ?? '' );
		$user  = Mantia_Repository::find_user_by_phone( $phone );
		if ( ! $user ) {
			return new WP_Error(
				'mantia_user_not_found',
				sprintf(
					/* translators: 1: indefinite article (una/un/um), 2: vocab noun (penca/pronóstico/bolão). */
					__( 'Todavia no estas en ningun%1$s %2$s. Mandame un codigo de invitacion.', 'mantia' ),
					'a' === substr( Mantia_Vocab::word( 'article_indef', $phone ), -1 ) ? 'a' : '',
					Mantia_Vocab::word( 'noun', $phone )
				)
			);
		}

		return Mantia_Repository::set_active_group_for_user( (int) $user->ID, (int) ( $args['group_id'] ?? 0 ) );
	}

	private static function register_whatsapp_home_ability(): void {
		wp_register_ability(
			'mantia/get-whatsapp-home',
			array(
				'label'               => __( 'Get WhatsApp home', 'mantia' ),
				'description'         => __( 'Return the user-initiated WhatsApp home view: active group, pending predictions, standings, and invite message.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_phone'  => array( 'type' => 'string' ),
						'hours_ahead' => array(
							'type' => 'integer',
							'minimum' => 1,
							'maximum' => 240,
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_whatsapp_home' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	public static function get_whatsapp_home( array $args ): array {
		$args['user_phone'] = $args['user_phone'] ?? self::resolve_caller_phone( $args );
		$gate = self::check_caller_phone( $args );
		if ( is_wp_error( $gate ) ) {
			// Return the SAME shape as the no-user / needs_group branch so
			// downstream array_access (in the bot composer / agent prompt)
			// doesn't crash on missing keys. The `error` key tells the
			// caller why state was wiped out.
			return array(
				'mode'             => 'user_initiated',
				'needs_group'      => true,
				'groups'           => array(),
				'active_group'     => null,
				'standings'        => array(),
				'upcoming'         => array(),
				'pending'          => array(),
				'outbound_enabled' => Mantia_Whatsapp_Flow::outbound_workflows_enabled(),
				'error'            => $gate->get_error_code(),
			);
		}
		$hours = isset( $args['hours_ahead'] ) ? max( 1, min( 240, (int) $args['hours_ahead'] ) ) : 48;
		$user  = Mantia_Repository::find_user_by_phone( (string) ( $args['user_phone'] ?? '' ) );
		if ( ! $user ) {
			return array(
				'mode'             => 'user_initiated',
				'needs_group'      => true,
				'groups'           => array(),
				'active_group'     => null,
				'standings'        => array(),
				'upcoming'         => Mantia_Repository::upcoming_matches( $hours ),
				'pending'          => array(),
				'outbound_enabled' => Mantia_Whatsapp_Flow::outbound_workflows_enabled(),
			);
		}

		$user_id        = (int) $user->ID;
		$active_group   = Mantia_Repository::active_group_id_for_user( $user_id );
		$upcoming       = Mantia_Repository::upcoming_matches( $hours );
		$pending        = array();
		foreach ( $upcoming as &$match ) {
			$has_prediction          = $active_group > 0 && Mantia_Repository::find_prediction( $user_id, (int) $match['id'], $active_group );
			$match['has_prediction'] = (bool) $has_prediction;
			if ( ! $has_prediction ) {
				$pending[] = $match;
			}
		}

		return array(
			'mode'             => 'user_initiated',
			'needs_group'      => $active_group <= 0,
			'groups'           => Mantia_Repository::user_groups_to_array( $user_id ),
			'active_group'     => $active_group > 0 ? Mantia_Repository::group_to_array( $active_group ) : null,
			'standings'        => $active_group > 0 ? Mantia_Leaderboard::rows( $active_group, 5 ) : array(),
			'upcoming'         => $upcoming,
			'pending'          => array_slice( $pending, 0, 10 ),
			'outbound_enabled' => Mantia_Whatsapp_Flow::outbound_workflows_enabled(),
		);
	}

	private static function register_finished_matches_ability(): void {
		wp_register_ability(
			'mantia/get-finished-unresolved-matches',
			array(
				'label'               => __( 'Get finished unresolved matches', 'mantia' ),
				'description'         => __( 'Return finished matches that still need prediction scoring.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn( array $args = array() ): array => array( 'matches' => Mantia_Repository::finished_unresolved_matches() ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	private static function register_resolve_match_ability(): void {
		wp_register_ability(
			'mantia/resolve-match',
			array(
				'label'               => __( 'Resolve match', 'mantia' ),
				'description'         => __( 'Score all predictions for a finished match and mark it resolved.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'match_id' ),
					'properties' => array( 'match_id' => array( 'type' => 'integer' ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'resolve_match' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'destructive' => true ),
				),
			)
		);
	}

	public static function resolve_match( array $args ): array|WP_Error {
		$match_id = (int) ( $args['match_id'] ?? 0 );
		$result   = Mantia_Results_Fetcher::fetch_match_result( $match_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Mantia_Repository::update_match_result(
			$match_id,
			(int) $result['home_score'],
			(int) $result['away_score'],
			(string) ( $result['status'] ?? 'finished' )
		);

		$scored = array();
		foreach ( Mantia_Repository::predictions_for_match( $match_id ) as $prediction ) {
			$scored[] = Mantia_Repository::score_prediction_post(
				(int) $prediction['id'],
				(int) $result['home_score'],
				(int) $result['away_score']
			);
		}

		Mantia_Repository::mark_match_resolved( $match_id );
		do_action( 'mantia_match_resolved', $match_id, $scored, $result );

		return array(
			'match_id' => $match_id,
			'result'   => $result,
			'scored'   => $scored,
			'count'    => count( $scored ),
		);
	}

	private static function register_sync_fifa_fixture_ability(): void {
		wp_register_ability(
			'mantia/sync-fifa-fixture',
			array(
				'label'               => __( 'Sync FIFA fixture', 'mantia' ),
				'description'         => __( 'Pull the upcoming match calendar for a FIFA competition (default: mundial-2026) from the official FIFA endpoint and upsert each match into the fixture.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'competition_id' => array( 'type' => 'string' ),
						'days_ahead'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 730,
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn( array $args ): array|WP_Error => Mantia_Fifa_Fixture::sync(
					(string) ( $args['competition_id'] ?? 'mundial-2026' ),
					isset( $args['days_ahead'] ) ? (int) $args['days_ahead'] : 365
				),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'destructive' => true ),
				),
			)
		);
	}

	private static function register_fetch_result_ability(): void {
		wp_register_ability(
			'mantia/fetch-fifa-result',
			array(
				'label'               => __( 'Fetch match result', 'mantia' ),
				'description'         => __( 'Fetch or read a final match result from the configured results source.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'match_id' ),
					'properties' => array( 'match_id' => array( 'type' => 'integer' ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn( array $args ) => Mantia_Results_Fetcher::fetch_match_result( (int) ( $args['match_id'] ?? 0 ) ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	private static function register_score_prediction_ability(): void {
		wp_register_ability(
			'mantia/score-prediction',
			array(
				'label'               => __( 'Score prediction', 'mantia' ),
				'description'         => __( 'Score one predicted result against a real result using configured scoring rules.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'predicted_home', 'predicted_away', 'real_home', 'real_away' ),
					'properties' => array(
						'predicted_home' => array( 'type' => 'integer' ),
						'predicted_away' => array( 'type' => 'integer' ),
						'real_home'      => array( 'type' => 'integer' ),
						'real_away'      => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static fn( array $args ): array => Mantia_Scoring::score_prediction(
					(int) $args['predicted_home'],
					(int) $args['predicted_away'],
					(int) $args['real_home'],
					(int) $args['real_away']
				),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	private static function register_reminder_targets_ability(): void {
		wp_register_ability(
			'mantia/get-match-reminder-targets',
			array(
				'label'               => __( 'Get match reminder targets', 'mantia' ),
				'description'         => __( 'Return users who need a WhatsApp reminder before upcoming matches.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'hours_ahead' => array( 'type' => 'integer' ) ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_match_reminder_targets' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	public static function get_match_reminder_targets( array $args ): array {
		$hours   = isset( $args['hours_ahead'] ) ? max( 1, (int) $args['hours_ahead'] ) : 2;
		$targets = array();

		foreach ( Mantia_Repository::upcoming_matches( $hours ) as $match ) {
			foreach ( Mantia_Repository::users_missing_prediction_for_match( (int) $match['id'] ) as $user ) {
				$recipient = '' !== $user['recipient'] ? $user['recipient'] : $user['phone'];
				if ( '' === $recipient ) {
					continue;
				}

				$targets[] = array(
					'user_id'    => (int) $user['user_id'],
					'match_id'   => (int) $match['id'],
					'recipient'  => $recipient,
					'dedupe_key' => 'mantia_reminder_' . md5( $match['id'] . ':' . $user['user_id'] ),
					'message'    => sprintf(
						'Falta poco para %s vs %s. Mandame tu pronostico antes de que arranque.',
						$match['home_team'],
						$match['away_team']
					),
				);
			}
		}

		return array( 'targets' => $targets );
	}

	private static function register_daily_digest_targets_ability(): void {
		wp_register_ability(
			'mantia/get-daily-digest-targets',
			array(
				'label'               => __( 'Get daily digest targets', 'mantia' ),
				'description'         => __( 'Return WhatsApp recipients and digest messages for the daily prediction-pool summary.', 'mantia' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'get_daily_digest_targets' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations' => array( 'readonly' => true ),
				),
			)
		);
	}

	public static function get_daily_digest_targets( array $args = array() ): array {
		unset( $args );
		$targets = array();
		$users   = get_users( array(
			'role'   => WA_Identity_Bridge::role_slug(),
			'number' => -1,
			'fields' => 'all',
		) );

		foreach ( $users as $user ) {
			$user_id   = (int) $user->ID;
			$recipient = (string) get_user_meta( $user_id, Mantia_Repository::META_WHATSAPP_RECIPIENT, true );
			if ( '' === $recipient ) {
				$recipient = (string) get_user_meta( $user_id, Mantia_Repository::META_PHONE, true );
			}
			if ( '' === $recipient ) {
				continue;
			}

			$group_id  = Mantia_Repository::active_group_id_for_user( $user_id );
			if ( $group_id <= 0 ) {
				continue;
			}
			$standings = Mantia_Leaderboard::rows( $group_id, 3 );
			$upcoming  = Mantia_Repository::upcoming_matches( 24 );

			$user_phone = (string) get_user_meta( $user_id, Mantia_Repository::META_PHONE, true );
			$lines      = array( sprintf(
				/* translators: 1: definite article matching noun gender, 2: vocab noun (penca/pronóstico/bolão). */
				__( 'Resumen de %1$s %2$s de hoy:', 'mantia' ),
				Mantia_Vocab::word( 'article', $user_phone ),
				Mantia_Vocab::word( 'noun', $user_phone )
			) );
			if ( ! empty( $standings ) ) {
				$lines[] = 'Top 3: ' . implode(
					', ',
					array_map(
						static fn( array $row ): string => sprintf( '%s %d pts', $row['name'], $row['points'] ),
						$standings
					)
				);
			}
			if ( ! empty( $upcoming ) ) {
				$first   = $upcoming[0];
				$lines[] = sprintf( 'Proximo partido: %s vs %s.', $first['home_team'], $first['away_team'] );
			}

			$targets[] = array(
				'user_id'    => $user_id,
				'recipient'  => $recipient,
				'dedupe_key' => 'mantia_digest_' . gmdate( 'Ymd' ) . '_' . $user_id,
				'message'    => implode( "\n", $lines ),
			);
		}

		return array( 'targets' => $targets );
	}
}
