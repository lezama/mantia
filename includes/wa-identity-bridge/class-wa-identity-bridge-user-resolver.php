<?php
/**
 * Phone → WP_User resolution. Optional helper for consumers that want
 * the library to manage the wp_user identity (rather than maintaining
 * their own canonical user model + handling redemption manually).
 *
 * Identity model:
 *   - user_login   = configurable_prefix . phone_e164_digits
 *   - user_email   = phone_e164_digits . '@' . configurable_domain
 *   - display_name = WhatsApp profile.name (refreshed on each call)
 *   - role         = WA_Identity_Bridge::role_slug()
 *   - user_meta    = META_PHONE → phone_e164 (for reverse lookup)
 *
 * Email domain trap (learned the hard way): hosts like wpcom Atomic
 * reject inserts with non-public TLDs (`.local`, `.invalid`, etc.) via
 * a low-priority `wp_pre_insert_user_data` filter that silently returns
 * `false` → `wp_insert_user` bails with "empty_data". The default
 * domain here is derived from the site host so it inherits a valid TLD.
 * Override via filter if your host has stricter rules.
 *
 * @package WA_Identity_Bridge
 */

defined( 'ABSPATH' ) || exit;

final class WA_Identity_Bridge_User_Resolver {

	/** user_meta key for the canonical phone (E.164 digits, no '+'). */
	const META_PHONE = 'wa_phone_e164';

	/**
	 * Normalise a raw phone to E.164 digits (7-16). Returns '' for input
	 * that doesn't look like a phone — callers should treat '' as "skip".
	 */
	public static function normalize_phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( null === $digits || strlen( $digits ) < 7 || strlen( $digits ) > 16 ) {
			return '';
		}
		return $digits;
	}

	/**
	 * Look up a WP_User by phone. Hits META_PHONE first; falls back to
	 * user_login lookup and self-heals the meta if found. Returns null
	 * for unknown phones — never creates.
	 */
	public static function find_by_phone( string $phone_e164 ): ?WP_User {
		$phone_e164 = self::normalize_phone( $phone_e164 );
		if ( '' === $phone_e164 ) {
			return null;
		}

		$users = get_users( array(
			'meta_key'   => self::META_PHONE,
			'meta_value' => $phone_e164,
			'number'     => 1,
			'fields'     => 'all',
		) );
		if ( ! empty( $users ) ) {
			return $users[0];
		}

		$prefix = (string) apply_filters( 'wa_identity_bridge_user_login_prefix', '' );
		$login  = $prefix . $phone_e164;
		$user   = get_user_by( 'login', $login );
		if ( $user instanceof WP_User ) {
			update_user_meta( $user->ID, self::META_PHONE, $phone_e164 );
			return $user;
		}

		return null;
	}

	/**
	 * Find or create. On creation:
	 *   - Phone normalised, role set, META_PHONE stamped.
	 *   - Email built from configured domain (default derived from
	 *     site host so it's always a valid TLD).
	 *   - 'wa_identity_bridge_user_created' fires for consumer side-effects.
	 *
	 * On existing-user calls, the display_name is opportunistically
	 * refreshed — but only if the new value is non-empty AND differs.
	 * We never clobber a stable name with a blank from a later WhatsApp
	 * turn that happens not to carry profile.name.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function resolve_or_create( string $phone_e164, string $display_name = '' ) {
		$phone_e164 = self::normalize_phone( $phone_e164 );
		if ( '' === $phone_e164 ) {
			return new WP_Error(
				'wa_identity_bad_phone',
				__( 'Invalid phone (must be E.164 digits, 7-16 chars).', 'wa-identity-bridge' )
			);
		}

		$existing = self::find_by_phone( $phone_e164 );
		if ( $existing instanceof WP_User ) {
			self::maybe_refresh_display_name( $existing, $display_name );
			return $existing;
		}

		$prefix       = (string) apply_filters( 'wa_identity_bridge_user_login_prefix', '' );
		$email_domain = (string) apply_filters( 'wa_identity_bridge_email_domain', self::default_email_domain() );
		$role_slug    = WA_Identity_Bridge::role_slug();

		$user_id = wp_insert_user( array(
			'user_login'   => $prefix . $phone_e164,
			'user_email'   => $phone_e164 . '@' . $email_domain,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'display_name' => '' !== $display_name ? $display_name : '+' . $phone_e164,
			'nickname'     => '' !== $display_name ? $display_name : '+' . $phone_e164,
			'role'         => $role_slug,
		) );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( (int) $user_id, self::META_PHONE, $phone_e164 );

		/**
		 * Fires after a WhatsApp identity is materialised as a WP_User.
		 * Use to seed consumer-specific state (avatar generation, welcome
		 * message dispatch, link to a CPT-based profile, etc.).
		 *
		 * @param int    $user_id      The freshly-created user.
		 * @param string $phone_e164   Canonical phone.
		 * @param string $display_name Initial display_name (may be empty).
		 */
		do_action( 'wa_identity_bridge_user_created', (int) $user_id, $phone_e164, $display_name );

		return get_user_by( 'id', (int) $user_id );
	}

	/**
	 * Derive a default email domain from the site host. We prefix with
	 * 'wa.' to make it visually obvious in admin lists that these users
	 * came from the bridge, AND to guarantee a public-TLD suffix even on
	 * hosts (Atomic Platform, hopefully others) that reject made-up
	 * TLDs like .local.
	 */
	private static function default_email_domain(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $host ) {
			// Defensive fallback if home_url is misconfigured (test envs etc.).
			return 'wa.invalid';
		}
		return 'wa.' . $host;
	}

	private static function maybe_refresh_display_name( WP_User $user, string $new_name ): void {
		$new_name = trim( $new_name );
		if ( '' === $new_name ) {
			return;
		}
		if ( $user->display_name === $new_name ) {
			return;
		}
		wp_update_user( array(
			'ID'           => $user->ID,
			'display_name' => $new_name,
			'nickname'     => $new_name,
		) );
		// Mutate the in-memory user too — `wp_update_user` writes to the DB
		// and busts the object cache, but the WP_User instance the caller
		// already holds would otherwise still have the stale display_name.
		$user->display_name = $new_name;
		$user->nickname     = $new_name;
	}
}
