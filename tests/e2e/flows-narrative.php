<?php
/**
 * Narrative simulation: walk a real user journey through every public
 * command + every UI surface. If a flow breaks silently in production
 * it ought to fail loudly here first.
 *
 * Personas: Alice (the organiser), Bob (a friend), Carla (the lurker).
 * The story:
 *   1. Alice says hi → bot welcomes her
 *   2. Alice sets a name
 *   3. Alice asks for help → bot lists commands
 *   4. Alice creates a Mundial penca
 *   5. Alice predicts a match manually (overrides nothing — fresh)
 *   6. Bob joins via the invite code
 *   7. Bob and Alice both predict the same match (different scores)
 *   8. Alice checks "mis pronosticos"
 *   9. Alice checks "tabla"
 *  10. Alice checks "partidos"
 *  11. Alice checks "mis grupos"
 *  12. Alice asks for the share link
 *  13. Alice creates a SECOND penca → bot routes their prediction to both
 *  14. Alice switches active penca
 *  15. Carla joins penca 1 → her predictions invisible to Alice
 *
 * Each step lands at least one assertion; the suite as a whole is the
 * "Mantia still works end-to-end" smoke test.
 *
 * @package Mantia\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Narrative — every command, three personas' );

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob',   2 );
$carla = Mantia_E2E::persona( 'Carla', 3 );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '1. Welcome — "hola" greets the unknown user' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'hola' );
Mantia_E2E::assert_contains( $r, 'Mantia', 'welcome names the brand' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '2. Set name — "me llamo Alice" stores the display name' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'me llamo Alice' );
Mantia_E2E::assert_contains( $r, 'Alice', 'echo back the chosen name' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '3. Help — "ayuda" lists commands in the right language' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'ayuda' );
Mantia_E2E::assert_contains( $r, 'partidos', 'help mentions the partidos command' );
Mantia_E2E::assert_contains( $r, 'tabla',    'help mentions the tabla command' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '4. Create penca — bot walks competition → name flow' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::assert_contains( $r, 'torneo', 'asks which competition' );

$r = Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::assert_contains( $r, '¿cómo se va a llamar', 'asks for a penca name' );

$r = Mantia_E2E::send( $alice, '__E2E__ Narrativa' );
Mantia_E2E::assert_contains( $r, 'Creaste', 'create confirmation' );

$alice_user = Mantia_Repository::find_user_by_phone( $alice['phone'] );
$alice_id   = (int) $alice_user->ID;
$group_ids  = (array) get_post_meta( $alice_id, Mantia_Repository::META_GROUP_IDS, true );
$group_id   = (int) $group_ids[0];
$invite     = (string) get_post_meta( $group_id, Mantia_Repository::META_INVITE_CODE, true );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '5. Predict manually — tap a match, send "2-1"' );
/* ------------------------------------------------------------------------ */
// Filter to MUNDIAL matches specifically — Alice's penca is Mundial, so
// predictions for non-Mundial fixtures get rejected by the competition guard.
$upcoming = Mantia_Repository::upcoming_matches_for_competition( 'mundial-2026', 24 * 365 );
Mantia_E2E::assert_eq( true, ! empty( $upcoming ), 'fixture has upcoming Mundial matches' );

