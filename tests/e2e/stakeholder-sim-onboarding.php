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

$alice = Mantia_E2E::persona( 'Alice', 1 );

$transcript = array();
// Scrub test-only noise so the reviewer doesn't flag false positives on
// scaffolding it can't know about. Today: the __E2E__ persona name
// prefix. Add more entries when a new test-internal token surfaces in
// user-visible copy.
$scrub_test_noise = static function( string $s ): string {
	return preg_replace( '/__E2E__\s*/', '', $s );
};
$capture = static function( string $who, string $msg, ?array $interactive = null ) use ( &$transcript, $scrub_test_noise ): void {
	$transcript[] = sprintf( '[%s] %s', $who, $scrub_test_noise( $msg ) );
	// Capture interactive payloads (buttons + list rows) so the reviewer
	// has the FULL surface, not just the reply text. Without this it
	// flags "Tocá un partido…" as a dangling instruction when the actual
	// UI has a tappable list below it.
	if ( is_array( $interactive ) && ! empty( $interactive ) ) {
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
	}
};

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Drive the canonical Matías flow' );
/* ─────────────────────────────────────────────────────────────────────── */
$turns = array(
	'hola',
	'mantia:cmd:new-penca',
	'mantia:newcomp:mundial-2026',
	'__E2E__ MundialAlice',
	'partidos',
	'tabla',
	'mi pronostico',
);
foreach ( $turns as $msg ) {
	$capture( 'user', $msg );
	$r           = Mantia_E2E::send( $alice, $msg );
	$reply       = (string) ( $r['reply'] ?? '' );
	$interactive = is_array( $r ) ? ( $r['interactive'] ?? null ) : null;
	$capture( 'bot', $reply, $interactive );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Inline lived-UX asserts' );
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
// than fixed index (interactive-payload capture shifts the numbering).
$find_bot_reply_containing = static function( string $needle ) use ( $transcript ): string {
	foreach ( $transcript as $line ) {
		if ( 0 === strpos( $line, '[bot] ' ) && false !== stripos( $line, $needle ) ) {
			return $line;
		}
	}
	return '';
};
$create_reply   = $find_bot_reply_containing( 'Creaste' );
$partidos_reply = $find_bot_reply_containing( 'Tocá un partido' );
Mantia_E2E::assert_true( false !== stripos( $create_reply, 'Mundial 2026' ), 'create reply mentions Mundial 2026' );
Mantia_E2E::assert_true( false === stripos( $partidos_reply, 'Libertadores' ), 'partidos reply does NOT show Libertadores after creating a Mundial penca' );

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
Mantia_E2E::step( '3. Dump full transcript for offline review' );
/* ─────────────────────────────────────────────────────────────────────── */
$out_dir = dirname( __DIR__ ) . '/qa-output';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0775, true );
}
$out_path = $out_dir . '/stakeholder-sim-onboarding.txt';
$body  = "=== scenario: stakeholder-sim onboarding (Matías canonical) ===\n";
$body .= "captured: " . gmdate( 'c' ) . "\n";
$body .= "persona phone: " . $alice['phone'] . " (logged in via WA bridge in the harness)\n";
$body .= "default competition at capture time: " . Mantia_Competitions::default_id() . "\n\n";
$body .= $transcript_str . "\n";
file_put_contents( $out_path, $body );
fwrite( STDOUT, "    · transcript dumped to: $out_path\n" );
fwrite( STDOUT, "    · pass it to the mantia-stakeholder-sim subagent for the lived-UX pass:\n" );
fwrite( STDOUT, "    ·   Agent(subagent_type='mantia-stakeholder-sim', prompt='Read $out_path and emit the punch list.')\n" );

Mantia_E2E::finish();
