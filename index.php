<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/helpers.php';

$db = Database::get();
$showAll = isset($_GET['all']);
$jobs = $db->listJobs($showAll ? 1000 : 50);
$totalJobs = $db->countJobs();
$canDelete = geo_can_delete();
$csrf = $canDelete ? geo_csrf_token() : '';

$categories = [
    ['Доступ AI-ботів та індексація', 14, 'чи пускають robots.txt, сервер і захист (Cloudflare тощо) GPTBot, ClaudeBot, PerplexityBot та інших; чи не закрито Google-Extended у robots.txt; noindex, canonical.'],
    ['Рендеринг та індексованість', 10, 'чи є основний текст у HTML без JavaScript (AI-краулери здебільшого не виконують JS), мова сторінки, hreflang.'],
    ['Цитованість контенту', 20, 'чи є самодостатні абзаци з фактами, чіткі заголовки, списки, таблиці, FAQ - те, що AI охоче цитує.'],
    ['Структуровані дані', 12, 'JSON-LD Schema.org: Organization, WebSite, Product, Article, LocalBusiness, FAQ - залежно від типу сайту.'],
    ['E-E-A-T сигнали', 14, 'сторінки «Про нас» і «Контакти», автори, реквізити, відгуки, юридичні сторінки - те, що підтверджує довіру.'],
    ['Сутність бренду', 12, 'чи впізнають бренд як сутність: Wikidata, Вікіпедія, sameAs, профілі в соцмережах, узгоджена назва.'],
    ['Технічна база', 10, 'HTTPS, швидкість відповіді, HTTP/2, кешування, заголовки безпеки, мобільний viewport.'],
    ['XML-карта сайту', 5, 'наявність sitemap.xml, посилання в robots.txt, валідність, дати lastmod.'],
    ['llms.txt', 3, 'файл-підказка для мовних моделей зі стислим описом сайту і ключовими посиланнями.'],
];
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GEO Audit</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="app">
    <h1>GEO Audit</h1>
    <span class="badge-version">v2.0</span>
</header>
<div class="container">

<div class="card">
    <h2>Новий аудит</h2>
    <form id="auditForm" class="row" novalidate>
        <input type="url" name="url" id="url" placeholder="https://example.com" required autocomplete="url" inputmode="url">
        <button type="submit" class="btn" id="submitBtn">Запустити аудит</button>
    </form>
    <p class="muted spacer small" style="margin-bottom:0">
        Перевіряємо: доступ AI-ботів · рендеринг без JavaScript · цитованість контенту · Schema.org · E-E-A-T · сутність бренду · технічну базу · sitemap.xml · llms.txt.
        Аналізуємо головну і до 4 внутрішніх сторінок, тип сайту визначаємо автоматично. Триває близько 1-2 хвилин.
    </p>
    <div id="formStatus" class="spacer form-status" hidden></div>
</div>

<div class="card" id="uaHelpCard">
    <details class="help-details">
        <summary>&#9432; Як це працює</summary>
        <div class="help-body">
            <p style="margin-top:0">
                <strong>GEO Audit</strong> показує, наскільки сайт готовий до пошуку через AI (GEO - Generative Engine Optimization):
                чи можуть його прочитати AI-краулери, чи зручно його цитувати в ChatGPT, Perplexity, Google AI Overviews і Copilot,
                і чи достатньо на ньому сигналів довіри. Аудит підходить для будь-якого сайту: інтернет-магазину, локального бізнесу, медіа, SaaS, послуг чи корпоративного сайту.
            </p>

            <h3>9 категорій перевірки</h3>
            <div class="table-wrap">
                <table class="cat-table">
                    <thead><tr><th>Категорія</th><th>Що перевіряємо</th><th class="w">Вага</th></tr></thead>
                    <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr><td><?= h($cat[0]) ?></td><td class="muted"><?= h($cat[2]) ?></td><td class="w"><?= (int)$cat[1] ?>%</td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small">Загальний бал 0-100 - це зважена сума категорій. Якщо категорію не вдалося оцінити (наприклад, сайт не віддав контент), вона позначається «н/д» і не впливає на бал.</p>

            <h3>Що отримаєте у звіті</h3>
            <ul>
                <li><strong>Тип сайту визначається автоматично</strong> (магазин, локальний бізнес, медіа, SaaS, послуги, корпоративний, особистий сайт експерта) - рекомендації й розмітка підбираються під нього.</li>
                <li><strong>Аналіз кількох сторінок:</strong> головна і до 4 внутрішніх - «Про нас», «Контакти», стаття, товар або послуга.</li>
                <li><strong>Кожне зауваження з доказами:</strong> що саме знайдено на сайті, чому це важливо, блоки «Як зараз» і «Як треба», готовий код (JSON-LD, robots.txt, HTML, nginx) і спосіб перевірити результат.</li>
                <li><strong>AI-аналіз:</strong> короткий висновок, оцінка E-E-A-T і якості контенту, топ-пріоритети, швидкі перемоги та 8-10 тестових запитів для ручного моніторингу видимості в AI.</li>
                <li><strong>Експорт:</strong> PDF для клієнта і JSON із сирими даними.</li>
            </ul>

            <h3>Як користуватись</h3>
            <ol>
                <li>Вставте адресу сайту в поле «Новий аудит», наприклад <code>https://example.com</code>. Протокол можна не вказувати.</li>
                <li>Натисніть <strong>«Запустити аудит»</strong> і зачекайте 1-2 хвилини - прогрес видно на сторінці звіту.</li>
                <li>Почніть із критичних і високих зауважень: у кожному є готове рішення. Тестові запити з AI-аналізу збережіть як базову точку і перевірте їх повторно через 4-6 тижнів після змін.</li>
            </ol>

            <p class="small" style="color:var(--good);margin-bottom:0">
                &#10003; Детерміновані перевірки працюють завжди. AI-аналіз додається, якщо на сервері налаштовано ключ моделі; без нього звіт усе одно повний.
            </p>
        </div>
    </details>
