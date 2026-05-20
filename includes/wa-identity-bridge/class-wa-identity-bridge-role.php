<?php
/**
 * Registers the WhatsApp-managed user role and blocks wp-login + wp-admin
 * access for it (configurable). The role exists so consumers can scope
 * caps and filter user listings; the blocking ensures the only auth path
 * for these users is the magic-link round-trip.
 *
 * @package WA_Identity_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class WA_Identity_Bridge_Role {

	public static function boot(): void {
		// If init already fired (boot called from a late context, like
		// wp-cli eval-file or a delayed plugin loader), register the role
		// immediately. Otherwise, defer to init priority 5 — early enough
		// that role-dependent logic running at init priority 10+ sees it.
		if ( did_action( 'init' ) ) {
			self::register_role();
		} else {
			add_action( 'init', array( __CLASS__, 'register_role' ), 5 );
		}
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_password_login' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'block_admin_access' ) );
		add_action( 'login_init', array( __CLASS__, 'maybe_redirect_login_page' ) );
	}

	/**
	 * Register the role idempotently. We use `add_role` (a no-op if it
	 * already exists) rather than mutating an existing role — that way
	 * a misconfigured slug doesn't accidentally grant caps to 'subscriber'.
	 *
	 * Default caps: just `read`. Consumers can layer more via filter
	 * 'wa_identity_bridge_role_caps' if they need to expose specific
	 * post types or front-end-only features.
	 */
	public static function register_role(): void {
		$slug = WA_Identity_Bridge::role_slug();
		if ( get_role( $slug ) ) {
			return;
		}

		$default_caps = array( 'read' => true );
		/**
		 * Filter the capabilities map for the WhatsApp role.
		 *
		 * @param array  $caps Map of cap => bool.
		 * @param string $slug The role slug being registered.
		 */
		$caps = (array) apply_filters( 'wa_identity_bridge_role_caps', $default_caps, $slug );

		add_role( $slug, __( 'WhatsApp user', 'wa-identity-bridge' ), $caps );
	}

	/**
	 * Reject password-based login attempts for WhatsApp users. The only
	 * sanctioned entry path is the magic link. Returning a WP_Error from
	 * `wp_authenticate_user` stops the auth pipeline cleanly.
	 */
	public static function block_password_login( $user, $password ) {
		if ( ! self::blocking_enabled() || ! ( $user instanceof WP_User ) ) {
			return $user;
		}
		if ( self::user_in_role( $user ) ) {
			return new WP_Error(
				'wa_identity_password_login_blocked',
				__( 'Este usuario entra por WhatsApp. Pediselo al bot.', 'wa-identity-bridge' )
			);
		}
		return $user;
	}

	/**
	 * Bounce logged-in WhatsApp users away from wp-admin. They have no
	 * business there (no editing caps) and exposing the dashboard is a
	 * surprise + information leak. AJAX requests pass through so any
	 * front-end JS using admin-ajax still works.
	 */
	public static function block_admin_access(): void {
		if ( ! self::blocking_enabled() || wp_doing_ajax() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) || 0 === $user->ID ) {
			return;
		}
		if ( self::user_in_role( $user ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Redirect away from wp-login.php for any flow that's clearly a
	 * WhatsApp user trying to use the form. This catches the case where
	 * someone types their phone into the login form — we'd rather point
	 * them at the chat than show a "wrong password" error.
	 *
	 * Conservative: only redirects when the login attempt carries a
	 * `log` (username) parameter that resolves to a WhatsApp user. Lets
	 * normal admin logins proceed untouched.
	 */
	public static function maybe_redirect_login_page(): void {
		if ( ! self::blocking_enabled() ) {
			return;
		}
		$log = isset( $_POST['log'] ) ? sanitize_user( wp_unslash( (string) $_POST['log'] ), true ) : '';
		if ( '' === $log ) {
			return;
		}
		$user = get_user_by( 'login', $log );
		if ( $user instanceof WP_User && self::user_in_role( $user ) ) {
			wp_safe_redirect( apply_filters( 'wa_identity_bridge_expired_redirect_url', home_url( '/' ) ) );
			exit;
		}
	}

	private static function user_in_role( WP_User $user ): bool {
		return in_array( WA_Identity_Bridge::role_slug(), (array) $user->roles, true );
	}

	private static function blocking_enabled(): bool {
		return (bool) apply_filters( 'wa_identity_bridge_block_wp_login', true );
	}
}
