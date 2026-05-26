<?php
/**
 * Three-user manual-verification walkthrough.
 *
 * Builds a complete world: a match starting in ~60 minutes, three
 * personas who invite each other, three predictions, the match
 * finishing, and resolution scoring. Exercises both the WhatsApp side
 * (every user types into the bot through the same preflight openclaWP
 * uses) AND the web side (every per-user signed URL + the group page
 * + the competition standings).
 *
 * Designed for hands-on verification: after the scenario passes, the
 * URLs printed at the end stay live for ~90 minutes (the match is real,
 * the magic links are real, the predictions are persisted). You can
 * paste them into a browser to poke at the rendering manually before
 * the next run's cleanup() removes the personas.
 *
 * Run:
 *   bin/e2e.sh three-user-walkthrough
 *
 * Or directly:
 *   wp eval-file tests/e2e/three-user-walkthrough.php
 *
 * Re-runnable: cleanup() at start nukes any prior 9999000* persona, and
 * the snapshot/restore mechanic puts the match back to its original
 * fixture state on every cleanup so seeds aren't permanently altered.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Three-user walkthrough — match in 60 min, full WA + web' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Clean slate — restore matches, drop test personas' );
/* ─────────────────────────────────────────────────────────────────────── */
$wiped = Mantia_E2E::cleanup();
fwrite( STDOUT, "    · removed {$wiped} stale test artifacts\n" );

