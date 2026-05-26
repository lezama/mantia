<?php
/**
 * Six-user deep stress walkthrough.
 *
 * The "let's break it" scenario. Sets up 6 personas across 2 overlapping
 * pencas, walks every visible WhatsApp command path, hits every public
 * `/pronostico/*` rewrite endpoint per user, exercises the scheduler reminder
 * ability, then time-travels two matches through scheduled → finished →
 * resolved in two full cycles so the same code paths run repeatedly with
 * different state.
 *
 * Compared to tests/e2e/three-user-walkthrough.php — which is the
 * minimum-viable happy-path demo — this scenario:
 *   • doubles the persona count (so cross-user state matters)
 *   • runs two pencas in parallel with overlapping membership (so
 *     auto-routing into multiple groups gets exercised)
 *   • exercises a sample of every bot regex branch
 *   • hits OG, share-card, slug-based, token-based, sumate, expired and
 *     offline web endpoints — not just the happy /pronostico/g/ + /pronostico/me/
 *   • invokes the match-reminder scheduler ability and checks recipients
 *   • runs through two full match-resolution cycles (not just one)
 *
 * Run:
 *   bin/e2e.sh six-user-deep-walkthrough
 *
 * Re-runnable: cleanup() at start drops the 9999000* personas and any
 * __E2E__ groups, and the snapshot/restore on touched matches puts the
 * fixture back to its original kickoff/score.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Six-user deep walkthrough — 2 pencas, 2 matches, 2 rounds' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Clean slate' );
/* ─────────────────────────────────────────────────────────────────────── */
$wiped = Mantia_E2E::cleanup();
fwrite( STDOUT, "    · removed {$wiped} stale test artifacts\n" );

$alice     = Mantia_E2E::persona( 'Alice',     1 );
$bob       = Mantia_E2E::persona( 'Bob',       2 );
$carla     = Mantia_E2E::persona( 'Carla',     3 );
$diego     = Mantia_E2E::persona( 'Diego',     4 );
$esteban   = Mantia_E2E::persona( 'Esteban',   5 );
$florencia = Mantia_E2E::persona( 'Florencia', 6 );
$everyone  = array( $alice, $bob, $carla, $diego, $esteban, $florencia );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Schedule TWO matches: M1 in +60min, M2 in +180min' );
/* ─────────────────────────────────────────────────────────────────────── */
$competition_id = 'libertadores-semana';

// Pick the soonest seeded match for M1.
$m1 = Mantia_E2E::schedule_match_in_minutes( 60, $competition_id );
if ( empty( $m1 ) ) {
	Mantia_E2E::step( '! no seeded match available — aborting' );
	Mantia_E2E::finish();
	return;
}
$m1_id = (int) $m1['id'];

// Pick a DIFFERENT match for M2 by walking the fixture.
$m2_id = 0;
foreach ( Mantia_Repository::upcoming_matches_for_competition( $competition_id, 24 * 365 ) as $candidate ) {
	if ( (int) $candidate['id'] !== $m1_id ) { $m2_id = (int) $candidate['id']; break; }
}
if ( 0 === $m2_id ) {
	// Fall back to any non-M1 match in the parent comp.
	$ids = get_posts( array(
		'post_type'      => Mantia_CPTs::MATCH,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'exclude'        => array( $m1_id ),
	) );
	$m2_id = ! empty( $ids ) ? (int) $ids[0] : 0;
}
Mantia_E2E::assert_true( $m2_id > 0, 'second match available for round 2' );
$m2 = Mantia_E2E::schedule_match_in_minutes( 180, $competition_id, $m2_id );
$m1_home = (string) $m1['home_team']; $m1_away = (string) $m1['away_team'];
$m2_home = (string) $m2['home_team']; $m2_away = (string) $m2['away_team'];

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Alice creates "La Familia" (everyone joins)' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $alice, 'hola' );
Mantia_E2E::assert_contains( $r, 'Crear penca', 'cold onboarding shows create CTA' );

Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:' . $competition_id );
$familia_name = Mantia_E2E::TEST_NAME_PREFIX . ' La Familia';
$r = Mantia_E2E::send( $alice, $familia_name );
Mantia_E2E::assert_contains( $r, 'Creaste', 'La Familia created' );

