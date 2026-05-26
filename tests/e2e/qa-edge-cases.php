<?php
/**
 * QA — edge cases, malformed input, empty-state UX.
 *
 * The point isn't to make the test pass — it's to make sure the bot AND
 * the web frontend stay coherent when faced with stuff users actually do:
 * gibberish, duplicated commands, unicode, empty state, race-likely
 * upserts. Each case asserts the no-crash path.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'QA — edge cases + malformed input' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Clean slate' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::cleanup();

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob',   2 );

$competition_id = 'libertadores-semana';
$match = Mantia_E2E::schedule_match_in_minutes( 60, $competition_id );
$match_id = (int) $match['id'];

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Cold user — every command before joining a penca does NOT crash' );
/* ─────────────────────────────────────────────────────────────────────── */
foreach ( array( 'hola', 'tabla', 'pendientes', 'consenso', 'compartir', 'mi equipo', 'sortear', 'mis pencas', 'hoy' ) as $cmd ) {
	$r = Mantia_E2E::send( $alice, $cmd );
	Mantia_E2E::assert_true(
		is_array( $r ) && isset( $r['reply'] ) && '' !== (string) $r['reply'],
		sprintf( 'cold "%s" returns a non-empty reply (no crash)', $cmd )
	);
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Score regex variations all funnel to the same handler' );
/* ─────────────────────────────────────────────────────────────────────── */
// Set up Alice with a penca + tap a match so a bare score has context.
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:' . $competition_id );
$alice_penca = Mantia_E2E::TEST_NAME_PREFIX . ' Edge';
Mantia_E2E::send( $alice, $alice_penca );

global $wpdb;
$alice_group_id = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_title=%s ORDER BY ID DESC LIMIT 1",
	Mantia_CPTs::GROUP, $alice_penca
) );
Mantia_E2E::assert_true( $alice_group_id > 0, 'alice penca exists' );