</div>

<div class="card">
    <div class="card-head">
        <h2>Аудити (<span id="jobsShown"><?= count($jobs) ?></span><?= $totalJobs > count($jobs) ? ' з ' . (int)$totalJobs : '' ?>)</h2>
        <?php if ($totalJobs > count($jobs)): ?>
            <a href="index.php?all=1">Показати всі</a>
        <?php elseif ($showAll && $totalJobs > 50): ?>
            <a href="index.php">Лише останні 50</a>
        <?php endif; ?>
    </div>
    <?php if (empty($jobs)): ?>
        <p class="muted">Аудитів ще немає. Запустіть перший аудит вище.</p>
    <?php else: ?>
    <?php if ($canDelete): ?>
        <div class="jobs-toolbar">
            <button type="button" class="btn btn-danger btn-sm" id="delSelected" disabled>Видалити вибрані</button>
            <span class="muted small" id="delStatus" role="status"></span>
        </div>
    <?php endif; ?>
    <div class="table-wrap">
    <table class="jobs-table">
        <thead>
            <tr>
                <?php if ($canDelete): ?><th class="col-check"><input type="checkbox" id="checkAll" aria-label="Вибрати всі"></th><?php endif; ?>
                <th>URL</th>
                <th>Статус</th>
                <th>Бал</th>
                <th class="hide-sm">Запущено</th>
                <th class="hide-sm"></th>
                <?php if ($canDelete): ?><th class="col-del"></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($jobs as $j): $jStatus = (string)($j['status'] ?? 'queued'); ?>
            <tr data-id="<?= h((string)$j['id']) ?>">
                <?php if ($canDelete): ?>
                    <td class="col-check"><input type="checkbox" class="job-check" value="<?= h((string)$j['id']) ?>" aria-label="Вибрати аудит <?= h(short_url((string)$j['url'])) ?>"></td>
                <?php endif; ?>
                <td class="url-cell"><a href="audit.php?id=<?= h(rawurlencode((string)$j['id'])) ?>" style="font-weight:500"><?= h(short_url((string)$j['url'])) ?></a></td>
                <td><span class="status-pill status-<?= h($jStatus) ?>"><?= h(status_label_uk($jStatus)) ?></span></td>
                <td>
                    <?php if ($j['score'] !== null && isset($j['coverage']) && $j['coverage'] !== null && (float)$j['coverage'] < 0.6): ?>
                        <span class="score-pill na" title="Неповний бал: оцінено лише <?= (int)round((float)$j['coverage'] * 100) ?>% ваги категорій, решту не вдалося перевірити"><?= (int)$j['score'] ?>/100 неповний</span>
                    <?php elseif ($j['score'] !== null): ?>
                        <span class="score-pill <?= tier_class((int)$j['score']) ?>"><?= (int)$j['score'] ?>/100</span>
                    <?php elseif ($jStatus === 'running'): ?>
                        <span class="muted"><?= (int)$j['progress'] ?>%</span>
                    <?php else: ?>
                        <span class="muted">-</span>
                    <?php endif; ?>
                </td>
                <td class="muted hide-sm" style="white-space:nowrap"><?= h(fmt_time($j['created_at'])) ?></td>
                <td class="hide-sm" style="white-space:nowrap"><a href="audit.php?id=<?= h(rawurlencode((string)$j['id'])) ?>">Переглянути &rarr;</a></td>
                <?php if ($canDelete): ?>
                    <td class="col-del"><button type="button" class="btn-icon job-del" title="Видалити аудит" aria-label="Видалити аудит <?= h(short_url((string)$j['url'])) ?>">&#128465;</button></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div>
