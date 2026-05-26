<?php
/**
 * WA_Identity_Bridge — signed magic-link primitives + optional WP_User
 * resolver, plus a WhatsApp role with locked-down auth. Designed so any
 * WordPress plugin can offer a WhatsApp-as-auth flow without rebuilding
 * the crypto, the lockout, or the user-management plumbing.
 *
 * Two layers, both optional:
 *
 *   1. Signed URL primitive — sign_link(payload, path) + verify_link(token).
 *      Consumer-defined payload, library handles the HMAC/expiry/path-binding.
 *
 *   2. User resolver — resolve_or_create(phone, name) returns a WP_User
 *      keyed by phone. Consumers that don't want their own canonical
 *      identity get find-or-create for free.
 *
 * The two layers compose via the default redemption hook: if no consumer
 * attaches to `wa_identity_bridge_redemption` to log a user in, the
 * library does it automatically — it picks the `phone`/`name` out of the
 * payload, resolves to a WP_User via the resolver, and calls login_as().
 * Consumer attaches at default priority (10) to pre-empt this.
 *
 * Why the optional layering: most managed WP hosts rate-limit
 * `wp_insert_user`; eagerly creating a wp_user for every WhatsApp inbound
 * could burn that budget on traffic that never visits the web. Lazy
 * creation at redemption time is the safe default; consumers with bursty
 * web traffic can still call `resolve_or_create()` themselves at chat
 * time if they want eager wp_users.
 *
 * @package WA_Identity_Bridge
 */

defined( 'ABSPATH' ) || exit;

// Coexistence guard: if the standalone wa-identity-bridge plugin is
// active on this install OR our classes are already loaded (alphabetical
// plugin load order can race either way), skip our in-tree copy so we
// don't redeclare the classes. Mantia's bootstrap still calls ::boot()
// against whichever copy loaded first — public API is identical.
if ( defined( 'WA_IDENTITY_BRIDGE_LOADED' ) || class_exists( 'WA_Identity_Bridge', false ) ) {
	return;
}
define( 'WA_IDENTITY_BRIDGE_LOADED', true );

require_once __DIR__ . '/class-wa-identity-bridge-magic-link.php';
require_once __DIR__ . '/class-wa-identity-bridge-role.php';
require_once __DIR__ . '/class-wa-identity-bridge-user-resolver.php';

final class WA_Identity_Bridge {

	const VERSION = '0.3.0';

	private static bool $booted = false;

	/**
	 * Wire the library into WordPress. Idempotent — safe to call from any
	 * number of consumer plugins; only the first call binds the hooks.
	 *
	 *   - Register the configurable role.
	 *   - Register the magic-link endpoint + redirect handler.
	 *   - Block password login + wp-admin for users in the role.
	 *   - Attach the default redemption handler at priority 1000 (so any
	 *     consumer hook at default priority 10 runs first and wins).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		WA_Identity_Bridge_Role::boot();
		WA_Identity_Bridge_Magic_Link::boot();

		add_action(
			'wa_identity_bridge_redemption',
			array( __CLASS__, 'default_redemption_handler' ),
			1000,
			2
		);
	}

	/* ──── Signed URL primitive ──── */

	/**
	 * Build a signed magic-link URL.
	 *
	 * @param array  $payload Consumer-defined claims. For the default
	 *                        redemption handler to log a user in, include
	 *                        at least 'phone' (E.164 digits). 'name'
	 *                        feeds display_name on first creation.
	 * @param string $path    Internal path to land on (whitelist-enforced).
	 * @param array  $opts    {
	 *   @type int  $ttl         Seconds until expiry. Default: filter
	 *                           'wa_identity_bridge_default_ttl' or DAY_IN_SECONDS.
	 *   @type bool $single_use  Reject replay. Default: filter
	 *                           'wa_identity_bridge_default_single_use' or false.
	 * }
	 * @return string Absolute URL, or '' if path failed whitelist.
	 */
	public static function sign_link( array $payload, string $path, array $opts = array() ): string {
		return WA_Identity_Bridge_Magic_Link::build( $payload, $path, $opts );
	}

	/**
	 * Verify a token + expected path. Returns the consumer payload on
	 * success or WP_Error on any failure. Callers should treat all
	 * errors as a generic "expired or invalid" when surfacing to users.
	 *
	 * @return array|WP_Error
	 */
	public static function verify_link( string $token, string $path ) {
		return WA_Identity_Bridge_Magic_Link::verify( $token, $path );
	}

	/**
	 * Set the WP auth cookie for $user_id. Thin wrapper so consumers
	 * don't have to remember both calls (wp_set_current_user + cookie).
	 * Fires `wa_identity_bridge_logged_in` for audit hooks.
	 */
	public static function login_as( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		/**
		 * Fires immediately after a magic-link redemption logs a user in.
		 *
		 * @param int $user_id The newly-logged-in user.
		 */
		do_action( 'wa_identity_bridge_logged_in', $user_id );
	}

	/* ──── User resolver (optional helpers) ──── */

	/**
	 * Find-or-create a WP_User by phone. Use at chat time for eager
	 * materialisation, or let the default redemption handler call this
	 * lazily on first web visit.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function resolve_or_create( string $phone_e164, string $display_name = '' ) {
		return WA_Identity_Bridge_User_Resolver::resolve_or_create( $phone_e164, $display_name );
	}

	/** Look up an existing WP_User by phone; null if none. Never creates. */
	public static function find_by_phone( string $phone_e164 ): ?WP_User {
		return WA_Identity_Bridge_User_Resolver::find_by_phone( $phone_e164 );
	}

	/* ──── Configuration ──── */

	/** Configured role slug for WhatsApp-managed users. */
	public static function role_slug(): string {
		return (string) apply_filters( 'wa_identity_bridge_role_slug', 'whatsapp_user' );
	}

	/* ──── Default redemption handler ──── */

	/**
	 * Default behaviour when no consumer has attached to
	 * `wa_identity_bridge_redemption` at a higher priority: pluck a
	 * phone out of the payload, find-or-create the wp_user, log them in.
	 *
	 * Bails silently if:
	 *   - Someone already called login_as() (current user is set).
	 *   - Payload has no 'phone' (consumer using a different shape).
	 *   - resolve_or_create errors (e.g. wp_insert_user rate-limited).
	 *
	 * Consumers that want their own resolution flow attach their hook
	 * at default priority 10 and call login_as() — this handler then
	 * sees current_user is set and no-ops.
	 */
	public static function default_redemption_handler( array $payload, string $path ): void {
		if ( get_current_user_id() > 0 ) {
			return; // Consumer hook already logged someone in.
		}
		$phone = isset( $payload['phone'] ) ? (string) $payload['phone'] : '';
		if ( '' === $phone ) {
			return;
		}
		$name = isset( $payload['name'] ) ? (string) $payload['name'] : '';

		$user = self::resolve_or_create( $phone, $name );
		if ( $user instanceof WP_User ) {
			self::login_as( $user->ID );
		}
	}
}
