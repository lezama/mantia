<?php
/**
 * REST API surface for write actions from the PWA / web.
 *
 * Current scope: one endpoint to upsert a prediction, authenticated by
 * the same view token the /me/<token>/ page already uses. The token IS
 * the credential — anyone with the link can edit, mirroring how the
 * WhatsApp bot trusts the sender_id without an extra passcode. Good
 * enough for a Mundial pool among friends; if Mantia ever ships as a
 * public product, layer a real auth step here.
 *
 * The write path goes through Mantia_Abilities::register_prediction so
 * we reuse the existing fan-out (one prediction lands in every penca
 * the user has in the match's competition) and the kickoff-window
 * validation that the bot already runs.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Rest {

	public const NAMESPACE_V1 = 'mantia/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/predictions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_save_prediction' ),
				// Token-based auth happens inside the callback so we can
				// return a structured error body. The route itself is open.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ): bool {
							return null === $v || '' === $v || ( is_string( $v ) && (bool) preg_match( '/^[a-f0-9]{16,64}$/i', $v ) );
						},
					),
					'match_id'   => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'home_score' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 0,
						'maximum'  => 20,
					),
					'away_score' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 0,
						'maximum'  => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/groups',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_create_group' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'group_name'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'competition_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/join',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_join_group' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'invite_code' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ): bool {
							return is_string( $v ) && '' !== Mantia_Repository::normalize_invite_code( $v );
						},
					),
				),
			)
		);
	}

	/**
	 * POST /wp-json/mantia/v1/predictions
	 *
	 * Body: { token, match_id, home_score, away_score }
	 * Returns: { ok, match, prediction, groups } on success;
	 *          400/403/404 with { code, message } on failure.
	 */
	public static function handle_save_prediction( WP_REST_Request $request ): WP_REST_Response {
		$token      = (string) $request->get_param( 'token' );
		$match_id   = (int) $request->get_param( 'match_id' );
		$home_score = (int) $request->get_param( 'home_score' );
		$away_score = (int) $request->get_param( 'away_score' );

		$current_id = (int) get_current_user_id();
		$user       = $current_id > 0 ? get_user_by( 'id', $current_id ) : null;
		if ( ! $user && '' !== $token ) {
			$user = Mantia_Repository::find_user_by_view_token( $token );
		}
		if ( ! $user ) {
			return self::error( 'invalid_token', __( 'Tu link privado ya no funciona. Pedile al bot uno nuevo.', 'mantia' ), 404 );
		}

		$phone = (string) get_user_meta( (int) $user->ID, Mantia_Repository::META_PHONE, true );
		if ( '' === $phone ) {
			return self::error( 'user_missing_phone', __( 'No puedo identificar el número del usuario.', 'mantia' ), 400 );
		}

		$match = Mantia_Repository::match_to_array( $match_id );
		if ( empty( $match ) ) {
			return self::error( 'match_not_found', __( 'No encuentro ese partido.', 'mantia' ), 404 );
		}

		// Delegate to the ability so we share the same fan-out, kickoff
		// validation, and "user is in at least one penca for this
		// competition" guard that the WhatsApp bot uses.
		$result = Mantia_Abilities::register_prediction(
			array(
				'user_phone'  => $phone,
				'match_id'    => $match_id,
				'first_team'  => $match['home_team'],
				'first_score' => $home_score,
				'second_team' => $match['away_team'],
				'second_score' => $away_score,
			)
		);

		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();
			// Map known ability codes to HTTP statuses so the JS can branch
			// on response.status instead of parsing string codes.
			$status = match ( $code ) {
				'mantia_match_closed'             => 403,
				'mantia_no_group_in_competition'  => 409,
				'mantia_match_ambiguous',
				'mantia_match_not_found'          => 404,
				default                           => 400,
			};
			return self::error( $code, (string) $result->get_error_message(), $status );
		}

		$groups = array_map(
			static fn ( array $g ): array => array(
				'id'   => (int) $g['id'],
				'name' => (string) $g['name'],
			),
			(array) ( $result['groups'] ?? array() )
		);

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'match'      => array(
					'id'        => (int) $match['id'],
					'home_team' => (string) $match['home_team'],
					'away_team' => (string) $match['away_team'],
				),
				'prediction' => array(
					'home_score' => $home_score,
					'away_score' => $away_score,
				),
				'groups'     => $groups,
			),
			200
		);
	}

	/**
	 * POST /wp-json/mantia/v1/groups
	 *
	 * Cookie-authenticated via the WhatsApp magic-link session. Creates a
	 * group, joins the current user, and returns web + WhatsApp share URLs.
	 */
	public static function handle_create_group( WP_REST_Request $request ): WP_REST_Response {
		$current = self::require_current_user();
		if ( $current instanceof WP_REST_Response ) {
			return $current;
		}

		$group_name = trim( (string) $request->get_param( 'group_name' ) );
		if ( '' === $group_name ) {
			return self::error( 'group_name_required', __( 'Poné un nombre para la penca.', 'mantia' ), 400 );
		}

		$competition_id = sanitize_title( (string) $request->get_param( 'competition_id' ) );
		$user           = get_userdata( $current );
		$result         = Mantia_Abilities::create_group(
			array(
				'group_name'     => $group_name,
				'competition_id' => $competition_id,
				'user_name'      => $user ? (string) $user->display_name : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::error( (string) $result->get_error_code(), (string) $result->get_error_message(), 400 );
		}

		$group = isset( $result['group'] ) && is_array( $result['group'] ) ? $result['group'] : array();
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'group' => self::group_response( $group, $current ),
			),
			200
		);
	}

	/**
	 * POST /wp-json/mantia/v1/join
	 *
	 * Cookie-authenticated via the WhatsApp magic-link session. Anonymous
	 * invite recipients use the wa.me handoff instead.
	 */
	public static function handle_join_group( WP_REST_Request $request ): WP_REST_Response {
		$current = self::require_current_user();
		if ( $current instanceof WP_REST_Response ) {
			return $current;
		}

		$invite_code = Mantia_Repository::normalize_invite_code( (string) $request->get_param( 'invite_code' ) );
		$user        = get_userdata( $current );
		$result      = Mantia_Abilities::join_group(
			array(
				'invite_code' => $invite_code,
				'user_name'   => $user ? (string) $user->display_name : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			$code   = (string) $result->get_error_code();
			$status = 'mantia_group_not_found' === $code ? 404 : 400;
			return self::error( $code, (string) $result->get_error_message(), $status );
		}

		$group = isset( $result['group'] ) && is_array( $result['group'] ) ? $result['group'] : array();
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'group' => self::group_response( $group, $current ),
			),
			200
		);
	}

	private static function require_current_user(): int|WP_REST_Response {
		$current = (int) get_current_user_id();
		if ( $current <= 0 ) {
			return self::error( 'auth_required', __( 'Entrá por WhatsApp para seguir.', 'mantia' ), 401 );
		}
		$phone = Mantia_Repository::normalize_phone(
			(string) get_user_meta( $current, Mantia_Repository::META_PHONE, true )
		);
		if ( '' === $phone ) {
			return self::error( 'phone_required', __( 'No encuentro el WhatsApp de esta sesión. Pedile un link nuevo al bot.', 'mantia' ), 403 );
		}
		return $current;
	}

	private static function group_response( array $group, int $current_user_id ): array {
		$group_id     = (int) ( $group['id'] ?? 0 );
		$name         = (string) ( $group['name'] ?? '' );
		$invite_code  = Mantia_Repository::normalize_invite_code( (string) ( $group['invite_code'] ?? '' ) );
		$join_url     = '' !== $invite_code ? home_url( '/pronostico/sumate/' . $invite_code . '/' ) : '';
		$share_text   = '' !== $join_url
			? sprintf( 'Sumate a %s: %s', $name, $join_url )
			: '';
		$view_url     = $group_id > 0 ? Mantia_Repository::group_view_url( $group_id, $current_user_id ) : '';

		return array(
			'id'                 => $group_id,
			'name'               => $name,
			'invite_code'        => $invite_code,
			'view_url'           => $view_url,
			'join_url'           => $join_url,
			'whatsapp_share_url' => '' !== $share_text ? 'https://wa.me/?text=' . rawurlencode( $share_text ) : '',
		);
	}

	private static function error( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
