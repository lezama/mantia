<?php
/**
 * ADD: mantia/get-user-history (read-only)
 *
 * Run: bin/e2e.sh abilities/get-user-history
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-user-history' );

$persona = array( 'phone' => '9999000807', 'name' => '__E2E__ History Owner' );
Mantia_E2E::cleanup_persona( $persona );

/* ──── Case 1: cold user → empty history, no error ──── */

Mantia_E2E::step( '1. Cold user → empty history (gracefully)' );

$result = Mantia_E2E::call_ability( 'mantia/get-user-history', array(
	'user_phone' => $persona['phone'],
) );
Mantia_E2E::assert_ability_output( 'mantia/get-user-history', $result );
Mantia_E2E::assert_true( is_array( $result['predictions'] ?? null ), 'predictions array' );
Mantia_E2E::assert_eq( 0, count( (array) $result['predictions'] ), 'no predictions for cold user' );

/* ──── Case 2: with penca → autofilled predictions surface in history ─ */

Mantia_E2E::step( '2. After join + auto-fill → history populated' );

Mantia_E2E::send( $persona, 'crear penca __E2E__ History' );
Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );

$result = Mantia_E2E::call_ability( 'mantia/get-user-history', array(
	'user_phone' => $persona['phone'],
) );
Mantia_E2E::assert_ability_output( 'mantia/get-user-history', $result );
Mantia_E2E::assert_true(
	count( (array) ( $result['predictions'] ?? array() ) ) > 0,
	'predictions array populated after auto-fill'
);

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
