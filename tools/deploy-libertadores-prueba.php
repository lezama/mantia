<?php
/**
 * One-off deployment script: nuke everything that isn't Mundial 2026,
 * create a fresh 'libertadores-prueba' competition, and seed the 12
 * Libertadores matchday-6 fixtures (today + tomorrow, Uruguay time).
 *
 * Run via:
 *   ssh mantia3.wordpress.com@ssh.wp.com "cd htdocs && wp eval-file wp-content/plugins/mantia/tools/deploy-libertadores-prueba.php"
 *
 * Idempotent: each match has a stable external_id so re-running updates
 * in place. Competition slug is fixed at 'libertadores-prueba'.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

echo "============================================================\n";
echo "Mantia · deploy libertadores-prueba (today+tomorrow UY)\n";
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
	// Will be either a leftover Libertadores match or the new libertadores-prueba
	// (which we haven't inserted yet, so this is safe). Delete blanket.
	wp_delete_post( (int) $mid, true );
	$nuked_matches++;
}
echo "  · total: $nuked_comps competitions + $nuked_matches matches deleted\n\n";

/* ── 2. Create the libertadores-prueba competition ────────────── */
$existing = Mantia_Competitions::find_post( 'libertadores-prueba' );
if ( $existing ) {
	wp_delete_post( (int) $existing->ID, true );
}
$prueba_id = wp_insert_post( array(
	'post_type'    => Mantia_CPTs::COMPETITION,
	'post_status'  => 'publish',
	'post_title'   => 'Libertadores fecha 6 (prueba)',
	'post_name'    => 'libertadores-prueba',
	'post_excerpt' => 'Fixture de prueba — Libertadores fecha 6, hoy + mañana (UY)',
	'menu_order'   => 5,
), true );
if ( is_wp_error( $prueba_id ) ) {
	echo "ERROR creating libertadores-prueba: " . $prueba_id->get_error_message() . "\n";
	return;
}
update_post_meta( (int) $prueba_id, Mantia_Competitions::META_EMOJI, '🧪' );
update_post_meta( (int) $prueba_id, Mantia_Competitions::META_IS_DEFAULT, 1 );  // set as default
update_post_meta( (int) $prueba_id, Mantia_Competitions::META_SORT_ORDER, 5 );
// Aliases the bot accepts when a user mentions the competition.
update_post_meta( (int) $prueba_id, Mantia_Competitions::META_ALIASES, array(
	'libertadores', 'libertadores prueba', 'libertadores fecha 6', 'fecha 6', 'libertadores-prueba'
) );
echo "  + created competition 'libertadores-prueba' (id=$prueba_id)\n";

// Unset the previous default flag if it was on a different comp.
foreach ( $comps as $c ) {
	if ( $c->post_name !== 'libertadores-prueba' && (int) $c->ID !== (int) $prueba_id ) {
		delete_post_meta( (int) $c->ID, Mantia_Competitions::META_IS_DEFAULT );
	}
}

/* ── 3. Insert the 12 matchday-6 fixtures ────────────────────── */
// Kickoffs are computed dynamically so the seed never ages into the
// past. Real Copa Libertadores fecha 6 shape: 12 matches across 2 days,
// two slots per day (19:00 UY and 21:30 UY = 22:00 UTC / 00:30 UTC).
// We anchor "day 0" to today UY, OR tomorrow UY if it's already past
// 17:00 UY locally (so the first 19:00 UY slot is still in the future).
//
// 2026-05-27 incident: prior version had hardcoded 2026-05-26..28 dates;
// once we crossed 05-27 the Peñarol fixture rendered "hoy 21:30" while
// the real-life match had been played the day before — bot offered to
// take a prediction on a match the user knew was already over.
$now_utc           = time();
// $base_midnight_utc is the UTC timestamp that corresponds to 00:00 UY
// of the day we anchor to. UY is UTC-3, so 00:00 UY = 03:00 UTC.
$today_uy_midnight = strtotime( gmdate( 'Y-m-d', $now_utc - 3 * HOUR_IN_SECONDS ) . ' 03:00:00 UTC' );
// 17:00 UY = 20:00 UTC. If we're past that, push base to tomorrow so
// even the earliest slot (19:00 UY) is still in the future.
$base_midnight_utc = $today_uy_midnight + ( $now_utc >= $today_uy_midnight + 17 * HOUR_IN_SECONDS ? DAY_IN_SECONDS : 0 );

