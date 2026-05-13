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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_home' ), 5 );
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

		$create_url = self::create_penca_wa_url( $comp );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $comp['name'] ) );
		?>
		<header class="mantia-hero">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p class="mantia-sub"><?php echo esc_html( self::competition_meta( $slug, (string) ( $comp['description'] ?? '' ), $matches ) ); ?></p>
			<?php if ( '' !== $create_url ) : ?>
				<a class="mantia-cta mantia-cta-create" href="<?php echo esc_url( $create_url ); ?>">
					<?php
					printf(
						/* translators: %s: competition name, e.g. "Mundial 2026". */
						esc_html__( '🆕 Crear penca de %s →', 'mantia' ),
						esc_html( $comp['name'] )
					);
					?>
				</a>
			<?php endif; ?>
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
				<?php self::render_matches_grouped_by_day( array_slice( $matches, 0, 20 ) ); ?>
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
		$comp_url = Mantia_Repository::competition_view_url( $comp_id );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $group['name'] ) );
		?>
		<header class="mantia-hero">
			<h1><?php echo esc_html( $group['name'] ); ?></h1>
			<p class="mantia-sub">
				<a class="mantia-sub-link" href="<?php echo esc_url( $comp_url ); ?>"><?php echo esc_html( $group['competition_name'] ); ?></a>
			</p>
		</header>

		<?php if ( ! empty( $group['share_url'] ) ) : ?>
			<a class="mantia-cta" href="<?php echo esc_url( $group['share_url'] ); ?>">
				🤝 Sumate a <?php echo esc_html( $group['name'] ); ?>
			</a>
		<?php endif; ?>

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

		<?php self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_user( string $token ): string {
		$user_post = Mantia_Repository::find_user_by_view_token( $token );
		if ( ! $user_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Usuario no encontrado o token inválido', 'mantia' ) );
		}

		$user_id      = (int) $user_post->ID;
		$display_name = self::display_name_for( $user_id );
		$groups       = Mantia_Repository::user_groups_to_array( $user_id );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $display_name ) );
		?>
		<div class="mantia-private-badge">🔒 link privado — no lo compartas</div>

		<header class="mantia-hero">
			<h1><?php printf( esc_html__( 'Hola %s', 'mantia' ), esc_html( $display_name ) ); ?></h1>
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
					<?php self::render_matches_grouped_by_day( array_slice( array_values( $pending ), 0, 12 ) ); ?>
				<?php endif; endif; ?>
			</section>
		<?php endforeach; endif; ?>

		<?php self::page_footer();
		return (string) ob_get_clean();
	}

	/**
	 * Hijack the site's front page with a black landing that's just one thing
	 * to do: scan the QR and open WhatsApp with a primed first message.
	 *
	 * Site owners that want their own homepage can opt out:
	 *   add_filter( 'mantia_render_home', '__return_false' );
	 */
	public static function maybe_render_home(): void {
		if ( ! is_front_page() || is_paged() ) {
			return;
		}
		if ( '' !== (string) get_query_var( self::QUERY_VAR_VIEW ) ) {
			return;
		}
		if ( ! apply_filters( 'mantia_render_home', true ) ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::render_home();
		exit;
	}

	private static function render_home(): string {
		$phone = Mantia_Repository::bot_phone_e164();
		$msg   = (string) apply_filters( 'mantia_home_first_message', 'hola' );
		$wa    = '' !== $phone ? sprintf( 'https://wa.me/%s?text=%s', $phone, rawurlencode( $msg ) ) : '';

		$site_name = get_bloginfo( 'name' );
		$title     = sprintf( 'Mantia · %s', $site_name );

		ob_start();
		?><!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#000000">
	<meta name="robots" content="index,follow">
	<title><?php echo esc_html( $title ); ?></title>
	<style><?php echo self::home_stylesheet(); ?></style>
</head>
<body class="mantia-home">
	<main class="mantia-home-wrap">
		<header class="mantia-home-head">
			<h1>Mantia</h1>
			<p><?php esc_html_e( 'penca por WhatsApp', 'mantia' ); ?></p>
		</header>

		<?php if ( '' === $wa ) : ?>
			<section class="mantia-home-card mantia-home-card--alert">
				<p><?php esc_html_e( 'El bot todavía no tiene número configurado. Pedile al admin que cargue las credenciales de WhatsApp.', 'mantia' ); ?></p>
			</section>
		<?php else : ?>
			<a class="mantia-qr-card" href="<?php echo esc_url( $wa ); ?>" aria-label="<?php esc_attr_e( 'Abrir WhatsApp con Mantia', 'mantia' ); ?>">
				<img
					class="mantia-qr-img"
					src="<?php echo esc_url( self::qr_image_url( $wa, 640 ) ); ?>"
					alt="<?php esc_attr_e( 'Código QR para chatear con Mantia por WhatsApp', 'mantia' ); ?>"
					width="320" height="320"
					loading="eager"
				>
			</a>

			<p class="mantia-home-tagline">
				<?php
				printf(
					/* translators: %s: message that will be prefilled in WhatsApp, e.g. "hola". */
					esc_html__( 'Escaneá y mandá "%s" para empezar', 'mantia' ),
					esc_html( $msg )
				);
				?>
			</p>

			<a class="mantia-home-cta" href="<?php echo esc_url( $wa ); ?>"><?php esc_html_e( 'Abrir WhatsApp', 'mantia' ); ?> →</a>

			<?php $create_url = self::create_penca_wa_url(); ?>
			<?php if ( '' !== $create_url ) : ?>
				<a class="mantia-home-secondary" href="<?php echo esc_url( $create_url ); ?>">🆕 <?php esc_html_e( 'Crear una penca', 'mantia' ); ?> →</a>
			<?php endif; ?>
		<?php endif; ?>

		<footer class="mantia-home-foot">
			<a href="<?php echo esc_url( Mantia_Repository::competition_view_url( Mantia_Competitions::default_id() ) ); ?>"><?php esc_html_e( 'ver el ranking', 'mantia' ); ?></a>
		</footer>
	</main>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build a wa.me deeplink that prefills the WhatsApp draft with a "crear"
	 * intent. When a competition is provided we hint the bot's preflight
	 * regex; otherwise the bot will show the competition picker on tap.
	 *
	 * @param array<string,mixed>|null $competition Optional competition row.
	 */
	private static function create_penca_wa_url( ?array $competition = null ): string {
		$phone = Mantia_Repository::bot_phone_e164();
		if ( '' === $phone ) {
			return '';
		}
		$text = null !== $competition && ! empty( $competition['name'] )
			? sprintf( 'Crear penca de %s', $competition['name'] )
			: 'Crear penca';
		return sprintf( 'https://wa.me/%s?text=%s', $phone, rawurlencode( $text ) );
	}

	/**
	 * Build a QR-image URL. Default backend is api.qrserver.com — a free,
	 * long-lived service. Sites that prefer self-hosting can swap via the
	 * `mantia_qr_image_url` filter (e.g. point at a local SVG endpoint).
	 */
	private static function qr_image_url( string $payload, int $size = 600 ): string {
		$url = sprintf(
			'https://api.qrserver.com/v1/create-qr-code/?size=%1$dx%1$d&qzone=2&data=%2$s',
			max( 200, min( 1000, $size ) ),
			rawurlencode( $payload )
		);
		return (string) apply_filters( 'mantia_qr_image_url', $url, $payload, $size );
	}

	private static function home_stylesheet(): string {
		return <<<'CSS'
*,
*::before,
*::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body.mantia-home {
	min-height: 100vh;
	background: #000;
	color: #fff;
	font: 16px/1.5 -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", system-ui, sans-serif;
	-webkit-font-smoothing: antialiased;
	display: flex;
	align-items: center;
	justify-content: center;
}
.mantia-home-wrap {
	width: 100%;
	max-width: 420px;
	padding: 48px 24px;
	text-align: center;
}
.mantia-home-head h1 {
	margin: 0 0 6px;
	font-size: 36px;
	font-weight: 700;
	letter-spacing: -0.02em;
}
.mantia-home-head p {
	margin: 0 0 36px;
	font-size: 14px;
	color: #8c8c92;
	text-transform: lowercase;
	letter-spacing: 0.08em;
}
.mantia-qr-card {
	display: inline-block;
	background: #fff;
	padding: 18px;
	border-radius: 22px;
	line-height: 0;
	box-shadow: 0 0 0 1px rgba(255,255,255,0.06);
	transition: transform 0.15s ease;
}
.mantia-qr-card:hover,
.mantia-qr-card:focus-visible {
	transform: scale(1.02);
	outline: none;
}
.mantia-qr-img {
	display: block;
	width: 320px;
	max-width: 70vmin;
	height: auto;
	margin: 0;
	background: #fff;
}
.mantia-home-tagline {
	margin: 28px 0 22px;
	font-size: 17px;
	color: #ededed;
}
.mantia-home-cta {
	display: inline-block;
	padding: 14px 28px;
	background: #25d366;
	color: #000;
	font-size: 16px;
	font-weight: 600;
	text-decoration: none;
	border-radius: 999px;
	transition: filter 0.15s ease;
}
.mantia-home-cta:hover,
.mantia-home-cta:focus-visible {
	filter: brightness(1.05);
	outline: none;
}
.mantia-home-secondary {
	display: inline-block;
	margin-top: 14px;
	padding: 10px 18px;
	color: #ededed;
	text-decoration: none;
	font-size: 14px;
	border: 1px solid #2a2a2e;
	border-radius: 999px;
	transition: border-color 0.15s ease, color 0.15s ease;
}
.mantia-home-secondary:hover,
.mantia-home-secondary:focus-visible {
	color: #fff;
	border-color: #5a5a5e;
	outline: none;
}
.mantia-home-card {
	background: #111;
	color: #bbb;
	border: 1px solid #222;
	border-radius: 14px;
	padding: 16px 18px;
	margin: 0 0 20px;
	font-size: 14px;
}
.mantia-home-card--alert {
	border-color: #5a2a2a;
	color: #ddd;
}
.mantia-home-foot {
	margin-top: 44px;
	font-size: 13px;
	color: #5a5a5e;
}
.mantia-home-foot a {
	color: inherit;
	text-decoration: none;
	border-bottom: 1px dotted #3a3a3e;
	padding-bottom: 1px;
}
.mantia-home-foot a:hover { color: #aaa; }
@media (max-width: 380px) {
	.mantia-qr-img { width: 260px; }
	.mantia-home-head h1 { font-size: 32px; }
}
CSS;
	}

	private static function render_not_found( string $message ): string {
		$bot_phone = Mantia_Repository::bot_phone_e164();
		$bot_url   = '' !== $bot_phone ? sprintf( 'https://wa.me/%s?text=ayuda', $bot_phone ) : '';
		$home_url  = Mantia_Repository::competition_view_url( Mantia_Competitions::default_id() );

		ob_start();
		self::page_header( __( 'No encontrado', 'mantia' ) );
		?>
		<header class="mantia-hero">
			<h1>🤔</h1>
			<p class="mantia-sub"><?php echo esc_html( $message ); ?></p>
		</header>
		<section class="mantia-section">
			<p><?php esc_html_e( 'Probá una de estas:', 'mantia' ); ?></p>
			<div class="mantia-recovery">
				<a class="mantia-cta" href="<?php echo esc_url( $home_url ); ?>">🏆 Ver Mundial 2026</a>
				<?php if ( '' !== $bot_url ) : ?>
					<a class="mantia-cta mantia-cta-secondary" href="<?php echo esc_url( $bot_url ); ?>">💬 Hablar con el bot</a>
				<?php endif; ?>
			</div>
			<p class="mantia-tip"><?php esc_html_e( 'Si te mandaron un link, pediles que te lo reenvíen — algunos vencen.', 'mantia' ); ?></p>
		</section>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	/**
	 * Resolve a friendly display name for a user. If they never set one we
	 * fall back to "jugador/a" rather than showing the raw E.164 phone in
	 * a greeting — the phone is the post_title only because nothing else
	 * was known at signup.
	 */
	private static function display_name_for( int $user_id ): string {
		$title = (string) get_the_title( $user_id );
		$phone = (string) get_post_meta( $user_id, Mantia_Repository::META_PHONE, true );
		if ( '' !== $title && $title !== $phone ) {
			return $title;
		}
		return __( 'jugador', 'mantia' );
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
.mantia-sub-link {
	color: var(--fg-dim);
	text-decoration: none;
	border-bottom: 1px dotted var(--fg-dim);
}
.mantia-sub-link:hover {
	color: var(--accent);
	border-bottom-color: var(--accent);
}
.mantia-cta {
	display: inline-block;
	background: var(--accent);
	color: #0b0f1a;
	text-decoration: none;
	padding: 12px 18px;
	border-radius: 999px;
	font-weight: 600;
	margin: 0 0 18px;
}
.mantia-cta-secondary {
	background: transparent;
	color: var(--accent);
	border: 1px solid var(--accent);
}
.mantia-recovery {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin: 12px 0 14px;
}
.mantia-private-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: var(--fg-dim);
	background: rgba(255,255,255,0.04);
	padding: 4px 10px;
	border-radius: 999px;
	margin: 0 0 14px;
	border: 1px solid var(--border);
}
.mantia-day {
	margin: 14px 0 0;
}
.mantia-day h4 {
	margin: 0 0 6px;
	font-size: 12px;
	color: var(--fg-dim);
	text-transform: uppercase;
	letter-spacing: 0.08em;
	font-weight: 600;
}
.mantia-vs {
	color: var(--fg-dim);
	margin: 0 6px;
}
.mantia-cta {
	display: inline-block;
	padding: 10px 18px;
	background: var(--accent);
	color: var(--bg);
	font-weight: 600;
	text-decoration: none;
	border-radius: 999px;
	margin: 0 8px 8px 0;
}
.mantia-cta-secondary {
	background: transparent;
	color: var(--fg);
	border: 1px solid var(--border);
}
.mantia-cta-create {
	margin-top: 16px;
	background: var(--accent);
	color: var(--bg);
}
.mantia-cta:hover { filter: brightness(1.07); }
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

	/**
	 * Build a useful meta line under the competition title: fixture date
	 * range, total matches, custom hints for known competitions. Falls back
	 * to the plain description when we don't have anything richer.
	 */
	private static function competition_meta( string $slug, string $description, array $matches ): string {
		$hints = array(
			'mundial-2026' => '11 jun – 19 jul 2026 · 48 selecciones',
		);
		if ( isset( $hints[ $slug ] ) ) {
			return $hints[ $slug ];
		}
		if ( ! empty( $matches ) ) {
			$first = self::parse_gmt_ts( (string) $matches[0]['kickoff_gmt'] );
			$last  = self::parse_gmt_ts( (string) end( $matches )['kickoff_gmt'] );
			if ( null !== $first && null !== $last ) {
				$range = $first === $last
					? self::format_es_day( $first )
					: sprintf( '%s — %s', self::format_es_day( $first ), self::format_es_day( $last ) );
				return sprintf( '%s · %d partidos', $range, count( $matches ) );
			}
		}
		return $description;
	}

	private static function format_kickoff( string $gmt ): string {
		$ts = self::parse_gmt_ts( $gmt );
		if ( null === $ts ) {
			return $gmt;
		}
		return self::format_es_time( $ts );
	}

	private static function parse_gmt_ts( string $gmt ): ?int {
		if ( '' === $gmt ) {
			return null;
		}
		$ts = strtotime( $gmt . ( str_ends_with( $gmt, 'Z' ) ? '' : ' UTC' ) );
		return false === $ts ? null : $ts;
	}

	private static function format_es_time( int $ts_utc ): string {
		$local = $ts_utc - 3 * HOUR_IN_SECONDS; // Uruguay
		return self::es_dow( gmdate( 'w', $local ) ) . ' ' . gmdate( 'H:i', $local );
	}

	private static function format_es_day( int $ts_utc ): string {
		$local = $ts_utc - 3 * HOUR_IN_SECONDS;
		$dow   = self::es_dow_full( gmdate( 'w', $local ) );
		$day   = gmdate( 'j', $local );
		$month = self::es_month( gmdate( 'n', $local ) );
		return sprintf( '%s %s de %s', $dow, $day, $month );
	}

	private static function es_dow( string $w ): string {
		return array( 'dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb' )[ (int) $w ];
	}

	private static function es_dow_full( string $w ): string {
		return array( 'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado' )[ (int) $w ];
	}

	private static function es_month( string $n ): string {
		return array( '', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' )[ (int) $n ];
	}

	/**
	 * Render a flat list of matches grouped under day headings ("Martes 19
	 * de mayo") with kickoff times. Fixes the "homogeneous flat list" UX
	 * issue when many matches happen the same day.
	 */
	private static function render_matches_grouped_by_day( array $matches ): void {
		if ( empty( $matches ) ) {
			return;
		}

		// Group by local day key (yyyy-mm-dd in Uruguay).
		$by_day = array();
		foreach ( $matches as $m ) {
			$ts = self::parse_gmt_ts( (string) $m['kickoff_gmt'] );
			if ( null === $ts ) {
				continue;
			}
			$day_key = gmdate( 'Y-m-d', $ts - 3 * HOUR_IN_SECONDS );
			$by_day[ $day_key ][] = array( 'm' => $m, 'ts' => $ts );
		}

		foreach ( $by_day as $day_key => $entries ) {
			$first_ts = $entries[0]['ts'];
			?>
			<div class="mantia-day">
				<h4><?php echo esc_html( self::format_es_day( $first_ts ) ); ?></h4>
				<ul class="mantia-matches">
					<?php foreach ( $entries as $entry ) :
						$m = $entry['m'];
						?>
						<li>
							<span class="mantia-when"><?php echo esc_html( gmdate( 'H:i', $entry['ts'] - 3 * HOUR_IN_SECONDS ) ); ?></span>
							<strong><?php echo esc_html( $m['home_team'] ); ?></strong><span class="mantia-vs">·</span><strong><?php echo esc_html( $m['away_team'] ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}
	}
}
