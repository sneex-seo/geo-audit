<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/Crawler.php';
require_once __DIR__ . '/Page.php';
require_once __DIR__ . '/SiteProfile.php';

/**
 * Рушій аудиту v2.
 *
 *   $engine = new AuditEngine($jobId);            // прогрес пишеться в БД
 *   $engine = new AuditEngine(null, ['ai' => false]); // CLI / тести без БД
 *   $report = $engine->run($url);
 *
 * Для dev-інструментів: buildContext($url), runChecks($url, $ctx, $only), timings().
 */
class AuditEngine
{
    const ENGINE_VERSION = '2.0.0';

    /** Порядок перевірок v2 (клас => файл у lib/checks). */
    const CHECKS = [
        'CrawlerCheck', 'ContentRenderCheck', 'CitabilityCheck', 'SchemaCheck', 'EeatCheck',
        'BrandCheck', 'TechnicalCheck', 'SitemapCheck', 'LlmsTxtCheck',
    ];

    /** id для старих перевірок, які не повертають 'id'. */
    const LEGACY_IDS = [
        'CrawlerCheck' => 'crawler', 'ContentRenderCheck' => 'render', 'CitabilityCheck' => 'citability',
        'SchemaCheck' => 'schema', 'EeatCheck' => 'eeat', 'BrandCheck' => 'brand', 'TechnicalCheck' => 'technical',
        'SitemapCheck' => 'sitemap', 'LlmsTxtCheck' => 'llmstxt',
    ];

    const SAMPLE_TIMEOUT = 15;

    private $db = null;
    private ?string $jobId;
    private array $options;
    private array $timings = [];
    private float $t0 = 0.0;

    /**
     * @param string|null $jobId null = без БД (CLI-тести)
     * @param array $options ['ai' => bool (true), 'progress' => callable(int $pct, string $stage)|null]
     */
    public function __construct(?string $jobId = null, array $options = [])
    {
        $this->jobId = ($jobId !== null && $jobId !== '') ? $jobId : null;
        $this->options = $options + ['ai' => true, 'progress' => null];
        if ($this->jobId !== null) {
            require_once __DIR__ . '/Database.php';
            $this->db = Database::get();
        }
    }

    public function timings(): array
    {
        return $this->timings;
    }

    // ================================================================== full run

    public function run(string $url): array
    {
        $this->t0 = microtime(true);
        $this->timings = [];
        if ($this->db) $this->db->setRunning($this->jobId);

        $ctx = $this->buildContext($url);
        $results = $this->runChecks($ctx['url'], $ctx, null, 20, 85);

        // ---- AI
        $this->progress(88, 'AI-аналіз');
        $t = microtime(true);
        $ai = $this->runAi($ctx, $results);
        $this->timings['ai'] = $this->ms($t);

        $this->progress(96, 'Формую звіт');
        $composite = self::composite($results);

        $samples = [];
        foreach ($ctx['samples'] as $s) {
            $samples[] = [
                'role'       => $s['role'],
                'url'        => $s['url'],
                'final_url'  => $s['res']['final_url'] ?? $s['url'],
                'status'     => (int)($s['res']['status'] ?? 0),
                'title'      => $s['page'] ? $s['page']->title() : '',
                'via'        => $s['via'] ?? 'link',
                'fetched_as' => $s['fetched_as'] ?? 'bot',
                'challenge'  => $s['challenge'] ?? null,
            ];
        }
        $site = $ctx['site'];

        return [
            'url'             => $url,
            'final_url'       => $ctx['url'],
            'host'            => Crawler::safeHost($ctx['url']),
            'audited_at'      => date('c'),
            'composite_score' => $composite,
            'score_coverage'  => self::coverage($results),
            'tier'            => self::tier($composite),
            'site'            => $site,
            'samples'         => $samples,
            'access'          => $ctx['access'],
            'checks'          => self::stripBodies($results),
            'ai'              => $ai,
            'meta'            => [
                'job_id'         => $this->jobId,
                'engine_version' => self::ENGINE_VERSION,
                'duration_ms'    => $this->ms($this->t0),
                'timings'        => $this->timings,
            ],
        ];
    }

