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
					return self::handle_help();
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

		// Bare "crear / nueva penca" with no name kicks off the
		// competition-first flow (same destination as the home menu button).
		if ( preg_match( '/^(?:crear|nueva|new)(?:\s+penca)?\s*$/iu', $lc ) ) {
			return self::handle_new_penca_start( $identity );
		}

		if ( preg_match( '/^(?:nueva|crear|create|new)\s+penca\s+(.+)$/iu', $plain, $m ) ) {
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

		if ( preg_match( '/^(?:me\s+llamo|mi\s+nombre\s+es|llamame|llamame|decime)\s+(.+)$/iu', $plain, $m ) ) {
			return self::handle_set_name( trim( (string) $m[1] ), $identity );
		}

		if ( preg_match( '/^(?:mis\s+grupos?|mis\s+pencas?|grupos?|pencas?)$/i', $lc ) ) {
			return self::handle_my_groups( $identity );
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

		if ( preg_match( '/^(?:mis\s+pronostic[oa]s?|mis\s+preds?|historial|mi\s+historial|jugadas|mis\s+jugadas)$/i', $lc ) ) {
			return self::handle_my_predictions( $identity );
		}

		if ( preg_match( '/^(?:ayuda|help|menu|comandos|\?|\/help|\/)$/i', $lc ) ) {
			return self::handle_help();
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
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$user_id = Mantia_Repository::get_or_create_user( $identity['phone'], $name, $identity['recipient'] );
		if ( 0 === $user_id ) {
			return array( 'reply' => 'No pude guardar tu nombre. Reintentá.', 'completed' => true );
		}

		return array(
			'reply'       => sprintf( '¡Hola, %s! Quedó guardado.', $name ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array( 'id' => 'mantia:cmd:home',     'title' => '🏠 Resumen' ),
					array( 'id' => 'mantia:cmd:matches',  'title' => '📅 Partidos' ),
					array( 'id' => 'mantia:cmd:help',     'title' => '❓ Ayuda' ),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_switch_group( int $group_id, array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array( 'reply' => 'Todavia no estas en ninguna penca.', 'completed' => true );
		}
		$result = Mantia_Repository::set_active_group_for_user( (int) $user->ID, $group_id );
		if ( is_wp_error( $result ) ) {
			return array( 'reply' => $result->get_error_message(), 'completed' => true );
		}
		$active = $result['active_group'];
		return array(
			'reply'       => sprintf( 'Listo, ahora tu penca activa es *%s*.', $active['name'] ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array( 'id' => 'mantia:cmd:home',       'title' => '🏠 Resumen' ),
					array( 'id' => 'mantia:cmd:share-link', 'title' => '📤 Invitar' ),
					array( 'id' => 'mantia:cmd:my-groups',  'title' => '📋 Mis pencas' ),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_join( WP_Post $group, array $identity ): array {
		$code   = (string) get_post_meta( (int) $group->ID, Mantia_Repository::META_INVITE_CODE, true );
		$result = Mantia_Repository::join_group( $identity['phone'], $code, $identity['name'], $identity['recipient'] );

		if ( is_wp_error( $result ) ) {
			return array( 'reply' => $result->get_error_message(), 'completed' => true );
		}

		$g     = $result['group'];
		$intro = ! empty( $result['already_member'] )
			? sprintf( 'Listo, cambie tu penca activa a *%s*.', $g['name'] )
			: sprintf( 'Listo, te sume a *%s*. Esa queda como tu penca activa.', $g['name'] );

		$share = '' !== ( $g['share_url'] ?? '' )
			? "\n\nPara invitar amigos, reenviá este link:\n" . $g['share_url']
			: '';

		return array(
			'reply'       => $intro . $share,
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array( 'id' => 'mantia:cmd:share-link', 'title' => '📤 Invitar' ),
					array( 'id' => 'mantia:cmd:home',       'title' => '🏠 Resumen' ),
					array( 'id' => 'mantia:cmd:my-groups',  'title' => '📋 Mis pencas' ),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_create_group( string $raw_name, array $identity ): array {
		$name = sanitize_text_field( $raw_name );
		if ( '' === $name ) {
			return array(
				'reply'     => 'Decime como se llama la penca. Ejemplo: *nueva penca La Familia*.',
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
				'header'       => 'Nueva penca',
				'button_label' => 'Elegir torneo',
				'sections'     => array(
					array( 'title' => 'Competencias', 'rows' => $rows ),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_competition_chosen( string $competition_id, array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$competition = Mantia_Competitions::get( $competition_id );
		if ( ! $competition ) {
			return array( 'reply' => 'Esa competencia no existe. Probá *nueva penca <nombre>* otra vez.', 'completed' => true );
		}

		$name_key = self::pending_create_key( $identity['phone'] );
		$name     = (string) get_transient( $name_key );
		if ( '' === $name ) {
			return array(
				'reply'     => 'No tengo nombre de penca pendiente. Escribí *nueva penca <nombre>* otra vez y vuelvo a preguntar el torneo.',
				'completed' => true,
			);
		}
		delete_transient( $name_key );

		$group_id = Mantia_Repository::create_group( $name, '', '', $competition_id );
		if ( $group_id <= 0 ) {
			return array( 'reply' => 'No pude crear esa penca. Probá con otro nombre.', 'completed' => true );
		}

		$group = Mantia_Repository::group_to_array( $group_id );
		Mantia_Repository::join_group(
			$identity['phone'],
			$group['invite_code'],
			$identity['name'],
			$identity['recipient']
		);

		$share = '' !== ( $group['share_url'] ?? '' )
			? "\n\nReenvía este link a quien quieras sumar:\n" . $group['share_url']
			: "\n\nQue tus amigos manden este codigo al bot: *" . $group['invite_code'] . '*';

		$reply = sprintf(
			"Creé *%s* para %s. Ya estás dentro.%s",
			$group['name'],
			$group['competition_name'],
			$share
		);

		return array(
			'reply'       => $reply,
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array( 'id' => 'mantia:cmd:share-link',  'title' => '📤 Compartir' ),
					array( 'id' => 'mantia:cmd:matches',     'title' => '📅 Partidos' ),
					array( 'id' => 'mantia:cmd:help',        'title' => '❓ Ayuda' ),
				),
			),
			'completed' => true,
		);
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

		return array(
			'reply'       => 'Genial! Empecemos por el torneo de tu penca:',
			'interactive' => array(
				'type'         => 'list',
				'header'       => 'Nueva penca',
				'button_label' => 'Elegir torneo',
				'sections'     => array(
					array( 'title' => 'Competencias', 'rows' => $rows ),
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
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$competition = Mantia_Competitions::get( $competition_id );
		if ( ! $competition ) {
			return array( 'reply' => 'Esa competencia ya no existe. Probá *crear* otra vez.', 'completed' => true );
		}

		set_transient(
			self::pending_comp_key( $identity['phone'] ),
			$competition_id,
			15 * MINUTE_IN_SECONDS
		);

		$label = trim( ( $competition['emoji'] ?? '' ) . ' ' . $competition['name'] );
		return array(
			'reply'     => sprintf(
				"*%s* — ¿cómo se va a llamar tu penca?\n\nMandame un nombre cortito (ej: *Amigos del Faso*) y la creo. Mandá *cancelar* si cambiaste de idea.",
				$label
			),
			'completed' => true,
		);
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
				'reply'     => 'Decime un nombre cortito (max 80 chars). Ej: *Amigos del Faso*.',
				'completed' => true,
			);
		}

		$competition = Mantia_Competitions::get( $competition_id );
		if ( ! $competition ) {
			delete_transient( self::pending_comp_key( $identity['phone'] ) );
			return array( 'reply' => 'Esa competencia ya no existe. Probá *crear* otra vez.', 'completed' => true );
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

		// Allow direct slug ("crear penca mundial-2026").
		if ( null !== Mantia_Competitions::get( $h ) ) {
			return $h;
		}

		$aliases = array(
			'mundial-2026'        => array( 'mundial', 'world cup', 'copa del mundo', 'fifa', 'mundial 2026' ),
			'libertadores-semana' => array( 'libertadores semana', 'libertadores de esta semana', 'libertadores esta semana', 'libertadores semanal' ),
			'libertadores-2026'   => array( 'libertadores', 'copa libertadores', 'libertadores completa', 'libertadores 2026' ),
			'sudamericana-2026'   => array( 'sudamericana', 'copa sudamericana', 'sudamericana 2026' ),
			'liga-uy-2026'        => array( 'liga uy', 'liga uruguaya', 'liga uruguay', 'liga-uy', 'campeonato uruguayo', 'liga uy 2026', 'auf' ),
		);

		foreach ( $aliases as $id => $list ) {
			foreach ( $list as $alias ) {
				if ( false !== strpos( $h, $alias ) && null !== Mantia_Competitions::get( $id ) ) {
					return $id;
				}
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

	private static function handle_my_groups( array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array(
				'reply'     => 'No pude identificar tu numero de WhatsApp. Reintentá en un toque.',
				'completed' => true,
			);
		}

		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array(
				'reply'       => 'Todavia no estas en ninguna penca.',
				'interactive' => array(
					'type'    => 'button',
					'buttons' => array(
						array( 'id' => 'mantia:cmd:new-penca',  'title' => '➕ Crear penca' ),
						array( 'id' => 'mantia:cmd:have-code',  'title' => '🔑 Tengo código' ),
						array( 'id' => 'mantia:cmd:help',       'title' => '❓ Ayuda' ),
					),
				),
				'completed' => true,
			);
		}

		$groups = Mantia_Repository::user_groups_to_array( (int) $user->ID );
		if ( empty( $groups ) ) {
			return array(
				'reply'       => 'Todavia no estas en ninguna penca.',
				'interactive' => array(
					'type'    => 'button',
					'buttons' => array(
						array( 'id' => 'mantia:cmd:new-penca',  'title' => '➕ Crear penca' ),
						array( 'id' => 'mantia:cmd:have-code',  'title' => '🔑 Tengo código' ),
					),
				),
				'completed' => true,
			);
		}

		// 2+ groups → list selector. Single group → text-only summary with action buttons.
		if ( count( $groups ) >= 2 ) {
			$rows = array();
			foreach ( $groups as $g ) {
				$rows[] = array(
					'id'          => 'mantia:switch:' . (int) $g['id'],
					'title'       => ! empty( $g['is_active'] ) ? '✓ ' . $g['name'] : $g['name'],
					'description' => sprintf( 'código %s', $g['invite_code'] ),
				);
			}
			return array(
				'reply'       => 'Tus pencas — tocá una para que sea la activa:',
				'interactive' => array(
					'type'         => 'list',
					'button_label' => 'Ver pencas',
					'sections'     => array(
						array( 'title' => 'Mis pencas', 'rows' => $rows ),
					),
				),
				'completed' => true,
			);
		}

		$g = $groups[0];
		return array(
			'reply'       => sprintf( "Tu única penca: *%s* (código `%s`).", $g['name'], $g['invite_code'] ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array( 'id' => 'mantia:cmd:share-link', 'title' => '📤 Invitar' ),
					array( 'id' => 'mantia:cmd:home',       'title' => '🏠 Resumen' ),
					array( 'id' => 'mantia:cmd:new-penca',  'title' => '➕ Crear otra' ),
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
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array(
				'reply'     => 'Todavia no estas en ninguna penca. Creá una con *nueva penca <nombre>* o entrá a una con su codigo.',
				'completed' => true,
			);
		}
		$active = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
		if ( $active <= 0 ) {
			return array(
				'reply'     => 'No tenes una penca activa. Mandame un codigo o creá una con *nueva penca <nombre>*.',
				'completed' => true,
			);
		}
		$group = Mantia_Repository::group_to_array( $active );
		$share = (string) ( $group['share_url'] ?? '' );
		$view  = (string) ( $group['view_url'] ?? '' );

		$lines = array( sprintf( '*%s* (%s)', $group['name'], $group['competition_name'] ?? '' ) );
		if ( '' !== $share ) {
			$lines[] = '';
			$lines[] = '🤝 Invitar amigos (1 toque):';
			$lines[] = $share;
		} else {
			$lines[] = sprintf( 'Codigo: *%s*', $group['invite_code'] );
		}
		if ( '' !== $view ) {
			$lines[] = '';
			$lines[] = '🌐 Ver standings en la web:';
			$lines[] = $view;
		}

		return array( 'reply' => implode( "\n", $lines ), 'completed' => true );
	}

	private static function handle_home( array $identity ): array {
		$user = '' !== $identity['phone'] ? Mantia_Repository::find_user_by_phone( $identity['phone'] ) : null;
		if ( ! $user ) {
			return array(
				'reply'       => "Hola! Soy *Mantia*, tu penca mundialista por WhatsApp.\n\n¿Por dónde arrancamos?",
				'interactive' => array(
					'type'    => 'button',
					'buttons' => array(
						array( 'id' => 'mantia:cmd:new-penca', 'title' => '➕ Crear penca' ),
						array( 'id' => 'mantia:cmd:have-code', 'title' => '🔑 Tengo código' ),
						array( 'id' => 'mantia:cmd:help',      'title' => '❓ Ayuda' ),
					),
				),
				'completed' => true,
			);
		}

		$user_id   = (int) $user->ID;
		$groups    = Mantia_Repository::user_groups_to_array( $user_id );
		$active_id = Mantia_Repository::active_group_id_for_user( $user_id );
		$active    = $active_id > 0 ? Mantia_Repository::group_to_array( $active_id ) : array();
		$upcoming  = Mantia_Repository::upcoming_matches( 48 );
		$standings = $active_id > 0 ? Mantia_Leaderboard::rows( $active_id, 3 ) : array();

		$lines = array();
		if ( ! empty( $active['name'] ) ) {
			$lines[] = sprintf( 'Penca activa: *%s*', $active['name'] );
			$lines[] = sprintf( '%s • codigo `%s`', $active['competition_name'] ?? '', $active['invite_code'] );
		} else {
			$lines[] = 'Todavia no tenes penca activa.';
		}

		if ( count( $groups ) > 1 ) {
			$lines[] = sprintf( 'Tenes %d pencas en total. Escribi *mis grupos* para verlas.', count( $groups ) );
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
				$lines[] = sprintf( '  • %s vs %s%s', $m['home_team'], $m['away_team'], $kickoff );
			}
		}

		$buttons = array(
			array( 'id' => 'mantia:cmd:matches',     'title' => '📅 Partidos' ),
			array( 'id' => 'mantia:cmd:pending',     'title' => '⏳ Pendientes' ),
			array( 'id' => 'mantia:cmd:leaderboard', 'title' => '📊 Tabla' ),
		);

		$me_url = Mantia_Repository::user_view_url( $user_id );
		if ( '' !== $me_url ) {
			$lines[] = '';
			$lines[] = '🌐 Ver tus pencas en la web (link privado):';
			$lines[] = $me_url;
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array( 'type' => 'button', 'buttons' => $buttons ),
			'completed'   => true,
		);
	}

	private static function handle_help(): array {
		$lines = array(
			'*Mantia* — penca mundialista por WhatsApp.',
			'',
			'*Pencas*',
			'• *nueva penca <nombre>* — crear penca y obtener link',
			'• <codigo> — sumate a una penca (ej: `FAMILIA2026`)',
			'• *mis grupos* — lista tus pencas',
			'• *link* — compartir la penca activa',
			'',
			'*Partidos*',
			'• *partidos* — ver fixture con tu pronostico al lado',
			'• *pendientes* — partidos sin pronostico',
			'• *mis pronosticos* — historial tuyo',
			'• `Uruguay 2 Portugal 1` — registrar pronostico (con IA)',
			'',
			'*Penca activa*',
			'• *tabla* — ranking de la penca',
			'• *hola* / *home* — resumen general',
		);
		return array( 'reply' => implode( "\n", $lines ), 'completed' => true );
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
			return array( 'reply' => $msg, 'completed' => true );
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
				'title'       => sprintf( '%s vs %s', $m['home_team'], $m['away_team'] ),
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
					array( 'title' => 'Proximos partidos', 'rows' => $rows ),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_pending( array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array(
				'reply'     => 'Primero unite a una penca con su codigo.',
				'completed' => true,
			);
		}
		$active_id = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
		if ( $active_id <= 0 ) {
			return array(
				'reply'     => 'No tenes penca activa. Mandame un codigo o creá una con *nueva penca <nombre>*.',
				'completed' => true,
			);
		}

		$comp_id  = Mantia_Repository::group_competition_id( $active_id );
		$upcoming = Mantia_Repository::upcoming_matches_for_competition( $comp_id, 24 * 60 );
		$pending  = array();
		foreach ( $upcoming as $m ) {
			if ( ! Mantia_Repository::find_prediction( (int) $user->ID, (int) $m['id'], $active_id ) ) {
				$pending[] = $m;
			}
		}

		if ( empty( $pending ) ) {
			return array(
				'reply'     => '✅ Tenes todos los partidos pronosticados. ¡Bien ahi!',
				'completed' => true,
			);
		}

		$rows = array();
		foreach ( array_slice( $pending, 0, 10 ) as $m ) {
			$rows[] = array(
				'id'          => 'mantia:match:' . (int) $m['id'],
				'title'       => sprintf( '%s vs %s', $m['home_team'], $m['away_team'] ),
				'description' => self::format_kickoff( (string) $m['kickoff_gmt'] ),
			);
		}

		return array(
			'reply'       => sprintf( 'Te faltan *%d* pronosticos:', count( $pending ) ),
			'interactive' => array(
				'type'         => 'list',
				'button_label' => 'Cargar uno',
				'sections'     => array(
					array( 'title' => 'Sin pronostico', 'rows' => $rows ),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_leaderboard( array $identity ): array {
		$user      = '' !== $identity['phone'] ? Mantia_Repository::find_user_by_phone( $identity['phone'] ) : null;
		$active_id = $user ? Mantia_Repository::active_group_id_for_user( (int) $user->ID ) : 0;
		if ( $active_id <= 0 ) {
			return array(
				'reply'     => 'No tenes penca activa. Mandame un codigo o creá una con *nueva penca <nombre>*.',
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
					array( 'id' => 'mantia:cmd:matches',         'title' => '📅 Partidos' ),
					array( 'id' => 'mantia:cmd:my-predictions',  'title' => '📝 Mis preds' ),
					array( 'id' => 'mantia:cmd:home',            'title' => '🏠 Home' ),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_my_predictions( array $identity ): array {
		if ( '' === $identity['phone'] ) {
			return array( 'reply' => 'No pude identificar tu numero. Reintentá en un toque.', 'completed' => true );
		}
		$user = Mantia_Repository::find_user_by_phone( $identity['phone'] );
		if ( ! $user ) {
			return array( 'reply' => 'Todavia no estas en ninguna penca.', 'completed' => true );
		}
		$active_id = Mantia_Repository::active_group_id_for_user( (int) $user->ID );
		if ( $active_id <= 0 ) {
			return array( 'reply' => 'No tenes penca activa.', 'completed' => true );
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
				'title'       => sprintf( '%s vs %s', $m['home_team'], $m['away_team'] ),
				'description' => sprintf( '%d-%d%s', (int) $p['home_score'], (int) $p['away_score'], $scored ),
			);
		}

		return array(
			'reply'       => 'Tus pronosticos:',
			'interactive' => array(
				'type'         => 'list',
				'button_label' => 'Ver',
				'sections'     => array(
					array( 'title' => 'Mis pronosticos', 'rows' => $rows ),
				),
			),
			'completed' => true,
		);
	}

	private static function handle_match_detail( int $match_id, array $identity ): array {
		$match = Mantia_Repository::match_to_array( $match_id );
		if ( empty( $match ) ) {
			return array( 'reply' => 'No encuentro ese partido.', 'completed' => true );
		}

		$lines = array(
			sprintf( '*%s vs %s*', $match['home_team'], $match['away_team'] ),
			self::format_kickoff( (string) $match['kickoff_gmt'] ),
		);
		if ( ! empty( $match['phase'] ) ) {
			$lines[] = $match['phase'];
		}

		$status = (string) ( $match['status'] ?? 'scheduled' );
		if ( 'finished' === $status && null !== $match['home_score'] && null !== $match['away_score'] ) {
			$lines[] = sprintf( "\nResultado final: *%d-%d*", (int) $match['home_score'], (int) $match['away_score'] );
		}

		$user      = '' !== $identity['phone'] ? Mantia_Repository::find_user_by_phone( $identity['phone'] ) : null;
		$active_id = $user ? Mantia_Repository::active_group_id_for_user( (int) $user->ID ) : 0;
		$prediction = ( $user && $active_id > 0 ) ? Mantia_Repository::find_prediction( (int) $user->ID, $match_id, $active_id ) : null;

		if ( $prediction ) {
			$ph = (int) get_post_meta( (int) $prediction->ID, Mantia_Repository::META_PRED_HOME_SCORE, true );
			$pa = (int) get_post_meta( (int) $prediction->ID, Mantia_Repository::META_PRED_AWAY_SCORE, true );
			$lines[] = sprintf( "\nTu pronostico: *%d-%d*", $ph, $pa );
			if ( 'scheduled' === $status ) {
				$lines[] = 'Para cambiar, mandame un nuevo marcador. Ej: `' . $match['home_team'] . ' 3 ' . $match['away_team'] . ' 1`';
			}
		} elseif ( 'scheduled' === $status ) {
			$lines[] = "\nTodavia no cargaste pronostico.";
			$lines[] = 'Mandame el marcador, ej: `' . $match['home_team'] . ' 2 ' . $match['away_team'] . ' 1`';
		}

		return array(
			'reply'       => implode( "\n", $lines ),
			'interactive' => array(
				'type'    => 'button',
				'buttons' => array(
					array( 'id' => 'mantia:cmd:matches',  'title' => '📅 Otros partidos' ),
					array( 'id' => 'mantia:cmd:pending',  'title' => '⏳ Pendientes' ),
					array( 'id' => 'mantia:cmd:home',     'title' => '🏠 Home' ),
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
