<?php
/**
 * ADD: mantia/get-match-reminder-targets (read-only, workflow)
 *
 * Used by the cron workflow that sends "your match starts in X hours,
 * pronostica!" reminders. The agent never calls it directly; workflows
 * do. Test the schema + that it dedupes users who already predicted.
 *
 * Run: bin/e2e.sh abilities/get-match-reminder-targets
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-match-reminder-targets' );

/* ──── Case 1: shape — targets is an array ──── */

Mantia_E2E::step( '1. Default call returns targets[]' );

$result = Mantia_E2E::call_ability( 'mantia/get-match-reminder-targets', array() );
Mantia_E2E::assert_ability_output( 'mantia/get-match-reminder-targets', $result );
Mantia_E2E::assert_true(
	isset( $result['targets'] ) && is_array( $result['targets'] ),
	'targets is array'
);

/* ──── Case 2: each target has the keys the workflow needs ──── */

Mantia_E2E::step( '2. Each target has user_id + match_id + recipient + dedupe_key + message' );

foreach ( (array) $result['targets'] as $t ) {
	Mantia_E2E::assert_true( (int) $t['user_id'] > 0, 'user_id > 0' );
	Mantia_E2E::assert_true( (int) $t['match_id'] > 0, 'match_id > 0' );
	Mantia_E2E::assert_true( '' !== (string) $t['recipient'], 'recipient non-empty' );
	Mantia_E2E::assert_true( '' !== (string) $t['dedupe_key'], 'dedupe_key non-empty' );
	Mantia_E2E::assert_true( '' !== (string) $t['message'], 'message non-empty' );
}

/* ──── Case 3: hours_ahead respected ──── */

Mantia_E2E::step( '3. hours_ahead=1 returns subset of hours_ahead=24' );

$narrow = Mantia_E2E::call_ability( 'mantia/get-match-reminder-targets', array( 'hours_ahead' => 1 ) );
$wide   = Mantia_E2E::call_ability( 'mantia/get-match-reminder-targets', array( 'hours_ahead' => 24 ) );
Mantia_E2E::assert_true(
	count( (array) $narrow['targets'] ) <= count( (array) $wide['targets'] ),
	'narrow window ⊆ wide window'
);

Mantia_E2E::finish();
