<?php
/**
 * QA — phone-impersonation guard.
 *
 * Simulates the production WA-bridged session (where wp_set_current_user
 * has fired for the authenticated phone-owner) and verifies that the
 * mantia abilities reject `user_phone` arguments that don't match the
 * current user. This closes the prompt-injection attack surface where an
 * LLM agent can be coerced into calling `get_user_history(phone=mallory)`
 * or `join_group(phone=mallory, code=spam)` and leak data / mutate state
 * on behalf of another user.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'QA — phone-impersonation guard on abilities' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Setup' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::cleanup();

$alice   = Mantia_E2E::persona( 'Alice',   1 );
$mallory = Mantia_E2E::persona( 'Mallory', 7 );

// Bootstrap both users so they exist in the system.
$alice_uid   = Mantia_Repository::get_or_create_user( $alice['phone'],   $alice['name'],   $alice['phone'] );
$mallory_uid = Mantia_Repository::get_or_create_user( $mallory['phone'], $mallory['name'], $mallory['phone'] );

// Build a penca for Alice, predict a match so there's something to leak.
$alice_group = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' Private Alice', 'IMPALICE', '', 'libertadores-semana' );
Mantia_Repository::join_group( $alice['phone'], 'IMPALICE', $alice['name'], $alice['phone'] );
$match = Mantia_E2E::schedule_match_in_minutes( 60, 'libertadores-semana' );
Mantia_Repository::register_prediction( $alice_uid, (int) $match['id'], $alice_group, 2, 1 );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Demote test session to a non-admin mantia_player (simulates WA-bridged user)' );
/* ─────────────────────────────────────────────────────────────────────── */
// The test harness logs in as admin by default. The phone-binding guard
// only fires for non-admin sessions (admin/workflow/CLI bypass). Switch
// the current user to Mallory's wp_user so the guard activates.
wp_set_current_user( $mallory_uid );
$now_uid = (int) get_current_user_id();
Mantia_E2E::assert_eq( $mallory_uid, $now_uid, 'current_user switched to Mallory' );
Mantia_E2E::assert_true( ! current_user_can( 'manage_options' ), 'Mallory is not an admin' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. get_user_history — Mallory cannot read Alice\'s history' );
/* ─────────────────────────────────────────────────────────────────────── */
$leak = Mantia_Abilities::get_user_history( array( 'user_phone' => $alice['phone'] ) );
Mantia_E2E::assert_true( is_wp_error( $leak ), 'cross-phone history call rejected' );
Mantia_E2E::assert_eq( 'mantia_phone_mismatch', is_wp_error( $leak ) ? $leak->get_error_code() : '', 'rejection code is mantia_phone_mismatch' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. get_my_groups — Mallory cannot list Alice\'s pencas' );
/* ─────────────────────────────────────────────────────────────────────── */
$leak2 = Mantia_Abilities::get_my_groups( array( 'user_phone' => $alice['phone'] ) );
Mantia_E2E::assert_true( is_wp_error( $leak2 ), 'cross-phone get_my_groups rejected' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. set_active_group — Mallory cannot flip Alice\'s active penca' );
/* ─────────────────────────────────────────────────────────────────────── */
$flip = Mantia_Abilities::set_active_group( array(
	'user_phone' => $alice['phone'],
	'group_id'   => $alice_group,
) );
Mantia_E2E::assert_true( is_wp_error( $flip ), 'cross-phone set_active_group rejected' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. join_group — Mallory cannot force-join Alice into a group' );
/* ─────────────────────────────────────────────────────────────────────── */
$mallory_group = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' Mallory Spam', 'SPAM1', '', 'libertadores-semana' );
$force = Mantia_Abilities::join_group( array(
	'user_phone'  => $alice['phone'],
	'invite_code' => 'SPAM1',
) );
Mantia_E2E::assert_true( is_wp_error( $force ), 'cross-phone join_group rejected' );

