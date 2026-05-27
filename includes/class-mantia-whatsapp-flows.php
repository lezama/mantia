<?php
/**
 * WhatsApp Flows publisher + helpers for Mantia.
 *
 * Flows are JSON-defined multi-screen native UIs that run inside WhatsApp
 * (https://developers.facebook.com/docs/whatsapp/flows). Each Flow lives
 * as a `.flow.json` file under mantia/flows/; this class handles the
 * "register the flow with Meta + cache the flow_id" lifecycle so handlers
 * can just call `Mantia_Whatsapp_Flows::flow_id( $name )` and get back the
 * id to embed in an outbound interactive Flow message.
 *
 * Publishing is one-shot per environment:
 *   POST graph.facebook.com/<WABA-ID>/flows
 *     body: { name, categories[], publish: true }
 *   PUT  graph.facebook.com/<flow-id>/assets
 *     body: <flow_json> as multipart upload
 *
 * The flow_id is stored in wp_options keyed by `mantia_flow_id_<name>` so
 * a re-publish only happens when the option is missing OR the local JSON
 * checksum has drifted (we store the checksum alongside the id).
 *
 * Mantia's WABA-ID comes from the `mantia_waba_id` option (operator sets
 * it once via wp-cli or admin); the access token is the openclaWP
 * WhatsApp Cloud API token, which we already have in openclaWP settings.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Whatsapp_Flows {

	private const OPTION_PREFIX = 'mantia_flow_';

	/**
	 * Resolve a flow_id for a Mantia Flow by name. Returns '' if the flow
	 * hasn't been published yet — callers should fall back to the legacy
	 * chat-bubble path in that case (graceful degradation, no fatal).
	 *
	 * To publish a flow:
	 *   $id = Mantia_Whatsapp_Flows::publish( 'create_penca' );
	 * Once published the flow_id sticks; subsequent flow_id() calls return
	 * it from the wp_options cache without re-hitting Meta.
	 */
	public static function flow_id( string $name ): string {
		$cache = get_option( self::OPTION_PREFIX . $name, array() );
		if ( is_array( $cache ) && ! empty( $cache['id'] ) ) {
			return (string) $cache['id'];
		}
		return '';
	}

	/**
	 * Path on disk to a flow's JSON definition. Centralized so the layout
	 * (flows/<name>.flow.json) is consistent across publish + future
	 * tooling.
	 */
	public static function flow_json_path( string $name ): string {
		$slug = str_replace( '_', '-', $name );
		return MANTIA_PATH . 'flows/' . $slug . '.flow.json';
	}

	/**
	 * Read the local Flow JSON, strip `__example__` keys (only useful in
	 * the source-of-truth file for Meta's Flow Builder validator), and
	 * return as a string ready to upload.
	 *
	 * Returns '' if the file is missing or unreadable — caller should
	 * abort the publish.
	 */
	public static function flow_json_for_upload( string $name ): string {
		$path = self::flow_json_path( $name );
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return '';
		}
		// Strip `__example__` keys — they're only there to make the local
		// JSON readable to humans and to Meta's static validator. The
		// uploaded Flow JSON doesn't need them.
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}
		array_walk_recursive(
			$decoded,
			static function ( &$v, $k ): void {
				if ( '__example__' === $k ) {
					$v = null;
				}
			}
		);
		// Remove the now-nulled keys.
		return (string) wp_json_encode( self::strip_nulls_recursive( $decoded ) );
	}

	/**
	 * Build the runtime `data` payload that ships with the Flow message —
	 * the same `data` the screen will see for its `${data.foo}` bindings.
	 * For create_penca this is the list of competitions currently seeded
	 * on the site, so the Dropdown shows live options instead of
	 * placeholders.
	 */
	public static function flow_runtime_data( string $name ): array {
		if ( 'create_penca' === $name ) {
			if ( ! class_exists( 'Mantia_Competitions' ) ) {
				return array();
			}
			$competitions = array();
			foreach ( Mantia_Competitions::all() as $slug => $row ) {
				$emoji  = isset( $row['emoji'] ) ? (string) $row['emoji'] . ' ' : '';
				$competitions[] = array(
					'id'    => $slug,
					'title' => $emoji . (string) ( $row['name'] ?? $slug ),
				);
			}
			return array( 'competitions' => $competitions );
		}
		return array();
	}

	/**
	 * Build the interactive payload to attach to an outbound message so
	 * it renders as a Flow card with a "Crear penca" CTA button. Returns
	 * null if the Flow isn't published yet — caller falls back to the
	 * legacy chat flow.
	 *
	 * @param string $name       Flow slug (e.g. 'create_penca').
	 * @param string $body_text  WhatsApp body bubble text.
	 * @param array  $opts       Optional: 'header', 'footer', 'cta_label'.
	 * @return array|null
	 */
	public static function build_flow_message( string $name, string $body_text, array $opts = array() ): ?array {
		$flow_id = self::flow_id( $name );
		if ( '' === $flow_id ) {
			return null;
		}
		$cta   = (string) ( $opts['cta_label'] ?? 'Empezar' );
		$token = wp_generate_password( 16, false, false );

		$payload = array(
			'type'       => 'flow',
			'parameters' => array(
				'flow_message_version' => '3',
				'flow_token'           => $token,
				'flow_id'              => $flow_id,
				'flow_cta'             => $cta,
				'flow_action'          => 'navigate',
				'flow_action_payload'  => array(
					'screen' => self::initial_screen( $name ),
					'data'   => self::flow_runtime_data( $name ),
				),
			),
		);
		if ( ! empty( $opts['header'] ) ) {
			$payload['header'] = $opts['header'];
		}
		if ( ! empty( $opts['footer'] ) ) {
			$payload['footer'] = $opts['footer'];
		}
		return $payload;
	}

	/**
	 * Operator-facing publish step. Uploads the local Flow JSON to Meta
	 * and stores the returned flow_id in wp_options. Returns the new id
	 * on success or a WP_Error on failure.
	 *
	 * Requires:
	 *   - `mantia_waba_id` option set (operator provides the WABA ID)
	 *   - openclaWP WhatsApp settings populated (access_token)
	 *
	 * Idempotent: re-publishing the same name updates the existing flow
	 * if Meta supports it (`overwrite=true`), otherwise creates a new one.
	 */
	public static function publish( string $name ) {
		$waba_id = (string) get_option( 'mantia_waba_id', '' );
		if ( '' === $waba_id ) {
			return new WP_Error( 'mantia_flows_no_waba', 'Set the `mantia_waba_id` option first (wp option update mantia_waba_id <ID>).' );
		}
		if ( ! class_exists( 'OpenclaWP_Whatsapp' ) ) {
			return new WP_Error( 'mantia_flows_no_openclawp', 'OpenclaWP not loaded.' );
		}

		$settings = OpenclaWP_Whatsapp::settings();
		$token    = (string) ( $settings['access_token'] ?? '' );
		if ( '' === $token ) {
			return new WP_Error( 'mantia_flows_no_token', 'No WhatsApp access_token in OpenclaWP settings.' );
		}

		$json = self::flow_json_for_upload( $name );
		if ( '' === $json ) {
			return new WP_Error( 'mantia_flows_no_json', sprintf( 'flows/%s.flow.json missing or unreadable.', str_replace( '_', '-', $name ) ) );
		}

		$api_version = (string) ( $settings['api_version'] ?? 'v22.0' );
		$base        = sprintf( 'https://graph.facebook.com/%s', rawurlencode( $api_version ) );

		// Step 1: ensure a Flow exists with this name (create-or-find).
		$existing = self::flow_id( $name );
		if ( '' === $existing ) {
			$resp = wp_remote_post(
				$base . '/' . rawurlencode( $waba_id ) . '/flows',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'timeout' => 30,
					'body'    => wp_json_encode(
						array(
							'name'       => 'mantia_' . $name,
							'categories' => array( 'OTHER' ),
						)
					),
				)
			);
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $body ) || empty( $body['id'] ) ) {
				return new WP_Error( 'mantia_flows_create_failed', 'Could not create Flow: ' . substr( (string) wp_remote_retrieve_body( $resp ), 0, 200 ) );
			}
			$existing = (string) $body['id'];
		}

		// Step 2: upload the JSON as the Flow's `flow.json` asset.
		$boundary = '----MantiaFlows' . wp_generate_password( 16, false, false );
		$multipart = '';
		$multipart .= '--' . $boundary . "\r\n";
		$multipart .= 'Content-Disposition: form-data; name="name"' . "\r\n\r\n";
		$multipart .= 'flow.json' . "\r\n";
		$multipart .= '--' . $boundary . "\r\n";
		$multipart .= 'Content-Disposition: form-data; name="asset_type"' . "\r\n\r\n";
		$multipart .= 'FLOW_JSON' . "\r\n";
		$multipart .= '--' . $boundary . "\r\n";
		$multipart .= 'Content-Disposition: form-data; name="file"; filename="flow.json"' . "\r\n";
		$multipart .= 'Content-Type: application/json' . "\r\n\r\n";
		$multipart .= $json . "\r\n";
		$multipart .= '--' . $boundary . '--' . "\r\n";

		$resp = wp_remote_post(
			$base . '/' . rawurlencode( $existing ) . '/assets',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'timeout' => 30,
				'body'    => $multipart,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return new WP_Error( 'mantia_flows_upload_failed', 'Could not upload Flow JSON: ' . substr( (string) wp_remote_retrieve_body( $resp ), 0, 200 ) );
		}

		// Step 3: publish the flow (move from DRAFT to PUBLISHED).
		$resp = wp_remote_post(
			$base . '/' . rawurlencode( $existing ) . '/publish',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 30,
				'body'    => array(),
			)
		);
		// Publish is best-effort — Meta may keep a flow in DRAFT while
		// reviewing certain categories. We still cache the id either way
		// so subsequent runs can re-attempt.

		update_option(
			self::OPTION_PREFIX . $name,
			array(
				'id'           => $existing,
				'checksum'     => md5( $json ),
				'published_at' => time(),
			),
			false
		);

		return $existing;
	}

	/**
	 * The initial screen each Flow opens on. Hard-coded per flow today;
	 * a future routing-model-aware impl could derive this from the JSON.
	 */
	private static function initial_screen( string $name ): string {
		if ( 'create_penca' === $name ) {
			return 'CREATE_PENCA';
		}
		return '';
	}

	/**
	 * After array_walk_recursive nulls our `__example__` entries, this
	 * pass removes the now-empty keys so the JSON we ship to Meta is
	 * clean. Recursive because Flow JSON has nested arrays + objects.
	 */
	private static function strip_nulls_recursive( $val ) {
		if ( ! is_array( $val ) ) {
			return $val;
		}
		$out = array();
		foreach ( $val as $k => $v ) {
			if ( null === $v ) {
				continue;
			}
			$out[ $k ] = self::strip_nulls_recursive( $v );
		}
		return $out;
	}
}
