<?php
/**
 * ADD example: mantia/register-prediction
 *
 * Exercises the ability in isolation against five real cases:
 *   1. Happy path: home/away order known, scores valid → prediction saved.
 *   2. Natural-language order: first/second + names → resolver picks
 *      next match for those teams + maps to home/away.
 *   3. Ambiguous: no match_id, no team hints → returns WP_Error.
 *   4. Auto-routing: user in N pencas → prediction lands in all of them.
 *   5. Schema enforcement: response always has prediction/match/group keys.
 *
 * Pattern intentionally short so anyone shipping a new ability has a
 * template to copy. See docs/ability-driven-development.md for the
 * design rationale + when to write one of these vs a flow E2E.
 *
 * Run: bin/e2e.sh abilities/register-prediction
 *
 * @package Mantia
 */

require_once __DIR__ . '/../lib.php';

defined( 'ABSPATH' ) || exit;

Mantia_E2E::start( 'ability: mantia/register-prediction' );

/* ──── Fixtures ──────────────────────────────────────────────────────── */

$persona = array(
	'phone' => '9999000801',
	'name'  => '__E2E__ Owner ADD',
);
Mantia_E2E::cleanup_persona( $persona );

// Bootstrap a user with one penca in libertadores-semana.
$boot = Mantia_E2E::send(
	$persona,
	'crear penca __E2E__ ADD Test'
);
Mantia_E2E::assert_contains( $boot, 'torneo', 'creator picker shown' );

$pick = Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );
Mantia_E2E::assert_contains( $pick, 'Creaste', 'penca created' );

$user_id = Mantia_Repository::find_user_by_phone( $persona['phone'] )->ID;
$group   = Mantia_Repository::user_groups_to_array( (int) $user_id )[0] ?? null;
Mantia_E2E::assert_not_null( $group, 'user is in a penca' );

/* ──── Case 1: happy path with home_score/away_score ─────────────────── */

Mantia_E2E::step( '1. Happy path: explicit home/away scores' );

// Pick any upcoming match in this competition.
$matches = Mantia_Repository::upcoming_matches_for_competition( 'libertadores-2026', 24 * 7 );
if ( empty( $matches ) ) {
	Mantia_E2E::step( '! no upcoming matches seeded; skipping ability tests' );
	Mantia_E2E::cleanup_persona( $persona );
	Mantia_E2E::finish();
	return;
}
$match_id = (int) $matches[0]['id'];

$result = Mantia_E2E::call_ability( 'mantia/register-prediction', array(
	'user_phone' => $persona['phone'],
	'user_name'  => $persona['name'],
	'match_id'   => $match_id,
	'home_score' => 2,
	'away_score' => 1,
) );

Mantia_E2E::assert_ability_output( 'mantia/register-prediction', $result );
Mantia_E2E::assert_eq( 2, (int) ( $result['prediction']['home_score'] ?? -1 ), 'home_score persisted' );
Mantia_E2E::assert_eq( 1, (int) ( $result['prediction']['away_score'] ?? -1 ), 'away_score persisted' );

/* ──── Case 2: natural-language first/second + team names ────────────── */

Mantia_E2E::step( '2. Natural-language: first_team / second_team resolver' );

// Use the same match — register_prediction should map first/second against
// the existing fixture, regardless of home/away order.
$home = (string) ( $matches[0]['home_team'] ?? '' );
$away = (string) ( $matches[0]['away_team'] ?? '' );
$result = Mantia_E2E::call_ability( 'mantia/register-prediction', array(
	'user_phone'   => $persona['phone'],
	'user_name'    => $persona['name'],
	'first_team'   => $away,   // intentionally swapped
	'first_score'  => 3,
	'second_team'  => $home,
	'second_score' => 0,
) );

Mantia_E2E::assert_ability_output( 'mantia/register-prediction', $result );
// The resolver should have remapped these to home/away of the fixture.
Mantia_E2E::assert_eq( 0, (int) ( $result['prediction']['home_score'] ?? -1 ), 'home was second (mapped)' );
Mantia_E2E::assert_eq( 3, (int) ( $result['prediction']['away_score'] ?? -1 ), 'away was first (mapped)' );

/* ──── Case 3: ambiguous match → WP_Error ────────────────────────────── */

Mantia_E2E::step( '3. Ambiguous match (no id, no teams) → WP_Error' );

$result = Mantia_E2E::call_ability( 'mantia/register-prediction', array(
	'user_phone'  => $persona['phone'],
	'user_name'   => $persona['name'],
	'home_score'  => 1,
	'away_score'  => 1,
) );
Mantia_E2E::assert_true( is_wp_error( $result ), 'ambiguous match returns WP_Error' );
if ( is_wp_error( $result ) ) {
	Mantia_E2E::assert_eq( 'mantia_match_ambiguous', $result->get_error_code(), 'error code is mantia_match_ambiguous' );
}

/* ──── Case 4: auto-routing — second penca, same competition ─────────── */

Mantia_E2E::step( '4. Auto-routing: same score in N pencas at once' );

// Create a second penca in the same competition for the same user.
Mantia_E2E::send( $persona, 'crear penca __E2E__ ADD Second' );
Mantia_E2E::send( $persona, 'mantia:newcomp:libertadores-semana' );

$result = Mantia_E2E::call_ability( 'mantia/register-prediction', array(
	'user_phone' => $persona['phone'],
	'user_name'  => $persona['name'],
	'match_id'   => $match_id,
	'home_score' => 4,
	'away_score' => 0,
) );
Mantia_E2E::assert_ability_output( 'mantia/register-prediction', $result );
// `groups[]` should now list 2 pencas (the original + the new one).
$groups_written = (array) ( $result['groups'] ?? array() );
Mantia_E2E::assert_eq( 2, count( $groups_written ), 'auto-routing wrote to both pencas' );

/* ──── Cleanup ───────────────────────────────────────────────────────── */

Mantia_E2E::cleanup_persona( $persona );
Mantia_E2E::finish();
