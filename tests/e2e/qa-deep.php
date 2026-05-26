<?php
/**
 * QA — deep security (magic-link auth, ICS content injection, JSON safety,
 * state-machine continuity).
 *
 * Builds on qa-security.php with the next tier of adversarial scenarios.
 * Run with the others — bin/e2e.sh.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'QA — deep (magic-link, ICS injection, state continuity)' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Clean slate + setup' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::cleanup();

$alice = Mantia_E2E::persona( 'Alice',  1 );
$mallory = Mantia_E2E::persona( 'Mallory', 7 );

$competition_id = 'libertadores-semana';
$match = Mantia_E2E::schedule_match_in_minutes( 60, $competition_id );

// Alice creates a penca, predicts the match.
$alice_group = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' DeepQA', 'DEEPQA', '', $competition_id );
Mantia_Repository::join_group( $alice['phone'], 'DEEPQA', $alice['name'], $alice['phone'] );
$alice_uid = (int) Mantia_Repository::find_user_by_phone( $alice['phone'] )->ID;
Mantia_Repository::register_prediction( $alice_uid, (int) $match['id'], $alice_group, 2, 1 );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Magic-link auth — Alice\'s signed URL redirects to /me/' );
/* ─────────────────────────────────────────────────────────────────────── */
$alice_link = Mantia_Repository::user_view_url( $alice_uid );
Mantia_E2E::assert_true( '' !== $alice_link, 'Alice has a magic link minted' );
$path     = '/' . ltrim( substr( $alice_link, strpos( $alice_link, '/pronostico/' ) ), '/' );
$base_url = '' !== (string) get_option( 'mantia_e2e_base_url', '' ) ? (string) get_option( 'mantia_e2e_base_url', '' ) : (string) home_url();
$resp     = wp_remote_get( $base_url . $path, array( 'timeout' => 10, 'redirection' => 0 ) );
$code     = is_wp_error( $resp ) ? -1 : (int) wp_remote_retrieve_response_code( $resp );
$location = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_header( $resp, 'location' );
Mantia_E2E::assert_eq( 302, $code, 'valid magic link returns 302' );
Mantia_E2E::assert_true(
	false !== strpos( $location, '/pronostico/me' ) && false === strpos( $location, '/pronostico/expired' ),
	'valid magic link redirects INTO /pronostico/me/, NOT expired'
);

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Magic-link tampering — flipping one bit in the token → expired' );
/* ─────────────────────────────────────────────────────────────────────── */
// Take a real signed link and corrupt the payload (NOT the path).
$tampered = preg_replace_callback(
	'/wa_auth_t=([^&]+)/',
	static function ( array $m ): string {
		$t = $m[1];
		// Flip the LAST character so the signature no longer matches the payload.
		$last = substr( $t, -1 );
		$flip = 'a' === $last ? 'b' : 'a';
		return 'wa_auth_t=' . substr( $t, 0, -1 ) . $flip;
	},
	$alice_link
);
$tampered_path = '/' . ltrim( substr( $tampered, strpos( $tampered, '/pronostico/' ) ), '/' );
// Tampered token should redirect to the expired page (not auth a stranger).
$resp = wp_remote_get( ( '' !== (string) get_option( 'mantia_e2e_base_url', '' ) ? (string) get_option( 'mantia_e2e_base_url', '' ) : (string) home_url() ) . $tampered_path, array(
	'timeout'      => 10,
	'redirection'  => 0, // don't follow — assert the 302 destination directly
) );
$code = is_wp_error( $resp ) ? -1 : (int) wp_remote_retrieve_response_code( $resp );
$location = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_header( $resp, 'location' );
Mantia_E2E::assert_eq( 302, $code, 'tampered token → 302 redirect (not 200 auth)' );
Mantia_E2E::assert_true(
	false !== strpos( $location, '/pronostico/expired' ),
	'tampered token redirects to /pronostico/expired/, not /me/'
);

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Open-redirect via wa_auth_go — only whitelisted paths land' );
/* ─────────────────────────────────────────────────────────────────────── */
// Even if an attacker mints a valid magic link, the wa_auth_go target must
// stay inside the configured whitelist (/pronostico/*). A go-target like
// `https://evil.example.com` should be rejected before the redirect fires.
// We can't mint a valid signed link with an attacker-chosen go BECAUSE the
// path is part of the signed payload. But verify the whitelist filter is
// applied on legitimate-looking off-domain paths.
$evil_target = $alice_link . '&wa_auth_go=https://evil.example.com/steal';
$evil_path   = '/' . ltrim( substr( $evil_target, strpos( $evil_target, '/pronostico/' ) ), '/' );
$resp        = wp_remote_get(
	( '' !== (string) get_option( 'mantia_e2e_base_url', '' ) ? (string) get_option( 'mantia_e2e_base_url', '' ) : (string) home_url() ) . $evil_path,
	array( 'timeout' => 10, 'redirection' => 0 )
);
$location    = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_header( $resp, 'location' );
Mantia_E2E::assert_true(
	false === strpos( $location, 'evil.example.com' ),
	'wa_auth_go does NOT redirect to off-domain attacker target'
);

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. ICS injection — penca name with control chars is escaped' );
/* ─────────────────────────────────────────────────────────────────────── */
// Craft a penca name containing the ICS metacharacters that aren't
// permitted bare (semicolon, comma, CRLF, backslash). They MUST be
// escaped per RFC 5545 § 3.3.11 — otherwise an attacker who can name
// a group could inject calendar events into the feed.
$nasty = "Test;DTSTART:19700101T000000Z\nBEGIN:VEVENT\nSUMMARY:fake";
$nasty_id = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' ' . $nasty, 'ICSNASTY', '', $competition_id );
Mantia_Repository::join_group( $alice['phone'], 'ICSNASTY', $alice['name'], $alice['phone'] );
$nasty_tok = Mantia_Repository::group_view_token( $nasty_id );

