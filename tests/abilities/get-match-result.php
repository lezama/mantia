<?php
/**
 * ADD: mantia/get-match-result (read-only)
 *
 * Run: bin/e2e.sh abilities/get-match-result
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-match-result' );

/* ──── Case 1: known match → returns shape ──── */

Mantia_E2E::step( '1. Known match → match object populated' );

$matches = Mantia_Repository::upcoming_matches_for_competition( 'libertadores-2026', 24 * 30 );
if ( empty( $matches ) ) {
	Mantia_E2E::step( '! no matches seeded; skipping' );
	Mantia_E2E::finish();
	return;
}
$match_id = (int) $matches[0]['id'];

$result = Mantia_E2E::call_ability( 'mantia/get-match-result', array( 'match_id' => $match_id ) );
Mantia_E2E::assert_ability_output( 'mantia/get-match-result', $result );
Mantia_E2E::assert_true( ! empty( $result['match'] ), 'match payload present' );
Mantia_E2E::assert_eq( $match_id, (int) $result['match']['id'], 'echoed match_id' );

/* ──── Case 2: unknown match_id → empty match (graceful, not error) ──── */

Mantia_E2E::step( '2. Unknown match_id → empty match object' );

$result = Mantia_E2E::call_ability( 'mantia/get-match-result', array( 'match_id' => 999999999 ) );
Mantia_E2E::assert_ability_output( 'mantia/get-match-result', $result );
Mantia_E2E::assert_eq( array(), (array) $result['match'], 'empty match for unknown id' );

Mantia_E2E::finish();
