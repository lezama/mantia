<?php
/**
 * ADD: mantia/get-finished-unresolved-matches (read-only, no input)
 *
 * Lightest possible ability — no input, just returns array of matches
 * that finished but still need scoring. Used by the resolution workflow.
 *
 * Run: bin/e2e.sh abilities/get-finished-unresolved-matches
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-finished-unresolved-matches' );

/* ──── Case 1: empty call → matches array ──── */

Mantia_E2E::step( '1. No input → matches[] returned (possibly empty)' );

$result = Mantia_E2E::call_ability( 'mantia/get-finished-unresolved-matches', array() );
Mantia_E2E::assert_ability_output( 'mantia/get-finished-unresolved-matches', $result );
Mantia_E2E::assert_true( isset( $result['matches'] ) && is_array( $result['matches'] ), 'matches is array' );

/* ──── Case 2: each entry has the keys a scorer needs ──── */

Mantia_E2E::step( '2. Each finished match has scoreable fields' );

foreach ( (array) $result['matches'] as $m ) {
	Mantia_E2E::assert_true( isset( $m['id'] ), 'match has id' );
	Mantia_E2E::assert_true( isset( $m['home_team'] ) && isset( $m['away_team'] ), 'team names present' );
	Mantia_E2E::assert_true(
		array_key_exists( 'home_score', $m ) && array_key_exists( 'away_score', $m ),
		'home_score + away_score keys present'
	);
}

Mantia_E2E::finish();
