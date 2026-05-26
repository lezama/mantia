<?php
/**
 * Coverage for the 4 features added from competing-pool research:
 *   1. Prediction lockout buffer (10 min before kickoff by default)
 *   2. Consenso reveals per-user predictions post-kickoff
 *   3. Sweepstake draw + query bot commands
 *   4. ICS calendar export endpoint
 *
 * Run:
 *   bin/e2e.sh new-features
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'New features — lockout, consenso reveal, sortear, ICS' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Clean slate + personas' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::cleanup();

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob',   2 );
$carla = Mantia_E2E::persona( 'Carla', 3 );

$competition_id = 'libertadores-semana';

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1a. Lockout buffer — match in 5 min REJECTS prediction' );
/* ─────────────────────────────────────────────────────────────────────── */
// Default lockout is 600s (10 min). Schedule a match in 5 min: too close.
$tight = Mantia_E2E::schedule_match_in_minutes( 5, $competition_id );
$tight_id = (int) $tight['id'];

// Get Alice into a penca first so the no_group fallback doesn't fire.
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:' . $competition_id );
$penca_name = Mantia_E2E::TEST_NAME_PREFIX . ' Lockout';
Mantia_E2E::send( $alice, $penca_name );

global $wpdb;
$group_id = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s ORDER BY ID DESC LIMIT 1",
	Mantia_CPTs::GROUP, $penca_name
) );
Mantia_E2E::assert_true( $group_id > 0, 'lockout penca exists' );