// vocab_country, competition-graph fix-up, and OrbStack no_proxy setup
// all happen inside Mantia_E2E::start() — see lib.php for details. Tests
// that need different config can override after this point.

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob',   2 );
$carla = Mantia_E2E::persona( 'Carla', 3 );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Schedule a real match to kick off in 60 minutes' );
/* ─────────────────────────────────────────────────────────────────────── */
$competition_id = 'libertadores-semana';
$match = Mantia_E2E::schedule_match_in_minutes( 60, $competition_id );
if ( empty( $match ) ) {
	Mantia_E2E::step( '! no seeded match for libertadores-semana — aborting' );
	Mantia_E2E::finish();
	return;
}
$match_id  = (int) $match['id'];
$home_team = (string) ( $match['home_team'] ?? '' );
$away_team = (string) ( $match['away_team'] ?? '' );
Mantia_E2E::assert_true( $match_id > 0,                  'match scheduled in next hour' );
Mantia_E2E::assert_true( '' !== $home_team && '' !== $away_team, 'match has both teams populated' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Alice arrives cold → sees onboarding menu' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $alice, 'hola' );
Mantia_E2E::assert_contains( $r, 'Crear penca', 'onboarding offers create CTA' );
Mantia_E2E::assert_contains( $r, 'Tengo código', 'onboarding offers join-by-code CTA' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Alice taps Crear penca → competition picker' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::assert_eq( 'list', $r['interactive']['type'] ?? '', 'picker uses list type' );

$r = Mantia_E2E::send( $alice, 'mantia:newcomp:' . $competition_id );
Mantia_E2E::assert_contains( $r, '¿cómo se va a llamar', 'asks for the name' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. Alice names it → group created with invite code' );
/* ─────────────────────────────────────────────────────────────────────── */
$penca_name = Mantia_E2E::TEST_NAME_PREFIX . ' Los Tres';
$r = Mantia_E2E::send( $alice, $penca_name );
Mantia_E2E::assert_contains( $r, 'Creaste', 'creation confirmed' );
Mantia_E2E::assert_contains( $r, $penca_name, 'reply mentions penca name' );

global $wpdb;
$group_id = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s ORDER BY ID DESC LIMIT 1",
	Mantia_CPTs::GROUP,
	$penca_name
) );
Mantia_E2E::assert_true( $group_id > 0, 'group resolved in CPT' );
$group      = Mantia_Repository::group_to_array( $group_id );
$invite     = (string) $group['invite_code'];
$view_token = Mantia_Repository::group_view_token( $group_id );
$slug       = (string) get_post_meta( $group_id, Mantia_Repository::META_GROUP_SLUG, true );
Mantia_E2E::assert_eq( $competition_id, (string) $group['competition_id'], 'group bound to Libertadores-semana' );
Mantia_E2E::assert_true( '' !== $invite, 'invite code minted' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. Bob receives the code by WhatsApp forward → joins' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $bob, $invite );
Mantia_E2E::assert_contains( $r, $penca_name, 'Bob welcomed into the right penca' );
Mantia_E2E::assert_contains( $r, 'sume', 'reply confirms join' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '6. Carla joins the same group' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::send( $carla, $invite );

$expected = array( 'Alice' => true, 'Bob' => true, 'Carla' => true );
$actual   = array();
foreach ( array( $alice, $bob, $carla ) as $p ) {
	$u = Mantia_Repository::find_user_by_phone( $p['phone'] );
	// META_GROUP_IDS values come back as strings from get_user_meta —
	// cast to int before comparing so a strict in_array() works.
	$ids = $u ? array_map( 'intval', (array) get_user_meta( (int) $u->ID, Mantia_Repository::META_GROUP_IDS, true ) ) : array();
	$actual[ $p['name'] ] = $u && in_array( $group_id, $ids, true );
}
Mantia_E2E::assert_eq( $expected, $actual, 'all 3 personas are members' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '7. Bob predicts 2-1 (the exact score)' );
/* ─────────────────────────────────────────────────────────────────────── */
// Use an explicit match_id so we exercise auto-routing without relying on
// fuzzy team-name resolution (which depends on alias seeds and is tested
// separately in tests/e2e/easy-predicting.php).
$bob_pred = Mantia_Abilities::register_prediction( array(
	'user_phone'  => $bob['phone'],
	'match_id'    => $match_id,
	'home_score'  => 2,
	'away_score'  => 1,
) );
Mantia_E2E::assert_true( ! is_wp_error( $bob_pred ), 'Bob prediction call returned ok' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8. Alice predicts 3-2 (right winner, wrong score)' );
/* ─────────────────────────────────────────────────────────────────────── */
$alice_pred = Mantia_Abilities::register_prediction( array(
	'user_phone'  => $alice['phone'],
	'match_id'    => $match_id,
	'home_score'  => 3,
	'away_score'  => 2,
) );
Mantia_E2E::assert_true( ! is_wp_error( $alice_pred ), 'Alice prediction call returned ok' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '9. Carla predicts 0-0 (a draw — misses completely)' );
/* ─────────────────────────────────────────────────────────────────────── */
$carla_pred = Mantia_Abilities::register_prediction( array(
	'user_phone'  => $carla['phone'],
	'match_id'    => $match_id,
	'home_score'  => 0,
	'away_score'  => 0,
) );
Mantia_E2E::assert_true( ! is_wp_error( $carla_pred ), 'Carla prediction call returned ok' );

// Sanity: 3 predictions persisted against this group + match.
$alice_post = Mantia_Repository::find_user_by_phone( $alice['phone'] );
$bob_post   = Mantia_Repository::find_user_by_phone( $bob['phone'] );
$carla_post = Mantia_Repository::find_user_by_phone( $carla['phone'] );
foreach ( array( 'Alice' => $alice_post, 'Bob' => $bob_post, 'Carla' => $carla_post ) as $label => $u ) {
	$has = $u && Mantia_Repository::find_prediction( (int) $u->ID, $match_id, $group_id );
	Mantia_E2E::assert_true( (bool) $has, "{$label}'s prediction persisted in CPT" );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '10. WhatsApp menu surfaces for each persona' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $alice, 'hoy' );
Mantia_E2E::assert_contains( $r, $penca_name, 'Alice "hoy" names her penca' );

$r = Mantia_E2E::send( $bob, 'mis pencas' );
Mantia_E2E::assert_contains( $r, $penca_name, 'Bob "mis pencas" lists shared penca' );

$r = Mantia_E2E::send( $carla, 'tabla' );
Mantia_E2E::assert_contains( $r, $penca_name, 'Carla "tabla" replies in penca context' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '11. Web surfaces — pre-match (predictions hidden, scoreboard zero)' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $view_token . '/',     array( $penca_name ) );
Mantia_E2E::assert_http_ok( '/pronostico/' . $competition_id . '/',   array() );
Mantia_E2E::assert_http_ok( '/pronostico/sumate/' . $invite . '/',    array( $penca_name ) );

$alice_token = Mantia_Repository::user_view_token( (int) $alice_post->ID );
$bob_token   = Mantia_Repository::user_view_token( (int) $bob_post->ID );
$carla_token = Mantia_Repository::user_view_token( (int) $carla_post->ID );

// /pronostico/me/<token>/ — the auth-gated personal view (token in URL stands
// in for the magic-link login the production flow goes through).
Mantia_E2E::assert_http_ok( '/pronostico/me/' . $alice_token . '/', array() );
Mantia_E2E::assert_http_ok( '/pronostico/me/' . $bob_token   . '/', array() );
Mantia_E2E::assert_http_ok( '/pronostico/me/' . $carla_token . '/', array() );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '12. Time-travel: match finishes 2-1 (Bob nails it)' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::finish_match( $match_id, 2, 1 );
$resolve = Mantia_Abilities::resolve_match( array( 'match_id' => $match_id ) );
Mantia_E2E::assert_true( ! is_wp_error( $resolve ), 'match resolution returned ok' );

$leaderboard = Mantia_Leaderboard::rows( $group_id, 50 );
$by_name     = array();
foreach ( $leaderboard as $row ) {
	$by_name[ (string) $row['name'] ] = (int) $row['points'];
}
fwrite( STDOUT, "    · leaderboard: " . wp_json_encode( $by_name ) . "\n" );

// The leaderboard rows are keyed by the WP_User display_name (which is
// what the bot copies print too). Use the User object directly — NOT
// get_the_title() on the user ID, since user_ids and post_ids live in
// separate tables and a coincidental collision (e.g. user_id=3 vs the
// "Privacy Policy" post) would return junk.
$alice_name = (string) $alice_post->display_name;
$bob_name   = (string) $bob_post->display_name;
$carla_name = (string) $carla_post->display_name;

// Default scoring: exact=5, diff=3, winner=1.
// 2-1 (real) vs Bob 2-1 → exact = 5
// 2-1 (real) vs Alice 3-2 → both +1 home wins, diff matches = 3
// 2-1 (real) vs Carla 0-0 → draw, wrong winner = 0
Mantia_E2E::assert_eq( 5, $by_name[ $bob_name ]   ?? -1, "Bob ({$bob_name}): exact = 5 pts" );
Mantia_E2E::assert_eq( 3, $by_name[ $alice_name ] ?? -1, "Alice ({$alice_name}): diff = 3 pts" );
Mantia_E2E::assert_eq( 0, $by_name[ $carla_name ] ?? -1, "Carla ({$carla_name}): miss = 0 pts" );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '13. Post-match WhatsApp — tabla shows the order' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $bob, 'tabla' );
Mantia_E2E::assert_contains( $r, $bob_name,   'tabla lists Bob' );
Mantia_E2E::assert_contains( $r, $alice_name, 'tabla lists Alice' );
Mantia_E2E::assert_contains( $r, $carla_name, 'tabla lists Carla' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '14. Post-match web — standings reflect points' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $view_token . '/', array( $bob_name, $alice_name, $carla_name ) );
Mantia_E2E::assert_http_ok( '/pronostico/me/' . $bob_token . '/', array( '5' ) );

/* ─────────────────────────────────────────────────────────────────────── */
fwrite( STDOUT, "\n" . str_repeat( '═', 72 ) . "\n" );
fwrite( STDOUT, "🔗  Live URLs — paste into a browser to inspect manually\n" );
fwrite( STDOUT, "    (artifacts persist until the next walkthrough run)\n" );
fwrite( STDOUT, str_repeat( '═', 72 ) . "\n\n" );

// Use the public home_url for the printed URLs (those go into a browser),
// NOT the e2e_base_url override (that's the cross-container hostname used
// by the test harness's internal HTTP probes — it isn't reachable from
// outside Docker).
$base = rtrim( (string) home_url(), '/' );
$urls   = array(
	'  group page (public)        ' => $base . '/pronostico/g/' . $view_token . '/',
	'  group page (slug)          ' => '' !== $slug ? $base . '/pronostico/g/' . $slug . '/' : '(no slug)',
	'  group invite landing       ' => $base . '/pronostico/sumate/' . $invite . '/',
	'  competition standings      ' => $base . '/pronostico/' . $competition_id . '/',
	'  Alice — personal /me/      ' => '' !== $alice_token ? $base . '/pronostico/me/' . $alice_token . '/' : '(no token)',
	'  Bob   — personal /me/      ' => '' !== $bob_token   ? $base . '/pronostico/me/' . $bob_token   . '/' : '(no token)',
	'  Carla — personal /me/      ' => '' !== $carla_token ? $base . '/pronostico/me/' . $carla_token . '/' : '(no token)',
	'  Alice — signed magic link  ' => Mantia_Repository::user_view_url( (int) $alice_post->ID ),
	'  Bob   — signed magic link  ' => Mantia_Repository::user_view_url( (int) $bob_post->ID ),
	'  Carla — signed magic link  ' => Mantia_Repository::user_view_url( (int) $carla_post->ID ),
);
foreach ( $urls as $label => $url ) {
	fwrite( STDOUT, "{$label}{$url}\n" );
}

fwrite( STDOUT, "\n  Test phones (use with bin/sim-wa.sh for one-off pokes):\n" );
fwrite( STDOUT, "    Alice  +{$alice['phone']}\n" );
fwrite( STDOUT, "    Bob    +{$bob['phone']}\n" );
fwrite( STDOUT, "    Carla  +{$carla['phone']}\n" );

fwrite( STDOUT, "\n  Invite code: {$invite}\n" );
fwrite( STDOUT, "  Match #{$match_id}: {$home_team} vs {$away_team} (finished 2-1)\n" );
fwrite( STDOUT, str_repeat( '═', 72 ) . "\n\n" );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '15. Finish — leave artifacts in place for browser poking' );
/* ─────────────────────────────────────────────────────────────────────── */
// Deliberately NOT calling cleanup() here. The next walkthrough run will
// nuke this state automatically. Until then, the URLs above stay live so
// you can paste them into a browser and verify rendering by hand. Run
// `bin/e2e.sh cleanup` (or just kick off another walkthrough) when done.
fwrite( STDOUT, "    · artifacts preserved — paste the URLs above into a browser to verify\n" );

Mantia_E2E::finish();
