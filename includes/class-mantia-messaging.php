<?php
/**
 * Outbound messaging bridge.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Messaging {

	public static function register(): void {
		add_filter( 'wp_agent_dispatch_message_handler', array( __CLASS__, 'register_dispatch_handler' ), 10, 2 );
		add_filter( 'agents_dispatch_message_permission', array( __CLASS__, 'allow_mantia_dispatches' ), 10, 2 );
	}

	public static function register_dispatch_handler( $existing, array $input ) {
		if ( null !== $existing ) {
			return $existing;
		}
		if ( Mantia_Whatsapp_Flow::user_initiated_only() && ! self::is_explicitly_allowed_user_window_message( $input ) ) {
			return $existing;
		}

		$channel = sanitize_key( (string) ( $input['channel'] ?? '' ) );
		if ( in_array( $channel, array( 'whatsapp', 'wacli', 'mantia-whatsapp' ), true ) ) {
			return array( __CLASS__, 'send' );
		}

		return $existing;
	}

	public static function allow_mantia_dispatches( bool $allowed, array $input ): bool {
		$metadata = isset( $input['metadata'] ) && is_array( $input['metadata'] ) ? $input['metadata'] : array();
		if ( 'mantia' === ( $metadata['source_plugin'] ?? '' ) ) {
			return ! Mantia_Whatsapp_Flow::user_initiated_only() || self::is_explicitly_allowed_user_window_message( $input );
		}

		return $allowed;
	}

	public static function send( array $input ): array|WP_Error {
		if ( Mantia_Whatsapp_Flow::user_initiated_only() && ! self::is_explicitly_allowed_user_window_message( $input ) ) {
			return new WP_Error(
				'mantia_user_initiated_only',
				__( 'Outbound WhatsApp dispatch is disabled in user-initiated mode.', 'mantia' )
			);
		}

		$channel   = sanitize_key( (string) ( $input['channel'] ?? '' ) );
		$recipient = sanitize_text_field( (string) ( $input['recipient'] ?? '' ) );
		$message   = trim( (string) ( $input['message'] ?? '' ) );

		if ( '' === $recipient || '' === $message ) {
			return new WP_Error( 'mantia_dispatch_invalid_message', __( 'Recipient and message are required.', 'mantia' ) );
		}

		$metadata   = isset( $input['metadata'] ) && is_array( $input['metadata'] ) ? $input['metadata'] : array();
		$dedupe_key = sanitize_key( (string) ( $metadata['dedupe_key'] ?? '' ) );
		if ( '' !== $dedupe_key && get_transient( $dedupe_key ) ) {
			return array(
				'sent'       => false,
				'channel'    => $channel,
				'recipient'  => $recipient,
				'message_id' => null,
				'metadata'   => array( 'duplicate' => true ),
			);
		}

		if ( class_exists( 'OpenclaWP_Wacli_Transport' ) && ( 'wacli' === $channel || str_contains( $recipient, '@' ) ) ) {
			$result = OpenclaWP_Wacli_Transport::send_via_wacli( $recipient, $message );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			self::remember_dedupe_key( $dedupe_key );
			return self::sent_result( $channel, $recipient, null, 'wacli' );
		}

		if ( class_exists( 'OpenclaWP_Whatsapp' ) && method_exists( 'OpenclaWP_Whatsapp', 'send_text_message' ) ) {
			$sent = OpenclaWP_Whatsapp::send_text_message( $recipient, $message );
			if ( $sent ) {
				self::remember_dedupe_key( $dedupe_key );
				return self::sent_result( $channel, $recipient, null, 'whatsapp_cloud' );
			}
		}

		do_action( 'mantia_dispatch_message_requested', $input );

		return new WP_Error(
			'mantia_no_message_transport',
			__( 'No hay transporte WhatsApp disponible para enviar el mensaje.', 'mantia' )
		);
	}

	private static function sent_result( string $channel, string $recipient, ?string $message_id, string $provider ): array {
		return array(
			'sent'       => true,
			'channel'    => $channel,
			'recipient'  => $recipient,
			'message_id' => $message_id,
			'metadata'   => array( 'provider' => $provider ),
		);
	}

	private static function remember_dedupe_key( string $dedupe_key ): void {
		if ( '' !== $dedupe_key ) {
			set_transient( $dedupe_key, 1, DAY_IN_SECONDS );
		}
	}

	private static function is_explicitly_allowed_user_window_message( array $input ): bool {
		$metadata = isset( $input['metadata'] ) && is_array( $input['metadata'] ) ? $input['metadata'] : array();

		return ! empty( $metadata['within_customer_service_window'] ) || ! empty( $metadata['user_initiated_reply'] );
	}
}
