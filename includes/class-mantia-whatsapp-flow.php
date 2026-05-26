<?php
/**
 * WhatsApp-first deterministic shortcuts.
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

final class Mantia_Whatsapp_Flow {

	public static function register(): void {
		add_filter( 'openclawp_pre_chat_turn', array( __CLASS__, 'maybe_handle_command' ), 10, 2 );
		add_action( 'openclawp_image_message_received', array( __CLASS__, 'handle_inbound_image' ), 10, 1 );
	}

	/**
	 * Inbound WhatsApp image → user's avatar.
	 *
	 * Any image a known mantia_user sends to the bot is treated as their
	 * profile picture (WhatsApp has no other reason to share an image with
	 * a penca bot). We resolve the Meta media URL, download the bytes
	 * with the same Bearer token openclawp uses for outbound, and let the
	 * repository attach it as the post_thumbnail of the user post. The
	 * web frontend already prefers the thumbnail over the generated
	 * initials, so the avatar updates everywhere on the next request.
	 *
	 * @param array<string,mixed> $payload See openclawp_image_message_received doc.
	 */
	public static function handle_inbound_image( array $payload ): void {
		$phone    = (string) ( $payload['phone'] ?? '' );
		$media_id = (string) ( $payload['media_id'] ?? '' );
		$token    = (string) ( $payload['access_token'] ?? '' );
		$api_ver  = (string) ( $payload['api_version'] ?? 'v25.0' );
		if ( '' === $phone || '' === $media_id || '' === $token ) {
			return;
		}

		$user = Mantia_Repository::find_user_by_phone( $phone );
		if ( ! $user ) {
			// Stranger sent us an image before they joined a penca; nothing
			// to attach to. We silently drop it.
			return;
		}

		// Step 1: ask Graph API for the actual media URL (Meta keeps the
		// bytes behind a short-lived signed CDN URL we have to resolve).
		$meta_url = sprintf( 'https://graph.facebook.com/%s/%s', $api_ver, rawurlencode( $media_id ) );
		$response = wp_remote_get(
			$meta_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return;
		}
		$meta = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$url  = is_array( $meta ) ? (string) ( $meta['url'] ?? '' ) : '';
		if ( '' === $url ) {
			return;
		}

		// Step 2: pull the bytes (also requires Bearer) and attach.
		$attachment_id = Mantia_Repository::set_user_avatar_from_url(
			(int) $user->ID,
			$url,
			array( 'Authorization' => 'Bearer ' . $token )
		);
		if ( $attachment_id <= 0 ) {
			return;
		}

		// Confirm to the user. Stays inside the 24h service window since
		// they just messaged us, so no template cost.
		if ( class_exists( 'OpenclaWP_Whatsapp' ) && method_exists( 'OpenclaWP_Whatsapp', 'send_text_message' ) ) {
			OpenclaWP_Whatsapp::send_text_message(
				$phone,
				'📸 Foto guardada como tu avatar. La van a ver tus compañeros en la web.'
			);
		}
	}

	public static function user_initiated_only(): bool {
		return (bool) apply_filters( 'mantia_whatsapp_user_initiated_only', true );
	}

	public static function outbound_workflows_enabled(): bool {
		return (bool) apply_filters( 'mantia_enable_outbound_whatsapp_workflows', ! self::user_initiated_only() );
	}

	public static function maybe_handle_command( mixed $preflight, array $turn ): mixed {
		if ( null !== $preflight || Mantia_Agent::SLUG !== (string) ( $turn['agent_slug'] ?? '' ) ) {
			return $preflight;
		}

		$raw      = trim( (string) ( $turn['message'] ?? '' ) );
		$plain    = function_exists( 'remove_accents' ) ? remove_accents( $raw ) : $raw;
		$lc       = strtolower( $plain );
		$identity = self::identity_from_turn( $turn );

		if ( '' === $raw ) {
			return null;
		}

		// Throttle inbound per phone so a stuck client / abusive sender can't
		// burn through the LLM budget or the Cloud API rate limit. Reply
		// inside the 24h service window so the user gets a clear message
		// without spending an outbound template.
		if ( '' !== $identity['phone'] ) {
			$throttled = self::rate_limit_check( $identity['phone'] );
			if ( null !== $throttled ) {
				return $throttled;
			}
		}

		// Button / list reply payload from openclaWP — routed by the id we set.
		if ( str_starts_with( $raw, 'mantia:cmd:' ) ) {
			$cmd = substr( $raw, strlen( 'mantia:cmd:' ) );
			switch ( $cmd ) {
				case 'help':
					return self::handle_help( $identity );
				case 'home':
					return self::handle_home( $identity );
				case 'my-groups':
					return self::handle_my_groups( $identity );
				case 'share-link':
					return self::handle_share_link( $identity );
				case 'matches':
					return self::handle_matches( $identity );
				case 'pending':
					return self::handle_pending( $identity );
				case 'leaderboard':
					return self::handle_leaderboard( $identity );
				case 'my-predictions':
					return self::handle_my_predictions( $identity );
				case 'new-penca':
					return self::handle_new_penca_start( $identity );
				case 'have-code':
					return array(
						'reply'     => 'Mandame el codigo de invitacion (5-20 caracteres, sin espacios). Ejemplo: *FAMILIA2026*.',
						'completed' => true,
					);
			}
		}
		if ( str_starts_with( $raw, 'mantia:switch:' ) ) {
			$group_id = (int) substr( $raw, strlen( 'mantia:switch:' ) );
			return self::handle_switch_group( $group_id, $identity );
		}
		if ( str_starts_with( $raw, 'mantia:match:' ) ) {
			$match_id = (int) substr( $raw, strlen( 'mantia:match:' ) );
			return self::handle_match_detail( $match_id, $identity );
		}
		// Score picker tap: `mantia:score:<match_id>:<H>-<A>` writes the
		// prediction directly. `:other` parks the match as pending so the
		// next bare-score the user types lands on this match.
		if ( str_starts_with( $raw, 'mantia:score:' ) ) {
			$payload = substr( $raw, strlen( 'mantia:score:' ) );
			$parts   = array_pad( explode( ':', $payload, 2 ), 2, '' );
			$match_id = (int) $parts[0];
			$score    = (string) $parts[1];
			if ( $match_id > 0 && '' !== $identity['phone'] ) {
				if ( 'other' === $score || 'otro' === $score ) {
					self::stash_pending_match( $identity['phone'], $match_id );
					return array(
						'reply'     => 'Tipea el marcador. Ej *3-1*.',
						'completed' => true,
					);
				}
				if ( preg_match( '/^(\d{1,2})-(\d{1,2})$/', $score, $sc ) ) {
					return self::handle_quick_score( $match_id, (int) $sc[1], (int) $sc[2], $identity );
				}
			}
		}
		if ( str_starts_with( $raw, 'mantia:competition:' ) ) {
			$competition_id = substr( $raw, strlen( 'mantia:competition:' ) );
			return self::handle_competition_chosen( $competition_id, $identity );
		}
		if ( str_starts_with( $raw, 'mantia:newcomp:' ) ) {
			$competition_id = substr( $raw, strlen( 'mantia:newcomp:' ) );
			return self::handle_competition_picked_for_new( $competition_id, $identity );
		}

		// Bare "crear / nueva penca" (or pronostico / quiniela / polla /
		// bolão / pollada — any country's local term) with no name kicks
		// off the competition-first flow.
		$noun_alt = '(?:penca|pronost[ií]co|quiniela|polla|bol[aã]o|pollada)';
		if ( preg_match( '/^(?:crear|criar|nueva|nuevo|new)(?:\s+' . $noun_alt . ')?\s*$/iu', $lc ) ) {
			return self::handle_new_penca_start( $identity );
		}

		if ( preg_match( '/^(?:nueva|nuevo|crear|criar|create|new)\s+' . $noun_alt . '\s+(.+)$/iu', $plain, $m ) ) {
			$arg = trim( (string) $m[1] );
			// "Crear penca de Mundial 2026" — competition hint short-circuits the
			// flow and lands us on the name prompt with the competition pre-picked.
			$competition_id = self::resolve_competition_hint( $arg );
			if ( null !== $competition_id ) {
				return self::handle_competition_picked_for_new( $competition_id, $identity );
			}
			return self::handle_create_group( $arg, $identity );
		}

		// User picked a competition and we asked for a name — next message is
		// the name, unless it's an obvious escape command.
		if ( '' !== $identity['phone'] ) {
			$pending_comp = (string) get_transient( self::pending_comp_key( $identity['phone'] ) );
			if ( '' !== $pending_comp && ! self::is_escape_command( $lc ) ) {
				return self::handle_name_after_competition( $raw, $identity, $pending_comp );
			}
		}

		// User tapped a match in /partidos or /pendientes and we asked for a
		// score. Accept every natural form a Spanish speaker reaches for:
		// "2-1", "2 1", "2:1", "2x1", "2 a 1". Falls through to the LLM
		// only when the input doesn't even look like a bare score.
		if ( '' !== $identity['phone'] && preg_match( '/^\s*(\d{1,2})\s*(?:\s+a\s+|[-:x\s])\s*(\d{1,2})\s*$/iu', $plain, $sc ) ) {
			$pending_match = (int) get_transient( self::pending_match_key( $identity['phone'] ) );
			if ( $pending_match > 0 ) {
				return self::handle_quick_score( $pending_match, (int) $sc[1], (int) $sc[2], $identity );
			}
			// Regex matched but there's no pending-match transient — the user
			// typed a score without tapping a match first. Don't silently
			// fall through to the LLM (which can't disambiguate without a
			// tool call): re-use handle_pending() so the user sees their
			// next un-predicted matches as taps. Stash the score so the
			// next tap applies it immediately.
			set_transient( self::pending_score_key( $identity['phone'] ), array( (int) $sc[1], (int) $sc[2] ), 5 * MINUTE_IN_SECONDS );
			$pending = self::handle_pending( $identity );
			// If the user has already predicted every match, handle_pending()
			// returns no rows — "Tocá uno y lo anoto" would contradict the
			// empty list. Suggest editing an existing prediction instead.
			$has_rows = ! empty( $pending['interactive']['sections'][0]['rows'] );
			$prefix   = $has_rows
				? sprintf( "Recibí *%d-%d* pero no sé para qué partido. Tocá uno y lo anoto.\n\n", (int) $sc[1], (int) $sc[2] )
				: sprintf( "Recibí *%d-%d* — pero ya pronosticaste todos los pendientes. Mandá el partido por nombre (ej *Boca 2 River 1*) o tocá *partidos* para cambiar uno.\n\n", (int) $sc[1], (int) $sc[2] );
			$pending['reply'] = $prefix . (string) ( $pending['reply'] ?? '' );
			return $pending;
		}

		if ( preg_match( '/^(?:me\s+llamo|mi\s+nombre\s+es|llamame|llamame|decime)\s+(.+)$/iu', $plain, $m ) ) {
			return self::handle_set_name( trim( (string) $m[1] ), $identity );
		}

		if ( preg_match( '/^(?:mis\s+)?(?:grupos?|pencas?|pron[oó]sticos?|quinielas?|pollas?|bol[oõ]es?|bol[aã]o|pollada)$/iu', $lc ) ) {
			return self::handle_my_groups( $identity );
		}

		// Group consensus — only renders predictions for matches that
		// already kicked off, so this command can't be used to peek at
		// the group's picks before the whistle. The Repository helper
		// enforces the time guard.
		if ( preg_match( '/^(?:consenso|consensus|que\s+vot[oó]|qu[eé]\s+puso\s+el\s+grupo)$/iu', $lc ) ) {
			return self::handle_consensus( $identity );
		}

		// Sweepstake: organiser draws one random team per group member.
		// First-draw aliases REQUIRE a qualifier (sortear/sortear equipos/
		// hacé el sorteo/polla de equipos) — bare "polla" and bare "sorteo"
		// are too common as standalone Spanish words to safely capture.
		if ( preg_match( '/^(?:\/?sortear(?:\s+equipos?)?|hac[eé]r?\s+(?:el\s+)?sorteo(?:\s+de\s+equipos?)?|polla\s+de\s+equipos?)$/iu', $lc ) ) {
			return self::handle_sweepstake_draw( $identity, false );
		}

		// Explicit re-draw — wipes any existing assignment. Separate alias
		// so a one-off `sortear` typo can't silently overwrite everyone's
		// team mid-tournament.
		if ( preg_match( '/^(?:re[-\s]?sortear(?:\s+equipos?)?|sortear\s+de\s+nuevo|volver\s+a\s+sortear|nuevo\s+sorteo|rehacer\s+(?:el\s+)?sorteo)$/iu', $lc ) ) {
			return self::handle_sweepstake_draw( $identity, true );
		}

		// Sweepstake query — "what's my team?"
		if ( preg_match( '/^(?:mi\s+(?:equipo|sorteo|sweepstake)|qu[eé]\s+equipo\s+me\s+toc[oó]\??)$/iu', $lc ) ) {
			return self::handle_sweepstake_mine( $identity );
		}

		// Bulk-set "Argentina gana todo" / "Brasil gana siempre" / "Marca a
		// Uruguay como ganador". Maps every upcoming match where the named
		// team plays to a 2-1 win (most common winning scoreline). Lets
		// users "back a favourite" without typing 64 individual scores.
		// Accepts a few natural phrasings: "X gana todo|siempre|todos los
		// partidos" or "marca[r] [a] X [como (ganador|wins)]".
		if ( preg_match( '/^(.+?)\s+(?:gana|wins?)\s+(?:todo|todos?(?:\s+los\s+partidos)?|siempre)$/iu', $plain, $bm ) ) {
			return self::handle_bulk_back_team( trim( (string) $bm[1] ), $identity );
		}
		if ( preg_match( '/^marca(?:r)?\s+(?:a\s+)?(.+?)(?:\s+(?:gana(?:dor)?|wins?))?$/iu', $plain, $bm ) ) {
			return self::handle_bulk_back_team( trim( (string) $bm[1] ), $identity );
		}

		if ( preg_match( '/^(?:partidos?|proximos?|fixture|matches)$/i', $lc ) ) {
			return self::handle_matches( $identity );
		}

		if ( preg_match( '/^(?:pendientes?|falta|faltan|sin\s+pronostic[oa]s?|tengo\s+que\s+jugar)$/i', $lc ) ) {
			return self::handle_pending( $identity );
		}

		if ( preg_match( '/^(?:tabla|ranking|leaderboard|posicion(?:es)?|posiciones|standings?|puntos?|mi\s+puntaje)$/i', $lc ) ) {
			return self::handle_leaderboard( $identity );
		}

		// Broad match: "mis predicciones", "cuales son mis predicciones?",
		// "ver mis pronosticos", "que prediji", "mi historial" — anything
		// that's clearly asking about the user's own predictions.
		if ( preg_match( '/\b(?:mis\s+(?:pronostic[oa]s|prediccion(?:es)?|preds?|jugadas)|que\s+(?:prediji|pronostique)|mi\s+historial|^\s*historial\s*\??$|^\s*jugadas\s*\??$)\b/iu', $lc ) ) {
			return self::handle_my_predictions( $identity );
		}

		if ( preg_match( '/^(?:ayuda|help|menu|comandos|\?|\/help|\/)$/i', $lc ) ) {
			return self::handle_help( $identity );
		}

		if ( preg_match( '/^(?:hola|hello|hi|hey|home|inicio|resumen|hoy|status)$/i', $lc ) ) {
			return self::handle_home( $identity );
		}

		// Match the share intent in any phrasing the user reaches for:
		// "compartir mi penca", "mandame el link", "como invito amigos",
		// "share the group", etc. R5 Don Roberto sent "compartir mi penca"
		// and the LLM hallucinated the share message with a placeholder
		// number. Catch it deterministically so the canonical share URL
		// always wins over an LLM compose pass.
		if ( preg_match( '/\b(?:link|invitaci[oó]n|compartir|share|invite|invitar)\b/iu', $lc ) ) {
			return self::handle_share_link( $identity );
		}

		$group = Mantia_Repository::find_group_by_invite_message( $raw );
		if ( $group ) {
			return self::handle_join( $group, $identity );
		}

		return null;
	}

	/**
	 * Consensus view for the most recently kicked-off match in the user's
	 * active penca. Only shows once the whistle's blown — predictions
	 * before kickoff are private, and Repository::group_consensus_for_match
	 * enforces that guard server-side.
	 */
	private static function handle_consensus( array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			$noun = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
			return array( 'reply' => sprintf( 'Primero entrá a una %s con su código.', $noun ), 'completed' => true );
		}
		$group_id = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
		if ( $group_id <= 0 ) {
			$indef  = Mantia_Vocab::word( 'article_indef', $identity['phone'] ?? '' );
			$noun   = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
			$plural = Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' );
			return array(
				'reply'     => sprintf( 'Activá %s %s primero (mandame *mis %s* y tocá %s).', $indef, $noun, $plural, $indef ),
				'completed' => true,
			);
		}

		// Find the most recently kicked-off match in this penca's
		// competition — that's the one the group naturally wants to
		// post-mortem.
		$comp_id   = Mantia_Repository::group_competition_id( $group_id );
		$now       = time();
		$candidates = Mantia_Repository::recent_finished_matches_for_competition( $comp_id, 5 );
		if ( empty( $candidates ) ) {
			return array(
				'reply'     => '_Todavía no terminó ningún partido. Volvé después del primer pitazo final._',
				'completed' => true,
			);
		}

		$match     = $candidates[0];
		$consensus = Mantia_Repository::group_consensus_for_match( $group_id, (int) $match['id'] );
		if ( empty( $consensus ) ) {
			return array(
				'reply'     => sprintf(
					"*%s vs %s*\n\nNadie del grupo pronosticó este partido.",
					$match['home_team'],
					$match['away_team']
				),
				'completed' => true,
			);
		}

		// Format as: header with real score, then the aggregate vote tally,
		// then the per-user breakdown with ✓/diff/winner badges (only when
		// the match has finished — for in-progress matches we still reveal
		// individual picks since kickoff already happened, but the badges
		// stay neutral).
		$total = array_sum( $consensus );
		$lines = array(
			sprintf(
				'*%s · %d-%d %s*',
				Mantia_Frontend::normalize_team_name( (string) $match['home_team'] ),
				(int) $match['home_score'],
				(int) $match['away_score'],
				Mantia_Frontend::normalize_team_name( (string) $match['away_team'] )
			),
			sprintf( '_Cómo votó el grupo (%d jugadores):_', $total ),
		);
		foreach ( $consensus as $score => $count ) {
			$lines[] = sprintf( '  *%s* — %d', $score, $count );
		}

		$rows = Mantia_Repository::group_predictions_for_match( $group_id, (int) $match['id'] );
		if ( ! empty( $rows ) ) {
			$lines[] = '';
			$lines[] = '_Quién puso qué:_';
			foreach ( $rows as $row ) {
				// Anchor emojis only (approved set): ✅ for exact, ⚽ for
				// matching goal-difference, 🏆 for the right outcome. No
				// decorative emojis on the per-row lines.
				$badge = '';
				if ( $row['exact']  )      { $badge = ' ✅ exacto'; }
				elseif ( $row['diff']   )  { $badge = ' ⚽ +diff'; }
				elseif ( $row['winner'] )  { $badge = ' 🏆 ganador'; }
				$lines[] = sprintf( '  *%s* %d-%d%s', $row['name'], (int) $row['home'], (int) $row['away'], $badge );
			}
		}

		return array(
			'reply'     => implode( "\n", $lines ),
			'completed' => true,
		);
	}

	/**
	 * /sortear — draw one random team per group member from the active
	 * penca's competition fixture. A second `sortear` after a draw exists
	 * returns a guard reply instead of silently re-shuffling; the user
	 * has to mandar `re-sortear` (force=true) to overwrite. Prevents a
	 * mid-tournament typo from wiping everyone's affinity team.
	 */
	private static function handle_sweepstake_draw( array $identity, bool $force ): array {
		$phone = (string) ( $identity['phone'] ?? '' );
		if ( '' === $phone ) {
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$user = Mantia_Repository::find_user_by_phone( $phone );
		if ( ! $user ) {
			$noun  = Mantia_Vocab::word( 'noun', $phone );
			$indef = Mantia_Vocab::word( 'article_indef', $phone );
			return array(
				'reply'     => sprintf( 'Primero entrá a %s %s con su código.', $indef, $noun ),
				'completed' => true,
			);
		}
		$group_id = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
		if ( $group_id <= 0 ) {
			$noun   = Mantia_Vocab::word( 'noun', $phone );
			$plural = Mantia_Vocab::word( 'plural', $phone );
			$indef  = Mantia_Vocab::word( 'article_indef', $phone );
			$activa = Mantia_Vocab::word( 'active_adj', $phone );
			return array(
				'reply'     => sprintf( 'Activá %s %s %s primero (mandame *mis %s*).', $indef, $noun, $activa, $plural ),
				'completed' => true,
			);
		}

		// Guard against accidental re-draws. If anyone already has a team,
		// require the user to mandar *re-sortear* explicitly to overwrite.
		if ( ! $force ) {
			$existing = Mantia_Repository::sweepstake_for_group( $group_id );
			foreach ( $existing as $row ) {
				if ( '' !== (string) ( $row['team'] ?? '' ) ) {
					$noun = Mantia_Vocab::word( 'noun', $phone );
					return array(
						// Plain sentence — no anchor emoji needed. The
						// previous ⚠️ wasn't in the approved set; dropping
						// it keeps the message readable without decoration.
						'reply'     => sprintf( 'Ya hubo sorteo en esta %s. Mandame *mi equipo* para ver el tuyo, o *re-sortear* para volver a tirar.', $noun ),
						'completed' => true,
					);
				}
			}
		}

		$assignments = Mantia_Repository::assign_sweepstake( $group_id );
		if ( empty( $assignments ) ) {
			return array(
				'reply'     => '❌ No pude armar el sorteo: no hay equipos cargados en la competencia, o el grupo está vacío.',
				'completed' => true,
			);
		}

		$group = Mantia_Repository::group_to_array( $group_id );
		$rows  = Mantia_Repository::sweepstake_for_group( $group_id );
		$lines = array(
			sprintf( '🎲 *Sorteo — %s*', (string) $group['name'] ),
			'',
		);
		foreach ( $rows as $row ) {
			// Italic on the empty-slot placeholder so it reads as a hint,
			// not as a team literally called "sin equipo" (rule: italic for
			// secondary / status text).
			$team    = '' !== (string) $row['team'] ? sprintf( '*%s*', (string) $row['team'] ) : '_sin equipo_';
			$lines[] = sprintf( '  • %s → %s', $row['name'], $team );
		}
		$lines[] = '';
		$lines[] = '📅 Te aviso cuando juegue tu equipo.';
		$lines[] = '_Mandame *mi equipo* para consultar._';

		return array(
			'reply'     => implode( "\n", $lines ),
			'completed' => true,
		);
	}

	/**
	 * /mi equipo — query the caller's sweepstake assignment for their
	 * active penca. Silent fallback when no draw happened yet.
	 */
	private static function handle_sweepstake_mine( array $identity ): array {
		$phone = (string) ( $identity['phone'] ?? '' );
		if ( '' === $phone ) {
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$user = Mantia_Repository::find_user_by_phone( $phone );
		if ( ! $user ) {
			$noun  = Mantia_Vocab::word( 'noun', $phone );
			$indef = Mantia_Vocab::word( 'article_indef', $phone );
			return array(
				'reply'     => sprintf( 'Todavía no estás en %s %s.', $indef, $noun ),
				'completed' => true,
			);
		}
		$group_id = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
		if ( $group_id <= 0 ) {
			$noun   = Mantia_Vocab::word( 'noun', $phone );
			$indef  = Mantia_Vocab::word( 'article_indef', $phone );
			$activa = Mantia_Vocab::word( 'active_adj', $phone );
			return array(
				'reply'     => sprintf( 'No tenés %s %s %s.', $indef, $noun, $activa ),
				'completed' => true,
			);
		}
		$team = Mantia_Repository::get_sweepstake_team( (int) $user->ID, $group_id );
		if ( '' === $team ) {
			$noun = Mantia_Vocab::word( 'noun', $phone );
			return array(
				'reply'     => sprintf( '⏳ Todavía no hubo sorteo en esta %s. Pedile al organizador que mande *sortear*.', $noun ),
				'completed' => true,
			);
		}
		return array(
			'reply'     => sprintf( '🎲 Tu equipo del sorteo: *%s*', $team ),
			'completed' => true,
		);
	}

	/**
	 * Bulk-back a team: set 2-1 wins for every upcoming match where the
	 * named team plays in any of the user's pencas. The pick is the most
	 * common winning scoreline so the "back my country / favourite club"
	 * intent gets a sensible default without typing 7 individual scores.
	 *
	 * Predictions are fanned out across all the user's pencas in each
	 * match's competition (same fan-out the regular score command uses),
	 * so backing Argentina updates Family penca + Office penca at once.
	 */
	private static function handle_bulk_back_team( string $team_raw, array $identity ): array {
		if ( '' === trim( $team_raw ) ) {
			return array(
				'reply' => 'Decime qué equipo querés bancar. Ej: *Argentina gana todo*.',
				'completed' => true,
			);
		}
		if ( '' === $identity['phone'] ) {
			return array(
				'reply' => 'No pude identificar tu numero. Reintentá en un toque.',
				'completed' => true,
			);
		}
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			$noun = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
			return array(
				'reply'     => sprintf( 'Primero entrá a una %s con su código y volvemos al bulk-set.', $noun ),
				'completed' => true,
			);
		}

		$user_id     = (int) $user->ID;
		$canonical   = Mantia_Repository::team_canonical( $team_raw );
		if ( '' === $canonical ) {
			return array(
				'reply'     => sprintf( 'No reconozco *%s* como un equipo del Mundial. Probá con el nombre exacto, ej: *Argentina gana todo*.', sanitize_text_field( $team_raw ) ),
				'completed' => true,
			);
		}

		// Collect upcoming matches across every penca the user belongs to.
		$touched   = 0;
		$competitions = array();
		foreach ( Mantia_Repository::user_groups_to_array( $user_id ) as $g ) {
			$gid     = (int) $g['id'];
			$comp_id = Mantia_Repository::group_competition_id( $gid );
			if ( '' === $comp_id || isset( $competitions[ $comp_id ] ) ) {
				continue;
			}
			$competitions[ $comp_id ] = true;
			foreach ( Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 365 ) as $match ) {
				$home = (string) $match['home_team'];
				$away = (string) $match['away_team'];
				if ( $canonical !== $home && $canonical !== $away ) {
					continue;
				}
				$is_home = ( $canonical === $home );
				$pred = Mantia_Abilities::register_prediction(
					array(
						'user_phone'   => $identity['phone'],
						'match_id'     => (int) $match['id'],
						'first_team'   => $home,
						'first_score'  => $is_home ? 2 : 1,
						'second_team'  => $away,
						'second_score' => $is_home ? 1 : 2,
					)
				);
				if ( ! is_wp_error( $pred ) ) {
					$touched++;
				}
			}
		}

		if ( 0 === $touched ) {
			$plural = Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' );
			return array(
				'reply'     => sprintf( 'No encontré partidos pendientes con *%s*. Quizás ya jugaron, o no están en tus %s.', $canonical, $plural ),
				'completed' => true,
			);
		}

		return array(
			'reply'     => sprintf( '✅ Listo, *%s* sale ganando en %d %s. Para ajustar uno, mandame el marcador (ej: *3-0*).', $canonical, $touched, $touched === 1 ? 'partido' : 'partidos' ),
			'completed' => true,
		);
	}

	private static function handle_set_name( string $raw_name, array $identity ): array {
		$name = sanitize_text_field( $raw_name );
		// Strip trailing punctuation like "Me llamo Miguel."
		$name = trim( (string) preg_replace( '/[.!?,;:]+$/u', '', $name ) );
		if ( '' === $name || strlen( $name ) > 80 ) {
			return array(
				'reply'     => 'Decime tu nombre así nomás, ej: *me llamo Miguel*.',
				'completed' => true,
			);
		}
		if ( '' === $identity['phone'] ) {
			return array(
				'reply' => 'No pude identificar tu numero. Reintentá en un toque.',
				'completed' => true,
			);
		}
		$user_id = Mantia_Repository::get_or_create_user( $identity['phone'], $name, $identity['recipient'] );
		if ( 0 === $user_id ) {
			return array(
				'reply' => 'No pude guardar tu nombre. Reintentá.',
				'completed' => true,
			);
		}

		return array(
			'reply'       => sprintf( '¡Hola, %s! Quedó guardado.', $name ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array(
						'id' => 'mantia:cmd:home',
						'title' => '🏠 Resumen',
					),
					array(
						'id' => 'mantia:cmd:matches',
						'title' => '📅 Partidos',
					),
					array(
						'id' => 'mantia:cmd:help',
						'title' => '❓ Ayuda',
					),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_switch_group( int $group_id, array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array(
				'reply' => 'No pude identificar tu numero. Reintentá en un toque.',
				'completed' => true,
			);
		}
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array(
				'reply' => sprintf( 'Todavía no tenés %s.', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ),
				'completed' => true,
			);
		}
		$result = Mantia_Repository::set_active_group_for_user( (int) $user->ID, $group_id );
		if ( is_wp_error( $result ) ) {
			return array(
				'reply' => $result->get_error_message(),
				'completed' => true,
			);
		}
		$active = $result['active_group'];
		$lines  = array( sprintf( 'Listo, %s %s: *%s*.', Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' ), Mantia_Vocab::word( 'active_adj', $identity['phone'] ?? '' ), $active['name'] ), '' );
		$lines  = array_merge( $lines, self::member_lines( (int) $active['id'], (int) $user->ID ) );

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array(
						'id' => 'mantia:cmd:home',
						'title' => '🏠 Resumen',
					),
					array(
						'id' => 'mantia:cmd:share-link',
						'title' => '📤 Invitar',
					),
					array(
						'id' => 'mantia:cmd:my-groups',
						'title' => sprintf( '📋 Mis %s', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ),
					),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_join( WP_Post $group, array $identity ): array {
		$code   = (string) get_post_meta( (int) $group->ID, Mantia_Repository::META_INVITE_CODE, true );
		$result = Mantia_Repository::join_group( $identity['phone'], $code, $identity['name'], $identity['recipient'] );

		if ( is_wp_error( $result ) ) {
			return array(
				'reply' => $result->get_error_message(),
				'completed' => true,
			);
		}

		$g       = $result['group'];
		$joiner  = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		$me_id   = $joiner ? (int) $joiner->ID : 0;
		$noun    = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
		$activa  = Mantia_Vocab::word( 'active_adj', $identity['phone'] ?? '' );
		$autofilled = (int) ( $result['autofilled'] ?? 0 );
		$intro      = ! empty( $result['already_member'] )
			? sprintf( 'Listo, %s %s: *%s*.', $noun, $activa, $g['name'] )
			: sprintf( 'Listo, te sume a *%s*. Esa queda como tu %s %s.', $g['name'], $noun, $activa );

		$lines = array( $intro );
		if ( $autofilled > 0 ) {
			$lines[] = sprintf(
				'_Ya te puse pronósticos para los %d partidos. Mandame el marcador o tocá un partido para cambiarlos._',
				$autofilled
			);
		}
		$lines[] = '';
		$lines = array_merge( $lines, self::member_lines( (int) $g['id'], $me_id ) );

		if ( '' !== ( $g['share_url'] ?? '' ) ) {
			$lines[] = '';
			$lines[] = 'Para invitar amigos, reenviá este link:';
			$lines[] = $g['share_url'];
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array(
						'id' => 'mantia:cmd:share-link',
						'title' => '📤 Invitar',
					),
					array(
						'id' => 'mantia:cmd:home',
						'title' => '🏠 Resumen',
					),
					array(
						'id' => 'mantia:cmd:my-groups',
						'title' => sprintf( '📋 Mis %s', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ),
					),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_create_group( string $raw_name, array $identity ): array {
		$name = sanitize_text_field( $raw_name );
		if ( '' === $name ) {
			$noun = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
			return array(
				'reply'     => sprintf( 'Decime como se llama la %s. Ejemplo: *nueva %s La Familia*.', $noun, $noun ),
				'completed' => true,
			);
		}
		if ( '' === $identity['phone'] ) {
			return array(
				'reply'     => 'No pude identificar tu numero de WhatsApp. Reintentá en un toque.',
				'completed' => true,
			);
		}

		// Stash the name; user must pick a competition before we create.
		set_transient( self::pending_create_key( $identity['phone'] ), $name, 15 * MINUTE_IN_SECONDS );

		$rows = array();
		foreach ( Mantia_Competitions::all() as $c ) {
			$rows[] = array(
				'id'          => 'mantia:competition:' . $c['id'],
				'title'       => trim( ( $c['emoji'] ?? '' ) . ' ' . $c['name'] ),
				'description' => (string) ( $c['description'] ?? '' ),
			);
		}

		return array(
			'reply'       => sprintf( '*%s* — ¿para qué torneo es?', $name ),
			'interactive' => array(
				'type'         => 'list',
				'header'       => Mantia_Vocab::word( 'create', $identity['phone'] ?? '' ),
				'button_label' => 'Elegir torneo',
				'sections'     => array(
					array(
						'title' => 'Competencias',
						'rows' => $rows,
					),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_competition_chosen( string $competition_id, array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array(
				'reply' => 'No pude identificar tu numero. Reintentá en un toque.',
				'completed' => true,
			);
		}
		$noun        = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
		$competition = Mantia_Competitions::get( $competition_id );
		if ( ! $competition ) {
			return array(
				'reply' => sprintf( 'Esa competencia no existe. Probá *nueva %s <nombre>* otra vez.', $noun ),
				'completed' => true,
			);
		}

		$name_key = self::pending_create_key( $identity['phone'] );
		$name     = (string) get_transient( $name_key );
		if ( '' === $name ) {
			return array(
				'reply'     => sprintf( 'No tengo nombre de %s pendiente. Escribí *nueva %s <nombre>* otra vez y vuelvo a preguntar el torneo.', $noun, $noun ),
				'completed' => true,
			);
		}
		delete_transient( $name_key );

		$group_id = Mantia_Repository::create_group( $name, '', '', $competition_id );
		if ( $group_id <= 0 ) {
			return array(
				'reply' => sprintf( 'No pude crear esa %s. Probá con otro nombre.', $noun ),
				'completed' => true,
			);
		}

		$group = Mantia_Repository::group_to_array( $group_id );
		$join_result = Mantia_Repository::join_group(
			$identity['phone'],
			$group['invite_code'],
			$identity['name'],
			$identity['recipient']
		);

		$creator    = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		$me_id      = $creator ? (int) $creator->ID : 0;
		$autofilled = is_array( $join_result ) ? (int) ( $join_result['autofilled'] ?? 0 ) : 0;

		// Send the forwardable invitation card FIRST (will appear upper in
		// the chat — slightly older). It's self-contained: no "vos creaste"
		// context, so a friend who receives a long-press-and-forward of it
		// reads a clean invitation.
		self::send_invite_card( $identity['recipient'], $group );

		// The bot's reply to the creator (lower in chat = newest = visible).
		// Explains what they should do next: forward the card above.
		$lines = array(
			sprintf( '✅ Creaste *%s* para %s.', $group['name'], $group['competition_name'] ),
		);
		if ( $autofilled > 0 ) {
			$lines[] = sprintf(
				'_Ya te puse pronósticos para los %d partidos. Mandame el marcador o tocá un partido para cambiarlos._',
				$autofilled
			);
		}
		$lines[] = '';
		$lines[] = '_Reenviá la tarjeta de arriba ↑ a cualquier grupo de WhatsApp para que se sumen tus amigos._';
		$lines[] = '';
		// Roster only here (no private link inline) — this reply lives right
		// next to the forwardable invite card, and any line that looks
		// link-ish can get copy/pasted by accident. The user's /me/ URL
		// already exists in the post-join replies and persists in chat
		// history; reproducing it inside a "forward this" context risks
		// handing edit-capable access to recipients. Same fix as the
		// share-link reply (R1) — close the same hole in the create reply.
		$lines = array_merge( $lines, self::member_lines( $group_id, $me_id, false ) );

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array(
						'id' => 'mantia:cmd:share-link',
						'title' => '📤 Compartir',
					),
					array(
						'id' => 'mantia:cmd:matches',
						'title' => '📅 Partidos',
					),
					array(
						'id' => 'mantia:cmd:help',
						'title' => '❓ Ayuda',
					),
				),
			),
			'completed' => true,
		);
	}

	/**
	 * A self-contained invitation card the user can long-press → Forward to
	 * a WhatsApp group. The text reads cleanly when received by someone who
	 * is NOT the creator: it's framed as an invitation, not a confirmation.
	 *
	 * Sent as a separate outbound message (not the preflight reply) so it
	 * lives in its own bubble and forwards independently of the creator's
	 * confirmation + buttons.
	 */
	private static function send_invite_card( string $recipient, array $group ): bool {
		if ( '' === $recipient || ! class_exists( 'OpenclaWP_Whatsapp' ) ) {
			return false;
		}
		$lines = self::build_invite_card_lines( $group );
		if ( empty( $lines ) ) {
			return false;
		}
		return (bool) OpenclaWP_Whatsapp::send_text_message( $recipient, implode( "\n", $lines ) );
	}

	/**
	 * @param array<string,mixed> $group Group array from Mantia_Repository::group_to_array
	 * @return array<int,string>
	 */
	private static function build_invite_card_lines( array $group ): array {
		$name      = (string) ( $group['name'] ?? '' );
		$comp      = (string) ( $group['competition_name'] ?? '' );
		$code      = (string) ( $group['invite_code'] ?? '' );

		// Prefer the mantia.uy-style landing URL when we have a view token —
		// it carries rich link previews (og:image) in WhatsApp / Slack /
		// anywhere a URL is pasted, AND 302s the click straight to wa.me.
		$landing = self::build_join_landing_url( $group );
		$wa_raw  = (string) ( $group['share_url'] ?? '' );
		$primary = '' !== $landing ? $landing : $wa_raw;

		if ( '' === $name || ( '' === $primary && '' === $code ) ) {
			return array();
		}

		// Deliberately NO competition line in the text body — without a
		// "Torneo:" prefix it reads as ambiguous prose ("Esta semana" looks
		// like a phrase, not a tournament name). The OG card preview that
		// renders below the link already shows the competition in uppercase
		// labeled context where it reads as a category.
		$lines   = array();
		$lines[] = sprintf( '🏆 *Sumate a %s*', $name );
		$lines[] = '';
		if ( '' !== $primary ) {
			$lines[] = 'Tocá el link para sumarte:';
			$lines[] = $primary;
		} else {
			$lines[] = 'Mandale este código al bot de WhatsApp:';
			$lines[] = '*' . $code . '*';
		}
		$lines[] = '';
		// Brand footer — kept country-neutral ("fútbol" works everywhere) so a
		// forwarded card reads naturally no matter which region the recipient
		// is in. The card itself isn't bound to a specific user phone.
		$lines[] = '_— Mantia, fútbol por WhatsApp_';
		unset( $comp ); // intentionally not used in the body
		return $lines;
	}

	/**
	 * Build the /pronostico/sumate/<INVITE_CODE>/ landing URL. The page handles
	 * OG preview for WhatsApp + 302s to wa.me with the code prefilled.
	 *
	 * Uses invite_code (random + non-guessable) rather than the group slug.
	 * The slug shape leaked existence + bypassed the invite_code gate via
	 * guessing — "Los Cinicos" → /pronostico/g/los-cinicos/sumate/ → anyone
	 * who could guess the name could see and join.
	 */
	private static function build_join_landing_url( array $group ): string {
		$code = (string) ( $group['invite_code'] ?? '' );
		if ( '' === $code ) {
			return '';
		}
		return home_url( '/pronostico/sumate/' . $code . '/' );
	}

	/**
	 * Render a group's member list as text lines, marking the current
	 * user. Shared by post-create, post-switch, post-join, and share-link
	 * replies so the "who's already here?" answer is consistent.
	 *
	 * NEVER includes the user's /me/ private edit-URL inline. R3 caught
	 * the URL leaking through every "Reenviá esto a tus amigos" context
	 * because it was rendered in the same bubble as the share text — a
	 * user copy/pasting the wrong line handed edit access to recipients.
	 * The owner already has the chat history; /me/ is also exposed via
	 * topbar / send_invite_card paths. The convenience wasn't worth the
	 * blast radius.
	 *
	 * @return array<int,string>
	 */
	private static function member_lines( int $group_id, int $current_user_id ): array {
		$members = Mantia_Repository::group_members( $group_id );
		if ( count( $members ) <= 1 ) {
			return array( '👥 Solo vos por ahora. Compartí el link con tus amigos para sumarlos.' );
		}
		$lines = array( sprintf( '👥 Quiénes están (%d):', count( $members ) ) );
		foreach ( $members as $m ) {
			$marker  = (int) $m['id'] === $current_user_id ? ' _(vos)_' : '';
			$lines[] = sprintf( '  • %s%s', $m['display_name'], $marker );
		}
		return $lines;
	}

	private static function pending_create_key( string $phone ): string {
		return 'mantia_pending_create_' . md5( $phone );
	}

	private static function pending_comp_key( string $phone ): string {
		return 'mantia_pending_comp_' . md5( $phone );
	}

	private static function is_escape_command( string $lc ): bool {
		return (bool) preg_match( '/^(?:hola|hello|hi|hey|home|inicio|menu|ayuda|help|comandos|cancelar|cancel|salir|olvida|olvidalo|\?|\/[a-z]*)$/iu', trim( $lc ) );
	}

	/**
	 * Entry-point for the competition-first new-penca flow: show the
	 * tournament list immediately, then ask for the name once the user picks.
	 * More discoverable than asking for free-text name first.
	 */
	private static function handle_new_penca_start( array $identity ): array {
		if ( '' !== $identity['phone'] ) {
			delete_transient( self::pending_create_key( $identity['phone'] ) );
		}

		$rows = array();
		foreach ( Mantia_Competitions::all() as $c ) {
			$rows[] = array(
				'id'          => 'mantia:newcomp:' . $c['id'],
				'title'       => self::truncate_title( trim( ( $c['emoji'] ?? '' ) . ' ' . $c['name'] ), 24 ),
				'description' => self::truncate_title( (string) ( $c['description'] ?? '' ), 72 ),
			);
		}

		if ( empty( $rows ) ) {
			return array(
				'reply'     => 'No tengo competencias cargadas todavía. Pedile al admin que active Mantia.',
				'completed' => true,
			);
		}

		$noun = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
		return array(
			'reply'       => sprintf( 'Listo. ¿Para qué torneo es tu %s?', $noun ),
			'interactive' => array(
				'type'         => 'list',
				'header'       => Mantia_Vocab::word( 'create', $identity['phone'] ?? '' ),
				'button_label' => 'Elegir torneo',
				'sections'     => array(
					array(
						'title' => 'Competencias',
						'rows' => $rows,
					),
				),
			),
			'completed' => true,
		);
	}

	/**
	 * Competition picked first; stash it and prompt for the name. The pending-
	 * name detector in the main router will pick the next message up as the
	 * group name (unless it's an escape command).
	 */
	private static function handle_competition_picked_for_new( string $competition_id, array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array(
				'reply' => 'No pude identificar tu numero. Reintentá en un toque.',
				'completed' => true,
			);
		}
		$competition = Mantia_Competitions::get( $competition_id );
		if ( ! $competition ) {
			return array(
				'reply' => 'Esa competencia ya no existe. Probá *crear* otra vez.',
				'completed' => true,
			);
		}

		set_transient(
			self::pending_comp_key( $identity['phone'] ),
			$competition_id,
			15 * MINUTE_IN_SECONDS
		);

		$label = trim( ( $competition['emoji'] ?? '' ) . ' ' . $competition['name'] );
		$noun  = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
		return array(
			'reply'     => sprintf(
				"*%s* — ¿cómo se va a llamar tu %s?\n\nMandame un nombre cortito (ej: *%s*) y la creo. Mandá *cancelar* si cambiaste de idea.",
				$label,
				$noun,
				self::pick_name_example()
			),
			'completed' => true,
		);
	}

	/**
	 * Rotating examples for "how should we name this penca?" prompts.
	 * Stays neutral + grouped around the most common penca social
	 * settings (family, friends, work). No slang.
	 */
	private static function pick_name_example(): string {
		$examples = apply_filters(
			'mantia_name_examples',
			array( 'Los Amigos', 'La Familia', 'La Oficina', 'Los Mundialistas', 'Los Pibes' )
		);
		return (string) $examples[ array_rand( $examples ) ];
	}

	/**
	 * Counterpart of handle_competition_chosen: competition was picked first
	 * and the user just sent us the penca name. Creates the group + reuses
	 * the same final reply.
	 */
	private static function handle_name_after_competition( string $raw_name, array $identity, string $competition_id ): array {
		$name = sanitize_text_field( $raw_name );
		$name = trim( (string) preg_replace( '/[.!?,;:]+$/u', '', $name ) );
		if ( '' === $name || strlen( $name ) > 80 ) {
			return array(
				'reply'     => sprintf( 'Decime un nombre cortito (max 80 chars). Ej: *%s*.', self::pick_name_example() ),
				'completed' => true,
			);
		}

		$competition = Mantia_Competitions::get( $competition_id );
		if ( ! $competition ) {
			delete_transient( self::pending_comp_key( $identity['phone'] ) );
			return array(
				'reply' => 'Esa competencia ya no existe. Probá *crear* otra vez.',
				'completed' => true,
			);
		}

		delete_transient( self::pending_comp_key( $identity['phone'] ) );

		// Reuse the post-creation reply by routing through the existing path:
		// stash the name, then call handle_competition_chosen which expects it.
		set_transient( self::pending_create_key( $identity['phone'] ), $name, 15 * MINUTE_IN_SECONDS );
		return self::handle_competition_chosen( $competition_id, $identity );
	}

	/**
	 * Resolve a free-text competition hint ("Mundial 2026", "Libertadores",
	 * "liga uruguaya") to a slug. Returns null if the hint clearly looks
	 * like a custom penca name instead.
	 */
	private static function resolve_competition_hint( string $hint ): ?string {
		$h = function_exists( 'remove_accents' ) ? remove_accents( $hint ) : $hint;
		$h = strtolower( trim( $h ) );
		if ( '' === $h ) {
			return null;
		}

		// Strip natural-language connectors so "de Esta semana" / "de la
		// LigaUY" still match the slug. We do this once at the top so
		// every branch below sees the normalized hint.
		$h = (string) preg_replace( '/^(?:de\s+la\s+|de\s+los\s+|de\s+las\s+|de\s+los?\s+|del\s+|de\s+)/u', '', $h );
		$h = trim( $h );
		if ( '' === $h ) {
			return null;
		}

		// 1. Direct slug or sanitize-equivalent ("Esta semana" → "esta-semana").
		$direct = Mantia_Competitions::get( $h );
		if ( null !== $direct ) {
			return (string) $direct['id'];
		}

		// 2. Walk every competition in the CPT registry. For each candidate
		// we score by name + admin-managed aliases — the longest match
		// wins so "libertadores 2026" beats "libertadores". Aliases live
		// in post_meta, not in code, so admins can add a new competition
		// + its short forms entirely via wp-admin.
		$candidates = array();
		foreach ( Mantia_Competitions::all() as $c ) {
			$slug    = (string) $c['id'];
			$name    = (string) $c['name'];
			$name    = function_exists( 'remove_accents' ) ? remove_accents( $name ) : $name;
			$name    = strtolower( trim( $name ) );
			$aliases = isset( $c['aliases'] ) && is_array( $c['aliases'] ) ? $c['aliases'] : array();
			$terms   = array_values( array_filter( array_merge( array( $name ), $aliases ) ) );
			foreach ( $terms as $term ) {
				$term = (string) $term;
				if ( '' !== $term ) {
					$candidates[] = array(
						'slug' => $slug,
						'term' => $term,
					);
				}
			}
		}
		// Longer term first so specific matches ("liga uy 2026") win over
		// generic ones ("liga"). Stable sort isn't required.
		usort(
			$candidates,
			static fn( array $a, array $b ): int => strlen( (string) $b['term'] ) - strlen( (string) $a['term'] )
		);
		foreach ( $candidates as $cand ) {
			if ( false !== strpos( $h, (string) $cand['term'] ) ) {
				return (string) $cand['slug'];
			}
		}

		return null;
	}

	private static function truncate_title( string $s, int $max ): string {
		$s = trim( $s );
		return ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s ) ) > $max
			? rtrim( ( function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max - 1 ) : substr( $s, 0, $max - 1 ) ) ) . '…'
			: $s;
	}

	/**
	 * Common South-American club abbreviations for narrow display contexts
	 * (WhatsApp Interactive List row.title caps at 24 chars total, so a
	 * matchup like "Mineros de Guayana vs Independiente del Valle" would
	 * clip to "Mineros de Guayana vs In"). Public web views render the
	 * full name — this map only fires through format_matchup() below.
	 */
	private const TEAM_ABBREVIATIONS = array(
		'Universidad de Chile'    => 'U. Chile',
		'Universidad Católica'    => 'U. Católica',
		'Atlético Bucaramanga'    => 'Bucaramanga',
		'Defensa y Justicia'      => 'Defensa y J.',
		'Mineros de Guayana'      => 'Mineros',
		'Independiente del Valle' => 'Indep. del V.',
		'Vélez Sársfield'         => 'Vélez',
		'Internacional'           => 'Inter',
		'River Plate'             => 'River',
		'Boca Juniors'            => 'Boca',
		'Independiente Rivadavia' => 'Indep. Riv.',
		'Independiente Medellín'  => 'Indep. Med.',
		'Deportivo La Guaira'     => 'La Guaira',
		'Sporting Cristal'        => 'Sp. Cristal',
		'Universidad Central'     => 'U. Central',
	);

	/**
	 * Format a "Home vs Away" matchup that fits the WhatsApp row.title
	 * cap. First tries the full names; if they overflow, swaps in the
	 * curated abbreviation; last resort is a hard ellipsis truncate.
	 */
	private static function format_matchup( string $home, string $away, int $max = 24 ): string {
		$full = $home . ' vs ' . $away;
		$len  = static fn ( string $s ): int => function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
		if ( $len( $full ) <= $max ) {
			return $full;
		}
		$home_short = self::TEAM_ABBREVIATIONS[ $home ] ?? $home;
		$away_short = self::TEAM_ABBREVIATIONS[ $away ] ?? $away;
		$abbreviated = $home_short . ' vs ' . $away_short;
		return self::truncate_title( $abbreviated, $max );
	}

	private static function handle_my_groups( array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array(
				'reply'     => 'No pude identificar tu numero de WhatsApp. Reintentá en un toque.',
				'completed' => true,
			);
		}

		$empty_msg = sprintf( 'Todavía no tenés %s.', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) );

		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array(
				'reply'       => $empty_msg,
				'interactive' => array(
					'type'    => 'button',
					'buttons' => array(
						array(
							'id' => 'mantia:cmd:new-penca',
							'title' => '➕ ' . Mantia_Vocab::word( 'create', $identity['phone'] ?? '' ),
						),
						array(
							'id' => 'mantia:cmd:have-code',
							'title' => '🔑 Tengo código',
						),
						array(
							'id' => 'mantia:cmd:help',
							'title' => '❓ Ayuda',
						),
					),
				),
				'completed' => true,
			);
		}

		$groups = Mantia_Repository::user_groups_to_array( (int) $user->ID );
		if ( empty( $groups ) ) {
			return array(
				'reply'       => $empty_msg,
				'interactive' => array(
					'type'    => 'button',
					'buttons' => array(
						array(
							'id' => 'mantia:cmd:new-penca',
							'title' => '➕ ' . Mantia_Vocab::word( 'create', $identity['phone'] ?? '' ),
						),
						array(
							'id' => 'mantia:cmd:have-code',
							'title' => '🔑 Tengo código',
						),
					),
				),
				'completed' => true,
			);
		}

		// Render every penca — even N=1 — as a tappable Interactive List
		// row. Two reasons: (a) consistent affordance (always a tap to
		// drill in, never a "where do I go?" plain-text); (b) the row
		// description carries the "N jugadores · M pronósticos" sub-line
		// the QA cycle asked for, which has no home in a plain-text reply.
		$rows  = array();
		$lines = array();
		if ( count( $groups ) >= 2 ) {
			$lines[] = sprintf( 'Estás en %d %s:', count( $groups ), Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) );
			$lines[] = '';
		} else {
			$lines[] = sprintf( 'Tu %s:', Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' ) );
			$lines[] = '';
		}
		foreach ( $groups as $g ) {
			$gid          = (int) $g['id'];
			$marker       = ! empty( $g['is_active'] ) ? '✅' : '▫️';
			$comp_part    = isset( $g['competition_name'] ) && '' !== $g['competition_name']
				? ' · ' . $g['competition_name']
				: '';
			$lines[]      = sprintf( '%s *%s*%s', $marker, $g['name'], $comp_part );

			// Per-row counts: members + this-user predictions in this penca.
			// Pluralize each half independently — sharing a single _n() with
			// max() of the two yielded "1 jugadores · 8 pronósticos" when
			// member_count=1 and pred_count=8.
			$member_count = count( Mantia_Repository::group_members( $gid ) );
			$pred_count   = self::count_user_predictions_in_group( (int) $user->ID, $gid );
			$members_str  = sprintf(
				/* translators: %d: members count */
				_n( '%d jugador', '%d jugadores', $member_count, 'mantia' ),
				$member_count
			);
			$preds_str    = sprintf(
				/* translators: %d: predictions count */
				_n( '%d pronóstico', '%d pronósticos', $pred_count, 'mantia' ),
				$pred_count
			);
			$desc_parts   = array_filter( array(
				(string) ( $g['competition_name'] ?? '' ),
				$members_str . ' · ' . $preds_str,
			) );

			$rows[] = array(
				'id'          => 'mantia:switch:' . $gid,
				'title'       => self::truncate_title( ( ! empty( $g['is_active'] ) ? '✓ ' : '' ) . $g['name'], 24 ),
				'description' => self::truncate_title( implode( ' · ', $desc_parts ), 72 ),
			);
		}
		$lines[] = '';
		$lines[] = count( $groups ) >= 2
			? '_Tocá una para hacerla la activa._'
			: '_Tocá para ver el detalle._';

		// Web link belongs here (not the home) — users only need it when
		// they're looking at their full collection of pencas, which is
		// exactly this view. Keeping it off the home reduces noise on the
		// 90% case where the user just wants to predict.
		$me_url = Mantia_Repository::user_view_url( (int) $user->ID );
		if ( '' !== $me_url ) {
			$lines[] = '';
			$lines[] = sprintf( '🌐 Ver en la web (link privado): %s', $me_url );
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'         => 'list',
				'button_label' => self::truncate_title( 'Ver ' . Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ), 20 ),
				'sections'     => array(
					array(
						'title' => sprintf( 'Mis %s', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ),
						'rows'  => $rows,
					),
				),
			),
			'completed' => true,
		);
	}

	/**
	 * Count predictions made by a user in a specific penca. Used to build
	 * the "N pronósticos" sub-line on the mis-pencas list — fresh joiners
	 * see how many of their default seeded predictions they've reviewed.
	 */
	private static function count_user_predictions_in_group( int $user_id, int $group_id ): int {
		if ( $user_id <= 0 || $group_id <= 0 ) {
			return 0;
		}
		$ids = get_posts( array(
			'post_type'      => Mantia_CPTs::PREDICTION,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => Mantia_Repository::META_USER_ID,  'value' => $user_id ),
				array( 'key' => Mantia_Repository::META_GROUP_ID, 'value' => $group_id ),
			),
		) );
		return count( (array) $ids );
	}

	private static function handle_share_link( array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array(
				'reply'     => 'No pude identificar tu numero. Reintentá en un toque.',
				'completed' => true,
			);
		}
		$noun = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array(
				'reply'     => sprintf( 'Todavía no tenés %1$s. Creá %2$s con *%3$s %4$s <nombre>* o entrá con un código.', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ), Mantia_Vocab::word( 'article_indef', $identity['phone'] ?? '' ), Mantia_Vocab::word( 'new_adj', $identity['phone'] ?? '' ), $noun ),
				'completed' => true,
			);
		}
		$active = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
		if ( $active <= 0 ) {
			$indef  = Mantia_Vocab::word( 'article_indef', $identity['phone'] ?? '' );
			$activa = Mantia_Vocab::word( 'active_adj', $identity['phone'] ?? '' );
			$nueva  = Mantia_Vocab::word( 'new_adj', $identity['phone'] ?? '' );
			return array(
				'reply'     => sprintf( 'No tenes %1$s %2$s %3$s. Mandame un código o creá %1$s con *%4$s %2$s <nombre>*.', $indef, $noun, $activa, $nueva ),
				'completed' => true,
			);
		}
		$group = Mantia_Repository::group_to_array( $active );
		$share = (string) ( $group['share_url'] ?? '' );

		// Push a fresh invitation card the user can long-press → Forward.
		// Lives in its own bubble (above the bot's reply with buttons) so a
		// forward carries only the card, not the bot context.
		self::send_invite_card( $identity['recipient'], $group );

		// Reply intentionally compact: a one-line context + the wa.me
		// share URL. Don't include `📱 Tu link privado:` here (that's the
		// owner's /me/ edit-token URL — the QA cycle flagged 3 personas
		// copying the wrong line and forwarding edit-capable access).
		// Don't include `🌐 Ver standings en la web:` either — the wa.me
		// link is the one-tap join, and Standings can live on the group
		// page once recipients join.
		$lines = array(
			'_↑ Reenviá la tarjeta de arriba a tus amigos._',
			'',
			sprintf( '*%s* (%s)', $group['name'], $group['competition_name'] ?? '' ),
			sprintf(
				'👥 %d en %s %s',
				count( Mantia_Repository::group_members( $active ) ),
				Mantia_Vocab::word( 'article', $identity['phone'] ?? '' ),
				$noun
			),
		);

		if ( '' !== $share ) {
			$lines[] = '';
			$lines[] = '🤝 Invitar amigos (1 toque):';
			$lines[] = $share;
		} else {
			$lines[] = '';
			$lines[] = sprintf( 'Código: *%s*', $group['invite_code'] );
		}

		return array(
			'reply' => implode( "\n", $lines ),
			'completed' => true,
		);
	}

	private static function handle_home( array $identity ): array {
		$user = '' !== $identity['phone'] ? Mantia_Repository::find_user_by_phone( $identity['phone'] ) : null;
		if ( ! $user ) {
			$home_url = home_url( '/' );
			return array(
				'reply'       => sprintf(
					"Hola, soy *Mantia* — pronósticos de fútbol con tus amigos.\n\nAcá por chat o desde la web: %s",
					$home_url
				),
				'interactive' => array(
					'type'    => 'button',
					'buttons' => array(
						array(
							'id' => 'mantia:cmd:new-penca',
							'title' => '➕ ' . Mantia_Vocab::word( 'create', $identity['phone'] ?? '' ),
						),
						array(
							'id' => 'mantia:cmd:have-code',
							'title' => '🔑 Tengo código',
						),
						array(
							'id' => 'mantia:cmd:help',
							'title' => '❓ Cómo funciona',
						),
					),
				),
				'completed' => true,
			);
		}

		$user_id   = (int) $user->ID;
		$groups    = Mantia_Repository::user_groups_to_array( $user_id );
		$active_id = Mantia_Repository::active_group_id_for_user( $user_id );
		$active    = $active_id > 0 ? Mantia_Repository::group_to_array( $active_id ) : array();
		$standings = $active_id > 0 ? Mantia_Leaderboard::rows( $active_id, 3 ) : array();

		// "Próximos partidos" must come from the active penca's competition
		// — never the global fixture. Otherwise a user in a Mundial penca
		// sees random LigaUY matches because they happen to be scheduled
		// in the next 48h. (Bug surfaced 2026-05-17 in a screenshot:
		// Penca Familia / Mundial 2026 was showing "Miramar Misiones vs
		// River Plate Montevideo" et al.)
		$comp_for_upcoming = $active_id > 0 ? Mantia_Repository::group_competition_id( $active_id ) : '';
		$upcoming          = '' !== $comp_for_upcoming
			? Mantia_Repository::upcoming_matches_for_competition( $comp_for_upcoming, 48 )
			: Mantia_Repository::upcoming_matches( 48 );

		$lines = array();
		if ( ! empty( $active['name'] ) ) {
			$comp_label = ! empty( $active['competition_name'] ) ? sprintf( ' · %s', $active['competition_name'] ) : '';
			// Drop the "Activa:" prefix — it's internal vocab. The name alone
			// is enough context; users with multiple pencas get a switcher
			// hint below.
			$lines[] = sprintf( '*%s*%s', $active['name'], $comp_label );
		} else {
			$lines[] = sprintf( 'Todavía no tenés %s.', Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' ) );
		}

		// When the user has more than one penca, surface the switcher so they
		// know there are others and how to flip between them. Without this
		// nudge, the home looks identical regardless of count and the multi-
		// penca affordance gets invisible.
		if ( count( $groups ) > 1 ) {
			$lines[] = sprintf( '_(%d %s — *mis pencas* para cambiar)_', count( $groups ), Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) );
		}

		if ( ! empty( $standings ) ) {
			$lines[] = '';
			$lines[] = 'Top:';
			foreach ( $standings as $row ) {
				$lines[] = sprintf( '  %d. %s — %d pts', $row['rank'], $row['name'], $row['points'] );
			}
		}

		if ( ! empty( $upcoming ) ) {
			$lines[] = '';
			$lines[] = 'Próximos partidos:';
			foreach ( array_slice( $upcoming, 0, 3 ) as $m ) {
				$kickoff = self::format_kickoff( (string) ( $m['kickoff_gmt'] ?? '' ) );
				$lines[] = sprintf(
					'  • %s vs %s%s',
					Mantia_Frontend::normalize_team_name( (string) $m['home_team'] ),
					Mantia_Frontend::normalize_team_name( (string) $m['away_team'] ),
					'' !== $kickoff ? ' — ' . $kickoff : ''
				);
			}
		}

		$buttons = array(
			array(
				'id' => 'mantia:cmd:matches',
				'title' => '📅 Partidos',
			),
			array(
				'id' => 'mantia:cmd:pending',
				'title' => '⏳ Pendientes',
			),
			array(
				'id' => 'mantia:cmd:leaderboard',
				'title' => '📊 Tabla',
			),
		);

		// Cierre con link a la penca activa en la web — para que el user
		// sepa que el chat y la web son la misma información, dos puertas.
		// Magic-link tied to the user so the click auto-logs them in.
		$group_url = $active_id > 0 ? Mantia_Repository::group_view_url( $active_id, $user_id ) : '';
		if ( '' !== $group_url ) {
			$lines[] = '';
			$lines[] = sprintf( '🌐 Ver en la web: %s', $group_url );
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type' => 'button',
				'buttons' => $buttons,
			),
			'completed'   => true,
		);
	}

	private static function handle_help( array $identity = array() ): array {
		$phone      = $identity['phone'] ?? '';
		$noun       = Mantia_Vocab::word( 'noun', $phone );
		$plural     = Mantia_Vocab::word( 'plural', $phone );
		$new_adj    = Mantia_Vocab::word( 'new_adj', $phone );
		$indef      = Mantia_Vocab::word( 'article_indef', $phone );
		$active_adj = Mantia_Vocab::word( 'active_adj', $phone );
		// Capitalise the section header (multibyte-safe for "Pronósticos").
		$plural_cap = function_exists( 'mb_convert_case' )
			? mb_convert_case( $plural, MB_CASE_TITLE, 'UTF-8' )
			: ucfirst( $plural );

		$lines = array(
			'*Mantia* — pronósticos de fútbol con tus amigos.',
			'',
			sprintf( 'Funciona por chat o por la web: %s', home_url( '/' ) ),
			'',
			'*' . $plural_cap . '*',
			sprintf( '• *%1$s %2$s <nombre>* — crear y obtener link', $new_adj, $noun ),
			sprintf( '• <código> — sumate a %s %s (ej: `FAMILIA2026`)', $indef, $noun ),
			sprintf( '• *mis pencas* — lista tus %s', $plural ),
			sprintf( '• *link* — compartir tu %s %s', $noun, $active_adj ),
			'',
			'*Partidos*',
			'• *partidos* — ver fixture con tu pronóstico al lado',
			'• *pendientes* — partidos sin pronóstico',
			'• *mis pronósticos* — historial tuyo',
			'• `Boca 2 River 1` — registrar pronóstico (con IA)',
			'',
			'*Otros*',
			sprintf( '• *tabla* — ranking de tu %s', $noun ),
			'• *hola* / *home* — resumen general',
		);
		return array(
			'reply' => implode( "\n", $lines ),
			'completed' => true,
		);
	}

	private static function handle_matches( array $identity ): array {
		$user      = '' !== $identity['phone'] ? Mantia_Repository::find_user_by_phone( $identity['phone'] ) : null;
		$active_id = $user ? Mantia_Repository::active_group_id_for_user( (int) $user->ID ) : 0;
		$comp_id   = $active_id > 0 ? Mantia_Repository::group_competition_id( $active_id ) : '';
		$upcoming  = '' !== $comp_id
			? Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 60 )
			: Mantia_Repository::upcoming_matches( 24 * 60 );

		if ( empty( $upcoming ) ) {
			$comp_label = '' !== $comp_id ? Mantia_Competitions::label( $comp_id ) : '';
			$msg        = '' !== $comp_label
				? sprintf( 'No hay partidos cargados para *%s* todavía.', $comp_label )
				: 'No hay partidos proximos cargados todavia. Una vez que arranque el fixture, los vas a ver acá.';
			return array(
				'reply' => $msg,
				'completed' => true,
			);
		}

		// Group rows into date sections (Hoy / Mañana / weekday / Próxima semana)
		// so a fixture-heavy week — Libertadores has 4-5 matches per day during
		// matchdays — doesn't dump everything into one anonymous list. The user
		// reads the section header before scanning the rows below it.
		$buckets   = array(); // bucket_label => array of rows
		$bucket_order = array();
		$rows_total = 0;
		foreach ( $upcoming as $m ) {
			if ( $rows_total >= 10 ) {
				break; // WhatsApp caps interactive lists at 10 rows total.
			}
			$predicted = '';
			if ( $user && $active_id > 0 ) {
				$p = Mantia_Repository::find_prediction( (int) $user->ID, (int) $m['id'], $active_id );
				if ( $p ) {
					$ph = (int) get_post_meta( (int) $p->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
					$pa = (int) get_post_meta( (int) $p->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
					$predicted = sprintf( '✓ %d-%d', $ph, $pa );
				} else {
					$predicted = '○ sin pronóstico';
				}
			}
			$kickoff_str = self::format_kickoff( (string) $m['kickoff_gmt'] );
			$bucket      = self::date_bucket_label( (string) $m['kickoff_gmt'] );
			if ( ! isset( $buckets[ $bucket ] ) ) {
				$buckets[ $bucket ] = array();
				$bucket_order[]    = $bucket;
			}
			$buckets[ $bucket ][] = array(
				'id'          => 'mantia:match:' . (int) $m['id'],
				'title'       => self::format_matchup( (string) $m['home_team'], (string) $m['away_team'] ),
				// Within a bucket the day is redundant — show just the time
				// + prediction status so the line stays scannable.
				'description' => trim( self::time_only( (string) $m['kickoff_gmt'] ) . ( $predicted ? ' • ' . $predicted : '' ) ),
			);
			++$rows_total;
		}

		$sections = array();
		foreach ( $bucket_order as $bucket ) {
			$sections[] = array(
				'title' => self::truncate_title( $bucket, 24 ),
				'rows'  => $buckets[ $bucket ],
			);
		}

		$header = $user && $active_id > 0
			? Mantia_Repository::group_to_array( $active_id )['competition_name']
			: 'Fixture';

		return array(
			'reply'       => 'Tocá un partido para ver detalle o cargar pronóstico:',
			'interactive' => array(
				'type'         => 'list',
				'header'       => $header,
				'button_label' => 'Ver partidos',
				'sections'     => $sections,
			),
			'completed' => true,
		);
	}

	/**
	 * Classify a kickoff into a human-friendly bucket label for the WhatsApp
	 * list section header. Buckets: Hoy / Mañana / weekday name within the
	 * next 7 days / "Próxima semana" after that. Times are evaluated in
	 * Uruguay local time (UTC-3) — same offset format_kickoff() uses.
	 */
	private static function date_bucket_label( string $gmt ): string {
		if ( '' === $gmt ) {
			return 'Próximos';
		}
		$ts = strtotime( $gmt . ( str_ends_with( $gmt, 'Z' ) ? '' : ' UTC' ) );
		if ( false === $ts ) {
			return 'Próximos';
		}
		static $days   = array( 'dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb' );
		static $months = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );

		$now_local       = time() - 3 * HOUR_IN_SECONDS;
		$match_local     = $ts - 3 * HOUR_IN_SECONDS;
		$today_midnight  = (int) strtotime( gmdate( 'Y-m-d', $now_local ) . ' 00:00:00 UTC' );
		$match_midnight  = (int) strtotime( gmdate( 'Y-m-d', $match_local ) . ' 00:00:00 UTC' );
		$delta_days      = (int) round( ( $match_midnight - $today_midnight ) / DAY_IN_SECONDS );

		if ( $delta_days <= 0 ) {
			return 'Hoy';
		}
		if ( 1 === $delta_days ) {
			return 'Mañana';
		}
		if ( $delta_days <= 6 ) {
			$dow = (int) gmdate( 'w', $match_local );
			$d   = (int) gmdate( 'j', $match_local );
			$m   = (int) gmdate( 'n', $match_local ) - 1;
			return sprintf( '%s %d %s', $days[ $dow ], $d, $months[ $m ] );
		}
		return 'Próxima semana';
	}

	/**
	 * Render just the HH:MM (Uruguay time) for a UTC kickoff. Used inside
	 * a date-bucketed section where the day name is already in the section
	 * header — repeating "mié 21 may" on every row would be redundant.
	 */
	private static function time_only( string $gmt ): string {
		if ( '' === $gmt ) {
			return '';
		}
		$ts = strtotime( $gmt . ( str_ends_with( $gmt, 'Z' ) ? '' : ' UTC' ) );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'H:i', $ts - 3 * HOUR_IN_SECONDS );
	}

	private static function handle_pending( array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array(
				'reply' => 'No pude identificar tu numero. Reintentá en un toque.',
				'completed' => true,
			);
		}
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array(
				'reply'     => sprintf( 'Primero unite a una %s con su codigo.', Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' ) ),
				'completed' => true,
			);
		}

		// Aggregate pending across EVERY competition the user has a penca in.
		// People with a Mundial penca + a midweek Libertadores penca expect to
		// see both buckets here, not just whatever group happens to be active.
		$user_id    = (int) $user->ID;
		$by_comp    = self::pending_by_competition( $user_id, 24 * 60 );
		$total      = array_sum( array_map( static fn ( array $b ): int => count( $b['matches'] ), $by_comp ) );

		if ( 0 === $total ) {
			return array(
				'reply'       => '✅ Tenés todos los partidos pronosticados.',
				'interactive' => array(
					'type'    => 'button',
					'buttons' => array(
						array(
							'id' => 'mantia:cmd:my-predictions',
							'title' => '📋 Mis pronósticos',
						),
						array(
							'id' => 'mantia:cmd:matches',
							'title' => '📅 Próximos partidos',
						),
						array(
							'id' => 'mantia:cmd:leaderboard',
							'title' => '📊 Tabla',
						),
					),
				),
				'completed' => true,
			);
		}

		$sections   = array();
		$rows_total = 0;
		$lines      = array(
			sprintf(
				/* translators: %d: pending predictions count */
				_n( 'Te falta *%d* pronóstico:', 'Te faltan *%d* pronósticos:', $total, 'mantia' ),
				$total
			),
			'',
		);
		foreach ( $by_comp as $bucket ) {
			if ( empty( $bucket['matches'] ) ) {
				continue;
			}
			$bucket_n = count( $bucket['matches'] );
			$lines[]  = sprintf(
				'*%s* — %s',
				$bucket['label'],
				sprintf(
					/* translators: %d: matches count in this bucket */
					_n( '%d partido', '%d partidos', $bucket_n, 'mantia' ),
					$bucket_n
				)
			);
			$rows = array();
			foreach ( $bucket['matches'] as $m ) {
				if ( $rows_total >= 10 ) {
					// WhatsApp interactive lists cap at 10 rows across all sections.
					break;
				}
				$rows[] = array(
					'id'          => 'mantia:match:' . (int) $m['id'],
					'title'       => self::format_matchup( (string) $m['home_team'], (string) $m['away_team'] ),
					'description' => self::truncate_title( self::format_kickoff( (string) $m['kickoff_gmt'] ), 72 ),
				);
				++$rows_total;
			}
			if ( ! empty( $rows ) ) {
				$sections[] = array(
					'title' => self::truncate_title( $bucket['label'], 24 ),
					'rows'  => $rows,
				);
			}
			if ( $rows_total >= 10 ) {
				break;
			}
		}

		$lines[] = '';
		$lines[] = '_Tocá uno y te pido el marcador._';

		$me_url = Mantia_Repository::user_view_url( $user_id );
		if ( '' !== $me_url ) {
			$lines[] = '';
			$lines[] = sprintf( '🌐 Ver pendientes en la web: %s', $me_url );
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'         => 'list',
				'button_label' => 'Elegir partido',
				'sections'     => $sections,
			),
			'completed' => true,
		);
	}

	/**
	 * Build pending-matches buckets for the user across every competition
	 * they have a penca in. Each bucket contains the competition label
	 * (with emoji) and the user's pending matches in that competition.
	 *
	 * @return array<int, array{competition_id:string, label:string, matches:array<int,array<string,mixed>>}>
	 */
	private static function pending_by_competition( int $user_id, int $hours_ahead ): array {
		$groups = Mantia_Repository::user_groups_to_array( $user_id );
		if ( empty( $groups ) ) {
			return array();
		}

		// Resolve each user-group's competition to its storage root (so a
		// group in libertadores-semana points at libertadores-2026 fixtures).
		$comp_roots = array();
		foreach ( $groups as $g ) {
			$root = Mantia_Competitions::storage_id( (string) ( $g['competition_id'] ?? '' ) );
			if ( '' !== $root && ! in_array( $root, $comp_roots, true ) ) {
				$comp_roots[] = $root;
			}
		}

		$buckets = array();
		foreach ( $comp_roots as $root ) {
			$matches = Mantia_Repository::upcoming_matches_for_competition( $root, $hours_ahead );
			if ( empty( $matches ) ) {
				continue;
			}
			$user_group_ids = Mantia_Repository::user_groups_in_competition( $user_id, $root );
			if ( empty( $user_group_ids ) ) {
				continue;
			}
			$pending = array();
			foreach ( $matches as $m ) {
				// A match is "pending" if the user hasn't predicted it in ANY
				// of their groups in this competition — once predicted in one,
				// auto-routing fans it to the others, so one entry per match.
				$has = false;
				foreach ( $user_group_ids as $gid ) {
					if ( Mantia_Repository::find_prediction( $user_id, (int) $m['id'], (int) $gid ) ) {
						$has = true;
						break;
					}
				}
				if ( ! $has ) {
					$pending[] = $m;
				}
			}
			if ( empty( $pending ) ) {
				continue;
			}
			$comp_info = Mantia_Competitions::get( $root );
			$label     = $comp_info ? trim( ( $comp_info['emoji'] ?? '' ) . ' ' . $comp_info['name'] ) : $root;
			$buckets[] = array(
				'competition_id' => $root,
				'label'          => $label,
				'matches'        => $pending,
			);
		}

		// Stable order: competitions with kickoff soonest first.
		usort(
			$buckets,
			static fn ( array $a, array $b ): int =>
				(int) ( $a['matches'][0]['kickoff_ts'] ?? PHP_INT_MAX )
				<=> (int) ( $b['matches'][0]['kickoff_ts'] ?? PHP_INT_MAX )
		);

		return $buckets;
	}

	private static function handle_leaderboard( array $identity ): array {
		$user      = '' !== $identity['phone'] ? Mantia_Repository::find_user_by_phone( $identity['phone'] ) : null;
		$active_id = $user ? Mantia_Repository::active_group_id_for_user( (int) $user->ID ) : 0;
		if ( $active_id <= 0 ) {
			$noun   = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
			$indef  = Mantia_Vocab::word( 'article_indef', $identity['phone'] ?? '' );
			$activa = Mantia_Vocab::word( 'active_adj', $identity['phone'] ?? '' );
			$nueva  = Mantia_Vocab::word( 'new_adj', $identity['phone'] ?? '' );
			return array(
				'reply'     => sprintf( 'No tenes %1$s %2$s %3$s. Mandame un código o creá %1$s con *%4$s %2$s <nombre>*.', $indef, $noun, $activa, $nueva ),
				'completed' => true,
			);
		}

		$group = Mantia_Repository::group_to_array( $active_id );
		$rows  = Mantia_Leaderboard::rows( $active_id, 20 );

		if ( empty( $rows ) ) {
			// Pre-results state: instead of dropping a URL (intimidante per
			// stakeholder feedback), surface what's PENDIENTE so the user
			// has an actionable next step inline. Count un-predicted matches
			// in the active group's competition.
			$comp_id  = Mantia_Repository::group_competition_id( $active_id );
			$upcoming = '' !== $comp_id
				? Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 14 )
				: array();
			$pending  = 0;
			foreach ( $upcoming as $m ) {
				if ( ! Mantia_Repository::find_prediction( (int) $user->ID, (int) $m['id'], $active_id ) ) {
					$pending++;
				}
			}
			$tail = $pending > 0
				? sprintf( "\n\nTe faltan *%d* %s. Mandame *pendientes* para pronosticarlos.", $pending, $pending === 1 ? 'pronóstico' : 'pronósticos' )
				: '';
			return array(
				'reply'     => sprintf( "*%s*\n\nTodavía no hay puntos. Después de que se resuelvan los primeros partidos, aparecen acá.%s", $group['name'], $tail ),
				'completed' => true,
			);
		}

		$lines = array( sprintf( '*Tabla — %s*', $group['name'] ), '' );
		$me_id = (int) $user->ID;
		foreach ( $rows as $row ) {
			$marker = (int) $row['user_id'] === $me_id ? ' ⬅' : '';
			$lines[] = sprintf( '%d. %s — %d pts (%d exactos)%s', $row['rank'], $row['name'], $row['points'], $row['exacts'], $marker );
		}

		$group_url = Mantia_Repository::group_view_url( $active_id, (int) $user->ID );
		if ( '' !== $group_url ) {
			$lines[] = '';
			$lines[] = sprintf( '🌐 Tabla completa en la web: %s', $group_url );
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array(
						'id' => 'mantia:cmd:matches',
						'title' => '📅 Partidos',
					),
					array(
						'id' => 'mantia:cmd:my-predictions',
						'title' => '📝 Mis preds',
					),
					array(
						'id' => 'mantia:cmd:home',
						'title' => '🏠 Home',
					),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_my_predictions( array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array(
				'reply' => 'No pude identificar tu numero. Reintentá en un toque.',
				'completed' => true,
			);
		}
		$noun = Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' );
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array(
				'reply' => sprintf( 'Todavía no tenés %s.', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ),
				'completed' => true,
			);
		}
		$active_id = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
		if ( $active_id <= 0 ) {
			return array(
				'reply' => sprintf( 'No tenes %s %s.', $noun, Mantia_Vocab::word( 'active_adj', $identity['phone'] ?? '' ) ),
				'completed' => true,
			);
		}
		$history = Mantia_Repository::user_history( (int) $user->ID, $active_id );
		if ( empty( $history ) ) {
			return array(
				'reply'     => 'Todavía no cargaste pronósticos. Escribi *partidos* y elegí uno.',
				'completed' => true,
			);
		}

		$rows = array();
		foreach ( array_slice( $history, 0, 10 ) as $p ) {
			$m = $p['match'] ?? array();
			if ( empty( $m ) ) {
				continue;
			}
			$scored = ! empty( $p['scored'] ) ? sprintf( ' • %d pts', (int) $p['points'] ) : ' • pendiente';
			$rows[] = array(
				'id'          => 'mantia:match:' . (int) $m['id'],
				'title'       => self::format_matchup( (string) $m['home_team'], (string) $m['away_team'] ),
				'description' => sprintf( '%d-%d%s', (int) $p['home_score'], (int) $p['away_score'], $scored ),
			);
		}

		$me_url      = Mantia_Repository::user_view_url( (int) $user->ID );
		$reply_lines = array( 'Tus pronósticos:' );
		if ( '' !== $me_url ) {
			$reply_lines[] = '';
			$reply_lines[] = sprintf( '🌐 Todos en la web (link privado): %s', $me_url );
		}

		return array(
			'reply'       => implode( "\n", $reply_lines ),
			'interactive' => array(
				'type'         => 'list',
				'button_label' => 'Ver',
				'sections'     => array(
					array(
						'title' => 'Mis pronósticos',
						'rows' => $rows,
					),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_match_detail( int $match_id, array $identity ): array {
		$match = Mantia_Repository::match_to_array( $match_id );
		if ( empty( $match ) ) {
			return array(
				'reply' => 'No encuentro ese partido.',
				'completed' => true,
			);
		}

		$home_name = Mantia_Frontend::normalize_team_name( (string) $match['home_team'] );
		$away_name = Mantia_Frontend::normalize_team_name( (string) $match['away_team'] );
		$status    = (string) ( $match['status'] ?? 'scheduled' );

		$user      = '' !== $identity['phone'] ? Mantia_Repository::find_user_by_phone( $identity['phone'] ) : null;
		$user_id   = $user ? (int) $user->ID : 0;

		// First prediction across any of the user's pencas in this competition
		// — auto-routing writes the same score to all of them, so one read
		// reflects the full state.
		$comp_id        = (string) ( $match['competition_id'] ?? '' );
		$user_group_ids = $user_id > 0 && '' !== $comp_id
			? Mantia_Repository::user_groups_in_competition( $user_id, $comp_id )
			: array();
		$any_prediction = null;
		foreach ( $user_group_ids as $gid ) {
			$p = Mantia_Repository::find_prediction( $user_id, $match_id, (int) $gid );
			if ( $p ) {
				$any_prediction = $p;
				break;
			}
		}

		// Compact one-shot panel: title + meta on two lines, current state on
		// a third, ask for input on the fourth. The previous "panel" repeated
		// the matchup, restated the prediction, and added a verbose preamble —
		// when a user clicks then immediately types a new score, that whole
		// block lands as stale noise next to the "Anotado" confirm. Keeping
		// it tight so even a delayed-arrival doesn't bury the chat.
		$meta_bits = array( self::format_kickoff( (string) $match['kickoff_gmt'] ) );
		if ( ! empty( $match['phase'] ) ) {
			$meta_bits[] = Mantia_Frontend::normalize_phase( (string) $match['phase'] );
		}
		$lines = array(
			sprintf( '*%s vs %s*', $home_name, $away_name ),
			implode( ' · ', array_filter( $meta_bits ) ),
		);

		if ( 'finished' === $status && null !== $match['home_score'] && null !== $match['away_score'] ) {
			$lines[] = sprintf( 'Final: *%d-%d*', (int) $match['home_score'], (int) $match['away_score'] );
			if ( $any_prediction ) {
				$ph = (int) get_post_meta( (int) $any_prediction->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
				$pa = (int) get_post_meta( (int) $any_prediction->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
				$lines[] = sprintf( 'Tu pronóstico: *%d-%d*', $ph, $pa );
			}

			return array(
				'reply'       => implode( "\n", $lines ),
				'interactive' => array(
					'type'    => 'button',
					'buttons' => array(
						array( 'id' => 'mantia:cmd:matches', 'title' => '📅 Otros partidos' ),
						array( 'id' => 'mantia:cmd:pending', 'title' => '⏳ Pendientes' ),
						array( 'id' => 'mantia:cmd:home', 'title' => '🏠 Home' ),
					),
				),
				'completed' => true,
			);
		}

		// "scheduled" in meta status doesn't auto-flip to "in_progress" on
		// kickoff — status only changes when a workflow marks it finished.
		// So a match can read $status === 'scheduled' but be 2 hours into
		// the second half. Use the kickoff_ts vs now check to decide if
		// the picker should appear at all.
		$kickoff_ts    = (int) ( $match['kickoff_ts'] ?? 0 );
		$can_predict   = 'scheduled' === $status && $kickoff_ts > 0 && $kickoff_ts > time();
		$kicked_off    = 'scheduled' === $status && $kickoff_ts > 0 && $kickoff_ts <= time();

		if ( $can_predict ) {
			$current_score = null;
			if ( $any_prediction ) {
				$current_score = array(
					(int) get_post_meta( (int) $any_prediction->ID, Mantia_Repository::META_PRED_HOME_SCORE, true ),
					(int) get_post_meta( (int) $any_prediction->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true ),
				);
				$lines[] = sprintf( 'Pronóstico actual: *%d-%d* — tocá para cambiarlo.', $current_score[0], $current_score[1] );
			} else {
				$lines[] = 'Tocá un marcador o escribí el tuyo (ej *3-1*).';
			}
			if ( $user_id > 0 ) {
				self::stash_pending_match( $identity['phone'], $match_id );
			}

			return array(
				'reply'       => implode( "\n", $lines ),
				'interactive' => self::score_picker_payload( $match_id, $home_name, $away_name, $current_score ),
				'completed'   => true,
			);
		}

		// Match already kicked off (scheduled-in-meta but past kickoff_ts)
		// OR finished but unresolved. Show what the user predicted, mark
		// it as locked, and give nav buttons — no picker.
		if ( $kicked_off ) {
			$lines[] = '⏱️ Ya arrancó — pronóstico bloqueado.';
			if ( $any_prediction ) {
				$ph = (int) get_post_meta( (int) $any_prediction->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
				$pa = (int) get_post_meta( (int) $any_prediction->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
				$lines[] = sprintf( 'Tu pronóstico: *%d-%d*', $ph, $pa );
			}
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array( 'id' => 'mantia:cmd:matches', 'title' => '📅 Otros partidos' ),
					array( 'id' => 'mantia:cmd:pending', 'title' => '⏳ Pendientes' ),
					array( 'id' => 'mantia:cmd:home', 'title' => '🏠 Home' ),
				),
			),
			'completed' => true,
		);
	}

	/**
	 * Build the score-picker WhatsApp list for a scheduled match. 9 common
	 * scorelines cover ~85% of real football results; the 10th row is the
	 * "Otro" escape hatch that keeps the bare-score regex path intact for
	 * unusual scores like 4-3.
	 *
	 * The currently-predicted score is marked with ✓ in the row description
	 * — gives users a clear visual anchor when re-opening a match they've
	 * already predicted.
	 *
	 * @param array{0:int,1:int}|null $current_score The score the user already
	 *                                               picked, if any.
	 */
	private static function score_picker_payload( int $match_id, string $home_name, string $away_name, ?array $current_score ): array {
		$common = array(
			array( 0, 0 ),
			array( 1, 0 ),
			array( 0, 1 ),
			array( 1, 1 ),
			array( 2, 0 ),
			array( 0, 2 ),
			array( 2, 1 ),
			array( 1, 2 ),
			array( 2, 2 ),
		);

		$rows = array();
		foreach ( $common as $sc ) {
			$h    = $sc[0];
			$a    = $sc[1];
			$tag  = '';
			if ( null !== $current_score && $current_score[0] === $h && $current_score[1] === $a ) {
				$tag = '✓ ';
			}
			$desc = self::truncate_title(
				$tag . self::score_outcome_label( $h, $a, $home_name, $away_name ),
				72
			);
			$rows[] = array(
				'id'          => sprintf( 'mantia:score:%d:%d-%d', $match_id, $h, $a ),
				'title'       => sprintf( '%d - %d', $h, $a ),
				'description' => $desc,
			);
		}
		$rows[] = array(
			'id'          => sprintf( 'mantia:score:%d:other', $match_id ),
			'title'       => '📝 Otro marcador',
			'description' => 'Escribilo (ej *3-1*)',
		);

		return array(
			'type'         => 'list',
			'button_label' => 'Elegir marcador',
			'sections'     => array(
				array(
					'title' => 'Marcadores comunes',
					'rows'  => $rows,
				),
			),
		);
	}

	private static function score_outcome_label( int $h, int $a, string $home, string $away ): string {
		if ( $h === $a ) {
			return 0 === $h ? 'Empate sin goles' : sprintf( 'Empate %d-%d', $h, $a );
		}
		return sprintf( 'Gana %s', $h > $a ? $home : $away );
	}

	private static function pending_match_key( string $phone ): string {
		return 'mantia_pending_match_' . md5( $phone );
	}

	/**
	 * Score the user typed before any match was in context. We park it
	 * here for ~5 min and apply it automatically the next time they tap
	 * a match — closes the "bare score then no idea which match" gap
	 * that the QA cycle flagged as a silent-fail blocker.
	 */
	private static function pending_score_key( string $phone ): string {
		return 'mantia_pending_score_' . md5( $phone );
	}

	private static function stash_pending_match( string $phone, int $match_id ): void {
		if ( '' === $phone || $match_id <= 0 ) {
			return;
		}
		set_transient( self::pending_match_key( $phone ), $match_id, 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * Save a prediction for a previously-stashed match using a bare score
	 * reply ("2-1"). Routes through Mantia_Abilities::register_prediction
	 * so auto-routing across the user's pencas in this competition is
	 * applied identically to the natural-language path.
	 */
	private static function handle_quick_score( int $match_id, int $home, int $away, array $identity ): array {
		delete_transient( self::pending_match_key( $identity['phone'] ) );

		// Reject edits on a match that's already kicked off. Reading the
		// kickoff once is cheaper than letting register_prediction race
		// the live state. Same one-line check production-quality bots
		// reach for; we'd rather a 1-line "ya arrancó" than a stealthy
		// rewrite of a prediction during the match.
		$match = Mantia_Repository::match_to_array( $match_id );
		if ( ! empty( $match['kickoff_gmt'] ) ) {
			$kickoff_ts = strtotime( (string) $match['kickoff_gmt'] . ( str_ends_with( (string) $match['kickoff_gmt'], 'Z' ) ? '' : ' UTC' ) );
			if ( false !== $kickoff_ts && $kickoff_ts <= time() ) {
				return array(
					'reply'     => sprintf(
						'⏱️ *%s vs %s* ya arrancó — no podés cambiar el pronóstico.',
						Mantia_Frontend::normalize_team_name( (string) ( $match['home_team'] ?? '' ) ),
						Mantia_Frontend::normalize_team_name( (string) ( $match['away_team'] ?? '' ) )
					),
					'completed' => true,
				);
			}
		}

		$args = array(
			'user_phone'         => $identity['phone'],
			'user_name'          => $identity['name'],
			'whatsapp_recipient' => $identity['recipient'],
			'match_id'           => $match_id,
			'home_score'         => max( 0, $home ),
			'away_score'         => max( 0, $away ),
		);
		$result = Mantia_Abilities::register_prediction( $args );

		if ( is_wp_error( $result ) ) {
			return array(
				'reply' => $result->get_error_message(),
				'completed' => true,
			);
		}

		$match    = (array) ( $result['match'] ?? array() );
		$groups   = (array) ( $result['groups'] ?? array() );
		$names    = array_filter( array_map( static fn ( $g ): string => (string) ( is_array( $g ) ? ( $g['name'] ?? '' ) : '' ), $groups ) );
		$home_t   = (string) ( $match['home_team'] ?? '' );
		$away_t   = (string) ( $match['away_team'] ?? '' );
		$where    = empty( $names )
			? ''
			: sprintf( ' en %s', implode( ', ', array_map( static fn ( string $n ): string => '*' . $n . '*', $names ) ) );

		// Close the loop: tell the user how many predictions they still owe
		// across all of their pencas. Reuses pending_by_competition() — same
		// number the user would see on /pendientes — so the count is exact.
		$user             = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		$pending_total    = 0;
		if ( $user ) {
			$buckets = self::pending_by_competition( (int) $user->ID, 24 * 60 );
			foreach ( $buckets as $b ) {
				$pending_total += count( $b['matches'] );
			}
		}
		$tail = $pending_total > 0
			? sprintf(
				"\n\nTe %s *%d* sin pronóstico.",
				1 === $pending_total ? 'queda' : 'quedan',
				$pending_total
			)
			: "\n\n🎯 Ya pronosticaste todos los pendientes.";

		// Append a web link to the first penca where the prediction landed.
		// auto-routing may have fanned the score to multiple groups; one
		// link is enough — the user lands on a page that links to the rest.
		$first_group_id = 0;
		foreach ( $groups as $g ) {
			if ( is_array( $g ) && ! empty( $g['id'] ) ) {
				$first_group_id = (int) $g['id'];
				break;
			}
		}
		$user_id_for_link = $user ? (int) $user->ID : 0;
		$group_url        = $first_group_id > 0
			? Mantia_Repository::group_view_url( $first_group_id, $user_id_for_link )
			: '';
		if ( '' !== $group_url ) {
			$tail .= sprintf( "\n\n🌐 Ver en la web: %s", $group_url );
		}

		$buttons = $pending_total > 0
			? array(
				array( 'id' => 'mantia:cmd:pending', 'title' => '⏳ Pendientes' ),
				array( 'id' => 'mantia:cmd:matches', 'title' => '📅 Otros partidos' ),
				array( 'id' => 'mantia:cmd:home', 'title' => '🏠 Resumen' ),
			)
			: array(
				array( 'id' => 'mantia:cmd:leaderboard', 'title' => '📊 Tabla' ),
				array( 'id' => 'mantia:cmd:matches', 'title' => '📅 Próximos' ),
				array( 'id' => 'mantia:cmd:home', 'title' => '🏠 Resumen' ),
			);

		return array(
			'reply'       => sprintf( '✅ Anotado%s: %s %d - %d %s%s', $where, $home_t, $home, $away, $away_t, $tail ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => $buttons,
			),
			'completed' => true,
		);
	}

	private static function format_kickoff( string $gmt ): string {
		if ( '' === $gmt ) {
			return '';
		}
		$ts = strtotime( $gmt . ( str_ends_with( $gmt, 'Z' ) ? '' : ' UTC' ) );
		if ( false === $ts ) {
			return $gmt;
		}
		// Display in Uruguay time (UTC-3) for now; future: per-user timezone.
		// Hardcode the Spanish tables instead of trusting wp_date() — prod's
		// WP locale is en_US (mantia3.wpcomstaging.com on wpcom Atomic), so
		// wp_date would return "Tue 19 May" even after R3 supposedly fixed
		// this. Don Roberto cited the English days as voz-rota in R4. Mantia
		// is monolingual Spanish; hardcode and ship.
		static $days   = array( 'dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb' );
		static $months = array( 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic' );

		// For matches within the next 24-48h, "hoy" / "mañana" reads infinitely
		// better than the absolute day-of-week. Use midnight-anchored deltas
		// instead of raw 24h windows so a 23:00 match still counts as today,
		// and a 00:30 match counts as today right up until midnight.
		$local_ts       = $ts - 3 * HOUR_IN_SECONDS;
		$now_local      = time() - 3 * HOUR_IN_SECONDS;
		$today_midnight = (int) strtotime( gmdate( 'Y-m-d', $now_local ) . ' 00:00:00 UTC' );
		$match_midnight = (int) strtotime( gmdate( 'Y-m-d', $local_ts ) . ' 00:00:00 UTC' );
		$delta_days     = (int) round( ( $match_midnight - $today_midnight ) / DAY_IN_SECONDS );
		$time           = gmdate( 'H:i', $local_ts );

		if ( 0 === $delta_days ) {
			return sprintf( 'hoy • %s', $time );
		}
		if ( 1 === $delta_days ) {
			return sprintf( 'mañana • %s', $time );
		}
		if ( -1 === $delta_days ) {
			return sprintf( 'ayer • %s', $time );
		}

		$dow = (int) gmdate( 'w', $local_ts );        // 0 = Sunday
		$d   = (int) gmdate( 'j', $local_ts );
		$m   = (int) gmdate( 'n', $local_ts ) - 1;
		return sprintf( '%s %d %s • %s', $days[ $dow ], $d, $months[ $m ], $time );
	}

	/**
	 * Per-phone fixed-window throttle. Returns a deterministic reply payload
	 * when the sender is over budget, or null to let processing continue.
	 *
	 * Defaults: 20 turns / 60s. Filterable:
	 *   - `mantia_rate_limit_max` (int)
	 *   - `mantia_rate_limit_window_seconds` (int)
	 */
	private static function rate_limit_check( string $phone ): ?array {
		// Bumped from 20 → 40 per minute after QA round 2 hit the cap on a
		// legitimate "create 3 pencas + send 1 score" pattern (each command
		// is multiple deterministic round-trips). Filter still lets admins
		// tune. Cost is bounded — these turns don't invoke the LLM.
		$max    = (int) apply_filters( 'mantia_rate_limit_max', 40 );
		$window = (int) apply_filters( 'mantia_rate_limit_window_seconds', 60 );

		if ( $max <= 0 || $window <= 0 ) {
			return null;
		}

		$key   = 'mantia_rl_' . md5( $phone );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return array(
				'reply'     => sprintf(
					'Estás mandando muchos mensajes en poco tiempo. Esperá ~%ds y volvé a intentar.',
					$window
				),
				'completed' => true,
			);
		}

		set_transient( $key, $count + 1, $window );
		return null;
	}

	private static function identity_from_turn( array $turn ): array {
		$runtime_context = isset( $turn['runtime_context'] ) && is_array( $turn['runtime_context'] )
			? $turn['runtime_context']
			: array();
		$client_context  = isset( $runtime_context['client_context'] ) && is_array( $runtime_context['client_context'] )
			? $runtime_context['client_context']
			: array();
		$sender_id       = (string) ( $client_context['sender_id'] ?? $client_context['external_conversation_id'] ?? '' );

		return array(
			'phone'     => $sender_id,
			'recipient' => $sender_id,
			'name'      => (string) ( $client_context['sender_name'] ?? $client_context['display_name'] ?? '' ),
		);
	}
}
