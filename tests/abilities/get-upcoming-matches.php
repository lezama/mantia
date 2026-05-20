<?php
/**
 * ADD: mantia/get-upcoming-matches (read-only)
 *
 * Run: bin/e2e.sh abilities/get-upcoming-matches
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-upcoming-matches' );

$persona = array( 'phone' => '9999000806', 'name' => '__E2E__ Upcoming Owner' );
Mantia_E2E::cleanup_persona( $persona );

/* ──── Case 1: no user_phone → matches without has_prediction tagging ─ */

Mantia_E2E::step( '1. Anonymous: matches[] returned, no has_prediction context' );

$result = Mantia_E2E::call_ability( 'mantia/get-upcoming-matches', array( 'hours_ahead' => 48 ) );
Mantia_E2E::assert_ability_output( 'mantia/get-upcoming-matches', $result );
Mantia_E2E::assert_true( isset( $result['matches'] ) && is_array( $result['matches'] ), 'matches is array' );
foreach ( (array) $result['matches'] as $m ) {
	Mantia_E2E::assert_eq( false, (bool) $m['has_prediction'], 'anon → has_prediction=false' );
}

/* ──── Case 2: hours_ahead clamp (input > max=240 should clamp) ──── */

Mantia_E2E::step( '2. hours_ahead clamp — accepts but clamps absurd values' );

$result = Mantia_E2E::call_ability( 'mantia/get-upcoming-matches', array( 'hours_ahead' => 9999 ) );
// Can't directly check the internal clamp, but the result should still
// return without error. The internal max=240 is enforced in source.
Mantia_E2E::assert_ability_output( 'mantia/get-upcoming-matches', $result );

/* ──── Case 3: with user_phone → has_prediction flag accurate ──── */

Mantia_E2E::step( '3. With penca + prediction → has_prediction=true for that match' );

Mantia_E2E::send( $persona, 'crear penca __E2E__ Upcoming' );
Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );

$user_id = (int) Mantia_Repository::find_user_by_phone( $persona['phone'] )->ID;
$result = Mantia_E2E::call_ability( 'mantia/get-upcoming-matches', array(
	'user_phone'  => $persona['phone'],
	'hours_ahead' => 240,
) );
Mantia_E2E::assert_ability_output( 'mantia/get-upcoming-matches', $result );

// Auto-fill on join means at least some matches should be flagged true.
$any_predicted = false;
foreach ( (array) $result['matches'] as $m ) {
	if ( ! empty( $m['has_prediction'] ) ) {
		$any_predicted = true;
		break;
	}
}
Mantia_E2E::assert_true( $any_predicted, 'auto-filled predictions surface as has_prediction=true' );

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