    // ================================================================== context

    /**
     * Збирає ctx для перевірок (контракт ENGINE CONTEXT).
     * Кидає RuntimeException лише якщо хост зовсім недоступний (DNS / з'єднання).
     */
    public function buildContext(string $url): array
    {
        if ($this->t0 == 0.0) $this->t0 = microtime(true);

        // ---- головна
        $this->progress(3, 'Завантаження головної сторінки');
        $t = microtime(true);
        Crawler::setAltAgent(null);
        $bot = Crawler::fetch($url);
        $botPage = Page::fromCrawl($bot);
        $botChallenge = $botPage ? $botPage->challenge() : null;
        $botOk = $bot['status'] >= 200 && $bot['status'] < 400 && $botPage !== null && $botChallenge === null;

        $browser = null; $browserPage = null; $browserChallenge = null; $browserOk = false;
        if (!$botOk) {
            $this->progress(6, 'Повторне завантаження як браузер');
            $browser = Crawler::fetchBrowser($url);
            $browserPage = Page::fromCrawl($browser);
            $browserChallenge = $browserPage ? $browserPage->challenge() : null;
            $browserOk = $browser['status'] >= 200 && $browser['status'] < 400 && $browserPage !== null && $browserChallenge === null;
        }

        // захист блокує і бота, і браузер: частина сайтів пропускає лише відомих ботів за User-Agent
        $alt = null; $altPage = null; $altName = null; $altTried = [];
        if (!$botOk && !$browserOk && ((int)$bot['status'] !== 0 || ($browser && (int)$browser['status'] !== 0))) {
            $this->progress(7, 'Повторне завантаження як AI-бот');
            foreach (Crawler::fetchAgents($url, Crawler::AI_AGENTS, 15) as $name => $r) {
                $altTried[$name] = (int)$r['status'];
                if ($alt !== null) continue;
                $p = Page::fromCrawl($r);
                if ($r['status'] >= 200 && $r['status'] < 400 && $p !== null && $p->challenge() === null) {
                    $alt = $r; $altPage = $p; $altName = $name;
                }
            }
        }
        $this->timings['homepage'] = $this->ms($t);

        if ((int)$bot['status'] === 0 && (!$browser || (int)$browser['status'] === 0)) {
            $err = $bot['error'] ?: ($browser['error'] ?? '');
            throw new RuntimeException('Сайт недоступний: не вдалося з\'єднатися з ' . Crawler::safeHost($url) . ($err !== '' ? ' (' . $err . ')' : ''));
        }

        if ($botOk) {
            $homepage = $bot; $page = $botPage; $fetchedAs = 'bot';
        } elseif ($browserOk) {
            $homepage = $browser; $page = $browserPage; $fetchedAs = 'browser';
        } elseif ($alt !== null) {
            // далі режим 'browser' (повтори після блоку бота) ходить від імені цього AI-бота
            Crawler::setAltAgent(Crawler::AI_AGENTS[$altName], $altName);
            $alt['fetched_as'] = 'browser';
            $homepage = $alt; $page = $altPage; $fetchedAs = 'browser';
        } else {
            // обидві спроби невдалі: віддаємо чесну відповідь сервера боту, сторінки немає
            $homepage = ((int)$bot['status'] === 0 && $browser) ? $browser : $bot;
            $page = null; $fetchedAs = 'none';
        }

        // корінь віддає російську версію, а є українська (hreflang uk): аудит ведемо по українській
        $langSwitch = null;
        $ukUrl = $page !== null ? self::ukAlternate($page) : null;
        if ($ukUrl !== null) {
            $this->progress(8, 'Завантаження української версії');
            $ur = $fetchedAs === 'bot' ? Crawler::fetch($ukUrl) : Crawler::fetchBrowser($ukUrl);
            $up = Page::fromCrawl($ur);
            if ((int)$ur['status'] === 200 && $up !== null && $up->challenge() === null) {
                $langSwitch = ['from' => (string)($homepage['final_url'] ?? $url), 'from_lang' => (string)$page->lang(), 'to' => (string)($ur['final_url'] ?? $ukUrl)];
                $homepage = $ur; $page = $up;
            }
        }

        $botStatus = (int)$bot['status'];
        $botBlockedStatus = in_array($botStatus, [401, 403, 406, 429, 451], true);
        $blockReason = null;
        if ($botChallenge !== null) $blockReason = $botChallenge;
        elseif ($botStatus >= 400) $blockReason = 'http_' . $botStatus;
        elseif ($botStatus === 0) $blockReason = 'connection';
        elseif (!$botOk && $botPage === null) $blockReason = 'empty_body';
        if ($blockReason === null && $fetchedAs === 'none' && $browserChallenge !== null) $blockReason = $browserChallenge;

        $blocked = $botChallenge !== null || $botBlockedStatus || (!$botOk && ($browserOk || $alt !== null));

        $access = [
            'homepage_status'  => (int)$homepage['status'],
            'bot_status'       => $botStatus,
            'browser_status'   => $browser ? (int)$browser['status'] : null,
            'fetched_as'       => $fetchedAs,
            'blocked'          => $blocked,
            'block_reason'     => ($blocked || $fetchedAs === 'none') ? $blockReason : null,
            'bot_challenge'    => $botChallenge,
            'browser_challenge'=> $browserChallenge,
            'alt_agent'        => $altName,
            'alt_tried'        => $altTried,
            'fetched_with'     => $fetchedAs === 'bot' ? 'GeoAuditBot' : ($fetchedAs === 'browser' ? Crawler::altName() : null),
            'lang_switch'      => $langSwitch,
            'final_url'        => (string)($homepage['final_url'] ?? $url),
            'redirect_count'   => (int)($homepage['redirect_count'] ?? 0),
            'ttfb_ms'          => $homepage['ttfb_ms'] ?? null,
        ];
        $finalUrl = $access['final_url'] !== '' ? $access['final_url'] : $url;
        $base = Crawler::baseUrl($finalUrl);

        // ---- robots.txt
        $this->progress(9, 'Читаю robots.txt');
        $t = microtime(true);
        $robots = Crawler::fetch($base . '/robots.txt', 15);
        $robotsBot = null;
        if (!self::looksLikeRobots($robots) && ($fetchedAs === 'browser' || $blocked)) {
            $rb = Crawler::fetchBrowser($base . '/robots.txt', 15);
            if (self::looksLikeRobots($rb)) {
                $robotsBot = ['status' => (int)$robots['status'], 'challenge' => $robots['body'] !== '' ? Page::challengeFromHtml($robots['body']) : null];
                $robots = $rb; // fetched_as = 'browser' всередині результату
            }
        }
        $this->timings['robots'] = $this->ms($t);

        // ---- вибірка внутрішніх сторінок
        $this->progress(12, 'Вибираю внутрішні сторінки');
        $t = microtime(true);
        $cands = SiteProfile::pickSamples($page, $finalUrl, $robots, 10.0);
        $this->timings['pick_samples'] = $this->ms($t);

        $t = microtime(true);
        $samples = $this->fetchSamples($cands, $fetchedAs);
        $this->timings['fetch_samples'] = $this->ms($t);

        // ---- профіль сайту
        $this->progress(17, 'Визначаю тип сайту');
        $t = microtime(true);
        $robotsBody = self::looksLikeRobots($robots) ? (string)$robots['body'] : null;
        $site = SiteProfile::detect($page, $samples, $finalUrl, $robotsBody);
        $this->timings['site_profile'] = $this->ms($t);

        $ctx = [
            'url'        => $finalUrl,
            'input_url'  => $url,
            'homepage'   => $homepage,
            'page'       => $page,
            'robots_txt' => $robots,
            'site'       => $site,
            'samples'    => $samples,
            'access'     => $access,
        ];
        if ($robotsBot !== null) $ctx['robots_txt_bot'] = $robotsBot;
        return $ctx;
    }

