<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';

/**
 * Технічна база: HTTPS і редиректи, одна версія хоста, TTFB, стиснення і розмір HTML, HTTP/2,
 * Core Web Vitals (PageSpeed Insights / CrUX), viewport, alt у зображеннях, soft-404, заголовки безпеки.
 *
 * Мережеві запити перевірки (http-версія, www-версія, неіснуюча сторінка, повторний замір TTFB з
 * перевіркою сертифіката, PageSpeed Insights) виконуються паралельно одним curl_multi.
 *
 * Dev: GEO_AUDIT_PSI=off - не викликати PageSpeed; GEO_AUDIT_PSI_CACHE=<dir> - кешувати відповіді PSI на 6 год.
 */
class TechnicalCheck extends BaseCheck
{
    const NET_TIMEOUT = 15;
    const PSI_TIMEOUT = 35;
    const CRUX_ENDPOINT = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';
    const PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    const HTML_HEAVY_BYTES = 1572864; // 1,5 МБ

    public function id(): string { return 'technical'; }
    public function name(): string { return 'Технічна база'; }
    public function weight(): float { return 0.10; }

    public function run(string $url, array $ctx): array
    {
        $hp = is_array($ctx['homepage'] ?? null) ? $ctx['homepage'] : [];
        $page = (($ctx['page'] ?? null) instanceof Page) ? $ctx['page'] : null;
        $access = is_array($ctx['access'] ?? null) ? $ctx['access'] : [];
        // сторінка «чи ви не робот», яку не розпізнав загальний детектор: контентні параметри не оцінюємо
        $botWall = $page !== null && self::isBotWall($page, $hp);
        if ($botWall) $page = null;
        $final = (string)($ctx['url'] ?? $url);
        $pu = parse_url($final);
        $scheme = strtolower((string)($pu['scheme'] ?? 'https'));
        $host = strtolower((string)($pu['host'] ?? ''));
        if ($host === '' || (int)($hp['status'] ?? 0) === 0) {
            return $this->skipped('Сервер не відповів на запит головної сторінки, технічні параметри виміряти неможливо');
        }
        $port = isset($pu['port']) ? ':' . $pu['port'] : '';
        $origin = $scheme . '://' . $host . $port;
        $isHttps = $scheme === 'https';
        $blocked = $page === null;
        $asBrowser = ($access['fetched_as'] ?? 'bot') === 'browser';
        $altHost = self::altHost($host);

        // ------------------------------------------------------------ мережа (паралельно)
        $ua = $asBrowser ? Crawler::altUa() : Crawler::UA;
        $hdrs = $asBrowser
            ? ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: uk-UA,uk;q=0.9,en;q=0.8']
            : ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: uk,en;q=0.8'];
        $reqs = [];
        if ($isHttps) {
            $reqs['http'] = ['url' => 'http://' . $host . '/', 'ua' => $ua, 'headers' => $hdrs, 'max_bytes' => 65536];
        } else {
            // з перевіркою сертифіката: інакше https із самопідписаним сертифікатом виглядав би робочим
            $reqs['https_try'] = ['url' => 'https://' . $host . '/', 'ua' => $ua, 'headers' => $hdrs, 'verify' => true, 'max_bytes' => 65536];
        }
        if ($altHost !== null) {
            $reqs['alt'] = ['url' => $scheme . '://' . $altHost . '/', 'ua' => $ua, 'headers' => $hdrs, 'max_bytes' => 262144];
        }
        $probePath = '/geo-audit-404-check-' . bin2hex(random_bytes(5));
        $reqs['s404'] = ['url' => $origin . $probePath, 'ua' => $ua, 'headers' => $hdrs, 'max_bytes' => 262144];
        $reqs['probe'] = ['url' => $final, 'ua' => $ua, 'headers' => $hdrs, 'verify' => $isHttps, 'follow' => false,
            'encoding' => 'gzip, deflate, br', 'max_bytes' => 32768];
        $psiInfo = ['status' => 'disabled'];
        $psiReq = $this->psiRequest($final, $psiInfo, $blocked);
        if ($psiReq !== null) $reqs['psi'] = $psiReq;

        $net = self::netBatch($reqs);

        // сертифікат: якщо перевірка не пройшла - повторний замір без перевірки
        $certError = null;
        $sslVerified = $isHttps && isset($net['probe']) && (int)$net['probe']['errno'] === 0 && (int)$net['probe']['status'] > 0;
        if ($isHttps && isset($net['probe']) && in_array((int)$net['probe']['errno'], [51, 60], true)) {
            $certError = ['error' => $net['probe']['error'], 'verify' => (int)$net['probe']['ssl_verify_result']];
            $again = self::netBatch(['probe' => ['url' => $final, 'ua' => $ua, 'headers' => $hdrs, 'follow' => false, 'encoding' => 'gzip, deflate, br', 'max_bytes' => 32768]]);
            $net['probe'] = $again['probe'];
        }
        // PageSpeed з ключем відхилено (ключ недійсний / для проєкту ключа не увімкнено PageSpeed Insights API) -
        // повторюємо без ключа, щоб не втрачати дані через налаштування ключа
        // (400 буває і від самого Lighthouse - FAILED_DOCUMENT_REQUEST тощо; тоді повтор без ключа лише марнує спільну квоту)
        $psiErr = isset($net['psi']) ? self::psiErrorText($net['psi']) : '';
        if (isset($net['psi'], $psiReq) && !empty($psiInfo['keyed'])
            && ((int)$net['psi']['status'] === 403 || ((int)$net['psi']['status'] === 400 && preg_match('/api.?key|key not valid|API_KEY|has not been used|is disabled|PERMISSION_DENIED/i', $psiErr)))) {
            $psiInfo['key_rejected'] = 'HTTP ' . (int)$net['psi']['status'] . ': ' . self::psiErrorText($net['psi']);
            $retry = $psiReq;
            $retry['url'] = self::PSI_ENDPOINT . '?url=' . rawurlencode($final) . '&strategy=mobile&category=performance';
            $net['psi'] = self::netBatch(['psi' => $retry])['psi'] ?? null;
            $psiInfo['keyed'] = false;
        }
        $keep = array_intersect_key($psiInfo, ['crux_api' => 1, 'keyed' => 1, 'key_name' => 1, 'key_rejected' => 1]);
        if (isset($net['psi']) || !empty($psiInfo['cached']) || !empty($psiInfo['done'])) $psiInfo = $this->psiParse($net['psi'] ?? null, $final, $psiInfo);
        $psiInfo += $keep;
        if (($psiInfo['status'] ?? '') === 'quota') {
            $psiInfo['note'] = !empty($psiInfo['keyed'])
                ? 'вичерпано квоту PageSpeed Insights API для ключа ' . ($psiInfo['key_name'] ?? '') . ' (ліміт проєкту Google Cloud)'
                : 'вичерпано спільну квоту PageSpeed Insights для запитів без ключа; потрібен власний ключ (PAGESPEED_API_KEY у .env) з увімкненим PageSpeed Insights API';
        }

        $findings = [];
        $passed = [];
        $sub = [];
        $data = ['final_url' => $final, 'fetched_as' => $access['fetched_as'] ?? 'bot', 'ssl_verified' => $sslVerified && $certError === null];
        if ($botWall) $data['bot_wall'] = true;
        $caps = [];

        // ------------------------------------------------------------ 1. HTTPS
        $this->checkHttps($isHttps, $host, $final, $net, $certError, $sub, $findings, $passed, $data, $caps);

        // ------------------------------------------------------------ 2. одна версія хоста
        $this->checkHostVariants($altHost, $host, $scheme, $final, $net, $sub, $findings, $passed, $data);

        // тимчасові редиректи (http->https і www) - одна знахідка
        $this->checkTempRedirects($data, $host, $findings);

        // ------------------------------------------------------------ 3. TTFB
        $this->checkTtfb($blocked, $hp, $net['probe'] ?? null, $psiInfo, $final, $sub, $findings, $passed, $data);

        // ------------------------------------------------------------ 4. стиснення + розмір HTML
        $this->checkCompression($blocked, $hp, $page, $net['probe'] ?? null, $final, $host, $sub, $findings, $passed, $data);

        // ------------------------------------------------------------ 5. HTTP/2
        $this->checkHttp2($isHttps, $hp, $net['probe'] ?? null, $final, $sub, $findings, $passed, $data);

        // ------------------------------------------------------------ 6. Core Web Vitals
        $this->checkCwv($psiInfo, $final, $sub, $findings, $passed, $data);

        // ------------------------------------------------------------ 7. viewport
        $this->checkViewport($page, $final, $sub, $findings, $passed, $data);

        // ------------------------------------------------------------ 8. alt у зображеннях
        $this->checkImages($page, $final, (array)($ctx['samples'] ?? []), $sub, $findings, $passed, $data);

        // ------------------------------------------------------------ 9. soft-404
        $stack = self::stack((array)($hp['headers'] ?? []), $page);
        if (array_filter($stack)) $data['stack'] = array_filter($stack);
        $this->checkSoft404($net['s404'] ?? null, $origin, $probePath, $stack, $sub, $findings, $passed, $data);

        // ------------------------------------------------------------ 10. заголовки безпеки + версії ПЗ
        $this->checkSecurityHeaders($blocked, $isHttps, $hp, $page, $stack, $final, $host, $sub, $findings, $passed, $data);

        // ------------------------------------------------------------ підсумок
        $labels = [
            'https'                 => 'HTTPS і редирект з http',
            'host_canonicalization' => 'Одна версія хоста (www / без www)',
            'ttfb'                  => 'Час відповіді сервера (TTFB)',
            'compression'           => 'Стиснення і розмір HTML',
            'http2'                 => 'HTTP/2+',
            'cwv'                   => 'Core Web Vitals',
            'mobile'                => 'Мобільний viewport',
            'images_alt'            => 'Атрибут alt у зображеннях',
            'soft404'               => 'Коректний 404 для неіснуючих URL',
            'security_headers'      => 'Заголовки безпеки',
        ];
        $order = array_keys($labels);
        $subOrdered = [];
        foreach ($order as $k) $subOrdered[$k] = $sub[$k] ?? null;
        $weights = ['https' => 2.0, 'host_canonicalization' => 1.0, 'ttfb' => 1.5, 'compression' => 1.0, 'http2' => 0.5,
            'cwv' => 1.5, 'mobile' => 1.5, 'images_alt' => 1.0, 'soft404' => 1.0, 'security_headers' => 0.5];
        $comp = [];
        foreach ($subOrdered as $k => $v) $comp[] = [$v, $weights[$k]];
        $hasAny = array_filter($subOrdered, function ($v) { return $v !== null; });
        if (!$hasAny) {
            return $this->skipped('Жоден технічний параметр не вдалося виміряти (сервер блокує запити аудиту)', $data);
        }
        $score = $this->scoreFrom($comp);
        foreach ($caps as $c) $score = min($score, $c);

        // сайт не віддав HTML (захист від ботів): оцінено лише мережеві параметри - бал перевірки
        // входить у загальний з вагою, пропорційною частці виміряних параметрів, щоб 100 із 2-3 пунктів не завищувало оцінку
        $partialWeight = null;
        if ($blocked) {
            $evalW = 0.0;
            foreach ($subOrdered as $k => $v) if ($v !== null) $evalW += $weights[$k];
            $partialWeight = max(0.01, round($this->weight() * $evalW / array_sum($weights), 3));
            $data['partial'] = ['evaluated' => array_keys(array_filter($subOrdered, function ($v) { return $v !== null; })), 'weight' => $partialWeight];
        }

        $data['psi'] = array_diff_key($psiInfo, ['raw' => 1, 'lcp_element' => 1, 'lcp_snippet' => 1, 'cache_file' => 1]);
        $summary = $this->summaryLine($findings, $blocked, $subOrdered, $partialWeight, $data);

        $res = $this->result($score, $subOrdered, $labels, $findings, $passed, $data, $summary);
        if ($partialWeight !== null && ($res['status'] ?? '') === 'ok') $res['weight'] = $partialWeight;
        return $res;
    }

    // ================================================================== parts