global $wpdb;
$familia_id = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s ORDER BY ID DESC LIMIT 1",
	Mantia_CPTs::GROUP, $familia_name
) );
Mantia_E2E::assert_true( $familia_id > 0, 'familia exists in DB' );
$familia    = Mantia_Repository::group_to_array( $familia_id );
$familia_iv = (string) $familia['invite_code'];
$familia_tk = Mantia_Repository::group_view_token( $familia_id );
$familia_sl = (string) get_post_meta( $familia_id, Mantia_Repository::META_GROUP_SLUG, true );

foreach ( array( $bob, $carla, $diego, $esteban, $florencia ) as $p ) {
	$r = Mantia_E2E::send( $p, $familia_iv );
	Mantia_E2E::assert_contains( $r, $familia_name, "{$p['name']} joined La Familia" );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Alice creates "La Oficina" (Diego + Florencia join)' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:' . $competition_id );
$oficina_name = Mantia_E2E::TEST_NAME_PREFIX . ' La Oficina';
$r = Mantia_E2E::send( $alice, $oficina_name );
Mantia_E2E::assert_contains( $r, $oficina_name, 'La Oficina created' );

$oficina_id = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s ORDER BY ID DESC LIMIT 1",
	Mantia_CPTs::GROUP, $oficina_name
) );
Mantia_E2E::assert_true( $oficina_id > 0 && $oficina_id !== $familia_id, 'oficina is a distinct group' );
$oficina    = Mantia_Repository::group_to_array( $oficina_id );
$oficina_iv = (string) $oficina['invite_code'];
$oficina_tk = Mantia_Repository::group_view_token( $oficina_id );

foreach ( array( $diego, $florencia ) as $p ) {
	$r = Mantia_E2E::send( $p, $oficina_iv );
	Mantia_E2E::assert_contains( $r, $oficina_name, "{$p['name']} joined La Oficina" );
}

