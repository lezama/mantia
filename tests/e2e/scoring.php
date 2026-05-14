<?php
/**
 * Scoring rules: every (predicted, real) combination produces the
 * documented points + reason. Plus the `mantia_scoring_rules` filter
 * is honored.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Scoring rules: 5 / 3 / 1 / 0' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '1. Exact match → 5 pts' );
/* ------------------------------------------------------------------------- */
$r = Mantia_Scoring::score_prediction( 2, 1, 2, 1 );
Mantia_E2E::assert_eq( 5, $r['points'], '2-1 vs 2-1 = 5 pts' );
Mantia_E2E::assert_eq( 'exact', $r['reason'], 'reason=exact' );

$r = Mantia_Scoring::score_prediction( 0, 0, 0, 0 );
Mantia_E2E::assert_eq( 5, $r['points'], '0-0 vs 0-0 = 5 pts' );
Mantia_E2E::assert_eq( 'exact', $r['reason'], '0-0 reason=exact' );

$r = Mantia_Scoring::score_prediction( 3, 3, 3, 3 );
Mantia_E2E::assert_eq( 5, $r['points'], '3-3 vs 3-3 = 5 pts (draw exact)' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '2. Goal difference correct (not exact) → 3 pts' );
/* ------------------------------------------------------------------------- */
$r = Mantia_Scoring::score_prediction( 3, 2, 2, 1 );
Mantia_E2E::assert_eq( 3, $r['points'], '3-2 vs 2-1 = 3 pts (both home wins by 1)' );
Mantia_E2E::assert_eq( 'goal_difference', $r['reason'], 'reason=goal_difference' );

$r = Mantia_Scoring::score_prediction( 1, 2, 0, 1 );
Mantia_E2E::assert_eq( 3, $r['points'], '1-2 vs 0-1 = 3 pts (both away wins by 1)' );

$r = Mantia_Scoring::score_prediction( 2, 2, 1, 1 );
Mantia_E2E::assert_eq( 3, $r['points'], '2-2 vs 1-1 = 3 pts (both draws, diff 0)' );
Mantia_E2E::assert_eq( 'goal_difference', $r['reason'], 'draw with different totals is goal_difference' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '3. Outcome correct only (wrong diff) → 1 pt' );
/* ------------------------------------------------------------------------- */
$r = Mantia_Scoring::score_prediction( 3, 0, 1, 0 );
Mantia_E2E::assert_eq( 1, $r['points'], '3-0 vs 1-0 = 1 pt (both home wins, diff wrong)' );
Mantia_E2E::assert_eq( 'outcome', $r['reason'], 'reason=outcome' );

// 0-3 (diff -3) vs 1-2 (diff -1) — both away wins, diff differs → outcome only.
$r = Mantia_Scoring::score_prediction( 0, 3, 1, 2 );
Mantia_E2E::assert_eq( 1, $r['points'], '0-3 vs 1-2 = 1 pt (both away wins, diff differs)' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '4. Wrong outcome → 0 pts' );
/* ------------------------------------------------------------------------- */
$r = Mantia_Scoring::score_prediction( 2, 1, 0, 1 );
Mantia_E2E::assert_eq( 0, $r['points'], '2-1 vs 0-1: home win predicted, away won = 0 pts' );
Mantia_E2E::assert_eq( 'miss', $r['reason'], 'reason=miss' );

$r = Mantia_Scoring::score_prediction( 0, 0, 1, 0 );
Mantia_E2E::assert_eq( 0, $r['points'], '0-0 vs 1-0: draw predicted, home won = 0 pts' );

$r = Mantia_Scoring::score_prediction( 1, 1, 2, 0 );
Mantia_E2E::assert_eq( 0, $r['points'], '1-1 vs 2-0: draw predicted, home won = 0 pts' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '5. `mantia_scoring_rules` filter overrides defaults' );
/* ------------------------------------------------------------------------- */
$override = function () {
	return array(
		'exact'           => 10,
		'goal_difference' => 5,
		'outcome'         => 2,
		'miss'            => 0,
	);
};
add_filter( 'mantia_scoring_rules', $override );

$r = Mantia_Scoring::score_prediction( 2, 1, 2, 1 );
Mantia_E2E::assert_eq( 10, $r['points'], 'override: exact = 10' );

$r = Mantia_Scoring::score_prediction( 3, 2, 2, 1 );
Mantia_E2E::assert_eq( 5, $r['points'], 'override: goal_difference = 5' );

$r = Mantia_Scoring::score_prediction( 3, 0, 1, 0 );
Mantia_E2E::assert_eq( 2, $r['points'], 'override: outcome = 2' );

remove_filter( 'mantia_scoring_rules', $override );

// Confirm defaults are restored after filter removal.
$r = Mantia_Scoring::score_prediction( 2, 1, 2, 1 );
Mantia_E2E::assert_eq( 5, $r['points'], 'defaults restored after filter removed' );

Mantia_E2E::finish();
