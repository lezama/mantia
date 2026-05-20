<?php
/**
 * QA platform — wipe test data created by simulated WhatsApp turns.
 *
 * Invoked via `wp eval-file bin/qa-cleanup.php`. Deletes every
 * mantia_user post whose phone meta starts with the QA prefix
 * (9999000), the groups those users owned, and the predictions they
 * cast. Idempotent; never touches non-test rows because the phone
 * prefix is the only selector.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

const QA_TEST_PHONE_PREFIX = '9999000';

if ( ! class_exists( 'Mantia_Repository' ) ) {
	fwrite( STDERR, "Mantia plugin not loaded\n" );
	exit( 1 );
}

// Post-Phase-6: identity is in wp_users, not mantia_user CPT. Query users
// by phone meta. role-filter would over-include if multi-tenant.
$users = get_users( array(
	'meta_key'     => Mantia_Repository::META_PHONE,
	'meta_value'   => QA_TEST_PHONE_PREFIX,
	'meta_compare' => 'LIKE',
	'number'       => -1,
	'fields'       => 'all',
) );

$deleted = array( 'users' => 0, 'groups' => 0, 'predictions' => 0 );
$group_ids_to_check = array();

foreach ( $users as $u ) {
	$uid   = (int) $u->ID;
	$phone = (string) get_user_meta( $uid, Mantia_Repository::META_PHONE, true );
	// Defensive: meta_query LIKE could in principle match non-prefix values
	// if anyone seeded weird data. Re-check before delete.
	if ( ! str_starts_with( $phone, QA_TEST_PHONE_PREFIX ) ) {
		continue;
	}
	$groups = (array) get_user_meta( $uid, Mantia_Repository::META_GROUP_IDS, true );
	foreach ( array_map( 'intval', $groups ) as $gid ) {
		if ( $gid > 0 ) {
			$group_ids_to_check[ $gid ] = true;
		}
	}

	// Find this user's predictions across all groups.
	$pred_ids = get_posts( array(
		'post_type'      => Mantia_CPTs::PREDICTION,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => Mantia_Repository::META_USER_ID,
				'value' => $uid,
			),
		),
	) );
	foreach ( $pred_ids as $pid ) {
		wp_delete_post( (int) $pid, true );
		$deleted['predictions']++;
	}

	wp_delete_user( $uid );
	$deleted['users']++;
}

// Delete groups whose ONLY members were test users. A group with a real
// user-id in its membership stays untouched — defensive against any group
// that was created by a real user but joined by a tester.
foreach ( array_keys( $group_ids_to_check ) as $gid ) {
	$members = Mantia_Repository::group_members( $gid );
	$has_real_member = false;
	foreach ( $members as $m ) {
		if ( ! str_starts_with( (string) ( $m['phone'] ?? '' ), QA_TEST_PHONE_PREFIX ) ) {
			$has_real_member = true;
			break;
		}
	}
	if ( $has_real_member ) {
		continue;
	}
	wp_delete_post( (int) $gid, true );
	$deleted['groups']++;
}

echo wp_json_encode( array(
	'ok'      => true,
	'deleted' => $deleted,
) ) . "\n";
