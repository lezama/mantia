<?php
/**
 * Public-facing web views for Mantia.
 *
 * Three URL patterns, all under /pronostico/:
 *
 *   /pronostico/<competition-slug>  Global ranking per competition (public)
 *   /pronostico/g/<group-token>     Single group view (shareable read-token)
 *   /pronostico/me/<user-token>     Personal view: your groups + predictions
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
		// Calendar subscribers (Google Calendar, Apple Calendar, Outlook) hit
		// the .ics URL WITHOUT a trailing slash — that's the convention.
		// WP's redirect_canonical would 301 it to /calendar.ics/ which some
		// subscribers don't follow. Short-circuit canonical for that one
		// view so the no-slash URL serves directly.
		add_filter( 'redirect_canonical', array( __CLASS__, 'maybe_skip_canonical' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_home' ), 5 );
		// Catch malformed /pronostico/* URLs (non-hex tokens, deprecated /compartir/
		// suffix, etc.) BEFORE the theme renders its default 404. Without this
		// they fall through to the Assembler theme placeholder content.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_intercept_penca_404' ), 1 );
	}

	/**
	 * Bump this when the PWA shell or service worker logic changes — the
	 * service worker keys its cache on this string, so a bump triggers
	 * "waiting → activate → clean old caches" on every installed client.
	 */
	const PWA_VERSION = 'mantia-v1';

	public static function register_rewrites(): void {
		// PWA endpoints. All at root scope so the service worker can
		// control /pronostico/* paths — a SW served from /pronostico/... can only
		// control siblings of its own path.
		// Optional trailing slash on both — WP's canonical redirect rewrites
		// path-style URLs to trailing-slash form, so the strict no-slash
		// regex used to 404 the canonical form. Lighthouse / PWA Builder
		// hit the non-slash form; browsers register the trailing-slash form;
		// accept both.
		add_rewrite_rule(
			'^manifest\.json/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=pwa-manifest',
			'top'
		);
		add_rewrite_rule(
			'^service-worker\.js/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=pwa-sw',
			'top'
		);
		add_rewrite_rule(
			'^pronostico/offline/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=pwa-offline',
			'top'
		);
		add_rewrite_rule(
			'^icons/(192|512)\.png$',
			'index.php?' . self::QUERY_VAR_VIEW . '=pwa-icon&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		// Join landing — gated by invite_code (random, non-guessable). The
		// old /pronostico/g/<slug>/sumate/ shape leaked existence + bypassed the
		// invite_code gate via slug guessing; this shape requires knowing
		// the code, which is the only secret that actually matters for
		// admission.
		add_rewrite_rule(
			'^pronostico/sumate/([A-Z0-9_-]+)/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=join-landing&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^pronostico/g/([a-f0-9]+)/og/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=join-og&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^pronostico/g/([a-f0-9]+)/compartir/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=share-group&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		// ICS calendar feed for the group's upcoming fixture. Plain GET,
		// no auth — the view token in the URL is already the access gate.
		// Subscribable from any calendar app (Google, Apple, Outlook…) so
		// users get partidos automatically on their phone calendar.
		add_rewrite_rule(
			'^pronostico/g/([a-f0-9]+)/calendar\.ics/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=group-ics&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		// /pronostico/me/share/<share_token>/ — the screenshotable poster.
		// Uses a SEPARATE token from /pronostico/me/<view_token>/ so a leaked
		// share link can't be transformed into the private edit URL by
		// dropping segments. Old `/pronostico/me/<view_token>/compartir/`
		// route is removed in the same commit; any previously-screenshot
		// share URL now 404s, which is the right failure mode.
		add_rewrite_rule(
			'^pronostico/me/share/([a-f0-9]+)/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=share-user&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		// Group view: broadened from hex-only to slug-or-token. Handler
		// tries find_group_by_slug() first, then falls back to view_token
		// for backwards compat (legacy URLs shipped before magic links).
		add_rewrite_rule(
			'^pronostico/g/([a-z0-9-]+)/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=group&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		// Auth-gated /pronostico/me/ (no token). Uses get_current_user_id()
		// from the magic-link cookie. If not logged in, handler redirects
		// to /pronostico/expired/.
		add_rewrite_rule(
			'^pronostico/me/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=me',
			'top'
		);
		// Expired magic-link landing page. Magic-link verification failures
		// (bad sig, expired, replayed) all funnel here so users see a
		// "pediselo de nuevo al bot" affordance instead of a 404.
		add_rewrite_rule(
			'^pronostico/expired/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=expired',
			'top'
		);
		// Legacy token URL for /pronostico/me/<hex>/ — kept until Phase 6 so
		// users with the link in chat history can still open it.
		add_rewrite_rule(
			'^pronostico/me/([a-f0-9]+)/?$',
			'index.php?' . self::QUERY_VAR_VIEW . '=user&' . self::QUERY_VAR_ID . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^pronostico/([a-z0-9][a-z0-9-]*)/?$',
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
			case 'me':
				// Auth-gated /pronostico/me/. Magic-link cookie sets current_user.
				$current_user_id = get_current_user_id();
				if ( $current_user_id <= 0 ) {
					wp_safe_redirect( home_url( '/pronostico/expired/' ), 302 );
					exit;
				}
				echo self::render_user_for_id( (int) $current_user_id );
				break;
			case 'expired':
				echo self::render_magic_link_expired();
				break;
			case 'share-group':
				echo self::render_share_group( (string) $id );
				break;
			case 'share-user':
				echo self::render_share_user( (string) $id );
				break;
			case 'join-landing':
				echo self::render_join_landing( (string) $id );
				break;
			case 'join-og':
				self::render_join_og_png( (string) $id ); // emits headers + body + exits.
				break;
			case 'group-ics':
				self::render_group_ics( (string) $id ); // emits headers + body + exits.
				break;
			case 'pwa-manifest':
				self::render_pwa_manifest(); // headers + body + exits.
				break;
			case 'pwa-sw':
				self::render_pwa_service_worker(); // headers + body + exits.
				break;
			case 'pwa-offline':
				echo self::render_pwa_offline();
				break;
			case 'pwa-icon':
				self::render_pwa_icon( (int) $id ); // headers + body + exits.
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

	/**
	 * Any URL under /pronostico/ that didn't match our rewrite rules (typo'd
	 * token, non-hex characters, deprecated suffixes like /compartir/) ends
	 * up as a generic WP 404 and falls through to whatever 404.html the
	 * active theme ships — for Assembler that's "Page Not Found" + Lorem
	 * ipsum business hours. Intercept here so the Mantia themed 404 wins.
	 */
	public static function maybe_intercept_penca_404(): void {
		if ( ! is_404() ) {
			return;
		}
		if ( '' !== (string) get_query_var( self::QUERY_VAR_VIEW ) ) {
			return; // Our own handler will deal with it.
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( '' === $path || ! str_starts_with( $path, '/pronostico/' ) ) {
			return;
		}

		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::render_not_found( __( 'Este link de penca no funciona o ya venció.', 'mantia' ) );
		exit;
	}

	/* =================================================================
	 * Views
	 * ================================================================= */

	private static function render_home(): string {
		$phone = Mantia_Repository::bot_phone_e164();
		$msg   = (string) apply_filters( 'mantia_home_first_message', 'hola' );
		$wa    = '' !== $phone ? sprintf( 'https://wa.me/%s?text=%s', $phone, rawurlencode( $msg ) ) : '';
		// CTA points at the install's default competition (seed-driven). Read
		// its name dynamically so the label never goes stale after a seed
		// change — the QA cycle caught a "Ver el ranking del Mundial" CTA
		// pointing at libertadores-2026 after Mundial was removed from the
		// seed but the hardcoded label survived.
		$default_id   = Mantia_Competitions::default_id();
		$ranking_url  = Mantia_Repository::competition_view_url( $default_id );
		$default_comp = Mantia_Competitions::get( $default_id );
		$ranking_label = $default_comp
			? sprintf( __( 'Ver %s', 'mantia' ), (string) $default_comp['name'] )
			: __( 'Ver el ranking', 'mantia' );

		ob_start();
		self::page_header( 'Mantia · Penca por WhatsApp' );
		?>
		<main class="mantia-page mantia-home">
			<?php // PWA install banner. Hidden by default; the inline JS in
			      // print_pwa_register() reveals it when the browser fires
			      // beforeinstallprompt. iOS users never see it (Safari
			      // doesn't fire the event). Once dismissed it stays gone
			      // for the session via localStorage. ?>
			<div class="mantia-pwa-install" hidden>
				<span class="mantia-pwa-text"><?php esc_html_e( 'Agregalo a tu pantalla de inicio para abrirlo en un toque.', 'mantia' ); ?></span>
				<button class="mantia-pwa-install-btn" type="button">
					<?php esc_html_e( 'Instalar', 'mantia' ); ?>
				</button>
				<button class="mantia-pwa-dismiss" type="button" aria-label="<?php esc_attr_e( 'Cerrar', 'mantia' ); ?>">✕</button>
			</div>

			<div class="mantia-home-mark">
				<div class="mantia-home-stickers" aria-hidden="true">
					<span class="mantia-home-sticker mantia-home-sticker-r">x WhatsApp</span>
				</div>
				<h1 class="mantia-wordmark">mantia</h1>
				<p class="mantia-tagline"><?php esc_html_e( 'Pronosticá, picanteá el grupo, ganale a tus amigos. Sin app.', 'mantia' ); ?></p>
			</div>

			<?php if ( '' !== $wa ) : ?>
				<a class="mantia-qr-card" href="<?php echo esc_url( $wa ); ?>" aria-label="<?php esc_attr_e( 'Abrir WhatsApp con Mantia', 'mantia' ); ?>">
					<img class="mantia-qr-img" src="<?php echo esc_url( self::qr_image_url( $wa, 448 ) ); ?>"
						alt="<?php esc_attr_e( 'Código QR para chatear con Mantia por WhatsApp', 'mantia' ); ?>"
						width="224" height="224" loading="eager">
					<div class="mantia-qr-caption"><?php esc_html_e( 'escaneá · mandá "hola"', 'mantia' ); ?></div>
				</a>
				<a class="mantia-pill mantia-pill-wa" href="<?php echo esc_url( $wa ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.2-1.8-.9-2.1-1-.3-.1-.5-.2-.7.2-.2.3-.8 1-1 1.2-.2.2-.4.2-.7 0-.3-.2-1.3-.5-2.5-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.7.1-.1.3-.4.4-.5.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5-.1-.2-.7-1.6-.9-2.2-.2-.6-.4-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1.1 1-1.1 2.5s1.1 2.9 1.3 3.1c.2.2 2.2 3.4 5.3 4.8.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.3-.7.3-1.3.2-1.4-.1-.1-.3-.2-.6-.3zM12 2C6.5 2 2 6.5 2 12c0 1.8.5 3.5 1.3 5L2 22l5.2-1.3c1.4.7 3 1.2 4.8 1.2 5.5 0 10-4.5 10-10S17.5 2 12 2z"/></svg>
					<?php esc_html_e( 'Abrir WhatsApp', 'mantia' ); ?>
				</a>
			<?php else : ?>
				<section class="mantia-empty-card">
					<p><?php esc_html_e( 'El bot todavía no tiene número configurado.', 'mantia' ); ?></p>
				</section>
			<?php endif; ?>

			<?php
			// Logged-in shortcut: when the magic-link cookie is set, surface
			// "Mis pencas" as the most prominent affordance. The QR + Abrir
			// WhatsApp pair stays for the "fui a la home en otra máquina"
			// case (escanear con el celu del bot, mandar hola).
			if ( get_current_user_id() > 0 ) :
				$me_url = home_url( '/pronostico/me/' );
				?>
				<a class="mantia-pill mantia-pill-primary" href="<?php echo esc_url( $me_url ); ?>">
					<?php esc_html_e( '📋 Mis pencas', 'mantia' ); ?>
				</a>
			<?php endif; ?>

			<?php if ( '' !== $ranking_url ) : ?>
				<a class="mantia-pill mantia-pill-ghost" href="<?php echo esc_url( $ranking_url ); ?>">
					<?php echo esc_html( $ranking_label ); ?>
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
			return self::render_not_found( __( 'Este link de penca no funciona o ya venció.', 'mantia' ) );
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
						<?php
						$row_count = count( $rows );
						// "TOP 50" scaffolding looks template-y under 50 — adapt.
						$eyebrow_label = $row_count >= 50
							? __( 'ranking global · top 50', 'mantia' )
							: __( 'ranking global', 'mantia' );
						?>
						<span class="mantia-eyebrow"><?php echo esc_html( $eyebrow_label ); ?></span>
						<span class="mantia-eyebrow-count"><?php
							echo esc_html( sprintf(
								/* translators: %d: number of players in the global ranking. */
								_n( 'de %d jugador', 'de %d jugadores', $row_count, 'mantia' ),
								$row_count
							) );
						?></span>
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

	private static function render_group( string $token_or_slug ): string {
		// Phase 5 cutover: try the new slug-based lookup first (matches the
		// /pronostico/g/<slug>/ URLs the magic-link helper generates), fall back
		// to the legacy view-token lookup so links shipped before the
		// migration still work until Phase 6 retires them.
		$group_post = Mantia_Repository::find_group_by_slug( $token_or_slug );
		if ( ! $group_post ) {
			$group_post = Mantia_Repository::find_group_by_view_token( $token_or_slug );
		}
		if ( ! $group_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Este link no funciona o ya venció.', 'mantia' ) );
		}

		// Optional `?as=<share_token>` lets the caller (typically the /me/
		// page linking here) ask the group page to highlight a specific
		// row. We use the SHARE token (not the view token) so that link
		// can leak harmlessly — share tokens grant no write access.
		$me_id = null;
		$as    = isset( $_GET['as'] ) ? (string) $_GET['as'] : '';
		if ( '' !== $as ) {
			$me_post = Mantia_Repository::find_user_by_share_token( $as );
			if ( $me_post ) {
				$me_id = (int) $me_post->ID;
			}
		}

		$group_id   = (int) $group_post->ID;
		$group      = Mantia_Repository::group_to_array( $group_id );
		$rows       = Mantia_Leaderboard::rows( $group_id, 50 );
		$comp_id    = Mantia_Repository::group_competition_id( $group_id );
		$matches    = Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 30 );
		$comp_url   = Mantia_Repository::competition_view_url( $comp_id );
		$create_url = self::create_penca_wa_url();
		$members    = Mantia_Repository::group_members( $group_id );

		// Share URL for the group view. Uses the group's slug (Phase 5 cutover)
		// instead of the view-token segment that the original $token argument
		// carried. /compartir/ is rendered by the same group page in a
		// screenshot-friendly variant.
		$share_url = home_url( '/pronostico/g/' . $token_or_slug . '/compartir/' );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $group['name'] ) );
		?>
		<main class="mantia-page">
			<?php self::render_topbar( $share_url ); ?>

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

			<?php
			// Two-mode CTA on the group page:
			//   - Members (the common case post-Phase 5 — magic-link sets
			//     current_user) get a "Compartir" button that opens WhatsApp's
			//     share-to-contacts picker with the invite card pre-filled.
			//   - Non-members (someone who landed via a public link without
			//     auth) see the original "Sumate" CTA that posts the join
			//     message to the bot.
			$current_uid = get_current_user_id();
			$is_member   = false;
			if ( $current_uid > 0 ) {
				foreach ( $members as $m ) {
					if ( (int) $m['id'] === $current_uid ) {
						$is_member = true;
						break;
					}
				}
			}
			$invite_code = (string) ( $group['invite_code'] ?? '' );
			?>
			<?php if ( $is_member && '' !== $invite_code ) : ?>
				<?php
				// Share-with-contacts: wa.me with no phone opens WhatsApp's
				// contact picker. The text mirrors the invite card the bot
				// already sends so people pasting from web get the same
				// rich-preview link in their friends' chats.
				$share_landing = home_url( '/pronostico/sumate/' . $invite_code . '/' );
				$share_text    = sprintf(
					"🏆 Sumate a %s\n\nTocá el link para sumarte:\n%s",
					$group['name'],
					$share_landing
				);
				$share_wa = 'https://wa.me/?text=' . rawurlencode( $share_text );
				?>
				<section class="mantia-cta-section">
					<a class="mantia-pill mantia-pill-primary" href="<?php echo esc_url( $share_wa ); ?>">
						<?php
						printf(
							/* translators: %s: invite code. */
							esc_html__( '📤 Invitar amigos · código %s', 'mantia' ),
							esc_html( $invite_code )
						);
						?>
					</a>
				</section>
			<?php elseif ( ! empty( $group['share_url'] ) ) : ?>
				<section class="mantia-cta-section">
					<a class="mantia-pill mantia-pill-primary" href="<?php echo esc_url( $group['share_url'] ); ?>">
						<?php
						printf(
							/* translators: %s: invite code. */
							esc_html__( 'Sumate · código %s', 'mantia' ),
							esc_html( $invite_code )
						);
						?>
					</a>
				</section>
			<?php endif; ?>

			<hr class="mantia-rule">

			<?php
			// "Último partido" panel: shows the latest finished match in
			// this penca's competition + the group's consensus tally
			// (only renders post-kickoff per the Repository guard, so it
			// never leaks pre-match picks). Mirrors what the bot's
			// "consenso" command shows in WhatsApp, but on the web.
			$recent = Mantia_Repository::recent_finished_matches_for_competition( $comp_id, 1 );
			if ( ! empty( $recent ) ) :
				$last_match = $recent[0];
				$consensus  = Mantia_Repository::group_consensus_for_match( $group_id, (int) $last_match['id'] );
				if ( ! empty( $consensus ) ) :
					$total_votes = array_sum( $consensus );
					$top_score   = array_key_first( $consensus );  // arsort'd already
					?>
					<section class="mantia-block mantia-recent-match">
						<div class="mantia-eyebrow"><?php esc_html_e( 'último partido', 'mantia' ); ?></div>
						<div class="mantia-recent-card">
							<div class="mantia-recent-teams">
								<?php echo esc_html( self::normalize_team_name( (string) $last_match['home_team'] ) ); ?>
								<strong class="mantia-recent-score">
									<?php echo (int) $last_match['home_score']; ?>·<?php echo (int) $last_match['away_score']; ?>
								</strong>
								<?php echo esc_html( self::normalize_team_name( (string) $last_match['away_team'] ) ); ?>
							</div>
							<div class="mantia-recent-consensus">
								<?php
								printf(
									/* translators: 1: count of voters in group, 2: most-voted score, 3: votes for it */
									esc_html__( 'El grupo votó · mayoría %2$s (%3$d/%1$d):', 'mantia' ),
									(int) $total_votes,
									esc_html( $top_score ),
									(int) $consensus[ $top_score ]
								);
								?>
							</div>
							<div class="mantia-recent-tally">
								<?php foreach ( array_slice( $consensus, 0, 6, true ) as $score => $count ) : ?>
									<span class="mantia-recent-chip <?php echo $score === $top_score ? 'is-top' : ''; ?>">
										<?php echo esc_html( $score ); ?>
										<small><?php echo (int) $count; ?></small>
									</span>
								<?php endforeach; ?>
							</div>
						</div>
					</section>
				<?php endif;
			endif; ?>

			<section class="mantia-block">
				<div class="mantia-eyebrow"><?php esc_html_e( 'tabla del grupo', 'mantia' ); ?></div>
				<?php if ( empty( $rows ) ) : ?>
					<?php
					// Fresh-joiner safety net: when no scores are tabulated
					// yet, show the roster instead of a dead "Todavía no hay
					// puntos cargados." A QA persona who'd just joined
					// reported feeling unconfirmed — hero says "4 jugadores"
					// but nothing visible attests they're one of them. The
					// roster doubles as that proof + a "who's here?" answer.
					if ( ! empty( $members ) ) :
						?>
						<p class="mantia-empty mantia-empty-soft"><?php esc_html_e( 'Sin puntos todavía — la tabla aparece después del primer partido resuelto.', 'mantia' ); ?></p>
						<div class="mantia-roster">
							<?php foreach ( $members as $m ) :
								$row_cls = 'mantia-roster-row' . ( null !== $me_id && (int) $m['id'] === $me_id ? ' mantia-roster-row-me' : '' );
								?>
								<div class="<?php echo esc_attr( $row_cls ); ?>">
									<?php echo self::user_avatar( (int) $m['id'], 28 ); ?>
									<span class="mantia-roster-name">
										<?php echo esc_html( (string) ( $m['display_name'] ?? __( 'Jugador', 'mantia' ) ) ); ?>
										<?php if ( null !== $me_id && (int) $m['id'] === $me_id ) : ?>
											<span class="mantia-roster-me"><?php esc_html_e( '· vos', 'mantia' ); ?></span>
										<?php endif; ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p class="mantia-empty"><?php esc_html_e( 'Todavía no hay puntos cargados.', 'mantia' ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<?php self::render_leaderboard( $rows, 'group', $me_id ); ?>
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

			<section class="mantia-aside-pair">
				<a class="mantia-ghost-link" href="<?php echo esc_url( $share_url ); ?>">
					<?php esc_html_e( 'compartí cómo va la penca →', 'mantia' ); ?>
				</a>
				<?php if ( '' !== $create_url ) : ?>
					<a class="mantia-ghost-link" href="<?php echo esc_url( $create_url ); ?>">
						<?php esc_html_e( 'o creá tu propia penca →', 'mantia' ); ?>
					</a>
				<?php endif; ?>
			</section>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_user( string $token ): string {
		$user = Mantia_Repository::find_user_by_view_token( $token );
		if ( ! $user ) {
			status_header( 404 );
			return self::render_not_found( __( 'Este link privado no funciona o ya venció.', 'mantia' ) );
		}
		return self::render_user_for_id( (int) $user->ID );
	}

	/**
	 * Render the /pronostico/me/ page for a known user_id. Shared between the
	 * legacy /pronostico/me/<token>/ entrypoint and the magic-link /pronostico/me/
	 * route which resolves the user from get_current_user_id().
	 */
	private static function render_user_for_id( int $user_id ): string {
		if ( $user_id <= 0 ) {
			status_header( 404 );
			return self::render_not_found( __( 'No encuentro tu cuenta.', 'mantia' ) );
		}
		// The editable-matches form posts to the REST endpoint with a token
		// for auth. We keep the user_view_token as a per-user secret here
		// until Phase 6 lets the REST endpoint authenticate from the
		// magic-link cookie session directly. Auto-generated lazily by
		// user_view_token() on first use.
		$token        = Mantia_Repository::user_view_token( $user_id );
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

		// IMPORTANT: the share URL uses a SEPARATE token from this page's
		// private view token. Sharing /me/<view>/compartir/ would let
		// recipients strip /compartir/ and land on the editable view —
		// a privacy + write-access leak. The share token is a distinct
		// random hex string with no relation to the view token.
		$share_url = home_url( '/pronostico/me/share/' . Mantia_Repository::user_share_token( $user_id ) . '/' );

		ob_start();
		self::page_header( sprintf( 'Penca — %s', $display_name ) );
		?>
		<main class="mantia-page">
			<?php self::render_topbar( $share_url ); ?>

			<?php // Sticky compact bar: appears once the user scrolls past
			      // the big stat-grid, so they keep their name + pts visible
			      // while skimming match-by-match. Hidden by default;
			      // IntersectionObserver on .mantia-stat-grid toggles it. ?>
			<div class="mantia-me-sticky" hidden>
				<?php echo self::user_avatar( $user_id, 26 ); ?>
				<span class="mantia-me-sticky-name"><?php echo esc_html( $display_name ); ?></span>
				<span class="mantia-me-sticky-stat"><strong><?php echo (int) $total_points; ?></strong> pts</span>
				<span class="mantia-me-sticky-stat"><strong><?php echo (int) $total_exacts; ?></strong> exc</span>
			</div>

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
					<div class="mantia-stat-label"><?php echo esc_html( _n( 'punto', 'puntos', (int) $total_points, 'mantia' ) ); ?></div>
				</div>
				<div class="mantia-stat mantia-stat-bordered">
					<div class="mantia-stat-value"><?php echo (int) $total_exacts; ?></div>
					<div class="mantia-stat-label"><?php echo esc_html( _n( 'exacto', 'exactos', (int) $total_exacts, 'mantia' ) ); ?></div>
				</div>
				<div class="mantia-stat mantia-stat-bordered">
					<div class="mantia-stat-value"><?php echo (int) $total_preds; ?></div>
					<div class="mantia-stat-label"><?php echo esc_html( _n( 'pronóstico', 'pronósticos', (int) $total_preds, 'mantia' ) ); ?></div>
				</div>
			</section>

			<?php
			$has_manual = Mantia_Repository::user_has_manual_prediction( $user_id );
			if ( $total_preds > 0 ) :
				if ( ! $has_manual ) :
					// Random-by-default users: explain why they have predictions
					// they don't remember making, so "wait, I never said 2-1"
					// becomes "ah, the bot put a guess; I can change it".
					?>
					<p class="mantia-me-hint mantia-me-hint-random">
						<?php
						/* translators: %d: number of auto-filled predictions */
						printf( esc_html__( '🎲 Tus %d pronósticos siguen siendo random hasta que los cambies. Tocá cualquier partido abajo.', 'mantia' ), (int) $total_preds );
						?>
					</p>
				<?php else : ?>
					<p class="mantia-me-hint">
						<?php
						/* translators: %d: number of predictions already saved */
						printf( esc_html__( '✨ Ya tenés %d pronósticos cargados. Tocá cualquier partido abajo para cambiarlo.', 'mantia' ), (int) $total_preds );
						?>
					</p>
				<?php endif;
			endif; ?>

			<hr class="mantia-rule">

			<?php if ( empty( $groups ) ) : ?>
				<p class="mantia-empty"><?php esc_html_e( 'Todavía no estás en ninguna penca.', 'mantia' ); ?></p>
			<?php else :
				// Collect competitions once across all the user's pencas
				// so "próximos · editá tu pronóstico" renders ONE time per
				// competition, not duplicated per penca. Predictions
				// fan out across pencas in the same competition anyway —
				// duplicating the same match three times when a user has
				// three Mundial pencas was UX noise.
				$competitions = array();
				foreach ( $groups as $g ) {
					$cid = Mantia_Repository::group_competition_id( (int) $g['id'] );
					if ( '' === $cid || isset( $competitions[ $cid ] ) ) {
						continue;
					}
					$competitions[ $cid ] = array(
						'name'             => (string) ( $g['competition_name'] ?? '' ),
						'primary_group_id' => (int) $g['id'],
						'matches'          => Mantia_Repository::upcoming_matches_for_competition( $cid, 24 * 30 ),
					);
				}
				?>

				<?php
				$competitions_with_matches = array_filter( $competitions, static fn ( $c ) => ! empty( $c['matches'] ) );
				if ( ! empty( $competitions_with_matches ) ) :
					?>
					<section class="mantia-block">
						<?php foreach ( $competitions_with_matches as $cid => $comp ) : ?>
							<div class="mantia-subblock-eyebrow"><?php
								if ( count( $competitions_with_matches ) === 1 ) {
									esc_html_e( 'próximos · editá tu pronóstico', 'mantia' );
								} else {
									/* translators: %s: competition name */
									printf( esc_html__( 'próximos · %s', 'mantia' ), esc_html( $comp['name'] ) );
								}
							?></div>
							<?php self::render_editable_matches(
								array_slice( array_values( $comp['matches'] ), 0, 8 ),
								$user_id,
								(int) $comp['primary_group_id'],
								$token
							); ?>
						<?php endforeach; ?>
					</section>
				<?php endif; ?>

				<?php
				foreach ( $groups as $g ) :
					$group_id   = (int) $g['id'];
					$lb         = Mantia_Leaderboard::rows( $group_id, 100 );
					$my_row     = null;
					foreach ( $lb as $row ) {
						if ( (int) $row['user_id'] === $user_id ) {
							$my_row = $row;
							break;
						}
					}
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

						<?php if ( $my_row ) :
							// Pass `?as=<share_token>` to the group page so it can
							// highlight this user's row. Share token is read-only,
							// safe to bake into a URL the user might copy/forward.
							$group_token   = Mantia_Repository::group_view_token( $group_id );
							$share_tok     = Mantia_Repository::user_share_token( $user_id );
							$group_link    = home_url( '/pronostico/g/' . $group_token . '/?as=' . $share_tok );
							?>
							<a class="mantia-me-line" href="<?php echo esc_url( $group_link ); ?>">
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
								<span class="mantia-me-line-arrow" aria-hidden="true">→</span>
							</a>
							<?php
							// Delta line: how many points did this user earn
							// from the most recent finished match in this
							// penca? Surfaces so returning users see what
							// changed since last time, even when their total
							// is 0 ("0 pts esta vez" still tells them the
							// match resolved without their score moving).
							$delta = Mantia_Repository::last_match_delta( $user_id, $group_id );
							if ( null !== $delta ) :
								$dm = $delta['match'];
								?>
								<p class="mantia-me-delta">
									<?php
									printf(
										/* translators: 1: home team, 2: home score, 3: away score, 4: away team, 5: signed delta like +5 or 0 */
										esc_html__( 'Último: %1$s %2$d-%3$d %4$s → %5$s', 'mantia' ),
										esc_html( self::normalize_team_name( (string) $dm['home_team'] ) ),
										(int) $dm['home_score'],
										(int) $dm['away_score'],
										esc_html( self::normalize_team_name( (string) $dm['away_team'] ) ),
										$delta['points'] > 0 ? '+' . (int) $delta['points'] . ' pts' : '0 pts' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									);
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>

						<?php
						$my_history = Mantia_Repository::user_history( $user_id, $group_id );
						if ( ! empty( $my_history ) ) :
							?>
							<div class="mantia-subblock-eyebrow"><?php esc_html_e( 'tus pronósticos', 'mantia' ); ?></div>
							<?php self::render_history_rows( $my_history ); ?>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>

			<section class="mantia-aside-pair">
				<a class="mantia-ghost-link" href="<?php echo esc_url( $share_url ); ?>">
					<?php esc_html_e( 'compartí tu posición →', 'mantia' ); ?>
				</a>
				<?php if ( '' !== $create_url ) : ?>
					<a class="mantia-ghost-link" href="<?php echo esc_url( $create_url ); ?>">
						<?php esc_html_e( 'crear otra penca →', 'mantia' ); ?>
					</a>
				<?php endif; ?>
			</section>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	/**
	 * Landing page for an invitation link. Pasted into a WhatsApp/Slack/
	 * Discord chat, it renders a rich preview via OpenGraph tags pointing
	 * at /og.png; a human tap immediately 302s to wa.me with the invite
	 * code prefilled. The OG scraper sees the tags + image; the user
	 * never lingers on this page.
	 */
	private static function render_join_landing( string $invite_code ): string {
		// Resolve by invite_code — the only secret a recipient should need
		// to know to act on the invitation. Slug-based resolution was a
		// privacy leak because slugs are guessable from the penca name.
		$invite_code = Mantia_Repository::normalize_invite_code( $invite_code );
		$group_post  = Mantia_Repository::find_group_by_invite_code( $invite_code );
		if ( ! $group_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Esta invitación ya no funciona.', 'mantia' ) );
		}
		$group_id  = (int) $group_post->ID;
		$group     = Mantia_Repository::group_to_array( $group_id );
		$members   = Mantia_Repository::group_members( $group_id );
		$wa_target = (string) ( $group['share_url'] ?? '' );
		if ( '' === $wa_target ) {
			// Fall back to whatever wa.me URL we can build from the code.
			$bot       = Mantia_Repository::bot_phone_e164();
			$wa_target = ( '' !== $bot ) ? sprintf( 'https://wa.me/%s?text=%s', $bot, rawurlencode( $invite_code ) ) : home_url( '/' );
		}

		$comp_name = (string) ( $group['competition_name'] ?? '' );
		$og_title  = sprintf( __( 'Sumate a %s', 'mantia' ), $group['name'] );
		$og_desc   = trim(
			$comp_name
			. ( '' !== $comp_name ? ' · ' : '' )
			. sprintf( _n( '%d jugador', '%d jugadores', count( $members ), 'mantia' ), count( $members ) )
		);
		// OG image still uses the group view token — it's a static asset URL.
		$view_token = Mantia_Repository::group_view_token( $group_id );
		$og_image   = home_url( '/pronostico/g/' . $view_token . '/og/' );
		$page_url   = home_url( '/pronostico/sumate/' . $invite_code . '/' );

		ob_start();
		?>
		<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $og_title ); ?> — Mantia</title>

<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo esc_url( $page_url ); ?>">
<meta property="og:title" content="<?php echo esc_attr( $og_title ); ?>">
<meta property="og:description" content="<?php echo esc_attr( $og_desc ); ?>">
<meta property="og:image" content="<?php echo esc_url( $og_image ); ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="Mantia">
<meta property="og:locale" content="es_UY">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo esc_attr( $og_title ); ?>">
<meta name="twitter:description" content="<?php echo esc_attr( $og_desc ); ?>">
<meta name="twitter:image" content="<?php echo esc_url( $og_image ); ?>">

<meta http-equiv="refresh" content="0; url=<?php echo esc_url( $wa_target ); ?>">
<link rel="canonical" href="<?php echo esc_url( $page_url ); ?>">

<style><?php echo self::stylesheet(); ?></style>
<script>
// Belt-and-braces redirect — meta refresh covers no-JS, this covers no-meta-refresh.
window.location.replace(<?php echo wp_json_encode( $wa_target ); ?>);
</script>
</head>
<body class="mantia-body-share">
<main class="mantia-share">
	<div class="mantia-share-card">
		<div class="mantia-share-top">
			<span class="mantia-share-wordmark">mantia</span>
			<span class="mantia-share-comp"><?php echo esc_html( wp_strip_all_tags( $comp_name ) ); ?></span>
		</div>
		<div class="mantia-share-center">
			<div class="mantia-share-mark">·</div>
			<div class="mantia-share-name"><?php echo esc_html( (string) $group['name'] ); ?></div>
			<div class="mantia-share-in"><?php echo esc_html( $og_desc ); ?></div>
		</div>
		<div class="mantia-share-url">
			<?php esc_html_e( 'Abriendo WhatsApp…', 'mantia' ); ?>
		</div>
	</div>
	<div class="mantia-share-actions">
		<a class="mantia-share-copy" href="<?php echo esc_url( $wa_target ); ?>">
			<?php esc_html_e( 'Tocá si no abre solo', 'mantia' ); ?>
		</a>
	</div>
	<noscript>
		<p style="color:#0a0a0a;margin-top:24px;text-align:center;font-weight:700">
			<?php esc_html_e( 'Redirigiendo a WhatsApp…', 'mantia' ); ?>
			<a href="<?php echo esc_url( $wa_target ); ?>" style="color:#0a0a0a;text-decoration:underline">
				<?php esc_html_e( 'tocá acá', 'mantia' ); ?>
			</a>
		</p>
	</noscript>
</main>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the OG image (1200×630) for an invitation link.
	 *
	 * Two backends in order of preference:
	 * 1. GD + bundled TTF — when imagettftext + a usable font are
	 *    available, we rasterize to PNG (WhatsApp's strict scraper renders
	 *    this perfectly; Twitter renders it; Slack renders it).
	 * 2. SVG fallback — when neither (e.g. wp.com Atomic, which lacks
	 *    Imagick + ships fonts under theme dirs that may move). Slack +
	 *    Discord + Twitter render SVG og:images; WhatsApp may not, in
	 *    which case the og:title + og:description still produce a useful
	 *    text-only preview.
	 *
	 * TODO: ship Inter Variable in mantia/assets/fonts/ so backend (1)
	 * always wins regardless of host.
	 */
	/**
	 * Suppress WP's canonical redirect for the .ics endpoint. Without
	 * this, /pronostico/g/<token>/calendar.ics 301-redirects to
	 * /pronostico/g/<token>/calendar.ics/ — and some calendar clients
	 * (Apple Calendar in particular) don't follow that redirect.
	 */
	public static function maybe_skip_canonical( $redirect_url, $requested_url ) {
		if ( is_string( $requested_url ) && false !== strpos( $requested_url, '/calendar.ics' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * VCALENDAR feed for the group's upcoming fixture. Subscribable from
	 * any calendar app: every match in the group's competition becomes a
	 * VEVENT with the canonical kickoff time and a one-line summary. The
	 * URL is gated by the group's view_token, so no auth is needed — the
	 * token IS the credential, same as /pronostico/g/<token>/.
	 *
	 * Per RFC 5545: CRLF line endings, UTC stamps with the Z suffix, and
	 * a stable UID per match so subscribers update in place when a kick-
	 * off shifts instead of getting duplicate events.
	 */
	private static function render_group_ics( string $token ): void {
		$group_post = Mantia_Repository::find_group_by_view_token( $token );
		if ( ! $group_post ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo "Not found\n";
			exit;
		}
		$group_id = (int) $group_post->ID;
		$comp_id  = Mantia_Repository::group_competition_id( $group_id );
		$matches  = '' !== $comp_id
			? Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 365 )
			: array();

		$group     = Mantia_Repository::group_to_array( $group_id );
		$cal_name  = sprintf( 'Mantia · %s', (string) $group['name'] );
		$site_host = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'mantia.local' );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Mantia//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::ics_escape( $cal_name ),
			'X-WR-TIMEZONE:UTC',
		);

		$now_stamp = gmdate( 'Ymd\THis\Z' );
		foreach ( $matches as $match ) {
			$kickoff_ts = (int) ( $match['kickoff_ts'] ?? 0 );
			if ( $kickoff_ts <= 0 ) {
				continue;
			}
			$home_team = (string) ( $match['home_team'] ?? '' );
			$away_team = (string) ( $match['away_team'] ?? '' );
			if ( '' === $home_team || '' === $away_team ) {
				continue;
			}

			$dtstart = gmdate( 'Ymd\THis\Z', $kickoff_ts );
			$dtend   = gmdate( 'Ymd\THis\Z', $kickoff_ts + 2 * HOUR_IN_SECONDS );
			$summary = sprintf( '⚽ %s vs %s', $home_team, $away_team );
			$descr   = sprintf(
				'Pronosticar: %s',
				home_url( '/pronostico/g/' . Mantia_Repository::group_view_token( $group_id ) . '/' )
			);
			$uid = sprintf( 'mantia-match-%d-group-%d@%s', (int) $match['id'], $group_id, $site_host );

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . self::ics_escape( $uid );
			$lines[] = 'DTSTAMP:' . $now_stamp;
			$lines[] = 'DTSTART:' . $dtstart;
			$lines[] = 'DTEND:'   . $dtend;
			$lines[] = 'SUMMARY:' . self::ics_escape( $summary );
			$lines[] = 'DESCRIPTION:' . self::ics_escape( $descr );
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		$body = implode( "\r\n", $lines ) . "\r\n";
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="mantia-' . sanitize_title( (string) $group['name'] ) . '.ics"' );
		header( 'Cache-Control: public, max-age=900' ); // 15 min — fast enough for kickoff edits.
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Escape a value for an ICS property per RFC 5545 § 3.3.11: backslash
	 * is the escape char, then ; , and newlines need quoting. Sticks to
	 * the minimum needed for SUMMARY/DESCRIPTION/UID — no fold logic since
	 * mantia values stay well under the 75-octet line limit.
	 */
	private static function ics_escape( string $value ): string {
		return strtr(
			$value,
			array(
				'\\' => '\\\\',
				';'  => '\\;',
				','  => '\\,',
				"\n" => '\\n',
				"\r" => '',
			)
		);
	}

	private static function render_join_og_png( string $token ): void {
		$group_post = Mantia_Repository::find_group_by_view_token( $token );
		if ( ! $group_post ) {
			status_header( 404 );
			exit;
		}
		$group_id = (int) $group_post->ID;
		$group    = Mantia_Repository::group_to_array( $group_id );
		$members  = Mantia_Repository::group_members( $group_id );
		$leader   = null;
		$rows     = Mantia_Leaderboard::rows( $group_id, 1 );
		if ( ! empty( $rows ) && (int) $rows[0]['points'] > 0 ) {
			$leader = $rows[0];
		}

		// Try PNG via GD first (best preview compatibility, esp. WhatsApp).
		$png = self::render_og_via_gd( $group, $members, $leader );
		if ( '' !== $png ) {
			header( 'Content-Type: image/png', true );
			header( 'Cache-Control: public, max-age=3600' );
			header( 'Content-Length: ' . strlen( $png ) );
			echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		// Fall back to SVG. Most modern preview scrapers handle it.
		$svg = self::build_join_og_svg( $group, $members, $leader );
		header( 'Content-Type: image/svg+xml; charset=utf-8', true );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Content-Length: ' . strlen( $svg ) );
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Render the OG image as PNG via GD. Walks a list of TTF candidate
	 * paths (bundled plugin font first, then a few stable host paths)
	 * and returns binary PNG bytes on success, '' on failure.
	 *
	 * @param array<string,mixed>      $group
	 * @param array<int,array<string,mixed>> $members
	 * @param array<string,mixed>|null $leader
	 */
	private static function render_og_via_gd( array $group, array $members, ?array $leader ): string {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
			return '';
		}
		$font_path = self::locate_og_font();
		if ( '' === $font_path ) {
			return '';
		}

		$W = 1200;
		$H = 630;
		$im = imagecreatetruecolor( $W, $H );
		// Cancha palette — lime ground, black ink, magenta accent.
		$lime    = imagecolorallocate( $im, 0xc5, 0xf2, 0x4e );
		$ink     = imagecolorallocate( $im, 0x0a, 0x0a, 0x0a );
		$soft    = imagecolorallocate( $im, 0x59, 0x58, 0x51 );
		$magenta = imagecolorallocate( $im, 0xff, 0x3d, 0x8e );
		$gold    = imagecolorallocate( $im, 0xff, 0xd4, 0x00 );
		$white   = imagecolorallocate( $im, 0xff, 0xff, 0xff );
		imagefilledrectangle( $im, 0, 0, $W, $H, $lime );

		$count    = count( $members );
		$has_lead = null !== $leader;

		// Archivo / Inter have no emoji glyphs, so emoji prefixes would render
		// as broken tofu boxes. Strip everything outside the printable ASCII +
		// Latin block before sending text to imagettftext.
		$comp = strtoupper( self::strip_non_text( (string) ( $group['competition_name'] ?? '' ) ) );
		$name = self::strip_non_text( (string) ( $group['name'] ?? 'Mantia' ) );

		$lead_line   = $has_lead
			? sprintf( '%s va ganando', self::strip_non_text( (string) $leader['name'] ) )
			: 'Penca abierta — sumate antes que arranque';
		$meta_parts  = array();
		if ( $count > 0 ) {
			$meta_parts[] = sprintf( 1 === $count ? '%d jugador' : '%d jugadores', $count );
		}
		if ( $has_lead ) {
			$meta_parts[] = sprintf( '%d pts · %d exactos', (int) $leader['points'], (int) $leader['exacts'] );
		}
		$meta_line = implode( ' · ', $meta_parts );

		// Top row: wordmark left, magenta sticker tag with competition right.
		imagettftext( $im, 30, 0, 64, 96, $ink, $font_path, 'mantia' );
		if ( '' !== $comp ) {
			$box   = imagettfbbox( 16, 0, $font_path, $comp );
			$tag_w = ( $box[2] - $box[0] ) + 36;
			$tag_h = 38;
			$tag_x = $W - 64 - $tag_w;
			$tag_y = 60;
			self::imagerect_rounded( $im, $tag_x, $tag_y, $tag_x + $tag_w, $tag_y + $tag_h, $magenta );
			self::imagerect_rounded_outline( $im, $tag_x, $tag_y, $tag_x + $tag_w, $tag_y + $tag_h, $ink );
			imagettftext( $im, 16, 0, $tag_x + 18, $tag_y + 26, $white, $font_path, $comp );
		}

		// Big medal disc left — gold for the leader, white circle as empty-state.
		// Drawn as filled circles to mimic the sticker disc with the rank inside.
		$cx = 200;
		$cy = 330;
		// Sticker shadow (offset black disc behind the medal)
		imagefilledellipse( $im, $cx + 8, $cy + 8, 280, 280, $ink );
		if ( $has_lead ) {
			imagefilledellipse( $im, $cx, $cy, 280, 280, $gold );
			imagefilledellipse( $im, $cx, $cy, 268, 268, $gold );
			// Black outline ring
			imagefilledellipse( $im, $cx, $cy, 280, 280, $ink );
			imagefilledellipse( $im, $cx, $cy, 272, 272, $gold );
			// Rank glyph inside — "1" / "2" / "3" or zero-padded "04"
			$mark = self::rank_label( (int) $leader['rank'] );
			$size = mb_strlen( $mark ) > 1 ? 160 : 200;
			$box  = imagettfbbox( $size, 0, $font_path, $mark );
			$tw   = $box[2] - $box[0];
			$th   = $box[1] - $box[7];
			$tx   = $cx - (int) round( $tw / 2 ) - $box[0];
			$ty   = $cy + (int) round( $th / 2 );
			imagettftext( $im, $size, 0, $tx, $ty, $ink, $font_path, $mark );
		} else {
			// Empty-state: white disc with black ring
			imagefilledellipse( $im, $cx, $cy, 280, 280, $ink );
			imagefilledellipse( $im, $cx, $cy, 272, 272, $white );
		}

		// Right block — name + lead line + meta. Auto-fit the headline so
		// long penca names don't overflow.
		$right_x   = 460;
		$right_max = $W - $right_x - 64;
		$headline_size = self::fit_font_size( $font_path, $name, $right_max, 60, 30 );

		imagettftext( $im, 18, 0, $right_x, 230, $ink, $font_path, 'SUMATE A' );
		imagettftext( $im, $headline_size, 0, $right_x, 310, $ink, $font_path, $name );
		imagettftext( $im, 22, 0, $right_x, 360, $ink, $font_path, $lead_line );
		if ( '' !== $meta_line ) {
			imagettftext( $im, 18, 0, $right_x, 400, $soft, $font_path, $meta_line );
		}

		// Bottom dashed rule + footer
		self::imageline_dashed( $im, 64, 540, $W - 64, 540, $ink );
		imagettftext( $im, 16, 0, 64, 588, $ink, $font_path, 'PENCA POR WHATSAPP' );
		$tag = 'MANTIA · 2026';
		$box = imagettfbbox( 16, 0, $font_path, $tag );
		$w   = $box[2] - $box[0];
		imagettftext( $im, 16, 0, $W - 64 - $w, 588, $ink, $font_path, $tag );

		ob_start();
		imagepng( $im, null, 8 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $im );
		return $bytes;
	}

	/**
	 * Filled rounded rectangle (sticker pill) for GD. Approximates a radius
	 * of $r via two side rectangles + four corner ellipses.
	 */
	private static function imagerect_rounded( $im, int $x1, int $y1, int $x2, int $y2, int $color, int $r = 18 ): void {
		imagefilledrectangle( $im, $x1 + $r, $y1, $x2 - $r, $y2, $color );
		imagefilledrectangle( $im, $x1, $y1 + $r, $x2, $y2 - $r, $color );
		imagefilledellipse( $im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color );
		imagefilledellipse( $im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color );
		imagefilledellipse( $im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color );
		imagefilledellipse( $im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color );
	}

	/**
	 * Stroked rounded rectangle. Approximates a 2px outline by drawing
	 * concentric rounded rects 2px apart.
	 */
	private static function imagerect_rounded_outline( $im, int $x1, int $y1, int $x2, int $y2, int $color, int $r = 18, int $thickness = 2 ): void {
		for ( $i = 0; $i < $thickness; $i++ ) {
			imagearc( $im, $x1 + $r, $y1 + $r, $r * 2 - $i, $r * 2 - $i, 180, 270, $color );
			imagearc( $im, $x2 - $r, $y1 + $r, $r * 2 - $i, $r * 2 - $i, 270, 360, $color );
			imagearc( $im, $x1 + $r, $y2 - $r, $r * 2 - $i, $r * 2 - $i, 90, 180, $color );
			imagearc( $im, $x2 - $r, $y2 - $r, $r * 2 - $i, $r * 2 - $i, 0, 90, $color );
			imageline( $im, $x1 + $r, $y1 + $i, $x2 - $r, $y1 + $i, $color );
			imageline( $im, $x1 + $r, $y2 - $i, $x2 - $r, $y2 - $i, $color );
			imageline( $im, $x1 + $i, $y1 + $r, $x1 + $i, $y2 - $r, $color );
			imageline( $im, $x2 - $i, $y1 + $r, $x2 - $i, $y2 - $r, $color );
		}
	}

	/**
	 * Dashed horizontal line. Used for the share poster's bottom divider.
	 */
	private static function imageline_dashed( $im, int $x1, int $y, int $x2, int $y2, int $color, int $dash = 12, int $gap = 8 ): void {
		for ( $x = $x1; $x < $x2; $x += $dash + $gap ) {
			$end = min( $x + $dash, $x2 );
			imagesetthickness( $im, 2 );
			imageline( $im, $x, $y, $end, $y, $color );
		}
		imagesetthickness( $im, 1 );
	}

	/**
	 * Strip emoji + other non-text codepoints so imagettftext doesn't render
	 * tofu boxes. Keeps ASCII, Latin-1 supplement + extended, basic Latin
	 * punctuation, and middot — enough for Spanish + the design's typography.
	 */
	private static function strip_non_text( string $s ): string {
		$out = preg_replace( '/[^\x{0020}-\x{007E}\x{00A0}-\x{024F}\x{2010}-\x{2027}\x{00B7}]+/u', '', $s );
		return trim( (string) $out );
	}

	/**
	 * Pick the largest font size from $max..$min that fits $text inside
	 * $max_width using $font_path. Returns the size in points.
	 */
	private static function fit_font_size( string $font_path, string $text, int $max_width, int $max, int $min ): int {
		for ( $size = $max; $size >= $min; $size -= 2 ) {
			$box = imagettfbbox( $size, 0, $font_path, $text );
			$w   = $box[2] - $box[0];
			if ( $w <= $max_width ) {
				return $size;
			}
		}
		return $min;
	}

	/**
	 * Locate a TTF font we can use for the OG image. Prefers a font
	 * bundled with the plugin; falls back to known host paths if not.
	 * Returns absolute path or empty string if none found.
	 */
	private static function locate_og_font(): string {
		$candidates = array(
			// Bundled with the plugin — always present, works on any WP host.
			MANTIA_PATH . 'assets/fonts/InterVariable.ttf',
			// Legacy bundle paths if a sibling project shipped one of these.
			MANTIA_PATH . 'assets/fonts/Inter-SemiBold.ttf',
			MANTIA_PATH . 'assets/fonts/Inter-Regular.ttf',
			// wp.com Atomic ships Inter inside the bundled default theme
			ABSPATH . 'wp-content/themes/twentytwentythree/assets/fonts/inter/Inter-VariableFont_slnt,wght.ttf',
		);
		// Also discover any theme-bundled NotoSans / Inter on the host.
		$discovered = glob( WP_CONTENT_DIR . '/themes/**/assets/fonts/**/*.ttf' );
		if ( is_array( $discovered ) ) {
			$candidates = array_merge( $candidates, $discovered );
		}
		foreach ( $candidates as $path ) {
			if ( is_string( $path ) && '' !== $path && file_exists( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Build the 1200×630 OG image as SVG. Layout: monumental brass mark
	 * left (rank or empty-dot), name + meta right. Marfil hairlines on
	 * deep ink ground — same vocabulary as the share-card poster but
	 * landscape so WhatsApp + Twitter render it without cropping.
	 */
	private static function build_join_og_svg( array $group, array $members, ?array $leader ): string {
		$name     = self::escape_svg( (string) ( $group['name'] ?? 'Mantia' ) );
		$comp     = self::escape_svg( strtoupper( (string) ( $group['competition_name'] ?? '' ) ) );
		$count    = count( $members );
		$has_lead = null !== $leader;

		$mark_label = $has_lead ? self::rank_label( (int) $leader['rank'] ) : '';
		// Gold for #1, silver/bronze for 2/3, white empty-state.
		$rank = $has_lead ? (int) $leader['rank'] : 0;
		$mark_fill = '#c5f24e';
		if ( 1 === $rank ) {
			$mark_fill = '#ffd400'; } elseif ( 2 === $rank ) {
			$mark_fill = '#dadada'; } elseif ( 3 === $rank ) {
				$mark_fill = '#e08a3c'; } elseif ( ! $has_lead ) {
				$mark_fill = '#ffffff'; }
				$mark_size = mb_strlen( $mark_label ) > 1 ? 110 : 150;

				$line1 = $has_lead
				? sprintf( '%s va ganando', self::escape_svg( (string) $leader['name'] ) )
				: __( 'Penca abierta — sumate antes que arranque', 'mantia' );
				$line2_parts = array();
				if ( $count > 0 ) {
					$line2_parts[] = sprintf(
						_n( '%d jugador', '%d jugadores', $count, 'mantia' ),
						$count
					);
				}
				if ( $has_lead ) {
					$line2_parts[] = sprintf( '%d pts · %d exactos', (int) $leader['points'], (int) $leader['exacts'] );
				}
				$line2 = self::escape_svg( implode( ' · ', $line2_parts ) );

				return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
	<rect width="1200" height="630" fill="#c5f24e"/>
	<!-- top row -->
	<text x="64" y="96" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="36" font-weight="900" font-style="italic" fill="#0a0a0a" letter-spacing="-1.5">mantia</text>
	<g transform="rotate(6 1000 80)">
		<rect x="820" y="60" rx="20" ry="20" width="290" height="40" fill="#ff3d8e" stroke="#0a0a0a" stroke-width="2.5"/>
		<text x="965" y="88" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="16" font-weight="900" letter-spacing="1.6" fill="#ffffff" text-anchor="middle">{$comp}</text>
	</g>
	<!-- medal disc on the left (shadow + disc + rank) -->
	<circle cx="208" cy="338" r="140" fill="#0a0a0a"/>
	<circle cx="200" cy="330" r="140" fill="{$mark_fill}" stroke="#0a0a0a" stroke-width="6"/>
	<text x="200" y="365" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="{$mark_size}" font-weight="900" fill="#0a0a0a" text-anchor="middle" letter-spacing="-4">{$mark_label}</text>
	<!-- right block: invitation -->
	<text x="460" y="222" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="18" font-weight="800" letter-spacing="3" fill="#0a0a0a">SUMATE A</text>
	<text x="460" y="296" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="60" font-weight="900" fill="#0a0a0a" letter-spacing="-2">{$name}</text>
	<text x="460" y="350" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="24" font-weight="700" fill="#0a0a0a">{$line1}</text>
	<text x="460" y="394" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="20" font-weight="700" fill="#595851">{$line2}</text>
	<!-- bottom dashed rule + footer -->
	<line x1="64" y1="540" x2="1136" y2="540" stroke="#0a0a0a" stroke-width="2" stroke-dasharray="12 8"/>
	<text x="64" y="588" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="16" font-weight="800" letter-spacing="2" fill="#0a0a0a">PENCA POR WHATSAPP</text>
	<text x="1136" y="588" font-family="Archivo, Helvetica, Arial, sans-serif" font-size="16" font-weight="800" letter-spacing="2" fill="#0a0a0a" text-anchor="end">MANTIA · 2026</text>
</svg>
SVG;
	}

	/**
	 * Minimal SVG-text escape (preserves the tags we control; escapes
	 * the dynamic strings interpolated inside <text> nodes).
	 */
	private static function escape_svg( string $s ): string {
		$s = htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		// Truncate very long names to keep the layout intact.
		if ( mb_strlen( $s ) > 36 ) {
			$s = mb_substr( $s, 0, 35 ) . '…';
		}
		return $s;
	}

	/**
	 * SVG → PNG via Imagick. Returns binary PNG bytes or '' on failure
	 * (caller handles the fallback). Imagick is available on wp.com
	 * Atomic + most managed WP hosts; on environments without it the
	 * empty string triggers the 1×1 transparent fallback.
	 */
	private static function svg_to_png( string $svg ): string {
		if ( ! class_exists( '\Imagick' ) ) {
			return '';
		}
		try {
			$im = new \Imagick();
			$im->setBackgroundColor( new \ImagickPixel( '#14130f' ) );
			$im->readImageBlob( '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . $svg );
			$im->setImageFormat( 'png32' );
			$im->setImageDepth( 8 );
			$im->resizeImage( 1200, 630, \Imagick::FILTER_LANCZOS, 1, true );
			$bytes = $im->getImageBlob();
			$im->clear();
			return (string) $bytes;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Share-card view for a group — shows the LEADER's standing as a 4:5
	 * portrait poster on a deep-ink ground, optimized to be screenshotted
	 * and pasted into a WhatsApp group chat. "Pull, no push" virality:
	 * the bot never sends unsolicited messages, the screenshot is the
	 * message.
	 */
	private static function render_share_group( string $token ): string {
		$group_post = Mantia_Repository::find_group_by_view_token( $token );
		if ( ! $group_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Este link no funciona o ya venció.', 'mantia' ) );
		}
		$group_id = (int) $group_post->ID;
		$group    = Mantia_Repository::group_to_array( $group_id );
		$rows     = Mantia_Leaderboard::rows( $group_id, 1 );
		$members  = Mantia_Repository::group_members( $group_id );

		// No predictions yet → no leader. Fall back to a different copy.
		$leader = ! empty( $rows ) ? $rows[0] : null;

		$back_url = Mantia_Repository::group_view_url( $group_id );
		if ( '' === $back_url ) {
			$back_url = home_url( '/pronostico/g/' . $token . '/' );
		}
		$share_url = $back_url;

		// Group share poster: 3rd-person headline. The recipient isn't
		// necessarily the leader, so "<leader> va primero en <group>"
		// reads as commentary instead of pretending the receiver is the
		// subject. Falls back to a quiet "en <group>" when there's no leader.
		$headline_html = '';
		$headline_text = '';
		$group_tag     = '<span class="mantia-share-in-tag">' . esc_html( (string) $group['name'] ) . '</span>';
		if ( $leader ) {
			$ordinal       = self::rank_phrase_es( (int) $leader['rank'] );
			/* translators: 1: ordinal phrase (primero / 5°), 2: group name. */
			$headline_html = sprintf( esc_html__( 'va %1$s en %2$s', 'mantia' ), esc_html( $ordinal ), $group_tag );
			$headline_text = sprintf(
				/* translators: 1: leader name, 2: ordinal phrase, 3: group name. */
				__( '%1$s · %2$s en %3$s', 'mantia' ),
				(string) $leader['name'],
				$ordinal,
				(string) $group['name']
			);
		} else {
			$headline_html = sprintf( esc_html__( 'en %s', 'mantia' ), $group_tag );
			$headline_text = sprintf( __( 'en %s', 'mantia' ), (string) $group['name'] );
		}

		return self::render_share_poster(
			array(
				'title'         => $group['name'],
				'subtitle'      => $group['competition_name'] ?? '',
				'leader_name'   => $leader ? (string) $leader['name'] : '',
				'leader_rank'   => $leader ? (int) $leader['rank'] : 0,
				'headline_html' => $headline_html,
				'headline_text' => $headline_text,
				'stat_a_value'  => $leader ? (int) $leader['points'] : 0,
				'stat_a_label'  => 'pts',
				'stat_b_value'  => $leader ? (int) $leader['exacts'] : 0,
				'stat_b_label'  => 'exc',
				'stat_c_value'  => count( $members ),
				'stat_c_label'  => 'jug',
				'share_url'     => $share_url,
				'back_url'      => $back_url,
				'empty_message' => __( 'Sin pronósticos todavía. Sumate antes que arranquen los partidos.', 'mantia' ),
			)
		);
	}

	/**
	 * Share-card view for a user — shows their own rank/points in their
	 * primary group as a screenshot-friendly portrait poster. Drawn from
	 * the same template as the group share.
	 */
	private static function render_share_user( string $token ): string {
		// Lookup by SHARE token, never view token. The two are
		// independently random — see Mantia_Repository::META_USER_SHARE_TOKEN
		// — so even a leaked share URL can't be transformed into the
		// private /me/<view>/ edit page.
		$user_post = Mantia_Repository::find_user_by_share_token( $token );
		if ( ! $user_post ) {
			status_header( 404 );
			return self::render_not_found( __( 'Este link de compartir no funciona o ya venció.', 'mantia' ) );
		}
		$user_id = (int) $user_post->ID;
		$name    = self::display_name_for( $user_id );
		$groups  = Mantia_Repository::user_groups_to_array( $user_id );

		// Pick the user's "best showing" — highest points across their groups.
		$best = null;
		foreach ( $groups as $g ) {
			$gid = (int) $g['id'];
			foreach ( Mantia_Leaderboard::rows( $gid, 100 ) as $row ) {
				if ( (int) $row['user_id'] === $user_id ) {
					if ( null === $best || (int) $row['points'] > (int) $best['row']['points'] ) {
						$best = array(
							'row' => $row,
							'group' => $g,
						);
					}
				}
			}
		}

		// "Back" goes home — NOT to the private /me/ page. We never want
		// to surface the view token in any link reachable from the share
		// poster page (someone screenshots the URL bar of the page they
		// were sent and the recipient could now navigate to it).
		$back_url = home_url( '/' );

		// Even on the empty-state poster the recipient should land on
		// THIS user's share view (not bare domain) — otherwise the wa.me
		// preview reads as "mantia3.wpcomstaging.com/" with no context.
		$share_group_url = $best
			? Mantia_Repository::group_view_url( (int) $best['group']['id'] )
			: home_url( '/pronostico/me/share/' . $token . '/' );

		// Rank-aware headline. The user's poster is 2nd-person ("vas X en Y")
		// because the share originates from the user themselves. For ranks
		// 1-3 the ordinal is spelled out; rank 4+ uses "4° en Y".
		$headline_html = '';
		$headline_text = '';
		if ( $best ) {
			$rank         = (int) $best['row']['rank'];
			$group_name   = (string) $best['group']['name'];
			$ordinal      = self::rank_phrase_es( $rank );
			$group_tag    = '<span class="mantia-share-in-tag">' . esc_html( $group_name ) . '</span>';
			/* translators: 1: ordinal phrase (primero / 5°), 2: group name. */
			$headline_html = sprintf( esc_html__( 'vas %1$s en %2$s', 'mantia' ), esc_html( $ordinal ), $group_tag );
			$headline_text = sprintf( __( 'vas %1$s en %2$s', 'mantia' ), $ordinal, $group_name );
		}

		return self::render_share_poster(
			array(
				'title'         => $name,
				'subtitle'      => $best ? (string) ( $best['group']['competition_name'] ?? '' ) : '',
				'leader_name'   => $name,
				'leader_rank'   => $best ? (int) $best['row']['rank'] : 0,
				'headline_html' => $headline_html,
				'headline_text' => $headline_text,
				'stat_a_value'  => $best ? (int) $best['row']['points'] : 0,
				'stat_a_label'  => 'pts',
				'stat_b_value'  => $best ? (int) $best['row']['exacts'] : 0,
				'stat_b_label'  => 'exc',
				'stat_c_value'  => $best ? (int) $best['row']['predictions'] : 0,
				'stat_c_label'  => 'jug',
				'share_url'     => $share_group_url,
				'back_url'      => $back_url,
				'empty_message' => __( 'Sin pronósticos todavía.', 'mantia' ),
			)
		);
	}

	/**
	 * Pure render helper for the share poster. Both group and user share
	 * views feed it. Stays on a deep-ink ground regardless of the rest of
	 * the site's marfil palette — the poster has to look monumental and
	 * "afiche-like" so people screenshot it.
	 *
	 * @param array<string,mixed> $args Poster fields.
	 */
	private static function render_share_poster( array $args ): string {
		$rank        = (int) ( $args['leader_rank'] ?? 0 );
		$share_url   = (string) ( $args['share_url'] ?? '' );
		$short_url   = preg_replace( '#^https?://#', '', $share_url );
		$has_leader  = '' !== (string) ( $args['leader_name'] ?? '' );

		ob_start();
		self::page_header( sprintf( __( 'Compartir — %s', 'mantia' ), (string) ( $args['title'] ?? 'Mantia' ) ), true );
		?>
		<main class="mantia-share">
			<div class="mantia-share-card">
				<div class="mantia-share-top">
					<span class="mantia-share-wordmark">mantia</span>
					<?php if ( ! empty( $args['subtitle'] ) ) : ?>
						<span class="mantia-share-comp"><?php echo esc_html( wp_strip_all_tags( (string) $args['subtitle'] ) ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $has_leader ) : ?>
					<div class="mantia-share-center">
						<div class="mantia-share-rank" data-rank="<?php echo esc_attr( (string) max( 1, min( $rank, 3 ) ) ); ?>"><?php echo esc_html( (string) $rank ); ?></div>
						<div class="mantia-share-name"><?php echo esc_html( (string) $args['leader_name'] ); ?></div>
						<?php if ( ! empty( $args['headline_html'] ) ) : ?>
							<div class="mantia-share-in"><?php
								echo $args['headline_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — caller-built, see render_share_user / render_share_group.
							?></div>
						<?php endif; ?>
					</div>

					<div class="mantia-share-stats">
						<div class="mantia-share-stat">
							<div class="mantia-share-num"><?php echo (int) $args['stat_a_value']; ?></div>
							<div class="mantia-share-label"><?php echo esc_html( (string) $args['stat_a_label'] ); ?></div>
						</div>
						<div class="mantia-share-stat">
							<div class="mantia-share-num"><?php echo (int) $args['stat_b_value']; ?></div>
							<div class="mantia-share-label"><?php echo esc_html( (string) $args['stat_b_label'] ); ?></div>
						</div>
						<div class="mantia-share-stat">
							<div class="mantia-share-num"><?php echo (int) $args['stat_c_value']; ?></div>
							<div class="mantia-share-label"><?php echo esc_html( (string) $args['stat_c_label'] ); ?></div>
						</div>
					</div>
				<?php else : ?>
					<div class="mantia-share-center mantia-share-empty">
						<div class="mantia-share-mark" aria-hidden="true"></div>
						<div class="mantia-share-name"><?php echo esc_html( (string) $args['title'] ); ?></div>
						<div class="mantia-share-in"><?php echo esc_html( (string) $args['empty_message'] ); ?></div>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $short_url ) : ?>
					<div class="mantia-share-url"><?php echo esc_html( $short_url ); ?></div>
				<?php endif; ?>
			</div>

			<?php
			// One-tap WhatsApp share: prefill a forwardable message with
			// the headline ("1° en La Familia · <url>") so the user
			// doesn't have to type anything in WhatsApp's compose box
			// after opening it. Caller passes a pre-built headline_text
			// (no HTML); fall back to the title for the empty-state poster.
			$wa_headline = (string) ( $args['headline_text'] ?? $args['title'] ?? '' );
			$wa_text     = '' !== $wa_headline && '' !== $short_url
				? sprintf( '%s · %s', wp_strip_all_tags( $wa_headline ), $short_url )
				: $short_url;
			$wa_link     = 'https://wa.me/?text=' . rawurlencode( $wa_text );
			?>
			<div class="mantia-share-actions">
				<a class="mantia-share-wa" href="<?php echo esc_url( $wa_link ); ?>" target="_blank" rel="noopener">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4c-.3-.2-1.8-.9-2.1-1-.3-.1-.5-.2-.7.2-.2.3-.8 1-1 1.2-.2.2-.4.2-.7 0-.3-.2-1.3-.5-2.5-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.7.1-.1.3-.4.4-.5.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5-.1-.2-.7-1.6-.9-2.2-.2-.6-.4-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1.1 1-1.1 2.5s1.1 2.9 1.3 3.1c.2.2 2.2 3.4 5.3 4.8.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2-1.4.3-.7.3-1.3.2-1.4-.1-.1-.3-.2-.6-.3zM12 2C6.5 2 2 6.5 2 12c0 1.8.5 3.5 1.3 5L2 22l5.2-1.3c1.4.7 3 1.2 4.8 1.2 5.5 0 10-4.5 10-10S17.5 2 12 2z"/></svg>
					<?php esc_html_e( 'Mandar por WhatsApp', 'mantia' ); ?>
				</a>
				<button class="mantia-share-copy" type="button" data-url="<?php echo esc_attr( $share_url ); ?>">
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="6" width="13" height="13" rx="2"/><path d="M14 6V4.5A1.5 1.5 0 0 0 12.5 3h-7A1.5 1.5 0 0 0 4 4.5v10A1.5 1.5 0 0 0 5.5 16H6"/></svg>
					<?php esc_html_e( 'Copiar link', 'mantia' ); ?>
				</button>
				<a class="mantia-share-close" href="<?php echo esc_url( (string) $args['back_url'] ); ?>">
					<?php esc_html_e( 'Cerrar', 'mantia' ); ?>
				</a>
			</div>
		</main>

		<script>
		(function () {
			var btn = document.querySelector('.mantia-share-copy');
			if (!btn) return;
			// Preserve the icon + original label so the swap doesn't blow the SVG away.
			var original = btn.innerHTML;
			var copied   = <?php echo wp_json_encode( __( 'Link copiado · pegalo en el grupo', 'mantia' ) ); ?>;
			btn.addEventListener('click', function () {
				var url = btn.getAttribute('data-url') || '';
				if (!url) return;
				var done = function () {
					btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12l5 5 11-11"/></svg>' + copied;
					btn.classList.add('is-copied');
					setTimeout(function () {
						btn.innerHTML = original;
						btn.classList.remove('is-copied');
					}, 1800);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(url).then(done, done);
				} else {
					var ta = document.createElement('textarea');
					ta.value = url; document.body.appendChild(ta); ta.select();
					try { document.execCommand('copy'); } catch (e) {}
					document.body.removeChild(ta);
					done();
				}
			});
		})();
		</script>
		<?php
		self::page_footer( true );
		return (string) ob_get_clean();
	}

	private static function render_not_found( string $message ): string {
		$bot_phone     = Mantia_Repository::bot_phone_e164();
		$bot_url       = '' !== $bot_phone ? sprintf( 'https://wa.me/%s?text=ayuda', $bot_phone ) : '';
		$default_id    = Mantia_Competitions::default_id();
		$home_url      = Mantia_Repository::competition_view_url( $default_id );
		$create_url    = self::create_penca_wa_url();
		// Dynamic CTA so the 404 recovery never points at a torneo that
		// was removed from the seed. Falls back to a generic label.
		$default_comp  = Mantia_Competitions::get( $default_id );
		$home_label    = $default_comp
			? sprintf( __( 'Ver %s', 'mantia' ), (string) $default_comp['name'] )
			: __( 'Ver torneo', 'mantia' );

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
				<a class="mantia-pill mantia-pill-primary" href="<?php echo esc_url( $home_url ); ?>"><?php echo esc_html( $home_label ); ?></a>
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
	 * PWA renderers (manifest, service worker, offline, icons)
	 * ================================================================= */

	/**
	 * Emit the Web App Manifest. Browsers fetch this once the
	 * <link rel="manifest"> tag is present; everything else (install
	 * prompt, splash screen, theme color, shortcuts) is derived from it.
	 */
	private static function render_pwa_manifest(): void {
		$home    = home_url( '/' );
		$default = Mantia_Competitions::default_id();
		$ranking = '' !== $default ? Mantia_Repository::competition_view_url( $default ) : $home;

		$manifest = array(
			'name'             => 'Mantia · Penca por WhatsApp',
			'short_name'       => 'Mantia',
			'description'      => __( 'Pronosticá, picanteá el grupo, ganale a tus amigos. Sin app.', 'mantia' ),
			'start_url'        => '/',
			'scope'            => '/',
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#c5f24e',
			'theme_color'      => '#c5f24e',
			'lang'             => 'es-UY',
			'dir'              => 'ltr',
			'categories'       => array( 'sports', 'social', 'entertainment' ),
			'icons'            => array(
				array(
					'src'     => home_url( '/icons/192.png/' ),
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				),
				array(
					'src'     => home_url( '/icons/512.png/' ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				),
			),
			'shortcuts'        => array(
				array(
					'name'  => __( 'Ver el ranking', 'mantia' ),
					'url'   => '' !== $ranking ? wp_make_link_relative( $ranking ) : '/',
					'icons' => array(
						array(
							'src' => home_url( '/icons/192.png/' ),
							'sizes' => '192x192',
						),
					),
				),
			),
		);

		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Emit the service worker. Strategy:
	 *   - Pre-cache the home + offline page + icons + manifest on install.
	 *   - Navigation requests: network-first → cache fallback → offline page.
	 *     Cache successful navigations so the user sees a recent snapshot
	 *     when they re-open the PWA without signal.
	 *   - Static assets (fonts, icons, og): cache-first with background
	 *     revalidation.
	 *
	 * Versioning: the cache name embeds PWA_VERSION. Bump that constant
	 * to force every installed client to drop old caches on activate.
	 */
	private static function render_pwa_service_worker(): void {
		$version       = self::PWA_VERSION;
		$home          = home_url( '/' );
		$offline       = home_url( '/pronostico/offline/' );
		$manifest_url  = home_url( '/manifest.json/' );
		$icon_192      = home_url( '/icons/192.png/' );
		$icon_512      = home_url( '/icons/512.png/' );

		// Service workers must be served with a JS content type and from the
		// origin they want to control. We also require the most permissive
		// scope header so SW at /service-worker.js controls all of /.
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'Cache-Control: no-cache' );

		$pre_cache = wp_json_encode(
			array( $home, $offline, $manifest_url, $icon_192, $icon_512 ),
			JSON_UNESCAPED_SLASHES
		);
		$offline_js = wp_json_encode( $offline, JSON_UNESCAPED_SLASHES );
		$ver_js     = wp_json_encode( $version );

		echo <<<JS
// Mantia · Service Worker
// Cache key is versioned — bump PWA_VERSION in PHP to evict old caches.

const VERSION = {$ver_js};
const CACHE   = VERSION;
const PRE_CACHE  = {$pre_cache};
const OFFLINE_URL = {$offline_js};

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches.open(CACHE).then((cache) => cache.addAll(PRE_CACHE)).then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys().then((keys) =>
			Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
		).then(() => self.clients.claim())
	);
});

// Heuristic for "this is a navigable HTML request". Strict equality on
// request.mode === 'navigate' is the canonical check, but we also accept
// GET requests with an Accept header that includes text/html.
function isNav(request) {
	if (request.mode === 'navigate') return true;
	const accept = request.headers.get('accept') || '';
	return request.method === 'GET' && accept.includes('text/html');
}

function isStaticAsset(url) {
	const p = url.pathname;
	return (
		p.startsWith('/icons/') ||
		p.endsWith('.png') ||
		p.endsWith('.svg') ||
		p.endsWith('.woff2') ||
		p.endsWith('/og/')
	);
}

self.addEventListener('fetch', (event) => {
	const req = event.request;
	if (req.method !== 'GET') return;

	const url = new URL(req.url);
	if (url.origin !== self.location.origin) return; // let cross-origin pass through

	// wa.me / WhatsApp redirect links never benefit from caching.
	if (url.pathname.startsWith('/wp-admin/') || url.pathname.startsWith('/wp-json/')) return;

	if (isNav(req)) {
		event.respondWith(
			fetch(req)
				.then((res) => {
					// Cache successful HTML responses so re-opens work offline.
					if (res && res.ok && res.type === 'basic') {
						const copy = res.clone();
						caches.open(CACHE).then((c) => c.put(req, copy));
					}
					return res;
				})
				.catch(() =>
					caches.match(req).then((hit) => hit || caches.match(OFFLINE_URL))
				)
		);
		return;
	}

	if (isStaticAsset(url)) {
		event.respondWith(
			caches.match(req).then((hit) => {
				const fetcher = fetch(req).then((res) => {
					if (res && res.ok) {
						const copy = res.clone();
						caches.open(CACHE).then((c) => c.put(req, copy));
					}
					return res;
				}).catch(() => hit);
				return hit || fetcher;
			})
		);
	}
});
JS;
		exit;
	}

	/**
	 * Render the offline fallback page. Shown when the service worker
	 * can't reach the network and there's no cached copy for the path
	 * the user asked for.
	 */
	/**
	 * Landing page for failed magic-link verification (bad sig, expired,
	 * replayed, missing). Surfaces a "pediselo de nuevo al bot" CTA so the
	 * user doesn't get stuck on a generic 404.
	 */
	private static function render_magic_link_expired(): string {
		$bot_phone = Mantia_Repository::bot_phone_e164();
		$wa_url    = '' !== $bot_phone
			? sprintf( 'https://wa.me/%s?text=%s', $bot_phone, rawurlencode( 'link' ) )
			: '';

		ob_start();
		self::page_header( __( 'Link vencido — Mantia', 'mantia' ) );
		?>
		<main class="mantia-page mantia-page-narrow">
			<?php self::render_topbar(); ?>
			<section class="mantia-hero">
				<div class="mantia-eyebrow"><?php esc_html_e( 'link vencido', 'mantia' ); ?></div>
				<h1 class="mantia-h1"><?php esc_html_e( 'Este link ya no funciona.', 'mantia' ); ?></h1>
				<p class="mantia-hero-meta"><?php esc_html_e( 'Por seguridad los links del bot vencen. Pediselo de nuevo escribiendo *link* en el chat — te llega uno fresco al toque.', 'mantia' ); ?></p>
			</section>
			<?php if ( '' !== $wa_url ) : ?>
				<section class="mantia-cta-section mantia-cta-stack">
					<a class="mantia-pill mantia-pill-wa" href="<?php echo esc_url( $wa_url ); ?>">
						<?php esc_html_e( 'Pediselo al bot', 'mantia' ); ?>
					</a>
				</section>
			<?php endif; ?>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	private static function render_pwa_offline(): string {
		$bot_phone = Mantia_Repository::bot_phone_e164();
		$wa_url    = '' !== $bot_phone ? sprintf( 'https://wa.me/%s?text=hola', $bot_phone ) : '';

		ob_start();
		self::page_header( __( 'Sin conexión — Mantia', 'mantia' ) );
		?>
		<main class="mantia-page mantia-page-narrow">
			<?php self::render_topbar(); ?>
			<section class="mantia-hero">
				<div class="mantia-eyebrow"><?php esc_html_e( 'sin señal', 'mantia' ); ?></div>
				<h1 class="mantia-h1"><?php esc_html_e( 'Mantia te espera cuando vuelva la red.', 'mantia' ); ?></h1>
				<p class="mantia-hero-meta"><?php esc_html_e( 'La penca se carga por WhatsApp — y la web necesita red para mostrar la tabla. Mientras tanto, mandale lo que tengas al bot cuando vuelvas.', 'mantia' ); ?></p>
			</section>
			<?php if ( '' !== $wa_url ) : ?>
				<section class="mantia-cta-section mantia-cta-stack">
					<a class="mantia-pill mantia-pill-wa" href="<?php echo esc_url( $wa_url ); ?>">
						<?php esc_html_e( 'Abrir WhatsApp', 'mantia' ); ?>
					</a>
				</section>
			<?php endif; ?>
		</main>
		<?php
		self::page_footer();
		return (string) ob_get_clean();
	}

	/**
	 * Render a Mantia icon (192 or 512) as a PNG. Lime ground + black
	 * italic Archivo Black "m" centred. Generated at runtime via GD so
	 * we don't have to bundle binary PNGs in the plugin repo; cached
	 * for a year client-side.
	 *
	 * Note on "any maskable" purpose: we reserve ~10% safe-zone padding
	 * so the icon survives the platform's adaptive mask (Android cuts
	 * a circle out of the bounding box).
	 */
	private static function render_pwa_icon( int $size ): void {
		$size = in_array( $size, array( 192, 512 ), true ) ? $size : 192;
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagettftext' ) ) {
			status_header( 503 );
			exit;
		}
		$font = self::locate_og_font();
		if ( '' === $font ) {
			status_header( 503 );
			exit;
		}

		$im = imagecreatetruecolor( $size, $size );
		// Allocate true colors; alpha not needed — solid lime ground reads
		// cleanly under both maskable + any purposes.
		$lime    = imagecolorallocate( $im, 0xc5, 0xf2, 0x4e );
		$ink     = imagecolorallocate( $im, 0x0a, 0x0a, 0x0a );
		$magenta = imagecolorallocate( $im, 0xff, 0x3d, 0x8e );
		imagefilledrectangle( $im, 0, 0, $size, $size, $lime );

		// Black outer ring inside the safe zone so the wordmark feels
		// contained even under an adaptive mask.
		$ring_inset = (int) round( $size * 0.10 );
		$ring_thick = (int) round( $size * 0.045 );
		for ( $i = 0; $i < $ring_thick; $i++ ) {
			imagerectangle(
				$im,
				$ring_inset + $i,
				$ring_inset + $i,
				$size - $ring_inset - $i - 1,
				$size - $ring_inset - $i - 1,
				$ink
			);
		}

		// Big italic "m" centered in the safe zone. imagettftext doesn't
		// support italic faux-slant so we approximate by transform-baking
		// the glyph onto a separate canvas, then copying it across; but
		// for simplicity (and Archivo not present), we just render upright
		// "m" in Inter Bold weight. Good enough at 192/512.
		$font_size  = (int) round( $size * 0.5 );
		$mark       = 'm';
		$box        = imagettfbbox( $font_size, 0, $font, $mark );
		$text_w     = $box[2] - $box[0];
		$text_h     = $box[1] - $box[7];
		$x          = (int) round( ( $size - $text_w ) / 2 - $box[0] );
		$y          = (int) round( ( $size + $text_h ) / 2 );
		imagettftext( $im, $font_size, 0, $x, $y, $ink, $font, $mark );

		// Small magenta dot in the corner — sticker accent that survives
		// the maskable crop on Android (lives just outside the safe zone
		// but inside the bounding box).
		$dot_r = (int) round( $size * 0.06 );
		$dot_x = (int) round( $size * 0.82 );
		$dot_y = (int) round( $size * 0.22 );
		imagefilledellipse( $im, $dot_x, $dot_y, $dot_r * 2, $dot_r * 2, $magenta );
		// Black outline on the dot
		for ( $i = 0; $i < 3; $i++ ) {
			imageellipse( $im, $dot_x, $dot_y, $dot_r * 2 + $i, $dot_r * 2 + $i, $ink );
		}

		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=' . YEAR_IN_SECONDS );
		imagepng( $im, null, 8 );
		imagedestroy( $im );
		exit;
	}

	/* =================================================================
	 * Component renderers
	 * ================================================================= */

	private static function render_topbar( string $share_url = '' ): void {
		?>
		<div class="mantia-topbar">
			<a class="mantia-topbar-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Inicio', 'mantia' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/><path d="M20 12H8"/></svg>
			</a>
			<span class="mantia-wordmark-sm">mantia</span>
			<?php if ( '' !== $share_url ) : ?>
				<a class="mantia-topbar-btn mantia-topbar-share" href="<?php echo esc_url( $share_url ); ?>" aria-label="<?php esc_attr_e( 'Compartir', 'mantia' ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7"/><path d="M9 7h8v8"/></svg>
				</a>
			<?php else : ?>
				<span class="mantia-topbar-spacer"></span>
			<?php endif; ?>
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
			<?php
			foreach ( $competitions as $c ) :
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
			endforeach;
			?>
		</nav>
		<?php
	}

	/**
	 * Render a leaderboard with optional pedestal for the #1 row.
	 *
	 * @param array $rows    Standardized leaderboard rows (rank/name/user_id/points/exacts/predictions[/group_name]).
	 * @param string $variant 'group' (no group_name column) or 'competition' (with group_name)
	 */
	private static function render_leaderboard( array $rows, string $variant = 'group', ?int $me_id = null ): void {
		if ( empty( $rows ) ) {
			return;
		}
		$leader = $rows[0];
		$rest          = array_slice( $rows, 1 );
		$me_is_leader  = null !== $me_id && (int) $leader['user_id'] === $me_id;
		$pedestal_cls  = 'mantia-pedestal' . ( $me_is_leader ? ' mantia-pedestal-me' : '' );
		?>
		<div class="<?php echo esc_attr( $pedestal_cls ); ?>">
			<?php echo self::user_avatar( (int) $leader['user_id'], 56, 'mantia-avatar-ring' ); ?>
			<div class="mantia-pedestal-text">
				<div class="mantia-eyebrow mantia-eyebrow-accent"><?php
					$me_is_leader ? esc_html_e( 'vas ganando', 'mantia' ) : esc_html_e( 'va ganando', 'mantia' );
				?></div>
				<div class="mantia-pedestal-name">
					<?php echo esc_html( $leader['name'] ); ?>
					<?php if ( $me_is_leader ) : ?><span class="mantia-pedestal-name-suffix"><?php esc_html_e( '· vos', 'mantia' ); ?></span><?php endif; ?>
				</div>
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
			<?php
			foreach ( $rest as $row ) :
				$rank   = (int) $row['rank'];
				$is_me  = null !== $me_id && (int) $row['user_id'] === $me_id;
				$row_cls = 'mantia-board-row' . ( $is_me ? ' mantia-board-row-me' : '' );
				?>
				<div class="<?php echo esc_attr( $row_cls ); ?>">
					<span class="mantia-rank" data-rank="<?php echo esc_attr( (string) $rank ); ?>"><?php echo esc_html( self::rank_label( $rank ) ); ?></span>
					<?php echo self::user_avatar( (int) $row['user_id'], 32 ); ?>
					<div class="mantia-board-player">
						<div class="mantia-board-name <?php echo $is_me ? 'mantia-board-name-me' : ''; ?>">
							<?php echo esc_html( $row['name'] ); ?>
							<?php if ( $is_me ) : ?><span class="mantia-board-name-suffix"><?php esc_html_e( '· vos', 'mantia' ); ?></span><?php endif; ?>
						</div>
						<div class="mantia-board-exc">
							<strong><?php echo (int) $row['exacts']; ?></strong>
							<span><?php echo esc_html( _n( 'exacto', 'exactos', (int) $row['exacts'], 'mantia' ) ); ?></span>
							<?php if ( 'competition' === $variant && ! empty( $row['group_name'] ) ) : ?>
								<span class="mantia-mid">·</span>
								<span class="mantia-board-group"><?php echo esc_html( $row['group_name'] ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<div class="mantia-board-stats">
						<span class="mantia-board-pts"><?php echo (int) $row['points']; ?></span>
						<span class="mantia-board-pts-suffix"><?php esc_html_e( 'pts', 'mantia' ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render history rows (predictions vs real result) for /me/.
	 */
	private static function render_history_rows( array $history ): void {
		?>
		<div class="mantia-history">
			<?php
			foreach ( array_slice( $history, 0, 8 ) as $p ) :
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
							<?php echo esc_html( self::normalize_team_name( (string) $m['home_team'] ) ); ?>
							<span class="mantia-mid">·</span>
							<?php echo esc_html( self::normalize_team_name( (string) $m['away_team'] ) ); ?>
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
			$by_day[ $day_key ][] = array(
				'm' => $m,
				'ts' => $ts,
			);
		}

		foreach ( $by_day as $entries ) :
			$first_ts = $entries[0]['ts'];

			// If every match this day shares the same phase, hoist it to the
			// day header — saves ~30 redundant "GROUP STAGE MATCHDAY 5" lines
			// on packed fixture pages. Per-match phase still renders when
			// phases differ within the same day.
			$day_phase  = self::normalize_phase( (string) ( $entries[0]['m']['phase'] ?? '' ) );
			$phase_uniform = '' !== $day_phase;
			foreach ( $entries as $entry ) {
				if ( self::normalize_phase( (string) ( $entry['m']['phase'] ?? '' ) ) !== $day_phase ) {
					$phase_uniform = false;
					break;
				}
			}
			?>
			<div class="mantia-day-group">
				<div class="mantia-day-eyebrow mantia-eyebrow"><?php
					echo esc_html( strtoupper( self::format_es_short_day( $first_ts ) ) );
					if ( $phase_uniform ) {
						echo ' · ' . esc_html( $day_phase );
					}
				?></div>
				<?php
				foreach ( $entries as $entry ) :
					$m     = $entry['m'];
					$hm    = gmdate( 'H:i', $entry['ts'] - 3 * HOUR_IN_SECONDS );
					$phase = self::normalize_phase( (string) ( $m['phase'] ?? '' ) );
					?>
					<div class="mantia-match-row">
						<div class="mantia-match-time"><?php echo esc_html( $hm ); ?></div>
						<div class="mantia-match-teams">
							<div class="mantia-match-names">
								<?php echo esc_html( self::normalize_team_name( (string) $m['home_team'] ) ); ?>
								<span class="mantia-mid">·</span>
								<?php echo esc_html( self::normalize_team_name( (string) $m['away_team'] ) ); ?>
							</div>
							<?php if ( '' !== $phase && ! $phase_uniform ) : ?>
								<div class="mantia-match-phase"><?php echo esc_html( $phase ); ?></div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		endforeach;
	}

	/**
	 * Editable variant of the match list: each row is a tiny form with
	 * home/away number inputs (pre-filled when a prediction exists) and a
	 * "Guardar" button that POSTs to /wp-json/mantia/v1/predictions.
	 *
	 * Token comes from the /me/<token>/ URL; we embed it as a data-attr
	 * on the surrounding container so the inline JS can read it without
	 * parsing window.location. The match still has to be in `scheduled`
	 * state with a future kickoff — the server validates this again on
	 * write, so this is purely a hint for the UI.
	 */
	private static function render_editable_matches( array $matches, int $user_id, int $group_id, string $token ): void {
		if ( empty( $matches ) ) {
			return;
		}

		// Bucket by local day (UY time = GMT-3). Reusing the same shape
		// that render_matches_grouped_by_day produces so the eyebrows
		// read identically.
		$by_day = array();
		foreach ( $matches as $m ) {
			$ts = self::parse_gmt_ts( (string) $m['kickoff_gmt'] );
			if ( null === $ts ) {
				continue;
			}
			$day_key            = gmdate( 'Y-m-d', $ts - 3 * HOUR_IN_SECONDS );
			$by_day[ $day_key ] = $by_day[ $day_key ] ?? array();
			$by_day[ $day_key ][] = array(
				'm' => $m,
				'ts' => $ts,
			);
		}

		?>
		<div class="mantia-edit-list" data-mantia-token="<?php echo esc_attr( $token ); ?>">
			<?php
			foreach ( $by_day as $entries ) :
				$first_ts = $entries[0]['ts'];
				?>
				<div class="mantia-day-group">
					<div class="mantia-day-eyebrow mantia-eyebrow"><?php echo esc_html( strtoupper( self::format_es_short_day( $first_ts ) ) ); ?></div>
					<?php
					foreach ( $entries as $entry ) :
						$m   = $entry['m'];
						$hm  = gmdate( 'H:i', $entry['ts'] - 3 * HOUR_IN_SECONDS );
						$pred = Mantia_Repository::find_prediction( $user_id, (int) $m['id'], $group_id );
						$home_val = '';
						$away_val = '';
						if ( $pred ) {
							$home_val = (string) (int) get_post_meta( (int) $pred->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
							$away_val = (string) (int) get_post_meta( (int) $pred->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
						}
						?>
						<form class="mantia-edit-row" data-match-id="<?php echo (int) $m['id']; ?>">
							<div class="mantia-edit-meta">
								<div class="mantia-edit-time"><?php echo esc_html( $hm ); ?></div>
								<div class="mantia-edit-teams">
									<?php echo esc_html( self::normalize_team_name( (string) $m['home_team'] ) ); ?>
									<span class="mantia-mid">·</span>
									<?php echo esc_html( self::normalize_team_name( (string) $m['away_team'] ) ); ?>
								</div>
								<?php $phase_edit = self::normalize_phase( (string) ( $m['phase'] ?? '' ) ); ?>
								<?php if ( '' !== $phase_edit ) : ?>
									<div class="mantia-match-phase"><?php echo esc_html( $phase_edit ); ?></div>
								<?php endif; ?>
							</div>
							<div class="mantia-edit-inputs">
								<input class="mantia-edit-score" name="home_score" type="number" min="0" max="20" inputmode="numeric" autocomplete="off"
									value="<?php echo esc_attr( $home_val ); ?>"
									aria-label="<?php echo esc_attr( sprintf( __( 'Goles de %s', 'mantia' ), self::normalize_team_name( (string) $m['home_team'] ) ) ); ?>">
								<span class="mantia-edit-sep">·</span>
								<input class="mantia-edit-score" name="away_score" type="number" min="0" max="20" inputmode="numeric" autocomplete="off"
									value="<?php echo esc_attr( $away_val ); ?>"
									aria-label="<?php echo esc_attr( sprintf( __( 'Goles de %s', 'mantia' ), self::normalize_team_name( (string) $m['away_team'] ) ) ); ?>">
							</div>
							<button class="mantia-edit-save" type="submit" aria-label="<?php esc_attr_e( 'Guardar pronóstico', 'mantia' ); ?>">
								<?php esc_html_e( 'Guardar', 'mantia' ); ?>
							</button>
							<div class="mantia-edit-quick" aria-label="<?php esc_attr_e( 'Marcadores rápidos', 'mantia' ); ?>">
								<?php
								// Most-common World Cup scorelines, ordered by likelihood —
								// matches the distribution in random_realistic_score so the
								// chips feel familiar after auto-fill.
								$quick = array(
									array( 1, 0 ),
									array( 1, 1 ),
									array( 2, 1 ),
									array( 2, 0 ),
									array( 0, 0 ),
									array( 0, 1 ),
								);
								foreach ( $quick as $q ) :
									?>
									<button type="button" class="mantia-edit-chip"
										data-home="<?php echo (int) $q[0]; ?>"
										data-away="<?php echo (int) $q[1]; ?>"
										aria-label="<?php echo esc_attr( sprintf( '%d a %d', $q[0], $q[1] ) ); ?>"
									><?php echo (int) $q[0]; ?>·<?php echo (int) $q[1]; ?></button>
								<?php endforeach; ?>
							</div>
							<div class="mantia-edit-status" hidden></div>
						</form>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php self::print_edit_script_once(); ?>
		<?php
	}

	/**
	 * Print the AJAX-save script tag once per page. Subsequent calls to
	 * render_editable_matches won't re-emit it.
	 */
	private static function print_edit_script_once(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		$rest_url = esc_url_raw( rest_url( Mantia_Rest::NAMESPACE_V1 . '/predictions' ) );
		?>
		<script>
		(function () {
			var REST_URL = <?php echo wp_json_encode( $rest_url ); ?>;
			var lists = document.querySelectorAll('.mantia-edit-list');
			lists.forEach(function (list) {
				var token = list.getAttribute('data-mantia-token') || '';

				// Quick-tap chip: fill the inputs with the chip's score and
				// trigger submit. One tap = one prediction saved, no typing
				// or keyboard required.
				list.addEventListener('click', function (evt) {
					var chip = evt.target.closest('.mantia-edit-chip');
					if (!chip) return;
					var form = chip.closest('.mantia-edit-row');
					if (!form) return;
					evt.preventDefault();
					form.querySelector('input[name="home_score"]').value = chip.getAttribute('data-home') || '0';
					form.querySelector('input[name="away_score"]').value = chip.getAttribute('data-away') || '0';
					form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
				});

				list.addEventListener('submit', function (evt) {
					evt.preventDefault();
					var form = evt.target.closest('.mantia-edit-row');
					if (!form) return;
					var matchId = parseInt(form.getAttribute('data-match-id') || '0', 10);
					var home = parseInt(form.querySelector('input[name="home_score"]').value, 10);
					var away = parseInt(form.querySelector('input[name="away_score"]').value, 10);
					if (isNaN(home) || isNaN(away) || home < 0 || away < 0) {
						setStatus(form, 'Cargá los dos marcadores.', false);
						return;
					}
					var btn = form.querySelector('.mantia-edit-save');
					btn.disabled = true;
					setStatus(form, 'Guardando…', null);
					fetch(REST_URL, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
						body: JSON.stringify({ token: token, match_id: matchId, home_score: home, away_score: away })
					}).then(function (res) {
						return res.json().then(function (body) { return { res: res, body: body }; });
					}).then(function (r) {
						btn.disabled = false;
						if (r.res.ok && r.body && r.body.ok) {
							var n = (r.body.groups || []).length;
							var msg = n > 1 ? ('Guardado en ' + n + ' pencas ✓') : 'Guardado ✓';
							setStatus(form, msg, true);
							setTimeout(function () { setStatus(form, '', null); }, 2400);
						} else {
							setStatus(form, (r.body && r.body.message) || 'No se pudo guardar.', false);
						}
					}).catch(function () {
						btn.disabled = false;
						setStatus(form, 'Sin red — intentá de nuevo en un toque.', false);
					});
				});
			});
			function setStatus(form, msg, ok) {
				var el = form.querySelector('.mantia-edit-status');
				if (!el) return;
				if (!msg) {
					el.hidden = true;
					el.textContent = '';
					el.classList.remove('is-ok', 'is-err');
					return;
				}
				el.hidden = false;
				el.textContent = msg;
				el.classList.toggle('is-ok', ok === true);
				el.classList.toggle('is-err', ok === false);
			}
		})();
		</script>
		<?php
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
	private static function user_avatar( int $user_id, int $size = 40, string $extra_class = '' ): string {
		$cls = 'mantia-avatar' . ( '' !== $extra_class ? ' ' . $extra_class : '' );
		$url = Mantia_Repository::user_avatar_url( $user_id );
		if ( '' !== $url ) {
			return sprintf(
				'<img class="%s mantia-avatar-img" src="%s" width="%d" height="%d" alt="" loading="lazy">',
				esc_attr( $cls ),
				esc_url( $url ),
				$size,
				$size
			);
		}

		$name = self::display_name_for( $user_id );
		if ( '' === $name || __( 'jugador', 'mantia' ) === $name ) {
			$initials = '?';
		} else {
			$initials = self::initials_from( $name );
		}

		// Seed the hue from user_id, not display name. Two players named
		// "Miguel" in the same competition need different avatar colors so
		// the leaderboard reads as distinct people instead of "is this the
		// same user duplicated?".
		$hue = abs( crc32( 'u' . $user_id ) ) % 360;
		$bg  = sprintf( 'oklch(0.78 0.18 %d)', $hue );
		$fg  = sprintf( 'oklch(0.20 0.05 %d)', $hue );
		$style = sprintf(
			'--avatar-bg:%s;--avatar-fg:%s;width:%dpx;height:%dpx;font-size:%dpx;',
			$bg,
			$fg,
			$size,
			$size,
			(int) round( $size * 0.42 )
		);
		return sprintf(
			'<span class="%s mantia-avatar-initials" style="%s" aria-hidden="true">%s</span>',
			esc_attr( $cls ),
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
	 * Resolve a friendly display name for a user. WhatsApp Cloud API
	 * delivers profile.name on every inbound and Mantia stores it via
	 * Mantia_Repository::get_or_create_user(); the fallback below kicks
	 * in only when the WA name was missing AND the user never set one.
	 * Falls back to the formatted phone so people can match it to their
	 * contacts list.
	 */
	private static function display_name_for( int $user_id ): string {
		return Mantia_Repository::user_display_name( $user_id );
	}

	/**
	 * Roman numerals for ranks 1-3 (Greek classical touch), arabic
	 * zero-padded for the rest.
	 */
	private static function rank_label( int $n ): string {
		// Cancha aesthetic uses arabic digits inside medal discs (1/2/3
		// then zero-padded 04/05). The marfil edition used Roman
		// numerals for the top three; that read as classical, but
		// looks off inside a coloured medal.
		if ( $n <= 0 ) {
			return '—';
		}
		if ( $n <= 3 ) {
			return (string) $n;
		}
		return str_pad( (string) $n, 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Normalize a phase string from upstream fixture data into Spanish.
	 * Feeds (Wikipedia, FIFA, etc.) ship English labels like "Group stage"
	 * or "Group stage Matchday 5" — render those as "Fase de grupos" /
	 * "Fase de grupos · Fecha 5" so the public UI stays single-language.
	 * Unknown phases pass through unchanged.
	 */
	public static function normalize_phase( string $phase ): string {
		$phase = trim( $phase );
		if ( '' === $phase ) {
			return '';
		}
		// "Group stage Matchday 5" → "Fase de grupos · Fecha 5"
		if ( preg_match( '/^group stage\s+matchday\s*(\d+)$/i', $phase, $match ) ) {
			return sprintf( __( 'Fase de grupos · Fecha %d', 'mantia' ), (int) $match[1] );
		}
		// "Matchday 5" alone → "Fecha 5" (no group-stage assumption).
		if ( preg_match( '/^matchday\s*(\d+)$/i', $phase, $match ) ) {
			return sprintf( __( 'Fecha %d', 'mantia' ), (int) $match[1] );
		}
		$map = array(
			'group stage'     => __( 'Fase de grupos', 'mantia' ),
			'round of 32'     => __( '32avos de final', 'mantia' ),
			'round of 16'     => __( 'Octavos de final', 'mantia' ),
			'quarter-finals'  => __( 'Cuartos de final', 'mantia' ),
			'quarter finals'  => __( 'Cuartos de final', 'mantia' ),
			'semi-finals'     => __( 'Semifinales', 'mantia' ),
			'semi finals'     => __( 'Semifinales', 'mantia' ),
			'final'           => __( 'Final', 'mantia' ),
			'third place'     => __( 'Tercer puesto', 'mantia' ),
			'play-off'        => __( 'Repechaje', 'mantia' ),
			'playoff'         => __( 'Repechaje', 'mantia' ),
		);
		$key = strtolower( $phase );
		return $map[ $key ] ?? $phase;
	}

	/**
	 * Localize country / club names that fixture feeds ship in English.
	 * Same passthrough rule as normalize_phase — only known mappings get
	 * replaced; everything else renders as-is.
	 */
	public static function normalize_team_name( string $name ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return '';
		}
		static $map = array(
			'Mexico'          => 'México',
			'Brazil'          => 'Brasil',
			'Spain'           => 'España',
			'United States'   => 'Estados Unidos',
			'USA'             => 'Estados Unidos',
			'Germany'         => 'Alemania',
			'France'          => 'Francia',
			'England'         => 'Inglaterra',
			'Italy'           => 'Italia',
			'Belgium'         => 'Bélgica',
			'Netherlands'     => 'Países Bajos',
			'Switzerland'     => 'Suiza',
			'Sweden'          => 'Suecia',
			'Denmark'         => 'Dinamarca',
			'Norway'          => 'Noruega',
			'Poland'          => 'Polonia',
			'Czechia'         => 'Chequia',
			'Czech Republic'  => 'Chequia',
			'Croatia'         => 'Croacia',
			'Serbia'          => 'Serbia',
			'Türkiye'         => 'Turquía',
			'Turkey'          => 'Turquía',
			'Greece'          => 'Grecia',
			'Egypt'           => 'Egipto',
			'Morocco'         => 'Marruecos',
			'South Africa'    => 'Sudáfrica',
			'Korea Republic'  => 'Corea del Sur',
			'South Korea'     => 'Corea del Sur',
			'Japan'           => 'Japón',
			'Saudi Arabia'    => 'Arabia Saudita',
			'Iran'            => 'Irán',
			'Australia'       => 'Australia',
			'New Zealand'     => 'Nueva Zelanda',
			'Canada'          => 'Canadá',
			'Russia'          => 'Rusia',
			'Ukraine'         => 'Ucrania',
			'Ireland'         => 'Irlanda',
			'Scotland'        => 'Escocia',
			'Wales'           => 'Gales',
		);
		return $map[ $name ] ?? $name;
	}

	/**
	 * Spanish ordinal for share-poster headlines: "primero", "segundo",
	 * "tercero" for the podium, then numeric "4°", "5°" for the rest.
	 * Anything <= 0 returns empty so callers can fall back.
	 */
	private static function rank_phrase_es( int $n ): string {
		switch ( $n ) {
			case 1: return __( 'primero', 'mantia' );
			case 2: return __( 'segundo', 'mantia' );
			case 3: return __( 'tercero', 'mantia' );
			default: return $n > 0 ? sprintf( '%d°', $n ) : '';
		}
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
					: sprintf( '%s → %s', self::format_es_short_day( $first ), self::format_es_short_day( $last ) );
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

	private static function page_header( string $title, bool $for_share = false ): void {
		// Cancha lime is the site palette now; the share poster shifts to lime
		// too — the somber-ink poster from the marfil edition is gone.
		$theme_color = '#c5f24e';
		$body_class  = $for_share ? 'mantia-body-share' : '';
		// Trailing slashes are mandatory: wp.com Atomic's nginx hijacks
		// requests whose path ends in a recognized static extension (.json,
		// .js, .png) before WP can route them. A trailing slash dodges
		// that and lets our rewrite rule match.
		$manifest    = home_url( '/manifest.json/' );
		$icon_192    = home_url( '/icons/192.png/' );
		$icon_512    = home_url( '/icons/512.png/' );
		?>
		<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="theme-color" content="<?php echo esc_attr( $theme_color ); ?>">
	<title><?php echo esc_html( $title ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800;900&family=Archivo+Black&display=swap" rel="stylesheet">
	<link rel="manifest" href="<?php echo esc_url( $manifest ); ?>">
	<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( $icon_192 ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( $icon_192 ); ?>">
	<link rel="apple-touch-icon" sizes="512x512" href="<?php echo esc_url( $icon_512 ); ?>">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
	<meta name="apple-mobile-web-app-title" content="Mantia">
	<meta name="application-name" content="Mantia">
	<style><?php echo self::stylesheet(); ?></style>
</head>
<body class="<?php echo esc_attr( $body_class ); ?>">
		<?php
	}

	private static function page_footer( bool $for_share = false ): void {
		if ( $for_share ) {
			self::print_pwa_register();
			echo '</body></html>';
			return;
		}
		?>
		<footer class="mantia-foot">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">mantia</a> · penca por whatsapp
		</footer>
		<?php self::print_pwa_register(); ?>
</body>
</html>
		<?php
	}

	/**
	 * Register the service worker after page load. Kept inline so we
	 * don't have to ship a separate JS file just for one navigator call.
	 * Falls silent on browsers without SW support (still renders fine
	 * as a plain web page).
	 */
	private static function print_pwa_register(): void {
		$sw_url = home_url( '/service-worker.js/' );
		?>
		<script>
		if ('serviceWorker' in navigator) {
			window.addEventListener('load', function () {
				navigator.serviceWorker.register(<?php echo wp_json_encode( wp_make_link_relative( $sw_url ) ); ?>, { scope: '/' }).catch(function () { /* offline-first is optional; fail silently */ });
			});
		}

		// PWA install prompt: when Chrome/Edge/Android fires
		// beforeinstallprompt (it does once, after our manifest +
		// service worker have been seen by the page for a while), we
		// stash the event and reveal a small banner with a one-tap
		// install button. iOS doesn't fire this event — Safari is
		// "Add to Home Screen" from the share menu — so we don't
		// surface a banner there to avoid lying about the action.
		(function () {
			var deferredPrompt = null;
			var banner = document.querySelector('.mantia-pwa-install');
			if (!banner) return;
			function isDismissed() {
				try { return localStorage.getItem('mantia-pwa-dismissed') === '1'; }
				catch (e) { return false; }
			}
			window.addEventListener('beforeinstallprompt', function (evt) {
				evt.preventDefault();
				deferredPrompt = evt;
				// Respect a prior dismissal — without this check the banner
				// re-appears on every page load whenever Chrome re-fires
				// the install prompt event.
				if (isDismissed()) return;
				banner.hidden = false;
			});
			banner.addEventListener('click', function (evt) {
				if (evt.target.closest('.mantia-pwa-dismiss')) {
					banner.hidden = true;
					try { localStorage.setItem('mantia-pwa-dismissed', '1'); } catch (e) {}
					return;
				}
				if (evt.target.closest('.mantia-pwa-install-btn') && deferredPrompt) {
					deferredPrompt.prompt();
					deferredPrompt.userChoice.then(function () {
						deferredPrompt = null;
						banner.hidden = true;
					});
				}
			});
			window.addEventListener('appinstalled', function () { banner.hidden = true; });
		})();

		// Sticky compact stats bar on /me/. When the big stat-grid scrolls
		// out of view, reveal a slim horizontal bar with name + pts + exc.
		// IntersectionObserver is supported by every browser >= 2018.
		(function () {
			var sticky = document.querySelector('.mantia-me-sticky');
			var grid   = document.querySelector('.mantia-stat-grid');
			if (!sticky || !grid || !('IntersectionObserver' in window)) return;
			var io = new IntersectionObserver(function (entries) {
				sticky.hidden = entries[0].isIntersecting;
			}, { rootMargin: '-60px 0px 0px 0px' });
			io.observe(grid);
		})();

		// Scroll the active competition chip into view inside its
		// horizontal nav. Without this, pages whose chip is far right
		// in the list (e.g. "Otra / Personalizada") load with the active
		// chip off-screen and no visible scroll affordance.
		(function () {
			var chips = document.querySelector('.mantia-chips');
			if (!chips) return;
			var active = chips.querySelector('.mantia-chip.is-active');
			if (!active) return;
			var offset = active.offsetLeft - (chips.clientWidth - active.offsetWidth) / 2;
			chips.scrollLeft = Math.max(0, offset);
		})();
		</script>
		<?php
	}

	private static function stylesheet(): string {
		return <<<'CSS'
/* Mantia · juvenil "cancha" · Archivo Black + sticker shadows */

:root {
	--bg: #c5f24e;                     /* cancha lime */
	--ink: #0a0a0a;
	--ink-soft: #595851;
	--rule: rgba(10,10,10,0.10);
	--surface: #ffffff;
	--field: rgba(10,10,10,0.06);
	--accent: #ff3d8e;                 /* hot magenta */
	--accent-2: #ffe54a;               /* electric yellow */
	--accent-3: #2a7bff;
	--medal-1: #ffd400;                /* gold */
	--medal-2: #dadada;                /* silver */
	--medal-3: #e08a3c;                /* bronze */
	--shadow-sticker: 4px 4px 0 var(--ink);
	--shadow-stickerL: 6px 6px 0 var(--ink);
	--shadow-stickerS: 2px 2px 0 var(--ink);

	--font-display: 'Archivo Black', Archivo, Helvetica, Arial, sans-serif;
	--font-body:    Archivo, 'Archivo Black', Helvetica, Arial, sans-serif;
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
	width: 42px; height: 42px;
	border: 2.5px solid var(--ink);
	border-radius: 999px;
	display: inline-flex; align-items: center; justify-content: center;
	color: var(--ink);
	text-decoration: none;
	background: var(--surface);
	cursor: pointer;
	box-shadow: 3px 3px 0 var(--ink);
}
.mantia-topbar-btn:hover { transform: translate(1px, 1px); box-shadow: 2px 2px 0 var(--ink); }
.mantia-topbar-share { background: var(--accent-2); }
.mantia-topbar-spacer { width: 42px; }
.mantia-wordmark-sm {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 18px;
	letter-spacing: -0.05em;
	color: var(--ink);
	font-style: italic;
}

/* ─── Typography ─────────────────────────────────────────────────── */

.mantia-h1 {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 38px;
	line-height: 0.98;
	letter-spacing: -0.04em;
	color: var(--ink);
	margin: 10px 0 0;
}
.mantia-h1-balance { text-wrap: balance; }
.mantia-h2 {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 24px;
	line-height: 1.08;
	letter-spacing: -0.025em;
	color: var(--ink);
	margin: 0;
}
.mantia-eyebrow {
	font-family: var(--font-body);
	font-size: 11.5px;
	font-weight: 800;
	letter-spacing: 0.16em;
	text-transform: uppercase;
	color: var(--ink);
	display: inline-flex;
	align-items: center;
	gap: 8px;
}
/* Leading dash mark (turns plain eyebrows into the juvenil "·— TAG" style). */
.mantia-eyebrow::before {
	content: "";
	width: 14px;
	height: 3px;
	background: var(--ink);
	display: inline-block;
}
.mantia-eyebrow-accent { color: var(--accent); }
.mantia-eyebrow-accent::before { background: var(--accent); }
.mantia-eyebrow-row {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	margin: 0 0 16px;
}
.mantia-eyebrow-count {
	font-family: var(--font-body);
	font-size: 11.5px;
	font-weight: 700;
	color: var(--ink-soft);
	letter-spacing: 0.04em;
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
	display: inline-flex;
	align-items: center;
	font-family: var(--font-body);
	font-size: 11.5px;
	font-weight: 800;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--accent-2);
	background: var(--ink);
	padding: 5px 11px;
	border-radius: 999px;
	border: 2px solid var(--ink);
	text-decoration: none;
	margin-bottom: 12px;
}
.mantia-crumb:hover { transform: translate(1px, 1px); }

.mantia-numeral {
	font-family: var(--font-display);
	font-weight: 900;
	font-variant-numeric: tabular-nums;
	color: var(--ink);
	line-height: 0.9;
	letter-spacing: -0.04em;
}
.mantia-numeral-s { font-size: 18px; line-height: 1; }
.mantia-numeral-m { font-size: 22px; }
.mantia-numeral-l { font-size: 52px; }

.mantia-stat-label {
	font-family: var(--font-body);
	font-size: 10.5px;
	font-weight: 800;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: var(--ink-soft);
	margin-top: 6px;
}
.mantia-stat-label-inline {
	font-family: var(--font-body);
	font-size: 10.5px;
	font-weight: 800;
	letter-spacing: 0.14em;
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
.mantia-empty-soft {
	font-size: 13px;
	color: var(--ink-soft);
	margin: 4px 0 10px;
}
.mantia-empty-card {
	background: var(--field);
	padding: 14px 16px;
	border-radius: 4px;
	color: var(--ink-soft);
	font-size: 14px;
	margin: 14px 0 0;
}
/* Roster shown on /pronostico/g/<token>/ when no scores tabulated yet —
   gives fresh joiners visible confirmation they're in the group, plus
   answers "who's already here?" until the leaderboard wakes up. */
.mantia-roster {
	display: grid;
	gap: 6px;
	background: var(--surface);
	border: 2px solid var(--ink);
	border-radius: 14px;
	padding: 12px 14px;
	box-shadow: 2px 2px 0 var(--ink);
}
.mantia-roster-row {
	display: flex;
	align-items: center;
	gap: 10px;
	font-family: var(--font-body);
	font-weight: 700;
	font-size: 14px;
	color: var(--ink);
}
.mantia-roster-row-me {
	background: var(--accent-2);
	border-radius: 8px;
	padding: 4px 8px;
	margin: -4px -8px;
}
.mantia-roster-name { min-width: 0; }
.mantia-roster-me {
	color: var(--ink-soft);
	font-weight: 600;
	font-size: 13px;
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
	gap: 9px;
	height: 54px;
	padding: 0 22px;
	border-radius: 999px;
	font-family: var(--font-body);
	font-size: 16px;
	font-weight: 800;
	letter-spacing: -0.01em;
	text-decoration: none;
	border: 2.5px solid var(--ink);
	cursor: pointer;
	background: var(--surface);
	color: var(--ink);
	box-shadow: var(--shadow-sticker);
	transition: transform 0.12s ease, box-shadow 0.12s ease;
	-webkit-tap-highlight-color: transparent;
}
.mantia-pill:hover { transform: translate(1px, 1px); box-shadow: 3px 3px 0 var(--ink); }
.mantia-pill:active { transform: translate(3px, 3px); box-shadow: 1px 1px 0 var(--ink); }
.mantia-pill-primary {
	background: var(--accent);
	color: #ffffff;
}
.mantia-pill-ghost {
	background: var(--surface);
	color: var(--ink);
}
.mantia-pill-wa {
	background: #25D366;
	color: var(--ink);
}
.mantia-pill-ink {
	background: var(--ink);
	color: var(--surface);
}
.mantia-page .mantia-pill { width: 100%; }
.mantia-aside { margin: 18px 0 4px; }
.mantia-aside-pair {
	margin: 28px 0 4px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: center;
}
.mantia-ghost-link {
	color: var(--ink);
	text-decoration: underline;
	text-decoration-thickness: 2px;
	text-underline-offset: 4px;
	font-family: var(--font-body);
	font-size: 13.5px;
	font-weight: 800;
	letter-spacing: -0.005em;
}
.mantia-ghost-link:hover { color: var(--accent); }

/* ─── Chips (competition nav) ────────────────────────────────────── */

.mantia-chips {
	display: flex;
	gap: 8px;
	padding: 0 0 22px;
	overflow-x: auto;
	scrollbar-width: none;
	-webkit-overflow-scrolling: touch;
}
.mantia-chips::-webkit-scrollbar { display: none; }
.mantia-chip {
	white-space: nowrap;
	padding: 9px 15px;
	border-radius: 999px;
	font-family: var(--font-body);
	font-size: 13px;
	font-weight: 800;
	letter-spacing: -0.005em;
	border: 2px solid var(--ink);
	color: var(--ink);
	background: var(--surface);
	text-decoration: none;
	box-shadow: 2px 2px 0 var(--ink);
}
.mantia-chip:hover { transform: translate(1px, 1px); box-shadow: 1px 1px 0 var(--ink); }
.mantia-chip.is-active {
	background: var(--ink);
	color: var(--surface);
	box-shadow: 3px 3px 0 var(--accent);
}

/* ─── Pedestal (leader) + leaderboard ────────────────────────────── */

.mantia-pedestal {
	display: grid;
	grid-template-columns: 60px 1fr auto;
	align-items: center;
	gap: 16px;
	padding: 18px 18px 20px;
	background: var(--surface);
	border: 2.5px solid var(--ink);
	border-radius: 18px;
	box-shadow: var(--shadow-stickerL);
	margin: 4px 0 24px;
}
/* Pedestal-as-me variant: when the viewer IS the leader, paint the
   whole card the highlight yellow + sticker-shadow accent so the
   "this is you" cue is consistent with how rest-of-table rows mark
   the viewer. Without this, a #1 viewer didn't get any visual
   anchor — the row highlight only existed below the pedestal. */
.mantia-pedestal-me {
	background: var(--accent-2);
	box-shadow: 6px 6px 0 var(--accent);
}

/* "Último partido" panel on /pronostico/g/<token>/. Shows the most
   recently kicked-off match's final score + the group's consensus
   tally. Mirrors the bot's `consenso` command on the web — same
   pre-kickoff privacy guard applies via group_consensus_for_match. */
.mantia-recent-card {
	background: var(--ink);
	color: var(--surface);
	border: 2.5px solid var(--ink);
	border-radius: 18px;
	box-shadow: var(--shadow-stickerL);
	padding: 16px 18px;
	margin: 4px 0 22px;
}
.mantia-recent-teams {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 18px;
	letter-spacing: -0.02em;
	color: var(--surface);
	display: flex;
	align-items: baseline;
	gap: 10px;
	flex-wrap: wrap;
}
.mantia-recent-score {
	color: var(--accent-2);
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 22px;
	letter-spacing: -0.03em;
	font-variant-numeric: tabular-nums;
}
.mantia-recent-consensus {
	margin-top: 10px;
	font-family: var(--font-body);
	font-size: 12px;
	font-weight: 700;
	color: rgba(255,255,255,0.7);
	letter-spacing: 0.02em;
}
.mantia-recent-tally {
	margin-top: 10px;
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}
.mantia-recent-chip {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 4px 10px;
	border-radius: 999px;
	background: rgba(255,255,255,0.10);
	color: var(--surface);
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 13px;
	letter-spacing: -0.01em;
	font-variant-numeric: tabular-nums;
}
.mantia-recent-chip small {
	font-family: var(--font-body);
	font-weight: 700;
	font-size: 11px;
	color: rgba(255,255,255,0.6);
}
.mantia-recent-chip.is-top {
	background: var(--accent-2);
	color: var(--ink);
}
.mantia-recent-chip.is-top small { color: var(--ink); }
.mantia-pedestal-name-suffix {
	font-family: var(--font-body);
	font-weight: 800;
	font-size: 14px;
	color: var(--ink);
	margin-left: 4px;
}
.mantia-pedestal-text { min-width: 0; }
.mantia-pedestal-name {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 22px;
	letter-spacing: -0.025em;
	color: var(--ink);
	margin-top: 4px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.mantia-pedestal-meta {
	font-family: var(--font-body);
	font-size: 12px;
	font-weight: 700;
	color: var(--ink-soft);
	margin-top: 4px;
	letter-spacing: 0.04em;
}
.mantia-pedestal-points {
	text-align: right;
}
.mantia-pedestal-points .mantia-stat-label { margin-top: 2px; }

.mantia-board {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.mantia-board-row {
	display: grid;
	grid-template-columns: 34px 36px 1fr auto;
	align-items: center;
	gap: 12px;
	padding: 13px 14px;
	background: var(--surface);
	border: 2px solid var(--ink);
	border-radius: 14px;
	box-shadow: 2px 2px 0 var(--ink);
}
.mantia-board-row:hover { transform: translate(1px, 1px); box-shadow: 1px 1px 0 var(--ink); }
.mantia-rank {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	font-family: var(--font-display);
	font-weight: 900;
	font-variant-numeric: tabular-nums;
	font-size: 13px;
	letter-spacing: -0.02em;
	color: var(--ink);
	background: transparent;
	border: 1.5px solid var(--rule);
}
.mantia-rank[data-rank="1"] { background: var(--medal-1); color: var(--ink); border: 2px solid var(--ink); box-shadow: 2px 2px 0 var(--ink); font-size: 15px; }
.mantia-rank[data-rank="2"] { background: var(--medal-2); color: var(--ink); border: 2px solid var(--ink); box-shadow: 2px 2px 0 var(--ink); font-size: 15px; }
.mantia-rank[data-rank="3"] { background: var(--medal-3); color: #ffffff;    border: 2px solid var(--ink); box-shadow: 2px 2px 0 var(--ink); font-size: 15px; }
.mantia-board-player { min-width: 0; }
.mantia-board-name {
	font-family: var(--font-body);
	font-size: 14.5px;
	font-weight: 800;
	color: var(--ink);
	letter-spacing: -0.005em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.mantia-board-group {
	font-family: var(--font-body);
	font-size: 11.5px;
	font-weight: 700;
	color: var(--ink-soft);
	margin-top: 3px;
	letter-spacing: 0.04em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.mantia-board-stats {
	display: flex;
	align-items: baseline;
	gap: 6px;
}
.mantia-board-pts {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 22px;
	font-variant-numeric: tabular-nums;
	letter-spacing: -0.03em;
	color: var(--ink);
}
.mantia-board-pts-suffix {
	font-family: var(--font-body);
	font-size: 10px;
	font-weight: 800;
	letter-spacing: 0.1em;
	text-transform: uppercase;
	color: var(--ink-soft);
}
.mantia-board-exc {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-family: var(--font-body);
	font-size: 11px;
	font-weight: 700;
	color: var(--ink-soft);
	letter-spacing: 0.04em;
	margin-top: 3px;
}
.mantia-board-exc strong {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 12px;
	color: var(--ink);
}
.mantia-board-legend { display: none; }

/* "vos" highlight inside a leaderboard row */
.mantia-board-row-me {
	background: var(--accent-2);
	box-shadow: 3px 3px 0 var(--accent);
}
.mantia-board-name-me {
	color: var(--ink);
	font-weight: 900;
}

/* ─── Match list ─────────────────────────────────────────────────── */

.mantia-day-group { margin: 0 0 22px; }
.mantia-day-eyebrow { padding: 0 0 10px; }
.mantia-match-row {
	display: grid;
	grid-template-columns: 54px 1fr;
	align-items: center;
	gap: 12px;
	padding: 13px 14px;
	margin-bottom: 10px;
	background: var(--surface);
	border: 2px solid var(--ink);
	border-radius: 14px;
	box-shadow: 3px 3px 0 var(--ink);
}
.mantia-match-time {
	font-family: var(--font-display);
	font-weight: 900;
	font-variant-numeric: tabular-nums;
	font-size: 16px;
	color: var(--ink);
	letter-spacing: -0.02em;
}
.mantia-match-names {
	font-family: var(--font-body);
	font-size: 14px;
	font-weight: 700;
	color: var(--ink);
	letter-spacing: -0.005em;
	line-height: 1.3;
}
.mantia-match-phase {
	font-family: var(--font-body);
	font-size: 11px;
	font-weight: 700;
	color: var(--ink-soft);
	margin-top: 3px;
	letter-spacing: 0.06em;
	text-transform: uppercase;
}
.mantia-mid { color: var(--ink-soft); margin: 0 6px; font-weight: 600; }

/* ─── Avatars ────────────────────────────────────────────────────── */

.mantia-avatar {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: var(--avatar-bg, var(--accent-2));
	color: var(--avatar-fg, var(--ink));
	font-family: var(--font-body);
	font-weight: 800;
	letter-spacing: -0.02em;
	border: 2px solid var(--ink);
	flex-shrink: 0;
	overflow: hidden;
	user-select: none;
}
.mantia-avatar-img { object-fit: cover; }
.mantia-avatar-ring { box-shadow: 3px 3px 0 var(--ink); }

/* ─── /me view: privacy + stats + group block ────────────────────── */

.mantia-privacy-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 11px;
	border-radius: 999px;
	font-family: var(--font-body);
	font-size: 11.5px;
	font-weight: 800;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: var(--ink);
	border: 2px solid var(--ink);
	background: var(--surface);
	box-shadow: 2px 2px 0 var(--ink);
	margin: 0 0 16px;
}
.mantia-hero-user {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 4px 0 24px;
}
.mantia-hero-user-text { min-width: 0; }
.mantia-hero-user-text .mantia-h1 { margin-top: 6px; }

/* Sticky compact bar on /me/. Hidden by default; revealed by the
   IntersectionObserver in print_pwa_register() when the user
   scrolls past the big stat grid. Keeps the "who am I, how many
   pts" tether visible while skimming match-by-match below. */
.mantia-me-sticky {
	position: sticky;
	top: 0;
	z-index: 30;
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 14px;
	background: var(--ink);
	color: var(--surface);
	border: 2px solid var(--ink);
	border-radius: 999px;
	box-shadow: 2px 2px 0 var(--accent);
	margin: -8px 0 12px;
	font-family: var(--font-body);
	font-size: 12.5px;
	font-weight: 700;
}
.mantia-me-sticky[hidden] { display: none; }
.mantia-me-sticky .mantia-avatar { border-color: var(--surface); }
.mantia-me-sticky-name {
	font-family: var(--font-display);
	font-weight: 900;
	color: var(--surface);
	font-size: 14px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 50%;
}
.mantia-me-sticky-stat {
	display: inline-flex;
	align-items: baseline;
	gap: 3px;
	color: rgba(255,255,255,0.85);
}
.mantia-me-sticky-stat strong {
	font-family: var(--font-display);
	font-weight: 900;
	color: var(--accent-2);
	font-size: 16px;
	font-variant-numeric: tabular-nums;
}

/* PWA install banner on the home page. Hidden until
   beforeinstallprompt fires; dismiss persists via localStorage.
   Mobile-only — desktop browsers rarely install PWAs and the
   banner would just be a full-width band of noise on those widths. */
.mantia-pwa-install {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 0 0 18px;
	padding: 12px 14px;
	background: var(--accent-3);
	color: #ffffff;
	border: 2px solid var(--ink);
	border-radius: 14px;
	box-shadow: 3px 3px 0 var(--ink);
	font-family: var(--font-body);
	font-size: 13px;
	font-weight: 700;
}
.mantia-pwa-install[hidden] { display: none; }
@media (min-width: 768px) { .mantia-pwa-install { display: none !important; } }
.mantia-pwa-text { flex: 1; min-width: 0; }
.mantia-pwa-install-btn {
	appearance: none;
	cursor: pointer;
	height: 34px;
	padding: 0 14px;
	border-radius: 999px;
	background: var(--accent-2);
	color: var(--ink);
	border: 2px solid var(--ink);
	font-family: var(--font-body);
	font-weight: 800;
	font-size: 12px;
	letter-spacing: 0.02em;
	box-shadow: 2px 2px 0 var(--ink);
}
.mantia-pwa-install-btn:hover { transform: translate(1px, 1px); box-shadow: 1px 1px 0 var(--ink); }
.mantia-pwa-dismiss {
	appearance: none;
	cursor: pointer;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: rgba(255,255,255,0.12);
	color: #ffffff;
	border: none;
	font-size: 14px;
	font-weight: 800;
	line-height: 0;
	padding: 0;
	flex-shrink: 0;
	opacity: 0.85;
}
.mantia-pwa-dismiss:hover { background: rgba(255,255,255,0.22); opacity: 1; }

/* Friendly one-liner below the stats tiles to tell first-time
   visitors what they should do here. Renders only when the user has
   ≥1 prediction so empty-state Mateos don't get a misleading "tap to
   change" promise before they've even predicted. */
.mantia-me-hint {
	margin: 0 0 22px;
	padding: 12px 14px;
	background: var(--accent-2);
	border: 2px solid var(--ink);
	border-radius: 14px;
	box-shadow: 2px 2px 0 var(--ink);
	font-family: var(--font-body);
	font-size: 13.5px;
	font-weight: 700;
	color: var(--ink);
	line-height: 1.4;
}
/* Variant for the "your scores are still random" hint — magenta so it
   reads as a stronger nudge than the green "tap to change" hint. */
.mantia-me-hint-random {
	background: var(--accent);
	color: #ffffff;
}

.mantia-stat-grid {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 10px;
	padding: 0 0 28px;
}
.mantia-stat {
	padding: 14px 10px 12px;
	text-align: center;
	background: var(--surface);
	border: 2.5px solid var(--ink);
	border-radius: 16px;
	box-shadow: var(--shadow-sticker);
}
.mantia-stat:nth-child(1) { background: var(--accent-2); }
.mantia-stat:nth-child(2) { background: var(--accent); color: #ffffff; }
.mantia-stat:nth-child(2) .mantia-stat-value { color: #ffffff; }
.mantia-stat:nth-child(2) .mantia-stat-label { color: rgba(255,255,255,0.85); }
.mantia-stat:nth-child(3) { background: var(--surface); }
.mantia-stat-bordered { border-left: 2.5px solid var(--ink); }
.mantia-stat-value {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 32px;
	line-height: 1;
	letter-spacing: -0.045em;
	color: var(--ink);
	font-variant-numeric: tabular-nums;
}
.mantia-stat-label { margin-top: 6px; }

.mantia-group-head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 10px;
	margin: 0 0 14px;
}
.mantia-tag-active {
	font-family: var(--font-body);
	font-size: 11px;
	font-weight: 800;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: #ffffff;
	background: var(--accent);
	padding: 5px 10px;
	border-radius: 999px;
	border: 2px solid var(--ink);
	box-shadow: 2px 2px 0 var(--ink);
	flex-shrink: 0;
}
.mantia-me-line {
	margin: 12px 0 22px;
	padding: 18px;
	background: var(--ink);
	color: var(--surface);
	border: 2.5px solid var(--ink);
	border-radius: 18px;
	box-shadow: var(--shadow-stickerL);
	display: flex;
	align-items: baseline;
	gap: 10px;
	line-height: 1.4;
	text-decoration: none;
}
.mantia-me-line:hover { transform: translate(1px, 1px); box-shadow: 4px 4px 0 var(--ink); }
/* Delta line under the rank tile: "Último: Uruguay 2-1 Portugal → +5 pts".
   Plain copy, no border — the rank tile above already does the heavy
   visual lifting. */
.mantia-me-delta {
	margin: -16px 0 22px;
	padding: 0 4px;
	font-family: var(--font-body);
	font-size: 12.5px;
	font-weight: 700;
	color: var(--ink-soft);
	letter-spacing: 0.01em;
}
.mantia-me-line .mantia-numeral { color: var(--accent-2); }
.mantia-me-line-arrow {
	margin-left: 4px;
	color: var(--accent-2);
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 18px;
}
.mantia-board-name-suffix {
	font-family: var(--font-body);
	font-weight: 800;
	color: var(--ink);
	margin-left: 4px;
}
.mantia-me-rank-suffix { color: rgba(255,255,255,0.7); }
.mantia-me-points-wrap {
	margin-left: auto;
	display: flex;
	align-items: baseline;
	gap: 6px;
}
.mantia-me-points-wrap .mantia-stat-label-inline { color: rgba(255,255,255,0.7); }
.mantia-subblock-eyebrow {
	font-family: var(--font-body);
	font-size: 11.5px;
	font-weight: 800;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: var(--ink);
	margin: 22px 0 10px;
}

/* ─── History rows ───────────────────────────────────────────────── */

.mantia-history {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
.mantia-history-row {
	display: grid;
	grid-template-columns: 1fr 64px 64px 44px;
	align-items: center;
	gap: 10px;
	padding: 12px 14px;
	background: var(--surface);
	border: 2px solid var(--ink);
	border-radius: 14px;
	box-shadow: 2px 2px 0 var(--ink);
}
.mantia-history-match { min-width: 0; }
.mantia-history-teams {
	font-family: var(--font-body);
	font-size: 13.5px;
	font-weight: 800;
	color: var(--ink);
	letter-spacing: -0.005em;
	overflow: hidden;
	line-height: 1.25;
	/* Long matchups like "Universidad de Chile · Defensa y Justicia" were
	   clipping mid-word at 390px because of nowrap+ellipsis. Allow wrap
	   to 2 lines so both team names stay readable. */
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	white-space: normal;
}
.mantia-history-day {
	font-family: var(--font-body);
	font-size: 10.5px;
	font-weight: 700;
	color: var(--ink-soft);
	margin-top: 3px;
	letter-spacing: 0.06em;
	text-transform: uppercase;
}
.mantia-history-score { text-align: center; }
.mantia-score-line {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 17px;
	letter-spacing: -0.03em;
	color: var(--ink);
	font-variant-numeric: tabular-nums;
}
.mantia-score-line .mantia-mid { font-size: 14px; }
.mantia-history-points { text-align: right; display: inline-flex; justify-content: flex-end; }
.mantia-history-points .mantia-numeral {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 40px;
	height: 30px;
	padding: 0 8px;
	border-radius: 8px;
	border: 2px solid var(--ink);
	background: var(--surface);
}
.mantia-history-points .mantia-text-accent {
	background: var(--accent-2);
	color: var(--ink) !important;
}
.mantia-history-points .mantia-soft {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 40px;
	height: 30px;
	padding: 0 8px;
	border-radius: 8px;
	border: 2px dashed var(--rule);
	color: var(--ink-soft);
}

/* ─── Scoring rows (group view) ──────────────────────────────────── */

.mantia-scoring-rows {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 16px;
	background: var(--surface);
	border: 2.5px solid var(--ink);
	border-radius: 18px;
	box-shadow: var(--shadow-stickerL);
}
.mantia-scoring-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 10px 0;
	border-bottom: 1.5px dashed var(--rule);
	font-family: var(--font-body);
	font-weight: 700;
	font-size: 14px;
	color: var(--ink);
}
.mantia-scoring-row:last-child { border-bottom: 0; }
.mantia-scoring-row .mantia-numeral-s {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 38px;
	height: 28px;
	padding: 0 8px;
	border-radius: 8px;
}
.mantia-scoring-row:first-child .mantia-numeral-s {
	background: var(--accent-2);
	border: 2px solid var(--ink);
}

/* ─── Home ───────────────────────────────────────────────────────── */

.mantia-home {
	max-width: 420px;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding-top: 32px;
	min-height: 100vh;
}
.mantia-home-mark {
	text-align: center;
	padding: 28px 0 8px;
	position: relative;
	width: 100%;
}
.mantia-wordmark {
	font-family: var(--font-display);
	font-weight: 900;
	font-style: italic;
	font-size: 64px;
	line-height: 0.92;
	letter-spacing: -0.055em;
	color: var(--ink);
	margin: 0;
}
.mantia-tagline {
	margin: 16px auto 0;
	font-family: var(--font-body);
	font-size: 14px;
	font-weight: 700;
	letter-spacing: -0.005em;
	color: var(--ink);
	text-transform: none;
	max-width: 280px;
	text-wrap: balance;
}
/* Floating sticker chips that sit just above the monumental wordmark. */
.mantia-home-stickers {
	pointer-events: none;
	height: 0;
	position: relative;
	width: 100%;
}
.mantia-home-sticker {
	position: absolute;
	font-family: var(--font-body);
	font-size: 11.5px;
	font-weight: 800;
	letter-spacing: 0.04em;
	text-transform: uppercase;
	color: #ffffff;
	background: var(--accent);
	padding: 5px 11px;
	border-radius: 999px;
	border: 2px solid var(--ink);
	/* Rotation grows the pill's visual bbox a few px beyond its rect, so
	   keep both stickers off the absolute edge — the right one was clipping
	   at 390px before. */
}
.mantia-home-sticker-l { left: 14px;  top: -22px; transform: rotate(-9deg); }
.mantia-home-sticker-r { right: 14px; top: -16px; transform: rotate(8deg);  background: var(--accent-3); color: #ffffff; }
.mantia-qr-card {
	background: var(--surface);
	border: 2.5px solid var(--ink);
	padding: 18px;
	border-radius: 22px;
	line-height: 0;
	margin: 22px 0 22px;
	display: inline-block;
	text-decoration: none;
	box-shadow: var(--shadow-stickerL);
	transform: rotate(-2deg);
	transition: transform 0.15s ease;
}
.mantia-qr-card:hover { transform: rotate(-2deg) translate(1px, 1px); }
.mantia-qr-img {
	display: block;
	width: 224px; height: 224px;
}
.mantia-qr-caption {
	margin-top: 12px;
	font-family: var(--font-body);
	font-size: 12px;
	font-weight: 700;
	letter-spacing: 0.12em;
	text-transform: uppercase;
	color: var(--ink);
	text-align: center;
	line-height: 1;
}
.mantia-home-hint {
	text-align: center;
	color: var(--ink-soft);
	font-size: 14px;
	font-weight: 700;
	line-height: 1.5;
	max-width: 280px;
	margin: 0 0 22px;
}
.mantia-home .mantia-pill { width: 100%; max-width: 320px; margin-bottom: 14px; }
.mantia-home .mantia-ghost-link { margin-top: 10px; }

/* ─── Footer ─────────────────────────────────────────────────────── */

.mantia-foot {
	max-width: 560px;
	margin: 0 auto;
	padding: 40px 22px 36px;
	text-align: center;
	font-family: var(--font-body);
	font-size: 11.5px;
	font-weight: 800;
	letter-spacing: 0.14em;
	text-transform: uppercase;
	color: var(--ink);
}
.mantia-foot a {
	color: var(--ink);
	text-decoration: none;
	font-weight: 900;
	font-style: italic;
}

/* ─── Share card (screenshotable poster) ─────────────────────────── */

body.mantia-body-share {
	background: var(--ink);
	color: var(--surface);
	min-height: 100vh;
}
.mantia-share {
	max-width: 420px;
	margin: 0 auto;
	padding: 56px 22px 32px;
	display: flex;
	flex-direction: column;
	align-items: center;
	min-height: 100vh;
}
.mantia-share-card {
	width: 100%;
	max-width: 320px;
	aspect-ratio: 4 / 5;
	position: relative;
	background: var(--bg);
	color: var(--ink);
	border: 3px solid var(--ink);
	border-radius: 18px;
	padding: 22px 20px;
	display: flex;
	flex-direction: column;
	box-shadow: 8px 8px 0 var(--ink);
	font-family: var(--font-body);
	overflow: hidden;
}
/* Floating sticker corner tag for the competition name. */
.mantia-share-top {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
}
.mantia-share-wordmark {
	font-family: var(--font-display);
	font-weight: 900;
	font-style: italic;
	font-size: 26px;
	letter-spacing: -0.05em;
	color: var(--ink);
}
.mantia-share-comp {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 11px;
	letter-spacing: 0.05em;
	text-transform: uppercase;
	color: #ffffff;
	background: var(--accent);
	padding: 5px 11px;
	border-radius: 999px;
	border: 2px solid var(--ink);
	transform: rotate(6deg);
	text-align: center;
	line-height: 1.2;
	max-width: 50%;
}
.mantia-share-center {
	flex: 1;
	display: flex;
	flex-direction: column;
	justify-content: center;
	padding: 12px 0;
}
/* Big medal disc in lieu of a numeral. */
.mantia-share-rank,
.mantia-share-mark {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 96px;
	height: 96px;
	border-radius: 50%;
	background: var(--medal-1);
	border: 3px solid var(--ink);
	color: var(--ink);
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 60px;
	letter-spacing: -0.05em;
	box-shadow: 5px 5px 0 var(--ink);
	margin-bottom: 14px;
}
.mantia-share-rank[data-rank="2"] { background: var(--medal-2); }
.mantia-share-rank[data-rank="3"] { background: var(--medal-3); color: #ffffff; }
.mantia-share-mark {
	font-size: 0; /* hide the "·" — keep the disc as a placeholder shape */
	background: var(--surface);
}
.mantia-share-name {
	margin-top: 4px;
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 26px;
	line-height: 1;
	letter-spacing: -0.03em;
	color: var(--ink);
	text-wrap: balance;
}
.mantia-share-in {
	margin-top: 8px;
	font-family: var(--font-body);
	font-size: 13px;
	font-weight: 700;
	color: var(--ink);
}
.mantia-share-in-tag {
	background: var(--ink);
	color: var(--bg);
	padding: 1px 6px;
	border-radius: 4px;
}
.mantia-share-empty .mantia-share-name { font-size: 22px; }
.mantia-share-stats {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 8px;
	padding-top: 14px;
	border-top: 2px dashed var(--ink);
}
.mantia-share-stat {
	text-align: center;
	padding: 0;
}
.mantia-share-stat-bordered { border-left: 0; }
.mantia-share-num {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 24px;
	line-height: 1;
	letter-spacing: -0.04em;
	color: var(--ink);
	font-variant-numeric: tabular-nums;
}
.mantia-share-label {
	font-family: var(--font-body);
	font-size: 9.5px;
	font-weight: 800;
	letter-spacing: 0.16em;
	text-transform: uppercase;
	color: var(--ink);
	margin-top: 4px;
}
.mantia-share-url {
	margin-top: 12px;
	font-size: 11px;
	font-weight: 700;
	letter-spacing: 0.08em;
	color: var(--ink);
	text-align: center;
	word-break: break-all;
}

.mantia-share-actions {
	margin-top: 24px;
	display: flex;
	flex-direction: column;
	gap: 10px;
	width: 100%;
	max-width: 320px;
}
/* Primary share action: opens wa.me with the headline+URL prefilled.
   WhatsApp green so it reads as "WhatsApp" even before the icon renders.
   Sits above "Copiar link" because WhatsApp is the dominant share channel
   for Mantia's audience and a one-tap deeplink beats a clipboard paste. */
.mantia-share-wa {
	appearance: none;
	cursor: pointer;
	height: 52px;
	border-radius: 999px;
	border: 2.5px solid var(--ink);
	background: #25D366;
	color: var(--ink);
	font-family: var(--font-body);
	font-size: 15px;
	font-weight: 800;
	letter-spacing: -0.005em;
	padding: 0 18px;
	text-align: center;
	box-shadow: 4px 4px 0 var(--ink);
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	text-decoration: none;
}
.mantia-share-wa:hover { transform: translate(1px, 1px); box-shadow: 3px 3px 0 var(--ink); }

.mantia-share-copy {
	appearance: none;
	cursor: pointer;
	height: 52px;
	border-radius: 999px;
	border: 2.5px solid var(--bg);
	background: var(--bg);
	color: var(--ink);
	font-family: var(--font-body);
	font-size: 15px;
	font-weight: 800;
	letter-spacing: -0.005em;
	padding: 0 18px;
	text-align: center;
	box-shadow: 4px 4px 0 var(--surface);
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
}
.mantia-share-copy.is-copied { background: var(--accent-2); border-color: var(--accent-2); }
.mantia-share-copy:hover { transform: translate(1px, 1px); box-shadow: 3px 3px 0 var(--surface); }
.mantia-share-close {
	height: 52px;
	border-radius: 999px;
	background: transparent;
	border: 2.5px solid var(--surface);
	color: var(--surface);
	font-family: var(--font-body);
	font-size: 14px;
	font-weight: 700;
	letter-spacing: -0.005em;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	text-decoration: none;
}
.mantia-share-close:hover { border-color: var(--bg); color: var(--bg); }

/* ─── Editable prediction rows (PWA inline edit) ─────────────────── */

.mantia-edit-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.mantia-edit-list .mantia-day-group {
	margin: 0;
}
.mantia-edit-list .mantia-day-eyebrow {
	padding: 0 0 8px;
}
.mantia-edit-row {
	display: grid;
	grid-template-columns: 1fr auto auto;
	align-items: center;
	gap: 12px;
	padding: 12px 14px;
	background: var(--surface);
	border: 2px solid var(--ink);
	border-radius: 14px;
	box-shadow: 2px 2px 0 var(--ink);
	margin-bottom: 10px;
}
.mantia-edit-meta {
	min-width: 0;
}
.mantia-edit-time {
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 14px;
	letter-spacing: -0.02em;
	color: var(--ink);
	font-variant-numeric: tabular-nums;
}
.mantia-edit-teams {
	font-family: var(--font-body);
	font-weight: 800;
	font-size: 13.5px;
	color: var(--ink);
	letter-spacing: -0.005em;
	margin-top: 2px;
	overflow: hidden;
	line-height: 1.25;
	/* Wrap to 2 lines instead of clipping mid-word. The competing inputs
	   + Guardar button column is auto-sized so the team column has
	   limited room at 390px — wrap is the only way long names survive. */
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	white-space: normal;
}
.mantia-edit-row .mantia-match-phase {
	margin-top: 3px;
	font-family: var(--font-body);
	font-size: 10.5px;
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--ink-soft);
}
.mantia-edit-inputs {
	display: flex;
	align-items: center;
	gap: 4px;
}
.mantia-edit-score {
	width: 46px;
	height: 38px;
	border: 2px solid var(--ink);
	border-radius: 10px;
	background: var(--bg);
	color: var(--ink);
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 18px;
	letter-spacing: -0.02em;
	text-align: center;
	font-variant-numeric: tabular-nums;
	padding: 0 4px;
	-moz-appearance: textfield;
	-webkit-appearance: none;
	appearance: none;
}
.mantia-edit-score::-webkit-outer-spin-button,
.mantia-edit-score::-webkit-inner-spin-button {
	-webkit-appearance: none;
	margin: 0;
}
.mantia-edit-score:focus {
	outline: 0;
	box-shadow: 2px 2px 0 var(--accent);
}
.mantia-edit-sep {
	font-family: var(--font-body);
	font-weight: 700;
	color: var(--ink-soft);
	font-size: 14px;
}
.mantia-edit-save {
	appearance: none;
	cursor: pointer;
	height: 38px;
	padding: 0 12px;
	border-radius: 999px;
	border: 2px solid var(--ink);
	background: var(--accent-2);
	color: var(--ink);
	font-family: var(--font-body);
	font-weight: 800;
	font-size: 12.5px;
	letter-spacing: -0.005em;
	box-shadow: 2px 2px 0 var(--ink);
}
.mantia-edit-save:hover { transform: translate(1px, 1px); box-shadow: 1px 1px 0 var(--ink); }
.mantia-edit-save:disabled { opacity: 0.6; cursor: not-allowed; box-shadow: none; }
.mantia-edit-quick {
	grid-column: 1 / -1;
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
	padding-top: 6px;
}
.mantia-edit-chip {
	appearance: none;
	cursor: pointer;
	height: 30px;
	padding: 0 10px;
	border-radius: 999px;
	border: 2px solid var(--ink);
	background: var(--surface);
	color: var(--ink);
	font-family: var(--font-display);
	font-weight: 900;
	font-size: 13px;
	letter-spacing: -0.01em;
	box-shadow: 1px 1px 0 var(--ink);
	font-variant-numeric: tabular-nums;
}
.mantia-edit-chip:hover { transform: translate(1px, 1px); box-shadow: 0 0 0 var(--ink); }
.mantia-edit-chip:active { background: var(--accent-2); }
.mantia-edit-status {
	grid-column: 1 / -1;
	font-family: var(--font-body);
	font-weight: 700;
	font-size: 12px;
	letter-spacing: -0.005em;
	color: var(--ink-soft);
	padding-top: 4px;
}
.mantia-edit-status.is-ok  { color: var(--ink); }
.mantia-edit-status.is-err { color: var(--accent); }

/* ─── Mobile tuning ──────────────────────────────────────────────── */

@media (max-width: 380px) {
	.mantia-h1 { font-size: 32px; }
	.mantia-wordmark { font-size: 54px; }
	.mantia-qr-img { width: 200px; height: 200px; }
	.mantia-share-rank, .mantia-share-mark { width: 84px; height: 84px; font-size: 52px; }
	.mantia-share-name { font-size: 22px; }
}
CSS;
	}
}
