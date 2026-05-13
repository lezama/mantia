<?php
/**
 * Group standings block render.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

$group_id = isset( $attributes['groupId'] ) ? (int) $attributes['groupId'] : 0;
$limit    = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 20;

if ( 0 === $group_id && ! empty( $attributes['inviteCode'] ) ) {
	$group = Mantia_Repository::find_group_by_invite_code( (string) $attributes['inviteCode'] );
	if ( $group ) {
		$group_id = (int) $group->ID;
	}
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'mantia-standings mantia-group-standings' ) ); ?>>
	<?php echo wp_kses_post( Mantia_Leaderboard::render_table( $group_id, $limit ) ); ?>
</div>
