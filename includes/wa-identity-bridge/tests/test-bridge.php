<?php
/**
 * Tests for WA_Identity_Bridge (signed-URL primitives). Runs against a
 * real WP via wp-cli eval-file — no PHPUnit scaffolding needed.
 *
 * Run:
 *   ssh mantia3.wordpress.com@ssh.wp.com "cd htdocs && wp eval-file \
 *     wp-content/plugins/mantia/includes/wa-identity-bridge/tests/test-bridge.php"
 *
 * @package WA_Identity_Bridge
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WA_Identity_Bridge' ) ) {
	require_once __DIR__ . '/../class-wa-identity-bridge.php';
	WA_Identity_Bridge::boot();
}

$passed = 0;
$failed = 0;

$ok = function ( bool $cond, string $msg ) use ( &$passed, &$failed ): void {
	if ( $cond ) {
		++$passed;
		echo "  ✓ $msg\n";
	} else {
		++$failed;
		echo "  ✗ $msg\n";
	}
};

echo "WA_Identity_Bridge tests\n";

remove_all_filters( 'wa_identity_bridge_path_whitelist' );

// --- sign_link returns a URL ---
echo "\n[sign_link]\n";

$payload = array( 'phone' => '99990001234', 'name' => 'Tincho' );
$path    = '/wa-bridge-test/';
$url     = WA_Identity_Bridge::sign_link( $payload, $path );

$ok( '' !== $url, 'sign_link returns a non-empty URL' );
$ok( str_contains( $url, 'wa_auth_t=' ), 'URL carries the t parameter' );
$ok( str_contains( $url, 'wa_auth_go=' ), 'URL carries the go parameter' );

$parts = parse_url( $url );
parse_str( (string) ( $parts['query'] ?? '' ), $q );
$token = (string) ( $q['wa_auth_t'] ?? '' );
$go    = rawurldecode( (string) ( $q['wa_auth_go'] ?? '' ) );

// --- verify_link round-trips the payload ---
echo "\n[verify_link]\n";

$verified = WA_Identity_Bridge::verify_link( $token, $go );
$ok( is_array( $verified ), 'verify_link returns array on success' );
$ok( ( $verified['phone'] ?? '' ) === '99990001234', 'payload phone round-trips' );
$ok( ( $verified['name'] ?? '' ) === 'Tincho', 'payload name round-trips' );

// --- path tampering ---
$bad = WA_Identity_Bridge::verify_link( $token, '/different/' );
$ok( is_wp_error( $bad ) && 'wa_magic_path_mismatch' === $bad->get_error_code(), 'path-mismatch detected' );

// --- signature tampering ---
$tampered = preg_replace_callback( '/\.([0-9a-f]+)$/', function ( $m ) {
	$sig = $m[1];
	$sig[0] = '0' === $sig[0] ? '1' : '0';
	return '.' . $sig;
}, $token );
$bad = WA_Identity_Bridge::verify_link( $tampered, $go );
$ok( is_wp_error( $bad ) && 'wa_magic_bad_sig' === $bad->get_error_code(), 'bad-sig detected' );

// --- malformed token ---
$bad = WA_Identity_Bridge::verify_link( 'not-a-token', $go );
$ok( is_wp_error( $bad ) && 'wa_magic_malformed' === $bad->get_error_code(), 'malformed token rejected' );

// --- single_use ---
echo "\n[single_use]\n";

$su_url = WA_Identity_Bridge::sign_link( $payload, $path, array( 'single_use' => true ) );
parse_str( (string) parse_url( $su_url, PHP_URL_QUERY ), $q );
$su_token = (string) $q['wa_auth_t'];

$r1 = WA_Identity_Bridge::verify_link( $su_token, $path );
$ok( is_array( $r1 ), 'single-use token works on first redemption' );

$r2 = WA_Identity_Bridge::verify_link( $su_token, $path );
$ok( is_wp_error( $r2 ) && 'wa_magic_replay' === $r2->get_error_code(), 'single-use token rejected on replay' );

// Non-single-use survives replay.
$multi_url = WA_Identity_Bridge::sign_link( $payload, $path );
parse_str( (string) parse_url( $multi_url, PHP_URL_QUERY ), $q );
$multi_token = (string) $q['wa_auth_t'];
WA_Identity_Bridge::verify_link( $multi_token, $path );
$rep = WA_Identity_Bridge::verify_link( $multi_token, $path );
$ok( is_array( $rep ), 'multi-use token survives replay' );

// --- ttl ---
echo "\n[ttl]\n";

$short_url   = WA_Identity_Bridge::sign_link( $payload, $path, array( 'ttl' => 60 ) );
parse_str( (string) parse_url( $short_url, PHP_URL_QUERY ), $q );
$short_token = (string) $q['wa_auth_t'];

$parts       = explode( '.', $short_token );
$decoded_b64 = strtr( $parts[0], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[0] ) % 4 ) % 4 );
$envelope    = json_decode( base64_decode( $decoded_b64 ), true );
$ok( is_array( $envelope ) && abs( $envelope['exp'] - ( time() + 60 ) ) <= 2, 'ttl honored in envelope exp' );
$ok( 60 === (int) ( $envelope['exp'] - time() ) || abs( (int) ( $envelope['exp'] - time() ) - 60 ) <= 2, 'ttl close to requested' );

// --- path whitelist ---
echo "\n[path whitelist]\n";

add_filter( 'wa_identity_bridge_path_whitelist', function () {
	return array( '/penca/' );
}, 10, 0 );

$blocked = WA_Identity_Bridge::sign_link( $payload, '/not-allowed/' );
$ok( '' === $blocked, 'sign_link returns empty for path outside whitelist' );

$allowed = WA_Identity_Bridge::sign_link( $payload, '/penca/me/' );
$ok( '' !== $allowed, 'sign_link works for whitelisted prefix' );

// Verify also enforces whitelist.
remove_all_filters( 'wa_identity_bridge_path_whitelist' );
$wide = WA_Identity_Bridge::sign_link( $payload, '/anywhere/' );
add_filter( 'wa_identity_bridge_path_whitelist', function () {
	return array( '/penca/' );
}, 10, 0 );
parse_str( (string) parse_url( $wide, PHP_URL_QUERY ), $q );
$wide_token = (string) $q['wa_auth_t'];
$bad = WA_Identity_Bridge::verify_link( $wide_token, '/anywhere/' );
$ok( is_wp_error( $bad ), 'verify rejects path even with valid sig if outside whitelist' );

remove_all_filters( 'wa_identity_bridge_path_whitelist' );

// --- role registered ---
echo "\n[role]\n";

$slug = WA_Identity_Bridge::role_slug();
$ok( null !== get_role( $slug ), "role '$slug' registered" );

// --- resolver helpers ---
echo "\n[resolver]\n";

$test_phone = '88880009999';
$test_name  = 'Bridge Resolver Test';

// Clean up any leftover from a prior failed run.
$leftover = WA_Identity_Bridge::find_by_phone( $test_phone );
if ( $leftover ) {
	wp_delete_user( $leftover->ID );
}

$user = WA_Identity_Bridge::resolve_or_create( $test_phone, $test_name );
$ok( $user instanceof WP_User, 'resolve_or_create returns WP_User' );
$ok( in_array( $slug, (array) $user->roles, true ), 'created user has WhatsApp role' );
$ok( $user->display_name === $test_name, 'display_name matches input' );
$ok( get_user_meta( $user->ID, WA_Identity_Bridge_User_Resolver::META_PHONE, true ) === $test_phone, 'phone meta stamped' );

// Idempotency.
$user2 = WA_Identity_Bridge::resolve_or_create( $test_phone, $test_name );
$ok( $user2->ID === $user->ID, 'second call returns same user' );

// display_name refresh.
$user3 = WA_Identity_Bridge::resolve_or_create( $test_phone, 'Renamed' );
$ok( $user3->display_name === 'Renamed', 'display_name refreshed on subsequent calls' );

// Empty name does NOT clobber.
$user4 = WA_Identity_Bridge::resolve_or_create( $test_phone, '' );
$ok( $user4->display_name === 'Renamed', 'empty name does NOT clobber existing display_name' );

// find_by_phone hits.
$found = WA_Identity_Bridge::find_by_phone( $test_phone );
$ok( $found instanceof WP_User && $found->ID === $user->ID, 'find_by_phone resolves the same user' );

// Bad phone.
$bad = WA_Identity_Bridge::resolve_or_create( '12' );
$ok( is_wp_error( $bad ), 'too-short phone returns WP_Error' );

wp_delete_user( $user->ID );

// --- default redemption handler (resolver-driven login on click) ---
echo "\n[default redemption]\n";

$leftover = WA_Identity_Bridge::find_by_phone( '88880008888' );
if ( $leftover ) {
	wp_delete_user( $leftover->ID );
}

// Simulate the action firing as the endpoint would.
wp_set_current_user( 0 );
do_action( 'wa_identity_bridge_redemption', array( 'phone' => '88880008888', 'name' => 'Default Flow' ), '/penca/me/' );
$cur = wp_get_current_user();
$ok( $cur && $cur->ID > 0 && '88880008888' === get_user_meta( $cur->ID, WA_Identity_Bridge_User_Resolver::META_PHONE, true ), 'default handler created+logged in user from payload' );
if ( $cur && $cur->ID > 0 ) {
	wp_delete_user( $cur->ID );
}
wp_set_current_user( 0 );

// Verify it bails when a consumer hook already logged someone in.
$consumer_user = WA_Identity_Bridge::resolve_or_create( '88880007777', 'Consumer Owned' );
add_action( 'wa_identity_bridge_redemption', function () use ( $consumer_user ) {
	WA_Identity_Bridge::login_as( $consumer_user->ID );
}, 5, 0 );
do_action( 'wa_identity_bridge_redemption', array( 'phone' => '88880006666', 'name' => 'Should Be Ignored' ), '/penca/me/' );
$cur = wp_get_current_user();
$ok( $cur && $cur->ID === $consumer_user->ID, 'default handler defers to consumer hook' );
// Make sure the phone 88880006666 was NOT silently created in the background.
$ignored = WA_Identity_Bridge::find_by_phone( '88880006666' );
$ok( null === $ignored, 'default handler did not create user when consumer already logged in' );
wp_delete_user( $consumer_user->ID );
wp_set_current_user( 0 );
remove_all_actions( 'wa_identity_bridge_redemption' );
// Re-attach the default we just removed so the rest of the suite is clean.
add_action( 'wa_identity_bridge_redemption', array( 'WA_Identity_Bridge', 'default_redemption_handler' ), 1000, 2 );

echo "\nResult: $passed passed, $failed failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
