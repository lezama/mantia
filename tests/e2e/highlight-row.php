<?php
/**
 * Group-page row highlight: when /penca/g/<group>/?as=<share> resolves
 * a known user, that user's row in the leaderboard gets the "you" class.
 * Share token (not view token) is what the URL accepts — guarantees the
 * link is leakable without granting edit access.
 *
 * @package Mantia\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Group page highlights "you" with ?as=<share_token>' );

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob',   2 );
$carla = Mantia_E2E::persona( 'Carla', 3 );

// Alice creates a penca, Bob + Carla join.
Mantia_E2E::send( $alice, 'me llamo Alice' );
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::send( $alice, '__E2E__ Highlight' );

$alice_id   = (int) Mantia_Repository::find_user_by_phone( $alice['phone'] )->ID;
$group_ids  = (array) get_post_meta( $alice_id, Mantia_Repository::META_GROUP_IDS, true );
$group_id   = (int) $group_ids[0];
$invite     = (string) get_post_meta( $group_id, Mantia_Repository::META_INVITE_CODE, true );

Mantia_E2E::send( $bob,   'me llamo Bob' );
Mantia_E2E::send( $bob,   $invite );
Mantia_E2E::send( $carla, 'me llamo Carla' );
Mantia_E2E::send( $carla, $invite );

$bob_id   = (int) Mantia_Repository::find_user_by_phone( $bob['phone'] )->ID;
$carla_id = (int) Mantia_Repository::find_user_by_phone( $carla['phone'] )->ID;

// Each predicts the same match (different scores → leaderboard has 3 rows).
$upcoming  = Mantia_Repository::upcoming_matches_for_competition( 'mundial-2026', 24 * 365 );
$match_id  = (int) $upcoming[0]['id'];
Mantia_E2E::send( $alice, 'mantia:match:' . $match_id );
Mantia_E2E::send( $alice, '2-1' );
Mantia_E2E::send( $bob,   'mantia:match:' . $match_id );
Mantia_E2E::send( $bob,   '1-1' );
Mantia_E2E::send( $carla, 'mantia:match:' . $match_id );
Mantia_E2E::send( $carla, '0-0' );

// Resolve so all three appear in the leaderboard with points.
Mantia_E2E::finish_match( $match_id, 2, 1 );
Mantia_Abilities::resolve_match( array( 'match_id' => $match_id ) );

$group_token = Mantia_Repository::group_view_token( $group_id );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '1. Without ?as= — no row carries the "you" class' );
/* ------------------------------------------------------------------------ */
Mantia_E2E::assert_http_ok( '/penca/g/' . $group_token . '/', array( 'mantia-board-row' ) );
$resp = wp_remote_get( home_url( '/penca/g/' . $group_token . '/' ) );
$body = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
Mantia_E2E::assert_eq( true, false === strpos( $body, 'mantia-board-row mantia-board-row-me' ), 'no row-me class without ?as=' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '2. With Bob\'s share token, his row gets row-me' );
/* ------------------------------------------------------------------------ */
$bob_share = Mantia_Repository::user_share_token( $bob_id );
Mantia_E2E::assert_http_ok(
	'/penca/g/' . $group_token . '/?as=' . $bob_share,
	array( 'mantia-board-row mantia-board-row-me', 'Bob' )
);

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '3. With Bob\'s VIEW token, no highlight (privacy guard)' );
/* ------------------------------------------------------------------------ */
// View tokens must not be accepted via ?as= — only share tokens are.
$bob_view = Mantia_Repository::user_view_token( $bob_id );
$resp = wp_remote_get( home_url( '/penca/g/' . $group_token . '/?as=' . $bob_view ) );
$body = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
Mantia_E2E::assert_eq( true, false === strpos( $body, 'mantia-board-row mantia-board-row-me' ), 'view token does NOT trigger highlight' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '4. Cleanup' );
/* ------------------------------------------------------------------------ */
Mantia_E2E::cleanup();
Mantia_E2E::finish();
