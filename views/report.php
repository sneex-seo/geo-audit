<?php
/**
 * Веб-сторінка звіту GEO-аудиту.
 * Очікує: $job (рядок з БД), $report (масив звіту або null).
 * Необов'язково: $inlineCss (рядок CSS для рендеру фікстур без assets/).
 */
if (!isset($job) || !is_array($job)) { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/helpers.php';

$jobId = (string)($job['id'] ?? '');
$jobStatus = (string)($job['status'] ?? 'queued');
$jobFinal = in_array($jobStatus, ['done', 'error'], true);
$rep = (isset($report) && is_array($report)) ? normalize_report($report) : null;
$allFindings = $rep ? report_findings($rep['checks']) : [];
$banner = $rep ? access_banner($rep['access']) : null;
$duration = $rep ? report_duration($job, $rep) : (!empty($job['finished_at']) ? fmt_duration($job['created_at'] ?? null, $job['finished_at']) : '');
$siteUrl = ($rep && $rep['url'] !== '') ? $rep['url'] : (string)($job['url'] ?? '');

$sevKeys = ['critical', 'high', 'medium', 'low'];
$sevCounts = array_fill_keys($sevKeys, 0);
$catCounts = [];
foreach ($allFindings as $af) {
    $sevCounts[$af['severity']]++;
    $catCounts[$af['cat_index']] = ($catCounts[$af['cat_index']] ?? 0) + 1;
}

$radarChecks = [];
$skippedNames = [];
if ($rep) {
    foreach ($rep['checks'] as $rc) {
        if ($rc['status'] === 'skipped') { $skippedNames[] = $rc['name']; continue; }
        $radarChecks[] = ['label' => radar_label($rc['name']), 'score' => (int)$rc['score']];
    }
}
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GEO-аудит · <?= h(short_url((string)($job['url'] ?? ''))) ?></title>
<?php if (!empty($inlineCss)): ?>
<style><?= $inlineCss ?></style>
<?php else: ?>
<link rel="stylesheet" href="assets/style.css">
<?php endif; ?>
<?php if ($radarChecks): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php endif; ?>
</head>
<body>
<header class="app no-print">
    <h1><a href="index.php">&larr; GEO Audit</a></h1>
    <span class="badge-version">Звіт <?= h($jobId) ?></span>
</header>
<div class="container">

<!-- 1. Шапка звіту -->
<div class="card report-head">
    <div class="rh-top">
        <div class="rh-main">
            <h2 class="rh-url"><a href="<?= h($siteUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($siteUrl) ?></a></h2>
            <div class="meta-line muted">
                <span>Запущено <?= h(fmt_time($job['created_at'] ?? null)) ?></span>
                <?php if ($duration !== ''): ?><span>Тривалість <?= h($duration) ?></span><?php endif; ?>
                <?php if ($rep && $rep['meta']['engine_version'] !== ''): ?><span>Версія рушія <?= h($rep['meta']['engine_version']) ?></span><?php endif; ?>
            </div>
        </div>
        <span id="statusPill" class="status-pill status-<?= h($jobStatus) ?>"><?= h(status_label_uk($jobStatus)) ?></span>
    </div>

    <?php if ($rep && ($rep['site'] || $rep['samples'])): $site = $rep['site']; ?>
        <?php if ($site): ?>
        <div class="site-profile">
            <details class="site-type">
                <summary title="<?= h($site['signals'] ? 'Ознаки: ' . implode('; ', $site['signals']) : 'Тип сайту визначено автоматично') ?>">
                    <span class="muted">Тип сайту:</span>
                    <span class="type-badge"><?= h($site['type_label']) ?></span>
                    <?php if ($site['confidence'] !== null): ?>
                        <span class="conf">впевненість <?= (int)round($site['confidence'] * 100) ?>%</span>
                    <?php endif; ?>
                    <?php if ($site['signals']): ?><span class="sig-toggle">чому так?</span><?php endif; ?>
                </summary>
                <?php if ($site['signals']): ?>
                    <ul class="signals">
                        <?php foreach ($site['signals'] as $sig): ?><li><?= h($sig) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </details>
            <?php if ($site['brand'] !== ''): ?><span class="kv"><span class="muted">Бренд:</span> <?= h($site['brand']) ?></span><?php endif; ?>
            <?php if ($site['languages']): ?><span class="kv"><span class="muted">Мови:</span> <?= h(implode(', ', $site['languages'])) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($rep && $rep['legacy']): ?>
        <div class="banner info">
            <span class="ico" aria-hidden="true">i</span>
            <span>Звіт створено попередньою версією інструмента: тексти перевірок англійською, без доказів, прикладів «Як зараз / Як треба» і готового коду. Запустіть аудит повторно, щоб отримати повний звіт v2.</span>
        </div>
    <?php endif; ?>

    <?php foreach (array_filter([$banner, $rep ? lang_banner($rep['access']) : null]) as $bn): ?>
        <div class="banner <?= h($bn['level']) ?>"<?= $bn['level'] === 'info' ? '' : ' role="alert"' ?>>
            <span class="ico" aria-hidden="true"><?= $bn['level'] === 'info' ? 'i' : '!' ?></span>
            <span><?= h($bn['text']) ?></span>
        </div>
    <?php endforeach; ?>

    <?php if ($rep && $rep['samples']): ?>
        <div class="samples">
            <h3>Проаналізовані сторінки (<?= count($rep['samples']) + 1 ?>)</h3>
            <ul class="sample-list">
                <?php $homeStatus = $rep['access']['homepage_status'] ?? null; ?>
                <li>
                    <span class="role-badge"><?= h(role_label_uk('home')) ?></span>
                    <span class="sample-main">
                        <a class="sample-url" href="<?= h($siteUrl) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($siteUrl) ?>"><?= h(short_url($siteUrl)) ?></a>
                    </span>
                    <?php if ($homeStatus !== null): ?><span class="http-code<?= $homeStatus >= 400 ? ' bad' : '' ?>"><?= (int)$homeStatus ?></span><?php else: ?><span></span><?php endif; ?>
                </li>
                <?php foreach ($rep['samples'] as $smp): ?>
                    <li>
                        <span class="role-badge"><?= h(role_label_uk($smp['role'], (string)($report['site']['type'] ?? ''))) ?></span>
                        <span class="sample-main">
                            <?php if ($smp['title'] !== ''): ?><span class="sample-title" title="<?= h($smp['title']) ?>"><?= h($smp['title']) ?></span><?php endif; ?>
                            <a class="sample-url" href="<?= h($smp['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($smp['url']) ?>"><?= h(short_url($smp['url'])) ?></a>
                        </span>
                        <?php if ($smp['status'] !== null): ?><span class="http-code<?= $smp['status'] >= 400 || $smp['status'] === 0 ? ' bad' : '' ?>"><?= (int)$smp['status'] ?></span><?php else: ?><span></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$jobFinal): ?>
        <div class="progress-wrap"><div class="progress-bar" id="progBar" style="width: <?= (int)($job['progress'] ?? 0) ?>%"></div></div>
        <div class="muted progress-note"><span id="progStage"><?= h(stage_label_uk($job['stage'] ?? '')) ?></span> &middot; <span id="progPct"><?= (int)($job['progress'] ?? 0) ?>%</span></div>
        <p class="muted small" style="margin:6px 0 0">Аудит зазвичай триває 1-2 хвилини. Сторінка оновиться автоматично.</p>
    <?php endif; ?>
    <?php if ($jobStatus === 'error'): ?>
        <div class="banner error" role="alert">
            <span class="ico" aria-hidden="true">!</span>
            <span><strong>Помилка аудиту.</strong> <?= h(job_error_uk($job['error'] ?? '')) ?></span>
        </div>
    <?php elseif ($jobStatus === 'done' && !$rep): ?>
        <div class="banner error" role="alert">
            <span class="ico" aria-hidden="true">!</span>
            <span>Аудит завершено, але результат не вдалося прочитати. Спробуйте запустити аудит ще раз.</span>
        </div>
    <?php endif; ?>
