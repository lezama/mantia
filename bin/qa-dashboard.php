<?php
/**
 * QA platform — dashboard renderer.
 *
 * Aggregates every tests/qa-output/*-findings.json file, classifies the
 * findings by severity and lens, and emits a single HTML file you can
 * open in a browser (no server needed). Intended to be regenerated after
 * every QA run.
 *
 * Usage: php bin/qa-dashboard.php > tests/qa-output/dashboard.html
 *
 * @package Mantia
 */

$project = dirname( __DIR__ );
$out_dir = $project . '/tests/qa-output';
$files   = glob( $out_dir . '/*-findings.json' );

$all_findings = array();
$personas     = array();
foreach ( $files as $path ) {
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) ) {
		continue;
	}
	$persona = (string) ( $data['persona'] ?? basename( $path, '-findings.json' ) );
	$personas[ $persona ] = array(
		'ran_at'    => (string) ( $data['ran_at'] ?? '?' ),
		'flows_run' => (array) ( $data['flows_run'] ?? array() ),
		'total'     => count( (array) ( $data['findings'] ?? array() ) ),
	);
	foreach ( (array) ( $data['findings'] ?? array() ) as $f ) {
		if ( ! is_array( $f ) ) {
			continue;
		}
		$f['persona'] = $persona;
		$all_findings[] = $f;
	}
}

// Stable sort: blocker > paper-cut > polish > works
$sev_order = array( 'blocker' => 0, 'paper-cut' => 1, 'polish' => 2, 'works' => 3 );
usort( $all_findings, function ( array $a, array $b ) use ( $sev_order ): int {
	$as = $sev_order[ $a['severity'] ?? '' ] ?? 9;
	$bs = $sev_order[ $b['severity'] ?? '' ] ?? 9;
	if ( $as !== $bs ) {
		return $as <=> $bs;
	}
	return strcmp( (string) ( $a['lens'] ?? '' ), (string) ( $b['lens'] ?? '' ) );
} );

$counts = array( 'blocker' => 0, 'paper-cut' => 0, 'polish' => 0, 'works' => 0 );
foreach ( $all_findings as $f ) {
	$sev = $f['severity'] ?? 'unknown';
	if ( isset( $counts[ $sev ] ) ) {
		$counts[ $sev ]++;
	}
}

