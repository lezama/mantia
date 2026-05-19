<?php
/**
 * QA platform — WhatsApp turn simulator.
 *
 * Invoked via `wp eval-file bin/qa-sim.php` with a JSON document on stdin
 * describing one or more operations. Uses `OpenclaWP_Runner::run_turn()` —
 * the same code path that openclawp's HTTP webhook handler runs in prod.
 * That means Mantia's deterministic router fires AND, on router-miss, the
 * LLM agent runs. Both halves of the conversation flow are exercised
 * exactly as a real WhatsApp turn would be.
 *
 * Input shape (stdin):
 *   {
 *     "operations": [
 *       { "type": "send", "phone": "999900001", "name": "QA Owner", "message": "hola" },
 *       { "type": "send", "phone": "999900001", "name": "QA Owner", "message": "crear penca" },
 *       { "type": "state", "phone": "999900001" },
 *       { "type": "reset_session", "phone": "999900001" }
 *     ]
 *   }
 *
 * Sessions are stashed per-phone in transients so multi-turn conversations
 * within the SAME persona share context (LLM remembers prior turns). Use
 * "reset_session" to start fresh.
 *
 * Output shape (stdout):
 *   {
 *     "results": [
 *       {
 *         "type": "send",
 *         "ok": true,
 *         "reply": "...",
 *         "interactive": {...},
 *         "via": "router" | "llm" | "preflight",
 *         "session_id": "...",
 *         "elapsed_ms": 412
 *       },
 *       ...
 *     ]
 *   }
 *
 * Safety: every phone MUST start with `9999000`. Other phones are refused.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

const QA_TEST_PHONE_PREFIX = '9999000';
const QA_SESSION_TTL       = 30 * MINUTE_IN_SECONDS;

if ( ! class_exists( 'OpenclaWP_Runner' ) ) {
	fwrite( STDERR, "OpenclaWP_Runner not loaded — is openclawp plugin active?\n" );
	exit( 1 );
}
if ( ! class_exists( 'Mantia_Whatsapp_Flow' ) ) {
	fwrite( STDERR, "Mantia plugin not loaded\n" );
	exit( 1 );
}

function qa_safe_phone( string $phone ): bool {
	return str_starts_with( $phone, QA_TEST_PHONE_PREFIX );
}

function qa_session_key( string $phone ): string {
	return 'qa_sim_session_' . md5( $phone );
}

/**
 * Mantia returns an array from openclawp_pre_chat_turn whenever the
 * deterministic router matched. If the array is identical to the
 * runner's result reply, we know the LLM didn't run — useful for the
 * "via" field in the result.
 */
function qa_classify_via( array $runner_result, string $phone, string $message ): string {
	// Replay the filter ourselves to see whether the deterministic path
	// would have caught the message. If it would have AND the reply
	// matches, classify as "router". Otherwise the LLM ran.
	$preflight = apply_filters(
		'openclawp_pre_chat_turn',
		null,
		array(
			'agent_slug'      => Mantia_Agent::SLUG,
			'message'         => $message,
			'session_id'      => '',
			'user_id'         => 0,
			'runtime_context' => array(
				'client_context' => array(
					'sender_id'   => $phone,
					'sender_name' => 'QA',
				),
			),
		)
	);
	if ( is_array( $preflight ) && '' !== (string) ( $preflight['reply'] ?? '' ) ) {
		return 'router';
	}
	return 'llm';
}

