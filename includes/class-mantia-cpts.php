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
	public const USER        = 'mantia_user';
	public const COMPETITION = 'mantia_competition';

	public static function register(): void {
		self::register_match();
		self::register_prediction();
		self::register_group();
		self::register_user();
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
				'show_in_rest'        => true,
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
				'show_in_rest'        => true,
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
				'show_in_rest'        => true,
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
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'menu_icon'           => 'dashicons-groups',
				'supports'            => array( 'title', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	private static function register_user(): void {
		register_post_type(
			self::USER,
			array(
				'labels'              => array(
					'name'          => __( 'Mantia Users', 'mantia' ),
					'singular_name' => __( 'Mantia User', 'mantia' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'menu_icon'           => 'dashicons-smartphone',
				'supports'            => array( 'title', 'custom-fields' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}
}
