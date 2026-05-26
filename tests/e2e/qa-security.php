<?php
/**
 * QA — security boundaries and authorization.
 *
 * Adversarial scenarios against the bot's ability surface AND the public
 * web routes. Tests that BREAK existing code are intentional — they
 * surface real bugs and document the patch via the assertion.
 *
 * Personas use distinct phones / pencas so cross-group leaks fail loudly.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'QA — security boundaries' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Clean slate' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::cleanup();

$alice  = Mantia_E2E::persona( 'Alice',  1 );
$bob    = Mantia_E2E::persona( 'Bob',    2 );
$mallory = Mantia_E2E::persona( 'Mallory', 7 ); // the attacker

$competition_id = 'libertadores-semana';
$m1 = Mantia_E2E::schedule_match_in_minutes( 60, $competition_id );
$m1_id = (int) $m1['id'];

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. SETUP — Alice and Bob create their own private pencas' );
/* ─────────────────────────────────────────────────────────────────────── */
$alice_group = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' Alice Private', 'ALICESEC', '', $competition_id );
$bob_group   = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' Bob Private',   'BOBSEC',   '', $competition_id );
Mantia_E2E::assert_true( $alice_group > 0 && $bob_group > 0, 'both private groups created' );

// Alice and Bob join their own; Mallory is in neither.
Mantia_Repository::join_group( $alice['phone'], 'ALICESEC', $alice['name'], $alice['phone'] );
Mantia_Repository::join_group( $bob['phone'],   'BOBSEC',   $bob['name'],   $bob['phone'] );
// Mallory joins her own to have a baseline.
$mallory_group = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' Mallory Own', 'MALSEC', '', $competition_id );
Mantia_Repository::join_group( $mallory['phone'], 'MALSEC', $mallory['name'], $mallory['phone'] );

