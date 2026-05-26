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

		// Tests bypass the openclawp dispatcher (which would normally
		// wp_set_current_user before invoking abilities), so we have to
		// log in as an admin here. Otherwise ability permission_callbacks
		// reject with manage_options denied and the test breaks deep
		// inside an ->execute() with a WP_Error→array cast fatal.
		self::login_as_admin();

		// Production auto-fills predictions for every match in a penca's
		// competition when a user joins. That's the right UX default but
		// most scenarios in this suite want to exercise the manual
		// prediction flow (tap a match → bot asks for score → user
		// replies "2-1"). A scenario can opt back in with
		// `Mantia_E2E::enable_auto_predict()` if it specifically wants to
		// verify the auto-fill path.
		add_filter( 'mantia_auto_predict_on_join', '__return_false' );

		// Pin the site's vocab to UY ("penca"/"pencas") for the suite.
		// Test personas use the 9999000* phone prefix, which doesn't match
		// any E.164 country code in Mantia_Vocab — so they fall back to
		// the site default. The default of `mantia_default_country` is
		// 'AR' (→ "pronóstico"), which would break existing assertions
		// like `assert_contains( $r, 'Crear penca' )`. Tests that need to
		// exercise the AR/BR/default path can override this option mid-
		// scenario; `Mantia_E2E::vocab_country( 'XX' )` is the helper.
		update_option( 'mantia_default_country', 'UY' );

		self::ensure_competition_graph();
		self::route_http_probes_via_docker();
	}

	/**
	 * Make sure both the default seed competitions AND the parent linkage
	 * between view → root competitions exist. Older fixture seeds may have
	 * created the view (e.g. libertadores-semana) without its parent post
	 * (libertadores-2026), which breaks Mantia_Competitions::storage_id()
	 * and in turn breaks user_groups_in_competition() — predictions then
	 * fail with mantia_no_group_in_competition even though the user IS in
	 * a matching group.
	 */
	private static function ensure_competition_graph(): void {
		if ( ! class_exists( 'Mantia_Competitions' ) ) {
			return;
		}
		Mantia_Competitions::seed_defaults();

		// Force-link libertadores-semana → libertadores-2026 if it was
		// originally seeded without a parent. seed_defaults() is idempotent
		// but does NOT re-link existing posts, so this fix is needed once.
		$parent = get_posts( array(
			'post_type'      => Mantia_CPTs::COMPETITION,
			'post_status'    => 'publish',
			'name'           => 'libertadores-2026',
			'posts_per_page' => 1,
		) );
		$child = get_posts( array(
			'post_type'      => Mantia_CPTs::COMPETITION,
			'post_status'    => 'publish',
			'name'           => 'libertadores-semana',
			'posts_per_page' => 1,
		) );
		if ( $parent && $child && 0 === (int) $child[0]->post_parent ) {
			wp_update_post( array(
				'ID'          => (int) $child[0]->ID,
				'post_parent' => (int) $parent[0]->ID,
			) );
		}
	}

	/**
	 * OrbStack/Docker Desktop quirk: containers inherit http_proxy env
	 * vars (e.g. proxyproxy.orb.internal:8305) whose no_proxy list does
	 * NOT include the docker-network hostnames. Without this, every
	 * wp_remote_get( 'http://wordpress/...' ) inside the CLI container
	 * gets routed through the host proxy and 502s. Append the wp-env
	 * hostnames to no_proxy so curl bypasses the proxy for them.
	 *
	 * No-op when running via SSH against mantia3 (no http_proxy in that
	 * environment).
	 */
	private static function route_http_probes_via_docker(): void {
		$existing = (string) getenv( 'no_proxy' );
		$hosts    = 'wordpress,tests-wordpress,localhost,127.0.0.1';
		$merged   = '' !== $existing ? $existing . ',' . $hosts : $hosts;
		putenv( 'no_proxy=' . $merged );
		putenv( 'NO_PROXY=' . $merged );

		// Auto-set mantia_e2e_base_url to the cross-container hostname
		// when we can reach it. Outside Docker (SSH, prod), the option
		// stays at its existing value (which CI sets to the public URL).
		if ( '' !== (string) get_option( 'mantia_e2e_base_url', '' ) || '' !== (string) getenv( 'MANTIA_E2E_BASE_URL' ) ) {
			return;
		}
		$probe = @file_get_contents(
			'http://wordpress/wp-login.php',
			false,
			stream_context_create( array( 'http' => array( 'timeout' => 2, 'ignore_errors' => true, 'proxy' => '' ) ) )
		);
		if ( false !== $probe ) {
			update_option( 'mantia_e2e_base_url', 'http://wordpress' );
		}
	}

	/**
	 * Skip when a specific demo match isn't in the fixture. Used by the
	 * Mundial-era tests that hard-code Uruguay-Portugal (or similar) —
	 * the live FIFA sync grabs whatever's in its 365-day window, so we
	 * can't depend on any one team-pair being there.
	 */
	public static function require_team_match_or_skip( string $home, string $away ): void {
		$mid = self::match_id_by_teams( $home, $away );
		if ( $mid > 0 ) {
			return;
		}
		self::step( sprintf( '! skipping — no %s vs %s demo match in this install\'s fixture', $home, $away ) );
		self::finish();
		exit( 0 );
	}

	/**
	 * Skip the scenario gracefully when the install doesn't have fixture
	 * data for the named competition. Lets Mundial-era scenarios run
	 * unchanged against a Libertadores-only local install AND against
	 * mantia3 (which has Mundial). Prints a step note, finishes the
	 * harness, and exits 0 — the e2e.sh runner sees a pass.
	 */
	public static function require_fixture_or_skip( string $competition_id ): void {
		$any = get_posts( array(
			'post_type'      => Mantia_CPTs::MATCH,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => Mantia_Competitions::META_KEY, 'value' => $competition_id ),
			),
		) );
		if ( ! empty( $any ) ) {
			return;
		}
		self::step( sprintf( '! skipping — no %s fixture seeded in this install', $competition_id ) );
		self::finish();
		exit( 0 );
	}

	/**
	 * Override the site vocab for the rest of a scenario. Pass a country
	 * code that's in Mantia_Vocab::VOCAB (e.g. 'AR', 'BR', 'UY'), or ''
	 * to delete the option entirely and exercise the "unset → default
	 * fallback" path.
	 */
	public static function vocab_country( string $country_code ): void {
		if ( '' === $country_code ) {
			delete_option( 'mantia_default_country' );
			return;
		}
		update_option( 'mantia_default_country', strtoupper( $country_code ) );
	}

	/**
	 * Re-enable auto-fill for scenarios that specifically test it.
	 * Defaults off in the suite so we don't have to update every
	 * existing test that walks the manual-predict flow.
	 */
	public static function enable_auto_predict(): void {
		remove_filter( 'mantia_auto_predict_on_join', '__return_false' );
	}

	private static function login_as_admin(): void {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		if ( ! empty( $admins ) ) {
			wp_set_current_user( (int) $admins[0] );
		}
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

	/* ──── Ability-driven development helpers ──────────────────────────── */

	/**
	 * Invoke an ability registered via wp_register_ability(). This is the
	 * same call path the agent loop uses — input goes through schema
	 * validation, the execute_callback runs, and the return value lands
	 * here. Use this for ADD unit tests where you exercise one ability
	 * in isolation and assert the output shape + business behavior.
	 *
	 * @return array|WP_Error
	 */
	public static function call_ability( string $name, array $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( null === $ability ) {
			++self::$failures;
			self::log( "    ✗ FAIL: ability {$name} not registered" );
			return new WP_Error( 'mantia_e2e_no_ability', "Ability not registered: {$name}" );
		}
		return $ability->execute( $input );
	}

	/**
	 * Assert that an ability output matches its declared `output_schema`.
	 * Walks `required` + `properties.type` enforcement — the common shape
	 * checks an agent loop relies on. Skips deep validation (no jsonschema
	 * dep) but catches missing keys + wrong primitive types.
	 */
	public static function assert_ability_output( string $ability_name, $result, string $label = '' ): bool {
		++self::$assertions;
		$label = '' !== $label ? $label : "{$ability_name} output";
		if ( is_wp_error( $result ) ) {
			++self::$failures;
			self::log( "    ✗ FAIL: {$label} returned WP_Error: " . $result->get_error_message() );
			return false;
		}
		if ( ! is_array( $result ) ) {
			++self::$failures;
			self::log( "    ✗ FAIL: {$label} not an array — got " . gettype( $result ) );
			return false;
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
		if ( null === $ability ) {
			self::log( "    · {$label} — no schema (ability not loaded), passing" );
			return true;
		}
		$schema = method_exists( $ability, 'get_output_schema' )
			? $ability->get_output_schema()
			: array();
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			self::log( "    · {$label} — no output_schema declared, passing" );
			return true;
		}

		$required   = (array) ( $schema['required'] ?? array() );
		$properties = (array) ( $schema['properties'] ?? array() );
		$missing    = array();
		$wrong_type = array();
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $result ) ) {
				$missing[] = $key;
				continue;
			}
			$expected_type = (string) ( $properties[ $key ]['type'] ?? '' );
			if ( '' === $expected_type ) {
				continue;
			}
			$actual_type = self::json_type_of( $result[ $key ] );
			if ( $expected_type !== $actual_type ) {
				$wrong_type[] = "{$key}: schema={$expected_type}, got={$actual_type}";
			}
		}
		if ( ! empty( $missing ) || ! empty( $wrong_type ) ) {
			++self::$failures;
			self::log( "    ✗ FAIL: {$label}" );
			if ( ! empty( $missing ) ) {
				self::log( "      missing required: " . implode( ', ', $missing ) );
			}
			if ( ! empty( $wrong_type ) ) {
				self::log( "      type mismatch: " . implode( '; ', $wrong_type ) );
			}
			return false;
		}
		self::log( "    ✓ {$label} matches output_schema" );
		return true;
	}

	private static function json_type_of( $value ): string {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			return $is_list ? 'array' : 'object';
		}
		if ( is_int( $value ) ) return 'integer';
		if ( is_float( $value ) ) return 'number';
		if ( is_bool( $value ) ) return 'boolean';
		if ( is_string( $value ) ) return 'string';
		if ( null === $value ) return 'null';
		return 'unknown';
	}

	public static function assert_true( bool $condition, string $label ): bool {
		++self::$assertions;
		if ( $condition ) {
			self::log( "    ✓ {$label}" );
			return true;
		}
		++self::$failures;
		self::log( "    ✗ FAIL: {$label}" );
		return false;
	}

	public static function assert_not_null( $value, string $label ): bool {
		return self::assert_true( null !== $value, $label );
	}

	/**
	 * Delete a single persona's user, their groups, and predictions. Used
	 * by ability/flow tests that want to start from a known-clean state
	 * without nuking other E2E test data running in parallel.
	 */
	public static function cleanup_persona( array $persona ): void {
		$phone = (string) ( $persona['phone'] ?? '' );
		if ( '' === $phone ) {
			return;
		}
		$user = Mantia_Repository::find_user_by_phone( $phone );
		if ( ! $user ) {
			return;
		}
		$user_id = (int) $user->ID;

		// Delete this user's predictions across every group.
		$pred_ids = get_posts( array(
			'post_type'      => Mantia_CPTs::PREDICTION,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => Mantia_Repository::META_USER_ID, 'value' => $user_id ),
			),
		) );
		foreach ( $pred_ids as $pid ) {
			wp_delete_post( (int) $pid, true );
		}

		// Delete groups this user is in where they are the only member.
		// Post-Phase-6 the group list lives in user_meta on the wp_user.
		$groups = (array) get_user_meta( $user_id, Mantia_Repository::META_GROUP_IDS, true );
		foreach ( array_map( 'intval', $groups ) as $gid ) {
			if ( $gid <= 0 ) continue;
			$members = Mantia_Repository::group_members( $gid );
			$has_other = false;
			foreach ( $members as $m ) {
				if ( (int) $m['id'] !== $user_id ) { $has_other = true; break; }
			}
			if ( ! $has_other ) {
				wp_delete_post( $gid, true );
			}
		}

		// Identity itself is a wp_user now, not a CPT post.
		wp_delete_user( $user_id );
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
	 * container; the cross-container hostname is `http://wordpress`. The
	 * CI workflow sets the `mantia_e2e_base_url` option once at boot;
	 * locally it falls back to home_url().
	 */
	private static function http_url( string $path ): string {
		$base = self::base_url_override();
		if ( '' === $base ) {
			return home_url( $path );
		}
		return rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * When we override the URL host we have to pass the canonical Host
	 * header so WP's canonical_redirect doesn't 301 us off the docker
	 * hostname and back to localhost:8889 (unreachable from inside cli).
	 */
	private static function http_args(): array {
		$args = array( 'timeout' => 15, 'redirection' => 3 );
		if ( '' !== self::base_url_override() ) {
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

	private static function base_url_override(): string {
		$opt = (string) get_option( 'mantia_e2e_base_url', '' );
		if ( '' !== $opt ) {
			return $opt;
		}
		return (string) getenv( 'MANTIA_E2E_BASE_URL' );
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
		self::snapshot_match( $match_id );

		$past = gmdate( 'Y-m-d H:i:s', time() - 90 * MINUTE_IN_SECONDS );
		update_post_meta( $match_id, Mantia_Repository::META_KICKOFF_GMT, $past );
		update_post_meta( $match_id, Mantia_Repository::META_KICKOFF_TS, time() - 90 * MINUTE_IN_SECONDS );
		update_post_meta( $match_id, Mantia_Repository::META_STATUS, 'finished' );
		update_post_meta( $match_id, Mantia_Repository::META_HOME_SCORE, $home );
		update_post_meta( $match_id, Mantia_Repository::META_AWAY_SCORE, $away );
		update_post_meta( $match_id, Mantia_Repository::META_RESOLVED, 0 );
	}

	/**
	 * Snapshot a match's mutable state into a sidecar meta so cleanup() can
	 * restore it. Idempotent — repeated calls don't clobber the original
	 * snapshot (only the first one matters).
	 */
	private static function snapshot_match( int $match_id ): void {
		// The snapshot meta is stored as an associative array, so we can't
		// cast it to string for the empty check — that warns "Array to
		// string conversion". A simple is_array() guard is enough since
		// the only thing we ever store under this key is the snapshot itself.
		$existing = get_post_meta( $match_id, '_mantia_e2e_snapshot', true );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			return;
		}
		$snapshot = array(
			'kickoff_gmt' => (string) get_post_meta( $match_id, Mantia_Repository::META_KICKOFF_GMT, true ),
			'kickoff_ts'  => (int) get_post_meta( $match_id, Mantia_Repository::META_KICKOFF_TS, true ),
			'status'      => (string) get_post_meta( $match_id, Mantia_Repository::META_STATUS, true ),
			'home_score'  => get_post_meta( $match_id, Mantia_Repository::META_HOME_SCORE, true ),
			'away_score'  => get_post_meta( $match_id, Mantia_Repository::META_AWAY_SCORE, true ),
			'resolved'    => (int) get_post_meta( $match_id, Mantia_Repository::META_RESOLVED, true ),
		);
		update_post_meta( $match_id, '_mantia_e2e_snapshot', $snapshot );
	}

	/**
	 * Re-time a seeded match to kick off N minutes from now. Picks the
	 * first seeded match (optionally filtered by competition) when
	 * $match_id is 0. Snapshots the pre-test state so cleanup() restores
	 * exactly — same mechanism finish_match() uses, so multiple time-
	 * travel calls compose cleanly within one scenario.
	 *
	 * @return array{id:int, home_team:string, away_team:string, kickoff_ts:int, competition_id:string}
	 */
	public static function schedule_match_in_minutes( int $minutes, string $competition_id = '', int $match_id = 0 ): array {
		if ( 0 === $match_id ) {
			$candidates = '' !== $competition_id
				? Mantia_Repository::upcoming_matches_for_competition( $competition_id, 24 * 365 )
				: Mantia_Repository::upcoming_matches( 24 * 365 );

			// Fall back to ANY seeded match (incl. finished ones) if no upcoming.
			if ( empty( $candidates ) ) {
				$ids = get_posts( array(
					'post_type'      => Mantia_CPTs::MATCH,
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				) );
				if ( empty( $ids ) ) {
					self::log( "    ✗ FAIL: no seeded matches to time-travel — run the fixture seeder first" );
					return array();
				}
				$match_id = (int) $ids[0];
			} else {
				$match_id = (int) $candidates[0]['id'];
			}
		}

		self::snapshot_match( $match_id );

		$future_ts  = time() + max( 1, $minutes ) * MINUTE_IN_SECONDS;
		$future_gmt = gmdate( 'Y-m-d H:i:s', $future_ts );
		update_post_meta( $match_id, Mantia_Repository::META_KICKOFF_GMT, $future_gmt );
		update_post_meta( $match_id, Mantia_Repository::META_KICKOFF_TS, $future_ts );
		update_post_meta( $match_id, Mantia_Repository::META_STATUS, 'scheduled' );
		update_post_meta( $match_id, Mantia_Repository::META_HOME_SCORE, '' );
		update_post_meta( $match_id, Mantia_Repository::META_AWAY_SCORE, '' );
		update_post_meta( $match_id, Mantia_Repository::META_RESOLVED, 0 );

		$match = Mantia_Repository::match_to_array( $match_id );
		self::log( sprintf(
			'    · re-timed match #%d (%s vs %s) → kickoff in %d min (%s UTC)',
			$match_id,
			$match['home_team'] ?? '?',
			$match['away_team'] ?? '?',
			$minutes,
			$future_gmt
		) );
		return $match;
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

		// 1. Test users — wp_users with the test phone prefix.
		$user_objs = get_users( array(
			'meta_key'     => Mantia_Repository::META_PHONE,
			'meta_value'   => self::TEST_PHONE_PREFIX,
			'meta_compare' => 'LIKE',
			'number'       => -1,
			'fields'       => 'all',
		) );
		$users = array_map( static fn ( $u ): int => (int) $u->ID, $user_objs );

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

		// 4. Test users themselves. wp_users is a separate table from
		// wp_posts — must use wp_delete_user, not wp_delete_post. (The
		// previous wp_delete_post call was a no-op for users, which is
		// how stale META_GROUP_IDS survived across runs and made each
		// rerun show the same persona in N+1 phantom groups.)
		foreach ( $users as $uid ) {
			wp_delete_user( (int) $uid );
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

	public static function user_by_phone( string $phone ): ?WP_User {
		return Mantia_Repository::find_user_by_phone( $phone );
	}

	/**
	 * Bootstrap a penca via the WhatsApp flow in three turns:
	 *   1. crear penca <name> → bot replies with competition picker
	 *   2. mantia:newcomp:<competition_id> → bot asks for name
	 *   3. <name> → bot confirms "Creaste *<name>* para ..."
	 *
	 * Test-friendly helper: the picker step doesn't reuse the name from
	 * step 1 (handle_competition_picked_for_new ignores pending_create),
	 * so the test has to send the name twice. Centralised here so each
	 * ability/flow test doesn't repeat the dance.
	 *
	 * @return array The reply from the final 'name' turn (carries the
	 *               "Creaste" confirmation + group context).
	 */
	public static function create_penca_via_chat( array $persona, string $name, string $competition_id = 'libertadores-semana' ): array {
		self::send( $persona, 'crear penca ' . $name );
		self::send( $persona, 'mantia:newcomp:' . $competition_id );
		return self::send( $persona, $name );
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