    /** URL української версії, якщо сторінка російськомовна і має hreflang uk на тому ж сайті; інакше null. */
    private static function ukAlternate(Page $page): ?string
    {
        $lang = strtolower((string)$page->lang());
        $ru = $lang !== '' ? strpos($lang, 'ru') === 0 : (bool)preg_match('/[ыэъё]/u', mb_substr($page->visibleText(), 0, 3000));
        if (!$ru) return null;
        foreach ($page->hreflangs() as $h) {
            $hl = strtolower((string)$h['lang']);
            if ($hl !== 'uk' && strpos($hl, 'uk-') !== 0) continue;
            $href = (string)$h['href'];
            if (!Crawler::sameSite($href, $page->url())) continue;
            if (rtrim(strtolower($href), '/') === rtrim(strtolower($page->url()), '/')) continue;
            return $href;
        }
        return null;
    }

    /** Завантажує кандидатів паралельно; для сторінки блогу бере першу статтю. */
    private function fetchSamples(array $cands, string $fetchedAs): array
    {
        if (!$cands) return [];
        $urls = array_column($cands, 'url');
        $mode = $fetchedAs === 'browser' ? 'browser' : null;
        $res = Crawler::fetchMulti($urls, $mode, self::SAMPLE_TIMEOUT);

        // повтор браузером для заблокованих
        $retry = [];
        $built = [];
        foreach ($cands as $i => $c) {
            $r = $res[$c['url']] ?? null;
            $b = $this->buildSample($c, $r);
            $built[$i] = $b;
            if ($mode === null && $r && ($b['challenge'] !== null || in_array((int)$r['status'], [401, 403, 429, 503], true))) $retry[$i] = $c['url'];
        }
        if ($retry) {
            $res2 = Crawler::fetchMulti(array_values($retry), 'browser', self::SAMPLE_TIMEOUT);
            foreach ($retry as $i => $u) {
                if (!isset($res2[$u])) continue;
                $b = $this->buildSample($cands[$i], $res2[$u]);
                if ($b['page'] !== null) $built[$i] = $b;
            }
        }

        // сторінка блогу -> перша стаття
        foreach ($built as $i => $b) {
            if (empty($cands[$i]['index'])) continue;
            $articleUrl = $b['page'] ? SiteProfile::firstArticleLink($b['page']) : null;
            if ($articleUrl) {
                $r = ($b['fetched_as'] === 'browser') ? Crawler::fetchBrowser($articleUrl, 12) : Crawler::fetch($articleUrl, 12);
                $a = $this->buildSample(['role' => 'article', 'url' => $articleUrl, 'via' => 'blog-index'], $r);
                if ($a['page'] === null && $b['fetched_as'] !== 'browser' && ($a['challenge'] !== null || (int)$r['status'] >= 400)) {
                    $a = $this->buildSample(['role' => 'article', 'url' => $articleUrl, 'via' => 'blog-index'], Crawler::fetchBrowser($articleUrl, 12));
                }
                if ($a['page'] !== null) { $built[$i] = $a; continue; }
            }
            // статті не знайшли - лишаємо сторінку блогу як 'other'
            $built[$i]['role'] = 'other';
            $built[$i]['note'] = 'сторінка блогу / новин';
        }
        // перевірка ролі «стаття» за фактичним вмістом
        $hasProduct = false;
        foreach ($built as $b) if ($b['role'] === 'product') $hasProduct = true;
        foreach ($built as $i => $b) {
            if ($b['role'] !== 'article' || $b['page'] === null) continue;
            $p = $b['page'];
            if ($p->hasSchemaType('Product') && !$p->hasSchemaType('Article')) {
                // насправді картка товару
                $built[$i]['role'] = $hasProduct ? 'other' : 'product';
                $built[$i]['note'] = 'за розміткою це картка товару';
                $hasProduct = true;
                continue;
            }
            $words = $p->wordCount($p->mainText());
            $weak = !empty($cands[$i]['weak']) && $b['via'] !== 'blog-index';
            if ($words < 120 || ($weak && $words < 300 && !$p->hasSchemaType('Article'))) {
                $built[$i]['role'] = 'other';
                $built[$i]['note'] = 'замало тексту для статті';
            }
        }
        return array_values($built);
    }

