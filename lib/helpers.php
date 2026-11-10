<?php
declare(strict_types=1);

/* ============================================================
 * Базові хелпери (використовуються з v1)
 * ============================================================ */

function tier_class(int $score): string
{
    if ($score >= 80) return 'good';
    if ($score >= 60) return 'moderate';
    if ($score >= 40) return 'poor';
    return 'critical';
}

function tier_label(int $score): string
{
    if ($score >= 80) return 'GOOD';
    if ($score >= 60) return 'MODERATE';
    if ($score >= 40) return 'POOR';
    return 'CRITICAL';
}

function h($s): string
{
    if (is_array($s) || is_object($s)) {
        $s = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_time($ts): string
{
    if (!$ts) return '-';
    return date('d.m.Y H:i', (int)$ts);
}

function fmt_duration($from, $to): string
{
    if (!$from || !$to) return '-';
    return fmt_seconds_uk((int)$to - (int)$from);
}

/* ============================================================
 * Українські підписи
 * ============================================================ */

function fmt_seconds_uk(int $s): string
{
    if ($s < 0) $s = 0;
    if ($s < 60) return $s . ' с';
    $m = intdiv($s, 60);
    if ($m < 60) return $m . ' хв ' . ($s % 60) . ' с';
    return intdiv($m, 60) . ' год ' . ($m % 60) . ' хв';
}

function fmt_iso_uk($iso): string
{
    if (!is_string($iso) || $iso === '') return '-';
    $t = strtotime($iso);
    return $t ? date('d.m.Y H:i', $t) : '-';
}

function tier_label_uk(int $score): string
{
    if ($score >= 80) return 'Добре';
    if ($score >= 60) return 'Помірно';
    if ($score >= 40) return 'Слабко';
    return 'Критично';
}

function severity_label_uk(string $sev): string
{
    $map = ['critical' => 'Критично', 'high' => 'Високий', 'medium' => 'Середній', 'low' => 'Низький'];
    return $map[strtolower($sev)] ?? 'Низький';
}

/** Множина для чипів фільтра: «Критичні», «Високі»... */
function severity_plural_uk(string $sev): string
{
    $map = ['critical' => 'Критичні', 'high' => 'Високі', 'medium' => 'Середні', 'low' => 'Низькі'];
    return $map[strtolower($sev)] ?? 'Інші';
}

function severity_order(string $sev): int
{
    $map = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    return $map[$sev] ?? 9;
}

function role_label_uk(string $role, string $siteType = ''): string
{
    if ($role === 'about' && $siteType === 'personal') return 'Про мене';
    $map = [
        'home' => 'Головна',
        'about' => 'Про нас',
        'contact' => 'Контакти',
        'article' => 'Стаття',
        'product' => 'Товар',
        'category' => 'Категорія',
        'service' => 'Послуга',
        'other' => 'Інша',
    ];
    return $map[strtolower($role)] ?? 'Інша';
}

function status_label_uk(string $status): string
{
    $map = ['queued' => 'В черзі', 'running' => 'Виконується', 'done' => 'Готово', 'error' => 'Помилка'];
    return $map[strtolower($status)] ?? $status;
}

/** Нормалізує зусилля до S/M/L. Розуміє також Low/Medium/High та «M (1-2 години...)». */
function effort_code(string $effort): string
{
    $e = trim($effort);
    if ($e === '') return '';
    if (preg_match('/^([SML])\b/u', $e, $m)) return $m[1];
    $l = mb_strtolower($e, 'UTF-8');
    if (preg_match('/^(low|easy|quick|низьк|легк|мал)/u', $l)) return 'S';
    if (preg_match('/^(medium|mid|moderate|середн)/u', $l)) return 'M';
    if (preg_match('/^(high|hard|large|висок|велик|складн)/u', $l)) return 'L';
    return '';
}

function effort_label_uk(string $effort): string
{
    $map = ['S' => 'до 2 годин', 'M' => 'до 2 днів', 'L' => 'більше 2 днів'];
    $code = effort_code($effort);
    return $map[$code] ?? '';
}

function impact_label_uk(string $impact): string
{
    $l = mb_strtolower(trim($impact), 'UTF-8');
    if ($l === '') return '';
    if (preg_match('/^(critical|критичн)/u', $l)) return 'Критичний';
    if (preg_match('/^(high|висок)/u', $l)) return 'Високий';
    if (preg_match('/^(medium|moderate|середн)/u', $l)) return 'Середній';
    if (preg_match('/^(low|низьк)/u', $l)) return 'Низький';
    return trim($impact);
}

/** Клас кольору для бейджа впливу. */
function impact_class(string $impactUk): string
{
    $map = ['Критичний' => 'critical', 'Високий' => 'high', 'Середній' => 'medium', 'Низький' => 'low'];
    return $map[$impactUk] ?? 'medium';
}

/** Назви v1-перевірок (англійські) -> українські. v2-назви повертаються як є. */
function check_name_uk(string $name): string
{
    $map = [
        'Technical Foundations' => 'Технічна база',
        'AI Crawler Access' => 'Доступ AI-ботів та індексація',
        'Structured Data' => 'Структуровані дані',
        'AI Citability' => 'Цитованість контенту',
        'llms.txt Standard' => 'llms.txt',
        'Brand Authority Signals' => 'Сутність бренду',
        'XML Sitemap' => 'XML-карта сайту',
        'Content Rendering' => 'Рендеринг та індексованість',
        'E-E-A-T Signals' => 'E-E-A-T сигнали',
    ];
    return $map[$name] ?? $name;
}

/** Етапи прогресу з рушія (англ. у v1) -> українські. */
function stage_map_uk(): array
{
    return [
        'queued' => 'В черзі',
        'Queued' => 'В черзі',
        'Fetching homepage' => 'Завантаження головної сторінки',
        'Fetching robots.txt' => 'Завантаження robots.txt',
        'Sampling pages' => 'Вибір внутрішніх сторінок',
        'Detecting site type' => 'Визначення типу сайту',
        'AI insights' => 'AI-аналіз',
        'Generating report' => 'Формування звіту',
        'completed' => 'Завершено',
    ];
}

function stage_label_uk(?string $stage): string
{
    $stage = trim((string)$stage);
    if ($stage === '') return 'В черзі';
    $map = stage_map_uk();
    if (isset($map[$stage])) return $map[$stage];
    if (strpos($stage, 'Running: ') === 0) {
        return 'Перевірка: ' . check_name_uk(substr($stage, 9));
    }
    return $stage;
}

/** Підписи відомих sub_scores (для v1-звітів без sub_labels). */
function sub_label_dictionary(): array
{
    return [
        // v1 Technical
        'https' => 'HTTPS',
        'security_headers' => 'Заголовки безпеки',
        'http2' => 'HTTP/2+',
        'performance_ttfb' => 'Швидкість відповіді (TTFB)',
        'cache' => 'Кешування',
        'security_disclosure' => 'Приховування версій сервера',
        'mobile_viewport' => 'Мобільний viewport',
        // v1 Crawler
        'robots_txt_available' => 'robots.txt доступний',
        'ai_bot_access' => 'Доступ AI-ботів',
        'wildcard_policy' => 'Загальне правило (User-agent: *)',
        'server_access' => 'Доступ на рівні сервера',
        // v1 Schema
        'json_ld_present' => 'JSON-LD на сторінці',
        'organization' => 'Organization',
        'website' => 'WebSite',
        'breadcrumbs' => 'BreadcrumbList',
        'same_as_links' => 'Посилання sameAs',
        // v1 Citability
        'content_volume' => 'Обсяг контенту',
        'citable_passages' => 'Абзаци для цитування',
        'heading_structure' => 'Структура заголовків',
        'title_meta' => 'Title та description',
        'social_meta' => 'OpenGraph',
        'qa_format' => 'Формат питання-відповідь',
        // v1 llms.txt
        'exists' => 'Файл існує',
        'has_title' => 'Є заголовок',
        'has_summary' => 'Є короткий опис',
        'has_links' => 'Є посилання',
        // v1 Brand
        'brand_guess' => 'Назва бренду визначена',
        'wikipedia_en' => 'Wikipedia (EN)',
        'wikipedia_uk' => 'Вікіпедія (UK)',
        'wikidata' => 'Wikidata',
        'social_profiles' => 'Профілі в соцмережах',
        // v1 Sitemap
        'robots_reference' => 'Посилання в robots.txt',
        'entry_count' => 'Кількість URL',
        'has_lastmod' => 'Дати lastmod',
        'valid_xml' => 'Валідний XML',
        // v1 Rendering
        'server_rendered' => 'Контент у HTML (SSR)',
        'noscript_fallback' => 'Фолбек noscript',
        'js_ratio' => 'Частка JavaScript',
        'canonical' => 'Canonical',
        'lang_attribute' => 'Атрибут lang',
        'hreflang' => 'hreflang',
        // v1 E-E-A-T
        'about_page' => 'Сторінка «Про нас»',
        'contact_info' => 'Контактні дані',
        'legal_pages' => 'Юридичні сторінки',
        'author_signals' => 'Сигнали автора',
        'trust_signals' => 'Сигнали довіри',
        'professionalism' => 'Професійність',
        'call_to_action' => 'Заклик до дії',
    ];
}

/** Підпис для ключа sub_scores: sub_labels -> словник -> ключ з пробілами. */
function sub_label(array $check, string $key): string
{
    $labels = $check['sub_labels'] ?? [];
    if (is_array($labels) && isset($labels[$key]) && is_scalar($labels[$key]) && trim((string)$labels[$key]) !== '') {
        return (string)$labels[$key];
    }
    $dict = sub_label_dictionary();
    if (isset($dict[$key])) return $dict[$key];
    return str_replace('_', ' ', $key);
}

function block_reason_label_uk(?string $reason): string
{
    $r = strtolower(trim((string)$reason));
    if ($r === '') return 'невідома причина';
    $map = [
        'cloudflare' => 'Cloudflare',
        'ddos-guard' => 'DDoS-Guard',
        'imperva' => 'Imperva',
        'incapsula' => 'Imperva Incapsula',
        'akamai' => 'Akamai',
        'sucuri' => 'Sucuri',
        'captcha' => 'CAPTCHA',
        'waf' => 'WAF',
    ];
    if (isset($map[$r])) return $map[$r];
    if (preg_match('/^http_(\d{3})$/', $r, $m)) return 'HTTP ' . $m[1];
    return (string)$reason;
}

/** Повідомлення AI-клієнта v1 (англ.) -> українські. */
function ai_message_uk(string $msg): string
{
    $msg = trim($msg);
    if ($msg === '') return '';
    if (stripos($msg, 'AI insights pending') === 0 || stripos($msg, 'ANTHROPIC_API_KEY') !== false) {
        return 'AI-аналіз вимкнено: на сервері не налаштовано ключ API. Детерміновані перевірки нижче працюють у повному обсязі.';
    }
    if (stripos($msg, 'AI call failed') === 0) {
        return 'Не вдалося отримати відповідь AI-моделі. Детерміновані перевірки нижче працюють у повному обсязі.';
    }
    if (stripos($msg, 'non-JSON') !== false) {
        return 'AI-модель повернула відповідь у неочікуваному форматі, тому AI-аналіз не показано.';
    }
    return $msg;
}

/** Повідомлення про помилку задачі (рушій пише англійською) -> українською, технічні деталі в дужках. */
function job_error_uk(?string $err): string
{
    $e = trim((string)$err);
    if ($e === '') return 'Невідома помилка.';
    if (preg_match('/^Cannot reach URL:?\s*(.*)$/is', $e, $m)) {
        return 'Не вдалося відкрити сайт' . (trim($m[1]) !== '' ? ' (' . trim($m[1]) . ')' : '') . '. Перевірте адресу і доступність сайту.';
    }
    if (stripos($e, 'timed out') !== false || stripos($e, 'timeout') !== false) {
        return 'Сайт не відповів вчасно (' . $e . '). Спробуйте пізніше.';
    }
    return $e;
}

function plural_uk(int $n, string $one, string $few, string $many): string
{
    $n = abs($n);
    $mod10 = $n % 10;
    $mod100 = $n % 100;
    if ($mod10 === 1 && $mod100 !== 11) return $one;
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return $few;
    return $many;
}

function findings_count_uk(int $n): string
{
    return $n . ' ' . plural_uk($n, 'зауваження', 'зауваження', 'зауважень');
}

function site_type_label_uk(string $type): string
{
    $map = [
        'ecommerce' => 'Інтернет-магазин',
        'local' => 'Локальний бізнес',
        'media' => 'Медіа / блог',
        'saas' => 'SaaS / продукт',
        'services' => 'Послуги / B2B',
        'corporate' => 'Корпоративний сайт',
        'personal' => 'Особистий сайт експерта',
    ];
    return $map[$type] ?? '';
}

/** Короткий вигляд URL для списків: без протоколу. */
function short_url(string $url): string
{
    $s = preg_replace('#^https?://(www\.)?#i', '', $url) ?? $url;
    return preg_replace('#^([^/]+)/$#', '$1', $s) ?? $s;
}

/**
 * Евристика: чи схожий текст на код (HTML, JSON, robots.txt, конфіг сервера, HTTP-заголовки).
 * Однорядкова проза, що лише згадує тег («2 теги <h1>»), кодом не вважається.
 */
function looks_like_code(string $s): bool
{
    $t = trim($s);
    if ($t === '') return false;
    // Починається як код
    if (preg_match('~^(<[a-z!?/]|[{\[]\s*("|$|\{)|"[@\w-]+"\s*:\s*[{\["\d]|(User-agent|Disallow|Allow|Sitemap|Crawl-delay)\s*:|(add_header|location|server_name|RewriteRule|RewriteCond|Header\s+(always\s+)?set)\b)~i', $t)) {
        return true;
    }
    if (strpos($t, "\n") === false) return false;
    // Багаторядковий текст з ознаками коду
    return (bool)preg_match(
        '~^\s*</?[a-z][a-z0-9-]*(\s[^>]*)?/?>|"@(type|context)"|^\s*(User-agent|Disallow|Allow|Sitemap|Crawl-delay)\s*:|^\s*(add_header|location|server_name|RewriteRule|RewriteCond)\b|^\s*[A-Za-z][A-Za-z0-9-]{1,40}:\s\S~mi',
        $t
    );
}

/** Розгортає довільне значення у рядок або список рядків (для rich-полів знахідок). */
function rich_value($v)
{
    if ($v === null || $v === false) return '';
    if (is_scalar($v)) return trim((string)$v);
    if (is_array($v)) {
        $out = [];
        foreach ($v as $item) {
            if (is_scalar($item)) {
                $t = trim((string)$item);
                if ($t !== '') $out[] = $t;
            } elseif (is_array($item)) {
                $t = trim(implode(' ', array_filter(array_map(function ($x) { return is_scalar($x) ? (string)$x : ''; }, $item))));
                if ($t !== '') $out[] = $t;
            }
        }
        if (count($out) === 1) return $out[0];
        return $out;
    }
    return '';
}

function rich_empty($v): bool
{
    return $v === '' || $v === [] || $v === null;
}

/** HTML для rich-поля: список, код (pre) або звичайний текст з переносами. */
function rich_html($v, bool $codeAware = false): string
{
    if (is_array($v)) {
        $li = '';
        foreach ($v as $item) $li .= '<li>' . h($item) . '</li>';
        return '<ul class="rich-list">' . $li . '</ul>';
    }
    $s = (string)$v;
    if ($codeAware && looks_like_code($s)) {
        return '<pre class="snippet">' . h($s) . '</pre>';
    }
    return '<div class="rich-text">' . h($s) . '</div>';
}

/** Мультирядкові підписи для радара Chart.js. */
function radar_label(string $name, int $max = 16): array
{
    if (mb_strlen($name, 'UTF-8') <= $max) return [$name];
    $words = preg_split('/\s+/u', $name) ?: [$name];
    $lines = [];
    $cur = '';
    foreach ($words as $w) {
        $try = $cur === '' ? $w : $cur . ' ' . $w;
        if (mb_strlen($try, 'UTF-8') > $max && $cur !== '') {
            $lines[] = $cur;
            $cur = $w;
        } else {
            $cur = $try;
        }
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}

/* ============================================================
 * Нормалізація звіту (v1 + v2) для в'юх
 * ============================================================ */

function _arr($v): array
{
    return is_array($v) ? $v : [];
}

function _str($v): string
{
    if (is_scalar($v)) return trim((string)$v);
    return '';
}

function _score($v): ?int
{
    if (!is_numeric($v)) return null;
    return max(0, min(100, (int)round((float)$v)));
}

function normalize_finding($f): ?array
{
    if (is_string($f)) $f = ['text' => $f];
    if (!is_array($f)) return null;
    $sev = strtolower(_str($f['severity'] ?? ''));
    $aliases = ['info' => 'low', 'notice' => 'low', 'warning' => 'medium', 'warn' => 'medium', 'error' => 'high', 'blocker' => 'critical'];
    if (isset($aliases[$sev])) $sev = $aliases[$sev];
    if (!in_array($sev, ['critical', 'high', 'medium', 'low'], true)) $sev = 'low';

    $text = _str($f['text'] ?? ($f['title'] ?? ''));
    $fix = rich_value($f['fix'] ?? '');
    if ($text === '' && rich_empty($fix)) return null;
    if ($text === '') {
        $text = is_array($fix) ? (string)$fix[0] : $fix;
        $fix = '';
    }
    $code = $f['code'] ?? '';
    if (is_array($code)) $code = implode("\n\n", array_filter(array_map(function ($x) { return is_scalar($x) ? (string)$x : ''; }, $code)));

    return [
        'id' => _str($f['id'] ?? ''),
        'severity' => $sev,
        'text' => $text,
        'evidence' => rich_value($f['evidence'] ?? ''),
        'why' => rich_value($f['why'] ?? ''),
        'fix' => $fix,
        'bad' => rich_value($f['bad'] ?? ''),
        'good' => rich_value($f['good'] ?? ''),
        'code' => rtrim(is_scalar($code) ? (string)$code : ''),
        'verify' => rich_value($f['verify'] ?? ''),
        'effort' => effort_code(_str($f['effort'] ?? '')),
    ];
}

function _score_note($v): array
{
    if (is_numeric($v)) return ['score' => _score($v), 'notes' => ''];
    if (is_string($v)) return ['score' => null, 'notes' => trim($v)];
    $v = _arr($v);
    $notes = $v['notes'] ?? ($v['note'] ?? ($v['summary'] ?? ''));
    $notes = rich_value($notes);
    if (is_array($notes)) $notes = implode("\n", $notes);
    return ['score' => _score($v['score'] ?? null), 'notes' => (string)$notes];
}

function _string_list($v, array $keys = ['text', 'prompt', 'query', 'action', 'title']): array
{
    if (is_string($v)) {
        $v = trim($v);
        return $v === '' ? [] : [$v];
    }
    $out = [];
    foreach (_arr($v) as $item) {
        if (is_scalar($item)) {
            $t = trim((string)$item);
        } elseif (is_array($item)) {
            $t = '';
            foreach ($keys as $k) {
                if (isset($item[$k]) && is_scalar($item[$k]) && trim((string)$item[$k]) !== '') { $t = trim((string)$item[$k]); break; }
            }
        } else {
            $t = '';
        }
        if ($t !== '') $out[] = $t;
    }
    return $out;
}

function normalize_ai($ai): array
{
    $ai = _arr($ai);
    $s = _arr($ai['sections'] ?? null);

    $prioRaw = $s['top_priorities'] ?? ($s['top_3_priorities'] ?? ($s['priorities'] ?? []));
    $priorities = [];
    foreach (_arr($prioRaw) as $p) {
        if (is_string($p)) $p = ['action' => $p];
        if (!is_array($p)) continue;
        $action = _str($p['action'] ?? ($p['title'] ?? ''));
        if ($action === '') continue;
        $impact = impact_label_uk(_str($p['impact'] ?? ''));
        $priorities[] = [
            'action' => $action,
            'impact' => $impact,
            'impact_class' => impact_class($impact),
            'effort' => effort_code(_str($p['effort'] ?? '')),
            'effort_raw' => _str($p['effort'] ?? ''),
            'why' => _str($p['why'] ?? ''),
            'example' => _str($p['example'] ?? ''),
        ];
    }

    $summary = $s['executive_summary'] ?? '';
    if (is_array($summary)) $summary = implode("\n\n", _string_list($summary));

    $res = [
        'enabled' => !empty($ai['enabled']),
        'model' => _str($ai['model'] ?? ''),
        'message' => ai_message_uk(_str($ai['message'] ?? '')),
        'summary' => trim((string)$summary),
        'eeat' => _score_note($s['eeat_assessment'] ?? null),
        'content' => _score_note($s['content_quality'] ?? null),
        'priorities' => $priorities,
        'quick_wins' => _string_list($s['quick_wins'] ?? []),
        'strategic' => _string_list($s['strategic_recommendations'] ?? []),
        'test_prompts' => _string_list($s['test_prompts'] ?? []),
    ];
    $res['has_content'] = $res['summary'] !== '' || $res['priorities'] || $res['quick_wins'] || $res['strategic']
        || $res['test_prompts'] || $res['eeat']['score'] !== null || $res['content']['score'] !== null
        || $res['eeat']['notes'] !== '' || $res['content']['notes'] !== '';
    return $res;
}

function normalize_site($site): ?array
{
    $site = _arr($site);
    $type = _str($site['type'] ?? '');
    if ($type === '' && _str($site['type_label'] ?? '') === '') return null;
    $conf = $site['confidence'] ?? null;
    $conf = is_numeric($conf) ? (float)$conf : null;
    if ($conf !== null && $conf > 1) $conf = $conf / 100;
    $label = _str($site['type_label'] ?? '');
    if ($label === '') $label = site_type_label_uk($type) ?: $type;
    $langs = _string_list($site['languages'] ?? []);
    return [
        'type' => $type,
        'type_label' => $label,
        'confidence' => $conf !== null ? max(0.0, min(1.0, $conf)) : null,
        'signals' => _string_list($site['signals'] ?? []),
        'brand' => _str($site['brand'] ?? ''),
        'host' => _str($site['host'] ?? ''),
        'lang' => _str($site['lang'] ?? ''),
        'languages' => $langs,
    ];
}

function normalize_samples($samples): array
{
    $out = [];
    foreach (_arr($samples) as $s) {
        if (!is_array($s)) continue;
        $url = _str($s['url'] ?? '');
        if ($url === '') continue;
        $status = $s['status'] ?? null;
        if (!is_numeric($status) && isset($s['res']['status'])) $status = $s['res']['status'];
        $out[] = [
            'role' => _str($s['role'] ?? 'other') ?: 'other',
            'url' => $url,
            'status' => is_numeric($status) ? (int)$status : null,
            'title' => html_entity_decode(_str($s['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }
    return $out;
}

function normalize_access($access): ?array
{
    $a = _arr($access);
    if (!$a) return null;
    $fetched = _str($a['fetched_as'] ?? '');
    return [
        'homepage_status' => is_numeric($a['homepage_status'] ?? null) ? (int)$a['homepage_status'] : null,
        'fetched_as' => $fetched,
        'blocked' => !empty($a['blocked']),
        'block_reason' => _str($a['block_reason'] ?? '') ?: null,
        'alt_agent' => _str($a['alt_agent'] ?? '') ?: null,
        'lang_switch' => is_array($a['lang_switch'] ?? null) && _str($a['lang_switch']['to'] ?? '') !== ''
            ? ['from' => _str($a['lang_switch']['from'] ?? ''), 'to' => _str($a['lang_switch']['to'])] : null,
    ];
}

/**
 * Яку частку загальної ваги покрили оцінені перевірки. Якщо сайт заблокував бота і контентні
 * перевірки пропущено, бал рахується лише з решти й виглядає вищим, ніж є насправді.
 * low = оцінено менше 60% ваги: такий бал не можна порівнювати з повними аудитами.
 */
function score_coverage(array $checks): array
{
    $total = 0.0; $rated = 0.0; $n = 0; $nRated = 0;
    foreach ($checks as $c) {
        $w = (float)($c['weight'] ?? 0);
        $n++;
        $total += $w;
        if (($c['status'] ?? 'ok') === 'ok' && ($c['score'] ?? null) !== null) { $nRated++; $rated += $w; }
    }
    // звіти без ваг (v1): рахуємо за кількістю перевірок
    $ratio = $total > 0 ? $rated / $total : ($n ? $nRated / $n : 1.0);
    return [
        'ratio' => $ratio,
        'percent' => (int)round($ratio * 100),
        'rated' => $nRated,
        'total' => $n,
        'partial' => $nRated < $n,
        'low' => $nRated < $n && $ratio < 0.6,
    ];
}

/**
 * Приводить звіт будь-якої версії (v1/v2) до єдиного вигляду для в'юх.
 * Ніколи не кидає винятків і не генерує notice на неповних даних.
 */
function normalize_report(array $r): array
{
    $checks = [];
    foreach (_arr($r['checks'] ?? null) as $i => $c) {
        if (!is_array($c)) continue;
        $score = _score($c['score'] ?? null);
        $status = _str($c['status'] ?? 'ok') === 'skipped' ? 'skipped' : 'ok';
        if ($score === null) $status = 'skipped';
        if ($status === 'skipped') $score = null;

        $sub = [];
        foreach (_arr($c['sub_scores'] ?? null) as $k => $v) {
            $sub[(string)$k] = _score($v);
        }
        $findings = [];
        foreach (_arr($c['findings'] ?? null) as $f) {
            $nf = normalize_finding($f);
            if ($nf) $findings[] = $nf;
        }
        $rawName = _str($c['name'] ?? '');
        $name = check_name_uk($rawName !== '' ? $rawName : ('Перевірка ' . ((int)$i + 1)));
        $id = _str($c['id'] ?? '');
        if ($id === '') $id = 'c' . count($checks);

        $checks[] = [
            'id' => $id,
            'name' => $name,
            'weight' => is_numeric($c['weight'] ?? null) ? (float)$c['weight'] : 0.0,
            'status' => $status,
            'score' => $score,
            'summary' => _str($c['summary'] ?? ''),
            'sub_scores' => $sub,
            'sub_labels' => _arr($c['sub_labels'] ?? null),
            'passed' => _string_list($c['passed'] ?? []),
            'findings' => $findings,
        ];
    }

    $composite = _score($r['composite_score'] ?? null) ?? 0;
    $meta = _arr($r['meta'] ?? null);

    // Звіт v1: немає профілю сайту і жодна перевірка не має id/status/sub_labels
    $legacy = !isset($r['site']) && !isset($r['access']);
    foreach (_arr($r['checks'] ?? null) as $c) {
        if (is_array($c) && (isset($c['id']) || isset($c['status']) || isset($c['sub_labels']))) { $legacy = false; break; }
    }

    return [
        'url' => _str($r['url'] ?? ''),
        'host' => _str($r['host'] ?? ''),
        'audited_at' => _str($r['audited_at'] ?? ''),
        'composite_score' => $composite,
        'tier_class' => tier_class($composite),
        'tier_label' => tier_label_uk($composite),
        'site' => normalize_site($r['site'] ?? null),
        'samples' => normalize_samples($r['samples'] ?? null),
        'access' => normalize_access($r['access'] ?? null),
        'checks' => $checks,
        'coverage' => score_coverage($checks),
        'legacy' => $legacy,
        'ai' => normalize_ai($r['ai'] ?? null),
        'meta' => [
            'job_id' => _str($meta['job_id'] ?? ''),
            'engine_version' => _str($meta['engine_version'] ?? ''),
            'duration_ms' => is_numeric($meta['duration_ms'] ?? null) ? (int)$meta['duration_ms'] : null,
        ],
    ];
}

/** Плаский список знахідок: серйозність -> вага перевірки -> порядок у перевірці. */
function report_findings(array $checks): array
{
    $all = [];
    $n = 0;
    foreach ($checks as $ci => $c) {
        foreach ($c['findings'] as $f) {
            $f['category'] = $c['name'];
            $f['cat_index'] = $ci;
            $f['cat_weight'] = $c['weight'];
            $f['_n'] = $n++;
            $all[] = $f;
        }
    }
    usort($all, function ($a, $b) {
        $d = severity_order($a['severity']) <=> severity_order($b['severity']);
        if ($d !== 0) return $d;
        $d = $b['cat_weight'] <=> $a['cat_weight'];
        if ($d !== 0) return $d;
        return $a['_n'] <=> $b['_n'];
    });
    return $all;
}

/** Банер про доступ: ['level' => 'warn'|'error', 'text' => ...] або null. */
function access_banner(?array $access): ?array
{
    if (!$access) return null;
    if ($access['fetched_as'] === 'none') {
        return ['level' => 'error', 'text' => 'Не вдалося отримати контент сайту - перевірки контенту пропущено.'];
    }
    if ($access['blocked'] && $access['fetched_as'] === 'browser' && !empty($access['alt_agent'])) {
        return [
            'level' => 'info',
            'text' => 'Сайт пропускає лише відомих ботів: наш бот і звичайний браузер отримали відмову захисту (' . block_reason_label_uk($access['block_reason']) . '), а ' . $access['alt_agent'] . ' - повну сторінку. Контент проаналізовано від імені ' . $access['alt_agent'] . ', тобто так, як його бачить AI-пошук.',
        ];
    }
    if ($access['blocked'] && $access['fetched_as'] === 'browser') {
        return [
            'level' => 'warn',
            'text' => 'Сайт блокує невідомих ботів (' . block_reason_label_uk($access['block_reason']) . '). Контент проаналізовано від імені браузера - перевірте, чи пропускає захист AI-краулерів.',
        ];
    }
    return null;
}

/** Видаляти аудити можна, лише якщо в .env увімкнено GEO_AUDIT_ALLOW_DELETE=1. */
function geo_can_delete(): bool
{
    return getenv('GEO_AUDIT_ALLOW_DELETE') === '1';
}

/** CSRF-токен сесії для видалення аудитів; '' без активної сесії. */
function geo_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['geo_csrf'])) $_SESSION['geo_csrf'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['geo_csrf'];
}

/** Банер про перемикання на українську версію: корінь сайту віддає російську. */
function lang_banner(?array $access): ?array
{
    if (!$access || empty($access['lang_switch'])) return null;
    $ls = $access['lang_switch'];
    return [
        'level' => 'info',
        'text' => 'Головна ' . $ls['from'] . ' за замовчуванням віддає російськомовну версію. Аудит проведено по українській версії ' . $ls['to'] . '. AI-боти, які заходять на корінь домену, бачать російську версію: українська має відкриватися за замовчуванням.',
    ];
}

/** Підпис під загальним балом, якщо частину категорій не оцінено; null для повного аудиту. */
function coverage_note(array $rep): ?string
{
    $cov = $rep['coverage'] ?? null;
    if (!$cov || !$cov['partial']) return null;
    $base = 'Оцінено ' . $cov['rated'] . ' з ' . $cov['total'] . ' категорій (' . $cov['percent'] . '% ваги)';
    if (!$cov['low']) return $base . '; пропущені категорії в бал не входять.';
    $access = $rep['access'] ?? null;
    $why = ($access && ($access['fetched_as'] === 'none' || $access['blocked']))
        ? 'сайт не віддав контент інструменту, тому контентні перевірки пропущено'
        : 'решту категорій не вдалося перевірити';
    return 'Неповний бал. ' . $base . ': ' . $why . '. Бал порахований лише з оцінених категорій, його не можна порівнювати з повними аудитами.';
}

/** Тривалість аудиту: з meta.duration_ms або з часу задачі. */
function report_duration(array $job, array $rep): string
{
    if (!empty($rep['meta']['duration_ms'])) return fmt_seconds_uk((int)round($rep['meta']['duration_ms'] / 1000));
    if (!empty($job['finished_at']) && !empty($job['created_at'])) return fmt_duration($job['created_at'], $job['finished_at']);
    return '';
}
