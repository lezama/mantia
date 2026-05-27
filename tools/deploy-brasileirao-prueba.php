<?php
/**
 * One-off deployment script: nuke everything that isn't Mundial 2026,
 * create a fresh test competition (slug stays 'brasileirao-prueba' for
 * backwards compatibility with promptfoo configs + setup-matrix), and
 * seed it with REAL Brasileirão Serie A weekend fixtures.
 *
 * Source of truth: ESPN.com Brazil Serie A schedule, fetched 2026-05-27.
 * Weekend 30-31 may 2026 — the last round before the Mundial 2026 break.
 *
 * Run via:
 *   ssh mantia3.wordpress.com@ssh.wp.com "cd htdocs && wp eval-file wp-content/plugins/mantia/tools/deploy-brasileirao-prueba.php"
 *
 * Idempotent: each match has a stable external_id so re-running updates
 * in place. Competition slug is fixed at 'brasileirao-prueba'.
 *
 * When the weekend passes, refresh by re-querying ESPN/Soccerway and
 * replacing the $matches array below. The freshness guard in
 * promptfooconfig.matrix.yaml will fail loudly once any kickoff drops
 * into the past.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

echo "============================================================\n";
echo "Mantia · deploy fixture (Brasileirão · finde 30-31 may 2026)\n";
echo "============================================================\n";

/* ── 1. Wipe every competition that isn't Mundial ──────────────── */
$preserve = array( 'mundial-2026', 'mundial-semana' );
$comps    = get_posts( array(
	'post_type'      => Mantia_CPTs::COMPETITION,
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'all',
) );
$nuked_comps  = 0;
$nuked_matches = 0;
foreach ( $comps as $c ) {
	if ( in_array( $c->post_name, $preserve, true ) ) {
		continue;
	}
	// Delete matches under this competition.
	$match_ids = get_posts( array(
		'post_type'      => Mantia_CPTs::MATCH,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => Mantia_Competitions::META_KEY, 'value' => $c->post_name ),
		),
	) );
	foreach ( $match_ids as $mid ) {
		wp_delete_post( (int) $mid, true );
		$nuked_matches++;
	}
	wp_delete_post( (int) $c->ID, true );
	$nuked_comps++;
	echo "  - nuked competition '{$c->post_name}' (+" . count( $match_ids ) . " matches)\n";
}

/* Also: orphan matches whose competition_id is anything other than the
 * mundial-2026 family. Safety net for matches that lost their parent. */
$orphans = get_posts( array(
	'post_type'      => Mantia_CPTs::MATCH,
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
) );
foreach ( $orphans as $mid ) {
	$cid = (string) get_post_meta( (int) $mid, Mantia_Competitions::META_KEY, true );
	if ( 'mundial-2026' === $cid || 'mundial-semana' === $cid ) {
		continue;
	}
	// Will be either a leftover Libertadores match or the new brasileirao-prueba
	// (which we haven't inserted yet, so this is safe). Delete blanket.
	wp_delete_post( (int) $mid, true );
	$nuked_matches++;
}
echo "  · total: $nuked_comps competitions + $nuked_matches matches deleted\n\n";

/* ── 2. Create the brasileirao-prueba competition ────────────── */
$existing = Mantia_Competitions::find_post( 'brasileirao-prueba' );
if ( $existing ) {
	wp_delete_post( (int) $existing->ID, true );
}
$prueba_id = wp_insert_post( array(
	'post_type'    => Mantia_CPTs::COMPETITION,
	'post_status'  => 'publish',
	'post_title'   => 'Brasileirão · finde (prueba)',
	'post_name'    => 'brasileirao-prueba',
	'post_excerpt' => 'Fixture de prueba — Brasileirão Serie A, 30-31 may 2026 (real fixtures via ESPN)',
	'menu_order'   => 5,
), true );
if ( is_wp_error( $prueba_id ) ) {
	echo "ERROR creating fixture comp: " . $prueba_id->get_error_message() . "\n";
	return;
}
update_post_meta( (int) $prueba_id, Mantia_Competitions::META_EMOJI, '🧪' );
update_post_meta( (int) $prueba_id, Mantia_Competitions::META_IS_DEFAULT, 1 );  // set as default
update_post_meta( (int) $prueba_id, Mantia_Competitions::META_SORT_ORDER, 5 );
// Aliases the bot accepts when a user mentions the competition. The
// slug stays 'brasileirao-prueba' for back-compat with promptfoo +
// setup-matrix, but every alias the user is likely to type points to
// the same comp.
update_post_meta( (int) $prueba_id, Mantia_Competitions::META_ALIASES, array(
	'brasileirao', 'brasileirão', 'brasileiro', 'serie a',
	'finde', 'fin de semana', 'prueba',
) );
echo "  + created competition slug='brasileirao-prueba' title='Brasileirão · finde (prueba)' (id=$prueba_id)\n";

