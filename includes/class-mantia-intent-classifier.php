<?php
/**
 * LLM intent classifier — maps free-form WhatsApp messages to one of
 * Mantia's canonical intents. Stop growing brittle regex alternations
 * to cover natural-language phrasings ("mi pronostico", "que jugué el
 * otro día", "dame la tabla por favor"); ask Claude Haiku instead.
 *
 * Used by `Mantia_Whatsapp_Flow::maybe_handle_command` as a fallback
 * AFTER the deterministic regex switch (button payloads, scores,
 * single-word command keywords) and BEFORE the LLM agent loop. If the
 * classifier returns a known intent, the router dispatches to the
 * matching deterministic handler — no agent loop, no tool roundtrips.
 *
 * Design notes:
 *   - Haiku 4.5: fastest current Anthropic model, ~200ms per call,
 *     ~$0.0008 per classification at these prompt sizes.
 *   - Cached by md5(message) for 15 min — repeated typing of the same
 *     phrase only pays the LLM cost once.
 *   - Filterable: `mantia_intent_classify` lets tests stub the result.
 *   - Bypassed entirely when the Anthropic key isn't set OR the
 *     `mantia_intent_classifier_enabled` filter returns false.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Intent_Classifier {

	private const MODEL          = 'claude-haiku-4-5';
	private const CACHE_TTL      = 15 * MINUTE_IN_SECONDS;
	private const OPTION_API_KEY = 'connectors_ai_anthropic_api_key';

	/**
	 * Canonical intent slugs the classifier may return. Anything else is
	 * coerced to null so callers can safely fall through to the LLM agent.
	 */
	public const INTENTS = array(
		'home',           // hola / inicio / qué onda
		'tabla',          // ranking / leaderboard / posiciones
		'pendientes',     // partidos sin pronóstico
		'partidos',       // fixture / próximos partidos
		'mis_pencas',     // grupos del usuario
		'consenso',       // qué votó el grupo
		'share',          // compartir el link de invitación
		'my_predictions', // ver mis pronósticos cargados
		'bulk_back',      // "Argentina gana todo"
		'cancel',         // cancelar el flujo en curso
		'help',           // ayuda / cómo funciona
		'chitchat',       // saludo casual / smalltalk → fall through to agent
	);

	/**
	 * Classify a message. Returns one of self::INTENTS or null when:
	 *  - the classifier is disabled (no API key, filter says off)
	 *  - the message is empty
	 *  - the LLM call fails (network, rate-limit, bad parse)
	 *
	 * Callers should treat null as "unable to classify — fall through to
	 * the LLM agent loop the usual way".
	 */
	public static function classify( string $message ): ?string {
		$message = trim( $message );
		if ( '' === $message ) {
			return null;
		}
		if ( mb_strlen( $message ) > 240 ) {
			// Messages longer than 240 chars are almost always free-form
			// LLM territory (storytelling, multi-question). Don't burn a
			// classification call — fall through.
			return null;
		}
		if ( ! (bool) apply_filters( 'mantia_intent_classifier_enabled', true ) ) {
			return null;
		}

		// Test stub hook — tests pre-register what the classifier should
		// return without making an HTTP call.
		$stub = apply_filters( 'mantia_intent_classify', null, $message );
		if ( null !== $stub ) {
			return in_array( $stub, self::INTENTS, true ) ? (string) $stub : null;
		}

		$key = (string) get_option( self::OPTION_API_KEY, '' );
		if ( '' === $key ) {
			return null;
		}

		$cache_key = 'mantia_intent_' . md5( strtolower( $message ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return '' === $cached ? null : (string) $cached;
		}

		$intent = self::call_anthropic( $message, $key );
		// Persist negative results too — 15 min of "no, we tried" is fine
		// for repeated typing of the same nonsense.
		set_transient( $cache_key, null === $intent ? '' : $intent, self::CACHE_TTL );

		return $intent;
	}

	private static function call_anthropic( string $message, string $key ): ?string {
		$intents_str = implode( ' | ', self::INTENTS );
		$system = <<<PROMPT
You map ONE rioplatense Spanish WhatsApp message to ONE intent slug for a Mantia football-prediction bot.

Reply with ONLY the slug (lowercase, snake_case). No quotes, no explanation, no markdown.

Allowed slugs: {$intents_str}

Mapping guide (use rioplatense intuition):
- "tabla" / "ranking" / "posiciones" / "puntos" / "dame el podio" → tabla
- "pendientes" / "falta jugar" / "que me queda" → pendientes
- "partidos" / "fixture" / "próximos" / "qué se viene" → partidos
- "mis pencas" / "grupos" / "en qué estoy" → mis_pencas
- "consenso" / "qué puso el grupo" / "votos" → consenso
- "link" / "compartir" / "invitar amigos" → share
- "mi pronóstico" / "qué predije" / "lo que puse" / "mi jugada" → my_predictions
- "Argentina gana todo" / "Brasil siempre" / "marca a X" → bulk_back
- "cancelar" / "salir" / "olvidalo" → cancel
- "ayuda" / "cómo funciona" / "qué hace esto" → help
- "hola" / "buenas" / "qué onda" → home
- random chatter or single emoji or unparseable → chitchat

Stay strict — when in doubt prefer `chitchat` so the smarter LLM agent picks it up.
PROMPT;

		$body = wp_json_encode( array(
			'model'      => self::MODEL,
			'max_tokens' => 12,
			'system'     => $system,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $message ),
			),
		) );

		$resp = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 4,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => $body,
		) );

		if ( is_wp_error( $resp ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['content'] ) ) {
			return null;
		}
		$text = '';
		foreach ( (array) $decoded['content'] as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		$slug = strtolower( trim( $text ) );
		// Strip leading/trailing punctuation the model sometimes adds.
		$slug = (string) preg_replace( '/^[^a-z_]+|[^a-z_]+$/', '', $slug );
		return in_array( $slug, self::INTENTS, true ) ? $slug : null;
	}
}
