<?php
/**
 * External result lookup adapter.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Results_Fetcher {

	public static function fetch_match_result( int $match_id ): array|WP_Error {
		$match = Mantia_Repository::match_to_array( $match_id );
		if ( empty( $match ) ) {
			return new WP_Error( 'mantia_match_not_found', __( 'No encuentro ese partido.', 'mantia' ) );
		}

		$filtered = apply_filters( 'mantia_fetch_match_result', null, $match );
		if ( is_array( $filtered ) ) {
			return self::normalize_result( $filtered );
		}
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}

		if ( 'finished' === $match['status'] && null !== $match['home_score'] && null !== $match['away_score'] ) {
			return array(
				'match_id'   => $match_id,
				'home_score' => (int) $match['home_score'],
				'away_score' => (int) $match['away_score'],
				'status'     => 'finished',
				'source'     => 'match_meta',
			);
		}

		return new WP_Error(
			'mantia_result_unavailable',
			__( 'Todavia no tengo resultado final para ese partido.', 'mantia' )
		);
	}

	private static function normalize_result( array $result ): array|WP_Error {
		if ( ! isset( $result['home_score'], $result['away_score'] ) ) {
			return new WP_Error( 'mantia_result_invalid', __( 'El resultado externo no trajo marcador completo.', 'mantia' ) );
		}

		return array(
			'match_id'   => isset( $result['match_id'] ) ? (int) $result['match_id'] : 0,
			'home_score' => (int) $result['home_score'],
			'away_score' => (int) $result['away_score'],
			'status'     => (string) ( $result['status'] ?? 'finished' ),
			'source'     => (string) ( $result['source'] ?? 'filter' ),
		);
	}
}
