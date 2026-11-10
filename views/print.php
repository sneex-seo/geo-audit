<?php
/**
 * Друкована версія звіту (для api/pdf.php): світла тема, усі знахідки розгорнуті.
 * Очікує: $job (рядок з БД), $report (масив звіту).
 * Необов'язково: $autoPrint (bool, за замовчуванням true) - відкрити діалог друку після завантаження.
 */
if (!isset($job) || !is_array($job) || !isset($report) || !is_array($report)) { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/helpers.php';

$rep = normalize_report($report);
$jobId = (string)($job['id'] ?? ($rep['meta']['job_id'] ?? ''));
$doAutoPrint = !isset($autoPrint) || $autoPrint;
$allFindings = report_findings($rep['checks']);
$banner = access_banner($rep['access']);
$duration = report_duration($job, $rep);
$siteUrl = $rep['url'] !== '' ? $rep['url'] : (string)($job['url'] ?? '');
$hostName = $rep['host'] !== '' ? $rep['host'] : short_url($siteUrl);
$auditedAt = $rep['audited_at'] !== '' ? fmt_iso_uk($rep['audited_at']) : fmt_time($job['finished_at'] ?? ($job['created_at'] ?? null));

$sevKeys = ['critical', 'high', 'medium', 'low'];
$sevCounts = array_fill_keys($sevKeys, 0);
$catCounts = [];
foreach ($allFindings as $af) {
    $sevCounts[$af['severity']]++;
    $catCounts[$af['cat_index']] = ($catCounts[$af['cat_index']] ?? 0) + 1;
}
$site = $rep['site'];
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GEO-аудит · <?= h($hostName) ?></title>
<style>
@page { size: A4; margin: 16mm 14mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1f2937; font-size: 11.5px; line-height: 1.5; padding: 24px; max-width: 840px; margin: 0 auto; overflow-wrap: break-word; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
a { color: #1d4ed8; text-decoration: none; }
h1 { font-size: 22px; margin: 0 0 4px; color: #111827; }
h2 { font-size: 15px; margin: 26px 0 10px; padding-bottom: 5px; border-bottom: 2px solid #e5e7eb; color: #111827; break-after: avoid; page-break-after: avoid; }
h3 { font-size: 12.5px; margin: 0 0 6px; color: #111827; }
code, pre { font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace; }
.muted { color: #6b7280; }
.small { font-size: 10.5px; }
.no-print { background: #fffbe6; padding: 8px 12px; border: 1px solid #fde047; border-radius: 4px; margin-bottom: 16px; font-size: 11.5px; }
@media print { body { padding: 0; } .no-print { display: none !important; } }

/* Шапка */
.doc-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.doc-url { font-size: 13px; font-weight: 600; word-break: break-all; }
.doc-meta { color: #6b7280; font-size: 10.5px; margin-top: 4px; }
.doc-meta span + span::before { content: " · "; }
.score-box { flex: 0 0 auto; text-align: center; border: 2px solid #e5e7eb; border-radius: 10px; padding: 10px 18px; min-width: 130px; }
.score-num { font-size: 40px; font-weight: 700; line-height: 1; }
.score-num small { font-size: 15px; color: #9ca3af; font-weight: 600; }
.score-tier { font-size: 11px; letter-spacing: 1px; text-transform: uppercase; margin-top: 4px; font-weight: 700; }
.tier-good { color: #15803d; }
.tier-moderate { color: #1d4ed8; }
.tier-poor { color: #b45309; }
.tier-critical { color: #b91c1c; }
.score-box.is-partial .score-num, .score-box.is-partial .score-tier { color: #6b7280; }
.profile { display: flex; flex-wrap: wrap; gap: 4px 18px; margin-top: 12px; font-size: 11px; }
.type-badge { display: inline-block; background: #dbeafe; color: #1d4ed8; padding: 1px 9px; border-radius: 10px; font-weight: 600; }
.signals { margin: 4px 0 0; color: #6b7280; font-size: 10.5px; }
.banner { margin-top: 12px; padding: 8px 12px; border-radius: 6px; border: 1px solid; font-size: 11px; break-inside: avoid; page-break-inside: avoid; }
.banner.warn { background: #fffbeb; border-color: #fcd34d; color: #92400e; }
.banner.error { background: #fef2f2; border-color: #fca5a5; color: #991b1b; }
.banner.info { background: #eff6ff; border-color: #bfdbfe; color: #1e3a8a; }
.samples { margin-top: 12px; }
.samples table td { padding: 4px 8px; }

/* Таблиці */
table { width: 100%; border-collapse: collapse; margin: 6px 0 12px; font-size: 11px; }
th { background: #f3f4f6; padding: 6px 8px; text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.4px; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
tr { break-inside: avoid; page-break-inside: avoid; }
td.num, th.num { text-align: right; white-space: nowrap; }
.pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; white-space: nowrap; }
.pill-good { background: #dcfce7; color: #15803d; }
.pill-moderate { background: #dbeafe; color: #1d4ed8; }
.pill-poor { background: #fef3c7; color: #b45309; }
.pill-critical { background: #fee2e2; color: #b91c1c; }
.pill-na { background: #f3f4f6; color: #6b7280; border: 1px dashed #d1d5db; }
.bar { height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden; min-width: 80px; margin-top: 5px; }
.bar i { display: block; height: 100%; }
.bar i.good { background: #22c55e; } .bar i.moderate { background: #3b82f6; } .bar i.poor { background: #f59e0b; } .bar i.critical { background: #ef4444; }
.http-bad { color: #b91c1c; }

/* Категорії */
.cat-card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; margin-bottom: 8px; break-inside: avoid; page-break-inside: avoid; }
.cat-head { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.cat-summary { margin: 4px 0 0; font-size: 11px; }
.sub-table { margin: 6px 0 0; }
.sub-table td { border: 0; padding: 2px 0; font-size: 10.5px; }
.sub-table td.v { text-align: right; font-weight: 700; width: 50px; }
.sub-table td.na { color: #9ca3af; font-weight: 400; font-style: italic; }
.passed-title { font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.4px; color: #15803d; font-weight: 700; margin-top: 8px; }
.passed-list { list-style: none; margin: 2px 0 0; padding: 0; font-size: 10.5px; columns: 2; column-gap: 18px; }
.passed-list li { position: relative; padding: 1px 0 1px 14px; break-inside: avoid; }
.passed-list li::before { content: "\2713"; position: absolute; left: 0; color: #16a34a; font-weight: 700; }

/* Бейджі */
.sev-badge, .impact-badge { display: inline-block; font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 3px; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
.sev-badge.sev-critical, .impact-badge.sev-critical { background: #fee2e2; color: #b91c1c; }
.sev-badge.sev-high, .impact-badge.sev-high { background: #fef3c7; color: #b45309; }
.sev-badge.sev-medium, .impact-badge.sev-medium { background: #dbeafe; color: #1d4ed8; }
.sev-badge.sev-low, .impact-badge.sev-low { background: #f3f4f6; color: #4b5563; }
.effort-badge { display: inline-block; font-size: 9.5px; padding: 0 6px; border-radius: 8px; border: 1px solid #d1d5db; color: #4b5563; white-space: nowrap; }
.sev-line { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 10px; }

/* Знахідки */
.fcard { border: 1px solid #e5e7eb; border-left: 4px solid #9ca3af; border-radius: 0 6px 6px 0; margin-bottom: 10px; background: #fff; color: #1f2937; }
.fcard.sev-critical { border-left-color: #dc2626; }
.fcard.sev-high { border-left-color: #d97706; }
.fcard.sev-medium { border-left-color: #2563eb; }
.fcard.sev-low { border-left-color: #9ca3af; }
.f-head { display: flex; flex-wrap: wrap; gap: 4px 8px; align-items: baseline; padding: 8px 12px; background: #f9fafb; border-bottom: 1px solid #f3f4f6; break-after: avoid; page-break-after: avoid; break-inside: avoid; page-break-inside: avoid; }
.f-title { font-weight: 700; font-size: 12px; flex: 1 1 300px; color: #111827; }
.f-meta { display: flex; gap: 6px; align-items: baseline; flex-wrap: wrap; }
.f-cat { font-size: 10px; color: #6b7280; }
.f-body { padding: 4px 12px 10px; }
.f-sec { margin-top: 8px; break-inside: avoid; page-break-inside: avoid; }
.f-sec h4, .cmp h4, .code-head h4 { margin: 0 0 2px; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; font-weight: 700; }
.rich-text { white-space: pre-line; }
.rich-list { margin: 0; padding-left: 16px; }
pre.snippet, pre.code { margin: 0; white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere; font-size: 10px; line-height: 1.45; }
pre.snippet { background: rgba(0,0,0,0.035); padding: 6px 8px; border-radius: 4px; }
.compare { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 8px; }
.compare.single { grid-template-columns: minmax(0, 1fr); }
.cmp { border: 1px solid; border-radius: 5px; padding: 6px 8px 8px; min-width: 0; break-inside: avoid; page-break-inside: avoid; }
.cmp.bad { background: #fef2f2; border-color: #fecaca; }
.cmp.bad h4 { color: #b91c1c; }
.cmp.good { background: #f0fdf4; border-color: #bbf7d0; }
.cmp.good h4 { color: #15803d; }
.code-wrap { margin-top: 8px; border: 1px solid #e5e7eb; border-radius: 5px; background: #f8fafc; break-inside: avoid; page-break-inside: avoid; }
.code-head { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; }
pre.code { padding: 8px 10px; }
.f-id { margin-top: 8px; font-size: 9px; color: #9ca3af; font-family: ui-monospace, Consolas, monospace; }

/* AI-аналіз */
.ai-summary { font-size: 12.5px; line-height: 1.6; margin: 0 0 12px; }
.ai-tiles { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px; }
.ai-tile { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 2px 10px; break-inside: avoid; page-break-inside: avoid; }
.ai-tile .t-score { font-size: 26px; font-weight: 700; line-height: 1; grid-row: span 2; }
.ai-tile .t-score small { font-size: 11px; color: #9ca3af; }
.ai-tile .t-name { font-weight: 700; }
.ai-tile .t-notes { font-size: 10.5px; color: #4b5563; white-space: pre-line; }
.ai-h { font-size: 11px; font-weight: 700; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: 0.5px; color: #374151; break-after: avoid; page-break-after: avoid; }
.ai-prio { list-style: none; margin: 0; padding: 0; }
.prio-card { border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px 10px; margin-bottom: 6px; break-inside: avoid; page-break-inside: avoid; }
.prio-head { display: flex; gap: 8px; align-items: baseline; }
.prio-num { flex: 0 0 auto; width: 18px; height: 18px; border-radius: 50%; background: #1d4ed8; color: #fff; font-weight: 700; font-size: 10px; display: inline-flex; align-items: center; justify-content: center; }
.prio-action { font-weight: 700; font-size: 12px; }
.prio-badges { display: flex; gap: 6px; flex-wrap: wrap; margin: 4px 0 0 26px; }
.prio-why { margin: 4px 0 0 26px; color: #4b5563; }
.prio-example { margin: 6px 0 0 26px; border-left: 3px solid #22c55e; background: #f0fdf4; padding: 5px 8px; border-radius: 0 4px 4px 0; }
.prio-example .lbl { display: block; font-size: 9px; font-weight: 700; color: #15803d; text-transform: uppercase; letter-spacing: 0.4px; }
.ai-checklist { list-style: none; margin: 0; padding: 0; }
.ai-checklist li { break-inside: avoid; page-break-inside: avoid; margin-bottom: 3px; }
.ai-checklist label { display: flex; gap: 8px; align-items: flex-start; }
.ai-checklist input { margin: 2px 0 0; flex: 0 0 auto; }
.ai-strategic { margin: 0; padding-left: 20px; }
.ai-strategic li { margin-bottom: 3px; break-inside: avoid; page-break-inside: avoid; }
.tp-head { margin: 14px 0 4px; }
.tp-head .ai-h { margin: 0; }
.tp-list { margin: 6px 0 0; padding-left: 22px; }
.tp-list li { margin-bottom: 2px; break-inside: avoid; page-break-inside: avoid; }
.ai-message { margin: 0; }
.footer { margin-top: 28px; padding-top: 10px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 10px; text-align: center; }
@media (max-width: 600px) {
    body { padding: 12px; }
    .doc-head { flex-direction: column; }
    .compare, .ai-tiles { grid-template-columns: minmax(0, 1fr); }
    .passed-list { columns: 1; }
}
</style>
</head>
<body>
<div class="no-print">
    Щоб зберегти PDF, натисніть <strong>Ctrl+P</strong> (<strong>Cmd+P</strong> на Mac) і оберіть «Зберегти як PDF».
    <?php if ($doAutoPrint): ?>
        <a href="?id=<?= h(rawurlencode($jobId)) ?>&amp;noprint=1">Вимкнути автодрук</a>
    <?php endif; ?>
</div>

<div class="doc-head">
    <div>
        <h1>Звіт GEO-аудиту</h1>
        <div class="doc-url"><?= h($siteUrl) ?></div>
        <div class="doc-meta">
            <span>Сформовано <?= h($auditedAt) ?></span>
            <?php if ($duration !== ''): ?><span>Тривалість <?= h($duration) ?></span><?php endif; ?>
            <?php if ($jobId !== ''): ?><span>Звіт <?= h($jobId) ?></span><?php endif; ?>
            <?php if ($rep['meta']['engine_version'] !== ''): ?><span>Версія рушія <?= h($rep['meta']['engine_version']) ?></span><?php endif; ?>
        </div>
        <?php if ($site): ?>
            <div class="profile">
                <span>Тип сайту: <span class="type-badge"><?= h($site['type_label']) ?></span>
                    <?php if ($site['confidence'] !== null): ?><span class="muted">впевненість <?= (int)round($site['confidence'] * 100) ?>%</span><?php endif; ?>
                </span>
                <?php if ($site['brand'] !== ''): ?><span><span class="muted">Бренд:</span> <?= h($site['brand']) ?></span><?php endif; ?>
                <?php if ($site['languages']): ?><span><span class="muted">Мови:</span> <?= h(implode(', ', $site['languages'])) ?></span><?php endif; ?>
            </div>
            <?php if ($site['signals']): ?>
                <div class="signals">Ознаки типу: <?= h(implode('; ', $site['signals'])) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php $covLow = $rep['coverage']['low']; $covNote = coverage_note($rep); ?>
    <div class="score-box<?= $covLow ? ' is-partial' : '' ?>">
        <div class="score-num tier-<?= h($rep['tier_class']) ?>"><?= (int)$rep['composite_score'] ?><small>/100</small></div>
        <div class="score-tier tier-<?= h($rep['tier_class']) ?>"><?= $covLow ? 'Неповний бал' : h($rep['tier_label']) ?></div>
        <div class="muted small" style="margin-top:4px">Загальний GEO-бал</div>
    </div>
</div>
<?php if ($covNote): ?>
    <div class="<?= $covLow ? 'banner warn' : 'muted small' ?>" style="margin-top:8px"><?= h($covNote) ?></div>
<?php endif; ?>

<?php if ($rep['legacy']): ?>
    <div class="banner info">Звіт створено попередньою версією інструмента (тексти перевірок англійською, без доказів і готового коду). Для повного звіту запустіть аудит повторно.</div>
<?php endif; ?>
<?php foreach (array_filter([$banner, lang_banner($rep['access'])]) as $bn): ?>
    <div class="banner <?= h($bn['level']) ?>"><?= $bn['level'] === 'info' ? '' : '<strong>Увага.</strong> ' ?><?= h($bn['text']) ?></div>
<?php endforeach; ?>

<?php if ($rep['samples']): ?>
    <div class="samples">
        <h3>Проаналізовані сторінки (<?= count($rep['samples']) + 1 ?>)</h3>
        <table>
            <thead><tr><th>Роль</th><th>Сторінка</th><th class="num">HTTP</th></tr></thead>
            <tbody>
                <?php $homeStatus = $rep['access']['homepage_status'] ?? null; ?>
                <tr>
                    <td><?= h(role_label_uk('home')) ?></td>
                    <td><?= h($siteUrl) ?></td>
                    <td class="num<?= ($homeStatus !== null && $homeStatus >= 400) ? ' http-bad' : '' ?>"><?= $homeStatus !== null ? (int)$homeStatus : '-' ?></td>
                </tr>
                <?php foreach ($rep['samples'] as $smp): ?>
                    <tr>
                        <td><?= h(role_label_uk($smp['role'], (string)($report['site']['type'] ?? ''))) ?></td>
                        <td><?php if ($smp['title'] !== ''): ?><strong><?= h($smp['title']) ?></strong><br><?php endif; ?><span class="muted" style="word-break:break-all"><?= h($smp['url']) ?></span></td>
                        <td class="num<?= ($smp['status'] !== null && ($smp['status'] >= 400 || $smp['status'] === 0)) ? ' http-bad' : '' ?>"><?= $smp['status'] !== null ? (int)$smp['status'] : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2>Оцінки за категоріями</h2>
<table>
    <thead>
        <tr><th>Категорія</th><th class="num">Вага</th><th style="width:150px">Бал</th><th class="num">Зауважень</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rep['checks'] as $ci => $c): $skip = $c['status'] === 'skipped'; ?>
        <tr>
            <td><strong><?= h($c['name']) ?></strong></td>
            <td class="num"><?= (int)round($c['weight'] * 100) ?>%</td>
            <td>
                <?php if ($skip): ?>
                    <span class="pill pill-na">н/д</span> <span class="muted small">не враховано</span>
                <?php else: $tc = tier_class((int)$c['score']); ?>
                    <span class="pill pill-<?= $tc ?>"><?= (int)$c['score'] ?>/100</span>
                    <div class="bar"><i class="<?= $tc ?>" style="width:<?= (int)$c['score'] ?>%"></i></div>
                <?php endif; ?>
            </td>
            <td class="num"><?= (int)($catCounts[$ci] ?? 0) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p class="muted small" style="margin:0">Шкала: 80+ Добре · 60-79 Помірно · 40-59 Слабко · до 40 Критично. Категорії «н/д» не вдалося оцінити, вони не входять у загальний бал.</p>

<h2>AI-аналіз<?php if ($rep['ai']['model'] !== '' && $rep['ai']['has_content']): ?> <span class="muted small" style="font-weight:400">(<?= h($rep['ai']['model']) ?>)</span><?php endif; ?></h2>
<?php $ai = $rep['ai']; $mode = 'print'; include __DIR__ . '/ai.php'; ?>

<h2>Детальні оцінки</h2>
<?php foreach ($rep['checks'] as $c): $skip = $c['status'] === 'skipped'; ?>
    <div class="cat-card">
        <div class="cat-head">
            <h3 style="margin:0"><?= h($c['name']) ?> <span class="muted small" style="font-weight:400">· вага <?= (int)round($c['weight'] * 100) ?>%</span></h3>
            <?php if ($skip): ?>
                <span class="pill pill-na">н/д</span>
            <?php else: ?>
                <span class="pill pill-<?= tier_class((int)$c['score']) ?>"><?= (int)$c['score'] ?>/100</span>
            <?php endif; ?>
        </div>
        <?php if ($c['summary'] !== ''): ?>
            <p class="cat-summary"><?= h($c['summary']) ?></p>
        <?php elseif ($skip): ?>
            <p class="cat-summary muted">Перевірку не вдалося виконати.</p>
        <?php endif; ?>
        <?php if ($c['sub_scores']): ?>
            <table class="sub-table">
                <?php foreach ($c['sub_scores'] as $sk => $sv): ?>
                    <tr>
                        <td class="muted"><?= h(sub_label($c, (string)$sk)) ?></td>
                        <?php if ($sv === null): ?>
                            <td class="v na">н/д</td>
                        <?php else: ?>
                            <td class="v tier-<?= tier_class((int)$sv) ?>"><?= (int)$sv ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <?php if ($c['passed']): ?>
            <div class="passed-title">Що вже добре</div>
            <ul class="passed-list">
                <?php foreach ($c['passed'] as $ps): ?><li><?= h($ps) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<h2>Проблеми та рекомендації (<?= count($allFindings) ?>)</h2>
<?php if (!$allFindings): ?>
    <p class="muted">Зауважень немає - сайт виглядає добре.</p>
<?php else: ?>
    <div class="sev-line">
        <?php foreach ($sevKeys as $sk): if (!$sevCounts[$sk]) continue; ?>
            <span class="sev-badge sev-<?= h($sk) ?>"><?= h(severity_plural_uk($sk)) ?>: <?= (int)$sevCounts[$sk] ?></span>
        <?php endforeach; ?>
    </div>
    <?php $mode = 'print'; foreach ($allFindings as $fi => $f) { include __DIR__ . '/finding.php'; } ?>
<?php endif; ?>

<div class="footer">GEO-аудит · <?= h(date('d.m.Y')) ?></div>

<?php if ($doAutoPrint): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