    private function checkHttps(bool $isHttps, string $host, string $final, array $net, ?array $certError, array &$sub, array &$findings, array &$passed, array &$data, array &$caps): void
    {
        if (!$isHttps) {
            $try = $net['https_try'] ?? null;
            $tryText = 'https://' . $host . '/ - не перевірено';
            if ($try) {
                if ($try['status'] === 0) $tryText = 'https://' . $host . '/ - з\'єднання не встановлено (' . ($try['error'] !== '' ? $try['error'] : 'немає відповіді') . ')';
                else $tryText = self::chainText($try);
            }
            $data['https_try'] = $try ? self::chainData($try) : null;
            // https-версія є, але сертифікат недійсний: редирект на https зараз лише зламав би сайт
            if ($try && in_array((int)$try['errno'], [51, 60], true)) {
                [$certLabel, $certFix] = self::certExplain((int)$try['ssl_verify_result'], (string)$try['error']);
                $data['https_try_cert_error'] = ['error' => $try['error'], 'verify' => (int)$try['ssl_verify_result']];
                $sub['https'] = 10;
                $caps[] = 50;
                $findings[] = $this->finding('no_https', 'high', 'Сайт працює без HTTPS: https-версія має недійсний сертифікат (' . $certLabel . ')', [
                    'evidence' => "Головна сторінка відкривається за адресою {$final} (без шифрування).\nhttps://{$host}/ відповідає з помилкою сертифіката: «{$try['error']}» (код перевірки OpenSSL " . (int)$try['ssl_verify_result'] . ').',
                    'why'      => 'Google використовує HTTPS як сигнал ранжування, а браузери позначають http-сторінки як «Не захищено». Редирект на https зараз зробив би гірше: і відвідувачі, і боти отримали б помилку сертифіката замість сторінки.',
                    'fix'      => $certFix . ' Лише після цього налаштуйте постійний 301-редирект з http:// на https://.',
                    'bad'      => "http://{$host}/ -> 200 (без шифрування)\nhttps://{$host}/ -> помилка сертифіката: {$certLabel}",
                    'good'     => "https://{$host}/ -> 200 (валідний сертифікат)\nhttp://{$host}/ -> 301 -> https://{$host}/",
                    'code'     => "# 1) спершу валідний сертифікат Let's Encrypt (nginx)\nsudo certbot --nginx -d {$host}\n\n# 2) лише потім - усі http-запити -> https\nserver {\n    listen 80;\n    server_name {$host};\n    return 301 https://{$host}\$request_uri;\n}",
                    'verify'   => "curl -sSI https://{$host}/ (без -k) має відповісти без помилки сертифіката; SSL Labs: https://www.ssllabs.com/ssltest/analyze.html?d={$host}. Потім curl -sI http://{$host}/ - очікуємо 301 на https.",
                    'effort'   => 'S',
                ]);
                return;
            }
            // https-версія працює (200 на https), але сайт віддає і http без редиректу
            $httpsWorks = $try && (int)$try['status'] === 200 && stripos((string)$try['final_url'], 'https://') === 0
                && ($try['body'] === '' || Page::challengeFromHtml($try['body']) === null);
            if ($httpsWorks) {
                $sub['https'] = 40;
                $findings[] = $this->finding('http_not_redirected', 'medium', 'Сайт відкривається по http без редиректу на https', [
                    'evidence' => "Головна відкрилася за адресою {$final} без редиректу на https, хоча https-версія працює: " . self::chainText($try) . '.',
                    'why'      => 'Дві робочі версії (http і https) - це дубль: пошуковики й AI-краулери витрачають ресурси на обидві, а частина посилань і згадок веде на незахищену версію.',
                    'fix'      => 'Налаштуйте постійний 301-редирект з http:// на https:// для всіх адрес сайту.',
                    'bad'      => "http://{$host}/ -> 200 (контент без шифрування)",
                    'good'     => "http://{$host}/ -> 301 -> https://{$host}/",
                    'code'     => "# nginx\nserver {\n    listen 80;\n    server_name {$host};\n    return 301 https://{$host}\$request_uri;\n}\n\n# або Apache (.htaccess)\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]",
                    'verify'   => "curl -sI http://{$host}/ | head -3 - очікуємо 301 і Location: https://{$host}/",
                    'effort'   => 'S',
                ]);
                return;
            }
            $sub['https'] = 0;
            $caps[] = 50;
            $findings[] = $this->finding('no_https', 'high', 'Сайт працює без HTTPS', [
                'evidence' => "Головна сторінка відкривається за адресою {$final} (без шифрування).\n" . $tryText . '.',
                'why'      => 'Google використовує HTTPS як сигнал ранжування, а браузери позначають такі сторінки як «Не захищено». AI Overviews і ChatGPT search беруть джерела з індексів Google і Bing, тому незахищений сайт програє конкурентам ще до етапу цитування.',
                'fix'      => 'Встановіть SSL-сертифікат (безкоштовно - Let\'s Encrypt або SSL у Cloudflare) і налаштуйте постійний 301-редирект з http:// на https:// для всіх адрес.',
                'bad'      => 'http://' . $host . '/ -> 200 (сторінка без шифрування)',
                'good'     => 'http://' . $host . '/ -> 301 -> https://' . $host . '/ -> 200',
                'code'     => "# 1) сертифікат Let's Encrypt (nginx)\nsudo certbot --nginx -d {$host}\n\n# 2) nginx: усі http-запити -> https\nserver {\n    listen 80;\n    server_name {$host};\n    return 301 https://{$host}\$request_uri;\n}\n\n# або Apache (.htaccess)\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]",
                'verify'   => "curl -sI http://{$host}/ | head -3 - очікуємо 301 і Location: https://{$host}/. Сертифікат перевірте в SSL Labs: https://www.ssllabs.com/ssltest/analyze.html?d={$host}",
                'effort'   => 'S',
            ]);
            return;
        }

        $score = 100;
        // сертифікат
        if ($certError !== null) {
            $score = 0;
            $caps[] = 40;
            [$certLabel, $certFix] = self::certExplain($certError['verify'], $certError['error']);
            $data['ssl_error'] = $certError;
            $findings[] = $this->finding('ssl_invalid', 'high', 'SSL-сертифікат не проходить перевірку: ' . $certLabel, [
                'evidence' => "Запит до {$final} з перевіркою сертифіката завершився помилкою: «{$certError['error']}» (код перевірки OpenSSL {$certError['verify']}).",
                'why'      => 'Браузер іноді «прощає» такі помилки (наприклад, сам довантажує проміжний сертифікат), а боти і програмні клієнти - ні: для них сторінка просто не завантажується, тож AI-пошук не може її прочитати й процитувати.',
                'fix'      => $certFix,
                'bad'      => 'curl ' . $final . "\ncurl: (60) " . $certError['error'],
                'good'     => "curl -sI {$final}\nHTTP/2 200\n(без помилок сертифіката, ланцюжок: сертифікат сайту + проміжний сертифікат центру сертифікації)",
                'code'     => "# nginx: вказуйте повний ланцюжок (fullchain), а не лише сертифікат сайту\nssl_certificate     /etc/letsencrypt/live/{$host}/fullchain.pem;\nssl_certificate_key /etc/letsencrypt/live/{$host}/privkey.pem;\n\n# автоматичне продовження Let's Encrypt\nsudo certbot renew --dry-run",
                'verify'   => "SSL Labs: https://www.ssllabs.com/ssltest/analyze.html?d={$host} - оцінка не нижче A, без «Chain issues». Або curl -sSI {$final} (без -k) має відповісти без помилки сертифіката.",
                'effort'   => 'S',
            ]);
        }

        $h = $net['http'] ?? null;
        $data['http_chain'] = $h ? self::chainData($h) : null;
        $sslOk = !empty($data['ssl_verified']);
        if ($h === null || ((int)$h['status'] === 0 && !$h['hops'])) {
            $data['http_note'] = 'http-версія не відповідає' . ($h && $h['error'] !== '' ? ' (' . $h['error'] . ')' : '');
            // порт 80 закритий / домен без http - дубля немає; тайм-аут - висновок про http не робимо
            $refused = $h === null || in_array((int)$h['errno'], [6, 7], true);
            if ($certError === null && $sslOk) {
                $passed[] = $refused
                    ? 'Сайт працює на HTTPS із валідним сертифікатом; http-версія не обслуговується, тож дубля http/https немає'
                    : 'Сайт працює на HTTPS із валідним сертифікатом (http-версія не відповіла вчасно, редирект не перевірено)';
            }
            $sub['https'] = $score;
            return;
        }
        $hops = $h['hops'];
        $first = $hops[0] ?? null;
        $firstStatus = $first ? (int)$first['status'] : (int)$h['status'];
        $endsHttps = stripos((string)$h['final_url'], 'https://') === 0;
        $endHost = strtolower((string)parse_url((string)$h['final_url'], PHP_URL_HOST));
        if ($firstStatus >= 300 && $firstStatus < 400 && $endsHttps) {
            if (in_array($firstStatus, [301, 308], true)) {
                if ($certError === null) {
                    // ланцюжок, що закінчується сторінкою WAF (403), показуємо без фінального коду
                    $passed[] = (int)$h['status'] >= 400
                        ? 'Постійний редирект з http на https: ' . self::chainText(['hops' => array_slice($hops, 0, -1)]) . ' -> ' . $h['final_url']
                        : 'HTTPS: ' . self::chainText($h);
                }
            } else {
                $score = min($score, 70);
                $data['temp_redirects'][] = ['what' => 'http -> https', 'chain' => self::chainText($h), 'status' => $firstStatus];
            }
            if (count($hops) > 3) $data['http_chain_long'] = true;
        } elseif ($firstStatus >= 200 && $firstStatus < 300 && !$endsHttps) {
            $isChallenge = $h['body'] !== '' && Page::challengeFromHtml($h['body']) !== null;
            if ($isChallenge) {
                $data['http_note'] = 'http-версія віддала сторінку перевірки WAF - висновок неможливий';
            } else {
                $score = min($score, 40);
                $title = self::titleOf($h['body']);
                $findings[] = $this->finding('http_not_redirected', 'medium', 'http-версія сайту відкривається без редиректу на https', [
                    'evidence' => "http://{$host}/ відповідає {$firstStatus} без редиректу" . ($title !== '' ? " (title «{$title}»)" : '') . ", при цьому основна версія - {$final}.",
                    'why'      => 'Дві робочі версії головної (http і https) - це дубль: пошуковики й AI-краулери витрачають ресурси на обидві, а частина посилань і згадок веде на незахищену версію.',
                    'fix'      => 'Налаштуйте постійний 301-редирект з http:// на https:// для всіх адрес сайту (не лише головної).',
                    'bad'      => "http://{$host}/ -> {$firstStatus} (контент без шифрування)",
                    'good'     => "http://{$host}/ -> 301 -> https://{$host}/",
                    'code'     => "# nginx\nserver {\n    listen 80;\n    server_name {$host};\n    return 301 https://{$host}\$request_uri;\n}\n\n# або Apache (.htaccess)\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]",
                    'verify'   => "curl -sI http://{$host}/ | head -3 - очікуємо 301 і Location: https://{$host}/",
                    'effort'   => 'S',
                ]);
            }
        } elseif ($firstStatus >= 300 && $firstStatus < 400 && !$endsHttps) {
            $data['http_note'] = 'http-версія редиректить, але не на https: ' . self::chainText($h);
            $score = min($score, 60);
        } else {
            // 4xx/5xx/WAF на http - редирект оцінити неможливо, але сам HTTPS працює
            $data['http_note'] = 'http-версія: ' . self::chainText($h) . ' - редирект на https перевірити не вдалося';
            if ($certError === null && $sslOk) $passed[] = 'Сайт працює на HTTPS із валідним сертифікатом (http-версія відповіла ' . $firstStatus . ', редирект на https не перевірено)';
        }
        if ($endHost !== '' && $endsHttps && $endHost !== $host && !Crawler::sameSite($endHost, $host)) {
            $data['http_note'] = 'http-версія веде на інший домен: ' . $endHost;
        }
        $sub['https'] = $score;
    }

    private function checkHostVariants(?string $altHost, string $host, string $scheme, string $final, array $net, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        if ($altHost === null) {
            $sub['host_canonicalization'] = null;
            $data['alt_host'] = 'не перевірявся (піддомен)';
            return;
        }
        $a = $net['alt'] ?? null;
        $data['alt_chain'] = $a ? self::chainData($a) : null;
        if ($a === null || ((int)$a['status'] === 0 && !$a['hops'])) {
            $errno = $a ? (int)$a['errno'] : 0;
            if ($a !== null && $errno === 28) {
                // тайм-аут - не доказ відсутності дубля
                $sub['host_canonicalization'] = null;
                $data['alt_note'] = $altHost . ' не відповів за ' . self::NET_TIMEOUT . ' с - висновок неможливий';
                return;
            }
            $sub['host_canonicalization'] = 100;
            $data['alt_note'] = $altHost . ' не відповідає' . ($a && $a['error'] !== '' ? ' (' . $a['error'] . ')' : '') . ' - дубля хоста немає';
            $why = [6 => 'домен не резолвиться', 7 => 'з\'єднання відхилено', 35 => 'TLS-з\'єднання не встановлюється'][$errno] ?? 'немає відповіді';
            $passed[] = 'Версія ' . $altHost . ' не відкривається (' . $why . ') - дубля хоста немає';
            return;
        }
        $hops = $a['hops'];
        $firstStatus = $hops ? (int)$hops[0]['status'] : (int)$a['status'];
        $endHost = strtolower((string)parse_url((string)$a['final_url'], PHP_URL_HOST));
        $altUrl = $scheme . '://' . $altHost . '/';
        if ($firstStatus >= 300 && $firstStatus < 400) {
            if ($endHost === $host) {
                if (in_array($firstStatus, [301, 308], true)) {
                    $sub['host_canonicalization'] = 100;
                    // ланцюжок, що закінчується сторінкою WAF (4xx), показуємо без фінального коду
                    $passed[] = 'Одна версія хоста: ' . ((int)$a['status'] >= 400 && count($hops) > 1
                        ? self::chainText(['hops' => array_slice($hops, 0, -1)]) . ' -> ' . $a['final_url']
                        : self::chainText($a));
                } else {
                    $sub['host_canonicalization'] = 70;
                    $data['temp_redirects'][] = ['what' => $altHost . ' -> ' . $host, 'chain' => self::chainText($a), 'status' => $firstStatus];
                }
            } else {
                $sub['host_canonicalization'] = 85;
                $data['alt_note'] = $altHost . ' редиректить не на основний хост: ' . self::chainText($a);
            }
            return;
        }
        if ($firstStatus >= 200 && $firstStatus < 300) {
            $isChallenge = $a['body'] !== '' && Page::challengeFromHtml($a['body']) !== null;
            if ($isChallenge) {
                $sub['host_canonicalization'] = null;
                $data['alt_note'] = $altHost . ' віддав сторінку перевірки WAF - висновок неможливий';
                return;
            }
            $altPage = $a['body'] !== '' ? Page::fromHtml($a['body'], (string)$a['final_url'], $a['headers']) : null;
            $altCanon = $altPage ? $altPage->canonical() : null;
            $altTitle = $altPage ? $altPage->title() : '';
            $consolidated = $altCanon !== null && strtolower((string)parse_url($altCanon, PHP_URL_HOST)) === $host;
            $sev = $consolidated ? 'low' : 'medium';
            $sub['host_canonicalization'] = $consolidated ? 60 : 30;
            $ev = "{$altUrl} відповідає {$firstStatus} без редиректу" . ($altTitle !== '' ? " (title «" . self::mbTrunc($altTitle, 80) . "»)" : '') . ".\nОсновна версія сайту - {$final}.\n";
            $ev .= $altCanon !== null ? "Canonical на {$altUrl}: {$altCanon}" . ($consolidated ? ' - вказує на основний хост, тому дубль частково згладжено.' : '.') : "Canonical на {$altUrl} відсутній.";
            $findings[] = $this->finding('host_duplicate', $sev, 'Сайт доступний і з www, і без www', [
                'evidence' => $ev,
                'why'      => 'Дві робочі версії сайту ділять між собою посилання і згадки. Пошуковики й AI можуть показувати і цитувати не ту адресу, а сигнали довіри розпорошуються.',
                'fix'      => "Залиште одну версію (зараз основна - {$host}) і налаштуйте постійний 301-редирект з {$altHost} на неї зі збереженням шляху.",
                'bad'      => "{$altUrl} -> {$firstStatus} (копія сайту)\n{$final} -> 200",
                'good'     => "{$altUrl} -> 301 -> {$scheme}://{$host}/",
                'code'     => "# nginx\nserver {\n    listen 443 ssl;\n    server_name {$altHost};\n    # ssl_certificate ... (той самий сертифікат має покривати {$altHost})\n    return 301 https://{$host}\$request_uri;\n}\n\n# або Apache (.htaccess)\nRewriteEngine On\nRewriteCond %{HTTP_HOST} ^" . preg_quote($altHost, '/') . "$ [NC]\nRewriteRule ^ https://{$host}%{REQUEST_URI} [R=301,L]",
                'verify'   => "curl -sI {$altUrl} | head -3 - очікуємо 301 і Location: {$scheme}://{$host}/",
                'effort'   => 'S',
            ]);
            return;
        }
        // 4xx/5xx/WAF на альтернативному хості - не дубль
        $sub['host_canonicalization'] = in_array($firstStatus, [401, 403, 429], true) ? null : 90;
        $data['alt_note'] = in_array($firstStatus, [401, 403, 429], true)
            ? $altHost . ' відповідає ' . $firstStatus . ' (захист сервера) - висновок неможливий'
            : $altHost . ' відповідає ' . $firstStatus . ' - дубля немає';
    }

    private function checkTempRedirects(array $data, string $host, array &$findings): void
    {
        $tr = $data['temp_redirects'] ?? [];
        if (!$tr) return;
        $lines = [];
        $codes = [];
        foreach ($tr as $t) { $lines[] = $t['what'] . ': ' . $t['chain']; $codes[] = $t['status']; }
        $codes = array_values(array_unique($codes));
        $findings[] = $this->finding('temp_redirect', 'low', 'Редирект на основну версію тимчасовий (' . implode('/', $codes) . ' замість 301)', [
            'evidence' => implode("\n", $lines),
            'why'      => '302 і 307 означають «тимчасово»: пошукові системи можуть довше тримати в індексі стару адресу й повільніше переносити сигнали на основну. 301 або 308 - однозначний сигнал, яку адресу індексувати й цитувати.',
            'fix'      => 'Замініть тимчасові редиректи на постійні 301 (або 308).',
            'bad'      => implode("\n", $lines),
            'good'     => str_replace([' 302 ', ' 307 '], [' 301 ', ' 301 '], implode("\n", $lines)),
            'code'     => "# nginx\nreturn 301 https://{$host}\$request_uri;\n\n# Apache (.htaccess)\nRewriteRule ^ https://{$host}%{REQUEST_URI} [R=301,L]",
            'verify'   => "curl -sI http://{$host}/ | head -1 - очікуємо HTTP/1.1 301",
            'effort'   => 'S',
        ]);
    }

