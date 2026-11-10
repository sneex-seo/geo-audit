<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';

/**
 * Рендеринг та індексованість: чи є контент у сирому HTML (SSR vs CSR) на головній і внутрішніх сторінках,
 * canonical, html lang проти фактичної мови тексту, hreflang (для багатомовних сайтів),
 * коди відповіді сторінок вибірки і ланцюжок редиректів головної.
 */
class ContentRenderCheck extends BaseCheck
{
    const NET_TIMEOUT = 15;
    const UA_GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    const UA_OAI = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.3; +https://openai.com/searchbot';

    /** ISO 639-1 (дволітерні коди мов). */
    const ISO639_1 = 'aa ab ae af ak am an ar as av ay az ba be bg bh bi bm bn bo br bs ca ce ch co cr cs cu cv cy da de dv dz ee el en eo es et eu fa ff fi fj fo fr fy ga gd gl gn gu gv ha he hi ho hr ht hu hy hz ia id ie ig ii ik io is it iu ja jv ka kg ki kj kk kl km kn ko kr ks ku kv kw ky la lb lg li ln lo lt lu lv mg mh mi mk ml mn mr ms mt my na nb nd ne ng nl nn no nr nv ny oc oj om or os pa pi pl ps pt qu rm rn ro ru rw sa sc sd se sg si sk sl sm sn so sq sr ss st su sv sw ta te tg th ti tk tl tn to tr ts tt tw ty ug uk ur uz ve vi vo wa wo xh yi yo za zh zu';

    const LANG_NAMES = ['uk' => 'українська', 'ru' => 'російська', 'en' => 'англійська'];

    public function id(): string { return 'render'; }
    public function name(): string { return 'Рендеринг та індексованість'; }
    public function weight(): float { return 0.10; }

    public function run(string $url, array $ctx): array
    {
        $page = (($ctx['page'] ?? null) instanceof Page) ? $ctx['page'] : null;
        $access = is_array($ctx['access'] ?? null) ? $ctx['access'] : [];
        if ($page === null) {
            return $this->skipped(self::blockedReason($access, (array)($ctx['homepage'] ?? [])), [
                'homepage_status' => (int)($access['homepage_status'] ?? 0),
                'block_reason'    => $access['block_reason'] ?? null,
            ]);
        }
        if (self::isBotWall($page, (array)($ctx['homepage'] ?? []))) {
            return $this->skipped('Головна віддала сторінку перевірки «чи ви не робот» (захист від ботів) замість контенту - сирий HTML сайту недоступний, тому рендеринг та індексованість оцінити неможливо. Це не означає, що контент рендериться через JavaScript.', [
                'homepage_status' => (int)($access['homepage_status'] ?? 0),
                'block_reason'    => 'bot_wall',
            ]);
        }
        $final = (string)($ctx['url'] ?? $url);
        $site = is_array($ctx['site'] ?? null) ? $ctx['site'] : [];
        $samples = is_array($ctx['samples'] ?? null) ? $ctx['samples'] : [];
        $asBrowser = ($access['fetched_as'] ?? 'bot') === 'browser';

        $pages = [[
            'role' => 'home', 'label' => 'Головна', 'url' => $final, 'page' => $page,
            'res' => is_array($ctx['homepage'] ?? null) ? $ctx['homepage'] : [],
        ]];
        $walls = [];
        foreach ($samples as $i => $s) {
            if (!(($s['page'] ?? null) instanceof Page)) continue;
            $res = is_array($s['res'] ?? null) ? $s['res'] : [];
            if (self::isBotWall($s['page'], $res)) {
                // сторінка захисту від ботів, яку не розпізнав загальний детектор: не оцінюємо її як контент
                $samples[$i]['challenge'] = $samples[$i]['challenge'] ?? 'bot_wall';
                $walls[] = (string)($s['url'] ?? '');
                continue;
            }
            $pages[] = [
                'role' => (string)($s['role'] ?? 'other'), 'label' => self::roleLabel((string)($s['role'] ?? 'other')),
                'url' => (string)($res['final_url'] ?? ($s['url'] ?? '')), 'page' => $s['page'], 'res' => $res,
            ];
        }

        $findings = [];
        $passed = [];
        $sub = [];
        $data = ['pages_checked' => count($pages)];
        if ($walls) $data['samples_bot_wall'] = $walls;
        $caps = [];

        $platform = self::platform((array)(($ctx['homepage'] ?? [])['headers'] ?? []));
        if ($platform !== '') $data['platform'] = $platform;
        $this->checkSsr($pages, $final, $platform, $sub, $findings, $passed, $data, $caps);
        $this->checkCanonical($pages, $asBrowser, $sub, $findings, $passed, $data);
        $this->checkLang($pages, $site, $sub, $findings, $passed, $data);
        $this->checkHreflang($page, $final, $pages, $site, $sub, $findings, $passed, $data);
        $this->checkStatuses($samples, (array)($ctx['homepage'] ?? []), (string)($ctx['input_url'] ?? $url), $final, $asBrowser, $sub, $findings, $passed, $data);
        $this->checkPriceInHtml($pages, $site, $findings, $data);
        $data['noscript'] = self::noscriptInfo($page);

        $labels = [
            'ssr_home'      => 'Текст головної в сирому HTML',
            'ssr_samples'   => 'Текст внутрішніх сторінок у сирому HTML',
            'canonical'     => 'Canonical',
            'lang'          => 'Атрибут lang і мова контенту',
            'hreflang'      => 'hreflang',
            'sample_status' => 'Коди відповіді й редиректи',
        ];
        $subOrdered = [];
        foreach (array_keys($labels) as $k) $subOrdered[$k] = $sub[$k] ?? null;
        $weights = ['ssr_home' => 3.0, 'ssr_samples' => 2.0, 'canonical' => 2.0, 'lang' => 1.0, 'hreflang' => 1.0, 'sample_status' => 1.0];
        $comp = [];
        foreach ($subOrdered as $k => $v) $comp[] = [$v, $weights[$k]];
        $score = $this->scoreFrom($comp);
        foreach ($caps as $c) $score = min($score, $c);

        return $this->result($score, $subOrdered, $labels, $findings, $passed, $data, $this->summaryLine($findings, $data));
    }

    // ================================================================== SSR / CSR

