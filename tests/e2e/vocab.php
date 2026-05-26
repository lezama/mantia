<?php
/**
 * Mantia_Vocab smoke test.
 *
 * Verifies the per-country word lookup + site-default fallback so a
 * future tweak to DEFAULT_VOCAB or the option default doesn't silently
 * regress the bot copy in some region.
 *
 * Run via:
 *   wp eval-file tests/e2e/vocab.php
 *
 * Or from local:
 *   bin/e2e.sh vocab
 *
 * @package Mantia
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/lib.php';

Mantia_E2E::start( 'Mantia_Vocab country + default fallback' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '1. Phone-based vocab: UY → penca' );
/* ------------------------------------------------------------------------- */
$uy = Mantia_Vocab::for_phone( '+59899123456' );
Mantia_E2E::assert_eq( 'penca',       $uy['noun'],       'UY noun' );
Mantia_E2E::assert_eq( 'pencas',      $uy['plural'],     'UY plural' );
Mantia_E2E::assert_eq( 'Crear penca', $uy['create'],     'UY create CTA' );
Mantia_E2E::assert_eq( 'activa',      $uy['active_adj'], 'UY active_adj feminine' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '2. Phone-based vocab: AR → pronóstico' );
/* ------------------------------------------------------------------------- */
$ar = Mantia_Vocab::for_phone( '+5491141234567' );
Mantia_E2E::assert_eq( 'pronóstico',       $ar['noun'],       'AR noun' );
Mantia_E2E::assert_eq( 'pronósticos',      $ar['plural'],     'AR plural' );
Mantia_E2E::assert_eq( 'Crear pronóstico', $ar['create'],     'AR create CTA' );
Mantia_E2E::assert_eq( 'activo',           $ar['active_adj'], 'AR active_adj masculine' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '3. Phone-based vocab: BR → bolão' );
/* ------------------------------------------------------------------------- */
$br = Mantia_Vocab::for_phone( '+5511998765432' );
Mantia_E2E::assert_eq( 'bolão',       $br['noun'],       'BR noun' );
Mantia_E2E::assert_eq( 'bolões',      $br['plural'],     'BR plural' );
Mantia_E2E::assert_eq( 'Criar bolão', $br['create'],     'BR create CTA (criar, not crear)' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '4. Phone-based vocab: MX/CL/ES → pronóstico' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::assert_eq( 'pronóstico', Mantia_Vocab::word( 'noun', '+5215512345678' ), 'MX noun' );
Mantia_E2E::assert_eq( 'pronóstico', Mantia_Vocab::word( 'noun', '+56912345678' ),   'CL noun' );
Mantia_E2E::assert_eq( 'pronóstico', Mantia_Vocab::word( 'noun', '+34612345678' ),   'ES noun' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '5. Unknown country code → falls back to site default' );
/* ------------------------------------------------------------------------- */
// Test harness pins site to UY in start(), so an unrecognized phone
// (e.g. +1 US) falls back to UY/penca.
$us = Mantia_Vocab::for_phone( '+15551234567' );
Mantia_E2E::assert_eq( 'penca', $us['noun'], 'unknown country falls back to site default (UY/penca)' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '6. Site default with option unset → pronóstico (the new neutral default)' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::vocab_country( '' );
$site = Mantia_Vocab::for_site();
Mantia_E2E::assert_eq( 'pronóstico',  $site['noun'],   'unset option → pronóstico default' );
Mantia_E2E::assert_eq( 'pronósticos', $site['plural'], 'unset option → pronósticos plural' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '7. Site default with option=UY → penca (canonical UY install)' );
/* ------------------------------------------------------------------------- */
Mantia_E2E::vocab_country( 'UY' );
$site_uy = Mantia_Vocab::for_site();
Mantia_E2E::assert_eq( 'penca',  $site_uy['noun'],   'UY option → penca' );
Mantia_E2E::assert_eq( 'pencas', $site_uy['plural'], 'UY option → pencas' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '8. Phone vocab always wins over site default' );
/* ------------------------------------------------------------------------- */
// Even with site default forced to BR, an AR phone must still get pronóstico.
Mantia_E2E::vocab_country( 'BR' );
Mantia_E2E::assert_eq( 'pronóstico', Mantia_Vocab::word( 'noun', '+5491141234567' ), 'AR phone overrides BR site default' );
Mantia_E2E::assert_eq( 'penca',      Mantia_Vocab::word( 'noun', '+59899123456' ),   'UY phone overrides BR site default' );

/* ------------------------------------------------------------------------- */
Mantia_E2E::step( '9. word() graceful fallback for unknown keys' );
/* ------------------------------------------------------------------------- */
// Unknown key returns the key itself (never empty string) — protects
// against callers accidentally rendering a hole in the message.
Mantia_E2E::assert_eq( 'noun-typo', Mantia_Vocab::word( 'noun-typo', '+59899123456' ), 'unknown key returns key itself' );

// Restore the suite default before finishing.
Mantia_E2E::vocab_country( 'UY' );

Mantia_E2E::finish();