</div>

<?php if ($rep): ?>

<!-- 2. Загальний бал і радар -->
<div class="grid-2">
    <div class="card score-card">
        <h2>Загальний GEO-бал</h2>
        <?php $covLow = $rep['coverage']['low']; $covNote = coverage_note($rep); ?>
        <div class="score-gauge<?= $covLow ? ' is-partial' : '' ?>">
            <div class="num tier-<?= h($rep['tier_class']) ?>"><?= (int)$rep['composite_score'] ?><small>/100</small></div>
            <div class="label tier-<?= h($rep['tier_class']) ?>"><?= $covLow ? 'Неповний бал' : h($rep['tier_label']) ?></div>
        </div>
        <?php if ($covNote): ?>
            <p class="coverage-note<?= $covLow ? ' warn' : ' muted small' ?>"><?= h($covNote) ?></p>
        <?php endif; ?>
        <p class="tier-scale muted">80+ Добре &middot; 60-79 Помірно &middot; 40-59 Слабко &middot; до 40 Критично</p>
        <div class="btn-row no-print">
            <a class="btn btn-secondary" href="api/pdf.php?id=<?= h(rawurlencode($jobId)) ?>" target="_blank">Завантажити PDF</a>
            <a class="btn btn-secondary" href="api/result.php?id=<?= h(rawurlencode($jobId)) ?>" target="_blank">JSON</a>
        </div>
        <?php if ($allFindings): ?>
            <div class="sev-summary no-print">
                <?php $sevColor = ['critical' => 'tier-critical', 'high' => 'tier-poor', 'medium' => 'tier-moderate', 'low' => 'muted']; ?>
                <?php foreach ($sevKeys as $sk): if (!$sevCounts[$sk]) continue; ?>
                    <a href="#findings" data-filter-sev="<?= h($sk) ?>"><b class="<?= $sevColor[$sk] ?>"><?= (int)$sevCounts[$sk] ?></b><?= h(mb_strtolower(severity_plural_uk($sk), 'UTF-8')) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h2>Оцінки за категоріями</h2>
        <?php if ($radarChecks): ?>
            <div class="radar-wrap"><canvas id="radarChart" aria-label="Радар оцінок за категоріями" role="img"></canvas></div>
        <?php else: ?>
            <p class="muted">Немає оцінених категорій.</p>
        <?php endif; ?>
        <?php if ($skippedNames): ?>
            <p class="muted small" style="margin:10px 0 0">Не оцінено (н/д): <?= h(implode(', ', $skippedNames)) ?>. Ці категорії не враховано в загальному балі.</p>
        <?php endif; ?>
    </div>
