<?php
/**
 * Manual fallback to resolve a match when FIFA's data feed is lagging.
 *
 * Usage:
 *   ssh mantia3.wordpress.com@ssh.wp.com 'cd htdocs && wp eval-file \
 *     wp-content/plugins/mantia/tools/resolve-match.php <match_id> <home_score> <away_score>'
 *
 * Example — resolve #9018 (México vs Sudáfrica) as 2-1 when FIFA still
 * shows it as MatchStatus=0:
 *   ssh ... 'cd htdocs && wp eval-file .../tools/resolve-match.php 9018 2 1'
 *
 * Effect:
 *   1. Writes the score + status=finished onto the match meta.
 *   2. Calls score_prediction_post for every prediction on that match,
 *      awarding points + updating the per-penca leaderboard.
 *   3. Flags the match resolved so wp-cron's resolve-matches workflow
 *      skips it next tick.
 *
 * Read-only sanity: when the args list is incomplete the script just
 * lists the candidate matches (recent + past-kickoff but still
 * unresolved) so the admin can pick the right id.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

// `wp eval-file ... a b c` exposes the positional args as $args[0]=a,
// $args[1]=b, $args[2]=c. (No script path at offset 0.)
$argv      = isset( $args ) ? $args : array();
$match_id  = isset( $argv[0] ) ? (int) $argv[0] : 0;
$home_in   = isset( $argv[1] ) ? $argv[1] : null;
$away_in   = isset( $argv[2] ) ? $argv[2] : null;

if ( $match_id <= 0 || null === $home_in || null === $away_in ) {
	echo "usage: wp eval-file tools/resolve-match.php <match_id> <home_score> <away_score>\n\n";
	echo "candidate matches (past kickoff, not yet resolved):\n";
	$now = time();
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.ID,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_mantia_home_team') AS ht,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_mantia_away_team') AS at_,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_mantia_kickoff_gmt') AS kg,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_mantia_status') AS st,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_mantia_home_score') AS hs,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_mantia_away_score') AS as_,
			(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_mantia_resolved') AS rv
		FROM {$wpdb->posts} p
		JOIN {$wpdb->postmeta} ts ON ts.post_id=p.ID AND ts.meta_key='_mantia_kickoff_ts' AND CAST(ts.meta_value AS UNSIGNED) < %d
		WHERE p.post_type='mantia_match'
		  AND COALESCE((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=p.ID AND meta_key='_mantia_resolved'), '0') != '1'
		ORDER BY kg DESC
		LIMIT 20",
		$now
	) );
	foreach ( $rows as $r ) {
		printf(
			"  #%-6d  %-22s vs %-22s  kg=%s  status=%-10s  score=%s-%s\n",
			$r->ID,
			$r->ht,
			$r->at_,
			$r->kg,
			$r->st,
			'' === $r->hs ? '-' : $r->hs,
			'' === $r->as_ ? '-' : $r->as_
		);
	}
	if ( empty( $rows ) ) {
		echo "  (none — every past-kickoff match is already resolved)\n";
	}
	exit;
}

$home_score = (int) $home_in;
$away_score = (int) $away_in;
if ( $home_score < 0 || $away_score < 0 ) {
	echo "scores must be ≥ 0 (got $home_score-$away_score)\n";
	exit( 1 );
}

$match = Mantia_Repository::match_to_array( $match_id );
if ( empty( $match ) ) {
	echo "ERROR: match #$match_id not found\n";
	exit( 1 );
}

echo "▶ resolving #$match_id: " . $match['home_team'] . ' ' . $home_score . '-' . $away_score . ' ' . $match['away_team'] . "\n";

$r = Mantia_Abilities::resolve_match( array(
	'match_id'   => $match_id,
	'home_score' => $home_score,
	'away_score' => $away_score,
) );

if ( is_wp_error( $r ) ) {
	echo "  ✗ " . $r->get_error_code() . " — " . $r->get_error_message() . "\n";
	exit( 1 );
}

$n = count( $r['scored'] ?? array() );
echo "  ✓ scored $n predictions\n";

// Quick recap of points awarded (top 6 to keep noise low).
if ( $n > 0 ) {
	$top = array_slice( $r['scored'], 0, 6 );
	echo "  · sample:\n";
	foreach ( $top as $s ) {
		$pid = (int) ( $s['id'] ?? 0 );
		$h   = (int) ( $s['home_score'] ?? 0 );
		$a   = (int) ( $s['away_score'] ?? 0 );
		$pts = (int) ( $s['points'] ?? 0 );
		echo "    pred #$pid  picked $h-$a  → $pts pts\n";
	}
	if ( $n > 6 ) {
		echo "    ... +" . ( $n - 6 ) . " more\n";
	}
}
