<?php
/**
 * Custom post types.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_CPTs {

	public const MATCH       = 'mantia_match';
	public const PREDICTION  = 'mantia_prediction';
	public const GROUP       = 'mantia_group';
	public const COMPETITION = 'mantia_competition';

	public static function register(): void {
		self::register_match();
		self::register_prediction();
		self::register_group();
		self::register_competition();
	}

	private static function register_competition(): void {
		register_post_type(
			self::COMPETITION,
			array(
				'labels'              => array(
					'name'          => __( 'Mantia Competitions', 'mantia' ),
					'singular_name' => __( 'Mantia Competition', 'mantia' ),
				),
				'public'              => false,
				'show_ui'             => true,
				// REST disabled across all Mantia CPTs — privacy model depends
				// on tokens, and /wp-json/wp/v2/mantia_* would enumerate
				// predictions/users/groups/matches anonymously. Mantia exposes
				// what it needs via /wp-json/mantia/v1 with auth.
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'hierarchical'        => true,
				'menu_icon'           => 'dashicons-awards',
				'supports'            => array( 'title', 'editor', 'page-attributes', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	private static function register_match(): void {
		register_post_type(
			self::MATCH,
			array(
				'labels'              => array(
					'name'          => __( 'Mantia Matches', 'mantia' ),
					'singular_name' => __( 'Mantia Match', 'mantia' ),
				),
				'public'              => false,
				'show_ui'             => true,
				// REST disabled across all Mantia CPTs — privacy model depends
				// on tokens, and /wp-json/wp/v2/mantia_* would enumerate
				// predictions/users/groups/matches anonymously. Mantia exposes
				// what it needs via /wp-json/mantia/v1 with auth.
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'menu_icon'           => 'dashicons-calendar-alt',
				'supports'            => array( 'title', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	private static function register_prediction(): void {
		register_post_type(
			self::PREDICTION,
			array(
				'labels'              => array(
					'name'          => __( 'Mantia Predictions', 'mantia' ),
					'singular_name' => __( 'Mantia Prediction', 'mantia' ),
				),
				'public'              => false,
				'show_ui'             => true,
				// REST disabled across all Mantia CPTs — privacy model depends
				// on tokens, and /wp-json/wp/v2/mantia_* would enumerate
				// predictions/users/groups/matches anonymously. Mantia exposes
				// what it needs via /wp-json/mantia/v1 with auth.
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'menu_icon'           => 'dashicons-chart-bar',
				'supports'            => array( 'title', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	private static function register_group(): void {
		register_post_type(
			self::GROUP,
			array(
				'labels'              => array(
					'name'          => __( 'Mantia Groups', 'mantia' ),
					'singular_name' => __( 'Mantia Group', 'mantia' ),
				),
				'public'              => false,
				'show_ui'             => true,
				// REST disabled across all Mantia CPTs — privacy model depends
				// on tokens, and /wp-json/wp/v2/mantia_* would enumerate
				// predictions/users/groups/matches anonymously. Mantia exposes
				// what it needs via /wp-json/mantia/v1 with auth.
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'menu_icon'           => 'dashicons-groups',
				'supports'            => array( 'title', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	// Mantia_CPTs::USER (mantia_user) removed in Phase 6 of the wp_user
	// migration. Identity now lives in wp_users via WA_Identity_Bridge;
	// per-user state moved from post_meta on the CPT to user_meta on the
	// wp_user. Mantia_Repository helpers retain the same META_* constants.
}