$alice_groups_after = (array) get_user_meta( $alice_uid, Mantia_Repository::META_GROUP_IDS, true );
Mantia_E2E::assert_true(
	! in_array( $mallory_group, array_map( 'intval', $alice_groups_after ), true ),
	'Alice was NOT silently added to Mallory\'s group'
);

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '6. create_group — Mallory cannot create a group on Alice\'s behalf' );
/* ─────────────────────────────────────────────────────────────────────── */
$forged = Mantia_Abilities::create_group( array(
	'user_phone' => $alice['phone'],
	'group_name' => Mantia_E2E::TEST_NAME_PREFIX . ' Forged',
	'competition_id' => 'libertadores-semana',
) );
Mantia_E2E::assert_true( is_wp_error( $forged ), 'cross-phone create_group rejected' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '7. register_prediction — Mallory cannot predict as Alice' );
/* ─────────────────────────────────────────────────────────────────────── */
$forged_pred = Mantia_Abilities::register_prediction( array(
	'user_phone' => $alice['phone'],
	'match_id'   => (int) $match['id'],
	'home_score' => 9,
	'away_score' => 9,
) );
Mantia_E2E::assert_true( is_wp_error( $forged_pred ), 'cross-phone register_prediction rejected' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8. Same-phone call from Mallory\'s session is still allowed' );
/* ─────────────────────────────────────────────────────────────────────── */
$own = Mantia_Abilities::get_my_groups( array( 'user_phone' => $mallory['phone'] ) );
Mantia_E2E::assert_true( ! is_wp_error( $own ), 'Mallory CAN list her own groups (guard allows same-phone)' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8b. get_standings — Mallory cannot get Alice\'s active-group leaderboard' );
/* ─────────────────────────────────────────────────────────────────────── */
$stand = Mantia_Abilities::get_standings( array( 'user_phone' => $alice['phone'] ) );
Mantia_E2E::assert_true(
	isset( $stand['error'] ) && 'mantia_phone_mismatch' === $stand['error'],
	'cross-phone get_standings returns the needs_group shape with error code'
);
Mantia_E2E::assert_eq( array(), $stand['standings'] ?? array(), 'no standings leaked in the response' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8c. get_upcoming_matches — `has_prediction` is stripped on cross-phone calls' );
/* ─────────────────────────────────────────────────────────────────────── */
$upc = Mantia_Abilities::get_upcoming_matches( array( 'user_phone' => $alice['phone'] ) );
$any_pred_flag = false;
foreach ( (array) ( $upc['matches'] ?? array() ) as $m ) {
	if ( ! empty( $m['has_prediction'] ) ) { $any_pred_flag = true; break; }
}
Mantia_E2E::assert_true( ! $any_pred_flag, 'no has_prediction:true survives a cross-phone call' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8d. get_whatsapp_home — same shape on error (no missing keys for downstream)' );
/* ─────────────────────────────────────────────────────────────────────── */
$home_err = Mantia_Abilities::get_whatsapp_home( array( 'user_phone' => $alice['phone'] ) );
foreach ( array( 'mode', 'needs_group', 'groups', 'active_group', 'standings', 'upcoming', 'pending', 'outbound_enabled' ) as $required_key ) {
	Mantia_E2E::assert_true( array_key_exists( $required_key, $home_err ), "error-degraded home still has key: {$required_key}" );
}
Mantia_E2E::assert_eq( 'mantia_phone_mismatch', $home_err['error'] ?? '', 'error code present on degraded home' );
Mantia_E2E::assert_eq( array(), $home_err['groups'], 'no group data leaked' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8e. register_prediction — Mallory in her own group, but for a group she\'s NOT in → mantia_not_a_member' );
/* ─────────────────────────────────────────────────────────────────────── */
// Same-phone call (so the impersonation guard passes), but $group_id
// points to Alice's group → the IDOR check should kick in.
$idor = Mantia_Abilities::register_prediction( array(
	'user_phone' => $mallory['phone'],
	'match_id'   => (int) $match['id'],
	'group_id'   => $alice_group,
	'home_score' => 0,
	'away_score' => 0,
) );
Mantia_E2E::assert_true( is_wp_error( $idor ), 'same-phone but non-member group → rejected' );
Mantia_E2E::assert_eq( 'mantia_not_a_member', is_wp_error( $idor ) ? $idor->get_error_code() : '', 'rejection code is mantia_not_a_member' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '9. Admin session still bypasses the guard (workflows, REST)' );
/* ─────────────────────────────────────────────────────────────────────── */
// Restore admin login — workflows, scheduled jobs and REST endpoints
// MUST be able to act on any phone (the guard is for the LLM agent's
// untrusted context).
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$admin_call = Mantia_Abilities::get_my_groups( array( 'user_phone' => $alice['phone'] ) );
Mantia_E2E::assert_true( ! is_wp_error( $admin_call ), 'admin can fetch any user\'s groups' );

Mantia_E2E::finish();
