<?php
/**
 * Matrix UX-test setup. Builds a multi-user / multi-penca / mixed-
 * prediction-state fixture so the UX detective can audit the whole
 * URL × identity surface instead of just one happy-path slice.
 *
 * Fixture shape:
 *   Alice   creator of two pencas (libertadores-prueba, mundial-2026)
 *   Bob     joiner of Alice's libertadores penca
 *   Carol   joiner of Alice's libertadores penca AND mundial penca
 *
 * Predictions:
 *   Alice predicts 1-1 on the FIRST libertadores match (manual)
 *   Alice predicts 2-0 on the SECOND libertadores match (manual)
 *   Bob predicts 0-3 on the FIRST libertadores match (manual)
 *   Carol stays on auto-fill 0-0 for every match (pending across both pencas)
 *
 * Output stable KV — the bash wrapper parses it into one .txt file
 * per var so the promptfoo file:// loader can pick them up.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WA_Identity_Bridge' ) ) {
	echo "ERROR: WA_Identity_Bridge not loaded — abort.\n";
	exit( 1 );
}

/** ── helpers ─────────────────────────────────────────────────────── */
function ux_create_user( string $phone, string $name ): WP_User {
	$user = WA_Identity_Bridge::resolve_or_create( $phone, $name );
	if ( ! ( $user instanceof WP_User ) ) {
		echo "ERROR: could not create $name — " . ( is_wp_error( $user ) ? $user->get_error_message() : 'unknown' ) . "\n";
		exit( 1 );
	}
	$digits = preg_replace( '/\D+/', '', $phone );
	update_user_meta( (int) $user->ID, Mantia_Repository::META_PHONE, $digits );
	return $user;
}

function ux_create_penca( string $name, string $comp ): array {
	$id = Mantia_Repository::create_group( $name, '', '', $comp );
	if ( $id <= 0 ) {
		echo "ERROR: could not create '$name' — gid=$id\n";
		exit( 1 );
	}
	return Mantia_Repository::group_to_array( (int) $id );
}

function ux_join( WP_User $user, array $group, string $name_for_join = '' ): void {
	$digits = preg_replace( '/\D+/', '', (string) get_user_meta( (int) $user->ID, Mantia_Repository::META_PHONE, true ) );
	$display = '' !== $name_for_join ? $name_for_join : (string) $user->display_name;
	$r = Mantia_Repository::join_group( $digits, (string) $group['invite_code'], $display, $digits );
	if ( is_wp_error( $r ) ) {
		echo "ERROR: join failed for {$user->display_name} → {$group['name']}: " . $r->get_error_message() . "\n";
		exit( 1 );
	}
}

function ux_predict( WP_User $user, int $group_id, int $match_id, int $h, int $a ): void {
	$r = Mantia_Repository::register_prediction( (int) $user->ID, $match_id, $group_id, $h, $a, false );
	if ( is_wp_error( $r ) ) {
		echo "ERROR: predict failed for user={$user->ID} match=$match_id: " . $r->get_error_message() . "\n";
		exit( 1 );
	}
}

/** ── users ───────────────────────────────────────────────────────── */
$alice = ux_create_user( '+59899900042', 'Alice' );
$bob   = ux_create_user( '+59899900043', 'Bob' );
$carol = ux_create_user( '+59899900044', 'Carol' );

/** ── pencas (Alice creates both) ─────────────────────────────────── */
$libe = ux_create_penca( 'P_LIBE_MTX', 'libertadores-prueba' );
$mun  = ux_create_penca( 'P_MUN_MTX',  'mundial-2026' );
ux_join( $alice, $libe );
ux_join( $alice, $mun );

/** Bob joins libertadores only. Carol joins both. ──────────────── */
ux_join( $bob,   $libe );
ux_join( $carol, $libe );
ux_join( $carol, $mun );

/** ── predictions: mixed manual + auto ───────────────────────────── */
$libe_matches = Mantia_Repository::upcoming_matches_for_competition( 'libertadores-prueba', 24 * 365 );
if ( count( $libe_matches ) < 2 ) {
	echo "ERROR: libertadores-prueba needs ≥2 upcoming matches\n";
	exit( 1 );
}
$canary_a = $libe_matches[0];
$canary_b = $libe_matches[1];

ux_predict( $alice, (int) $libe['id'], (int) $canary_a['id'], 1, 1 );
ux_predict( $alice, (int) $libe['id'], (int) $canary_b['id'], 2, 0 );
ux_predict( $bob,   (int) $libe['id'], (int) $canary_a['id'], 0, 3 );
// Carol stays on auto-fill 0-0 — pending state for both her pencas.

/** ── emit ────────────────────────────────────────────────────────── */
$ut = static fn ( WP_User $u ): string => Mantia_Repository::user_share_token( (int) $u->ID );
$vt = static fn ( array $g ): string  => (string) get_post_meta( (int) $g['id'], Mantia_Repository::META_GROUP_VIEW_TOKEN, true );

echo "ALICE_ID: " . (int) $alice->ID . "\n";
echo "ALICE_SHARE: " . $ut( $alice ) . "\n";
echo "BOB_SHARE: "   . $ut( $bob )   . "\n";
echo "CAROL_SHARE: " . $ut( $carol ) . "\n";
echo "LIBE_VIEW: "   . $vt( $libe )  . "\n";
echo "LIBE_NAME: "   . $libe['name'] . "\n";
echo "LIBE_CODE: "   . $libe['invite_code'] . "\n";
echo "MUN_VIEW: "    . $vt( $mun )   . "\n";
echo "MUN_NAME: "    . $mun['name']  . "\n";
echo "MUN_CODE: "    . $mun['invite_code'] . "\n";
echo "CANARY_A_HOME: " . $canary_a['home_team'] . "\n";
echo "CANARY_A_AWAY: " . $canary_a['away_team'] . "\n";
echo "CANARY_B_HOME: " . $canary_b['home_team'] . "\n";
echo "CANARY_B_AWAY: " . $canary_b['away_team'] . "\n";
