<?php
/**
 * Stakeholder-sim onboarding capture.
 *
 * Drives the bot through the canonical "I want to create a Mundial pool
 * + see the matches" flow that Matías Benedetto walked on prod (see
 * Slack screenshots 2026-05-26). Dumps the FULL transcript to
 * tests/qa-output/stakeholder-sim-onboarding.txt and runs hard asserts
 * that mirror the six lived-UX rules. The transcript file is what gets
 * fed to the `mantia-stakeholder-sim` review subagent.
 *
 * Three classes of check:
 *   (a) **Inline asserts** — the things we CAN test deterministically
 *       (no >300-char URLs, no "Necesito tu teléfono" after auth).
 *   (b) **Say-do consistency** — bot confirmation must match underlying
 *       state (created Mundial → `partidos` shows Mundial fixtures).
 *   (c) **Transcript dump** — for the stakeholder-sim subagent to review
 *       offline. The path is printed at the end; a human or another
 *       agent can `cat` it and pass to the review prompt.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Stakeholder-sim — onboarding + say-do consistency' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Clean slate + Mundial fixture must be present' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::cleanup();
Mantia_E2E::require_fixture_or_skip( 'mundial-2026' );

// Install the outbound recorder BEFORE creating personas. Without this,
// side-effect sends (invite cards, avatar confirms) silently no-op
// against an unconfigured WhatsApp Cloud API in the test env, and the
// reviewer ends up reading a transcript that's missing half the
// bubbles the real user saw. The recorder intercepts every send via
// Mantia_Whatsapp_Flow::send_outbound_text() and queues it for the
// transcript renderer below.
Mantia_E2E::install_outbound_recorder();

$alice = Mantia_E2E::persona( 'Alice', 1 );
$bob   = Mantia_E2E::persona( 'Bob',   2 );
$phone_to_name = array(
	$alice['phone'] => $alice['name'],
	$bob['phone']   => $bob['name'],
);

$transcript = array();
// Scrub test-only noise so the reviewer doesn't flag false positives on
// scaffolding it can't know about. Today: the __E2E__ persona name
// prefix. Add more entries when a new test-internal token surfaces in
// user-visible copy.
$scrub_test_noise = static function ( string $s ): string {
	return preg_replace( '/__E2E__\s*/', '', $s );
};
$render_interactive = static function ( ?array $interactive ) use ( &$transcript, $scrub_test_noise ): void {
	if ( ! is_array( $interactive ) || empty( $interactive ) ) {
		return;
	}
	$type = (string) ( $interactive['type'] ?? '' );
	if ( ! empty( $interactive['buttons'] ) ) {
		foreach ( $interactive['buttons'] as $b ) {
			$transcript[] = '   [button] ' . $scrub_test_noise( (string) ( $b['title'] ?? '' ) );
		}
	}
	if ( ! empty( $interactive['sections'] ) ) {
		foreach ( $interactive['sections'] as $s ) {
			$section_title = (string) ( $s['title'] ?? '' );
			if ( '' !== $section_title ) {
				$transcript[] = '   [section] ' . $scrub_test_noise( $section_title );
			}
			foreach ( $s['rows'] ?? array() as $row ) {
				$transcript[] = sprintf(
					'     [row] %s — %s',
					$scrub_test_noise( (string) ( $row['title'] ?? '' ) ),
					$scrub_test_noise( (string) ( $row['description'] ?? '' ) )
				);
			}
		}
	}
	if ( '' !== $type && empty( $interactive['buttons'] ) && empty( $interactive['sections'] ) ) {
		$transcript[] = sprintf( '   [interactive %s — empty]', $type );
	}
};
// Render every captured outbound side-effect for the persona — interleaved
// BEFORE the bot's main reply since the side-effect ships first chronologically.
$drain_outbounds = static function () use ( &$transcript, $scrub_test_noise, $phone_to_name ): void {
	foreach ( Mantia_E2E::consume_outbound() as $out ) {
		$name = $phone_to_name[ $out['to'] ] ?? $out['to'];
		$transcript[] = sprintf( '[to %s · %s]', $name, $out['kind'] );
		foreach ( explode( "\n", $scrub_test_noise( $out['body'] ) ) as $line ) {
			$transcript[] = '   ' . $line;
		}
	}
};
// One unified send-and-capture used by every turn for every persona.
// Each turn emits in time order: the inbound message → any side-effect
// outbounds the handler shipped → the bot's main reply to the inbound
// persona. The "[from <name>]" / "[to <name> · <kind>]" tags let the
// reviewer follow each persona's thread independently.
$send_turn = static function ( array $persona, string $msg ) use ( &$transcript, $scrub_test_noise, $render_interactive, $drain_outbounds ): array {
	$transcript[] = sprintf( '[from %s] %s', $persona['name'], $scrub_test_noise( $msg ) );
	$r = Mantia_E2E::send( $persona, $msg );
	$drain_outbounds();
	$reply       = (string) ( $r['reply'] ?? '' );
	$interactive = is_array( $r ) ? ( $r['interactive'] ?? null ) : null;
	$transcript[] = sprintf( '[to %s · reply] %s', $persona['name'], $scrub_test_noise( $reply ) );
	$render_interactive( $interactive );
	return is_array( $r ) ? $r : array();
};

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Drive the canonical Matías flow (Alice creates a Mundial pool)' );
/* ─────────────────────────────────────────────────────────────────────── */
$alice_turns = array(
	'hola',
	'mantia:cmd:new-penca',
	'mantia:newcomp:mundial-2026',
	'__E2E__ MundialAlice',
	// Tap Compartir right after create. Canary for the 2026-05-26
	// prod regression where this exact button reply read "Todavía no
	// tenés pencas" — a user-not-found false positive on a user who
	// HAD just been created two turns ago. Anything similar in the
	// future should now fail this scenario at the inline say-do
	// assertion below ("share-link must not say no tenés pencas").
	'mantia:cmd:share-link',
	'partidos',
	'tabla',
	// "mi pronostico" intentionally NOT in the canonical sim — it's
	// a fuzzy intent that routes through Mantia_Intent_Classifier (LLM)
	// in production. The LLM isn't configured in wp-env, so capturing
	// it here produced a "(null — fell through to LLM)" dead bubble.
	// Coverage for the LLM path lives in tests/e2e/intent-classifier.php.
);
foreach ( $alice_turns as $msg ) {
	$send_turn( $alice, $msg );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Bob joins Alice\'s pool via the invite code (cross-thread context)' );
