<?php
/**
 * Full Mantia lifecycle — onboarding → invite → predict → resolve → web views.
 *
 * Run via:
 *   wp eval-file tests/e2e/penca-lifecycle.php
 *
 * Or from local:
 *   bin/e2e.sh
 *
 * Every interaction goes through the same preflight openclaWP invokes for
 * real WhatsApp traffic, so this is a true end-to-end test of the bot flow.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Mantia full lifecycle' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '0. Clean previous run' );
/* ------------------------------------------------------------------------- */
$wiped = Mantia_E2E::cleanup();
fwrite( STDOUT, "    · removed {$wiped} stale test artifacts\n" );

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob', 2 );
$carla = Mantia_E2E::persona( 'Carla', 3 );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '1. Alice arrives cold — sees onboarding menu' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $alice, 'hola' );
Mantia_E2E::assert_contains( $r, 'Crear penca', 'onboarding offers create' );
Mantia_E2E::assert_contains( $r, 'Tengo código', 'onboarding offers join-by-code' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '2. Alice taps Crear penca → sees competition picker' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::assert_contains( $r, 'torneo', 'picker prompts for torneo' );
Mantia_E2E::assert_eq( 'list', $r['interactive']['type'] ?? '', 'competition picker uses list type' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '3. Alice picks Mundial 2026' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::assert_contains( $r, '¿cómo se va a llamar', 'asks for penca name' );
Mantia_E2E::assert_contains( $r, 'Mundial 2026', 'shows chosen competition' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '4. Alice names the penca → group is created' );
/* ------------------------------------------------------------------------- */
$penca_name = Mantia_E2E::TEST_NAME_PREFIX . ' La Familia';
$r = Mantia_E2E::send( $alice, $penca_name );
Mantia_E2E::assert_contains( $r, 'Creé', 'creation confirmed' );
Mantia_E2E::assert_contains( $r, $penca_name, 'reply mentions the penca name' );

$group_post = null;
$groups     = get_posts(
	array(
		'post_type'   => Mantia_CPTs::GROUP,
		'name'        => sanitize_title( $penca_name ),
		'post_status' => 'publish',
		'posts_per_page' => 1,
	)
);
if ( ! empty( $groups ) ) {
	$group_post = $groups[0];
}
if ( null === $group_post ) {
	// Fallback by title
	global $wpdb;
	$gid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1", Mantia_CPTs::GROUP, $penca_name ) );
	if ( $gid > 0 ) {
		$group_post = get_post( $gid );
	}
}
Mantia_E2E::assert_eq( true, null !== $group_post, 'group exists in CPT' );

$group_id   = $group_post ? (int) $group_post->ID : 0;
$group      = Mantia_Repository::group_to_array( $group_id );
$invite     = (string) $group['invite_code'];
$share_url  = (string) ( $group['share_url'] ?? '' );
$view_token = Mantia_Repository::group_view_token( $group_id );

Mantia_E2E::assert_eq( 'mundial-2026', (string) $group['competition_id'], 'group bound to mundial-2026' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '5. Bob receives invite code by WhatsApp forward → joins' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $bob, $invite );
Mantia_E2E::assert_contains( $r, $penca_name, 'bob is welcomed into the right penca' );
Mantia_E2E::assert_contains( $r, 'sume', 'reply confirms join' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '6. Carla joins the same penca' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::send( $carla, $invite );

$members_check = array();
foreach ( array( $alice, $bob, $carla ) as $p ) {
	$u = Mantia_Repository::find_user_by_phone( $p['phone'] );
	$in = $u ? in_array( $group_id, (array) get_post_meta( (int) $u->ID, Mantia_Repository::META_GROUP_IDS, true ), true ) : false;
	$members_check[ $p['name'] ] = $in;
}
Mantia_E2E::assert_eq( array( 'Alice' => true, 'Bob' => true, 'Carla' => true ), $members_check, 'all 3 personas are members' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '7. Bob taps Pendientes → sees Mundial matches' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $bob, 'pendientes' );
Mantia_E2E::assert_contains( $r, 'Mundial 2026', 'pending list includes Mundial bucket' );
$match_id = Mantia_E2E::match_id_from_payload( $r, 0 );
Mantia_E2E::assert_eq( true, $match_id > 0, 'list contains at least one tappable match' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '8. Bob taps a match → bot stashes id + prompts for score' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $bob, "mantia:match:{$match_id}" );
Mantia_E2E::assert_contains( $r, $penca_name, 'detail names the target penca' );
Mantia_E2E::assert_contains( $r, 'marcador', 'asks for the score' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '9. Bob replies "2-1" — auto-routed by match competition' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $bob, '2-1' );
Mantia_E2E::assert_contains( $r, 'Anotado', 'bot confirms prediction' );
Mantia_E2E::assert_contains( $r, $penca_name, 'confirmation names the right penca' );

$bob_user = Mantia_Repository::find_user_by_phone( $bob['phone'] );
$bob_pred = $bob_user ? Mantia_Repository::find_prediction( (int) $bob_user->ID, $match_id, $group_id ) : null;
Mantia_E2E::assert_eq( true, null !== $bob_pred, 'prediction is persisted in the CPT' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '10. Alice predicts the same match with a different score' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::send( $alice, "mantia:match:{$match_id}" );
Mantia_E2E::send( $alice, '3-2' );

Mantia_E2E::send( $carla, "mantia:match:{$match_id}" );
Mantia_E2E::send( $carla, '0-0' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '11. Match finishes 2-1 — Bob nailed it, Alice missed' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::finish_match( $match_id, 2, 1 );
$resolve = Mantia_Abilities::resolve_match( array( 'match_id' => $match_id ) );
Mantia_E2E::assert_eq( true, ! is_wp_error( $resolve ), 'match resolution succeeded' );

$leaderboard = Mantia_Leaderboard::rows( $group_id, 50 );
$by_name     = array();
foreach ( $leaderboard as $row ) {
	$by_name[ $row['name'] ] = (int) $row['points'];
}
fwrite( STDOUT, "    · leaderboard after resolution: " . json_encode( $by_name ) . "\n" );

// Scoring per the default rules:
// Bob:   exact 2-1 = 5 pts
// Alice: 3-2 → diff +1 correct (both 1-goal home win) = 3 pts
// Carla: 0-0 → diff 0 wrong, winner wrong = 0 pts
$alice_post = Mantia_Repository::find_user_by_phone( $alice['phone'] );
$bob_post   = Mantia_Repository::find_user_by_phone( $bob['phone'] );
$carla_post = Mantia_Repository::find_user_by_phone( $carla['phone'] );
$alice_name = $alice_post ? get_the_title( (int) $alice_post->ID ) : 'Alice';
$bob_name   = $bob_post ? get_the_title( (int) $bob_post->ID ) : 'Bob';
$carla_name = $carla_post ? get_the_title( (int) $carla_post->ID ) : 'Carla';

Mantia_E2E::assert_eq( 5, $by_name[ $bob_name ] ?? -1, 'Bob: exact = 5 pts' );
Mantia_E2E::assert_eq( 3, $by_name[ $alice_name ] ?? -1, 'Alice: diff = 3 pts' );
Mantia_E2E::assert_eq( 0, $by_name[ $carla_name ] ?? -1, 'Carla: miss = 0 pts' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '12. Bob asks tabla — sees Bob first, Alice second' );
/* ------------------------------------------------------------------------- */
$r = Mantia_E2E::send( $bob, 'tabla' );
Mantia_E2E::assert_contains( $r, $bob_name, 'tabla lists Bob' );
Mantia_E2E::assert_contains( $r, $alice_name, 'tabla lists Alice' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '13. Web frontend renders the right surfaces' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::assert_http_ok( '/', array( 'mantia', 'WhatsApp' ) );
Mantia_E2E::assert_http_ok( '/penca/mundial-2026/', array( 'Mundial 2026', 'Crear penca' ) );
Mantia_E2E::assert_http_ok( '/penca/g/' . $view_token . '/', array( $penca_name, 'Sumate' ) );

$bob_token = Mantia_Repository::user_view_token( (int) $bob_post->ID );
Mantia_E2E::assert_http_ok( '/penca/me/' . $bob_token . '/', array( $bob_name, 'puntos' ) );

Mantia_E2E::assert_http_status( '/penca/g/0000000000000000/', 404, array( 'no funciona', 'Crear una penca' ) );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '14. Multi-penca routing: Alice creates a second Mundial penca' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
$second_name = Mantia_E2E::TEST_NAME_PREFIX . ' Oficina';
Mantia_E2E::send( $alice, $second_name );

// Pick another match and predict
$r        = Mantia_E2E::send( $alice, 'pendientes' );
$match2_id = Mantia_E2E::match_id_from_payload( $r, 0 );
if ( $match2_id > 0 && $match2_id !== $match_id ) {
	Mantia_E2E::send( $alice, "mantia:match:{$match2_id}" );
	Mantia_E2E::send( $alice, '1-0' );

	// Auto-routing should have written into BOTH of Alice's mundial pencas.
	$alice_groups = Mantia_Repository::user_groups_in_competition( (int) $alice_post->ID, 'mundial-2026' );
	Mantia_E2E::assert_eq( true, count( $alice_groups ) >= 2, 'Alice now has 2+ Mundial pencas' );
	$preds_count = 0;
	foreach ( $alice_groups as $gid ) {
		if ( Mantia_Repository::find_prediction( (int) $alice_post->ID, $match2_id, (int) $gid ) ) {
			++$preds_count;
		}
	}
	Mantia_E2E::assert_eq( count( $alice_groups ), $preds_count, 'prediction landed in EVERY Mundial penca' );
}

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '15. Cleanup' );
/* ------------------------------------------------------------------------- */
$wiped = Mantia_E2E::cleanup();
fwrite( STDOUT, "    · removed {$wiped} test artifacts\n" );

Mantia_E2E::finish();
