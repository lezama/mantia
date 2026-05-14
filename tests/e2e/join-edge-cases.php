<?php
/**
 * Invite-code joining: edge cases that real users hit. Whitespace,
 * invalid codes, joining the same group twice, case-insensitive matching.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Join edge cases' );

Mantia_E2E::cleanup();
Mantia_Competitions::seed_defaults();

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob', 2 );

// Set up a real group for join attempts.
Mantia_E2E::send( $alice, 'me llamo Alice' );
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::send( $alice, Mantia_E2E::TEST_NAME_PREFIX . ' Joiners' );

global $wpdb;
$group_id = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
		Mantia_CPTs::GROUP,
		Mantia_E2E::TEST_NAME_PREFIX . ' Joiners'
	)
);
$group  = Mantia_Repository::group_to_array( $group_id );
$invite = (string) $group['invite_code'];

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '1. Valid invite code joins successfully' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::send( $bob, 'me llamo Bob' );
$r = Mantia_E2E::send( $bob, $invite );
Mantia_E2E::assert_contains( $r, 'sume a', 'first-time join confirms' );
Mantia_E2E::assert_contains( $r, Mantia_E2E::TEST_NAME_PREFIX, 'reply names the penca' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '2. Joining the same group twice → already-member path' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $bob, $invite );
// The repository returns `already_member=true`; the bot says "cambie tu penca activa"
// instead of "te sume" — confirms we recognized the existing membership.
Mantia_E2E::assert_contains( $r, 'cambie tu penca activa', 'already-member path triggered' );
Mantia_E2E::assert_not_contains( $r, 'sume a', 'no duplicate "sume" message' );

// Bob's group list should still have exactly one entry for this group_id.
$bob_post = Mantia_Repository::find_user_by_phone( $bob['phone'] );
$bob_groups = (array) get_post_meta( (int) $bob_post->ID, Mantia_Repository::META_GROUP_IDS, true );
$dupes = array_filter( $bob_groups, static fn ( $g ): bool => (int) $g === $group_id );
Mantia_E2E::assert_eq( 1, count( $dupes ), 'group_id not duplicated in user META_GROUP_IDS' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '3. Invite code with whitespace is normalized' );
/* ------------------------------------------------------------------------- */
$padded = '  ' . $invite . '  ';
$carla  = Mantia_E2E::persona( 'Carla', 3 );
Mantia_E2E::send( $carla, 'me llamo Carla' );
$r = Mantia_E2E::send( $carla, $padded );
Mantia_E2E::assert_contains( $r, 'sume', 'padded code still resolves to a join' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '4. Lowercase invite code → matched (normalize is case-insensitive)' );
/* ------------------------------------------------------------------------- */
$dora = Mantia_E2E::persona( 'Dora', 4 );
Mantia_E2E::send( $dora, 'me llamo Dora' );
$r = Mantia_E2E::send( $dora, strtolower( $invite ) );
Mantia_E2E::assert_contains( $r, 'sume', 'lowercase code joins same group' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '5. Invalid invite code does NOT trigger join — falls through to LLM' );
/* ------------------------------------------------------------------------- */
$evan = Mantia_E2E::persona( 'Evan', 5 );
Mantia_E2E::send( $evan, 'me llamo Evan' );
$r = Mantia_E2E::send( $evan, 'NOEXISTECODIGOXYZ' );
// The preflight returns null for an unknown invite code → falls through to the LLM,
// which we don't run in this test. The preflight result here is the "(null...)" sentinel.
$reply = (string) ( $r['reply'] ?? '' );
Mantia_E2E::assert_eq( true, str_starts_with( $reply, '(null' ), 'unknown code falls through (no false join)' );

// Confirm Evan was never auto-added to any group.
$evan_post = Mantia_Repository::find_user_by_phone( $evan['phone'] );
$evan_groups = $evan_post ? (array) get_post_meta( (int) $evan_post->ID, Mantia_Repository::META_GROUP_IDS, true ) : array();
Mantia_E2E::assert_eq( 0, count( array_filter( $evan_groups ) ), 'no phantom group memberships' );

Mantia_E2E::step( '6. Cleanup' );
Mantia_E2E::cleanup();

Mantia_E2E::finish();
