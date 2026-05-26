<?php
/**
 * Mundial 2026 — FIFA fixture sync.
 *
 * The competition rows must exist after upgrade. The sync flow has to
 * handle three shapes: a real-looking FIFA Results payload, a no-team
 * placeholder (TBD knockout brackets), and an HTTP failure.
 *
 * We stub the FIFA response via the `mantia_fifa_fixture_response`
 * filter so the test runs deterministically without hitting api.fifa.com
 * — same trick a corporate-proxied dev env would use.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Mundial 2026 — FIFA fixture sync' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Setup: ensure Mundial competition rows exist' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_Competitions::seed_defaults();

$comps = array_keys( Mantia_Competitions::all() );
Mantia_E2E::assert_true( in_array( 'mundial-2026', $comps, true ), 'mundial-2026 registered' );
Mantia_E2E::assert_true( ! in_array( 'mundial-semana', $comps, true ), 'mundial-semana removed in v11' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Stub the FIFA Results payload — 3 matches, 1 finished, 1 TBD' );
/* ─────────────────────────────────────────────────────────────────────── */
$stubbed = array(
	'Results' => array(
		// A finished group-stage match.
		array(
			'IdMatch'     => 'WC26-001',
			'Date'        => '2026-06-11T20:00:00Z',
			'MatchStatus' => 3, // finished
			'Home'        => array(
				'TeamName' => array( array( 'Description' => 'Mexico' ) ),
				'Score'    => 2,
			),
			'Away'        => array(
				'TeamName' => array( array( 'Description' => 'Croatia' ) ),
				'Score'    => 1,
			),
			'StageName'   => array( array( 'Description' => 'Group A · Match 1' ) ),
		),
		// A scheduled match.
		array(
			'IdMatch'     => 'WC26-002',
			'Date'        => '2026-06-12T18:00:00Z',
			'MatchStatus' => 0,
			'Home'        => array( 'TeamName' => array( array( 'Description' => 'Argentina' ) ) ),
			'Away'        => array( 'TeamName' => array( array( 'Description' => 'France' ) ) ),
			'StageName'   => array( array( 'Description' => 'Group B · Match 1' ) ),
		),
		// A TBD knockout bracket — should be skipped, not crashed on.
		array(
			'IdMatch'     => 'WC26-RO16-T1',
			'Date'        => '2026-06-29T20:00:00Z',
			'MatchStatus' => 0,
			'Home'        => array( 'TeamName' => array() ),
			'Away'        => array( 'TeamName' => array() ),
			'StageName'   => array( array( 'Description' => 'Round of 16' ) ),
		),
	),
);
add_filter(
	'mantia_fifa_fixture_response',
	static fn() => $stubbed,
	99,
	0
);

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Run Mantia_Fifa_Fixture::sync — upserts the 2 valid, skips the TBD' );
/* ─────────────────────────────────────────────────────────────────────── */
$result = Mantia_Fifa_Fixture::sync( 'mundial-2026' );
Mantia_E2E::assert_true( ! is_wp_error( $result ), 'sync returned ok' );
Mantia_E2E::assert_eq( 3, $result['fetched'] ?? -1, '3 records fetched from stub' );
Mantia_E2E::assert_eq( 1, $result['skipped'] ?? -1, '1 TBD bracket skipped' );
Mantia_E2E::assert_eq( 2, $result['count']   ?? -1, '2 matches written (insert + update sum)' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Re-run sync — same payload, all UPDATES (no duplicates)' );
/* ─────────────────────────────────────────────────────────────────────── */
$result2 = Mantia_Fifa_Fixture::sync( 'mundial-2026' );
Mantia_E2E::assert_eq( 0, $result2['inserted'] ?? -1, 'second sync inserts nothing' );
Mantia_E2E::assert_eq( 2, $result2['updated']  ?? -1, 'second sync updates the same 2 rows' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. Stored matches look right' );
/* ─────────────────────────────────────────────────────────────────────── */
$mexico = Mantia_Repository::find_match_by_external_id( 'fifa-WC26-001' );
Mantia_E2E::assert_true( null !== $mexico, 'Mexico-Croatia upserted with fifa- prefix' );
if ( $mexico ) {
	Mantia_E2E::assert_eq( 'Mexico',   (string) get_post_meta( $mexico->ID, Mantia_Repository::META_HOME_TEAM, true ), 'home_team stored' );
	Mantia_E2E::assert_eq( 'Croatia',  (string) get_post_meta( $mexico->ID, Mantia_Repository::META_AWAY_TEAM, true ), 'away_team stored' );
	Mantia_E2E::assert_eq( 'finished', (string) get_post_meta( $mexico->ID, Mantia_Repository::META_STATUS,    true ), 'status mapped to finished' );
	Mantia_E2E::assert_eq( 2,          (int)    get_post_meta( $mexico->ID, Mantia_Repository::META_HOME_SCORE, true ), 'home_score persisted' );
	Mantia_E2E::assert_eq( 1,          (int)    get_post_meta( $mexico->ID, Mantia_Repository::META_AWAY_SCORE, true ), 'away_score persisted' );
	Mantia_E2E::assert_eq( 'mundial-2026', (string) get_post_meta( $mexico->ID, Mantia_Competitions::META_KEY, true ), 'competition_id bound' );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. mundial-2026 upcoming-matches lookup picks up the FIFA stub' );
/* ─────────────────────────────────────────────────────────────────────── */
// Argentina-France is in 2026 so it shouldn't show in a 7-day window from
// now — open the window wide (2 years) to verify the data path works.
$mundial_matches = Mantia_Repository::upcoming_matches_for_competition( 'mundial-2026', 24 * 365 * 2 );
$mundial_ids     = array_map( static fn( $m ) => (int) $m['id'], $mundial_matches );
Mantia_E2E::assert_true( ! empty( $mundial_ids ), 'mundial-2026 has ≥1 upcoming match after sync' );

$arg_fra = Mantia_Repository::find_match_by_external_id( 'fifa-WC26-002' );
Mantia_E2E::assert_true( null !== $arg_fra && in_array( (int) $arg_fra->ID, $mundial_ids, true ), 'Argentina-France match is among upcoming' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '6. Unknown competition rejects with mantia_fifa_unsupported' );
/* ─────────────────────────────────────────────────────────────────────── */
$bad = Mantia_Fifa_Fixture::sync( 'sudamericana-2099' );
Mantia_E2E::assert_true( is_wp_error( $bad ), 'unknown competition rejected' );
Mantia_E2E::assert_eq( 'mantia_fifa_unsupported', is_wp_error( $bad ) ? $bad->get_error_code() : '', 'error code matches' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '7. The /pronostico/mundial-2026/ web route renders' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::assert_http_ok( '/pronostico/mundial-2026/', array( 'Mundial 2026' ) );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '8. The ability mantia/sync-fifa-fixture is registered' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::assert_not_null( wp_get_ability( 'mantia/sync-fifa-fixture' ), 'sync ability registered' );

// Cleanup: remove the stub filter and the test matches.
remove_all_filters( 'mantia_fifa_fixture_response', 99 );
foreach ( array( 'fifa-WC26-001', 'fifa-WC26-002' ) as $ext ) {
	$p = Mantia_Repository::find_match_by_external_id( $ext );
	if ( $p ) {
		wp_delete_post( (int) $p->ID, true );
	}
}

Mantia_E2E::finish();
