<?php
/**
 * Member listing renders correctly across all four surfaces:
 * share, post-create, post-join, post-switch. All four share the
 * member_lines() helper, so this test guards them as a unit.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Members render in share / create / join / switch' );

Mantia_E2E::cleanup();
Mantia_Competitions::seed_defaults();

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob', 2 );
$carla = Mantia_E2E::persona( 'Carla', 3 );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '1. Post-CREATE reply shows "Solo vos por ahora"' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::send( $alice, 'me llamo Alice' );
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
$r = Mantia_E2E::send( $alice, Mantia_E2E::TEST_NAME_PREFIX . ' Roster' );
Mantia_E2E::assert_contains( $r, 'Solo vos por ahora', 'single-member empty state' );
Mantia_E2E::assert_not_contains( $r, 'Quiénes están', 'no roster header for 1 member' );

global $wpdb;
$group_id = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
		Mantia_CPTs::GROUP,
		Mantia_E2E::TEST_NAME_PREFIX . ' Roster'
	)
);
$group  = Mantia_Repository::group_to_array( $group_id );
$invite = (string) $group['invite_code'];

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '2. Post-JOIN reply (Bob) lists Alice + Bob with "(vos)" on Bob' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::send( $bob, 'me llamo Bob' );
$r = Mantia_E2E::send( $bob, $invite );
Mantia_E2E::assert_contains( $r, 'Quiénes están (2)', 'roster header shows 2' );
Mantia_E2E::assert_contains( $r, 'Alice', 'lists Alice' );
Mantia_E2E::assert_contains( $r, 'Bob', 'lists Bob' );
Mantia_E2E::assert_contains( $r, '_(vos)_', 'marker for the current user (Bob)' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '3. Post-JOIN reply (Carla) lists all 3' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::send( $carla, 'me llamo Carla' );
$r = Mantia_E2E::send( $carla, $invite );
Mantia_E2E::assert_contains( $r, 'Quiénes están (3)', 'header now shows 3' );
Mantia_E2E::assert_contains( $r, 'Alice', 'Alice present' );
Mantia_E2E::assert_contains( $r, 'Bob', 'Bob present' );
Mantia_E2E::assert_contains( $r, 'Carla', 'Carla present' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '4. SHARE reply (link / Invitar) lists same roster' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $alice, 'mantia:cmd:share-link' );
Mantia_E2E::assert_contains( $r, 'Quiénes están (3)', 'share roster header' );
Mantia_E2E::assert_contains( $r, 'wa.me/', 'share includes wa.me link' );
Mantia_E2E::assert_contains( $r, '/penca/g/', 'share includes web URL' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '5. SWITCH reply lists roster of new active penca' );
/* ------------------------------------------------------------------------- */
// Alice creates a second penca, then switches back to the first.
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:libertadores-2026' );
Mantia_E2E::send( $alice, Mantia_E2E::TEST_NAME_PREFIX . ' Second' );
// Now switch back to the first (the 3-member one).
$r = Mantia_E2E::send( $alice, "mantia:switch:{$group_id}" );
Mantia_E2E::assert_contains( $r, 'Roster', 'switch reply names the target penca' );
Mantia_E2E::assert_contains( $r, 'Quiénes están (3)', 'switch reply shows roster' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '6. group_members() helper returns sorted alphabetically' );
/* ------------------------------------------------------------------------- */
$members = Mantia_Repository::group_members( $group_id );
$names   = array_map( static fn ( array $m ): string => (string) $m['display_name'], $members );
$sorted  = $names;
sort( $sorted, SORT_FLAG_CASE | SORT_STRING );
Mantia_E2E::assert_eq( $sorted, $names, 'members sorted case-insensitive alpha' );

Mantia_E2E::step( '7. Cleanup' );
Mantia_E2E::cleanup();

Mantia_E2E::finish();
