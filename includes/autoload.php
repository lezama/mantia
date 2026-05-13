<?php
/**
 * Class autoloader.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, 'Mantia_' ) ) {
			return;
		}

		$files = array(
			'Mantia_Abilities'       => 'class-mantia-abilities.php',
			'Mantia_Agent'           => 'class-mantia-agent.php',
			'Mantia_Bootstrap'       => 'class-mantia-bootstrap.php',
			'Mantia_Competitions'    => 'class-mantia-competitions.php',
			'Mantia_CPTs'            => 'class-mantia-cpts.php',
			'Mantia_Fixture_Seeder'  => 'class-mantia-fixture-seeder.php',
			'Mantia_Frontend'        => 'class-mantia-frontend.php',
			'Mantia_Leaderboard'     => 'class-mantia-leaderboard.php',
			'Mantia_Messaging'       => 'class-mantia-messaging.php',
			'Mantia_Repository'      => 'class-mantia-repository.php',
			'Mantia_Results_Fetcher' => 'class-mantia-results-fetcher.php',
			'Mantia_Scoring'         => 'class-mantia-scoring.php',
			'Mantia_Whatsapp_Flow'   => 'class-mantia-whatsapp-flow.php',
			'Mantia_Workflows'       => 'class-mantia-workflows.php',
		);

		if ( isset( $files[ $class ] ) ) {
			require_once MANTIA_PATH . 'includes/' . $files[ $class ];
		}
	}
);
