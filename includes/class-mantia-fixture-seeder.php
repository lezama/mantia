<?php
/**
 * Fixture seeding.
 *
 * On activation we walk `tools/seed-*.json` and upsert each match. Each file
 * describes one competition (mundial, libertadores, sudamericana, liga-uy,
 * etc.); the per-match `competition_id` lands in match post_meta so the
 * public web views and the WhatsApp flows route correctly.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Fixture_Seeder {

	private const SEED_GLOB = 'tools/seed-*.json';

	/**
	 * @return array<int,int> List of upserted match post IDs.
	 */
	public static function seed(): array {
		Mantia_Repository::default_group_id();

		$ids     = array();
		$sources = array();
		foreach ( self::files() as $path ) {
			$file_ids = self::seed_from_file( $path );
			if ( ! empty( $file_ids ) ) {
				$ids       = array_merge( $ids, $file_ids );
				$sources[] = basename( $path );
			}
		}

		if ( ! empty( $sources ) ) {
			update_option( 'mantia_fixture_seeded_at', time() );
			update_option( 'mantia_fixture_seed_sources', $sources );
		}

		return $ids;
	}

	/**
	 * @return array<int,string> Absolute paths of seed-*.json files in tools/.
	 */
	private static function files(): array {
		$pattern = MANTIA_PATH . self::SEED_GLOB;
		$paths   = glob( $pattern );
		return is_array( $paths ) ? $paths : array();
	}

	/**
	 * @return array<int,int>
	 */
	private static function seed_from_file( string $path ): array {
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['matches'] ) || ! is_array( $decoded['matches'] ) ) {
			return array();
		}

		$file_competition = isset( $decoded['competition_id'] ) ? (string) $decoded['competition_id'] : '';

		$ids = array();
		foreach ( $decoded['matches'] as $match ) {
			if ( ! is_array( $match ) ) {
				continue;
			}
			if ( '' !== $file_competition && empty( $match['competition_id'] ) ) {
				$match['competition_id'] = $file_competition;
			}
			$id = Mantia_Repository::upsert_match( $match );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