</div>

<!-- 3. AI-аналіз -->
<div class="card ai-card" id="ai">
    <div class="card-head">
        <h2>AI-аналіз</h2>
        <?php if ($rep['ai']['model'] !== '' && $rep['ai']['has_content']): ?><span class="badge-version"><?= h($rep['ai']['model']) ?></span><?php endif; ?>
    </div>
    <?php $ai = $rep['ai']; $mode = 'web'; include __DIR__ . '/ai.php'; ?>
</div>

<!-- 4. Категорії -->
<div class="card" id="categories">
    <h2>Категорії</h2>
    <div class="grid-cards">
    <?php foreach ($rep['checks'] as $ci => $c): $cSkipped = $c['status'] === 'skipped'; $cCount = $catCounts[$ci] ?? 0; ?>
        <div class="check-card<?= $cSkipped ? ' is-skipped' : '' ?>">
            <div class="head">
                <h3><?= h($c['name']) ?></h3>
                <?php if ($cSkipped): ?>
                    <span class="score-pill na" title="Перевірку не вдалося виконати - не враховано в загальному балі">н/д</span>
                <?php else: ?>
                    <span class="score-pill <?= tier_class((int)$c['score']) ?>"><?= (int)$c['score'] ?></span>
                <?php endif; ?>
            </div>
            <div class="weight">
                Вага <?= (int)round($c['weight'] * 100) ?>% &middot;
                <?php if ($cCount > 0): ?>
                    <a href="#findings" class="cat-link" data-filter-cat="<?= (int)$ci ?>"><?= h(findings_count_uk($cCount)) ?></a>
                <?php else: ?>
                    без зауважень
                <?php endif; ?>
            </div>
            <?php if ($c['summary'] !== ''): ?>
                <p class="check-summary"><?= h($c['summary']) ?></p>
            <?php elseif ($cSkipped): ?>
                <p class="check-summary muted">Перевірку не вдалося виконати.</p>
            <?php endif; ?>

            <?php if ($c['sub_scores']): ?>
                <div class="subscores">
                <?php foreach ($c['sub_scores'] as $sk => $sv): ?>
                    <div class="subscore-row">
                        <span class="muted"><?= h(sub_label($c, (string)$sk)) ?></span>
                        <?php if ($sv === null): ?>
                            <span class="val na" title="Не застосовується до цього сайту">н/д</span>
                        <?php else: ?>
                            <span class="val tier-<?= tier_class((int)$sv) ?>"><?= (int)$sv ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($c['passed']): $pFirst = array_slice($c['passed'], 0, 4); $pRest = array_slice($c['passed'], 4); ?>
                <div class="passed">
                    <div class="passed-title">Що вже добре</div>
                    <ul class="passed-list">
                        <?php foreach ($pFirst as $ps): ?><li><?= h($ps) ?></li><?php endforeach; ?>
                    </ul>
                    <?php if ($pRest): ?>
                        <details class="passed-more">
                            <summary>Ще <?= count($pRest) ?></summary>
                            <ul class="passed-list">
                                <?php foreach ($pRest as $ps): ?><li><?= h($ps) ?></li><?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- 5. Проблеми та рекомендації -->
