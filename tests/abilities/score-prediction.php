<?php
/**
 * ADD: mantia/score-prediction (read-only, pure)
 *
 * Pure function ability — no side effects, no fixtures. The fastest
 * possible ability to test. Validates the canonical scoring rules:
 *   5 pts exact, 3 pts diff, 1 pt winner, 0 otherwise.
 *
 * Run: bin/e2e.sh abilities/score-prediction
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/score-prediction' );

$call = function ( int $ph, int $pa, int $rh, int $ra ) {
	return Mantia_E2E::call_ability( 'mantia/score-prediction', array(
		'predicted_home' => $ph,
		'predicted_away' => $pa,
		'real_home'      => $rh,
		'real_away'      => $ra,
	) );
};

/* ──── Case 1: exact match → 5 points ──── */

Mantia_E2E::step( '1. Exact match → 5 points' );
$r = $call( 2, 1, 2, 1 );
Mantia_E2E::assert_ability_output( 'mantia/score-prediction', $r );
Mantia_E2E::assert_eq( 5, (int) ( $r['points'] ?? -1 ), 'exact = 5 pts' );

/* ──── Case 2: same diff, wrong scores → 3 points ──── */

Mantia_E2E::step( '2. Same goal diff, different scores → 3 points' );
$r = $call( 3, 1, 2, 0 );  // both: home wins by 2
Mantia_E2E::assert_eq( 3, (int) ( $r['points'] ?? -1 ), 'same diff = 3 pts' );

/* ──── Case 3: winner only → 1 point ──── */

Mantia_E2E::step( '3. Winner only → 1 point' );
$r = $call( 2, 1, 3, 0 );  // both: home wins (different diff)
Mantia_E2E::assert_eq( 1, (int) ( $r['points'] ?? -1 ), 'winner only = 1 pt' );

/* ──── Case 4: missed both ways → 0 points ──── */

Mantia_E2E::step( '4. Wrong direction → 0 points' );
$r = $call( 2, 1, 0, 3 );  // predicted home win, away won
Mantia_E2E::assert_eq( 0, (int) ( $r['points'] ?? -1 ), 'wrong outcome = 0 pts' );

/* ──── Case 5: exact draw → 5 points ──── */

Mantia_E2E::step( '5. Exact draw → 5 points' );
$r = $call( 1, 1, 1, 1 );
Mantia_E2E::assert_eq( 5, (int) ( $r['points'] ?? -1 ), 'exact 1-1 = 5 pts' );

/* ──── Case 6: draw predicted, draw happened (different score) → 3 ─── */

Mantia_E2E::step( '6. Draw vs draw with different score → 3 points' );
$r = $call( 2, 2, 1, 1 );  // both draws, different scores → same "diff" = 0
Mantia_E2E::assert_eq( 3, (int) ( $r['points'] ?? -1 ), 'draw vs draw (different) = 3 pts' );

Mantia_E2E::finish();