    private function buildSample(array $c, ?array $r): array
    {
        $r = $r ?? ['ok' => false, 'status' => 0, 'body' => '', 'headers' => [], 'final_url' => $c['url'], 'error' => 'not fetched', 'fetched_as' => 'none'];
        $page = null; $challenge = null;
        if ((string)($r['body'] ?? '') !== '') {
            $p = Page::fromCrawl($r);
            if ($p) {
                $challenge = $p->challenge();
                $ct = strtolower((string)($r['content_type'] ?? 'text/html'));
                $isHtml = $ct === '' || strpos($ct, 'html') !== false || strpos($ct, 'xml') !== false;
                if ($challenge === null && $isHtml && (int)$r['status'] >= 200 && (int)$r['status'] < 400) $page = $p;
            }
        }
        return [
            'role'       => (string)$c['role'],
            'url'        => (string)$c['url'],
            'res'        => $r,
            'page'       => $page,
            'via'        => (string)($c['via'] ?? 'link'),
            'fetched_as' => (string)($r['fetched_as'] ?? 'bot'),
            'challenge'  => $challenge,
        ];
    }

    private static function looksLikeRobots(?array $r): bool
    {
        if (!$r || (int)($r['status'] ?? 0) !== 200) return false;
        $b = ltrim((string)($r['body'] ?? ''));
        if ($b === '') return true; // порожній robots.txt - валідний
        if ($b[0] === '<') return false; // HTML замість robots.txt (SPA / сторінка перевірки)
        return true;
    }

