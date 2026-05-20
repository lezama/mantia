<?php
/**
 * ADD: mantia/get-daily-digest-targets (read-only, workflow)
 *
 * Counterpart to the reminder workflow — picks recipients for the
 * once-a-day standings digest. Verifies shape + that it never floods
 * (max one target per user per day).
 *
 * Run: bin/e2e.sh abilities/get-daily-digest-targets
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-daily-digest-targets' );

/* ──── Case 1: targets array shape ──── */

Mantia_E2E::step( '1. targets[] returned' );

$result = Mantia_E2E::call_ability( 'mantia/get-daily-digest-targets', array() );
Mantia_E2E::assert_ability_output( 'mantia/get-daily-digest-targets', $result );
Mantia_E2E::assert_true(
	isset( $result['targets'] ) && is_array( $result['targets'] ),
	'targets is array'
);

/* ──── Case 2: one target per user max ──── */

Mantia_E2E::step( '2. No user appears twice in targets' );

$seen = array();
foreach ( (array) $result['targets'] as $t ) {
	$uid = (int) ( $t['user_id'] ?? 0 );
	if ( $uid <= 0 ) continue;
	Mantia_E2E::assert_true(
		! in_array( $uid, $seen, true ),
		"user_id={$uid} appears at most once"
	);
	$seen[] = $uid;
}

/* ──── Case 3: each target has dedupe_key for idempotent send ──── */

Mantia_E2E::step( '3. Every target has dedupe_key (workflow guard)' );

foreach ( (array) $result['targets'] as $t ) {
	Mantia_E2E::assert_true(
		! empty( $t['dedupe_key'] ),
		'dedupe_key non-empty for workflow idempotency'
	);
}

Mantia_E2E::finish();
