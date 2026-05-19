<?php
/**
 * QA platform — WhatsApp turn simulator.
 *
 * Invoked via `wp eval-file bin/qa-sim.php` with a JSON document on stdin
 * describing one or more operations. Same code path as the production
 * webhook: Mantia_Whatsapp_Flow::maybe_handle_command receives the same
 * shape openclaWP would build from an inbound Cloud-API message.
 *
 * Input shape (stdin):
 *   {
 *     "operations": [
 *       { "type": "send", "phone": "999900001", "name": "QA Owner", "message": "hola" },
 *       { "type": "send", "phone": "999900001", "name": "QA Owner", "message": "crear penca" },
 *       { "type": "state", "phone": "999900001" }
 *     ]
 *   }
 *
 * Output shape (stdout):
 *   {
 *     "results": [
 *       { "type": "send", "ok": true, "reply": "...", "interactive": {...}, "elapsed_ms": 412 },
 *       ...
 *     ]
 *   }
 *
 * Safety: every operation MUST use a phone that begins with `9999000`. The
 * script refuses anything else so QA traffic can never collide with real
 * user data on a shared install. Cleanup matches the same prefix.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Mantia_Whatsapp_Flow' ) ) {
	fwrite( STDERR, "Mantia plugin not loaded\n" );
	exit( 1 );
}

const QA_TEST_PHONE_PREFIX = '9999000';

function qa_safe_phone( string $phone ): bool {
	return str_starts_with( $phone, QA_TEST_PHONE_PREFIX );
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

	$turn = array(
		'agent_slug'      => 'mantia',
		'message'         => $msg,
		'runtime_context' => array(
			'client_context' => array(
				'sender_id'   => $phone,
				'sender_name' => $name,
			),
		),
	);

	$result = Mantia_Whatsapp_Flow::maybe_handle_command( null, $turn );
	if ( ! is_array( $result ) ) {
		$result = array( 'reply' => null, 'interactive' => null, 'fell_through_to_llm' => true );
	}
	$elapsed = (int) round( ( microtime( true ) - $start ) * 1000 );

	return array(
		'type'        => 'send',
		'ok'          => true,
		'phone'       => $phone,
		'message'     => $msg,
		'reply'       => (string) ( $result['reply'] ?? '' ),
		'interactive' => $result['interactive'] ?? null,
		'fell_through_to_llm' => ! empty( $result['fell_through_to_llm'] ),
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
		default:
			$results[] = array( 'type' => $type, 'ok' => false, 'error' => 'unknown op type' );
	}
}

echo wp_json_encode( array( 'results' => $results ) ) . "\n";
