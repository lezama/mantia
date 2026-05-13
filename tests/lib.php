<?php
/**
 * E2E test harness for Mantia.
 *
 * Designed to run via `wp eval-file` against any Mantia install (Studio
 * local or live mantia3.wpcomstaging.com). Simulates the WhatsApp preflight
 * with the same turn shape openclaWP passes in production — so the same
 * code path runs for tests and real traffic.
 *
 * Convention: test personas use phone numbers starting with `9999000` so
 * cleanup() can safely target them without touching production data.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_E2E {

	public const TEST_PHONE_PREFIX = '9999000';
	public const TEST_NAME_PREFIX  = '__E2E__';

	private static int $assertions   = 0;
	private static int $failures     = 0;
	private static array $current    = array( 'scenario' => '', 'step' => '' );
	private static float $start_time = 0;

	/* -------------------------------- Lifecycle -------------------------------- */

	public static function start( string $scenario ): void {
		self::$current['scenario'] = $scenario;
		self::$start_time          = microtime( true );
		self::log( "\n" . str_repeat( '━', 60 ) );
		self::log( "▶ {$scenario}" );
		self::log( str_repeat( '━', 60 ) );
	}

	public static function step( string $name ): void {
		self::$current['step'] = $name;
		self::log( "\n• {$name}" );
	}

	public static function finish(): void {
		$duration = round( microtime( true ) - self::$start_time, 2 );
		$status   = self::$failures === 0 ? '✅ PASS' : '❌ FAIL';
		self::log( "\n" . str_repeat( '━', 60 ) );
		self::log( sprintf( "%s  %d asserts  %d fails  %ss", $status, self::$assertions, self::$failures, $duration ) );
		self::log( str_repeat( '━', 60 ) );
		if ( self::$failures > 0 ) {
			exit( 1 );
		}
	}

	/* --------------------------------- Personas -------------------------------- */

	public static function persona( string $name, int $slot = 1 ): array {
		$phone = self::TEST_PHONE_PREFIX . sprintf( '%02d', max( 1, min( 99, $slot ) ) );
		return array(
			'name'      => $name,
			'phone'     => $phone,
			'recipient' => $phone,
		);
	}

	/* --------------------------- Simulate WhatsApp turn ------------------------ */

	/**
	 * Send a "message" through the same preflight openclaWP invokes. Returns
	 * the result array (reply + interactive). Identical code path to the
	 * production webhook → no mocks.
	 */
	public static function send( array $persona, string $message ): array {
		$turn = array(
			'agent_slug'      => 'mantia',
			'message'         => $message,
			'runtime_context' => array(
				'client_context' => array(
					'sender_id'   => $persona['phone'],
					'sender_name' => $persona['name'],
				),
			),
		);
		$result = Mantia_Whatsapp_Flow::maybe_handle_command( null, $turn );
		if ( ! is_array( $result ) ) {
			$result = array( 'reply' => '(null — fell through to LLM)' );
		}
		self::log( "  [{$persona['name']}] » \"{$message}\"" );
		self::log( "  [bot] « " . self::summarize( $result['reply'] ?? '' ) );
		return $result;
	}

	/* ----------------------------------- Asserts ----------------------------------- */

	/**
	 * Search the reply text AND every interactive button/list row title for
	 * the needle. Mirrors what a real user sees on WhatsApp — button labels
	 * count as visible content too.
	 */
	public static function assert_contains( array $result, string $needle, string $label = '' ): bool {
		++self::$assertions;
		$haystack = (string) ( $result['reply'] ?? '' );
		$interactive = $result['interactive'] ?? array();
		foreach ( $interactive['buttons'] ?? array() as $b ) {
			$haystack .= ' ' . (string) ( $b['title'] ?? '' );
		}
		foreach ( $interactive['sections'] ?? array() as $s ) {
			$haystack .= ' ' . (string) ( $s['title'] ?? '' );
			foreach ( $s['rows'] ?? array() as $row ) {
				$haystack .= ' ' . (string) ( $row['title'] ?? '' );
				$haystack .= ' ' . (string) ( $row['description'] ?? '' );
			}
		}
		if ( false !== stripos( $haystack, $needle ) ) {
			self::log( "    ✓ " . ( '' !== $label ? $label : "contains \"{$needle}\"" ) );
			return true;
		}
		++self::$failures;
		self::log( "    ✗ FAIL: " . ( '' !== $label ? $label : "expected to find \"{$needle}\"" ) );
		self::log( "      got: " . self::summarize( $haystack ) );
		return false;
	}

	public static function assert_not_contains( array $result, string $needle, string $label = '' ): bool {
		++self::$assertions;
		$reply = (string) ( $result['reply'] ?? '' );
		if ( false === stripos( $reply, $needle ) ) {
			self::log( "    ✓ " . ( '' !== $label ? $label : "reply does NOT contain \"{$needle}\"" ) );
			return true;
		}
		++self::$failures;
		self::log( "    ✗ FAIL: expected NOT to contain \"{$needle}\"" );
		return false;
	}

	public static function assert_eq( $expected, $actual, string $label ): bool {
		++self::$assertions;
		if ( $expected === $actual ) {
			self::log( "    ✓ {$label} = " . self::summarize( var_export( $actual, true ) ) );
			return true;
		}
		++self::$failures;
		self::log( "    ✗ FAIL: {$label}" );
		self::log( "      expected: " . var_export( $expected, true ) );
		self::log( "      got:      " . var_export( $actual, true ) );
		return false;
	}

	/**
	 * GET a URL with a cache-busting param + assert 200 and optional body
	 * substrings. The cache-buster matters: wp_remote_get going from the
	 * server back to itself hits WP.com's edge cache, which may serve a
	 * stale render. The query param sidesteps it.
	 */
	public static function assert_http_ok( string $path, ?array $must_contain = null ): bool {
		++self::$assertions;
		$url      = self::bust_cache( self::http_url( $path ) );
		$response = wp_remote_get( $url, self::http_args() );
		if ( is_wp_error( $response ) ) {
			++self::$failures;
			self::log( "    ✗ FAIL: HTTP {$path} — " . $response->get_error_message() );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code ) {
			++self::$failures;
			self::log( "    ✗ FAIL: {$path} → HTTP {$code}" );
			return false;
		}
		self::log( "    ✓ GET {$path} → 200" );
		if ( null !== $must_contain ) {
			foreach ( $must_contain as $needle ) {
				++self::$assertions;
				if ( false !== stripos( $body, (string) $needle ) ) {
					self::log( "    ✓   body contains \"{$needle}\"" );
				} else {
					++self::$failures;
					self::log( "    ✗   body MISSING \"{$needle}\"" );
				}
			}
		}
		return true;
	}

	public static function assert_http_status( string $path, int $expected, ?array $must_contain = null ): bool {
		++self::$assertions;
		$url      = self::bust_cache( self::http_url( $path ) );
		$response = wp_remote_get( $url, self::http_args() );
		if ( is_wp_error( $response ) ) {
			++self::$failures;
			self::log( "    ✗ FAIL: HTTP {$path} — " . $response->get_error_message() );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code !== $expected ) {
			++self::$failures;
			self::log( "    ✗ FAIL: {$path} → HTTP {$code} (expected {$expected})" );
			return false;
		}
		self::log( "    ✓ GET {$path} → HTTP {$code}" );
		if ( null !== $must_contain ) {
			foreach ( $must_contain as $needle ) {
				++self::$assertions;
				if ( false !== stripos( $body, (string) $needle ) ) {
					self::log( "    ✓   body contains \"{$needle}\"" );
				} else {
					++self::$failures;
					self::log( "    ✗   body MISSING \"{$needle}\"" );
				}
			}
		}
		return true;
	}

	private static function bust_cache( string $url ): string {
		$sep = false === strpos( $url, '?' ) ? '?' : '&';
		return $url . $sep . 'e2e_ts=' . microtime( true );
	}

	/**
	 * Resolve the URL for an HTTP assertion. In a wp-env CLI container,
	 * `localhost:8889` isn't reachable because WP runs in a sibling
	 * container; the cross-container hostname is `http://wordpress`.
	 * The MANTIA_E2E_BASE_URL env var lets the CI workflow override.
	 */
	private static function http_url( string $path ): string {
		$base = (string) getenv( 'MANTIA_E2E_BASE_URL' );
		if ( '' === $base ) {
			return home_url( $path );
		}
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Pass the canonical Host header when we override the URL host —
	 * otherwise WP's canonical_redirect filter 301s us off the container
	 * hostname and back to localhost:8889, which the CLI container can't
	 * reach.
	 */
	private static function http_args(): array {
		$args = array( 'timeout' => 15, 'redirection' => 3 );
		$base = (string) getenv( 'MANTIA_E2E_BASE_URL' );
		if ( '' !== $base ) {
			$canonical = wp_parse_url( home_url() );
			if ( ! empty( $canonical['host'] ) ) {
				$host = $canonical['host'];
				if ( ! empty( $canonical['port'] ) ) {
					$host .= ':' . $canonical['port'];
				}
				$args['headers'] = array( 'Host' => $host );
			}
		}
		return $args;
	}

	/* ------------------------------ Time travel ----------------------------------- */

	/**
	 * Move a match's kickoff to N hours ago and set status=finished with the
	 * given real score. Used to test the resolution flow without waiting.
	 */
	/**
	 * Snapshot the match's pre-test state in a sidecar meta key before
	 * overwriting it, so cleanup() can restore it exactly. Without this,
	 * Mundial demo matches stay 'finished' across test runs and subsequent
	 * runs see an empty fixture.
	 */
	public static function finish_match( int $match_id, int $home, int $away ): void {
		$snapshot = array(
			'kickoff_gmt' => (string) get_post_meta( $match_id, Mantia_Repository::META_KICKOFF_GMT, true ),
			'kickoff_ts'  => (int) get_post_meta( $match_id, Mantia_Repository::META_KICKOFF_TS, true ),
			'status'      => (string) get_post_meta( $match_id, Mantia_Repository::META_STATUS, true ),
			'home_score'  => get_post_meta( $match_id, Mantia_Repository::META_HOME_SCORE, true ),
			'away_score'  => get_post_meta( $match_id, Mantia_Repository::META_AWAY_SCORE, true ),
			'resolved'    => (int) get_post_meta( $match_id, Mantia_Repository::META_RESOLVED, true ),
		);
		update_post_meta( $match_id, '_mantia_e2e_snapshot', $snapshot );

		$past = gmdate( 'Y-m-d H:i:s', time() - 90 * MINUTE_IN_SECONDS );
		update_post_meta( $match_id, Mantia_Repository::META_KICKOFF_GMT, $past );
		update_post_meta( $match_id, Mantia_Repository::META_KICKOFF_TS, time() - 90 * MINUTE_IN_SECONDS );
		update_post_meta( $match_id, Mantia_Repository::META_STATUS, 'finished' );
		update_post_meta( $match_id, Mantia_Repository::META_HOME_SCORE, $home );
		update_post_meta( $match_id, Mantia_Repository::META_AWAY_SCORE, $away );
		update_post_meta( $match_id, Mantia_Repository::META_RESOLVED, 0 );
	}

	private static function restore_touched_matches(): int {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_mantia_e2e_snapshot'
			)
		);
		$restored = 0;
		foreach ( (array) $ids as $mid ) {
			$snap = get_post_meta( (int) $mid, '_mantia_e2e_snapshot', true );
			if ( ! is_array( $snap ) ) {
				continue;
			}
			update_post_meta( (int) $mid, Mantia_Repository::META_KICKOFF_GMT, (string) ( $snap['kickoff_gmt'] ?? '' ) );
			update_post_meta( (int) $mid, Mantia_Repository::META_KICKOFF_TS, (int) ( $snap['kickoff_ts'] ?? 0 ) );
			update_post_meta( (int) $mid, Mantia_Repository::META_STATUS, (string) ( $snap['status'] ?? 'scheduled' ) );
			update_post_meta( (int) $mid, Mantia_Repository::META_HOME_SCORE, $snap['home_score'] ?? '' );
			update_post_meta( (int) $mid, Mantia_Repository::META_AWAY_SCORE, $snap['away_score'] ?? '' );
			update_post_meta( (int) $mid, Mantia_Repository::META_RESOLVED, (int) ( $snap['resolved'] ?? 0 ) );
			delete_post_meta( (int) $mid, '_mantia_e2e_snapshot' );
			++$restored;
		}
		return $restored;
	}

	/* --------------------------------- Cleanup ------------------------------------ */

	/**
	 * Delete every artifact created by an E2E run. Targets ONLY entities
	 * whose owner phone starts with TEST_PHONE_PREFIX so production data
	 * is never touched.
	 */
	public static function cleanup(): int {
		global $wpdb;
		$deleted = self::restore_touched_matches();

		// 1. Test users — phone prefix is our anchor.
		$users = get_posts(
			array(
				'post_type'      => Mantia_CPTs::USER,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => Mantia_Repository::META_PHONE,
						'value'   => self::TEST_PHONE_PREFIX,
						'compare' => 'LIKE',
					),
				),
			)
		);

		// 2. Predictions by those users.
		foreach ( $users as $uid ) {
			$preds = get_posts(
				array(
					'post_type'      => Mantia_CPTs::PREDICTION,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array( 'key' => Mantia_Repository::META_USER_ID, 'value' => (int) $uid ),
					),
				)
			);
			foreach ( $preds as $pid ) {
				wp_delete_post( (int) $pid, true );
				++$deleted;
			}
		}

		// 3. Groups with our E2E title prefix — names are the unambiguous
		// anchor (no shared-state issues with production groups).
		$groups = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s",
				Mantia_CPTs::GROUP,
				$wpdb->esc_like( self::TEST_NAME_PREFIX ) . '%'
			)
		);
		foreach ( (array) $groups as $gid ) {
			wp_delete_post( (int) $gid, true );
			++$deleted;
		}

		// 4. Test users themselves.
		foreach ( $users as $uid ) {
			wp_delete_post( (int) $uid, true );
			++$deleted;
		}

		// 5. Transients keyed by test phones (best effort).
		for ( $i = 1; $i <= 9; $i++ ) {
			$phone = self::TEST_PHONE_PREFIX . sprintf( '%02d', $i );
			$md5   = md5( $phone );
			foreach ( array( 'mantia_pending_create_', 'mantia_pending_comp_', 'mantia_pending_match_', 'mantia_rl_' ) as $prefix ) {
				delete_transient( $prefix . $md5 );
			}
		}

		return $deleted;
	}

	/* --------------------------------- Helpers ------------------------------------ */

	public static function group_by_invite( string $code ): ?WP_Post {
		return Mantia_Repository::find_group_by_invite_code( $code );
	}

	public static function user_by_phone( string $phone ): ?WP_Post {
		return Mantia_Repository::find_user_by_phone( $phone );
	}

	public static function match_id_from_payload( array $result, int $index = 0 ): int {
		$sections = $result['interactive']['sections'] ?? array();
		foreach ( $sections as $s ) {
			foreach ( $s['rows'] ?? array() as $i => $row ) {
				if ( $i === $index && str_starts_with( (string) $row['id'], 'mantia:match:' ) ) {
					return (int) substr( $row['id'], strlen( 'mantia:match:' ) );
				}
			}
		}
		return 0;
	}

	public static function match_id_by_teams( string $home_substr, string $away_substr ): int {
		$matches = get_posts(
			array(
				'post_type'      => Mantia_CPTs::MATCH,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		foreach ( $matches as $m ) {
			$h = (string) get_post_meta( $m->ID, Mantia_Repository::META_HOME_TEAM, true );
			$a = (string) get_post_meta( $m->ID, Mantia_Repository::META_AWAY_TEAM, true );
			if ( false !== stripos( $h, $home_substr ) && false !== stripos( $a, $away_substr ) ) {
				return (int) $m->ID;
			}
		}
		return 0;
	}

	private static function summarize( string $s ): string {
		$s = (string) preg_replace( '/\s+/', ' ', trim( $s ) );
		return strlen( $s ) > 120 ? substr( $s, 0, 117 ) . '…' : $s;
	}

	private static function log( string $line ): void {
		fwrite( STDOUT, $line . "\n" );
	}
}