function qa_op_send( array $op ): array {
	$start = microtime( true );
	$phone = (string) ( $op['phone'] ?? '' );
	$name  = (string) ( $op['name'] ?? '' );
	$msg   = (string) ( $op['message'] ?? '' );

	if ( ! qa_safe_phone( $phone ) ) {
		return array(
			'type' => 'send',
			'ok'   => false,
			'error' => sprintf( 'unsafe phone "%s" — must start with %s', $phone, QA_TEST_PHONE_PREFIX ),
		);
	}

	$session_id = (string) get_transient( qa_session_key( $phone ) );
	if ( '' === $session_id ) {
		$session_id = null;
	}

	$runtime_context = array(
		'client_context' => array(
			'sender_id'   => $phone,
			'sender_name' => $name,
			'platform'    => 'whatsapp',
		),
	);

	$result = OpenclaWP_Runner::run_turn(
		Mantia_Agent::SLUG,
		$msg,
		$session_id,
		0, // user_id = 0 → anonymous-WP user; identity is via phone in runtime_context
		$runtime_context
	);

	// Persist session id so the next call within this persona maintains
	// LLM context (the deterministic router is stateless either way).
	if ( ! empty( $result['session_id'] ) ) {
		set_transient( qa_session_key( $phone ), (string) $result['session_id'], QA_SESSION_TTL );
	}

	$reply       = (string) ( $result['reply'] ?? '' );
	$interactive = $result['interactive'] ?? null;
	$elapsed     = (int) round( ( microtime( true ) - $start ) * 1000 );

	// Classify how the reply was produced. ~10ms suggests router; anything
	// >300ms with an empty interactive payload is almost certainly LLM.
	// We classify by replaying the filter — accurate even when the LLM
	// happens to be fast (e.g., short reply, warm cache).
	$via = qa_classify_via( $result, $phone, $msg );

	return array(
		'type'        => 'send',
		'ok'          => true,
		'phone'       => $phone,
		'message'     => $msg,
		'reply'       => $reply,
		'interactive' => $interactive,
		'via'         => $via,
		'session_id'  => (string) ( $result['session_id'] ?? '' ),
		'error'       => isset( $result['error'] ) ? (string) $result['error'] : null,
		'elapsed_ms'  => $elapsed,
	);
}

function qa_op_state( array $op ): array {
	$phone = (string) ( $op['phone'] ?? '' );
	if ( ! qa_safe_phone( $phone ) ) {
		return array( 'type' => 'state', 'ok' => false, 'error' => 'unsafe phone' );
	}

	$user_post = Mantia_Repository::find_user_by_phone( $phone );
	if ( ! $user_post ) {
		return array(
			'type'    => 'state',
			'ok'      => true,
			'phone'   => $phone,
			'exists'  => false,
		);
	}
	$user_id = (int) $user_post->ID;
	$groups  = (array) get_post_meta( $user_id, Mantia_Repository::META_GROUP_IDS, true );

	$group_summary = array();
	foreach ( array_map( 'intval', $groups ) as $gid ) {
		if ( $gid <= 0 ) {
			continue;
		}
		$group_summary[] = array(
			'id'          => $gid,
			'name'        => (string) get_the_title( $gid ),
			'view_token'  => (string) get_post_meta( $gid, Mantia_Repository::META_GROUP_VIEW_TOKEN, true ),
			'competition' => (string) get_post_meta( $gid, Mantia_Competitions::META_KEY, true ),
		);
	}

	$pred_ids = get_posts( array(
		'post_type'      => Mantia_CPTs::PREDICTION,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => Mantia_Repository::META_USER_ID,
				'value' => $user_id,
			),
		),
	) );

	return array(
		'type'         => 'state',
		'ok'           => true,
		'phone'        => $phone,
		'exists'       => true,
		'user_id'      => $user_id,
		'display_name' => (string) get_the_title( $user_id ),
		'view_token'   => (string) get_post_meta( $user_id, Mantia_Repository::META_USER_VIEW_TOKEN, true ),
		'share_token'  => (string) get_post_meta( $user_id, Mantia_Repository::META_USER_SHARE_TOKEN, true ),
		'groups'       => $group_summary,
		'predictions'  => count( $pred_ids ),
	);
}

function qa_op_reset_session( array $op ): array {
	$phone = (string) ( $op['phone'] ?? '' );
	if ( ! qa_safe_phone( $phone ) ) {
		return array( 'type' => 'reset_session', 'ok' => false, 'error' => 'unsafe phone' );
	}
	delete_transient( qa_session_key( $phone ) );
	return array( 'type' => 'reset_session', 'ok' => true, 'phone' => $phone );
}

$input_raw = stream_get_contents( STDIN );
$input     = json_decode( (string) $input_raw, true );
if ( ! is_array( $input ) || ! is_array( $input['operations'] ?? null ) ) {
	fwrite( STDERR, "expected JSON {\"operations\":[...]}\n" );
	exit( 2 );
}

$results = array();
foreach ( $input['operations'] as $op ) {
	if ( ! is_array( $op ) ) {
		continue;
	}
	$type = (string) ( $op['type'] ?? '' );
	switch ( $type ) {
		case 'send':
			$results[] = qa_op_send( $op );
			break;
		case 'state':
			$results[] = qa_op_state( $op );
			break;
		case 'reset_session':
			$results[] = qa_op_reset_session( $op );
			break;
		default:
			$results[] = array( 'type' => $type, 'ok' => false, 'error' => 'unknown op type' );
	}
}

echo wp_json_encode( array( 'results' => $results ) ) . "\n";