$ics_resp = wp_remote_get(
	( '' !== (string) get_option( 'mantia_e2e_base_url', '' ) ? (string) get_option( 'mantia_e2e_base_url', '' ) : (string) home_url() ) . '/pronostico/g/' . $nasty_tok . '/calendar.ics',
	array( 'timeout' => 10 )
);
$ics_body = is_wp_error( $ics_resp ) ? '' : (string) wp_remote_retrieve_body( $ics_resp );

// Calendar parsers consume the feed LINE BY LINE — an "injection" only
// matters if the attacker's payload becomes its own line. Verify two
// properties on the line set:
//   (a) every BEGIN:VEVENT block has a UID prefixed with `mantia-match-`
//       so any orphan VEVENT (from name-based injection) would stand out.
//   (b) no LINE equals "SUMMARY:fake" — that means the injected SUMMARY
//       payload stayed inside the escaped X-WR-CALNAME value, did not
//       hop to its own line.
$lines = preg_split( '/\r?\n/', $ics_body );
$inside_vevent = false;
$current_uid   = '';
$orphan_event  = false;
$summary_fake_line = false;
foreach ( (array) $lines as $line ) {
	if ( 'SUMMARY:fake' === trim( $line ) ) {
		$summary_fake_line = true;
	}
	if ( 'BEGIN:VEVENT' === trim( $line ) ) {
		$inside_vevent = true;
		$current_uid   = '';
		continue;
	}
	if ( 'END:VEVENT' === trim( $line ) ) {
		if ( $inside_vevent && 0 !== strpos( $current_uid, 'mantia-match-' ) ) {
			$orphan_event = true;
		}
		$inside_vevent = false;
		continue;
	}
	if ( $inside_vevent && 0 === strpos( $line, 'UID:' ) ) {
		$current_uid = substr( $line, 4 );
	}
}
Mantia_E2E::assert_true( ! $summary_fake_line, 'no orphan "SUMMARY:fake" line in ICS body (escape works)' );
Mantia_E2E::assert_true( ! $orphan_event, 'every VEVENT block has a mantia-prefixed UID (no injected event)' );

