<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';
require_once __DIR__ . '/../SiteProfile.php';

/**
 * XML-карта сайту.
 * Пошук: усі рядки Sitemap у robots.txt, далі стандартні адреси; .gz; soft-404 (HTML замість XML).
 * Індекс карт: до 3 дочірніх карт різних типів. Далі - валідність XML і namespace, lastmod,
 * протокол/хост URL, коди відповіді вибірки URL з карти, наявність ключових сторінок (коли список повний).
 */
class SitemapCheck extends BaseCheck
{
    const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    const GUESS_PATHS = ['/sitemap.xml', '/sitemap_index.xml', '/sitemap.xml/', '/sitemap-index.xml', '/wp-sitemap.xml'];
    const MAX_ROBOTS_SITEMAPS = 5;
    const MAX_CHILDREN = 3;
    /** Невеликий індекс читаємо повністю: так можна перевірити повноту карти (мовні версії, ключові сторінки). */
    const MAX_CHILDREN_ALL = 8;
    const MAX_SAMPLE = 6;
    const MAX_LOCS = 60000;
    const PROTOCOL_MAX_URLS = 50000;
    const PROTOCOL_MAX_BYTES = 52428800;
    const GZ_CAP = 26214400;
    const ROOT_TIMEOUT = 20;
    const CHILD_TIMEOUT = 15;
    const URL_TIMEOUT = 15;
    const GOOGLEBOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    const BINGBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36';
    const OAI_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot';
    const PPLX_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)';
    const RX_W3C_DATE = '/^\d{4}(-\d{2}(-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:?\d{2})?)?)?)?$/';
    /** Явно службові адреси, яким не місце в карті (кошик, вхід, «дякуємо»). */
    const RX_SERVICE = '#/(cart|basket|korzina|koshyk|checkout|login|logout|signin|sign-in|my-account|cabinet|kabinet|wishlist|wp-admin)(/|$|\.|\?)|/(thank-?you|thanks|dyakuyemo|dyakuiemo|spasibo|podyaka)(?:[-_]page)?(?:\.html?|\.php)?(/|$|\?)|[?&]add-to-cart=#i';
    /** Мовний сегмент шляху або піддомену. */
    const RX_LANG_SEG = '/^(uk|ua|en|ru|pl|de|fr|es|it|cs|ro|hu|tr|kk|be|uk-ua|ru-ua|en-us|en-gb)$/i';

    /** Особисті / службові розділи (кабінет, кошик, вхід), яким не місце ні в карті, ні в llms.txt. */
    const RX_PRIVATE_PATH = '#/(user|users|customer|customers|my|me|lk|garage|personal|personal-info|personal-data|history[\w-]*|orders?|wishlist|favorites|favourites|compare|cart|basket|checkout|login|logout|register|registration|account|my-account|profile|auth|signin|sign-in|signup|sign-up|cabinet|kabinet|password|forgot[\w-]*)(/|$)#i';
    const RX_PRIVATE_QUERY = '#route=(account|checkout|affiliate|product/compare|product/search)#i';

    private string $final = '';
    private string $base = '';
    private ?string $mode = null;
    /** Основна адреса сайту scheme://host[:port]: з canonical головної (www / без www, https), інакше - кінцева адреса головної. */
    private string $origin = '';
    private bool $originFromCanonical = false;

    public function id(): string { return 'sitemap'; }
    public function name(): string { return 'XML-карта сайту'; }
    public function weight(): float { return 0.05; }

    public function run(string $url, array $ctx): array
    {
        $this->final = (string)($ctx['url'] ?? '') !== '' ? (string)$ctx['url'] : $url;
        $this->base = Crawler::baseUrl($this->final);
        $this->mode = (($ctx['access']['fetched_as'] ?? 'bot') === 'browser') ? 'browser' : null;
        $page = (($ctx['page'] ?? null) instanceof Page) ? $ctx['page'] : null;
        $siteType = (string)($ctx['site']['type'] ?? 'corporate');
        [$this->origin, $this->originFromCanonical] = self::mainOrigin($this->final, $page, (string)($ctx['input_url'] ?? ''));

        $labels = [
            'exists'      => 'Карта знайдена',
            'robots_ref'  => 'Посилання в robots.txt',
            'valid_xml'   => 'Валідний XML і namespace',
            'coverage'    => 'Обсяг і повнота',
            'lastmod'     => 'Дати lastmod',
            'consistency' => 'Протокол і хост URL',
            'url_status'  => 'Коди відповіді URL з карти',
        ];

        $t0 = microtime(true);
        $tm = [];
        $robots = $this->robotsInfo((array)($ctx['robots_txt'] ?? []));
        $data = [
            'robots_state' => $robots['state'],
            'robots_lines' => array_map(function ($l) { return $l['raw']; }, $robots['lines']),
        ];

        // ------------------------------------------------------------ 1. пошук карти
        $allDeclared = [];
        foreach ($robots['lines'] as $ln) {
            if ($ln['url'] !== null && !isset($allDeclared[$ln['url']])) $allDeclared[$ln['url']] = $ln;
        }
        // забагато рядків Sitemap: перевіряємо до 5 найпоказовіших (основна карта / індекс, товари, статті), а не перші 5
        $declared = count($allDeclared) > self::MAX_ROBOTS_SITEMAPS ? $this->pickDeclared($allDeclared) : $allDeclared;
        $data['robots_sitemaps_total'] = count($allDeclared);
        $roots = [];
        $dupRoots = [];
        if ($declared) {
            $byHash = [];
            foreach ($this->fetchDocs(array_keys($declared), self::ROOT_TIMEOUT) as $u => $info) {
                $info['source'] = 'robots';
                // та сама карта під двома адресами (www і без www, або редирект на іншу адресу з robots.txt) - рахуємо один раз
                if ($info['state'] === 'ok') {
                    $hk = $info['md5'] !== '' ? $info['md5'] : self::normUrl((string)$info['final_url']);
                    $fk = 'f:' . self::normUrl((string)$info['final_url']);
                    $first = $byHash[$hk] ?? ($byHash[$fk] ?? null);
                    if ($first !== null) {
                        $info['dup_of'] = $first;
                        $dupRoots[$u] = $first;
                    } else {
                        $byHash[$hk] = $u;
                        $byHash[$fk] = $u;
                    }
                }
                $roots[$u] = $info;
            }
        }
        if ($dupRoots) $data['robots_duplicate_sitemaps'] = $dupRoots;
        $guesses = [];
        if (!$this->okOnly($roots)) {
            $gUrls = [];
            foreach (self::GUESS_PATHS as $p) {
                $g = $this->base . $p;
                if (!isset($roots[$g])) $gUrls[] = $g;
            }
            $gRes = $this->fetchDocs($gUrls, self::ROOT_TIMEOUT);
            foreach ($gUrls as $g) {
                if (!isset($gRes[$g])) continue;
                $gRes[$g]['source'] = 'guess';
                $guesses[$g] = $gRes[$g];
            }
            foreach ($guesses as $g => $info) {
                if ($info['state'] === 'ok') { $roots[$g] = $info; break; }
            }
        }
        $okRoots = $this->okOnly($roots);
        $tm['discover'] = (int)round((microtime(true) - $t0) * 1000);
        $data['candidates'] = [];
        foreach ($roots + $guesses as $u => $info) $data['candidates'][$u] = $this->stateLabel($info);

        if (!$okRoots) {
            return $this->notFound($robots, $declared, $roots, $guesses, $page, $ctx, $data, $labels);
        }

        // ------------------------------------------------------------ 2. індекс -> дочірні карти
        $children = [];
        $rootKeys = [];
        foreach ($okRoots as $u => $r) {
            $rootKeys[self::normUrl($u)] = true;
            $rootKeys[self::normUrl((string)$r['final_url'])] = true;
        }
        foreach ($dupRoots as $u => $_) $rootKeys[self::normUrl($u)] = true;
        $childListed = 0;
        foreach ($okRoots as $u => $r) {
            if ($r['kind'] !== 'index') continue;
            foreach ($r['parsed']['entries'] as $e) {
                $childListed++;
                // карта, яку вже завантажено як кореневу (з robots.txt), вдруге не рахуємо
                if (isset($rootKeys[self::normUrl((string)$e['loc'])])) continue;
                $children[] = ['loc' => $e['loc'], 'lastmod' => $e['lastmod'], 'parent' => $u];
            }
        }
        $childTotal = count(array_unique(array_column($children, 'loc')));
        $pick = $this->pickChildren($children);
        $t1 = microtime(true);
        $childRes = $pick ? $this->fetchDocs($pick, self::CHILD_TIMEOUT) : [];
        $tm['children'] = (int)round((microtime(true) - $t1) * 1000);

        $entries = [];
        $docs = [];
        $rootUrls = 0;
        $truncatedAny = false;
        $altSet = [];
        foreach ($okRoots as $u => $r) {
            $docs[] = ['url' => $u, 'role' => 'root', 'info' => $r];
            if ($r['truncated']) $truncatedAny = true;
            if ($r['kind'] !== 'index') {
                $rootUrls += $r['parsed']['count'];
                $this->addEntries($entries, $r['parsed']['entries'], !empty($r['parsed']['news']));
                foreach ($r['parsed']['alts'] ?? [] as $a) $altSet[$a] = true;
            }
        }
        $childOk = 0; $childUrls = 0; $childNested = 0;
        $childFail = []; $childUnknown = [];
        foreach ($childRes as $cu => $r) {
            if ($r['state'] === 'ok') {
                $docs[] = ['url' => $cu, 'role' => 'child', 'info' => $r];
                if ($r['truncated']) $truncatedAny = true;
                if ($r['kind'] === 'index') { $childNested++; continue; }
                $childOk++;
                $childUrls += $r['parsed']['count'];
                $this->addEntries($entries, $r['parsed']['entries'], !empty($r['parsed']['news']));
                foreach ($r['parsed']['alts'] ?? [] as $a) $altSet[$a] = true;
            } elseif (in_array($r['state'], ['blocked', 'timeout'], true)) {
                $childUnknown[$cu] = $r;
            } else {
                $childFail[$cu] = $r;
            }
        }
        $rootsSkipped = max(0, count($allDeclared) - count($declared));
        $complete = !$truncatedAny && $childNested === 0 && $rootsSkipped === 0 && ($childTotal === 0 || $childOk === $childTotal);
        $urlsCounted = $rootUrls + $childUrls;
        $urlsEstimated = $urlsCounted;
        if (!$complete && $childOk > 0 && $childTotal > $childOk) {
            $urlsEstimated = $rootUrls + (int)round($childUrls / $childOk * ($childTotal - count($childFail)));
        }

        $primaryUrl = (string)array_key_first($okRoots);
        $primary = $okRoots[$primaryUrl];
        $anyBotBlocked = false;
        foreach ($docs as $d) if (!empty($d['info']['bot_blocked'])) $anyBotBlocked = true;

        $data += [
            'sitemap_urls'     => array_keys($okRoots),
            'source'           => $primary['source'],
            'kind'             => $primary['kind'],
            'fetched_as'       => $anyBotBlocked ? 'browser' : ($this->mode ?? 'bot'),
            'children_total'   => $childTotal,
            'children_listed'  => $childListed,
            'children_checked' => array_map(function ($r) { return $this->stateLabel($r); }, $childRes),
            'urls_counted'     => $urlsCounted,
            'urls_estimated'   => $complete ? null : $urlsEstimated,
            'complete'         => $complete,
        ];

        $findings = [];
        $passed = [];
        $sub = array_fill_keys(array_keys($labels), null);
        $sub['exists'] = 100;

        // ---- що знайдено (passed)
        if (count($okRoots) > 1) {
            $foundTxt = ($rootsSkipped > 0
                    ? 'У robots.txt ' . self::plural(count($allDeclared), 'рядок', 'рядки', 'рядків') . ' Sitemap; перевірено ' . count($declared) . ' основних, робочих - ' . count($okRoots)
                    : 'Робочі карти з robots.txt: ' . self::plural(count($okRoots), 'файл', 'файли', 'файлів'))
                . ' (' . self::listSome(array_keys($okRoots), 2) . ')'
                . ($childListed > 0 ? '; дочірніх карт в індексах: ' . self::num($childListed) : '')
                . ($urlsCounted > 0 ? '; ' . ($complete ? self::num($urlsCounted) . ' URL' : 'пораховано ' . self::num($urlsCounted) . ' URL у перевірених файлах') : '');
        } else {
            $foundTxt = $this->describeRoot($primaryUrl, $primary, $childListed, $urlsCounted, $urlsEstimated, $complete, $childOk);
        }
        if ($dupRoots) {
            $dupTxt = [];
            foreach ($dupRoots as $du => $first) $dupTxt[] = $du . ' віддає той самий файл, що й ' . $first;
            $foundTxt .= '; ' . implode('; ', array_slice($dupTxt, 0, 2)) . ' (рахуємо один раз)';
        }
        $passed[] = $foundTxt . ($anyBotBlocked ? ' (наш бот отримав перевірку захисту, вміст прочитано з User-Agent ' . (Crawler::altName() === 'Chrome' ? 'браузера' : Crawler::altName()) . ')' : '');

        // ------------------------------------------------------------ 3. robots.txt
        // у рекомендаціях - кінцева адреса карти (без редиректу)
        $workingUrl = ($primary['redirects'] > 0 && Crawler::sameSite($primary['final_url'], $this->final)) ? $primary['final_url'] : $primaryUrl;
        $declaredOk = array_intersect_key($okRoots, $declared);
        $declaredBad = [];
        foreach ($declared as $du => $ln) {
            if (isset($okRoots[$du]) || !empty($roots[$du]['dup_of'])) continue;
            $st = $roots[$du]['state'] ?? 'timeout';
            if (!in_array($st, ['blocked', 'timeout'], true)) $declaredBad[$du] = $ln;
        }
        if ($robots['state'] === 'ok') {
            if ($declaredOk) {
                $sub['robots_ref'] = $declaredBad ? 70 : 100;
                $passed[] = 'Карта вказана в robots.txt: «' . $declared[array_key_first($declaredOk)]['raw'] . '»';
            } elseif ($declared) {
                $sub['robots_ref'] = $declaredBad ? 30 : null;
            } else {
                $sub['robots_ref'] = 0;
            }
            if ($declaredBad) {
                $findings[] = $this->findingBrokenRef($declaredBad, $roots, $workingUrl, (bool)$declaredOk);
            }
            if (!$declared) {
                $findings[] = $this->findingNotInRobots($robots, $workingUrl);
            }
            $rel = array_filter($robots['lines'], function ($l) { return $l['relative'] && $l['url'] !== null; });
            if ($rel) {
                $r0 = reset($rel);
                $findings[] = $this->finding('robots_relative_url', 'low', 'У robots.txt адреса карти вказана не повністю', [
                    'evidence' => 'Рядок ' . $r0['line'] . ': «' . $r0['raw'] . '». Протокол sitemaps.org вимагає повну адресу з протоколом і доменом.',
                    'why'      => 'Частина краулерів ігнорує відносний шлях у директиві Sitemap, тому може не знайти карту.',
                    'fix'      => 'Запишіть адресу карти повністю, з https:// і доменом.',
                    'bad'      => $r0['raw'],
                    'good'     => 'Sitemap: ' . $r0['url'],
                    'code'     => 'Sitemap: ' . $r0['url'],
                    'verify'   => 'Відкрийте ' . $this->base . '/robots.txt і перевірте, що рядок Sitemap містить повну адресу.',
                    'effort'   => 'S',
                ]);
            }
        } elseif (in_array($robots['state'], ['missing', 'html'], true)) {
            $sub['robots_ref'] = 0;
            $findings[] = $this->findingNotInRobots($robots, $workingUrl);
        }

        // ------------------------------------------------------------ 4. дочірні карти
        if ($childFail) {
            $lines = [];
            foreach ($childFail as $cu => $r) $lines[] = $cu . ' - ' . $this->stateLabel($r);
            $findings[] = $this->finding('child_unavailable', 'medium', 'Частина карт з індексу недоступна', [
                'evidence' => 'Індекс ' . $primaryUrl . ' містить ' . self::plural($childTotal, 'карту', 'карти', 'карт') . '; перевірено ' . count($childRes) . ', з них недоступні: ' . count($childFail) . ".\n" . implode("\n", array_slice($lines, 0, 5)),
                'why'      => 'Краулер, який не може завантажити дочірню карту, не дізнається про сторінки з неї. У Search Console така карта має статус «Не вдалося отримати».',
                'fix'      => 'Виправте генерацію цих карт або приберіть їх з індексу, якщо розділ більше не існує.',
                'bad'      => implode("\n", array_slice($lines, 0, 5)),
                'good'     => 'Кожна карта з індексу відповідає HTTP 200 і містить XML <urlset>.',
                'code'     => '',
                'verify'   => 'curl -I для кожної адреси з індексу (очікуємо 200, Content-Type: application/xml); Google Search Console -> Файли Sitemap: статус «Успішно».',
                'effort'   => 'M',
            ]);
        }
        if ($childUnknown) $data['children_unknown'] = array_keys($childUnknown);

        // ------------------------------------------------------------ 5. валідність і namespace
        $xmlDocs = array_values(array_filter($docs, function ($d) { return in_array($d['info']['kind'], ['urlset', 'index', 'feed'], true); }));
        $validScore = null;
        if ($xmlDocs) {
            $invalid = []; $nsBad = []; $checkedXml = 0;
            foreach ($xmlDocs as $d) {
                $p = $d['info']['parsed'];
                if ($p['valid'] === null) continue; // обрізаний файл - не оцінюємо
                $checkedXml++;
                if ($p['valid'] === false) $invalid[] = $d;
                elseif ($d['info']['kind'] !== 'feed' && $p['ns'] !== self::NS) $nsBad[] = $d;
            }
            if ($checkedXml > 0) {
                $validScore = 100;
                foreach ($invalid as $d) $validScore = min($validScore, $d['role'] === 'root' ? 0 : 50);
                if ($nsBad) $validScore = min($validScore, 60);
            }
            if ($invalid) {
                $d = $invalid[0];
                $p = $d['info']['parsed'];
                $isRoot = $d['role'] === 'root';
                $err = $p['errors'][0] ?? 'помилка розбору XML';
                $findings[] = $this->finding('invalid_xml', $isRoot ? 'high' : 'medium', $isRoot ? 'Карта сайту - невалідний XML' : 'Дочірня карта - невалідний XML', [
                    'evidence' => $d['url'] . ': ' . $err . (count($invalid) > 1 ? "\nНевалідних файлів: " . count($invalid) . ' (' . self::listSome(array_column($invalid, 'url'), 3) . ')' : '')
                        . "\nУ файлі знайдено " . self::plural((int)$p['count'], 'запис', 'записи', 'записів') . ' <' . ($d['info']['kind'] === 'index' ? 'sitemap' : 'url') . '>'
                        . (isset($p['count_reader']) && (int)$p['count_reader'] < (int)$p['count'] ? ', до помилки розбору прочитано ' . self::num((int)$p['count_reader']) : '') . '.',
                    'why'      => 'Google і Bing відкидають карту з помилкою розбору повністю або частково, тож сторінки з неї краулери знаходять лише через посилання.',
                    'fix'      => 'Виправте генерацію карти: усі теги закриті, спецсимволи в URL екрановані (& як &amp;), перед <?xml немає пробілів чи BOM-сміття.',
                    'bad'      => $p['err_snippet'] !== '' ? $p['err_snippet'] : $err,
                    'good'     => "<url>\n  <loc>" . self::xmlUrl($p['entries'][0]['loc'] ?? $this->base . '/') . "</loc>\n</url>",
                    'code'     => '',
                    'verify'   => 'xmllint --noout ' . basename((string)parse_url($d['url'], PHP_URL_PATH)) . ' (без виводу = валідно); Google Search Console -> Файли Sitemap: статус «Успішно».',
                    'effort'   => 'S',
                ]);
            }
            if ($nsBad) {
                $d = $nsBad[0];
                $p = $d['info']['parsed'];
                $root = $p['root'] !== '' ? $p['root'] : ($d['info']['kind'] === 'index' ? 'sitemapindex' : 'urlset');
                $findings[] = $this->finding('namespace', 'medium', 'Неправильний namespace у карті сайту', [
                    'evidence' => $d['url'] . ': кореневий <' . $root . '> ' . ($p['ns'] === '' ? 'без атрибута xmlns' : 'з xmlns="' . $p['ns'] . '"') . '. Очікується саме ' . self::NS . (count($nsBad) > 1 ? '. Таких файлів: ' . count($nsBad) : ''),
                    'why'      => 'За протоколом sitemaps.org namespace обов\'язковий і має збігатися дослівно; Search Console показує для такої карти помилку «Неправильний простір імен».',
                    'fix'      => 'Виправте атрибут xmlns кореневого елемента в генераторі карти.',
                    'bad'      => $p['root_tag'] !== '' ? $p['root_tag'] : '<' . $root . '>',
                    'good'     => '<' . $root . ' xmlns="' . self::NS . '">',
                    'code'     => '<' . $root . ' xmlns="' . self::NS . '">',
                    'verify'   => 'Відкрийте карту в браузері і перевірте перший тег; Google Search Console -> Файли Sitemap: без помилок.',
                    'effort'   => 'S',
                ]);
            }
            if (!$invalid && !$nsBad && $validScore !== null) {
                $passed[] = 'XML валідний, namespace ' . self::NS . ' (перевірено файлів: ' . $checkedXml . ')';
            }
        }
        $sub['valid_xml'] = $validScore;

        // ------------------------------------------------------------ 6. обсяг, ліміти, повнота
        $coverage = $urlsCounted > 0 ? 100 : 0;
        $sizeIssues = [];
        foreach ($docs as $d) {
            $c = (int)($d['info']['parsed']['count'] ?? 0);
            if ($d['info']['kind'] !== 'index' && $c > self::PROTOCOL_MAX_URLS) $sizeIssues[] = $d['url'] . ' - ' . self::num($c) . ' URL';
            elseif ((int)$d['info']['bytes'] > self::PROTOCOL_MAX_BYTES) $sizeIssues[] = $d['url'] . ' - ' . round($d['info']['bytes'] / 1048576) . ' МБ';
        }
        if ($sizeIssues) {
            $coverage = min($coverage, 70);
            $findings[] = $this->finding('too_large', 'medium', 'Карта перевищує ліміт протоколу (50 000 URL або 50 МБ)', [
                'evidence' => implode("\n", $sizeIssues),
                'why'      => 'Google і Bing приймають в одному файлі не більше 50 000 URL або 50 МБ у розпакованому вигляді; більший файл Search Console позначає помилкою, і адреси понад ліміт можуть не потрапити в обхід.',
                'fix'      => 'Розбийте карту на кілька файлів (наприклад, за типами сторінок) і об\'єднайте їх індексом sitemapindex.',
                'bad'      => implode("\n", $sizeIssues),
                'good'     => "<sitemapindex xmlns=\"" . self::NS . "\">\n  <sitemap><loc>" . $this->base . "/sitemap-1.xml</loc></sitemap>\n  <sitemap><loc>" . $this->base . "/sitemap-2.xml</loc></sitemap>\n</sitemapindex>",
                'code'     => '',
                'verify'   => 'Кожен файл карти - до 50 000 URL; Search Console -> Файли Sitemap: без попереджень.',
                'effort'   => 'M',
            ]);
        }
        $emptyDocs = array_filter($docs, function ($d) { return (int)$d['info']['parsed']['count'] === 0 && $d['info']['parsed']['valid'] !== false; });
        if ($emptyDocs) {
            $e0 = reset($emptyDocs);
            $coverage = min($coverage, $urlsCounted > 0 ? 70 : 0);
            $findings[] = $this->finding('empty', $urlsCounted > 0 ? 'low' : 'medium', $urlsCounted > 0 ? 'Частина карт порожня' : 'Карта сайту порожня', [
                'evidence' => 'Без жодного <url>: ' . self::listSome(array_column($emptyDocs, 'url'), 3) . '.',
                'why'      => 'Порожня карта не дає краулерам жодної адреси - її наявність нічого не змінює.',
                'fix'      => 'Перевірте генератор карти: він має додавати всі індексовані сторінки розділу.',
                'bad'      => $e0['info']['parsed']['root_tag'] !== '' ? $e0['info']['parsed']['root_tag'] . "\n</" . ($e0['info']['kind'] === 'index' ? 'sitemapindex' : 'urlset') . '>' : 'Файл без записів <url>',
                'good'     => "<urlset xmlns=\"" . self::NS . "\">\n  <url><loc>" . self::xmlUrl($this->final) . "</loc></url>\n  ...\n</urlset>",
                'code'     => '',
                'verify'   => 'Відкрийте ' . $e0['url'] . ' - мають бути записи <url>.',
                'effort'   => 'S',
            ]);
        }

        // службові адреси в карті
        $service = []; $serviceTypes = [];
        foreach ($entries as $e) {
            $rel = (string)preg_replace('#^https?://[^/]+#i', '', $e['loc']);
            if (!preg_match(self::RX_SERVICE, $rel)) continue;
            $service[] = $e['loc'];
            if (preg_match('/thank-?you|dyaku|spasibo|podyaka/i', $rel)) $serviceTypes['подяки після форми'] = true;
            elseif (preg_match('#/(cart|basket|korzina|koshyk|checkout)|add-to-cart#i', $rel)) $serviceTypes['кошик / оформлення'] = true;
            elseif (preg_match('#/wishlist#i', $rel)) $serviceTypes['обране'] = true;
            else $serviceTypes['вхід / кабінет'] = true;
        }
        if ($service) {
            $coverage = min($coverage, 85);
            $findings[] = $this->finding('service_urls', 'low', 'У карті є службові сторінки', [
                'evidence' => 'Адрес, схожих на службові (' . implode(', ', array_keys($serviceTypes)) . '): ' . self::num(count($service)) . '. Приклади: ' . self::listSome($service, 4) . '.',
                'why'      => 'Службові сторінки не мають цінності для пошуку й AI-відповідей; їхня присутність у карті знижує довіру краулерів до неї як до списку важливих сторінок.',
                'fix'      => 'Виключіть такі сторінки з карти (і закрийте їх noindex, якщо вони відкриті для індексації).',
                'bad'      => implode("\n", array_map(function ($u) { return '<url><loc>' . self::xmlUrl($u) . '</loc></url>'; }, array_slice($service, 0, 3))),
                'good'     => 'У карті лише сторінки, які мають сенс показувати в пошуку: послуги, товари, категорії, статті, контакти.',
                'code'     => '',
                'verify'   => 'Пошук у файлі карти за словами thank, cart, login - збігів немає.',
                'effort'   => 'S',
            ]);
        }

        // наявність ключових сторінок (лише коли список повний)
        $missingKey = [];
        $navInfo = null;
        if ($complete && $entries && count($entries) < self::MAX_LOCS) {
            $set = [];
            foreach ($entries as $e) $set[self::normUrl($e['loc'], true)] = true;
            // мовні версії, вказані лише через xhtml:link hreflang, Google теж знаходить - не вважаємо їх відсутніми
            foreach ($altSet as $a => $_) $set[self::normUrl((string)$a, true)] = true;
            foreach ($this->keyPages($ctx) as $kp) {
                $found = false;
                foreach ($kp['alts'] as $alt) if (isset($set[self::normUrl($alt, true)])) { $found = true; break; }
                if (!$found) $missingKey[] = $kp;
            }
            if ($page) $navInfo = $this->navCoverage($page, $set);
        }
        if ($missingKey || ($navInfo && $navInfo['confirmed'])) {
            $lines = [];
            foreach ($missingKey as $kp) $lines[] = $kp['label'] . ': ' . $kp['url'];
            $ev = [];
            if ($missingKey) $ev[] = 'Відкриті для індексації сторінки, яких немає в карті (' . self::num(count($entries)) . ' URL перевірено повністю): ' . implode('; ', $lines) . '.';
            if ($navInfo && $navInfo['confirmed']) {
                $ev[] = 'З ' . $navInfo['total'] . ' посилань меню на головній ' . $navInfo['missing'] . ' немає в карті. Перевірено ' . $navInfo['checked'] . ' з них - відповідають 200 без редиректу: ' . self::listSome($navInfo['confirmed'], 4) . '.';
            }
            $coverage = min($coverage, max(40, 100 - 15 * count($missingKey) - ($navInfo && $navInfo['confirmed'] ? (int)round(40 * $navInfo['missing'] / max(1, $navInfo['total'])) : 0)));
            $missUrls = [];
            foreach (array_merge(array_column($missingKey, 'url'), $navInfo ? $navInfo['confirmed'] : []) as $mu) $missUrls[self::normUrl($mu, true)] = $mu;
            $missUrls = array_values($missUrls);
            $navMiss = $navInfo && $navInfo['confirmed'];
            if ($navMiss) $pnText = 'Карта, ймовірно, неповна: частини сторінок меню в ній немає';
            elseif (count($missingKey) === 1 && $missingKey[0]['label'] === 'головна') $pnText = 'У карті немає головної сторінки';
            else $pnText = 'У карті немає частини ключових сторінок';
            $findings[] = $this->finding('pages_not_in_sitemap', 'low', $pnText, [
                'evidence' => implode("\n", $ev),
                'why'      => 'Сторінки поза картою краулери знаходять лише через посилання - повільніше й не завжди. Для AI-відповідей це означає, що нові або змінені сторінки довше не потрапляють у джерела.',
                'fix'      => 'Перевірте налаштування генератора: які типи сторінок і розділи виключені. Додайте в карту всі канонічні сторінки з кодом 200 без noindex.',
                'bad'      => 'Немає в карті:' . "\n" . implode("\n", array_slice($missUrls, 0, 6)),
                'good'     => implode("\n", array_map(function ($u) { return '<url><loc>' . self::xmlUrl($u) . '</loc></url>'; }, array_slice($missUrls, 0, 4))),
                'code'     => '',
                'verify'   => 'Знайдіть ці адреси у файлі карти; Google Search Console -> Перевірка URL -> «Файли Sitemap» у блоці «Виявлення».',
                'effort'   => 'S',
            ]);
        } elseif ($complete && $entries) {
            $passed[] = count($this->keyPages($ctx)) > 1 ? 'Головна та внутрішні сторінки з вибірки аудиту є в карті' : 'Головна сторінка є в карті';
        }
        $sub['coverage'] = $coverage;

        // ------------------------------------------------------------ 7. lastmod
        $hasUrlset = (bool)array_filter($docs, function ($d) { return $d['info']['kind'] === 'urlset'; });
        $lm = $this->lastmodStats($hasUrlset ? $entries : []);
        $data['lastmod'] = ['entries' => $lm['E'], 'with_lastmod' => $lm['L'], 'distinct' => $lm['distinct'], 'newest' => $lm['newest'] ? gmdate('Y-m-d', $lm['newest']) : null, 'age_days' => $lm['age']];
        if ($lm['E'] > 0) {
            $share = $lm['L'] / $lm['E'];
            $sample = $entries[0];
            if ($lm['L'] === 0) {
                $lmScore = 30;
                $rawFirst = $this->firstRawEntry($docs);
                $findings[] = $this->finding('no_lastmod', 'low', 'У карті немає дат lastmod', [
                    'evidence' => 'Жоден із ' . self::num($lm['E']) . ' перевірених записів <url> не містить <lastmod>.',
                    'why'      => 'lastmod підказує краулерам, які сторінки оновилися і їх варто переобійти. Bing прямо радить вказувати точний lastmod, Google використовує його, якщо дати правдиві.',
                    'fix'      => 'Увімкніть у генераторі карти виведення lastmod - дати останньої суттєвої зміни сторінки.',
                    'bad'      => $rawFirst !== '' ? $rawFirst : "<url>\n  <loc>" . self::xmlUrl($sample['loc']) . "</loc>\n</url>",
                    'good'     => "<url>\n  <loc>" . self::xmlUrl($sample['loc']) . "</loc>\n  <lastmod>" . gmdate('Y-m-d') . "</lastmod>\n</url>\n(дата - момент останньої реальної зміни сторінки, а не час генерації карти)",
                    'code'     => '',
                    'verify'   => 'Відкрийте карту: у кожному <url> є <lastmod> у форматі YYYY-MM-DD або YYYY-MM-DDThh:mm:ss+03:00.',
                    'effort'   => 'S',
                ]);
            } else {
                $fresh = $lm['age'] === null ? 0.7 : ($lm['age'] <= 90 ? 1.0 : ($lm['age'] <= 365 ? 0.8 : 0.5));
                $lmScore = (int)round((40 + 60 * $share) * $fresh);
                $identical = $lm['L'] >= 10 && $lm['distinct'] === 1;
                // Автогенерація: більшість дат - за останні 36 годин і в дуже вузькому вікні (час запуску генератора / синхронізації).
                // Новинні карти (news:news) не враховуються - там свіжі дати закономірні; для дат без часу вимагаємо майже 100%.
                $autogen = !$identical && $lm['base'] >= 20 && (
                    $lm['recent_has_time']
                        ? $lm['dense_share'] >= 0.5
                        : ($lm['recent_share'] >= 0.9 && $lm['base'] >= 50)
                );
                if ($identical || $autogen) {
                    $lmScore = min($lmScore, 45);
                    if ($identical) {
                        $ev = 'В усіх ' . self::num($lm['L']) . ' записах однакова дата lastmod: ' . $lm['top_value'] . '.';
                    } else {
                        $ev = $lm['recent_has_time']
                            ? self::num($lm['dense']) . ' з ' . self::num($lm['base']) . ' дат lastmod (' . round($lm['dense_share'] * 100) . '%) потрапляють у проміжок ' . self::spanText((int)$lm['dense_max'] - (int)$lm['dense_min'])
                                . ' за останню добу-півтори (' . gmdate('Y-m-d H:i:s', (int)$lm['dense_min']) . ' - ' . gmdate('H:i:s', (int)$lm['dense_max']) . ' UTC)'
                            : self::num($lm['recent']) . ' з ' . self::num($lm['base']) . ' дат lastmod (' . round($lm['recent_share'] * 100) . '%) датовані сьогодні або вчора і мають лише ' . self::plural($lm['recent_distinct'], 'значення', 'значення', 'значень') . ' (дата без часу)';
                        $ev .= ' - схоже на час генерації карти або масового технічного оновлення (наприклад, синхронізації залишків), а не на реальні зміни вмісту сторінок.'
                            . ' Найчастіше значення: ' . $lm['top_value'] . ' (' . self::plural($lm['top_count'], 'раз', 'рази', 'разів') . ').';
                    }
                    // у «як зараз» - саме підозрілі записи (з найчастішою або свіжою датою), а не перші-ліпші
                    $badRows = [];
                    foreach ($entries as $e) {
                        if ($e['lastmod'] === null || !empty($e['news'])) continue;
                        if ($e['lastmod'] === $lm['top_value'] || (!$identical && in_array($e['lastmod'], $lm['recent_values'], true))) $badRows[$e['loc']] = '<loc>' . self::xmlUrl($e['loc']) . '</loc> <lastmod>' . $e['lastmod'] . '</lastmod>';
                        if (count($badRows) >= 3) break;
                    }
                    $findings[] = $this->finding('lastmod_autogenerated', 'low', $identical ? 'Усі дати lastmod однакові' : 'Дати lastmod, ймовірно, ставляться автоматично', [
                        'evidence' => $ev,
                        'why'      => 'Google використовує lastmod, лише якщо дати стабільно відповідають реальним змінам сторінок; інакше поле ігнорується і не допомагає краулерам знаходити оновлений вміст.',
                        'fix'      => 'Налаштуйте генератор так, щоб lastmod брався з дати останньої зміни вмісту сторінки (опис, ціна, наявність, текст статті), а не з часу формування файлу чи технічної синхронізації.',
                        'bad'      => implode("\n", array_values($badRows)),
                        'good'     => "<loc>" . self::xmlUrl($sample['loc']) . "</loc> <lastmod>2026-03-14</lastmod>\n(дата реального оновлення конкретної сторінки)",
                        'code'     => '',
                        'verify'   => 'Змініть одну сторінку і перегенеруйте карту: lastmod має змінитися лише в неї.',
                        'effort'   => 'M',
                    ]);
                }
                if ($lm['age'] !== null && $lm['age'] > 365) {
                    $sev = in_array($siteType, ['media'], true) ? 'medium' : 'low';
                    $findings[] = $this->finding('lastmod_stale', $sev, 'Карта давно не оновлювалась', [
                        'evidence' => 'Найновіший lastmod серед ' . self::num($lm['L']) . ' записів: ' . gmdate('Y-m-d', (int)$lm['newest']) . ' (' . self::num($lm['age']) . ' днів тому).',
                        'why'      => 'Якщо карта не оновлюється автоматично, нові сторінки в неї не потрапляють, а краулери не бачать змін. Для AI-пошуку свіжість джерела має значення.',
                        'fix'      => 'Перевірте, що карта генерується автоматично (за подією публікації або щодоби), а не лежить статичним файлом.',
                        'bad'      => '<lastmod>' . gmdate('Y-m-d', (int)$lm['newest']) . '</lastmod> - найновіша дата в карті',
                        'good'     => 'Нові й оновлені сторінки з\'являються в карті з поточною датою lastmod протягом доби.',
                        'code'     => '',
                        'verify'   => 'Опублікуйте тестову сторінку і перевірте, що вона з\'явилася в карті.',
                        'effort'   => 'M',
                    ]);
                }
                if ($lm['invalid'] || $lm['future']) {
                    $lmScore = min($lmScore, 70);
                    $bits = [];
                    if ($lm['invalid']) $bits[] = 'не у форматі W3C Datetime: ' . count($lm['invalid']) . ' (напр. «' . $lm['invalid'][0] . '»)';
                    if ($lm['future']) $bits[] = 'дати в майбутньому: ' . count($lm['future']) . ' (напр. «' . $lm['future'][0] . '»)';
                    $findings[] = $this->finding('lastmod_invalid', 'low', 'Некоректні дати lastmod', [
                        'evidence' => 'Записи з lastmod ' . implode('; ', $bits) . '.',
                        'why'      => 'Некоректні дати краулери відкидають, а дати з майбутнього підривають довіру до всього поля lastmod.',
                        'fix'      => 'Виводьте дату у форматі YYYY-MM-DD або YYYY-MM-DDThh:mm:ss+03:00 і перевірте часовий пояс сервера.',
                        'bad'      => '<lastmod>' . ($lm['invalid'][0] ?? $lm['future'][0]) . '</lastmod>',
                        'good'     => '<lastmod>' . gmdate('Y-m-d') . '</lastmod> або <lastmod>' . gmdate('Y-m-d') . 'T10:30:00+03:00</lastmod>',
                        'code'     => '',
                        'verify'   => 'validator: https://www.xml-sitemaps.com/validate-xml-sitemap.html або Search Console -> Файли Sitemap без попереджень.',
                        'effort'   => 'S',
                    ]);
                }
                $partial = $share < 0.5 && $lm['E'] >= 10;
                if ($partial) {
                    $noLm = [];
                    foreach ($entries as $e) {
                        if ($e['lastmod'] === null) $noLm[] = $e['loc'];
                        if (count($noLm) >= 3) break;
                    }
                    $findings[] = $this->finding('lastmod_partial', 'low', 'lastmod є лише в частині записів карти', [
                        'evidence' => 'lastmod мають ' . self::num($lm['L']) . ' з ' . self::num($lm['E']) . ' перевірених записів (' . round($share * 100) . '%). Без дати, напр.: ' . self::listSome($noLm, 3) . '.',
                        'why'      => 'Для записів без lastmod краулер не знає, чи змінилася сторінка, і переобходить її за власним графіком - оновлення довше потрапляють у пошук і AI-відповіді.',
                        'fix'      => 'Додайте lastmod в усі карти (зазвичай бракує окремих генераторів: зображень, брендів, фільтрів, статичних сторінок).',
                        'bad'      => implode("\n", array_map(function ($u) { return "<url><loc>" . self::xmlUrl($u) . "</loc></url>"; }, $noLm)),
                        'good'     => $noLm ? "<url>\n  <loc>" . self::xmlUrl($noLm[0]) . "</loc>\n  <lastmod>[дата останньої зміни сторінки]</lastmod>\n</url>" : '<lastmod>YYYY-MM-DD</lastmod> у кожному <url>',
                        'code'     => '',
                        'verify'   => 'Відкрийте кожну карту з індексу: у кожному <url> є <lastmod>.',
                        'effort'   => 'S',
                    ]);
                }
                if (!$identical && !$autogen && !$partial && !$lm['invalid'] && !$lm['future']) {
                    $passed[] = 'lastmod у ' . round($share * 100) . '% записів, найновіший ' . gmdate('Y-m-d', (int)$lm['newest']) . ($lm['age'] !== null && $lm['age'] <= 90 ? ' (карта свіжа)' : '');
                }
            }
            $sub['lastmod'] = $lmScore;
        }

        // ------------------------------------------------------------ 8. протокол і хост
        $locsForHost = array_column($entries, 'loc');
        foreach ($children as $c) $locsForHost[] = $c['loc'];
        $mm = $this->hostMismatch($locsForHost);
        // адреса самої карти в robots.txt: інший хост дозволений протоколом, а от http замість https чи інший варіант www - ні
        $mmRoots = $this->hostMismatch(array_keys($declared));
        $rootMis = array_values(array_unique(array_merge($mmRoots['scheme'], $mmRoots['www'])));
        $data['mismatch'] = ['checked' => $mm['checked'], 'scheme' => count($mm['scheme']), 'www' => count($mm['www']), 'foreign' => count($mm['foreign']), 'robots_ref' => count($rootMis)];
        if ($mm['checked'] > 0 || $rootMis) {
            $bad = count(array_unique(array_merge($mm['scheme'], $mm['www'], $mm['foreign'])));
            $cons = $mm['checked'] > 0 ? (int)round(100 * ($mm['checked'] - $bad) / $mm['checked']) : 100;
            $entryMis = $mm['scheme'] || $mm['www'];
            if ($entryMis || $rootMis) {
                $cons = min($cons, $entryMis ? 70 : 85);
                $bits = [];
                if ($mm['scheme']) $bits[] = self::num(count($mm['scheme'])) . ' з протоколом, що відрізняється від головної (напр. ' . $mm['scheme'][0] . ')';
                if ($mm['www']) $bits[] = self::num(count($mm['www'])) . ' з іншим варіантом хоста www/без www (напр. ' . $mm['www'][0] . ')';
                $ev = 'Основна адреса сайту: ' . $this->origin() . '.';
                if ($entryMis) $ev .= ' Перевірено URL з карти: ' . self::num($mm['checked']) . '. З них ' . implode('; ', $bits) . '.';
                if ($rootMis) $ev .= ' Адреса карти в robots.txt теж на іншому протоколі або хості: ' . self::listSome($rootMis, 2) . '.';
                $examples = array_slice(array_values(array_unique(array_merge($rootMis, $mm['scheme'], $mm['www']))), 0, 3);
                $findings[] = $this->finding('scheme_host_mismatch', $entryMis ? 'medium' : 'low', $entryMis ? 'URL у карті не збігаються з основною адресою сайту (протокол або www)' : 'Адреса карти в robots.txt не збігається з основною адресою сайту (протокол або www)', [
                    'evidence' => $ev,
                    'why'      => $entryMis
                        ? 'Кожна така адреса веде через редирект або на дубль. Краулер витрачає ресурс на зайві запити, а карта перестає бути надійним списком канонічних сторінок.'
                        : 'Сама карта при цьому коректна, але зайвий або неканонічний рядок Sitemap змушує краулер завантажувати ту саму карту вдруге чи через редирект. Варто лишити одну адресу на основному хості.',
                    'fix'      => ($entryMis ? 'Налаштуйте генератор на основний протокол і хост (той, на який редиректить головна).' : '')
                        . ($rootMis ? ($entryMis ? ' ' : '') . 'У robots.txt вкажіть адресу карти на основному протоколі й хості.' : ''),
                    'bad'      => implode("\n", $examples),
                    'good'     => implode("\n", array_map(function ($u) { return $this->toCanonicalHost($u); }, $examples)),
                    'code'     => $rootMis ? 'Sitemap: ' . $this->toCanonicalHost($rootMis[0]) : '',
                    'verify'   => $entryMis
                        ? 'curl -s ' . $this->firstOf($okRoots) . ' | grep -c "' . ($mm['scheme'] ? '<loc>' . strtolower((string)parse_url($mm['scheme'][0], PHP_URL_SCHEME)) . '://' : '://' . strtolower((string)parse_url($mm['www'][0], PHP_URL_HOST)) . (parse_url($mm['www'][0], PHP_URL_PORT) ? ':' . parse_url($mm['www'][0], PHP_URL_PORT) : '') . '/') . '" - має бути 0 (для кожної дочірньої карти теж).'
                        : 'Відкрийте ' . $this->base . '/robots.txt - адреса в рядку Sitemap починається з ' . $this->origin() . '/.',
                    'effort'   => 'S',
                ]);
            }
            if ($mm['foreign']) {
                $cons = min($cons, 85);
                $hosts = array_values(array_unique(array_map(function ($u) { return (string)parse_url($u, PHP_URL_HOST); }, $mm['foreign'])));
                $findings[] = $this->finding('foreign_hosts', 'low', 'У карті є адреси інших хостів', [
                    'evidence' => self::num(count($mm['foreign'])) . ' URL з ' . self::num($mm['checked']) . ' перевірених - на інших хостах (' . self::listSome($hosts, 3) . '), напр. ' . $mm['foreign'][0] . '.',
                    'why'      => 'За протоколом карта може містити лише адреси свого хоста (піддомен - теж окремий хост). Адреси інших хостів Google приймає, лише якщо право на них підтверджено: обидва сайти підтверджені в Search Console або robots.txt іншого хоста посилається на цю карту. Інакше Search Console показує помилку «URL не дозволено».',
                    'fix'      => 'Якщо це не підтверджений у Search Console піддомен чи домен - винесіть ці адреси в карту відповідного сайту.',
                    'bad'      => implode("\n", array_slice($mm['foreign'], 0, 3)),
                    'good'     => 'У карті ' . $this->base . '/ лише адреси ' . (string)parse_url($this->final, PHP_URL_HOST) . '; інші хости мають власні карти.',
                    'code'     => '',
                    'verify'   => 'Google Search Console -> Файли Sitemap: без попереджень «URL не дозволено».',
                    'effort'   => 'S',
                ]);
            }
            if ($mm['checked'] > 0 && !$mm['scheme'] && !$mm['www'] && !$mm['foreign'] && !$rootMis) {
                $passed[] = 'URL у карті (перевірено ' . self::num($mm['checked']) . ') на тому ж протоколі й хості, що й головна: ' . $this->scheme() . '://' . (string)parse_url($this->final, PHP_URL_HOST);
            }
            $sub['consistency'] = $cons;
        }

        // ------------------------------------------------------------ 9. коди відповіді вибірки URL
        $sampleUrls = $this->pickSampleUrls($entries);
        if ($sampleUrls) {
            $t1 = microtime(true);
            $checks = $this->checkUrls($sampleUrls);
            $tm['url_checks'] = (int)round((microtime(true) - $t1) * 1000);
            $data['url_checks'] = array_map(function ($c) { return $c['label']; }, $checks);
            $vr = count(array_filter($checks, function ($c) { return !empty($c['visitor_redirect']); }));
            if ($vr) $data['visitor_only_redirects'] = $vr;
            $known = array_filter($checks, function ($c) { return $c['state'] !== 'unknown'; });
            if ($known) {
                $ok = 0.0; $badList = []; $noindex = []; $canon = [];
                foreach ($known as $u => $c) {
                    if ($c['state'] === 'ok') {
                        if ($c['noindex']) $noindex[$u] = $c;
                        elseif ($c['canonical'] !== null) { $canon[$u] = $c; $ok += 0.5; }
                        else $ok += empty($c['bing_redirect']) ? 1 : 0.75;
                    } else {
                        $badList[$u] = $c;
                    }
                }
                $sub['url_status'] = (int)round(100 * $ok / count($known));
                if ($badList) {
                    $lines = [];
                    $goods = [];
                    $toHome = 0;
                    $crossHosts = [];
                    $myHost = strtolower((string)parse_url($this->final, PHP_URL_HOST));
                    foreach ($badList as $u => $c) {
                        $lines[] = $u . ' - ' . $c['label'];
                        if (!empty($c['to_home'])) { $toHome++; continue; }
                        if ($c['state'] !== 'redirect') continue;
                        $fh = strtolower((string)parse_url((string)$c['final_url'], PHP_URL_HOST));
                        // редирект на інший хост (піддомен): кінцеву адресу в цю карту додати не можна - лише в карту того хоста
                        if ($fh !== '' && !Crawler::sameSite($fh, $myHost)) { $crossHosts[$fh] = true; continue; }
                        if ($c['final_status'] === 200) $goods[] = $c['final_url'];
                    }
                    $good = $goods ? "У карті кінцеві адреси:\n" . implode("\n", array_slice($goods, 0, 4)) : 'Кожна адреса з карти відповідає HTTP 200 без редиректу.';
                    if ($toHome) $good .= "\nАдреси, які редиректять на головну, з карти прибрано.";
                    if ($crossHosts) $good .= "\nАдреси, що переїхали на " . implode(', ', array_keys($crossHosts)) . ', прибрано з цієї карти й додано в карту того хоста.';
                    $findings[] = $this->finding('bad_status', 'medium', 'У карті є адреси з редиректом або помилкою', [
                        'evidence' => 'Перевірено ' . count($known) . ' випадкових URL з карти, проблемних: ' . count($badList) . ".\n" . implode("\n", $lines),
                        'why'      => 'Карта має містити лише кінцеві адреси з кодом 200. Редиректи й помилки витрачають бюджет сканування і вчать краулери менше довіряти карті.',
                        'fix'      => 'Замініть адреси з редиректом на кінцеві, а 404/410 ' . ($toHome ? 'і редиректи на головну (видалені сторінки) ' : '') . 'приберіть з карти.'
                            . ($crossHosts ? ' Сторінки, що переїхали на інший хост (' . implode(', ', array_keys($crossHosts)) . '), мають бути в карті того хоста, а не тут.' : '')
                            . ' Найчастіше причина - генератор не враховує видалені й перенесені сторінки або формат слеша в кінці.',
                        'bad'      => implode("\n", $lines),
                        'good'     => $good,
                        'code'     => '',
                        'verify'   => 'Screaming Frog -> Mode: List -> Download XML Sitemap: усі URL з кодом 200; Google Search Console -> Сторінки -> фільтр «Усі надіслані сторінки».',
                        'effort'   => 'S',
                    ]);
                }
                if ($noindex || $canon) {
                    $lines = [];
                    $paramCanon = array_filter($canon, function ($c) { return !empty($c['canonical_param']); });
                    $langCanon = array_filter($canon, function ($c) { return !empty($c['canonical_lang']); });
                    $otherCanon = count($canon) - count($paramCanon) - count($langCanon);
                    foreach ($noindex as $u => $c) $lines[] = $u . ' - ' . $c['noindex'];
                    foreach ($canon as $u => $c) {
                        $note = !empty($c['canonical_param']) ? ' (та сама сторінка з трекінг-параметром)'
                            : (!empty($c['canonical_lang']) ? ' (інша мовна версія' . (!empty($c['hreflang_self']) ? ', хоча сама сторінка вказана в hreflang' : '') . ')' : '');
                        $lines[] = $u . ' - canonical вказує на ' . $c['canonical'] . $note;
                    }
                    // що саме не так: noindex / мовний canonical (помилка тегу, важливіше) / решта canonical
                    if ($noindex) { $sev = 'medium'; $text = 'У карті є сторінки, закриті від індексації'; }
                    elseif ($langCanon && !$otherCanon && !$paramCanon) { $sev = 'medium'; $text = 'Мовні версії з карти мають canonical на іншу мову'; }
                    elseif ($langCanon) { $sev = 'medium'; $text = 'У карті є неканонічні адреси, зокрема мовні версії з canonical на іншу мову'; }
                    else { $sev = 'low'; $text = 'У карті є неканонічні адреси'; }
                    $fix = [];
                    if ($noindex || $otherCanon) $fix[] = 'Додавайте в карту лише канонічні сторінки без noindex; закриті та неканонічні адреси виключіть у генераторі.';
                    if ($langCanon) $fix[] = 'Для мовних версій помилка в тегу canonical, а не в карті: кожна мовна версія має вказувати canonical сама на себе, а зв\'язок мов задається через hreflang. Інакше Google вважає, наприклад, англійську сторінку дублем української і не показує її в пошуку та AI-відповідях англійською.';
                    if ($paramCanon) $fix[] = 'Де canonical відрізняється від адреси лише трекінг-параметром (utm_source, gclid тощо), помилка теж у тегу canonical: він має вказувати на чисту адресу сторінки, карту тут міняти не треба.';
                    $good = [];
                    if ($langCanon) {
                        $lu = (string)array_key_first($langCanon);
                        $lf = (string)$langCanon[$lu]['final_url'];
                        $good[] = 'На ' . $lf . ":\n" . '<link rel="canonical" href="' . htmlspecialchars($lf, ENT_QUOTES, 'UTF-8') . '">' . "\n" . '<link rel="alternate" hreflang="..." href="..."> - для всіх мовних версій, як зараз';
                    }
                    if ($paramCanon) $good[] = '<link rel="canonical" href="' . htmlspecialchars((string)preg_replace('/\?.*$/', '', (string)reset($paramCanon)['canonical']), ENT_QUOTES, 'UTF-8') . '">';
                    if ($noindex || $otherCanon) $good[] = 'У карті тільки адреси, у яких canonical вказує сам на себе і немає noindex.';
                    $findings[] = $this->finding('non_indexable', $sev, $text, [
                        'evidence' => 'Серед ' . count($known) . ' перевірених URL з карти: ' . ($noindex ? count($noindex) . ' з noindex' : '') . ($noindex && $canon ? ', ' : '') . ($canon ? count($canon) . ' з canonical на іншу адресу' : '') . ".\n" . implode("\n", $lines),
                        'why'      => 'Карта і директиви сторінки суперечать одна одній: карта каже «індексуй», сторінка - «ні» або «канонічна інша». Google у такому разі довіряє сторінці, тож ці адреси не потрапляють в індекс, а карта стає менш корисною.',
                        'fix'      => implode(' ', $fix),
                        'bad'      => implode("\n", array_slice($lines, 0, 4)),
                        'good'     => implode("\n\n", $good),
                        'code'     => $langCanon ? '<link rel="canonical" href="' . htmlspecialchars((string)$langCanon[(string)array_key_first($langCanon)]['final_url'], ENT_QUOTES, 'UTF-8') . '">' : '',
                        'verify'   => 'Google Search Console -> Перевірка URL для цих адрес: «Канонічна сторінка, вибрана Google» має збігатися з самою адресою; звіт «Сторінки» -> «Виключено тегом noindex» і «Дублікат, Google вибрав іншу канонічну» для надісланих URL.',
                        'effort'   => 'S',
                    ]);
                }
                $bingRedir = array_filter($known, function ($c) { return !empty($c['bing_redirect']) || !empty($c['oai_redirect']); });
                if ($bingRedir) {
                    $lines = []; $bots = [];
                    foreach ($bingRedir as $c) {
                        if (!empty($c['bing_redirect'])) $bots['Bingbot'] = true;
                        if (!empty($c['oai_redirect'])) $bots['OAI-SearchBot'] = true;
                    }
                    $botList = implode(' і ', array_keys($bots));
                    foreach (array_slice($bingRedir, 0, 3, true) as $u => $c) $lines[] = $u . ' -> ' . ($c['bing_redirect'] ?? $c['oai_redirect']);
                    $u0 = (string)array_key_first($bingRedir);
                    $c0 = $bingRedir[$u0];
                    $ua0 = !empty($c0['oai_redirect']) ? 'OAI-SearchBot/1.0' : 'bingbot/2.0';
                    $findings[] = $this->finding('redirect_for_bing', 'low', 'URL з карти, ймовірно, редиректять ' . $botList . ', хоча Googlebot отримує 200', [
                        'evidence' => count($bingRedir) . ' з ' . count($known) . ' перевірених URL з карти відповідають 200 для User-Agent Googlebot, але редиректять User-Agent ' . $botList . ' (і звичайних відвідувачів). Схоже на мовний або регіональний редирект, з якого виключено лише Googlebot. Перевіряли лише за User-Agent: справжніх ботів з їхніх IP сервер може обробляти інакше.' . "\n" . implode("\n", $lines),
                        'why'      => 'На індексі Bing працює Copilot, його також використовують інші AI-пошуковики, а OAI-SearchBot збирає сторінки для пошуку ChatGPT. Якщо бот не може відкрити мовну версію сторінки, ця версія не потрапить у його індекс і AI-відповіді.',
                        'fix'      => 'Не редиректьте пошукових і AI-ботів (bingbot, OAI-SearchBot, ChatGPT-User, PerplexityBot, ClaudeBot) за мовою чи регіоном; відвідувачам пропонуйте іншу мовну версію банером, а не примусовим редиректом.',
                        'bad'      => 'curl -I -A "' . $ua0 . '" ' . $u0 . "\nHTTP 302 -> " . ($c0['oai_redirect'] ?? $c0['bing_redirect']),
                        'good'     => 'curl -I -A "' . $ua0 . '" ' . $u0 . "\nHTTP 200 (та сама сторінка, що й для Googlebot)",
                        'code'     => '',
                        'verify'   => 'curl -I -A "Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)" ' . $u0 . ' - очікуємо 200; Bing Webmaster Tools -> Перевірка URL для цієї адреси.',
                        'effort'   => 'M',
                    ]);
                }
                if (!$badList && !$noindex && !$canon) {
                    $vr = count(array_filter($known, function ($c) { return !empty($c['visitor_redirect']); }));
                    $passed[] = 'Вибірка URL з карти (' . count($known) . ' з ' . count($known) . ') відповідає 200 без noindex і з canonical на себе'
                        . ($vr ? ' (для Googlebot; ' . $vr . ' з них редиректять звичайних відвідувачів на іншу мовну версію)' : ', без редиректів');
                }
            }
            $unknownN = count($checks) - count($known);
            if ($unknownN > 0) $data['url_checks_unknown'] = $unknownN;
        }

        // ------------------------------------------------------------ результат
        $tm['total'] = (int)round((microtime(true) - $t0) * 1000);
        $data['timing_ms'] = $tm;
        $score = $this->scoreFrom([
            [$sub['exists'], 20], [$sub['robots_ref'], 10], [$sub['valid_xml'], 15], [$sub['coverage'], 15],
            [$sub['lastmod'], 10], [$sub['consistency'], 10], [$sub['url_status'], 20],
        ]);
        $summary = $this->summaryFound(count($okRoots), $urlsCounted, $urlsEstimated, $complete, $childListed, $childOk, $findings);
        return $this->result($score, $sub, $labels, $findings, $passed, $data, $summary);
    }

    // ================================================================== not found

    private function notFound(array $robots, array $declared, array $roots, array $guesses, ?Page $page, array $ctx, array $data, array $labels): array
    {
        $all = $roots + $guesses;
        $unknown = array_filter($all, function ($i) { return in_array($i['state'], ['blocked', 'timeout'], true); });
        $declaredUnknown = array_intersect_key($unknown, $declared);
        $smUnknown = isset($unknown[$this->base . '/sitemap.xml']);
        $lines = [];
        foreach ($all as $u => $info) $lines[] = 'GET ' . self::shortPath($u, $this->base) . ' -> ' . $this->stateLabel($info);

        $robotsUnknown = in_array($robots['state'], ['blocked', 'timeout'], true);
        if (($declared && count($declaredUnknown) === count($declared)) || (!$declared && ($smUnknown || $robotsUnknown))) {
            $first = $declaredUnknown ? reset($declaredUnknown) : ($unknown[$this->base . '/sitemap.xml'] ?? ($unknown ? reset($unknown) : null));
            if ($first) {
                $why = ($first['challenge'] ?? null) ? 'сервер віддає сторінку перевірки захисту від ботів (' . $first['challenge'] . ')' : 'сервер не відповів вчасно';
                $why .= ' і для нашого бота, і для браузерного запиту';
            } else {
                $why = 'robots.txt недоступний (' . ($robots['challenge'] ? 'перевірка захисту від ботів, ' . $robots['challenge'] : 'немає відповіді') . '), тож нестандартну адресу карти з нього дізнатися не можна, а за стандартними адресами карти немає';
            }
            $reason = 'Не вдалося отримати карту сайту: ' . $why . '. '
                . ($declared ? 'У robots.txt вказано: ' . self::listSome(array_keys($declared), 3) . '. ' : '')
                . 'Відсутність карти не підтверджено - перевірте вручну або в Search Console -> Файли Sitemap.';
            return $this->skipped($reason, $data + ['attempts' => $lines]);
        }

        $sub = array_fill_keys(array_keys($labels), null);
        $sub['exists'] = 0;
        if ($robots['state'] === 'ok' || in_array($robots['state'], ['missing', 'html'], true)) $sub['robots_ref'] = $declared ? 20 : 0;

        $cms = self::cmsHint($page);
        $sitemapUrl = $this->base . '/sitemap.xml';
        $evidence = [];
        if ($robots['state'] === 'ok') {
            $evidence[] = $declared ? 'У robots.txt є рядки Sitemap, але жоден не веде на робочу карту.' : 'У robots.txt немає рядка Sitemap.';
        } elseif (in_array($robots['state'], ['missing', 'html'], true)) {
            $evidence[] = 'robots.txt відсутній (' . ($robots['state'] === 'html' ? 'замість нього HTML-сторінка' : 'HTTP ' . $robots['status']) . '), тож посилання на карту в ньому теж немає.';
        }
        $evidence[] = 'Перевірені адреси:' . "\n" . implode("\n", $lines);
        $soft = array_filter($all, function ($i) { return $i['state'] === 'soft404'; });
        if ($soft) $evidence[] = 'Адреси з HTML-сторінкою замість XML (soft-404) не вважаються картою.';
        if ($unknown) $evidence[] = 'Не вдалося перевірити (захист від ботів / тайм-аут): ' . self::listSome(array_keys($unknown), 3) . '.';

        $urls = $this->templateUrls($page, $ctx);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="' . self::NS . '">' . "\n";
        foreach ($urls as $u) $xml .= "  <url>\n    <loc>" . self::xmlUrl($u) . "</loc>\n  </url>\n";
        $xml .= '</urlset>';

        $fix = 'Увімкніть автоматичну генерацію sitemap.xml з датами lastmod і додайте в robots.txt рядок «Sitemap: ' . $sitemapUrl . '».';
        if ($cms) $fix .= ' ' . $cms['name'] . ': ' . $cms['hint'] . '.';
        $finding = $this->finding('missing', 'high', 'XML-карту сайту не знайдено', [
            'evidence' => implode("\n", $evidence),
            'why'      => 'Карта - найпростіший спосіб показати краулерам Google і Bing (на їхніх індексах працюють AI Overviews, Copilot та інші AI-відповіді) усі важливі сторінки і дати їх оновлення. Без неї нові й глибокі сторінки знаходяться лише через посилання і повільніше потрапляють у джерела.',
            'fix'      => $fix,
            'bad'      => implode("\n", array_slice($lines, 0, 6)) . ($robots['state'] === 'ok' && !$declared ? "\nrobots.txt: рядка Sitemap немає" : ''),
            'good'     => 'GET /sitemap.xml -> 200, Content-Type: application/xml' . "\n" . '<urlset xmlns="' . self::NS . '"> з усіма індексованими сторінками і <lastmod>' . "\n" . 'robots.txt: Sitemap: ' . $sitemapUrl,
            'code'     => $xml,
            'verify'   => 'curl -I ' . $sitemapUrl . ' (очікуємо 200 і application/xml); надішліть карту в Google Search Console -> Файли Sitemap і в Bing Webmaster Tools -> Sitemaps - статус «Успішно».',
            'effort'   => $cms ? 'S' : 'M',
        ]);
        $data['template_urls'] = count($urls);
        return $this->result(0, $sub, $labels, [$finding], [], $data, 'XML-карту не знайдено ні в robots.txt, ні за стандартними адресами.');
    }

    // ================================================================== findings helpers

    private function findingBrokenRef(array $declaredBad, array $roots, string $workingUrl, bool $someDeclaredOk): array
    {
        $lines = []; $raw = [];
        foreach ($declaredBad as $du => $ln) {
            $lines[] = 'Рядок ' . $ln['line'] . ': «' . $ln['raw'] . '» -> ' . $this->stateLabel($roots[$du] ?? ['state' => 'timeout']);
            $raw[] = $ln['raw'];
        }
        $note = $someDeclaredOk ? 'Інші рядки Sitemap ведуть на робочу карту.' : 'Робоча карта знайдена за адресою ' . $workingUrl . ', але в robots.txt її немає.';
        return $this->finding('robots_broken_ref', 'medium', 'robots.txt посилається на неробочу карту', [
            'evidence' => implode("\n", $lines) . "\n" . $note,
            'why'      => 'Краулери, які беруть карту з robots.txt (Bing, AI-боти), отримують помилку замість списку сторінок.',
            'fix'      => $someDeclaredOk ? 'Приберіть або виправте неробочі рядки Sitemap.' : 'Замініть адресу в robots.txt на робочу карту.',
            'bad'      => implode("\n", $raw),
            'good'     => 'Sitemap: ' . $workingUrl,
            'code'     => 'Sitemap: ' . $workingUrl,
            'verify'   => 'curl -I ' . $workingUrl . ' (очікуємо 200); відкрийте ' . $this->base . '/robots.txt і перевірте рядок Sitemap.',
            'effort'   => 'S',
        ]);
    }

    private function findingNotInRobots(array $robots, string $workingUrl): array
    {
        $line = 'Sitemap: ' . $workingUrl;
        if ($robots['state'] === 'ok') {
            $cur = $this->robotsExcerpt($robots['body']);
            return $this->finding('not_in_robots', 'low', 'Карта не вказана в robots.txt', [
                'evidence' => 'Карта ' . $workingUrl . ' доступна, але в robots.txt немає рядка Sitemap.',
                'why'      => 'Bing, Google та AI-краулери читають директиву Sitemap у robots.txt - так карту знаходять без ручного надсилання в панелях вебмайстрів.',
                'fix'      => 'Додайте в robots.txt рядок з повною адресою карти (можна в кінці файлу).',
                'bad'      => $cur !== '' ? $cur : '(robots.txt порожній)',
                'good'     => ($cur !== '' ? $cur . "\n\n" : '') . $line,
                'code'     => $line,
                'verify'   => 'Відкрийте ' . $this->base . '/robots.txt - рядок Sitemap на місці; Google Search Console -> Налаштування -> robots.txt: файл оновлено.',
                'effort'   => 'S',
            ]);
        }
        return $this->finding('not_in_robots', 'low', 'robots.txt відсутній, тому карта в ньому не вказана', [
            'evidence' => 'GET ' . $this->base . '/robots.txt -> ' . ($robots['state'] === 'html' ? 'HTML-сторінка з кодом 200 замість текстового файлу' : 'HTTP ' . $robots['status']) . '. Карта ' . $workingUrl . ' доступна.',
            'why'      => 'Директива Sitemap у robots.txt - стандартний спосіб показати карту всім краулерам, включно з AI-ботами, без ручного надсилання.',
            'fix'      => 'Створіть robots.txt у корені сайту з базовими правилами і рядком Sitemap.',
            'bad'      => 'GET /robots.txt -> ' . ($robots['state'] === 'html' ? '200, text/html (сторінка сайту)' : (string)$robots['status']),
            'good'     => "User-agent: *\nDisallow:\n\n" . $line,
            'code'     => "User-agent: *\nDisallow:\n\n" . $line,
            'verify'   => 'curl -s ' . $this->base . '/robots.txt - текстовий файл з рядком Sitemap, Content-Type: text/plain.',
            'effort'   => 'S',
        ]);
    }

    private function summaryFound(int $roots, int $counted, int $estimated, bool $complete, int $childListed, int $childOk, array $findings): string
    {
        if ($complete) $n = self::num($counted) . ' URL';
        elseif ($roots > 1) $n = self::plural($roots, 'файл', 'файли', 'файлів') . ' з robots.txt, у перевірених - ' . self::num($counted) . ' URL';
        elseif ($childListed > 0) $n = 'індекс із ' . self::num($childListed) . ' карт, ' . self::approx($estimated) . ' URL за оцінкою з ' . $childOk . ' перевірених';
        else $n = self::num($counted) . '+ URL';
        $s = 'Карта знайдена (' . $n . ')';
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $top = null;
        foreach ($findings as $f) if ($top === null || $order[$f['severity']] < $order[$top['severity']]) $top = $f;
        if ($top === null) return $s . ', без зауважень.';
        $t = $top['text'];
        $second = mb_substr($t, 1, 1);
        if ($second !== '' && mb_strtolower($second) === $second) $t = mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1); // «URL ...» не чіпаємо
        return $s . '; головне зауваження: ' . $t . '.';
    }

    private function describeRoot(string $u, array $r, int $childTotal, int $counted, int $estimated, bool $complete, int $childOk = 0): string
    {
        $how = $r['source'] === 'robots' ? 'з robots.txt' : 'за стандартною адресою';
        $gz = $r['gz'] ? ', стиснута .gz' : '';
        if ($r['kind'] === 'index') {
            return 'Індекс карт ' . $u . ' (' . $how . ') відповідає 200: ' . self::plural($childTotal, 'дочірня карта', 'дочірні карти', 'дочірніх карт')
                . ($counted > 0 ? ', ' . ($complete ? self::num($counted) . ' URL' : self::approx($estimated) . ' URL (оцінка за ' . $childOk . ' з ' . self::num($childTotal) . ' файлів)') : '') . $gz;
        }
        $kind = $r['kind'] === 'text' ? 'Текстова карта' : ($r['kind'] === 'feed' ? 'RSS/Atom-стрічка як карта' : 'Карта');
        return $kind . ' ' . $u . ' (' . $how . ') відповідає 200: ' . self::num($counted) . ' URL' . ($r['truncated'] ? ' (файл більший за 6 МБ, пораховано частково)' : '') . $gz;
    }

    // ================================================================== fetch / classify

    /** [url => info]; бот, а для 401/403/429/503/перевірки - повтор браузером. */
    private function fetchDocs(array $urls, int $timeout): array
    {
        $urls = array_values(array_unique(array_filter($urls, 'is_string')));
        if (!$urls) return [];
        $res = Crawler::fetchMulti($urls, $this->mode, $timeout);
        $out = []; $retry = [];
        foreach ($urls as $u) {
            $out[$u] = $this->classifyDoc($u, $res[$u] ?? null);
            if ($this->mode === null && $out[$u]['retry']) $retry[] = $u;
        }
        if ($retry) {
            $res2 = Crawler::fetchMulti($retry, 'browser', $timeout);
            foreach ($retry as $u) {
                $i2 = $this->classifyDoc($u, $res2[$u] ?? null);
                if (!in_array($i2['state'], ['blocked', 'timeout'], true)) {
                    if ($i2['state'] === 'ok') $i2['bot_blocked'] = true;
                    $out[$u] = $i2;
                } else {
                    $out[$u]['state'] = 'blocked';
                    if ($out[$u]['challenge'] === null) $out[$u]['challenge'] = $i2['challenge'];
                }
            }
        }
        return $out;
    }

    private function classifyDoc(string $u, ?array $r): array
    {
        $info = [
            'url' => $u, 'state' => 'timeout', 'kind' => null, 'status' => 0, 'final_url' => $u, 'ctype' => '',
            'fetched_as' => 'bot', 'redirects' => 0, 'gz' => false, 'truncated' => false, 'bytes' => 0, 'head' => '',
            'challenge' => null, 'retry' => false, 'parsed' => null, 'source' => '', 'md5' => '',
        ];
        if (!$r) return $info;
        $status = (int)($r['status'] ?? 0);
        $info['status'] = $status;
        $info['final_url'] = (string)($r['final_url'] ?? $u);
        $info['ctype'] = strtolower((string)($r['content_type'] ?? ''));
        $info['fetched_as'] = (string)($r['fetched_as'] ?? 'bot');
        $info['redirects'] = (int)($r['redirect_count'] ?? 0);
        $info['truncated'] = !empty($r['truncated']);
        $body = (string)($r['body'] ?? '');
        if ($status === 0) { $info['retry'] = false; return $info; }
        $waf = self::wafChallenge($r);
        if ($waf !== null) { $info['challenge'] = $waf; $info['state'] = 'blocked'; $info['retry'] = true; return $info; }

        if (strncmp($body, "\x1f\x8b", 2) === 0) {
            $tr = false;
            $d = self::gunzip($body, self::GZ_CAP, $tr);
            $info['gz'] = true;
            if ($d === null) { $info['state'] = 'not_sitemap'; $info['head'] = 'gzip-архів, який не вдалося розпакувати'; return $info; }
            $body = $d;
            if ($tr) $info['truncated'] = true;
        }
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) $body = substr($body, 3);
        $info['bytes'] = strlen($body);
        $info['md5'] = $body !== '' ? md5($body) : '';
        $info['head'] = self::oneLine(mb_substr(mb_scrub(ltrim($body), 'UTF-8'), 0, 160));

        $start = ltrim(substr($body, 0, 4096));
        $isHtml = (bool)preg_match('/^<(!doctype\s+html|html|head|body)\b/i', $start) || (stripos(substr($body, 0, 2048), '<html') !== false && !preg_match('/<(?:[\w-]+:)?(urlset|sitemapindex)[\s>]/i', substr($body, 0, 65536)));
        if ($isHtml) {
            $ch = self::safeChallenge($body);
            if ($ch !== null && $ch !== 'access_denied') {
                $info['challenge'] = $ch;
                $info['state'] = 'blocked';
                $info['retry'] = true;
                return $info;
            }
        }
        if (in_array($status, [401, 403, 406, 429, 503], true)) {
            $info['state'] = in_array($status, [401, 403, 406, 429], true) ? 'forbidden' : 'error';
            $info['retry'] = true;
            return $info;
        }
        if ($status === 404 || $status === 410) { $info['state'] = 'not_found'; return $info; }
        if ($status >= 500) { $info['state'] = 'error'; return $info; }
        if ($status >= 300 && $status < 400) { $info['state'] = 'error'; return $info; }
        if ($status >= 400) { $info['state'] = 'error'; return $info; }

        // 2xx
        $probe = substr($body, 0, 65536);
        if (preg_match('/<(?:[\w-]+:)?(urlset|sitemapindex)[\s>]/i', $probe, $m)) {
            $info['kind'] = strtolower($m[1]) === 'sitemapindex' ? 'index' : 'urlset';
            $info['parsed'] = $this->parseXml($body, $info['kind'], $info['truncated']);
            $info['state'] = 'ok';
            return $info;
        }
        if ($isHtml) {
            // 202/204/206 з HTML замість карти - заглушка захисту, а не відсутність карти
            if ($status !== 200) { $info['state'] = 'blocked'; $info['challenge'] = 'нестандартна відповідь'; $info['retry'] = true; return $info; }
            $info['state'] = 'soft404';
            return $info;
        }
        if (preg_match('/<(rss|feed)[\s>]/i', $probe)) {
            $info['kind'] = 'feed';
            $info['parsed'] = $this->parseXml($body, 'feed', $info['truncated']);
            $info['state'] = $info['parsed']['count'] > 0 ? 'ok' : 'not_sitemap';
            return $info;
        }
        // текстова карта: один URL на рядок
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        $nonEmpty = array_values(array_filter(array_map('trim', $lines), function ($l) { return $l !== ''; }));
        $urlLines = array_values(array_filter($nonEmpty, function ($l) { return (bool)preg_match('#^https?://\S+$#i', $l); }));
        if ($urlLines && count($urlLines) >= 0.8 * count($nonEmpty)) {
            $entries = [];
            foreach (array_slice($urlLines, 0, self::MAX_LOCS) as $l) $entries[] = ['loc' => $l, 'lastmod' => null];
            $info['kind'] = 'text';
            $info['parsed'] = ['valid' => null, 'errors' => [], 'err_snippet' => '', 'root' => '', 'root_tag' => '', 'ns' => '', 'count' => count($urlLines), 'entries' => $entries, 'first_raw' => ''];
            $info['state'] = 'ok';
            return $info;
        }
        $info['state'] = 'not_sitemap';
        return $info;
    }

    /** Розбір XML через XMLReader: валідність, namespace, записи url/sitemap з loc і lastmod. */
    private function parseXml(string $xml, string $kind, bool $truncated): array
    {
        $out = ['valid' => true, 'errors' => [], 'err_snippet' => '', 'root' => '', 'root_tag' => '', 'ns' => '', 'count' => 0, 'entries' => [], 'first_raw' => '', 'news' => false, 'alts' => []];
        if (preg_match('/<(?:[\w-]+:)?(?:urlset|sitemapindex|rss|feed)\b[^>]*>/i', substr($xml, 0, 65536), $m)) $out['root_tag'] = self::oneLine(mb_substr($m[0], 0, 400));
        // новинна карта Google News: свіжі lastmod там закономірні
        $out['news'] = $kind === 'urlset' && (bool)preg_match('#<(?:[\w-]+:)?news[\s>]#i', substr($xml, 0, 65536)) && stripos($out['root_tag'], 'sitemap-news') !== false;
        if (preg_match('#<(?:[\w-]+:)?(url|sitemap)>.*?</(?:[\w-]+:)?\1>#is', substr($xml, 0, 65536), $m)) {
            $rows = preg_split('/\R/', (string)preg_replace('/>\s*</', ">\n<", trim($m[0]))) ?: [];
            $rows = array_values(array_filter(array_map('trim', $rows), function ($r) { return $r !== ''; }));
            $last = count($rows) - 1;
            foreach ($rows as $i => $row) if ($i > 0 && $i < $last) $rows[$i] = '  ' . $row;
            $out['first_raw'] = mb_substr(implode("\n", $rows), 0, 400);
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $r = new XMLReader();
        $opened = @$r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $entryTag = $kind === 'index' ? 'sitemap' : 'url';
        $cur = null; $inEntry = false; $failed = false;
        if ($opened) {
            while (true) {
                $ok = @$r->read();
                if (!$ok) break;
                $type = $r->nodeType;
                if ($type === XMLReader::ELEMENT) {
                    $d = $r->depth;
                    $name = $r->localName;
                    if ($d === 0) {
                        $out['root'] = $name;
                        $out['ns'] = (string)$r->namespaceURI;
                        continue;
                    }
                    if ($kind === 'feed') {
                        if ($name === 'item' || $name === 'entry') { $out['count']++; $inEntry = true; $cur = ['loc' => null, 'lastmod' => null]; }
                        elseif ($inEntry && $name === 'link' && $cur['loc'] === null) {
                            $href = (string)$r->getAttribute('href');
                            $loc = $href !== '' ? $href : trim((string)$r->readString());
                            if (preg_match('#^https?://#i', $loc)) $cur['loc'] = $loc;
                        }
                        continue;
                    }
                    if ($d === 1 && $name === $entryTag) {
                        $out['count']++;
                        $inEntry = true;
                        $cur = ['loc' => null, 'lastmod' => null];
                        if ($r->isEmptyElement) { $inEntry = false; $cur = null; }
                    } elseif ($d === 2 && $inEntry && ($name === 'loc' || $name === 'lastmod')) {
                        $val = trim((string)$r->readString());
                        if ($name === 'loc') $cur['loc'] = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                        else $cur['lastmod'] = $val !== '' ? $val : null;
                    } elseif ($d === 2 && $inEntry && $name === 'link' && count($out['alts']) < self::MAX_LOCS) {
                        // <xhtml:link rel="alternate" hreflang=".." href=".."> - мовні версії сторінки
                        $href = trim((string)$r->getAttribute('href'));
                        if ($href !== '' && preg_match('#^https?://#i', $href)) $out['alts'][] = $href;
                    }
                } elseif ($type === XMLReader::END_ELEMENT) {
                    $name = $r->localName;
                    if ($inEntry && (($kind === 'feed' && ($name === 'item' || $name === 'entry')) || ($kind !== 'feed' && $r->depth === 1 && $name === $entryTag))) {
                        if ($cur && $cur['loc'] !== null && $cur['loc'] !== '' && count($out['entries']) < self::MAX_LOCS) $out['entries'][] = $cur;
                        $inEntry = false; $cur = null;
                    }
                }
            }
        } else {
            $failed = true;
        }
        $errs = [];
        foreach (libxml_get_errors() as $e) {
            if ($e->level < LIBXML_ERR_ERROR) continue;
            $errs[] = $e;
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $r->close();

        if ($failed || $errs) {
            if ($truncated) {
                $out['valid'] = null; // файл обрізано нашим лімітом - помилка в кінці очікувана
            } else {
                $out['valid'] = false;
                foreach (array_slice($errs, 0, 3) as $e) {
                    $out['errors'][] = 'рядок ' . $e->line . ', позиція ' . $e->column . ': ' . trim((string)$e->message);
                }
                if (!$out['errors']) $out['errors'][] = 'документ не вдалося розібрати як XML';
                $e0 = $errs[0] ?? null;
                if ($e0) $out['err_snippet'] = self::lineSnippet($xml, (int)$e0->line, (int)$e0->column);
            }
            // запасний підрахунок регуляркою (щоб знати обсяг навіть зі зламаним XML)
            if ($kind !== 'feed') {
                $out['count_reader'] = $out['count'];
                $tag = $kind === 'index' ? 'sitemap' : 'url';
                $n = (int)preg_match_all('#<(?:[\w-]+:)?' . $tag . '[\s/>]#i', $xml);
                if ($n > $out['count']) {
                    $out['count'] = $n;
                    // записи блоками <url>...</url>: lastmod кожного запису зберігаємо, щоб не вигадати «lastmod відсутній»
                    if (preg_match_all('#<(?:[\w-]+:)?' . $tag . '\b[^>]*>(.*?)</(?:[\w-]+:)?' . $tag . '>#is', $xml, $blocks)) {
                        $out['entries'] = [];
                        foreach (array_slice($blocks[1], 0, self::MAX_LOCS) as $blk) {
                            if (!preg_match('#<(?:[\w-]+:)?loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $blk, $lm)) continue;
                            $lmod = preg_match('#<(?:[\w-]+:)?lastmod>\s*([^<\s]+)\s*<#i', $blk, $md) ? $md[1] : null;
                            $out['entries'][] = ['loc' => html_entity_decode($lm[1], ENT_QUOTES | ENT_XML1, 'UTF-8'), 'lastmod' => $lmod];
                        }
                    }
                }
            }
        }
        return $out;
    }

    private function stateLabel(array $i): string
    {
        $st = (int)($i['status'] ?? 0);
        $redir = (int)($i['redirects'] ?? 0) > 0 && ($i['final_url'] ?? '') !== ($i['url'] ?? '') ? ' (після редиректу на ' . $i['final_url'] . ')' : '';
        switch ($i['state'] ?? 'timeout') {
            case 'ok':
                $p = $i['parsed'] ?? [];
                $n = (int)($p['count'] ?? 0);
                if (($i['kind'] ?? '') === 'index') return 'HTTP 200, індекс із ' . $n . ' карт' . $redir;
                return 'HTTP 200, ' . self::num($n) . ' URL' . (($i['kind'] ?? '') === 'text' ? ' (текстова карта)' : '') . $redir;
            case 'not_found':
                $hd = (string)($i['head'] ?? '');
                $showHead = $hd !== '' && ($i['bytes'] ?? 0) < 200 && $hd[0] !== '<';
                return 'HTTP ' . $st . $redir . ($showHead ? ' («' . $hd . '»)' : '');
            case 'soft404': return 'HTML-сторінка з кодом ' . ($st ?: 200) . ' (soft-404)' . $redir;
            case 'forbidden': return 'HTTP ' . $st . ' (доступ заборонено)';
            case 'blocked': return 'перевірка захисту від ботів' . (($i['challenge'] ?? null) ? ' (' . $i['challenge'] . ')' : '') . ($st ? ', HTTP ' . $st : '');
            case 'error': return 'HTTP ' . $st . $redir;
            case 'not_sitemap': return 'HTTP ' . $st . ', але це не карта сайту' . ($i['ctype'] !== '' ? ' (' . $i['ctype'] . ')' : '') . ($i['head'] !== '' ? ': «' . mb_substr($i['head'], 0, 60) . '»' : '');
            default: return 'немає відповіді (тайм-аут або помилка з\'єднання)';
        }
    }

    private function okOnly(array $docs): array
    {
        return array_filter($docs, function ($i) { return ($i['state'] ?? '') === 'ok' && empty($i['dup_of']); });
    }

    private function addEntries(array &$entries, array $add, bool $news = false): void
    {
        foreach ($add as $e) {
            if (count($entries) >= self::MAX_LOCS) return;
            if ($news) $e['news'] = true;
            $entries[] = $e;
        }
    }

    /** До MAX_ROBOTS_SITEMAPS рядків Sitemap з robots.txt: спершу основна карта / індекс, далі сторінки, товари, статті; зображення й відео - в останню чергу. */
    private function pickDeclared(array $declared): array
    {
        $ranked = [];
        $i = 0;
        foreach ($declared as $u => $ln) {
            $name = strtolower(basename((string)parse_url($u, PHP_URL_PATH)));
            if (preg_match('/^(sitemap|sitemap[_-]?index|sitemaps?)\.xml(\.gz)?$/', $name) || strpos($name, 'index') !== false) $rank = 0;
            elseif (preg_match('/image|video|photo|img|review|comment|media/', $name)) $rank = 3;
            elseif (preg_match('/product|tovar|goods|catalog|categor|page|post|article|blog|news|service|poslug|main/', $name)) $rank = 1;
            else $rank = 2;
            $ranked[] = [$rank, $i++, $u];
        }
        usort($ranked, function ($a, $b) { return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0]; });
        $out = [];
        foreach (array_slice($ranked, 0, self::MAX_ROBOTS_SITEMAPS) as $r) $out[$r[2]] = $declared[$r[2]];
        return $out;
    }

    /** До 3 дочірніх карт різних типів (товари, статті, категорії, сторінки). */
    private function pickChildren(array $children): array
    {
        $locs = [];
        foreach ($children as $c) {
            $l = (string)$c['loc'];
            if (preg_match('#^https?://#i', $l) && !in_array($l, $locs, true)) $locs[] = $l;
        }
        if (count($locs) <= self::MAX_CHILDREN_ALL) return $locs;
        // Тип карти - за словами в назві файлу (токени між - _ . /), а не за підрядком:
        // «sitemap-market-tovary-dlya-detey-0» - не карта товарів, хоч і містить «tovar».
        $map = [
            'product'  => '/^(products?|goods|items?|offers?|tovary?|tovaru|tovariv)$/',
            'category' => '/^(categor(y|ies|ys)|catalog|catalogue|collections?|product_cat|product-cat|cat|rubric|rubrics)$/',
            'post'     => '/^(posts?|blog|news|articles?|stati|statti|statji|novyny|novosti|novini|publications?)$/',
            'service'  => '/^(services?|poslugy|uslugi)$/',
            'page'     => '/^(pages?|static|info|main|root)$/',
            'tag'      => '/^(tags?|authors?|archives?|filters?|brands?)$/',
            'media'    => '/^(images?|videos?|media|photos?)$/',
        ];
        $groups = [];
        foreach ($locs as $i => $u) {
            $name = strtolower(rawurldecode((string)parse_url($u, PHP_URL_PATH)) . '?' . (string)parse_url($u, PHP_URL_QUERY));
            $tokens = array_values(array_filter(preg_split('/[^a-z]+/', $name) ?: [], function ($t) { return $t !== '' && !in_array($t, ['sitemap', 'sitemaps', 'xml', 'gz', 'index', 'wp'], true); }));
            $g = 'other'; $firstTok = false;
            foreach ($map as $k => $rx) {
                foreach ($tokens as $ti => $t) {
                    if (!preg_match($rx, $t)) continue;
                    $g = $k; $firstTok = $ti === 0;
                    break 2;
                }
            }
            // «сім'я» карт: однакова назва без цифр (sitemap-products-1 ... sitemap-products-329)
            $family = (string)preg_replace('/\d+/', '#', basename((string)parse_url($u, PHP_URL_PATH)));
            $groups[$g][] = ['u' => $u, 'i' => $i, 'first' => $firstTok, 'family' => $family];
        }
        $out = [];
        foreach (['product', 'post', 'category', 'service', 'page', 'other', 'tag', 'media'] as $g) {
            if (empty($groups[$g])) continue;
            // з групи - карта з найбільшої сім'ї, де тип - перше слово назви (типова карта розділу, а не випадкова підбірка)
            $fam = array_count_values(array_column($groups[$g], 'family'));
            $cands = $groups[$g];
            usort($cands, function ($a, $b) use ($fam) {
                if ($a['first'] !== $b['first']) return $a['first'] ? -1 : 1;
                if ($fam[$a['family']] !== $fam[$b['family']]) return $fam[$b['family']] <=> $fam[$a['family']];
                return $a['i'] <=> $b['i'];
            });
            $out[] = $cands[0]['u'];
            if (count($out) >= self::MAX_CHILDREN) break;
        }
        $n = count($locs);
        for ($k = 0; count($out) < self::MAX_CHILDREN && $k < self::MAX_CHILDREN; $k++) {
            $cand = $locs[(int)floor(($k + 0.5) * $n / self::MAX_CHILDREN)];
            if (!in_array($cand, $out, true)) $out[] = $cand;
        }
        return array_slice($out, 0, self::MAX_CHILDREN);
    }

    // ================================================================== URL sample

    private function pickSampleUrls(array $entries): array
    {
        $home = self::normUrl($this->final, true);
        $pool = []; $seen = [];
        foreach ($entries as $e) {
            $loc = (string)$e['loc'];
            if (!preg_match('#^https?://#i', $loc) || !Crawler::sameSite($loc, $this->final)) continue;
            $path = (string)parse_url($loc, PHP_URL_PATH);
            if (preg_match('/\.(pdf|jpe?g|png|gif|webp|svg|avif|zip|rar|7z|docx?|xlsx?|pptx?|mp4|mp3|xml|txt|gz|csv)$/i', $path)) continue;
            $k = self::normUrl($loc, true);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $pool[] = ['loc' => $loc, 'home' => $k === $home];
        }
        if (count($pool) > self::MAX_SAMPLE) $pool = array_values(array_filter($pool, function ($p) { return !$p['home']; }));
        $n = count($pool);
        if ($n === 0) return [];
        if ($n <= self::MAX_SAMPLE) return array_column($pool, 'loc');
        // Карти часто чергують мовні версії (/page, /en/page, ...), і рівномірний крок влучає лише в одну.
        // Тому ділимо пул за мовним префіксом і беремо рівномірно з кожної групи (до 3 груп).
        $groups = [];
        foreach ($pool as $p) {
            $lp = preg_match('#^/([a-z]{2})(/|$)#i', (string)parse_url($p['loc'], PHP_URL_PATH), $m) ? strtolower($m[1]) : '';
            $groups[$lp][] = $p['loc'];
        }
        uasort($groups, function ($a, $b) { return count($b) <=> count($a); });
        $groups = array_slice($groups, 0, 3, true);
        $quota = [];
        $left = self::MAX_SAMPLE;
        foreach ($groups as $k => $g) { $quota[$k] = 1; $left--; }
        while ($left > 0) {
            $best = null; $bestRatio = -1.0;
            foreach ($groups as $k => $g) {
                if ($quota[$k] >= count($g)) continue;
                $ratio = count($g) / ($quota[$k] + 1);
                if ($ratio > $bestRatio) { $bestRatio = $ratio; $best = $k; }
            }
            if ($best === null) break;
            $quota[$best]++;
            $left--;
        }
        $out = [];
        foreach ($groups as $k => $g) {
            $gc = count($g);
            for ($i = 0; $i < $quota[$k]; $i++) $out[] = $g[(int)floor(($i + 0.5) * $gc / $quota[$k])];
        }
        return array_values(array_unique($out));
    }

    /** [url => ['state'=>ok|redirect|error|unknown, 'label', 'final_url', 'final_status', 'noindex'=>string|null, 'canonical'=>string|null]] */
    private function checkUrls(array $urls): array
    {
        $res = Crawler::fetchMulti($urls, $this->mode, self::URL_TIMEOUT);
        $out = []; $retry = [];
        foreach ($urls as $u) {
            $out[$u] = $this->classifyUrl($u, $res[$u] ?? null);
            if ($this->mode === null && $out[$u]['retry']) $retry[] = $u;
        }
        if ($retry) {
            $res2 = Crawler::fetchMulti($retry, 'browser', self::URL_TIMEOUT);
            foreach ($retry as $u) {
                $c2 = $this->classifyUrl($u, $res2[$u] ?? null);
                if ($c2['state'] !== 'unknown' && !$c2['retry']) $out[$u] = $c2;
                elseif ($c2['state'] === 'unknown' || $out[$u]['state'] === 'unknown') {
                    $out[$u]['state'] = 'unknown';
                    $out[$u]['label'] = 'не вдалося перевірити (' . $out[$u]['label'] . ')';
                } else {
                    $out[$u] = $c2; // браузер теж отримав 401/403/429/503 без перевірки - це реальна відповідь
                }
            }
        }
        // Редирект може бути лише для звичайних відвідувачів (мова/регіон за UA чи cookie), а пошуковий бот
        // отримує 200. Для карти важливо, що бачить пошуковий бот, тому редиректи перепитуємо як Googlebot.
        $redirected = array_keys(array_filter($out, function ($c) { return $c['state'] === 'redirect'; }));
        if ($redirected) {
            $res3 = Crawler::fetchMulti($redirected, self::GOOGLEBOT_UA, self::URL_TIMEOUT);
            $googleOk = [];
            foreach ($redirected as $u) {
                $g = $this->classifyUrl($u, $res3[$u] ?? null);
                if ($g['state'] === 'ok') $googleOk[] = $u;
            }
            // той самий URL від імені Bingbot: сервер може звільняти від редиректу лише Googlebot
            $resB = $googleOk ? Crawler::fetchMulti($googleOk, self::BINGBOT_UA, self::URL_TIMEOUT) : [];
            $resO = $googleOk ? Crawler::fetchMulti($googleOk, self::OAI_UA, self::URL_TIMEOUT) : [];
            foreach ($redirected as $u) {
                $g = $this->classifyUrl($u, $res3[$u] ?? null);
                if ($g['state'] === 'ok') {
                    $b = $this->classifyUrl($u, $resB[$u] ?? null);
                    $o = $this->classifyUrl($u, $resO[$u] ?? null);
                    $g['label'] .= ' для Googlebot (редирект на ' . $out[$u]['final_url'] . ' лише для звичайних відвідувачів)';
                    $g['visitor_redirect'] = true;
                    if ($b['state'] === 'redirect') {
                        $g['bing_redirect'] = $b['final_url'];
                        $g['label'] .= '; для User-Agent Bingbot - теж редирект на ' . $b['final_url'];
                    }
                    if ($o['state'] === 'redirect') {
                        $g['oai_redirect'] = $o['final_url'];
                        $g['label'] .= '; для User-Agent OAI-SearchBot - теж редирект на ' . $o['final_url'];
                    }
                    $out[$u] = $g;
                } elseif ($g['state'] === 'unknown' || $g['retry']) {
                    if (self::langOnlyDiff($u, (string)$out[$u]['final_url'])) {
                        // мовний редирект міг бути лише для відвідувачів, а підтвердити це від імені Googlebot не вдалося
                        $out[$u]['state'] = 'unknown';
                        $out[$u]['label'] = 'не вдалося перевірити: ' . $out[$u]['label'] . ', а запит від імені Googlebot заблоковано';
                    } else {
                        // редирект на іншу сторінку / хост - не мовний, тому вважаємо його реальним
                        $out[$u]['label'] .= ' (від імені Googlebot перевірити не вдалося, але редирект не мовний)';
                    }
                }
            }
        }
        return $out;
    }

    private function classifyUrl(string $u, ?array $r): array
    {
        $c = ['state' => 'unknown', 'label' => 'немає відповіді', 'final_url' => $u, 'final_status' => 0, 'noindex' => null, 'canonical' => null, 'retry' => false];
        if (!$r || (int)($r['status'] ?? 0) === 0) return $c;
        $status = (int)$r['status'];
        if (self::wafChallenge($r) !== null) {
            $c['label'] = 'перевірка захисту від ботів (aws-waf), HTTP ' . $status;
            $c['retry'] = true;
            return $c;
        }
        $final = (string)($r['final_url'] ?? $u);
        $c['final_url'] = $final;
        $c['final_status'] = $status;
        $body = (string)($r['body'] ?? '');
        $page = null;
        $ct = strtolower((string)($r['content_type'] ?? ''));
        if ($body !== '' && ($ct === '' || strpos($ct, 'html') !== false)) $page = Page::fromCrawl($r);
        $ch = $page ? $page->challenge() : null;
        if ($ch !== null && $ch !== 'access_denied') {
            $c['label'] = 'перевірка захисту від ботів (' . $ch . ')';
            $c['retry'] = true;
            return $c;
        }
        if (in_array($status, [401, 403, 429, 503], true)) $c['retry'] = true;
        $redirected = (int)($r['redirect_count'] ?? 0) > 0 && self::normUrl($final) !== self::normUrl($u);
        if ($redirected) {
            $c['state'] = 'redirect';
            $homes = [self::normUrl($this->base . '/', true) => true, self::normUrl($this->final, true) => true];
            $c['to_home'] = isset($homes[self::normUrl($final, true)]) && !isset($homes[self::normUrl($u, true)]);
            $c['label'] = 'редирект на ' . $final . ($c['to_home'] ? ' (головна - схоже, сторінку видалено)' : '') . ', кінцевий код ' . $status;
            return $c;
        }
        if ($status >= 300) {
            $c['state'] = 'error';
            $c['label'] = 'HTTP ' . $status;
            return $c;
        }
        if ($status !== 200) {
            // 202/204/206 - не звичайна сторінка (часто заглушка WAF); стан лишається «не перевірено»
            $c['label'] = 'HTTP ' . $status . ' замість 200 (ймовірно, заглушка захисту)';
            $c['retry'] = true;
            return $c;
        }
        $c['state'] = 'ok';
        $c['label'] = 'HTTP ' . $status;
        if ($page) {
            $dirs = [];
            foreach ($page->metaRobots() as $d) $dirs[] = ['meta robots', $d];
            foreach ($page->xRobotsTag() as $d) $dirs[] = ['X-Robots-Tag', $d];
            foreach ($dirs as [$src, $d]) {
                $d = (string)preg_replace('/^[a-z\-]+bot:/i', '', $d);
                if ($d === 'noindex' || $d === 'none') { $c['noindex'] = $src . ': ' . $d; break; }
            }
            $can = $page->canonical();
            if ($can !== null && $c['noindex'] === null && Crawler::sameSite($can, $final) && self::normUrl($can) !== self::normUrl($final)) {
                $c['canonical'] = $can;
                // canonical = та сама адреса + службові параметри (utm, gclid ...) - помилка самого тегу, а не карти
                $cq = (string)parse_url($can, PHP_URL_QUERY);
                $c['canonical_param'] = $cq !== '' && preg_match('/(^|&)(utm_[a-z]+|gclid|fbclid|yclid|_ga|ref|srsltid)=/i', $cq)
                    && self::normUrl((string)preg_replace('/\?.*$/', '', $can), true) === self::normUrl((string)preg_replace('/\?.*$/', '', $final), true);
                // canonical мовної версії на іншу мову (напр. /en/page -> /page), хоча сторінка сама вказана в hreflang:
                // помилка тегу canonical, а не карти - мовна версія фактично випадає з індексу
                $selfInHreflang = false;
                foreach ($page->hreflangs() as $hl) {
                    if (self::normUrl($hl['href'], true) === self::normUrl($final, true)) { $selfInHreflang = true; break; }
                }
                $c['canonical_lang'] = !$c['canonical_param'] && self::langOnlyDiff($can, $final);
                $c['hreflang_self'] = $c['canonical_lang'] && $selfInHreflang;
            }
            if ($c['noindex']) $c['label'] .= ', ' . $c['noindex'];
            elseif ($c['canonical']) $c['label'] .= ', canonical -> ' . $c['canonical'];
        }
        return $c;
    }

    // ================================================================== coverage helpers

    /** Головна + сторінки вибірки (200, без noindex): [['label','url','alts'=>[...]]]. */
    private function keyPages(array $ctx): array
    {
        $out = [];
        $home = $this->final;
        $homeAlts = [$home, $this->base . '/'];
        $hc = (($ctx['page'] ?? null) instanceof Page) ? $ctx['page']->canonical() : null;
        if ($hc !== null && Crawler::sameSite($hc, $home)) $homeAlts[] = $hc;
        $out[] = ['label' => 'головна', 'url' => $home, 'alts' => array_values(array_unique($homeAlts))];
        $roleLabels = ['about' => 'про компанію', 'contact' => 'контакти', 'article' => 'стаття', 'product' => 'товар', 'category' => 'категорія', 'service' => 'послуга'];
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            $p = $s['page'] ?? null;
            if (!($p instanceof Page) || !isset($roleLabels[$s['role'] ?? ''])) continue;
            if ((int)($s['res']['status'] ?? 0) !== 200) continue;
            $noindex = false;
            foreach (array_merge($p->metaRobots(), $p->xRobotsTag()) as $d) {
                $d = (string)preg_replace('/^[a-z\-]+bot:/i', '', $d);
                if ($d === 'noindex' || $d === 'none') $noindex = true;
            }
            if ($noindex) continue;
            $fin = (string)($s['res']['final_url'] ?? $s['url']);
            if (!Crawler::sameSite($fin, $this->final)) continue;
            $can = $p->canonical();
            $alts = [$fin];
            if ($can !== null && Crawler::sameSite($can, $fin)) array_unshift($alts, $can);
            $out[] = ['label' => $roleLabels[$s['role']], 'url' => $alts[0], 'alts' => array_values(array_unique($alts))];
        }
        return $out;
    }

    /** Посилання меню головної, яких немає в повній карті; приклади перевіряються запитом. */
    private function navCoverage(Page $page, array $set): ?array
    {
        $nav = [];
        $homeKey = self::normUrl($this->final, true);
        $baseKey = self::normUrl($this->base . '/', true);
        foreach ($page->internalLinks() as $l) {
            if (!$l['in_nav']) continue;
            $h = $l['href'];
            if (parse_url($h, PHP_URL_QUERY) !== null || SiteProfile::isExcluded($h, $this->final)) continue;
            if (preg_match(self::RX_PRIVATE_PATH, (string)parse_url($h, PHP_URL_PATH))) continue;
            $k = self::normUrl($h, true);
            if ($k === $homeKey || $k === $baseKey) continue;
            $nav[$k] = $h;
        }
        if (count($nav) < 8) return null;
        $missing = [];
        foreach ($nav as $k => $h) if (!isset($set[$k])) $missing[$k] = $h;
        if (count($missing) < 3 || count($missing) / count($nav) < 0.3) return null;
        // підтверджуємо: сторінка відповідає 200 без редиректу (інакше це не проблема карти)
        $probe = array_slice(array_values($missing), 0, 5);
        $res = Crawler::fetchMulti($probe, $this->mode, self::URL_TIMEOUT);
        $confirmed = [];
        foreach ($probe as $u) {
            $r = $res[$u] ?? null;
            if (!$r || (int)$r['status'] !== 200) continue;
            $fin = (string)($r['final_url'] ?? $u);
            if (isset($set[self::normUrl($fin, true)])) continue;
            if ((int)($r['redirect_count'] ?? 0) > 0 && self::normUrl($fin, true) !== self::normUrl($u, true)) continue;
            $p = Page::fromCrawl($r);
            if ($p && $p->challenge() !== null) continue;
            if ($p) {
                $skip = false;
                foreach (array_merge($p->metaRobots(), $p->xRobotsTag()) as $d) {
                    $d = (string)preg_replace('/^[a-z\-]+bot:/i', '', $d);
                    if ($d === 'noindex' || $d === 'none') $skip = true;
                }
                $can = $p->canonical();
                if ($can !== null && isset($set[self::normUrl($can, true)])) $skip = true;
                if ($skip) continue;
            }
            $confirmed[] = $u;
        }
        if (count($confirmed) < 3) return null;
        return ['total' => count($nav), 'missing' => count($missing), 'checked' => count($probe), 'confirmed' => $confirmed];
    }

    private function hostMismatch(array $locs): array
    {
        $out = ['checked' => 0, 'scheme' => [], 'www' => [], 'foreign' => []];
        $hp = parse_url($this->origin());
        $hHost = strtolower((string)($hp['host'] ?? ''));
        $hScheme = strtolower((string)($hp['scheme'] ?? 'https'));
        $hReg = Crawler::registrableHost($hHost);
        $seen = [];
        foreach ($locs as $u) {
            $u = (string)$u;
            if (isset($seen[$u])) continue;
            $seen[$u] = true;
            $p = parse_url($u);
            if (!$p || empty($p['host'])) continue;
            $out['checked']++;
            $h = strtolower($p['host']);
            $s = strtolower((string)($p['scheme'] ?? ''));
            if (Crawler::registrableHost($h) !== $hReg) { $out['foreign'][] = $u; continue; }
            if ($h !== $hHost) $out['www'][] = $u;
            if ($s !== $hScheme) $out['scheme'][] = $u;
        }
        return $out;
    }

    /**
     * Статистика lastmod по унікальних URL. Для евристики автогенерації (recent_*) новинні карти не враховуються:
     * base - кількість неновинних записів з коректною датою, recent - з них за останні 36 годин.
     */
    private function lastmodStats(array $entries): array
    {
        $now = time();
        $uniq = [];
        foreach ($entries as $e) {
            $k = (string)$e['loc'];
            if (!isset($uniq[$k])) $uniq[$k] = $e;
        }
        $E = count($uniq);
        $vals = []; $invalid = []; $future = []; $times = [];
        $base = 0; $recentTs = []; $recentVals = []; $recentHasTime = false;
        foreach ($uniq as $e) {
            $lm = $e['lastmod'] ?? null;
            if ($lm === null || $lm === '') continue;
            $vals[] = $lm;
            if (!preg_match(self::RX_W3C_DATE, $lm)) { if (count($invalid) < 50) $invalid[] = $lm; continue; }
            $ts = strtotime($lm);
            if ($ts === false) continue;
            if ($ts > $now + 2 * 86400) { if (count($future) < 50) $future[] = $lm; continue; }
            $times[] = $ts;
            if (!empty($e['news'])) continue;
            $base++;
            // дата без часу: «свіжа» = сьогодні або вчора (UTC), інакше вердикт залежав би від години запуску
            $dateOnly = strpos($lm, 'T') === false;
            $isRecent = $dateOnly ? (substr($lm, 0, 10) >= gmdate('Y-m-d', $now - 86400)) : ($ts >= $now - 36 * 3600);
            if ($isRecent) {
                $recentTs[] = $ts;
                $recentVals[$lm] = true;
                if (strpos($lm, 'T') !== false) $recentHasTime = true;
            }
        }
        $L = count($vals);
        $counts = $L ? array_count_values($vals) : [];
        arsort($counts);
        $newest = $times ? max($times) : null;
        $recent = count($recentTs);
        // найщільніше тригодинне вікно серед свіжих дат: генератор / синхронізація ставлять «зараз» більшості записів
        sort($recentTs);
        $dense = 0; $dMin = null; $dMax = null;
        for ($i = 0, $j = 0; $i < $recent; $i++) {
            while ($recentTs[$i] - $recentTs[$j] > 3 * 3600) $j++;
            if ($i - $j + 1 > $dense) { $dense = $i - $j + 1; $dMin = $recentTs[$j]; $dMax = $recentTs[$i]; }
        }
        return [
            'E' => $E, 'L' => $L, 'distinct' => count($counts),
            'top_value' => $counts ? (string)array_key_first($counts) : '', 'top_count' => $counts ? (int)reset($counts) : 0,
            'newest' => $newest, 'age' => $newest !== null ? max(0, (int)floor(($now - $newest) / 86400)) : null,
            'invalid' => $invalid, 'future' => $future,
            'base' => $base, 'recent' => $recent, 'recent_distinct' => count($recentVals), 'recent_share' => $base ? $recent / $base : 0.0,
            'recent_values' => array_slice(array_keys($recentVals), 0, 200),
            'recent_has_time' => $recentHasTime,
            'dense' => $dense, 'dense_share' => $base ? $dense / $base : 0.0, 'dense_min' => $dMin, 'dense_max' => $dMax,
        ];
    }

    /** «24 с», «18 хв», «2 год 5 хв». */
    private static function spanText(int $sec): string
    {
        if ($sec < 60) return $sec . ' с';
        if ($sec < 3600) return (int)round($sec / 60) . ' хв';
        $h = intdiv($sec, 3600); $m = (int)round(($sec % 3600) / 60);
        return $h . ' год' . ($m > 0 ? ' ' . $m . ' хв' : '');
    }

    /** Реальні адреси для шаблону карти: головна, about/contact/вибірка, посилання меню (до 10). */
    private function templateUrls(?Page $page, array $ctx): array
    {
        $out = [$this->final];
        $seen = [self::normUrl($this->final, true) => true];
        $add = function (string $u) use (&$out, &$seen) {
            if (count($out) >= 10 || !Crawler::sameSite($u, $this->final)) return;
            $k = self::normUrl($u, true);
            if (isset($seen[$k])) return;
            $seen[$k] = true;
            $out[] = $u;
        };
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            if (($s['page'] ?? null) instanceof Page && (int)($s['res']['status'] ?? 0) === 200) $add((string)($s['res']['final_url'] ?? $s['url']));
        }
        if ($page) {
            foreach (self::orderedLinks($page) as $l) {
                if (parse_url($l['href'], PHP_URL_QUERY) !== null || SiteProfile::isExcluded($l['href'], $this->final)) continue;
                if (preg_match(self::RX_PRIVATE_PATH, (string)parse_url($l['href'], PHP_URL_PATH))) continue;
                $add($l['href']);
            }
        }
        return $out;
    }

    private function firstRawEntry(array $docs): string
    {
        foreach ($docs as $d) {
            if (in_array($d['info']['kind'], ['urlset'], true) && ($d['info']['parsed']['first_raw'] ?? '') !== '') return $d['info']['parsed']['first_raw'];
        }
        return '';
    }

    // ================================================================== robots

    private function robotsInfo(array $r): array
    {
        $status = (int)($r['status'] ?? 0);
        $body = (string)($r['body'] ?? '');
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) $body = substr($body, 3);
        $trim = ltrim($body);
        $isHtml = $trim !== '' && $trim[0] === '<';
        $challenge = self::wafChallenge($r) ?? ($isHtml ? self::safeChallenge($body) : null);
        if ($challenge === 'aws-waf') $state = 'blocked';
        elseif ($status === 200 && !$isHtml) $state = 'ok';
        elseif ($challenge !== null && $challenge !== 'access_denied') $state = 'blocked';
        elseif ($status > 200 && $status < 300) { $state = 'blocked'; $challenge = $challenge ?? 'нестандартна відповідь'; }
        elseif ($status === 404 || $status === 410) $state = 'missing';
        elseif ($status === 200) $state = 'html';
        elseif ($status === 0) $state = 'timeout';
        elseif (in_array($status, [401, 403, 429], true)) $state = 'forbidden';
        else $state = 'error';
        $lines = [];
        if ($state === 'ok') {
            foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $i => $line) {
                if (!preg_match('/^\s*sitemap\s*:\s*(.*)$/i', $line, $m)) continue;
                $val = trim((string)preg_replace('/\s+#.*$/', '', $m[1]));
                if ($val === '') continue;
                $relative = !preg_match('#^https?://#i', $val);
                $abs = $relative ? Crawler::absolutize($val, $this->base . '/') : $val;
                $lines[] = ['line' => $i + 1, 'raw' => trim($line), 'url' => $abs, 'relative' => $relative];
            }
        }
        return ['state' => $state, 'status' => $status, 'lines' => $lines, 'body' => $state === 'ok' ? $body : '', 'challenge' => $challenge];
    }

    private function robotsExcerpt(string $body): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        $out = array_slice($lines, 0, 14);
        $s = implode("\n", array_map('rtrim', $out));
        if (count($lines) > 14) $s .= "\n# ... ще " . (count($lines) - 14) . ' рядків';
        return mb_substr($s, 0, 450);
    }

    // ================================================================== static utils

    /** Внутрішні посилання в порядку важливості: меню/шапка, тіло сторінки, футер. */
    private static function orderedLinks(Page $page): array
    {
        $footer = [];
        foreach ($page->query('//footer//a[@href] | //*[@role="contentinfo"]//a[@href]') as $a) {
            $abs = Crawler::absolutize((string)$a->getAttribute('href'), $page->url());
            if ($abs !== null) $footer[$abs] = true;
        }
        $groups = [0 => [], 1 => [], 2 => []];
        foreach ($page->internalLinks() as $l) {
            $g = isset($footer[$l['href']]) ? 2 : ($l['in_nav'] ? 0 : 1);
            $groups[$g][] = $l;
        }
        return array_merge($groups[0], $groups[1], $groups[2]);
    }

    private static function cmsHint(?Page $p): ?array
    {
        if (!$p) return null;
        $h = $p->html();
        $gen = strtolower((string)($p->metaTags()['generator'] ?? ''));
        if (strpos($gen, 'wordpress') !== false || strpos($h, '/wp-content/') !== false) return ['name' => 'WordPress', 'hint' => 'увімкніть карту в Yoast SEO або Rank Math (розділ «Карти сайту») або скористайтеся вбудованою /wp-sitemap.xml'];
        if (strpos($gen, 'opencart') !== false || preg_match('#catalog/view/(theme|javascript)/|index\.php\?route=#', $h)) return ['name' => 'OpenCart', 'hint' => 'увімкніть розширення Google Sitemap (Розширення -> Канали просування) або модуль генерації карти з lastmod'];
        if (strpos($gen, 'joomla') !== false || strpos($h, '/media/jui/') !== false || strpos($h, '/media/system/js/') !== false) return ['name' => 'Joomla', 'hint' => 'встановіть розширення OSMap або JSitemap і опублікуйте XML-карту'];
        if (strpos($h, 'cdn.shopify.com') !== false) return ['name' => 'Shopify', 'hint' => '/sitemap.xml генерується автоматично - перевірте, що магазин не закритий паролем'];
        if (strpos($h, '/bitrix/') !== false) return ['name' => '1С-Бітрікс', 'hint' => 'Маркетинг -> Пошукова оптимізація -> Налаштування sitemap.xml, плюс агент для щоденного оновлення'];
        if (strpos($gen, 'drupal') !== false || strpos($h, '/sites/default/files/') !== false) return ['name' => 'Drupal', 'hint' => 'модуль Simple XML Sitemap'];
        if (stripos($h, 'Magento') !== false || strpos($h, '/static/version') !== false) return ['name' => 'Magento', 'hint' => 'Marketing -> SEO & Search -> Site Map, з розкладом генерації'];
        if (strpos($gen, 'modx') !== false || strpos($h, '/assets/components/') !== false) return ['name' => 'MODX', 'hint' => 'сніпет pdoSitemap (pdoTools) на окремому ресурсі sitemap.xml з типом вмісту XML'];
        if (stripos($h, 'tilda') !== false && strpos($h, 'tildacdn') !== false) return ['name' => 'Tilda', 'hint' => 'карта генерується автоматично - перевірте, що сторінки не виключені в налаштуваннях SEO'];
        if (strpos($h, 'static.wixstatic.com') !== false) return ['name' => 'Wix', 'hint' => 'карта генерується автоматично - перевірте, що сайт опубліковано і сторінки не приховано від пошуку'];
        $fw = $p->frameworkMarkers();
        if (in_array('next', $fw, true)) return ['name' => 'Next.js', 'hint' => 'файл app/sitemap.ts або пакет next-sitemap'];
        if (in_array('nuxt', $fw, true)) return ['name' => 'Nuxt', 'hint' => 'модуль @nuxtjs/sitemap'];
        return null;
    }

    /** Розпакування gzip з лімітом розміру (захист від gzip-бомб). */
    private static function gunzip(string $data, int $cap, bool &$truncated): ?string
    {
        $truncated = false;
        if (function_exists('inflate_init')) {
            $ctx = @inflate_init(ZLIB_ENCODING_GZIP);
            if ($ctx === false) return null;
            $out = '';
            $len = strlen($data);
            for ($off = 0; $off < $len; $off += 8192) {
                $chunk = @inflate_add($ctx, substr($data, $off, 8192), ZLIB_SYNC_FLUSH);
                if ($chunk === false) return $out !== '' ? $out : null;
                $out .= $chunk;
                if (strlen($out) >= $cap) { $truncated = true; return substr($out, 0, $cap); }
            }
            return $out !== '' ? $out : null;
        }
        $d = @gzdecode($data);
        return is_string($d) ? $d : null;
    }

    /**
     * Нормалізація URL для порівняння. $loose: без протоколу, без www, без кінцевого слеша.
     */
    private static function normUrl(string $u, bool $loose = false): string
    {
        $p = parse_url(trim($u));
        if (!$p || empty($p['host'])) return strtolower(trim($u));
        $host = strtolower($p['host']);
        if ($loose && strpos($host, 'www.') === 0) $host = substr($host, 4);
        $path = rawurldecode((string)($p['path'] ?? '/'));
        if ($path === '') $path = '/';
        if ($loose && strlen($path) > 1) $path = rtrim($path, '/');
        $port = (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) ? ':' . $p['port'] : '';
        $q = (isset($p['query']) && $p['query'] !== '') ? '?' . $p['query'] : '';
        $scheme = $loose ? '' : strtolower((string)($p['scheme'] ?? 'http')) . '://';
        return $scheme . $host . $port . $path . $q;
    }

    /**
     * Дві адреси відрізняються лише мовою: мовним сегментом на початку шляху (/en/page vs /page, /ua/page vs /ru/page)
     * або мовним піддоменом (en.site.com vs site.com). Однакові адреси - false.
     */
    private static function langOnlyDiff(string $a, string $b): bool
    {
        $pa = parse_url($a); $pb = parse_url($b);
        if (!$pa || !$pb || empty($pa['host']) || empty($pb['host'])) return false;
        $ha = strtolower($pa['host']); $hb = strtolower($pb['host']);
        if (strpos($ha, 'www.') === 0) $ha = substr($ha, 4);
        if (strpos($hb, 'www.') === 0) $hb = substr($hb, 4);
        $langHost = false;
        if ($ha !== $hb) {
            $strip = function (string $h) { $parts = explode('.', $h, 2); return (count($parts) === 2 && preg_match(self::RX_LANG_SEG, $parts[0])) ? $parts[1] : $h; };
            if ($strip($ha) !== $strip($hb)) return false;
            $langHost = true;
        }
        $segs = function (array $p): array {
            $s = array_values(array_filter(explode('/', rawurldecode((string)($p['path'] ?? '/'))), function ($x) { return $x !== ''; }));
            if ($s && preg_match(self::RX_LANG_SEG, $s[0])) array_shift($s);
            return $s;
        };
        $qa = (string)($pa['query'] ?? ''); $qb = (string)($pb['query'] ?? '');
        if ($segs($pa) !== $segs($pb) || $qa !== $qb) return false;
        return $langHost || self::normUrl($a, true) !== self::normUrl($b, true);
    }

    private function toCanonicalHost(string $u): string
    {
        $p = parse_url($u);
        $hp = parse_url($this->final);
        if (!$p || !$hp) return $u;
        return $this->origin() . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /** scheme://host[:port] головної. */
    private function origin(): string
    {
        if ($this->origin !== '') return $this->origin;
        return self::originOf($this->final);
    }

    private static function originOf(string $u): string
    {
        $hp = parse_url($u) ?: [];
        return strtolower((string)($hp['scheme'] ?? 'https')) . '://' . strtolower((string)($hp['host'] ?? '')) . (isset($hp['port']) ? ':' . $hp['port'] : '');
    }

    /**
     * Основна адреса сайту: з canonical головної, якщо він на тому ж сайті (www / без www, http / https),
     * інакше - кінцева адреса аудиту. Сайт може віддавати 200 на обох хостах, а канонічним вважати один.
     * Повертає [origin, чи взято з canonical].
     */
    private static function mainOrigin(string $final, ?Page $page, string $input = ''): array
    {
        $can = $page ? $page->canonical() : null;
        if ($can !== null && preg_match('#^https?://#i', $can) && Crawler::sameSite($can, $final)) {
            $o = self::originOf($can);
            return [$o, $o !== self::originOf($final)];
        }
        return [self::originOf($final), false];
    }

    private function scheme(): string
    {
        return strtolower((string)(parse_url($this->final, PHP_URL_SCHEME) ?: 'https'));
    }

    private function firstOf(array $okRoots): string
    {
        return (string)array_key_first($okRoots);
    }

    /** URL для XML: екранування &, кирилиця і пробіли - у percent-encoding. */
    private static function xmlUrl(string $u): string
    {
        $u = (string)preg_replace_callback('/[^\x21-\x7E]/u', function ($m) { return rawurlencode($m[0]); }, $u);
        return htmlspecialchars($u, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function shortPath(string $u, string $base): string
    {
        return strpos($u, $base) === 0 ? (substr($u, strlen($base)) ?: '/') : $u;
    }

    private static function oneLine(string $s): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    private static function lineSnippet(string $xml, int $line, int $col): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $xml, $line + 1) ?: [];
        $l = (string)($lines[max(0, $line - 1)] ?? '');
        if ($l === '') return '';
        $l = mb_scrub($l, 'UTF-8');
        if (mb_strlen($l) > 220) {
            $start = max(0, $col - 110);
            $l = ($start > 0 ? '... ' : '') . mb_substr($l, $start, 220) . ' ...';
        }
        return 'Рядок ' . $line . ': ' . trim($l);
    }

    /** WAF віддав перевірку замість документа (AWS WAF ставить заголовок x-amzn-waf-action і код 202). */
    private static function wafChallenge(array $r): ?string
    {
        $h = (array)($r['headers'] ?? []);
        if (!empty($h['x-amzn-waf-action'])) return 'aws-waf';
        return null;
    }

    /** Page::challengeFromHtml для довільного тіла: обрізка по межі символу UTF-8, без винятків. */
    private static function safeChallenge(string $body): ?string
    {
        try {
            $b = strlen($body) > 150000 ? mb_strcut($body, 0, 150000, 'UTF-8') : $body;
            if (!mb_check_encoding($b, 'UTF-8')) $b = mb_scrub($b, 'UTF-8');
            return Page::challengeFromHtml($b);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function num(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    /** Оцінка без зайвої точності: «≈6,7 млн», «≈52 000», «≈3 120». */
    private static function approx(int $n): string
    {
        if ($n >= 1000000) return '≈' . str_replace('.', ',', sprintf('%.1f', $n / 1000000)) . ' млн';
        if ($n >= 10000) return '≈' . self::num((int)(round($n / 1000) * 1000));
        return '≈' . self::num($n);
    }

    /** «3 адреси», «5 адрес», «21 адресу» - число з відмінком. */
    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $m10 = $n % 10; $m100 = $n % 100;
        $w = ($m10 === 1 && $m100 !== 11) ? $one : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? $few : $many);
        return self::num($n) . ' ' . $w;
    }
}
