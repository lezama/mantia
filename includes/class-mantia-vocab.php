<?php
/**
 * Per-country vocabulary for Mantia.
 *
 * The product noun varies by country:
 *   UY/PY        → "penca"     (Río de la Plata native term)
 *   AR/CL/MX/ES  → "pronóstico" (neutral Spanish — avoids dated
 *                                lottery brand collisions like
 *                                "prode" or "quiniela")
 *   BR           → "bolão"     (Portuguese native)
 *
 * We pick the right word based on the user's WhatsApp E.164 phone
 * country code. For server-side surfaces where we have no phone (the
 * public web), we fall back to a site-default driven by the
 * `mantia_default_country` option (UY by default since that's the
 * canonical install).
 *
 * Strings are kept short and grammatically simple (avoid adjective
 * gender agreement) so a single noun swap works across the bot copy
 * without per-locale sentence rewrites.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Vocab {

	/**
	 * E.164 country-code prefix → ISO 3166-1 alpha-2.
	 * Sorted longest-first at lookup time.
	 */
	private const PHONE_CC = array(
		'598' => 'UY',
		'595' => 'PY',
		'55'  => 'BR',
		'56'  => 'CL',
		'54'  => 'AR',
		'52'  => 'MX',
		'34'  => 'ES',
	);

	/**
	 * Vocabulary per country. Keys:
	 *   noun        — singular form of the product ("penca", "pronóstico"…)
	 *   plural      — plural form
	 *   create      — "Crear/Criar <noun>" CTA glued (e.g. "Crear penca")
	 *   article     — "la"/"el"/"o" — definite article matching gender
	 *   article_indef — "una"/"un"/"um"
	 *   new_adj     — "nueva"/"nuevo"/"novo" matching gender
	 *   active_adj  — "activa"/"activo"/"ativo" matching gender
	 */
	private const VOCAB = array(
		// Uruguay (and Paraguay by tradition) — the native term.
		'UY' => array( 'noun' => 'penca',      'plural' => 'pencas',      'create' => 'Crear penca',      'article' => 'la', 'article_indef' => 'una', 'new_adj' => 'nueva', 'active_adj' => 'activa' ),
		'PY' => array( 'noun' => 'penca',      'plural' => 'pencas',      'create' => 'Crear penca',      'article' => 'la', 'article_indef' => 'una', 'new_adj' => 'nueva', 'active_adj' => 'activa' ),
		// Other Spanish-speaking countries — "pronóstico" is the
		// neutral term that everyone understands. ("Prode" is a dated
		// lottery brand in AR, "quiniela" reads as the national
		// lottery, "polla" varies in connotation by country.) Note
		// there's an intentional double meaning with the individual
		// match prediction — context disambiguates ("Crear pronóstico"
		// = make a pool, "Tu pronóstico: 2-1" = your guess).
		'AR' => array( 'noun' => 'pronóstico', 'plural' => 'pronósticos', 'create' => 'Crear pronóstico', 'article' => 'el', 'article_indef' => 'un',  'new_adj' => 'nuevo', 'active_adj' => 'activo' ),
		'CL' => array( 'noun' => 'pronóstico', 'plural' => 'pronósticos', 'create' => 'Crear pronóstico', 'article' => 'el', 'article_indef' => 'un',  'new_adj' => 'nuevo', 'active_adj' => 'activo' ),
		'MX' => array( 'noun' => 'pronóstico', 'plural' => 'pronósticos', 'create' => 'Crear pronóstico', 'article' => 'el', 'article_indef' => 'un',  'new_adj' => 'nuevo', 'active_adj' => 'activo' ),
		'ES' => array( 'noun' => 'pronóstico', 'plural' => 'pronósticos', 'create' => 'Crear pronóstico', 'article' => 'el', 'article_indef' => 'un',  'new_adj' => 'nuevo', 'active_adj' => 'activo' ),
		// Brazil — Portuguese, native term.
		'BR' => array( 'noun' => 'bolão',      'plural' => 'bolões',      'create' => 'Criar bolão',      'article' => 'o',  'article_indef' => 'um',  'new_adj' => 'novo',  'active_adj' => 'ativo'  ),
	);

	private const DEFAULT_VOCAB = array(
		'noun'          => 'penca',
		'plural'        => 'pencas',
		'create'        => 'Crear penca',
		'article'       => 'la',
		'article_indef' => 'una',
		'new_adj'       => 'nueva',
		'active_adj'    => 'activa',
	);

	/**
	 * Vocabulary for a given E.164 phone (digits or +prefixed). Falls
	 * back to the site default when the country code isn't recognized.
	 */
	public static function for_phone( string $e164 ): array {
		$cc = self::extract_country_code( $e164 );
		if ( '' === $cc ) {
			return self::for_site();
		}
		return self::VOCAB[ $cc ] ?? self::for_site();
	}

	public static function for_site(): array {
		$code = strtoupper( (string) apply_filters( 'mantia_default_country', get_option( 'mantia_default_country', 'UY' ) ) );
		return self::VOCAB[ $code ] ?? self::DEFAULT_VOCAB;
	}

	/**
	 * Read a single vocabulary key for a phone (when known) or the
	 * site default (when not). Returns the literal key as a graceful
	 * fallback so callers can't accidentally print empty strings.
	 */
	public static function word( string $key, string $e164 = '' ): string {
		$vocab = '' !== $e164 ? self::for_phone( $e164 ) : self::for_site();
		return (string) ( $vocab[ $key ] ?? $key );
	}

	/**
	 * Pull the country code prefix off an E.164 number. Returns the
	 * ISO alpha-2 (UY/AR/…) or '' if no prefix matches. Tries the
	 * longest prefix first so 595 wins over 5.
	 */
	public static function extract_country_code( string $e164 ): string {
		$digits = (string) preg_replace( '/\D+/', '', $e164 );
		if ( '' === $digits ) {
			return '';
		}
		$prefixes = array_keys( self::PHONE_CC );
		usort( $prefixes, static fn( string $a, string $b ): int => strlen( $b ) - strlen( $a ) );
		foreach ( $prefixes as $p ) {
			if ( str_starts_with( $digits, (string) $p ) ) {
				return self::PHONE_CC[ $p ];
			}
		}
		return '';
	}
}
