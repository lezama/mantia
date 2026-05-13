<?php
/**
 * Public-facing web views for Mantia.
 *
 * Three URL patterns, all under /penca/:
 *
 *   /penca/<competition-slug>  Global ranking per competition (public)
 *   /penca/g/<group-token>     Single group view (shareable read-token)
 *   /penca/me/<user-token>     Personal view: your groups + predictions
 *
 * Tokens are random hex strings (12 bytes / 24 chars) generated lazily by
 * Mantia_Repository::group_view_token / user_view_token. They are not the
 * invite_code — invite_code is short and writeable (joining); view tokens
 * are read-only and harder to guess.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Frontend {

	private const QUERY_VAR_VIEW = 'mantia_view'; // 'competition' | 'group' | 'user'
	private const QUERY_VAR_ID   = 'mantia_view_id';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ), 11 );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	public static function register_rewrites(): void {
		add_rewrite_rule(
			'^penca/g/([a-f0-9]+)/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=group&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^penca/me/([a-f0-9]+)/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=user&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^penca/([a-z0-9][a-z0-9-]*)/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=competition&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR_VIEW;
		$vars[] = self::QUERY_VAR_ID;
		return $vars;
	}

	public static function maybe_render(): void {
		$view = get_query_var( self::QUERY_VAR_VIEW );
		$id   = get_query_var( self::QUERY_VAR_ID );
		if ( '' === $view ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		switch ( $view ) {
			case 'competition':
				echo self::render_competition( (string) $id );
				break;
			case 'group':
				echo self::render_group( (string) $id );
				break;
			case 'user':
				echo self::render_user( (string) $id );
				break;
			default:
				status_header( 404 );
				echo self::render_not_found( __( 'Página no encontrada', 'mantia' ) );
		}
		exit;
	}

	/* --------------------------- Views --------------------------- */

	private static function render_competition( string $slug ): string {
		$comp = Mantia_Competitions::get( $slug );
		if ( ! $comp ) {
			status_header( 404 );
			return self::render_not_found( sprintf( __( 'Competencia "%s" no encontrada', 'mantia' ), $slug ) );
		}

		$title    = trim( ( $comp['emoji'] ?? '' ) . ' ' . $comp['name'] );
		$rows     = Mantia_Repository::competition_leaderboard( $slug, 50 );
		$matches  = Mantia_Repository::upcoming_matches_for_competition( $slug, 24 * 30 );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $comp['name'] ) );
		?>
		<header class="mantia-hero">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p class="mantia-sub"><?php echo esc_html( $comp['description'] ?? '' ); ?></p>
		</header>

		<section class="mantia-section">
			<h2><?php esc_html_e( 'Ranking global', 'mantia' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p class="mantia-empty"><?php esc_html_e( 'Todavía no hay puntos cargados.', 'mantia' ); ?></p>
			<?php else : ?>
				<table class="mantia-table">
					<thead>
						<tr>
							<th><?php esc_html_e( '#', 'mantia' ); ?></th>
							<th><?php esc_html_e( 'Jugador', 'mantia' ); ?></th>
							<th><?php esc_html_e( 'Penca', 'mantia' ); ?></th>
							<th><?php esc_html_e( 'Pts', 'mantia' ); ?></th>
							<th><?php esc_html_e( 'Exactos', 'mantia' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo (int) $row['rank']; ?></td>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo esc_html( $row['group_name'] ); ?></td>
								<td><strong><?php echo (int) $row['points']; ?></strong></td>
								<td><?php echo (int) $row['exacts']; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<?php if ( ! empty( $matches ) ) : ?>
			<section class="mantia-section">
				<h2><?php esc_html_e( 'Próximos partidos', 'mantia' ); ?></h2>
				<ul class="mantia-matches">
					<?php foreach ( array_slice( $matches, 0, 12 ) as $m ) : ?>
						<li>
							<span class="mantia-when"><?php echo esc_html( self::format_kickoff( (string) $m['kickoff_gmt'] ) ); ?></span>
							<strong><?php echo esc_html( $m['home_team'] ); ?></strong>
							<span class="mantia-vs"> vs </span>
							<strong><?php echo esc_html( $m['away_team'] ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_group( string $token ): string {
		$group_post = Mantia_Repository::find_group_by_view_token( $token );
		if ( ! $group_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Grupo no encontrado o token inválido', 'mantia' ) );
		}

		$group_id = (int) $group_post->ID;
		$group    = Mantia_Repository::group_to_array( $group_id );
		$rows     = Mantia_Leaderboard::rows( $group_id, 50 );
		$comp_id  = Mantia_Repository::group_competition_id( $group_id );
		$matches  = Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 30 );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $group['name'] ) );
		?>
		<header class="mantia-hero">
			<h1><?php echo esc_html( $group['name'] ); ?></h1>
			<p class="mantia-sub"><?php echo esc_html( $group['competition_name'] ); ?></p>
		</header>

		<section class="mantia-section">
			<h2><?php esc_html_e( 'Tabla del grupo', 'mantia' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p class="mantia-empty"><?php esc_html_e( 'Todavía no hay puntos cargados.', 'mantia' ); ?></p>
			<?php else : ?>
				<table class="mantia-table">
					<thead>
						<tr>
							<th>#</th>
							<th><?php esc_html_e( 'Jugador', 'mantia' ); ?></th>
							<th><?php esc_html_e( 'Pts', 'mantia' ); ?></th>
							<th><?php esc_html_e( 'Exactos', 'mantia' ); ?></th>
							<th><?php esc_html_e( 'Pronosticados', 'mantia' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo (int) $row['rank']; ?></td>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><strong><?php echo (int) $row['points']; ?></strong></td>
								<td><?php echo (int) $row['exacts']; ?></td>
								<td><?php echo (int) $row['predictions']; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<?php if ( ! empty( $matches ) ) : ?>
			<section class="mantia-section">
				<h2><?php esc_html_e( 'Próximos partidos', 'mantia' ); ?></h2>
				<ul class="mantia-matches">
					<?php foreach ( array_slice( $matches, 0, 12 ) as $m ) : ?>
						<li>
							<span class="mantia-when"><?php echo esc_html( self::format_kickoff( (string) $m['kickoff_gmt'] ) ); ?></span>
							<strong><?php echo esc_html( $m['home_team'] ); ?></strong> vs <strong><?php echo esc_html( $m['away_team'] ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<footer class="mantia-tip">
			<?php if ( ! empty( $group['share_url'] ) ) : ?>
				<p><?php esc_html_e( 'Para sumarte:', 'mantia' ); ?> <a href="<?php echo esc_url( $group['share_url'] ); ?>"><?php echo esc_html( $group['share_url'] ); ?></a></p>
			<?php endif; ?>
		</footer>

		<?php self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_user( string $token ): string {
		$user_post = Mantia_Repository::find_user_by_view_token( $token );
		if ( ! $user_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Usuario no encontrado o token inválido', 'mantia' ) );
		}

		$user_id  = (int) $user_post->ID;
		$name     = get_the_title( $user_id );
		$groups   = Mantia_Repository::user_groups_to_array( $user_id );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $name ) );
		?>
		<header class="mantia-hero">
			<h1><?php printf( esc_html__( 'Hola %s', 'mantia' ), esc_html( $name ) ); ?></h1>
			<p class="mantia-sub"><?php esc_html_e( 'Tus pencas, ranking y pronósticos.', 'mantia' ); ?></p>
		</header>

		<?php if ( empty( $groups ) ) : ?>
			<p class="mantia-empty"><?php esc_html_e( 'Todavía no estás en ninguna penca.', 'mantia' ); ?></p>
		<?php else : foreach ( $groups as $g ) :
			$group_id   = (int) $g['id'];
			$rows       = Mantia_Leaderboard::rows( $group_id, 100 );
			$my_row     = null;
			foreach ( $rows as $r ) {
				if ( (int) $r['user_id'] === $user_id ) {
					$my_row = $r;
					break;
				}
			}
			$comp_id    = Mantia_Repository::group_competition_id( $group_id );
			$upcoming   = Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 30 );
			$active_str = ! empty( $g['is_active'] ) ? ' (activa)' : '';
			?>
			<section class="mantia-section">
				<h2><?php echo esc_html( $g['name'] ); ?><?php echo esc_html( $active_str ); ?></h2>
				<p class="mantia-sub"><?php echo esc_html( $g['competition_name'] ?? '' ); ?></p>

				<?php if ( $my_row ) : ?>
					<p class="mantia-my-row">
						<?php
						/* translators: 1: rank, 2: total groups, 3: points, 4: exact predictions count */
						printf(
							esc_html__( 'Estás %1$d° con %2$d pts (%3$d exactos)', 'mantia' ),
							(int) $my_row['rank'],
							(int) $my_row['points'],
							(int) $my_row['exacts']
						);
						?>
					</p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Tus pronósticos', 'mantia' ); ?></h3>
				<?php
				$my_history = Mantia_Repository::user_history( $user_id, $group_id );
				if ( empty( $my_history ) ) :
				?>
					<p class="mantia-empty"><?php esc_html_e( 'Sin pronósticos todavía.', 'mantia' ); ?></p>
				<?php else : ?>
					<table class="mantia-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Partido', 'mantia' ); ?></th>
								<th><?php esc_html_e( 'Tu pronóstico', 'mantia' ); ?></th>
								<th><?php esc_html_e( 'Resultado', 'mantia' ); ?></th>
								<th><?php esc_html_e( 'Pts', 'mantia' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $my_history as $p ) :
								$m = $p['match'] ?? array();
								if ( empty( $m ) ) {
									continue;
								}
								$real = ( null !== $m['home_score'] && null !== $m['away_score'] )
									? sprintf( '%d-%d', (int) $m['home_score'], (int) $m['away_score'] )
									: '—';
								?>
								<tr>
									<td><?php echo esc_html( $m['home_team'] . ' vs ' . $m['away_team'] ); ?></td>
									<td><?php printf( '%d-%d', (int) $p['home_score'], (int) $p['away_score'] ); ?></td>
									<td><?php echo esc_html( $real ); ?></td>
									<td><?php echo $p['scored'] ? (int) $p['points'] : '—'; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( ! empty( $upcoming ) ) :
					$pending = array_filter( $upcoming, static fn ( $m ) => ! Mantia_Repository::find_prediction( $user_id, (int) $m['id'], $group_id ) );
					if ( ! empty( $pending ) ) :
				?>
					<h3><?php esc_html_e( 'Pendientes de pronosticar', 'mantia' ); ?></h3>
					<ul class="mantia-matches">
						<?php foreach ( array_slice( $pending, 0, 10 ) as $m ) : ?>
							<li>
								<span class="mantia-when"><?php echo esc_html( self::format_kickoff( (string) $m['kickoff_gmt'] ) ); ?></span>
								<strong><?php echo esc_html( $m['home_team'] ); ?></strong> vs <strong><?php echo esc_html( $m['away_team'] ); ?></strong>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; endif; ?>
			</section>
		<?php endforeach; endif; ?>

		<footer class="mantia-tip">
			<p><?php esc_html_e( 'Este link es privado — no lo compartas.', 'mantia' ); ?></p>
		</footer>

		<?php self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_not_found( string $message ): string {
		ob_start();
		self::page_header( __( 'No encontrado', 'mantia' ) );
		?>
		<header class="mantia-hero">
			<h1>404</h1>
			<p class="mantia-sub"><?php echo esc_html( $message ); ?></p>
		</header>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	/* --------------------------- Layout --------------------------- */

	private static function page_header( string $title ): void {
		?><!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $title ); ?></title>
	<style><?php echo self::stylesheet(); ?></style>
</head>
<body>
	<main class="mantia-wrap">
		<?php
	}

	private static function page_footer(): void {
		?>
		<footer class="mantia-foot">
			<p><a href="<?php echo esc_url( home_url() ); ?>">mantia</a></p>
		</footer>
	</main>
</body>
</html>
		<?php
	}

	private static function stylesheet(): string {
		return <<<'CSS'
:root {
	--bg: #0b0f1a;
	--bg-card: #131a2c;
	--fg: #e6e9f2;
	--fg-dim: #9aa3b9;
	--accent: #5dd6a4;
	--border: #1f2740;
}
* { box-sizing: border-box; }
body {
	margin: 0;
	background: var(--bg);
	color: var(--fg);
	font: 16px/1.55 -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", system-ui, sans-serif;
}
.mantia-wrap {
	max-width: 720px;
	margin: 0 auto;
	padding: 32px 18px 64px;
}
.mantia-hero h1 {
	margin: 0 0 6px;
	font-size: 28px;
	letter-spacing: -0.01em;
}
.mantia-sub {
	margin: 0 0 24px;
	color: var(--fg-dim);
}
.mantia-section {
	background: var(--bg-card);
	border: 1px solid var(--border);
	border-radius: 14px;
	padding: 18px 18px 22px;
	margin: 0 0 18px;
}
.mantia-section h2 {
	margin: 0 0 12px;
	font-size: 18px;
}
.mantia-section h3 {
	margin: 18px 0 8px;
	font-size: 14px;
	color: var(--fg-dim);
	text-transform: uppercase;
	letter-spacing: 0.06em;
}
.mantia-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}
.mantia-table th, .mantia-table td {
	padding: 8px 10px;
	border-bottom: 1px solid var(--border);
	text-align: left;
}
.mantia-table thead th {
	font-size: 11px;
	color: var(--fg-dim);
	text-transform: uppercase;
	letter-spacing: 0.08em;
	font-weight: 600;
}
.mantia-table tbody tr:last-child td {
	border-bottom: none;
}
.mantia-empty {
	color: var(--fg-dim);
	margin: 6px 0 0;
}
.mantia-matches {
	list-style: none;
	margin: 0;
	padding: 0;
}
.mantia-matches li {
	padding: 8px 0;
	border-bottom: 1px solid var(--border);
}
.mantia-matches li:last-child { border-bottom: none; }
.mantia-when {
	display: inline-block;
	min-width: 130px;
	color: var(--fg-dim);
	font-size: 13px;
}
.mantia-my-row {
	background: rgba(93, 214, 164, 0.1);
	border-left: 3px solid var(--accent);
	padding: 8px 12px;
	margin: 0 0 12px;
	border-radius: 0 8px 8px 0;
	font-size: 14px;
}
.mantia-tip {
	color: var(--fg-dim);
	font-size: 13px;
	margin-top: 18px;
}
.mantia-tip a {
	color: var(--accent);
	word-break: break-all;
}
.mantia-foot {
	color: var(--fg-dim);
	font-size: 12px;
	text-align: center;
	margin-top: 36px;
	letter-spacing: 0.1em;
	text-transform: uppercase;
}
.mantia-foot a {
	color: var(--fg-dim);
	text-decoration: none;
}
CSS;
	}

	private static function format_kickoff( string $gmt ): string {
		if ( '' === $gmt ) {
			return '';
		}
		$ts = strtotime( $gmt . ( str_ends_with( $gmt, 'Z' ) ? '' : ' UTC' ) );
		if ( false === $ts ) {
			return $gmt;
		}
		return gmdate( 'D j M • H:i', $ts - 3 * HOUR_IN_SECONDS );
	}
}
