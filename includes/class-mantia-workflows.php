<?php
/**
 * Workflow registrations.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Workflows {

	public static function register(): void {
		add_action( 'wp_agents_api_init', array( __CLASS__, 'register_workflows' ), 20 );
		if ( Mantia_Whatsapp_Flow::outbound_workflows_enabled() ) {
			Mantia_Messaging::register();
		}
	}

	public static function register_workflows(): void {
		if ( ! function_exists( 'wp_register_workflow' ) ) {
			return;
		}

		self::register_resolve_matches();
		if ( Mantia_Whatsapp_Flow::outbound_workflows_enabled() ) {
			self::register_match_reminders();
			self::register_daily_digest();
		} else {
			self::unregister_outbound_schedules();
		}
	}

	private static function register_resolve_matches(): void {
		wp_register_workflow(
			array(
				'id'       => 'mantia/resolve-matches',
				'version'  => '1.0.0',
				'inputs'   => array(),
				'steps'    => array(
					array(
						'id'      => 'find_matches',
						'type'    => 'ability',
						'ability' => 'mantia/get-finished-unresolved-matches',
						'args'    => array(),
					),
					array(
						'id'    => 'resolve_each',
						'type'  => 'foreach',
						'items' => '${steps.find_matches.output.matches}',
						'as'    => 'match',
						'steps' => array(
							array(
								'id'      => 'resolve',
								'type'    => 'ability',
								'ability' => 'mantia/resolve-match',
								'args'    => array(
									'match_id' => '${vars.match.id}',
								),
							),
						),
					),
				),
				'triggers' => array(
					array(
						'type' => 'cron',
						'interval' => 15 * MINUTE_IN_SECONDS,
					),
				),
				'meta'     => self::meta( 'resolve-matches' ),
			)
		);
	}

	private static function register_match_reminders(): void {
		wp_register_workflow(
			array(
				'id'       => 'mantia/match-reminders',
				'version'  => '1.0.0',
				'inputs'   => array(),
				'steps'    => array(
					array(
						'id'      => 'targets',
						'type'    => 'ability',
						'ability' => 'mantia/get-match-reminder-targets',
						'args'    => array( 'hours_ahead' => 2 ),
					),
					array(
						'id'                => 'send_each',
						'type'              => 'foreach',
						'items'             => '${steps.targets.output.targets}',
						'as'                => 'target',
						'continue_on_error' => true,
						'steps'             => array(
							array(
								'id'      => 'send',
								'type'    => 'ability',
								'ability' => 'agents/dispatch-message',
								'args'    => array(
									'channel'   => 'whatsapp',
									'recipient' => '${vars.target.recipient}',
									'message'   => '${vars.target.message}',
									'metadata'  => array(
										'source_plugin' => 'mantia',
										'workflow'      => 'mantia/match-reminders',
										'dedupe_key'    => '${vars.target.dedupe_key}',
									),
								),
							),
						),
					),
				),
				'triggers' => array(
					array(
						'type' => 'cron',
						'interval' => 30 * MINUTE_IN_SECONDS,
					),
				),
				'meta'     => self::meta( 'match-reminders' ),
			)
		);
	}

	private static function register_daily_digest(): void {
		wp_register_workflow(
			array(
				'id'       => 'mantia/daily-digest',
				'version'  => '1.0.0',
				'inputs'   => array(),
				'steps'    => array(
					array(
						'id'      => 'targets',
						'type'    => 'ability',
						'ability' => 'mantia/get-daily-digest-targets',
						'args'    => array(),
					),
					array(
						'id'                => 'send_each',
						'type'              => 'foreach',
						'items'             => '${steps.targets.output.targets}',
						'as'                => 'target',
						'continue_on_error' => true,
						'steps'             => array(
							array(
								'id'      => 'send',
								'type'    => 'ability',
								'ability' => 'agents/dispatch-message',
								'args'    => array(
									'channel'   => 'whatsapp',
									'recipient' => '${vars.target.recipient}',
									'message'   => '${vars.target.message}',
									'metadata'  => array(
										'source_plugin' => 'mantia',
										'workflow'      => 'mantia/daily-digest',
										'dedupe_key'    => '${vars.target.dedupe_key}',
									),
								),
							),
						),
					),
				),
				'triggers' => array(
					array(
						'type' => 'cron',
						'expression' => '0 8 * * *',
					),
				),
				'meta'     => self::meta( 'daily-digest' ),
			)
		);
	}

	private static function meta( string $source_type ): array {
		return array(
			'source_plugin'  => 'mantia/mantia.php',
			'source_type'    => $source_type,
			'source_package' => 'automattic/mantia',
			'source_version' => MANTIA_VERSION,
		);
	}

	private static function unregister_outbound_schedules(): void {
		if ( ! class_exists( '\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge' ) ) {
			return;
		}

		\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge::unregister( 'mantia/match-reminders' );
		\AgentsAPI\AI\Workflows\WP_Agent_Workflow_Action_Scheduler_Bridge::unregister( 'mantia/daily-digest' );
	}
}
