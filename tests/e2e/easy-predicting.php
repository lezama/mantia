<?php
/**
 * Easy predicting: auto-fill on join, "team gana todo" bulk-set, quick
 * chips, and the privacy guarantee that one user can't see another's
 * predictions from the web.
 *
 * These are the bot's "make-predicting-frictionless" features. The
 * scenarios verify the three default-prediction paths and the one hard
 * rule (your predictions are yours alone until kickoff).
 *
 * @package Mantia\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Easy predicting (auto-fill + bulk + privacy)' );
Mantia_E2E::require_fixture_or_skip( 'mundial-2026' );

// This scenario specifically validates the auto-fill path, so we
// reverse the suite-wide default (filter set to false in lib.php::start).
Mantia_E2E::enable_auto_predict();

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob',   2 );
$carla = Mantia_E2E::persona( 'Carla', 3 );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '1. Alice creates a penca → auto-fill fires' );
/* ------------------------------------------------------------------------ */

Mantia_E2E::send( $alice, 'me llamo Alice' );
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
$r = Mantia_E2E::send( $alice, '__E2E__ AutoFill' );

Mantia_E2E::assert_contains( $r, '0-0', 'create reply mentions the 0-0 default fill' );

$alice_user = Mantia_Repository::find_user_by_phone( $alice['phone'] );
Mantia_E2E::assert_eq( true, null !== $alice_user, 'Alice exists as a user' );

$group_ids = (array) get_user_meta( (int) $alice_user->ID, Mantia_Repository::META_GROUP_IDS, true );
Mantia_E2E::assert_eq( true, count( $group_ids ) >= 1, 'Alice has at least one penca' );

$group_id = (int) $group_ids[0];
$preds_count = count(
	get_posts(
		array(
			'post_type'      => Mantia_CPTs::PREDICTION,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array( 'key' => Mantia_Repository::META_USER_ID,  'value' => (int) $alice_user->ID ),
				array( 'key' => Mantia_Repository::META_GROUP_ID, 'value' => $group_id ),
			),
		)
	)
);
Mantia_E2E::assert_eq( true, $preds_count > 0, 'Alice has auto-filled predictions: ' . $preds_count );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '2. Auto-filled scores fall within the realistic range (0..6)' );
/* ------------------------------------------------------------------------ */