    private function checkTtfb(bool $blocked, array $hp, ?array $probe, array $psi, string $final, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        if ($blocked) {
            $sub['ttfb'] = null;
            $data['ttfb_note'] = 'головна не віддала звичайний HTML (захист від ботів) - час відповіді сторінки перевірки не показовий';
            return;
        }
        $m = [];
        $hpTtfb = isset($hp['ttfb_ms']) ? (int)$hp['ttfb_ms'] : null;
        $hpRedir = (int)($hp['redirect_count'] ?? 0);
        if ($hpTtfb !== null && $hpTtfb > 0 && $hpRedir === 0) $m[] = $hpTtfb;
        $server = null; $netPart = null; $pt = null;
        if ($probe && (int)$probe['status'] >= 200 && (int)$probe['status'] < 400 && $probe['t']['starttransfer'] > 0) {
            $pt = (int)round($probe['t']['starttransfer'] * 1000);
            $m[] = $pt;
            $server = max(0, (int)round(($probe['t']['starttransfer'] - $probe['t']['pretransfer']) * 1000));
            $netPart = max(0, (int)round($probe['t']['pretransfer'] * 1000));
        }
        if (!$m) {
            $sub['ttfb'] = null;
            $data['ttfb_note'] = 'не вдалося виміряти';
            return;
        }
        $best = min($m);
        $data['ttfb_ms'] = $m;
        $data['ttfb_server_ms'] = $server;
        if ($best <= 200) $s = 100;
        elseif ($best <= 500) $s = 90;
        elseif ($best <= 800) $s = 75;
        elseif ($best <= 1500) $s = 45;
        else $s = 15;
        $sub['ttfb'] = $s;
        $mText = implode(' і ', array_map([self::class, 'fmtMs'], $m));
        if ($best <= 800) {
            $passed[] = 'Час відповіді сервера (TTFB) головної: ' . self::fmtMs($best) . ' (замір із сервера аудиту)';
            return;
        }
        $sev = $best > 1500 ? 'high' : 'medium';
        $ev = 'Замір із сервера аудиту (Німеччина), ' . count($m) . ' ' . (count($m) === 1 ? 'запит' : 'запити') . ': ' . $mText . '.';
        if ($server !== null && $pt !== null) $ev .= "\nУ контрольному запиті (" . self::fmtMs($pt) . ') очікування відповіді сервера ~' . self::fmtMs($server) . ', DNS + з\'єднання + TLS ~' . self::fmtMs((int)$netPart) . '.';
        if ($hpRedir > 0 && $hpTtfb !== null) $ev .= "\nПочатковий запит головної (з редиректом на " . $final . ') - ' . self::fmtMs($hpTtfb) . ' до першого байта.';
        $fieldTtfb = $psi['metrics']['ttfb'] ?? null;
        if ($fieldTtfb && strpos((string)($psi['source'] ?? ''), 'field') === 0) {
            $ev .= "\nРеальні користувачі Chrome (CrUX, p75): TTFB " . self::fmtMs((int)$fieldTtfb['value']) . '.';
        }
        $findings[] = $this->finding('slow_ttfb', $sev, 'Сервер повільно віддає головну: TTFB ' . self::fmtMs($best), [
            'evidence' => $ev,
            'why'      => 'На повільні відповіді сервера Googlebot реагує зменшенням обходу; ліміти AI-краулерів не публікуються, але повільний сервер ризикує так само: менше сторінок потрапляє в індекс. Для людей повільний TTFB напряму погіршує LCP.',
            'fix'      => ($server !== null && $server > 600)
                ? 'Основна затримка - генерація сторінки на сервері. Увімкніть повносторінковий кеш (кеш CMS, nginx fastcgi_cache або CDN з кешуванням HTML), перевірте повільні SQL-запити й плагіни.'
                : 'Увімкніть кешування HTML на рівні сервера або CDN, перевірте мережеву затримку хостингу та налаштування TLS.',
            'bad'      => 'TTFB головної: ' . $mText . ($server !== null ? ' (генерація ~' . self::fmtMs($server) . ')' : ''),
            'good'     => 'TTFB до 800 мс (норма web.dev), для кешованих сторінок - 100-300 мс',
            'code'     => "# nginx + PHP-FPM: мікрокеш HTML на 1 хвилину для анонімних відвідувачів\n# http { ... }\nfastcgi_cache_path /var/cache/nginx/html levels=1:2 keys_zone=HTML:50m inactive=10m max_size=1g;\n\n# server { ... }\nset \$skip_cache 0;\nif (\$request_method = POST) { set \$skip_cache 1; }\nif (\$http_cookie ~* \"PHPSESSID|wordpress_logged_in|comment_author\") { set \$skip_cache 1; }\n\nlocation ~ \\.php$ {\n    include fastcgi_params;\n    fastcgi_pass unix:/run/php/php-fpm.sock;\n    fastcgi_cache HTML;\n    fastcgi_cache_key \$scheme\$host\$request_uri;\n    fastcgi_cache_valid 200 301 1m;\n    fastcgi_cache_bypass \$skip_cache;\n    fastcgi_no_cache \$skip_cache;\n    fastcgi_cache_use_stale error timeout updating http_500;\n    add_header X-Cache \$upstream_cache_status;\n}",
            'verify'   => "curl -o /dev/null -s -w \"TTFB: %{time_starttransfer}s\\n\" {$final} (повторіть 3-5 разів), а також PageSpeed Insights -> «Час до першого байта».",
            'effort'   => 'M',
        ]);
    }

    private function checkCompression(bool $blocked, array $hp, ?Page $page, ?array $probe, string $final, string $host, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        if ($blocked) {
            $sub['compression'] = null;
            return;
        }
        $size = (int)($hp['size'] ?? 0);
        $dl = (int)($hp['size_download'] ?? 0);
        $enc = strtolower((string)($hp['content_encoding'] ?? ''));
        $penc = $probe ? strtolower((string)($probe['content_encoding'] ?? '')) : '';
        $data['html_bytes'] = $size;
        $data['html_bytes_network'] = $dl;
        $data['content_encoding'] = $enc !== '' ? $enc : ($penc !== '' ? $penc : null);
        $known = ['gzip', 'br', 'zstd', 'deflate', 'x-gzip'];
        $score = 100;
        $effective = in_array($enc, $known, true) ? $enc : (in_array($penc, $known, true) ? $penc : '');
        if ($effective !== '') {
            $passed[] = 'HTML стискається (' . $effective . '): ' . self::fmtBytes($dl > 0 ? $dl : $size) . ' по мережі замість ' . self::fmtBytes($size);
        } elseif ($size < 1500) {
            $score = 100;
            $data['compression_note'] = 'HTML менший за 1,5 КБ - стиснення не дає ефекту';
        } else {
            $score = 0;
            $findings[] = $this->finding('no_compression', 'medium', 'HTML віддається без стиснення', [
                'evidence' => "Два запити до {$final} із заголовком Accept-Encoding (gzip, br та інші) - у відповіді немає Content-Encoding. По мережі передається " . self::fmtBytes($dl > 0 ? $dl : $size) . ' HTML.',
                'why'      => 'Стиснення зменшує HTML у 4-8 разів. Сторінка швидше доходить до користувача і до краулерів, які обмежують час завантаження.',
                'fix'      => 'Увімкніть gzip (і, якщо є модуль, brotli) для text/html, CSS, JS, JSON і SVG на веб-сервері або в CDN.',
                'bad'      => self::statusLine($hp) . "\ncontent-type: " . (string)($hp['content_type'] ?? 'text/html') . "\n(немає content-encoding, " . self::fmtBytes($size) . ')',
                'good'     => "HTTP/2 200\ncontent-type: text/html; charset=utf-8\ncontent-encoding: br\nvary: Accept-Encoding",
                'code'     => "# nginx: http { ... }\ngzip on;\ngzip_comp_level 5;\ngzip_min_length 1024;\ngzip_vary on;\ngzip_proxied any;\ngzip_types text/plain text/css text/xml application/xml application/json application/javascript application/rss+xml image/svg+xml;\n# text/html стискається автоматично, якщо gzip on\n\n# brotli (потрібен модуль ngx_brotli)\nbrotli on;\nbrotli_comp_level 5;\nbrotli_types text/plain text/css text/xml application/xml application/json application/javascript image/svg+xml;\n\n# Apache (.htaccess)\n<IfModule mod_deflate.c>\n  AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml application/xml application/json application/javascript image/svg+xml\n</IfModule>",
                'verify'   => "curl -sI -H \"Accept-Encoding: gzip, br\" {$final} | grep -i content-encoding - очікуємо gzip або br.",
                'effort'   => 'S',
            ]);
        }
        // розмір HTML
        if ($size > self::HTML_HEAVY_BYTES) {
            $score = min($score, 60);
            $parts = [];
            if ($page) {
                $sc = $page->scripts();
                if ($sc['inline_bytes'] > 0) $parts[] = 'inline JavaScript ' . self::fmtBytes((int)$sc['inline_bytes']);
                if ($sc['jsonld_bytes'] > 20000) $parts[] = 'JSON-LD ' . self::fmtBytes((int)$sc['jsonld_bytes']);
                $html = $page->html();
                $dataUri = 0;
                if (preg_match_all('/data:image\/[a-z+.\-]+;base64,[A-Za-z0-9+\/=]{200,}/', $html, $mm)) {
                    foreach ($mm[0] as $x) $dataUri += strlen($x);
                }
                if ($dataUri > 0) $parts[] = 'base64-зображення ' . self::fmtBytes($dataUri);
                $svg = 0;
                if (preg_match_all('#<svg\b.*?</svg>#is', $html, $sm)) foreach ($sm[0] as $x) $svg += strlen($x);
                if ($svg > 50000) $parts[] = 'inline SVG ' . self::fmtBytes($svg);
                $style = 0;
                if (preg_match_all('#<style\b[^>]*>.*?</style>#is', $html, $stm)) foreach ($stm[0] as $x) $style += strlen($x);
                if ($style > 50000) $parts[] = 'inline CSS ' . self::fmtBytes($style);
            }
            $trunc = !empty($hp['truncated']) ? ' (завантаження обірвано на ліміті аудиту, фактичний розмір більший)' : '';
            $findings[] = $this->finding('html_heavy', 'medium', 'Дуже великий HTML головної: ' . self::fmtBytes($size), [
                'evidence' => "Розмір HTML після розпакування: " . self::fmtBytes($size) . $trunc . ($dl > 0 ? ', по мережі ' . self::fmtBytes($dl) : '') . '.' . ($parts ? "\nНайбільші складники: " . implode(', ', $parts) . '.' : ''),
                'why'      => 'Краулери обмежують обсяг і час завантаження сторінки: для Google Search Googlebot читає лише перші 2 МБ HTML (після розпакування), ліміти AI-ботів не публікуються. Важкий HTML довше парситься і на мобільних, і в ботів - контент наприкінці документа може не потрапити в індекс.',
                'fix'      => 'Винесіть inline-скрипти і великий JSON-стан у зовнішні файли, замініть base64-зображення на звичайні файли, обмежте кількість товарів/блоків на головній з пагінацією.',
                'bad'      => 'HTML головної: ' . self::fmtBytes($size) . ($parts ? ' (' . implode(', ', $parts) . ')' : ''),
                'good'     => 'HTML до 300-500 КБ без розпакування; скрипти і стилі - окремими кешованими файлами',
                'code'     => "<!-- замість великого inline-скрипту -->\n<script src=\"/assets/app.js\" defer></script>\n\n<!-- замість base64 у src -->\n<img src=\"/images/banner.webp\" width=\"1200\" height=\"400\" alt=\"[ВКАЖІТЬ ОПИС]\" loading=\"lazy\">",
                'verify'   => "curl -s --compressed {$final} | wc -c - розмір HTML у байтах; Chrome DevTools -> Network -> Doc.",
                'effort'   => 'M',
            ]);
        } elseif ($size > 0 && $size <= 512000) {
            // хвалимо лише компактний HTML (до 500 КБ - та сама межа, що в порадах html_heavy); 0,5-1,5 МБ - без оцінки
            $passed[] = 'Розмір HTML головної: ' . self::fmtBytes($size);
        }
        $sub['compression'] = $score;
    }

    private function checkHttp2(bool $isHttps, array $hp, ?array $probe, string $final, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        $v = (int)($hp['http_version'] ?? 0);
        $pv = $probe ? (int)($probe['http_version'] ?? 0) : 0;
        $best = max($v, $pv);
        $data['http_version'] = self::httpVersionLabel($best);
        $altSvc = strtolower(implode(', ', (array)($hp['headers']['alt-svc'] ?? [])));
        $h3 = strpos($altSvc, 'h3') !== false;
        if ($best === 0) { $sub['http2'] = null; return; }
        if ($best >= 3) {
            $sub['http2'] = 100;
            $passed[] = 'Протокол ' . self::httpVersionLabel($best) . ($h3 ? ', HTTP/3 оголошено через alt-svc' : '');
            return;
        }
        if (!$isHttps) { $sub['http2'] = 0; return; } // окремо не звітуємо: головна проблема - відсутність HTTPS
        $sub['http2'] = 0;
        $findings[] = $this->finding('http1', 'low', 'Сервер працює по ' . self::httpVersionLabel($best) . ', без HTTP/2', [
            'evidence' => "Запит до {$final} з підтримкою HTTP/2 (ALPN h2): сервер відповів по " . self::httpVersionLabel($best) . '.',
            'why'      => 'HTTP/2 завантажує багато ресурсів по одному з\'єднанню і пришвидшує сторінки. Для AI-видимості це другорядний пункт, але дешевий у впровадженні.',
            'fix'      => 'Увімкніть HTTP/2 у веб-сервері (nginx 1.25.1+ - директива http2 on) або підключіть CDN, яка віддає HTTP/2 і HTTP/3.',
            'bad'      => self::httpVersionLabel($best) . ' 200 OK',
            'good'     => 'HTTP/2 200',
            'code'     => "# nginx 1.25.1+\nserver {\n    listen 443 ssl;\n    http2 on;\n    ...\n}\n# старіші версії nginx: listen 443 ssl http2;\n\n# Apache 2.4.17+ (mod_http2)\nProtocols h2 http/1.1",
            'verify'   => "curl -sI --http2 {$final} | head -1 - очікуємо HTTP/2 200.",
            'effort'   => 'S',
        ]);
    }