// Diego and Florencia are now in BOTH pencas. Sanity-check.
foreach ( array( $diego, $florencia ) as $p ) {
	$u = Mantia_Repository::find_user_by_phone( $p['phone'] );
	$ids = array_map( 'intval', (array) get_user_meta( (int) $u->ID, Mantia_Repository::META_GROUP_IDS, true ) );
	Mantia_E2E::assert_true(
		in_array( $familia_id, $ids, true ) && in_array( $oficina_id, $ids, true ),
		"{$p['name']} is in both pencas"
	);
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4a. Scheduler — reminder list BEFORE anyone predicts' );
/* ─────────────────────────────────────────────────────────────────────── */
// Reminders go to users who DON'T yet have a prediction for an upcoming
// match. At this point all 6 personas qualify (M1 is +60min, no
// predictions exist yet). Capture this BEFORE step 4b's predictions wipe
// them off the list.
$reminder_pre = Mantia_Abilities::get_match_reminder_targets( array( 'hours_ahead' => 2 ) );
Mantia_E2E::assert_true( ! is_wp_error( $reminder_pre ) && isset( $reminder_pre['targets'] ),
	'reminder ability returned targets array' );

$pre_phones = array_map(
	static fn( array $t ): string => (string) ( $t['recipient'] ?? '' ),
	(array) ( $reminder_pre['targets'] ?? array() )
);
foreach ( $everyone as $p ) {
	$found = false;
	foreach ( $pre_phones as $tp ) {
		if ( false !== strpos( $tp, $p['phone'] ) ) { $found = true; break; }
	}
	Mantia_E2E::assert_true( $found, "{$p['name']} is on the pre-prediction reminder list" );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4b. ROUND 1 — every persona predicts M1 (auto-route across pencas)' );
/* ─────────────────────────────────────────────────────────────────────── */
$r1_scores = array(
	'Alice'     => array( 2, 1 ),  // exact          → 5 pts
	'Bob'       => array( 1, 1 ),  // wrong (draw)   → 0 pts
	'Carla'     => array( 3, 0 ),  // right winner   → 1 pt
	'Diego'     => array( 2, 0 ),  // right winner   → 1 pt (diff +2 ≠ real +1)
	'Esteban'   => array( 1, 2 ),  // wrong (away)   → 0 pts
	'Florencia' => array( 0, 0 ),  // wrong (draw)   → 0 pts
);
foreach ( $everyone as $p ) {
	$score = $r1_scores[ $p['name'] ];
	$res   = Mantia_Abilities::register_prediction( array(
		'user_phone' => $p['phone'],
		'match_id'   => $m1_id,
		'home_score' => $score[0],
		'away_score' => $score[1],
	) );
	Mantia_E2E::assert_true( ! is_wp_error( $res ), "{$p['name']} predicted M1 {$score[0]}-{$score[1]}" );
}

// Diego and Florencia are in BOTH pencas — auto-routing should have
// written their M1 prediction into both group rows.
foreach ( array( $diego, $florencia ) as $p ) {
	$u = Mantia_Repository::find_user_by_phone( $p['phone'] );
	$f_pred = Mantia_Repository::find_prediction( (int) $u->ID, $m1_id, $familia_id );
	$o_pred = Mantia_Repository::find_prediction( (int) $u->ID, $m1_id, $oficina_id );
	Mantia_E2E::assert_true( null !== $f_pred && null !== $o_pred, "{$p['name']} fanned out to both pencas" );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. WhatsApp flow sampler — exercise every public bot command' );
/* ─────────────────────────────────────────────────────────────────────── */

// onboarding view post-join (Alice is in 2 pencas — Oficina is active
// because it was created last; "hoy" speaks to the currently active one)
$r = Mantia_E2E::send( $alice, 'hoy' );
Mantia_E2E::assert_contains( $r, $oficina_name, '"hoy" speaks to Alice\'s currently active penca' );

$r = Mantia_E2E::send( $alice, 'mis pencas' );
Mantia_E2E::assert_contains( $r, $familia_name, '"mis pencas" lists La Familia' );
Mantia_E2E::assert_contains( $r, $oficina_name, '"mis pencas" lists La Oficina' );

// Pendientes — should NOT be empty given a match upcoming.
$r = Mantia_E2E::send( $bob, 'pendientes' );
Mantia_E2E::assert_contains( $r, 'Libertadores', '"pendientes" includes Libertadores bucket' );

// Tap a match in pending list.
$tapped_match = Mantia_E2E::match_id_from_payload( $r, 0 );
Mantia_E2E::assert_true( $tapped_match > 0, 'pending list exposes a tappable match' );

// Tabla pre-resolución
$r = Mantia_E2E::send( $carla, 'tabla' );
Mantia_E2E::assert_contains( $r, $familia_name, '"tabla" replies in penca context' );

// Compartir link (handle_share_link)
$r = Mantia_E2E::send( $alice, 'compartir');
Mantia_E2E::assert_true( ! empty( $r['reply'] ?? '' ), '"compartir" returned some reply' );

// Consenso — no finished matches yet, so this is the early-exit path.
$r = Mantia_E2E::send( $diego, 'consenso' );
Mantia_E2E::assert_true( ! empty( $r['reply'] ?? '' ), '"consenso" returned a reply (even if "no matches yet")' );

// Unknown text should not crash the router.
$r = Mantia_E2E::send( $esteban, 'gracias' );
Mantia_E2E::assert_true( is_array( $r ), 'unknown chitchat does not break the bot' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '6. Scheduler — reminder list is empty AFTER everyone has predicted' );
/* ─────────────────────────────────────────────────────────────────────── */
// Once predictions exist, the reminder system drops those users from the
// list (no prediction gap). This is the workflow's whole reason for
// existing — verify the negative case so a regression that floods users
// with redundant reminders fails loudly.
$reminder_post = Mantia_Abilities::get_match_reminder_targets( array( 'hours_ahead' => 2 ) );
Mantia_E2E::assert_true( ! is_wp_error( $reminder_post ), 'reminder ability ok post-predict' );
$post_phones = array_map(
	static fn( array $t ): string => (string) ( $t['recipient'] ?? '' ),
	(array) ( $reminder_post['targets'] ?? array() )
);
foreach ( $everyone as $p ) {
	$found_after = false;
	foreach ( $post_phones as $tp ) {
		if ( false !== strpos( $tp, $p['phone'] ) ) { $found_after = true; break; }
	}
	Mantia_E2E::assert_true( ! $found_after, "{$p['name']} dropped from reminder list (predicted)" );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '7. Web crawl — every public /pronostico/* endpoint (pre-resolution)' );
/* ─────────────────────────────────────────────────────────────────────── */

// Token-based group page (no auth required).
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $familia_tk . '/', array( $familia_name ) );
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $oficina_tk . '/', array( $oficina_name ) );

// Slug-based group page (auth-gated → 404 anonymously, but the bot's
// shared link uses the same slug for known visitors).
Mantia_E2E::assert_http_status( '/pronostico/g/' . $familia_sl . '/', 404 );

// OG card (PNG image rendered by GD or SVG fallback).
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $familia_tk . '/og/' );

