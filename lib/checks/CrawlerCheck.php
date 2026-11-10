<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';

/**
 * Доступ AI-ботів та індексація (v2).
 *
 * 1) robots.txt за RFC 9309 / специфікацією Google: групи з кількох User-agent, найточніша група
 *    (з резервом на '*'), об'єднання однойменних груп, longest-match між Allow/Disallow з * і $,
 *    за рівної довжини перемагає Allow. Оцінюється доступ до кореня, головної і кожної сторінки вибірки.
 *    Коди відповіді: 2xx - розбір; 4xx - «обмежень немає»; 5xx/429/таймаут - Google тимчасово
 *    вважає сайт закритим; HTML замість файлу - як відсутній файл.
 * 2) Два класи ботів: пошук / цитування (вирішальні для видимості) і навчання (бізнес-рішення).
 * 3) WAF-проба: паралельний GET головної з User-Agent 7 AI-ботів проти базової відповіді (наш бот або
 *    браузер), один спільний повтор і контрольний запит після проб (бан IP аудитора != блок за User-Agent).
 * 4) Директиви індексації на головній і вибірці: meta robots / googlebot / bingbot, X-Robots-Tag,
 *    data-nosnippet; noarchive / nocache - як обмеження Bing / Copilot.
 */
class CrawlerCheck extends BaseCheck
{
    /**
     * Пошукові / retrieval-боти: від них залежить показ і цитування. w - вага в балі (нормується на суму).
     * user - запит на прохання користувача; ign - true: виробник заявляє, що robots.txt для таких запитів
     * може не застосовуватися; false: заявляє, що бот виконує robots.txt; null - без заяв. svc - сервіс.
     */
    const RETRIEVAL_BOTS = [
        'Googlebot'        => ['w' => 24, 'sev' => 'critical', 'fb' => [], 'user' => false, 'svc' => 'Google', 'what' => 'Google Пошук, AI Overviews і AI Mode'],
        'Bingbot'          => ['w' => 18, 'sev' => 'critical', 'fb' => ['msnbot'], 'user' => false, 'svc' => 'Bing', 'what' => 'Bing і Copilot; індекс Bing використовує й ChatGPT search'],
        'OAI-SearchBot'    => ['w' => 15, 'sev' => 'high', 'fb' => [], 'user' => false, 'svc' => 'ChatGPT search', 'what' => 'пошук у ChatGPT'],
        'PerplexityBot'    => ['w' => 10, 'sev' => 'high', 'fb' => [], 'user' => false, 'svc' => 'Perplexity', 'what' => 'пошуковий індекс Perplexity'],
        'ChatGPT-User'     => ['w' => 7, 'sev' => 'medium', 'fb' => [], 'user' => true, 'ign' => true, 'svc' => 'ChatGPT', 'what' => 'ChatGPT відкриває сторінку на запит користувача'],
        'Claude-SearchBot' => ['w' => 6, 'sev' => 'medium', 'fb' => [], 'user' => false, 'svc' => 'пошук Claude', 'what' => 'пошуковий індекс Claude'],
        'Applebot'         => ['w' => 5, 'sev' => 'medium', 'fb' => ['Googlebot'], 'user' => false, 'svc' => 'Apple', 'what' => 'Siri, Spotlight і Apple Intelligence'],
        'Claude-User'      => ['w' => 4, 'sev' => 'medium', 'fb' => [], 'user' => true, 'ign' => false, 'svc' => 'Claude', 'what' => 'Claude відкриває сторінку на запит користувача'],
        'Perplexity-User'  => ['w' => 4, 'sev' => 'medium', 'fb' => [], 'user' => true, 'ign' => true, 'svc' => 'Perplexity', 'what' => 'Perplexity відкриває сторінку на запит користувача'],
        'DuckAssistBot'    => ['w' => 4, 'sev' => 'medium', 'fb' => [], 'user' => false, 'svc' => 'DuckDuckGo', 'what' => 'AI-відповіді DuckAssist у DuckDuckGo'],
        'MistralAI-User'   => ['w' => 3, 'sev' => 'medium', 'fb' => [], 'user' => true, 'ign' => false, 'svc' => 'Le Chat', 'what' => 'Le Chat (Mistral) на запит користувача'],
        // Amazon: пошук для Alexa+ / Rufus і запити на прохання користувача; для українського ринку другорядні
        'Amzn-SearchBot'   => ['w' => 2, 'sev' => 'low', 'fb' => [], 'user' => false, 'svc' => 'Amazon', 'what' => 'пошук Amazon для Alexa+ і Rufus'],
        'Amzn-User'        => ['w' => 1, 'sev' => 'low', 'fb' => [], 'user' => true, 'ign' => null, 'svc' => 'Alexa+', 'what' => 'Alexa+ відкриває сторінку на запит користувача'],
    ];

    /** Боти, що збирають дані для навчання моделей (блокування - легітимний вибір). Сума ваг 100. */
    const TRAINING_BOTS = [
        'GPTBot'             => ['w' => 20, 'fb' => [], 'what' => 'навчання моделей OpenAI'],
        'Google-Extended'    => ['w' => 18, 'fb' => [], 'what' => 'навчання Gemini і grounding у застосунках Gemini; на Google Пошук і AI Overviews не впливає'],
        'ClaudeBot'          => ['w' => 15, 'fb' => [], 'what' => 'навчання моделей Anthropic'],
        'CCBot'              => ['w' => 12, 'fb' => [], 'what' => 'Common Crawl - відкритий датасет, на якому навчають багато моделей'],
        'Applebot-Extended'  => ['w' => 8, 'fb' => [], 'what' => 'навчання моделей Apple'],
        'Meta-ExternalAgent' => ['w' => 8, 'fb' => [], 'what' => 'навчання моделей Meta AI'],
        'Amazonbot'          => ['w' => 7, 'fb' => [], 'what' => 'сервіси Amazon, зокрема навчання моделей'],
        'Bytespider'         => ['w' => 4, 'fb' => [], 'what' => 'ByteDance: TikTok, Doubao'],
        'anthropic-ai'       => ['w' => 4, 'fb' => [], 'what' => 'застарілий токен Anthropic'],
        'cohere-ai'          => ['w' => 4, 'fb' => [], 'what' => 'Cohere'],
    ];