/* ─────────────────────────────────────────────────────────────────────── */
// Pull the invite code from Alice's just-created group. The reviewer's
// transcript shows it appearing in an [to Alice · invite_card] bubble
// during step 1 — Bob receiving it on a different channel (WhatsApp
// group forward) is modeled by us reading the code here and feeding
// it as Bob's first content message.
$alice_user_id   = (int) ( Mantia_E2E::user_by_phone( $alice['phone'] )->ID ?? 0 );
$alice_group_id  = $alice_user_id > 0 ? Mantia_Repository::active_group_id_for_user( $alice_user_id ) : 0;
$invite_code     = '';
if ( $alice_group_id > 0 ) {
	$alice_group = Mantia_Repository::group_to_array( $alice_group_id );
	$invite_code = (string) ( $alice_group['invite_code'] ?? '' );
}
Mantia_E2E::assert_true( '' !== $invite_code, 'Alice\'s invite code exists (Bob needs it to join)' );

$bob_turns = array(
	'hola',
	'mantia:cmd:have-code',
	$invite_code,
);
foreach ( $bob_turns as $msg ) {
	$send_turn( $bob, $msg );
}

// After joining, drive Bob into the match-detail card so the
// transcript captures the picker → match-detail handoff (where the
// 2026-05-27 follow-up shipped two copy fixes: web fallback URL +
// chat-typing hint on the change-existing branch).
$bob_pick_match_id = 0;
$alice_group_comp  = $alice_group_id > 0 ? Mantia_Repository::group_competition_id( $alice_group_id ) : '';
if ( '' !== $alice_group_comp ) {
	$ms = Mantia_Repository::upcoming_matches_for_competition( $alice_group_comp, 24 * 365 );
	if ( ! empty( $ms ) ) {
		$bob_pick_match_id = (int) $ms[0]['id'];
	}
}
if ( $bob_pick_match_id > 0 ) {
	$send_turn( $bob, 'mantia:cmd:matches' );
	$send_turn( $bob, 'mantia:match:' . $bob_pick_match_id );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Inline lived-UX asserts' );
/* ─────────────────────────────────────────────────────────────────────── */
$transcript_str = implode( "\n", $transcript );

// Rule 1 — intimidación: no NON-URL line >300 chars (giant prose
// bubbles intimidate). Lines that contain an http URL are inherently
// long when they carry a magic-link token (the /me/ auth path); we
// exempt those, because the URL IS the value the user clicks. The
// follow-up below caps the URL-only line so it can't run wild either.
$max_prose_line = 0;
$max_url_line   = 0;
foreach ( explode( "\n", $transcript_str ) as $line ) {
	$len = mb_strlen( $line );
	if ( false !== stripos( $line, 'http' ) ) {
		$max_url_line = max( $max_url_line, $len );
	} else {
		$max_prose_line = max( $max_prose_line, $len );
	}
}
Mantia_E2E::assert_true( $max_prose_line <= 300, sprintf( 'no prose bot reply line longer than 300 chars (longest: %d)', $max_prose_line ) );
Mantia_E2E::assert_true( $max_url_line <= 500, sprintf( 'URL lines stay under 500 chars even with magic-link token (longest: %d)', $max_url_line ) );

// Rule 1 — `wa_auth_t=` is the magic-link auth mechanism for /me/,
// so the bot legitimately ships it when anchored as a "🌐 …" web
// fallback. Forbid it ONLY when it appears without the 🌐 anchor —
// that's the leak pattern (e.g. a bare token in a non-URL context,
// or a URL the user can't tell is a deliberate web jump-off).
$wa_auth_lines_missing_anchor = array();
foreach ( explode( "\n", $transcript_str ) as $line ) {
	if ( false !== stripos( $line, 'wa_auth_t=' ) && false === strpos( $line, '🌐' ) ) {
		$wa_auth_lines_missing_anchor[] = $line;
	}
}
Mantia_E2E::assert_true(
	empty( $wa_auth_lines_missing_anchor ),
	sprintf(
		'magic-link wa_auth_t= URLs only appear anchored with "🌐 …" (unanchored: %s)',
		empty( $wa_auth_lines_missing_anchor ) ? 'none' : substr( $wa_auth_lines_missing_anchor[0], 0, 80 )
	)
);

// Rule 2 — no "Necesito tu teléfono / pasame tu número / código de país" after a
// WA-authed user already wrote in. (We sent every turn through a logged-in
// persona — the auto-resolve fallback must kick in.)
$asked_phone = (bool) preg_match( '/teléfono|telefono|n[uú]mero de wh|c[oó]digo de pa[ií]s/iu', $transcript_str );
Mantia_E2E::assert_true( ! $asked_phone, 'bot never re-asks for the phone it already has' );

// Rule 3 — say-do consistency: locate the bot replies by content rather
// than fixed index (interactive-payload capture + multi-persona threads
// shift the numbering). Now matches both legacy `[bot]` lines and the
// new `[to <name> · reply]` prefix.
$find_bot_reply_containing = static function ( string $needle ) use ( $transcript ): string {
	foreach ( $transcript as $line ) {
		$is_reply = 0 === strpos( $line, '[bot] ' ) || (bool) preg_match( '/^\[to [^\]]+ · reply\]/', $line );
		if ( $is_reply && false !== stripos( $line, $needle ) ) {
			return $line;
		}
	}
	return '';
};
$create_reply   = $find_bot_reply_containing( 'Creaste' );
$partidos_reply = $find_bot_reply_containing( 'Tocá un partido' );
Mantia_E2E::assert_true( false !== stripos( $create_reply, 'Mundial 2026' ), 'create reply mentions Mundial 2026' );
Mantia_E2E::assert_true( false === stripos( $partidos_reply, 'Libertadores' ), 'partidos reply does NOT show Libertadores after creating a Mundial penca' );

// Rule 3 — say-do consistency: when the bot's reply references a card
// "above" (Reenviá la tarjeta de arriba ↑ or ↑ Reenviá la tarjeta), an
// actual [to <name> · invite_card] outbound MUST exist immediately
// before that reply in the transcript. Without this check the regression
// the user reported on 2026-05-26 (no card delivered but the reply
// pointed at one) would not have been caught.
$say_do_card_break = '';
foreach ( $transcript as $i => $line ) {
	$mentions_card_above = false !== stripos( $line, 'tarjeta de arriba' );
	$is_reply_line       = (bool) preg_match( '/^\[to [^\]]+ · reply\]/', $line );
	if ( ! ( $mentions_card_above && $is_reply_line ) ) {
		continue;
	}
	// Walk backwards looking for the most recent invite_card outbound
	// before any earlier `[from …]` (a new turn boundary).
	$found_card = false;
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		if ( 0 === strpos( $transcript[ $j ], '[from ' ) ) {
			break;
		}
		if ( 0 === strpos( $transcript[ $j ], '[to ' ) && false !== strpos( $transcript[ $j ], '· invite_card]' ) ) {
			$found_card = true;
			break;
		}
	}
	if ( ! $found_card ) {
		$say_do_card_break = $line;
		break;
	}
}
Mantia_E2E::assert_true(
	'' === $say_do_card_break,
	'reply mentioning "tarjeta de arriba" has a real invite_card outbound preceding it (' . ( '' === $say_do_card_break ? 'ok' : 'break: ' . substr( $say_do_card_break, 0, 80 ) ) . ')'
);