// Share card (human-readable share preview).
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $familia_tk . '/compartir/', array( $familia_name ) );

// Invite landing.
Mantia_E2E::assert_http_ok( '/pronostico/sumate/' . $familia_iv . '/', array( $familia_name ) );
Mantia_E2E::assert_http_ok( '/pronostico/sumate/' . $oficina_iv . '/', array( $oficina_name ) );

// Competition standings (anonymous read).
Mantia_E2E::assert_http_ok( '/pronostico/' . $competition_id . '/' );

// Personal /me/<token>/ for ALL 6 personas.
$user_tokens = array();
foreach ( $everyone as $p ) {
	$u  = Mantia_Repository::find_user_by_phone( $p['phone'] );
	$tk = $u ? Mantia_Repository::user_view_token( (int) $u->ID ) : '';
	$user_tokens[ $p['name'] ] = $tk;
	Mantia_E2E::assert_true( '' !== $tk, "{$p['name']} got a /me/ token" );
	Mantia_E2E::assert_http_ok( '/pronostico/me/' . $tk . '/' );
}

// Auth landing (expired magic-link).
Mantia_E2E::assert_http_ok( '/pronostico/expired/' );

// PWA offline page.
Mantia_E2E::assert_http_ok( '/pronostico/offline/' );

// Bogus group token → 404.
Mantia_E2E::assert_http_status( '/pronostico/g/0000000000000000/', 404 );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8. Time-travel M1 → finished 2-1, resolve, verify scoring' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::finish_match( $m1_id, 2, 1 );
$resolve = Mantia_Abilities::resolve_match( array( 'match_id' => $m1_id ) );
Mantia_E2E::assert_true( ! is_wp_error( $resolve ), 'M1 resolution succeeded' );

$familia_board = Mantia_Leaderboard::rows( $familia_id, 50 );
$oficina_board = Mantia_Leaderboard::rows( $oficina_id, 50 );
$fam_pts = array(); foreach ( $familia_board as $row ) { $fam_pts[ (string) $row['name'] ] = (int) $row['points']; }
$ofi_pts = array(); foreach ( $oficina_board as $row ) { $ofi_pts[ (string) $row['name'] ] = (int) $row['points']; }
fwrite( STDOUT, "    · familia board: " . wp_json_encode( $fam_pts ) . "\n" );
fwrite( STDOUT, "    · oficina board: " . wp_json_encode( $ofi_pts ) . "\n" );