// Unset the previous default flag if it was on a different comp.
foreach ( $comps as $c ) {
	if ( $c->post_name !== 'brasileirao-prueba' && (int) $c->ID !== (int) $prueba_id ) {
		delete_post_meta( (int) $c->ID, Mantia_Competitions::META_IS_DEFAULT );
	}
}

/* ── 3. Insert the Brasileirão weekend fixtures ──────────────────
 * Real fixtures, weekend 30-31 may 2026, scraped from ESPN's Brazil
 * Serie A schedule on 2026-05-27. Times are local Brazilian (BRT,
 * UTC-3 year-round since 2019). Stored as UTC.
 *
 * 6 matches: 3 Saturday + 3 Sunday, spread across morning / afternoon
 * / night slots so the picker has variety. Picked the higher-profile
 * matchups from the round of 10 (Flamengo, Palmeiras, Corinthians,
 * Cruzeiro, Internacional, Santos).
 *
 * BRT → UTC conversion:
 *   15:00 BRT = 18:00 UTC same day
 *   16:30 BRT = 19:30 UTC same day
 *   19:00 BRT = 22:00 UTC same day
 *   10:00 BRT = 13:00 UTC same day
 *   19:30 BRT = 22:30 UTC same day
 */
$matches = array(
	array( 'home' => 'Flamengo',           'away' => 'Coritiba',       'kickoff' => '2026-05-30 18:00:00' ), // sáb 15:00 BRT
	array( 'home' => 'Grêmio',             'away' => 'Corinthians',    'kickoff' => '2026-05-30 19:30:00' ), // sáb 16:30 BRT
	array( 'home' => 'Santos',             'away' => 'Vitória',        'kickoff' => '2026-05-30 22:00:00' ), // sáb 19:00 BRT
	array( 'home' => 'Bragantino',         'away' => 'Internacional',  'kickoff' => '2026-05-31 13:00:00' ), // dom 10:00 BRT
	array( 'home' => 'Palmeiras',          'away' => 'Chapecoense',    'kickoff' => '2026-05-31 18:00:00' ), // dom 15:00 BRT
	array( 'home' => 'Cruzeiro',           'away' => 'Fluminense',     'kickoff' => '2026-05-31 22:30:00' ), // dom 19:30 BRT
);

$inserted = 0;
$total    = count( $matches );
foreach ( $matches as $i => $m ) {
	$ext = sprintf( 'brasileirao-finde-%02d', $i + 1 );
	$post_id = Mantia_Repository::upsert_match( array(
		'external_id'    => $ext,
		'home_team'      => $m['home'],
		'away_team'      => $m['away'],
		'kickoff_gmt'    => $m['kickoff'],
		'phase'          => 'Brasileirão · Serie A',
		'status'         => 'scheduled',
		'home_score'     => null,
		'away_score'     => null,
		'competition_id' => 'brasileirao-prueba',
	) );
	if ( $post_id > 0 ) {
		$inserted++;
		printf( "  + #%-4d  %-22s vs %-22s  %s UTC\n", $post_id, $m['home'], $m['away'], $m['kickoff'] );
	}
}
echo "  · inserted $inserted / $total matches\n\n";

/* ── 4. Flush rewrites + verify ──────────────────────────────── */
flush_rewrite_rules();
echo "  · rewrites flushed\n\n";

/* ── 5. Summary ─────────────────────────────────────────────── */
$final_comps = array_keys( Mantia_Competitions::all() );
echo "competitions now:    " . wp_json_encode( $final_comps ) . "\n";

$default_comp = Mantia_Competitions::default_id();
echo "default competition: $default_comp\n";

$counts = array();
foreach ( $final_comps as $slug ) {
	$ids = get_posts( array(
		'post_type'      => Mantia_CPTs::MATCH,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => Mantia_Competitions::META_KEY, 'value' => $slug ) ),
	) );
	$counts[ $slug ] = count( $ids );
}
echo "match counts:        " . wp_json_encode( $counts ) . "\n";
echo "\ndone.\n";