    private function checkSsr(array $pages, string $final, string $platform, array &$sub, array &$findings, array &$passed, array &$data, array &$caps): void
    {
        $csr = []; $thin = []; $nosPages = []; $partial = []; $jsData = []; $hiddenPages = []; $framePages = []; $okSamples = []; $sampleScores = [];
        $homeInfo = null;
        foreach ($pages as $i => $pg) {
            $info = self::ssrInfo($pg['page']);
            $row = ['role' => $pg['role'], 'url' => $pg['url'], 'state' => $info['state'], 'words' => $info['words'],
                'main_words' => $info['main'], 'frameworks' => $info['fw'], 'empty_root' => $info['empty_root']];
            if ($info['hidden_words'] > 0) $row['hidden_words'] = $info['hidden_words'];
            if ($info['iframes']) $row['iframes'] = array_column($info['iframes'], 'src');
            $data['ssr'][] = $row;
            $sc = ['csr' => 0, 'thin' => 30, 'iframe' => 30, 'partial' => 50, 'hidden' => 50, 'jsdata' => 55, 'noscript' => 60, 'short' => 85][$info['state']] ?? 100;
            if ($i === 0) { $sub['ssr_home'] = $sc; $homeInfo = $info; }
            else $sampleScores[] = $sc;
            if ($info['state'] === 'csr') $csr[] = $pg + ['info' => $info];
            elseif ($info['state'] === 'thin') $thin[] = $pg + ['info' => $info];
            elseif ($info['state'] === 'iframe') $framePages[] = $pg + ['info' => $info];
            elseif ($info['state'] === 'hidden') $hiddenPages[] = $pg + ['info' => $info];
            elseif ($info['state'] === 'noscript') $nosPages[] = $pg + ['info' => $info];
            elseif ($info['state'] === 'partial') $partial[] = $pg + ['info' => $info];
            elseif ($info['state'] === 'jsdata') $jsData[] = $pg + ['info' => $info];
            elseif ($i > 0 && $info['state'] === 'ssr') $okSamples[] = $info['words'];
        }
        $sub['ssr_samples'] = $sampleScores ? (int)round(array_sum($sampleScores) / count($sampleScores)) : null;
        // майже порожня головна (без доказів CSR) - бал перевірки не може бути високим
        if ($homeInfo && in_array($homeInfo['state'], ['thin', 'iframe'], true)) $caps[] = 60;

        // «текст у сирому HTML» хвалимо лише від 50 слів: 0-49 слів - не доказ коректного SSR
        if ($homeInfo && $homeInfo['state'] === 'ssr') {
            $passed[] = 'Головна віддає текст у сирому HTML: ' . self::num($homeInfo['words']) . ' ' . self::plural($homeInfo['words'], 'слово', 'слова', 'слів') . ' без виконання JavaScript';
        }
        if ($okSamples) {
            $passed[] = count($okSamples) . ' ' . self::plural(count($okSamples), 'внутрішня сторінка', 'внутрішні сторінки', 'внутрішніх сторінок') . ' теж ' . (count($okSamples) === 1 ? 'віддає' : 'віддають') . ' текст у HTML'
                . (count($okSamples) > 1 ? ': від ' . self::num(min($okSamples)) . ' до ' . self::num(max($okSamples)) . ' слів' : ': ' . self::num($okSamples[0]) . ' слів');
        }

        if ($csr) {
            $homeCsr = $csr[0]['role'] === 'home';
            $caps[] = $homeCsr ? 30 : 50;
            $fwAll = [];
            foreach ($csr as $c) foreach ($c['info']['fw'] as $f) $fwAll[$f] = true;
            $fw = array_keys($fwAll);
            $lines = [];
            foreach (array_slice($csr, 0, 4) as $c) $lines[] = self::ssrLine($c);
            $first = $csr[0];
            [$fix, $code, $fwLabel] = self::ssrRecipe($fw, $first['url'], $platform);
            $p0 = $first['page'];
            $h1 = $p0->title() !== '' ? $p0->title() : '[ЗАГОЛОВОК СТОРІНКИ]';
            $desc = $p0->metaDescription() !== '' ? $p0->metaDescription() : '[Короткий опис: що це за сервіс/сторінка і чим корисна]';
            $rootId = preg_match('/id="([^"]+)"/', (string)$first['info']['root'], $rm) ? $rm[1] : 'root';
            $good = "<body>\n  <div id=\"" . $rootId . "\">\n    <header>...</header>\n    <main>\n      <h1>" . self::mbTrunc($h1, 90) . "</h1>\n      <p>" . self::mbTrunc($desc, 200) . "</p>\n      <!-- основний текст, списки, ціни - уже в HTML-відповіді сервера -->\n    </main>\n  </div>\n  <script type=\"module\" src=\"/assets/app.js\"></script>\n</body>";
            $findings[] = $this->finding('csr_only', 'critical', $homeCsr
                ? 'Контент головної з\'являється лише після виконання JavaScript'
                : 'Ключові сторінки віддають майже порожній HTML (контент рендериться JavaScript)', [
                'evidence' => implode("\n", $lines) . (count($csr) > 4 ? "\nІ ще " . (count($csr) - 4) . ' сторінок.' : '') . ($fwLabel !== '' ? "\nВиявлено: " . $fwLabel . '.' : ''),
                'why'      => 'Більшість AI-краулерів (GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot) не виконують JavaScript і читають лише HTML-відповідь сервера. Для них ця сторінка порожня: її неможливо процитувати, хоч би яким був контент після рендеру. Googlebot JavaScript рендерить, але з затримкою і не завжди повністю.',
                'fix'      => $fix,
                'bad'      => self::bodyExcerpt($p0->html(), 520),
                'good'     => $good,
                'code'     => $code,
                'verify'   => 'curl -s -A "OAI-SearchBot/1.0" ' . $first['url'] . ' > page.html і пошук у файлі фрази з основного тексту сторінки (не з title чи description) - вона має знайтися. Те саме через «Перегляд коду сторінки» (Ctrl+U), не DevTools.',
                'effort'   => 'L',
            ]);
        }
        if ($thin) {
            $homeThin = $thin[0]['role'] === 'home';
            $lines = [];
            foreach (array_slice($thin, 0, 4) as $c) $lines[] = self::ssrLine($c);
            $first = $thin[0];
            $p0 = $first['page'];
            $withJs = $first['info']['ext_scripts'] >= 1 || $first['info']['inline_bytes'] > 2000;
            $findings[] = $this->finding('thin_html', $homeThin ? 'high' : 'medium', $homeThin
                ? 'У сирому HTML головної майже немає тексту'
                : 'Внутрішні сторінки віддають майже порожній HTML', [
                'evidence' => implode("\n", $lines) . "\n" . ($withJs
                    ? 'Порожнього контейнера застосунку чи маркерів SPA-фреймворку не знайдено, тому автоматично не вдалося підтвердити, чи контент підставляє JavaScript, чи сторінка справді порожня. Порівняйте Ctrl+U з тим, що видно в браузері.'
                    : 'Скриптів, які могли б підставити текст, на сторінці майже немає - ймовірно, сторінка справді майже без тексту (або текст лише на зображеннях).'),
                'why'      => 'AI-краулери (GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot) здебільшого не виконують JavaScript і бачать лише HTML-відповідь сервера. Сторінку майже без тексту вони не зможуть ні зрозуміти, ні процитувати.',
                'fix'      => $withJs
                    ? 'Якщо контент з\'являється після завантаження скриптів - віддавайте основний текст у HTML (SSR, SSG або пререндер). Якщо сторінка справді порожня - додайте змістовний текст: що це, для кого, ключові факти.'
                    : 'Додайте на сторінку змістовний текст у HTML: що це за сайт чи сторінка, для кого, ключові факти. Текст на зображеннях AI-краулери не читають.',
                'bad'      => self::bodyExcerpt($p0->html(), 520),
                'good'     => "<main>\n  <h1>" . self::mbTrunc($p0->title() !== '' ? $p0->title() : '[ЗАГОЛОВОК СТОРІНКИ]', 90) . "</h1>\n  <p>" . self::mbTrunc($p0->metaDescription() !== '' ? $p0->metaDescription() : '[Короткий опис: що це за сторінка і чим корисна]', 200) . "</p>\n  <!-- основний текст сторінки - уже в HTML-відповіді сервера -->\n</main>",
                'code'     => '',
                'verify'   => 'curl -s -A "OAI-SearchBot/1.0" ' . $first['url'] . ' > page.html і пошук у файлі фрази з основного тексту сторінки; або Ctrl+U у браузері (не DevTools).',
                'effort'   => 'M',
            ]);
        }
        if ($framePages) {
            $homeFrame = $framePages[0]['role'] === 'home';
            $lines = [];
            foreach (array_slice($framePages, 0, 4) as $c) {
                $lines[] = self::ssrLine($c) . ' Вміст завантажується з: ' . implode(', ', array_column($c['info']['iframes'], 'src')) . '.';
            }
            $first = $framePages[0];
            $p0 = $first['page'];
            $findings[] = $this->finding('content_in_iframe', $homeFrame ? 'high' : 'medium', $homeFrame
                ? 'Контент головної підвантажується через iframe, а сама сторінка майже порожня'
                : 'Внутрішні сторінки показують контент лише через iframe', [
                'evidence' => implode("\n", $lines),
                'why'      => 'Вміст iframe - окремий документ з іншою адресою. Пошуковики й AI-краулери індексують його окремо (якщо взагалі можуть) і не зараховують цій сторінці, тож для них вона майже порожня і цитувати на ній нічого.',
                'fix'      => 'Виведіть основний текст безпосередньо в HTML сторінки. iframe лишайте для віджетів (карта, відео, форма), а не для основного змісту.',
                'bad'      => $first['info']['iframes'][0]['tag'],
                'good'     => "<main>\n  <h1>" . self::mbTrunc($p0->title() !== '' ? $p0->title() : '[ЗАГОЛОВОК СТОРІНКИ]', 90) . "</h1>\n  <p>" . self::mbTrunc($p0->metaDescription() !== '' ? $p0->metaDescription() : '[Короткий опис: що це за сторінка і чим корисна]', 200) . "</p>\n  <!-- основний текст - у HTML самої сторінки, а не в iframe -->\n</main>",
                'code'     => '',
                'verify'   => 'Ctrl+U на ' . $first['url'] . ' (сирий HTML) - основний текст має знаходитися через Ctrl+F поза тегом <iframe>.',
                'effort'   => 'M',
            ]);
        }
        if ($hiddenPages) {
            $homeHidden = $hiddenPages[0]['role'] === 'home';
            $lines = [];
            foreach (array_slice($hiddenPages, 0, 4) as $c) $lines[] = self::ssrLine($c);
            $first = $hiddenPages[0];
            $fi = $first['info'];
            $p0 = $first['page'];
            $findings[] = $this->finding('content_hidden', $homeHidden ? 'medium' : 'low', $homeHidden
                ? 'Текст головної є в HTML, але прихований (display:none)'
                : 'Текст внутрішніх сторінок є в HTML, але прихований (display:none)', [
                'evidence' => implode("\n", $lines) . "\nНайбільший прихований блок " . $fi['hidden_tag'] . ': «' . self::mbTrunc((string)$fi['hidden_sample'], 160) . '».'
                    . "\nЙмовірно, блок показує JavaScript або це текст лише для пошуковиків. Автоматично підтвердити, чи бачать його відвідувачі, не вдалося.",
                'why'      => 'Частина парсерів і AI-систем відкидає приховані елементи як службові. Вкладки й акордеони Google порушенням не вважає, але текст, який відвідувачі не бачать за жодних умов, може суперечити правилам Google щодо прихованого тексту.',
                'fix'      => 'Зробіть основний опис видимим без JavaScript - у звичайній розмітці сторінки. Приховувати варто лише другорядні елементи (вкладки, акордеони), а не весь зміст.',
                'bad'      => $fi['hidden_tag'] . "\n  " . self::mbTrunc((string)$fi['hidden_sample'], 200) . "\n</" . (preg_match('/^<([a-z0-9]+)/i', (string)$fi['hidden_tag'], $tm) ? strtolower($tm[1]) : 'div') . '>',
                'good'     => "<main>\n  <h1>" . self::mbTrunc($p0->title() !== '' ? $p0->title() : '[ЗАГОЛОВОК СТОРІНКИ]', 90) . "</h1>\n  <p>" . self::mbTrunc((string)$fi['hidden_sample'], 160) . "</p>\n  <!-- той самий текст, але видимий одразу, без display:none -->\n</main>",
                'code'     => '',
                'verify'   => 'Відкрийте ' . $first['url'] . ' з вимкненим JavaScript (DevTools -> Ctrl+Shift+P -> «Disable JavaScript») - основний текст має бути видно на сторінці.',
                'effort'   => 'M',
            ]);
        }
        if ($nosPages) {
            $lines = [];
            foreach (array_slice($nosPages, 0, 4) as $c) {
                $lines[] = self::ssrLine($c) . ' Усередині <noscript> - ' . self::num((int)$c['info']['noscript_words']) . ' слів.';
            }
            $first = $nosPages[0];
            $p0 = $first['page'];
            $nosHtml = '';
            foreach ($p0->query('//body//noscript') as $n) {
                if ($p0->wordCount(Page::norm((string)$n->textContent)) >= 20) { $nosHtml = (string)$p0->dom()->saveHTML($n); break; }
            }
            $nosHtml = self::mbTrunc(trim((string)preg_replace('/\s+/u', ' ', $nosHtml)), 420);
            $findings[] = $this->finding('content_in_noscript', 'medium', $first['role'] === 'home'
                ? 'Основний текст головної є лише всередині <noscript>'
                : 'Основний текст внутрішніх сторінок є лише всередині <noscript>', [
                'evidence' => implode("\n", $lines) . "\nПоза <noscript> сторінка майже порожня, а текст для відвідувачів малює JavaScript.",
                'why'      => 'Текст у <noscript> формально є в HTML, але частина парсерів і AI-краулерів відкидає цей блок як службовий (він призначений для браузерів з вимкненим JavaScript). Покладатися на нього як на єдине джерело основного контенту ризиковано.',
                'fix'      => 'Виведіть основний текст у звичайну розмітку сторінки (видиму без JavaScript), а скрипти використовуйте лише для інтерактивності.',
                'bad'      => $nosHtml !== '' ? $nosHtml : self::bodyExcerpt($p0->html(), 420),
                'good'     => "<main>\n  <h1>" . self::mbTrunc($p0->title() !== '' ? $p0->title() : '[ЗАГОЛОВОК СТОРІНКИ]', 90) . "</h1>\n  <p>[Той самий текст, що зараз у noscript, - у звичайному HTML]</p>\n</main>\n<script src=\"/js/app.js\" defer></script>",
                'code'     => '',
                'verify'   => 'curl -s ' . $first['url'] . ' і перевірка, що основний текст знаходиться поза тегом <noscript>; Ctrl+U у браузері.',
                'effort'   => 'M',
            ]);
        }
        if ($jsData) {
            $homeJs = $jsData[0]['role'] === 'home';
            $lines = [];
            foreach (array_slice($jsData, 0, 4) as $c) {
                $i = $c['info'];
                $lines[] = $c['label'] . ' ' . $c['url'] . ': в HTML ' . self::num($i['main']) . ' ' . self::plural($i['main'], 'слово', 'слова', 'слів') . ' основного тексту, ще '
                    . self::num($i['js_words']) . ' ' . self::plural($i['js_words'], 'слово', 'слова', 'слів') . ' є лише в даних JavaScript (' . $i['js_source'] . ') і з\'являються після рендеру в браузері.';
                foreach (array_slice($i['js_items'], 0, 3) as $it) $lines[] = '  - «' . self::mbTrunc($it, 140) . '»';
            }
            $first = $jsData[0];
            [$fix, $code, $fwLabel] = self::ssrRecipe($first['info']['fw'], $first['url'], $platform);
            $ex = $first['info']['js_items'][0] ?? '';
            $findings[] = $this->finding('content_in_js_data', $homeJs ? 'high' : 'medium', $homeJs
                ? 'Значна частина контенту головної є лише в даних JavaScript, а не в HTML'
                : 'Частина контенту сторінок з\'являється лише після JavaScript: у HTML її немає', [
                'evidence' => implode("\n", $lines) . (count($jsData) > 4 ? "\nІ ще " . (count($jsData) - 4) . ' сторінок.' : '') . ($fwLabel !== '' ? "\nВиявлено: " . $fwLabel . '.' : ''),
                'why'      => 'Браузер показує ці блоки, бо домальовує їх з даних JavaScript. AI-краулери (GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot) JavaScript не виконують і бачать лише HTML-відповідь, тобто сторінку без цих блоків: процитувати їх вони не можуть, а сама сторінка для них виглядає тонкою.',
                'fix'      => 'Рендерте ці блоки на сервері, щоб текст був уже в HTML-відповіді. ' . $fix,
                'bad'      => "У HTML-відповіді сервера:\n" . self::mbTrunc($first['page']->mainText(), 300) . "\n\nЛише в даних JavaScript:\n«" . self::mbTrunc($ex, 200) . '»',
                'good'     => "<section>\n  <h2>" . self::mbTrunc(preg_split('/[.!?]/u', $ex)[0] ?? $ex, 80) . "</h2>\n  <p>" . self::mbTrunc($ex, 200) . "</p>\n</section>\n<!-- текст уже в HTML-відповіді сервера, а не лише в window.__NUXT__ / __NEXT_DATA__ -->",
                'code'     => $code,
                'verify'   => 'Ctrl+U на ' . $first['url'] . ' (не DevTools) і пошук через Ctrl+F фрази «' . self::mbTrunc($ex, 60) . '»: вона має знайтися в тексті сторінки, а не лише всередині <script>.',
                'effort'   => 'M',
            ]);
        }
        if ($partial) {
            $lines = [];
            foreach (array_slice($partial, 0, 4) as $c) $lines[] = self::ssrLine($c);
            $first = $partial[0];
            [$fix, $code, $fwLabel] = self::ssrRecipe($first['info']['fw'], $first['url'], $platform);
            // без маркера фреймворку порожній #app/#root може бути дрібним віджетом - лише low
            $partialFw = false;
            foreach ($partial as $c) if ($c['info']['fw']) $partialFw = true;
            $findings[] = $this->finding('js_main_content', $partialFw ? 'medium' : 'low', 'Основний блок сторінки, ймовірно, рендериться JavaScript', [
                'evidence' => implode("\n", $lines) . "\nШапка/підвал є в HTML, але контейнер застосунку порожній - найімовірніше, основний вміст підставляє JavaScript. Автоматично підтвердити повністю не вдалося: порівняйте з рендером у браузері.",
                'why'      => 'AI-краулери, що не виконують JavaScript, побачать лише меню і підвал без основного змісту, тож сторінку не процитують.',
                'fix'      => $fix,
                'bad'      => self::bodyExcerpt($first['page']->html(), 520),
                'good'     => "<div id=\"app\">\n  <main>\n    <h1>" . self::mbTrunc($first['page']->title(), 90) . "</h1>\n    <p>Основний текст сторінки вже в HTML-відповіді сервера.</p>\n  </main>\n</div>",
                'code'     => $code,
                'verify'   => 'Ctrl+U на ' . $first['url'] . ' (сирий HTML) - знайдіть через Ctrl+F фразу з основного тексту сторінки.',
                'effort'   => 'L',
            ]);
        }
    }