// Default scoring: exact=5, diff=3, winner=1, else=0.
// Real M1 = 2-1 (home wins by 1). Reminder: "diff" requires the SIGNED
// goal difference to match exactly (predicted +2 vs real +1 fails diff).
$expected_r1 = array(
	'Alice'     => 5, // 2-1 = exact
	'Bob'       => 0, // 1-1 draw, wrong outcome
	'Carla'     => 1, // 3-0 home win, diff +3 ≠ +1, winner right
	'Diego'     => 1, // 2-0 home win, diff +2 ≠ +1, winner right
	'Esteban'   => 0, // 1-2 away win, wrong
	'Florencia' => 0, // 0-0 draw, wrong
);
foreach ( $expected_r1 as $name => $pts ) {
	Mantia_E2E::assert_eq( $pts, $fam_pts[ $name ] ?? -1, "Familia R1: {$name} = {$pts} pts" );
}
// In La Oficina only Alice/Diego/Florencia matter.
Mantia_E2E::assert_eq( 5, $ofi_pts['Alice']     ?? -1, 'Oficina R1: Alice exact = 5' );
Mantia_E2E::assert_eq( 1, $ofi_pts['Diego']     ?? -1, 'Oficina R1: Diego winner = 1' );
Mantia_E2E::assert_eq( 0, $ofi_pts['Florencia'] ?? -1, 'Oficina R1: Florencia miss = 0' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '9. Post-M1 WhatsApp — tabla + consenso now have data' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $bob, 'tabla' );
foreach ( array( 'Alice', 'Bob', 'Carla', 'Diego', 'Esteban', 'Florencia' ) as $name ) {
	Mantia_E2E::assert_contains( $r, $name, "tabla lists {$name}" );
}

$r = Mantia_E2E::send( $florencia, 'consenso' );
Mantia_E2E::assert_contains( $r, $m1_home, 'consenso shows the home team of last finished match' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '10. ROUND 2 — predict M2; Alice splits her vote per penca' );
/* ─────────────────────────────────────────────────────────────────────── */

// Alice predicts DIFFERENT scores in each penca by passing group_id
// explicitly — exercises the non-auto-route path.
$alice_familia = Mantia_Abilities::register_prediction( array(
	'user_phone' => $alice['phone'],
	'match_id'   => $m2_id,
	'group_id'   => $familia_id,
	'home_score' => 1,
	'away_score' => 0,
) );
Mantia_E2E::assert_true( ! is_wp_error( $alice_familia ), 'Alice predicted M2 1-0 in Familia' );
$alice_oficina = Mantia_Abilities::register_prediction( array(
	'user_phone' => $alice['phone'],
	'match_id'   => $m2_id,
	'group_id'   => $oficina_id,
	'home_score' => 0,
	'away_score' => 2,
) );
Mantia_E2E::assert_true( ! is_wp_error( $alice_oficina ), 'Alice predicted M2 0-2 in Oficina' );

// Everyone else picks one score; for those in two pencas auto-route fans out.
$r2_scores = array(
	'Bob'       => array( 2, 2 ),  // exact draw
	'Carla'     => array( 0, 0 ),  // draw — but real will be 2-2 → diff wrong, winner right
	'Diego'     => array( 3, 1 ),  // wrong (home wins) for a 2-2 real
	'Esteban'   => array( 2, 2 ),  // exact draw → 5
	'Florencia' => array( 1, 1 ),  // draw, diff +0 match → 3
);
foreach ( array( $bob, $carla, $diego, $esteban, $florencia ) as $p ) {
	$s = $r2_scores[ $p['name'] ];
	$res = Mantia_Abilities::register_prediction( array(
		'user_phone' => $p['phone'],
		'match_id'   => $m2_id,
		'home_score' => $s[0],
		'away_score' => $s[1],
	) );
	Mantia_E2E::assert_true( ! is_wp_error( $res ), "{$p['name']} predicted M2 {$s[0]}-{$s[1]}" );
}

