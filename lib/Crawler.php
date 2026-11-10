<?php
declare(strict_types=1);

/**
 * HTTP-шар аудиту. Усі методи статичні.
 *
 * Результат fetch()/head()/fetchBrowser()/fetchMulti() (ключі v1 збережені):
 *   ok, status, http_version, body, headers (lowercase => [values]), final_url, ttfb_ms, total_ms,
 *   size (байти тіла після розпакування), error
 * Нові ключі v2:
 *   size_download (байти по мережі), content_type, content_encoding, redirect_count, primary_ip,
 *   ssl_verify_result, truncated, fetched_as ('bot'|'browser'|'custom'), url (запитаний URL)
 */
class Crawler
{
    const UA = 'Mozilla/5.0 (compatible; GeoAuditBot/1.0; +https://github.com/sneex-seo/geo-audit)';
    const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
    const TIMEOUT = 20;
    const MAX_BODY = 6291456; // 6 МБ - більше для аудиту не потрібно

    /**
     * Остання спроба отримати головну, коли захист блокує і нашого бота, і браузер: частина сайтів
     * пропускає лише відомих ботів за User-Agent. Порядок = пріоритет.
     */
    const AI_AGENTS = [
        'OAI-SearchBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.3; +https://openai.com/searchbot',
        'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
        'Googlebot'     => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Bingbot'       => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    ];

    /**
     * Запасний агент для режиму 'browser' (повтори після блокування нашого бота). За замовчуванням Chrome;
     * AuditEngine перемикає на AI-бота, якщо сайт пропустив лише його. Діє до кінця процесу (один аудит).
     */
    private static $altUa = null;
    private static $altName = 'Chrome';

    public static function setAltAgent(?string $ua = null, string $name = 'Chrome'): void
    {
        self::$altUa = $ua;
        self::$altName = $ua === null ? 'Chrome' : $name;
    }

    public static function altUa(): string
    {
        return self::$altUa ?? self::BROWSER_UA;
    }

    /** 'Chrome' або назва AI-бота (для текстів звіту). */
    public static function altName(): string
    {
        return self::$altName;
    }

    public static function altHeaders(): array
    {
        return self::$altUa === null ? self::browserHeaders() : self::botHeaders();
    }

    /** Заголовки, з якими ходить «браузер». */
    private static function browserHeaders(): array
    {
        return [
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
    }

    private static function botHeaders(): array
    {
        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: uk,en;q=0.8',
        ];
    }

    /**
     * Готує curl-хендл. $state - масив за посиланням, куди пишуться заголовки й тіло.
     */
    private static function prepare(string $url, array &$state, array $o = [])
    {
        $ua       = $o['ua'] ?? self::UA;
        $headOnly = !empty($o['head']);
        $timeout  = (int)($o['timeout'] ?? self::TIMEOUT);
        $maxBytes = (int)($o['max_bytes'] ?? self::MAX_BODY);
        $hdrs     = $o['headers'] ?? self::botHeaders();

        $state = ['headers' => [], 'body' => '', 'truncated' => false];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => (int)($o['max_redirs'] ?? 5),
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_NOBODY         => $headOnly,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$state) {
                $len = strlen($header);
                $line = trim($header);
                // новий блок заголовків після редиректу - скидаємо попередні
                if (stripos($line, 'HTTP/') === 0) {
                    $state['headers'] = [];
                    return $len;
                }
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $state['headers'][strtolower(trim($parts[0]))][] = trim($parts[1]);
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$state, $maxBytes) {
                $state['body'] .= $chunk;
                if (strlen($state['body']) >= $maxBytes) {
                    $state['truncated'] = true;
                    return 0; // перериваємо завантаження
                }
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        return $ch;
    }

    private static function finish($ch, array $state, string $url, float $elapsedMs, string $fetchedAs, bool $execOk): array
    {
        $info  = curl_getinfo($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        if ($state['truncated'] && $errno === 23) { // CURLE_WRITE_ERROR через наш ліміт
            $error = '';
            $execOk = true;
        }
        $status = (int)($info['http_code'] ?? 0);
        $ct = $info['content_type'] ?? null;
        if (!$ct && isset($state['headers']['content-type'])) $ct = end($state['headers']['content-type']);
        $ce = isset($state['headers']['content-encoding']) ? strtolower((string)end($state['headers']['content-encoding'])) : null;
        $body = $state['body'];

        return [
            'ok'                => $execOk && $status >= 200 && $status < 400,
            'status'            => $status,
            'http_version'      => $info['http_version'] ?? null,
            'body'              => $body,
            'headers'           => $state['headers'],
            'final_url'         => $info['url'] ?? $url,
            'ttfb_ms'           => isset($info['starttransfer_time']) ? (int)round($info['starttransfer_time'] * 1000) : null,
            'total_ms'          => (int)round($elapsedMs),
            'size'              => strlen($body),
            'size_download'     => (int)($info['size_download'] ?? 0),
            'content_type'      => $ct ?: null,
            'content_encoding'  => $ce ?: null,
            'redirect_count'    => (int)($info['redirect_count'] ?? 0),
            'primary_ip'        => $info['primary_ip'] ?? null,
            'ssl_verify_result' => isset($info['ssl_verify_result']) ? (int)$info['ssl_verify_result'] : null,
            'truncated'         => (bool)$state['truncated'],
            'error'             => $error,
            'errno'             => $errno,
            'fetched_as'        => $fetchedAs,
            'url'               => $url,
        ];
    }

    private static function curl(string $url, array $o = [], string $fetchedAs = 'bot'): array
    {
        $state = [];
        $ch = self::prepare($url, $state, $o);
        $start = microtime(true);
        $res = curl_exec($ch);
        $elapsed = (microtime(true) - $start) * 1000;
        $out = self::finish($ch, $state, $url, $elapsed, $fetchedAs, $res !== false);

        return $out;
    }

    /** GET ботом GeoAuditBot (як у v1). */
    public static function fetch(string $url, int $timeout = self::TIMEOUT): array
    {
        return self::curl($url, ['timeout' => $timeout], 'bot');
    }

    /** HEAD ботом (як у v1). */
    public static function head(string $url): array
    {
        return self::curl($url, ['head' => true, 'timeout' => 15], 'bot');
    }

    /** GET запасним агентом: «як звичайний браузер» (Chrome desktop, uk/en) або обраним AI-ботом (див. setAltAgent). */
    public static function fetchBrowser(string $url, int $timeout = self::TIMEOUT): array
    {
        return self::curl($url, ['ua' => self::altUa(), 'headers' => self::altHeaders(), 'timeout' => $timeout], 'browser');
    }

    /**
     * Паралельне завантаження. Повертає [url => result] (формат як у fetch()).
     * $ua: null = бот, 'browser' = запасний агент (Chrome або AI-бот), інше = довільний UA.
     */
    public static function fetchMulti(array $urls, ?string $ua = null, int $timeout = 15): array
    {
        $urls = array_values(array_unique(array_filter($urls, 'is_string')));
        if (!$urls) return [];
        $o = ['timeout' => $timeout];
        $as = 'bot';
        if ($ua === 'browser') {
            $o['ua'] = self::altUa();
            $o['headers'] = self::altHeaders();
            $as = 'browser';
        } elseif ($ua !== null) {
            $o['ua'] = $ua;
            $as = 'custom';
        }
        $reqs = [];
        foreach ($urls as $i => $u) $reqs[$i] = ['url' => $u, 'o' => $o];
        $out = [];
        foreach (self::multi($reqs, $as) as $i => $r) $out[$urls[$i]] = $r;
        return $out;
    }

    /** Одна адреса паралельно кількома User-Agent: [назва => result] у порядку $agents ([назва => UA]). */
    public static function fetchAgents(string $url, array $agents, int $timeout = 15): array
    {
        $reqs = [];
        foreach ($agents as $name => $ua) $reqs[$name] = ['url' => $url, 'o' => ['ua' => $ua, 'timeout' => $timeout]];
        return self::multi($reqs, 'custom');
    }

    /** curl_multi по [key => ['url' => .., 'o' => опції prepare()]]: [key => result] у порядку запитів. */
    private static function multi(array $reqs, string $as): array
    {
        if (!$reqs) return [];
        $mh = curl_multi_init();
        $handles = [];
        $states = [];
        foreach ($reqs as $i => $rq) {
            $states[$i] = [];
            $ch = self::prepare($rq['url'], $states[$i], $rq['o']);
            $handles[$i] = $ch;
            curl_multi_add_handle($mh, $ch);
        }
        $start = microtime(true);
        $done = [];
        do {
            $st = curl_multi_exec($mh, $running);
            while ($inf = curl_multi_info_read($mh)) {
                foreach ($handles as $i => $h) {
                    if ($h === $inf['handle']) { $done[$i] = (microtime(true) - $start) * 1000; break; }
                }
            }
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running && $st === CURLM_OK);

        $out = [];
        foreach ($handles as $i => $ch) {
            $err = curl_errno($ch);
            $execOk = ($err === 0) || ($err === 23 && $states[$i]['truncated']);
            $out[$i] = self::finish($ch, $states[$i], $reqs[$i]['url'], $done[$i] ?? (microtime(true) - $start) * 1000, $as, $execOk);
            curl_multi_remove_handle($mh, $ch);

        }
        curl_multi_close($mh);
        return $out;
    }

    /**
     * Перевірка доступу певним User-Agent: GET (не HEAD - WAF часто відповідають на HEAD інакше),
     * тіло обрізається після ~64 КБ. Ключі v1 'status' і 'blocked' збережені.
     */
    public static function fetchAs(string $url, string $userAgent): array
    {
        $r = self::curl($url, [
            'ua' => $userAgent,
            'timeout' => 15,
            'max_redirs' => 3,
            'max_bytes' => 65536,
        ], 'custom');
        $challenge = null;
        if ($r['body'] !== '') {
            require_once __DIR__ . '/Page.php';
            $challenge = Page::challengeFromHtml($r['body']);
        }
        $status = (int)$r['status'];
        return [
            'status'    => $status,
            'blocked'   => in_array($status, [401, 403, 429], true) || $challenge !== null,
            'challenge' => $challenge,
            'bytes'     => strlen($r['body']),
            'final_url' => $r['final_url'],
            'error'     => $r['error'],
            'ua'        => $userAgent,
        ];
    }

    public static function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim($url, '/');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    public static function safeHost(string $url): string
    {
        $h = parse_url($url, PHP_URL_HOST);
        return $h ?: $url;
    }

    /** Хост без www., у нижньому регістрі. Приймає URL або голий хост. */
    public static function registrableHost(string $url): string
    {
        $h = (strpos($url, '//') !== false) ? (string)parse_url($url, PHP_URL_HOST) : $url;
        if ($h === '' && strpos($url, '//') === false) $h = (string)parse_url('http://' . $url, PHP_URL_HOST);
        $h = strtolower(rtrim(trim($h), '.'));
        if (strpos($h, 'www.') === 0) $h = substr($h, 4);
        return $h;
    }

    /** Чи це один сайт (ігноруючи www і регістр). */
    public static function sameSite(string $a, string $b): bool
    {
        $ha = self::registrableHost($a);
        $hb = self::registrableHost($b);
        return $ha !== '' && $ha === $hb;
    }

    /**
     * Перетворює href на абсолютний URL відносно $base. Повертає null для javascript:/mailto:/tel:/data:/порожніх.
     */
    public static function absolutize(string $href, string $base, bool $stripFragment = true): ?string
    {
        $href = trim(str_ireplace('&amp;', '&', $href));
        $href = preg_replace('/[\x00-\x1F\x7F]/', '', $href);
        if ($href === '' || $href[0] === '#') return null;
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $href, $m)) {
            $scheme = strtolower($m[1]);
            if ($scheme !== 'http' && $scheme !== 'https') return null;
            $abs = $href;
        } else {
            $b = parse_url($base);
            if (!$b || empty($b['host'])) return null;
            $scheme = $b['scheme'] ?? 'https';
            $auth = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
            if (strpos($href, '//') === 0) {
                $abs = $scheme . ':' . $href;
            } elseif ($href[0] === '/') {
                $abs = $scheme . '://' . $auth . $href;
            } elseif ($href[0] === '?') {
                $abs = $scheme . '://' . $auth . ($b['path'] ?? '/') . $href;
            } else {
                $bp = $b['path'] ?? '/';
                $dir = substr($bp, 0, (int)strrpos($bp, '/') + 1);
                if ($dir === '') $dir = '/';
                $abs = $scheme . '://' . $auth . $dir . $href;
            }
        }
        // нормалізуємо ./ і ../ у шляху
        $p = parse_url($abs);
        if (!$p || empty($p['host'])) return null;
        $path = $p['path'] ?? '/';
        if (strpos($path, '.') !== false) {
            $segs = [];
            foreach (explode('/', $path) as $i => $seg) {
                if ($seg === '..') { if (count($segs) > 1) array_pop($segs); continue; }
                if ($seg === '.') continue;
                $segs[] = $seg;
            }
            $path = implode('/', $segs);
            if ($path === '' || $path[0] !== '/') $path = '/' . $path;
            if (preg_match('#/\.\.?$#', $p['path'] ?? '')) $path = rtrim($path, '/') . '/';
        }
        if ($path === '') $path = '/';
        $out = strtolower($p['scheme'] ?? 'https') . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
        if (isset($p['query']) && $p['query'] !== '') $out .= '?' . $p['query'];
        if (!$stripFragment && isset($p['fragment'])) $out .= '#' . $p['fragment'];
        // пробіли та кирилиця в шляху лишаємо як є (їх нормалізує curl)
        return str_replace(' ', '%20', $out);
    }
}