$rejected = Mantia_Abilities::register_prediction( array(
	'user_phone' => $alice['phone'],
	'match_id'   => $tight_id,
	'home_score' => 2,
	'away_score' => 1,
) );
Mantia_E2E::assert_true( is_wp_error( $rejected ), '5-min match: prediction blocked by lockout' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1b. Lockout override via filter — 0s lets the same call through' );
/* ─────────────────────────────────────────────────────────────────────── */
add_filter( 'mantia_prediction_lockout_seconds', static fn(): int => 0, 99 );
$accepted = Mantia_Abilities::register_prediction( array(
	'user_phone' => $alice['phone'],
	'match_id'   => $tight_id,
	'home_score' => 2,
	'away_score' => 1,
) );
remove_all_filters( 'mantia_prediction_lockout_seconds', 99 );
Mantia_E2E::assert_true( ! is_wp_error( $accepted ), 'with lockout=0, same call now accepts' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Consenso reveal — per-user breakdown after kickoff' );
/* ─────────────────────────────────────────────────────────────────────── */
$reveal = Mantia_E2E::schedule_match_in_minutes( 60, $competition_id );
$reveal_id = (int) $reveal['id'];

// Bob and Carla join Alice's penca, then everyone predicts.
$group   = Mantia_Repository::group_to_array( $group_id );
$invite  = (string) $group['invite_code'];
Mantia_E2E::send( $bob,   $invite );
Mantia_E2E::send( $carla, $invite );

Mantia_Abilities::register_prediction( array( 'user_phone' => $alice['phone'], 'match_id' => $reveal_id, 'home_score' => 2, 'away_score' => 1 ) );
Mantia_Abilities::register_prediction( array( 'user_phone' => $bob['phone'],   'match_id' => $reveal_id, 'home_score' => 1, 'away_score' => 1 ) );
Mantia_Abilities::register_prediction( array( 'user_phone' => $carla['phone'], 'match_id' => $reveal_id, 'home_score' => 3, 'away_score' => 0 ) );

// Time-travel match to FINISHED 2-1 so consenso has a real score to mark badges.
Mantia_E2E::finish_match( $reveal_id, 2, 1 );
Mantia_Abilities::resolve_match( array( 'match_id' => $reveal_id ) );

$r = Mantia_E2E::send( $alice, 'consenso' );
Mantia_E2E::assert_contains( $r, 'Quién puso qué', 'consenso shows per-user header' );
Mantia_E2E::assert_contains( $r, 'Alice', 'consenso lists Alice' );
Mantia_E2E::assert_contains( $r, 'Bob',   'consenso lists Bob' );
Mantia_E2E::assert_contains( $r, 'Carla', 'consenso lists Carla' );
Mantia_E2E::assert_contains( $r, 'exacto', 'consenso marks the exact prediction' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3a. Sweepstake — /sortear draws one team per member' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $alice, 'sortear' );
Mantia_E2E::assert_contains( $r, 'Sorteo', 'sorteo reply has the header' );
Mantia_E2E::assert_contains( $r, 'Alice', 'sorteo lists Alice' );
Mantia_E2E::assert_contains( $r, 'Bob',   'sorteo lists Bob' );
Mantia_E2E::assert_contains( $r, 'Carla', 'sorteo lists Carla' );

// Each persona now has a team_meta value.
$assigned_count = 0;
foreach ( array( $alice, $bob, $carla ) as $p ) {
	$u = Mantia_Repository::find_user_by_phone( $p['phone'] );
	$team = Mantia_Repository::get_sweepstake_team( (int) $u->ID, $group_id );
	if ( '' !== $team ) {
		$assigned_count++;
	}
}
Mantia_E2E::assert_eq( 3, $assigned_count, 'all 3 personas got a team assigned' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3b. /mi equipo returns the caller\'s assignment' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $bob, 'mi equipo' );
Mantia_E2E::assert_contains( $r, 'Tu equipo del sorteo', 'mi-equipo reply formatted' );

$bob_user = Mantia_Repository::find_user_by_phone( $bob['phone'] );
$bob_team = Mantia_Repository::get_sweepstake_team( (int) $bob_user->ID, $group_id );
Mantia_E2E::assert_contains( $r, $bob_team, 'reply contains Bob\'s assigned team' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3c. Second `sortear` after a draw exists → guard reply, no overwrite' );
/* ─────────────────────────────────────────────────────────────────────── */
$bob_team_v1 = $bob_team;
$r = Mantia_E2E::send( $alice, 'sortear' );
Mantia_E2E::assert_contains( $r, 'Ya hubo sorteo', 'bare sortear after a draw shows the guard message' );
Mantia_E2E::assert_contains( $r, 're-sortear', 'guard mentions the re-sortear command' );
$bob_team_after_guard = Mantia_Repository::get_sweepstake_team( (int) $bob_user->ID, $group_id );
Mantia_E2E::assert_eq( $bob_team_v1, $bob_team_after_guard, 'guard did NOT overwrite Bob\'s team' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3d. Explicit `re-sortear` overwrites the previous draw' );
/* ─────────────────────────────────────────────────────────────────────── */
$diff_seen = false;
for ( $i = 0; $i < 20 && ! $diff_seen; $i++ ) {
	Mantia_E2E::send( $alice, 're-sortear' );
	$bob_team_v2 = Mantia_Repository::get_sweepstake_team( (int) $bob_user->ID, $group_id );
	if ( '' !== $bob_team_v2 && $bob_team_v2 !== $bob_team_v1 ) {
		$diff_seen = true;
		break;
	}
	$bob_team_v1 = $bob_team_v2;
}
Mantia_E2E::assert_true( $diff_seen, 're-sortear eventually shuffles Bob to a different team' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. ICS calendar export endpoint' );
/* ─────────────────────────────────────────────────────────────────────── */
$view_token = Mantia_Repository::group_view_token( $group_id );

Mantia_E2E::assert_http_ok( '/pronostico/g/' . $view_token . '/calendar.ics', array(
	'BEGIN:VCALENDAR',
	'VERSION:2.0',
	'PRODID:-//Mantia//EN',
	'X-WR-CALNAME',
	$penca_name,
	'BEGIN:VEVENT',
	'DTSTART:',
	'SUMMARY:',
	'END:VEVENT',
	'END:VCALENDAR',
) );

// Bogus token → 404.
Mantia_E2E::assert_http_status( '/pronostico/g/0000000000000000/calendar.ics', 404 );

Mantia_E2E::finish();
