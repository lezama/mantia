<?php
/**
 * Public web frontend: every /pronostico/* route renders the right surface
 * with the right CTAs and recovery paths.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Public web routes render correctly' );
Mantia_E2E::require_fixture_or_skip( 'mundial-2026' );

Mantia_E2E::cleanup();
Mantia_Competitions::seed_defaults();

Mantia_E2E::step( '1. Homepage' );
Mantia_E2E::assert_http_ok( '/', array( 'Mantia', 'WhatsApp' ) );

Mantia_E2E::step( '2. Competition pages — every seeded competition' );
foreach ( Mantia_Competitions::all() as $c ) {
	$slug = (string) $c['id'];
	if ( 'custom' === $slug ) {
		continue; // "Otra / Personalizada" has no fixture, valid empty leaderboard.
	}
	Mantia_E2E::assert_http_ok( "/pronostico/{$slug}/", array( $c['name']) );
}

Mantia_E2E::step( '3. Group view — valid token' );
// Create a one-off group + token so we don't depend on existing data.
$gid       = Mantia_Repository::create_group( Mantia_E2E::TEST_NAME_PREFIX . ' WebTest', 'WEBTEST', '', 'mundial-2026' );
$token     = Mantia_Repository::group_view_token( (int) $gid );
Mantia_E2E::assert_http_ok( "/pronostico/g/{$token}/", array( 'WebTest', 'Mundial' ) );

Mantia_E2E::step( '4. Group view — invalid hex token returns 404 with recovery' );
Mantia_E2E::assert_http_status( '/pronostico/g/0000000000000000/', 404, array( 'no funciona', 'no funciona' ) );

Mantia_E2E::step( '5. User view — invalid token' );
Mantia_E2E::assert_http_status( '/pronostico/me/0000000000000000/', 404, array( 'no funciona' ) );

Mantia_E2E::step( '6. Unknown competition slug' );
Mantia_E2E::assert_http_status( '/pronostico/competencia-que-no-existe/', 404 );

Mantia_E2E::step( '7. Cleanup' );
wp_delete_post( (int) $gid, true );
Mantia_E2E::cleanup();

Mantia_E2E::finish();