<div class="card" id="findings">
    <div class="card-head">
        <h2>Проблеми та рекомендації</h2>
        <span class="muted small" id="fCount"><?= h(findings_count_uk(count($allFindings))) ?></span>
    </div>
    <?php if ($allFindings): ?>
        <div class="f-toolbar no-print">
            <div class="chips" role="group" aria-label="Фільтр за серйозністю">
                <button type="button" class="chip active" data-sev="all">Усі <span class="cnt"><?= count($allFindings) ?></span></button>
                <?php foreach ($sevKeys as $sk): ?>
                    <button type="button" class="chip chip-<?= h($sk) ?>" data-sev="<?= h($sk) ?>"<?= $sevCounts[$sk] ? '' : ' disabled' ?>><?= h(severity_plural_uk($sk)) ?> <span class="cnt"><?= (int)$sevCounts[$sk] ?></span></button>
                <?php endforeach; ?>
            </div>
            <div class="f-controls">
                <select id="catFilter" aria-label="Фільтр за категорією">
                    <option value="">Усі категорії</option>
                    <?php foreach ($rep['checks'] as $ci => $c): if (empty($catCounts[$ci])) continue; ?>
                        <option value="<?= (int)$ci ?>"><?= h($c['name']) ?> (<?= (int)$catCounts[$ci] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-secondary btn-sm" id="expandAll">Розгорнути всі</button>
                <button type="button" class="btn btn-secondary btn-sm" id="collapseAll">Згорнути всі</button>
            </div>
        </div>
        <div class="f-list">
            <?php $mode = 'web'; foreach ($allFindings as $fi => $f) { include __DIR__ . '/finding.php'; } ?>
        </div>
        <p class="muted f-empty" id="fEmpty" hidden>За цим фільтром зауважень немає.</p>
    <?php else: ?>
        <p class="muted">Зауважень немає - сайт виглядає добре.</p>
    <?php endif; ?>
</div>

<?php endif; ?>

</div>

<script>
(function () {
    'use strict';

    /* ---- Копіювання ---- */
    function legacyCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        return ok;
    }
    function flash(btn, text, cls) {
        if (!btn) return;
        if (!btn.dataset.label) btn.dataset.label = btn.textContent;
        btn.textContent = text;
        if (cls) btn.classList.add(cls);
        clearTimeout(btn._t);
        btn._t = setTimeout(function () { btn.textContent = btn.dataset.label; btn.classList.remove('ok'); }, 1600);
    }
    function copyText(text, btn) {
        var done = function () { flash(btn, 'Скопійовано', 'ok'); };
        var fail = function () { flash(btn, 'Не вдалося скопіювати'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text) ? done() : fail(); });
        } else {
            legacyCopy(text) ? done() : fail();
        }
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-copy-from]') : null;
        if (!b) return;
        e.preventDefault();
        var el = document.getElementById(b.getAttribute('data-copy-from'));
        if (!el) return;
        copyText(el.tagName === 'TEXTAREA' ? el.value : el.textContent, b);
    });

    /* ---- Фільтри знахідок ---- */
    var cards = Array.prototype.slice.call(document.querySelectorAll('.fcard'));
    var chips = Array.prototype.slice.call(document.querySelectorAll('.chip[data-sev]'));
    var sel = document.getElementById('catFilter');
    var emptyEl = document.getElementById('fEmpty');
    var countEl = document.getElementById('fCount');
    var curSev = 'all';
    var total = cards.length;

    function plural(n) {
        var m10 = n % 10, m100 = n % 100;
        if (m10 === 1 && m100 !== 11) return 'зауваження';
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'зауваження';
        return 'зауважень';
    }
    function apply() {
        var cat = sel ? sel.value : '';
        var counts = { all: 0, critical: 0, high: 0, medium: 0, low: 0 };
        var shown = 0;
        cards.forEach(function (c) {
            var inCat = !cat || c.getAttribute('data-cat') === cat;
            var sev = c.getAttribute('data-sev');
            if (inCat) { counts.all++; counts[sev] = (counts[sev] || 0) + 1; }
            var vis = inCat && (curSev === 'all' || sev === curSev);
            c.hidden = !vis;
            if (vis) shown++;
        });
        chips.forEach(function (ch) {
            var s = ch.getAttribute('data-sev');
            var n = counts[s] || 0;
            var cnt = ch.querySelector('.cnt');
            if (cnt) cnt.textContent = n;
            ch.classList.toggle('active', s === curSev);
            ch.disabled = (s !== 'all' && s !== curSev && n === 0);
        });
        if (emptyEl) emptyEl.hidden = shown > 0;
        if (countEl) countEl.textContent = (shown === total) ? (total + ' ' + plural(total)) : ('Показано ' + shown + ' з ' + total);
    }
    chips.forEach(function (ch) {
        ch.addEventListener('click', function () { curSev = ch.getAttribute('data-sev'); apply(); });
    });
    if (sel) sel.addEventListener('change', apply);

    document.addEventListener('click', function (e) {
        if (!e.target.closest) return;
        var a = e.target.closest('[data-filter-cat],[data-filter-sev]');
        if (!a || !cards.length) return;
        e.preventDefault();
        if (a.hasAttribute('data-filter-cat')) {
            if (sel) sel.value = a.getAttribute('data-filter-cat');
            curSev = 'all';
        } else {
            if (sel) sel.value = '';
            curSev = a.getAttribute('data-filter-sev');
        }
        apply();
        var target = document.getElementById('findings');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    var exp = document.getElementById('expandAll');
    var col = document.getElementById('collapseAll');
    if (exp) exp.addEventListener('click', function () { cards.forEach(function (c) { if (!c.hidden) c.open = true; }); });
    if (col) col.addEventListener('click', function () { cards.forEach(function (c) { c.open = false; }); });

    if (location.hash && /^#f-\d+$/.test(location.hash)) {
        var fc = document.querySelector(location.hash);
        if (fc && fc.tagName === 'DETAILS') fc.open = true;
    }
    /* Друк із браузера: розгорнути все, щоб нічого не загубилось */
    window.addEventListener('beforeprint', function () {
        document.querySelectorAll('details').forEach(function (d) { d.open = true; });
    });

<?php if ($radarChecks): ?>
    /* ---- Радар ---- */
    var radarData = <?= json_encode($radarChecks, $jsonFlags) ?>;
    var canvas = document.getElementById('radarChart');
    if (canvas && window.Chart) {
        var small = window.matchMedia && window.matchMedia('(max-width: 600px)').matches;
        new Chart(canvas, {
            type: 'radar',
            data: {
                labels: radarData.map(function (c) { return c.label; }),
                datasets: [{
                    label: 'Бал',
                    data: radarData.map(function (c) { return c.score; }),
                    backgroundColor: 'rgba(88,166,255,0.2)',
                    borderColor: 'rgba(88,166,255,1)',
                    pointBackgroundColor: 'rgba(88,166,255,1)',
                    borderWidth: 2
                }]
            },
            options: {
                maintainAspectRatio: false,
                layout: { padding: small ? 0 : 6 },
                scales: {
                    r: {
                        min: 0, max: 100,
                        ticks: { color: '#8b949e', backdropColor: 'transparent', stepSize: 25, font: { size: 10 } },
                        grid: { color: 'rgba(139,148,158,0.2)' },
                        angleLines: { color: 'rgba(139,148,158,0.2)' },
                        pointLabels: { color: '#e6edf3', font: { size: small ? 9 : 11 } }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { title: function (items) { var l = items[0].label; return Array.isArray(l) ? l.join(' ') : l; } } }
                }
            }
        });
    } else if (canvas) {
        canvas.parentNode.innerHTML = '<p class="muted">Не вдалося завантажити бібліотеку графіків.</p>';
    }
<?php endif; ?>

<?php if (!$jobFinal): ?>
    /* ---- Опитування статусу ---- */
    var jobId = <?= json_encode($jobId, $jsonFlags) ?>;
    var STATUS = <?= json_encode(['queued' => status_label_uk('queued'), 'running' => status_label_uk('running'), 'done' => status_label_uk('done'), 'error' => status_label_uk('error')], $jsonFlags) ?>;
    var STAGES = <?= json_encode(stage_map_uk(), $jsonFlags) ?>;
    var CHECKS = <?= json_encode(['Technical Foundations' => 'Технічна база', 'AI Crawler Access' => 'Доступ AI-ботів та індексація', 'Structured Data' => 'Структуровані дані', 'AI Citability' => 'Цитованість контенту', 'llms.txt Standard' => 'llms.txt', 'Brand Authority Signals' => 'Сутність бренду', 'XML Sitemap' => 'XML-карта сайту', 'Content Rendering' => 'Рендеринг та індексованість', 'E-E-A-T Signals' => 'E-E-A-T сигнали'], $jsonFlags) ?>;
    function stageUk(s) {
        s = (s || '').trim();
        if (!s) return 'В черзі';
        if (STAGES[s]) return STAGES[s];
        if (s.indexOf('Running: ') === 0) { var n = s.slice(9); return 'Перевірка: ' + (CHECKS[n] || n); }
        return s;
    }
    var fails = 0;
    var poll = setInterval(function () {
        fetch('api/status.php?id=' + encodeURIComponent(jobId), { cache: 'no-store' })
            .then(function (r) {
                return r.text().then(function (t) {
                    try { return JSON.parse(t); } catch (e) { throw new Error('HTTP ' + r.status); }
                });
            })
            .then(function (d) {
                fails = 0;
                if (d.status === 'done' || d.status === 'error') {
                    clearInterval(poll);
                    window.location.reload();
                    return;
                }
                var bar = document.getElementById('progBar');
                var pct = document.getElementById('progPct');
                var stg = document.getElementById('progStage');
                var pill = document.getElementById('statusPill');
                var p = parseInt(d.progress, 10) || 0;
                if (bar) bar.style.width = p + '%';
                if (pct) pct.textContent = p + '%';
                if (stg) stg.textContent = stageUk(d.stage);
                if (pill && d.status) {
                    pill.textContent = STATUS[d.status] || d.status;
                    pill.className = 'status-pill status-' + d.status;
                }
            })
            .catch(function (e) {
                fails++;
                if (fails >= 5) {
                    var stg = document.getElementById('progStage');
                    if (stg) stg.textContent = 'Немає зв\'язку із сервером (' + e.message + '). Оновіть сторінку пізніше.';
                }
            });
    }, 2000);
<?php endif; ?>
})();
</script>
</body>
</html>