    private function checkCwv(array $psi, string $final, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        $status = $psi['status'] ?? 'disabled';
        if ($status !== 'ok' || empty($psi['metrics'])) {
            $sub['cwv'] = null;
            return;
        }
        $source = (string)$psi['source'];
        $isField = strpos($source, 'field') === 0;
        $metrics = $psi['metrics'];
        $keys = $isField ? ['lcp', 'inp', 'cls'] : ['lcp', 'tbt', 'cls'];
        $scores = []; $bad = []; $poor = false; $parts = [];
        foreach ($keys as $k) {
            if (!isset($metrics[$k])) continue;
            $mm = $metrics[$k];
            $cat = $mm['cat'];
            $scores[] = $cat === 'good' ? 100 : ($cat === 'ni' ? 60 : 20);
            $parts[] = self::metricText($k, $mm);
            if ($cat !== 'good') {
                $bad[] = $k;
                if ($cat === 'poor') $poor = true;
            }
        }
        if (!$scores) { $sub['cwv'] = null; return; }
        $sub['cwv'] = (int)round(array_sum($scores) / count($scores));
        $psiLink = 'https://pagespeed.web.dev/analysis?url=' . rawurlencode($final) . '&form_factor=mobile';
        $srcLabel = $source === 'field-url' ? 'реальні користувачі Chrome (CrUX, 28 днів, мобільні, p75)'
            : ($source === 'field-origin' ? 'реальні користувачі Chrome по всьому домену (CrUX, 28 днів, мобільні, p75; для самої головної даних замало)'
                : 'лабораторний тест Lighthouse (емуляція мобільного з повільним 4G); даних реальних користувачів CrUX для сайту немає');
        if (!$bad) {
            $passed[] = 'Core Web Vitals в нормі (' . ($isField ? 'CrUX, мобільні' : 'лабораторний тест, мобільний') . '): ' . implode(', ', $parts);
            return;
        }
        $names = array_map(function ($k) { return strtoupper($k); }, $bad);
        $sev = ($isField && $poor) ? 'high' : 'medium';
        $ev = 'Джерело: ' . $srcLabel . ".\n" . implode("\n", $parts);
        if ($isField && isset($psi['overall'])) {
            $ov = ['FAST' => 'добре', 'AVERAGE' => 'потребує покращення', 'SLOW' => 'погано'][strtoupper((string)$psi['overall'])] ?? '';
            if ($ov !== '') $ev .= "\nЗагальна оцінка Core Web Vitals за CrUX: " . $ov . '.';
        }
        if ($isField && isset($psi['lab']['perf'])) $ev .= "\nДля довідки, лабораторний Lighthouse: Performance " . $psi['lab']['perf'] . '/100.';
        $fix = [];
        $code = [];
        if (in_array('lcp', $bad, true)) {
            $lcpSnip = (string)($psi['lcp_snippet'] ?? ($psi['lcp_element'] ?? ''));
            $lcpImgSrc = preg_match('/^<img\b[^>]*\ssrc="([^"]+)"/i', $lcpSnip, $mm) ? html_entity_decode($mm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $isTextLcp = (bool)preg_match('/^<(h[1-6]|p|span|a|li|strong|b|em|blockquote|label|td)\b/i', $lcpSnip)
                || (bool)preg_match('/^<div\b[^>]*\bclass="[^"]*(heading|headline|title|subtitle|caption|text)/i', $lcpSnip);
            if ($lcpSnip !== '' && !$isTextLcp && $lcpImgSrc === '') {
                $fix[] = 'LCP: найбільший елемент першого екрана - блок ' . self::mbTrunc($lcpSnip, 80) . ' (найчастіше це фонове зображення або текст): замініть CSS-фон на <img> з fetchpriority="high" або підвантажте фон через preload, вбудуйте критичний CSS, відкладіть некритичні скрипти, скоротіть TTFB.';
                $code[] = "<!-- LCP: фонове зображення першого екрана -> звичайний <img> з пріоритетом -->\n<link rel=\"preload\" as=\"image\" href=\"[URL ФОНОВОГО ЗОБРАЖЕННЯ]\" fetchpriority=\"high\">\n<img src=\"[URL ФОНОВОГО ЗОБРАЖЕННЯ]\" width=\"[ШИРИНА]\" height=\"[ВИСОТА]\" fetchpriority=\"high\" alt=\"[ВКАЖІТЬ ОПИС]\" style=\"object-fit: cover\">";
            } elseif ($isTextLcp) {
                $fix[] = 'LCP: найбільший елемент першого екрана - текстовий блок, тож його затримують шрифти і блокувальні CSS/JS: підвантажте основний шрифт заздалегідь (preload, font-display: swap), вбудуйте критичний CSS, відкладіть некритичні скрипти, скоротіть TTFB.';
                $code[] = "<!-- LCP - текст: шрифт і критичні стилі без затримки -->\n<link rel=\"preload\" as=\"font\" href=\"/fonts/main.woff2\" type=\"font/woff2\" crossorigin>\n<style>\n  @font-face { font-family: 'Main'; src: url('/fonts/main.woff2') format('woff2'); font-display: swap; }\n  /* критичні стилі першого екрана */\n</style>\n<link rel=\"stylesheet\" href=\"/css/main.css\" media=\"print\" onload=\"this.media='all'\">\n<script src=\"/js/app.js\" defer></script>";
            } else {
                $heroSrc = $lcpImgSrc !== '' ? $lcpImgSrc : '/images/hero.webp';
                $fix[] = 'LCP: оптимізуйте головне зображення першого екрана (WebP/AVIF, правильний розмір, fetchpriority="high", без lazy-loading), скоротіть TTFB і блокувальні CSS/JS.';
                $code[] = "<!-- LCP: головне зображення першого екрана -->\n<link rel=\"preload\" as=\"image\" href=\"{$heroSrc}\" fetchpriority=\"high\">\n<img src=\"{$heroSrc}\" width=\"[ШИРИНА]\" height=\"[ВИСОТА]\" fetchpriority=\"high\" alt=\"[ВКАЖІТЬ ОПИС]\">";
            }
        }
        if (in_array('inp', $bad, true) || in_array('tbt', $bad, true)) {
            $fix[] = 'INP/TBT: розбийте довгі JavaScript-задачі, відкладіть сторонні скрипти (чати, пікселі, A/B-тести), приберіть зайві обробники подій.';
            $code[] = "<!-- сторонні скрипти: не блокувати основний потік -->\n<script src=\"https://example-widget.com/widget.js\" defer></script>\n<script>\n  // довгу обробку ділимо на частини\n  async function handleClick() {\n    updateUi();\n    await new Promise(r => setTimeout(r, 0));\n    sendAnalytics();\n  }\n</script>";
        }
        if (in_array('cls', $bad, true)) {
            $fix[] = 'CLS: задайте width і height для зображень і відео, резервуйте місце під банери й віджети, не вставляйте блоки над уже показаним контентом.';
            $code[] = "<!-- CLS: розміри зарезервовані до завантаження -->\n<img src=\"/images/banner.webp\" width=\"1200\" height=\"400\" alt=\"[ВКАЖІТЬ ОПИС]\">\n<style>.promo-slot { min-height: 250px; }</style>";
        }
        $badLine = implode(' | ', $parts);
        $lcpEl = (string)($psi['lcp_element'] ?? '');
        if ($lcpEl !== '' && in_array('lcp', $bad, true)) {
            $badLine .= "\nLCP-елемент за лабораторним тестом Lighthouse: " . self::mbTrunc($lcpEl, 260);
            // фази LCP з lcp-breakdown-insight не показуємо: це виміри без емуляції повільної мережі
            // (TTFB 1-13 мс поруч із симульованим LCP 7 с) - для клієнта вони суперечливі й недостовірні
        }
        $findings[] = $this->finding('cwv', $sev, ($isField ? 'Core Web Vitals гірше норми: ' : 'Лабораторний тест швидкості гірше норми: ') . implode(', ', $names), [
            'evidence' => $ev,
            'why'      => 'Core Web Vitals Google використовує в системах ранжування як частину оцінки page experience (єдиного сигналу page experience немає). Прямого впливу на цитування в ChatGPT чи Perplexity не доведено, але AI Overviews і AI Mode обирають джерела з видачі Google, де повільні сторінки програють.' . ($isField ? '' : ' Лабораторні цифри - симуляція, реальні значення можуть бути кращими.'),
            'fix'      => implode(' ', $fix),
            'bad'      => $badLine,
            'good'     => $isField ? 'LCP до 2,5 с | INP до 200 мс | CLS до 0,1 (для 75% візитів)' : 'LCP до 2,5 с | TBT до 200 мс | CLS до 0,1',
            'code'     => implode("\n\n", $code),
            'verify'   => $isField
                ? "PageSpeed Insights: {$psiLink} - блок «Дані реальних користувачів». У Search Console -> Core Web Vitals. Дані CrUX оновлюються з вікном 28 днів, тож ефект видно через 3-4 тижні."
                : "PageSpeed Insights: {$psiLink} - розділ «Діагностика продуктивності» (лабораторний тест); повторіть 2-3 рази, бо результати коливаються.",
            'effort'   => 'M',
        ]);
    }

    private function checkViewport(?Page $page, string $final, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        if (!$page) { $sub['mobile'] = null; return; }
        $vp = null;
        foreach ($page->query('//meta[@name]') as $m) {
            if (strtolower(trim($m->getAttribute('name'))) === 'viewport') { $vp = trim($m->getAttribute('content')); break; }
        }
        $data['viewport'] = $vp;
        if ($vp === null) {
            $sub['mobile'] = 0;
            $head = '';
            $metaNames = [];
            foreach ($page->query('//head/meta') as $m) {
                $n = $m->getAttribute('name') ?: ($m->getAttribute('property') ?: ($m->getAttribute('charset') !== '' ? 'charset=' . $m->getAttribute('charset') : $m->getAttribute('http-equiv')));
                if ($n !== '') $metaNames[] = $n;
            }
            $head = $metaNames ? 'Є meta-теги: ' . self::listSome($metaNames, 8) . '.' : 'У <head> немає жодного meta-тегу.';
            $findings[] = $this->finding('no_viewport', 'high', 'Немає мобільного meta viewport', [
                'evidence' => "На {$final} немає <meta name=\"viewport\">. " . $head,
                'why'      => 'Google індексує сайти за мобільною версією (mobile-first). Без viewport телефон показує зменшену десктопну сторінку - це погіршує мобільні сигнали, а AI Overviews і AI Mode беруть джерела саме з цього індексу.',
                'fix'      => 'Додайте meta viewport у <head> усіх шаблонів і перевірте, що верстка адаптивна.',
                'bad'      => "<head>\n  <!-- немає meta viewport -->\n  <title>" . self::mbTrunc($page->title(), 80) . "</title>",
                'good'     => '<meta name="viewport" content="width=device-width, initial-scale=1">',
                'code'     => '<meta name="viewport" content="width=device-width, initial-scale=1">',
                'verify'   => 'Chrome DevTools -> Toggle device toolbar (Ctrl+Shift+M) або Lighthouse -> SEO: пункт «Has a <meta name="viewport"> tag».',
                'effort'   => 'S',
            ]);
            return;
        }
        if (stripos($vp, 'device-width') === false) {
            $sub['mobile'] = 40;
            $findings[] = $this->finding('viewport_fixed', 'medium', 'meta viewport не адаптує сторінку під ширину екрана', [
                'evidence' => "На {$final}: <meta name=\"viewport\" content=\"{$vp}\"> - немає width=device-width.",
                'why'      => 'З фіксованою шириною телефон показує зменшену десктопну сторінку. Google індексує мобільну версію, тож це погіршує мобільні сигнали сайту.',
                'fix'      => 'Замініть значення на width=device-width, initial-scale=1 і перевірте адаптивну верстку.',
                'bad'      => '<meta name="viewport" content="' . $vp . '">',
                'good'     => '<meta name="viewport" content="width=device-width, initial-scale=1">',
                'code'     => '<meta name="viewport" content="width=device-width, initial-scale=1">',
                'verify'   => 'Lighthouse -> SEO і Chrome DevTools у режимі мобільного пристрою: сторінка без горизонтального скролу.',
                'effort'   => 'S',
            ]);
            return;
        }
        // заборона масштабування (user-scalable=no / maximum-scale < 2) - не «пройдено», а дрібне зауваження доступності
        $noZoom = (bool)preg_match('/user-scalable\s*=\s*["\']?(no|0)\b/i', $vp);
        $maxScale = preg_match('/maximum-scale\s*=\s*["\']?([\d.]+)/i', $vp, $ms) ? (float)$ms[1] : null;
        if ($noZoom || ($maxScale !== null && $maxScale < 2)) {
            $sub['mobile'] = 85;
            $what = [];
            if ($noZoom) $what[] = 'user-scalable=no';
            if ($maxScale !== null && $maxScale < 2) $what[] = 'maximum-scale=' . rtrim(rtrim(number_format($maxScale, 1, '.', ''), '0'), '.');
            $clean = trim((string)preg_replace('/\s*,?\s*(user-scalable\s*=\s*["\']?[a-z0-9]+["\']?|maximum-scale\s*=\s*["\']?[\d.]+["\']?)/i', '', $vp), " ,");
            if ($clean === '') $clean = 'width=device-width, initial-scale=1';
            $findings[] = $this->finding('viewport_zoom', 'low', 'meta viewport забороняє масштабування сторінки (' . implode(', ', $what) . ')', [
                'evidence' => "На {$final}: <meta name=\"viewport\" content=\"{$vp}\">. Адаптивна ширина (width=device-width) є, але користувач не може збільшити текст жестом.",
                'why'      => 'На AI-видимість не впливає - це вимога доступності (WCAG 1.4.4): людям зі слабким зором потрібне збільшення. Lighthouse знімає за це бали в розділі Accessibility.',
                'fix'      => 'Приберіть user-scalable=no і maximum-scale з meta viewport.',
                'bad'      => '<meta name="viewport" content="' . $vp . '">',
                'good'     => '<meta name="viewport" content="' . $clean . '">',
                'code'     => '<meta name="viewport" content="' . $clean . '">',
                'verify'   => 'Lighthouse -> Accessibility: пункт «[user-scalable="no"] is not used in the <meta name="viewport"> element and the [maximum-scale] attribute is not less than 5».',
                'effort'   => 'S',
            ]);
            return;
        }
        $sub['mobile'] = 100;
        $passed[] = 'Мобільний viewport: ' . self::mbTrunc($vp, 80);
    }

    private function checkImages(?Page $page, string $final, array $samples, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        if (!$page) { $sub['images_alt'] = null; return; }
        $pages = [['головна', $final, $page]];
        foreach ($samples as $s) {
            if (($s['page'] ?? null) instanceof Page && !self::isBotWall($s['page'], (array)($s['res'] ?? []))) $pages[] = [(string)($s['role'] ?? 'other'), (string)($s['url'] ?? ''), $s['page']];
        }
        $seen = []; $total = 0; $withAlt = 0; $emptyAlt = 0; $ignored = 0; $missing = []; $missingPages = [];
        foreach ($pages as [$label, $purl, $pg]) {
            /** @var Page $pg */
            foreach ($pg->query('//body//img') as $img) {
                if (!($img instanceof DOMElement)) continue;
                if (self::inSkippedTag($img)) continue;
                $src = trim($img->getAttribute('src'));
                $lazy = '';
                foreach (['data-src', 'data-lazy-src', 'data-original', 'data-lazy', 'data-srcset', 'srcset'] as $at) {
                    $v = trim($img->getAttribute($at));
                    if ($v !== '') { $lazy = (string)(preg_split('/[\s,]+/', $v)[0] ?? ''); break; }
                }
                $real = ($src !== '' && stripos($src, 'data:') !== 0) ? $src : $lazy;
                if ($real === '' || stripos($real, 'data:') === 0) { $ignored++; continue; }
                $role = strtolower(trim($img->getAttribute('role')));
                if ($role === 'presentation' || $role === 'none' || strtolower(trim($img->getAttribute('aria-hidden'))) === 'true') { $ignored++; continue; }
                if ($img->hasAttribute('hidden') || preg_match('/display\s*:\s*none|visibility\s*:\s*hidden/i', $img->getAttribute('style'))) { $ignored++; continue; }
                $w = (int)$img->getAttribute('width');
                $h = (int)$img->getAttribute('height');
                if (($w > 0 && $w <= 2) || ($h > 0 && $h <= 2) || ($w > 0 && $h > 0 && $w <= 16 && $h <= 16)) { $ignored++; continue; }
                if (preg_match('#facebook\.com/tr|google-analytics\.com|googletagmanager\.com|doubleclick\.net|bat\.bing\.com|mc\.yandex|/pixel[./?]|[?&]pixel=|counter\.#i', $real)) { $ignored++; continue; }
                $abs = Crawler::absolutize($real, $purl) ?? $real;
                if (isset($seen[$abs])) continue;
                $seen[$abs] = true;
                $total++;
                if (!$img->hasAttribute('alt')) {
                    $missingPages[$purl] = ($missingPages[$purl] ?? 0) + 1;
                    if (count($missing) < 40) {
                        // для прикладів беремо насамперед контентні зображення, а не іконки шапки
                        $prio = self::imgPriority($img, $abs, $w, $h);
                        $tag = self::imgTag($pg, $img);
                        $missing[] = ['tag' => $tag, 'img' => $img, 'abs' => $abs, 'prio' => $prio, 'n' => count($missing), 'page' => $purl, 'label' => $label];
                    }
                } else {
                    $withAlt++;
                    if (trim($img->getAttribute('alt')) === '') $emptyAlt++;
                }
            }
        }
        $noAlt = $total - $withAlt;
        $data['images'] = ['checked_pages' => count($pages), 'unique' => $total, 'with_alt' => $withAlt, 'empty_alt' => $emptyAlt, 'missing_alt' => $noAlt, 'ignored' => $ignored];
        if ($total === 0) {
            $sub['images_alt'] = null;
            return;
        }
        $sub['images_alt'] = (int)round(100 * $withAlt / $total);
        if ($noAlt === 0) {
            $where = ' на ' . count($pages) . ' ' . self::plural(count($pages), 'сторінці', 'сторінках', 'сторінках');
            if ($emptyAlt >= 10 && $emptyAlt / $total >= 0.5) {
                // формально все гаразд, але порожній alt у більшості - варто перевірити вибірково
                $data['images']['note'] = 'порожній alt у більшості зображень';
                $passed[] = 'Атрибут alt є в усіх ' . $total . ' ' . self::plural($total, 'унікальному зображенні', 'унікальних зображеннях', 'унікальних зображеннях') . $where
                    . ', але в ' . $emptyAlt . ' з них він порожній (alt="") - це доречно лише для декоративних, тож варто вибірково перевірити логотип і фото послуг чи товарів';
            } else {
                $passed[] = 'Усі ' . $total . ' ' . self::plural($total, 'унікальне зображення', 'унікальні зображення', 'унікальних зображень') . $where . ' мають атрибут alt' . ($emptyAlt > 0 ? ' (з них ' . $emptyAlt . ' з порожнім alt="", що доречно лише для декоративних)' : '');
            }
            return;
        }
        $ratio = $noAlt / $total;
        $sev = ($noAlt >= 5 || $ratio >= 0.2) ? 'medium' : 'low';
        $where = [];
        foreach ($missingPages as $u => $n) $where[] = $u . ' - ' . $n;
        $ev = "Перевірено " . count($pages) . ' ' . self::plural(count($pages), 'сторінку', 'сторінки', 'сторінок') . ", {$total} " . self::plural($total, 'унікальне зображення', 'унікальні зображення', 'унікальних зображень') . " (без пікселів, іконок до 16 px і data:-заглушок): без атрибута alt - {$noAlt}, з порожнім alt=\"\" - {$emptyAlt}.\nДе саме: " . implode('; ', array_slice($where, 0, 5)) . '.';
        usort($missing, function ($a, $b) { return $b['prio'] <=> $a['prio'] ?: $a['n'] <=> $b['n']; });
        $missing = array_slice($missing, 0, 3);
        foreach ($missing as $k => $m) {
            $missing[$k]['fixed'] = self::imgTagWithAlt($m['tag'], $m['prio'] <= 1 ? self::iconAlt($m['img']) : self::altSuggestion($m['img'], $m['abs']));
        }
        $badTags = array_map(function ($m) { return $m['tag']; }, $missing);
        $goodTags = array_map(function ($m) { return $m['fixed']; }, $missing);
        $findings[] = $this->finding('img_no_alt', $sev, $noAlt . ' ' . self::plural($noAlt, 'зображення', 'зображення', 'зображень') . ' без атрибута alt', [
            'evidence' => $ev,
            'why'      => 'alt - єдиний текстовий опис зображення для пошуковиків, AI-систем і скрінрідерів. Без нього фото товарів, команди чи робіт не «читаються» і не допомагають сторінці в пошуку по картинках і мультимодальних відповідях.',
            'fix'      => 'Додайте змістовний alt до інформативних зображень (що на фото, без переспаму ключами). Для іконки в посиланні без тексту alt описує призначення посилання, для суто декоративних зображень - порожній alt="".',
            'bad'      => implode("\n", $badTags),
            'good'     => implode("\n", $goodTags),
            'code'     => implode("\n", $goodTags) . "\n\n<!-- декоративне зображення -->\n<img src=\"/images/decor-line.svg\" alt=\"\">",
            'verify'   => 'Lighthouse -> Accessibility: пункт «Image elements have [alt] attributes»; або в консолі браузера: document.querySelectorAll(\'img:not([alt])\').length має дорівнювати 0.',
            'effort'   => $noAlt > 30 ? 'M' : 'S',
        ]);
    }

    private function checkSoft404(?array $r, string $origin, string $path, array $stack, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        $url = $origin . $path;
        if (!$r || ((int)$r['status'] === 0)) {
            $sub['soft404'] = null;
            $data['soft404'] = ['url' => $url, 'status' => 0, 'error' => $r['error'] ?? 'не виконано'];
            return;
        }
        $status = (int)$r['status'];
        $hops = $r['hops'];
        $first = $hops ? (int)$hops[0]['status'] : $status;
        $chain = self::chainText($r);
        $data['soft404'] = ['url' => $url, 'status' => $status, 'chain' => $chain];
        $isChallenge = $r['body'] !== '' && Page::challengeFromHtml($r['body']) !== null;
        if (in_array($status, [404, 410], true)) {
            $sub['soft404'] = ($first >= 300 && $first < 400) ? 90 : 100;
            $passed[] = 'Неіснуюча сторінка повертає ' . $status . ($first >= 300 && $first < 400 ? ' (через редирект)' : '');
            return;
        }
        if ($isChallenge || in_array($status, [401, 403, 429], true)) {
            $sub['soft404'] = null;
            $data['soft404']['note'] = 'захист сервера відповів ' . $status . ' - висновок неможливий';
            return;
        }
        if ($status >= 500) {
            $sub['soft404'] = 40;
            $findings[] = $this->finding('error_5xx_on_404', 'low', 'На неіснуючу адресу сервер відповідає помилкою ' . $status, [
                'evidence' => "GET {$chain}.",
                'why'      => 'Помилка 5xx замість 404 виглядає для краулерів як збій сервера: вони частіше повертаються і можуть знизити частоту обходу всього сайту.',
                'fix'      => 'Обробляйте неіснуючі адреси окремим шаблоном «Сторінку не знайдено» з кодом 404.',
                'bad'      => "GET {$path}\nHTTP {$status}",
                'good'     => "GET {$path}\nHTTP 404 (сторінка «Не знайдено» з пошуком і посиланнями на розділи)",
                'code'     => "# nginx\nerror_page 404 /404.html;\nlocation = /404.html { internal; }\n\n# PHP: у шаблоні «не знайдено»\n<?php http_response_code(404); ?>",
                'verify'   => "curl -s -o /dev/null -w \"%{http_code}\\n\" {$url} - очікуємо 404.",
                'effort'   => 'S',
            ]);
            return;
        }
        if ($status >= 200 && $status < 300) {
            $finalUrl = (string)$r['final_url'];
            $redirected = $first >= 300 && $first < 400;
            // редирект лише на версію зі слешем (або без) - нормальна практика; проблема в 200 на кінцевій адресі
            $slashOnly = $redirected && count($hops) === 2 && rtrim(strtolower($finalUrl), '/') === rtrim(strtolower($url), '/');
            if ($slashOnly) $redirected = false;
            $title = self::titleOf($r['body']);
            $sub['soft404'] = $redirected ? 30 : 0;
            $ev = "GET {$chain}" . ($title !== '' ? ", title «" . self::mbTrunc($title, 90) . "»" : '') . '.';
            if ($redirected) $ev .= "\nНеіснуючу адресу перенаправлено на {$finalUrl}, яка відповідає 200.";
            if ($slashOnly) $ev .= "\nРедирект на версію адреси зі слешем - нормальна практика; проблема в тому, що неіснуюча {$finalUrl} відповідає 200.";
            $isSpa = false;
            if ($r['body'] !== '') {
                $pp = Page::fromHtml($r['body'], $finalUrl, $r['headers']);
                $isSpa = $pp->emptyAppRoot() || ($pp->wordCount() < 30 && $pp->scripts()['count'] > 0);
            }
            // стек: за головною, доповнений заголовками відповіді на неіснуючу адресу
            foreach (self::stack((array)$r['headers'], null) as $k => $v) if ($stack[$k] === '' && $v !== '') $stack[$k] = $v;
            $code = self::soft404Code($stack, $isSpa, $redirected);
            $findings[] = $this->finding('soft_404', 'medium', $redirected ? 'Неіснуючі адреси перенаправляються на сторінку з кодом 200 (soft 404)' : 'Неіснуюча сторінка відповідає 200 замість 404 (soft 404)', [
                'evidence' => $ev,
                'why'      => 'Якщо будь-яка адреса відповідає 200, пошуковики й AI-краулери індексують порожні або дубльовані сторінки, а видалені матеріали довго не випадають з індексу. Google позначає такі сторінки як soft 404 і витрачає на них бюджет обходу.',
                'fix'      => $redirected
                    ? 'Замість редиректу на ' . $finalUrl . ' віддавайте для неіснуючих адрес код 404 (або 410 для видалених назавжди) зі зручною сторінкою-підказкою.'
                    : ($slashOnly
                        ? 'Редирект на версію зі слешем можна лишити, але кінцева адреса неіснуючої сторінки має відповідати 404 (або 410 для видалених назавжди) зі зручною сторінкою-підказкою.'
                        : 'Віддавайте для неіснуючих адрес код 404 (або 410 для видалених назавжди), зберігаючи зручну сторінку «Не знайдено».'),
                'bad'      => "GET {$path}\n" . $chain,
                'good'     => "GET {$path}\nHTTP 404 (сторінка «Не знайдено» з пошуком і посиланнями на розділи)",
                'code'     => $code,
                'verify'   => "curl -sL -o /dev/null -w \"%{http_code}\\n\" {$url} (з -L - код кінцевої адреси після редиректів) - очікуємо 404. У Search Console -> Індексування сторінок -> «Soft 404».",
                'effort'   => $isSpa ? 'M' : 'S',
            ]);
            return;
        }
        $sub['soft404'] = null;
        $data['soft404']['note'] = 'нестандартна відповідь ' . $status;
    }

    private function checkSecurityHeaders(bool $blocked, bool $isHttps, array $hp, ?Page $page, array $stack, string $final, string $host, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        if ($blocked) {
            $sub['security_headers'] = null;
            $data['security_note'] = 'заголовки сторінки перевірки WAF не показові для сайту';
            return;
        }
        $H = (array)($hp['headers'] ?? []);
        $get = function (string $k) use ($H): ?string {
            if (!isset($H[$k])) return null;
            $v = trim(implode(', ', array_map('strval', (array)$H[$k])));
            return $v;
        };
        $items = []; // key => [ok(bool), label, note, invalid(bool) - заголовок є, але з помилкою]
        $notes = [];
        // HSTS
        if ($isHttps) {
            $v = $get('strict-transport-security');
            if ($v === null) $items['hsts'] = [false, 'Strict-Transport-Security', 'відсутній', false];
            elseif (!preg_match('/max-age\s*=\s*"?(\d+)/i', $v, $m)) $items['hsts'] = [false, 'Strict-Transport-Security', 'є, але з помилкою синтаксису: «' . self::mbTrunc($v, 80) . '» (потрібно max-age=31536000) - браузери його ігнорують', true];
            elseif ((int)$m[1] === 0) $items['hsts'] = [false, 'Strict-Transport-Security', 'max-age=0 вимикає HSTS', true];
            else $items['hsts'] = [true, 'Strict-Transport-Security', $v, false];
        }
        // X-Content-Type-Options
        $v = $get('x-content-type-options');
        if ($v !== null && stripos($v, 'nosniff') !== false) {
            $items['xcto'] = [true, 'X-Content-Type-Options', $v, false];
        } else {
            $items['xcto'] = [false, 'X-Content-Type-Options', $v === null ? 'відсутній' : 'некоректне значення «' . $v . '»', $v !== null];
        }
        // Referrer-Policy
        $v = $get('referrer-policy');
        $metaRef = null;
        if ($page) {
            foreach ($page->query('//meta[@name]') as $m) {
                if (strtolower(trim($m->getAttribute('name'))) === 'referrer' && trim($m->getAttribute('content')) !== '') { $metaRef = trim($m->getAttribute('content')); break; }
            }
        }
        if ($v !== null && $v !== '') $items['refpol'] = [true, 'Referrer-Policy', $v, false];
        elseif ($metaRef !== null) $items['refpol'] = [true, 'Referrer-Policy', 'задано через <meta name="referrer" content="' . self::mbTrunc($metaRef, 60) . '"> (для HTML-сторінок цього достатньо)', false];
        else $items['refpol'] = [false, 'Referrer-Policy', 'відсутній', false];
        // фрейми: XFO або CSP frame-ancestors
        $xfo = $get('x-frame-options');
        $csp = $get('content-security-policy');
        $fa = $csp !== null && stripos($csp, 'frame-ancestors') !== false;
        $xfoOk = $xfo !== null && preg_match('/\b(deny|sameorigin)\b/i', $xfo);
        $xfoConflict = $xfo !== null && preg_match('/deny/i', $xfo) && preg_match('/sameorigin/i', $xfo);
        if ($xfoOk || $fa) {
            $items['frame'] = [true, 'X-Frame-Options / CSP frame-ancestors', $fa ? 'CSP frame-ancestors' . ($xfo !== null ? ', X-Frame-Options: ' . $xfo : '') : 'X-Frame-Options: ' . $xfo, false];
            if ($xfoConflict) $notes[] = 'X-Frame-Options має два конфліктні значення («' . $xfo . '») - браузери в такому разі повністю забороняють вбудовування, як з DENY; залиште одне значення';
        } elseif ($xfo !== null) {
            $items['frame'] = [false, 'X-Frame-Options / CSP frame-ancestors', 'X-Frame-Options: «' . self::mbTrunc($xfo, 60) . '» - застаріле або некоректне значення (ALLOW-FROM браузери ігнорують), CSP frame-ancestors немає', true];
        } else {
            $items['frame'] = [false, 'X-Frame-Options / CSP frame-ancestors', $csp !== null ? 'CSP є («' . self::mbTrunc($csp, 60) . '»), але без frame-ancestors; X-Frame-Options відсутній' : 'відсутні', false];
        }
        $ok = array_filter($items, function ($i) { return $i[0]; });
        $miss = array_filter($items, function ($i) { return !$i[0]; });
        $score = (int)round(100 * count($ok) / max(1, count($items)));
        $data['security_headers'] = array_map(function ($i) { return $i[0]; }, $items);

        // розкриття версій ПЗ
        $disc = [];
        $server = $get('server');
        if ($server !== null && preg_match('#/\s*\d+(\.\d+)*#', $server)) $disc['Server'] = $server;
        foreach (['x-powered-by' => 'X-Powered-By', 'x-aspnet-version' => 'X-AspNet-Version', 'x-aspnetmvc-version' => 'X-AspNetMvc-Version', 'x-generator' => 'X-Generator'] as $k => $lbl) {
            $vv = $get($k);
            if ($vv !== null && $vv !== '') $disc[$lbl] = $vv;
        }

        if ($miss) {
            $absent = array_filter($miss, function ($i) { return !$i[3]; });
            $invalid = array_filter($miss, function ($i) { return $i[3]; });
            $lines = [];
            foreach ($items as $i) $lines[] = ($i[0] ? '+ ' : '- ') . $i[1] . ': ' . $i[2];
            $vl = self::httpVersionLabel((int)($hp['http_version'] ?? 0));
            $statusLine = ($vl === 'невідомо' ? 'HTTP/1.1' : $vl) . ' ' . (int)($hp['status'] ?? 200);
            $badHdr = [$statusLine];
            foreach (['server', 'content-type', 'strict-transport-security', 'x-content-type-options', 'referrer-policy', 'x-frame-options', 'content-security-policy'] as $k) {
                $vv = $get($k);
                if ($vv !== null) $badHdr[] = $k . ': ' . self::mbTrunc($vv, 90);
            }
            // «як треба»: наявні коректні заголовки лишаються як є, відсутні й помилкові - виправлені
            $goodHdr = [$statusLine];
            foreach (['server', 'content-type'] as $k) { $vv = $get($k); if ($vv !== null) $goodHdr[] = $k . ': ' . self::mbTrunc($vv, 90); }
            if ($isHttps) $goodHdr[] = 'strict-transport-security: ' . (isset($ok['hsts']) ? self::mbTrunc((string)$get('strict-transport-security'), 90) : 'max-age=31536000; includeSubDomains');
            $goodHdr[] = 'x-content-type-options: nosniff';
            $refHdr = $get('referrer-policy');
            if ($refHdr !== null && $refHdr !== '') $goodHdr[] = 'referrer-policy: ' . self::mbTrunc($refHdr, 90);
            elseif (!isset($ok['refpol'])) $goodHdr[] = 'referrer-policy: strict-origin-when-cross-origin';
            // (Referrer-Policy, заданий через <meta name="referrer">, лишаємо як є)
            if (isset($ok['frame'])) {
                if ($xfo !== null) $goodHdr[] = 'x-frame-options: ' . ($xfoConflict ? 'SAMEORIGIN' : $xfo);
                if ($fa) $goodHdr[] = 'content-security-policy: ' . self::mbTrunc((string)$csp, 90);
            } else {
                $goodHdr[] = 'x-frame-options: SAMEORIGIN';
            }
            $set = [];
            if (isset($miss['hsts'])) $set['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
            if (isset($miss['xcto'])) $set['X-Content-Type-Options'] = 'nosniff';
            if (isset($miss['refpol'])) $set['Referrer-Policy'] = 'strict-origin-when-cross-origin';
            if (isset($miss['frame'])) $set['X-Frame-Options'] = 'SAMEORIGIN';
            $xfoHedge = isset($set['X-Frame-Options'])
                ? 'X-Frame-Options: SAMEORIGIN - лише якщо сайт не має вбудовуватися в iframe на інших доменах; інакше замість нього Content-Security-Policy: frame-ancestors \'self\' з переліком дозволених доменів.'
                : '';
            $code = self::headersCode($set, $stack, $host, (bool)$invalid, $xfoHedge);
            $ev = implode("\n", $lines);
            if ($notes) $ev .= "\n" . implode("\n", $notes);
            $names = function (array $a): string { return implode(', ', array_map(function ($i) { return $i[1]; }, $a)); };
            if ($absent && $invalid) $title = 'Заголовки безпеки: бракує ' . $names($absent) . ', з помилкою ' . $names($invalid);
            elseif ($invalid) $title = 'Заголовки безпеки з помилкою: ' . $names($invalid);
            else $title = 'Бракує заголовків безпеки: ' . $names($absent);
            $fix = ($absent && $invalid) ? 'Додайте відсутні й виправте помилкові заголовки на рівні веб-сервера або CDN для всіх відповідей.'
                : ($invalid ? 'Виправте значення заголовків на рівні веб-сервера або CDN - у нинішньому вигляді браузери їх ігнорують.'
                    : 'Додайте відсутні заголовки на рівні веб-сервера або CDN для всіх відповідей.');
            if ($xfoConflict) $fix .= ' X-Frame-Options задано двічі з різними значеннями - залиште одне (SAMEORIGIN).';
            if ($xfoHedge !== '') $fix .= ' ' . $xfoHedge;
            $findings[] = $this->finding('security_headers', 'low', $title, [
                'evidence' => $ev,
                'why'      => 'На AI-видимість ці заголовки прямо не впливають, але це базова гігієна: захист від підміни типу контенту, клікджекінгу і витоку адрес. Їх перевіряють сканери безпеки та сервіси оцінки довіри до сайту.',
                'fix'      => $fix,
                'bad'      => implode("\n", $badHdr),
                'good'     => implode("\n", $goodHdr),
                'code'     => $code,
                'verify'   => "curl -sI {$final} | grep -iE \"strict-transport|x-content-type|referrer-policy|x-frame|content-security\" або https://securityheaders.com/?q={$host}",
                'effort'   => 'S',
            ]);
        } else {
            $passed[] = 'Заголовки безпеки на місці: ' . implode(', ', array_map(function ($i) { return $i[1]; }, $items));
        }

        if ($disc) {
            $score = max(0, $score - 10);
            $lines = [];
            foreach ($disc as $k => $v) $lines[] = $k . ': ' . $v;
            $eol = '';
            foreach ($disc as $v) {
                if (preg_match('#PHP/(\d+)\.(\d+)#i', $v, $pm)) {
                    $ver = (int)$pm[1] * 100 + (int)$pm[2];
                    if ($ver < 802) $eol = "\nPHP " . $pm[1] . '.' . $pm[2] . ' уже не отримує оновлень безпеки - варто оновити до підтримуваної версії (8.2+).';
                }
            }
            $ng = [];
            $ap = [];
            if (isset($disc['Server'])) { $ng[] = 'server_tokens off;'; $ap[] = 'ServerTokens Prod' . "\n" . 'ServerSignature Off'; }
            if (isset($disc['X-Powered-By'])) { $ng[] = 'fastcgi_hide_header X-Powered-By;'; $ng[] = 'proxy_hide_header X-Powered-By;'; $ap[] = 'Header unset X-Powered-By'; }
            if (isset($disc['X-Generator'])) { $ng[] = 'fastcgi_hide_header X-Generator;'; $ap[] = 'Header unset X-Generator'; }
            if (isset($disc['X-AspNet-Version']) || isset($disc['X-AspNetMvc-Version'])) { $ap[] = '# ASP.NET: <httpRuntime enableVersionHeader="false" /> у web.config'; }
            $xpb = strtolower((string)($disc['X-Powered-By'] ?? ''));
            $code = '';
            if (strpos($xpb, 'express') !== false) $code .= "// Express (Node.js)\napp.disable('x-powered-by');\n\n";
            if (strpos($xpb, 'next.js') !== false) $code .= "// next.config.js\nmodule.exports = { poweredByHeader: false };\n\n";
            if (strpos($xpb, 'asp.net') !== false) $code .= "<!-- web.config -->\n<system.webServer>\n  <httpProtocol>\n    <customHeaders>\n      <remove name=\"X-Powered-By\" />\n    </customHeaders>\n  </httpProtocol>\n</system.webServer>\n\n";
            // приклади лише для виявленого веб-сервера (якщо його видно із заголовка Server)
            if ($ng && !in_array($stack['server'], ['apache', 'litespeed', 'iis'], true)) $code .= "# nginx (http { ... })\n" . implode("\n", $ng) . "\n\n";
            if ($ap && !in_array($stack['server'], ['nginx', 'iis'], true)) $code .= "# Apache (httpd.conf / .htaccess)\n" . implode("\n", $ap) . "\n\n";
            if (strpos($xpb, 'php') !== false) $code .= "# PHP (php.ini)\nexpose_php = Off";
            $hasVer = false;
            foreach ($disc as $v) if (preg_match('#\d+\.\d+#', $v)) $hasVer = true;
            $findings[] = $this->finding('version_disclosure', 'low', $hasVer ? 'Сервер розкриває версії програмного забезпечення' : 'Заголовки розкривають серверну платформу (' . implode(', ', array_keys($disc)) . ')', [
                'evidence' => 'Заголовки відповіді головної: ' . implode('; ', $lines) . '.' . $eol,
                'why'      => $hasVer
                    ? 'На AI-видимість не впливає, але точні версії ПЗ полегшують автоматизований пошук відомих вразливостей. Злам і спам-вставки на сайті вже напряму шкодять довірі пошуковиків і AI.'
                    : 'На AI-видимість не впливає. Назва платформи без версії сама по собі не вразливість, але підказує сканерам, які атаки пробувати; прибрати заголовок - справа кількох хвилин.',
                'fix'      => $hasVer
                    ? 'Приховайте версії в заголовках ' . implode(' і ', array_keys($disc)) . ' (і оновіть ПЗ, якщо версія застаріла).'
                    : 'Приберіть заголовок ' . implode(', ', array_keys($disc)) . ' у налаштуваннях застосунку або веб-сервера.',
                'bad'      => implode("\n", $lines),
                'good'     => isset($disc['Server']) ? 'server: ' . preg_replace('#/.*$#', '', $disc['Server']) . "\n(без X-Powered-By)" : '(заголовка X-Powered-By немає)',
                'code'     => trim($code),
                'verify'   => "curl -sI {$final} | grep -iE \"^server|x-powered-by|x-generator\" - " . ($hasVer ? 'версій бути не повинно.' : 'заголовка ' . implode(', ', array_keys($disc)) . ' бути не повинно.'),
                'effort'   => 'S',
            ]);
        }
        $sub['security_headers'] = $score;
    }

    // ================================================================== PageSpeed Insights

    private function psiRequest(string $final, array &$info, bool $blocked = false): ?array
    {
        if (strtolower((string)getenv('GEO_AUDIT_PSI')) === 'off') { $info = ['status' => 'disabled', 'note' => 'вимкнено (GEO_AUDIT_PSI=off)']; return null; }
        [$keyName, $key] = self::psiKey();
        // 1) CrUX API: польові дані за ~0,1 с (якщо для ключа увімкнено Chrome UX Report API)
        $crux = $key !== '' ? self::cruxQuery($final, $key) : ['status' => 'no_key'];
        if (($crux['status'] ?? '') === 'ok') { $info = $crux + ['done' => true, 'keyed' => true, 'key_name' => $keyName]; return null; }
        // головна віддає сторінку захисту від ботів: Lighthouse у PageSpeed, найімовірніше, теж її отримає і впаде
        // за тайм-аутом (на таких сайтах PageSpeed не відповідав 35-45 с), тому PageSpeed не чекаємо
        if ($blocked) {
            $info = ['status' => 'skipped', 'note' => 'головна заблокована захистом від ботів - PageSpeed Insights не викликано (Lighthouse, ймовірно, теж отримав би сторінку перевірки)', 'crux_api' => $crux['status'] ?? null];
            return null;
        }
        // 2) PageSpeed Insights (польові + лабораторні дані, 10-35 с)
        $info = ['status' => 'pending', 'keyed' => $key !== '', 'crux_api' => $crux['status'] ?? null];
        if ($keyName !== '') $info['key_name'] = $keyName; // лише назва змінної, не значення
        $u = self::PSI_ENDPOINT . '?url=' . rawurlencode($final) . '&strategy=mobile&category=performance' . ($key !== '' ? '&key=' . rawurlencode($key) : '');
        $cacheDir = (string)getenv('GEO_AUDIT_PSI_CACHE');
        if ($cacheDir !== '' && is_dir($cacheDir)) {
            $cf = rtrim($cacheDir, '/') . '/psi_' . md5($final) . '.json';
            if (is_file($cf) && filemtime($cf) > time() - 21600) {
                $info['cached'] = true;
                $info['cache_file'] = $cf;
                return null;
            }
            $info['cache_file'] = $cf;
        }
        return ['url' => $u, 'ua' => 'GeoAudit/2.0 (PageSpeed client)', 'headers' => ['Accept: application/json'], 'timeout' => self::PSI_TIMEOUT,
            'max_bytes' => 12582912, 'verify' => true, 'psi' => true];
    }

    private function psiParse(?array $r, string $final, array $info): array
    {
        if (!empty($info['done'])) { unset($info['done']); return $info; }
        $body = null; $ms = null;
        if (!empty($info['cached']) && !empty($info['cache_file'])) {
            $body = (string)@file_get_contents($info['cache_file']);
            $status = 200;
        } elseif ($r !== null) {
            $status = (int)$r['status'];
            $ms = (int)round($r['t']['total'] * 1000);
            $body = $r['body'];
            if ($status === 0) {
                return ['status' => ((int)$r['errno'] === 28) ? 'timeout' : 'error', 'error' => self::mbTrunc((string)$r['error'], 160), 'ms' => $ms];
            }
            if ($status === 200 && !empty($info['cache_file']) && $body !== '') @file_put_contents($info['cache_file'], $body);
        } else {
            return $info['status'] === 'pending' ? ['status' => 'error', 'error' => 'немає відповіді'] : $info;
        }
        $d = json_decode((string)$body, true);
        if ($status !== 200 || !is_array($d)) {
            return ['status' => $status === 429 ? 'quota' : 'error', 'http' => $status, 'error' => self::psiErrorText(['status' => $status, 'body' => (string)$body]), 'ms' => $ms];
        }
        $out = ['status' => 'ok', 'ms' => $ms, 'metrics' => [], 'source' => null];
        $map = [
            'lcp'  => ['LARGEST_CONTENTFUL_PAINT_MS', 2500, 4000, 1],
            'inp'  => ['INTERACTION_TO_NEXT_PAINT', 200, 500, 1],
            'cls'  => ['CUMULATIVE_LAYOUT_SHIFT_SCORE', 0.1, 0.25, 100],
            'ttfb' => ['EXPERIMENTAL_TIME_TO_FIRST_BYTE', 800, 1800, 1],
        ];
        $catMap = ['FAST' => 'good', 'AVERAGE' => 'ni', 'SLOW' => 'poor'];
        foreach (['loadingExperience' => 'field-url', 'originLoadingExperience' => 'field-origin'] as $blk => $src) {
            $le = $d[$blk] ?? null;
            if (!is_array($le) || empty($le['metrics']) || !isset($le['metrics']['LARGEST_CONTENTFUL_PAINT_MS'])) continue;
            if ($blk === 'loadingExperience' && !empty($le['origin_fallback'])) $src = 'field-origin';
            foreach ($map as $k => [$name, $good, $poor, $div]) {
                if (!isset($le['metrics'][$name]['percentile'])) continue;
                $val = (float)$le['metrics'][$name]['percentile'] / $div;
                $cat = $catMap[$le['metrics'][$name]['category'] ?? ''] ?? ($val <= $good ? 'good' : ($val <= $poor ? 'ni' : 'poor'));
                $out['metrics'][$k] = ['value' => $val, 'cat' => $cat];
            }
            $out['source'] = $src;
            if (!empty($le['overall_category'])) $out['overall'] = (string)$le['overall_category'];
            break;
        }
        $lh = $d['lighthouseResult'] ?? null;
        $lab = [];
        if (is_array($lh) && empty($lh['runtimeError']['code'])) {
            $a = $lh['audits'] ?? [];
            if (isset($lh['categories']['performance']['score'])) $lab['perf'] = (int)round((float)$lh['categories']['performance']['score'] * 100);
            if (isset($a['largest-contentful-paint']['numericValue'])) $lab['lcp'] = (int)round((float)$a['largest-contentful-paint']['numericValue']);
            if (isset($a['cumulative-layout-shift']['numericValue'])) $lab['cls'] = round((float)$a['cumulative-layout-shift']['numericValue'], 3);
            if (isset($a['total-blocking-time']['numericValue'])) $lab['tbt'] = (int)round((float)$a['total-blocking-time']['numericValue']);
            // LCP-елемент: Lighthouse 12+ (lcp-breakdown-insight) або старий аудит largest-contentful-paint-element
            $el = null; $sel = null; $phases = [];
            foreach ((array)($a['lcp-breakdown-insight']['details']['items'] ?? []) as $it) {
                if (!is_array($it)) continue;
                if (($it['type'] ?? '') === 'node') { $el = $it['snippet'] ?? null; $sel = $it['selector'] ?? null; }
                if (($it['type'] ?? '') === 'table') {
                    foreach ((array)($it['items'] ?? []) as $row) {
                        if (isset($row['subpart'], $row['duration'])) $phases[(string)$row['subpart']] = (int)round((float)$row['duration']);
                    }
                }
            }
            if ($el === null) $el = $a['largest-contentful-paint-element']['details']['items'][0]['items'][0]['node']['snippet'] ?? ($a['largest-contentful-paint-element']['details']['items'][0]['node']['snippet'] ?? null);
            if (is_string($el) && $el !== '') {
                $out['lcp_element'] = $el . (is_string($sel) && $sel !== '' ? ' (' . $sel . ')' : '');
                $out['lcp_snippet'] = $el;
            }
            if ($phases) $out['lcp_phases_unthrottled_ms'] = $phases; // лише для довідки в data, у текстах не використовується
        } elseif (is_array($lh) && !empty($lh['runtimeError']['code'])) {
            $out['lab_error'] = (string)$lh['runtimeError']['code'];
        }
        if ($lab) $out['lab'] = $lab;
        if ($out['source'] === null) {
            if (isset($lab['lcp'])) {
                $out['source'] = 'lab';
                $out['metrics']['lcp'] = ['value' => $lab['lcp'], 'cat' => $lab['lcp'] <= 2500 ? 'good' : ($lab['lcp'] <= 4000 ? 'ni' : 'poor')];
                if (isset($lab['cls'])) $out['metrics']['cls'] = ['value' => $lab['cls'], 'cat' => $lab['cls'] <= 0.1 ? 'good' : ($lab['cls'] <= 0.25 ? 'ni' : 'poor')];
                if (isset($lab['tbt'])) $out['metrics']['tbt'] = ['value' => $lab['tbt'], 'cat' => $lab['tbt'] <= 200 ? 'good' : ($lab['tbt'] <= 600 ? 'ni' : 'poor')];
            } else {
                return ['status' => 'no_data', 'ms' => $ms, 'lab_error' => $out['lab_error'] ?? null];
            }
        }
        return $out;
    }

    /** Chrome UX Report API: польові p75 для URL, інакше для origin. */
    private static function cruxQuery(string $final, string $key): array
    {
        $t0 = microtime(true);
        $origin = Crawler::baseUrl($final);
        foreach ([['url' => $final, 'src' => 'field-url'], ['origin' => $origin, 'src' => 'field-origin']] as $q) {
            $src = $q['src'];
            unset($q['src']);
            $q['formFactor'] = 'PHONE';
            $ch = curl_init(self::CRUX_ENDPOINT . '?key=' . rawurlencode($key));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => (string)json_encode($q), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
            $b = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 404) continue; // для URL даних немає - пробуємо origin
            if ($code !== 200) return ['status' => $code === 403 ? 'api_disabled' : 'error_' . $code];
            $d = json_decode((string)$b, true);
            $m = $d['record']['metrics'] ?? null;
            if (!is_array($m) || !isset($m['largest_contentful_paint']['percentiles']['p75'])) continue;
            $map = ['lcp' => ['largest_contentful_paint', 2500, 4000], 'inp' => ['interaction_to_next_paint', 200, 500],
                'cls' => ['cumulative_layout_shift', 0.1, 0.25], 'ttfb' => ['experimental_time_to_first_byte', 800, 1800]];
            $out = ['status' => 'ok', 'source' => $src, 'via' => 'crux_api', 'metrics' => [], 'ms' => (int)round((microtime(true) - $t0) * 1000)];
            foreach ($map as $k => [$name, $good, $poor]) {
                if (!isset($m[$name]['percentiles']['p75'])) continue;
                $v = (float)$m[$name]['percentiles']['p75'];
                $out['metrics'][$k] = ['value' => $v, 'cat' => $v <= $good ? 'good' : ($v <= $poor ? 'ni' : 'poor')];
            }
            return $out;
        }
        return ['status' => 'no_data'];
    }

    /** Текст помилки PageSpeed API без значення ключа. */
    private static function psiErrorText(array $r): string
    {
        $d = json_decode((string)($r['body'] ?? ''), true);
        $msg = is_array($d) && isset($d['error']['message']) ? (string)$d['error']['message'] : 'HTTP ' . (int)($r['status'] ?? 0);
        $msg = (string)preg_replace('/key=[A-Za-z0-9_\-]+|AIza[0-9A-Za-z_\-]{20,}/', '***', $msg);
        return self::mbTrunc($msg, 200);
    }

    /** [назва змінної, значення] Google API-ключа для PageSpeed/CrUX або ['', '']. */
    private static function psiKey(): array
    {
        foreach (['PAGESPEED_API_KEY', 'GOOGLE_PAGESPEED_API_KEY', 'PSI_API_KEY', 'GOOGLE_API_KEY'] as $k) {
            $v = getenv($k);
            if (is_string($v) && trim($v) !== '') return [$k, trim($v)];
        }
        return ['', ''];
    }

    private static function metricText(string $k, array $m): string
    {
        $cat = ['good' => 'добре', 'ni' => 'потребує покращення', 'poor' => 'погано'][$m['cat']] ?? $m['cat'];
        $v = (float)$m['value'];
        switch ($k) {
            case 'lcp': return 'LCP ' . self::fmtSec($v) . ' (' . $cat . ($m['cat'] !== 'good' ? ', норма до 2,5 с' : '') . ')';
            case 'inp': return 'INP ' . self::fmtMs((int)round($v)) . ' (' . $cat . ($m['cat'] !== 'good' ? ', норма до 200 мс' : '') . ')';
            case 'cls': return 'CLS ' . str_replace('.', ',', number_format($v, 2, '.', '')) . ' (' . $cat . ($m['cat'] !== 'good' ? ', норма до 0,1' : '') . ')';
            case 'tbt': return 'TBT ' . self::fmtMs((int)round($v)) . ' (' . $cat . ($m['cat'] !== 'good' ? ', норма до 200 мс' : '') . ')';
            case 'ttfb': return 'TTFB ' . self::fmtMs((int)round($v));
        }
        return $k;
    }

    // ================================================================== network

    /**
     * Паралельні запити з записом ланцюжка редиректів.
     * $reqs: key => [url, ua, headers, follow(true), verify(false), encoding(''), max_bytes, timeout]
     * Результат: key => [status, final_url, hops[[status,location,url,proto]], headers(останньої відповіді), body,
     *                     errno, error, http_version, content_encoding, ssl_verify_result, t[...]]
     */
    private static function netBatch(array $reqs): array
    {
        if (!$reqs) return [];
        $mh = curl_multi_init();
        $hs = [];
        $st = [];
        foreach ($reqs as $k => $r) {
            $st[$k] = ['hops' => [], 'headers' => [], 'body' => '', 'trunc' => false];
            $max = (int)($r['max_bytes'] ?? 262144);
            $verify = !empty($r['verify']);
            $ch = curl_init();
            $opts = [
                CURLOPT_URL            => (string)$r['url'],
                CURLOPT_FOLLOWLOCATION => $r['follow'] ?? true,
                CURLOPT_MAXREDIRS      => 8,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => (int)($r['timeout'] ?? self::NET_TIMEOUT),
                CURLOPT_USERAGENT      => (string)($r['ua'] ?? Crawler::UA),
                CURLOPT_HTTPHEADER     => (array)($r['headers'] ?? []),
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                CURLOPT_ENCODING       => (string)($r['encoding'] ?? ''),
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$st, $k) {
                    $len = strlen($line);
                    $t = trim($line);
                    if ($t === '') return $len;
                    if (preg_match('#^HTTP/(\S+)\s+(\d{3})#', $t, $m)) {
                        if ((int)$m[2] === 100) return $len;
                        $st[$k]['hops'][] = ['status' => (int)$m[2], 'proto' => 'HTTP/' . $m[1], 'location' => null];
                        $st[$k]['headers'] = [];
                        return $len;
                    }
                    $p = explode(':', $line, 2);
                    if (count($p) === 2) {
                        $name = strtolower(trim($p[0]));
                        $val = trim($p[1]);
                        $st[$k]['headers'][$name][] = $val;
                        if ($name === 'location' && $st[$k]['hops']) $st[$k]['hops'][count($st[$k]['hops']) - 1]['location'] = $val;
                    }
                    return $len;
                },
                CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$st, $k, $max) {
                    $st[$k]['body'] .= $chunk;
                    if (strlen($st[$k]['body']) >= $max) { $st[$k]['trunc'] = true; return 0; }
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        $results = [];
        do {
            $s = curl_multi_exec($mh, $running);
            while ($inf = curl_multi_info_read($mh)) {
                foreach ($hs as $k => $h) {
                    if ($h === $inf['handle']) { $results[$k] = (int)$inf['result']; break; }
                }
            }
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running && $s === CURLM_OK);
        while ($inf = curl_multi_info_read($mh)) {
            foreach ($hs as $k => $h) {
                if ($h === $inf['handle']) { $results[$k] = (int)$inf['result']; break; }
            }
        }

        $out = [];
        foreach ($hs as $k => $ch) {
            $info = curl_getinfo($ch);
            $errno = $results[$k] ?? curl_errno($ch);
            $error = $errno ? (curl_error($ch) ?: (function_exists('curl_strerror') ? (string)curl_strerror($errno) : 'curl error ' . $errno)) : '';
            if ($errno === 23 && $st[$k]['trunc']) { $errno = 0; $error = ''; }
            $hops = $st[$k]['hops'];
            // URL кожного кроку
            $prevUrl = (string)$reqs[$k]['url'];
            foreach ($hops as $i => $hop) {
                $hops[$i]['url'] = $prevUrl;
                if ($hop['location'] !== null) {
                    $prevUrl = Crawler::absolutize($hop['location'], $prevUrl, false) ?? $hop['location'];
                }
            }
            $status = (int)($info['http_code'] ?? 0);
            $ce = isset($st[$k]['headers']['content-encoding']) ? strtolower((string)end($st[$k]['headers']['content-encoding'])) : null;
            $out[$k] = [
                'url'               => (string)$reqs[$k]['url'],
                'status'            => $status,
                'final_url'         => (string)($info['url'] ?? $reqs[$k]['url']),
                'hops'              => $hops,
                'headers'           => $st[$k]['headers'],
                'body'              => $st[$k]['body'],
                'truncated'         => $st[$k]['trunc'],
                'errno'             => (int)$errno,
                'error'             => (string)$error,
                'http_version'      => (int)($info['http_version'] ?? 0),
                'content_encoding'  => $ce,
                'ssl_verify_result' => (int)($info['ssl_verify_result'] ?? 0),
                't'                 => [
                    'dns'           => (float)($info['namelookup_time'] ?? 0),
                    'connect'       => (float)($info['connect_time'] ?? 0),
                    'tls'           => (float)($info['appconnect_time'] ?? 0),
                    'pretransfer'   => (float)($info['pretransfer_time'] ?? 0),
                    'starttransfer' => (float)($info['starttransfer_time'] ?? 0),
                    'total'         => (float)($info['total_time'] ?? 0),
                ],
            ];
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    // ================================================================== helpers

    /** Сторінка перевірки «чи ви не робот», яку Page::challenge() не розпізнав (кастомні WAF, HTTP 202). Дубль ContentRenderCheck::isBotWall. */
    private static function isBotWall(Page $p, array $res = []): bool
    {
        if ($p->challenge() !== null) return true;
        $words = $p->wordCount();
        if ($words >= 150) return false;
        $nos = '';
        foreach ($p->query('//noscript') as $n) $nos .= ' ' . $n->textContent;
        $txt = mb_strtolower($p->title() . ' ' . $p->visibleText() . ' ' . $nos);
        if (preg_match('/not a robot|are you a robot|verify (that )?you are (a )?human|verify you.re human|checking your browser|checking if the site connection is secure|ви не робот|вы не робот|підтвердіть, що ви людина|подтвердите, что вы не робот|перевірка браузера|проверка браузера/u', $txt)) return true;
        if ($words < 80 && preg_match('/id=["\']challenge-(container|form|running|stage)["\']|captcha-delivery\.com|\/cdn-cgi\/challenge-platform|_Incapsula_Resource|check\.ddos-guard/i', $p->html())) return true;
        if ((int)($res['status'] ?? 200) === 202 && $words < 30) return true;
        return false;
    }

    /**
     * Платформа за заголовками і маркерами HTML - щоб приклади конфігурації пасували до стеку сайту.
     * ['host' => vercel|netlify|cloudfront|cloudflare|'', 'app' => next|nuxt|express|php|aspnet|'', 'server' => nginx|apache|litespeed|iis|'']
     */
    private static function stack(array $headers, ?Page $page): array
    {
        $h = function (string $k) use ($headers): string { return strtolower(implode(' ', array_map('strval', (array)($headers[$k] ?? [])))); };
        $server = $h('server');
        $xpb = $h('x-powered-by');
        $host = '';
        if (strpos($server, 'vercel') !== false || isset($headers['x-vercel-id']) || isset($headers['x-vercel-cache'])) $host = 'vercel';
        elseif (strpos($server, 'netlify') !== false || isset($headers['x-nf-request-id'])) $host = 'netlify';
        elseif (strpos($server, 'amazons3') !== false || strpos($server, 'cloudfront') !== false || isset($headers['x-amz-cf-id'])) $host = 'cloudfront';
        elseif (strpos($server, 'cloudflare') !== false || isset($headers['cf-ray'])) $host = 'cloudflare';
        $app = '';
        if (strpos($xpb, 'next.js') !== false) $app = 'next';
        elseif (strpos($xpb, 'nuxt') !== false) $app = 'nuxt';
        elseif (strpos($xpb, 'express') !== false) $app = 'express';
        elseif (strpos($xpb, 'php') !== false || strpos($h('set-cookie'), 'phpsessid') !== false) $app = 'php';
        elseif (strpos($xpb, 'asp.net') !== false || isset($headers['x-aspnet-version'])) $app = 'aspnet';
        if ($app === '' && $page) {
            $fw = $page->frameworkMarkers();
            if (in_array('next', $fw, true)) $app = 'next';
            elseif (in_array('nuxt', $fw, true)) $app = 'nuxt';
            elseif (preg_match('#/wp-content/|/wp-includes/|index\.php\?route=|/components/com_|catalog/view/theme/|/bitrix/(js|templates)/#i', substr($page->html(), 0, 300000))) $app = 'php';
        }
        $srv = '';
        if (strpos($server, 'nginx') !== false) $srv = 'nginx';
        elseif (strpos($server, 'apache') !== false) $srv = 'apache';
        elseif (strpos($server, 'litespeed') !== false) $srv = 'litespeed';
        elseif (strpos($server, 'microsoft-iis') !== false) $srv = 'iis';
        return ['host' => $host, 'app' => $app, 'server' => $srv];
    }

    /** Готовий фрагмент для заголовків безпеки під платформу сайту. $set: [назва => значення] лише для відсутніх/помилкових. */
    private static function headersCode(array $set, array $stack, string $host, bool $hasInvalid, string $xfoHedge): string
    {
        if (!$set) return '';
        $hedge = $xfoHedge !== '' ? "\n# " . $xfoHedge : '';
        switch ($stack['host']) {
            case 'vercel':
                $hs = [];
                foreach ($set as $k => $v) $hs[] = ['key' => $k, 'value' => $v];
                return "// vercel.json у корені проєкту (сайт віддається з Vercel)" . str_replace("\n# ", "\n// ", $hedge) . "\n" . self::jsonPretty(['headers' => [['source' => '/(.*)', 'headers' => $hs]]]);
            case 'netlify':
                $lines = [];
                foreach ($set as $k => $v) $lines[] = '  ' . $k . ': ' . $v;
                return "# Netlify: файл _headers у папці публікації" . $hedge . "\n/*\n" . implode("\n", $lines);
            case 'cloudfront':
                $lines = [];
                foreach ($set as $k => $v) $lines[] = '#   ' . $k . ': ' . $v;
                return "# Amazon CloudFront: Policies -> Response headers -> Create response headers policy\n# (або керована політика Managed-SecurityHeadersPolicy) і підключіть її до distribution:\n" . implode("\n", $lines) . $hedge;
        }
        if ($stack['app'] === 'express') {
            $lines = [];
            foreach ($set as $k => $v) $lines[] = "  res.setHeader('" . $k . "', '" . $v . "');";
            return "// Express: middleware перед усіма маршрутами" . str_replace("\n# ", "\n// ", $hedge) . "\napp.use((req, res, next) => {\n" . implode("\n", $lines) . "\n  next();\n});\n// або пакет helmet: app.use(helmet({ contentSecurityPolicy: false }))";
        }
        $ng = []; $ap = [];
        foreach ($set as $k => $v) {
            $ng[] = 'add_header ' . $k . ' "' . $v . '" always;';
            $ap[] = 'Header always set ' . $k . ' "' . $v . '"';
        }
        $code = '';
        if ($stack['host'] === 'cloudflare') {
            $code .= "# Cloudflare: Rules -> Transform Rules -> Modify Response Header -> Set static\n# (" . implode(', ', array_keys($set)) . "); або на сервері-джерелі:\n\n";
        }
        $code .= "# nginx (server { ... } для {$host})" . ($hasInvalid ? "\n# спершу приберіть наявні помилкові рядки add_header для цих заголовків" : '') . $hedge . "\n" . implode("\n", $ng)
            . "\n\n# Apache (.htaccess, потрібен mod_headers; Header always set замінює наявне значення)\n<IfModule mod_headers.c>\n  " . implode("\n  ", $ap) . "\n</IfModule>";
        if (isset($set['Strict-Transport-Security']) && $stack['host'] === 'cloudflare') $code .= "\n\n# HSTS у Cloudflare: SSL/TLS -> Edge Certificates -> HTTP Strict Transport Security (HSTS)";
        return $code;
    }

    /** Приклад налаштування справжнього 404 під стек сайту. */
    private static function soft404Code(array $stack, bool $isSpa, bool $redirected): string
    {
        $tail = $redirected ? "\n\n# Не перенаправляйте всі невідомі адреси на головну - віддавайте 404 зі сторінкою-підказкою." : '';
        switch ($stack['app']) {
            case 'next':
                return "// Next.js (App Router): для невідомого запису - notFound(), а не redirect('/')\nimport { notFound } from 'next/navigation';\n\nexport default async function Page({ params }) {\n  const item = await getItem(params.slug);\n  if (!item) notFound(); // код 404 і сторінка app/not-found.tsx\n  // ...\n}\n\n// Pages Router: return { notFound: true } у getServerSideProps / getStaticProps";
            case 'nuxt':
                return "// Nuxt 3: у сторінці, якщо даних немає\nif (!data.value) {\n  throw createError({ statusCode: 404, statusMessage: 'Сторінку не знайдено', fatal: true });\n}\n// error.vue показує «Не знайдено», а сервер віддає код 404";
            case 'express':
                return "// Express: обробник 404 - останнім, після всіх маршрутів\napp.use((req, res) => {\n  res.status(404).render('404'); // або res.status(404).send('Сторінку не знайдено')\n});\n\n// Якщо HTML рендерить Nuxt/Next поверх Express - віддавайте 404 і з рендерера\n// (Nuxt: throw createError({ statusCode: 404 }), Next.js: notFound())" . $tail;
        }
        // невідомі адреси перенаправляються на головну, а застосунок не визначено - вказуємо, де шукати правило редиректу
        if ($redirected && $stack['app'] === '' && in_array($stack['host'], ['vercel', 'netlify', 'cloudfront'], true)) {
            $where = [
                'cloudfront' => "# - CloudFront -> Error pages: Custom error response, що замінює 403/404 на головну;\n# - CloudFront Functions / Lambda@Edge з редиректом на «/»;",
                'vercel'     => "# - vercel.json: правило в \"redirects\" з source \"/(.*)\" на «/»;",
                'netlify'    => "# - файл _redirects / netlify.toml: правило «/*  /  301» чи «/*  /  302»;",
            ][$stack['host']];
            return "# Невідомі адреси перенаправляє на головну CDN або сам застосунок. Перевірте:\n" . $where . "\n# - роутер застосунку: catch-all маршрут з редиректом на головну.\n# Замість редиректу показуйте сторінку «Не знайдено» з кодом 404, напр. Vue Router + SSR:\n#   { path: '/:pathMatch(.*)*', component: NotFound }  і код 404 у відповіді сервера для цього маршруту";
        }
        switch ($stack['host']) {
            case 'vercel':
                return "// vercel.json: перезапис на index.html лише для відомих маршрутів SPA;\n// для решти адрес Vercel віддасть 404.html з папки збірки з кодом 404\n" . self::jsonPretty(['rewrites' => [['source' => '/(about|pricing|blog/.*)', 'destination' => '/index.html']]]);
            case 'netlify':
                return "# Netlify, файл _redirects: замість «/*  /index.html  200» для всіх адрес - лише відомі маршрути\n/about     /index.html   200\n/blog/*    /index.html   200\n# решту адрес Netlify віддасть як 404 зі сторінкою 404.html";
            case 'cloudfront':
                return "// Amazon CloudFront: не підміняйте відповідь 403/404 від S3 на /index.html з кодом 200\n// (Distribution -> Error pages -> Custom error response). Для SPA - CloudFront Function (viewer request):\nfunction handler(event) {\n  var req = event.request;\n  var known = /^\\/($|about|pricing|blog\\/)/;\n  if (req.uri.indexOf('.') === -1 && known.test(req.uri)) req.uri = '/index.html';\n  return req; // інші адреси S3 поверне з кодом 404 (404.html у бакеті)\n}" . $tail;
        }
        if ($isSpa) {
            return "# SPA: сервер повертає index.html на будь-яку адресу - тому 200.\n# Варіант 1 (рекомендовано): SSR/SSG-фреймворк віддає 404 для невідомих маршрутів\n#   Next.js: notFound() у сторінці; Nuxt: throw createError({ statusCode: 404 })\n# Варіант 2: nginx віддає index.html лише для відомих маршрутів\nlocation ~ ^/(|about|pricing|blog/.*)$ {\n    try_files \$uri /index.html;\n}\nlocation / {\n    try_files \$uri =404;\n}\nerror_page 404 /404.html;" . $tail;
        }
        if ($stack['app'] === 'aspnet' || $stack['server'] === 'iis') {
            return "<!-- web.config (IIS / ASP.NET): власна сторінка 404 зі збереженням коду -->\n<system.webServer>\n  <httpErrors errorMode=\"Custom\" existingResponse=\"Replace\">\n    <remove statusCode=\"404\" />\n    <error statusCode=\"404\" path=\"/404.html\" responseMode=\"File\" />\n  </httpErrors>\n</system.webServer>" . $tail;
        }
        if ($stack['app'] !== 'php' && $stack['server'] === 'nginx') {
            return "# nginx: справжній 404 для неіснуючих адрес\nerror_page 404 /404.html;\nlocation / {\n    try_files \$uri \$uri/ =404;\n}" . $tail;
        }
        if ($stack['app'] !== 'php' && in_array($stack['server'], ['apache', 'litespeed'], true)) {
            return "# Apache (.htaccess)\nErrorDocument 404 /404.html\n# правило RewriteRule, що відправляє невідомі адреси на головну, має віддавати для них 404" . $tail;
        }
        return "# nginx: справжній 404 для неіснуючих адрес\nerror_page 404 /404.html;\nlocation / {\n    try_files \$uri \$uri/ /index.php?\$query_string;\n}\n\n# PHP / CMS: у шаблоні «сторінку не знайдено»\n<?php http_response_code(404); ?>" . ($tail !== '' ? $tail : "\n\n# Не перенаправляйте всі невідомі адреси на головну - віддавайте 404 зі сторінкою-підказкою.");
    }

    /** Альтернативна версія хоста (www <-> без www) або null для піддоменів. */
    private static function altHost(string $host): ?string
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return null;
        if (strpos($host, 'www.') === 0) return substr($host, 4);
        $labels = explode('.', $host);
        $n = count($labels);
        if ($n === 2) return 'www.' . $host;
        if ($n === 3 && preg_match('/\.(com|org|net|gov|edu|co|in|biz|kiev|kyiv|lviv|od|dp|kh|zp|ck|cn|cv|if|km|kr|ks|lg|lt|lutsk|mk|pl|pp|rv|sm|te|uz|vn|zt|ltd|me|ac)\.[a-z]{2}$/i', $host)) return 'www.' . $host;
        return null;
    }

    private static function chainText(array $r): string
    {
        $parts = [];
        $hops = $r['hops'] ?? [];
        if (!$hops) return (string)($r['url'] ?? '') . ' -> ' . ((int)($r['status'] ?? 0) ?: 'немає відповіді');
        foreach ($hops as $h) {
            $parts[] = $h['url'];
            $parts[] = (string)$h['status'];
        }
        return implode(' -> ', $parts);
    }

    private static function chainData(array $r): array
    {
        return [
            'status' => (int)$r['status'],
            'final'  => (string)$r['final_url'],
            'hops'   => array_map(function ($h) { return $h['status'] . ' ' . $h['url']; }, $r['hops']),
            'error'  => $r['error'] !== '' ? $r['error'] : null,
        ];
    }

    private static function certExplain(int $code, string $err): array
    {
        $e = strtolower($err);
        if ($code === 10 || strpos($e, 'expired') !== false) {
            return ['термін дії сертифіката минув', 'Продовжіть сертифікат і налаштуйте автоматичне оновлення (certbot renew за cron або автопродовження в панелі хостингу/CDN).'];
        }
        if ($code === 18 || $code === 19 || strpos($e, 'self-signed') !== false || strpos($e, 'self signed') !== false) {
            return ['самопідписаний сертифікат', 'Замініть самопідписаний сертифікат на виданий довіреним центром (безкоштовно - Let\'s Encrypt).'];
        }
        if ($code === 62 || strpos($e, 'subject name') !== false || strpos($e, 'hostname') !== false || strpos($e, 'match') !== false) {
            return ['сертифікат виданий для іншого домену', 'Випустіть сертифікат, що покриває всі імена сайту (з www і без), і встановіть його на сервер.'];
        }
        if ($code === 20 || $code === 21 || strpos($e, 'local issuer') !== false) {
            return ['неповний ланцюжок сертифікатів', 'Встановіть на сервер повний ланцюжок (fullchain.pem: сертифікат сайту + проміжний сертифікат), а не лише сертифікат домену.'];
        }
        return ['помилка перевірки', 'Перевірте сертифікат і ланцюжок у SSL Labs та перевипустіть сертифікат у довіреного центру сертифікації.'];
    }

    private static function titleOf(string $html): string
    {
        if ($html === '' || !preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) return '';
        $t = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!mb_check_encoding($t, 'UTF-8')) $t = mb_scrub($t, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    private static function inSkippedTag(DOMNode $n): bool
    {
        for ($p = $n->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            $t = strtolower($p->nodeName);
            if ($t === 'noscript' || $t === 'template' || $t === 'svg' || $t === 'script') return true;
        }
        return false;
    }

    private static function imgTag(Page $pg, DOMElement $img): string
    {
        $html = (string)$pg->dom()->saveHTML($img);
        $html = (string)preg_replace('/\s+/u', ' ', $html);
        $html = (string)preg_replace('/(data:[a-z]+\/[a-z0-9+.\-]+[;,])[^"\'\s>]{20,}/i', '$1...', $html);
        $html = (string)preg_replace_callback('/\s(srcset|data-srcset|sizes|style)="([^"]{70,})"/i', function ($m) {
            return ' ' . $m[1] . '="' . mb_substr($m[2], 0, 60) . '..."';
        }, $html);
        return self::mbTrunc(trim($html), 300);
    }

    private static function imgTagWithAlt(string $tag, string $alt): string
    {
        $alt = str_replace('"', '&quot;', $alt);
        if (preg_match('#^<img\b#i', $tag)) return (string)preg_replace('#^<img\b#i', '<img alt="' . $alt . '"', $tag, 1);
        return $tag;
    }

    /** Пріоритет зображення для прикладу: 3 - контентне (main/article/товар/figure або від 100 px), 2 - звичайне, 1 - іконка. */
    private static function imgPriority(DOMElement $img, string $src, int $w, int $h): int
    {
        $path = strtolower((string)parse_url($src, PHP_URL_PATH));
        // розміри з атрибутів, inline-стилю або з шляху CDN-ресайзу (/44x44/)
        $st = $img->getAttribute('style');
        if ($w === 0 && preg_match('/(?:^|;)\s*width\s*:\s*(\d+)px/i', $st, $m)) $w = (int)$m[1];
        if ($h === 0 && preg_match('/(?:^|;)\s*height\s*:\s*(\d+)px/i', $st, $m)) $h = (int)$m[1];
        if ($w === 0 && $h === 0 && preg_match('#/(\d{1,3})x(\d{1,3})/#', $path, $m) && (int)$m[1] > 0 && (int)$m[2] > 0) { $w = (int)$m[1]; $h = (int)$m[2]; }
        $icon = (bool)preg_match('#\.svg$|/icons?/|_icons?/|/sprites?/|/svg/|[-_/]icon[-_.]|/header/|/ui/|favicon|/flags?/#', $path)
            || ($w > 0 && $w < 48) || ($h > 0 && $h < 48)
            || (bool)preg_match('/(^|[\s_-])icon/i', $img->getAttribute('class'));
        if ($icon) return 1;
        if ($w >= 100 || $h >= 100) return 3;
        for ($p = $img->parentNode, $i = 0; $p instanceof DOMElement && $i < 8; $p = $p->parentNode, $i++) {
            $t = strtolower($p->nodeName);
            if ($t === 'main' || $t === 'article' || $t === 'figure') return 3;
            if (in_array($t, ['header', 'nav', 'footer'], true)) return 2;
            if (preg_match('/product|goods|catalog|card|gallery|portfolio|team|post|news/i', $p->getAttribute('class') . ' ' . $p->getAttribute('id'))) return 3;
        }
        return 2;
    }

    /** alt для іконки: у посиланні/кнопці без тексту - призначення посилання, інакше - порожній (декоративна). */
    private static function iconAlt(DOMElement $img): string
    {
        for ($p = $img->parentNode, $i = 0; $p instanceof DOMElement && $i < 3; $p = $p->parentNode, $i++) {
            $t = strtolower($p->nodeName);
            if ($t === 'a' || $t === 'button') {
                foreach (['aria-label', 'title'] as $at) {
                    $v = Page::norm($p->getAttribute($at));
                    if ($v !== '' && mb_strlen($v) <= 120) return '';  // назва вже є на посиланні - іконка декоративна
                }
                if (preg_match('/\p{L}{2,}/u', Page::norm($p->textContent))) return '';
                return '[ВКАЖІТЬ ПРИЗНАЧЕННЯ ПОСИЛАННЯ, напр. Особистий кабінет]';
            }
        }
        return '';
    }

    /** Підказка для alt: title / текст посилання / figcaption / слова з імені файлу. */
    private static function altSuggestion(DOMElement $img, string $src): string
    {
        $t = trim($img->getAttribute('title'));
        if ($t !== '' && mb_strlen($t) <= 120) return Page::norm($t);
        for ($p = $img->parentNode, $i = 0; $p instanceof DOMElement && $i < 4; $p = $p->parentNode, $i++) {
            $tag = strtolower($p->nodeName);
            if ($tag === 'a') {
                foreach (['aria-label', 'title'] as $at) {
                    $v = Page::norm($p->getAttribute($at));
                    if ($v !== '' && mb_strlen($v) <= 120) return $v;
                }
                $v = Page::norm($p->textContent);
                if ($v !== '' && mb_strlen($v) <= 100 && preg_match('/\p{L}{3,}/u', $v)) return $v;
            }
            if ($tag === 'figure') {
                foreach ($p->getElementsByTagName('figcaption') as $fc) {
                    $v = Page::norm($fc->textContent);
                    if ($v !== '' && mb_strlen($v) <= 150) return $v;
                }
            }
        }
        $path = (string)parse_url($src, PHP_URL_PATH);
        $name = rawurldecode(pathinfo($path, PATHINFO_FILENAME));
        $name = (string)preg_replace('/[-_.]+/', ' ', $name);
        $name = (string)preg_replace('/\b\d+x\d+\b|\b[0-9a-f]{8,}\b|\b\d+\b/i', ' ', $name);
        $name = trim((string)preg_replace('/\s+/', ' ', $name));
        if (preg_match('/\p{L}{3,}/u', $name) && mb_strlen($name) <= 60) return '[ВКАЖІТЬ ОПИС: ' . $name . ']';
        return '[ВКАЖІТЬ ОПИС ЗОБРАЖЕННЯ]';
    }

    private function summaryLine(array $findings, bool $blocked, array $sub, ?float $partialWeight, array $data): string
    {
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($findings, function ($a, $b) use ($order) { return ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3); });
        $prefix = '';
        if ($blocked) {
            $short = ['https' => 'HTTPS', 'host_canonicalization' => 'одна версія хоста', 'ttfb' => 'TTFB', 'compression' => 'стиснення',
                'http2' => 'протокол HTTP/2', 'cwv' => 'Core Web Vitals', 'mobile' => 'viewport', 'images_alt' => 'alt зображень',
                'soft404' => 'обробка 404', 'security_headers' => 'заголовки безпеки'];
            $ev = [];
            foreach ($sub as $k => $v) if ($v !== null) $ev[] = $short[$k] ?? $k;
            $prefix = 'Головна не віддала боту звичайний HTML, тому оцінено частково: ' . count($ev) . ' з ' . count($sub) . ' параметрів (' . implode(', ', $ev) . ')'
                . ($partialWeight !== null ? '; вагу перевірки в загальному балі зменшено до ' . (int)max(1, round($partialWeight * 100)) . '%' : '') . '. ';
        }
        if (!$findings) {
            return $blocked ? $prefix . 'Зауважень до них немає.'
                : 'Технічна база в порядку: HTTPS, ' . ($data['http_version'] ?? 'HTTP/2') . ', стиснення, швидка відповідь сервера.';
        }
        $top = array_slice(array_map(function ($f) { return rtrim($f['text'], '.'); }, $findings), 0, 2);
        $rest = count($findings) - count($top);
        return $prefix . implode('; ', $top) . ($rest > 0 ? '; ще ' . $rest . ' ' . self::plural($rest, 'зауваження', 'зауваження', 'зауважень') : '') . '.';
    }

    public static function fmtMs(int $ms): string
    {
        return number_format($ms, 0, ',', ' ') . ' мс';
    }

    private static function fmtSec(float $ms): string
    {
        return str_replace('.', ',', number_format($ms / 1000, 1, '.', '')) . ' с';
    }

    private static function fmtBytes(int $b): string
    {
        if ($b < 1024) return $b . ' Б';
        if ($b < 1048576) return number_format($b / 1024, 0, ',', ' ') . ' КБ';
        return str_replace('.', ',', number_format($b / 1048576, 1, '.', '')) . ' МБ';
    }

    /** Рядок статусу фактичної відповіді, напр. «HTTP/2 200». */
    private static function statusLine(array $r): string
    {
        $vl = self::httpVersionLabel((int)($r['http_version'] ?? 0));
        return ($vl === 'невідомо' ? 'HTTP/1.1' : $vl) . ' ' . ((int)($r['status'] ?? 0) ?: 200);
    }

    private static function httpVersionLabel(int $v): string
    {
        switch ($v) {
            case 1: return 'HTTP/1.0';
            case 2: return 'HTTP/1.1';
            case 3: return 'HTTP/2';
            case 30: return 'HTTP/3';
        }
        return $v > 3 ? 'HTTP/2' : 'невідомо';
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) return $many;
        if ($n1 > 1 && $n1 < 5) return $few;
        if ($n1 === 1) return $one;
        return $many;
    }
}
