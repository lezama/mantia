<?php
/**
 * WA_Identity_Bridge — signed magic-link primitives plus a WhatsApp role
 * with locked-down auth, designed to let any WordPress plugin offer a
 * WhatsApp-as-auth flow without rebuilding the crypto + lockout pieces.
 *
 * Public API (call these — not the internal collaborators — so the
 * library can be extracted to its own repo without consumer churn):
 *
 *   WA_Identity_Bridge::boot()
 *   WA_Identity_Bridge::sign_link( array $payload, string $path, array $opts = [] ): string
 *   WA_Identity_Bridge::verify_link( string $token, string $path ): array|WP_Error
 *   WA_Identity_Bridge::login_as( int $user_id ): void
 *   WA_Identity_Bridge::role_slug(): string
 *
 * Why this shape (and not full user-management): wpcom Atomic — and many
 * other managed WP hosts — rate-limit `wp_insert_user` to a handful per
 * minute. Eagerly materialising a WP_User for every WhatsApp message
 * burns that budget on traffic that may never visit the web. Consumers
 * keep their own canonical identity (CPT, custom table, whatever) and
 * call this library only when they need (a) a signed URL, and (b) a
 * lazy wp_user-and-cookie at the moment the magic link is redeemed.
 *
 * @package WA_Identity_Bridge
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-wa-identity-bridge-magic-link.php';
require_once __DIR__ . '/class-wa-identity-bridge-role.php';

final class WA_Identity_Bridge {

	/** Library version — bump when public-API shape changes. */
	const VERSION = '0.2.0';

	private static bool $booted = false;

	/**
	 * Wire the library into WordPress. Idempotent.
	 *
	 *   - Register the configurable role (default 'whatsapp_user').
	 *   - Register the magic-link endpoint + redirect handler.
	 *   - Block password login + wp-admin for users in the role
	 *     (toggleable via wa_identity_bridge_block_wp_login).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		WA_Identity_Bridge_Role::boot();
		WA_Identity_Bridge_Magic_Link::boot();
	}

	/**
	 * Build a signed URL that — when followed — fires the consumer-provided
	 * redemption callback with the payload and lands the visitor on $path.
	 *
	 * Payload is opaque to the library; consumers stash whatever they need
	 * to resolve identity at redemption time (e.g. ['phone' => '598...',
	 * 'name' => 'Tincho']). Keep it small — it's URL-encoded into the link.
	 *
	 * @param array  $payload Consumer-defined claims. Avoid secrets — the
	 *                        payload is base64-encoded, not encrypted.
	 * @param string $path    Internal path to redirect to after redemption
	 *                        (must pass the configured whitelist).
	 * @param array  $opts    {
	 *   @type int  $ttl         Seconds until expiry. Default: filter
	 *                           'wa_identity_bridge_default_ttl' or DAY_IN_SECONDS.
	 *   @type bool $single_use  Reject second redemption of the same token.
	 *                           Default: filter
	 *                           'wa_identity_bridge_default_single_use' or false.
	 * }
	 * @return string Absolute URL, or '' if $path failed whitelist.
	 */
	public static function sign_link( array $payload, string $path, array $opts = array() ): string {
		return WA_Identity_Bridge_Magic_Link::build( $payload, $path, $opts );
	}

	/**
	 * Verify a token + expected path pair. On success returns the original
	 * payload (an array). On failure returns a WP_Error; callers should
	 * treat all error codes as a generic "expired or invalid" when
	 * surfacing to end users — never leak the specific failure reason.
	 *
	 * @return array|WP_Error
	 */
	public static function verify_link( string $token, string $path ) {
		return WA_Identity_Bridge_Magic_Link::verify( $token, $path );
	}

	/**
	 * Thin convenience wrapper around wp_set_current_user + wp_set_auth_cookie.
	 * Exists so consumers don't have to remember to call both, and so the
	 * library can layer telemetry / hooks here later without touching
	 * downstream callers.
	 */
	public static function login_as( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		/**
		 * Fires immediately after a magic-link redemption logs a user in.
		 * Useful for audit logs ("user X logged in via WhatsApp at time Y").
		 *
		 * @param int $user_id The newly-logged-in user.
		 */
		do_action( 'wa_identity_bridge_logged_in', $user_id );
	}

	/**
	 * Read the configured role slug. Useful for consumers that need to
	 * filter user lists, scope caps, or tag newly-created wp_users.
	 */
	public static function role_slug(): string {
		return (string) apply_filters( 'wa_identity_bridge_role_slug', 'whatsapp_user' );
	}
}
