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

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Inline lived-UX asserts' );
/* ─────────────────────────────────────────────────────────────────────── */
$transcript_str = implode( "\n", $transcript );

// Rule 1 — intimidación: no single line >300 chars (would be a giant URL).
$max_line = 0;
foreach ( explode( "\n", $transcript_str ) as $line ) {
	$max_line = max( $max_line, mb_strlen( $line ) );
}
Mantia_E2E::assert_true( $max_line <= 300, sprintf( 'no bot reply line longer than 300 chars (longest: %d)', $max_line ) );

// Rule 1 — no `wa_auth_t=` magic-link blob visible.
Mantia_E2E::assert_true(
	false === stripos( $transcript_str, 'wa_auth_t=' ),
	'no magic-link wa_auth_t= blob in user-visible replies'
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
