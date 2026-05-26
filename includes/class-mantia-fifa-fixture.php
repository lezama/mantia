<?php
/**
 * FIFA fixture sync.
 *
 * Pulls the World Cup 2026 schedule (or results) from the official, but
 * undocumented, FIFA Data API and upserts each match into the Mantia
 * fixture. Same endpoint that powers fifa.com — stable for a decade,
 * sin auth, sin rate limits documentados.
 *
 * The competition+season IDs are stable per edition. For Mundial 2026:
 *   - idcompetition = 17  (FIFA World Cup)
 *   - idseason      = 285023  (2026 edition: US/CA/MX)
 *
 * Other tournaments use the same shape — set the constants per slug if
 * we ever add EURO / Copa America / etc.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Fifa_Fixture {

	private const FIFA_BASE = 'https://api.fifa.com/api/v3';

	/**
	 * idcompetition / idseason pairs we know how to sync. Keyed by the
	 * Mantia competition slug so callers can pass `'mundial-2026'` and we
	 * resolve the FIFA IDs internally.
	 */
	private const FIFA_IDS = array(
		'mundial-2026' => array( 'competition' => '17', 'season' => '285023' ),
	);

	/**
	 * Pull and upsert the fixture for one Mantia competition.
	 *
	 * Returns:
	 *   array{
	 *     competition_id: string, count: int, inserted: int, updated: int,
	 *     skipped: int, fetched: int, from: string, to: string
	 *   }
	 * or WP_Error.
	 */
	public static function sync( string $competition_id, int $days_ahead = 365 ): array|WP_Error {
		if ( ! isset( self::FIFA_IDS[ $competition_id ] ) ) {
			return new WP_Error(
				'mantia_fifa_unsupported',
				sprintf( 'No conozco los IDs de FIFA para %s.', $competition_id )
			);
		}
		$ids = self::FIFA_IDS[ $competition_id ];

		$from = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
		$to   = gmdate( 'Y-m-d', time() + max( 1, $days_ahead ) * DAY_IN_SECONDS );

		$url = sprintf(
			'%s/calendar/matches?idseason=%s&idcompetition=%s&from=%s&to=%s&count=500',
			self::FIFA_BASE,
			rawurlencode( $ids['season'] ),
			rawurlencode( $ids['competition'] ),
			rawurlencode( $from ),
			rawurlencode( $to )
		);

		// Allow tests / private installs to short-circuit with a stubbed
		// payload via the standard pre_http_request filter, OR via a
		// Mantia-specific filter that returns the parsed Results array.
		$override = apply_filters( 'mantia_fifa_fixture_response', null, $url, $competition_id );
		if ( is_array( $override ) ) {
			$payload = $override;
		} else {
			$resp = wp_remote_get( $url, array(
				'timeout' => 20,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Mantia/' . MANTIA_VERSION,
				),
			) );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( 200 !== $code ) {
				return new WP_Error( 'mantia_fifa_http', sprintf( 'FIFA API returned %d', $code ) );
			}
			$payload = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $payload ) ) {
				return new WP_Error( 'mantia_fifa_decode', 'FIFA API returned non-JSON' );
			}
		}

		// The FIFA payload's match list lives under `Results` (uppercase R)
		// in the v3 shape. Tolerate both top-level array and {Results: [...]}.
		$matches = isset( $payload['Results'] ) && is_array( $payload['Results'] )
			? $payload['Results']
			: ( isset( $payload[0] ) ? $payload : array() );

		$inserted = 0;
		$updated  = 0;
		$skipped  = 0;
		foreach ( $matches as $raw ) {
			$normalized = self::normalize_match( $raw, $competition_id );
			if ( null === $normalized ) {
				$skipped++;
				continue;
			}
			$existing = Mantia_Repository::find_match_by_external_id( $normalized['external_id'] );
			$post_id  = Mantia_Repository::upsert_match( $normalized );
			if ( 0 === $post_id ) {
				$skipped++;
				continue;
			}
			$existing ? $updated++ : $inserted++;
		}

		return array(
			'competition_id' => $competition_id,
			'count'          => $inserted + $updated,
			'inserted'       => $inserted,
			'updated'        => $updated,
			'skipped'        => $skipped,
			'fetched'        => count( $matches ),
			'from'           => $from,
			'to'             => $to,
		);
	}

	/**
	 * Map a single FIFA match record into the shape Mantia_Repository
	 * understands. Returns null for entries we can't parse (TBD knockout
	 * brackets pre-group-stage, etc.) so the caller can count them as
	 * skipped without abortng the whole sync.
	 *
	 * The FIFA v3 shape uses keys like:
	 *   IdMatch, IdSeason, IdCompetition, IdStage, MatchNumber,
	 *   Date (ISO8601 UTC), Home {IdTeam, TeamName[{Description}]},
	 *   Away {...}, Score, MatchStatus (0=Scheduled, 1=Live, 3=Finished)
	 */
	private static function normalize_match( array $raw, string $competition_id ): ?array {
		$id_match = (string) ( $raw['IdMatch'] ?? '' );
		if ( '' === $id_match ) {
			return null;
		}
		$home = self::team_name( $raw['Home'] ?? null );
		$away = self::team_name( $raw['Away'] ?? null );
		if ( '' === $home || '' === $away ) {
			// TBD bracket placeholder — skip until FIFA fills it in.
			return null;
		}

		$kickoff_gmt = self::parse_date( (string) ( $raw['Date'] ?? '' ) );
		$status_int  = isset( $raw['MatchStatus'] ) ? (int) $raw['MatchStatus'] : 0;
		$status      = self::map_status( $status_int );

		$home_score = isset( $raw['HomeTeamScore'] ) ? (int) $raw['HomeTeamScore'] : null;
		$away_score = isset( $raw['AwayTeamScore'] ) ? (int) $raw['AwayTeamScore'] : null;
		// Some payloads nest under Home.Score / Away.Score.
		if ( null === $home_score && isset( $raw['Home']['Score'] ) && null !== $raw['Home']['Score'] ) {
			$home_score = (int) $raw['Home']['Score'];
		}
		if ( null === $away_score && isset( $raw['Away']['Score'] ) && null !== $raw['Away']['Score'] ) {
			$away_score = (int) $raw['Away']['Score'];
		}

		return array(
			'external_id'    => 'fifa-' . $id_match,
			'home_team'      => $home,
			'away_team'      => $away,
			'kickoff_gmt'    => $kickoff_gmt,
			'phase'          => self::phase_label( $raw ),
			'status'         => $status,
			'home_score'     => 'finished' === $status ? (int) ( $home_score ?? 0 ) : null,
			'away_score'     => 'finished' === $status ? (int) ( $away_score ?? 0 ) : null,
			'competition_id' => $competition_id,
		);
	}

	private static function team_name( $node ): string {
		if ( ! is_array( $node ) ) {
			return '';
		}
		// FIFA returns TeamName as an array of localized strings; we take
		// the first one (typically English).
		if ( isset( $node['TeamName'] ) && is_array( $node['TeamName'] ) ) {
			foreach ( $node['TeamName'] as $loc ) {
				$desc = (string) ( $loc['Description'] ?? '' );
				if ( '' !== $desc ) {
					return $desc;
				}
			}
		}
		// Fallback: direct Description on the node.
		return (string) ( $node['Description'] ?? $node['Name'] ?? '' );
	}

	private static function phase_label( array $raw ): string {
		// StageName follows the same localized-array pattern as TeamName.
		if ( isset( $raw['StageName'] ) && is_array( $raw['StageName'] ) ) {
			foreach ( $raw['StageName'] as $loc ) {
				$desc = (string) ( $loc['Description'] ?? '' );
				if ( '' !== $desc ) {
					return $desc;
				}
			}
		}
		return '';
	}

	private static function map_status( int $code ): string {
		// FIFA status codes: 0=Scheduled, 1=Live, 2=Postponed, 3=Finished.
		// Anything else we treat as scheduled so the bot keeps offering it.
		return 3 === $code ? 'finished' : 'scheduled';
	}

	private static function parse_date( string $iso ): string {
		if ( '' === $iso ) {
			return '';
		}
		$ts = strtotime( $iso );
		return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', $ts );
	}
}
