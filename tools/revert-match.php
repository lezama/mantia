<?php
/**
 * Roll back a previous resolve-match call so the leaderboard goes back
 * to "this match hasn't happened yet". Useful when you typed the wrong
 * score on a manual override and need to redo it cleanly, or when FIFA
 * eventually publishes the real score and you want the wp-cron pass to
 * pick it up instead of leaving the manual override frozen.
 *
 * Usage:
 *   ssh mantia3.wordpress.com@ssh.wp.com 'cd htdocs && wp eval-file \
 *     wp-content/plugins/mantia/tools/revert-match.php <match_id>'
 *
 * What it touches:
 *   match meta:        delete _mantia_resolved + scores + status='scheduled'
 *   prediction meta:   delete _mantia_scored + _mantia_points + _mantia_exacts
 *                      on every prediction for this match
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

$argv     = isset( $args ) ? $args : array();
$match_id = isset( $argv[0] ) ? (int) $argv[0] : 0;

if ( $match_id <= 0 ) {
	echo "usage: wp eval-file tools/revert-match.php <match_id>\n";
	exit( 1 );
}

$m = Mantia_Repository::match_to_array( $match_id );
if ( empty( $m ) ) {
	echo "ERROR: match #$match_id not found\n";
	exit( 1 );
}

echo "▶ reverting #$match_id: " . $m['home_team'] . ' vs ' . $m['away_team'] . "\n";

delete_post_meta( $match_id, '_mantia_resolved' );
delete_post_meta( $match_id, '_mantia_home_score' );
delete_post_meta( $match_id, '_mantia_away_score' );
update_post_meta( $match_id, '_mantia_status', 'scheduled' );

$preds = Mantia_Repository::predictions_for_match( $match_id );
foreach ( $preds as $p ) {
	$pid = (int) $p['id'];
	delete_post_meta( $pid, '_mantia_scored' );
	delete_post_meta( $pid, '_mantia_points' );
	delete_post_meta( $pid, '_mantia_exacts' );
}

echo "  ✓ match reset to scheduled\n";
echo "  ✓ " . count( $preds ) . " predictions un-scored\n";
echo "\nLeaderboard rows that touched this match will rebuild on next read.\n";