// Post-create Compartir tap must NOT say "Todavía no tenés pencas".
// Real prod regression on 2026-05-26: after creating PRUEBA4 the user
// tapped the 📤 Compartir button on the confirmation reply and the
// bot answered with the user-not-found message. find_user_by_phone()
// returned null for a user that had just been created two turns ago.
// Canary lives here so the next regression of that shape lands in CI.
$no_pencas_after_create = false;
$saw_share_tap          = false;
foreach ( $transcript as $line ) {
	if ( 0 === strpos( $line, '[from Alice] mantia:cmd:share-link' ) ) {
		$saw_share_tap = true;
		continue;
	}
	if ( ! $saw_share_tap ) {
		continue;
	}
	if ( (bool) preg_match( '/^\[to Alice · reply\]/', $line ) ) {
		if ( false !== stripos( $line, 'no tenés' ) || false !== stripos( $line, 'no tenes' ) ) {
			$no_pencas_after_create = true;
		}
		break;
	}
}
Mantia_E2E::assert_true( $saw_share_tap, 'transcript exercises the post-create share-link tap' );
Mantia_E2E::assert_true(
	! $no_pencas_after_create,
	'share-link tap after create does NOT say "no tenés pencas" (regression canary for 2026-05-26 prod bug)'
);

// 2026-05-27 live incident (Diego N. joining Miguel's CAPRITEST): when
// a brand-new joiner sent the invite code, the bot's FIRST outbound
// was the same "🏆 Sumate a <penca>" invite_card the joiner had just
// used to join, BEFORE the actual "Listo te sume" confirmation. Read
// as "the bot didn't notice you joined". The card is gone now — the
// "📤 Invitar" button on the confirmation lets a joiner request it on
// demand. Canary: no [to Bob · invite_card] outbound between Bob's
// invite-code turn and the next turn boundary.
//
// The transcript renderer runs $scrub_test_noise() on every line — it
// strips the __E2E__ prefix that the test harness bakes into invite
// codes — so we compare against the SCRUBBED invite code, not the raw
// DB value.
$scrubbed_code             = $scrub_test_noise( $invite_code );
$bob_received_card_on_join = false;
$saw_bob_invite_code_turn  = false;
$joiner_reply_idx          = -1;
foreach ( $transcript as $i => $line ) {
	if ( ! $saw_bob_invite_code_turn ) {
		if ( (bool) preg_match( '/^\[from Bob\]/', $line ) && false !== stripos( $line, $scrubbed_code ) ) {
			$saw_bob_invite_code_turn = true;
		}
		continue;
	}
	// Stop scanning at the next [from …] boundary — we only care about
	// what the bot pushed BETWEEN Bob sending the code and his next turn.
	if ( 0 === strpos( $line, '[from ' ) ) {
		break;
	}
	if ( (bool) preg_match( '/^\[to Bob · invite_card\]/', $line ) ) {
		$bob_received_card_on_join = true;
	}
	if ( -1 === $joiner_reply_idx && (bool) preg_match( '/^\[to Bob · reply\]/', $line ) ) {
		$joiner_reply_idx = $i;
	}
}
Mantia_E2E::assert_true( $saw_bob_invite_code_turn, 'transcript captures Bob sending the invite code' );
Mantia_E2E::assert_true(
	! $bob_received_card_on_join,
	'fresh joiner does NOT receive an invite_card as their first bot outbound (regression canary for 2026-05-27 CAPRITEST bug — Diego saw "Sumate a X" before "Listo te sume")'
);