if ( ! empty( $upcoming ) ) {
	$match_id = (int) $upcoming[0]['id'];
	Mantia_E2E::send( $alice, 'mantia:match:' . $match_id );
	$r = Mantia_E2E::send( $alice, '2-1' );
	Mantia_E2E::assert_contains( $r, 'Anotado', 'prediction confirmed' );
}

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '6. Bob joins — receives the welcome + group lineup' );
/* ------------------------------------------------------------------------ */
Mantia_E2E::send( $bob, 'me llamo Bob' );
$r = Mantia_E2E::send( $bob, $invite );
Mantia_E2E::assert_contains( $r, 'te sume',    'Bob is in' );
Mantia_E2E::assert_contains( $r, 'Narrativa',  'reply names the penca' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '7. Bob predicts the same match differently' );
/* ------------------------------------------------------------------------ */
if ( ! empty( $upcoming ) ) {
	$match_id = (int) $upcoming[0]['id'];
	Mantia_E2E::send( $bob, 'mantia:match:' . $match_id );
	$r = Mantia_E2E::send( $bob, '0-0' );
	Mantia_E2E::assert_contains( $r, 'Anotado', 'Bob prediction confirmed' );

	// Both predictions exist as separate rows
	$alice_pred = Mantia_Repository::find_prediction( $alice_id, $match_id, $group_id );
	$bob_user   = Mantia_Repository::find_user_by_phone( $bob['phone'] );
	$bob_pred   = Mantia_Repository::find_prediction( (int) $bob_user->ID, $match_id, $group_id );
	Mantia_E2E::assert_eq( true, $alice_pred && $bob_pred, 'two predictions saved for one match' );
}

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '8. "mis pronosticos" returns Alice\'s history' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'mis pronosticos' );
// Either lists predictions, or says "todavía no" if the fan-out's pending —
// both are valid; we just assert no fatal.
Mantia_E2E::assert_eq( true, isset( $r['reply'] ) && '' !== $r['reply'], 'mis pronosticos returned content' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '9. "tabla" returns the standings or an empty-state' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'tabla' );
Mantia_E2E::assert_eq( true, isset( $r['reply'] ) && '' !== $r['reply'], 'tabla replied' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '10. "partidos" lists the fixture' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'partidos' );
Mantia_E2E::assert_eq( true, isset( $r['reply'] ) && '' !== $r['reply'], 'partidos replied' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '11. "mis grupos" shows the joined penca' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'mis grupos' );
Mantia_E2E::assert_contains( $r, 'Narrativa', 'mis grupos names the penca' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '12. "link" returns the share URL' );
/* ------------------------------------------------------------------------ */
$r = Mantia_E2E::send( $alice, 'link' );
Mantia_E2E::assert_contains( $r, 'penca/g/',   'link contains the share path' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '13. Create a second penca — predictions still fan out' );
/* ------------------------------------------------------------------------ */
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::send( $alice, '__E2E__ Narrativa2' );

$all_groups = Mantia_Repository::user_groups_to_array( $alice_id );
Mantia_E2E::assert_eq( 2, count( $all_groups ), 'Alice has two Mundial pencas' );

// Now predict — should land in BOTH pencas.
if ( ! empty( $upcoming ) && count( $upcoming ) > 1 ) {
	$match_id = (int) $upcoming[1]['id'];
	Mantia_E2E::send( $alice, 'mantia:match:' . $match_id );
	Mantia_E2E::send( $alice, '3-1' );

	$count_in = 0;
	foreach ( $all_groups as $g ) {
		if ( Mantia_Repository::find_prediction( $alice_id, $match_id, (int) $g['id'] ) ) {
			$count_in++;
		}
	}
	Mantia_E2E::assert_eq( 2, $count_in, 'one "3-1" landed in both pencas' );
}

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '14. Switch active penca — both should be reachable' );
/* ------------------------------------------------------------------------ */
$other_gid = 0;
foreach ( $all_groups as $g ) {
	if ( (int) $g['id'] !== $group_id ) {
		$other_gid = (int) $g['id'];
		break;
	}
}
if ( $other_gid > 0 ) {
	$r = Mantia_E2E::send( $alice, 'mantia:switch:' . $other_gid );
	Mantia_E2E::assert_contains( $r, 'Narrativa2', 'switch lands on the right penca' );
}

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '15. PRIVACY — Carla joins, can\'t see Alice\'s scores' );
/* ------------------------------------------------------------------------ */
Mantia_E2E::send( $carla, 'me llamo Carla' );
Mantia_E2E::send( $carla, $invite );

$alice_view = '/penca/me/' . Mantia_Repository::user_view_token( $alice_id ) . '/';
$carla_view = '/penca/me/' . Mantia_Repository::user_view_token( (int) Mantia_Repository::find_user_by_phone( $carla['phone'] )->ID ) . '/';

// Alice's /me/ page must NOT be visible just because Carla knows the
// invite code or the group token. The user view token is per-user.
$alice_token = Mantia_Repository::user_view_token( $alice_id );
$alice_resp  = wp_remote_get( home_url( '/penca/me/' . $alice_token . '/' ) );
$alice_body  = is_wp_error( $alice_resp ) ? '' : (string) wp_remote_retrieve_body( $alice_resp );
Mantia_E2E::assert_eq( true, false !== strpos( $alice_body, 'Alice' ), 'Alice\'s view shows Alice' );

// Carla's view shouldn't expose Alice's predictions.
$carla_resp = wp_remote_get( home_url( $carla_view ) );
$carla_body = is_wp_error( $carla_resp ) ? '' : (string) wp_remote_retrieve_body( $carla_resp );
// "Anotado" is a fragment from prediction-confirmation copy; if it showed
// up on Carla's page it would only be for Carla's own predictions.
Mantia_E2E::assert_eq( true, false === strpos( $carla_body, 'Alice' ) || 0 === strlen( $alice_body ), 'Carla\'s page doesn\'t name Alice' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '16. Cleanup' );
/* ------------------------------------------------------------------------ */
Mantia_E2E::cleanup();
Mantia_E2E::finish();
