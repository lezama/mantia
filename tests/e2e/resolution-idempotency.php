<?php
/**
 * Resolve a match twice — points must not double. The cron workflow
 * runs every 15 minutes and could pick up the same match more than
 * once on the boundary, so resolution has to be idempotent.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Resolution is idempotent' );

Mantia_E2E::cleanup();
Mantia_Competitions::seed_defaults();
Mantia_Fixture_Seeder::seed(); // restore demo matches to scheduled

$alice = Mantia_E2E::persona( 'Alice', 1 );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '1. Set up: Alice creates a penca + predicts a Mundial match' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::send( $alice, Mantia_E2E::TEST_NAME_PREFIX . ' Resolver' );

$match_id = Mantia_E2E::match_id_by_teams( 'Uruguay', 'Portugal' );
Mantia_E2E::assert_eq( true, $match_id > 0, 'demo match Uruguay vs Portugal is in fixture' );

Mantia_E2E::send( $alice, "mantia:match:{$match_id}" );
Mantia_E2E::send( $alice, '2-1' );

$alice_post = Mantia_Repository::find_user_by_phone( $alice['phone'] );
$alice_id   = (int) $alice_post->ID;

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '2. Finish the match 2-1 (Alice exact = 5 pts)' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::finish_match( $match_id, 2, 1 );

$r = Mantia_Abilities::resolve_match( array( 'match_id' => $match_id ) );
Mantia_E2E::assert_eq( true, ! is_wp_error( $r ), 'first resolve_match succeeds' );

$groups        = Mantia_Repository::user_groups_in_competition( $alice_id, 'mundial-2026' );
$first_penca   = (int) $groups[0];
$first_pred    = Mantia_Repository::find_prediction( $alice_id, $match_id, $first_penca );
$points_before = (int) get_post_meta( (int) $first_pred->ID, Mantia_Repository::META_POINTS, true );
Mantia_E2E::assert_eq( 5, $points_before, 'Alice scored 5 pts (exact)' );

$rows_first = Mantia_Leaderboard::rows( $first_penca, 50 );
$alice_pts_first = 0;
foreach ( $rows_first as $row ) {
	if ( (int) $row['user_id'] === $alice_id ) {
		$alice_pts_first = (int) $row['points'];
	}
}
Mantia_E2E::assert_eq( 5, $alice_pts_first, 'leaderboard shows 5 pts after first resolve' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '3. Resolve the SAME match again — points must NOT double' );
/* ------------------------------------------------------------------------- */
$r2 = Mantia_Abilities::resolve_match( array( 'match_id' => $match_id ) );
Mantia_E2E::assert_eq( true, ! is_wp_error( $r2 ), 'second resolve_match also succeeds' );

$reread_pred  = Mantia_Repository::find_prediction( $alice_id, $match_id, $first_penca );
$points_after = (int) get_post_meta( (int) $reread_pred->ID, Mantia_Repository::META_POINTS, true );
Mantia_E2E::assert_eq( $points_before, $points_after, 'prediction points unchanged after double-resolve' );

$rows_second = Mantia_Leaderboard::rows( $first_penca, 50 );
$alice_pts_second = 0;
foreach ( $rows_second as $row ) {
	if ( (int) $row['user_id'] === $alice_id ) {
		$alice_pts_second = (int) $row['points'];
	}
}
Mantia_E2E::assert_eq( 5, $alice_pts_second, 'leaderboard still shows 5 pts after double-resolve' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '4. Resolve a 3rd time for paranoia' );
/* ------------------------------------------------------------------------- */
Mantia_Abilities::resolve_match( array( 'match_id' => $match_id ) );
$points_final = (int) get_post_meta( (int) $reread_pred->ID, Mantia_Repository::META_POINTS, true );
Mantia_E2E::assert_eq( 5, $points_final, 'three resolves, still 5 pts' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '5. Cleanup' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::cleanup();

Mantia_E2E::finish();
