<?php
/**
 * Leaderboard presentation helpers.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Leaderboard {

	public static function rows( int $group_id = 0, int $limit = 20 ): array {
		$rows = Mantia_Repository::leaderboard( $group_id );
		return array_slice( $rows, 0, max( 1, $limit ) );
	}

	public static function render_table( int $group_id = 0, int $limit = 20 ): string {
		$rows = self::rows( $group_id, $limit );
		if ( empty( $rows ) ) {
			return '<p class="mantia-standings-empty">' . esc_html__( 'Todavia no hay puntajes.', 'mantia' ) . '</p>';
		}

		ob_start();
		?>
		<table class="mantia-standings-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( '#', 'mantia' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Jugador', 'mantia' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Pts', 'mantia' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Exactos', 'mantia' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row['rank'] ); ?></td>
						<td><?php echo esc_html( (string) $row['name'] ); ?></td>
						<td><?php echo esc_html( (string) $row['points'] ); ?></td>
						<td><?php echo esc_html( (string) $row['exacts'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}
}