$alice_uid   = (int) Mantia_Repository::find_user_by_phone( $alice['phone'] )->ID;
$bob_uid     = (int) Mantia_Repository::find_user_by_phone( $bob['phone'] )->ID;
$mallory_uid = (int) Mantia_Repository::find_user_by_phone( $mallory['phone'] )->ID;

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. IDOR — Mallory tries to predict in Alice\'s private penca' );
/* ─────────────────────────────────────────────────────────────────────── */
// Adversarial input: an LLM tool call coerced via prompt injection. The
// ability accepts group_id explicitly; if it doesn't verify membership a
// malicious tool call can write into ANY group_id the attacker knows.
$attack = Mantia_Abilities::register_prediction( array(
	'user_phone' => $mallory['phone'],
	'match_id'   => $m1_id,
	'group_id'   => $alice_group,
	'home_score' => 9,
	'away_score' => 9,
) );
Mantia_E2E::assert_true(
	is_wp_error( $attack ),
	'IDOR: Mallory cannot write into Alice\'s group by passing group_id explicitly'
);
$alice_pred = Mantia_Repository::find_prediction( $mallory_uid, $m1_id, $alice_group );
Mantia_E2E::assert_eq( true, null === $alice_pred, 'no row was created in Alice\'s group for Mallory' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Score validation — negative and absurdly large scores' );
/* ─────────────────────────────────────────────────────────────────────── */
// Mallory predicts in HER OWN penca but with abuse-shaped scores.
$neg = Mantia_Abilities::register_prediction( array(
	'user_phone' => $mallory['phone'],
	'match_id'   => $m1_id,
	'group_id'   => $mallory_group,
	'home_score' => -5,
	'away_score' => 10,
) );
Mantia_E2E::assert_true( ! is_wp_error( $neg ), 'negative score accepted (clamped, not rejected)' );
$row = Mantia_Repository::find_prediction( $mallory_uid, $m1_id, $mallory_group );
$persisted_home = $row ? (int) get_post_meta( (int) $row->ID, Mantia_Repository::META_PRED_HOME_SCORE, true ) : -1;
Mantia_E2E::assert_eq( 0, $persisted_home, 'negative home_score clamped to 0' );

// Absurd magnitude — currently un-capped. Document the cap if/when added.
$huge = Mantia_Abilities::register_prediction( array(
	'user_phone' => $mallory['phone'],
	'match_id'   => $m1_id,
	'group_id'   => $mallory_group,
	'home_score' => 9999,
	'away_score' => 0,
) );
Mantia_E2E::assert_true( ! is_wp_error( $huge ), 'absurd score not rejected outright' );
$row2 = Mantia_Repository::find_prediction( $mallory_uid, $m1_id, $mallory_group );
$persisted_huge = $row2 ? (int) get_post_meta( (int) $row2->ID, Mantia_Repository::META_PRED_HOME_SCORE, true ) : -1;
Mantia_E2E::assert_true( $persisted_huge <= 50, 'home_score clamped to a sane upper bound (≤50)' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. XSS — script tag in penca name renders escaped on web' );
/* ─────────────────────────────────────────────────────────────────────── */
$xss_name = Mantia_E2E::TEST_NAME_PREFIX . ' <script>alert(1)</script> Hack';
$xss_id   = Mantia_Repository::create_group( $xss_name, 'XSSGRP', '', $competition_id );
Mantia_Repository::join_group( $alice['phone'], 'XSSGRP', $alice['name'], $alice['phone'] );
$xss_token = Mantia_Repository::group_view_token( $xss_id );

// Fetch the page and confirm the script tag is NOT present verbatim.
$base = (string) get_option( 'mantia_e2e_base_url', '' );
$base = '' !== $base ? rtrim( $base, '/' ) : (string) home_url();
$resp = wp_remote_get( $base . '/pronostico/g/' . $xss_token . '/' );
Mantia_E2E::assert_true( ! is_wp_error( $resp ), 'XSS test page fetched OK' );
$body = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
Mantia_E2E::assert_true( false === stripos( $body, '<script>alert(1)</script>' ), 'raw <script> NOT in rendered HTML' );
Mantia_E2E::assert_true( false !== stripos( $body, '&lt;script&gt;' ) || false !== stripos( $body, 'Hack' ), 'page rendered (escaped or sanitized)' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. Privacy — pre-kickoff consenso reveals nothing' );
/* ─────────────────────────────────────────────────────────────────────── */
// Pick a SECOND distinct match for the privacy test.
$future_id = 0;
foreach ( Mantia_Repository::upcoming_matches_for_competition( $competition_id, 24 * 365 ) as $cand ) {
	if ( (int) $cand['id'] !== $m1_id ) { $future_id = (int) $cand['id']; break; }
}
if ( 0 === $future_id ) {
	// Fall back to any non-m1 match in the parent.
	$ids = get_posts( array(
		'post_type'      => Mantia_CPTs::MATCH,
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'fields'         => 'ids',
		'exclude'        => array( $m1_id ),
	) );
	$future_id = ! empty( $ids ) ? (int) $ids[0] : 0;
}
if ( $future_id > 0 ) {
	Mantia_E2E::schedule_match_in_minutes( 120, $competition_id, $future_id );
}
if ( $future_id === $m1_id || 0 === $future_id ) {
	Mantia_E2E::step( '! could not schedule a SECOND distinct match — skipping privacy step' );
} else {
	// Alice and Bob both predict the future match in Alice's group.
	Mantia_Repository::join_group( $bob['phone'], 'ALICESEC', $bob['name'], $bob['phone'] );
	Mantia_Repository::register_prediction( $alice_uid, $future_id, $alice_group, 2, 1 );
	Mantia_Repository::register_prediction( $bob_uid,   $future_id, $alice_group, 0, 0 );

	$consensus = Mantia_Repository::group_consensus_for_match( $alice_group, $future_id );
	Mantia_E2E::assert_eq( array(), $consensus, 'pre-kickoff: group_consensus returns empty' );

	$rows = Mantia_Repository::group_predictions_for_match( $alice_group, $future_id );
	Mantia_E2E::assert_eq( array(), $rows, 'pre-kickoff: group_predictions_for_match returns empty' );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '6. Token brute-force — invalid view tokens return 404' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::assert_http_status( '/pronostico/g/deadbeefdeadbeefdeadbeef/', 404 );
Mantia_E2E::assert_http_status( '/pronostico/me/feedfacefeedfacefeedface/',  404 );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '7. Sweepstake meta — cross-group team isolation' );
/* ─────────────────────────────────────────────────────────────────────── */
// Mallory in HER own group; Alice in HER own. Sweepstake meta is keyed
// by group_id, so Mallory's draw in MALSEC must NOT leak into Alice's
// reading of get_sweepstake_team(alice_uid, MALSEC) — and vice-versa.
Mantia_Repository::set_sweepstake_team( $mallory_uid, $mallory_group, 'TestTeamA' );
Mantia_Repository::set_sweepstake_team( $alice_uid,   $alice_group,   'TestTeamB' );

Mantia_E2E::assert_eq( 'TestTeamA', Mantia_Repository::get_sweepstake_team( $mallory_uid, $mallory_group ), 'Mallory team in own group' );
Mantia_E2E::assert_eq( 'TestTeamB', Mantia_Repository::get_sweepstake_team( $alice_uid,   $alice_group ),   'Alice team in own group' );
Mantia_E2E::assert_eq( '',          Mantia_Repository::get_sweepstake_team( $mallory_uid, $alice_group ),   'Mallory has no team in Alice\'s group' );
Mantia_E2E::assert_eq( '',          Mantia_Repository::get_sweepstake_team( $alice_uid,   $mallory_group ), 'Alice has no team in Mallory\'s group' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8. Join idempotency — joining same penca twice does not duplicate membership' );
/* ─────────────────────────────────────────────────────────────────────── */
$before = (array) get_user_meta( $alice_uid, Mantia_Repository::META_GROUP_IDS, true );
Mantia_Repository::join_group( $alice['phone'], 'ALICESEC', $alice['name'], $alice['phone'] );
$after = (array) get_user_meta( $alice_uid, Mantia_Repository::META_GROUP_IDS, true );
Mantia_E2E::assert_eq( count( $before ), count( $after ), 'group count unchanged on duplicate join' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '9. ICS endpoint with wrong token returns 404 (not 200 empty body)' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::assert_http_status( '/pronostico/g/0000000000000000/calendar.ics', 404 );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '10. Predict-after-finished — repository rejects post-final mutations' );
/* ─────────────────────────────────────────────────────────────────────── */
$finished = Mantia_E2E::schedule_match_in_minutes( 60, $competition_id );
$fin_id = (int) $finished['id'];
Mantia_E2E::finish_match( $fin_id, 1, 0 );
$post_final = Mantia_Repository::register_prediction( $alice_uid, $fin_id, $alice_group, 0, 0 );
Mantia_E2E::assert_true( is_wp_error( $post_final ), 'register_prediction on a finished match → WP_Error' );

Mantia_E2E::finish();
