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
	 * Bump when a deploy needs the seed-defaults migration to re-run
	 * (currently used to self-heal placeholder competition descriptions
	 * without forcing a plugin re-activation on prod).
	 */
	private const DB_VERSION        = 2;
	private const DB_VERSION_OPTION = 'mantia_db_version';

	public static function maybe_run_upgrade(): void {
		$current = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $current >= self::DB_VERSION ) {
			return;
		}
		Mantia_Competitions::seed_defaults();
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