    /**
     * User-Agent для WAF-проби (офіційні рядки; для Claude-SearchBot і Perplexity-User - за форматом інших ботів
     * цих компаній). Запити йдуть паралельно. w - вага в балі (сума 100), major - блок = high.
     */
    const PROBES = [
        'OAI-SearchBot'    => ['w' => 25, 'retrieval' => true, 'major' => true, 'ua' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.3; +https://openai.com/searchbot'],
        'ChatGPT-User'     => ['w' => 18, 'retrieval' => true, 'major' => false, 'ua' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot'],
        'PerplexityBot'    => ['w' => 22, 'retrieval' => true, 'major' => true, 'ua' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)'],
        'Perplexity-User'  => ['w' => 8, 'retrieval' => true, 'major' => false, 'ua' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)'],
        'Claude-SearchBot' => ['w' => 12, 'retrieval' => true, 'major' => false, 'ua' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +Claude-SearchBot@anthropic.com)'],
        'ClaudeBot'        => ['w' => 8, 'retrieval' => false, 'major' => false, 'ua' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'],
        'GPTBot'           => ['w' => 7, 'retrieval' => false, 'major' => false, 'ua' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.3; +https://openai.com/gptbot'],
    ];

    /** Тайм-аут одного запиту проби (с). Раунд проб паралельний, повтор - один раз. */
    const PROBE_TIMEOUT = 10;

    /** Заголовки контрольного запиту «як браузер» (як у Crawler::fetchBrowser). */
    const BROWSER_HEADERS = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Ch-Ua: "Google Chrome";v="141", "Chromium";v="141", "Not?A_Brand";v="24"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Cache-Control: no-cache',
    ];
    const BOT_HEADERS = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: uk,en;q=0.8'];

    /** Ключі директив robots (щоб відрізнити «googlebot: noindex» від «max-snippet: 0»). */
    const DIRECTIVE_KEYS = [
        'max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after', 'noindex', 'nofollow', 'none',
        'all', 'index', 'follow', 'nosnippet', 'noarchive', 'nocache', 'notranslate', 'noimageindex', 'indexifembedded', 'noai', 'noimageai',
    ];

    /** Справжні сторінки перевірки CDN/WAF (не звичайна «403 Forbidden»). */
    const CHALLENGE_VENDORS = ['cloudflare' => 'Cloudflare', 'ddos-guard' => 'DDoS-Guard', 'imperva' => 'Imperva', 'sucuri' => 'Sucuri',
        'akamai' => 'Akamai', 'datadome' => 'DataDome', 'vercel' => 'Vercel', 'captcha' => null];

    const KEY_ROLES = ['about' => 1, 'product' => 1, 'article' => 1, 'category' => 1, 'service' => 1];

    const ROLE_LABELS = [
        'root' => 'корінь сайту', 'home' => 'головна', 'about' => 'про компанію', 'contact' => 'контакти',
        'article' => 'стаття', 'product' => 'товар', 'category' => 'категорія', 'service' => 'послуга', 'other' => 'інша сторінка',
    ];

    const SEV_RANK = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

    /** Межа розміру robots.txt, яку читає Google (500 КіБ). */
    const ROBOTS_MAX = 512000;

    private array $F = [];
    private array $P = [];
    private array $D = [];
    /** Вхідні дані виправлення для пошукових ботів (щоб варіант для навчальних ботів його враховував). */
    private array $retFix = ['drop' => [], 'comment' => []];
    /** Сирі результати WAF-проби (runProbes). */
    private array $probe = [];
    /** Проби, відмову яким підтверджено контрольним запитом (блок саме за User-Agent). */
    private array $wafConfirmed = [];

    public function id(): string { return 'crawler'; }
    public function name(): string { return 'Доступ AI-ботів та індексація'; }
    public function weight(): float { return 0.14; }

    // ================================================================== run

    public function run(string $url, array $ctx): array
    {
        $this->F = [];
        $this->P = [];
        $this->D = [];
        $this->retFix = ['drop' => [], 'comment' => []];
        $this->probe = [];
        $this->wafConfirmed = [];
        $finalUrl = (string)($ctx['url'] ?? '');
        if ($finalUrl === '') $finalUrl = $url;
        $base = Crawler::baseUrl($finalUrl);
        $host = strtolower(Crawler::safeHost($finalUrl));

        // спершу мережева проба (її результат потрібен і для формулювань по robots.txt)
        $this->runProbes($ctx, $finalUrl);
        $rb = $this->robotsSection($ctx, $finalUrl, $base, $host);
        $waf = $this->wafSection($ctx, $finalUrl, $rb);
        $idx = $this->directivesSection($ctx);

        $sub = [
            'robots_retrieval' => $rb['retrieval'],
            'robots_training'  => $rb['training'],
            'robots_file'      => $rb['file'],
            'waf_probe'        => $waf,
            'index_directives' => $idx,
        ];
        $labels = [
            'robots_retrieval' => 'robots.txt: пошукові та AI-пошукові боти',
            'robots_training'  => 'robots.txt: боти для навчання моделей',
            'robots_file'      => 'robots.txt: доступність і синтаксис',
            'waf_probe'        => 'Сервер / WAF: відповідь AI-ботам',
            'index_directives' => 'meta robots / X-Robots-Tag',
        ];

        $nonNull = array_filter($sub, function ($v) { return $v !== null; });
        if (!$nonNull) {
            $facts = [];
            if (isset($this->D['robots'])) $facts[] = 'robots.txt - HTTP ' . (int)$this->D['robots']['status'] . ', сторінка перевірки';
            $acc = (array)($ctx['access'] ?? []);
            $br = (string)($acc['block_reason'] ?? '');
            $brOk = $br !== '' && strpos($br, 'http_') !== 0 && !in_array($br, ['connection', 'empty_body'], true);
            $facts[] = 'головна - HTTP ' . (int)($acc['homepage_status'] ?? 0) . ($brOk ? ' (' . self::chLabel($br) . ')' : '');
            if (!empty($this->D['waf']['probes'])) {
                $codes = [];
                foreach ($this->D['waf']['probes'] as $n => $p) $codes[] = $n . ' ' . ($p['status'] === null ? '-' : (int)$p['status']);
                $facts[] = 'з User-Agent AI-ботів - ' . implode(', ', $codes);
            }
            return $this->skipped('Сервер блокує запити нашого аудитора (' . implode('; ', $facts) . '), тому доступ AI-ботів оцінити не вдалося. Справжні пошукові й AI-боти можуть пропускатися за списками верифікованих ботів - перевірте налаштування WAF/CDN і логи сервера.', $this->D);
        }

        $score = $this->scoreFrom([
            [$sub['robots_retrieval'], 0.40],
            [$sub['index_directives'], 0.25],
            [$sub['waf_probe'], 0.20],
            [$sub['robots_training'], 0.08],
            [$sub['robots_file'], 0.07],
        ]);
        $sevs = array_column($this->F, 'severity');
        if (in_array('critical', $sevs, true)) $score = min($score, 35);
        elseif (in_array('high', $sevs, true)) $score = min($score, 70);
        // сервер і meta-директиви не оцінено (сайт блокує аудитора): бал лише з robots.txt - не більше 80
        $partial = $sub['waf_probe'] === null && $sub['index_directives'] === null;
        if ($partial) {
            $score = min($score, 80);
            $this->D['partial'] = 'лише robots.txt: відповідь сервера AI-ботам і meta-директиви не перевірено';
        }
        $summary = $this->summary($sub);
        if ($partial) $summary = 'Частковий бал (оцінено лише robots.txt, максимум 80): ' . self::lcFirstWord($summary);

        return $this->result($score, $sub, $labels, $this->F, $this->P, $this->D, $summary);
    }

    // ================================================================== 1. robots.txt

    private function robotsSection(array $ctx, string $finalUrl, string $base, string $host): array
    {
        $out = ['file' => null, 'retrieval' => null, 'training' => null, 'state' => 'none', 'parsed' => null, 'bots' => [], 'paths' => []];
        $robotsUrl = $base . '/robots.txt';
        $rt = (isset($ctx['robots_txt']) && is_array($ctx['robots_txt'])) ? $ctx['robots_txt'] : Crawler::fetch($robotsUrl, 15);
        $state = self::robotsState($rt);
        $attempts = [self::statusText($rt)];
        $flaky = false;
        $browserTry = null;
        if ($state === 'unreachable') {
            // одна повторна спроба, щоб не прийняти разовий збій за постійну проблему
            usleep(800000);
            $rt2 = Crawler::fetch($robotsUrl, 15);
            $attempts[] = self::statusText($rt2);
            $st2 = self::robotsState($rt2);
            if ($st2 !== 'unreachable') {
                $flaky = true;
                $rt = $rt2;
                $state = $st2;
            } else {
                $browserTry = Crawler::fetchBrowser($robotsUrl, 15);
            }
        }
        $status = (int)($rt['status'] ?? 0);
        $body = (string)($rt['body'] ?? '');
        $viaBrowser = ($rt['fetched_as'] ?? 'bot') === 'browser';
        $botAttempt = (isset($ctx['robots_txt_bot']) && is_array($ctx['robots_txt_bot'])) ? $ctx['robots_txt_bot'] : null;
        $out['state'] = $state;
        $out['via_browser'] = $viaBrowser;
        $out['bot_attempt'] = $botAttempt;
        $this->D['robots'] = [
            'url' => $robotsUrl, 'status' => $status, 'state' => $state, 'bytes' => strlen($body),
            'fetched_as' => (string)($rt['fetched_as'] ?? 'bot'), 'attempts' => $attempts,
        ];
        if ($botAttempt) $this->D['robots']['bot_attempt'] = $botAttempt;

        $verifyRobots = 'Відкрийте ' . $robotsUrl . ' у браузері і виконайте curl -s -o /dev/null -w "%{http_code}" ' . $robotsUrl . ' (очікуємо 200 і текстовий файл). У Google Search Console: Налаштування -> Звіт про robots.txt (статус «Отримано», без помилок).';
        $minimalRobots = "User-agent: *\nDisallow:\n\n# Sitemap: [ВКАЖІТЬ URL карти сайту, напр. " . $base . "/sitemap.xml]";

        if ($state === 'unreachable') {
            $browserOk = $browserTry !== null && self::robotsState($browserTry) === 'ok';
            $what = $status === 429 ? '429 (Too Many Requests)' : ($status === 0 ? 'таймаут / немає відповіді' : 'помилку сервера ' . $status);
            $ev = ['GET ' . $robotsUrl . ': ' . implode('; повторна спроба: ', $attempts) . '.'];
            if ($browserTry !== null) $ev[] = 'Той самий запит від імені браузера (Chrome): ' . self::statusText($browserTry) . ($browserOk ? ', файл віддається' : '') . '.';
            $this->F[] = $this->finding('robots_unreachable', $browserOk ? 'high' : 'critical',
                'robots.txt віддає ' . $what . ' - Google у такому разі тимчасово вважає весь сайт закритим', [
                'evidence' => implode("\n", $ev) . ($browserOk ? "\nБраузеру файл віддається, ботам - ні: ймовірно, відповідь формує захист або rate limit, а не сам файл." : ''),
                'why'      => 'Якщо robots.txt відповідає 5xx або 429 чи не відповідає зовсім, Google перші 12 годин не сканує сайт, далі до 30 днів спирається на останню збережену копію, а якщо копії немає, вважає, що обмежень немає. За RFC 9309 інші боти в такому разі мають вважати закритим увесь сайт, тож нові сторінки не потрапляють в індекси, з яких беруться AI-відповіді.',
                'fix'      => 'Зробіть так, щоб /robots.txt завжди віддавався статичним файлом з кодом 200 (або 404, якщо файлу немає) - без PHP, без rate limit і без перевірок WAF.',
                'bad'      => 'GET /robots.txt -> ' . implode(' / ', $attempts) . ($body !== '' ? "\n" . self::htmlHead($body, 200) : ''),
                'good'     => "GET /robots.txt -> HTTP 200\nContent-Type: text/plain\n\nUser-agent: *\nDisallow:",
                'code'     => "# nginx: віддавати robots.txt статичним файлом, без бекенду\nlocation = /robots.txt {\n    root /var/www/[ВКАЖІТЬ корінь сайту];\n    try_files \$uri =404;\n    access_log off;\n}\n\n# Cloudflare / WAF: виключіть шлях /robots.txt з правил rate limiting і челенджів\n# (Security -> WAF -> Custom rules: URI Path equals \"/robots.txt\" -> Skip)",
                'verify'   => $verifyRobots . ' Повторіть запит кілька разів поспіль - кожна відповідь має бути 200.',
                'effort'   => 'S',
            ]);
            $out['file'] = 0;
            $out['retrieval'] = $browserOk ? 30 : 0;
            $out['training'] = $browserOk ? 30 : 0;
            return $out;
        }

        if ($state === 'challenge') {
            $ch = Page::challengeFromHtml($body);
            $vendor = $ch !== null ? (self::CHALLENGE_VENDORS[$ch] ?? null) : null;
            if ($vendor === null) $vendor = self::detectCdn((array)($rt['headers'] ?? []));
            $isCf = $vendor === 'Cloudflare';
            $code = $isCf
                ? "# Cloudflare: Security -> WAF -> Custom rules -> Create rule\n# Вираз:\n(http.request.uri.path eq \"/robots.txt\")\n# Дія: Skip - All remaining custom rules, Super Bot Fight Mode, Browser Integrity Check"
                : "# " . ($vendor ?: 'WAF / захист від ботів') . ": у панелі захисту додайте виняток для шляху /robots.txt\n# (без челенджу, капчі і rate limit), щоб файл отримував будь-який клієнт.\n\n"
                  . "# nginx: віддавати robots.txt статичним файлом\nlocation = /robots.txt {\n    root /var/www/[ВКАЖІТЬ корінь сайту];\n    try_files \$uri =404;\n    access_log off;\n}";
            $this->F[] = $this->finding('robots_challenge', 'low', 'robots.txt не вдалося прочитати: захист віддає сторінку перевірки', [
                'evidence' => 'GET ' . $robotsUrl . ': ' . self::statusText($rt) . ', замість файлу - ' . ($ch ? self::chLabel($ch) : 'сторінка захисту') . '. Через це правила для ботів не оцінено. Справжні Googlebot чи Bingbot можуть отримувати файл нормально, якщо захист пропускає верифікованих ботів.',
                'why'      => 'Боти, які не проходять перевірку, не можуть прочитати robots.txt. Google трактує 4xx як «обмежень немає», а 5xx/429 - як тимчасову заборону всього сайту, тож результат залежить від того, що саме захист віддає справжнім ботам.',
                'fix'      => 'Виключіть /robots.txt з перевірок WAF/CDN (челендж, захист від ботів), щоб файл отримував будь-який клієнт.',
                'bad'      => 'GET /robots.txt -> ' . self::statusText($rt) . "\n" . self::htmlHead($body, 220),
                'good'     => "GET /robots.txt -> HTTP 200\nContent-Type: text/plain\n\nUser-agent: *\nDisallow:",
                'code'     => $code,
                'verify'   => 'curl -s -A "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" ' . $robotsUrl . ' з іншого сервера має повернути текст файлу. У Google Search Console: Налаштування -> Звіт про robots.txt.',
                'effort'   => 'S',
            ]);
            return $out;
        }

        if ($state === 'missing' || $state === 'forbidden') {
            if ($state === 'forbidden') {
                $this->F[] = $this->finding('robots_forbidden', 'low', 'robots.txt віддає ' . $status . ' нашому запиту', [
                    'evidence' => 'GET ' . $robotsUrl . ': HTTP ' . $status . '. Google трактує 4xx як «обмежень немає», але 401/403 часто віддає захист чи налаштування доступу - справжнім ботам може віддаватися інший код.',
                    'why'      => 'Формально 4xx означає, що сканувати можна все, але без доступного robots.txt ви не керуєте ботами і не вказуєте їм карту сайту. Якщо 403 віддає WAF, він може блокувати й самі сторінки.',
                    'fix'      => 'Відкрийте /robots.txt для всіх клієнтів (код 200, text/plain).',
                    'bad'      => 'GET /robots.txt -> HTTP ' . $status . ($body !== '' ? "\n" . self::htmlHead($body, 160) : ''),
                    'good'     => "GET /robots.txt -> HTTP 200\nContent-Type: text/plain",
                    'code'     => $minimalRobots,
                    'verify'   => $verifyRobots,
                    'effort'   => 'S',
                ]);
                $out['file'] = 70;
            } else {
                $this->F[] = $this->finding('robots_missing', 'low', 'robots.txt відсутній (HTTP ' . $status . ')', [
                    'evidence' => 'GET ' . $robotsUrl . ': ' . self::statusText($rt) . '. Google і більшість ботів у такому разі вважають, що сканувати можна все.',
                    'why'      => 'Для AI-видимості це не блокер: боти мають повний доступ. Але без файлу ви не можете закрити службові розділи (кошик, пошук, фільтри) і не вказуєте ботам карту сайту.',
                    'fix'      => 'Створіть /robots.txt з дозволом для всіх і рядком Sitemap; за потреби закрийте службові розділи.',
                    'bad'      => 'GET /robots.txt -> ' . self::statusText($rt) . ($body !== '' ? "\n" . self::htmlHead($body, 160) : ''),
                    'good'     => $minimalRobots,
                    'code'     => $minimalRobots,
                    'verify'   => $verifyRobots,
                    'effort'   => 'S',
                ]);
                $out['file'] = 80;
            }
            $out['retrieval'] = 100;
            $out['training'] = 100;
            return $out;
        }

        if ($state === 'html') {
            $ct = (string)($rt['content_type'] ?? '');
            $this->F[] = $this->finding('robots_html', 'medium', 'Замість robots.txt сервер віддає HTML-сторінку з кодом 200', [
                'evidence' => 'GET ' . $robotsUrl . ': HTTP 200' . ($ct !== '' ? ', Content-Type: ' . $ct : '') . ', ' . self::kb(strlen($body)) . ' HTML. Файлу robots.txt фактично немає: боти не знайдуть у ньому правил і Sitemap.',
                'why'      => 'Google спробує розібрати HTML як robots.txt і не знайде директив, тобто дозволить усе. Але так ви не керуєте ботами, а відповідь 200 на неіснуючий файл зазвичай означає, що сервер (часто SPA-маршрутизація) віддає 200 і для інших неіснуючих адрес.',
                'fix'      => 'Покладіть статичний robots.txt у корінь сайту (для SPA - у public/ або static/) і переконайтеся, що маршрутизатор не перехоплює цей шлях.',
                'bad'      => 'GET /robots.txt -> HTTP 200 (HTML)' . "\n" . self::htmlHead($body, 300),
                'good'     => "GET /robots.txt -> HTTP 200\nContent-Type: text/plain\n\nUser-agent: *\nDisallow:",
                'code'     => $minimalRobots,
                'verify'   => $verifyRobots,
                'effort'   => 'S',
            ]);
            $out['file'] = 60;
            $out['retrieval'] = 100;
            $out['training'] = 100;
            return $out;
        }

        // ---------------- state ok: розбір
        $parsed = self::parseRobots($body);
        $out['parsed'] = $parsed;
        $fileScore = 100;

        if ($flaky) {
            $this->F[] = $this->finding('robots_unstable', 'low', 'robots.txt відповідає нестабільно', [
                'evidence' => 'GET ' . $robotsUrl . ': перша спроба - ' . $attempts[0] . ', друга - ' . ($attempts[1] ?? 'HTTP 200') . '.',
                'why'      => 'Відповідь 5xx/429 на robots.txt може змусити Google на перші 12 годин призупинити сканування всього сайту, далі він спирається на збережену копію. Поодинокі збої некритичні, регулярні - сповільнюють індексацію.',
                'fix'      => 'Перевірте логи сервера за /robots.txt: коди 5xx/429 і час відповіді. Віддавайте файл статично, без бекенду.',
                'bad'      => 'GET /robots.txt -> ' . implode(' / ', $attempts),
                'good'     => 'GET /robots.txt -> HTTP 200 (кожного разу)',
                'verify'   => $verifyRobots,
                'effort'   => 'S',
            ]);
            $fileScore -= 20;
        }

        $passedFile = 'robots.txt доступний: HTTP 200, ' . self::kb(strlen($body)) . ', ' . count($parsed['groups']) . ' ' . self::plural(count($parsed['groups']), 'група', 'групи', 'груп') . ' правил';
        if ($parsed['sitemaps']) $passedFile .= ', Sitemap: ' . count($parsed['sitemaps']);
        if ($viaBrowser && $botAttempt) {
            $passedFile .= ' (файл отримано браузерним запитом; нашому боту сервер віддав HTTP ' . (int)($botAttempt['status'] ?? 0) . (!empty($botAttempt['challenge']) ? ', ' . self::chLabel((string)$botAttempt['challenge']) : '') . ')';
        }
        if (trim($body) === '') $passedFile = 'robots.txt порожній (HTTP 200) - обмежень для ботів немає';
        $this->P[] = $passedFile;

        if ($parsed['truncated']) {
            $this->F[] = $this->finding('robots_too_big', 'low', 'robots.txt більший за 500 КіБ - Google читає лише початок', [
                'evidence' => 'Розмір файлу: ' . self::kb(strlen($body)) . '. Правила після 500 КіБ Google ігнорує.',
                'why'      => 'Правила в кінці файлу не діють для Google, а інші боти за RFC 9309 теж можуть ігнорувати все, що йде після 500 КіБ.',
                'fix'      => 'Скоротіть файл: замініть переліки URL шаблонами з * і $.',
                'bad'      => 'robots.txt: ' . self::kb(strlen($body)),
                'good'     => 'robots.txt: кілька КБ, правила через шаблони (Disallow: /*?sort=)',
                'verify'   => $verifyRobots,
                'effort'   => 'M',
            ]);
            $fileScore -= 20;
        }

        // ---- сторінки для перевірки
        $paths = [];
        $addPath = function (string $u, string $role) use (&$paths, $host) {
            $pu = parse_url($u);
            if (!$pu || strtolower((string)($pu['host'] ?? '')) !== $host) return;
            $path = (string)($pu['path'] ?? '');
            if ($path === '') $path = '/';
            if (isset($pu['query']) && $pu['query'] !== '') $path .= '?' . $pu['query'];
            if (!isset($paths[$path])) $paths[$path] = ['url' => $u, 'roles' => []];
            if (!in_array($role, $paths[$path]['roles'], true)) $paths[$path]['roles'][] = $role;
        };
        $addPath($base . '/', 'root');
        $addPath($finalUrl, 'home');
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            if (!is_array($s) || empty($s['url'])) continue;
            $addPath((string)$s['url'], (string)($s['role'] ?? 'other'));
        }
        $out['paths'] = $paths;

        // ---- оцінка ботів
        $bots = [];
        foreach (self::RETRIEVAL_BOTS as $tok => $b) $bots[$tok] = self::evalBot($parsed, $tok, $b['fb'], $paths) + ['class' => 'retrieval'];
        foreach (self::TRAINING_BOTS as $tok => $b) $bots[$tok] = self::evalBot($parsed, $tok, $b['fb'], $paths) + ['class' => 'training'];
        $out['bots'] = $bots;
        $nPaths = max(1, count($paths));

        $retScore = 0.0;
        $retW = 0;
        foreach (self::RETRIEVAL_BOTS as $tok => $b) {
            $e = $bots[$tok];
            $frac = $e['home_blocked'] ? 0.0 : ($nPaths - count($e['blocked'])) / $nPaths;
            $retScore += $b['w'] * $frac;
            $retW += $b['w'];
        }
        $retScore = $retW > 0 ? 100 * $retScore / $retW : 100.0;
        $trScore = 0.0;
        foreach (self::TRAINING_BOTS as $tok => $b) {
            if (!$bots[$tok]['home_blocked']) $trScore += $b['w'];
        }
        $out['retrieval'] = (int)round($retScore);
        $out['training'] = (int)round($trScore);

        $this->D['robots']['groups'] = count($parsed['groups']);
        $this->D['robots']['sitemaps'] = array_slice($parsed['sitemaps'], 0, 5);
        $this->D['robots']['paths'] = array_keys($paths);
        $this->D['bots'] = [];
        foreach ($bots as $tok => $e) {
            $this->D['bots'][$tok] = [
                'class' => $e['class'], 'group' => $e['matched'], 'home_allowed' => !$e['home_blocked'],
                'blocked_paths' => array_keys($e['blocked']),
            ];
        }

        // повністю заблоковані інші боти (для довідки)
        $known = array_map('strtolower', array_merge(array_keys(self::RETRIEVAL_BOTS), array_keys(self::TRAINING_BOTS), ['msnbot', '*']));
        $other = [];
        foreach ($parsed['groups'] as $gi => $g) {
            foreach ($g['ua'] as $ua) {
                if (in_array($ua[1], $known, true) || $ua[1] === '') continue;
                $merged = self::groupFor($parsed, $ua[1]);
                if (!self::evaluate($merged['rules'], '/')['allowed']) $other[] = $ua[2];
            }
        }
        if ($other) $this->D['robots']['other_blocked_agents'] = array_slice(array_values(array_unique($other)), 0, 15);

        $this->robotsRetrievalFindings($parsed, $bots, $paths, $robotsUrl);
        $this->robotsTrainingFindings($parsed, $bots, $paths, $robotsUrl);
        $fileScore -= $this->robotsHygieneFindings($parsed, $bots, $robotsUrl);

        $out['file'] = max(0, $fileScore);
        return $out;
    }

    /** Знахідки по пошукових / AI-пошукових ботах. */
    private function robotsRetrievalFindings(array $parsed, array $bots, array $paths, string $robotsUrl): void
    {
        $homeBlocked = [];
        $pageBlocked = [];
        $allowedAll = [];
        foreach (self::RETRIEVAL_BOTS as $tok => $b) {
            $e = $bots[$tok];
            if ($e['home_blocked']) $homeBlocked[] = $tok;
            elseif ($e['blocked']) $pageBlocked[] = $tok;
            else $allowedAll[] = $tok;
        }
        $nInner = 0;
        foreach ($paths as $info) if (!array_intersect($info['roles'], ['root', 'home'])) $nInner++;

        $wafRet = array_values(array_filter($this->wafConfirmed, function ($n) { return self::PROBES[$n]['retrieval']; }));
        $wafTail = !$wafRet ? '' : (count($wafRet) <= 2
            ? '; але сервер відмовляє ' . implode(', ', $wafRet) . ' (див. crawler.waf_blocks_ai)'
            : '; але сервер відмовляє ' . count($wafRet) . ' з них (див. crawler.waf_blocks_ai)');
        if (!$homeBlocked && !$pageBlocked) {
            $this->P[] = 'За robots.txt усі ' . count(self::RETRIEVAL_BOTS) . ' пошукових і AI-пошукових ботів (' . self::listSome(array_keys(self::RETRIEVAL_BOTS), 4) . ') мають доступ до головної' . ($nInner ? ' і ' . $nInner . ' ' . self::plural($nInner, 'перевіреної внутрішньої сторінки', 'перевірених внутрішніх сторінок', 'перевірених внутрішніх сторінок') : '') . $wafTail;
        } elseif ($allowedAll) {
            $this->P[] = 'За robots.txt мають доступ: ' . self::listSome($allowedAll, 6) . $wafTail;
        }

        // явні дозволи для AI-ботів
        $explicit = [];
        foreach ($bots as $tok => $e) {
            if (in_array($tok, ['Googlebot', 'Bingbot', 'Applebot'], true)) continue;
            if ($e['matched'] !== null && strcasecmp((string)$e['matched'], $tok) === 0 && !$e['home_blocked'] && !$e['blocked']) $explicit[] = $tok;
        }
        if ($explicit) $this->P[] = 'Явні дозволи для AI-ботів у robots.txt: ' . self::listSome($explicit, 8);

        if (!$homeBlocked && !$pageBlocked) return;

        // ---- спільний виправлений robots.txt
        $tokens = [];
        foreach (self::RETRIEVAL_BOTS as $tok => $b) $tokens[$tok] = ['fb' => $b['fb'], 'home_only' => false];
        $comment = [];
        foreach ($pageBlocked as $tok) {
            foreach ($bots[$tok]['blocked'] as $path => $rule) {
                if ($rule && !self::blocksRoot($rule)) $comment[(int)$rule['line']] = $paths[$path]['url'];
            }
        }
        $this->retFix = ['drop' => $homeBlocked, 'comment' => $comment];
        $fix = $this->buildFixedRobots($parsed, $tokens, $homeBlocked, $comment, $paths, '# Пошукові та AI-пошукові боти: доступ до сайту');
        $codeAttached = false;

        if ($homeBlocked) {
            $sev = 'low';
            $lines = [];
            $groupRules = [];
            $userAgents = [];
            $byRule = [];
            $involved = [];
            foreach ($homeBlocked as $tok) {
                $b = self::RETRIEVAL_BOTS[$tok];
                if (self::SEV_RANK[$b['sev']] > self::SEV_RANK[$sev]) $sev = $b['sev'];
                $e = $bots[$tok];
                $rule = $e['home_rule'];
                $byRule[self::describeRule($parsed, $e, $rule)][] = $tok;
                foreach ($e['groups'] as $gi) {
                    if (!isset($groupRules[$gi])) $groupRules[$gi] = [];
                    if ($rule) $groupRules[$gi][] = (int)$rule['line'];
                }
                if ($b['user']) $userAgents[] = $tok;
                $involved[] = strtolower($tok);
                if ($e['matched'] !== null) $involved[] = strtolower((string)$e['matched']);
            }
            $lines = self::groupedBotLines($byRule, self::RETRIEVAL_BOTS, ['Googlebot', 'Bingbot', 'OAI-SearchBot', 'PerplexityBot']);
            $everything = in_array('Googlebot', $homeBlocked, true) && in_array('Bingbot', $homeBlocked, true) && count($homeBlocked) === count(self::RETRIEVAL_BOTS);
            $text = $everything
                ? 'robots.txt закриває головну для всіх пошукових і AI-ботів, зокрема Googlebot і Bingbot'
                : 'robots.txt забороняє ' . self::listSome($homeBlocked, 4) . ' сканувати головну';
            $minorOnly = $sev === 'low';
            if ($minorOnly) {
                $why = implode(', ', $homeBlocked) . ' - боти сервісів Amazon (' . implode('; ', array_map(function ($t) { return $t . ': ' . self::RETRIEVAL_BOTS[$t]['what']; }, $homeBlocked)) . '). Для українського ринку це другорядні канали, тож на AI-видимість заборона майже не впливає; якщо її не ставили свідомо (лишилася від шаблону), її можна прибрати.';
            } else {
                $why = 'Googlebot потрібен для Google Пошуку, AI Overviews і AI Mode; Bingbot - для Bing і Copilot (індекс Bing використовує й ChatGPT search); OAI-SearchBot, PerplexityBot і Claude-SearchBot наповнюють власні індекси AI-пошуковиків. Бот, якому заборонено сканування, не бачить вмісту, тож сайт не потрапляє у відповіді або показується без опису.';
                $ign = array_values(array_filter($userAgents, function ($t) { return (self::RETRIEVAL_BOTS[$t]['ign'] ?? null) === true; }));
                $obey = array_values(array_filter($userAgents, function ($t) { return (self::RETRIEVAL_BOTS[$t]['ign'] ?? null) === false; }));
                if ($ign) $why .= ' ' . implode(', ', $ign) . ' - запити на прохання користувача: OpenAI і Perplexity зазначають, що для таких запитів robots.txt може не застосовуватися, але заборона все одно сигналізує небажання бути процитованим.';
                if ($obey) $why .= ' ' . implode(', ', $obey) . ' виконують robots.txt, тож заборона прямо не дає ' . implode(' і ', array_map(function ($t) { return self::RETRIEVAL_BOTS[$t]['svc']; }, $obey)) . ' відкрити сторінку, коли користувач про це просить.';
            }
            $involved = array_values(array_unique($involved));
            $good = $this->renderExcerpt($parsed, $groupRules, $fix['drop'], $fix['comment'], $involved);
            if ($fix['added_group'] !== '') $good = trim($good . "\n\n" . $fix['added_group']);
            $this->F[] = $this->finding('robots_blocks_search', $sev, $text, [
                'evidence' => implode("\n", $lines),
                'why'      => $why,
                'fix'      => 'Приберіть заборону для пошукових і AI-пошукових ботів: видаліть їх із блокуючих груп, додайте до наявної групи-дозволу або окремою групою з Allow: /, залишивши закритими лише службові розділи. Готовий файл нижче зберігає решту ваших правил і Sitemap.',
                'bad'      => $this->renderExcerpt($parsed, $groupRules, null, null, $involved),
                'good'     => $good,
                'code'     => $fix['text'],
                'verify'   => 'Google Search Console: Налаштування -> Звіт про robots.txt (нова версія отримана), URL Inspection головної -> «Сканування дозволено: так». Bing Webmaster Tools -> Robots.txt Tester для Bingbot. Після публікації: curl -s ' . $robotsUrl . '.',
                'effort'   => 'S',
            ]);
            $codeAttached = true;
        }

        if ($pageBlocked) {
            // групуємо за (сторінка, правило)
            $byKey = [];
            foreach ($pageBlocked as $tok) {
                foreach ($bots[$tok]['blocked'] as $path => $rule) {
                    $k = $path . '|' . (int)($rule['line'] ?? 0);
                    if (!isset($byKey[$k])) $byKey[$k] = ['path' => $path, 'rule' => $rule, 'bots' => [], 'e' => $bots[$tok]];
                    $byKey[$k]['bots'][] = $tok;
                }
            }
            $sev = 'low';
            $lines = [];
            $groupRules = [];
            $involved = [];
            foreach ($byKey as $item) {
                $roles = $paths[$item['path']]['roles'];
                $isKey = (bool)array_intersect_key(array_flip($roles), self::KEY_ROLES);
                $majorBot = (bool)array_intersect($item['bots'], ['Googlebot', 'Bingbot', 'OAI-SearchBot', 'PerplexityBot']);
                if ($isKey && $majorBot) $sev = 'high';
                foreach ($item['bots'] as $t) {
                    if (self::RETRIEVAL_BOTS[$t]['sev'] !== 'low' && $sev === 'low') $sev = 'medium';
                    $involved[] = strtolower($t);
                }
                if ($item['e']['matched'] !== null) $involved[] = strtolower((string)$item['e']['matched']);
                $lines[] = self::roleLabel($roles) . ' ' . $paths[$item['path']]['url'] . ' - ' . self::describeRule($parsed, $item['e'], $item['rule']) . '; заборонено для ' . self::listSome($item['bots'], 5);
                foreach ($item['e']['groups'] as $gi) {
                    if (!isset($groupRules[$gi])) $groupRules[$gi] = [];
                    if ($item['rule']) $groupRules[$gi][] = (int)$item['rule']['line'];
                }
            }
            $involved = array_values(array_unique($involved));
            $this->F[] = $this->finding('robots_blocks_pages', $sev, 'robots.txt закриває від пошукових ботів частину перевірених сторінок', [
                'evidence' => implode("\n", array_slice($lines, 0, 8)) . "\nЯкщо якусь із цих сторінок закрито свідомо (кабінет, кошик, форма) - це зауваження для неї можна ігнорувати.",
                'why'      => 'Сторінки, які бот не може завантажити, не потрапляють в індекс з повним текстом, тож AI-пошук не процитує ні статей, ні товарів, ні послуг з цих розділів.',
                'fix'      => 'Видаліть або звузьте правила, що зачіпають змістовні сторінки (наприклад, закривайте лише /search/ чи параметри ?sort=, а не весь розділ).',
                'bad'      => $this->renderExcerpt($parsed, $groupRules, null, null, $involved),
                'good'     => $this->renderExcerpt($parsed, $groupRules, $fix['drop'], $fix['comment'], $involved) . ($fix['added_group'] !== '' && !$homeBlocked ? "\n\n" . $fix['added_group'] : ''),
                'code'     => $codeAttached ? '' : $fix['text'],
                'verify'   => 'Google Search Console -> URL Inspection для кожної сторінки зі списку: «Сканування дозволено: так». Bing Webmaster Tools -> Robots.txt Tester.' . ($codeAttached ? ' Виправлений файл - у зауваженні crawler.robots_blocks_search.' : ''),
                'effort'   => 'S',
            ]);
        }
    }

    /** Знахідки по ботах для навчання моделей (інформуємо, low). */
    private function robotsTrainingFindings(array $parsed, array $bots, array $paths, string $robotsUrl): void
    {
        $blocked = [];
        $allowed = [];
        foreach (self::TRAINING_BOTS as $tok => $b) {
            if ($bots[$tok]['home_blocked']) $blocked[] = $tok;
            else $allowed[] = $tok;
        }
        $wafTr = array_values(array_filter($this->wafConfirmed, function ($n) { return !self::PROBES[$n]['retrieval']; }));
        if (!$blocked) {
            $this->P[] = 'За robots.txt боти для навчання моделей (' . self::listSome(array_keys(self::TRAINING_BOTS), 4) . ') не заблоковані'
                . ($wafTr
                    ? '; але сервер відмовляє ' . implode(', ', $wafTr) . ' за User-Agent (див. crawler.waf_blocks_' . ($this->wafConfirmedRet() ? 'ai' : 'training') . ')'
                    : ' - тексти сайту можуть потрапляти в знання моделей');
            return;
        }
        // якщо '*' закриває все - це вже критична знахідка вище, окремо не дублюємо
        if (!empty($bots['Googlebot']['home_blocked']) && count($blocked) === count(self::TRAINING_BOTS)) return;

        $lines = [];
        $groupRules = [];
        $byRule = [];
        $involved = [];
        foreach ($blocked as $tok) {
            $e = $bots[$tok];
            $byRule[self::describeRule($parsed, $e, $e['home_rule'])][] = $tok;
            foreach ($e['groups'] as $gi) {
                if (!isset($groupRules[$gi])) $groupRules[$gi] = [];
                if ($e['home_rule']) $groupRules[$gi][] = (int)$e['home_rule']['line'];
            }
            $involved[] = strtolower($tok);
            if ($e['matched'] !== null) $involved[] = strtolower((string)$e['matched']);
        }
        $involved = array_values(array_unique($involved));
        $lines = self::groupedBotLines($byRule, self::TRAINING_BOTS, ['GPTBot', 'Google-Extended', 'ClaudeBot']);
        $pairs = ['GPTBot' => 'OAI-SearchBot', 'ClaudeBot' => 'Claude-SearchBot'];
        $okPairs = [];
        $badPairs = [];
        foreach ($pairs as $tr => $se) {
            if (!in_array($tr, $blocked, true)) continue;
            if (empty($bots[$se]['home_blocked'])) $okPairs[] = $se; else $badPairs[] = $se;
        }
        if ($okPairs) {
            $svc = array_map(function ($t) { return self::RETRIEVAL_BOTS[$t]['svc']; }, $okPairs);
            $l = 'За robots.txt пошукові боти тих самих компаній дозволені (' . implode(', ', $okPairs) . '), тож ця заборона не заважає показу в ' . implode(' і ', $svc) . '.';
            $wafHit = array_values(array_intersect($okPairs, $this->wafConfirmed));
            if ($wafHit) $l .= ' Але сервер відмовляє ' . implode(', ', $wafHit) . ' за User-Agent (див. crawler.waf_blocks_ai).';
            $lines[] = $l;
        }
        if ($badPairs) $lines[] = 'Пошукові боти тих самих компаній теж заблоковані: ' . implode(', ', $badPairs) . ' (див. crawler.robots_blocks_search).';
        if ($allowed) $lines[] = 'Не заблоковані в robots.txt: ' . self::listSome($allowed, 5) . '.';
        // боти, яких robots.txt дозволяє, але сервер блокує, - щоб «не заблоковані» не суперечило WAF-знахідці
        $wafTrAll = array_values(array_intersect(array_filter($this->wafConfirmed, function ($n) { return !self::PROBES[$n]['retrieval']; }), $allowed));
        if ($wafTrAll) $lines[] = 'Але на рівні сервера ' . implode(', ', $wafTrAll) . ' ' . (count($wafTrAll) === 1 ? 'отримує' : 'отримують') . ' відмову за User-Agent (див. crawler.waf_blocks_' . ($this->wafConfirmedRet() ? 'ai' : 'training') . ').';
        if (!empty($this->D['robots']['other_blocked_agents'])) {
            $lines[] = 'Також повністю закриті боти поза цим переліком: ' . self::listSome($this->D['robots']['other_blocked_agents'], 6) . '.';
        }

        $why = 'Навчальні боти збирають тексти для тренування моделей, а не для пошуку з посиланнями, тому блокування - легітимне бізнес-рішення і напряму не прибирає сайт з ChatGPT search, Perplexity чи Google AI Overviews. Компроміс: бренд, продукти і факти про компанію гірше представлені у «знаннях» моделей, коли асистент відповідає без пошуку.';
        if (in_array('Google-Extended', $blocked, true)) $why .= ' За документацією Google, Google-Extended керує також використанням контенту для grounding у застосунках Gemini (на Google Пошук і AI Overviews не впливає).';

        // для навчальних ботів важить лише доступ до сайту загалом (корінь / головна);
        // якщо є виправлення для пошукових ботів - варіант будується поверх нього
        $tokens = [];
        foreach (self::TRAINING_BOTS as $tok => $b) $tokens[$tok] = ['fb' => $b['fb'], 'home_only' => true];
        $withRet = $this->retFix['drop'] || $this->retFix['comment'];
        if ($withRet) {
            foreach (self::RETRIEVAL_BOTS as $tok => $b) $tokens[$tok] = ['fb' => $b['fb'], 'home_only' => false];
        }
        $fix = $this->buildFixedRobots($parsed, $tokens, array_merge($blocked, $this->retFix['drop']), $this->retFix['comment'], $paths,
            $withRet ? '# Пошукові, AI-пошукові та навчальні боти: доступ до сайту' : '# Боти для навчання моделей: доступ до сайту');
        $good = $this->renderExcerpt($parsed, $groupRules, $fix['drop'], [], $involved);
        if ($fix['added_group'] !== '') $good = trim($good . "\n\n" . $fix['added_group']);

        $this->F[] = $this->finding('robots_training', 'low', 'robots.txt забороняє ботів для навчання моделей: ' . self::listSome($blocked, 4), [
            'evidence' => implode("\n", $lines),
            'why'      => $why,
            'fix'      => 'Якщо це свідоме рішення - залиште як є. Якщо ні (правило лишилося від шаблону чи плагіна) - приберіть заборону; готовий файл нижче зберігає решту правил' . ($withRet ? ' і вже містить виправлення для пошукових ботів' : '') . '. У будь-якому разі не блокуйте пошукових ботів (OAI-SearchBot, Claude-SearchBot, PerplexityBot).',
            'bad'      => $this->renderExcerpt($parsed, $groupRules, null, null, $involved),
            'good'     => $good,
            'code'     => $fix['text'],
            'verify'   => 'Після зміни: curl -s ' . $robotsUrl . ' і перевірка, що групи для ' . self::listSome($blocked, 3) . ' не містять Disallow: /.',
            'effort'   => 'S',
        ]);
    }

    /** Crawl-delay, синтаксис, конфлікти. Повертає штраф для robots_file. */
    private function robotsHygieneFindings(array $parsed, array $bots, string $robotsUrl): int
    {
        $penalty = 0;
        // ---- Crawl-delay для Bingbot
        $bing = $bots['Bingbot'] ?? null;
        if ($bing && $bing['groups']) {
            $cd = null; $cdGroup = null;
            foreach ($bing['groups'] as $gi) {
                $v = $parsed['groups'][$gi]['crawl_delay'];
                if ($v !== null && ($cd === null || $v > $cd)) { $cd = $v; $cdGroup = $gi; }
            }
            if ($cd !== null && $cd >= 5 && $cdGroup !== null) {
                $g = $parsed['groups'][$cdGroup];
                $perDay = (int)floor(86400 / $cd);
                $bad = [];
                $uaHead = self::uaHeadLines($parsed, $g, ['bingbot', 'msnbot', '*'], 4);
                foreach ($uaHead as $l) $bad[] = $l;
                $bad[] = self::rawLine($parsed, (int)$g['cd_line']);
                $goodLines = [];
                foreach ($uaHead as $l) $goodLines[] = $l;
                $goodLines[] = '# Crawl-delay прибрано (або Crawl-delay: 1)';
                // виправлений файл: без рядка Crawl-delay, решта правил і Sitemap без змін
                $cdLine = (int)$g['cd_line'];
                $fixed = [];
                $prevBlank = false;
                foreach ($parsed['lines'] as $ln => $raw) {
                    if ($ln === $cdLine) continue;
                    $isBlank = trim($raw) === '';
                    if ($isBlank && $prevBlank) continue;
                    $fixed[] = $raw;
                    $prevBlank = $isBlank;
                }
                $cdCode = trim(implode("\n", $fixed)) . "\n";
                if (strlen($cdCode) > 5500) {
                    $cdCode = '# Ваш robots.txt великий, тому тут лише зміна (решту файлу не чіпайте):' . "\n# Видаліть рядок " . $cdLine . ': ' . trim(self::rawLine($parsed, $cdLine)) . "\n";
                } else {
                    $cdCode = '# Виправлений robots.txt: прибрано рядок ' . $cdLine . ' «' . trim(self::rawLine($parsed, $cdLine)) . "», решта без змін\n" . $cdCode;
                }
                $this->F[] = $this->finding('crawl_delay', 'low', 'Crawl-delay: ' . self::num($cd) . ' сповільнює сканування Bingbot', [
                    'evidence' => 'Рядок ' . $g['cd_line'] . ': «' . trim(self::rawLine($parsed, (int)$g['cd_line'])) . '» у групі «User-agent: ' . self::groupName($g) . '», яка діє для Bingbot. Це не більше 1 запиту на ' . self::num($cd) . ' с, тобто до ~' . number_format($perDay, 0, '', ' ') . ' сторінок на добу.',
                    'why'      => 'Googlebot ігнорує Crawl-delay, а Bingbot його виконує. Індекс Bing живить Copilot і використовується в ChatGPT search, тож нові й оновлені сторінки потраплятимуть туди повільніше.',
                    'fix'      => 'Приберіть Crawl-delay або зменшіть до 1-2 с. Навантаження від Bing краще регулювати в Bing Webmaster Tools -> Crawl Control.',
                    'bad'      => implode("\n", $bad),
                    'good'     => implode("\n", $goodLines),
                    'code'     => $cdCode,
                    'verify'   => 'Bing Webmaster Tools -> Robots.txt Tester і звіт Search Performance (показник Crawl Requests): кількість запитів на сканування має зрости.',
                    'effort'   => 'S',
                ]);
            }
        }

        // ---- синтаксис
        $issues = $parsed['issues'];
        $ev = []; $bad = []; $good = [];
        foreach (array_slice($issues['orphan'], 0, 4) as $ln) {
            $ev[] = 'Рядок ' . $ln . ': «' . trim(self::rawLine($parsed, $ln)) . '» стоїть до першого User-agent і ігнорується.';
            $bad[] = self::rawLine($parsed, $ln);
        }
        if ($issues['orphan']) $good[] = "User-agent: *\n" . trim(self::rawLine($parsed, $issues['orphan'][0]));
        foreach (array_slice($issues['nocolon'], 0, 3) as $ln) {
            $ev[] = 'Рядок ' . $ln . ': «' . trim(self::rawLine($parsed, $ln)) . '» без двокрапки - Google це розуміє, інші боти можуть пропустити.';
            $bad[] = self::rawLine($parsed, $ln);
            $good[] = preg_replace('/^(\s*[A-Za-z\-]+)\s+/', '$1: ', trim(self::rawLine($parsed, $ln)));
        }
        foreach (array_slice($issues['conflict'], 0, 3) as $c) {
            $g = $parsed['groups'][$c['group']];
            $ev[] = 'Рядки ' . $c['lines'][0] . ' і ' . $c['lines'][1] . ' (група «User-agent: ' . self::groupName($g) . '»): Allow і Disallow з однаковим шляхом «' . $c['raw'] . '». Google за рівної довжини обирає Allow (дозвіл), але інші боти можуть прочитати це як заборону.';
            $bad[] = self::rawLine($parsed, $c['lines'][0]) . "\n" . self::rawLine($parsed, $c['lines'][1]);
            $good[] = '# залиште одне правило, яке відповідає наміру, напр.: ' . trim(self::rawLine($parsed, $c['lines'][0]));
        }
        foreach ($issues['unknown'] as $u) {
            if (in_array(strtolower($u[1]), ['noindex', 'nofollow', 'noarchive', 'nosnippet'], true)) {
                $ev[] = 'Рядок ' . $u[0] . ': «' . trim(self::rawLine($parsed, $u[0])) . '» - директиву ' . $u[1] . ' у robots.txt Google не підтримує з 2019 року, вона нічого не робить.';
                $bad[] = self::rawLine($parsed, $u[0]);
                $good[] = '# noindex - лише в <meta name="robots"> або заголовку X-Robots-Tag';
            }
        }
        if ($ev) {
            $penalty += 10;
            $this->F[] = $this->finding('robots_syntax', 'low', 'У robots.txt є рядки, які боти прочитають не так, як задумано', [
                'evidence' => implode("\n", $ev),
                'why'      => 'Такі рядки або ігноруються, або трактуються по-різному різними ботами. Через це правила, які мали щось закривати чи відкривати, можуть не працювати для частини пошукових і AI-ботів.',
                'fix'      => 'Виправте рядки: кожне правило має стояти після User-agent, мати формат «Директива: значення», а для одного шляху має бути одне правило.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => implode("\n", array_unique($good)),
                'verify'   => 'Google Search Console: Налаштування -> Звіт про robots.txt (розділ попереджень). Bing Webmaster Tools -> Robots.txt Tester.',
                'effort'   => 'S',
            ]);
        }
        if ($issues['unknown']) {
            $this->D['robots']['unknown_directives'] = array_slice(array_values(array_unique(array_map(function ($u) { return $u[1]; }, $issues['unknown']))), 0, 10);
        }
        return $penalty;
    }

    // ================================================================== 2. WAF / сервер

    /**
     * Мережева частина проби: паралельний GET головної з User-Agent AI-ботів (тайм-аут PROBE_TIMEOUT),
     * один спільний повтор для запитів без відповіді і контрольний запит тим самим User-Agent, що й базова
     * відповідь (наш бот або браузер), - щоб не прийняти бан IP аудитора посеред перевірки за блок AI-ботів.
     */
    private function runProbes(array $ctx, string $finalUrl): void
    {
        $access = (array)($ctx['access'] ?? []);
        $fetchedAs = (string)($access['fetched_as'] ?? 'none');
        $hp = (array)($ctx['homepage'] ?? []);
        $baseBytes = strlen((string)($hp['body'] ?? ''));
        $baselineOk = in_array($fetchedAs, ['bot', 'browser'], true);
        $t0 = microtime(true);

        $reqs = [];
        foreach (self::PROBES as $n => $pr) $reqs[$n] = ['url' => $finalUrl, 'ua' => $pr['ua']];
        $probes = [];
        foreach (self::multiGet($reqs, self::PROBE_TIMEOUT) as $n => $r) {
            $probes[$n] = self::probeRec($r, self::classifyProbe($r, $baseBytes, $finalUrl));
        }

        $control = null;
        $needRound2 = (bool)array_filter($probes, function ($p) { return $p['cls'] !== 'ok'; });
        if ($needRound2) {
            $reqs2 = [];
            foreach ($probes as $n => $p) if ($p['cls'] === 'noresp') $reqs2[$n] = $reqs[$n];
            if ($baselineOk) {
                $reqs2['_control'] = $fetchedAs === 'browser'
                    ? ['url' => $finalUrl, 'ua' => Crawler::altUa(), 'headers' => Crawler::altName() === 'Chrome' ? self::BROWSER_HEADERS : self::BOT_HEADERS]
                    : ['url' => $finalUrl, 'ua' => Crawler::UA, 'headers' => self::BOT_HEADERS];
            }
            if ($reqs2) {
                usleep(400000);
                foreach (self::multiGet($reqs2, self::PROBE_TIMEOUT) as $n => $r) {
                    $cls = self::classifyProbe($r, $baseBytes, $finalUrl);
                    if ($n === '_control') {
                        $control = self::probeRec($r, $cls) + ['ua_name' => $fetchedAs === 'browser' ? Crawler::altName() : 'GeoAuditBot'];
                        continue;
                    }
                    $first = $probes[$n];
                    if ($cls !== 'noresp') {
                        $probes[$n] = self::probeRec($r, $cls) + ['retried' => true];
                        continue;
                    }
                    $probes[$n]['retried'] = true;
                    $probes[$n]['timeout'] = $first['timeout'] && $r['timeout'];
                    $e2 = self::shortErr((string)$r['error']);
                    if ($e2 !== '' && ($e2 !== 'таймаут' || $probes[$n]['error'] === '')) $probes[$n]['error'] = $e2;
                }
            }
        }

        // остаточна класифікація запитів без відповіді: лише якщо контрольний запит пройшов
        $controlOk = $control !== null && $control['cls'] === 'ok';
        foreach ($probes as $n => $p) {
            if ($p['cls'] !== 'noresp' || !$controlOk) continue;
            if ($p['timeout']) {
                // двічі мовчить, хоча звичайний запит відповідає швидко: ймовірно, навмисне сповільнення
                if ((int)$control['ms'] < 5000) { $probes[$n]['cls'] = 'slow'; $probes[$n]['ctl_ms'] = (int)$control['ms']; }
            } elseif ($p['error'] !== '') {
                // двічі розриває з'єднання без відповіді (типово nginx «return 444» або правило WAF)
                $probes[$n]['cls'] = 'dropped';
            }
        }

        $confirmed = [];
        if ($baselineOk && $controlOk) {
            foreach ($probes as $n => $p) if (in_array($p['cls'], ['blocked', 'dropped', 'slow'], true)) $confirmed[] = $n;
        }
        $this->wafConfirmed = $confirmed;
        $this->probe = [
            'probes' => $probes, 'control' => $control, 'baseline_ok' => $baselineOk, 'fetched_as' => $fetchedAs,
            'base_bytes' => $baseBytes, 'ms' => (int)round((microtime(true) - $t0) * 1000),
        ];
    }

    private function wafConfirmedRet(): bool
    {
        foreach ($this->wafConfirmed as $n) if (self::PROBES[$n]['retrieval']) return true;
        return false;
    }

    private function wafSection(array $ctx, string $finalUrl, array $rb): ?int
    {
        $access = (array)($ctx['access'] ?? []);
        $fetchedAs = (string)($access['fetched_as'] ?? 'none');
        $hp = (array)($ctx['homepage'] ?? []);
        $hpHeaders = (array)($hp['headers'] ?? []);
        $baseBytes = (int)($this->probe['base_bytes'] ?? 0);
        $baselineOk = !empty($this->probe['baseline_ok']);
        $botStatus = (int)($access['bot_status'] ?? 0);
        $botChallenge = $access['bot_challenge'] ?? null;
        $browserStatus = $access['browser_status'] ?? null;
        $browserChallenge = $access['browser_challenge'] ?? null;
        $probes = (array)($this->probe['probes'] ?? []);
        $control = $this->probe['control'] ?? null;
        if (!$probes) return null;

        // хто саме блокує: спершу постачальник зі сторінки перевірки / заголовків захисту, потім CDN із заголовків
        $sec = null;
        $chs = [];
        foreach ($probes as $p) if (!empty($p['challenge'])) $chs[] = (string)$p['challenge'];
        if ($botChallenge) $chs[] = (string)$botChallenge;
        if ($browserChallenge) $chs[] = (string)$browserChallenge;
        foreach ($chs as $c) if (!empty(self::CHALLENGE_VENDORS[$c])) { $sec = self::CHALLENGE_VENDORS[$c]; break; }
        if ($sec === null) $sec = self::detectSecurity($hpHeaders);
        $cdnHdr = self::detectCdn($hpHeaders);
        $vendor = $sec ?: $cdnHdr;
        $vendorText = ($sec && $cdnHdr && $sec !== $cdnHdr) ? $sec . ' на ' . $cdnHdr : (string)$vendor;

        $this->D['waf'] = [
            'baseline' => $fetchedAs, 'bot_status' => $botStatus, 'bot_challenge' => $botChallenge,
            'browser_status' => $browserStatus, 'homepage_bytes' => $baseBytes, 'cdn' => $vendorText !== '' ? $vendorText : null,
            'probes' => array_map(function ($p) { return array_intersect_key($p, array_flip(['status', 'cls', 'bytes', 'challenge', 'error', 'retried', 'ms'])); }, $probes),
            'control' => $control ? array_intersect_key($control, array_flip(['status', 'cls', 'challenge', 'error', 'ms', 'ua_name'])) : null,
            'confirmed' => $this->wafConfirmed, 'ms' => (int)($this->probe['ms'] ?? 0),
        ];

        $allNames = array_keys($probes);
        $altAgent = (string)($access['alt_agent'] ?? '');
        $baseStatus = $fetchedAs === 'bot' ? $botStatus : (int)($altAgent !== '' ? ($access['homepage_status'] ?? 0) : $browserStatus);
        $baselineText = $fetchedAs === 'bot'
            ? 'Наш бот (GeoAuditBot) на початку аудиту: HTTP ' . $botStatus . ', ' . self::kb($baseBytes) . ' HTML.'
            : ($fetchedAs === 'browser' && $altAgent !== ''
                ? 'Наш бот (GeoAuditBot): HTTP ' . $botStatus . ($botChallenge ? ' (' . self::chLabel((string)$botChallenge) . ')' : '') . '; браузер (Chrome): HTTP ' . (int)$browserStatus . ($browserChallenge ? ' (' . self::chLabel((string)$browserChallenge) . ')' : '') . '; ' . $altAgent . ' на початку аудиту: HTTP ' . $baseStatus . ', ' . self::kb($baseBytes) . ' HTML.'
            : ($fetchedAs === 'browser'
                ? 'Наш бот (GeoAuditBot): HTTP ' . $botStatus . ($botChallenge ? ' (' . self::chLabel((string)$botChallenge) . ')' : '') . '; браузер (Chrome) на початку аудиту: HTTP ' . (int)$browserStatus . ', ' . self::kb($baseBytes) . ' HTML.'
                : 'Наш бот (GeoAuditBot): HTTP ' . $botStatus . ($botChallenge ? ' (' . self::chLabel((string)$botChallenge) . ')' : '') . '; браузер (Chrome): ' . ($browserStatus !== null ? 'HTTP ' . (int)$browserStatus : 'не перевірявся') . ($browserChallenge ? ' (' . self::chLabel((string)$browserChallenge) . ')' : '') . '.'));
        if ($altAgent !== '') {
            $this->P[] = 'Захист' . ($vendorText !== '' ? ' (' . $vendorText . ')' : '') . ' блокує невідомих ботів і браузерні запити з нашого сервера, але пропускає ' . $altAgent . ': контент проаналізовано від його імені, тобто так, як сайт бачить AI-пошук';
        }
        $controlText = $control ? 'Контрольний запит (' . $control['ua_name'] . ') після проб: ' . self::probeText($control) . '.' : '';
        $cdnLine = $vendorText !== '' ? 'Ознаки захисту / CDN: ' . $vendorText . '.' : '';
        $errLine = self::errLine($probes);
        $robotsBotLine = '';
        if (!empty($rb['bot_attempt']) && !empty($rb['via_browser'])) {
            $robotsBotLine = 'robots.txt нашому боту теж не віддається: HTTP ' . (int)($rb['bot_attempt']['status'] ?? 0) . (!empty($rb['bot_attempt']['challenge']) ? ' (' . self::chLabel((string)$rb['bot_attempt']['challenge']) . ')' : '') . ', браузеру - HTTP 200.';
        }
        $method = 'Метод: GET головної з User-Agent AI-ботів з IP нашого сервера (підміна UA, не справжні боти) - підтвердіть результат у логах або журналі WAF.';
        $spoofWhy = ' Перевірку зроблено підміною User-Agent з IP нашого сервера. Справжні боти приходять з IP-адрес OpenAI, Perplexity, Anthropic і можуть оброблятися інакше (як у кращий, так і в гірший бік), тож підтвердьте результат у логах сервера або журналі WAF.';
        $code = self::wafCode($vendor);
        $verifyUa = self::PROBES['OAI-SearchBot']['ua'];
        $verify = 'З іншого сервера (не з офісної мережі): curl -s -o /dev/null -w "%{http_code}\n" -A "' . $verifyUa . '" ' . $finalUrl . ' - очікуємо 200; повторіть для PerplexityBot, Claude-SearchBot і ChatGPT-User. Головне підтвердження - логи сервера або журнал подій WAF' . ($vendor === 'Cloudflare' ? ' (Cloudflare: Security -> Events, фільтр за User agent)' : '') . ': справжні запити OAI-SearchBot, PerplexityBot мають отримувати 200.';

        // ---- базова відповідь заблокована: висновок неможливий
        if (!$baselineOk) {
            $okProbes = array_keys(array_filter($probes, function ($p) { return $p['cls'] === 'ok'; }));
            if ($okProbes && count($okProbes) === count($probes)) {
                $this->P[] = 'Запити з User-Agent AI-ботів (' . self::listSome($okProbes, 7) . ') пройшли з HTTP 200, хоча звичайні запити з нашого сервера блокуються - захист пропускає AI-ботів';
                return 100;
            }
            // браузер отримує сторінку перевірки, а частина AI-ботів - жорстку відмову: окреме правило проти них
            $baseCh = $browserChallenge ?: $botChallenge;
            $hard = [];
            if ($baseCh) {
                foreach ($probes as $n => $p) if (in_array($p['cls'], ['blocked', 'dropped'], true) && empty($p['challenge'])) $hard[] = $n;
            }
            $hardRet = array_values(array_filter($hard, function ($n) { return self::PROBES[$n]['retrieval']; }));
            $ev = [$baselineText, 'З User-Agent AI-ботів (той самий IP):'];
            foreach (self::groupProbes($probes, $allNames) as $g) $ev[] = implode(', ', $g[0]) . ': ' . $g[1];
            if ($errLine !== '') $ev[] = $errLine;
            if ($hard) $ev[] = implode(', ', $hard) . ' отримали жорстку відмову без сторінки перевірки, тоді як браузер - ' . self::chLabel((string)$baseCh) . ': ймовірно, для цих User-Agent діє окреме правило блокування.';
            if ($cdnLine !== '') $ev[] = $cdnLine;
            $bad = ['GET / (наш сервер)'];
            foreach (self::groupProbes($probes, $allNames) as $g) $bad[] = '[' . implode(', ', $g[0]) . '] -> ' . $g[1];
            $this->F[] = $this->finding('waf_inconclusive', $hardRet ? 'medium' : 'low', $hardRet
                ? 'Ймовірно, захист окремо блокує AI-ботів (' . implode(', ', $hard) . '), але повністю перевірити не вдалося'
                : 'Не вдалося перевірити, чи пропускає сервер AI-ботів: захист блокує і звичайні запити аудитора', [
                'evidence' => implode("\n", $ev),
                'why'      => 'Захист блокує IP нашого сервера, тому не видно, чи відрізняє він AI-ботів. Справжні Googlebot, Bingbot, OAI-SearchBot і PerplexityBot приходять з опублікованих IP і часто пропускаються як верифіковані боти, але правила на кшталт «Block AI bots» блокують саме їх.',
                'fix'      => 'Перевірте налаштування WAF/CDN: чи не блокуються верифіковані пошукові та AI-пошукові боти' . ($vendor === 'Cloudflare' ? ' (Cloudflare: Security -> Bots, AI Crawl Control, Security -> Events)' : ($vendor ? ' (панель ' . $vendor . ')' : '')) . ', і подивіться в логах сервера, з яким кодом відповідають справжнім запитам OAI-SearchBot, PerplexityBot, Claude-SearchBot, ChatGPT-User.',
                'bad'      => implode("\n", $bad),
                'good'     => "Справжні OAI-SearchBot, PerplexityBot, Claude-SearchBot, ChatGPT-User у логах сервера -> HTTP 200\nУ налаштуваннях WAF верифіковані пошукові й AI-пошукові боти - Allow",
                'code'     => $code,
                'verify'   => $verify,
                'effort'   => 'S',
            ]);
            return null;
        }

        // ---- контрольний запит після проб теж не пройшов: сервер почав блокувати IP аудитора
        if ($control !== null && $control['cls'] !== 'ok') {
            $notOk = array_keys(array_filter($probes, function ($p) { return $p['cls'] !== 'ok'; }));
            $okNames = array_keys(array_filter($probes, function ($p) { return $p['cls'] === 'ok'; }));
            $ev = [$baselineText, $controlText];
            foreach (self::groupProbes($probes, $notOk) as $g) $ev[] = implode(', ', $g[0]) . ': ' . $g[1];
            if ($okNames) $ev[] = 'Пройшли: ' . implode(', ', $okNames) . '.';
            if ($errLine !== '') $ev[] = $errLine;
            $ev[] = 'Наш звичайний запит після проб теж отримав відмову, тож вона не залежить від User-Agent: ймовірно, спрацював ліміт запитів або бан IP (fail2ban, WAF).';
            if ($cdnLine !== '') $ev[] = $cdnLine;
            $bad = ['GET / [' . $control['ua_name'] . '] на початку -> HTTP ' . $baseStatus . ', після проб -> ' . self::probeText($control)];
            foreach (self::groupProbes($probes, $notOk) as $g) $bad[] = 'GET / [' . implode(', ', $g[0]) . '] -> ' . $g[1];
            $this->F[] = $this->finding('waf_inconclusive', 'low', 'Не вдалося перевірити AI-ботів: сервер почав блокувати IP аудитора під час перевірки', [
                'evidence' => implode("\n", $ev),
                'why'      => 'Коли сервер блокує IP після серії запитів, неможливо відрізнити блокування AI-ботів від загального ліміту. Надто агресивний ліміт або автобан можуть зачіпати й справжніх пошукових і AI-ботів, які сканують сайт серіями запитів.',
                'fix'      => 'Перевірте налаштування rate limiting / fail2ban / WAF: верифіковані пошукові та AI-пошукові боти (Googlebot, Bingbot, OAI-SearchBot, PerplexityBot, Claude-SearchBot) не повинні потрапляти в автобан. У логах сервера перевірте, з якими кодами відповідають справжнім AI-ботам.',
                'bad'      => implode("\n", $bad),
                'good'     => "GET / [GeoAuditBot] -> HTTP 200 і на початку, і після серії запитів\nСправжні OAI-SearchBot, PerplexityBot у логах сервера -> HTTP 200",
                'code'     => ($vendor === 'Cloudflare' ? '' : "# Автобан / ліміти запитів на сервері\nsudo fail2ban-client status            # які jail-и активні\nsudo fail2ban-client status [ВКАЖІТЬ jail]  # чи є там IP ботів\ngrep -RniE \"limit_req|limit_conn|deny \" /etc/nginx/ 2>/dev/null\n\n") . $code,
                'verify'   => $verify . ' Запустіть аудит повторно через кілька годин.',
                'effort'   => 'S',
            ]);
            return null;
        }

        // ---- базова відповідь ОК: порівнюємо
        $totalW = 0; $gotW = 0.0;
        $blockedRet = []; $blockedTr = []; $differs = []; $limited = []; $ok = [];
        foreach ($probes as $name => $p) {
            $w = self::PROBES[$name]['w'];
            $totalW += $w;
            switch ($p['cls']) {
                case 'ok': $gotW += $w; $ok[] = $name; break;
                case 'ratelimit': $gotW += $w * 0.5; $limited[] = $name; break;
                case 'differs':
                case 'noresp': $gotW += $w * 0.5; $differs[] = $name; break;
                default:
                    if (self::PROBES[$name]['retrieval']) $blockedRet[] = $name; else $blockedTr[] = $name;
            }
        }
        $score = $totalW > 0 ? (int)round(100 * $gotW / $totalW) : null;

        if ($ok && !$blockedRet && !$blockedTr && !$differs && !$limited) {
            $this->P[] = 'Запити з User-Agent ' . self::listSome($ok, 7) . ' отримали HTTP 200 і повний HTML, як і звичайний запит (перевірка підміною User-Agent з нашого сервера)';
            return $score;
        }
        if ($ok) $this->P[] = 'Без обмежень на рівні сервера: ' . implode(', ', $ok) . ' (HTTP 200)';

        // суперечність з robots.txt
        $robotsNote = '';
        if (!empty($rb['bots'])) {
            $allowedByRobots = [];
            foreach (array_merge($blockedRet, $blockedTr) as $n) {
                if (isset($rb['bots'][$n]) && !$rb['bots'][$n]['home_blocked']) $allowedByRobots[] = $n;
            }
            if ($allowedByRobots) $robotsNote = 'robots.txt при цьому дозволяє ' . implode(', ', $allowedByRobots) . ' - блокування відбувається на рівні сервера / CDN.';
        }
        $cmpName = $fetchedAs === 'bot' ? 'GeoAuditBot' : Crawler::altName();
        $cmpLine = 'GET / [' . $cmpName . '] -> HTTP ' . $baseStatus . ($control ? '; контроль після проб -> ' . self::probeText($control) : '');

        if ($blockedRet || $blockedTr) {
            $blockedAll = array_merge($blockedRet, $blockedTr);
            if ($blockedRet) {
                // чи AI-боти отримують те саме, що й наш звичайний бот (загальний фільтр ботів)
                $sameAsGeneric = false;
                if ($fetchedAs === 'browser' && $altAgent === '') {
                    $sameAsGeneric = true;
                    foreach ($blockedAll as $n) {
                        $p = $probes[$n];
                        if ((int)$p['status'] !== $botStatus || (string)($p['challenge'] ?? '') !== (string)($botChallenge ?? '')) { $sameAsGeneric = false; break; }
                    }
                }
                // без OAI-SearchBot / PerplexityBot (лише ChatGPT-User, Claude-SearchBot, Perplexity-User) - відчутна, але не повна втрата: medium
                $majorBlocked = (bool)array_filter($blockedRet, function ($n) { return self::PROBES[$n]['major']; });
                $sev = ($sameAsGeneric || !$majorBlocked) ? 'medium' : 'high';
                $text = $sameAsGeneric
                    ? 'Захист пропускає лише браузерні запити: AI-боти отримують відмову, як і будь-який бот (ймовірно)'
                    : 'Ймовірно, сервер або WAF блокує AI-ботів за User-Agent: ' . implode(', ', $blockedAll);
            } else {
                $sev = 'low';
                $text = 'Ймовірно, сервер блокує навчальних ботів за User-Agent: ' . implode(', ', $blockedTr);
            }
            $ev = [$method, $baselineText];
            if ($controlText !== '') $ev[] = $controlText;
            foreach (self::groupProbes($probes, array_merge($blockedAll, $differs, $limited)) as $g) $ev[] = implode(', ', $g[0]) . ': ' . $g[1];
            if ($errLine !== '') $ev[] = $errLine;
            if ($ok) $ev[] = 'Проходять: ' . implode(', ', $ok) . '.';
            if ($robotsNote !== '') $ev[] = $robotsNote;
            if ($robotsBotLine !== '') $ev[] = $robotsBotLine;
            if ($cdnLine !== '') $ev[] = $cdnLine;
            $bad = [$cmpLine];
            foreach (self::groupProbes($probes, $blockedAll) as $g) $bad[] = 'GET / [' . implode(', ', $g[0]) . '] -> ' . $g[1];
            $why = $blockedRet
                ? 'Якщо захист не пропускає OAI-SearchBot, ChatGPT-User, PerplexityBot чи Claude-SearchBot, ці системи не можуть завантажити сторінку, тож не покажуть і не процитують сайт, навіть якщо robots.txt усе дозволяє.'
                : 'GPTBot і ClaudeBot збирають дані для навчання моделей; блокувати їх - легітимне рішення, на показ в AI-пошуку це напряму не впливає. Але переконайтеся, що те саме правило не зачіпає пошукових ботів (OAI-SearchBot, Claude-SearchBot, PerplexityBot).';
            $this->F[] = $this->finding($blockedRet ? 'waf_blocks_ai' : 'waf_blocks_training', $sev, $text, [
                'evidence' => implode("\n", $ev),
                'why'      => $why . $spoofWhy,
                'fix'      => $blockedRet
                    ? 'Знайдіть правило, що блокує AI-ботів (WAF/CDN, плагін безпеки CMS, конфіг nginx/Apache, налаштування хостингу), і дозвольте верифікованих пошукових та AI-пошукових ботів. Блок навчальних ботів (GPTBot, ClaudeBot) за бажанням можна залишити.'
                    : 'Якщо блок свідомий - залиште. Якщо ні - вимкніть правило для GPTBot/ClaudeBot у WAF або конфігу сервера.',
                'bad'      => implode("\n", $bad),
                'good'     => 'GET / [' . implode(', ', $blockedRet ?: $blockedTr) . '] -> HTTP 200, повний HTML (як для ' . $cmpName . ')',
                'code'     => $code,
                'verify'   => $verify,
                'effort'   => 'S',
            ]);
            return $score;
        }

        if ($differs) {
            $ev = [$method, $baselineText];
            if ($controlText !== '') $ev[] = $controlText;
            foreach (self::groupProbes($probes, $differs) as $g) $ev[] = implode(', ', $g[0]) . ': ' . $g[1];
            if ($errLine !== '') $ev[] = $errLine;
            if ($ok) $ev[] = 'Без відмінностей: ' . implode(', ', $ok) . '.';
            if ($cdnLine !== '') $ev[] = $cdnLine;
            $bad = [$cmpLine];
            foreach (self::groupProbes($probes, $differs) as $g) $bad[] = 'GET / [' . implode(', ', $g[0]) . '] -> ' . $g[1];
            $this->F[] = $this->finding('waf_ai_different', 'medium', 'Ймовірно, AI-боти отримують іншу відповідь, ніж звичайні відвідувачі', [
                'evidence' => implode("\n", $ev),
                'why'      => 'Якщо AI-боту віддається урізана сторінка, редирект на інший домен або немає відповіді, AI-пошук бачить не той вміст, що користувачі, і не може його процитувати.' . $spoofWhy,
                'fix'      => 'Перевірте правила WAF/CDN і серверні редиректи для цих User-Agent: боти мають отримувати той самий HTML, що й браузер.',
                'bad'      => implode("\n", $bad),
                'good'     => 'GET / [OAI-SearchBot] -> HTTP 200, ' . self::kb(min($baseBytes, 65536)) . '+ HTML (як для ' . $cmpName . ')',
                'code'     => $code,
                'verify'   => $verify,
                'effort'   => 'S',
            ]);
            return $score;
        }

        if ($limited) {
            $ev = [$baselineText];
            if ($controlText !== '') $ev[] = $controlText;
            foreach (self::groupProbes($probes, $limited) as $g) $ev[] = implode(', ', $g[0]) . ': ' . $g[1];
            $ev[] = 'Можливо, ліміт спрацював через серію наших запитів; якщо він діє для AI-ботів постійно, вони скануватимуть сайт неповно.';
            $bad = [$cmpLine];
            foreach (self::groupProbes($probes, $limited) as $g) $bad[] = 'GET / [' . implode(', ', $g[0]) . '] -> ' . $g[1];
            $this->F[] = $this->finding('waf_rate_limit', 'low', 'Сервер обмежує частоту запитів (429) для AI-ботів', [
                'evidence' => implode("\n", $ev),
                'why'      => 'Відповідь 429 боти сприймають як сигнал зменшити швидкість. Надто жорсткий ліміт означає, що нові сторінки довше не потрапляють в індекси AI-пошуку.',
                'fix'      => 'Перевірте налаштування rate limiting для верифікованих ботів; для пошукових і AI-пошукових ботів ліміт має бути м\'якшим, ніж для анонімних клієнтів.',
                'bad'      => implode("\n", $bad),
                'good'     => 'GET / [OAI-SearchBot] -> HTTP 200',
                'code'     => $code,
                'verify'   => $verify,
                'effort'   => 'S',
            ]);
        }
        return $score;
    }

    /**
     * Паралельні GET (curl_multi): [key => ['url', 'ua', 'headers'?]] -> [key => результат].
     * Тіло обрізається після 64 КБ. Результат: status, bytes, final_url, error, challenge, ms, timeout.
     */
    private static function multiGet(array $reqs, int $timeout): array
    {
        if (!$reqs) return [];
        $mh = curl_multi_init();
        $hs = [];
        $st = [];
        foreach ($reqs as $k => $rq) {
            $st[$k] = ['body' => '', 'trunc' => false, 'result' => null];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => (string)$rq['url'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => (string)$rq['ua'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => (array)($rq['headers'] ?? self::BOT_HEADERS),
                CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$st, $k) {
                    $st[$k]['body'] .= $chunk;
                    if (strlen($st[$k]['body']) >= 65536) { $st[$k]['trunc'] = true; return 0; }
                    return strlen($chunk);
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        do {
            $mrc = curl_multi_exec($mh, $running);
            while ($inf = curl_multi_info_read($mh)) {
                foreach ($hs as $k => $h) {
                    if ($h === $inf['handle']) { $st[$k]['result'] = (int)$inf['result']; break; }
                }
            }
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running && $mrc === CURLM_OK);

        $out = [];
        foreach ($hs as $k => $ch) {
            $info = curl_getinfo($ch);
            $code = $st[$k]['result'] ?? curl_errno($ch);
            $err = '';
            if ($code !== 0 && !($code === 23 && $st[$k]['trunc'])) {
                $err = curl_error($ch);
                if ($err === '') $err = (string)curl_strerror((int)$code);
            }
            $body = $st[$k]['body'];
            $status = (int)($info['http_code'] ?? 0);
            $out[$k] = [
                'status'    => $status,
                'bytes'     => strlen($body),
                'final_url' => (string)($info['url'] ?? $reqs[$k]['url']),
                'error'     => $err,
                'timeout'   => $code === 28,
                'challenge' => $body !== '' ? Page::challengeFromHtml($body) : null,
                'ms'        => (int)round(((float)($info['total_time'] ?? 0)) * 1000),
            ];
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private static function probeRec(array $r, string $cls): array
    {
        return [
            'status' => (int)$r['status'], 'cls' => $cls, 'bytes' => (int)$r['bytes'], 'challenge' => $r['challenge'],
            'error' => self::shortErr((string)$r['error']), 'timeout' => (bool)$r['timeout'], 'ms' => (int)$r['ms'],
        ];
    }

    /** Проби з однаковим результатом - одним рядком: [[names], text]. */
    private static function groupProbes(array $probes, array $names): array
    {
        $by = [];
        foreach (array_values(array_unique($names)) as $n) {
            if (!isset($probes[$n])) continue;
            $by[self::probeText($probes[$n])][] = $n;
        }
        $out = [];
        foreach ($by as $txt => $ns) $out[] = [$ns, (string)$txt];
        return $out;
    }

    /** Помилки curl (без дублів) - один рядок доказів. */
    private static function errLine(array $probes): string
    {
        $errs = [];
        foreach ($probes as $p) {
            if ((int)($p['status'] ?? 0) === 0 && !empty($p['error']) && $p['error'] !== 'таймаут') $errs[(string)$p['error']] = true;
        }
        return $errs ? 'Помилка curl: ' . implode('; ', array_keys($errs)) . '.' : '';
    }

    /** Коротка назва помилки curl замість довгого англійського рядка. */
    private static function shortErr(string $e): string
    {
        $e = trim($e);
        if ($e === '') return '';
        if (stripos($e, 'PROTOCOL_ERROR') !== false) return 'HTTP/2 PROTOCOL_ERROR';
        if (stripos($e, 'Empty reply') !== false) return 'Empty reply from server';
        if (stripos($e, 'reset by peer') !== false || stripos($e, 'Recv failure') !== false) return 'Connection reset by peer';
        if (stripos($e, 'timed out') !== false || stripos($e, 'Timeout was reached') !== false) return 'таймаут';
        if (stripos($e, 'refused') !== false) return 'Connection refused';
        if (stripos($e, 'SSL') !== false || stripos($e, 'TLS') !== false) return 'помилка TLS';
        return mb_substr($e, 0, 60);
    }

    // ================================================================== 3. директиви індексації

    private function directivesSection(array $ctx): ?int
    {
        $page = $ctx['page'] ?? null;
        if (!$page instanceof Page) {
            $this->D['directives'] = ['skipped' => 'головна недоступна для аудитора'];
            return null;
        }
        $pages = [[
            'role' => 'home', 'url' => (string)($ctx['url'] ?? $page->url()), 'page' => $page,
            'headers' => (array)(($ctx['homepage']['headers'] ?? null) ?: $page->headers()),
        ]];
        $unavail = [];
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            if (!is_array($s)) continue;
            if (($s['page'] ?? null) instanceof Page) {
                $pages[] = ['role' => (string)($s['role'] ?? 'other'), 'url' => (string)$s['url'], 'page' => $s['page'], 'headers' => (array)($s['res']['headers'] ?? [])];
            } else {
                $unavail[] = self::roleLabel([(string)($s['role'] ?? 'other')]) . ' ' . (string)($s['url'] ?? '') . ' (' . (!empty($s['challenge']) ? self::chLabel((string)$s['challenge']) : ((int)($s['res']['status'] ?? 0) === 0 ? 'немає відповіді' : 'HTTP ' . (int)$s['res']['status'])) . ')';
            }
        }

        $score = 100;
        $noindex = []; $nosnippet = []; $nosnippetBing = []; $smallSnippet = []; $nofollowHome = null; $noai = []; $imgNone = []; $unavailAfter = []; $bulk = [];
        $noarchive = []; $nocache = [];
        $dnsTotal = 0; $homeUnlimited = false; $homeImgLarge = false;
        $dataPages = [];
        foreach ($pages as $pg) {
            $d = self::pageDirectives($pg['page'], $pg['headers']);
            $isHome = $pg['role'] === 'home';
            $label = self::roleLabel([$pg['role']]) . ' ' . $pg['url'];
            $dataPages[] = ['role' => $pg['role'], 'url' => $pg['url'], 'directives' => array_values(array_unique(array_map(function ($i) { return ($i['bot'] !== 'all' ? $i['bot'] . ':' : '') . $i['d']; }, $d['items'])))];
            if ($d['noindex']) $noindex[] = ['label' => $label, 'role' => $pg['role'], 'items' => $d['noindex'], 'home' => $isHome];
            if ($d['nosnippet']) $nosnippet[] = ['label' => $label, 'items' => $d['nosnippet'], 'home' => $isHome];
            if ($d['nosnippet_bing']) $nosnippetBing[] = ['label' => $label, 'items' => $d['nosnippet_bing'], 'home' => $isHome];
            if ($d['noarchive']) $noarchive[] = ['label' => $label, 'role' => $pg['role'], 'items' => $d['noarchive'], 'home' => $isHome];
            if ($d['nocache']) $nocache[] = ['label' => $label, 'items' => $d['nocache'], 'home' => $isHome];
            if ($d['maxsnippet_small']) $smallSnippet[] = ['label' => $label, 'items' => $d['maxsnippet_small']];
            if ($isHome && $d['nofollow'] && !$d['noindex']) $nofollowHome = ['label' => $label, 'items' => $d['nofollow']];
            if ($d['noai']) $noai[] = ['label' => $label, 'items' => $d['noai']];
            if ($d['imgnone']) $imgNone[] = ['label' => $label, 'items' => $d['imgnone']];
            if ($d['unavailable_after']) $unavailAfter[] = ['label' => $label, 'items' => $d['unavailable_after'], 'past' => $d['unavailable_past']];
            if ($d['other_noindex']) $this->D['directives_other_bots'][] = ['url' => $pg['url'], 'items' => array_map(function ($i) { return $i['raw']; }, $d['other_noindex'])];
            if ($isHome) { $homeUnlimited = $d['unlimited']; $homeImgLarge = $d['imglarge']; }
            $dnsTotal += $d['dns_count'];
            if ($d['dns_count'] > 0 && $d['dns_words'] >= 100 && $d['words'] > 0 && $d['dns_words'] >= 0.4 * $d['words']) {
                $bulk[] = ['label' => $label, 'dns' => $d['dns_words'], 'words' => $d['words'], 'raw' => $d['dns_sample']];
            }
        }
        $this->D['directives'] = ['pages' => $dataPages, 'unavailable' => $unavail];
        $nSamples = count($pages) - 1;
        $unavailNote = $unavail ? ' Не вдалося завантажити для перевірки: ' . self::listSome($unavail, 3) . '.' : '';

        $metaGood = '<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">';
        $removeCode = "<!-- 1) У <head> сторінки: замінити meta robots на -->\n" . $metaGood . "\n\n"
            . "# 2) Якщо директива приходить заголовком X-Robots-Tag - знайдіть, де він додається:\n"
            . "grep -Rni \"X-Robots-Tag\" /etc/nginx/ /etc/apache2/ 2>/dev/null\n"
            . "#    nginx: видаліть рядок add_header X-Robots-Tag \"noindex ...\";\n"
            . "#    Apache (.htaccess): видаліть Header set X-Robots-Tag \"noindex\" або додайте:\n"
            . "Header unset X-Robots-Tag";

        // ---- noindex
        $homeNoindex = array_values(array_filter($noindex, function ($n) { return $n['home']; }));
        if ($homeNoindex) {
            $n = $homeNoindex[0];
            // noindex лише для bingbot/msnbot: Google не зачіпає - high, а не critical
            $bingOnly = !array_filter($n['items'], function ($i) { return in_array($i['bot'], ['all', 'googlebot'], true); });
            $score = $bingOnly ? 30 : 0;
            $this->F[] = $this->finding('noindex_home', $bingOnly ? 'high' : 'critical', 'Головна закрита від індексації' . ($bingOnly ? ' в Bing' : '') . ' (' . self::itemsList($n['items']) . ')', [
                'evidence' => self::ucFirst($n['label']) . ': ' . self::itemsEvidence($n['items']) . '.',
                'why'      => $bingOnly
                    ? 'noindex для bingbot прибирає сторінку з індексу Bing. З цього індексу беруть відповіді Copilot, тож там головна не може бути ні показана, ні процитована. Сторонніх пошукових провайдерів використовує й ChatGPT search, тож видимість може впасти і там, хоча OpenAI має власний краулер OAI-SearchBot. На Google ця директива не впливає.'
                    : 'noindex прибирає сторінку з індексу. Google AI Overviews і AI Mode, Copilot і ChatGPT search (через індекс Bing) беруть відповіді з пошукових індексів, тож сторінка з noindex не може бути ні показана, ні процитована.',
                'fix'      => 'Приберіть noindex з meta robots і заголовка X-Robots-Tag. Перевірте налаштування CMS (у WordPress: Налаштування -> Читання -> «Попросити пошукові системи не індексувати сайт»), SEO-плагін і конфіг сервера.',
                'bad'      => self::itemsBad($n['items']),
                'good'     => $metaGood,
                'code'     => $removeCode,
                'verify'   => 'Google Search Console -> URL Inspection головної -> «Індексування дозволено: так» і «Запит на індексування». curl -sI ' . $pages[0]['url'] . ' | grep -i x-robots-tag - заголовка з noindex бути не повинно.',
                'effort'   => 'S',
            ]);
        }
        $sampleNoindex = array_values(array_filter($noindex, function ($n) { return !$n['home']; }));
        if ($sampleNoindex) {
            $sev = 'medium';
            $lines = []; $bad = [];
            foreach ($sampleNoindex as $n) {
                if (isset(self::KEY_ROLES[$n['role']])) { $sev = 'high'; $score -= 30; } else { $score -= 15; }
                $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']);
                $bad[] = self::itemsBad($n['items']);
            }
            $this->F[] = $this->finding('noindex_pages', $sev, 'Внутрішні сторінки закриті від індексації: ' . count($sampleNoindex) . ' з ' . $nSamples . ' перевірених', [
                'evidence' => implode("\n", $lines) . "\nЯкщо сторінку закрито свідомо (кабінет, дякувальна сторінка) - для неї це зауваження можна ігнорувати.",
                'why'      => 'Сторінки з noindex випадають з індексів Google і Bing, тож AI Overviews, AI Mode і Copilot не можуть процитувати їхній вміст: статті, товари, послуги чи інформацію про компанію.',
                'fix'      => 'Приберіть noindex зі змістовних сторінок (meta robots / X-Robots-Tag); перевірте шаблон цього типу сторінок у CMS і SEO-плагіні.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => $metaGood,
                'code'     => $removeCode,
                'verify'   => 'Google Search Console -> URL Inspection для кожної сторінки зі списку -> «Індексування дозволено: так».',
                'effort'   => 'S',
            ]);
        }

        // ---- nosnippet / max-snippet:0
        if ($nosnippet) {
            $score -= array_filter($nosnippet, function ($n) { return $n['home']; }) ? 40 : 25;
            $lines = []; $bad = [];
            $forAll = false;
            foreach ($nosnippet as $n) {
                $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']);
                $bad[] = self::itemsBad($n['items']);
                foreach ($n['items'] as $i) if ($i['bot'] === 'all') $forAll = true;
            }
            $this->F[] = $this->finding('nosnippet', 'high', 'Заборона сніпетів (nosnippet / max-snippet:0) не дає Google цитувати текст в AI Overviews', [
                'evidence' => implode("\n", $lines),
                'why'      => 'За документацією Google, nosnippet і max-snippet:0 забороняють використовувати текст сторінки в сніпетах, AI Overviews і AI Mode. Сторінка лишається в індексі, але її вміст не цитується, і в AI-відповідях перемагають конкуренти.' . ($forAll ? ' Bing теж виконує ці директиви з meta robots і не показує текстовий фрагмент сторінки.' : ''),
                'fix'      => 'Приберіть nosnippet і max-snippet:0. Якщо треба приховати окремий фрагмент (ціни партнерів, службовий текст) - обгорніть лише його в <div data-nosnippet>.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => $metaGood . "\n<div data-nosnippet>лише той фрагмент, який не можна цитувати</div>",
                'code'     => $metaGood,
                'verify'   => 'Перегляньте код сторінки (Ctrl+U) і заголовки: curl -sI ' . $pages[0]['url'] . ' | grep -i x-robots-tag. Google Search Console -> URL Inspection -> «Переглянути проскановану сторінку».',
                'effort'   => 'S',
            ]);
        }
        if ($nosnippetBing) {
            $score -= array_filter($nosnippetBing, function ($n) { return $n['home']; }) ? 20 : 10;
            $lines = []; $bad = [];
            foreach ($nosnippetBing as $n) { $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']); $bad[] = self::itemsBad($n['items']); }
            $this->F[] = $this->finding('nosnippet_bing', 'medium', 'Заборона сніпетів для Bing: Bing не показуватиме фрагмент тексту сторінки, а цитування в Copilot може погіршитися', [
                'evidence' => implode("\n", $lines) . "\nДиректива адресована лише Bing, на Google вона не впливає.",
                'why'      => 'За документацією Bing, nosnippet (і max-snippet:0) забороняє показувати текстовий фрагмент сторінки. Індекс Bing живить Copilot і використовується в ChatGPT search, тож у цих відповідях сторінка може бути представлена гірше або без цитати.',
                'fix'      => 'Приберіть nosnippet / max-snippet:0 з <meta name="bingbot"> (або X-Robots-Tag: bingbot). Якщо треба приховати окремий фрагмент - обгорніть лише його в <div data-nosnippet>.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => '<meta name="bingbot" content="index, follow, max-snippet:-1">',
                'code'     => '<meta name="bingbot" content="index, follow, max-snippet:-1">',
                'verify'   => 'Код сторінки (Ctrl+U) і curl -sI ' . $pages[0]['url'] . ' | grep -i x-robots-tag. Bing Webmaster Tools -> URL Inspection.',
                'effort'   => 'S',
            ]);
        }
        if ($noarchive) {
            $keyHit = (bool)array_filter($noarchive, function ($n) { return $n['home'] || isset(self::KEY_ROLES[$n['role']]); });
            $score -= $keyHit ? 10 : 5;
            $lines = []; $bad = []; $hdrVals = []; $inMeta = false;
            foreach ($noarchive as $n) {
                $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']);
                $bad[] = self::itemsBad($n['items']);
                foreach ($n['items'] as $i) {
                    if ($i['src'] === 'X-Robots-Tag') $hdrVals[trim(substr($i['raw'], strlen('X-Robots-Tag:')))] = true;
                    else $inMeta = true;
                }
            }
            $naGood = $inMeta ? $metaGood : '';
            $naCode = $inMeta ? "<!-- У <head>: meta robots без noarchive -->\n" . $metaGood . "\n" : '';
            if ($hdrVals) {
                $hv = array_keys($hdrVals);
                $naGood = trim($naGood . "\n" . 'Відповідь сервера без noarchive у X-Robots-Tag (або без цього заголовка зовсім)');
                $naCode .= ($naCode !== '' ? "\n" : '') . "# Заголовок зараз: X-Robots-Tag: " . $hv[0] . "\n"
                    . "# Знайдіть, де він додається (сервер, CMS або CDN):\n"
                    . "grep -Rni \"X-Robots-Tag\" /etc/nginx/ /etc/apache2/ 2>/dev/null\n"
                    . "#   nginx: видаліть рядок add_header X-Robots-Tag \"" . $hv[0] . "\";\n"
                    . "#   Apache (.htaccess):\n"
                    . "Header unset X-Robots-Tag\n"
                    . "#   CDN: перевірте правила, що змінюють заголовки відповіді";
            }
            $this->F[] = $this->finding('noarchive', $keyHit ? 'medium' : 'low', 'noarchive: за заявою Bing, такі сторінки не потрапляють у відповіді Copilot', [
                'evidence' => implode("\n", array_slice($lines, 0, 6)),
                'why'      => 'У вересні 2023 Bing оголосив: вміст із NOARCHIVE не включається у відповіді Bing Chat (тепер Copilot) і не отримує в них посилань. Google noarchive більше не використовує (кешовані копії прибрано), тож на AI Overviews ця директива не впливає.',
                'fix'      => 'Якщо сайт хоче цитувань у Copilot, приберіть noarchive з meta robots / X-Robots-Tag або замініть на nocache: тоді Microsoft братиме для навчання моделей лише URL, заголовок і сніпет. Для інших моделей навчання забороняють у robots.txt (GPTBot, ClaudeBot, Google-Extended).',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => $naGood,
                'code'     => trim($naCode),
                'verify'   => 'Код сторінки (Ctrl+U) і curl -sI ' . $pages[0]['url'] . ' | grep -i x-robots-tag - noarchive бути не повинно. Bing Webmaster Tools -> URL Inspection.',
                'effort'   => 'S',
            ]);
        }
        if ($nocache) {
            $score -= 3;
            $lines = []; $bad = [];
            foreach ($nocache as $n) { $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']); $bad[] = self::itemsBad($n['items']); }
            $this->F[] = $this->finding('nocache', 'low', 'nocache: Copilot показуватиме лише URL, заголовок і сніпет сторінки', [
                'evidence' => implode("\n", array_slice($lines, 0, 6)),
                'why'      => 'За заявою Bing (вересень 2023), вміст із NOCACHE може потрапляти у відповіді Bing Chat (тепер Copilot), але з нього показуються лише URL, заголовок і сніпет - без розгорнутих цитат. Google цю директиву не використовує.',
                'fix'      => 'Якщо обмеження не свідоме - приберіть nocache з meta robots / X-Robots-Tag.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => $metaGood,
                'code'     => '',
                'verify'   => 'Код сторінки (Ctrl+U) і curl -sI ' . $pages[0]['url'] . ' | grep -i x-robots-tag.',
                'effort'   => 'S',
            ]);
        }
        if ($smallSnippet) {
            $score -= 15;
            $lines = []; $bad = [];
            foreach ($smallSnippet as $n) { $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']); $bad[] = self::itemsBad($n['items']); }
            $this->F[] = $this->finding('max_snippet_small', 'medium', 'Обмеження max-snippet занадто мале для змістовних цитат', [
                'evidence' => implode("\n", $lines),
                'why'      => 'Google і Bing обрізають текстові фрагменти сторінки до вказаної кількості символів, а Google так само обмежує текст для AI Overviews і AI Mode (для директиви з name="googlebot" чи "bingbot" - лише в цьому пошуковику). Короткий ліміт заважає взяти з сайту повну відповідь чи визначення.',
                'fix'      => 'Встановіть max-snippet:-1 (без обмежень) або приберіть директиву.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => $metaGood,
                'code'     => $metaGood,
                'verify'   => 'Код сторінки (Ctrl+U): у meta robots має бути max-snippet:-1 або директиви немає.',
                'effort'   => 'S',
            ]);
        }
        if ($nofollowHome) {
            $score -= 15;
            $this->F[] = $this->finding('nofollow_home', 'medium', 'На головній стоїть nofollow - боти не переходять за посиланнями з неї', [
                'evidence' => $nofollowHome['label'] . ': ' . self::itemsEvidence($nofollowHome['items']) . '.',
                'why'      => 'Головна - основна точка входу для ботів. nofollow просить не переходити за її посиланнями, тож внутрішні сторінки знаходяться гірше і повільніше потрапляють в індекси.',
                'fix'      => 'Приберіть nofollow з meta robots / X-Robots-Tag головної.',
                'bad'      => self::itemsBad($nofollowHome['items']),
                'good'     => $metaGood,
                'code'     => $metaGood,
                'verify'   => 'Google Search Console -> URL Inspection головної; код сторінки (Ctrl+U).',
                'effort'   => 'S',
            ]);
        }
        if ($unavailAfter) {
            $past = array_values(array_filter($unavailAfter, function ($n) { return $n['past']; }));
            if ($past) $score -= 30;
            $lines = []; $bad = [];
            foreach ($unavailAfter as $n) { $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']) . ($n['past'] ? ' - дата вже минула' : ''); $bad[] = self::itemsBad($n['items']); }
            $this->F[] = $this->finding('unavailable_after', $past ? 'high' : 'low', $past ? 'Директива unavailable_after з минулою датою - Google прибирає сторінку з пошуку' : 'Директива unavailable_after: сторінка зникне з Google після вказаної дати', [
                'evidence' => implode("\n", $lines),
                'why'      => 'Після дати з unavailable_after Google перестає показувати сторінку, а отже і використовувати її в AI Overviews.',
                'fix'      => 'Приберіть unavailable_after, якщо сторінка має лишатися в пошуку.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => $metaGood,
                'code'     => '',
                'verify'   => 'Google Search Console -> URL Inspection -> «Індексування дозволено».',
                'effort'   => 'S',
            ]);
        }
        if ($bulk) {
            $score -= 15;
            $lines = [];
            foreach ($bulk as $b) $lines[] = $b['label'] . ': у data-nosnippet ' . $b['dns'] . ' з ' . $b['words'] . ' слів видимого тексту.';
            $this->F[] = $this->finding('nosnippet_bulk', 'medium', 'data-nosnippet приховує від сніпетів більшу частину тексту сторінки', [
                'evidence' => implode("\n", $lines),
                'why'      => 'Текст усередині data-nosnippet Google не використовує в сніпетах і AI Overviews. Якщо так позначено основний вміст, сторінку фактично неможливо процитувати.',
                'fix'      => 'Залиште data-nosnippet лише на дрібних фрагментах (службові написи, ціни партнерів), а не на контейнері з основним текстом.',
                'bad'      => $bulk[0]['raw'],
                'good'     => "<main>\n  <p>Основний текст доступний для цитування.</p>\n  <span data-nosnippet>лише службовий фрагмент</span>\n</main>",
                'code'     => '',
                'verify'   => 'Код сторінки (Ctrl+U): data-nosnippet не повинен обгортати <main>, <article> чи весь <body>.',
                'effort'   => 'S',
            ]);
        }
        if ($imgNone) {
            $score -= 5;
            $lines = []; $bad = [];
            foreach ($imgNone as $n) { $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']); $bad[] = self::itemsBad($n['items']); }
            $this->F[] = $this->finding('image_preview_none', 'low', 'max-image-preview:none забороняє показ прев\'ю зображень', [
                'evidence' => implode("\n", $lines),
                'why'      => 'Google не показуватиме прев\'ю зображень сайту в результатах пошуку і Discover, що знижує помітність посилання.',
                'fix'      => 'Замініть на max-image-preview:large.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => $metaGood,
                'code'     => $metaGood,
                'verify'   => 'Код сторінки (Ctrl+U).',
                'effort'   => 'S',
            ]);
        }
        if ($noai) {
            $lines = []; $bad = [];
            foreach ($noai as $n) { $lines[] = $n['label'] . ': ' . self::itemsEvidence($n['items']); $bad[] = self::itemsBad($n['items']); }
            $this->F[] = $this->finding('noai_meta', 'low', 'Нестандартні директиви noai / noimageai нічого не блокують', [
                'evidence' => implode("\n", $lines),
                'why'      => 'noai і noimageai не входять до стандарту і не описані в документації Google, Bing, OpenAI, Anthropic і Perplexity. Вони не захищають контент від навчання і не впливають на видимість, але створюють хибне відчуття контролю.',
                'fix'      => 'Якщо мета - заборонити навчання моделей, робіть це в robots.txt (GPTBot, ClaudeBot, Google-Extended, CCBot). Якщо ні - приберіть ці директиви.',
                'bad'      => implode("\n", array_unique($bad)),
                'good'     => "# robots.txt (лише якщо навчання справді треба заборонити)\nUser-agent: GPTBot\nUser-agent: ClaudeBot\nUser-agent: Google-Extended\nUser-agent: CCBot\nDisallow: /",
                'code'     => '',
                'verify'   => 'Код сторінки (Ctrl+U).',
                'effort'   => 'S',
            ]);
        }

        // ---- passed
        if (!$noindex && !$nosnippet && !$nosnippetBing && !$smallSnippet) {
            $this->P[] = 'Головна' . ($nSamples ? ' та ' . $nSamples . ' ' . self::plural($nSamples, 'внутрішня сторінка', 'внутрішні сторінки', 'внутрішніх сторінок') : '') . ' без noindex, nosnippet чи max-snippet:0 (meta robots і X-Robots-Tag)' . ($unavail ? '; ' . count($unavail) . ' ' . self::plural(count($unavail), 'сторінку', 'сторінки', 'сторінок') . ' не вдалося завантажити для перевірки' : '');
        } elseif (!$homeNoindex) {
            $this->P[] = 'Головна відкрита для індексації (немає noindex у meta robots і X-Robots-Tag)';
        }
        if ($homeUnlimited) $this->P[] = 'Головна явно дозволяє довгі цитати: max-snippet:-1';
        if ($homeImgLarge) $this->P[] = 'max-image-preview:large дозволяє великі прев\'ю зображень у видачі';
        if ($dnsTotal > 0 && !$bulk) $this->P[] = 'data-nosnippet використано точково (' . $dnsTotal . ' ' . self::plural($dnsTotal, 'елемент', 'елементи', 'елементів') . ') - основний текст доступний для цитування';
        if ($unavailNote !== '') $this->D['directives']['note'] = trim($unavailNote);

        return max(0, min(100, $score));
    }

    /** Директиви сторінки з meta (robots/googlebot/bingbot/msnbot) і X-Robots-Tag. */
    private static function pageDirectives(Page $p, array $headers): array
    {
        $items = [];
        foreach ($p->query('//meta[@name]') as $m) {
            $name = strtolower(trim((string)$m->getAttribute('name')));
            if (!in_array($name, ['robots', 'googlebot', 'bingbot', 'msnbot'], true)) continue;
            $content = (string)$m->getAttribute('content');
            $raw = '<meta name="' . $m->getAttribute('name') . '" content="' . $content . '">';
            $bot = $name === 'robots' ? 'all' : $name;
            foreach (self::splitDirectives($content) as $d) $items[] = ['src' => 'meta ' . $name, 'bot' => $bot, 'd' => $d, 'raw' => $raw];
        }
        foreach ((array)($headers['x-robots-tag'] ?? []) as $hv) {
            $hv = trim((string)$hv);
            if ($hv === '') continue;
            $bot = 'all';
            $rest = $hv;
            if (preg_match('/^([a-z0-9_\-]+)\s*:\s*(.+)$/i', $hv, $mm) && !in_array(strtolower($mm[1]), self::DIRECTIVE_KEYS, true)) {
                $bot = strtolower($mm[1]);
                $rest = $mm[2];
            }
            foreach (self::splitDirectives($rest) as $d) $items[] = ['src' => 'X-Robots-Tag', 'bot' => $bot, 'd' => $d, 'raw' => 'X-Robots-Tag: ' . $hv];
        }

        $out = [
            'items' => $items, 'noindex' => [], 'nosnippet' => [], 'nosnippet_bing' => [], 'maxsnippet_small' => [], 'nofollow' => [], 'noai' => [],
            'imgnone' => [], 'unavailable_after' => [], 'unavailable_past' => false, 'other_noindex' => [], 'unlimited' => false, 'imglarge' => false,
            'noarchive' => [], 'nocache' => [],
        ];
        $mainBots = ['all' => 1, 'googlebot' => 1, 'bingbot' => 1, 'msnbot' => 1];
        foreach ($items as $it) {
            $d = $it['d'];
            $main = isset($mainBots[$it['bot']]);
            if ($d === 'noindex' || $d === 'none') {
                if ($main) $out['noindex'][] = $it; else $out['other_noindex'][] = $it;
            }
            if (!$main) continue;
            if ($d === 'nofollow' || $d === 'none') $out['nofollow'][] = $it;
            // nosnippet для bingbot/msnbot - окремо: на Google не впливає
            $bingOnly = $it['bot'] === 'bingbot' || $it['bot'] === 'msnbot';
            $snipKey = $bingOnly ? 'nosnippet_bing' : 'nosnippet';
            if ($d === 'nosnippet') $out[$snipKey][] = $it;
            // noarchive / nocache: Google ігнорує, Bing обмежує використання в Copilot
            if ($d === 'noarchive' && $it['bot'] !== 'googlebot') $out['noarchive'][] = $it;
            if ($d === 'nocache' && $it['bot'] !== 'googlebot') $out['nocache'][] = $it;
            if (preg_match('/^max-snippet:(-?\d+)$/', $d, $mm)) {
                $n = (int)$mm[1];
                if ($n === 0) $out[$snipKey][] = $it;
                elseif ($n > 0 && $n <= 100) $out['maxsnippet_small'][] = $it;
                elseif ($n === -1 && $it['bot'] !== 'bingbot' && $it['bot'] !== 'msnbot') $out['unlimited'] = true;
            }
            if ($d === 'noai' || $d === 'noimageai') $out['noai'][] = $it;
            if ($d === 'max-image-preview:none') $out['imgnone'][] = $it;
            if ($d === 'max-image-preview:large') $out['imglarge'] = true;
            if (strpos($d, 'unavailable_after:') === 0) {
                $out['unavailable_after'][] = $it;
                $ts = strtotime(trim(substr($d, strlen('unavailable_after:'))));
                if ($ts !== false && $ts < time()) $out['unavailable_past'] = true;
            }
        }

        // Bing: якщо на сторінці стоять і noarchive, і nocache, діє nocache
        if ($out['noarchive'] && $out['nocache']) $out['noarchive'] = [];

        // data-nosnippet
        $all = $p->query('//*[@data-nosnippet]');
        $top = $p->query('//*[@data-nosnippet][not(ancestor::*[@data-nosnippet])]');
        $dnsWords = 0;
        $sample = '';
        foreach ($top as $n) {
            $txt = '';
            foreach ($p->query('.//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template)]', $n) as $t) $txt .= ' ' . $t->nodeValue;
            $w = $p->wordCount(trim((string)preg_replace('/\s+/u', ' ', $txt)));
            if ($w > $dnsWords || $sample === '') {
                $attrs = '';
                if ($n instanceof DOMElement) {
                    foreach (['id', 'class'] as $a) if ($n->hasAttribute($a)) $attrs .= ' ' . $a . '="' . mb_substr($n->getAttribute($a), 0, 60) . '"';
                }
                $sample = '<' . $n->nodeName . $attrs . ' data-nosnippet> ' . mb_substr(trim((string)preg_replace('/\s+/u', ' ', $txt)), 0, 200) . ' ...';
            }
            $dnsWords += $w;
        }
        $out['dns_count'] = count($all);
        $out['dns_words'] = $dnsWords;
        $out['dns_sample'] = $sample;
        $out['words'] = $p->wordCount();
        return $out;
    }

    private static function splitDirectives(string $s): array
    {
        $out = [];
        if (preg_match('/unavailable_after\s*:\s*(.+)$/i', $s, $m, PREG_OFFSET_CAPTURE)) {
            $out[] = 'unavailable_after:' . trim($m[1][0]);
            $s = substr($s, 0, (int)$m[0][1]);
        }
        foreach (explode(',', $s) as $d) {
            $d = strtolower((string)preg_replace('/\s+/', '', $d));
            if ($d !== '') $out[] = $d;
        }
        return $out;
    }

    // ================================================================== robots.txt: парсер і матчер

    /**
     * Розбір robots.txt за правилами Google: групи (кілька User-agent поспіль), правила Allow/Disallow,
     * Sitemap, Crawl-delay. Невідомі рядки не розривають групу.
     */
    public static function parseRobots(string $body): array
    {
        $truncated = false;
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) $body = substr($body, 3);
        if (strlen($body) > self::ROBOTS_MAX) { $body = substr($body, 0, self::ROBOTS_MAX); $truncated = true; }
        $raw = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $lines = [];
        $groups = [];
        $sitemaps = [];
        $issues = ['orphan' => [], 'nocolon' => [], 'unknown' => [], 'conflict' => []];
        $cur = -1;
        $inAgents = false;
        foreach ($raw as $i => $line) {
            $ln = $i + 1;
            $lines[$ln] = rtrim((string)$line);
            $s = (string)$line;
            $hash = strpos($s, '#');
            if ($hash !== false) $s = substr($s, 0, $hash);
            $s = trim($s);
            if ($s === '') continue;
            $colon = strpos($s, ':');
            if ($colon === false) {
                if (preg_match('/^(\S+)\s+(\S+)$/', $s, $m) && in_array(self::normKey($m[1]), ['user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay'], true)) {
                    $key = $m[1];
                    $val = $m[2];
                    $issues['nocolon'][] = $ln;
                } else {
                    continue;
                }
            } else {
                $key = substr($s, 0, $colon);
                $val = trim(substr($s, $colon + 1));
            }
            $k = self::normKey($key);
            if ($k === 'user-agent') {
                if ($cur < 0 || !$inAgents) {
                    $groups[] = ['ua' => [], 'agents' => [], 'rules' => [], 'lines' => [], 'crawl_delay' => null, 'cd_line' => null];
                    $cur = count($groups) - 1;
                }
                $tok = self::uaToken($val);
                $groups[$cur]['ua'][] = [$ln, $tok, $val];
                if ($tok !== '' && !in_array($tok, $groups[$cur]['agents'], true)) $groups[$cur]['agents'][] = $tok;
                $groups[$cur]['lines'][] = $ln;
                $inAgents = true;
            } elseif ($k === 'allow' || $k === 'disallow') {
                if ($cur < 0) { $issues['orphan'][] = $ln; continue; }
                $groups[$cur]['rules'][] = ['type' => $k, 'path' => $val, 'line' => $ln];
                $groups[$cur]['lines'][] = $ln;
                $inAgents = false;
            } elseif ($k === 'sitemap') {
                if ($val !== '') $sitemaps[] = $val;
            } elseif ($k === 'crawl-delay') {
                if ($cur >= 0) {
                    $groups[$cur]['crawl_delay'] = is_numeric($val) ? (float)$val : null;
                    $groups[$cur]['cd_line'] = $ln;
                    $groups[$cur]['lines'][] = $ln;
                }
            } else {
                $issues['unknown'][] = [$ln, mb_substr(trim($key), 0, 40)];
                if ($cur >= 0) $groups[$cur]['lines'][] = $ln;
            }
        }
        foreach ($groups as $gi => $g) {
            $seen = [];
            foreach ($g['rules'] as $r) {
                if ($r['path'] === '') continue;
                $np = self::normPath($r['path']);
                if (!isset($seen[$np])) $seen[$np] = ['raw' => $r['path']];
                $seen[$np][$r['type']] = $r['line'];
            }
            foreach ($seen as $np => $t) {
                if (isset($t['allow'], $t['disallow'])) $issues['conflict'][] = ['group' => $gi, 'raw' => $t['raw'], 'lines' => [$t['allow'], $t['disallow']]];
            }
        }
        return ['groups' => $groups, 'sitemaps' => $sitemaps, 'lines' => $lines, 'issues' => $issues, 'truncated' => $truncated];
    }

    /** Група для бота: точний токен, потім задокументовані резерви, потім '*'. Однойменні групи об'єднуються. */
    public static function groupFor(array $parsed, string $token, array $fallbacks = []): array
    {
        foreach (array_merge([$token], $fallbacks, ['*']) as $t) {
            $tl = strtolower($t);
            $idx = [];
            $rules = [];
            foreach ($parsed['groups'] as $gi => $g) {
                if (in_array($tl, $g['agents'], true)) {
                    $idx[] = $gi;
                    foreach ($g['rules'] as $r) $rules[] = $r;
                }
            }
            if ($idx) return ['matched' => $t, 'groups' => $idx, 'rules' => $rules];
        }
        return ['matched' => null, 'groups' => [], 'rules' => []];
    }

    /** Longest match: найдовше правило, що збіглося, перемагає; за рівної довжини - Allow. */
    public static function evaluate(array $rules, string $path): array
    {
        $np = self::normPath($path === '' ? '/' : $path);
        $best = null;
        $bestLen = -1;
        foreach ($rules as $r) {
            $pat = (string)$r['path'];
            if ($pat === '') continue;
            $npat = self::normPath($pat);
            if (!preg_match(self::ruleRegex($npat), $np)) continue;
            $len = strlen($npat);
            if ($len > $bestLen || ($len === $bestLen && $r['type'] === 'allow' && $best !== null && $best['type'] === 'disallow')) {
                $best = $r;
                $bestLen = $len;
            }
        }
        return ['allowed' => $best === null || $best['type'] === 'allow', 'rule' => $best];
    }

    private static function evalBot(array $parsed, string $token, array $fb, array $paths): array
    {
        $g = self::groupFor($parsed, $token, $fb);
        $res = ['token' => $token, 'matched' => $g['matched'], 'groups' => $g['groups'], 'home_blocked' => false, 'home_rule' => null, 'blocked' => []];
        foreach ($paths as $path => $info) {
            $e = self::evaluate($g['rules'], (string)$path);
            if ($e['allowed']) continue;
            if (in_array('home', $info['roles'], true)) {
                $res['home_blocked'] = true;
                $res['home_rule'] = $e['rule'];
            } else {
                $res['blocked'][(string)$path] = $e['rule'];
            }
        }
        // корінь закрито, а головна - на іншому шляху: показуємо як заблоковану сторінку
        return $res;
    }

    private static function ruleRegex(string $npat): string
    {
        static $cache = [];
        if (isset($cache[$npat])) return $cache[$npat];
        $p = $npat;
        $end = false;
        if (substr($p, -1) === '$') { $end = true; $p = substr($p, 0, -1); }
        $parts = explode('*', $p);
        $rx = implode('.*', array_map(function ($s) { return preg_quote($s, '#'); }, $parts));
        if (count($cache) > 2000) $cache = [];
        return $cache[$npat] = '#^' . $rx . ($end ? '$' : '') . '#s';
    }

    /** Percent-encoding не-ASCII і пробілів, верхній регістр у %xx - щоб кирилиця в правилі й URL збігалася. */
    private static function normPath(string $p): string
    {
        $p = (string)preg_replace_callback('/[^\x21-\x7E]/', function ($m) { return '%' . strtoupper(bin2hex($m[0])); }, $p);
        return (string)preg_replace_callback('/%[0-9a-f]{2}/i', function ($m) { return strtoupper($m[0]); }, $p);
    }

    private static function normKey(string $k): string
    {
        $k = strtolower(trim($k));
        $k = (string)preg_replace('/[\s_]+/', '-', $k);
        $map = [
            'user-agent' => 'user-agent', 'useragent' => 'user-agent', 'allow' => 'allow', 'disallow' => 'disallow',
            'dissallow' => 'disallow', 'dissalow' => 'disallow', 'disalow' => 'disallow', 'diasllow' => 'disallow', 'disallaw' => 'disallow',
            'sitemap' => 'sitemap', 'site-map' => 'sitemap', 'crawl-delay' => 'crawl-delay', 'crawldelay' => 'crawl-delay',
        ];
        return $map[$k] ?? $k;
    }

    /**
     * Токен продукту з рядка User-agent: «Googlebot/2.1» -> googlebot, «*» -> *.
     * Як у google/robotstxt: '*' - лише сама зірочка або зірочка з пробілом після неї; інакше токен -
     * початкові символи [A-Za-z_-]; порожній токен («*bot», «123») не відповідає жодному боту.
     */
    private static function uaToken(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        if ($v[0] === '*' && (strlen($v) === 1 || ctype_space($v[1]))) return '*';
        if (preg_match('/^[A-Za-z_\-]+/', $v, $m)) return strtolower($m[0]);
        return '';
    }

    private static function blocksRoot(array $rule): bool
    {
        return $rule['type'] === 'disallow' && !self::evaluate([$rule], '/')['allowed'];
    }

    // ================================================================== robots.txt: виправлений файл

    /**
     * Будує виправлений robots.txt: прибирає $dropTokens з груп, що їх блокують, коментує рядки $comment
     * (line => URL сторінки), а для ботів, які лишилися заблокованими, додає окрему групу з Allow: /
     * і службовими Disallow із групи '*'. Решта правил і Sitemap зберігаються.
     */
    private function buildFixedRobots(array $parsed, array $tokens, array $dropTokens, array $comment, array $paths, string $groupComment): array
    {
        $dropTok = array_values(array_unique($dropTokens));
        $text = '';
        $drop = [];
        $still = [];
        for ($iter = 0; $iter < 2; $iter++) {
            [$text, $drop] = self::rebuild($parsed, $dropTok, $comment);
            $p2 = self::parseRobots($text);
            $still = self::stillBlocked($p2, $tokens, $paths);
            if (!$still || $iter === 1) break;
            $dropTok = array_values(array_unique(array_merge($dropTok, $still)));
        }
        $added = '';
        if ($still) {
            // якщо на сайті є іменована група-дозвіл (головна й вибірка відкриті, службові розділи закриті) -
            // природніше додати ботів до неї, ніж створювати нову групу
            $p2 = self::parseRobots($text);
            $gi = self::findAllowGroup($p2, $paths);
            if ($gi !== null) {
                $g = $p2['groups'][$gi];
                $lastUa = (int)$g['ua'][count($g['ua']) - 1][0];
                $newLines = [];
                foreach ($p2['lines'] as $ln => $raw) {
                    $newLines[] = $raw;
                    if ($ln === $lastUa) foreach ($still as $t) $newLines[] = 'User-agent: ' . $t;
                }
                $cand = trim(implode("\n", $newLines));
                if (!self::stillBlocked(self::parseRobots($cand), $tokens, $paths)) {
                    $text = $cand;
                    $added = '# Додайте до наявної групи «User-agent: ' . self::listSome(array_map(function ($u) { return $u[2]; }, $g['ua']), 3)
                        . '» (після рядка «' . trim((string)$p2['lines'][$lastUa]) . '»):' . "\n"
                        . implode("\n", array_map(function ($t) { return 'User-agent: ' . $t; }, $still));
                    $still = [];
                }
            }
        }
        if ($still) {
            $p2 = self::parseRobots($text);
            $star = self::groupFor($p2, '*');
            $lines = [];
            foreach ($still as $t) $lines[] = 'User-agent: ' . $t;
            $lines[] = 'Allow: /';
            $copied = 0;
            foreach ($star['rules'] as $r) {
                if ($r['type'] !== 'disallow' || $r['path'] === '' || $copied >= 40) continue;
                $hitsContent = false;
                foreach ($paths as $path => $_) {
                    if (!self::evaluate([$r], (string)$path)['allowed']) { $hitsContent = true; break; }
                }
                if ($hitsContent) continue;
                $lines[] = 'Disallow: ' . $r['path'];
                $copied++;
            }
            array_unshift($lines, $groupComment . ($copied ? '; службові розділи закриті, як для всіх' : ''));
            $added = implode("\n", $lines);
            // група - перед Sitemap
            $sm = [];
            $body = [];
            foreach (explode("\n", $text) as $l) {
                if (preg_match('/^\s*sitemap\s*:/i', $l)) $sm[] = $l; else $body[] = $l;
            }
            $text = rtrim(implode("\n", $body)) . "\n\n" . $added . ($sm ? "\n\n" . implode("\n", $sm) : '');
        }
        $text = trim($text) . "\n";
        $ok = !self::stillBlocked(self::parseRobots($text), $tokens, $paths);
        if (strlen($text) > 5500) {
            // великий файл: замість повного тексту - інструкція зі змінами
            $msg = ["# Ваш robots.txt великий, тому тут лише зміни (решту файлу не чіпайте):"];
            if ($drop) $msg[] = '# 1) Видаліть рядки: ' . self::lineRanges(array_keys($drop));
            if ($comment) $msg[] = '# 2) Видаліть або звузьте правила в рядках: ' . self::lineRanges(array_keys($comment));
            if ($added !== '') {
                if (strpos($added, '# Додайте до наявної групи') === 0) { $msg[] = '# 3)'; $msg[] = $added; }
                else { $msg[] = '# 3) Додайте перед рядками Sitemap:'; $msg[] = ''; $msg[] = $added; }
            }
            $text = implode("\n", $msg) . "\n";
        }
        if (!$ok) {
            $text = "# Автоматично зібрати виправлений файл не вдалося - базовий варіант:\n" . $groupComment . "\n"
                . implode("\n", array_map(function ($t) { return 'User-agent: ' . $t; }, array_keys($tokens))) . "\nAllow: /\n";
        }
        return ['text' => $text, 'drop' => $drop, 'comment' => $comment, 'added_group' => self::shortBlock($added), 'added_full' => $added, 'ok' => $ok];
    }

    /**
     * Іменована група-дозвіл: без '*', з кількома User-agent (або з Googlebot / Bingbot), щонайменше одним
     * службовим Disallow і доступом до всіх перевірених сторінок. Перевага - група з Googlebot, далі найбільша.
     */
    private static function findAllowGroup(array $parsed, array $paths): ?int
    {
        $best = null;
        $bestRank = -1;
        foreach ($parsed['groups'] as $gi => $g) {
            if (!$g['agents'] || in_array('*', $g['agents'], true)) continue;
            $major = (bool)array_intersect($g['agents'], ['googlebot', 'bingbot']);
            if (count($g['agents']) < 2 && !$major) continue;
            $hasDis = false;
            foreach ($g['rules'] as $r) if ($r['type'] === 'disallow' && $r['path'] !== '') { $hasDis = true; break; }
            if (!$hasDis) continue;
            $open = true;
            foreach ($paths as $path => $_) {
                if (!self::evaluate($g['rules'], (string)$path)['allowed']) { $open = false; break; }
            }
            if (!$open) continue;
            $rank = ($major ? 1000 : 0) + count($g['agents']);
            if ($rank > $bestRank) { $bestRank = $rank; $best = $gi; }
        }
        return $best;
    }

    /** Скорочений доданий блок для «Як треба»: до 6 User-agent і 6 правил, решта - коментарем. */
    private static function shortBlock(string $block): string
    {
        if ($block === '') return '';
        $head = []; $ua = []; $rules = [];
        foreach (explode("\n", $block) as $l) {
            if (preg_match('/^\s*user-agent\s*:/i', $l)) $ua[] = $l;
            elseif (preg_match('/^\s*(allow|disallow)\s*:/i', $l)) $rules[] = $l;
            else $head[] = $l;
        }
        $out = $head;
        if (count($ua) > 7) {
            $out = array_merge($out, array_slice($ua, 0, 6));
            $out[] = '# ... ще ' . (count($ua) - 6) . ' User-agent (повний перелік - у виправленому файлі)';
        } else {
            $out = array_merge($out, $ua);
        }
        if (count($rules) > 7) {
            $out = array_merge($out, array_slice($rules, 0, 6));
            $out[] = '# ... ще ' . (count($rules) - 6) . ' ' . self::plural(count($rules) - 6, 'правило', 'правила', 'правил');
        } else {
            $out = array_merge($out, $rules);
        }
        return implode("\n", $out);
    }

    /** Текст robots.txt без UA-рядків $dropTok (і без груп, що стали порожніми), з коментованими рядками. */
    private static function rebuild(array $parsed, array $dropTok, array $comment): array
    {
        $dropLower = array_map('strtolower', $dropTok);
        $drop = [];
        foreach ($parsed['groups'] as $g) {
            $hasDrop = false;
            foreach ($g['ua'] as $ua) {
                if (in_array($ua[1], $dropLower, true)) { $drop[$ua[0]] = true; $hasDrop = true; }
            }
            if (!$hasDrop) continue;
            $all = true;
            foreach ($g['ua'] as $ua) if (empty($drop[$ua[0]])) { $all = false; break; }
            if ($all) foreach ($g['lines'] as $ln) $drop[$ln] = true;
        }
        $out = [];
        $prevBlank = false;
        foreach ($parsed['lines'] as $ln => $raw) {
            if (!empty($drop[$ln])) continue;
            if (isset($comment[$ln])) {
                $out[] = '# ' . trim($raw) . '   # закривало ' . $comment[$ln];
                $prevBlank = false;
                continue;
            }
            $isBlank = trim($raw) === '';
            if ($isBlank && $prevBlank) continue;
            $out[] = $raw;
            $prevBlank = $isBlank;
        }
        return [trim(implode("\n", $out)), $drop];
    }

    private static function stillBlocked(array $parsed, array $tokens, array $paths): array
    {
        $out = [];
        $homePaths = array_filter($paths, function ($info) { return (bool)array_intersect((array)($info['roles'] ?? []), ['root', 'home']); });
        foreach ($tokens as $tok => $spec) {
            $g = self::groupFor($parsed, (string)$tok, (array)($spec['fb'] ?? []));
            foreach ((!empty($spec['home_only']) ? $homePaths : $paths) as $path => $_) {
                if (!self::evaluate($g['rules'], (string)$path)['allowed']) { $out[] = (string)$tok; break; }
            }
        }
        return $out;
    }

    /**
     * Фрагмент robots.txt: UA-рядки груп і вирішальні правила. З $drop/$comment - стан після виправлення.
     * У великих групах показуються лише UA-рядки причетних ботів ($involved, у нижньому регістрі), решта -
     * одним коментарем, щоб рядки правил гарантовано вмістилися в ліміт поля.
     */
    private function renderExcerpt(array $parsed, array $groupRules, ?array $drop = null, ?array $comment = null, array $involved = []): string
    {
        $txt = '';
        foreach ([4, 2, 0] as $maxUa) {
            $txt = $this->renderExcerptN($parsed, $groupRules, $drop, $comment, $involved, $maxUa);
            if (mb_strlen($txt) <= self::MAX_EXAMPLE - 10) break;
        }
        return $txt;
    }

    private function renderExcerptN(array $parsed, array $groupRules, ?array $drop, ?array $comment, array $involved, int $maxUa): string
    {
        ksort($groupRules);
        $out = [];
        foreach ($groupRules as $gi => $ruleLines) {
            $g = $parsed['groups'][$gi] ?? null;
            if (!$g) continue;
            $ruleLines = array_values(array_unique(array_map('intval', $ruleLines)));
            sort($ruleLines);
            $kept = [];
            $dropped = [];
            foreach ($g['ua'] as $ua) {
                if ($drop !== null && !empty($drop[$ua[0]])) { $dropped[] = $ua[2]; continue; }
                $kept[] = $ua;
            }
            if ($drop !== null && !$kept) {
                $out[] = '# група «User-agent: ' . self::listSome($dropped, 4) . '» видалена - ці боти тепер підпадають під загальні правила';
                $out[] = '';
                continue;
            }
            $block = [];
            if (count($kept) <= $maxUa + 1) {
                foreach ($kept as $ua) $block[] = self::rawLine($parsed, $ua[0]);
            } else {
                // спершу причетні боти (у стані «до»), у стані «після» - перші рядки групи
                $show = [];
                if ($drop === null) {
                    foreach ($kept as $ua) if (count($show) < $maxUa && in_array($ua[1], $involved, true)) $show[$ua[0]] = $ua;
                }
                foreach ($kept as $ua) if (count($show) < $maxUa && !isset($show[$ua[0]])) $show[$ua[0]] = $ua;
                ksort($show);
                foreach ($show as $ua) $block[] = self::rawLine($parsed, $ua[0]);
                $rest = count($kept) - count($show);
                $block[] = '# ... ще ' . $rest . ' ' . self::plural($rest, 'рядок', 'рядки', 'рядків') . ' User-agent у цій групі' . ($drop !== null ? ' (без змін)' : '');
            }
            if ($drop !== null && $dropped) $block[] = '# прибрано User-agent: ' . self::listSome($dropped, 5);
            foreach ($ruleLines as $ln) {
                if ($comment !== null && isset($comment[$ln])) $block[] = '# ' . trim(self::rawLine($parsed, $ln)) . '   # видалено';
                else $block[] = self::rawLine($parsed, $ln);
            }
            $others = count($g['rules']) - count($ruleLines);
            if ($others > 0) $block[] = '# ... ще ' . $others . ' ' . self::plural($others, 'правило', 'правила', 'правил') . ' у цій групі';
            $out[] = implode("\n", $block);
            $out[] = '';
        }
        return trim(implode("\n", $out));
    }

    /** До $max UA-рядків групи (спершу з токенами $prefer) і коментар про решту. */
    private static function uaHeadLines(array $parsed, array $g, array $prefer, int $max): array
    {
        $ua = $g['ua'];
        if (count($ua) <= $max + 1) return array_map(function ($u) use ($parsed) { return self::rawLine($parsed, $u[0]); }, $ua);
        $show = [];
        foreach ($ua as $u) if (count($show) < $max && in_array($u[1], $prefer, true)) $show[$u[0]] = $u;
        foreach ($ua as $u) if (count($show) < $max && !isset($show[$u[0]])) $show[$u[0]] = $u;
        ksort($show);
        $out = array_map(function ($u) use ($parsed) { return self::rawLine($parsed, $u[0]); }, array_values($show));
        $rest = count($ua) - count($show);
        $out[] = '# ... ще ' . $rest . ' ' . self::plural($rest, 'рядок', 'рядки', 'рядків') . ' User-agent у цій групі';
        return $out;
    }

    private static function ucFirst(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function describeRule(array $parsed, array $e, ?array $rule): string
    {
        $name = $e['matched'] === null ? '?' : (string)$e['matched'];
        $gi = $e['groups'][0] ?? null;
        $g = $gi !== null ? ($parsed['groups'][$gi] ?? null) : null;
        if ($g && count($g['ua']) > 2 && $name !== '*' && strcasecmp($name, (string)$e['token']) === 0) {
            // спільна група багатьох ботів: однаковий опис, щоб боти з неї склалися в один рядок доказів
            $s = 'спільна група з ' . count($g['ua']) . ' рядків User-agent (рядки ' . $g['ua'][0][0] . '-' . $g['ua'][count($g['ua']) - 1][0] . ')';
        } else {
            $s = 'група «User-agent: ' . $name . '»';
        }
        if ($e['matched'] !== null && strcasecmp($name, (string)$e['token']) !== 0 && $name !== '*') {
            $s .= ' (' . $e['token'] . ' виконує правила ' . $name . ', якщо окремої групи немає)';
        }
        if ($rule) $s .= ', рядок ' . $rule['line'] . ' «' . trim(self::rawLine($parsed, (int)$rule['line'])) . '»';
        return $s;
    }

    /**
     * Рядки доказів: боти, заблоковані тим самим правилом, - одним рядком.
     * Для $major і для поодиноких ботів додається пояснення, навіщо бот.
     */
    private static function groupedBotLines(array $byRule, array $catalog, array $major): array
    {
        $lines = [];
        foreach ($byRule as $desc => $toks) {
            if (count($toks) === 1) {
                $t = $toks[0];
                $lines[] = $t . ' (' . $catalog[$t]['what'] . '): заборонено - ' . $desc;
                continue;
            }
            $names = array_map(function ($t) use ($catalog, $major) {
                return in_array($t, $major, true) ? $t . ' (' . $catalog[$t]['what'] . ')' : $t;
            }, $toks);
            $lines[] = 'Заборонено - ' . $desc . ': ' . implode(', ', $names);
        }
        return $lines;
    }

    private static function groupName(array $g): string
    {
        return implode(', ', array_map(function ($ua) { return $ua[2]; }, $g['ua']));
    }

    private static function rawLine(array $parsed, int $ln): string
    {
        return rtrim((string)($parsed['lines'][$ln] ?? ''));
    }

    private static function lineRanges(array $nums): string
    {
        $nums = array_values(array_unique(array_map('intval', $nums)));
        sort($nums);
        $out = [];
        $start = null; $prev = null;
        foreach ($nums as $n) {
            if ($start === null) { $start = $prev = $n; continue; }
            if ($n === $prev + 1) { $prev = $n; continue; }
            $out[] = $start === $prev ? (string)$start : $start . '-' . $prev;
            $start = $prev = $n;
        }
        if ($start !== null) $out[] = $start === $prev ? (string)$start : $start . '-' . $prev;
        return implode(', ', $out);
    }

    // ================================================================== helpers

    private static function robotsState(array $r): string
    {
        $status = (int)($r['status'] ?? 0);
        $body = (string)($r['body'] ?? '');
        // сторінка перевірки - лише справжні CDN/WAF; звичайна «403 Forbidden» (access_denied) іде за кодом відповіді
        $ch = $body !== '' ? Page::challengeFromHtml($body) : null;
        if ($ch !== null && array_key_exists($ch, self::CHALLENGE_VENDORS)) return 'challenge';
        if ($status === 0 || $status === 429 || $status >= 500) return 'unreachable';
        if ($status === 401 || $status === 403) return 'forbidden';
        if ($status >= 300) return 'missing';
        if ($status >= 200) return self::looksHtml($body) ? 'html' : 'ok';
        return 'missing';
    }

    private static function looksHtml(string $body): bool
    {
        $b = ltrim($body);
        if ($b === '') return false;
        if ($b[0] === '<') return true;
        $head = strtolower(substr($b, 0, 1024));
        return strpos($head, '<html') !== false || strpos($head, '<!doctype') !== false || strpos($head, '<head') !== false;
    }

    private static function classifyProbe(array $r, int $baseBytes, string $finalUrl): string
    {
        $st = (int)$r['status'];
        if (!empty($r['challenge'])) return 'blocked';
        if ($st === 0) return 'noresp';
        if ($st === 429) return 'ratelimit';
        if (in_array($st, [401, 403, 406, 451], true) || $st >= 500) return 'blocked';
        if ($st >= 300) return 'differs';
        $fu = (string)($r['final_url'] ?? '');
        if ($fu !== '' && !Crawler::sameSite($fu, $finalUrl)) return 'differs';
        $ref = min($baseBytes, 65536);
        if ($ref >= 15000 && (int)$r['bytes'] < 3000 && (int)$r['bytes'] < $ref * 0.1) return 'differs';
        return 'ok';
    }

    /** Текст результату запиту проби (без тексту помилки curl - він іде окремим рядком, один раз). */
    private static function probeText(array $p): string
    {
        $st = (int)($p['status'] ?? 0);
        $cls = (string)($p['cls'] ?? '');
        if ($cls === 'dropped') return 'сервер розриває з\'єднання без відповіді (2 спроби) - схоже на nginx «return 444» або правило WAF';
        if ($cls === 'slow') return 'немає відповіді за ' . self::PROBE_TIMEOUT . ' с у 2 спробах, хоча контрольний запит отримав відповідь ' . (((int)($p['ctl_ms'] ?? 0)) < 1000 ? 'менш ніж за 1 с' : 'за ' . self::num(round(((int)$p['ctl_ms']) / 1000, 1)) . ' с') . ' - ймовірно, навмисне сповільнення';
        if ($st === 0) return 'немає відповіді' . (!empty($p['retried']) ? ' у 2 спробах' : '') . (!empty($p['timeout']) ? ' (таймаут ' . self::PROBE_TIMEOUT . ' с)' : '');
        $s = 'HTTP ' . $st;
        if (!empty($p['challenge'])) $s .= ' (' . self::chLabel((string)$p['challenge']) . ')';
        elseif ($st >= 200 && $st < 300) $s .= ', ' . self::kb((int)$p['bytes'], true);
        if (($p['cls'] ?? '') === 'differs' && $st >= 200 && $st < 300) $s .= ' - підозріло мала відповідь або редирект на інший домен';
        return $s;
    }

    /** Людська назва сторінки захисту з Page::challengeFromHtml(). */
    private static function chLabel(string $ch): string
    {
        $map = [
            'cloudflare' => 'сторінка перевірки Cloudflare', 'ddos-guard' => 'сторінка перевірки DDoS-Guard',
            'imperva' => 'блок Imperva', 'sucuri' => 'блок Sucuri', 'akamai' => 'блок Akamai',
            'datadome' => 'перевірка DataDome', 'vercel' => 'перевірка Vercel', 'captcha' => 'капча',
            'access_denied' => 'сторінка «доступ заборонено»',
        ];
        return $map[$ch] ?? ('сторінка захисту ' . $ch);
    }

    /** Сервіс захисту від ботів за заголовками відповіді (DataDome, Imperva, Sucuri). */
    private static function detectSecurity(array $h): ?string
    {
        if (isset($h['x-datadome']) || isset($h['x-datadome-cid'])) return 'DataDome';
        if (isset($h['x-iinfo'])) return 'Imperva';
        if (isset($h['x-sucuri-id'])) return 'Sucuri';
        return null;
    }

    private static function detectCdn(array $h): ?string
    {
        $server = strtolower(implode(' ', array_map('strval', (array)($h['server'] ?? []))));
        if (isset($h['cf-ray']) || strpos($server, 'cloudflare') !== false) return 'Cloudflare';
        if (isset($h['x-sucuri-id']) || strpos($server, 'sucuri') !== false) return 'Sucuri';
        if (isset($h['x-iinfo'])) return 'Imperva';
        if (strpos($server, 'ddos-guard') !== false) return 'DDoS-Guard';
        if (strpos($server, 'akamai') !== false || isset($h['x-akamai-transformed'])) return 'Akamai';
        if (isset($h['x-amz-cf-id']) || strpos($server, 'cloudfront') !== false) return 'Amazon CloudFront';
        return null;
    }

    private static function wafCode(?string $cdn): string
    {
        $logs = "# Логи сервера: з якими кодами відповідає справжнім AI-ботам\n"
            . "grep -E \"OAI-SearchBot|ChatGPT-User|PerplexityBot|Perplexity-User|Claude-SearchBot|ClaudeBot|GPTBot\" /var/log/nginx/access.log | awk '{print \$9}' | sort | uniq -c";
        if ($cdn === 'Cloudflare') {
            return "# Cloudflare (dash.cloudflare.com -> ваш домен)\n"
                . "# 1) Security -> Bots: перевірте, чи не ввімкнено «Block AI bots».\n"
                . "#    AI Crawl Control: для OAI-SearchBot, ChatGPT-User, PerplexityBot, Claude-SearchBot - Allow.\n"
                . "# 2) Security -> WAF -> Custom rules -> Create rule (першим у списку)\n"
                . "#    Назва: Allow search and AI search bots\n"
                . "#    Вираз (Edit expression):\n"
                . "(cf.verified_bot_category in {\"Search Engine Crawler\" \"AI Search\" \"AI Assistant\"})\n"
                . "#    Дія: Skip - All remaining custom rules, Super Bot Fight Mode, Browser Integrity Check\n"
                . "# 3) Security -> Events: фільтр User agent contains \"OAI-SearchBot\" - яке правило спрацьовує.\n\n"
                . $logs;
        }
        return "# Знайти правила, що блокують ботів за User-Agent\n"
            . "grep -RniE \"GPTBot|ClaudeBot|Claude-SearchBot|PerplexityBot|OAI-SearchBot|ChatGPT-User|http_user_agent\" /etc/nginx/ 2>/dev/null\n"
            . "grep -niE \"GPTBot|ClaudeBot|Claude-SearchBot|PerplexityBot|OAI-SearchBot|ChatGPT-User|HTTP_USER_AGENT\" /var/www/[ВКАЖІТЬ корінь сайту]/.htaccess\n"
            . ($cdn ? "# " . $cdn . ": у панелі захисту дозвольте верифікованих пошукових і AI-пошукових ботів\n" : "# Також перевірте плагін безпеки CMS і панель хостингу (захист від ботів / DDoS)\n")
            . "\n" . $logs;
    }

    private static function statusText(array $r): string
    {
        $st = (int)($r['status'] ?? 0);
        if ($st === 0) {
            $e = trim((string)($r['error'] ?? ''));
            return 'немає відповіді' . ($e !== '' ? ' (' . mb_substr($e, 0, 80) . ')' : '');
        }
        return 'HTTP ' . $st;
    }

    /** Перші символи HTML без зайвих пробілів (для «Як зараз»). */
    private static function htmlHead(string $html, int $chars): string
    {
        $s = mb_scrub($html, 'UTF-8');
        $s = trim((string)preg_replace('/\s+/u', ' ', mb_substr($s, 0, $chars * 3)));
        return mb_strlen($s) > $chars ? mb_substr($s, 0, $chars) . ' ...' : $s;
    }

    private static function kb(int $bytes, bool $probe = false): string
    {
        if ($probe && $bytes >= 65536) return '64+ КБ';
        if ($bytes < 1024) return $bytes . ' ' . self::plural($bytes, 'байт', 'байти', 'байт');
        return number_format($bytes / 1024, 0, '', ' ') . ' КБ';
    }

    private static function num(float $v): string
    {
        return ($v == floor($v)) ? (string)(int)$v : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n);
        $n10 = $n % 10;
        $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) return $one;
        if ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20)) return $few;
        return $many;
    }

    private static function roleLabel(array $roles): string
    {
        foreach (['home', 'about', 'contact', 'article', 'product', 'category', 'service', 'other', 'root'] as $r) {
            if (in_array($r, $roles, true)) return self::ROLE_LABELS[$r];
        }
        return 'сторінка';
    }

    private static function itemsList(array $items): string
    {
        $srcs = [];
        foreach ($items as $it) $srcs[$it['src']] = true;
        $ds = array_values(array_unique(array_map(function ($i) { return $i['d']; }, $items)));
        return implode(', ', $ds) . ' у ' . implode(' і ', array_keys($srcs));
    }

    private static function itemsEvidence(array $items): string
    {
        $parts = [];
        foreach ($items as $it) {
            $who = $it['bot'] === 'all' ? '' : ' (лише для ' . ($it['bot'] === 'googlebot' ? 'Google' : ($it['bot'] === 'bingbot' || $it['bot'] === 'msnbot' ? 'Bing' : $it['bot'])) . ')';
            $parts[$it['raw'] . $who] = $it['raw'] . $who;
        }
        return implode('; ', array_values($parts));
    }

    private static function itemsBad(array $items): string
    {
        return implode("\n", array_values(array_unique(array_map(function ($i) { return $i['raw']; }, $items))));
    }

    private function summary(array $sub): string
    {
        $order = self::SEV_RANK;
        $fs = $this->F;
        usort($fs, function ($a, $b) use ($order) { return ($order[$b['severity']] ?? 0) <=> ($order[$a['severity']] ?? 0); });
        $top = array_values(array_filter($fs, function ($f) { return in_array($f['severity'], ['critical', 'high'], true); }));
        if ($top) {
            $s = $top[0]['text'];
            if (isset($top[1])) $s .= '; ' . self::lcFirstWord($top[1]['text']);
            if ($sub['index_directives'] === null) $s .= '. Директиви сторінок не оцінено: сайт не віддав HTML нашому серверу';
        } else {
            $ok = []; $na = [];
            foreach (['robots_retrieval' => 'robots.txt', 'waf_probe' => 'відповідь сервера', 'index_directives' => 'meta-директиви'] as $k => $lbl) {
                if ($sub[$k] === null) $na[] = $lbl; else $ok[] = $lbl;
            }
            $mid = array_values(array_filter($fs, function ($f) { return $f['severity'] === 'medium'; }));
            if ($mid) {
                $s = $mid[0]['text'] . (isset($mid[1]) ? '; ' . self::lcFirstWord($mid[1]['text']) : '') . '. Критичних блокувань доступу не виявлено';
                if ($na) $s .= '; ' . implode(' і ', $na) . ' перевірити не вдалося, бо сайт блокує запити аудитора';
            } else {
                $s = !$na
                    ? 'Пошукові та AI-боти мають доступ: robots.txt, сервер і meta-директиви їх не блокують'
                    : 'За перевіреними даними (' . implode(', ', $ok) . ') пошукові та AI-боти мають доступ; ' . implode(' і ', $na) . ' перевірити не вдалося, бо сайт блокує запити аудитора';
            }
        }
        return rtrim($s, '. ') . '.';
    }

    private static function lcFirstWord(string $s): string
    {
        if (preg_match('/^(\p{Lu})(\p{Ll})/u', $s)) return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        return $s;
    }
}