// Slot offsets from base (00:00 UY): just add the local UY hour. We
// don't add 22h/24h — that double-counts the UTC offset already baked
// into $base_midnight_utc.
//   19:00 UY → base + 19h
//   21:30 UY → base + 21h30m
$slot_d0_19 = gmdate( 'Y-m-d H:i:s', $base_midnight_utc + 19 * HOUR_IN_SECONDS );
$slot_d0_21 = gmdate( 'Y-m-d H:i:s', $base_midnight_utc + 21 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS );
$slot_d1_19 = gmdate( 'Y-m-d H:i:s', $base_midnight_utc + DAY_IN_SECONDS + 19 * HOUR_IN_SECONDS );
$slot_d1_21 = gmdate( 'Y-m-d H:i:s', $base_midnight_utc + DAY_IN_SECONDS + 21 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS );

printf( "  · slots: d0/19=%s · d0/21=%s · d1/19=%s · d1/21=%s\n", $slot_d0_19, $slot_d0_21, $slot_d1_19, $slot_d1_21 );

$matches = array(
	array( 'home' => 'LDU Quito',              'away' => 'Always Ready',           'kickoff' => $slot_d0_19 ),
	array( 'home' => 'Lanús',                  'away' => 'Mirassol',               'kickoff' => $slot_d0_19 ),
	array( 'home' => 'Nacional',               'away' => 'Coquimbo Unido',         'kickoff' => $slot_d0_21 ),
	array( 'home' => 'Flamengo',               'away' => 'Cusco FC',               'kickoff' => $slot_d0_21 ),
	array( 'home' => 'Estudiantes',            'away' => 'Independiente Medellín', 'kickoff' => $slot_d0_21 ),
	array( 'home' => 'Universitario',          'away' => 'Deportes Tolima',        'kickoff' => $slot_d0_21 ),
	array( 'home' => 'Independiente del Valle','away' => 'Rosario Central',        'kickoff' => $slot_d1_19 ),
	array( 'home' => 'Libertad',               'away' => 'Universidad Central',    'kickoff' => $slot_d1_19 ),
	array( 'home' => 'Peñarol',                'away' => 'Independiente Santa Fe', 'kickoff' => $slot_d1_21 ),
	array( 'home' => 'Fluminense',             'away' => 'Deportivo La Guaira',    'kickoff' => $slot_d1_21 ),
	array( 'home' => 'Corinthians',            'away' => 'Atlético Platense',      'kickoff' => $slot_d1_21 ),
	array( 'home' => 'Bolívar',                'away' => 'Independiente Rivadavia','kickoff' => $slot_d1_21 ),
);

$inserted = 0;
foreach ( $matches as $i => $m ) {
	$ext = sprintf( 'libertadores-prueba-md6-%02d', $i + 1 );
	$post_id = Mantia_Repository::upsert_match( array(
		'external_id'    => $ext,
		'home_team'      => $m['home'],
		'away_team'      => $m['away'],
		'kickoff_gmt'    => $m['kickoff'],
		'phase'          => 'Fecha 6 — Fase de grupos',
		'status'         => 'scheduled',
		'home_score'     => null,
		'away_score'     => null,
		'competition_id' => 'libertadores-prueba',
	) );
	if ( $post_id > 0 ) {
		$inserted++;
		printf( "  + #%-4d  %-30s vs %-30s  %s UTC\n", $post_id, $m['home'], $m['away'], $m['kickoff'] );
	}
}
echo "  · inserted $inserted / 12 matches\n\n";

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
