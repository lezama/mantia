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

echo "\nResult: $passed passed, $failed failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
