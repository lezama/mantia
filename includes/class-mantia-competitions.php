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
	public const META_ALIASES     = '_mantia_competition_aliases';

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
				'aliases'     => array( 'mundial', 'world cup', 'copa del mundo', 'fifa' ),
			),
			array(
				'slug'        => 'libertadores-2026',
				'name'        => 'Libertadores 2026',
				'emoji'       => '🥇',
				'description' => 'Copa Libertadores — torneo completo',
				'sort'        => 20,
				'aliases'     => array( 'libertadores', 'copa libertadores', 'libertadores completa' ),
			),
			array(
				'slug'        => 'libertadores-semana',
				'name'        => 'Libertadores — esta semana',
				'emoji'       => '📆',
				'description' => 'Solo partidos de Libertadores en los próximos 7 días',
				'parent_slug' => 'libertadores-2026',
				'window_days' => 7,
				'sort'        => 21,
				'aliases'     => array( 'libertadores semana', 'libertadores esta semana', 'libertadores semanal' ),
			),
			array(
				'slug'        => 'sudamericana-2026',
				'name'        => 'Sudamericana 2026',
				'emoji'       => '🥈',
				'description' => 'Copa Sudamericana — torneo completo',
				'sort'        => 30,
				'aliases'     => array( 'sudamericana', 'copa sudamericana' ),
			),
			array(
				'slug'        => 'liga-uy-2026',
				'name'        => 'LigaUY 2026',
				'emoji'       => '🇺🇾',
				'description' => 'Campeonato Uruguayo',
				'sort'        => 40,
				'aliases'     => array( 'liga uy', 'liga uruguaya', 'liga uruguay', 'campeonato uruguayo', 'auf' ),
			),
			array(
				'slug'        => 'custom',
				'name'        => 'Otra / Personalizada',
				'emoji'       => '⚽',
				'description' => 'Sin fixture preestablecido — cargás los partidos a mano',
				'sort'        => 100,
				'aliases'     => array(),
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
			// Idempotently backfill aliases on existing seeded competitions
			// — admins can override later via the meta box, but on first
			// install we want every default to ship with its aliases populated.
			if ( isset( $row['aliases'] ) && '' === (string) get_post_meta( (int) $existing->ID, self::META_ALIASES, true ) ) {
				self::save_aliases( (int) $existing->ID, (array) $row['aliases'] );
			}
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
		if ( isset( $row['aliases'] ) ) {
			self::save_aliases( (int) $post_id, (array) $row['aliases'] );
		}

		return (int) $post_id;
	}

	/**
	 * Normalize and persist a list of aliases on a competition. Each alias is
	 * lowercased and remove_accents-ed at write time so the resolver can
	 * compare strpos() cleanly without re-doing the work on every turn.
	 *
	 * @param array<int,string> $aliases
	 */
	public static function save_aliases( int $post_id, array $aliases ): void {
		$clean = array();
		foreach ( $aliases as $alias ) {
			$a = trim( (string) $alias );
			if ( '' === $a ) {
				continue;
			}
			$a = function_exists( 'remove_accents' ) ? remove_accents( $a ) : $a;
			$a = strtolower( $a );
			if ( ! in_array( $a, $clean, true ) ) {
				$clean[] = $a;
			}
		}
		update_post_meta( $post_id, self::META_ALIASES, $clean );
	}

	/**
	 * Read the alias list for a competition (by slug). Returns an empty
	 * array if none configured. The resolver in Mantia_Whatsapp_Flow
	 * walks this list instead of a hardcoded constant.
	 *
	 * @return array<int,string>
	 */
	public static function aliases( string $slug ): array {
		$post = self::find_post( $slug );
		if ( ! $post ) {
			return array();
		}
		$stored = get_post_meta( (int) $post->ID, self::META_ALIASES, true );
		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
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
		$stored_aliases = get_post_meta( $post->ID, self::META_ALIASES, true );
		$data['aliases'] = is_array( $stored_aliases )
			? array_values( array_filter( array_map( 'strval', $stored_aliases ) ) )
			: array();
		return $data;
	}

	/**
	 * Wire admin: a meta box on the competition edit screen so site owners
	 * can manage aliases (free-text matches) without touching code.
	 * Registered on plugin init via Mantia_Bootstrap.
	 */
	public static function register_admin(): void {
		add_action( 'add_meta_boxes_' . Mantia_CPTs::COMPETITION, array( __CLASS__, 'render_meta_boxes' ) );
		add_action( 'save_post_' . Mantia_CPTs::COMPETITION, array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	public static function render_meta_boxes( WP_Post $post ): void {
		add_meta_box(
			'mantia-competition-aliases',
			__( 'Aliases', 'mantia' ),
			array( __CLASS__, 'render_aliases_meta_box' ),
			Mantia_CPTs::COMPETITION,
			'normal',
			'default'
		);
		add_meta_box(
			'mantia-competition-config',
			__( 'Config', 'mantia' ),
			array( __CLASS__, 'render_config_meta_box' ),
			Mantia_CPTs::COMPETITION,
			'side',
			'default'
		);
	}

	public static function render_aliases_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'mantia_competition_meta', 'mantia_competition_meta_nonce' );
		$aliases = get_post_meta( $post->ID, self::META_ALIASES, true );
		$value   = is_array( $aliases ) ? implode( ', ', $aliases ) : '';
		?>
		<p>
			<label for="mantia-comp-aliases" style="display:block;margin-bottom:6px;">
				<?php esc_html_e( 'Free-text matches that route users to this competition. Separadas por coma. Lowercase, sin tildes.', 'mantia' ); ?>
			</label>
			<textarea id="mantia-comp-aliases" name="mantia_competition_aliases" rows="3" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $value ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'Ej: mundial, world cup, copa del mundo, fifa. Cualquier mensaje que contenga uno de estos términos routea acá.', 'mantia' ); ?>
		</p>
		<?php
	}

	public static function render_config_meta_box( WP_Post $post ): void {
		$emoji       = (string) get_post_meta( $post->ID, self::META_EMOJI, true );
		$window_days = (int) get_post_meta( $post->ID, self::META_WINDOW_DAYS, true );
		$is_default  = (int) get_post_meta( $post->ID, self::META_IS_DEFAULT, true ) === 1;
		?>
		<p>
			<label for="mantia-comp-emoji" style="display:block;"><?php esc_html_e( 'Emoji', 'mantia' ); ?></label>
			<input type="text" id="mantia-comp-emoji" name="mantia_competition_emoji" value="<?php echo esc_attr( $emoji ); ?>" class="widefat" maxlength="8">
		</p>
		<p>
			<label for="mantia-comp-window-days" style="display:block;"><?php esc_html_e( 'Window days', 'mantia' ); ?></label>
			<input type="number" id="mantia-comp-window-days" name="mantia_competition_window_days" value="<?php echo esc_attr( (string) $window_days ); ?>" class="widefat" min="0">
			<span class="description"><?php esc_html_e( '0 = sin ventana. >0 = vista filtrada por X días del padre.', 'mantia' ); ?></span>
		</p>
		<p>
			<label>
				<input type="checkbox" name="mantia_competition_is_default" value="1" <?php checked( $is_default ); ?>>
				<?php esc_html_e( 'Default competition', 'mantia' ); ?>
			</label>
		</p>
		<?php
	}

	public static function save_meta( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['mantia_competition_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mantia_competition_meta_nonce'] ) ), 'mantia_competition_meta' )
		) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['mantia_competition_aliases'] ) ) {
			$raw = sanitize_textarea_field( wp_unslash( (string) $_POST['mantia_competition_aliases'] ) );
			$arr = array_map( 'trim', preg_split( '/[,\n]+/', $raw ) ?: array() );
			self::save_aliases( $post_id, array_filter( $arr ) );
		}
		if ( isset( $_POST['mantia_competition_emoji'] ) ) {
			update_post_meta( $post_id, self::META_EMOJI, sanitize_text_field( wp_unslash( (string) $_POST['mantia_competition_emoji'] ) ) );
		}
		if ( isset( $_POST['mantia_competition_window_days'] ) ) {
			$w = max( 0, (int) wp_unslash( $_POST['mantia_competition_window_days'] ) );
			update_post_meta( $post_id, self::META_WINDOW_DAYS, $w );
		}
		// is_default is unique — if checked here, clear it on every other competition.
		if ( isset( $_POST['mantia_competition_is_default'] ) && '1' === (string) $_POST['mantia_competition_is_default'] ) {
			$others = get_posts(
				array(
					'post_type'      => Mantia_CPTs::COMPETITION,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $others as $oid ) {
				if ( (int) $oid === $post_id ) {
					continue;
				}
				delete_post_meta( (int) $oid, self::META_IS_DEFAULT );
			}
			update_post_meta( $post_id, self::META_IS_DEFAULT, 1 );
		} else {
			delete_post_meta( $post_id, self::META_IS_DEFAULT );
		}
	}
}
