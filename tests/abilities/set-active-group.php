<?php
/**
 * ADD: mantia/set-active-group (state-changing, dual-mode)
 *
 * Run: bin/e2e.sh abilities/set-active-group
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/set-active-group' );

$persona = array( 'phone' => '9999000808', 'name' => '__E2E__ SetActive Owner' );
Mantia_E2E::cleanup_persona( $persona );

/* ──── Case 1: cold user without invite_code → WP_Error ──── */

Mantia_E2E::step( '1. Cold user + no invite_code → mantia_user_not_found' );

$result = Mantia_E2E::call_ability( 'mantia/set-active-group', array(
	'user_phone' => $persona['phone'],
	'group_id'   => 0,
) );
Mantia_E2E::assert_true( is_wp_error( $result ), 'cold user returns WP_Error' );
if ( is_wp_error( $result ) ) {
	Mantia_E2E::assert_eq(
		'mantia_user_not_found',
		$result->get_error_code(),
		'error code = mantia_user_not_found'
	);
}

/* ──── Case 2: two pencas → switch active via group_id ──── */

Mantia_E2E::step( '2. Switch active group_id between two pencas' );

Mantia_E2E::create_penca_via_chat( $persona, '__E2E__ SetActive First' );
Mantia_E2E::create_penca_via_chat( $persona, '__E2E__ SetActive Second' );

$user_id = (int) Mantia_Repository::find_user_by_phone( $persona['phone'] )->ID;
$groups  = Mantia_Repository::user_groups_to_array( $user_id );
Mantia_E2E::assert_eq( 2, count( $groups ), 'two pencas created' );

$first_id  = (int) $groups[0]['id'];
$second_id = (int) $groups[1]['id'];

// Active should be the most recently created (second).
Mantia_E2E::assert_eq(
	$second_id,
	Mantia_Repository::active_group_id_for_user( $user_id ),
	'second is active by default'
);

// Switch to first.
$result = Mantia_E2E::call_ability( 'mantia/set-active-group', array(
	'user_phone' => $persona['phone'],
	'group_id'   => $first_id,
) );
Mantia_E2E::assert_ability_output( 'mantia/set-active-group', $result );
Mantia_E2E::assert_eq(
	$first_id,
	Mantia_Repository::active_group_id_for_user( $user_id ),
	'active switched to first'
);

/* ──── Case 3: invite_code delegates to join_group ──── */

Mantia_E2E::step( '3. invite_code passed → delegates to join_group flow' );

$result = Mantia_E2E::call_ability( 'mantia/set-active-group', array(
	'user_phone'  => '9999000818',
	'user_name'   => '__E2E__ SetActive Invitee',
	'invite_code' => (string) $groups[0]['invite_code'],
) );
Mantia_E2E::assert_ability_output( 'mantia/set-active-group', $result );

$invitee = Mantia_Repository::find_user_by_phone( '9999000818' );
Mantia_E2E::assert_not_null( $invitee, 'invitee user created via invite_code path' );

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::cleanup_persona( array( 'phone' => '9999000818' ) );
Mantia_E2E::finish();
