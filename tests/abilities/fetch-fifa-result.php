<?php
/**
 * ADD: mantia/fetch-fifa-result (read-only, external fetch)
 *
 * Wraps the results-fetcher pipeline. Hard to fully test without mocking
 * the upstream feed — we focus on contract behavior: known-id call
 * doesn't blow up, unknown-id returns a graceful shape (not unhandled
 * exception). Real fetcher behavior is exercised by the resolution flow
 * E2E suite (`tests/e2e/resolution-idempotency.php`).
 *
 * Run: bin/e2e.sh abilities/fetch-fifa-result
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/fetch-fifa-result' );

/* ──── Case 1: missing match_id → graceful (not exception) ──── */

Mantia_E2E::step( '1. match_id=0 → handled, no exception' );

$result = Mantia_E2E::call_ability( 'mantia/fetch-fifa-result', array( 'match_id' => 0 ) );
// Either WP_Error or an array; both fine. Just must not blow up.
Mantia_E2E::assert_true(
	is_wp_error( $result ) || is_array( $result ),
	'returns WP_Error or array (no exception)'
);

/* ──── Case 2: real match → array with at least the result shape ──── */

Mantia_E2E::step( '2. Real match_id → graceful return' );

$matches = Mantia_Repository::upcoming_matches_for_competition( 'libertadores-2026', 24 * 30 );
if ( empty( $matches ) ) {
	Mantia_E2E::step( '! no matches seeded; skipping' );
	Mantia_E2E::finish();
	return;
}
$result = Mantia_E2E::call_ability( 'mantia/fetch-fifa-result', array(
	'match_id' => (int) $matches[0]['id'],
) );
Mantia_E2E::assert_true(
	is_wp_error( $result ) || is_array( $result ),
	'returns WP_Error or array for real match (no exception)'
);

Mantia_E2E::finish();