<script>
(function () {
    'use strict';
    var form = document.getElementById('auditForm');
    var input = document.getElementById('url');
    var btn = document.getElementById('submitBtn');
    var statusEl = document.getElementById('formStatus');
    var ERRORS = {
        'Invalid URL': 'Некоректна адреса. Приклад: https://example.com',
        'Invalid host': 'Некоректний домен. Перевірте адресу сайту.',
        'POST only': 'Сервер очікує POST-запит.'
    };

    function show(cls, text, details) {
        statusEl.hidden = false;
        statusEl.textContent = '';
        var p = document.createElement('div');
        if (cls) p.className = cls;
        p.textContent = text;
        statusEl.appendChild(p);
        if (details) {
            var pre = document.createElement('pre');
            pre.textContent = details;
            statusEl.appendChild(pre);
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var url = input.value.trim();
        if (!url) {
            show('tier-critical', 'Вкажіть адресу сайту, наприклад https://example.com');
            input.focus();
            return;
        }
        btn.disabled = true;
        show('muted', 'Запускаємо аудит...');
        var fd = new FormData();
        fd.append('url', url);
        fetch('api/start.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                return r.text().then(function (body) {
                    var data = null;
                    try { data = JSON.parse(body); } catch (err) { data = null; }
                    return { r: r, body: body, data: data };
                });
            })
            .then(function (res) {
                var d = res.data;
                if (!d || typeof d !== 'object') {
                    var snippet = (res.body || '').replace(/\s+/g, ' ').trim().slice(0, 200);
                    show('tier-critical', 'Сервер повернув неочікувану відповідь (HTTP ' + res.r.status + '). Можливо, сесія завершилась - оновіть сторінку й спробуйте ще раз.', snippet || '(порожня відповідь)');
                    btn.disabled = false;
                    return;
                }
                if (d.ok && d.job_id) {
                    var href = 'audit.php?id=' + encodeURIComponent(d.job_id);
                    statusEl.hidden = false;
                    statusEl.textContent = '';
                    var a = document.createElement('a');
                    a.href = href;
                    a.textContent = 'Аудит запущено - відкриваємо звіт...';
                    statusEl.appendChild(a);
                    setTimeout(function () { window.location.href = href; }, 400);
                    return;
                }
                var msg = d.error ? (ERRORS[d.error] || d.error) : 'невідома помилка';
                show('tier-critical', 'Помилка (HTTP ' + res.r.status + '): ' + msg);
                btn.disabled = false;
            })
            .catch(function (err) {
                show('tier-critical', 'Помилка мережі: ' + (err && err.message ? err.message : err) + '. Перевірте з\'єднання і спробуйте ще раз.');
                btn.disabled = false;
            });
    });
})();
</script>
<?php if ($canDelete && !empty($jobs)): ?>
<script>
(function () {
    'use strict';
    var CSRF = <?= json_encode($csrf) ?>;
    var delBtn = document.getElementById('delSelected');
    var statusEl = document.getElementById('delStatus');
    var checkAll = document.getElementById('checkAll');
    var shownEl = document.getElementById('jobsShown');

    function checks() { return Array.prototype.slice.call(document.querySelectorAll('.job-check')); }
    function selected() { return checks().filter(function (c) { return c.checked; }).map(function (c) { return c.value; }); }
    function refresh() {
        var n = selected().length;
        delBtn.disabled = n === 0;
        delBtn.textContent = n ? 'Видалити вибрані (' + n + ')' : 'Видалити вибрані';
        var all = checks();
        checkAll.checked = all.length > 0 && n === all.length;
        checkAll.indeterminate = n > 0 && n < all.length;
    }
    function plural(n) {
        var m10 = n % 10, m100 = n % 100;
        if (m10 === 1 && m100 !== 11) return 'аудит';
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'аудити';
        return 'аудитів';
    }
    function remove(ids) {
        if (!ids.length) return;
        if (!window.confirm('Видалити ' + ids.length + ' ' + plural(ids.length) + '? Звіти буде видалено назавжди.')) return;
        delBtn.disabled = true;
        statusEl.textContent = 'Видаляю...';
        var fd = new FormData();
        fd.append('csrf', CSRF);
        ids.forEach(function (id) { fd.append('ids[]', id); });
        fetch('api/delete.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); })
            .then(function (d) {
                if (!d.ok) { statusEl.textContent = 'Не вдалося видалити: ' + (d.error || 'невідома помилка'); refresh(); return; }
                (d.deleted || []).forEach(function (id) {
                    var tr = document.querySelector('tr[data-id="' + id + '"]');
                    if (tr) tr.parentNode.removeChild(tr);
                });
                shownEl.textContent = String(checks().length);
                var skipped = (d.skipped || []).length;
                statusEl.textContent = 'Видалено: ' + (d.deleted || []).length + (skipped ? '. Не видалено ' + skipped + ' (аудит ще виконується)' : '') + '.';
                refresh();
            })
            .catch(function (e) { statusEl.textContent = 'Помилка мережі: ' + (e && e.message ? e.message : e); refresh(); });
    }

    document.addEventListener('change', function (e) {
        if (e.target === checkAll) checks().forEach(function (c) { c.checked = checkAll.checked; });
        if (e.target === checkAll || e.target.classList.contains('job-check')) refresh();
    });
    document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.job-del') : null;
        if (b) remove([b.closest('tr').getAttribute('data-id')]);
    });
    delBtn.addEventListener('click', function () { remove(selected()); });
})();
</script>
<?php endif; ?>
</body>
</html>
