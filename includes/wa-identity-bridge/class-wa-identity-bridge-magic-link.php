<?php
/**
 * Magic-link generation, verification, and the redemption endpoint.
 *
 * Token format:
 *   payload_b64 . '.' . sig
 *   payload_b64 = base64url_encode(json({d, exp, nonce, su, path}))
 *   sig         = hash_hmac('sha256', payload_b64, wp_salt('auth') . '|wa_identity_bridge')
 *
 * `d` is the consumer-defined payload (any JSON-serialisable array).
 * `path` is included in the signed payload so a valid token cannot be
 * weaponised against a different `?go=` destination.
 *
 * Single-use is enforced via a transient keyed by nonce, TTL'd to match
 * the token's remaining lifetime.
 *
 * The redemption endpoint *only* verifies the token + redirects. It does
 * NOT log anyone in — identity resolution is the consumer's job. The
 * library fires `wa_identity_bridge_redemption` so the consumer can do
 * find-or-create-user + login_as() + side-effects before the redirect.
 *
 * @package WA_Identity_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class WA_Identity_Bridge_Magic_Link {

	const QUERY_VAR_VIEW = 'wa_auth_view';
	const QUERY_VAR_T    = 'wa_auth_t';
	const QUERY_VAR_GO   = 'wa_auth_go';

	public static function boot(): void {
		if ( did_action( 'init' ) ) {
			self::register_endpoint();
		} else {
			add_action( 'init', array( __CLASS__, 'register_endpoint' ), 11 );
		}
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_endpoint' ), 1 );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR_VIEW;
		$vars[] = self::QUERY_VAR_T;
		$vars[] = self::QUERY_VAR_GO;
		return $vars;
	}

	public static function register_endpoint(): void {
		$path     = self::endpoint_path();
		$pattern  = '^' . preg_quote( $path, '#' ) . '/?$';
		$redirect = 'index.php?' . self::QUERY_VAR_VIEW . '=auth';
		add_rewrite_rule( $pattern, $redirect, 'top' );
	}

	private static function endpoint_path(): string {
		return ltrim( (string) apply_filters( 'wa_identity_bridge_endpoint_path', 'wa-auth' ), '/' );
	}

	/**
	 * Sign a payload + path, return the redemption URL.
	 *
	 * @return string Absolute URL, or '' if path failed whitelist.
	 */
	public static function build( array $payload, string $path, array $opts = array() ): string {
		$path = self::normalize_path( $path );
		if ( '' === $path ) {
			return '';
		}

		$ttl = isset( $opts['ttl'] )
			? max( 60, (int) $opts['ttl'] )
			: (int) apply_filters( 'wa_identity_bridge_default_ttl', DAY_IN_SECONDS );
		$single_use = isset( $opts['single_use'] )
			? (bool) $opts['single_use']
			: (bool) apply_filters( 'wa_identity_bridge_default_single_use', false );

		$envelope = array(
			'd'     => $payload,
			'exp'   => time() + $ttl,
			'nonce' => bin2hex( random_bytes( 8 ) ),
			'su'    => $single_use ? 1 : 0,
			'path'  => $path,
		);
		$payload_b64 = self::base64url_encode( (string) wp_json_encode( $envelope ) );
		$sig         = hash_hmac( 'sha256', $payload_b64, self::secret() );
		$token       = $payload_b64 . '.' . $sig;

		return add_query_arg(
			array(
				self::QUERY_VAR_T  => $token,
				self::QUERY_VAR_GO => rawurlencode( $path ),
			),
			home_url( '/' . self::endpoint_path() . '/' )
		);
	}

	/**
	 * Verify a token against the expected path.
	 *
	 * @return array|WP_Error  The consumer-provided payload (the `d` field)
	 *                         on success, or a WP_Error explaining the
	 *                         failure (do NOT surface the reason to users).
	 */
	public static function verify( string $token, string $expected_path ) {
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return new WP_Error( 'wa_magic_malformed', __( 'Malformed token.', 'wa-identity-bridge' ) );
		}
		list( $payload_b64, $sig ) = explode( '.', $token, 2 );
		$expected_sig              = hash_hmac( 'sha256', $payload_b64, self::secret() );
		if ( ! hash_equals( $expected_sig, $sig ) ) {
			return new WP_Error( 'wa_magic_bad_sig', __( 'Bad signature.', 'wa-identity-bridge' ) );
		}
		$envelope = json_decode( self::base64url_decode( $payload_b64 ), true );
		if ( ! is_array( $envelope ) ) {
			return new WP_Error( 'wa_magic_bad_payload', __( 'Bad payload.', 'wa-identity-bridge' ) );
		}
		if ( empty( $envelope['exp'] ) || empty( $envelope['nonce'] ) || empty( $envelope['path'] ) || ! isset( $envelope['d'] ) ) {
			return new WP_Error( 'wa_magic_payload_missing_fields', __( 'Payload missing fields.', 'wa-identity-bridge' ) );
		}
		if ( time() > (int) $envelope['exp'] ) {
			return new WP_Error( 'wa_magic_expired', __( 'Token expired.', 'wa-identity-bridge' ) );
		}
		// Path tampering check — defeats `go=` swaps even with a valid token.
		if ( self::normalize_path( (string) $envelope['path'] ) !== $expected_path ) {
			return new WP_Error( 'wa_magic_path_mismatch', __( 'Path mismatch.', 'wa-identity-bridge' ) );
		}
		if ( ! empty( $envelope['su'] ) ) {
			$key = 'wa_magic_used_' . preg_replace( '/[^a-f0-9]/', '', (string) $envelope['nonce'] );
			if ( get_transient( $key ) ) {
				return new WP_Error( 'wa_magic_replay', __( 'Token already used.', 'wa-identity-bridge' ) );
			}
			$remaining = max( 60, (int) $envelope['exp'] - time() );
			set_transient( $key, 1, $remaining );
		}
		return (array) $envelope['d'];
	}

	/**
	 * template_redirect handler. Verifies, gives the consumer a chance to
	 * find/create users and set the cookie via the redemption action, then
	 * redirects to the clean path.
	 */
	public static function maybe_handle_endpoint(): void {
		if ( 'auth' !== get_query_var( self::QUERY_VAR_VIEW ) ) {
			return;
		}
		$token = (string) get_query_var( self::QUERY_VAR_T );
		$go    = rawurldecode( (string) get_query_var( self::QUERY_VAR_GO ) );
		$go    = self::normalize_path( $go );

		if ( '' === $token || '' === $go ) {
			self::redirect_to_expired();
		}

		$payload = self::verify( $token, $go );
		if ( is_wp_error( $payload ) ) {
			self::redirect_to_expired();
		}

		/**
		 * Fires when a valid magic link is redeemed. Consumers MUST do
		 * their identity resolution + WA_Identity_Bridge::login_as() here.
		 * If no listener logs anyone in, the user lands on $go anonymous
		 * — which may be a deliberate flow for public-by-token pages.
		 *
		 * @param array  $payload The consumer-defined payload from the token.
		 * @param string $path    Validated destination path.
		 */
		do_action( 'wa_identity_bridge_redemption', $payload, $go );

		wp_safe_redirect( home_url( $go ), 302 );
		exit;
	}

	/**
	 * Internal path-only validator with whitelist enforcement. Returns ''
	 * for anything that's not a leading-slash internal path passing the
	 * configured whitelist.
	 */
	private static function normalize_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path || '/' !== $path[0] ) {
			return '';
		}
		if ( 0 === strpos( $path, '//' ) || false !== strpos( $path, '://' ) ) {
			return '';
		}
		if ( false !== strpos( $path, '..' ) ) {
			return '';
		}
		$whitelist = (array) apply_filters( 'wa_identity_bridge_path_whitelist', array() );
		if ( empty( $whitelist ) ) {
			return $path;
		}
		foreach ( $whitelist as $allowed_prefix ) {
			if ( 0 === strpos( $path, (string) $allowed_prefix ) ) {
				return $path;
			}
		}
		return '';
	}

	private static function redirect_to_expired(): void {
		$url = (string) apply_filters( 'wa_identity_bridge_expired_redirect_url', home_url( '/' ) );
		wp_safe_redirect( $url, 302 );
		exit;
	}

	private static function secret(): string {
		return wp_salt( 'auth' ) . '|wa_identity_bridge';
	}

	private static function base64url_encode( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $b64 ): string {
		$padded = $b64 . str_repeat( '=', ( 4 - strlen( $b64 ) % 4 ) % 4 );
		return (string) base64_decode( strtr( $padded, '-_', '+/' ) );
	}
}