    // ================================================================== checks

    /**
     * Запускає перевірки. $only - список імен класів (null = усі).
     * $pFrom/$pTo - діапазон прогресу.
     */
    public function runChecks(string $url, array $ctx, ?array $only = null, int $pFrom = 20, int $pTo = 85): array
    {
        $classes = self::CHECKS;
        if ($only !== null) {
            $want = array_map('strtolower', $only);
            $classes = array_values(array_filter($classes, function ($c) use ($want) { return in_array(strtolower($c), $want, true); }));
            // дозволяємо й перевірки поза стандартним списком
            foreach ($only as $o) {
                if (!in_array(strtolower($o), array_map('strtolower', $classes), true)) $classes[] = $o;
            }
        }
        $results = [];
        $total = max(1, count($classes));
        foreach ($classes as $i => $class) {
            $file = __DIR__ . '/checks/' . $class . '.php';
            if (!class_exists($class, false)) {
                if (!is_file($file)) continue;
                require_once $file;
            }
            if (!class_exists($class, false)) continue;
            $t = microtime(true);
            $check = null;
            try {
                $check = new $class();
                $this->progress($pFrom + (int)floor(($pTo - $pFrom) * $i / $total), 'Перевірка: ' . $check->name());
                $r = $check->run($url, $ctx);
                $results[] = self::normalizeResult(is_array($r) ? $r : [], $check, $class);
            } catch (Throwable $e) {
                $results[] = self::failedResult($check, $class, $e);
            }
            $this->timings['check:' . $class] = $this->ms($t);
        }
        return $results;
    }

