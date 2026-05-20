<?php
/**
 * ADD: mantia/get-whatsapp-home (read-only, composite)
 *
 * This is the "load the home view" ability — what the agent calls on
 * "hola" / "hoy" / "resumen". Composes upcoming + standings + pending +
 * active group. Two paths: cold user (needs_group=true) and authed user.
 *
 * Run: bin/e2e.sh abilities/get-whatsapp-home
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';
defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-whatsapp-home' );

$persona = array( 'phone' => '9999000809', 'name' => '__E2E__ Home Owner' );
Mantia_E2E::cleanup_persona( $persona );

/* ──── Case 1: cold user → needs_group=true + upcoming populated ──── */

Mantia_E2E::step( '1. Cold user (no penca) → needs_group=true' );

$result = Mantia_E2E::call_ability( 'mantia/get-whatsapp-home', array(
	'user_phone' => $persona['phone'],
) );
Mantia_E2E::assert_ability_output( 'mantia/get-whatsapp-home', $result );
Mantia_E2E::assert_eq( true, (bool) $result['needs_group'], 'flagged needs_group' );
Mantia_E2E::assert_eq( 'user_initiated', (string) $result['mode'], 'mode echoed' );
Mantia_E2E::assert_eq( null, $result['active_group'], 'no active group' );
Mantia_E2E::assert_true( is_array( $result['upcoming'] ?? null ), 'upcoming surfaced even for cold users' );

/* ──── Case 2: with penca → active_group populated + pending list ──── */

Mantia_E2E::step( '2. With penca → active_group, standings, pending' );

Mantia_E2E::send( $persona, 'crear penca __E2E__ Home' );
Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );

$result = Mantia_E2E::call_ability( 'mantia/get-whatsapp-home', array(
	'user_phone'  => $persona['phone'],
	'hours_ahead' => 240,
) );
Mantia_E2E::assert_ability_output( 'mantia/get-whatsapp-home', $result );
Mantia_E2E::assert_eq( false, (bool) $result['needs_group'], 'not needs_group anymore' );
Mantia_E2E::assert_true( ! empty( $result['active_group'] ), 'active_group populated' );
Mantia_E2E::assert_true( is_array( $result['standings'] ), 'standings array' );
Mantia_E2E::assert_true( is_array( $result['pending'] ), 'pending array' );
Mantia_E2E::assert_true( count( (array) $result['pending'] ) <= 10, 'pending capped at 10' );

/* ──── Case 3: each upcoming match tagged has_prediction ──── */

Mantia_E2E::step( '3. Every upcoming match has has_prediction boolean' );

foreach ( (array) $result['upcoming'] as $m ) {
	Mantia_E2E::assert_true( isset( $m['has_prediction'] ), 'has_prediction key set' );
	Mantia_E2E::assert_true( is_bool( $m['has_prediction'] ), 'has_prediction is bool' );
}

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