function h( $v ): string {
	return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mantia · primetime-readiness dashboard</title>
<style>
:root { --ink:#0a0a0a; --bg:#c5f24e; --pink:#ff3d8e; --yellow:#ffe54a; --blue:#2a7bff; }
* { box-sizing: border-box; }
body { font-family: -apple-system, system-ui, sans-serif; background: var(--bg); color: var(--ink); margin: 0; padding: 32px 20px; line-height: 1.4; }
h1 { font-size: 32px; margin: 0 0 4px; font-weight: 900; letter-spacing: -0.02em; }
.sub { color: #555; margin-bottom: 24px; }
.summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 32px; max-width: 600px; }
.tile { background: #fff; border: 2px solid var(--ink); border-radius: 14px; padding: 14px; box-shadow: 3px 3px 0 var(--ink); }
.tile .n { font-size: 36px; font-weight: 900; line-height: 1; }
.tile .l { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; margin-top: 4px; color: #555; }
.tile.blocker { background: var(--pink); color: #fff; }
.tile.blocker .l { color: #fff; }
.tile.paper-cut { background: var(--yellow); }
.tile.polish { background: #fff; }
.tile.works { background: #e1ffd6; }
.personas { background: #fff; border: 2px solid var(--ink); border-radius: 14px; padding: 16px 20px; margin-bottom: 24px; max-width: 900px; }
.personas table { width: 100%; border-collapse: collapse; font-size: 13px; }
.personas th, .personas td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #eee; }
.personas th { font-weight: 700; color: #555; font-size: 11px; text-transform: uppercase; }
.findings { max-width: 900px; }
.finding { background: #fff; border: 2px solid var(--ink); border-radius: 12px; padding: 14px 18px; margin-bottom: 10px; box-shadow: 2px 2px 0 var(--ink); }
.finding .head { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
.badge { display: inline-block; padding: 2px 8px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; border-radius: 999px; border: 1.5px solid var(--ink); }
.badge.blocker { background: var(--pink); color: #fff; border-color: var(--pink); }
.badge.paper-cut { background: var(--yellow); }
.badge.polish { background: #f0f0f0; }
.badge.works { background: #c5f5a4; }
.lens { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.06em; }
.persona-tag { font-size: 11px; color: #888; }
.where { font-weight: 700; font-size: 14px; }
.what { font-size: 14px; margin: 4px 0 0; }
.evidence, .fix { font-size: 12px; color: #444; margin-top: 6px; background: #f6f6f6; border-radius: 6px; padding: 6px 10px; font-family: ui-monospace, Menlo, monospace; }
.fix { background: #e1f5d6; }
.empty { text-align: center; padding: 80px 20px; color: #666; }
</style>
</head>
<body>

<h1>Mantia · primetime-readiness</h1>
<div class="sub">
  Generated <?= h( gmdate( 'Y-m-d H:i' ) ) ?> UTC &middot;
  <?= count( $files ) ?> personas &middot;
  <?= count( $all_findings ) ?> findings total
</div>

<div class="summary">
  <div class="tile blocker"><div class="n"><?= $counts['blocker'] ?></div><div class="l">Blockers</div></div>
  <div class="tile paper-cut"><div class="n"><?= $counts['paper-cut'] ?></div><div class="l">Paper-cuts</div></div>
  <div class="tile polish"><div class="n"><?= $counts['polish'] ?></div><div class="l">Polish</div></div>
  <div class="tile works"><div class="n"><?= $counts['works'] ?></div><div class="l">Works ✓</div></div>
</div>

<?php if ( ! empty( $personas ) ): ?>
<div class="personas">
  <table>
    <thead>
      <tr><th>Persona</th><th>Ran at</th><th>Flows run</th><th>Findings</th></tr>
    </thead>
    <tbody>
    <?php foreach ( $personas as $slug => $p ): ?>
      <tr>
        <td><?= h( $slug ) ?></td>
        <td><?= h( $p['ran_at'] ) ?></td>
        <td><?= h( implode( ', ', $p['flows_run'] ) ) ?></td>
        <td><?= (int) $p['total'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="findings">
<?php if ( empty( $all_findings ) ): ?>
  <div class="empty">No findings yet. Run the persona agents and re-render with <code>bin/qa-run.sh dashboard</code>.</div>
<?php else: ?>
  <?php foreach ( $all_findings as $f ):
    $sev = (string) ( $f['severity'] ?? 'unknown' );
    ?>
    <div class="finding">
      <div class="head">
        <span class="badge <?= h( $sev ) ?>"><?= h( $sev ) ?></span>
        <span class="lens"><?= h( (string) ( $f['lens'] ?? '?' ) ) ?></span>
        <span class="persona-tag">· <?= h( (string) ( $f['persona'] ?? '?' ) ) ?> · flow <?= h( (string) ( $f['flow'] ?? '?' ) ) ?></span>
      </div>
      <div class="where"><?= h( (string) ( $f['where'] ?? '' ) ) ?></div>
      <div class="what"><?= h( (string) ( $f['what'] ?? '' ) ) ?></div>
      <?php if ( ! empty( $f['evidence'] ) ): ?>
        <div class="evidence">evidence: <?= h( (string) $f['evidence'] ) ?></div>
      <?php endif; ?>
      <?php if ( ! empty( $f['fix_hint'] ) ): ?>
        <div class="fix">fix: <?= h( (string) $f['fix_hint'] ) ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

</body>
</html>