// And the joiner's confirmation MUST offer "📅 Ver y pronosticar" as
// a button. A brand-new joiner with 6 matches on auto-fill 0-0 needs
// a single-tap path into the predict picker — landing on "📋 Mis
// pencas" / "🏠 Resumen" forces extra steps before the user can do
// the one thing they joined to do.
$joiner_reply_buttons = array();
if ( $joiner_reply_idx >= 0 ) {
	for ( $i = $joiner_reply_idx + 1; $i < count( $transcript ); $i++ ) {
		$line = $transcript[ $i ];
		// Buttons appear as `   [button] <title>` indented under the reply.
		// Any new `[from …]` or new top-level `[to …` (no indent) ends the
		// scope.
		if ( (bool) preg_match( '/^\s+\[button\]\s+(.*)$/', $line, $btn_match ) ) {
			$joiner_reply_buttons[] = trim( $btn_match[1] );
			continue;
		}
		if ( 0 === strpos( $line, '[from ' ) || 0 === strpos( $line, '[to ' ) ) {
			break;
		}
	}
}
$joiner_has_predict_button = false;
foreach ( $joiner_reply_buttons as $btn ) {
	if ( false !== stripos( $btn, 'pronosticar' ) || false !== stripos( $btn, 'partidos' ) ) {
		$joiner_has_predict_button = true;
		break;
	}
}
Mantia_E2E::assert_true(
	$joiner_has_predict_button,
	sprintf(
		'fresh joiner confirmation offers a "Ver y pronosticar" button (saw: %s)',
		empty( $joiner_reply_buttons ) ? '<none>' : implode( ' · ', $joiner_reply_buttons )
	)
);