    /** Приводить результат (зокрема старих перевірок v1) до контракту v2. */
    private static function normalizeResult(array $r, $check, string $class): array
    {
        $status = ($r['status'] ?? 'ok') === 'skipped' ? 'skipped' : 'ok';
        $score = $r['score'] ?? null;
        if ($status === 'ok') $score = max(0, min(100, (int)round((float)($score ?? 0))));
        else $score = null;
        $sub = is_array($r['sub_scores'] ?? null) ? $r['sub_scores'] : [];
        $findings = [];
        foreach ((array)($r['findings'] ?? []) as $f) {
            if (!is_array($f)) continue;
            $f['severity'] = in_array($f['severity'] ?? '', ['critical', 'high', 'medium', 'low'], true) ? $f['severity'] : 'medium';
            $f['text'] = (string)($f['text'] ?? '');
            $findings[] = $f;
        }
        $out = [
            'id'         => (string)($r['id'] ?? (method_exists($check, 'id') ? $check->id() : (self::LEGACY_IDS[$class] ?? strtolower(preg_replace('/Check$/', '', $class))))),
            'name'       => (string)($r['name'] ?? $check->name()),
            'weight'     => (float)($r['weight'] ?? $check->weight()),
            'status'     => $status,
            'score'      => $score,
            'summary'    => (string)($r['summary'] ?? ''),
            'sub_scores' => $sub,
            'sub_labels' => is_array($r['sub_labels'] ?? null) ? $r['sub_labels'] : [],
            'passed'     => is_array($r['passed'] ?? null) ? array_values($r['passed']) : [],
            'findings'   => $findings,
            'data'       => is_array($r['data'] ?? null) ? $r['data'] : [],
        ];
        // зайві ключі нових перевірок не губимо
        foreach ($r as $k => $v) if (!array_key_exists($k, $out)) $out[$k] = $v;
        return $out;
    }

    private static function failedResult($check, string $class, Throwable $e): array
    {
        $name = $check ? $check->name() : $class;
        $msg = $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        return [
            'id'         => $check && method_exists($check, 'id') ? $check->id() : (self::LEGACY_IDS[$class] ?? strtolower($class)),
            'name'       => $name,
            'weight'     => $check ? $check->weight() : 0.0,
            'status'     => 'skipped',
            'score'      => null,
            'summary'    => 'Перевірка не виконалась через технічну помилку',
            'sub_scores' => [],
            'sub_labels' => [],
            'passed'     => [],
            'findings'   => [[
                'id'       => (self::LEGACY_IDS[$class] ?? 'check') . '.failed',
                'severity' => 'low',
                'text'     => 'Перевірка не виконалась',
                'evidence' => $msg,
                'why'      => 'Через помилку бал цієї перевірки не враховано в загальній оцінці.',
                'fix'      => 'Повторіть аудит; якщо помилка повторюється - передайте її розробнику інструмента.',
                'bad'      => $msg,
                'good'     => 'Перевірка відпрацьовує і виставляє бал.',
                'code'     => '',
                'verify'   => 'Повторний аудит без цієї помилки.',
                'effort'   => 'S',
            ]],
            'data'       => ['exception' => get_class($e)],
        ];
    }

    // ================================================================== AI

    private function runAi(array $ctx, array $results): array
    {
        if (empty($this->options['ai'])) {
            return ['enabled' => false, 'model' => null, 'usage' => ['in' => 0, 'out' => 0], 'message' => 'AI-аналіз вимкнено для цього запуску.', 'sections' => []];
        }
        try {
            if (!class_exists('AiClient', false)) {
                if (!is_file(__DIR__ . '/AiClient.php')) throw new RuntimeException('AiClient.php не знайдено');
                require_once __DIR__ . '/AiClient.php';
            }
            $ai = AiClient::run($ctx['url'], $results, self::aiContext($ctx));
            return is_array($ai) ? $ai : ['enabled' => false, 'model' => null, 'usage' => ['in' => 0, 'out' => 0], 'message' => 'AI повернув некоректну відповідь.', 'sections' => []];
        } catch (Throwable $e) {
            return ['enabled' => false, 'model' => null, 'usage' => ['in' => 0, 'out' => 0], 'message' => 'AI-аналіз не виконано: ' . $e->getMessage(), 'sections' => []];
        }
    }

