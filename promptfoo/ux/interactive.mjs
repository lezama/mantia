/**
 * Mantia · UX detective — interactive layer.
 *
 * Drives Playwright through end-to-end click flows on mantia3 and
 * dumps per-step HTML to interactive_dumps/<scenario>/<step>.html
 * for promptfoo to assert against. Where the deterministic suite
 * tests static page render (one URL → one HTML), this layer tests
 * STATE TRANSITIONS (page A → click button → page B is correct).
 *
 * The scenarios are deliberately small — adding more is just an
 * entry in the SCENARIOS array. Each step is `{ description, action,
 * dump }`.
 *
 * Vars loaded from promptfoo/ux/vars/ (same vars the matrix fixture
 * emits). Run with:
 *
 *   bin/promptfoo-ux.sh setup-matrix
 *   node promptfoo/ux/interactive.mjs
 *
 * Or via the wrapper subcommand:
 *
 *   bin/promptfoo-ux.sh interactive
 */
import { chromium } from 'playwright';
import { readFileSync, mkdirSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const VARS_DIR  = join(__dirname, 'vars');
const DUMP_ROOT = join(__dirname, 'interactive_dumps');

function readVar(name) {
  try {
    return readFileSync(join(VARS_DIR, `${name}.txt`), 'utf8').trim();
  } catch {
    return '';
  }
}

const V = {
  BASE:        readVar('base_url') || 'https://mantia3.wpcomstaging.com',
  ALICE_SHARE: readVar('alice_share'),
  BOB_SHARE:   readVar('bob_share'),
  CAROL_SHARE: readVar('carol_share'),
  LIBE_VIEW:   readVar('libe_view'),
  LIBE_NAME:   readVar('libe_name'),
  LIBE_CODE:   readVar('libe_code'),
  MUN_VIEW:    readVar('mun_view'),
  MUN_NAME:    readVar('mun_name'),
  MUN_CODE:    readVar('mun_code'),
};

if (!V.ALICE_SHARE || !V.LIBE_VIEW) {
  console.error('ERROR: matrix vars missing. Run `bin/promptfoo-ux.sh setup-matrix` first.');
  process.exit(2);
}

/**
 * Each scenario is a sequence of steps. A step is either:
 *   { kind: 'goto', url, label }                 navigate
 *   { kind: 'click', selector, label }           click an element
 *   { kind: 'assert', label, fn }                custom JS assertion against page state
 *   { kind: 'dump', label }                      save current HTML to dumps
 */
const SCENARIOS = [
  {
    id: 'creator-flow',
    description: 'Alice (creator) navigates her own libertadores penca + competition',
    steps: [
      { kind: 'goto', url: `${V.BASE}/pronostico/g/${V.LIBE_VIEW}/?as=${V.ALICE_SHARE}`, label: '01-libe-as-alice' },
      { kind: 'dump', label: '01-libe-as-alice' },
      // CTA should be the member-facing "Invitar amigos · código X".
      { kind: 'assert', label: 'cta-is-invitar', fn: async (page) => {
        const cta = await page.locator('a.mantia-pill-primary').first().textContent();
        if (!cta?.includes('Invitar amigos')) throw new Error(`unexpected CTA text: ${cta}`);
        const href = await page.locator('a.mantia-pill-primary').first().getAttribute('href');
        if (!href?.startsWith('https://wa.me/')) throw new Error(`CTA href doesn't open WA share: ${href}`);
      } },
      // Tap the breadcrumb to the competition page — ?as= must survive.
      { kind: 'click', selector: 'a.mantia-crumb', label: '02-tap-breadcrumb' },
      { kind: 'dump', label: '02-comp-via-breadcrumb' },
      { kind: 'assert', label: 'breadcrumb-propagated-as', fn: async (page) => {
        const url = new URL(page.url());
        if (!url.searchParams.get('as')) throw new Error(`?as= lost crossing breadcrumb: ${page.url()}`);
        const as = url.searchParams.get('as');
        if (as !== V.ALICE_SHARE) throw new Error(`?as= mutated: got ${as}, expected ${V.ALICE_SHARE.slice(0,8)}…`);
      } },
      // Competition page must show Alice's "mis pencas" card AND the badge.
      { kind: 'assert', label: 'comp-page-personalized', fn: async (page) => {
        const html = await page.content();
        if (!html.includes('mis pencas de')) throw new Error(`'mis pencas' section missing on comp page`);
        if (!html.includes(V.LIBE_NAME))    throw new Error(`Alice's penca '${V.LIBE_NAME}' not in card body`);
        if (!html.includes('✓ 1-1'))        throw new Error(`✓ 1-1 badge missing on comp page`);
        if (!html.includes('tus pronósticos')) throw new Error(`'tus pronósticos' eyebrow tail missing`);
      } },
      // Tap the user's penca card — should navigate back to /pronostico/g/<view>/?as=<share>.
      { kind: 'click', selector: 'a.mantia-mygroup-row', label: '03-tap-mygroup-card' },
      { kind: 'dump', label: '03-back-to-group' },
      { kind: 'assert', label: 'card-navigates-back-to-group', fn: async (page) => {
        const u = page.url();
        if (!u.includes(`/pronostico/g/${V.LIBE_VIEW}/`)) throw new Error(`card didn't return to group page: ${u}`);
        if (!u.includes('as=')) throw new Error(`?as= not on the card link`);
      } },
    ],
  },
  {
    id: 'anon-flow',
    description: 'Anonymous visitor lands on libertadores penca + taps Sumate CTA',
    steps: [
      { kind: 'goto', url: `${V.BASE}/pronostico/g/${V.LIBE_VIEW}/`, label: '01-libe-anon' },
      { kind: 'dump', label: '01-libe-anon' },
      { kind: 'assert', label: 'anon-cta-is-sumate', fn: async (page) => {
        const cta = await page.locator('a.mantia-pill-primary').first().textContent();
        if (!cta?.includes('Sumate'))      throw new Error(`anon CTA missing 'Sumate': ${cta}`);
        if (!cta?.includes(V.LIBE_CODE))   throw new Error(`anon CTA missing invite code: ${cta}`);
      } },
      // Anon must NOT see any prediction badges (privacy: no per-user data leak).
      { kind: 'assert', label: 'anon-no-prediction-badges', fn: async (page) => {
        const html = await page.content();
        if (html.includes('✓ 1-1')) throw new Error(`anonymous visitor sees Alice's ✓ 1-1 — privacy leak`);
        if (html.includes('✓ 2-0')) throw new Error(`anonymous visitor sees Alice's ✓ 2-0 — privacy leak`);
        if (html.includes('✓ 0-3')) throw new Error(`anonymous visitor sees Bob's ✓ 0-3 — privacy leak`);
      } },
      // /pronostico/sumate/<code>/ renders a small Mantia landing
      // that AUTO-REFRESHES to wa.me via <meta http-equiv="refresh">.
      // Playwright follows the refresh and ends up on facebook.com;
      // to inspect the Mantia HTML we use the request API (fetch-like,
      // no JS, no meta-refresh) to capture the raw landing body.
      { kind: 'assert', label: 'sumate-landing-wames-correctly', fn: async (page) => {
        const resp = await page.context().request.get(
          `${V.BASE}/pronostico/sumate/${V.LIBE_CODE}/`
        );
        if (resp.status() !== 200) throw new Error(`landing returned ${resp.status()}`);
        const html = await resp.text();
        if (!html.includes(V.LIBE_NAME))     throw new Error(`landing missing penca name '${V.LIBE_NAME}'`);
        const m = html.match(/https:\/\/wa\.me\/[^"\s]+/);
        if (!m)                              throw new Error('landing has no wa.me link');
        if (!decodeURIComponent(m[0]).includes(V.LIBE_CODE)) {
          throw new Error(`wa.me link doesn't carry invite code: ${m[0].slice(0, 100)}`);
        }
      } },
    ],
  },
];

/** ── Driver ─────────────────────────────────────────────────────── */
async function runStep(page, step) {
  const start = Date.now();
  switch (step.kind) {
    case 'goto':
      await page.goto(step.url, { waitUntil: 'domcontentloaded', timeout: 20_000 });
      break;
    case 'click':
      await page.locator(step.selector).first().click({ timeout: 10_000 });
      await page.waitForLoadState('domcontentloaded', { timeout: 20_000 });
      break;
    case 'assert':
      await step.fn(page);
      break;
    case 'dump': {
      const dir = join(DUMP_ROOT, page._scenarioId);
      mkdirSync(dir, { recursive: true });
      writeFileSync(join(dir, `${step.label}.html`), await page.content());
      break;
    }
    default:
      throw new Error(`unknown step kind: ${step.kind}`);
  }
  return Date.now() - start;
}

async function runScenario(browser, scenario) {
  const ctx  = await browser.newContext();
  const page = await ctx.newPage();
  page._scenarioId = scenario.id;

  const dir = join(DUMP_ROOT, scenario.id);
  if (existsSync(dir)) rmSync(dir, { recursive: true, force: true });
  mkdirSync(dir, { recursive: true });

  console.log(`\n▶ ${scenario.id} — ${scenario.description}`);
  let passed = 0, failed = 0;
  for (const step of scenario.steps) {
    try {
      const ms = await runStep(page, step);
      console.log(`  ✓ ${step.kind.padEnd(7)} ${step.label} (${ms}ms)`);
      passed++;
    } catch (err) {
      console.log(`  ✗ ${step.kind.padEnd(7)} ${step.label}`);
      console.log(`      ${err.message}`);
      failed++;
    }
  }
  await ctx.close();
  return { passed, failed };
}

const browser = await chromium.launch({ headless: true });
let total = { passed: 0, failed: 0 };
for (const scenario of SCENARIOS) {
  const r = await runScenario(browser, scenario);
  total.passed += r.passed;
  total.failed += r.failed;
}
await browser.close();

console.log(`\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`);
console.log(`${total.failed === 0 ? '✅' : '❌'}  ${total.passed} passed, ${total.failed} failed`);
console.log(`  dumps at: ${DUMP_ROOT}/`);
process.exit(total.failed === 0 ? 0 : 1);