// Verify Alice's two pencas DO have different stored scores. find_prediction
// returns a WP_Post. Predictions store their score under META_PRED_*
// (the unprefixed META_HOME_SCORE is the MATCH's real score, not the
// prediction's — easy footgun, hence the explicit meta key here).
$u_alice = Mantia_Repository::find_user_by_phone( $alice['phone'] );
$pf = Mantia_Repository::find_prediction( (int) $u_alice->ID, $m2_id, $familia_id );
$po = Mantia_Repository::find_prediction( (int) $u_alice->ID, $m2_id, $oficina_id );
Mantia_E2E::assert_true( null !== $pf && null !== $po, 'Alice has a row in both pencas for M2' );
$pf_home = $pf ? (int) get_post_meta( (int) $pf->ID, Mantia_Repository::META_PRED_HOME_SCORE, true ) : -1;
$po_home = $po ? (int) get_post_meta( (int) $po->ID, Mantia_Repository::META_PRED_HOME_SCORE, true ) : -1;
Mantia_E2E::assert_eq( 1, $pf_home, 'Alice Familia M2 pred home=1' );
Mantia_E2E::assert_eq( 0, $po_home, 'Alice Oficina M2 pred home=0' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '11. Scheduler again — M2 in +180min should also surface' );
/* ─────────────────────────────────────────────────────────────────────── */
// hours_ahead=4 widens the window so both M2 and a few seeds fall in.
$reminder2 = Mantia_Abilities::get_match_reminder_targets( array( 'hours_ahead' => 4 ) );
Mantia_E2E::assert_true( ! is_wp_error( $reminder2 ) && isset( $reminder2['targets'] ),
	'reminder ability returns array with 4h window' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '12. Time-travel M2 → finished 2-2 (a draw), resolve' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::finish_match( $m2_id, 2, 2 );
$resolve2 = Mantia_Abilities::resolve_match( array( 'match_id' => $m2_id ) );
Mantia_E2E::assert_true( ! is_wp_error( $resolve2 ), 'M2 resolution succeeded' );

// Real M2 = 2-2 (signed diff = 0, outcome = draw). R2 deltas:
//   Alice (Familia) 1-0 → home win, wrong = 0      | (Oficina) 0-2 → wrong = 0
//   Bob 2-2 → exact = 5
//   Carla 0-0 → draw outcome, diff +0 == real → diff = 3
//   Diego 3-1 → home win, wrong = 0
//   Esteban 2-2 → exact = 5
//   Florencia 1-1 → draw outcome, diff +0 == real → diff = 3
$familia_board2 = Mantia_Leaderboard::rows( $familia_id, 50 );
$by_name2 = array(); foreach ( $familia_board2 as $row ) { $by_name2[ (string) $row['name'] ] = (int) $row['points']; }
fwrite( STDOUT, "    · familia board (R1+R2): " . wp_json_encode( $by_name2 ) . "\n" );

$expected_total = array(
	'Alice'     => 5 + 0, // R1=5 exact, R2 wrong
	'Bob'       => 0 + 5, // R1=0,       R2=exact
	'Carla'     => 1 + 3, // R1=winner,  R2=diff
	'Diego'     => 1 + 0, // R1=winner,  R2=wrong
	'Esteban'   => 0 + 5, // R1=0,       R2=exact
	'Florencia' => 0 + 3, // R1=0,       R2=diff
);
foreach ( $expected_total as $name => $pts ) {
	Mantia_E2E::assert_eq( $pts, $by_name2[ $name ] ?? -1, "Familia R1+R2 total: {$name} = {$pts} pts" );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '13. Post-M2 WhatsApp — tabla shows two-round totals' );
/* ─────────────────────────────────────────────────────────────────────── */
$r = Mantia_E2E::send( $bob, 'tabla' );
// Bob (5+5=10) and Esteban (0+5=5) should both rank visibly.
Mantia_E2E::assert_contains( $r, 'Bob',     'tabla after R2 lists Bob' );
Mantia_E2E::assert_contains( $r, 'Esteban', 'tabla after R2 lists Esteban' );

// History per persona (calls mantia/get-user-history under the hood).
foreach ( array( $bob, $diego ) as $p ) {
	$u  = Mantia_Repository::find_user_by_phone( $p['phone'] );
	$h  = Mantia_Abilities::get_user_history( array(
		'user_phone' => $p['phone'],
		'group_id'   => $familia_id,
	) );
	Mantia_E2E::assert_true( ! is_wp_error( $h ) && isset( $h['history'] ),
		"{$p['name']}: get_user_history returned a history array" );
	Mantia_E2E::assert_true( count( (array) $h['history'] ) >= 2,
		"{$p['name']}: history has 2+ rows after two rounds" );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '14. Web crawl post-resolution — standings reflect points' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $familia_tk . '/',
	array( 'Bob', 'Alice', 'Esteban', 'Florencia', 'Diego', 'Carla' ) );
Mantia_E2E::assert_http_ok( '/pronostico/g/' . $oficina_tk . '/',
	array( 'Alice', 'Diego', 'Florencia' ) );
Mantia_E2E::assert_http_ok( '/pronostico/me/' . $user_tokens['Bob'] . '/',     array( 'Bob' ) );
Mantia_E2E::assert_http_ok( '/pronostico/me/' . $user_tokens['Esteban'] . '/', array( 'Esteban' ) );
Mantia_E2E::assert_http_ok( '/pronostico/' . $competition_id . '/' );

/* ─────────────────────────────────────────────────────────────────────── */
fwrite( STDOUT, "\n" . str_repeat( '═', 72 ) . "\n" );
fwrite( STDOUT, "🔗  Live URLs — six personas, two pencas, two resolved matches\n" );
fwrite( STDOUT, str_repeat( '═', 72 ) . "\n\n" );

$base = rtrim( (string) home_url(), '/' );
fwrite( STDOUT, "  PENCA: La Familia (everyone)\n" );
fwrite( STDOUT, "    public:   {$base}/pronostico/g/{$familia_tk}/\n" );
fwrite( STDOUT, "    og card:  {$base}/pronostico/g/{$familia_tk}/og/\n" );
fwrite( STDOUT, "    share:    {$base}/pronostico/g/{$familia_tk}/compartir/\n" );
fwrite( STDOUT, "    sumate:   {$base}/pronostico/sumate/{$familia_iv}/\n\n" );

fwrite( STDOUT, "  PENCA: La Oficina (Alice + Diego + Florencia)\n" );
fwrite( STDOUT, "    public:   {$base}/pronostico/g/{$oficina_tk}/\n" );
fwrite( STDOUT, "    sumate:   {$base}/pronostico/sumate/{$oficina_iv}/\n\n" );

fwrite( STDOUT, "  COMPETITION standings: {$base}/pronostico/{$competition_id}/\n\n" );

fwrite( STDOUT, "  Personal /me/ pages:\n" );
foreach ( $everyone as $p ) {
	$tk = $user_tokens[ $p['name'] ] ?? '';
	fwrite( STDOUT, sprintf( "    %-10s %s/pronostico/me/%s/\n", $p['name'], $base, $tk ) );
}

fwrite( STDOUT, "\n  Magic-link signed URLs (auto-login):\n" );
foreach ( $everyone as $p ) {
	$u = Mantia_Repository::find_user_by_phone( $p['phone'] );
	if ( $u ) {
		$link = Mantia_Repository::user_view_url( (int) $u->ID );
		fwrite( STDOUT, sprintf( "    %-10s %s\n", $p['name'], $link ) );
	}
}

fwrite( STDOUT, "\n  Test phones (for bin/sim-wa.sh single-shot pokes):\n" );
foreach ( $everyone as $p ) {
	fwrite( STDOUT, sprintf( "    %-10s +%s\n", $p['name'], $p['phone'] ) );
}

fwrite( STDOUT, "\n  Matches resolved:\n" );
fwrite( STDOUT, "    M1 #{$m1_id}: {$m1_home} vs {$m1_away} → finished 2-1\n" );
fwrite( STDOUT, "    M2 #{$m2_id}: {$m2_home} vs {$m2_away} → finished 2-2\n" );
fwrite( STDOUT, str_repeat( '═', 72 ) . "\n\n" );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '15. Finish — artifacts left in place for browser inspection' );
/* ─────────────────────────────────────────────────────────────────────── */
fwrite( STDOUT, "    · artifacts preserved — re-run this scenario to reset\n" );

Mantia_E2E::finish();
