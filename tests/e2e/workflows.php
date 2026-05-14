<?php
/**
 * Cron-driven workflows are registered + their abilities are wired
 * correctly. We don't actually invoke the workflow runner from CI
 * (it requires the agents-api action-scheduler bridge plus a tick),
 * but we verify the end-to-end resolution pipeline by chaining the
 * abilities exactly as the workflow does.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Workflows registered + resolution pipeline wired' );

Mantia_E2E::cleanup();
Mantia_Competitions::seed_defaults();
Mantia_Fixture_Seeder::seed();

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '1. The abilities the resolver workflow chains all exist' );
/* ------------------------------------------------------------------------- */
// We don't assert on workflow CPT posts because wp_register_workflow
// registers into agents-api's in-memory registry, not into a CPT. What
// we CAN assert deterministically: the abilities the workflow composes
// are registered + functional. If either breaks, the cron pipeline
// breaks the same way.
$expected_abilities = array(
	'mantia/get-finished-unresolved-matches',
	'mantia/resolve-match',
);
foreach ( $expected_abilities as $a ) {
	Mantia_E2E::assert_eq( true, null !== wp_get_ability( $a ), "ability {$a} is registered" );
}

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '2. End-to-end pipeline: predict → finish → workflow steps → resolved' );
/* ------------------------------------------------------------------------- */
$alice = Mantia_E2E::persona( 'Alice', 1 );

// Predict via the bot so the prediction exists in the same shape real traffic uses.
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::send( $alice, Mantia_E2E::TEST_NAME_PREFIX . ' Workflow' );

$match_id = Mantia_E2E::match_id_by_teams( 'Argentina', 'Mexico' );
Mantia_E2E::assert_eq( true, $match_id > 0, 'demo match exists' );

Mantia_E2E::send( $alice, "mantia:match:{$match_id}" );
Mantia_E2E::send( $alice, '3-1' );

// Simulate the match ending in the future (cron will pick it up later in real life).
Mantia_E2E::finish_match( $match_id, 3, 1 );

// --- workflow step 1: find finished-unresolved matches ---
// The ability uses an inline static fn so we go through the runtime
// rather than a class method — same path the workflow runner takes.
$ability = wp_get_ability( 'mantia/get-finished-unresolved-matches' );
$list    = $ability->execute( array() );
$matches = (array) ( $list['matches'] ?? array() );
$ids     = array_map( static fn ( array $m ): int => (int) $m['id'], $matches );
Mantia_E2E::assert_eq( true, in_array( $match_id, $ids, true ), 'finished-unresolved list includes our match' );

// --- workflow step 2 (foreach): resolve each match ---
foreach ( $matches as $m ) {
	$r = Mantia_Abilities::resolve_match( array( 'match_id' => (int) $m['id'] ) );
	if ( (int) $m['id'] === $match_id ) {
		Mantia_E2E::assert_eq( true, ! is_wp_error( $r ), 'workflow-style resolve succeeds for our match' );
	}
}

// After the workflow runs, the same list must be empty for our match.
$list_after = $ability->execute( array() );
$ids_after  = array_map( static fn ( array $m ): int => (int) $m['id'], (array) ( $list_after['matches'] ?? array() ) );
Mantia_E2E::assert_eq( false, in_array( $match_id, $ids_after, true ), 'after resolve, match drops out of pending list' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '4. Cleanup' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::cleanup();

Mantia_E2E::finish();
