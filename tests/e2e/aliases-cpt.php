<?php
/**
 * Competitions are CPTs + aliases live in post_meta — no hardcoded slugs.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Aliases live on the CPT, not in code' );

Mantia_E2E::step( '0. Reset to seeded defaults' );
Mantia_E2E::cleanup();
Mantia_Competitions::seed_defaults();

Mantia_E2E::step( '1. Built-in aliases populated from seed' );
$mundial = Mantia_Competitions::get( 'mundial-2026' );
Mantia_E2E::assert_eq( true, in_array( 'mundial', $mundial['aliases'], true ), 'mundial-2026 has "mundial" alias' );
Mantia_E2E::assert_eq( true, in_array( 'fifa', $mundial['aliases'], true ), 'mundial-2026 has "fifa" alias' );

$liga = Mantia_Competitions::get( 'liga-uy-2026' );
Mantia_E2E::assert_eq( true, in_array( 'liga uruguaya', $liga['aliases'], true ), 'liga-uy-2026 has "liga uruguaya"' );
Mantia_E2E::assert_eq( true, in_array( 'auf', $liga['aliases'], true ), 'liga-uy-2026 has "auf"' );

Mantia_E2E::step( '2. Resolver routes friendly hints to canonical slugs' );
$r = new ReflectionClass( 'Mantia_Whatsapp_Flow' );
$m = $r->getMethod( 'resolve_competition_hint' );
$m->setAccessible( true );

$cases = array(
	'mundial'                  => 'mundial-2026',
	'world cup'                => 'mundial-2026',
	'Mundial 2026'             => 'mundial-2026',
	'de Mundial 2026'          => 'mundial-2026',
	'libertadores'             => 'libertadores-2026',
	'libertadores semana'      => 'libertadores-semana',
	'de la LigaUY 2026'        => 'liga-uy-2026',
	'liga uruguaya'            => 'liga-uy-2026',
	'auf'                      => 'liga-uy-2026',
	'sudamericana'             => 'sudamericana-2026',
);
foreach ( $cases as $hint => $expected ) {
	$got = $m->invoke( null, $hint );
	Mantia_E2E::assert_eq( $expected, $got, "\"{$hint}\" → {$expected}" );
}

Mantia_E2E::step( '3. Admin-style alias edit persists and feeds the resolver' );
$ad_hoc_slug = 'e2e-test-competition';

// Create a fresh competition the way the admin UI would.
$cid = wp_insert_post( array(
	'post_type'    => Mantia_CPTs::COMPETITION,
	'post_status'  => 'publish',
	'post_title'   => 'E2E Test Competition',
	'post_name'    => $ad_hoc_slug,
	'post_excerpt' => 'Created by aliases-cpt.php',
) );
update_post_meta( (int) $cid, Mantia_Competitions::META_EMOJI, '🧪' );

// Use the public alias-save path — same one the meta box uses.
Mantia_Competitions::save_aliases( (int) $cid, array( 'TestComp', '  prueba  ', 'beta-2027' ) );

$comp = Mantia_Competitions::get( $ad_hoc_slug );
Mantia_E2E::assert_eq( true, null !== $comp, 'admin-created competition is queryable' );
Mantia_E2E::assert_eq( array( 'testcomp', 'prueba', 'beta-2027' ), $comp['aliases'], 'aliases normalized (lowercase + trim + dedup)' );

$slug = $m->invoke( null, 'prueba' );
Mantia_E2E::assert_eq( $ad_hoc_slug, $slug, 'new alias routes correctly via resolver' );
$slug = $m->invoke( null, 'TESTCOMP' );
Mantia_E2E::assert_eq( $ad_hoc_slug, $slug, 'case-insensitive alias matches' );

Mantia_E2E::step( '4. Cleanup' );
wp_delete_post( (int) $cid, true );
Mantia_E2E::cleanup();

Mantia_E2E::finish();
