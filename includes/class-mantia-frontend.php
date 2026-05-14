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
 * Visual system: marfil + ink (ivory/tinta), Helvetica, golden-ratio scale,
 * hairline rules, pedestal for #1, Roman numerals for top 3. Matches the
 * Claude Design "Mantia" bundle (2026-05-14).
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

	/* =================================================================
	 * Views
	 * ================================================================= */

	private static function render_home(): string {
		$phone = Mantia_Repository::bot_phone_e164();
		$msg   = (string) apply_filters( 'mantia_home_first_message', 'hola' );
		$wa    = '' !== $phone ? sprintf( 'https://wa.me/%s?text=%s', $phone, rawurlencode( $msg ) ) : '';
		$ranking_url = Mantia_Repository::competition_view_url( Mantia_Competitions::default_id() );

		ob_start();
		self::page_header( 'Mantia · Penca por WhatsApp' );
		?>
		<main class="mantia-page mantia-home">
			<div class="mantia-home-mark">
				<h1 class="mantia-wordmark">mantia</h1>
				<div class="mantia-tagline">penca · whatsapp</div>
			</div>

			<?php if ( '' !== $wa ) : ?>
				<a class="mantia-qr-card" href="<?php echo esc_url( $wa ); ?>" aria-label="<?php esc_attr_e( 'Abrir WhatsApp con Mantia', 'mantia' ); ?>">
					<img class="mantia-qr-img" src="<?php echo esc_url( self::qr_image_url( $wa, 448 ) ); ?>"
						alt="<?php esc_attr_e( 'Código QR para chatear con Mantia por WhatsApp', 'mantia' ); ?>"
						width="224" height="224" loading="eager">
				</a>
				<p class="mantia-home-hint">
					<?php
					printf(
						/* translators: %s: literal "hola" — the prefilled WhatsApp message. */
						esc_html__( 'Escaneá con la cámara. Mandanos %s para empezar.', 'mantia' ),
						'<span class="mantia-ink">"' . esc_html( $msg ) . '"</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					);
					?>
				</p>
				<a class="mantia-pill mantia-pill-primary" href="<?php echo esc_url( $wa ); ?>">
					<?php esc_html_e( 'Abrir WhatsApp', 'mantia' ); ?>
				</a>
			<?php else : ?>
				<section class="mantia-empty-card">
					<p><?php esc_html_e( 'El bot todavía no tiene número configurado.', 'mantia' ); ?></p>
				</section>
			<?php endif; ?>

			<?php if ( '' !== $ranking_url ) : ?>
				<a class="mantia-ghost-link" href="<?php echo esc_url( $ranking_url ); ?>">
					<?php esc_html_e( 'ver el ranking', 'mantia' ); ?> →
				</a>
			<?php endif; ?>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_competition( string $slug ): string {
		$comp = Mantia_Competitions::get( $slug );
		if ( ! $comp ) {
			status_header( 404 );
			return self::render_not_found( sprintf( __( 'Competencia "%s" no encontrada', 'mantia' ), $slug ) );
		}

		$title    = $comp['name'];
		$rows     = Mantia_Repository::competition_leaderboard( $slug, 50 );
		$matches  = Mantia_Repository::upcoming_matches_for_competition( $slug, 24 * 30 );
		$create_url = self::create_penca_wa_url( $comp );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $comp['name'] ) );
		?>
		<main class="mantia-page">
			<?php self::render_topbar(); ?>

			<section class="mantia-hero">
				<div class="mantia-eyebrow"><?php esc_html_e( 'penca · global', 'mantia' ); ?></div>
				<h1 class="mantia-h1"><?php echo esc_html( $title ); ?></h1>
				<p class="mantia-hero-meta"><?php echo esc_html( self::competition_meta( $slug, (string) ( $comp['description'] ?? '' ), $matches ) ); ?></p>
			</section>

			<?php self::render_competition_nav( $slug ); ?>

			<hr class="mantia-rule">

			<?php if ( ! empty( $rows ) ) : ?>
				<section class="mantia-block">
					<div class="mantia-eyebrow-row">
						<span class="mantia-eyebrow"><?php esc_html_e( 'ranking global · top 50', 'mantia' ); ?></span>
						<span class="mantia-eyebrow-count"><?php echo esc_html( sprintf( __( 'de %d jugadores', 'mantia' ), count( $rows ) ) ); ?></span>
					</div>
					<?php self::render_leaderboard( $rows, 'competition' ); ?>
				</section>
			<?php else : ?>
				<section class="mantia-block">
					<div class="mantia-eyebrow"><?php esc_html_e( 'ranking global', 'mantia' ); ?></div>
					<p class="mantia-empty"><?php esc_html_e( 'Todavía no hay puntos cargados.', 'mantia' ); ?></p>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $matches ) ) : ?>
				<section class="mantia-block">
					<div class="mantia-eyebrow"><?php esc_html_e( 'próximos partidos', 'mantia' ); ?></div>
					<?php self::render_matches_grouped_by_day( array_slice( $matches, 0, 20 ) ); ?>
				</section>
			<?php endif; ?>

			<?php if ( '' !== $create_url ) : ?>
				<section class="mantia-cta-section">
					<a class="mantia-pill mantia-pill-primary" href="<?php echo esc_url( $create_url ); ?>">
						<?php
						printf(
							/* translators: %s: competition short name. */
							esc_html__( 'Crear penca de %s', 'mantia' ),
							esc_html( $comp['name'] )
						);
						?>
					</a>
				</section>
			<?php endif; ?>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_group( string $token ): string {
		$group_post = Mantia_Repository::find_group_by_view_token( $token );
		if ( ! $group_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Este link no funciona o ya venció.', 'mantia' ) );
		}

		$group_id   = (int) $group_post->ID;
		$group      = Mantia_Repository::group_to_array( $group_id );
		$rows       = Mantia_Leaderboard::rows( $group_id, 50 );
		$comp_id    = Mantia_Repository::group_competition_id( $group_id );
		$matches    = Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 30 );
		$comp_url   = Mantia_Repository::competition_view_url( $comp_id );
		$create_url = self::create_penca_wa_url();
		$members    = Mantia_Repository::group_members( $group_id );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $group['name'] ) );
		?>
		<main class="mantia-page">
			<?php self::render_topbar( true ); ?>

			<section class="mantia-hero">
				<a class="mantia-crumb" href="<?php echo esc_url( $comp_url ); ?>">
					<?php echo esc_html( $group['competition_name'] ?? '' ); ?> ↗
				</a>
				<h1 class="mantia-h1 mantia-h1-balance"><?php echo esc_html( $group['name'] ); ?></h1>
				<p class="mantia-hero-meta">
					<span><?php echo esc_html( sprintf( _n( '%d jugador', '%d jugadores', count( $members ), 'mantia' ), count( $members ) ) ); ?></span>
					<span class="mantia-dot"></span>
					<span><?php esc_html_e( 'fecha 1 · jornada en curso', 'mantia' ); ?></span>
				</p>
			</section>

			<?php if ( ! empty( $group['share_url'] ) ) : ?>
				<section class="mantia-cta-section">
					<a class="mantia-pill mantia-pill-primary" href="<?php echo esc_url( $group['share_url'] ); ?>">
						<?php
						printf(
							/* translators: %s: invite code. */
							esc_html__( 'Sumate · código %s', 'mantia' ),
							esc_html( (string) $group['invite_code'] )
						);
						?>
					</a>
				</section>
			<?php endif; ?>

			<hr class="mantia-rule">

			<section class="mantia-block">
				<div class="mantia-eyebrow"><?php esc_html_e( 'tabla del grupo', 'mantia' ); ?></div>
				<?php if ( empty( $rows ) ) : ?>
					<p class="mantia-empty"><?php esc_html_e( 'Todavía no hay puntos cargados.', 'mantia' ); ?></p>
				<?php else : ?>
					<?php self::render_leaderboard( $rows, 'group' ); ?>
				<?php endif; ?>
			</section>

			<?php if ( ! empty( $matches ) ) : ?>
				<section class="mantia-block">
					<div class="mantia-eyebrow"><?php esc_html_e( 'próximos partidos', 'mantia' ); ?></div>
					<?php self::render_matches_grouped_by_day( array_slice( $matches, 0, 12 ) ); ?>
				</section>
			<?php endif; ?>

			<section class="mantia-block mantia-block-scoring">
				<div class="mantia-eyebrow"><?php esc_html_e( 'cómo se puntúa', 'mantia' ); ?></div>
				<div class="mantia-scoring-rows">
					<div class="mantia-scoring-row">
						<span><?php esc_html_e( 'Resultado exacto', 'mantia' ); ?></span>
						<span class="mantia-numeral mantia-numeral-s">+5</span>
					</div>
					<div class="mantia-scoring-row">
						<span><?php esc_html_e( 'Diferencia de gol', 'mantia' ); ?></span>
						<span class="mantia-numeral mantia-numeral-s">+3</span>
					</div>
					<div class="mantia-scoring-row">
						<span><?php esc_html_e( 'Solo ganador', 'mantia' ); ?></span>
						<span class="mantia-numeral mantia-numeral-s">+1</span>
					</div>
				</div>
			</section>

			<?php if ( '' !== $create_url ) : ?>
				<p class="mantia-aside">
					<a class="mantia-ghost-link" href="<?php echo esc_url( $create_url ); ?>">
						<?php esc_html_e( 'o creá tu propia penca →', 'mantia' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_user( string $token ): string {
		$user_post = Mantia_Repository::find_user_by_view_token( $token );
		if ( ! $user_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Este link privado no funciona o ya venció.', 'mantia' ) );
		}

		$user_id      = (int) $user_post->ID;
		$display_name = self::display_name_for( $user_id );
		$groups       = Mantia_Repository::user_groups_to_array( $user_id );
		$create_url   = self::create_penca_wa_url();

		// Aggregate stats: total points + exacts + prediction count across all groups.
		$total_points = 0;
		$total_exacts = 0;
		$total_preds  = 0;
		foreach ( $groups as $g ) {
			$gid = (int) $g['id'];
			foreach ( Mantia_Leaderboard::rows( $gid, 100 ) as $row ) {
				if ( (int) $row['user_id'] === $user_id ) {
					$total_points += (int) $row['points'];
					$total_exacts += (int) $row['exacts'];
					$total_preds  += (int) $row['predictions'];
				}
			}
		}

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $display_name ) );
		?>
		<main class="mantia-page">
			<?php self::render_topbar(); ?>

			<div class="mantia-privacy-badge">
				<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="1.5"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
				<?php esc_html_e( 'link privado', 'mantia' ); ?>
			</div>

			<section class="mantia-hero-user">
				<?php echo self::user_avatar( $user_id, 64 ); ?>
				<div class="mantia-hero-user-text">
					<div class="mantia-eyebrow"><?php esc_html_e( 'hola', 'mantia' ); ?></div>
					<h1 class="mantia-h1"><?php echo esc_html( $display_name ); ?></h1>
				</div>
			</section>

			<section class="mantia-stat-grid">
				<div class="mantia-stat">
					<div class="mantia-stat-value"><?php echo (int) $total_points; ?></div>
					<div class="mantia-stat-label"><?php esc_html_e( 'puntos', 'mantia' ); ?></div>
				</div>
				<div class="mantia-stat mantia-stat-bordered">
					<div class="mantia-stat-value"><?php echo (int) $total_exacts; ?></div>
					<div class="mantia-stat-label"><?php esc_html_e( 'exactos', 'mantia' ); ?></div>
				</div>
				<div class="mantia-stat mantia-stat-bordered">
					<div class="mantia-stat-value"><?php echo (int) $total_preds; ?></div>
					<div class="mantia-stat-label"><?php esc_html_e( 'pronósticos', 'mantia' ); ?></div>
				</div>
			</section>

			<hr class="mantia-rule">

			<?php if ( empty( $groups ) ) : ?>
				<p class="mantia-empty"><?php esc_html_e( 'Todavía no estás en ninguna penca.', 'mantia' ); ?></p>
			<?php else : ?>
				<?php foreach ( $groups as $g ) :
					$group_id   = (int) $g['id'];
					$lb         = Mantia_Leaderboard::rows( $group_id, 100 );
					$my_row     = null;
					foreach ( $lb as $row ) {
						if ( (int) $row['user_id'] === $user_id ) {
							$my_row = $row;
							break;
						}
					}
					$comp_id  = Mantia_Repository::group_competition_id( $group_id );
					$upcoming = Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 30 );
					$is_active = ! empty( $g['is_active'] );
					?>
					<section class="mantia-block">
						<div class="mantia-group-head">
							<div>
								<h2 class="mantia-h2"><?php echo esc_html( $g['name'] ); ?></h2>
								<div class="mantia-hero-meta"><?php echo esc_html( $g['competition_name'] ?? '' ); ?></div>
							</div>
							<?php if ( $is_active ) : ?>
								<span class="mantia-tag-active"><?php esc_html_e( 'activa', 'mantia' ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( $my_row ) : ?>
							<div class="mantia-me-line">
								<span class="mantia-numeral mantia-numeral-m"><?php echo esc_html( self::rank_label( (int) $my_row['rank'] ) ); ?></span>
								<span class="mantia-me-rank-suffix">
									<?php
									/* translators: %d: group size */
									printf( esc_html__( 'de %d', 'mantia' ), count( $lb ) );
									?>
								</span>
								<span class="mantia-me-points-wrap">
									<span class="mantia-numeral mantia-numeral-m"><?php echo (int) $my_row['points']; ?></span>
									<span class="mantia-stat-label-inline"><?php esc_html_e( 'pts', 'mantia' ); ?></span>
								</span>
							</div>
						<?php endif; ?>

						<?php
						$my_history = Mantia_Repository::user_history( $user_id, $group_id );
						if ( ! empty( $my_history ) ) :
						?>
							<div class="mantia-subblock-eyebrow"><?php esc_html_e( 'tus pronósticos', 'mantia' ); ?></div>
							<?php self::render_history_rows( $my_history ); ?>
						<?php endif; ?>

						<?php
						if ( ! empty( $upcoming ) ) :
							$pending = array_filter(
								$upcoming,
								static fn ( $m ) => ! Mantia_Repository::find_prediction( $user_id, (int) $m['id'], $group_id )
							);
							if ( ! empty( $pending ) ) :
								?>
								<div class="mantia-subblock-eyebrow"><?php esc_html_e( 'pendientes', 'mantia' ); ?></div>
								<?php self::render_matches_grouped_by_day( array_slice( array_values( $pending ), 0, 8 ) ); ?>
							<?php
							endif;
						endif;
						?>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php if ( '' !== $create_url ) : ?>
				<p class="mantia-aside">
					<a class="mantia-ghost-link" href="<?php echo esc_url( $create_url ); ?>">
						<?php esc_html_e( '🆕 Crear otra penca →', 'mantia' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_not_found( string $message ): string {
		$bot_phone  = Mantia_Repository::bot_phone_e164();
		$bot_url    = '' !== $bot_phone ? sprintf( 'https://wa.me/%s?text=ayuda', $bot_phone ) : '';
		$home_url   = Mantia_Repository::competition_view_url( Mantia_Competitions::default_id() );
		$create_url = self::create_penca_wa_url();

		ob_start();
		self::page_header( __( 'No encontrado', 'mantia' ) );
		?>
		<main class="mantia-page mantia-page-narrow">
			<?php self::render_topbar(); ?>
			<section class="mantia-hero">
				<div class="mantia-eyebrow"><?php esc_html_e( '404', 'mantia' ); ?></div>
				<h1 class="mantia-h1"><?php echo esc_html( $message ); ?></h1>
				<p class="mantia-hero-meta"><?php esc_html_e( 'Si te mandaron un link, pediles que te lo reenvíen — algunos vencen.', 'mantia' ); ?></p>
			</section>
			<section class="mantia-cta-section mantia-cta-stack">
				<a class="mantia-pill mantia-pill-primary" href="<?php echo esc_url( $home_url ); ?>"><?php esc_html_e( 'Ver Mundial 2026', 'mantia' ); ?></a>
				<?php if ( '' !== $create_url ) : ?>
					<a class="mantia-pill mantia-pill-ghost" href="<?php echo esc_url( $create_url ); ?>"><?php esc_html_e( 'Crear una penca', 'mantia' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $bot_url ) : ?>
					<a class="mantia-pill mantia-pill-ghost" href="<?php echo esc_url( $bot_url ); ?>"><?php esc_html_e( 'Hablar con el bot', 'mantia' ); ?></a>
				<?php endif; ?>
			</section>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	/* =================================================================
	 * Component renderers
	 * ================================================================= */

	private static function render_topbar( bool $with_share = false ): void {
		?>
		<div class="mantia-topbar">
			<a class="mantia-topbar-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Inicio', 'mantia' ); ?>">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>
			</a>
			<span class="mantia-wordmark-sm">mantia</span>
			<span class="mantia-topbar-spacer"></span>
		</div>
		<?php
	}

	/**
	 * Render a horizontal chip nav of competitions on a competition page.
	 * Active chip is filled-ink, others are hairline-bordered.
	 */
	private static function render_competition_nav( string $active_slug ): void {
		$competitions = Mantia_Competitions::all();
		if ( count( $competitions ) <= 1 ) {
			return;
		}
		?>
		<nav class="mantia-chips" aria-label="<?php esc_attr_e( 'Otras competencias', 'mantia' ); ?>">
			<?php foreach ( $competitions as $c ) :
				$is_active = (string) $c['id'] === $active_slug;
				$url       = Mantia_Repository::competition_view_url( (string) $c['id'] );
				$label     = (string) $c['name'];
				$cls       = 'mantia-chip' . ( $is_active ? ' is-active' : '' );
				if ( $is_active ) :
					?>
					<span class="<?php echo esc_attr( $cls ); ?>" aria-current="page"><?php echo esc_html( $label ); ?></span>
					<?php
				else :
					?>
					<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php
				endif;
			endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Render a leaderboard with optional pedestal for the #1 row.
	 *
	 * @param array $rows    Standardized leaderboard rows (rank/name/user_id/points/exacts/predictions[/group_name]).
	 * @param string $variant 'group' (no group_name column) or 'competition' (with group_name)
	 */
	private static function render_leaderboard( array $rows, string $variant = 'group' ): void {
		if ( empty( $rows ) ) {
			return;
		}
		$leader = $rows[0];
		$rest   = array_slice( $rows, 1 );
		?>
		<div class="mantia-pedestal">
			<?php echo self::user_avatar( (int) $leader['user_id'], 56 ); ?>
			<div class="mantia-pedestal-text">
				<div class="mantia-eyebrow mantia-eyebrow-accent"><?php esc_html_e( 'I · primero', 'mantia' ); ?></div>
				<div class="mantia-pedestal-name"><?php echo esc_html( $leader['name'] ); ?></div>
				<div class="mantia-pedestal-meta">
					<?php
					printf(
						/* translators: %d: exact predictions */
						esc_html( _n( '%d exacto', '%d exactos', (int) $leader['exacts'], 'mantia' ) ),
						(int) $leader['exacts']
					);
					if ( 'competition' === $variant && ! empty( $leader['group_name'] ) ) {
						echo ' · ' . esc_html( $leader['group_name'] );
					}
					?>
				</div>
			</div>
			<div class="mantia-pedestal-points">
				<div class="mantia-numeral mantia-numeral-l"><?php echo (int) $leader['points']; ?></div>
				<div class="mantia-stat-label"><?php esc_html_e( 'pts', 'mantia' ); ?></div>
			</div>
		</div>

		<div class="mantia-board">
			<?php foreach ( $rest as $row ) : ?>
				<div class="mantia-board-row">
					<span class="mantia-rank"><?php echo esc_html( self::rank_label( (int) $row['rank'] ) ); ?></span>
					<?php echo self::user_avatar( (int) $row['user_id'], 26 ); ?>
					<div class="mantia-board-player">
						<div class="mantia-board-name"><?php echo esc_html( $row['name'] ); ?></div>
						<?php if ( 'competition' === $variant && ! empty( $row['group_name'] ) ) : ?>
							<div class="mantia-board-group"><?php echo esc_html( $row['group_name'] ); ?></div>
						<?php endif; ?>
					</div>
					<div class="mantia-board-pts"><?php echo (int) $row['points']; ?></div>
					<div class="mantia-board-exc"><?php echo (int) $row['exacts']; ?></div>
				</div>
			<?php endforeach; ?>

			<div class="mantia-board-legend mantia-board-legend-<?php echo esc_attr( $variant ); ?>">
				<span></span>
				<span></span>
				<span class="mantia-eyebrow"><?php esc_html_e( 'jugador', 'mantia' ); ?></span>
				<span class="mantia-eyebrow mantia-text-right"><?php esc_html_e( 'pts', 'mantia' ); ?></span>
				<span class="mantia-eyebrow mantia-text-right"><?php esc_html_e( 'exc', 'mantia' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render history rows (predictions vs real result) for /me/.
	 */
	private static function render_history_rows( array $history ): void {
		?>
		<div class="mantia-history">
			<?php foreach ( array_slice( $history, 0, 8 ) as $p ) :
				$m = $p['match'] ?? array();
				if ( empty( $m ) ) {
					continue;
				}
				$real_set = ( null !== $m['home_score'] && null !== $m['away_score'] );
				$points   = $p['scored'] ? (int) $p['points'] : null;
				$ts       = self::parse_gmt_ts( (string) $m['kickoff_gmt'] );
				?>
				<div class="mantia-history-row">
					<div class="mantia-history-match">
						<div class="mantia-history-teams">
							<?php echo esc_html( $m['home_team'] ); ?>
							<span class="mantia-mid">·</span>
							<?php echo esc_html( $m['away_team'] ); ?>
						</div>
						<?php if ( null !== $ts ) : ?>
							<div class="mantia-history-day"><?php echo esc_html( self::format_es_short_day( $ts ) ); ?></div>
						<?php endif; ?>
					</div>
					<div class="mantia-history-score">
						<div class="mantia-score-line"><?php printf( '%d·%d', (int) $p['home_score'], (int) $p['away_score'] ); ?></div>
						<div class="mantia-stat-label-inline"><?php esc_html_e( 'vos', 'mantia' ); ?></div>
					</div>
					<div class="mantia-history-score">
						<?php if ( $real_set ) : ?>
							<div class="mantia-score-line"><?php printf( '%d·%d', (int) $m['home_score'], (int) $m['away_score'] ); ?></div>
						<?php else : ?>
							<div class="mantia-score-line mantia-soft">—</div>
						<?php endif; ?>
						<div class="mantia-stat-label-inline"><?php esc_html_e( 'real', 'mantia' ); ?></div>
					</div>
					<div class="mantia-history-points">
						<?php if ( null !== $points ) : ?>
							<span class="mantia-numeral mantia-numeral-s <?php echo 5 === $points ? 'mantia-text-accent' : ''; ?>">
								<?php echo $points > 0 ? '+' . $points : '0'; ?>
							</span>
						<?php else : ?>
							<span class="mantia-soft">—</span>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a flat list of matches grouped under day headings ("Jueves 11 de
	 * junio") with kickoff times. Marfil hairline-row treatment matching the
	 * design.
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

		foreach ( $by_day as $entries ) :
			$first_ts = $entries[0]['ts'];
			?>
			<div class="mantia-day-group">
				<div class="mantia-day-eyebrow mantia-eyebrow"><?php echo esc_html( strtoupper( self::format_es_short_day( $first_ts ) ) ); ?></div>
				<?php foreach ( $entries as $entry ) :
					$m  = $entry['m'];
					$hm = gmdate( 'H:i', $entry['ts'] - 3 * HOUR_IN_SECONDS );
					?>
					<div class="mantia-match-row">
						<div class="mantia-match-time"><?php echo esc_html( $hm ); ?></div>
						<div class="mantia-match-teams">
							<div class="mantia-match-names">
								<?php echo esc_html( $m['home_team'] ); ?>
								<span class="mantia-mid">·</span>
								<?php echo esc_html( $m['away_team'] ); ?>
							</div>
							<?php if ( ! empty( $m['phase'] ) ) : ?>
								<div class="mantia-match-phase"><?php echo esc_html( $m['phase'] ); ?></div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		endforeach;
	}

	/* =================================================================
	 * Helpers
	 * ================================================================= */

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
			'https://api.qrserver.com/v1/create-qr-code/?size=%1$dx%1$d&qzone=1&margin=0&data=%2$s',
			max( 200, min( 1000, $size ) ),
			rawurlencode( $payload )
		);
		return (string) apply_filters( 'mantia_qr_image_url', $url, $payload, $size );
	}

	/**
	 * Avatar markup for a user. Two layers:
	 * 1. If the mantia_user post has a thumbnail attached, use it.
	 * 2. Otherwise generate a circle with the user's initials + a color
	 *    derived from a stable hash of the name.
	 *
	 * Palette: oklch low-chroma earth tones matching the marfil canvas.
	 */
	private static function user_avatar( int $user_id, int $size = 40 ): string {
		if ( has_post_thumbnail( $user_id ) ) {
			$url = (string) get_the_post_thumbnail_url( $user_id, array( $size * 2, $size * 2 ) );
			if ( '' !== $url ) {
				return sprintf(
					'<img class="mantia-avatar mantia-avatar-img" src="%s" width="%d" height="%d" alt="" loading="lazy">',
					esc_url( $url ),
					$size,
					$size
				);
			}
		}

		$name = self::display_name_for( $user_id );
		if ( '' === $name || __( 'jugador', 'mantia' ) === $name ) {
			$initials = '?';
			$seed     = 'u' . $user_id;
		} else {
			$initials = self::initials_from( $name );
			$seed     = $name;
		}

		// Stable hue + low-chroma earth tone fill/foreground in oklch.
		$hue = abs( crc32( $seed ) ) % 360;
		$bg  = sprintf( 'oklch(0.82 0.04 %d)', $hue );
		$fg  = sprintf( 'oklch(0.32 0.05 %d)', $hue );
		$style = sprintf(
			'--avatar-bg:%s;--avatar-fg:%s;width:%dpx;height:%dpx;font-size:%dpx;',
			$bg,
			$fg,
			$size,
			$size,
			(int) round( $size * 0.4 )
		);
		return sprintf(
			'<span class="mantia-avatar mantia-avatar-initials" style="%s" aria-hidden="true">%s</span>',
			esc_attr( $style ),
			esc_html( $initials )
		);
	}

	private static function initials_from( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		$out   = '';
		foreach ( array_slice( $parts, 0, 2 ) as $p ) {
			$first = function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 1 ) : substr( $p, 0, 1 );
			$out  .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first ) : strtoupper( $first );
		}
		return '' !== $out ? $out : '?';
	}

	/**
	 * Resolve a friendly display name for a user. If they never set one we
	 * fall back to "jugador/a" rather than showing the raw E.164 phone.
	 */
	private static function display_name_for( int $user_id ): string {
		$title = (string) get_the_title( $user_id );
		$phone = (string) get_post_meta( $user_id, Mantia_Repository::META_PHONE, true );
		if ( '' !== $title && $title !== $phone ) {
			return $title;
		}
		return __( 'jugador', 'mantia' );
	}

	/**
	 * Roman numerals for ranks 1-3 (Greek classical touch), arabic
	 * zero-padded for the rest.
	 */
	private static function rank_label( int $n ): string {
		static $roman = array( '', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII' );
		if ( $n <= 0 ) {
			return '—';
		}
		if ( $n <= 3 && isset( $roman[ $n ] ) ) {
			return $roman[ $n ];
		}
		return str_pad( (string) $n, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Build a useful meta line under the competition title: fixture date
	 * range, total matches, or a custom hint per slug.
	 */
	private static function competition_meta( string $slug, string $description, array $matches ): string {
		$hints = array(
			'mundial-2026' => '48 selecciones · empieza 11 jun',
		);
		if ( isset( $hints[ $slug ] ) ) {
			return $hints[ $slug ];
		}
		if ( ! empty( $matches ) ) {
			$first = self::parse_gmt_ts( (string) $matches[0]['kickoff_gmt'] );
			$last  = self::parse_gmt_ts( (string) end( $matches )['kickoff_gmt'] );
			if ( null !== $first && null !== $last ) {
				$range = $first === $last
					? self::format_es_short_day( $first )
					: sprintf( '%s – %s', self::format_es_short_day( $first ), self::format_es_short_day( $last ) );
				return sprintf( '%s · %d partidos', $range, count( $matches ) );
			}
		}
		return $description;
	}

	/* ----------- Date / time formatters (UY local = UTC-3) ------------ */

	private static function parse_gmt_ts( string $gmt ): ?int {
		if ( '' === $gmt ) {
			return null;
		}
		$ts = strtotime( $gmt . ( str_ends_with( $gmt, 'Z' ) ? '' : ' UTC' ) );
		return false === $ts ? null : $ts;
	}

	private static function format_es_short_day( int $ts_utc ): string {
		$local = $ts_utc - 3 * HOUR_IN_SECONDS;
		$dow   = self::es_dow( gmdate( 'w', $local ) );
		$day   = gmdate( 'j', $local );
		$month = self::es_month_short( gmdate( 'n', $local ) );
		return sprintf( '%s %s %s', $dow, $day, $month );
	}

	private static function es_dow( string $w ): string {
		return array( 'dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb' )[ (int) $w ];
	}

	private static function es_month_short( string $n ): string {
		return array( '', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' )[ (int) $n ];
	}

	/* =================================================================
	 * Page chrome + stylesheet
	 * ================================================================= */

	private static function page_header( string $title ): void {
		?><!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#f5f1e8">
	<title><?php echo esc_html( $title ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<style><?php echo self::stylesheet(); ?></style>
</head>
<body>
		<?php
	}

	private static function page_footer(): void {
		?>
		<footer class="mantia-foot">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">mantia</a> · penca por whatsapp
		</footer>
</body>
</html>
		<?php
	}

	private static function stylesheet(): string {
		return <<<'CSS'
/* Mantia · marfil + Helvetica · golden-ratio scale (11/13/16/21/34/55) */

:root {
	--bg: #f5f1e8;
	--ink: #14130f;
	--ink-soft: #6e6a5f;
	--rule: rgba(20,19,15,0.10);
	--field: #ebe5d6;
	--accent: #8a6a3a;

	--font-display: Helvetica, "Helvetica Neue", Arial, system-ui, sans-serif;
	--font-body:    Helvetica, "Helvetica Neue", Arial, system-ui, sans-serif;
}

* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; min-height: 100%; }
body {
	background: var(--bg);
	color: var(--ink);
	font-family: var(--font-body);
	font-size: 16px;
	line-height: 1.5;
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
	text-rendering: optimizeLegibility;
}

/* ─── Layout ─────────────────────────────────────────────────────── */

.mantia-page {
	max-width: 560px;
	margin: 0 auto;
	padding: 0 22px 56px;
}
.mantia-page-narrow { max-width: 420px; }

.mantia-topbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 32px 0 16px;
}
.mantia-topbar-btn {
	width: 38px; height: 38px;
	border: 1px solid var(--rule);
	border-radius: 999px;
	display: inline-flex; align-items: center; justify-content: center;
	color: var(--ink);
	text-decoration: none;
	background: transparent;
	cursor: pointer;
}
.mantia-topbar-btn:hover { border-color: var(--ink); }
.mantia-topbar-spacer { width: 38px; }
.mantia-wordmark-sm {
	font-family: var(--font-display);
	font-weight: 500;
	font-size: 15px;
	letter-spacing: -0.04em;
	color: var(--ink);
}

/* ─── Typography ─────────────────────────────────────────────────── */

.mantia-h1 {
	font-family: var(--font-display);
	font-weight: 500;
	font-size: 34px;
	line-height: 1.05;
	letter-spacing: -0.035em;
	color: var(--ink);
	margin: 8px 0 0;
}
.mantia-h1-balance { text-wrap: balance; }
.mantia-h2 {
	font-family: var(--font-display);
	font-weight: 500;
	font-size: 21px;
	line-height: 1.15;
	letter-spacing: -0.02em;
	color: var(--ink);
	margin: 0;
}
.mantia-eyebrow {
	font-family: var(--font-body);
	font-size: 10.5px;
	font-weight: 500;
	letter-spacing: 0.18em;
	text-transform: uppercase;
	color: var(--ink-soft);
}
.mantia-eyebrow-accent { color: var(--accent); }
.mantia-eyebrow-row {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	margin: 0 0 16px;
}
.mantia-eyebrow-count {
	font-family: var(--font-body);
	font-size: 11.5px;
	color: var(--ink-soft);
	letter-spacing: 0.02em;
}
.mantia-hero {
	padding: 8px 0 22px;
}
.mantia-hero-meta {
	margin: 8px 0 0;
	color: var(--ink-soft);
	font-size: 14px;
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}
.mantia-dot {
	width: 3px; height: 3px;
	background: var(--rule);
	border-radius: 50%;
	display: inline-block;
}
.mantia-crumb {
	font-family: var(--font-body);
	font-size: 12.5px;
	font-weight: 500;
	letter-spacing: 0.16em;
	text-transform: uppercase;
	color: var(--ink-soft);
	text-decoration: none;
}
.mantia-crumb:hover { color: var(--ink); }

.mantia-numeral {
	font-family: var(--font-display);
	font-weight: 400;
	font-variant-numeric: tabular-nums;
	color: var(--ink);
	line-height: 0.9;
	letter-spacing: -0.045em;
}
.mantia-numeral-s { font-size: 17px; line-height: 1; }
.mantia-numeral-m { font-size: 22px; }
.mantia-numeral-l { font-size: 48px; }

.mantia-stat-label {
	font-family: var(--font-body);
	font-size: 10.5px;
	letter-spacing: 0.16em;
	text-transform: uppercase;
	color: var(--ink-soft);
	margin-top: 6px;
}
.mantia-stat-label-inline {
	font-family: var(--font-body);
	font-size: 10.5px;
	letter-spacing: 0.16em;
	text-transform: uppercase;
	color: var(--ink-soft);
}
.mantia-text-accent { color: var(--accent) !important; }
.mantia-text-right { text-align: right; }
.mantia-soft { color: var(--ink-soft); }
.mantia-ink { color: var(--ink); font-weight: 500; }

/* ─── Block (section) ────────────────────────────────────────────── */

.mantia-block {
	padding: 28px 0 8px;
}
.mantia-block .mantia-eyebrow { margin-bottom: 12px; }
.mantia-cta-section {
	padding: 24px 0 8px;
}
.mantia-cta-stack {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.mantia-empty {
	color: var(--ink-soft);
	font-size: 14px;
	margin: 6px 0 0;
}
.mantia-empty-card {
	background: var(--field);
	padding: 14px 16px;
	border-radius: 4px;
	color: var(--ink-soft);
	font-size: 14px;
	margin: 14px 0 0;
}

/* ─── Hairline rule ──────────────────────────────────────────────── */

.mantia-rule {
	border: 0;
	height: 1px;
	background: var(--rule);
	margin: 0;
}

/* ─── Pill button ────────────────────────────────────────────────── */

.mantia-pill {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	height: 48px;
	padding: 0 22px;
	border-radius: 999px;
	font-family: var(--font-body);
	font-size: 15px;
	font-weight: 500;
	letter-spacing: -0.01em;
	text-decoration: none;
	border: 1px solid transparent;
	cursor: pointer;
	transition: filter 0.15s ease, background 0.15s ease;
	-webkit-tap-highlight-color: transparent;
}
.mantia-pill-primary {
	background: var(--ink);
	color: var(--bg);
	border-color: var(--ink);
}
.mantia-pill-primary:hover { filter: brightness(1.1); }
.mantia-pill-ghost {
	background: transparent;
	color: var(--ink);
	border-color: var(--ink);
}
.mantia-pill-ghost:hover { background: var(--field); }
.mantia-page .mantia-pill { width: 100%; }
.mantia-aside { margin: 18px 0 4px; }
.mantia-ghost-link {
	color: var(--ink-soft);
	text-decoration: none;
	font-size: 13.5px;
	letter-spacing: 0.04em;
	border-bottom: 1px dotted var(--rule);
	padding-bottom: 1px;
}
.mantia-ghost-link:hover {
	color: var(--ink);
	border-bottom-color: var(--ink-soft);
}

/* ─── Chips (competition nav) ────────────────────────────────────── */

.mantia-chips {
	display: flex;
	gap: 6px;
	padding: 0 0 18px;
	overflow-x: auto;
	scrollbar-width: none;
	-webkit-overflow-scrolling: touch;
}
.mantia-chips::-webkit-scrollbar { display: none; }
.mantia-chip {
	white-space: nowrap;
	padding: 7px 13px;
	border-radius: 999px;
	font-family: var(--font-body);
	font-size: 12.5px;
	letter-spacing: -0.005em;
	border: 1px solid var(--rule);
	color: var(--ink-soft);
	background: transparent;
	text-decoration: none;
}
.mantia-chip:hover { border-color: var(--ink-soft); color: var(--ink); }
.mantia-chip.is-active {
	background: var(--ink);
	color: var(--bg);
	border-color: var(--ink);
}

/* ─── Pedestal (leader) + leaderboard ────────────────────────────── */

.mantia-pedestal {
	display: grid;
	grid-template-columns: 56px 1fr auto;
	align-items: center;
	gap: 16px;
	padding: 20px 20px 22px;
	background: var(--field);
	border-radius: 4px;
	margin: 4px 0 22px;
}
.mantia-pedestal-text { min-width: 0; }
.mantia-pedestal-name {
	font-family: var(--font-display);
	font-size: 21px;
	letter-spacing: -0.025em;
	color: var(--ink);
	margin-top: 2px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.mantia-pedestal-meta {
	font-family: var(--font-body);
	font-size: 12.5px;
	color: var(--ink-soft);
	margin-top: 4px;
	letter-spacing: 0.02em;
}
.mantia-pedestal-points {
	text-align: right;
}
.mantia-pedestal-points .mantia-stat-label { margin-top: 2px; }

.mantia-board { margin: 0 -22px; }
.mantia-board-row {
	display: grid;
	grid-template-columns: 28px 28px 1fr 56px 42px;
	align-items: center;
	gap: 12px;
	padding: 13px 22px;
	border-top: 1px solid var(--rule);
}
.mantia-board-row:last-of-type { border-bottom: 1px solid var(--rule); }
.mantia-rank {
	font-family: var(--font-display);
	font-weight: 400;
	font-variant-numeric: tabular-nums;
	font-size: 13px;
	color: var(--ink-soft);
	text-align: center;
}
.mantia-board-player { min-width: 0; }
.mantia-board-name {
	font-family: var(--font-body);
	font-size: 14.5px;
	color: var(--ink);
	letter-spacing: -0.01em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.mantia-board-group {
	font-family: var(--font-body);
	font-size: 11.5px;
	color: var(--ink-soft);
	margin-top: 1px;
	letter-spacing: 0.02em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.mantia-board-pts {
	text-align: right;
	font-family: var(--font-display);
	font-size: 17px;
	font-variant-numeric: tabular-nums;
	letter-spacing: -0.02em;
	color: var(--ink);
}
.mantia-board-exc {
	text-align: right;
	font-family: var(--font-body);
	font-size: 13px;
	font-variant-numeric: tabular-nums;
	color: var(--ink-soft);
}
.mantia-board-legend {
	display: grid;
	grid-template-columns: 28px 28px 1fr 56px 42px;
	gap: 12px;
	padding: 8px 22px 0;
}

/* ─── Match list ─────────────────────────────────────────────────── */

.mantia-day-group { margin: 0 0 18px; }
.mantia-day-eyebrow { padding: 0 0 8px; }
.mantia-match-row {
	display: grid;
	grid-template-columns: 52px 1fr;
	align-items: center;
	gap: 12px;
	padding: 13px 0;
	border-top: 1px solid var(--rule);
}
.mantia-match-row:last-of-type { border-bottom: 1px solid var(--rule); }
.mantia-match-time {
	font-family: var(--font-display);
	font-variant-numeric: tabular-nums;
	font-size: 15px;
	color: var(--ink);
	letter-spacing: -0.01em;
}
.mantia-match-names {
	font-family: var(--font-body);
	font-size: 15px;
	color: var(--ink);
	letter-spacing: -0.01em;
	line-height: 1.3;
}
.mantia-match-phase {
	font-family: var(--font-body);
	font-size: 11.5px;
	color: var(--ink-soft);
	margin-top: 2px;
	letter-spacing: 0.04em;
}
.mantia-mid { color: var(--ink-soft); margin: 0 6px; }

/* ─── Avatars ────────────────────────────────────────────────────── */

.mantia-avatar {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: var(--avatar-bg, var(--field));
	color: var(--avatar-fg, var(--ink));
	font-family: var(--font-body);
	font-weight: 600;
	letter-spacing: -0.02em;
	flex-shrink: 0;
	overflow: hidden;
	user-select: none;
}
.mantia-avatar-img { object-fit: cover; }

/* ─── /me view: privacy + stats + group block ────────────────────── */

.mantia-privacy-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 11px;
	border-radius: 999px;
	font-family: var(--font-body);
	font-size: 11px;
	letter-spacing: 0.12em;
	text-transform: uppercase;
	color: var(--ink-soft);
	border: 1px solid var(--rule);
	background: transparent;
	margin: 0 0 14px;
}
.mantia-hero-user {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 4px 0 24px;
}
.mantia-hero-user-text { min-width: 0; }
.mantia-hero-user-text .mantia-h1 { margin-top: 6px; }

.mantia-stat-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	padding: 0 0 28px;
}
.mantia-stat {
	padding: 4px 8px;
	text-align: center;
}
.mantia-stat-bordered { border-left: 1px solid var(--rule); }
.mantia-stat-value {
	font-family: var(--font-display);
	font-size: 36px;
	line-height: 1;
	letter-spacing: -0.045em;
	color: var(--ink);
	font-variant-numeric: tabular-nums;
}
.mantia-stat-label { margin-top: 7px; }

.mantia-group-head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 10px;
	margin: 0 0 14px;
}
.mantia-tag-active {
	font-family: var(--font-body);
	font-size: 10.5px;
	letter-spacing: 0.16em;
	text-transform: uppercase;
	color: var(--bg);
	background: var(--accent);
	padding: 3px 9px;
	border-radius: 999px;
	flex-shrink: 0;
}
.mantia-me-line {
	margin: 12px 0 22px;
	padding: 14px 16px;
	background: var(--field);
	border-radius: 4px;
	display: flex;
	align-items: baseline;
	gap: 8px;
	line-height: 1.4;
}
.mantia-me-rank-suffix { color: var(--ink-soft); }
.mantia-me-points-wrap {
	margin-left: auto;
	display: flex;
	align-items: baseline;
	gap: 6px;
}
.mantia-subblock-eyebrow {
	font-family: var(--font-body);
	font-size: 10.5px;
	font-weight: 500;
	letter-spacing: 0.18em;
	text-transform: uppercase;
	color: var(--ink-soft);
	margin: 22px 0 8px;
}

/* ─── History rows ───────────────────────────────────────────────── */

.mantia-history {
	margin: 0;
}
.mantia-history-row {
	display: grid;
	grid-template-columns: 1fr 56px 56px 40px;
	align-items: center;
	gap: 8px;
	padding: 12px 0;
	border-top: 1px solid var(--rule);
}
.mantia-history-row:last-of-type { border-bottom: 1px solid var(--rule); }
.mantia-history-match { min-width: 0; }
.mantia-history-teams {
	font-family: var(--font-body);
	font-size: 13.5px;
	color: var(--ink);
	letter-spacing: -0.005em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.mantia-history-day {
	font-family: var(--font-body);
	font-size: 11px;
	color: var(--ink-soft);
	margin-top: 2px;
	letter-spacing: 0.04em;
}
.mantia-history-score { text-align: center; }
.mantia-score-line {
	font-family: var(--font-display);
	font-size: 15px;
	letter-spacing: -0.02em;
	color: var(--ink);
	font-variant-numeric: tabular-nums;
}
.mantia-history-points { text-align: right; }

/* ─── Scoring rows (group view) ──────────────────────────────────── */

.mantia-scoring-rows {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.mantia-scoring-row {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 8px 0;
	border-bottom: 1px solid var(--rule);
	font-family: var(--font-body);
	font-size: 13.5px;
	color: var(--ink);
}

/* ─── Home ───────────────────────────────────────────────────────── */

.mantia-home {
	max-width: 420px;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding-top: 56px;
	min-height: 100vh;
}
.mantia-home-mark { text-align: center; padding: 28px 0 20px; }
.mantia-wordmark {
	font-family: var(--font-display);
	font-weight: 500;
	font-size: 55px;
	line-height: 1;
	letter-spacing: -0.05em;
	color: var(--ink);
	margin: 0;
}
.mantia-tagline {
	margin-top: 14px;
	font-family: var(--font-body);
	font-size: 13px;
	letter-spacing: 0.22em;
	text-transform: uppercase;
	color: var(--ink-soft);
}
.mantia-qr-card {
	background: #ffffff;
	border: 1px solid var(--rule);
	padding: 18px;
	border-radius: 4px;
	line-height: 0;
	margin: 4px 0 24px;
	display: block;
	transition: transform 0.15s ease;
}
.mantia-qr-card:hover { transform: scale(1.01); }
.mantia-qr-img {
	display: block;
	width: 224px; height: 224px;
}
.mantia-home-hint {
	text-align: center;
	color: var(--ink-soft);
	font-size: 14px;
	line-height: 1.5;
	max-width: 280px;
	margin: 0 0 24px;
}
.mantia-home .mantia-pill { width: 100%; max-width: 320px; margin-bottom: 14px; }
.mantia-home .mantia-ghost-link { margin-top: 8px; }

/* ─── Footer ─────────────────────────────────────────────────────── */

.mantia-foot {
	max-width: 560px;
	margin: 0 auto;
	padding: 40px 22px 36px;
	text-align: center;
	font-family: var(--font-body);
	font-size: 11px;
	letter-spacing: 0.2em;
	text-transform: uppercase;
	color: var(--ink-soft);
}
.mantia-foot a {
	color: var(--ink-soft);
	text-decoration: none;
	font-weight: 500;
}

/* ─── Mobile tuning ──────────────────────────────────────────────── */

@media (max-width: 380px) {
	.mantia-h1 { font-size: 28px; }
	.mantia-wordmark { font-size: 48px; }
	.mantia-qr-img { width: 200px; height: 200px; }
}
CSS;
	}
}
