<?php
/**
 * Fixture seeding.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Fixture_Seeder {

	public static function seed(): array {
		Mantia_Repository::default_group_id();

		$path = MANTIA_PATH . 'tools/seed-2026.json';
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['matches'] ) || ! is_array( $decoded['matches'] ) ) {
			return array();
		}

		$ids = array();
		foreach ( $decoded['matches'] as $match ) {
			if ( ! is_array( $match ) ) {
				continue;
			}
			$id = Mantia_Repository::upsert_match( $match );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		update_option( 'mantia_mundial_fixture_seeded_at', time() );
		update_option( 'mantia_mundial_fixture_seed_source', sanitize_text_field( (string) ( $decoded['source'] ?? 'unknown' ) ) );

		return $ids;
	}
}
