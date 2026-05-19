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
		// "2-1", "2 1", "2:1", "2x1", "2 a 1". Falls through if it doesn't
		// look like a score so the LLM can still handle full "Team A 2 Team B 1".
		if ( '' !== $identity['phone'] && preg_match( '/^\s*(\d{1,2})\s*(?:\s+a\s+|[-:x\s])\s*(\d{1,2})\s*$/iu', $plain, $sc ) ) {
			$pending_match = (int) get_transient( self::pending_match_key( $identity['phone'] ) );
			if ( $pending_match > 0 ) {
				return self::handle_quick_score( $pending_match, (int) $sc[1], (int) $sc[2], $identity );
			}
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

		if ( preg_match( '/^(?:link|invitacion|compartir|share|invite)$/i', $lc ) ) {
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
			return array( 'reply' => 'Activá una penca primero (mandame *mis grupos* y tocá una).', 'completed' => true );
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

		// Format as a flat readable list, leading with the majority pick.
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
			'',
		);
		foreach ( $consensus as $score => $count ) {
			$lines[] = sprintf( '  *%s* — %d', $score, $count );
		}

		return array(
			'reply'     => implode( "\n", $lines ),
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
			return array(
				'reply'     => sprintf( 'No encontré partidos pendientes con *%s*. Quizás ya jugaron, o no están en tus pencas.', $canonical ),
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
				'reply' => sprintf( 'Todavia no tenés %s.', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ),
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
		$lines = array_merge( $lines, self::member_lines( $group_id, $me_id ) );

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array(
						'id' => 'mantia:cmd:share-link',
						'title' => '📤 Re-enviar',
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
		$lines[] = '_— Mantia, penca por WhatsApp_';
		unset( $comp ); // intentionally not used in the body
		return $lines;
	}

	/**
	 * Build the /penca/g/<token>/sumate/ landing URL when we have a view
	 * token on the group, otherwise empty. The landing handles OG preview
	 * for WhatsApp + 302 to wa.me.
	 */
	private static function build_join_landing_url( array $group ): string {
		$view_url = (string) ( $group['view_url'] ?? '' );
		if ( '' === $view_url ) {
			return '';
		}
		return rtrim( $view_url, '/' ) . '/sumate/';
	}

	/**
	 * Render a group's member list as text lines, marking the current
	 * user. Shared by share-link, post-create, post-switch, and post-join
	 * replies so the "who's already here?" answer is consistent across
	 * surfaces.
	 *
	 * @return array<int,string>
	 */
	private static function member_lines( int $group_id, int $current_user_id ): array {
		$members = Mantia_Repository::group_members( $group_id );
		if ( count( $members ) <= 1 ) {
			$lines = array( '👥 Solo vos por ahora. Pegale el link a alguien!' );
		} else {
			$lines = array( sprintf( '👥 Quiénes están (%d):', count( $members ) ) );
			foreach ( $members as $m ) {
				$marker  = (int) $m['id'] === $current_user_id ? ' _(vos)_' : '';
				$lines[] = sprintf( '  • %s%s', $m['display_name'], $marker );
			}
		}
		// Surface the user's private edit-link in every group-context reply
		// so they always have a one-tap path to /me/ where editing scores
		// is a tap, not a typing exercise. No-op if the user post hasn't
		// been provisioned yet (shouldn't happen post-join).
		if ( $current_user_id > 0 ) {
			$me_url = Mantia_Repository::user_view_url( $current_user_id );
			if ( '' !== $me_url ) {
				$lines[] = '';
				$lines[] = sprintf( '📱 Tu link privado: %s', $me_url );
			}
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
			'reply'       => sprintf( 'Genial! Empecemos por el torneo de tu %s:', $noun ),
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

		$empty_msg = sprintf( 'Todavia no tenés %s.', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) );

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

		// 2+ groups → list selector. We render the group names inline in
		// the text first so the user reads them immediately instead of
		// having to tap the list button to see what's inside.
		if ( count( $groups ) >= 2 ) {
			$rows  = array();
			$lines = array( sprintf( 'Estás en %d %s:', count( $groups ), Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ), '' );
			foreach ( $groups as $g ) {
				$marker     = ! empty( $g['is_active'] ) ? '✅' : '▫️';
				$comp_part  = isset( $g['competition_name'] ) && '' !== $g['competition_name']
					? ' · ' . $g['competition_name']
					: '';
				$lines[]    = sprintf( '%s *%s*%s', $marker, $g['name'], $comp_part );
				$rows[]     = array(
					'id'          => 'mantia:switch:' . (int) $g['id'],
					'title'       => self::truncate_title( ( ! empty( $g['is_active'] ) ? '✓ ' : '' ) . $g['name'], 24 ),
					'description' => self::truncate_title( (string) ( $g['competition_name'] ?? '' ), 72 ),
				);
			}
			$lines[] = '';
			$lines[] = '_Tocá una para hacerla la activa._';
			return array(
				'reply'       => implode( "\n", $lines ),
				'interactive' => array(
					'type'         => 'list',
					'button_label' => self::truncate_title( 'Cambiar ' . Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' ), 20 ),
					'sections'     => array(
						array(
							'title' => sprintf( 'Mis %s', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ),
							'rows' => $rows,
						),
					),
				),
				'completed' => true,
			);
		}

		$g = $groups[0];
		return array(
			'reply'       => sprintf( 'Tu %s: *%s* (código `%s`).', Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' ), $g['name'], $g['invite_code'] ),
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
						'id' => 'mantia:cmd:new-penca',
						'title' => '➕ Crear otra',
					),
				),
			),
			'completed' => true,
		);
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
				'reply'     => sprintf( 'Todavia no tenés %1$s. Creá %2$s con *%3$s %4$s <nombre>* o entrá con un código.', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ), Mantia_Vocab::word( 'article_indef', $identity['phone'] ?? '' ), Mantia_Vocab::word( 'new_adj', $identity['phone'] ?? '' ), $noun ),
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
		$view  = (string) ( $group['view_url'] ?? '' );

		// Push a fresh invitation card the user can long-press → Forward.
		// Lives in its own bubble (above the bot's reply with buttons) so a
		// forward carries only the card, not the bot context.
		self::send_invite_card( $identity['recipient'], $group );

		$lines = array(
			'_↑ Reenviá la tarjeta de arriba a tus amigos._',
			'',
			sprintf( '*%s* (%s)', $group['name'], $group['competition_name'] ?? '' ),
		);
		$lines[] = '';
		$lines   = array_merge( $lines, self::member_lines( $active, (int) $user->ID ) );

		if ( '' !== $share ) {
			$lines[] = '';
			$lines[] = '🤝 Invitar amigos (1 toque):';
			$lines[] = $share;
		} else {
			$lines[] = '';
			$lines[] = sprintf( 'Codigo: *%s*', $group['invite_code'] );
		}
		if ( '' !== $view ) {
			$lines[] = '';
			$lines[] = '🌐 Ver standings en la web:';
			$lines[] = $view;
		}

		return array(
			'reply' => implode( "\n", $lines ),
			'completed' => true,
		);
	}

	private static function handle_home( array $identity ): array {
		$user = '' !== $identity['phone'] ? Mantia_Repository::find_user_by_phone( $identity['phone'] ) : null;
		if ( ! $user ) {
			return array(
				'reply'       => "Hola! Soy *Mantia*, la app de pronósticos mundialistas por WhatsApp.\n\n¿Por dónde arrancamos?",
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
			$lines[] = sprintf( 'Activa: *%s*', $active['name'] );
			$lines[] = sprintf( '%s • codigo `%s`', $active['competition_name'] ?? '', $active['invite_code'] );
		} else {
			$lines[] = sprintf( 'Todavia no tenes %s %s.', Mantia_Vocab::word( 'noun', $identity['phone'] ?? '' ), Mantia_Vocab::word( 'active_adj', $identity['phone'] ?? '' ) );
		}

		if ( count( $groups ) > 1 ) {
			$lines[] = sprintf( 'Tenés %d %s en total. Escribí *mis grupos* para verlas.', count( $groups ), Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) );
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
			$lines[] = 'Proximos partidos (48h):';
			foreach ( array_slice( $upcoming, 0, 3 ) as $m ) {
				$kickoff = ! empty( $m['kickoff_gmt'] ) ? ' — ' . $m['kickoff_gmt'] . ' GMT' : '';
				$lines[] = sprintf(
					'  • %s vs %s%s',
					Mantia_Frontend::normalize_team_name( (string) $m['home_team'] ),
					Mantia_Frontend::normalize_team_name( (string) $m['away_team'] ),
					$kickoff
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

		$me_url = Mantia_Repository::user_view_url( $user_id );
		if ( '' !== $me_url ) {
			$lines[] = '';
			$lines[] = sprintf( '🌐 Ver tus %s en la web (link privado):', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) );
			$lines[] = $me_url;
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
			'*Mantia* — pronósticos mundialistas por WhatsApp.',
			'',
			'*' . $plural_cap . '*',
			sprintf( '• *%1$s %2$s <nombre>* — crear y obtener link', $new_adj, $noun ),
			sprintf( '• <código> — sumate a %s %s (ej: `FAMILIA2026`)', $indef, $noun ),
			sprintf( '• *mis grupos* — lista tus %s', $plural ),
			sprintf( '• *link* — compartir tu %s %s', $noun, $active_adj ),
			'',
			'*Partidos*',
			'• *partidos* — ver fixture con tu pronostico al lado',
			'• *pendientes* — partidos sin pronostico',
			'• *mis pronosticos* — historial tuyo',
			'• `Uruguay 2 Portugal 1` — registrar pronostico (con IA)',
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

		$rows = array();
		foreach ( array_slice( $upcoming, 0, 10 ) as $m ) {
			$predicted = '';
			if ( $user && $active_id > 0 ) {
				$p = Mantia_Repository::find_prediction( (int) $user->ID, (int) $m['id'], $active_id );
				if ( $p ) {
					$ph = (int) get_post_meta( (int) $p->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
					$pa = (int) get_post_meta( (int) $p->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
					$predicted = sprintf( '✓ %d-%d', $ph, $pa );
				} else {
					$predicted = '○ sin pronostico';
				}
			}
			$rows[] = array(
				'id'          => 'mantia:match:' . (int) $m['id'],
				'title'       => self::format_matchup( (string) $m['home_team'], (string) $m['away_team'] ),
				'description' => trim( self::format_kickoff( (string) $m['kickoff_gmt'] ) . ( $predicted ? ' • ' . $predicted : '' ) ),
			);
		}

		$header = $user && $active_id > 0
			? Mantia_Repository::group_to_array( $active_id )['competition_name']
			: 'Fixture';

		return array(
			'reply'       => 'Tocá un partido para ver detalle o cargar pronostico:',
			'interactive' => array(
				'type'         => 'list',
				'header'       => $header,
				'button_label' => 'Ver partidos',
				'sections'     => array(
					array(
						'title' => 'Proximos partidos',
						'rows' => $rows,
					),
				),
			),
			'completed' => true,
		);
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
				'reply'       => '✅ Tenés todos los partidos pronosticados. ¡Bien ahí!',
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
		$lines      = array( sprintf( 'Te faltan *%d* pronósticos:', $total ), '' );
		foreach ( $by_comp as $bucket ) {
			if ( empty( $bucket['matches'] ) ) {
				continue;
			}
			$lines[] = sprintf( '*%s* — %d partidos', $bucket['label'], count( $bucket['matches'] ) );
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
			return array(
				'reply'     => sprintf( "*%s*\n\nTodavia no hay puntos. Despues de que se resuelvan los primeros partidos, aparecen acá.", $group['name'] ),
				'completed' => true,
			);
		}

		$lines = array( sprintf( '*Tabla — %s*', $group['name'] ), '' );
		$me_id = (int) $user->ID;
		foreach ( $rows as $row ) {
			$marker = (int) $row['user_id'] === $me_id ? ' ⬅' : '';
			$lines[] = sprintf( '%d. %s — %d pts (%d exactos)%s', $row['rank'], $row['name'], $row['points'], $row['exacts'], $marker );
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
				'reply' => sprintf( 'Todavia no tenés %s.', Mantia_Vocab::word( 'plural', $identity['phone'] ?? '' ) ),
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
				'reply'     => 'Todavia no cargaste pronosticos. Escribi *partidos* y elegí uno.',
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

		return array(
			'reply'       => 'Tus pronosticos:',
			'interactive' => array(
				'type'         => 'list',
				'button_label' => 'Ver',
				'sections'     => array(
					array(
						'title' => 'Mis pronosticos',
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
		} elseif ( 'scheduled' === $status ) {
			if ( $any_prediction ) {
				$ph = (int) get_post_meta( (int) $any_prediction->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
				$pa = (int) get_post_meta( (int) $any_prediction->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
				$lines[] = sprintf( 'Pronóstico actual: *%d-%d* — mandame uno nuevo (ej *2-1*).', $ph, $pa );
			} else {
				$lines[] = 'Mandame el marcador. Ej *2-1*.';
			}
			if ( $user_id > 0 ) {
				self::stash_pending_match( $identity['phone'], $match_id );
			}
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array(
						'id' => 'mantia:cmd:matches',
						'title' => '📅 Otros partidos',
					),
					array(
						'id' => 'mantia:cmd:pending',
						'title' => '⏳ Pendientes',
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

	private static function pending_match_key( string $phone ): string {
		return 'mantia_pending_match_' . md5( $phone );
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

		return array(
			'reply'       => sprintf( '✅ Anotado%s: %s %d - %d %s', $where, $home_t, $home, $away, $away_t ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array(
						'id' => 'mantia:cmd:pending',
						'title' => '⏳ Más pendientes',
					),
					array(
						'id' => 'mantia:cmd:matches',
						'title' => '📅 Otros partidos',
					),
					array(
						'id' => 'mantia:cmd:home',
						'title' => '🏠 Resumen',
					),
				),
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
		return gmdate( 'D j M • H:i', $ts - 3 * HOUR_IN_SECONDS );
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
		$max    = (int) apply_filters( 'mantia_rate_limit_max', 20 );
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
