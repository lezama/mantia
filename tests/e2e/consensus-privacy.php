<?php
/**
 * Consensus is post-kickoff only. The view exists, but never leaks
 * predictions before the whistle. This scenario asserts both halves:
 *   - Pre-kickoff: Repository::group_consensus_for_match returns [].
 *   - Post-kickoff: it returns the per-score tally.
 *
 * The bot command "consenso" inherits the same guard via the
 * Repository helper.
 *
 * @package Mantia\Tests\E2E
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Consensus respects the pre-kickoff privacy guard' );
Mantia_E2E::require_fixture_or_skip( 'mundial-2026' );

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob',   2 );

// Bootstrap a penca with two members, both predict.
Mantia_E2E::send( $alice, 'me llamo Alice' );
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::send( $alice, '__E2E__ Consenso' );

$alice_user = Mantia_Repository::find_user_by_phone( $alice['phone'] );
$alice_id   = (int) $alice_user->ID;
// Groups live on user_meta since Phase 6.
$group_ids  = (array) get_user_meta( $alice_id, Mantia_Repository::META_GROUP_IDS, true );
$group_id   = isset( $group_ids[0] ) ? (int) $group_ids[0] : 0;
$invite     = (string) get_post_meta( $group_id, Mantia_Repository::META_INVITE_CODE, true );

Mantia_E2E::send( $bob, 'me llamo Bob' );
Mantia_E2E::send( $bob, $invite );
$bob_id = (int) Mantia_Repository::find_user_by_phone( $bob['phone'] )->ID;

// Use the FIRST upcoming Mundial match — kickoff is definitely in the future
// since we only consider scheduled matches.
$upcoming = Mantia_Repository::upcoming_matches_for_competition( 'mundial-2026', 24 * 365 );
Mantia_E2E::assert_eq( true, ! empty( $upcoming ), 'fixture has upcoming Mundial matches' );

$match_id = (int) $upcoming[0]['id'];
Mantia_E2E::send( $alice, 'mantia:match:' . $match_id );
Mantia_E2E::send( $alice, '2-1' );
Mantia_E2E::send( $bob,   'mantia:match:' . $match_id );
Mantia_E2E::send( $bob,   '1-1' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '1. Pre-kickoff: consensus is hidden by the time guard' );
/* ------------------------------------------------------------------------ */
$pre = Mantia_Repository::group_consensus_for_match( $group_id, $match_id );
Mantia_E2E::assert_eq( array(), $pre, 'consensus is empty before kickoff' );

// The "consenso" bot command should also refuse — it falls back to the
// most-recently-finished match, which on a fresh fixture means none.
$r = Mantia_E2E::send( $alice, 'consenso' );
Mantia_E2E::assert_eq( true, isset( $r['reply'] ) && '' !== $r['reply'], 'consenso command replied' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '2. Post-kickoff: consensus reveals the per-score tally' );
/* ------------------------------------------------------------------------ */
// Backdate the kickoff so the time guard considers it done.
update_post_meta( $match_id, Mantia_Repository::META_KICKOFF_TS, time() - 60 );

$post = Mantia_Repository::group_consensus_for_match( $group_id, $match_id );
Mantia_E2E::assert_eq( 2, count( $post ), 'consensus shows two distinct picks' );
Mantia_E2E::assert_eq( 1, (int) ( $post['2-1'] ?? 0 ), 'one vote for 2-1' );
Mantia_E2E::assert_eq( 1, (int) ( $post['1-1'] ?? 0 ), 'one vote for 1-1' );

/* ------------------------------------------------------------------------ */
Mantia_E2E::step( '3. Cleanup' );
/* ------------------------------------------------------------------------ */
Mantia_E2E::cleanup();
Mantia_E2E::finish();