    private static function ssrInfo(Page $p): array
    {
        $words = $p->wordCount();
        $main = $p->wordCount($p->mainText());
        $fw = $p->frameworkMarkers();
        $empty = $p->emptyAppRoot();
        $sc = $p->scripts();
        $nos = '';
        foreach ($p->query('//noscript') as $n) {
            // текстові вузли через пробіл, щоб «<h2>What?</h2><p>This» не злипалося
            $parts = [];
            foreach ($p->query('.//text()', $n) as $tn) $parts[] = (string)$tn->nodeValue;
            $t = trim(html_entity_decode(strip_tags(implode(' ', $parts)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($t !== '') { $nos .= ' ' . $t; }
        }
        $nos = Page::norm($nos);
        $probe = $nos . ' ' . mb_substr($p->visibleText(), 0, 1500);
        $jsMsg = (bool)preg_match('/(enable|turn on|activate)\s+javascript|javascript\s+(is\s+)?(required|disabled|needed)|need\s+to\s+enable\s+javascript|увімкн\w*\s+javascript|включите\s+javascript|потрібен\s+javascript|необхідно\s+увімкнути/iu', $probe);
        $ext = count($sc['external']);
        $state = 'ssr';
        // CSR: майже немає тексту І є ознака застосунку. Маркер фреймворку сам по собі (без порожнього кореня)
        // доводить CSR лише при < 20 слів - коротка SSR-сторінка на Next.js не є CSR.
        // 'thin': < 20 слів видимого тексту без ознак SPA - причину (JS чи справді порожня сторінка) не підтверджено.
        // 'short': 20-49 слів - текст у HTML є, але його замало, щоб хвалити сторінку як «контент у сирому HTML».
        if ($words < 50 && ($empty || $jsMsg || ($words < 20 && $fw))) $state = 'csr';
        elseif ($empty && $words >= 50) $state = 'partial';
        elseif ($words < 20) $state = 'thin';
        elseif ($words < 50) $state = 'short';
        // основний текст лежить у <noscript>: у сирому HTML він є, але частина парсерів відкидає noscript
        $nosWords = $p->wordCount($nos);
        if (in_array($state, ['csr', 'thin', 'short'], true) && $nosWords >= 50 && $nosWords > $words) $state = 'noscript';
        // текст є, але в прихованому блоці (display:none / hidden) або сторінка - лише обгортка для iframe
        $hidden = ['words' => 0, 'tag' => '', 'sample' => ''];
        $frames = [];
        if ($state === 'thin' || $state === 'short') {
            $hidden = self::hiddenText($p);
            if ($hidden['words'] >= 50 && $hidden['words'] > 3 * $words) $state = 'hidden';
            elseif ($state === 'thin') {
                $frames = self::contentIframes($p);
                if ($frames) $state = 'iframe';
            }
        }
        // текст є в HTML, але значна частина контенту - лише в даних JS-фреймворку (домальовується в браузері)
        $js = ['words' => 0, 'items' => [], 'source' => ''];
        if (in_array($state, ['ssr', 'short', 'thin'], true)) {
            $js = $p->jsOnlyText();
            if ($js['words'] >= 80 && $js['words'] >= 0.5 * max($main, 1)) $state = 'jsdata';
        }
        return ['state' => $state, 'words' => $words, 'main' => $main, 'fw' => $fw, 'empty_root' => $empty, 'js_msg' => $jsMsg,
            'js_words' => $js['words'], 'js_items' => $js['items'], 'js_source' => $js['source'],
            'noscript' => mb_substr($nos, 0, 160), 'noscript_words' => $nosWords, 'scripts' => (int)$sc['count'], 'ext_scripts' => $ext,
            'inline_bytes' => (int)$sc['inline_bytes'], 'root' => self::rootSnippet($p),
            'hidden_words' => $hidden['words'], 'hidden_tag' => $hidden['tag'], 'hidden_sample' => $hidden['sample'], 'iframes' => $frames];
    }

    private static function isHiddenEl(DOMElement $el): bool
    {
        if ($el->hasAttribute('hidden')) return true;
        $st = $el->getAttribute('style');
        return $st !== '' && (bool)preg_match('/(display\s*:\s*none|visibility\s*:\s*hidden)/i', $st);
    }

    /** Текст у прихованих блоках body (display:none / visibility:hidden / hidden): найбільший блок і сума слів. */
    private static function hiddenText(Page $p): array
    {
        $total = 0; $bestW = 0; $tag = ''; $sample = '';
        $n = 0;
        foreach ($p->query('//body//*[@hidden or @style]') as $el) {
            if (!($el instanceof DOMElement) || ++$n > 3000) break;
            $t = strtolower($el->nodeName);
            if (in_array($t, ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'img', 'input'], true)) continue;
            if (!self::isHiddenEl($el)) continue;
            $nested = false;
            for ($a = $el->parentNode; $a instanceof DOMElement; $a = $a->parentNode) {
                $at = strtolower($a->nodeName);
                if ($at === 'noscript' || $at === 'template' || $at === 'script' || self::isHiddenEl($a)) { $nested = true; break; }
            }
            if ($nested) continue;
            $parts = [];
            foreach ($p->query('.//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::template)]', $el) as $tn) $parts[] = (string)$tn->nodeValue;
            $txt = Page::norm(implode(' ', $parts));
            $w = $p->wordCount($txt);
            if ($w === 0) continue;
            $total += $w;
            if ($w > $bestW) { $bestW = $w; $tag = self::openTag($el); $sample = mb_substr($txt, 0, 220); }
        }
        return ['words' => $total, 'tag' => $tag, 'sample' => $sample];
    }

    /** Видимі iframe з контентом (без трекінгових, капчі, крихітних і прихованих). */
    private static function contentIframes(Page $p): array
    {
        $out = [];
        foreach ($p->query('//body//iframe[not(ancestor::noscript)]') as $f) {
            if (!($f instanceof DOMElement)) continue;
            $src = trim($f->getAttribute('src'));
            if ($src === '') $src = trim($f->getAttribute('data-src'));
            if ($src === '' || preg_match('#^(about:|javascript:|data:)#i', $src)) continue;
            $w = (int)$f->getAttribute('width');
            $h = (int)$f->getAttribute('height');
            if (($w > 0 && $w <= 2) || ($h > 0 && $h <= 2) || self::isHiddenEl($f)) continue;
            if (preg_match('#googletagmanager\.com|doubleclick\.net|facebook\.com/tr|recaptcha|hcaptcha|challenges\.cloudflare\.com#i', $src)) continue;
            $out[] = ['src' => Crawler::absolutize($src, $p->url()) ?? $src, 'tag' => self::openTag($f) . '</iframe>'];
            if (count($out) >= 3) break;
        }
        return $out;
    }

    private static function ssrLine(array $c): string
    {
        $i = $c['info'];
        $parts = [self::num($i['words']) . ' ' . self::plural($i['words'], 'слово', 'слова', 'слів') . ' видимого тексту в сирому HTML'];
        if ($i['empty_root'] && $i['root'] !== '') $parts[] = 'порожній ' . $i['root'];
        if (!empty($i['hidden_words'])) $parts[] = self::num((int)$i['hidden_words']) . ' слів у прихованих блоках';
        if (!empty($i['iframes'])) $parts[] = count($i['iframes']) . ' iframe';
        $parts[] = $i['scripts'] . ' ' . self::plural($i['scripts'], 'скрипт', 'скрипти', 'скриптів');
        if ($i['noscript'] !== '') $parts[] = 'noscript: «' . self::mbTrunc($i['noscript'], 90) . '»';
        return $c['label'] . ' ' . $c['url'] . ': ' . implode(', ', $parts) . '.';
    }

    private static function rootSnippet(Page $p): string
    {
        foreach ($p->query('//body//*[@id="root" or @id="app" or @id="__next" or @id="__nuxt" or @id="___gatsby" or @id="svelte" or @id="application" or @id="react-root" or @id="main-app"]') as $el) {
            return '<' . strtolower($el->nodeName) . ' id="' . $el->getAttribute('id') . '">';
        }
        foreach ($p->query('//app-root') as $el) return '<app-root>';
        return '';
    }

    /** [fix, code, label] за виявленим фреймворком. */
    private static function ssrRecipe(array $fw, string $url, string $platform = ''): array
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $prerender = "# Тимчасовий варіант для будь-якого SPA: віддавати ботам пререндерений HTML\n# (prerender.io, Rendertron або власний headless Chrome). Google вважає це обхідним рішенням - пріоритет SSR/SSG.\nmap \$http_user_agent \$is_bot {\n    default 0;\n    \"~*(googlebot|bingbot|oai-searchbot|chatgpt-user|gptbot|perplexitybot|claudebot|claude-searchbot|applebot|duckduckbot)\" 1;\n}\nserver {\n    server_name {$host};\n    resolver 1.1.1.1 8.8.8.8 valid=300s;\n    location / {\n        if (\$is_bot) {\n            rewrite ^(.*)\$ /__prerender\$1 last;\n        }\n        try_files \$uri /index.html;\n    }\n    location ~ ^/__prerender(?<orig>/.*)\$ {\n        internal;\n        proxy_set_header X-Prerender-Token \"[ВКАЖІТЬ ТОКЕН PRERENDER.IO]\";\n        proxy_pass https://service.prerender.io/https://{$host}\$orig\$is_args\$args;\n    }\n}";
        // статичний хостинг (Vercel, Netlify, S3/CloudFront): nginx-конфіг не застосовний - пререндер під час збірки
        $hostLabel = ['vercel' => 'Vercel', 'netlify' => 'Netlify', 'cloudfront' => 'Amazon CloudFront / S3'][$platform] ?? '';
        if ($hostLabel !== '') {
            $prerender = "// Сайт віддається з {$hostLabel}: nginx-правила тут не застосовні.\n// Найпростіше - генерувати статичний HTML маршрутів під час збірки.\n// Vite + React/Vue: Vike (колишній vite-plugin-ssr)\n\n// vite.config.js\nimport react from '@vitejs/plugin-react'\nimport vike from 'vike/plugin'\nexport default { plugins: [react(), vike()] }\n\n// pages/+config.js\nexport default { prerender: true }\n\n// Інший шлях - перенести ключові сторінки на Next.js / Nuxt (SSR або SSG)" . ($platform === 'vercel' ? ', які Vercel підтримує нативно.' : '.');
        }
        if (in_array('next', $fw, true)) {
            return [
                'Сайт на Next.js: рендеріть ключові сторінки на сервері (Server Components, getServerSideProps) або генеруйте статично (SSG/ISR). Дані для основного тексту не завантажуйте лише в useEffect на клієнті.',
                "// app/page.tsx (Next.js App Router): серверний компонент - текст одразу в HTML\nexport const revalidate = 3600; // ISR: перегенерація раз на годину\n\nexport default async function Page() {\n  const data = await getPageData(); // запит виконується на сервері\n  return (\n    <main>\n      <h1>{data.title}</h1>\n      <p>{data.intro}</p>\n    </main>\n  );\n}\n\n// Pages Router: export async function getStaticProps() { ... } або getServerSideProps",
                'Next.js',
            ];
        }
        if (in_array('nuxt', $fw, true)) {
            return [
                'Сайт на Nuxt: увімкніть серверний рендеринг (ssr: true) або пререндер маршрутів; дані завантажуйте через useAsyncData/useFetch, а не в onMounted.',
                "// nuxt.config.ts\nexport default defineNuxtConfig({\n  ssr: true,\n  routeRules: {\n    '/': { prerender: true },\n    '/blog/**': { isr: 3600 },\n  },\n});\n\n// у сторінці: дані на сервері\nconst { data } = await useAsyncData('page', () => \$fetch('/api/page'));",
                'Nuxt',
            ];
        }
        if (in_array('angular', $fw, true)) {
            return [
                'Сайт на Angular: додайте серверний рендеринг або пререндер маршрутів (@angular/ssr).',
                "# Angular 17+\nng add @angular/ssr\n\n# angular.json -> projects.<app>.architect.build.options\n\"prerender\": true,\n\"ssr\": { \"entry\": \"src/server.ts\" }",
                'Angular',
            ];
        }
        if (in_array('svelte', $fw, true)) {
            return [
                'Сайт на Svelte: використовуйте SvelteKit із серверним рендерингом (за замовчуванням) або пререндером сторінок.',
                "// src/routes/+layout.js (SvelteKit)\nexport const prerender = true; // статичний HTML для всіх сторінок\nexport const ssr = true;",
                'SvelteKit / Svelte',
            ];
        }
        if (in_array('gatsby', $fw, true) || in_array('astro', $fw, true) || in_array('remix', $fw, true)) {
            return [
                'Фреймворк вміє генерувати HTML на сервері, але ці сторінки віддаються порожніми: перевірте, чи не винесено їх у client-only маршрути і чи дані не завантажуються лише в браузері.',
                $prerender,
                implode(', ', array_map('ucfirst', $fw)),
            ];
        }
        if (in_array('vue', $fw, true)) {
            return [
                'Vue-застосунок без SSR: перенесіть ключові сторінки на Nuxt (SSR/SSG) або згенеруйте статичний HTML під час збірки (vite-ssg). Як тимчасовий захід - пререндер для ботів.',
                $prerender,
                'Vue (клієнтський рендеринг)',
            ];
        }
        if (in_array('react', $fw, true)) {
            return [
                'React-застосунок без SSR: перенесіть ключові сторінки на Next.js або Remix (SSR/SSG) чи пререндеріть їх під час збірки. Як тимчасовий захід - пререндер для ботів.',
                $prerender,
                'React (клієнтський рендеринг)',
            ];
        }
        return [
            'Налаштуйте серверний рендеринг (SSR) або статичну генерацію (SSG) ключових сторінок, щоб заголовок і основний текст (а для магазину - ціни й наявність) були в HTML-відповіді сервера. Як тимчасовий захід - пререндер для ботів (prerender.io, Rendertron).',
            $prerender,
            '',
        ];
    }

    private static function bodyExcerpt(string $html, int $max): string
    {
        $b = preg_match('#<body\b[^>]*>.*?(</body>|$)#is', $html, $m) ? $m[0] : $html;
        $b = (string)preg_replace_callback('#(<script\b[^>]*>)(.*?)(</script>)#is', function ($m) {
            return strlen(trim($m[2])) > 60 ? $m[1] . '...' . $m[3] : $m[0];
        }, $b);
        $b = (string)preg_replace('#<style\b[^>]*>.*?</style>#is', '<style>...</style>', $b);
        $b = (string)preg_replace('#<svg\b.*?</svg>#is', '<svg>...</svg>', $b);
        $b = (string)preg_replace('#<!--.*?-->#s', '', $b);
        $b = trim((string)preg_replace('/\s+/u', ' ', $b));
        return self::mbTrunc($b, $max);
    }

    private static function noscriptInfo(Page $p): array
    {
        $n = 0; $texts = [];
        foreach ($p->query('//noscript') as $ns) {
            $t = Page::norm(html_entity_decode(strip_tags((string)$ns->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $n++;
            if ($t !== '' && count($texts) < 2) $texts[] = mb_substr($t, 0, 120);
        }
        return ['count' => $n, 'texts' => $texts];
    }

    // ================================================================== canonical

    private function checkCanonical(array $pages, bool $asBrowser, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        $rows = [];
        $toVerify = [];
        foreach ($pages as $i => $pg) {
            /** @var Page $p */
            $p = $pg['page'];
            $raw = [];
            foreach ($p->query('//link[@rel][@href]') as $l) {
                $rels = preg_split('/\s+/', strtolower(trim($l->getAttribute('rel'))));
                if (in_array('canonical', (array)$rels, true)) $raw[] = trim($l->getAttribute('href'));
            }
            $header = null;
            foreach ((array)($pg['res']['headers']['link'] ?? []) as $lv) {
                foreach (explode(',', (string)$lv) as $part) {
                    if (preg_match('/<([^>]+)>\s*;.*rel\s*=\s*"?canonical"?/i', $part, $m)) { $header = trim($m[1]); break 2; }
                }
            }
            $abs = $p->canonical();
            $src = 'html';
            if ($abs === null && $header !== null) { $abs = Crawler::absolutize($header, $pg['url'], false); $src = 'header'; }
            $distinct = [];
            foreach ($raw as $r) {
                $a = Crawler::absolutize($r, $pg['url'], false);
                if ($a !== null) $distinct[self::normUrl($a)] = $a;
            }
            $row = ['i' => $i, 'label' => $pg['label'], 'url' => $pg['url'], 'raw' => $raw[0] ?? $header, 'abs' => $abs, 'src' => $src, 'state' => 'self', 'relative' => false];
            if ($abs === null) {
                $row['state'] = 'missing';
            } elseif (count($distinct) > 1) {
                $row['state'] = 'multiple';
                $row['all'] = array_values($distinct);
            } elseif (!Crawler::sameSite($abs, $pg['url'])) {
                $row['state'] = 'other_host';
            } elseif (self::normUrl($abs) !== self::normUrl($pg['url'])) {
                $row['state'] = 'mismatch';
                if (count($toVerify) < 4) $toVerify[$i] = $abs;
            }
            if ($row['state'] === 'self' && $src === 'html' && isset($raw[0]) && !preg_match('#^https?://#i', $raw[0])) $row['relative'] = true;
            $rows[$i] = $row;
        }
        // перевіряємо цільові адреси canonical, що відрізняються від сторінки, і повторно завантажуємо
        // проблемні сторінки: canonical, що змінюється між запитами, - ознака кешу, а не стабільної помилки
        $reqs = [];
        foreach ($toVerify as $i => $u) $reqs['v' . $i] = ['url' => $u, 'follow' => false, 'browser' => $asBrowser, 'max_bytes' => 65536];
        $n = 0;
        foreach ($rows as $i => $r) {
            if (!in_array($r['state'], ['mismatch', 'other_host', 'multiple'], true) || $n >= 4) continue;
            $reqs['r' . $i] = ['url' => $r['url'], 'follow' => true, 'browser' => $asBrowser, 'max_bytes' => 2097152];
            $n++;
        }
        if ($reqs) {
            $res = self::fetchBatch($reqs);
            foreach ($res as $key => $r) {
                $i = (int)substr((string)$key, 1);
                if ($key[0] === 'v') {
                    $st = (int)$r['status'];
                    $loc = $r['hops'][0]['location'] ?? null;
                    $rows[$i]['target_status'] = $st;
                    if ($loc !== null) $rows[$i]['target_location'] = Crawler::absolutize($loc, (string)$rows[$i]['abs'], false) ?? $loc;
                    continue;
                }
                if ((int)$r['status'] !== 200 || $r['body'] === '' || Page::challengeFromHtml($r['body']) !== null) continue;
                if (!isset($rows[$i])) continue;
                $again = Page::fromHtml($r['body'], (string)$r['final_url'], $r['headers'])->canonical();
                if ($again !== null && self::normUrl($again) !== self::normUrl((string)$rows[$i]['abs'])) {
                    $rows[$i]['first_state'] = $rows[$i]['state'];
                    $rows[$i]['state'] = 'unstable';
                    $rows[$i]['again'] = $again;
                }
            }
            // цільова адреса canonical редиректить: перевіряємо, чи так само для Googlebot (редирект може залежати від User-Agent)
            $gReqs = [];
            foreach ($rows as $i => $r) {
                $ts = (int)($r['target_status'] ?? 0);
                if ($r['state'] === 'mismatch' && $ts >= 300 && $ts < 400) $gReqs[$i] = ['url' => (string)$r['abs'], 'follow' => false, 'ua' => self::UA_GOOGLEBOT, 'no_lang' => true, 'max_bytes' => 65536];
            }
            foreach ($gReqs ? self::fetchBatch($gReqs) : [] as $i => $r) {
                if ((int)$r['status'] === 200) {
                    $rows[$i]['target_status'] = 200;
                    $rows[$i]['target_note'] = 'редиректить лише для невідомого User-Agent, Googlebot отримує 200';
                    unset($rows[$i]['target_location']);
                }
            }
        }

        // класифікація canonical на іншу адресу: явна помилка (редирект/4xx/UTM/головна) чи, ймовірно, навмисна склейка дублів
        $homeNorm = self::normUrl((string)$pages[0]['url']);
        foreach ($rows as $i => $r) {
            if ($r['state'] !== 'mismatch') continue;
            $ts = (int)($r['target_status'] ?? 0);
            $abs = (string)$r['abs'];
            $absPath = (string)parse_url($abs, PHP_URL_PATH);
            if (self::hasTracking($abs)) $kind = 'utm';
            elseif (strtolower((string)parse_url($abs, PHP_URL_SCHEME)) === 'http' && strtolower((string)parse_url((string)$r['url'], PHP_URL_SCHEME)) === 'https') $kind = 'insecure';
            elseif ($ts >= 300) $kind = 'target_bad';
            elseif (self::variantOf($abs, (string)$r['url'])) $kind = 'variant';
            elseif ($i > 0 && (self::normUrl($abs) === $homeNorm || $absPath === '/' || $absPath === '')
                && !preg_match('#^/?(index\.(php|html?)|home|main)?/?$#i', (string)parse_url((string)$r['url'], PHP_URL_PATH))) $kind = 'home';
            else $kind = 'other';
            $rows[$i]['kind'] = $kind;
        }
        $scoreMap = ['self' => 100, 'missing' => 40, 'multiple' => 40, 'mismatch' => 40, 'other_host' => 0, 'unstable' => 70];
        $scores = [];
        foreach ($rows as $i => $r) {
            $s = $scoreMap[$r['state']];
            if ($r['state'] === 'self' && $r['relative']) $s = 85;
            if ($r['state'] === 'mismatch') {
                $kind = $r['kind'] ?? 'other';
                if ($kind === 'variant') $s = 100;
                elseif ($kind === 'other') $s = (int)($r['target_status'] ?? 0) === 200 ? 60 : 50;
            }
            $scores[] = $s;
            if ($i === 0) $scores[] = $s; // головна з подвійною вагою
        }
        $sub['canonical'] = $scores ? (int)round(array_sum($scores) / count($scores)) : null;
        $data['canonical'] = array_values(array_map(function ($r) {
            return ['url' => $r['url'], 'canonical' => $r['abs'], 'state' => $r['state'], 'kind' => $r['kind'] ?? null, 'target_status' => $r['target_status'] ?? null];
        }, $rows));

        $by = [];
        foreach ($rows as $r) {
            $st = $r['state'];
            if ($st === 'mismatch' && ($r['kind'] ?? '') === 'variant') $st = 'variant';
            $by[$st][] = $r;
        }
        $selfRows = $by['self'] ?? [];
        $variantRows = $by['variant'] ?? [];
        $goodCount = count($selfRows) + count($variantRows);
        $varNote = $variantRows ? ' (на ' . count($variantRows) . ' - на чисту версію адреси без параметрів чи слеша, напр. ' . $variantRows[0]['url'] . ' -> ' . $variantRows[0]['abs'] . ', це коректна склейка)' : '';
        if ($goodCount === count($rows)) {
            $passed[] = count($rows) === 1
                ? ($selfRows ? 'Canonical головної вказує на саму сторінку (' . $selfRows[0]['abs'] . ')' : 'Canonical головної вказує на її чисту адресу' . $varNote)
                : 'Canonical на всіх ' . count($rows) . ' перевірених сторінках коректний: вказує на саму сторінку' . $varNote;
        } elseif ($goodCount > 0) {
            $passed[] = 'Canonical коректний (вказує сам на себе) на ' . $goodCount . ' з ' . count($rows) . ' сторінок' . $varNote;
        }

        if (!empty($by['other_host'])) {
            $lines = [];
            foreach ($by['other_host'] as $r) $lines[] = $r['label'] . ' ' . $r['url'] . ' -> canonical ' . $r['abs'];
            $r0 = $by['other_host'][0];
            $findings[] = $this->finding('canonical_other_host', 'high', 'Canonical вказує на інший домен', [
                'evidence' => implode("\n", $lines),
                'why'      => 'Canonical на інший домен каже пошуковикам і AI-краулерам: «оригінал не тут». Сторінка може випасти з індексу, а цитування і сигнали піде на чужу адресу.',
                'fix'      => 'Якщо сторінка - оригінал, вкажіть canonical на її власну адресу. Якщо це справді копія іншого сайту, переконайтеся, що так задумано.',
                'bad'      => '<link rel="canonical" href="' . $r0['raw'] . '">',
                'good'     => '<link rel="canonical" href="' . $r0['url'] . '">',
                'code'     => '<link rel="canonical" href="' . $r0['url'] . '">',
                'verify'   => 'Google Search Console -> Перевірка URL -> «Канонічна URL-адреса, вибрана Google» має збігатися з адресою сторінки.',
                'effort'   => 'S',
            ]);
        }
        if (!empty($by['mismatch'])) {
            $lines = [];
            $explicit = false;
            $mm = $by['mismatch'];
            // спершу явні помилки
            usort($mm, function ($a, $b) { return (int)(($a['kind'] ?? 'other') === 'other') <=> (int)(($b['kind'] ?? 'other') === 'other'); });
            foreach ($mm as $r) {
                $t = $r['label'] . ' ' . $r['url'] . "\n  canonical: " . $r['abs'];
                if (isset($r['target_status'])) {
                    $ts = (int)$r['target_status'];
                    if ($ts >= 300 && $ts < 400) { $t .= ' (ця адреса редиректить: ' . $ts . (isset($r['target_location']) ? ' -> ' . $r['target_location'] : '') . ')'; $explicit = true; }
                    elseif ($ts >= 400) { $t .= ' (ця адреса відповідає ' . $ts . ')'; $explicit = true; }
                    elseif ($ts === 200) $t .= ' (відповідає 200)';
                }
                if (($r['kind'] ?? '') === 'utm') { $t .= ' - canonical містить UTM/трекінгові параметри'; $explicit = true; }
                if (($r['kind'] ?? '') === 'insecure') { $t .= ' - canonical https-сторінки вказує на незахищену http-версію'; $explicit = true; }
                if (($r['kind'] ?? '') === 'home') { $t .= ' - canonical внутрішньої сторінки вказує на головну, тобто сторінка просить не індексувати її окремо'; $explicit = true; }
                $lines[] = $t;
            }
            $r0 = $mm[0];
            $goodHref = self::stripTracking($r0['url']);
            if (self::normUrl($goodHref) === self::normUrl((string)$r0['abs']) && isset($r0['target_location'])) $goodHref = (string)$r0['target_location'];
            $insecure = false;
            foreach ($mm as $r) if (($r['kind'] ?? '') === 'insecure') $insecure = true;
            $allTarget200 = true;
            foreach ($mm as $r) if ((int)($r['target_status'] ?? 0) !== 200) $allTarget200 = false;
            $tail = '';
            if (!$explicit) {
                $tail = $allTarget200
                    ? "\nЦільова адреса відповідає 200, тож це може бути навмисна склейка дублів - тоді все гаразд. Якщо ж сторінка основна, canonical має вказувати на неї саму. Автоматично визначити намір неможливо."
                    : "\nЦільову адресу canonical перевірити не вдалося (немає відповіді). Якщо це навмисна склейка дублів - усе гаразд; якщо сторінка основна, canonical має вказувати на неї саму.";
            }
            $findings[] = $this->finding('canonical_mismatch', $explicit ? 'medium' : 'low', $insecure
                ? 'Canonical вказує на незахищену http-версію сторінки'
                : ($explicit
                ? 'Canonical вказує на іншу адресу того ж сайту'
                : 'Canonical вказує на іншу адресу того ж сайту (ймовірно, склейка дублів)'), [
                'evidence' => implode("\n", $lines) . $tail,
                'why'      => 'Пошуковики й AI-краулери індексують адресу з canonical, а не відкриту сторінку. Помилковий canonical (на редирект, 404 чи URL з UTM) розмиває або втрачає сторінку в індексі, і цитується не та адреса.',
                'fix'      => 'Вкажіть у canonical точну адресу сторінки, яка відповідає 200, без UTM-міток і редиректів. Внутрішні посилання ведіть на ту саму адресу.',
                'bad'      => 'Сторінка: ' . $r0['url'] . "\n" . '<link rel="canonical" href="' . $r0['raw'] . '">',
                'good'     => 'Сторінка: ' . $r0['url'] . "\n" . '<link rel="canonical" href="' . $goodHref . '">',
                'code'     => '<link rel="canonical" href="' . $goodHref . '">',
                'verify'   => 'Google Search Console -> Перевірка URL: «Канонічна URL-адреса, указана користувачем» і «вибрана Google» мають збігатися з адресою сторінки.',
                'effort'   => 'S',
            ]);
        }
        if (!empty($by['multiple'])) {
            $lines = [];
            foreach ($by['multiple'] as $r) $lines[] = $r['label'] . ' ' . $r['url'] . ': ' . implode(' | ', $r['all']);
            $r0 = $by['multiple'][0];
            $findings[] = $this->finding('canonical_multiple', 'medium', 'На сторінці кілька різних canonical', [
                'evidence' => implode("\n", $lines),
                'why'      => 'Коли canonical суперечать один одному, Google ігнорує їх усі і сам обирає основну адресу - можливо, не ту.',
                'fix'      => 'Залиште один <link rel="canonical"> у <head> (часто дубль додає SEO-плагін і тема одночасно).',
                'bad'      => implode("\n", array_map(function ($u) { return '<link rel="canonical" href="' . $u . '">'; }, $r0['all'])),
                'good'     => '<link rel="canonical" href="' . $r0['url'] . '">',
                'code'     => '<link rel="canonical" href="' . $r0['url'] . '">',
                'verify'   => 'Ctrl+U на сторінці і пошук «canonical» - має бути один збіг.',
                'effort'   => 'S',
            ]);
        }
        if (!empty($by['missing'])) {
            $lines = [];
            foreach ($by['missing'] as $r) $lines[] = $r['label'] . ' ' . $r['url'];
            $r0 = $by['missing'][0];
            $homeMissing = $r0['i'] === 0;
            $findings[] = $this->finding('canonical_missing', 'medium', count($rows) === 1 ? 'Немає canonical на головній' : 'Немає canonical на ' . count($by['missing']) . ' з ' . count($rows) . ' перевірених сторінок', [
                'evidence' => 'Без <link rel="canonical"> (і без заголовка Link: rel=canonical): ' . implode('; ', $lines) . '.',
                'why'      => 'Одна сторінка часто доступна за кількома адресами (з параметрами, слешем, UTM). Без canonical пошуковики й AI-краулери самі обирають основну версію, а сигнали розпорошуються між дублями.',
                'fix'      => 'Додайте в <head> кожної індексованої сторінки canonical з її власною абсолютною адресою (у більшості CMS - налаштування SEO-модуля).',
                'bad'      => ($homeMissing ? 'Головна' : $r0['label']) . ' ' . $r0['url'] . ":\n<head>\n  <title>" . self::mbTrunc($pages[$r0['i']]['page']->title(), 80) . "</title>\n  <!-- canonical відсутній -->\n</head>",
                'good'     => '<link rel="canonical" href="' . self::stripTracking($r0['url']) . '">',
                'code'     => '<link rel="canonical" href="' . self::stripTracking($r0['url']) . '">',
                'verify'   => 'Ctrl+U на ' . $r0['url'] . ' - пошук «canonical»; або Google Search Console -> Перевірка URL.',
                'effort'   => 'S',
            ]);
        }
        if (!empty($by['unstable'])) {
            $lines = [];
            foreach ($by['unstable'] as $r) $lines[] = $r['label'] . ' ' . $r['url'] . "\n  перший запит: canonical " . $r['abs'] . "\n  повторний запит: canonical " . $r['again'];
            $r0 = $by['unstable'][0];
            $utm = self::hasTracking((string)$r0['abs']);
            $findings[] = $this->finding('canonical_unstable', 'low', 'Canonical змінюється між запитами' . ($utm ? ' (потрапляють UTM-мітки)' : ''), [
                'evidence' => implode("\n", $lines) . "\nЙмовірно, кеш сервера або CDN зберігає HTML, згенерований для адреси з параметрами, і віддає його іншим відвідувачам. Автоматично підтвердити причину не вдалося.",
                'why'      => 'Якщо краулер потрапить на «неправильну» версію, він запам\'ятає canonical з параметрами або на іншу адресу - і в індексі й AI-відповідях з\'явиться не та URL.',
                'fix'      => 'Формуйте canonical із чистого шляху сторінки (без query-параметрів) і не кешуйте HTML з ключем без урахування параметрів, якщо canonical залежить від них.',
                'bad'      => '<link rel="canonical" href="' . $r0['abs'] . '">',
                'good'     => '<link rel="canonical" href="' . self::stripTracking($r0['url']) . '">',
                'code'     => '<link rel="canonical" href="' . self::stripTracking($r0['url']) . '">',
                'verify'   => 'Відкрийте ' . self::stripTracking($r0['url']) . '?utm_source=test, потім чисту адресу в іншому браузері: canonical в обох випадках має бути ' . self::stripTracking($r0['url']) . '.',
                'effort'   => 'S',
            ]);
        }
        $rel = array_filter($rows, function ($r) { return $r['state'] === 'self' && $r['relative']; });
        if ($rel) {
            $r0 = reset($rel);
            $findings[] = $this->finding('canonical_relative', 'low', 'Canonical вказано відносною адресою', [
                'evidence' => implode('; ', array_map(function ($r) { return $r['url'] . ' -> href="' . $r['raw'] . '"'; }, $rel)) . '.',
                'why'      => 'Google розуміє відносний canonical, але рекомендує абсолютний: відносний легко зламати при копіюванні сайту, на тестових доменах чи при http/https-дублях.',
                'fix'      => 'Виводьте canonical повною адресою з протоколом і доменом.',
                'bad'      => '<link rel="canonical" href="' . $r0['raw'] . '">',
                'good'     => '<link rel="canonical" href="' . $r0['url'] . '">',
                'code'     => '<link rel="canonical" href="' . $r0['url'] . '">',
                'verify'   => 'Ctrl+U - href у canonical починається з https://.',
                'effort'   => 'S',
            ]);
        }
    }

    // ================================================================== lang

    private function checkLang(array $pages, array $site, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        $missing = []; $bad = []; $okLangs = []; $scores = [];
        foreach ($pages as $i => $pg) {
            /** @var Page $p */
            $p = $pg['page'];
            // саме атрибут <html lang> (Page::lang() підставляє ще й meta content-language)
            $attr = null;
            $htmlEl = $p->query('/html');
            if ($htmlEl && $htmlEl[0] instanceof DOMElement) {
                $attr = trim($htmlEl[0]->getAttribute('lang'));
                if ($attr === '') $attr = trim($htmlEl[0]->getAttribute('xml:lang'));
                if ($attr === '') $attr = null;
            }
            $text = $p->mainText();
            if ($p->wordCount($text) < 80) $text = $p->visibleText();
            $det = self::detectLang($text);
            $prim = $attr !== null ? strtolower((string)preg_split('/[-_]/', trim($attr))[0]) : null;
            $row = ['i' => $i, 'label' => $pg['label'], 'url' => $pg['url'], 'attr' => $attr, 'detected' => $det, 'meta_cl' => $attr === null ? $p->lang() : null];
            $data['lang'][] = ['url' => $pg['url'], 'attr' => $attr, 'detected' => $det];
            if ($attr === null || $attr === '') { $missing[] = $row; $s = 30; }
            elseif ($prim === 'ua' || !self::validLangCode($attr)) { $row['why'] = 'invalid'; $bad[] = $row; $s = 40; }
            elseif ($det !== null && in_array($prim, ['uk', 'ru', 'en'], true) && $prim !== $det) { $row['why'] = 'mismatch'; $bad[] = $row; $s = 40; }
            else { $s = 100; $okLangs[] = $attr . ($det !== null ? '|' . $det : ''); }
            $scores[] = $s;
            if ($i === 0) $scores[] = $s;
        }
        $sub['lang'] = (int)round(array_sum($scores) / count($scores));

        if (!$missing && !$bad) {
            $vals = array_values(array_unique(array_map(function ($x) { return explode('|', $x)[0]; }, $okLangs)));
            $dets = array_values(array_unique(array_filter(array_map(function ($x) { return explode('|', $x)[1] ?? ''; }, $okLangs))));
            $passed[] = '<html lang="' . implode('" / "', $vals) . '">' . (count($pages) === 1 ? ' на головній' : ' на всіх ' . count($pages) . ' перевірених сторінках')
                . ($dets ? ' відповідає мові тексту (' . implode(', ', array_map(function ($d) { return self::LANG_NAMES[$d] ?? $d; }, $dets)) . ')' : '');
        }
        if ($missing) {
            $r0 = $missing[0];
            $rp = $pages[$r0['i']]['page'];
            $suggest = self::suggestLang($rp, $r0['detected'], $site, $r0['url'], null);
            $html = $rp->query('/html');
            $htmlTag = $html ? self::openTag($html[0]) : '<html>';
            // тегу <html> може не бути взагалі (документ починається одразу з <head>) - парсер додає його сам
            if (!preg_match('/<html[\s>]/i', substr($rp->html(), 0, 20000))) {
                $htmlTag = "<!DOCTYPE html>\n<head>...\n(тегу <html> у документі немає взагалі - браузер і боти додають його самі, без мови)";
            }
            $findings[] = $this->finding('lang_missing', 'medium', 'Не вказано мову сторінки (атрибут lang у <html>)', [
                'evidence' => 'Без атрибута lang: ' . implode('; ', array_map(function ($r) { return $r['label'] . ' ' . $r['url'] . ($r['detected'] ? ' (текст - ' . (self::LANG_NAMES[$r['detected']] ?? $r['detected']) . ')' : '') . ($r['meta_cl'] ? ', є лише meta Content-Language «' . $r['meta_cl'] . '»' : ''); }, $missing)) . '.',
                'why'      => 'lang підказує пошуковикам, AI-парсерам, скрінрідерам і перекладачам мову документа. Без нього мову визначають евристично, і для україномовної сторінки з російськими чи англійськими вкрапленнями можуть помилитися.',
                'fix'      => 'Додайте lang з кодом мови сторінки у тег <html> кожного шаблону (uk - українська, en - англійська).',
                'bad'      => $htmlTag,
                'good'     => '<html lang="' . $suggest . '">',
                'code'     => '<html lang="' . $suggest . '">',
                'verify'   => 'Ctrl+U - перший рядок після <!DOCTYPE html> містить <html lang="' . ($suggest === self::LANG_PLACEHOLDER ? 'код мови' : $suggest) . '">. Lighthouse -> Accessibility: «<html> element has a [lang] attribute».',
                'effort'   => 'S',
            ]);
        }
        if ($bad) {
            $lines = [];
            foreach ($bad as $r) {
                if ($r['why'] === 'invalid') {
                    $lines[] = $r['label'] . ' ' . $r['url'] . ': lang="' . $r['attr'] . '" - некоректний код мови' . (strtolower(substr((string)$r['attr'], 0, 2)) === 'ua' ? ' (ua - код країни; українська мова - uk)' : '') . ($r['detected'] ? ', текст - ' . (self::LANG_NAMES[$r['detected']] ?? $r['detected']) : '');
                } else {
                    $lines[] = $r['label'] . ' ' . $r['url'] . ': lang="' . $r['attr'] . '", а текст сторінки - ' . (self::LANG_NAMES[$r['detected']] ?? $r['detected']);
                }
            }
            $r0 = $bad[0];
            $right = self::suggestLang($pages[$r0['i']]['page'], $r0['detected'], $site, $r0['url'], (string)$r0['attr']);
            $findings[] = $this->finding('lang_mismatch', 'medium', 'Атрибут lang не відповідає мові контенту або має некоректний код', [
                'evidence' => implode("\n", $lines) . "\nМову тексту визначено за літерами (і, ї, є, ґ проти ы, э, ъ, ё) та частотними словами.",
                'why'      => 'Неправильний lang заплутує пошуковики й AI-системи щодо мови сторінки: україномовна сторінка з lang="ru" чи lang="ua" ризикує не потрапити у відповіді на запити українською.',
                'fix'      => 'Виставте в <html lang> код фактичної мови сторінки за ISO 639-1 (uk, en). Для кожної мовної версії - свій код.',
                'bad'      => '<html lang="' . $r0['attr'] . '">',
                'good'     => '<html lang="' . $right . '">',
                'code'     => '<html lang="' . $right . '">',
                'verify'   => 'Ctrl+U на ' . $r0['url'] . ' - <html lang="' . ($right === self::LANG_PLACEHOLDER ? 'код фактичної мови' : $right) . '">.',
                'effort'   => 'S',
            ]);
        }
    }

    const LANG_PLACEHOLDER = '[ВКАЖІТЬ КОД МОВИ]';

    /**
     * Код мови для прикладу виправлення: мова тексту -> ua->uk -> hreflang сторінки на саму себе -> og:locale ->
     * meta Content-Language -> мова профілю сайту -> плейсхолдер (а не «uk» навмання).
     */
    private static function suggestLang(Page $p, ?string $detected, array $site, string $url, ?string $attr): string
    {
        if ($detected !== null && $detected !== '') return $detected;
        if ($attr !== null && preg_match('/^ua([-_]|$)/i', trim($attr))) return 'uk';
        $prim = function (string $code): string {
            $l = strtolower((string)preg_split('/[-_]/', trim($code))[0]);
            return $l === 'ua' ? 'uk' : $l;
        };
        foreach ($p->hreflangs() as $h) {
            if (strtolower($h['lang']) === 'x-default') continue;
            if (self::sameUrlLoose($h['href'], $url) || self::sameUrlLoose($h['href'], $p->url())) {
                $l = $prim($h['lang']);
                if (self::validLangCode($l)) return $l;
            }
        }
        $m = $p->metaTags();
        foreach ([$p->og('locale'), $m['http-equiv:content-language'] ?? null, $site['lang'] ?? null] as $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $l = $prim(explode(',', $v)[0]);
            if (self::validLangCode($l)) return $l;
        }
        return self::LANG_PLACEHOLDER;
    }

    /** uk | ru | en | null (замало тексту або змішана мова). */
    private static function detectLang(string $text): ?string
    {
        $t = mb_substr($text, 0, 30000);
        $uk = (int)preg_match_all('/[іїєґ]/iu', $t);
        $ru = (int)preg_match_all('/[ыэъё]/iu', $t);
        $cyr = (int)preg_match_all('/\p{Cyrillic}/u', $t);
        $lat = (int)preg_match_all('/[a-z]/i', $t);
        if ($cyr + $lat < 300) return null;
        if ($cyr >= $lat) {
            if ($uk >= 5 && $uk >= 3 * $ru) return 'uk';
            if ($ru >= 5 && $ru >= 3 * $uk) return 'ru';
            return null;
        }
        if ($lat >= 2 * $cyr) {
            $words = max(1, (int)preg_match_all('/\b[a-z]+\b/i', $t));
            $en = (int)preg_match_all('/\b(the|and|of|to|in|is|for|with|you|your|our|that|this|on|are|we|from|by)\b/i', $t);
            return $en / $words >= 0.06 ? 'en' : null;
        }
        return null;
    }

    private static function validLangCode(string $code): bool
    {
        $code = trim($code);
        if (strtolower($code) === 'x-default') return true;
        if (!preg_match('/^([a-zA-Z]{2,3})(?:[-_]([a-zA-Z]{4}))?(?:[-_]([a-zA-Z]{2}|\d{3}))?$/', $code, $m)) return false;
        $lang = strtolower($m[1]);
        if (strlen($lang) === 2 && strpos(' ' . self::ISO639_1 . ' ', ' ' . $lang . ' ') === false) return false;
        if (isset($m[3]) && strtoupper($m[3]) === 'UK') return false; // правильно GB
        return true;
    }

    private static function openTag(DOMElement $el): string
    {
        $s = '<' . strtolower($el->nodeName);
        foreach ($el->attributes as $a) $s .= ' ' . $a->nodeName . '="' . htmlspecialchars($a->nodeValue, ENT_QUOTES) . '"';
        return self::mbTrunc($s . '>', 200);
    }

    // ================================================================== hreflang

    private function checkHreflang(Page $home, string $final, array $pages, array $site, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        $langs = [];
        foreach ((array)($site['languages'] ?? []) as $l) if (is_string($l) && $l !== '') $langs[$l] = 'профіль сайту';
        $sampleHl = [];
        foreach ($pages as $i => $pg) {
            if ($i === 0) continue;
            foreach ($pg['page']->hreflangs() as $h) {
                $l = strtolower((string)preg_split('/[-_]/', $h['lang'])[0]);
                if ($l === 'ua') $l = 'uk';
                if ($l !== '' && $l !== 'x') { $sampleHl[$l] = $pg['url']; if (!isset($langs[$l])) $langs[$l] = 'hreflang внутрішніх сторінок'; }
            }
        }
        $data['languages'] = array_keys($langs);
        if (count($langs) < 2) {
            $sub['hreflang'] = null;
            return;
        }
        $entries = [];
        foreach ($home->query('//link[@hreflang][@href]') as $l) {
            $rels = preg_split('/\s+/', strtolower(trim($l->getAttribute('rel'))));
            if (!in_array('alternate', (array)$rels, true)) continue;
            $raw = trim($l->getAttribute('href'));
            $abs = Crawler::absolutize($raw, $final, false);
            if ($abs === null) continue;
            $entries[] = ['lang' => trim($l->getAttribute('hreflang')), 'raw' => $raw, 'href' => $abs];
        }
        $data['hreflang_home'] = array_map(function ($e) { return $e['lang'] . ' ' . $e['href']; }, $entries);

        if (!$entries) {
            $sub['hreflang'] = 20;
            $alts = self::languageSwitcher($home, $final);
            $primary = (string)($site['lang'] ?? array_key_first($langs));
            $tags = [];
            $tags[] = '<link rel="alternate" hreflang="' . $primary . '" href="' . $final . '">';
            foreach (array_keys($langs) as $l) {
                if ($l === $primary) continue;
                $tags[] = '<link rel="alternate" hreflang="' . $l . '" href="' . ($alts[$l] ?? '[ВКАЖІТЬ URL ' . strtoupper($l) . '-ВЕРСІЇ ГОЛОВНОЇ]') . '">';
            }
            $tags[] = '<link rel="alternate" hreflang="x-default" href="' . $final . '">';
            $src = [];
            foreach ($langs as $l => $why) $src[] = $l . ' (' . $why . ')';
            $findings[] = $this->finding('hreflang_missing', 'medium', 'Сайт багатомовний, але на головній немає hreflang', [
                'evidence' => 'Мовні версії: ' . implode(', ', $src) . ".\nНа головній {$final} немає жодного <link rel=\"alternate\" hreflang>." . ($sampleHl ? "\nНа внутрішніх сторінках hreflang є (наприклад, " . reset($sampleHl) . ').' : ''),
                'why'      => 'hreflang пов\'язує мовні версії між собою. Без нього пошуковики можуть показувати користувачам не ту мову, а AI-системи - цитувати російську чи англійську версію замість української.',
                'fix'      => 'Додайте на головну (і на всі сторінки, що мають переклади) повний набір hreflang: кожна версія посилається на всі інші й на себе, плюс x-default.',
                'bad'      => "<head>\n  " . ($home->canonical() !== null
                    ? '<link rel="canonical" href="' . $home->canonical() . '">'
                    : '<title>' . self::mbTrunc($home->title(), 80) . '</title>') . "\n  <!-- жодного <link rel=\"alternate\" hreflang> -->\n</head>",
                'good'     => implode("\n", $tags),
                'code'     => implode("\n", $tags),
                'verify'   => 'Ctrl+U на головній кожної мовної версії - однаковий набір hreflang. Перевірка: https://technicalseo.com/tools/hreflang/ або звіт «Міжнародне націлювання» у сторонніх краулерах (Screaming Frog, Netpeak Spider).',
                'effort'   => 'S',
            ]);
            return;
        }

        $score = 100;
        $problems = [];
        $fixedTags = [];
        // коди
        $seen = [];
        foreach ($entries as $e) {
            $code = $e['lang'];
            $fixed = $code;
            if (strtolower($code) !== 'x-default' && !self::validLangCode($code)) {
                $fixed = (string)preg_replace(['/^ua(?=$|[-_])/i', '/[-_]uk$/i'], ['uk', '-GB'], $code);
                $problems['invalid'][] = 'hreflang="' . $code . '" - некоректний код' . (preg_match('/^ua/i', $code) ? ' (ua - код країни, українська мова - uk або uk-UA)' : (preg_match('/-uk$/i', $code) ? ' (Велика Британія - GB)' : ''));
            }
            $k = strtolower($fixed);
            if (isset($seen[$k]) && $seen[$k] !== self::normUrl($e['href'])) $problems['dup'][] = 'hreflang="' . $code . '" вказано двічі з різними адресами';
            $seen[$k] = self::normUrl($e['href']);
            if (!preg_match('#^(https?:)?//#i', $e['raw'])) $problems['relative'][] = 'hreflang="' . $code . '" href="' . $e['raw'] . '"';
            $fixedTags[] = ['lang' => $fixed, 'href' => $e['href']];
        }
        if (!empty($problems['invalid'])) $score -= 30;
        if (!empty($problems['relative'])) $score -= 25;
        if (!empty($problems['dup'])) $score -= 15;
        // self
        $hasSelf = false;
        foreach ($entries as $e) if (self::sameUrlLoose($e['href'], $final)) { $hasSelf = true; break; }
        if (!$hasSelf) {
            $score -= 25;
            $problems['self'][] = 'серед hreflang немає посилання на саму головну ' . $final;
            $hEl = $home->query('/html');
            $hAttr = ($hEl && $hEl[0] instanceof DOMElement) ? trim($hEl[0]->getAttribute('lang')) : '';
            $selfLang = ($hAttr !== '' && self::validLangCode($hAttr) && !preg_match('/^ua/i', $hAttr))
                ? $hAttr : self::suggestLang($home, self::detectLang($home->mainText()), $site, $final, $hAttr !== '' ? $hAttr : null);
            $fixedTags[] = ['lang' => $selfLang, 'href' => $final];
        }
        $hasXd = false;
        foreach ($entries as $e) if (strtolower($e['lang']) === 'x-default') $hasXd = true;
        // зворотні посилання і статуси альтернатив
        $cands = [];
        foreach ($entries as $e) {
            if (strtolower($e['lang']) === 'x-default' || self::sameUrlLoose($e['href'], $final)) continue;
            $n = self::normUrl($e['href']);
            if (isset($cands[$n])) continue;
            $cands[$n] = $e;
            if (count($cands) >= 3) break;
        }
        $altChecked = [];
        if ($cands) {
            $reqs = [];
            $keys = [];
            foreach (array_values($cands) as $j => $e) { $reqs[$j] = ['url' => $e['href'], 'follow' => false, 'browser' => false, 'no_lang' => true, 'max_bytes' => 1048576]; $keys[$j] = $e; }
            $res = self::fetchBatch($reqs);
            // для редиректів - контрольні запити від імені Googlebot і OAI-SearchBot (редирект може залежати від User-Agent)
            $uaReqs = [];
            foreach ($res as $j => $r) {
                $st = (int)$r['status'];
                if ($st >= 300 && $st < 400) {
                    $uaReqs['g' . $j] = ['url' => $keys[$j]['href'], 'follow' => false, 'ua' => self::UA_GOOGLEBOT, 'no_lang' => true, 'max_bytes' => 65536];
                    $uaReqs['o' . $j] = ['url' => $keys[$j]['href'], 'follow' => false, 'ua' => self::UA_OAI, 'no_lang' => true, 'max_bytes' => 1048576];
                }
            }
            $uaRes = $uaReqs ? self::fetchBatch($uaReqs) : [];
            $uaOnlyGoogle = false;
            $checkReturn = function (array $resp, array $e, array &$row) use ($final, &$problems): void {
                if ($resp['body'] === '' || Page::challengeFromHtml($resp['body']) !== null) return;
                $ap = Page::fromHtml($resp['body'], $e['href'], $resp['headers']);
                $back = false;
                foreach ($ap->hreflangs() as $h) if (self::sameUrlLoose($h['href'], $final)) { $back = true; break; }
                $row['return'] = $back;
                if (!$back) $problems['return'][] = $e['href'] . ' (hreflang="' . $e['lang'] . '") не містить hreflang-посилання назад на ' . $final . ($ap->hreflangs() ? '' : ' - hreflang там немає взагалі');
            };
            $desc = function (?array $x, string $base): string {
                if (!$x || (int)$x['status'] === 0) return 'немає відповіді';
                $s = (int)$x['status'];
                $l = $x['hops'][0]['location'] ?? '';
                return $s . ($s >= 300 && $s < 400 && $l !== '' ? ' -> ' . (Crawler::absolutize($l, $base, false) ?? $l) : '');
            };
            foreach ($res as $j => $r) {
                $e = $keys[$j];
                $st = (int)$r['status'];
                $row = ['lang' => $e['lang'], 'url' => $e['href'], 'status' => $st];
                if ($st === 0) $row['error'] = $r['error'];
                if ($st >= 300 && $st < 400) {
                    $g = $uaRes['g' . $j] ?? null;
                    $o = $uaRes['o' . $j] ?? null;
                    $gs = $g ? (int)$g['status'] : 0;
                    $os = $o ? (int)$o['status'] : 0;
                    $row['googlebot'] = $gs;
                    $row['oai_searchbot'] = $os;
                    if ($gs === 200 && $os === 200) {
                        // редирект лише для нашого (невідомого) UA - для пошукових і AI-ботів версія доступна
                        $row['note'] = 'редирект лише для невідомого User-Agent; Googlebot і OAI-SearchBot отримують 200';
                        $checkReturn($o, $e, $row);
                    } elseif ($gs === 0 && $os === 0) {
                        $row['note'] = 'контрольні запити не вдалися - висновок неможливий';
                    } else {
                        if ($gs === 200 && $os !== 200) $uaOnlyGoogle = true;
                        $problems['redirect'][] = 'hreflang="' . $e['lang'] . '" -> ' . $e['href'] . ': OAI-SearchBot - ' . $desc($o, $e['href']) . '; Googlebot - ' . $desc($g, $e['href']);
                    }
                } elseif ($st >= 400 && !in_array($st, [401, 403, 429], true)) {
                    $problems['redirect'][] = 'hreflang="' . $e['lang'] . '" -> ' . $e['href'] . ' відповідає ' . $st;
                } elseif ($st === 200) {
                    $checkReturn($r, $e, $row);
                }
                $altChecked[] = $row;
            }
        }
        $data['hreflang_alternates'] = $altChecked;
        if (!empty($uaOnlyGoogle)) $problems['redirect'][] = 'Мовна версія відповідає 200 лише для Googlebot, а інші боти (зокрема OAI-SearchBot, краулер ChatGPT search) отримують редирект - для AI-систем ця версія фактично недоступна.';
        if (!empty($problems['redirect'])) $score -= 25;
        if (!empty($problems['return'])) $score -= 20;
        if (!$hasXd) $score -= 10;
        $sub['hreflang'] = max(0, $score);

        $mainProblems = $problems;
        if ($mainProblems) {
            $lines = [];
            foreach (['invalid', 'dup', 'relative', 'self', 'redirect', 'return'] as $k) foreach ($problems[$k] ?? [] as $l) $lines[] = $l;
            if (!$hasXd) {
                // x-default додаємо в ту саму знахідку, окремо не дублюємо
                $lines[] = 'немає варіанта x-default (не обов\'язковий, але знімає неоднозначність для інших мов і регіонів)';
                $fixedTags[] = ['lang' => 'x-default', 'href' => $final];
            }
            $good = implode("\n", array_map(function ($t) { return '<link rel="alternate" hreflang="' . $t['lang'] . '" href="' . $t['href'] . '">'; }, $fixedTags));
            if (!empty($problems['redirect'])) $good = "<!-- кожна адреса нижче відповідає 200 без редиректу для будь-якого User-Agent -->\n" . $good;
            $bad = implode("\n", array_map(function ($e) { return '<link rel="alternate" hreflang="' . $e['lang'] . '" href="' . $e['raw'] . '">'; }, array_slice($entries, 0, 8)));
            $fix = [];
            if (!empty($problems['invalid'])) $fix[] = 'виправте коди мов (uk або uk-UA, en, en-GB)';
            if (!empty($problems['relative'])) $fix[] = 'вказуйте абсолютні адреси з https://';
            if (!empty($problems['self'])) $fix[] = 'додайте посилання сторінки на саму себе';
            if (!empty($problems['redirect'])) $fix[] = 'hreflang мають вести на кінцеві адреси, що відповідають 200 для всіх відвідувачів і ботів; не перенаправляйте автоматично за User-Agent, мовою браузера чи IP (замість цього - банер-підказка з вибором мови)';
            if (!empty($problems['return'])) $fix[] = 'додайте зворотні hreflang на всіх мовних версіях (кожна версія посилається на всі інші)';
            if (!$hasXd) $fix[] = 'додайте x-default на основну версію';
            $findings[] = $this->finding('hreflang_errors', 'medium', 'Помилки в hreflang на головній', [
                'evidence' => implode("\n", $lines),
                'why'      => 'Google ігнорує hreflang з помилками (невалідний код мови, відсутні зворотні посилання), а посилання на адреси з редиректом ненадійні. Тоді мовні версії конкурують між собою, і у відповідях на українські запити може з\'являтися російська чи англійська версія.',
                'fix'      => self::ucfirstU(implode('; ', $fix)) . '.',
                'bad'      => $bad,
                'good'     => $good,
                'code'     => $good,
                'verify'   => 'https://technicalseo.com/tools/hreflang/ для головної; Ctrl+U на кожній мовній версії - однаковий набір hreflang, усі адреси відповідають 200.',
                'effort'   => 'S',
            ]);
        }
        if (!$hasXd && !$mainProblems) {
            $tags = array_map(function ($e) { return '<link rel="alternate" hreflang="' . $e['lang'] . '" href="' . $e['href'] . '">'; }, array_slice($entries, 0, 8));
            $findings[] = $this->finding('hreflang_no_xdefault', 'low', 'У hreflang немає x-default', [
                'evidence' => 'На головній ' . count($entries) . ' hreflang: ' . self::listSome(array_map(function ($e) { return $e['lang']; }, $entries), 10) . '. Варіанта x-default немає.',
                'why'      => 'x-default підказує, яку версію показувати користувачам з інших мов і регіонів. Не обов\'язковий, але знімає неоднозначність для пошуковиків.',
                'fix'      => 'Додайте x-default, що вказує на основну (зазвичай українську) версію або сторінку вибору мови.',
                'bad'      => implode("\n", $tags),
                'good'     => implode("\n", $tags) . "\n" . '<link rel="alternate" hreflang="x-default" href="' . $final . '">',
                'code'     => '<link rel="alternate" hreflang="x-default" href="' . $final . '">',
                'verify'   => 'Ctrl+U на головній: є рядок з hreflang="x-default".',
                'effort'   => 'S',
            ]);
        }
        if (!$mainProblems) {
            $confirmed = array_values(array_filter($altChecked, function ($r) { return !empty($r['return']); }));
            $retText = '';
            if (count($confirmed) === 1) $retText = ', зворотне посилання з версії ' . $confirmed[0]['lang'] . ' підтверджено';
            elseif (count($confirmed) > 1) $retText = ', зворотні посилання підтверджено на ' . count($confirmed) . ' з ' . count($altChecked) . ' мовних версій';
            $passed[] = 'hreflang на головній: ' . self::listSome(array_map(function ($e) { return $e['lang']; }, $entries), 8) . ' (з посиланням на саму себе' . $retText . ')';
        }
    }

    /** lang => URL головної з перемикача мов (/en/, /ru/, en.domain). */
    private static function languageSwitcher(Page $home, string $final): array
    {
        $out = [];
        $anchor = ['en' => 'en', 'eng' => 'en', 'english' => 'en', 'ua' => 'uk', 'uk' => 'uk', 'укр' => 'uk', 'українська' => 'uk', 'ru' => 'ru', 'рус' => 'ru', 'ру' => 'ru', 'pl' => 'pl', 'de' => 'de'];
        foreach ($home->links() as $l) {
            $path = (string)parse_url($l['href'], PHP_URL_PATH);
            $t = mb_strtolower(trim($l['text']));
            if ($l['internal'] && preg_match('#^/(en|uk|ua|ru|pl|de)/?$#i', $path, $m)) {
                $code = strtolower($m[1]) === 'ua' ? 'uk' : strtolower($m[1]);
                if (!isset($out[$code])) $out[$code] = $l['href'];
            } elseif (isset($anchor[$t]) && !isset($out[$anchor[$t]])) {
                $out[$anchor[$t]] = $l['href'];
            }
        }
        return $out;
    }

    // ================================================================== статуси вибірки

    private function checkStatuses(array $samples, array $hp, string $inputUrl, string $final, bool $asBrowser, array &$sub, array &$findings, array &$passed, array &$data): void
    {
        $broken = []; $chains = []; $singles = []; $blocked = []; $timeouts = []; $ok = 0; $total = 0;
        foreach ($samples as $s) {
            $res = is_array($s['res'] ?? null) ? $s['res'] : [];
            $st = (int)($res['status'] ?? 0);
            $rc = (int)($res['redirect_count'] ?? 0);
            $u = (string)($s['url'] ?? '');
            $fu = (string)($res['final_url'] ?? $u);
            $via = (string)($s['via'] ?? 'link');
            $src = $via === 'sitemap' ? 'з sitemap.xml' : ($via === 'blog-index' ? 'зі сторінки блогу' : 'посилання з головної');
            $row = ['label' => self::roleLabel((string)($s['role'] ?? 'other')), 'url' => $u, 'final' => $fu, 'status' => $st, 'redirects' => $rc, 'src' => $src];
            $total++;
            if (($s['challenge'] ?? null) !== null || in_array($st, [401, 403, 429], true)) { $blocked[] = $row; continue; }
            if ($st === 0) { $row['error'] = (string)($res['error'] ?? ''); $timeouts[] = $row; continue; }
            if ($st >= 400) { $broken[] = $row; continue; }
            if ($rc >= 2) { $chains[] = $row; continue; }
            if ($rc === 1 && self::normUrl($fu) !== self::normUrl($u)) { $singles[] = $row; continue; }
            $ok++;
        }
        $data['samples_status'] = ['total' => $total, 'ok' => $ok, 'broken' => count($broken), 'chains' => count($chains), 'single_redirects' => count($singles), 'blocked' => count($blocked), 'timeouts' => count($timeouts)];

        // ланцюжок редиректів головної
        $homeHops = (int)($hp['redirect_count'] ?? 0);
        $homeChain = null;
        if ($homeHops >= 2) {
            $start = (string)preg_replace('#^http://#i', 'https://', $inputUrl);
            $r = self::fetchBatch(['h' => ['url' => $start, 'follow' => true, 'browser' => $asBrowser, 'max_bytes' => 65536]]);
            $hr = $r['h'] ?? null;
            if ($hr && count($hr['hops']) >= 3) $homeChain = $hr;
        }

        $evaluated = $total - count($blocked);
        if ($evaluated <= 0 && $homeChain === null) {
            $sub['sample_status'] = null;
            if ($blocked) $data['samples_status']['note'] = 'усі внутрішні сторінки вибірки заблоковано захистом сайту - коди відповіді не оцінено';
            return;
        }
        $score = 100 - 25 * count($broken) - 15 * count($chains) - 10 * count($timeouts) - 5 * count($singles) - ($homeChain ? 10 : 0);
        $sub['sample_status'] = max(0, $score);

        if ($evaluated > 0 && $ok === $evaluated) {
            $passed[] = $evaluated === 1 ? 'Внутрішня сторінка вибірки відповідає 200 без редиректів'
                : ($evaluated === 2 ? 'Обидві внутрішні сторінки вибірки відповідають 200 без редиректів'
                : 'Усі ' . $evaluated . ' ' . self::plural($evaluated, 'внутрішня сторінка', 'внутрішні сторінки', 'внутрішніх сторінок') . ' вибірки відповідають 200 без редиректів');
        }
        if ($broken || $chains || $timeouts || $singles) {
            $lines = [];
            foreach ($broken as $r) $lines[] = $r['label'] . ' ' . $r['url'] . ' -> HTTP ' . $r['status'] . ($r['redirects'] ? ' (після ' . $r['redirects'] . ' ' . self::plural($r['redirects'], 'редиректу', 'редиректів', 'редиректів') . ')' : '') . ' [' . $r['src'] . ']';
            foreach ($chains as $r) $lines[] = $r['label'] . ' ' . $r['url'] . ' -> ' . $r['redirects'] . ' редиректи -> ' . $r['final'] . ' (' . $r['status'] . ') [' . $r['src'] . ']';
            foreach ($timeouts as $r) $lines[] = $r['label'] . ' ' . $r['url'] . ' - не відповіла' . ($r['error'] !== '' ? ' (' . self::mbTrunc($r['error'], 80) . ')' : '') . ' [' . $r['src'] . ']';
            foreach ($singles as $r) $lines[] = $r['label'] . ' ' . $r['url'] . ' -> редирект -> ' . $r['final'] . ' [' . $r['src'] . ']';
            $sev = ($broken || $chains || $timeouts) ? 'medium' : 'low';
            $r0 = $broken[0] ?? ($chains[0] ?? ($timeouts[0] ?? $singles[0]));
            $isRedirect = !$broken && !$timeouts;
            $findings[] = $this->finding('sample_status', $sev, $isRedirect
                ? 'Внутрішні посилання ведуть на адреси з редиректом'
                : 'Частина внутрішніх сторінок відповідає помилкою або недоступна', [
                'evidence' => implode("\n", $lines),
                'why'      => $isRedirect
                    ? 'Кожен редирект - зайвий запит для краулера і втрата часу; ланцюжки з кількох редиректів боти можуть не дочитати до кінця.'
                    : 'Биті внутрішні посилання ведуть краулерів у глухий кут: сторінка випадає з індексу, а AI не може її процитувати. Для користувача це теж втрачений перехід.',
                'fix'      => $isRedirect
                    ? 'Замініть у меню, шаблонах і sitemap.xml посилання на кінцеві адреси (ті, що відповідають 200).'
                    : 'Відновіть сторінки або налаштуйте 301-редирект на актуальні адреси й оновіть посилання в меню/шаблонах.',
                'bad'      => $r0['url'] . ' -> ' . ($r0['status'] ? 'HTTP ' . $r0['status'] : 'немає відповіді') . ($r0['redirects'] ? ' (' . $r0['redirects'] . ' редир.)' : ''),
                'good'     => ($isRedirect ? $r0['final'] : $r0['url']) . ' -> HTTP 200 без редиректів',
                'code'     => $isRedirect ? '<a href="' . $r0['final'] . '">...</a>' : "# nginx: постійний редирект зі старої адреси\nlocation = " . ((string)parse_url($r0['url'], PHP_URL_PATH) ?: '/') . " {\n    return 301 [ВКАЖІТЬ АКТУАЛЬНУ АДРЕСУ];\n}",
                'verify'   => 'curl -sIL ' . $r0['url'] . ' | grep -i "^HTTP" - має бути один рядок HTTP/2 200. Повний обхід: Netpeak Spider або Screaming Frog -> звіт за кодами відповіді.',
                'effort'   => 'S',
            ]);
        }
        if ($homeChain) {
            $chainText = self::chainText($homeChain);
            $findings[] = $this->finding('home_redirect_chain', 'low', 'Головна відкривається через ланцюжок із ' . (count($homeChain['hops']) - 1) . ' редиректів', [
                'evidence' => $chainText,
                'why'      => 'Кожен крок ланцюжка - додаткова затримка для людей і ботів; частина краулерів обмежує кількість редиректів.',
                'fix'      => 'Налаштуйте один редирект одразу на кінцеву адресу ' . $final . '.',
                'bad'      => $chainText,
                'good'     => $homeChain['hops'][0]['url'] . ' -> 301 -> ' . $final . ' -> 200',
                'code'     => '',
                'verify'   => 'curl -sIL ' . $homeChain['hops'][0]['url'] . ' | grep -iE "^(HTTP|location)" - не більше одного редиректу.',
                'effort'   => 'S',
            ]);
        }
        if ($blocked) {
            $data['samples_status']['blocked_urls'] = array_map(function ($r) { return $r['url'] . ' (' . $r['status'] . ')'; }, array_slice($blocked, 0, 4));
        }
    }

    // ================================================================== ціна в HTML (e-commerce)

    private function checkPriceInHtml(array $pages, array $site, array &$findings, array &$data): void
    {
        if (($site['type'] ?? '') !== 'ecommerce') return;
        foreach ($pages as $pg) {
            if ($pg['role'] !== 'product') continue;
            /** @var Page $p */
            $p = $pg['page'];
            $vt = $p->visibleText();
            $hasPrice = (bool)preg_match('/\d[\d\s\x{00A0}.,]*\s?(грн|₴|uah)|(₴|грн\.?)\s?\d|\$\s?\d|\d\s?\$|€\s?\d|\d\s?€/iu', $vt);
            $data['product_price_in_html'] = $hasPrice;
            if ($hasPrice) return;
            $schemaPrice = null;
            foreach ($p->schemaItemsOfType('Offer') as $it) {
                $v = $it['data']['price'] ?? ($it['data']['lowPrice'] ?? null);
                if (is_scalar($v) && $v !== '') { $schemaPrice = (string)$v . ' ' . (string)($it['data']['priceCurrency'] ?? ''); break; }
            }
            $emptyEls = $p->query('//body//*[contains(translate(@class,"PRICE","price"),"price") or contains(translate(@id,"PRICE","price"),"price")][not(normalize-space())][not(self::meta)][not(self::input)][not(self::link)]');
            if ($schemaPrice === null && !$emptyEls) return; // без доказів - не звітуємо
            $snip = $emptyEls ? self::openTag($emptyEls[0]) . '</' . strtolower($emptyEls[0]->nodeName) . '>' : '';
            $findings[] = $this->finding('price_not_in_html', 'medium', 'На картці товару в сирому HTML немає видимої ціни', [
                'evidence' => 'Картка ' . $pg['url'] . ': у видимому тексті HTML не знайдено ціни (грн, ₴, UAH, $, €).'
                    . ($schemaPrice !== null ? ' У розмітці schema.org ціна є: ' . trim($schemaPrice) . '.' : '')
                    . ($snip !== '' ? ' Порожній контейнер ціни: ' . $snip . '.' : '')
                    . ' Ймовірно, ціну підставляє JavaScript (або вона не виводиться для цього товару) - перевірте вручну.',
                'why'      => 'Більшість AI-краулерів не виконують JavaScript. Без ціни в HTML AI-асистент не відповість на «скільки коштує» і не порівняє товар з конкурентами.',
                'fix'      => 'Виводьте ціну й наявність у HTML на сервері (у шаблоні картки); JavaScript може лише оновлювати їх.',
                'bad'      => $snip !== '' ? $snip : 'Ціна відсутня у видимому тексті сирого HTML',
                'good'     => "<div class=\"product-price\">\n  <span class=\"price\">" . ($schemaPrice !== null ? trim($schemaPrice) : '[ЦІНА] грн') . "</span>\n  <span class=\"stock\">В наявності</span>\n</div>",
                'code'     => '',
                'verify'   => 'Ctrl+U на ' . $pg['url'] . ' і пошук ціни через Ctrl+F.',
                'effort'   => 'M',
            ]);
            return;
        }
    }

    // ================================================================== мережа

    /**
     * Паралельні GET-запити. $reqs: key => [url, follow(bool), browser(bool), no_lang(bool), max_bytes]
     * Результат: key => [status, final_url, hops[[status, location, url]], headers, body, error]
     */
    private static function fetchBatch(array $reqs): array
    {
        if (!$reqs) return [];
        $mh = curl_multi_init();
        $hs = []; $st = [];
        foreach ($reqs as $k => $r) {
            $st[$k] = ['hops' => [], 'headers' => [], 'body' => '', 'trunc' => false];
            $max = (int)($r['max_bytes'] ?? 262144);
            $browser = !empty($r['browser']);
            $hdr = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
            if (empty($r['no_lang'])) $hdr[] = $browser ? 'Accept-Language: uk-UA,uk;q=0.9,en;q=0.8' : 'Accept-Language: uk,en;q=0.8';
            $ch = curl_init();
            $opts = [
                CURLOPT_URL            => (string)$r['url'],
                CURLOPT_FOLLOWLOCATION => $r['follow'] ?? true,
                CURLOPT_MAXREDIRS      => 8,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => self::NET_TIMEOUT,
                CURLOPT_USERAGENT      => isset($r['ua']) ? (string)$r['ua'] : ($browser ? Crawler::altUa() : Crawler::UA),
                CURLOPT_HTTPHEADER     => $hdr,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_ENCODING       => '',
                CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$st, $k) {
                    $len = strlen($line);
                    $t = trim($line);
                    if ($t === '') return $len;
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $t, $m)) {
                        if ((int)$m[1] === 100) return $len;
                        $st[$k]['hops'][] = ['status' => (int)$m[1], 'location' => null];
                        $st[$k]['headers'] = [];
                        return $len;
                    }
                    $p = explode(':', $line, 2);
                    if (count($p) === 2) {
                        $name = strtolower(trim($p[0]));
                        $st[$k]['headers'][$name][] = trim($p[1]);
                        if ($name === 'location' && $st[$k]['hops']) $st[$k]['hops'][count($st[$k]['hops']) - 1]['location'] = trim($p[1]);
                    }
                    return $len;
                },
                CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$st, $k, $max) {
                    $st[$k]['body'] .= $chunk;
                    if (strlen($st[$k]['body']) >= $max) { $st[$k]['trunc'] = true; return 0; }
                    return strlen($chunk);
                },
            ];
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        $codes = [];
        do {
            $s = curl_multi_exec($mh, $running);
            while ($inf = curl_multi_info_read($mh)) {
                foreach ($hs as $k => $h) if ($h === $inf['handle']) { $codes[$k] = (int)$inf['result']; break; }
            }
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running && $s === CURLM_OK);
        $out = [];
        foreach ($hs as $k => $ch) {
            $info = curl_getinfo($ch);
            $errno = $codes[$k] ?? 0;
            if ($errno === 23 && $st[$k]['trunc']) $errno = 0;
            $hops = $st[$k]['hops'];
            $prev = (string)$reqs[$k]['url'];
            foreach ($hops as $i => $hop) {
                $hops[$i]['url'] = $prev;
                if ($hop['location'] !== null) $prev = Crawler::absolutize($hop['location'], $prev, false) ?? $hop['location'];
            }
            $out[$k] = [
                'status'    => (int)($info['http_code'] ?? 0),
                'final_url' => (string)($info['url'] ?? $reqs[$k]['url']),
                'hops'      => $hops,
                'headers'   => $st[$k]['headers'],
                'body'      => $st[$k]['body'],
                'error'     => $errno ? (curl_error($ch) ?: 'curl error ' . $errno) : '',
            ];
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    // ================================================================== helpers

    /**
     * Сторінка перевірки «чи ви не робот», яку Page::challenge() не розпізнав (кастомні WAF, HTTP 202 з порожнім тілом).
     * Такі сторінки не можна оцінювати як контент сайту: інакше - хибні «CSR», «немає canonical» тощо.
     */
    public static function isBotWall(?Page $p, array $res = []): bool
    {
        if (!$p) return false;
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

    /** Хостинг за заголовками відповіді: vercel | netlify | cloudfront | '' (для вибору прикладу конфігурації). */
    private static function platform(array $headers): string
    {
        $h = function (string $k) use ($headers): string { return strtolower(implode(' ', array_map('strval', (array)($headers[$k] ?? [])))); };
        $server = $h('server');
        if (strpos($server, 'vercel') !== false || isset($headers['x-vercel-id']) || isset($headers['x-vercel-cache'])) return 'vercel';
        if (strpos($server, 'netlify') !== false || isset($headers['x-nf-request-id'])) return 'netlify';
        if (strpos($server, 'amazons3') !== false || strpos($server, 'cloudfront') !== false || isset($headers['x-amz-cf-id'])) return 'cloudfront';
        return '';
    }

    private static function blockedReason(array $access, array $hp): string
    {
        $reason = (string)($access['block_reason'] ?? '');
        $status = (int)($access['homepage_status'] ?? ($hp['status'] ?? 0));
        $names = ['cloudflare' => 'Cloudflare', 'ddos-guard' => 'DDoS-Guard', 'imperva' => 'Imperva', 'sucuri' => 'Sucuri', 'akamai' => 'Akamai', 'datadome' => 'DataDome', 'vercel' => 'Vercel', 'captcha' => 'капчу', 'access_denied' => 'сторінку «доступ заборонено»'];
        if (isset($names[$reason])) {
            return 'Сайт показує ' . ($reason === 'captcha' || $reason === 'access_denied' ? $names[$reason] : 'сторінку перевірки ' . $names[$reason]) . ' (HTTP ' . $status . ') і боту, і браузерному запиту - сирий HTML недоступний, тому рендеринг та індексованість оцінити неможливо. Це не означає, що контент рендериться через JavaScript.';
        }
        if (strpos($reason, 'http_') === 0) {
            return 'Сервер відповідає HTTP ' . $status . ' на запити аудиту (і бота, і браузера) - сирий HTML недоступний, тому рендеринг оцінити неможливо.';
        }
        if ($reason === 'empty_body') return 'Сервер повернув порожню відповідь замість HTML - рендеринг оцінити неможливо.';
        return 'Головна сторінка не віддала HTML для аналізу (HTTP ' . $status . ') - рендеринг оцінити неможливо.';
    }

    private static function roleLabel(string $role): string
    {
        $m = ['home' => 'Головна', 'about' => 'Про компанію', 'contact' => 'Контакти', 'article' => 'Стаття', 'product' => 'Товар',
            'category' => 'Категорія', 'service' => 'Послуга', 'other' => 'Сторінка'];
        return $m[$role] ?? 'Сторінка';
    }

    private static function normUrl(string $u): string
    {
        $p = parse_url(trim($u));
        if (!$p || empty($p['host'])) return $u;
        $scheme = strtolower((string)($p['scheme'] ?? 'https'));
        $host = strtolower((string)$p['host']);
        $port = '';
        if (isset($p['port']) && !(($scheme === 'http' && (int)$p['port'] === 80) || ($scheme === 'https' && (int)$p['port'] === 443))) $port = ':' . $p['port'];
        $path = (string)($p['path'] ?? '/');
        if ($path === '') $path = '/';
        $path = rawurldecode($path);
        $q = (isset($p['query']) && $p['query'] !== '') ? '?' . $p['query'] : '';
        return $scheme . '://' . $host . $port . $path . $q;
    }

    /** Збіг адрес з точністю до кінцевого слеша і регістру хоста. */
    private static function sameUrlLoose(string $a, string $b): bool
    {
        $na = self::normUrl($a);
        $nb = self::normUrl($b);
        if ($na === $nb) return true;
        $sa = (string)preg_replace('#/(\?|$)#', '$1', $na);
        $sb = (string)preg_replace('#/(\?|$)#', '$1', $nb);
        return $sa === $sb;
    }

    /** Чи відрізняються адреси лише query-рядком, кінцевим слешем або регістром шляху (canonical на «чисту» версію). */
    private static function variantOf(string $canon, string $page): bool
    {
        $c = parse_url($canon);
        $p = parse_url($page);
        if (!$c || !$p || empty($c['host']) || empty($p['host'])) return false;
        if (strtolower((string)$c['host']) !== strtolower((string)$p['host'])) return false;
        // http-canonical для https-сторінки чи інший порт - не «чиста версія», а інша адреса
        $cs = strtolower((string)($c['scheme'] ?? 'https'));
        $ps = strtolower((string)($p['scheme'] ?? 'https'));
        if ($cs !== $ps) return false;
        $defPort = $cs === 'http' ? 80 : 443;
        if ((int)($c['port'] ?? $defPort) !== (int)($p['port'] ?? $defPort)) return false;
        $cp = rtrim(mb_strtolower(rawurldecode((string)($c['path'] ?? '/'))), '/');
        $pp = rtrim(mb_strtolower(rawurldecode((string)($p['path'] ?? '/'))), '/');
        if ($cp !== $pp) return false;
        // canonical без параметрів (або з коротшим query), сторінка - з параметрами чи іншим слешем
        return empty($c['query']) || strlen((string)$c['query']) < strlen((string)($p['query'] ?? ''));
    }

    private static function hasTracking(string $u): bool
    {
        return (bool)preg_match('/[?&](utm_[a-z]+|gclid|fbclid|yclid|_openstat|mc_cid|ref)=/i', $u);
    }

    private static function stripTracking(string $u): string
    {
        $p = parse_url($u);
        if (!$p || empty($p['query'])) return $u;
        parse_str($p['query'], $q);
        foreach (array_keys($q) as $k) if (preg_match('/^(utm_[a-z]+|gclid|fbclid|yclid|_openstat|mc_cid|ref)$/i', (string)$k)) unset($q[$k]);
        $base = strtok($u, '?');
        return $q ? $base . '?' . http_build_query($q) : (string)$base;
    }

    private static function chainText(array $r): string
    {
        $parts = [];
        foreach ($r['hops'] as $h) { $parts[] = $h['url']; $parts[] = (string)$h['status']; }
        return implode(' -> ', $parts);
    }

    private static function ucfirstU(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function num(int $n): string
    {
        return number_format($n, 0, ',', ' ');
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

    private function summaryLine(array $findings, array $data): string
    {
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($findings, function ($a, $b) use ($order) { return ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3); });
        $note = '';
        if (!empty($data['samples_status']['blocked']) && (int)$data['samples_status']['blocked'] === (int)$data['samples_status']['total']) {
            $note = ' Внутрішні сторінки заблоковано захистом сайту - перевірено лише головну.';
        }
        $home = $data['ssr'][0] ?? null;
        if (!$findings && $home && ($home['state'] ?? '') === 'short') {
            return 'Текст головної є в сирому HTML, але його небагато (' . self::num((int)$home['words']) . ' ' . self::plural((int)$home['words'], 'слово', 'слова', 'слів') . '); canonical, lang і коди відповіді коректні.' . $note;
        }
        if (!$findings) return ($note !== '' ? 'Контент головної віддається в сирому HTML, canonical і lang коректні.' : 'Контент віддається в сирому HTML, canonical, lang і коди відповіді коректні.') . $note;
        $top = array_slice(array_map(function ($f) { return rtrim($f['text'], '.'); }, $findings), 0, 2);
        $rest = count($findings) - count($top);
        return implode('; ', $top) . ($rest > 0 ? '; ще ' . $rest . ' ' . self::plural($rest, 'зауваження', 'зауваження', 'зауважень') : '') . '.' . $note;
    }
}
