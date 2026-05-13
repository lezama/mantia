<?php
/**
 * Prediction scoring.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Scoring {

	public static function rules(): array {
		$rules = array(
			'exact'           => 5,
			'goal_difference' => 3,
			'outcome'         => 1,
			'miss'            => 0,
		);

		return (array) apply_filters( 'mantia_scoring_rules', $rules );
	}

	public static function score_prediction( int $predicted_home, int $predicted_away, int $real_home, int $real_away ): array {
		$rules = self::rules();

		if ( $predicted_home === $real_home && $predicted_away === $real_away ) {
			return array(
				'points' => (int) ( $rules['exact'] ?? 5 ),
				'reason' => 'exact',
			);
		}

		$predicted_diff = $predicted_home - $predicted_away;
		$real_diff      = $real_home - $real_away;

		if ( $predicted_diff === $real_diff ) {
			return array(
				'points' => (int) ( $rules['goal_difference'] ?? 3 ),
				'reason' => 'goal_difference',
			);
		}

		if ( self::outcome( $predicted_diff ) === self::outcome( $real_diff ) ) {
			return array(
				'points' => (int) ( $rules['outcome'] ?? 1 ),
				'reason' => 'outcome',
			);
		}

		return array(
			'points' => (int) ( $rules['miss'] ?? 0 ),
			'reason' => 'miss',
		);
	}

	private static function outcome( int $diff ): string {
		if ( $diff > 0 ) {
			return 'home';
		}
		if ( $diff < 0 ) {
			return 'away';
		}
		return 'draw';
	}
}
