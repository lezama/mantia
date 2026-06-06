<?php
/**
 * Scoped reset for the UX-test fixture (Alice/Bob/Carol + their pencas).
 *
 * Wipes ONLY the fixture identities — leaves Miguel's real testing data,
 * Diego's joins, any other live users, and any user-created group
 * intact. Run before `setup-matrix.php` or `setup-canonical-user.php`
 * so a re-seed lands on a clean slate without nuking live data.
 *
 * In-scope (gets deleted, then re-seeded by the setup script):
 *   - mantia_player users whose phone equals one of the fixture phones:
 *       59899900042 (Alice), 59899900043 (Bob), 59899900044 (Carol)
 *     plus anything with phone starting `9999000` (legacy e2e personas).
 *   - groups whose post_title is exactly:
 *       P_MUN_MTX, P_UX_TEST (the only fixture penca names we seed
 *       since the brasileirao-prueba comp was retired 2026-05-29 in
 *       favor of mundial-2026-only testing). P_LIBE_MTX is kept in
 *       the deletion list as legacy cleanup for anyone re-running on
 *       an old install state.
 *     plus anything starting with `__E2E__` (legacy e2e groups).
 *   - all predictions owned by those users.
 *
 * Out-of-scope (preserved no matter what):
 *   - any other mantia_player user (real testers + invited friends).
 *   - any other group (real pencas like Capritest, etc.).
 *   - competitions + matches (the deploy seed re-runs idempotently anyway).
 *   - bot-session transients for non-fixture phones.
 *
 * The full-nuke variant (tools/reset-users-and-groups.php) still exists
 * for "delete everything Mantia-owned on this install" — that one is
 * now reserved for explicit `bin/promptfoo-ux.sh nuke` invocations.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

echo "============================================================\n";
echo "Mantia · scoped reset of UX fixture (Alice/Bob/Carol only)\n";
echo "============================================================\n";

global $wpdb;

$fixture_phones = array( '59899900042', '59899900043', '59899900044' );
$fixture_groups = array( 'P_LIBE_MTX', 'P_MUN_MTX', 'P_UX_TEST' );

/* ── 1. Resolve target users ────────────────────────────────────── */
$user_ids = array();
foreach ( $fixture_phones as $ph ) {
	$us = get_users( array(
		'meta_key'   => Mantia_Repository::META_PHONE,
		'meta_value' => $ph,
		'fields'     => 'ID',
		'number'     => -1,
	) );
	foreach ( $us as $uid ) {
		$user_ids[ (int) $uid ] = true;
	}
}
// Legacy e2e personas: phone prefix 9999000
$legacy_us = get_users( array(
	'meta_key'     => Mantia_Repository::META_PHONE,
	'meta_value'   => '9999000',
	'meta_compare' => 'LIKE',
	'fields'       => 'ID',
	'number'       => -1,
) );
foreach ( $legacy_us as $uid ) {
	$user_ids[ (int) $uid ] = true;
}
$user_ids = array_keys( $user_ids );
echo sprintf( "  · %d fixture user(s) targeted\n", count( $user_ids ) );

/* ── 2. Predictions by those users ──────────────────────────────── */
$deleted_preds = 0;
foreach ( $user_ids as $uid ) {
	$preds = get_posts( array(
		'post_type'      => Mantia_CPTs::PREDICTION,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array( 'key' => Mantia_Repository::META_USER_ID, 'value' => $uid ),
		),
	) );
	foreach ( $preds as $pid ) {
		wp_delete_post( (int) $pid, true );
		++$deleted_preds;
	}
}
echo sprintf( "  · %d prediction(s) deleted\n", $deleted_preds );

/* ── 3. Fixture groups by exact title + legacy prefix ───────────── */
$group_ids = array();
foreach ( $fixture_groups as $title ) {
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s",
		Mantia_CPTs::GROUP,
		$title
	) );
	foreach ( $ids as $gid ) {
		$group_ids[ (int) $gid ] = true;
	}
}
$legacy_groups = $wpdb->get_col( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s",
	Mantia_CPTs::GROUP,
	'__E2E__%'
) );
foreach ( $legacy_groups as $gid ) {
	$group_ids[ (int) $gid ] = true;
}
$group_ids = array_keys( $group_ids );
foreach ( $group_ids as $gid ) {
	wp_delete_post( $gid, true );
}
echo sprintf( "  · %d fixture group(s) deleted\n", count( $group_ids ) );

/* ── 4. Users themselves ────────────────────────────────────────── */
foreach ( $user_ids as $uid ) {
	wp_delete_user( $uid );
}
echo sprintf( "  · %d fixture user(s) deleted\n", count( $user_ids ) );

/* ── 5. Bot-session transients keyed by the fixture phones ───────── */
$transient_keys = array(
	'mantia_pending_create_',
	'mantia_pending_comp_',
	'mantia_pending_match_',
	'mantia_pending_score_',
	'mantia_rl_',
);
$deleted_t = 0;
foreach ( $fixture_phones as $ph ) {
	foreach ( $transient_keys as $prefix ) {
		$hash = md5( $ph );
		$deleted_t += (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_' . $wpdb->esc_like( $prefix . $hash ) . '%',
			'_transient_timeout_' . $wpdb->esc_like( $prefix . $hash ) . '%'
		) );
	}
}
echo sprintf( "  · %d bot-session transient row(s) wiped\n", $deleted_t );

echo "\nfinal state:\n";
$total_users = (int) count_users()['avail_roles']['mantia_player'] ?? 0;
$total_groups = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
	Mantia_CPTs::GROUP, 'publish'
) );
echo sprintf( "  · %d mantia_player user(s) remain on the install\n", $total_users );
echo sprintf( "  · %d published mantia_group(s) remain on the install\n", $total_groups );
echo "  · scoped reset done — real-user data preserved.\n";
