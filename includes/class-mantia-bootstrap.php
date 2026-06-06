<?php
/**
 * Plugin bootstrap.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Bootstrap {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// The WhatsApp identity bridge was extracted to a standalone plugin
		// (github.com/lezama/wa-identity-bridge). We don't load classes
		// in-tree any more — the standalone plugin owns the source. If it
		// isn't installed/activated, fall back to limited mode: CPTs and
		// public blocks still work, but the conversational flow that
		// depends on signed magic-links is disabled.
		if ( ! class_exists( 'WA_Identity_Bridge' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_dependency_notice' ) );
			return;
		}

		// Mantia branding for the bridge: dedicated role, /pronostico/auth/ endpoint,
		// /pronostico/* path whitelist (anti open-redirect), and a placeholder email
		// domain derived from the site host (default would be `wa.<host>`
		// anyway — pin it explicitly so it survives a hypothetical host change).
		add_filter( 'wa_identity_bridge_role_slug',           static fn (): string => 'mantia_player' );
		add_filter( 'wa_identity_bridge_endpoint_path',       static fn (): string => 'pronostico/auth' );
		add_filter( 'wa_identity_bridge_path_whitelist',      static fn (): array => array( '/pronostico/' ) );
		add_filter( 'wa_identity_bridge_expired_redirect_url', static fn (): string => home_url( '/pronostico/expired/' ) );

		WA_Identity_Bridge::boot();

		add_action( 'init', array( 'Mantia_CPTs', 'register' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_blocks' ), 20 );
		add_action( 'init', array( __CLASS__, 'maybe_run_upgrade' ), 25 );
		add_action( 'admin_notices', array( __CLASS__, 'render_dependency_notice' ) );

		add_filter( 'openclawp_register_whatsapp', '__return_true' );

		Mantia_Abilities::register();
		Mantia_Agent::register();
		Mantia_Whatsapp_Flow::register();
		Mantia_Workflows::register();
		Mantia_Frontend::register();
		Mantia_Rest::register();
		if ( is_admin() ) {
			Mantia_Competitions::register_admin();
		}
	}

	public static function activate(): void {
		Mantia_CPTs::register();
		Mantia_Competitions::seed_defaults();
		Mantia_Fixture_Seeder::seed();
		flush_rewrite_rules();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Bump when a deploy needs to re-run seed migrations on existing
	 * installs (placeholder copy heal, competition renames that need to
	 * land without a plugin re-activation, etc.).
	 */
	private const DB_VERSION        = 13;
	private const DB_VERSION_OPTION = 'mantia_db_version';

	public static function maybe_run_upgrade(): void {
		$current = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $current >= self::DB_VERSION ) {
			return;
		}

		// v2: re-run seed_defaults so ensure_post() heals placeholder copy.
		if ( $current < 2 ) {
			Mantia_Competitions::seed_defaults();
		}

		// v3: rename "Libertadores — esta semana" → "Libertadores semanal".
		// WhatsApp Interactive List rows truncate post_title at 24 chars
		// and the original (27 chars) was rendering as "Libertadores — esta s".
		if ( $current < 3 ) {
			$post = Mantia_Competitions::find_post( 'libertadores-semana' );
			if ( $post && 'Libertadores — esta semana' === (string) $post->post_title ) {
				wp_update_post(
					array(
						'ID'         => (int) $post->ID,
						'post_title' => 'Libertadores semanal',
					)
				);
			}
		}

		// v5: PWA rewrite regexes added optional trailing slash. Flush so
		// the new patterns take effect without re-activating the plugin.
		// v6: WA_Identity_Bridge added /pronostico/auth/ endpoint — same flush
		// covers both.
		// v7: URL prefix migrated /penca/* → /pronostico/* (rewrite rules
		// changed, no backward-compatible redirects). Flush so installs
		// upgrading from <=v6 stop trying to serve the old paths.
		// v8: added /pronostico/g/<token>/calendar.ics subscribable feed —
		// new rewrite rule needs a flush to register on existing installs.
		// v10: WA_Identity_Bridge moved from in-tree to standalone plugin.
		// The endpoint now registers from the wa-identity-bridge plugin's
		// own init hook — flush so existing installs stop serving the
		// stale in-tree rewrite (and so the new one takes effect even if
		// the standalone plugin activated AFTER Mantia did this cycle).
		// v12: web-first create routes (/pronostico/crear[/competition]/).
		if ( $current < 12 ) {
			flush_rewrite_rules();
		}

		// v9: Mundial 2026 reinstated. Seed the competition row
		// (mundial-2026) and pull the fixture from FIFA's official
		// endpoint. The mundial-semana weekly sub-view was also seeded
		// here originally but was removed in v11. The pull is best-
		// effort — if the network is
		// down at upgrade time, the competition still exists and the
		// admin can re-trigger via wp eval-file or the sync-fifa-fixture
		// ability.
		if ( $current < 9 ) {
			Mantia_Competitions::seed_defaults();
			if ( class_exists( 'Mantia_Fifa_Fixture' ) ) {
				Mantia_Fifa_Fixture::sync( 'mundial-2026' );
			}
		}

		// v11: `mundial-semana` removed from default_seed() (weekly view
		// was confusing UX — sat empty most of the year). The v4 loop
		// below only fires for installs at < 4, so installs at 4–10 need
		// a separate pass to actually clear the slug. Same idempotent
		// iteration over REMOVED_COMPETITION_SLUGS — finds nothing on
		// fresh installs, finds mundial-semana on upgrading installs.
		if ( $current < 11 ) {
			foreach ( Mantia_Competitions::REMOVED_COMPETITION_SLUGS as $slug ) {
				$comp = Mantia_Competitions::find_post( $slug );
				if ( ! $comp ) {
					continue;
				}
				$match_ids = get_posts(
					array(
						'post_type'      => Mantia_CPTs::MATCH,
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => Mantia_Competitions::META_KEY,
						'meta_value'     => $slug,
					)
				);
				foreach ( $match_ids as $mid ) {
					wp_delete_post( (int) $mid, true );
				}
				wp_delete_post( (int) $comp->ID, true );
			}
		}

		// v13: Mantia collapsed to mundial-only. libertadores-2026,
		// libertadores-semana, brasileirao-prueba (legacy test fixture)
		// were just added to REMOVED_COMPETITION_SLUGS. The v11 loop
		// only fires for installs at < 11 — installs at 11+ need a
		// separate idempotent pass to actually clear the new entries.
		// Same delete shape as v11; fresh installs hit nothing.
		if ( $current < 13 ) {
			foreach ( Mantia_Competitions::REMOVED_COMPETITION_SLUGS as $slug ) {
				$comp = Mantia_Competitions::find_post( $slug );
				if ( ! $comp ) {
					continue;
				}
				$match_ids = get_posts(
					array(
						'post_type'      => Mantia_CPTs::MATCH,
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => Mantia_Competitions::META_KEY,
						'meta_value'     => $slug,
					)
				);
				foreach ( $match_ids as $mid ) {
					wp_delete_post( (int) $mid, true );
				}
				wp_delete_post( (int) $comp->ID, true );
			}
		}

		// v4: drop competitions removed from default_seed() (Mundial,
		// Sudamericana, LigaUY, Esta semana, Otra/Personalizada) plus the
		// match posts that referenced them. Bot pickers were surfacing
		// dead options.
		if ( $current < 4 ) {
			foreach ( Mantia_Competitions::REMOVED_COMPETITION_SLUGS as $slug ) {
				$comp = Mantia_Competitions::find_post( $slug );
				if ( ! $comp ) {
					continue;
				}
				$match_ids = get_posts(
					array(
						'post_type'      => Mantia_CPTs::MATCH,
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => Mantia_Competitions::META_KEY,
						'meta_value'     => $slug,
					)
				);
				foreach ( $match_ids as $mid ) {
					wp_delete_post( (int) $mid, true );
				}
				wp_delete_post( (int) $comp->ID, true );
			}
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type( MANTIA_PATH . 'blocks/standings' );
		register_block_type( MANTIA_PATH . 'blocks/group-standings' );
	}

	public static function render_dependency_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$missing = array();
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$missing[] = 'WordPress Abilities API';
		}
		if ( ! function_exists( 'wp_register_agent' ) ) {
			$missing[] = 'Automattic/agents-api';
		}
		if ( ! class_exists( 'OpenclaWP_Bootstrap' ) ) {
			$missing[] = 'openclaWP';
		}
		if ( ! class_exists( 'WA_Identity_Bridge' ) ) {
			$missing[] = 'wa-identity-bridge';
		}

		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Mantia is running in limited mode.', 'mantia' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated dependency names. */
					__( 'Missing conversational dependencies: %s. CPTs and public blocks still work.', 'mantia' ),
					implode( ', ', $missing )
				)
			)
		);
	}
}
