<?php
/**
 * Share-card poster routes — the screenshotable "afiche" for groups
 * + users. Lock the URL pattern + ensure the poster contains the rank
 * and the copy-link affordance so screenshots stay information-dense.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Share card posters render with the expected content' );

Mantia_E2E::cleanup();
Mantia_Competitions::seed_defaults();
Mantia_Fixture_Seeder::seed();

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob', 2 );

Mantia_E2E::step( '1. Build a small penca with one resolved match so we have a leader' );
Mantia_E2E::send( $alice, 'me llamo Alice' );
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::send( $alice, Mantia_E2E::TEST_NAME_PREFIX . ' Share' );

// Pull the group + Alice's tokens.
global $wpdb;
$group_id = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
		Mantia_CPTs::GROUP,
		Mantia_E2E::TEST_NAME_PREFIX . ' Share'
	)
);
$group_token = Mantia_Repository::group_view_token( $group_id );

Mantia_E2E::send( $bob, 'me llamo Bob' );
Mantia_E2E::send( $bob, Mantia_Repository::group_to_array( $group_id )['invite_code'] );

// Both predict the same match, then we finish it so Alice (5 pts) leads Bob (0 pts).
$match_id = Mantia_E2E::match_id_by_teams( 'Uruguay', 'Portugal' );
Mantia_E2E::send( $alice, "mantia:match:{$match_id}" );
Mantia_E2E::send( $alice, '2-1' );
Mantia_E2E::send( $bob,   "mantia:match:{$match_id}" );
Mantia_E2E::send( $bob,   '0-0' );

Mantia_E2E::finish_match( $match_id, 2, 1 );
Mantia_Abilities::resolve_match( array( 'match_id' => $match_id ) );

$alice_post  = Mantia_Repository::find_user_by_phone( $alice['phone'] );
$alice_token = Mantia_Repository::user_view_token( (int) $alice_post->ID );

Mantia_E2E::step( '2. /penca/g/<token>/compartir/ shows the leader poster' );
Mantia_E2E::assert_http_ok( '/penca/g/' . $group_token . '/compartir/', array(
	'mantia-share-card',
	'mantia-share-rank',
	'Alice',                  // leader name
	'data-rank="1"',          // medal disc marks the leader (juvenil)
	'Mundial 2026',           // competition subtitle
	'Copiar link',            // primary action
) );

Mantia_E2E::step( '3. /penca/me/<token>/compartir/ shows the user\'s own poster' );
Mantia_E2E::assert_http_ok( '/penca/me/' . $alice_token . '/compartir/', array(
	'mantia-share-card',
	'Alice',
	'data-rank="1"',
	'pts',
	'Copiar link',
) );

Mantia_E2E::step( '4. Invalid tokens 404 with friendly recovery' );
Mantia_E2E::assert_http_status( '/penca/g/0000000000000000/compartir/', 404, array( 'no funciona' ) );
Mantia_E2E::assert_http_status( '/penca/me/0000000000000000/compartir/', 404, array( 'no funciona' ) );

Mantia_E2E::step( '5. Cleanup' );
Mantia_E2E::cleanup();

Mantia_E2E::finish();