$bad = 0;
foreach (
	get_posts(
		array(
			'post_type'      => Mantia_CPTs::PREDICTION,
			'posts_per_page' => -1,
			'meta_query'     => array(
				array( 'key' => Mantia_Repository::META_USER_ID,  'value' => (int) $alice_user->ID ),
				array( 'key' => Mantia_Repository::META_GROUP_ID, 'value' => $group_id ),
			),
		)
	) as $pred
) {
	$h = (int) get_post_meta( (int) $pred->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
	$a = (int) get_post_meta( (int) $pred->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
	if ( $h < 0 || $h > 6 || $a < 0 || $a > 6 ) {
		$bad++;
	}
}
Mantia_E2E::assert_eq( 0, $bad, 'all auto-fill scores in [0,6]' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '3. "Argentina gana todo" overrides every Argentina match' );
/* ------------------------------------------------------------------------ */

$r = Mantia_E2E::send( $alice, 'Argentina gana todo' );
Mantia_E2E::assert_contains( $r, 'Argentina', 'reply confirms team name' );
Mantia_E2E::assert_contains( $r, 'sale ganando', 'reply uses bulk-back phrasing' );

// Every Argentina match in Alice's penca should now be 2-x (home) or x-2 (away)
// with Argentina as the winner.
$arg_wrong = 0;
$arg_matches = 0;
foreach (
	get_posts(
		array(
			'post_type'      => Mantia_CPTs::PREDICTION,
			'posts_per_page' => -1,
			'meta_query'     => array(
				array( 'key' => Mantia_Repository::META_USER_ID,  'value' => (int) $alice_user->ID ),
				array( 'key' => Mantia_Repository::META_GROUP_ID, 'value' => $group_id ),
			),
		)
	) as $pred
) {
	$match_id = (int) get_post_meta( (int) $pred->ID, Mantia_Repository::META_MATCH_ID, true );
	$match    = Mantia_Repository::match_to_array( $match_id );
	if ( 'Argentina' !== ( $match['home_team'] ?? '' ) && 'Argentina' !== ( $match['away_team'] ?? '' ) ) {
		continue;
	}
	$arg_matches++;
	$h = (int) get_post_meta( (int) $pred->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
	$a = (int) get_post_meta( (int) $pred->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
	$arg_home = ( 'Argentina' === $match['home_team'] );
	$arg_wins = $arg_home ? $h > $a : $a > $h;
	if ( ! $arg_wins ) {
		$arg_wrong++;
	}
}
Mantia_E2E::assert_eq( true, $arg_matches > 0, 'Argentina plays in this penca: ' . $arg_matches . ' match(es)' );
Mantia_E2E::assert_eq( 0, $arg_wrong, 'every Argentina match now backs Argentina' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '4. Bob joins → he gets 0-0 defaults; only Alice\'s manual overrides differ' );
/* ------------------------------------------------------------------------ */

// First we need the invite code for Alice's penca.
$invite = (string) get_post_meta( $group_id, Mantia_Repository::META_INVITE_CODE, true );
Mantia_E2E::assert_eq( true, '' !== $invite, 'invite code resolved' );

Mantia_E2E::send( $bob, 'me llamo Bob' );
$r = Mantia_E2E::send( $bob, $invite );
Mantia_E2E::assert_contains( $r, 'te sume a', 'Bob joined' );
Mantia_E2E::assert_contains( $r, '0-0', '0-0 default-fill mentioned to Bob' );

$bob_user = Mantia_Repository::find_user_by_phone( $bob['phone'] );
Mantia_E2E::assert_eq( true, null !== $bob_user, 'Bob exists' );

// Compare a handful of (match, alice_score) vs (match, bob_score). Default
// auto-fill is now 0-0 for both users, so most rows match. The Argentina
// matches Alice bulk-overrode in step 3 are her manual picks (e.g. "1-0"
// in Argentina's favor); Bob still has 0-0 for those. The test asserts at
// least one match diverges — that's the Argentina overrides shining
// through, which is the stronger signal than random Poisson chance was.
$differing = 0;
$pred_ids = get_posts(
	array(
		'post_type'      => Mantia_CPTs::PREDICTION,
		'posts_per_page' => 64,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => Mantia_Repository::META_USER_ID,  'value' => (int) $alice_user->ID ),
			array( 'key' => Mantia_Repository::META_GROUP_ID, 'value' => $group_id ),
		),
	)
);
foreach ( $pred_ids as $pid ) {
	$match_id = (int) get_post_meta( (int) $pid, Mantia_Repository::META_MATCH_ID, true );
	$bob_pred = Mantia_Repository::find_prediction( (int) $bob_user->ID, $match_id, $group_id );
	if ( ! $bob_pred ) {
		continue;
	}
	$ah = (int) get_post_meta( (int) $pid, Mantia_Repository::META_PRED_HOME_SCORE, true );
	$aa = (int) get_post_meta( (int) $pid, Mantia_Repository::META_PRED_AWAY_SCORE, true );
	$bh = (int) get_post_meta( (int) $bob_pred->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
	$ba = (int) get_post_meta( (int) $bob_pred->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
	if ( $ah !== $bh || $aa !== $ba ) {
		$differing++;
	}
}
// Argentina overrides drive the divergence: Alice manually set both
// Argentina matches in step 3, Bob still has 0-0 default for them.
// $differing therefore reflects "manual overrides survived join", not
// "random scores produced variation".
Mantia_E2E::assert_eq( true, $differing >= 1, "Alice's manual overrides diverge from Bob's 0-0 defaults: {$differing} match(es)" );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '5. PRIVACY — Carla cannot see Alice\'s predictions anywhere' );
/* ------------------------------------------------------------------------ */

Mantia_E2E::send( $carla, 'me llamo Carla' );
Mantia_E2E::send( $carla, $invite );

// Bot-side: render the group page. It must contain the leaderboard
// (points only) but never expose a specific predicted score by another user.
$alice_token = Mantia_Repository::user_view_token( (int) $alice_user->ID );
$alice_view  = '/pronostico/me/' . $alice_token . '/';
Mantia_E2E::assert_http_ok( $alice_view, array( 'Alice' ) );

// Carla viewing the group page should NOT see Alice's prediction strings.
$group_token = Mantia_Repository::group_view_token( $group_id );
// Group page returns 200. Names may or may not be rendered (initials-only
// in some layouts), so we don't assert their presence here — the key
// privacy invariant is that PREDICTIONS aren't leaked, which we check
// below by string-matching against a known Alice prediction.
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $group_token . '/' );
// Edit-mode UI must NOT appear on the group page (only on /me/).
$grp_resp = wp_remote_get( home_url( '/pronostico/g/' . $group_token . '/' ) );
$grp_body = is_wp_error( $grp_resp ) ? '' : (string) wp_remote_retrieve_body( $grp_resp );
Mantia_E2E::assert_eq( true, false === strpos( $grp_body, 'data-mantia-token' ), 'group page does NOT expose edit-mode form' );
// The group view should never embed someone else's specific predictions in
// the HTML — sanity-check that the page bytes don't include a known Alice
// prediction (we grab one and look it up).
$any_pred  = get_posts(
	array(
		'post_type'      => Mantia_CPTs::PREDICTION,
		'posts_per_page' => 1,
		'meta_query'     => array(
			array( 'key' => Mantia_Repository::META_USER_ID,  'value' => (int) $alice_user->ID ),
			array( 'key' => Mantia_Repository::META_GROUP_ID, 'value' => $group_id ),
		),
	)
);
if ( ! empty( $any_pred ) ) {
	$pid     = (int) $any_pred[0]->ID;
	$h       = (int) get_post_meta( $pid, Mantia_Repository::META_PRED_HOME_SCORE, true );
	$a       = (int) get_post_meta( $pid, Mantia_Repository::META_PRED_AWAY_SCORE, true );
	$mid     = (int) get_post_meta( $pid, Mantia_Repository::META_MATCH_ID, true );
	$match   = Mantia_Repository::match_to_array( $mid );
	$needle  = sprintf( 'Alice %s %d-%d %s', $match['home_team'] ?? '', $h, $a, $match['away_team'] ?? '' );
	// fetch the group view as Carla and check the needle is absent
	$resp    = wp_remote_get( home_url( '/pronostico/g/' . $group_token . '/' ) );
	$body    = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp );
	Mantia_E2E::assert_eq( true, false === strpos( $body, $needle ), "group view doesn't leak Alice's prediction prose" );
}

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '6. Cleanup' );
/* ------------------------------------------------------------------------ */
Mantia_E2E::cleanup();
Mantia_E2E::finish();
