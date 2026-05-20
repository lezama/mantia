<?php
/**
 * ADD: mantia/resolve-match (state-changing)
 *
 * Hard to test cleanly because it needs (1) a finished match with real
 * scores in fixtures, AND (2) at least one pending prediction. Uses
 * Mantia_E2E::finish_match() to manufacture the prerequisite, then
 * verifies the ability scores all predictions and marks resolved.
 *
 * Run: bin/e2e.sh abilities/resolve-match
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/resolve-match' );

$persona = array( 'phone' => '9999000810', 'name' => '__E2E__ Resolve Owner' );
Mantia_E2E::cleanup_persona( $persona );

/* ──── Case 1: invalid match_id → WP_Error ──── */

Mantia_E2E::step( '1. Invalid match_id → WP_Error' );

$result = Mantia_E2E::call_ability( 'mantia/resolve-match', array( 'match_id' => 999999999 ) );
Mantia_E2E::assert_true( is_wp_error( $result ), 'unknown match returns WP_Error' );

/* ──── Case 2: happy path (if we can manufacture a finished match) ──── */

if ( ! method_exists( 'Mantia_E2E', 'finish_match' ) ) {
	Mantia_E2E::step( '! Mantia_E2E::finish_match unavailable; skipping happy path' );
	Mantia_E2E::cleanup_persona( $persona );
	Mantia_E2E::finish();
	return;
}

Mantia_E2E::step( '2. Manufacture penca + prediction + finish match → resolve scores it' );

// Bootstrap a penca and a known prediction.
Mantia_E2E::send( $persona, 'crear penca __E2E__ Resolve' );
Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );

$matches  = Mantia_Repository::upcoming_matches_for_competition( 'libertadores-2026', 24 * 30 );
if ( empty( $matches ) ) {
	Mantia_E2E::step( '! no matches seeded; skipping happy path' );
	Mantia_E2E::cleanup_persona( $persona );
	Mantia_E2E::finish();
	return;
}
$match_id = (int) $matches[0]['id'];

Mantia_E2E::call_ability( 'mantia/register-prediction', array(
	'user_phone' => $persona['phone'],
	'user_name'  => $persona['name'],
	'match_id'   => $match_id,
	'home_score' => 2,
	'away_score' => 1,
) );

// Finish the match with the SAME score (so the predict scores 5 pts).
Mantia_E2E::finish_match( $match_id, 2, 1 );

// Now resolve.
$result = Mantia_E2E::call_ability( 'mantia/resolve-match', array( 'match_id' => $match_id ) );
Mantia_E2E::assert_ability_output( 'mantia/resolve-match', $result );

// Re-fetch the prediction; it should now have scored.
$user_id = (int) Mantia_Repository::find_user_by_phone( $persona['phone'] )->ID;
$groups  = Mantia_Repository::user_groups_to_array( $user_id );
$pred    = Mantia_Repository::find_prediction( $user_id, $match_id, (int) $groups[0]['id'] );
Mantia_E2E::assert_not_null( $pred, 'prediction still exists after resolve' );
if ( $pred ) {
	$points = (int) get_post_meta( (int) $pred->ID, Mantia_Repository::META_POINTS, true );
	$scored = (bool) get_post_meta( (int) $pred->ID, Mantia_Repository::META_SCORED, true );
	Mantia_E2E::assert_true( $scored, 'prediction marked scored' );
	Mantia_E2E::assert_eq( 5, $points, 'exact match scored 5 points' );
}

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