foreach ( array( '2-1', '2 1', '2:1', '2x1', '2 a 1', '  2-1  ' ) as $raw ) {
	Mantia_E2E::send( $alice, 'mantia:match:' . $match_id );
	$r = Mantia_E2E::send( $alice, $raw );
	Mantia_E2E::assert_contains( $r, 'Anotado', sprintf( 'score "%s" parsed and saved', trim( $raw ) ) );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Predicting the same match TWICE upserts (no duplicate row)' );
/* ─────────────────────────────────────────────────────────────────────── */
$alice_uid = (int) Mantia_Repository::find_user_by_phone( $alice['phone'] )->ID;
Mantia_Repository::register_prediction( $alice_uid, $match_id, $alice_group_id, 5, 0 );
Mantia_Repository::register_prediction( $alice_uid, $match_id, $alice_group_id, 0, 5 );
$rows = get_posts( array(
	'post_type'      => Mantia_CPTs::PREDICTION,
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
	'meta_query'     => array(
		array( 'key' => Mantia_Repository::META_USER_ID,  'value' => $alice_uid ),
		array( 'key' => Mantia_Repository::META_MATCH_ID, 'value' => $match_id ),
		array( 'key' => Mantia_Repository::META_GROUP_ID, 'value' => $alice_group_id ),
	),
) );
Mantia_E2E::assert_eq( 1, count( $rows ), 'repeated register_prediction yields exactly 1 row (upsert)' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. Unicode and very long penca name — accepted and rendered safely' );
/* ─────────────────────────────────────────────────────────────────────── */
$long_unicode = Mantia_E2E::TEST_NAME_PREFIX . ' 🇺🇾⚽ Peña-Yorugüa de los Domingos con Asado y Mate ' . str_repeat( 'X', 30 );
$long_id      = Mantia_Repository::create_group( $long_unicode, 'UNI1', '', $competition_id );
Mantia_E2E::assert_true( $long_id > 0, 'long+unicode name accepted by create_group' );
$resolved = Mantia_Repository::group_to_array( $long_id );
Mantia_E2E::assert_true( '' !== (string) ( $resolved['name'] ?? '' ), 'group_to_array returns a non-empty name' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. sortear with only 1 member — succeeds, the lone member gets 1 team' );
/* ─────────────────────────────────────────────────────────────────────── */
$solo_id = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' Solo', 'SOLO1', '', $competition_id );
Mantia_Repository::join_group( $alice['phone'], 'SOLO1', $alice['name'], $alice['phone'] );
$drew = Mantia_Repository::assign_sweepstake( $solo_id );
Mantia_E2E::assert_eq( 1, count( $drew ), 'single-member sortear returns 1 assignment' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '6. /mi equipo when never drew — pre-draw hint, not crash' );
/* ─────────────────────────────────────────────────────────────────────── */
// Bob is in no penca yet; force him into a fresh one with no sweepstake.
$fresh_id = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' Fresh', 'FRESH1', '', $competition_id );
Mantia_Repository::join_group( $bob['phone'], 'FRESH1', $bob['name'], $bob['phone'] );
$r = Mantia_E2E::send( $bob, 'mi equipo' );
Mantia_E2E::assert_contains( $r, 'Todavía no hubo sorteo', 'pre-draw user sees the hint, not a crash' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '7. Empty / whitespace-only penca name does NOT create a group' );
/* ─────────────────────────────────────────────────────────────────────── */
$empty_err = Mantia_Abilities::create_group( array(
	'user_phone' => $alice['phone'],
	'group_name' => '   ',
	'competition_id' => $competition_id,
) );
Mantia_E2E::assert_true( is_wp_error( $empty_err ), 'whitespace-only name rejected' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8. /pronostico/sumate/<bogus>/ for an invented invite code → 404 themed' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::assert_http_status( '/pronostico/sumate/THIS-CODE-DOES-NOT-EXIST/', 404 );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '9. Web — group view by VALID hex token shows the actual group, NOT 404' );
/* ─────────────────────────────────────────────────────────────────────── */
$tok = Mantia_Repository::group_view_token( $alice_group_id );
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $tok . '/', array( $alice_penca ) );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '10. Gibberish + extra-whitespace + accented input does not throw' );
/* ─────────────────────────────────────────────────────────────────────── */
foreach ( array( 'asdkjfhqwerty', '          ', 'compartír', 'qué partido se viene??' ) as $gibberish ) {
	$r = Mantia_E2E::send( $alice, $gibberish );
	Mantia_E2E::assert_true( is_array( $r ), sprintf( 'gibberish "%s" handled without exception', $gibberish ) );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '11. Sortear when competition has no teams → graceful empty result' );
/* ─────────────────────────────────────────────────────────────────────── */
// Mantia_Repository::create_group rewrites an unknown competition_id to
// the install default — that's good UX but makes "ghost competition"
// unreachable via the public API. We force the condition by directly
// writing a post with a competition_id that has zero matching fixtures,
// which is the actual code path teams_in_competition traverses.
$ghost_id = wp_insert_post( array(
	'post_type'   => Mantia_CPTs::GROUP,
	'post_status' => 'publish',
	'post_title'  => Mantia_E2E::TEST_NAME_PREFIX . ' Ghost',
) );
update_post_meta( (int) $ghost_id, Mantia_Repository::META_INVITE_CODE, 'GHOST1' );
update_post_meta( (int) $ghost_id, Mantia_Competitions::META_KEY, 'ghost-comp-xyz' );
update_post_meta( (int) $ghost_id, Mantia_Repository::META_GROUP_SLUG, 'ghost' );
Mantia_Repository::join_group( $alice['phone'], 'GHOST1', $alice['name'], $alice['phone'] );

$ghost_teams = Mantia_Repository::teams_in_competition( 'ghost-comp-xyz' );
Mantia_E2E::assert_eq( array(), $ghost_teams, 'teams_in_competition empty for unknown comp' );

$ghost_assign = Mantia_Repository::assign_sweepstake( (int) $ghost_id );
Mantia_E2E::assert_eq( array(), $ghost_assign, 'sortear on empty competition returns empty (no crash)' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '12. ICS feed for a group whose competition has zero upcoming matches' );
/* ─────────────────────────────────────────────────────────────────────── */
$ghost_tok = Mantia_Repository::group_view_token( (int) $ghost_id );
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $ghost_tok . '/calendar.ics', array( 'BEGIN:VCALENDAR', 'END:VCALENDAR' ) );

Mantia_E2E::finish();
