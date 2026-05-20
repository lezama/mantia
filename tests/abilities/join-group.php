<?php
/**
 * ADD example: mantia/join-group (state-changing)
 *
 * Covers the most common write — adding a user to an existing penca via
 * its invite code. State-changing tests need careful fixture isolation
 * AND post-assertion of side effects (group membership, active-group,
 * auto-filled predictions).
 *
 * Run: bin/e2e.sh abilities/join-group
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';

defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/join-group' );

$owner = array(
	'phone' => '9999000803',
	'name'  => '__E2E__ Join Owner',
);
$invitee = array(
	'phone' => '9999000813',
	'name'  => '__E2E__ Join Invitee',
);
Mantia_E2E::cleanup_persona( $owner );
Mantia_E2E::cleanup_persona( $invitee );

/* ──── Bootstrap: owner creates a penca, capture invite code ─────────── */

Mantia_E2E::create_penca_via_chat( $owner, '__E2E__ Join Target' );

$owner_id    = (int) Mantia_Repository::find_user_by_phone( $owner['phone'] )->ID;
$owner_groups = Mantia_Repository::user_groups_to_array( $owner_id );
$group       = $owner_groups[0] ?? null;
Mantia_E2E::assert_not_null( $group, 'owner has a penca' );
$invite_code = (string) ( $group['invite_code'] ?? '' );
Mantia_E2E::assert_true( '' !== $invite_code, 'invite code minted' );

/* ──── Case 1: happy path — new user joins by code ──────────────────── */

Mantia_E2E::step( '1. Happy path: new user joins by invite code' );

$result = Mantia_E2E::call_ability( 'mantia/join-group', array(
	'invite_code' => $invite_code,
	'user_phone'  => $invitee['phone'],
	'user_name'   => $invitee['name'],
) );
Mantia_E2E::assert_ability_output( 'mantia/join-group', $result );

// Side effect: the invitee user post exists.
$invitee_post = Mantia_Repository::find_user_by_phone( $invitee['phone'] );
Mantia_E2E::assert_not_null( $invitee_post, 'invitee user created' );

// Side effect: the user's name is captured (R5 caught a regression where
// title was the literal "QA" instead of the passed name — this assertion
// would have failed loudly).
Mantia_E2E::assert_eq(
	$invitee['name'],
	(string) get_the_title( (int) $invitee_post->ID ),
	'invitee post_title = passed name'
);

// Side effect: the group's member list now contains the invitee.
$members = Mantia_Repository::group_members( (int) $group['id'] );
$found   = false;
foreach ( $members as $m ) {
	if ( (int) $m['id'] === (int) $invitee_post->ID ) {
		$found = true;
		break;
	}
}
Mantia_E2E::assert_true( $found, 'invitee is in the group roster' );

// Side effect: invitee's active group is the joined one.
Mantia_E2E::assert_eq(
	(int) $group['id'],
	Mantia_Repository::active_group_id_for_user( (int) $invitee_post->ID ),
	'invitee active_group = joined group'
);

/* ──── Case 2: invalid invite code → WP_Error ───────────────────────── */

Mantia_E2E::step( '2. Invalid invite code → WP_Error' );

$result = Mantia_E2E::call_ability( 'mantia/join-group', array(
	'invite_code' => 'OBVIAMENTENOEXISTE123',
	'user_phone'  => '9999000823',
	'user_name'   => '__E2E__ Bad',
) );
Mantia_E2E::assert_true( is_wp_error( $result ), 'unknown code returns WP_Error' );
if ( is_wp_error( $result ) ) {
	Mantia_E2E::assert_eq(
		'mantia_group_not_found',
		$result->get_error_code(),
		'stable error code = mantia_group_not_found'
	);
}

// Side effect: NO user was created for the bad phone.
$bad_user = Mantia_Repository::find_user_by_phone( '9999000823' );
Mantia_E2E::assert_eq( null, $bad_user, 'no orphan user on failed join' );

/* ──── Case 3: idempotent — joining the same group twice ────────────── */

Mantia_E2E::step( '3. Idempotent: same user joins twice' );

$result = Mantia_E2E::call_ability( 'mantia/join-group', array(
	'invite_code' => $invite_code,
	'user_phone'  => $invitee['phone'],
	'user_name'   => $invitee['name'],
) );
Mantia_E2E::assert_ability_output( 'mantia/join-group', $result );

// Side effect: still only one membership (no duplicate).
$members_after = Mantia_Repository::group_members( (int) $group['id'] );
Mantia_E2E::assert_eq(
	count( $members ),
	count( $members_after ),
	'no duplicate member created on re-join'
);

/* ──── Cleanup ───────────────────────────────────────────────────────── */

Mantia_E2E::cleanup_persona( $owner );
Mantia_E2E::cleanup_persona( $invitee );
Mantia_E2E::finish();
