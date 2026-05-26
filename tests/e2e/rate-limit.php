<?php
/**
 * Per-phone inbound rate limit kicks in before the LLM budget burns.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Rate limit: phone-scoped throttle' );

Mantia_E2E::cleanup();

$alice = Mantia_E2E::persona( 'Alice', 1 );

// Force a small cap so the test runs in <1s and survives default tweaks.
// The plugin's prod default is 40; we pin to 5 here so the loop is short.
add_filter( 'mantia_rate_limit_max', static fn(): int => 5, 99 );

Mantia_E2E::step( '1. First N messages pass through' );
$max = (int) apply_filters( 'mantia_rate_limit_max', 5 );
for ( $i = 1; $i <= $max; $i++ ) {
	$r = Mantia_Whatsapp_Flow::maybe_handle_command(
		null,
		array(
			'agent_slug'      => 'mantia',
			'message'         => "hola {$i}",
			'runtime_context' => array( 'client_context' => array( 'sender_id' => $alice['phone'] ) ),
		)
	);
	$reply = is_array( $r ) ? (string) ( $r['reply'] ?? '' ) : '';
	if ( false !== stripos( $reply, 'muchos mensajes' ) ) {
		Mantia_E2E::assert_eq( $max + 1, $i, "throttled too early (at #{$i})" );
		break;
	}
}

Mantia_E2E::step( '2. Message N+1 is throttled' );
$r = Mantia_Whatsapp_Flow::maybe_handle_command(
	null,
	array(
		'agent_slug'      => 'mantia',
		'message'         => 'hola overflow',
		'runtime_context' => array( 'client_context' => array( 'sender_id' => $alice['phone'] ) ),
	)
);
Mantia_E2E::assert_contains(
	is_array( $r ) ? $r : array( 'reply' => '' ),
	'muchos mensajes',
	'over-cap message is throttled'
);

Mantia_E2E::step( '3. Other phones are not affected' );
$bob = Mantia_E2E::persona( 'Bob', 2 );
$r = Mantia_Whatsapp_Flow::maybe_handle_command(
	null,
	array(
		'agent_slug'      => 'mantia',
		'message'         => 'hola',
		'runtime_context' => array( 'client_context' => array( 'sender_id' => $bob['phone'] ) ),
	)
);
Mantia_E2E::assert_not_contains(
	is_array( $r ) ? $r : array( 'reply' => '' ),
	'muchos mensajes',
	'bob has his own budget'
);

Mantia_E2E::step( '4. Cleanup' );
// Reset the rate-limit transient for alice so subsequent tests start fresh.
delete_transient( 'mantia_rl_' . md5( $alice['phone'] ) );
delete_transient( 'mantia_rl_' . md5( $bob['phone'] ) );
Mantia_E2E::cleanup();

Mantia_E2E::finish();
