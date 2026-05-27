<?php
/**
 * Canonical UX-test setup. Creates a known-state user + penca + one
 * manual prediction, then prints the URLs the ux-detective agent
 * should walk. Run on a clean install (the agent calls reset first).
 *
 * Output is parsed by tests/ux/run-canonical.sh; keep the
 *   KEY: value
 * format stable so the bash wrapper can grep individual fields.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WA_Identity_Bridge' ) ) {
	echo "ERROR: WA_Identity_Bridge not loaded — abort.\n";
	exit( 1 );
}

// 1. Create Alice through the bridge (same path the bot uses).
$alice_phone = '+59899900042';
$user        = WA_Identity_Bridge::resolve_or_create( $alice_phone, 'Alice' );
if ( ! ( $user instanceof WP_User ) ) {
	echo "ERROR: could not create Alice — " . ( is_wp_error( $user ) ? $user->get_error_message() : 'unknown' ) . "\n";
	exit( 1 );
}
update_user_meta( (int) $user->ID, Mantia_Repository::META_PHONE, '59899900042' );

// 2. Create a brasileirao-prueba penca and join Alice.
$group_id = Mantia_Repository::create_group( 'P_UX_TEST', '', '', 'brasileirao-prueba' );
if ( $group_id <= 0 ) {
	echo "ERROR: could not create penca — group_id=$group_id\n";
	exit( 1 );
}
$group = Mantia_Repository::group_to_array( (int) $group_id );
$join  = Mantia_Repository::join_group( '59899900042', (string) $group['invite_code'], 'Alice', '59899900042' );
if ( is_wp_error( $join ) ) {
	echo "ERROR: join failed — " . $join->get_error_message() . "\n";
	exit( 1 );
}

// 3. Pick the first upcoming match in brasileirao-prueba and have
// Alice predict 1-1. This is the "user manually decided" state — the
// inline badge on web views should show "✓ 1-1" for this match only.
$matches = Mantia_Repository::upcoming_matches_for_competition( 'brasileirao-prueba', 24 * 365 );
if ( empty( $matches ) ) {
	echo "ERROR: no upcoming matches in brasileirao-prueba\n";
	exit( 1 );
}
$canary_match = $matches[0];
$pred         = Mantia_Repository::register_prediction(
	(int) $user->ID,
	(int) $canary_match['id'],
	(int) $group_id,
	1,
	1,
	false // manual prediction — must clear auto_filled so it surfaces as user-decided
);
if ( is_wp_error( $pred ) ) {
	echo "ERROR: register_prediction failed — " . $pred->get_error_message() . "\n";
	exit( 1 );
}

// 4. Emit the artifacts the bash wrapper greps. Stable key:value.
$share_token  = Mantia_Repository::user_share_token( (int) $user->ID );
$view_token   = (string) get_post_meta( (int) $group_id, Mantia_Repository::META_GROUP_VIEW_TOKEN, true );
$group_url    = Mantia_Repository::group_view_url( (int) $group_id, (int) $user->ID );
$comp_url_as  = Mantia_Repository::competition_view_url( 'brasileirao-prueba' ) . '?as=' . rawurlencode( $share_token );
$comp_url_an  = Mantia_Repository::competition_view_url( 'brasileirao-prueba' );
$group_url_an = home_url( '/pronostico/g/' . $view_token . '/' );

echo "USER_ID: " . (int) $user->ID . "\n";
echo "SHARE_TOKEN: $share_token\n";
echo "VIEW_TOKEN: $view_token\n";
echo "GROUP_NAME: " . $group['name'] . "\n";
echo "INVITE_CODE: " . $group['invite_code'] . "\n";
echo "CANARY_HOME: " . $canary_match['home_team'] . "\n";
echo "CANARY_AWAY: " . $canary_match['away_team'] . "\n";
echo "URL_GROUP_AS: $group_url\n";
echo "URL_GROUP_ANON: $group_url_an\n";
echo "URL_COMP_AS: $comp_url_as\n";
echo "URL_COMP_ANON: $comp_url_an\n";
