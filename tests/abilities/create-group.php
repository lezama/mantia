<?php
/**
 * ADD example: mantia/create-group (state-changing complement)
 *
 * Stresses the validation + competition resolver paths. Create-group is
 * the canonical "user does an action that mints persistent state" — so
 * it MUST validate group_name (required) AND competition_id (whitelist)
 * before touching the database.
 *
 * Run: bin/e2e.sh abilities/create-group
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';

defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/create-group' );

$persona = array(
	'phone' => '9999000805',
	'name'  => '__E2E__ Create Owner',
);
Mantia_E2E::cleanup_persona( $persona );

/* ──── Case 1: happy path — explicit competition_id ─────────────────── */

Mantia_E2E::step( '1. Happy path: explicit competition_id' );

$result = Mantia_E2E::call_ability( 'mantia/create-group', array(
	'group_name'     => '__E2E__ Create Happy',
	'competition_id' => 'libertadores-semana',
	'user_phone'     => $persona['phone'],
	'user_name'      => $persona['name'],
) );
Mantia_E2E::assert_ability_output( 'mantia/create-group', $result );
Mantia_E2E::assert_true( ! empty( $result['invite_code'] ), 'invite_code minted' );
Mantia_E2E::assert_true( ! empty( $result['invite_message'] ), 'invite_message populated' );
Mantia_E2E::assert_true( ! empty( $result['group']['name'] ), 'group.name echoed' );
Mantia_E2E::assert_eq(
	'libertadores-semana',
	(string) ( $result['group']['competition_id'] ?? '' ),
	'group attached to libertadores-semana'
);

// Side effect: creator is auto-joined.
$user = Mantia_Repository::find_user_by_phone( $persona['phone'] );
Mantia_E2E::assert_not_null( $user, 'creator user post exists' );
$active = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
Mantia_E2E::assert_eq( (int) $result['group']['id'], $active, 'creator active_group = new group' );

/* ──── Case 2: missing group_name → WP_Error ────────────────────────── */

Mantia_E2E::step( '2. Missing group_name → WP_Error' );

$result = Mantia_E2E::call_ability( 'mantia/create-group', array(
	'user_phone' => $persona['phone'],
	'user_name'  => $persona['name'],
) );
Mantia_E2E::assert_true( is_wp_error( $result ), 'missing group_name returns WP_Error' );
if ( is_wp_error( $result ) ) {
	Mantia_E2E::assert_eq(
		'mantia_group_name_required',
		$result->get_error_code(),
		'stable error code'
	);
}

/* ──── Case 3: unknown competition_id → WP_Error with helpful message ─ */

Mantia_E2E::step( '3. Unknown competition_id → WP_Error with hint' );

$result = Mantia_E2E::call_ability( 'mantia/create-group', array(
	'group_name'     => '__E2E__ Create Bad Comp',
	'competition_id' => 'this-tournament-doesnt-exist',
	'user_phone'     => $persona['phone'],
	'user_name'      => $persona['name'],
) );
Mantia_E2E::assert_true( is_wp_error( $result ), 'unknown competition returns WP_Error' );
if ( is_wp_error( $result ) ) {
	Mantia_E2E::assert_eq(
		'mantia_competition_unknown',
		$result->get_error_code(),
		'stable error code = mantia_competition_unknown'
	);
}

/* ──── Case 4: default competition fallback when omitted ────────────── */

Mantia_E2E::step( '4. No competition_id → defaults to install default' );

$result = Mantia_E2E::call_ability( 'mantia/create-group', array(
	'group_name' => '__E2E__ Create Default',
	'user_phone' => $persona['phone'],
	'user_name'  => $persona['name'],
) );
Mantia_E2E::assert_ability_output( 'mantia/create-group', $result );
$expected_default = Mantia_Competitions::default_id();
Mantia_E2E::assert_eq(
	$expected_default,
	(string) ( $result['group']['competition_id'] ?? '' ),
	'defaulted to install default competition'
);

/* ──── Case 5: invite_code is unique across created groups ──────────── */

Mantia_E2E::step( '5. Two groups → distinct invite codes' );

$result_a = Mantia_E2E::call_ability( 'mantia/create-group', array(
	'group_name' => '__E2E__ Create Distinct A',
	'user_phone' => $persona['phone'],
	'user_name'  => $persona['name'],
) );
$result_b = Mantia_E2E::call_ability( 'mantia/create-group', array(
	'group_name' => '__E2E__ Create Distinct B',
	'user_phone' => $persona['phone'],
	'user_name'  => $persona['name'],
) );
Mantia_E2E::assert_true(
	(string) $result_a['invite_code'] !== (string) $result_b['invite_code'],
	'invite codes are distinct between groups'
);

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
