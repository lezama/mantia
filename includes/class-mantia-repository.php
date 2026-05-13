<?php
/**
 * CPT-backed data access.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Repository {

	public const META_PHONE              = '_mantia_phone';
	public const META_WHATSAPP_RECIPIENT = '_mantia_whatsapp_recipient';
	public const META_GROUP_IDS          = '_mantia_group_ids';
	public const META_ACTIVE_GROUP_ID    = '_mantia_active_group_id';
	public const META_INVITE_CODE        = '_mantia_invite_code';
	public const META_GROUP_SLUG         = '_mantia_group_slug';
	public const META_EXTERNAL_ID        = '_mantia_external_id';
	public const META_HOME_TEAM          = '_mantia_home_team';
	public const META_AWAY_TEAM          = '_mantia_away_team';
	public const META_KICKOFF_GMT        = '_mantia_kickoff_gmt';
	public const META_KICKOFF_TS         = '_mantia_kickoff_ts';
	public const META_PHASE              = '_mantia_phase';
	public const META_STATUS             = '_mantia_status';
	public const META_HOME_SCORE         = '_mantia_home_score';
	public const META_AWAY_SCORE         = '_mantia_away_score';
	public const META_RESOLVED           = '_mantia_resolved';
	public const META_USER_ID            = '_mantia_user_id';
	public const META_USER_PHONE         = '_mantia_user_phone';
	public const META_MATCH_ID           = '_mantia_match_id';
	public const META_GROUP_ID           = '_mantia_group_id';
	public const META_PRED_HOME_SCORE    = '_mantia_predicted_home_score';
	public const META_PRED_AWAY_SCORE    = '_mantia_predicted_away_score';
	public const META_POINTS             = '_mantia_points';
	public const META_SCORING_REASON     = '_mantia_scoring_reason';
	public const META_SCORED             = '_mantia_scored';

	public static function normalize_phone( string $phone ): string {
		if ( str_contains( $phone, '@' ) ) {
			$phone = preg_replace( '/^([^:@]+):\d+(@.*)$/', '$1$2', strtolower( $phone ) );
			$phone = substr( (string) $phone, 0, (int) strpos( (string) $phone, '@' ) );
		}

		return preg_replace( '/\D+/', '', $phone ) ?: '';
	}

	public static function normalize_invite_code( string $invite_code ): string {
		$invite_code = function_exists( 'remove_accents' ) ? remove_accents( $invite_code ) : $invite_code;
		$invite_code = strtoupper( (string) $invite_code );
		$invite_code = preg_replace( '/[^A-Z0-9_-]+/', '', $invite_code );

		return substr( (string) $invite_code, 0, 32 );
	}

	public static function get_or_create_user( string $phone, string $name = '', string $recipient = '' ): int {
		$normalized = self::normalize_phone( $phone );
		if ( '' === $normalized ) {
			return 0;
		}

		$existing = self::find_user_by_phone( $normalized );
		if ( $existing ) {
			if ( '' !== $name ) {
				wp_update_post(
					array(
						'ID'         => $existing->ID,
						'post_title' => sanitize_text_field( $name ),
					)
				);
			}
			if ( '' !== $recipient ) {
				update_post_meta( $existing->ID, self::META_WHATSAPP_RECIPIENT, sanitize_text_field( $recipient ) );
			}
			return (int) $existing->ID;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Mantia_CPTs::USER,
				'post_status' => 'publish',
				'post_title'  => '' !== $name ? sanitize_text_field( $name ) : $normalized,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		update_post_meta( (int) $post_id, self::META_PHONE, $normalized );
		if ( '' !== $recipient ) {
			update_post_meta( (int) $post_id, self::META_WHATSAPP_RECIPIENT, sanitize_text_field( $recipient ) );
		}

		return (int) $post_id;
	}

	public static function find_user_by_phone( string $phone ): ?WP_Post {
		$normalized = self::normalize_phone( $phone );
		if ( '' === $normalized ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::USER,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_PHONE,
				'meta_value'     => $normalized,
			)
		);

		return $posts[0] ?? null;
	}

	public static function create_group( string $name, string $invite_code = '', string $slug = '', string $competition_id = '' ): int {
		$name        = sanitize_text_field( $name );
		$invite_code = '' !== $invite_code ? self::normalize_invite_code( $invite_code ) : self::generate_invite_code( $name );
		$existing    = self::find_group_by_invite_code( $invite_code );
		if ( $existing ) {
			return (int) $existing->ID;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => Mantia_CPTs::GROUP,
				'post_status' => 'publish',
				'post_title'  => $name,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$competition = '' !== $competition_id && Mantia_Competitions::get( $competition_id )
			? $competition_id
			: Mantia_Competitions::default_id();

		update_post_meta( (int) $post_id, self::META_INVITE_CODE, $invite_code );
		update_post_meta( (int) $post_id, self::META_GROUP_SLUG, '' !== $slug ? sanitize_title( $slug ) : sanitize_title( $name ) );
		update_post_meta( (int) $post_id, Mantia_Competitions::META_KEY, $competition );

		return (int) $post_id;
	}

	public static function group_competition_id( int $group_id ): string {
		$id = (string) get_post_meta( $group_id, Mantia_Competitions::META_KEY, true );
		return '' !== $id ? $id : Mantia_Competitions::default_id();
	}

	public const META_GROUP_VIEW_TOKEN = '_mantia_group_view_token';
	public const META_USER_VIEW_TOKEN  = '_mantia_user_view_token';

	public static function group_view_token( int $group_id ): string {
		$token = (string) get_post_meta( $group_id, self::META_GROUP_VIEW_TOKEN, true );
		if ( '' === $token ) {
			$token = bin2hex( random_bytes( 12 ) );
			update_post_meta( $group_id, self::META_GROUP_VIEW_TOKEN, $token );
		}
		return $token;
	}

	public static function find_group_by_view_token( string $token ): ?WP_Post {
		$token = preg_replace( '/[^a-f0-9]/i', '', $token );
		if ( strlen( $token ) < 16 ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::GROUP,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_GROUP_VIEW_TOKEN,
				'meta_value'     => $token,
			)
		);
		return $posts[0] ?? null;
	}

	public static function user_view_token( int $user_id ): string {
		$token = (string) get_post_meta( $user_id, self::META_USER_VIEW_TOKEN, true );
		if ( '' === $token ) {
			$token = bin2hex( random_bytes( 12 ) );
			update_post_meta( $user_id, self::META_USER_VIEW_TOKEN, $token );
		}
		return $token;
	}

	public static function find_user_by_view_token( string $token ): ?WP_Post {
		$token = preg_replace( '/[^a-f0-9]/i', '', $token );
		if ( strlen( $token ) < 16 ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::USER,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_USER_VIEW_TOKEN,
				'meta_value'     => $token,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * Cross-group leaderboard for a competition: aggregates points per (user, group)
	 * pair, returned sorted desc. Drives the public /penca/<competition> view.
	 */
	public static function competition_leaderboard( string $competition_id, int $limit = 50 ): array {
		$storage_id = Mantia_Competitions::storage_id( $competition_id );
		$group_ids  = self::groups_in_competition( $storage_id );
		if ( empty( $group_ids ) ) {
			return array();
		}
		$rows = array();
		foreach ( $group_ids as $gid ) {
			foreach ( Mantia_Leaderboard::rows( $gid, 100 ) as $r ) {
				$rows[] = array_merge( $r, array(
					'group_id'   => $gid,
					'group_name' => get_the_title( $gid ),
				) );
			}
		}
		usort(
			$rows,
			static fn ( array $a, array $b ): int => ( $b['points'] <=> $a['points'] )
				?: ( $b['exacts'] <=> $a['exacts'] )
				?: strcasecmp( $a['name'], $b['name'] )
		);
		$out = array_slice( $rows, 0, max( 1, $limit ) );
		foreach ( $out as $i => &$row ) {
			$row['rank'] = $i + 1;
		}
		return $out;
	}

	public static function groups_in_competition( string $competition_id ): array {
		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::GROUP,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => Mantia_Competitions::META_KEY,
				'meta_value'     => $competition_id,
			)
		);
		return array_map( static fn ( WP_Post $p ): int => (int) $p->ID, $posts );
	}

	public static function set_group_competition( int $group_id, string $competition_id ): bool {
		if ( ! Mantia_Competitions::get( $competition_id ) ) {
			return false;
		}
		update_post_meta( $group_id, Mantia_Competitions::META_KEY, $competition_id );
		return true;
	}

	/**
	 * Return the user's group IDs that belong to a given competition. A
	 * "match" here is at storage_id level: a group in libertadores-semana
	 * (a view) is considered a match for any match in libertadores-2026
	 * (its parent), because the window-views never store matches of their
	 * own — they're just filtered windows over the parent.
	 *
	 * @return array<int,int>
	 */
	public static function user_groups_in_competition( int $user_id, string $competition_id ): array {
		$target = Mantia_Competitions::storage_id( $competition_id );
		$out    = array();
		foreach ( self::user_groups_to_array( $user_id ) as $g ) {
			$group_comp = (string) ( $g['competition_id'] ?? '' );
			$group_root = Mantia_Competitions::storage_id( $group_comp );
			if ( $group_root === $target ) {
				$out[] = (int) $g['id'];
			}
		}
		return $out;
	}

	public static function generate_invite_code( string $name ): string {
		$base = self::normalize_invite_code( $name );
		$base = '' !== $base ? substr( $base, 0, 14 ) : 'MANTIA';

		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$suffix = strtoupper( substr( wp_generate_password( 5, false, false ), 0, 5 ) );
			$code   = self::normalize_invite_code( $base . $suffix );
			if ( ! self::find_group_by_invite_code( $code ) ) {
				return $code;
			}
		}

		return self::normalize_invite_code( $base . wp_rand( 10000, 99999 ) );
	}

	public static function find_group_by_invite_code( string $invite_code ): ?WP_Post {
		$invite_code = self::normalize_invite_code( $invite_code );
		if ( '' === $invite_code ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::GROUP,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_INVITE_CODE,
				'meta_value'     => $invite_code,
			)
		);

		return $posts[0] ?? null;
	}

	public static function find_group_by_invite_message( string $message ): ?WP_Post {
		$code = self::extract_invite_code_from_message( $message );
		return '' !== $code ? self::find_group_by_invite_code( $code ) : null;
	}

	public static function extract_invite_code_from_message( string $message ): string {
		$message = trim( $message );
		if ( '' === $message ) {
			return '';
		}

		if ( ! str_contains( $message, ' ' ) && ! str_contains( $message, "\n" ) && ! str_contains( $message, "\t" ) ) {
			$code = self::normalize_invite_code( $message );
			return strlen( $code ) >= 4 ? $code : '';
		}

		$plain = function_exists( 'remove_accents' ) ? remove_accents( $message ) : $message;
		if ( preg_match( '/^\s*(?:codigo|grupo|penca|invite|invitacion|me\s+uno(?:\s+a)?|unirme(?:\s+a)?)\s*[:#-]?\s+([A-Za-z0-9_-]{4,32})\s*$/i', (string) $plain, $matches ) ) {
			return self::normalize_invite_code( (string) $matches[1] );
		}

		return '';
	}

	public static function default_group_id(): int {
		return self::create_group( __( 'Liga Uruguay', 'mantia' ), 'URUGUAY2026', 'liga-uruguay' );
	}

	public static function join_group( string $phone, string $invite_code, string $name = '', string $recipient = '' ): array|WP_Error {
		$group = self::find_group_by_invite_code( $invite_code );
		if ( ! $group ) {
			return new WP_Error( 'mantia_group_not_found', __( 'No encuentro ese codigo de grupo.', 'mantia' ) );
		}

		$user_id = self::get_or_create_user( $phone, $name, $recipient );
		if ( 0 === $user_id ) {
			return new WP_Error( 'mantia_invalid_phone', __( 'Necesito un telefono valido para unirte al grupo.', 'mantia' ) );
		}

		$groups = self::user_group_ids( $user_id );
		$already_member = in_array( (int) $group->ID, $groups, true );
		if ( ! $already_member ) {
			$groups[] = (int) $group->ID;
			update_post_meta( $user_id, self::META_GROUP_IDS, array_values( $groups ) );
		}
		update_post_meta( $user_id, self::META_ACTIVE_GROUP_ID, (int) $group->ID );

		return array(
			'user_id'        => $user_id,
			'group_id'       => (int) $group->ID,
			'already_member' => $already_member,
			'active_group'   => (int) $group->ID,
			'group'          => self::group_to_array( (int) $group->ID ),
		);
	}

	public static function user_group_ids( int $user_id ): array {
		$groups = get_post_meta( $user_id, self::META_GROUP_IDS, true );
		if ( ! is_array( $groups ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $groups ) ) );
	}

	public static function active_group_id_for_user( int $user_id ): int {
		$active = (int) get_post_meta( $user_id, self::META_ACTIVE_GROUP_ID, true );
		if ( $active > 0 ) {
			return $active;
		}

		$groups = self::user_group_ids( $user_id );
		if ( ! empty( $groups ) ) {
			return (int) $groups[0];
		}

		return 0;
	}

	public static function set_active_group_for_user( int $user_id, int $group_id ): array|WP_Error {
		if ( $group_id <= 0 || Mantia_CPTs::GROUP !== get_post_type( $group_id ) ) {
			return new WP_Error( 'mantia_group_not_found', __( 'No encuentro ese grupo.', 'mantia' ) );
		}

		$groups = self::user_group_ids( $user_id );
		if ( ! in_array( $group_id, $groups, true ) ) {
			return new WP_Error( 'mantia_group_not_joined', __( 'Todavia no estas en ese grupo. Mandame el codigo de invitacion para unirte.', 'mantia' ) );
		}

		update_post_meta( $user_id, self::META_ACTIVE_GROUP_ID, $group_id );

		return array(
			'user_id'      => $user_id,
			'active_group' => self::group_to_array( $group_id ),
			'groups'       => self::user_groups_to_array( $user_id ),
		);
	}

	public static function user_groups_to_array( int $user_id ): array {
		$active = self::active_group_id_for_user( $user_id );

		return array_map(
			static function ( int $group_id ) use ( $active ): array {
				$group              = self::group_to_array( $group_id );
				$group['is_active'] = $group_id === $active;
				return $group;
			},
			self::user_group_ids( $user_id )
		);
	}

	public static function group_invite_message( int $group_id ): string {
		$group = self::group_to_array( $group_id );
		if ( empty( $group ) ) {
			return '';
		}

		return sprintf(
			'Para sumarte a %s, mandale este codigo al bot de WhatsApp: %s',
			$group['name'],
			$group['invite_code']
		);
	}

	public static function upsert_match( array $match ): int {
		$external_id = sanitize_key( (string) ( $match['external_id'] ?? '' ) );
		if ( '' === $external_id ) {
			return 0;
		}

		$post = self::find_match_by_external_id( $external_id );
		$data = array(
			'post_type'   => Mantia_CPTs::MATCH,
			'post_status' => 'publish',
			'post_title'  => self::match_title( (string) $match['home_team'], (string) $match['away_team'] ),
		);

		if ( $post ) {
			$data['ID'] = $post->ID;
			$post_id    = wp_update_post( $data, true );
		} else {
			$post_id = wp_insert_post( $data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$post_id = (int) $post_id;
		$kickoff = self::parse_gmt_timestamp( (string) ( $match['kickoff_gmt'] ?? '' ) );

		update_post_meta( $post_id, self::META_EXTERNAL_ID, $external_id );
		update_post_meta( $post_id, self::META_HOME_TEAM, sanitize_text_field( (string) ( $match['home_team'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_AWAY_TEAM, sanitize_text_field( (string) ( $match['away_team'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_KICKOFF_GMT, gmdate( 'Y-m-d H:i:s', $kickoff ) );
		update_post_meta( $post_id, self::META_KICKOFF_TS, $kickoff );
		update_post_meta( $post_id, self::META_PHASE, sanitize_text_field( (string) ( $match['phase'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_STATUS, sanitize_key( (string) ( $match['status'] ?? 'scheduled' ) ) );
		update_post_meta( $post_id, self::META_HOME_SCORE, isset( $match['home_score'] ) ? (int) $match['home_score'] : '' );
		update_post_meta( $post_id, self::META_AWAY_SCORE, isset( $match['away_score'] ) ? (int) $match['away_score'] : '' );
		update_post_meta( $post_id, self::META_RESOLVED, ! empty( $match['resolved'] ) ? 1 : 0 );

		$competition_id = isset( $match['competition_id'] ) && Mantia_Competitions::get( (string) $match['competition_id'] )
			? (string) $match['competition_id']
			: Mantia_Competitions::default_id();
		update_post_meta( $post_id, Mantia_Competitions::META_KEY, $competition_id );

		return $post_id;
	}

	public static function find_match_by_external_id( string $external_id ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::MATCH,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_key'       => self::META_EXTERNAL_ID,
				'meta_value'     => sanitize_key( $external_id ),
			)
		);

		return $posts[0] ?? null;
	}

	public static function match_to_array( int $match_id ): array {
		$post = get_post( $match_id );
		if ( ! $post || Mantia_CPTs::MATCH !== $post->post_type ) {
			return array();
		}

		$comp_id = (string) get_post_meta( $match_id, Mantia_Competitions::META_KEY, true );
		if ( '' === $comp_id ) {
			$comp_id = Mantia_Competitions::default_id();
		}

		return array(
			'id'             => $match_id,
			'external_id'    => (string) get_post_meta( $match_id, self::META_EXTERNAL_ID, true ),
			'home_team'      => (string) get_post_meta( $match_id, self::META_HOME_TEAM, true ),
			'away_team'      => (string) get_post_meta( $match_id, self::META_AWAY_TEAM, true ),
			'kickoff_gmt'    => (string) get_post_meta( $match_id, self::META_KICKOFF_GMT, true ),
			'kickoff_ts'     => (int) get_post_meta( $match_id, self::META_KICKOFF_TS, true ),
			'phase'          => (string) get_post_meta( $match_id, self::META_PHASE, true ),
			'status'         => (string) get_post_meta( $match_id, self::META_STATUS, true ),
			'home_score'     => self::nullable_int_meta( $match_id, self::META_HOME_SCORE ),
			'away_score'     => self::nullable_int_meta( $match_id, self::META_AWAY_SCORE ),
			'resolved'       => (bool) get_post_meta( $match_id, self::META_RESOLVED, true ),
			'competition_id' => $comp_id,
		);
	}

	public static function group_to_array( int $group_id ): array {
		$post = get_post( $group_id );
		if ( ! $post || Mantia_CPTs::GROUP !== $post->post_type ) {
			return array();
		}

		$name      = get_the_title( $group_id );
		$code      = (string) get_post_meta( $group_id, self::META_INVITE_CODE, true );
		$share_url = self::build_share_url( $code );
		$comp_id   = self::group_competition_id( $group_id );
		$comp      = Mantia_Competitions::get( $comp_id );

		$invite_message  = sprintf( 'Sumate a %s. Tocá el link y enviá: %s', $name, $share_url );
		$invite_fallback = '' === $share_url
			? sprintf( 'Sumate a %s. Mandale este codigo al bot de WhatsApp: %s', $name, $code )
			: $invite_message;

		return array(
			'id'               => $group_id,
			'name'             => $name,
			'invite_code'      => $code,
			'share_url'        => $share_url,
			'view_url'         => self::group_view_url( $group_id ),
			'invite_message'   => $invite_fallback,
			'slug'             => (string) get_post_meta( $group_id, self::META_GROUP_SLUG, true ),
			'competition_id'   => $comp_id,
			'competition_name' => $comp ? trim( ( $comp['emoji'] ?? '' ) . ' ' . $comp['name'] ) : $comp_id,
		);
	}

	public static function group_view_url( int $group_id ): string {
		$token = self::group_view_token( $group_id );
		return home_url( '/penca/g/' . $token );
	}

	public static function user_view_url( int $user_id ): string {
		$token = self::user_view_token( $user_id );
		return home_url( '/penca/me/' . $token );
	}

	public static function competition_view_url( string $competition_id ): string {
		return home_url( '/penca/' . $competition_id );
	}

	public static function bot_phone_e164(): string {
		$value = (string) get_option( 'mantia_bot_phone_e164', '' );
		return preg_replace( '/\D+/', '', $value ) ?: '';
	}

	public static function build_share_url( string $invite_code ): string {
		$invite_code = self::normalize_invite_code( $invite_code );
		$bot_phone   = self::bot_phone_e164();
		if ( '' === $invite_code || '' === $bot_phone ) {
			return '';
		}
		return sprintf( 'https://wa.me/%s?text=%s', $bot_phone, rawurlencode( $invite_code ) );
	}

	public static function upcoming_matches( int $hours_ahead = 48 ): array {
		return self::upcoming_matches_for_competition( '', $hours_ahead );
	}

	public static function upcoming_matches_for_competition( string $competition_id, int $hours_ahead = 48 ): array {
		$now = time();

		// Views like `libertadores-semana` are stored as `libertadores-2026`
		// and apply a tighter time window. Honor both: cap hours_ahead by the
		// view's window_days, then query against the storage id.
		$window_days = '' !== $competition_id ? Mantia_Competitions::window_days( $competition_id ) : 0;
		if ( $window_days > 0 ) {
			$hours_ahead = min( $hours_ahead, $window_days * 24 );
		}
		$storage_id = '' !== $competition_id ? Mantia_Competitions::storage_id( $competition_id ) : '';

		$max = $now + max( 1, $hours_ahead ) * HOUR_IN_SECONDS;

		$meta_query = array(
			array(
				'key'     => self::META_STATUS,
				'value'   => 'scheduled',
				'compare' => '=',
			),
			array(
				'key'     => self::META_KICKOFF_TS,
				'value'   => array( $now, $max ),
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			),
		);

		if ( '' !== $storage_id ) {
			$meta_query[] = array(
				'key'   => Mantia_Competitions::META_KEY,
				'value' => $storage_id,
			);
		}

		return array_map(
			static fn ( WP_Post $post ): array => self::match_to_array( (int) $post->ID ),
			get_posts(
				array(
					'post_type'      => Mantia_CPTs::MATCH,
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'no_found_rows'  => true,
					'meta_key'       => self::META_KICKOFF_TS,
					'orderby'        => 'meta_value_num',
					'order'          => 'ASC',
					'meta_query'     => $meta_query,
				)
			)
		);
	}

	public static function find_next_match_for_team( string $team ): array {
		$needle = self::team_key( $team );
		if ( '' === $needle ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::MATCH,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => self::META_KICKOFF_TS,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => self::META_STATUS,
						'value'   => 'scheduled',
						'compare' => '=',
					),
					array(
						'key'     => self::META_KICKOFF_TS,
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			$match = self::match_to_array( (int) $post->ID );
			if ( $needle === self::team_key( $match['home_team'] ) || $needle === self::team_key( $match['away_team'] ) ) {
				return $match;
			}
		}

		return array();
	}

	public static function find_next_match_between( string $first_team, string $second_team ): array {
		$first  = self::team_key( $first_team );
		$second = self::team_key( $second_team );
		if ( '' === $first || '' === $second ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::MATCH,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => self::META_KICKOFF_TS,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => self::META_STATUS,
						'value'   => 'scheduled',
						'compare' => '=',
					),
					array(
						'key'     => self::META_KICKOFF_TS,
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $posts as $post ) {
			$match = self::match_to_array( (int) $post->ID );
			$home  = self::team_key( $match['home_team'] );
			$away  = self::team_key( $match['away_team'] );
			if ( ( $first === $home && $second === $away ) || ( $first === $away && $second === $home ) ) {
				return $match;
			}
		}

		return array();
	}

	public static function team_key_for_match( string $team ): string {
		return self::team_key( $team );
	}

	public static function register_prediction( int $user_id, int $match_id, int $group_id, int $home_score, int $away_score ): array|WP_Error {
		$match = self::match_to_array( $match_id );
		if ( empty( $match ) ) {
			return new WP_Error( 'mantia_match_not_found', __( 'No encuentro ese partido.', 'mantia' ) );
		}
		if ( 'scheduled' !== $match['status'] || (int) $match['kickoff_ts'] <= time() ) {
			return new WP_Error( 'mantia_match_closed', __( 'Ese partido ya cerro para pronosticos.', 'mantia' ) );
		}

		$existing = self::find_prediction( $user_id, $match_id, $group_id );
		$title    = sprintf(
			'%s: %s %d-%d %s',
			get_the_title( $user_id ),
			$match['home_team'],
			$home_score,
			$away_score,
			$match['away_team']
		);
		$postarr  = array(
			'post_type'   => Mantia_CPTs::PREDICTION,
			'post_status' => 'publish',
			'post_title'  => $title,
		);

		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;
		update_post_meta( $post_id, self::META_USER_ID, $user_id );
		update_post_meta( $post_id, self::META_USER_PHONE, (string) get_post_meta( $user_id, self::META_PHONE, true ) );
		update_post_meta( $post_id, self::META_MATCH_ID, $match_id );
		update_post_meta( $post_id, self::META_GROUP_ID, $group_id );
		update_post_meta( $post_id, self::META_PRED_HOME_SCORE, max( 0, $home_score ) );
		update_post_meta( $post_id, self::META_PRED_AWAY_SCORE, max( 0, $away_score ) );
		update_post_meta( $post_id, self::META_SCORED, 0 );

		return self::prediction_to_array( $post_id );
	}

	public static function find_prediction( int $user_id, int $match_id, int $group_id ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::PREDICTION,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::META_USER_ID,
						'value' => $user_id,
					),
					array(
						'key'   => self::META_MATCH_ID,
						'value' => $match_id,
					),
					array(
						'key'   => self::META_GROUP_ID,
						'value' => $group_id,
					),
				),
			)
		);

		return $posts[0] ?? null;
	}

	public static function prediction_to_array( int $prediction_id ): array {
		$post = get_post( $prediction_id );
		if ( ! $post || Mantia_CPTs::PREDICTION !== $post->post_type ) {
			return array();
		}

		return array(
			'id'          => $prediction_id,
			'user_id'     => (int) get_post_meta( $prediction_id, self::META_USER_ID, true ),
			'user_phone'  => (string) get_post_meta( $prediction_id, self::META_USER_PHONE, true ),
			'match_id'    => (int) get_post_meta( $prediction_id, self::META_MATCH_ID, true ),
			'group_id'    => (int) get_post_meta( $prediction_id, self::META_GROUP_ID, true ),
			'home_score'  => (int) get_post_meta( $prediction_id, self::META_PRED_HOME_SCORE, true ),
			'away_score'  => (int) get_post_meta( $prediction_id, self::META_PRED_AWAY_SCORE, true ),
			'points'      => (int) get_post_meta( $prediction_id, self::META_POINTS, true ),
			'reason'      => (string) get_post_meta( $prediction_id, self::META_SCORING_REASON, true ),
			'scored'      => (bool) get_post_meta( $prediction_id, self::META_SCORED, true ),
			'user_name'   => get_the_title( (int) get_post_meta( $prediction_id, self::META_USER_ID, true ) ),
		);
	}

	public static function finished_unresolved_matches(): array {
		return array_values(
			array_filter(
				array_map(
					static fn ( WP_Post $post ): array => self::match_to_array( (int) $post->ID ),
					get_posts(
						array(
							'post_type'      => Mantia_CPTs::MATCH,
							'post_status'    => 'publish',
							'posts_per_page' => 50,
							'no_found_rows'  => true,
							'meta_query'     => array(
								array(
									'key'   => self::META_STATUS,
									'value' => 'finished',
								),
								array(
									'key'     => self::META_RESOLVED,
									'value'   => '1',
									'compare' => '!=',
								),
							),
						)
					)
				)
			)
		);
	}

	public static function predictions_for_match( int $match_id ): array {
		return array_map(
			static fn ( WP_Post $post ): array => self::prediction_to_array( (int) $post->ID ),
			get_posts(
				array(
					'post_type'      => Mantia_CPTs::PREDICTION,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_key'       => self::META_MATCH_ID,
					'meta_value'     => $match_id,
				)
			)
		);
	}

	public static function score_prediction_post( int $prediction_id, int $real_home, int $real_away ): array {
		$prediction = self::prediction_to_array( $prediction_id );
		if ( empty( $prediction ) ) {
			return array();
		}

		$score = Mantia_Scoring::score_prediction(
			(int) $prediction['home_score'],
			(int) $prediction['away_score'],
			$real_home,
			$real_away
		);

		update_post_meta( $prediction_id, self::META_POINTS, (int) $score['points'] );
		update_post_meta( $prediction_id, self::META_SCORING_REASON, (string) $score['reason'] );
		update_post_meta( $prediction_id, self::META_SCORED, 1 );

		return array_merge( self::prediction_to_array( $prediction_id ), $score );
	}

	public static function mark_match_resolved( int $match_id ): void {
		update_post_meta( $match_id, self::META_RESOLVED, 1 );
	}

	public static function update_match_result( int $match_id, int $home_score, int $away_score, string $status = 'finished' ): void {
		update_post_meta( $match_id, self::META_HOME_SCORE, max( 0, $home_score ) );
		update_post_meta( $match_id, self::META_AWAY_SCORE, max( 0, $away_score ) );
		update_post_meta( $match_id, self::META_STATUS, sanitize_key( $status ) );
	}

	public static function leaderboard( int $group_id = 0 ): array {
		$args = array(
			'post_type'      => Mantia_CPTs::PREDICTION,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => self::META_SCORED,
					'value' => 1,
				),
			),
		);

		if ( $group_id > 0 ) {
			$args['meta_query'][] = array(
				'key'   => self::META_GROUP_ID,
				'value' => $group_id,
			);
		}

		$rows = array();
		foreach ( get_posts( $args ) as $post ) {
			$prediction = self::prediction_to_array( (int) $post->ID );
			$user_id    = (int) $prediction['user_id'];
			if ( ! isset( $rows[ $user_id ] ) ) {
				$rows[ $user_id ] = array(
					'user_id'     => $user_id,
					'name'        => get_the_title( $user_id ),
					'points'      => 0,
					'predictions' => 0,
					'exacts'      => 0,
				);
			}
			$rows[ $user_id ]['points'] += (int) $prediction['points'];
			++$rows[ $user_id ]['predictions'];
			if ( 'exact' === $prediction['reason'] ) {
				++$rows[ $user_id ]['exacts'];
			}
		}

		$rows = array_values( $rows );
		usort(
			$rows,
			static fn ( array $a, array $b ): int => ( $b['points'] <=> $a['points'] )
				?: ( $b['exacts'] <=> $a['exacts'] )
				?: strcasecmp( $a['name'], $b['name'] )
		);

		foreach ( $rows as $index => &$row ) {
			$row['rank'] = $index + 1;
		}

		return $rows;
	}

	public static function user_history( int $user_id, int $group_id = 0 ): array {
		$args = array(
			'post_type'      => Mantia_CPTs::PREDICTION,
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => self::META_USER_ID,
					'value' => $user_id,
				),
			),
		);

		if ( $group_id > 0 ) {
			$args['meta_query'][] = array(
				'key'   => self::META_GROUP_ID,
				'value' => $group_id,
			);
		}

		return array_map(
			static function ( WP_Post $post ): array {
				$prediction          = self::prediction_to_array( (int) $post->ID );
				$prediction['match'] = self::match_to_array( (int) $prediction['match_id'] );
				return $prediction;
			},
			get_posts( $args )
		);
	}

	public static function users_missing_prediction_for_match( int $match_id, int $group_id = 0 ): array {
		$users = get_posts(
			array(
				'post_type'      => Mantia_CPTs::USER,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;
			$groups  = self::user_group_ids( $user_id );
			if ( empty( $groups ) ) {
				continue;
			}
			if ( $group_id > 0 && ! in_array( $group_id, $groups, true ) ) {
				continue;
			}
			$active_group = $group_id > 0 ? $group_id : self::active_group_id_for_user( $user_id );
			if ( $active_group <= 0 ) {
				continue;
			}
			if ( self::find_prediction( $user_id, $match_id, $active_group ) ) {
				continue;
			}
			$out[] = array(
				'user_id'   => $user_id,
				'name'      => get_the_title( $user_id ),
				'phone'     => (string) get_post_meta( $user_id, self::META_PHONE, true ),
				'recipient' => (string) get_post_meta( $user_id, self::META_WHATSAPP_RECIPIENT, true ),
				'group_id'  => $active_group,
			);
		}

		return $out;
	}

	private static function nullable_int_meta( int $post_id, string $key ): ?int {
		$value = get_post_meta( $post_id, $key, true );
		return '' === $value ? null : (int) $value;
	}

	private static function match_title( string $home_team, string $away_team ): string {
		return sanitize_text_field( trim( "{$home_team} vs {$away_team}" ) );
	}

	private static function parse_gmt_timestamp( string $value ): int {
		$timestamp = strtotime( $value . ( str_ends_with( $value, 'Z' ) ? '' : ' UTC' ) );
		return false === $timestamp ? time() : $timestamp;
	}

	private static function team_key( string $team ): string {
		$team = function_exists( 'remove_accents' ) ? remove_accents( $team ) : $team;
		return sanitize_title( $team );
	}
}
