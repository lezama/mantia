<?php
/**
 * One-off prod reset: nuke every Mantia user (mantia_player) + every group +
 * every prediction. Preserves competitions and matches so the next person
 * who says `hola` to the bot starts from a perfectly clean slate.
 *
 * Safe to re-run. Idempotent.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

echo "============================================================\n";
echo "Mantia · full reset of users + groups + predictions\n";
echo "============================================================\n";

$role_slug = WA_Identity_Bridge::role_slug(); // 'mantia_player'

/* ── 1. Predictions ────────────────────────────────────────── */
$pred_ids = get_posts( array(
	'post_type'      => Mantia_CPTs::PREDICTION,
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
) );
foreach ( $pred_ids as $pid ) {
	wp_delete_post( (int) $pid, true );
}
echo sprintf( "  · %d predictions deleted\n", count( $pred_ids ) );

/* ── 2. Groups (all of them, regardless of name) ───────────── */
$group_ids = get_posts( array(
	'post_type'      => Mantia_CPTs::GROUP,
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
) );
foreach ( $group_ids as $gid ) {
	wp_delete_post( (int) $gid, true );
}
echo sprintf( "  · %d groups deleted\n", count( $group_ids ) );

/* ── 3. Mantia users ───────────────────────────────────────── */
$users = get_users( array(
	'role'   => $role_slug,
	'number' => -1,
	'fields' => 'ID',
) );
foreach ( $users as $uid ) {
	wp_delete_user( (int) $uid );
}
echo sprintf( "  · %d %s users deleted\n", count( $users ), $role_slug );

/* ── 4. Bot-session transients keyed by phone ──────────────── */
// We can't enumerate transients trivially, but we can blow away the option
// rows that match the Mantia bot key shapes.
global $wpdb;
$transient_keys = array(
	'mantia_pending_create_',
	'mantia_pending_comp_',
	'mantia_pending_match_',
	'mantia_pending_score_',
	'mantia_rl_',
);
$deleted_t = 0;
foreach ( $transient_keys as $prefix ) {
	$rows = (int) $wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( $prefix ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%'
	) );
	$deleted_t += $rows;
}
echo sprintf( "  · %d bot-session transient rows wiped\n", $deleted_t );

/* ── 5. Summary ───────────────────────────────────────────── */
echo "\nfinal state:\n";
echo "  competitions: " . wp_json_encode( array_keys( Mantia_Competitions::all() ) ) . "\n";
echo "  matches:      " . count( get_posts( array(
	'post_type'      => Mantia_CPTs::MATCH,
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
) ) ) . "\n";
echo "  groups:       " . count( get_posts( array(
	'post_type'      => Mantia_CPTs::GROUP,
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
) ) ) . " (should be 0)\n";
echo "  mantia users: " . count( get_users( array( 'role' => $role_slug, 'number' => -1, 'fields' => 'ID' ) ) ) . " (should be 0)\n";
echo "\ndone.\n";
