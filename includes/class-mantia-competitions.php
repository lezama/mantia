<?php
/**
 * Competition registry (CPT-backed).
 *
 * Each penca has a competition scope: Mundial 2026, Libertadores 2026,
 * LigaUY 2026, or a "view" into another competition like "Libertadores —
 * esta semana" (which is just the parent's matches inside a 7-day window).
 *
 * Storage: a `mantia_competition` CPT. The post slug (post_name) is the
 * stable identifier matches and groups reference. `post_parent` models
 * views over a parent competition. Window/emoji live in post_meta.
 *
 * Defaults are seeded on plugin activation; admins can edit them in
 * wp-admin → Mantia Competitions, and create their own.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Competitions {

	/** Post meta key that matches/groups use to reference a competition by slug. */
	public const META_KEY = '_mantia_competition_id';

	public const META_EMOJI       = '_mantia_competition_emoji';
	public const META_WINDOW_DAYS = '_mantia_competition_window_days';
	public const META_IS_DEFAULT  = '_mantia_competition_is_default';
	public const META_SORT_ORDER  = '_mantia_competition_sort_order';

	/**
	 * Built-in seed list. Materialized into the CPT on activation. Admins can
	 * edit/delete/add after seeding; this list is only used to bootstrap.
	 */
	public static function default_seed(): array {
		return array(
			array(
				'slug'        => 'mundial-2026',
				'name'        => 'Mundial 2026',
				'emoji'       => '🏆',
				'description' => 'Copa del Mundo FIFA',
				'is_default'  => true,
				'sort'        => 10,
			),
			array(
				'slug'        => 'libertadores-2026',
				'name'        => 'Libertadores 2026',
				'emoji'       => '🥇',
				'description' => 'Copa Libertadores — torneo completo',
				'sort'        => 20,
			),
			array(
				'slug'        => 'libertadores-semana',
				'name'        => 'Libertadores — esta semana',
				'emoji'       => '📆',
				'description' => 'Solo partidos de Libertadores en los próximos 7 días',
				'parent_slug' => 'libertadores-2026',
				'window_days' => 7,
				'sort'        => 21,
			),
			array(
				'slug'        => 'sudamericana-2026',
				'name'        => 'Sudamericana 2026',
				'emoji'       => '🥈',
				'description' => 'Copa Sudamericana — torneo completo',
				'sort'        => 30,
			),
			array(
				'slug'        => 'liga-uy-2026',
				'name'        => 'LigaUY 2026',
				'emoji'       => '🇺🇾',
				'description' => 'Campeonato Uruguayo',
				'sort'        => 40,
			),
			array(
				'slug'        => 'custom',
				'name'        => 'Otra / Personalizada',
				'emoji'       => '⚽',
				'description' => 'Sin fixture preestablecido — cargás los partidos a mano',
				'sort'        => 100,
			),
		);
	}

	/**
	 * @return array<string, array{
	 *   id:string, name:string, emoji:string, description:string,
	 *   parent_id?:string, window_days?:int, default?:bool, sort?:int,
	 * }>
	 */
	public static function all(): array {
		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::COMPETITION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		$out = array();
		foreach ( $posts as $p ) {
			$slug = $p->post_name;
			$out[ $slug ] = self::post_to_array( $p );
		}
		return $out;
	}

	public static function get( string $slug ): ?array {
		$post = self::find_post( $slug );
		return $post ? self::post_to_array( $post ) : null;
	}

	public static function default_id(): string {
		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::COMPETITION,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => self::META_IS_DEFAULT,
				'meta_value'     => '1',
				'no_found_rows'  => true,
			)
		);
		if ( ! empty( $posts ) ) {
			return $posts[0]->post_name;
		}
		// Fallback: first competition by sort order.
		$first = get_posts(
			array(
				'post_type'      => Mantia_CPTs::COMPETITION,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return ! empty( $first ) ? $first[0]->post_name : 'custom';
	}

	public static function label( string $slug ): string {
		$c = self::get( $slug );
		if ( ! $c ) {
			return $slug;
		}
		return trim( ( $c['emoji'] ?? '' ) . ' ' . $c['name'] );
	}

	public static function storage_id( string $slug ): string {
		$c = self::get( $slug );
		return $c && ! empty( $c['parent_id'] ) ? (string) $c['parent_id'] : $slug;
	}

	public static function window_days( string $slug ): int {
		$c = self::get( $slug );
		return $c && isset( $c['window_days'] ) ? (int) $c['window_days'] : 0;
	}

	/**
	 * Insert any defaults that don't already exist as posts. Idempotent —
	 * safe to call on every activation. Does not overwrite admin edits.
	 */
	public static function seed_defaults(): void {
		// First pass: create parents.
		foreach ( self::default_seed() as $row ) {
			if ( ! empty( $row['parent_slug'] ) ) {
				continue;
			}
			self::ensure_post( $row );
		}
		// Second pass: views with parents.
		foreach ( self::default_seed() as $row ) {
			if ( empty( $row['parent_slug'] ) ) {
				continue;
			}
			self::ensure_post( $row );
		}
	}

	private static function ensure_post( array $row ): int {
		$existing = self::find_post( (string) $row['slug'] );
		if ( $existing ) {
			return (int) $existing->ID;
		}

		$parent_id = 0;
		if ( ! empty( $row['parent_slug'] ) ) {
			$parent = self::find_post( (string) $row['parent_slug'] );
			$parent_id = $parent ? (int) $parent->ID : 0;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => Mantia_CPTs::COMPETITION,
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( (string) $row['name'] ),
				'post_name'    => sanitize_title( (string) $row['slug'] ),
				'post_excerpt' => (string) ( $row['description'] ?? '' ),
				'post_parent'  => $parent_id,
				'menu_order'   => (int) ( $row['sort'] ?? 0 ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		if ( ! empty( $row['emoji'] ) ) {
			update_post_meta( (int) $post_id, self::META_EMOJI, (string) $row['emoji'] );
		}
		if ( ! empty( $row['window_days'] ) ) {
			update_post_meta( (int) $post_id, self::META_WINDOW_DAYS, (int) $row['window_days'] );
		}
		if ( ! empty( $row['is_default'] ) ) {
			update_post_meta( (int) $post_id, self::META_IS_DEFAULT, 1 );
		}
		if ( isset( $row['sort'] ) ) {
			update_post_meta( (int) $post_id, self::META_SORT_ORDER, (int) $row['sort'] );
		}

		return (int) $post_id;
	}

	private static function find_post( string $slug ): ?WP_Post {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => Mantia_CPTs::COMPETITION,
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $posts[0] ?? null;
	}

	private static function post_to_array( WP_Post $post ): array {
		$parent_slug = '';
		if ( $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent && Mantia_CPTs::COMPETITION === $parent->post_type ) {
				$parent_slug = $parent->post_name;
			}
		}

		$data = array(
			'id'          => $post->post_name,
			'name'        => $post->post_title,
			'emoji'       => (string) get_post_meta( $post->ID, self::META_EMOJI, true ),
			'description' => (string) $post->post_excerpt,
		);
		if ( '' !== $parent_slug ) {
			$data['parent_id'] = $parent_slug;
		}
		$window = (int) get_post_meta( $post->ID, self::META_WINDOW_DAYS, true );
		if ( $window > 0 ) {
			$data['window_days'] = $window;
		}
		if ( (int) get_post_meta( $post->ID, self::META_IS_DEFAULT, true ) === 1 ) {
			$data['default'] = true;
		}
		return $data;
	}
}
