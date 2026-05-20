<?php
/**
 * ADD example: mantia/get-standings (read-only)
 *
 * Read-only abilities are the easiest case — no side effects, no
 * persistence, no cleanup. The test still exercises: contract,
 * resolver fallbacks (phone → active group), pagination cap,
 * graceful-empty when no group can be resolved.
 *
 * Run: bin/e2e.sh abilities/get-standings
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';

defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/get-standings' );

$persona = array(
	'phone' => '9999000802',
	'name'  => '__E2E__ Standings Owner',
);
Mantia_E2E::cleanup_persona( $persona );

// Bootstrap: create a penca + register one prediction so standings have
// at least one row to read. Use the WhatsApp flow for the setup (it's
// what real users hit) — the ability test focuses on standings retrieval.
Mantia_E2E::send( $persona, 'crear penca __E2E__ Standings' );
Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );

$user_id = (int) Mantia_Repository::find_user_by_phone( $persona['phone'] )->ID;
$groups  = Mantia_Repository::user_groups_to_array( $user_id );
$group_id = (int) ( $groups[0]['id'] ?? 0 );
Mantia_E2E::assert_true( $group_id > 0, 'persona has at least one penca' );

/* ──── Case 1: scope=group with explicit group_id ────────────────────── */

Mantia_E2E::step( '1. scope=group with explicit group_id' );

$result = Mantia_E2E::call_ability( 'mantia/get-standings', array(
	'scope'    => 'group',
	'group_id' => $group_id,
) );
Mantia_E2E::assert_ability_output( 'mantia/get-standings', $result );
Mantia_E2E::assert_eq( 'group', (string) ( $result['scope'] ?? '' ), 'echoed scope=group' );
Mantia_E2E::assert_eq( $group_id, (int) ( $result['group_id'] ?? 0 ), 'echoed group_id' );
Mantia_E2E::assert_true( is_array( $result['standings'] ?? null ), 'standings is an array' );

/* ──── Case 2: scope=group, phone-only → active-group resolver ───────── */

Mantia_E2E::step( '2. scope=group + user_phone → resolves active group' );

$result = Mantia_E2E::call_ability( 'mantia/get-standings', array(
	'scope'      => 'group',
	'user_phone' => $persona['phone'],
) );
Mantia_E2E::assert_ability_output( 'mantia/get-standings', $result );
Mantia_E2E::assert_eq( $group_id, (int) ( $result['group_id'] ?? 0 ), 'resolved active group_id from phone' );

/* ──── Case 3: scope=global never needs a group ──────────────────────── */

Mantia_E2E::step( '3. scope=global returns leaderboard without group_id' );

$result = Mantia_E2E::call_ability( 'mantia/get-standings', array(
	'scope' => 'global',
	'limit' => 5,
) );
Mantia_E2E::assert_ability_output( 'mantia/get-standings', $result );
Mantia_E2E::assert_eq( 'global', (string) ( $result['scope'] ?? '' ), 'echoed scope=global' );
Mantia_E2E::assert_eq( 0, (int) ( $result['group_id'] ?? -1 ), 'group_id is 0 for global scope' );
Mantia_E2E::assert_true( count( (array) $result['standings'] ) <= 5, 'respects limit=5' );

/* ──── Case 4: graceful empty when no group can be resolved ──────────── */

Mantia_E2E::step( '4. scope=group with no hint → needs_group=true (no error)' );

$result = Mantia_E2E::call_ability( 'mantia/get-standings', array(
	'scope' => 'group',
) );
Mantia_E2E::assert_ability_output( 'mantia/get-standings', $result );
Mantia_E2E::assert_eq( true, (bool) ( $result['needs_group'] ?? false ), 'flagged needs_group' );
Mantia_E2E::assert_eq( array(), (array) ( $result['standings'] ?? null ), 'standings empty' );

/* ──── Case 5: limit clamp (input above max=50 should clamp to 50) ──── */

Mantia_E2E::step( '5. limit clamp — accepts but clamps absurd values' );

$result = Mantia_E2E::call_ability( 'mantia/get-standings', array(
	'scope'    => 'group',
	'group_id' => $group_id,
	'limit'    => 999,
) );
// Schema declares max=50 — the ability should NOT return more than 50
// rows even when the caller asks for more. (If the leaderboard has
// fewer than 50 entries, this is trivially satisfied.)
Mantia_E2E::assert_true( count( (array) $result['standings'] ) <= 50, 'clamped to max=50' );

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