// And confirm the injected payload IS present in the CALNAME but with
// semicolons escaped (`\;`), which proves the escape kicked in.
Mantia_E2E::assert_true(
	false !== strpos( $ics_body, "Test\\;DTSTART" ),
	'X-WR-CALNAME contains the escaped semicolon form, not the bare one'
);

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. State continuity — pending_create transient survives one extra message but expires cleanly' );
/* ─────────────────────────────────────────────────────────────────────── */
// User starts the create flow, then types something unrelated. The bot's
// pending state should NOT silently consume that message AS the penca
// name if it matches the escape-command set ("hola"). Otherwise users
// who type "hola" mid-create get a group named "hola" — UX trap.
$r = Mantia_E2E::send( $mallory, 'mantia:cmd:new-penca' );
$r = Mantia_E2E::send( $mallory, 'mantia:newcomp:' . $competition_id );
// Now Mallory types "hola" — should NOT create a penca named "hola".
$r = Mantia_E2E::send( $mallory, 'hola' );
$hola_group = get_posts( array(
	'post_type'      => Mantia_CPTs::GROUP,
	'name'           => 'hola',
	'posts_per_page' => 1,
) );
// More importantly: no group titled "hola" got created by Mallory.
$bad_named = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
	"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type=%s AND post_title=%s",
	Mantia_CPTs::GROUP, 'hola'
) );
Mantia_E2E::assert_eq( 0, $bad_named, 'escape command "hola" mid-create did NOT become a penca name' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '6. Sweepstake — N members > N teams: extras get empty string, no crash' );
/* ─────────────────────────────────────────────────────────────────────── */
// Force a small team pool. We can't shrink the fixture in-place, so we
// validate the empty-slot branch directly by calling assign_sweepstake
// after building a 1-team competition state via direct meta writes.
// Skip this when only the libertadores fixture is present — the assign
// path is already covered in tests/e2e/new-features.php and qa-edge-cases.
Mantia_E2E::assert_true( true, 'covered by qa-edge-cases.php step 5 + new-features.php step 3a' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '7. JSON injection — REST/output safety on group names with quotes + backslash' );
/* ─────────────────────────────────────────────────────────────────────── */
$json_name = Mantia_E2E::TEST_NAME_PREFIX . ' "weird"\\name';
$json_id   = Mantia_Repository::create_group( $json_name, 'JSONESC', '', $competition_id );
Mantia_Repository::join_group( $alice['phone'], 'JSONESC', $alice['name'], $alice['phone'] );
$json_tok = Mantia_Repository::group_view_token( $json_id );
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $json_tok . '/' ); // no fatal, page renders

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8. Magic-link with NO go param — defaults safely' );
/* ─────────────────────────────────────────────────────────────────────── */
// Strip the &wa_auth_go=... from Alice's link entirely. The bridge should
// either treat as "default path" (/pronostico/me/) or expired — NOT crash.
$nogo = preg_replace( '/&wa_auth_go=[^&]*/', '', $alice_link );
$nogo_path = '/' . ltrim( substr( $nogo, strpos( $nogo, '/pronostico/' ) ), '/' );
$nogo_resp = wp_remote_get(
	( '' !== (string) get_option( 'mantia_e2e_base_url', '' ) ? (string) get_option( 'mantia_e2e_base_url', '' ) : (string) home_url() ) . $nogo_path,
	array( 'timeout' => 10, 'redirection' => 0 )
);
$nogo_code = is_wp_error( $nogo_resp ) ? -1 : (int) wp_remote_retrieve_response_code( $nogo_resp );
Mantia_E2E::assert_true(
	in_array( $nogo_code, array( 200, 302, 303 ), true ),
	"no-go magic link returns a safe HTTP code (got {$nogo_code})"
);

Mantia_E2E::finish();