// Match-detail card asserts (e3ab2fa). Bob just tapped a match — the
// bot's reply must (a) drop the per-user /me/ URL so a phone-fatigued
// user can switch to web, AND (b) surface the chat-typing hint
// ("escribí ... 2-1") so the user knows they can bypass the picker.
$match_detail_reply = '';
$saw_bob_match_tap  = false;
foreach ( $transcript as $line ) {
	if ( ! $saw_bob_match_tap && (bool) preg_match( '/^\[from Bob\] mantia:match:/', $line ) ) {
		$saw_bob_match_tap = true;
		continue;
	}
	if ( ! $saw_bob_match_tap ) {
		continue;
	}
	if ( (bool) preg_match( '/^\[to Bob · reply\]/', $line ) ) {
		$match_detail_reply = $line;
		break;
	}
}
Mantia_E2E::assert_true( $saw_bob_match_tap, 'transcript captures Bob tapping into a match-detail card' );
Mantia_E2E::assert_true(
	false !== stripos( $match_detail_reply, '🌐' ) && false !== stripos( $match_detail_reply, 'web' ),
	'match-detail reply offers a web fallback link (🌐 ... web)'
);
Mantia_E2E::assert_true(
	false !== stripos( $match_detail_reply, 'escrib' ),
	'match-detail reply hints that the user can write the score in chat ("escribí ...")'
);

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. Failure-path: when send_invite_card returns false, reply must inline the link instead of pointing at a phantom card' );
/* ─────────────────────────────────────────────────────────────────────── */
// Simulate the production bug: install a one-shot filter that fails
// every subsequent invite_card outbound. The recorder respects the
// upstream non-null return so the failure actually sticks.
$fail_invite_card = static function ( $intercept, string $recipient, string $body, string $kind ) {
	if ( 'invite_card' === $kind ) {
		return false;
	}
	return $intercept;
};
add_filter( 'mantia_outbound_text', $fail_invite_card, 5, 4 );