    /** Стислий контекст для AI: профіль, доступ, головна (без сирого HTML), вибірка. */
    public static function aiContext(array $ctx): array
    {
        $p = $ctx['page'] ?? null;
        $pageInfo = ['title' => '', 'description' => '', 'h1' => [], 'h2' => [], 'text_excerpt' => ''];
        if ($p instanceof Page) {
            $h = $p->headings();
            $pageInfo = [
                'title'        => $p->title(),
                'description'  => $p->metaDescription(),
                'h1'           => array_slice($h['h1'], 0, 5),
                'h2'           => array_slice($h['h2'], 0, 15),
                'text_excerpt' => mb_substr($p->mainText(), 0, 6000),
            ];
        }
        $samples = [];
        foreach ($ctx['samples'] ?? [] as $s) {
            $sp = $s['page'] ?? null;
            $samples[] = [
                'role'  => $s['role'],
                'url'   => $s['url'],
                'title' => $sp instanceof Page ? $sp->title() : '',
                'h1'    => $sp instanceof Page ? array_slice($sp->headings()['h1'], 0, 3) : [],
                'status' => (int)($s['res']['status'] ?? 0),
                // текст внутрішніх сторінок теж потрібен AI для оцінки контенту, а не лише головна
                'h2'    => $sp instanceof Page ? array_slice(array_values(array_filter($sp->headings()['h2'])), 0, 8) : [],
                'text_excerpt' => $sp instanceof Page ? mb_substr($sp->mainText(), 0, 1500) : '',
                'js_only_words' => $sp instanceof Page ? (int)$sp->jsOnlyText()['words'] : 0,
            ];
        }
        $site = $ctx['site'] ?? [];
        unset($site['scores']);
        return ['site' => $site, 'access' => $ctx['access'] ?? [], 'page' => $pageInfo, 'samples' => $samples];
    }

    // ================================================================== scoring

    /** Зважене середнє по перевірках зі status 'ok' і числовим балом (ваги перенормовуються). */
    public static function composite(array $results): int
    {
        $sum = 0.0; $w = 0.0;
        foreach ($results as $r) {
            if (($r['status'] ?? 'ok') !== 'ok' || !isset($r['score']) || $r['score'] === null) continue;
            $wt = (float)($r['weight'] ?? 0);
            if ($wt <= 0) continue;
            $sum += (float)$r['score'] * $wt;
            $w += $wt;
        }
        return $w > 0 ? (int)round($sum / $w) : 0;
    }

    /** Частка загальної ваги, яку покрили оцінені перевірки (1.0 = усі оцінено). */
    public static function coverage(array $results): float
    {
        $all = 0.0; $ok = 0.0;
        foreach ($results as $r) {
            $wt = (float)($r['weight'] ?? 0);
            if ($wt <= 0) continue;
            $all += $wt;
            if (($r['status'] ?? 'ok') === 'ok' && isset($r['score']) && $r['score'] !== null) $ok += $wt;
        }
        return $all > 0 ? round($ok / $all, 2) : 1.0;
    }

    public static function tier(int $score): string
    {
        if ($score >= 80) return 'GOOD';
        if ($score >= 60) return 'MODERATE';
        if ($score >= 40) return 'POOR';
        return 'CRITICAL';
    }

    // ================================================================== utils

    /** Прибирає сирі тіла відповідей (body/html) з даних перевірок, щоб звіт лишався малим. */
    private static function stripBodies(array $a, int $depth = 0)
    {
        if ($depth > 8) return $a;
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::stripBodies($v, $depth + 1);
            } elseif (is_string($v) && in_array((string)$k, ['body', 'html', 'raw_html'], true) && strlen($v) > 2000) {
                $a[$k] = '[вилучено: ' . strlen($v) . ' байт]';
            } elseif (is_object($v)) {
                unset($a[$k]);
            }
        }
        return $a;
    }

    private function progress(int $pct, string $stage): void
    {
        if ($this->db) {
            try { $this->db->updateProgress($this->jobId, $pct, $stage); } catch (Throwable $e) { /* прогрес не критичний */ }
        }
        if (is_callable($this->options['progress'])) ($this->options['progress'])($pct, $stage);
    }

    private function ms(float $since): int
    {
        return (int)round((microtime(true) - $since) * 1000);
    }
}
