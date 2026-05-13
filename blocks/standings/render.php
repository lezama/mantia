<?php
/**
 * Global standings block render.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

$limit = isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 20;
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'mantia-standings' ) ); ?>>
	<?php echo wp_kses_post( Mantia_Leaderboard::render_table( 0, $limit ) ); ?>
</div>