// Alice already has a penca → mantia:cmd:share-link re-runs send_invite_card.
$share_r     = Mantia_E2E::send( $alice, 'mantia:cmd:share-link' );
$share_reply = (string) ( $share_r['reply'] ?? '' );

remove_filter( 'mantia_outbound_text', $fail_invite_card, 5 );

Mantia_E2E::assert_true(
	false === stripos( $share_reply, 'tarjeta de arriba' ),
	'share-link reply omits "tarjeta de arriba" when send_invite_card returned false'
);
Mantia_E2E::assert_true(
	false !== stripos( $share_reply, 'wa.me' ) || false !== stripos( $share_reply, 'sumate/' ) || false !== stripos( $share_reply, 'Código:' ),
	'share-link reply still provides a share artifact (wa.me link, sumate URL, or invite code) on send failure'
);

// Rule 5 — empty-state actionable: tabla pre-results suggests next step.
$tabla_reply = $find_bot_reply_containing( 'Todavía no hay puntos' );
$is_empty_tabla = false !== stripos( $tabla_reply, 'Todavía no hay puntos' );
if ( $is_empty_tabla ) {
	Mantia_E2E::assert_true(
		false !== stripos( $tabla_reply, 'pendientes' ) || false !== stripos( $tabla_reply, 'pronosticar' ) || false !== stripos( $tabla_reply, 'faltan' ),
		'empty tabla nudges toward action (pendientes / pronosticar / faltan)'
	);
	// And the empty tabla MUST NOT carry a long URL.
	Mantia_E2E::assert_true(
		false === stripos( $tabla_reply, 'https://' ) || mb_strlen( $tabla_reply ) < 400,
		'empty tabla either omits the URL or stays short'
	);
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4.5. Link-liveness — every URL the bot shipped must resolve' );
/* ─────────────────────────────────────────────────────────────────────── */
// 2026-05-27 incident: a live user opened a stale link from before a
// fixture reset and saw a Mantia 404 page. That was correct behavior —
// the resource really was gone. But the deeper risk is the bot
// shipping a URL that 404s the moment it's sent (e.g. a malformed
// view_token, a missing competition, a typo in a slug template). This
// canary scrapes every http URL the bot ever shipped during the
// transcript and confirms each one resolves (≤300 status). wa.me is
// external and treated as opaque; we just confirm it's syntactically
// well-formed.
$transcript_urls = array();
foreach ( $transcript as $line ) {
	if ( 0 !== strpos( $line, '[to ' ) ) {
		continue; // user turns, not bot output
	}
	if ( preg_match_all( '#https?://[^\s)>"]+#', $line, $m ) ) {
		foreach ( $m[0] as $url ) {
			$url = rtrim( $url, '.,;:!?)' );
			$transcript_urls[ $url ] = true;
		}
	}
}
$transcript_urls = array_keys( $transcript_urls );
Mantia_E2E::assert_true(
	count( $transcript_urls ) > 0,
	sprintf( 'transcript contains at least one bot-shipped URL (saw %d)', count( $transcript_urls ) )
);

$dead_links = array();
$leak_urls  = array();
foreach ( $transcript_urls as $url ) {
	$host = parse_url( $url, PHP_URL_HOST );
	if ( ! $host ) {
		$leak_urls[] = "malformed: $url";
		continue;
	}
	// wa.me is WhatsApp's redirect — outside our control; HEAD often
	// rejects. Skip — the e2e doesn't own that hostname.
	if ( false !== stripos( $host, 'wa.me' ) ) {
		continue;
	}
	// Anything that isn't on our staging/prod host is unexpected. Flag
	// for review rather than silently passing.
	$base_host = parse_url( home_url(), PHP_URL_HOST );
	if ( $host !== $base_host ) {
		$leak_urls[] = "off-host ($host): $url";
		continue;
	}
	$resp = wp_remote_get( $url, array(
		'timeout'     => 10,
		'redirection' => 3,
		'sslverify'   => false,
	) );
	if ( is_wp_error( $resp ) ) {
		$dead_links[] = sprintf( '%s — %s', $url, $resp->get_error_message() );
		continue;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( $code >= 400 ) {
		$dead_links[] = sprintf( '%s — HTTP %d', $url, $code );
	}
}
Mantia_E2E::assert_true(
	empty( $dead_links ),
	sprintf(
		'every internal URL the bot shipped resolves (%d checked, %d dead: %s)',
		count( $transcript_urls ),
		count( $dead_links ),
		empty( $dead_links ) ? 'none' : implode( ' | ', array_slice( $dead_links, 0, 2 ) )
	)
);
Mantia_E2E::assert_true(
	empty( $leak_urls ),
	sprintf(
		'no off-host or malformed URLs leaked into bot replies (saw: %s)',
		empty( $leak_urls ) ? 'none' : implode( ' | ', array_slice( $leak_urls, 0, 2 ) )
	)
);

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. Dump full transcript for offline review' );
/* ─────────────────────────────────────────────────────────────────────── */
$out_dir = dirname( __DIR__ ) . '/qa-output';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0775, true );
}
$out_path = $out_dir . '/stakeholder-sim-onboarding.txt';
$body  = "=== scenario: stakeholder-sim onboarding — multi-persona (Alice creates, Bob joins) ===\n";
$body .= "captured: " . gmdate( 'c' ) . "\n";
$body .= "personas:\n";
$body .= "  Alice — phone " . $alice['phone'] . " (creator, logged in via WA bridge)\n";
$body .= "  Bob   — phone " . $bob['phone']   . " (invited, joins via Alice's code)\n";
$body .= "default competition at capture time: " . Mantia_Competitions::default_id() . "\n";
$body .= "\nTranscript format:\n";
$body .= "  [from <name>] <msg>          — message from that persona to the bot\n";
$body .= "  [to <name> · reply] <msg>    — bot's main reply (the one the user typed against)\n";
$body .= "  [to <name> · <kind>] <msg>   — bot's side-effect outbound (invite_card, avatar_confirm, …)\n";
$body .= "  [button] / [section] / [row] — interactive payload on the preceding bot bubble\n";
$body .= "\n";
$body .= $transcript_str . "\n";
file_put_contents( $out_path, $body );
fwrite( STDOUT, "    · transcript dumped to: $out_path\n" );
fwrite( STDOUT, "    · pass it to the mantia-stakeholder-sim subagent for the lived-UX pass:\n" );
fwrite( STDOUT, "    ·   Agent(subagent_type='mantia-stakeholder-sim', prompt='Read $out_path and emit the punch list.')\n" );

Mantia_E2E::finish();
