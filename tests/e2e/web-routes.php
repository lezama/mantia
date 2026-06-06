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
update_option( 'mantia_bot_phone_e164', '59899123456' );

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

Mantia_E2E::step( '7. Web-first create and invite routes' );
Mantia_E2E::assert_http_ok( '/pronostico/crear/mundial-2026/', array( 'Crear penca', 'Entrar por WhatsApp' ) );
Mantia_E2E::assert_http_ok( '/pronostico/sumate/WEBTEST/', array( 'mantia-join-card', 'Entrar y sumarme por WhatsApp' ) );

Mantia_E2E::step( '8. Authenticated REST create + join' );
$alice_id = Mantia_Repository::get_or_create_user( Mantia_E2E::TEST_PHONE_PREFIX . '81', 'Alice Web' );
wp_set_current_user( $alice_id );
$create_request = new WP_REST_Request( 'POST', '/' . Mantia_Rest::NAMESPACE_V1 . '/groups' );
$create_request->set_param( 'group_name', Mantia_E2E::TEST_NAME_PREFIX . ' Web Created' );
$create_request->set_param( 'competition_id', 'mundial-2026' );
$create_response = Mantia_Rest::handle_create_group( $create_request );
$create_data     = $create_response->get_data();
Mantia_E2E::assert_true( 200 === $create_response->get_status() && ! empty( $create_data['ok'] ), 'REST create-group succeeds for WhatsApp-authenticated user' );
Mantia_E2E::assert_true( false !== strpos( (string) ( $create_data['group']['join_url'] ?? '' ), '/pronostico/sumate/' ), 'REST create-group returns web join URL' );
Mantia_E2E::assert_true( false !== strpos( (string) ( $create_data['group']['whatsapp_share_url'] ?? '' ), 'wa.me/?text=' ), 'REST create-group returns WhatsApp share URL' );
$matches = Mantia_Repository::upcoming_matches_for_competition( 'mundial-2026', 24 * 365 );
if ( ! empty( $matches ) ) {
	$prediction_request = new WP_REST_Request( 'POST', '/' . Mantia_Rest::NAMESPACE_V1 . '/predictions' );
	$prediction_request->set_param( 'match_id', (int) $matches[0]['id'] );
	$prediction_request->set_param( 'home_score', 1 );
	$prediction_request->set_param( 'away_score', 0 );
	$prediction_response = Mantia_Rest::handle_save_prediction( $prediction_request );
	$prediction_data     = $prediction_response->get_data();
	Mantia_E2E::assert_true( 200 === $prediction_response->get_status() && ! empty( $prediction_data['ok'] ), 'REST prediction save succeeds from WhatsApp-authenticated cookie without legacy token' );
}

$bob_id = Mantia_Repository::get_or_create_user( Mantia_E2E::TEST_PHONE_PREFIX . '82', 'Bob Web' );
wp_set_current_user( $bob_id );
$join_request = new WP_REST_Request( 'POST', '/' . Mantia_Rest::NAMESPACE_V1 . '/join' );
$join_request->set_param( 'invite_code', 'WEBTEST' );
$join_response = Mantia_Rest::handle_join_group( $join_request );
$join_data     = $join_response->get_data();
Mantia_E2E::assert_true( 200 === $join_response->get_status() && ! empty( $join_data['ok'] ), 'REST join-group succeeds for WhatsApp-authenticated user' );
Mantia_E2E::assert_true( (int) $join_data['group']['id'] === (int) $gid, 'REST join-group returns the joined group' );

Mantia_E2E::step( '9. Cleanup' );
wp_delete_post( (int) $gid, true );
Mantia_E2E::cleanup();

Mantia_E2E::finish();
