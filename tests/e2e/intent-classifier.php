<?php
/**
 * Intent classifier — fuzzy router fallback.
 *
 * The deterministic regex switch already handles the canonical single-
 * word commands (tabla, partidos, pendientes, …). The classifier kicks
 * in for natural-language phrasings the regex misses, AND for messages
 * the LLM agent loop would otherwise have to handle. We stub the LLM
 * via the `mantia_intent_classify` filter so the test is deterministic
 * and free.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Intent classifier — fuzzy router fallback' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '0. Clean slate + set up Alice in a Mundial penca' );
/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::cleanup();
Mantia_E2E::require_fixture_or_skip( 'mundial-2026' );

$alice = Mantia_E2E::persona( 'Alice', 1 );
Mantia_E2E::send( $alice, 'hola' );
Mantia_E2E::send( $alice, 'mantia:cmd:new-penca' );
Mantia_E2E::send( $alice, 'mantia:newcomp:mundial-2026' );
Mantia_E2E::send( $alice, Mantia_E2E::TEST_NAME_PREFIX . ' ClassifierTest' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '1. Disabled (no key + no stub): classifier returns null cleanly' );
/* ─────────────────────────────────────────────────────────────────────── */
// No mantia_intent_classify filter set → classifier reads the API key
// option; in the test env it isn't set; returns null gracefully.
$result = Mantia_Intent_Classifier::classify( 'mostrame mis pronosticos por favor' );
Mantia_E2E::assert_true( null === $result, 'no key + no stub → null (no crash)' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '2. Stubbed intents — each routes to the right handler' );
/* ─────────────────────────────────────────────────────────────────────── */
$cases = array(
	'mostrame mis pronosticos'         => 'my_predictions',
	'dame la tabla por favor'          => 'tabla',
	'que onda con mi grupo'            => 'mis_pencas',
	'qué partidos quedan'              => 'partidos',
	'pasame el link para invitar'      => 'share',
	'que voto la gente del grupo'      => 'consenso',
	'cuales me faltan jugar'           => 'pendientes',
	'como funciona esto'               => 'help',
	'hola que tal'                     => 'home',
);

foreach ( $cases as $message => $expected_intent ) {
	add_filter( 'mantia_intent_classify', static fn() => $expected_intent, 99 );
	$got = Mantia_Intent_Classifier::classify( $message );
	Mantia_E2E::assert_eq( $expected_intent, $got, sprintf( "'%s' → %s", $message, $expected_intent ) );
	remove_all_filters( 'mantia_intent_classify', 99 );
}

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '3. Router dispatches stubbed intents — `dame la tabla` → tabla handler' );
/* ─────────────────────────────────────────────────────────────────────── */
add_filter( 'mantia_intent_classify', static fn() => 'tabla', 99 );
$r = Mantia_E2E::send( $alice, 'dame la tabla por favor' );
remove_all_filters( 'mantia_intent_classify', 99 );
Mantia_E2E::assert_contains( $r, 'Todavía no hay puntos', 'router dispatched classified `tabla` intent → leaderboard handler' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '4. `chitchat` falls through (returns null) so the agent loop takes it' );
/* ─────────────────────────────────────────────────────────────────────── */
add_filter( 'mantia_intent_classify', static fn() => 'chitchat', 99 );
$r = Mantia_E2E::send( $alice, 'que bueno el partido de ayer eh' );
remove_all_filters( 'mantia_intent_classify', 99 );
// `null` in the harness means "fell through to LLM" — verifies the
// classifier did NOT short-circuit a smalltalk message.
Mantia_E2E::assert_contains( $r, 'null', 'chitchat falls through, agent loop expected to handle it' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '5. Unknown slug from LLM is coerced to null (no crash)' );
/* ─────────────────────────────────────────────────────────────────────── */
add_filter( 'mantia_intent_classify', static fn() => 'totally_made_up_intent', 99 );
$got = Mantia_Intent_Classifier::classify( 'algo raro' );
remove_all_filters( 'mantia_intent_classify', 99 );
Mantia_E2E::assert_eq( null, $got, 'unknown slug → null' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '6. Filter `mantia_intent_classifier_enabled=false` bypasses entirely' );
/* ─────────────────────────────────────────────────────────────────────── */
add_filter( 'mantia_intent_classifier_enabled', '__return_false', 99 );
add_filter( 'mantia_intent_classify', static fn() => 'tabla', 99 );
$got = Mantia_Intent_Classifier::classify( 'dame la tabla' );
remove_all_filters( 'mantia_intent_classifier_enabled', 99 );
remove_all_filters( 'mantia_intent_classify', 99 );
Mantia_E2E::assert_eq( null, $got, 'disabled flag wins over a stubbed intent' );

/* ─────────────────────────────────────────────────────────────────────── */
Mantia_E2E::step( '7. Very long input (>240 chars) is skipped — too free-form for classifier' );
/* ─────────────────────────────────────────────────────────────────────── */
$long = str_repeat( 'che mira lo que paso ayer en la cancha ', 10 );
add_filter( 'mantia_intent_classify', static fn() => 'tabla', 99 );
$got = Mantia_Intent_Classifier::classify( $long );
remove_all_filters( 'mantia_intent_classify', 99 );
Mantia_E2E::assert_eq( null, $got, 'long input bypassed (let LLM agent take it)' );

Mantia_E2E::finish();
