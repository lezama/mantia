<?php
/**
 * ADD example: mantia/get-my-groups (read-only complement)
 *
 * Shows the read-only error path: when the user has no Mantia presence
 * yet, the ability returns a stable WP_Error code rather than an empty
 * array (so the LLM can react with "first you need to join/create" copy).
 *
 * Run: bin/e2e.sh abilities/get-my-groups
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';

defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-my-groups' );

$persona = array(
	'phone' => '9999000804',
	'name'  => '__E2E__ MyGroups Owner',
);
Mantia_E2E::cleanup_persona( $persona );

/* ──── Case 1: cold user → WP_Error with stable code ────────────────── */

Mantia_E2E::step( '1. Cold user (no Mantia presence) → mantia_user_not_found' );

$result = Mantia_E2E::call_ability( 'mantia/get-my-groups', array(
	'user_phone' => $persona['phone'],
) );
Mantia_E2E::assert_true( is_wp_error( $result ), 'cold user returns WP_Error' );
if ( is_wp_error( $result ) ) {
	Mantia_E2E::assert_eq(
		'mantia_user_not_found',
		$result->get_error_code(),
		'error code = mantia_user_not_found'
	);
}

/* ──── Case 2: with one penca → groups[0] populated + active set ────── */

Mantia_E2E::step( '2. One penca → user_id + active_group_id + groups[0]' );

Mantia_E2E::send( $persona, 'crear penca __E2E__ MyGroups First' );
Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );

$result = Mantia_E2E::call_ability( 'mantia/get-my-groups', array(
	'user_phone' => $persona['phone'],
) );
Mantia_E2E::assert_ability_output( 'mantia/get-my-groups', $result );
Mantia_E2E::assert_true( (int) ( $result['user_id'] ?? 0 ) > 0, 'user_id populated' );
Mantia_E2E::assert_true( (int) ( $result['active_group_id'] ?? 0 ) > 0, 'active_group_id populated' );
Mantia_E2E::assert_eq( 1, count( (array) ( $result['groups'] ?? array() ) ), 'one group returned' );

$group_0 = (array) ( $result['groups'][0] ?? array() );
Mantia_E2E::assert_true( ! empty( $group_0['name'] ), 'group has name' );
Mantia_E2E::assert_true( ! empty( $group_0['invite_code'] ), 'group has invite_code' );

/* ──── Case 3: with two pencas → groups has 2, active is the latest ──── */

Mantia_E2E::step( '3. Two pencas → groups[].length=2, active = latest' );

Mantia_E2E::send( $persona, 'crear penca __E2E__ MyGroups Second' );
Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );

$result = Mantia_E2E::call_ability( 'mantia/get-my-groups', array(
	'user_phone' => $persona['phone'],
) );
Mantia_E2E::assert_ability_output( 'mantia/get-my-groups', $result );
Mantia_E2E::assert_eq( 2, count( (array) ( $result['groups'] ?? array() ) ), 'two groups returned' );

// is_active flag: exactly one group should be marked active and it
// should match active_group_id.
$active_id = (int) ( $result['active_group_id'] ?? 0 );
$active_in_groups = 0;
foreach ( (array) $result['groups'] as $g ) {
	if ( ! empty( $g['is_active'] ) ) {
		$active_in_groups++;
		Mantia_E2E::assert_eq( $active_id, (int) ( $g['id'] ?? 0 ), 'is_active row matches active_group_id' );
	}
}
Mantia_E2E::assert_eq( 1, $active_in_groups, 'exactly one is_active flag' );

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
