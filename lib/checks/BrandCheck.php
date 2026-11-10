<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';
require_once __DIR__ . '/../Crawler.php';
require_once __DIR__ . '/../Page.php';
require_once __DIR__ . '/../SiteProfile.php';

/**
 * «Сутність бренду»: чи існує бренд як розпізнавана сутність, яку AI-системи можуть однозначно зіставити з сайтом.
 *
 *  1) Wikidata: кандидати з wbsearchentities (uk/en) + зворотний пошук через SPARQL за P856. Елемент ПІДТВЕРДЖЕНИЙ
 *     лише якщо його P856 (офіційний сайт) має той самий хост, що й сайт (без www/схеми/шляху).
 *  2) Вікіпедія: статті ukwiki/enwiki підтвердженого елемента; скільки статей uk/en посилаються на домен (exturlusage).
 *  3) Профілі соцмереж, на які посилається сайт (головна + «Контакти» + «Про нас»), і чи є вони в sameAs Organization.
 *  4) Узгодженість назви: title головної, og:site_name, Organization.name, WebSite.name.
 *  5) Локальний бізнес: посилання на картку Google Maps / Business Profile.
 */
class BrandCheck extends BaseCheck
{
    /** User-Agent з контактом за політикою Wikimedia API. */
    const UA = 'GeoAuditBot/2.0 (+https://github.com/sneex-seo/geo-audit; open-source GEO audit) PHP-curl';
    const API_TIMEOUT = 8;
    /** Максимальний бал, якщо частину складових не вдалося перевірити (сайт не віддав HTML / Wikimedia не відповіла). */
    const PARTIAL_CAP = 70;
    const WD_API = 'https://www.wikidata.org/w/api.php';
    const WDQS = 'https://query.wikidata.org/sparql';

    const PLATFORMS = [
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube',
        'telegram' => 'Telegram', 'tiktok' => 'TikTok', 'x' => 'X (Twitter)', 'github' => 'GitHub',
    ];
    /** Властивості Wikidata для ідентифікаторів профілів (linkedin_in - особистий профіль linkedin.com/in/...). */
    const WD_SOCIAL = [
        'facebook' => 'P2013', 'instagram' => 'P2003', 'linkedin' => 'P4264', 'youtube' => 'P2397',
        'telegram' => 'P3789', 'x' => 'P2002', 'tiktok' => 'P7085', 'github' => 'P2037', 'linkedin_in' => 'P6634',
    ];
    /** Твердження, які читаємо з елемента (наявність). P106, P27, P735, P734 - для особи. */
    const WD_PROPS = ['P31', 'P17', 'P571', 'P159', 'P452', 'P178', 'P275', 'P577', 'P407', 'P106', 'P27', 'P735', 'P734'];
    /**
     * Обов'язкові твердження залежно від класу елемента (P31): компанії не потрібна ліцензія,
     * програмі - штаб-квартира і галузь (P452 має обмеження на тип суб'єкта), сайту/виданню - мова.
     */
    const WD_CLASS_PROPS = [
        'org' => ['P17' => 'P17 (країна)', 'P571' => 'P571 (дата заснування)', 'P159' => 'P159 (штаб-квартира)', 'P452' => 'P452 (галузь)'],
        'software' => ['P178' => 'P178 (розробник)', 'P275' => 'P275 (ліцензія)', 'P577' => 'P577 (дата випуску) або P571'],
        'website' => ['P17' => 'P17 (країна)', 'P571' => 'P571 (дата запуску)', 'P407' => 'P407 (мова)'],
        'other' => ['P17' => 'P17 (країна)', 'P571' => 'P571 (дата заснування)'],
        // особа (P31 = Q5, лише для особистого сайту експерта)
        'person' => ['P106' => 'P106 (рід занять)', 'P27' => 'P27 (громадянство)', 'P735' => 'P735 (ім\'я)', 'P734' => 'P734 (прізвище)'],
    ];
    const WD_CLASS_NOUN = ['org' => 'компанію', 'software' => 'продукт', 'website' => 'сайт чи видання', 'other' => 'бренд', 'person' => 'особу'];
    /** Кореневі класи для визначення класу елемента через P31/P279* (SPARQL). */
    const WD_CLASS_ROOTS = ['Q43229' => 'org', 'Q4830453' => 'org', 'Q7397' => 'software', 'Q35127' => 'website', 'Q1002697' => 'website', 'Q11032' => 'website'];
    /** Запасний варіант, якщо SPARQL не відповів: прямі значення P31. */
    const WD_DIRECT_ORG = ['Q4830453', 'Q783794', 'Q6881511', 'Q43229', 'Q891723', 'Q167037', 'Q22687', 'Q16917', 'Q27686', 'Q11707',
        'Q131734', 'Q3918', 'Q1331793', 'Q507619', 'Q1058914', 'Q1529128', 'Q60767305'];
    const WD_DIRECT_SOFTWARE = ['Q7397', 'Q341', 'Q620615', 'Q166142', 'Q9135', 'Q218616'];
    const WD_DIRECT_WEBSITE = ['Q35127', 'Q1153191', 'Q11032', 'Q41298', 'Q1002697', 'Q5633421'];
    /** Мови сайту -> Q-id для P407 (лише uk/en). */
    const WD_LANGS = ['uk' => 'Q8798', 'en' => 'Q1860'];
    const RX_UA_CONTEXT = '/(україн|ukrain|київ|kyiv|kiev|львів|lviv|одес|odesa|odessa|харків|kharkiv|дніпр|dnipro)/iu';
    /** Міста України -> Q-id (перевірено через wbgetentities). */
    const UA_CITIES = [
        'київ' => 'Q1899', 'киев' => 'Q1899', 'kyiv' => 'Q1899', 'kiev' => 'Q1899',
        'львів' => 'Q36036', 'львов' => 'Q36036', 'lviv' => 'Q36036',
        'одеса' => 'Q1874', 'одесса' => 'Q1874', 'odesa' => 'Q1874', 'odessa' => 'Q1874',
        'харків' => 'Q42308', 'харьков' => 'Q42308', 'kharkiv' => 'Q42308',
        'дніпро' => 'Q48256', 'днепр' => 'Q48256', 'dnipro' => 'Q48256',
        'запоріжжя' => 'Q157835', 'zaporizhzhia' => 'Q157835', 'вінниця' => 'Q157144', 'vinnytsia' => 'Q157144',
        'полтава' => 'Q156747', 'poltava' => 'Q156747', 'чернігів' => 'Q157053', 'черкаси' => 'Q157055',
        'житомир' => 'Q156713', 'суми' => 'Q156752', 'рівне' => 'Q156739', 'луцьк' => 'Q7550',
        'тернопіль' => 'Q156743', 'хмельницький' => 'Q156717', 'івано-франківськ' => 'Q156726',
        'ужгород' => 'Q156711', 'чернівці' => 'Q157725', 'миколаїв' => 'Q41572', 'херсон' => 'Q156732',
        'кропивницький' => 'Q158292', 'біла церква' => 'Q33342', 'бровари' => 'Q841026', 'ірпінь' => 'Q71727',
        'буча' => 'Q1013474', 'вишгород' => 'Q1004257',
    ];
    /** P31, з якими однойменний елемент точно не бренд (сторінка неоднозначності, людина, ім'я, категорія тощо). */
    const P31_BLOCK = ['Q4167410', 'Q5', 'Q101352', 'Q202444', 'Q12308941', 'Q11879590', 'Q4167836', 'Q13406463',
        'Q11266439', 'Q13442814', 'Q16521', 'Q7187', 'Q8054', 'Q4022', 'Q486972', 'Q515', 'Q532', 'Q3957', 'Q482994',
        'Q134556', 'Q7366', 'Q11424', 'Q5398426', 'Q95074', 'Q15632617'];
    /** P31 «організаційного» типу (бізнес, компанія, бренд, сайт, ПЗ, банк, готель тощо). */
    const P31_ORG = ['Q4830453', 'Q783794', 'Q6881511', 'Q43229', 'Q891723', 'Q167037', 'Q431289', 'Q35127', 'Q7397',
        'Q1058914', 'Q507619', 'Q11032', 'Q1153191', 'Q22687', 'Q16917', 'Q27686', 'Q11707', 'Q131734', 'Q3918', 'Q1331793'];
    const RX_ORG_DESC = '/(compan|business|brand|store|shop|retail|agency|bank|clinic|hospital|hotel|resort|restaurant|brewery|manufactur|website|web site|online|platform|software|service|startup|media|news|magazine|publisher|organi[sz]ation|enterprise|firm|chain|developer|operator|provider|компан|бізнес|бренд|магазин|мереж|агенц|банк|клінік|лікарн|готел|курорт|ресторан|бровар|виробник|сайт|платформ|сервіс|стартап|медіа|видання|журнал|газет|організац|підприємств|фірм|розробник|оператор|провайдер)/iu';

    const ROLE_LABELS = ['home' => 'головна', 'contact' => '«Контакти»', 'about' => '«Про нас»'];
    /** Пісочниці Wikidata: туди пишуть тестові P856 будь-яких сайтів. */
    const WD_SANDBOX = ['Q4115189', 'Q13406268', 'Q15397819', 'Q112795079'];

    /** @var string[] помилки звернень до Wikimedia */
    private array $apiErrors = [];
    /** Особистий сайт експерта: сутність - Person, а не Organization. */
    private bool $personal = false;

    /** Підпис ролі сторінки: для особистого сайту 'about' - «Про мене». */
    private function roleLabel(string $role): string
    {
        if ($role === 'about' && $this->personal) return '«Про мене»';
        return self::ROLE_LABELS[$role] ?? $role;
    }

    public function id(): string { return 'brand'; }
    public function name(): string { return 'Сутність бренду'; }
    public function weight(): float { return 0.12; }

    // ================================================================== run

    public function run(string $url, array $ctx): array
    {
        $this->apiErrors = [];
        $site = is_array($ctx['site'] ?? null) ? $ctx['site'] : [];
        $type = (string)($site['type'] ?? 'corporate');
        $isLocal = $type === 'local';
        $this->personal = $type === 'personal';
        $host = (string)($site['host'] ?? '');
        if ($host === '') $host = Crawler::registrableHost($url);
        $hosts = array_values(array_unique(array_filter([
            $host, Crawler::registrableHost($url), Crawler::registrableHost((string)($ctx['input_url'] ?? '')),
        ])));
        $homeUrl = Crawler::baseUrl($url) . '/';
        $hostLabel = SiteProfile::hostLabel($url);
        $brand = trim((string)($site['brand'] ?? ''));
        if ($brand === '') $brand = mb_strtoupper(mb_substr($hostLabel, 0, 1)) . mb_substr($hostLabel, 1);
        $isUa = (bool)preg_match('/\.ua$/', $host) || ($site['lang'] ?? '') === 'uk' || in_array('uk', (array)($site['languages'] ?? []), true);
        $langs = array_values(array_unique(array_filter(array_merge([(string)($site['lang'] ?? '')], array_map('strval', (array)($site['languages'] ?? []))),
            function ($l) { return isset(self::WD_LANGS[$l]); })));

        // ---- сторінки для HTML-частини
        [$pages, $pageUrls, $pageNotes] = $this->collectPages($ctx);
        $home = $pages['home'];

        // «Work.ua», «AIN.UA»: доменна форма - частина назви, якщо сайт сам так підписується
        $stripped = self::brandCore($brand);
        $core = $stripped;
        if ($stripped !== $brand && $home) {
            $forms = [$home->title(), (string)$home->og('site_name')];
            foreach ($home->schemaItemsOfType(['Organization', 'WebSite']) as $it) $forms[] = self::firstString($it['data']['name'] ?? null);
            foreach ($forms as $f) if ($f !== '' && mb_stripos($f, $brand) !== false) { $core = $brand; break; }
        }
        $keys = array_values(array_unique(array_filter([self::normKey($core), self::normKey($stripped), self::normKey($brand), self::normKey($hostLabel), self::normKey($host)],
            function ($k) { return strlen($k) >= 2; })));

        // ---- HTML-частина (соцмережі потрібні й для звірки однойменних елементів Wikidata)
        $social = $home ? $this->collectSocial($pages, $type, $keys) : null;
        // особистий сайт: sameAs і назву беремо з Person (головна особа сайту), а не з Organization
        $orgInfo = $home ? ($this->personal ? $this->personNodes($pages, $keys, $hosts) : $this->orgNodes($pages, $keys, $hosts)) : null;

        // ---- Wikimedia
        $terms = self::searchTerms($core, $stripped, $hostLabel);
        $wm = $this->wikimedia($terms, $hosts, $keys, $isUa, ($social['best'] ?? []) + ($social['meta'] ?? []));
        $wdOk = $wm['ok'];
        // особистий сайт: елемент-людина (P31 = Q5) перевіряємо за твердженнями для особи, а не компанії
        if ($this->personal && $wm['verified'] && in_array('Q5', $wm['verified']['p31'], true)) $wm['verified']['class'] = 'person';
        $entity = $wm['verified'] ?? ($wm['semi']['entity'] ?? null);

        $findings = [];
        $passed = [];
        $sub = [];
        $data = [
            'brand' => $brand, 'brand_core' => $core, 'host' => $host,
            'search_terms' => array_map(function ($t) { return $t[0]; }, $terms),
            'wikidata' => null, 'wikipedia' => null, 'wiki_mentions' => null,
        ];

        // ================= 1. Wikidata
        $sub['wikidata'] = null;
        $sub['wikipedia'] = null;
        if ($wdOk) {
            $sub['wikidata'] = $this->evalWikidata($wm, $core, $host, $homeUrl, $isUa, $langs, $type, $social, $orgInfo, $findings, $passed, $data);
            $sub['wikipedia'] = $this->evalWikipedia($wm, $entity, $core, $isLocal, $findings, $passed, $data);
        }

        // ================= 2. Згадки у Вікіпедії
        $sub['wiki_mentions'] = $this->evalMentions($wm, $entity, $host, $type, $findings, $passed, $data);

        if (!$wdOk || $sub['wiki_mentions'] === null) {
            if ($this->apiErrors) {
                $what = !$wdOk && $sub['wiki_mentions'] === null ? 'Wikidata і Вікіпедію' : (!$wdOk ? 'Wikidata' : 'згадки у Вікіпедії');
                $findings[] = $this->finding('wikimedia_unavailable', 'low', 'Не вдалося перевірити ' . $what, [
                    'evidence' => 'Сервіси Wikimedia не відповіли коректно за ' . self::API_TIMEOUT . ' с: ' . self::listSome(array_unique($this->apiErrors), 4) . '. Ці показники позначено «н/д» і не враховано в балі.',
                    'why' => 'Без відповіді API висновок про наявність сутності був би вигаданим.',
                    'fix' => 'Повторіть аудит пізніше. Вручну: пошук на wikidata.org за назвою бренду.',
                    'bad' => 'Wikidata / Вікіпедія: немає відповіді API',
                    'good' => 'API відповідає, аудит перевіряє елемент Wikidata за P856 і статті Вікіпедії',
                    'verify' => 'Повторний аудит: показники Wikidata і Вікіпедії отримують бал.',
                    'effort' => 'S',
                ]);
            }
        }

        // ================= 3-6. HTML
        $sub['social'] = null;
        $sub['same_as'] = null;
        $sub['consistency'] = null;
        $sub['local_profile'] = null;
        if ($home) {
            $sub['social'] = $this->evalSocial($social, $orgInfo, $type, $pages, $pageUrls, $pageNotes, $findings, $passed, $data);
            $sub['same_as'] = $this->evalSameAs($social, $orgInfo, $wm, $core, $homeUrl, $hosts, $type, $home, $findings, $passed, $data);
            $sub['consistency'] = $this->evalConsistency($home, $orgInfo, $core, $brand, $homeUrl, $findings, $passed, $data);
            if ($isLocal) $sub['local_profile'] = $this->evalLocal($pages, $pageUrls, $pageNotes, $orgInfo, $core, $findings, $passed, $data);
        } else {
            $acc = is_array($ctx['access'] ?? null) ? $ctx['access'] : [];
            $reason = (string)($acc['block_reason'] ?? '');
            $status = (int)($acc['homepage_status'] ?? ($ctx['homepage']['status'] ?? 0));
            $ev = 'Головна не віддала HTML ні боту, ні браузеру (HTTP ' . $status . ($reason !== '' ? ', причина: ' . $reason : '') . '). '
                . 'Соцмережі, sameAs, назву бренду в метаданих' . ($isLocal ? ' і посилання на Google Maps' : '') . ' не перевірено - ці показники «н/д». '
                . 'Wikidata і Вікіпедію перевірено за доменом ' . $host . ', бо для них HTML не потрібен.';
            $findings[] = $this->finding('html_unavailable', 'low', 'Частину перевірки не виконано: сайт не віддав HTML', [
                'evidence' => $ev,
                'why' => 'Якщо сайт закритий для нашого бота, найімовірніше, так само він закритий і для частини AI-краулерів - це окремо оцінює перевірка доступу ботів.',
                'fix' => 'Відкрийте сайт для ботів (див. перевірку доступу) і запустіть аудит повторно.',
                'bad' => 'HTTP ' . $status . ($reason !== '' ? ' (' . $reason . ')' : '') . ' замість HTML головної',
                'good' => 'HTTP 200 і повний HTML головної для бота',
                'verify' => 'curl -sI -A "OAI-SearchBot/1.0" ' . $homeUrl . ' - очікуємо HTTP 200.',
                'effort' => 'S',
            ]);
        }
        // local_profile лишається в sub_scores як null («н/д») для нелокальних сайтів

        // ================= бал
        $w = $isLocal
            ? ['wikidata' => 20, 'wikipedia' => 5, 'wiki_mentions' => 5, 'social' => 20, 'same_as' => 15, 'consistency' => 10, 'local_profile' => 25]
            : ['wikidata' => 25, 'wikipedia' => 15, 'wiki_mentions' => 10, 'social' => 20, 'same_as' => 15, 'consistency' => 15];
        // особистий сайт: статті у Вікіпедії про експертів рідкісні, профілі (LinkedIn тощо) і sameAs важать більше
        if ($this->personal) $w = ['wikidata' => 25, 'wikipedia' => 10, 'wiki_mentions' => 10, 'social' => 25, 'same_as' => 20, 'consistency' => 10];
        $comp = [];
        foreach ($w as $k => $wt) $comp[$k] = [$sub[$k] ?? null, $wt];
        $hasAny = false;
        foreach ($sub as $v) if ($v !== null) $hasAny = true;
        if (!$hasAny) {
            $why = $home ? 'Сервіси Wikimedia не відповіли, а на сторінках немає даних для оцінки' : 'Сайт не віддав HTML, а сервіси Wikimedia не відповіли';
            return $this->skipped($why . ' - сутність бренду оцінити не вдалося.', ['api_errors' => array_values(array_unique($this->apiErrors))]);
        }
        $score = $this->scoreFrom($comp);
        $data['weights'] = $w;
        if ($this->apiErrors) $data['api_errors'] = array_values(array_unique(array_slice($this->apiErrors, 0, 6)));

        // Часткова оцінка: складові, які не вдалося перевірити (не «не застосовно»), не мають завищувати бал.
        $notAssessed = [];
        if (!$home) {
            $notAssessed = ['соцмережі', 'sameAs', 'узгодженість назви'];
            if ($isLocal) $notAssessed[] = 'Google Maps';
        }
        if (!$wdOk) { $notAssessed[] = 'Wikidata'; $notAssessed[] = 'стаття у Вікіпедії'; }
        if ($sub['wiki_mentions'] === null) $notAssessed[] = 'посилання з Вікіпедії';
        if ($notAssessed) {
            $data['partial'] = ['not_assessed' => $notAssessed, 'of' => count($w), 'cap' => self::PARTIAL_CAP];
            if ($score > self::PARTIAL_CAP) { $data['partial']['score_uncapped'] = $score; $score = self::PARTIAL_CAP; }
        }

        $labels = [
            'wikidata' => 'Wikidata (підтверджено доменом)',
            'wikipedia' => 'Стаття у Вікіпедії (uk/en)',
            'wiki_mentions' => 'Посилання з Вікіпедії на сайт',
            'social' => 'Профілі в соцмережах',
            'same_as' => 'sameAs в Organization',
            'consistency' => 'Узгодженість назви бренду',
            'local_profile' => $isLocal ? 'Google Maps / Business Profile' : 'Google Maps / Business Profile (лише для локального бізнесу)',
        ];
        if ($this->personal) {
            $labels['wikipedia'] = 'Стаття про особу у Вікіпедії (uk/en)';
            $labels['social'] = 'Профілі особи (LinkedIn, соцмережі)';
            $labels['same_as'] = 'sameAs у Person';
            $labels['consistency'] = 'Узгодженість імені / назви сайту';
        }

        return $this->result($score, $sub, $labels, $findings, $passed, $data, $this->summary($wm, $sub, $social, $data, $home !== null));
    }

    // ================================================================== Wikidata / Wikipedia

    /**
     * Усі звернення до Wikimedia: 1-й раунд (пошук, SPARQL за P856, exturlusage uk/en) і 2-й (wbgetentities).
     */
    private function wikimedia(array $terms, array $hosts, array $keys, bool $isUa, array $profiles): array
    {
        $out = ['ok' => false, 'verified' => null, 'verified_via' => null, 'others_verified' => [], 'semi' => null,
            'possible' => [], 'other_site' => [], 'weak' => [], 'mentions' => ['uk' => null, 'en' => null], 'sparql_hits' => []];
        if (!$hosts) return $out;
        $t0 = microtime(true);
        $enc = PHP_QUERY_RFC3986;
        $req = [];
        foreach ($terms as $i => $t) {
            foreach ($t[1] as $l) {
                $req["s:$i:$l"] = self::WD_API . '?' . http_build_query(['action' => 'wbsearchentities', 'search' => $t[0],
                    'language' => $l, 'uselang' => $l, 'type' => 'item', 'limit' => 10, 'format' => 'json'], '', '&', $enc);
            }
        }
        $vals = [];
        foreach ($hosts as $h) {
            if (!preg_match('/^[a-z0-9.\-]+$/', $h)) continue;
            foreach (['https', 'http'] as $sc) foreach (['', 'www.'] as $ww) foreach (['/', ''] as $sl) $vals[] = '<' . $sc . '://' . $ww . $h . $sl . '>';
        }
        if ($vals) {
            $q = 'SELECT DISTINCT ?item WHERE { VALUES ?site { ' . implode(' ', array_unique($vals)) . ' } ?item wdt:P856 ?site . } LIMIT 10';
            $req['sparql'] = self::WDQS . '?' . http_build_query(['query' => $q, 'format' => 'json'], '', '&', $enc);
        }
        $euParams = ['action' => 'query', 'generator' => 'exturlusage', 'geuquery' => $hosts[0], 'geunamespace' => 0, 'geulimit' => 50,
            'prop' => 'pageprops', 'ppprop' => 'wikibase_item', 'format' => 'json', 'formatversion' => 2];
        foreach (['uk', 'en'] as $l) {
            $req["eu:$l"] = 'https://' . $l . '.wikipedia.org/w/api.php?' . http_build_query($euParams, '', '&', $enc);
        }
        $r1 = $this->apiGetMulti($req);

        // ---- пошук
        $searchOk = false;
        $cands = [];
        foreach ($terms as $i => $t) {
            $tk = self::normKey($t[0]);
            foreach ($t[1] as $l) {
                $d = $r1["s:$i:$l"] ?? null;
                if (!is_array($d) || !isset($d['search'])) continue;
                $searchOk = true;
                foreach ((array)$d['search'] as $pos => $hit) {
                    $id = (string)($hit['id'] ?? '');
                    if (!preg_match('/^Q\d+$/', $id)) continue;
                    $label = (string)($hit['label'] ?? '');
                    $mt = (string)($hit['match']['text'] ?? $label);
                    $k1 = self::normKey($label);
                    $k2 = self::normKey($mt);
                    $exact = $tk !== '' && ($k1 === $tk || $k2 === $tk);
                    $close = $exact || ($tk !== '' && strlen($tk) >= 4 && (strpos($k1, $tk) === 0 || strpos($k2, $tk) === 0));
                    if (!$close) continue;
                    $rank = ($exact ? 0 : 100) + (int)$pos;
                    if (!isset($cands[$id]) || $rank < $cands[$id]['rank']) {
                        $cands[$id] = ['rank' => $rank, 'label' => $label, 'desc' => (string)($hit['description'] ?? '')];
                    }
                }
            }
        }
        uasort($cands, function ($a, $b) { return $a['rank'] <=> $b['rank']; });

        // ---- SPARQL
        $sparqlOk = false;
        $sparqlIds = [];
        $d = $r1['sparql'] ?? null;
        if (is_array($d) && isset($d['results']['bindings'])) {
            $sparqlOk = true;
            foreach ((array)$d['results']['bindings'] as $b) {
                if (preg_match('#/entity/(Q\d+)$#', (string)($b['item']['value'] ?? ''), $m)) $sparqlIds[] = $m[1];
            }
        }
        $sparqlIds = array_values(array_unique($sparqlIds));
        $out['sparql_hits'] = $sparqlIds;

        // ---- exturlusage: перша порція + до 3 продовжень
        // (порція може бути порожньою з «continue»: посилання з інших просторів назв відсіюються вже після вибірки)
        $articleMatches = [];
        $euState = [];
        foreach (['uk', 'en'] as $l) {
            $d = $r1["eu:$l"] ?? null;
            if (!is_array($d) || (!isset($d['query']) && !isset($d['batchcomplete']) && !isset($d['continue']))) continue;
            $euState[$l] = ['pages' => [], 'cont' => null, 'batches' => 0];
            $this->euAbsorb($euState[$l], $d);
        }
        for ($round = 0; $round < 3; $round++) {
            if (microtime(true) - $t0 > 10) break; // бюджет часу: решту рахуємо як «щонайменше»
            $reqC = [];
            foreach ($euState as $l => $st) {
                if ($st['cont'] !== null && empty($st['stop']) && count($st['pages']) < 25) {
                    $reqC["eu:$l"] = 'https://' . $l . '.wikipedia.org/w/api.php?' . http_build_query($euParams + $st['cont'], '', '&', $enc);
                }
            }
            if (!$reqC) break;
            foreach ($this->apiGetMulti($reqC) as $k => $d) {
                $l = substr((string)$k, 3);
                if (is_array($d)) $this->euAbsorb($euState[$l], $d);
                else $euState[$l]['stop'] = true; // помилка продовження: далі не йдемо, «є ще» лишається
            }
        }
        foreach ($euState as $l => $st) {
            foreach ($st['pages'] as $pg) {
                $tk = self::normKey((string)preg_replace('/\s*\([^)]*\)\s*$/u', '', $pg['title']));
                if ($tk !== '' && in_array($tk, $keys, true) && preg_match('/^Q\d+$/', $pg['qid'])) {
                    $articleMatches[] = ['wiki' => $l, 'title' => $pg['title'], 'qid' => $pg['qid']];
                }
            }
            $out['mentions'][$l] = ['pages' => array_values($st['pages']), 'more' => $st['cont'] !== null, 'batches' => $st['batches']];
        }

        // Wikidata недоступна. Без пошуку за назвою (лише SPARQL за точними URL) негативний висновок ненадійний:
        // P856 може мати шлях (/uk/), якого немає серед варіантів у VALUES.
        if (!$searchOk && !$sparqlIds) return $out;

        // ---- 2-й раунд: сутності
        if ($sparqlIds) {
            $fetch = $sparqlIds;
        } else {
            $fetch = array_merge(array_column($articleMatches, 'qid'), array_slice(array_keys($cands), 0, 8));
        }
        $fetch = array_slice(array_values(array_unique($fetch)), 0, 12);
        $entities = [];
        if ($fetch) {
            $req2 = [];
            foreach (array_chunk($fetch, 6) as $ci => $chunk) {
                $req2["e:$ci"] = self::WD_API . '?' . http_build_query(['action' => 'wbgetentities', 'ids' => implode('|', $chunk),
                    'props' => 'claims|sitelinks|labels|descriptions', 'languages' => 'uk|en|mul', 'format' => 'json'], '', '&', $enc);
            }
            $r2 = $this->apiGetMulti($req2);
            $anyOk = false;
            foreach ($r2 as $d2) {
                if (!is_array($d2) || !isset($d2['entities'])) continue;
                $anyOk = true;
                foreach ((array)$d2['entities'] as $id => $e) {
                    if (!is_array($e) || isset($e['missing']) || !preg_match('/^Q\d+$/', (string)$id)) continue;
                    $entities[(string)$id] = self::parseEntity($e, $cands[(string)$id]['desc'] ?? '');
                }
            }
            if (!$anyOk) return $out; // не змогли прочитати елементи - не робимо висновків
        }
        $out['ok'] = true;

        // ---- підтвердження за P856
        $verified = [];
        foreach ($entities as $id => $ent) {
            if (in_array($id, self::WD_SANDBOX, true)) continue; // тестові елементи з випадковими P856
            foreach ($ent['p856'] as $u) {
                if (self::onHosts($u, $hosts)) { $verified[$id] = $ent; break; }
            }
        }
        if ($verified) {
            uasort($verified, function ($a, $b) {
                $sa = ($a['sitelinks']['ukwiki'] ? 4 : 0) + ($a['sitelinks']['enwiki'] ? 4 : 0) + (self::orgLike($a) ? 3 : 0) + min(count($a['sitelinks_other']), 5) * 0.2;
                $sb = ($b['sitelinks']['ukwiki'] ? 4 : 0) + ($b['sitelinks']['enwiki'] ? 4 : 0) + (self::orgLike($b) ? 3 : 0) + min(count($b['sitelinks_other']), 5) * 0.2;
                return $sb <=> $sa;
            });
            $v = reset($verified);
            $v['class'] = $this->entityClass($v);
            $out['verified'] = $v;
            $out['verified_via'] = in_array($v['id'], $sparqlIds, true) ? 'sparql' : 'search';
            $out['others_verified'] = array_slice(array_keys($verified), 1, 3);
            return $out;
        }

        // ---- елемент через статтю Вікіпедії, що посилається на сайт
        foreach ($articleMatches as $am) {
            if (isset($entities[$am['qid']])) {
                $out['semi'] = ['entity' => $entities[$am['qid']], 'article' => $am];
                break;
            }
        }
        // ---- однойменні елементи
        foreach ($entities as $id => $ent) {
            if ($out['semi'] && $out['semi']['entity']['id'] === $id) continue;
            if (!isset($cands[$id]) || in_array($id, self::WD_SANDBOX, true)) continue;
            $exact = false;
            foreach ([$ent['label_uk'], $ent['label_en'], $ent['label_mul'], $cands[$id]['label']] as $lb) {
                $k = self::normKey((string)$lb);
                if ($k !== '' && in_array($k, $keys, true)) { $exact = true; break; }
            }
            if (!$exact) continue;
            // клас/поняття (P279, без P31) або не організація - не кандидат («мафія» для mafia.ua)
            if (!self::candidateLike($ent)) { $out['weak'][] = $id; continue; }
            if ($ent['p856']) { $out['other_site'][] = $ent; continue; }
            // без P856 показуємо як можливий збіг лише за додатковою ознакою зв'язку з брендом
            if (self::socialMatch($ent, $profiles) || self::contextMatch($ent, $isUa)) $out['possible'][] = $ent;
            else $out['weak'][] = $id;
        }
        $out['possible'] = array_slice($out['possible'], 0, 3);
        $out['other_site'] = array_slice($out['other_site'], 0, 3);
        $out['weak'] = array_slice($out['weak'], 0, 5);
        return $out;
    }

    /** Клас елемента для вибору обов'язкових тверджень: org | software | website | other. */
    private function entityClass(array $ent): string
    {
        $id = (string)$ent['id'];
        $found = [];
        $nErr = count($this->apiErrors);
        if (preg_match('/^Q\d+$/', $id)) {
            $vals = implode(' ', array_map(function ($q) { return 'wd:' . $q; }, array_keys(self::WD_CLASS_ROOTS)));
            $q = 'SELECT DISTINCT ?root WHERE { VALUES ?root { ' . $vals . ' } wd:' . $id . ' wdt:P31/wdt:P279* ?root . }';
            $r = $this->apiGetMulti(['cls' => self::WDQS . '?' . http_build_query(['query' => $q, 'format' => 'json'], '', '&', PHP_QUERY_RFC3986)]);
            $d = $r['cls'] ?? null;
            if (is_array($d) && isset($d['results']['bindings'])) {
                foreach ((array)$d['results']['bindings'] as $b) {
                    if (preg_match('#/entity/(Q\d+)$#', (string)($b['root']['value'] ?? ''), $m) && isset(self::WD_CLASS_ROOTS[$m[1]])) $found[self::WD_CLASS_ROOTS[$m[1]]] = true;
                }
                foreach (['org', 'software', 'website'] as $c) if (isset($found[$c])) return $c;
                return 'other';
            }
            array_splice($this->apiErrors, $nErr); // збій лише уточнення класу - не показуємо як недоступність Wikidata
        }
        if (array_intersect($ent['p31'], self::WD_DIRECT_ORG)) return 'org';
        if (array_intersect($ent['p31'], self::WD_DIRECT_SOFTWARE)) return 'software';
        if (array_intersect($ent['p31'], self::WD_DIRECT_WEBSITE)) return 'website';
        return 'other';
    }

    /** Однойменний елемент може бути брендом: є P31 (не клас P279), P31 не з блок-листа, організаційний тип. */
    private static function candidateLike(array $ent): bool
    {
        if (!$ent['p31'] || !empty($ent['p279'])) return false;
        if (array_intersect($ent['p31'], self::P31_BLOCK)) return false;
        if (array_intersect($ent['p31'], self::P31_ORG)) return true;
        return (bool)preg_match(self::RX_ORG_DESC, $ent['desc_uk'] . ' ' . $ent['desc_en'] . ' ' . $ent['desc_search']);
    }

    /** Ідентифікатор соцмережі в елементі збігається з профілем, на який посилається сайт. */
    private static function socialMatch(array $ent, array $profiles): bool
    {
        foreach ($profiles as $pl => $a) {
            $sk = self::wdSocialProp((string)$pl, $a) === self::WD_SOCIAL['linkedin_in'] ? 'linkedin_in' : $pl;
            $wd = strtolower((string)($ent['social'][$sk] ?? ''));
            $mine = self::wdSocialValue((string)$pl, $a);
            if ($wd !== '' && $mine !== null && strtolower($mine) === $wd) return true;
        }
        return false;
    }

    /** Для українського сайту: країна Україна або українська географія в описі; для інших - чіткий організаційний P31. */
    private static function contextMatch(array $ent, bool $isUa): bool
    {
        if (!$isUa) return (bool)array_intersect($ent['p31'], self::P31_ORG);
        if (in_array('Q212', $ent['p17'], true)) return true;
        if ($ent['p17']) return false; // інша країна
        return (bool)preg_match(self::RX_UA_CONTEXT, $ent['desc_uk'] . ' ' . $ent['desc_en'] . ' ' . $ent['desc_search']);
    }

    /** Значення ідентифікатора Wikidata (P2013 тощо) з профілю на сайті або null, якщо з URL його не вивести. */
    private static function wdSocialValue(string $pl, array $a): ?string
    {
        $h = (string)($a['handle'] ?? '');
        $val = null;
        switch ($pl) {
            case 'facebook': if (strpos($h, ':') === false && strpos($h, '/') === false) $val = $h; break;
            case 'instagram': case 'x': case 'github': $val = $h; break;
            case 'tiktok': $val = ltrim($h, '@'); break;
            case 'telegram': if (($a['kind'] ?? '') === 'profile') $val = $h; break;
            case 'linkedin':
                if (strpos($h, 'company/') === 0) $val = substr($h, 8);
                elseif (strpos($h, 'in/') === 0) $val = substr($h, 3); // особистий профіль (P6634)
                break;
            case 'youtube': if (strpos($h, 'channel/') === 0) $val = substr($h, 8); break;
        }
        return ($val !== null && $val !== '') ? $val : null;
    }

    /** Властивість Wikidata для профілю: особистий LinkedIn (linkedin.com/in/...) - P6634, решта - за WD_SOCIAL. */
    private static function wdSocialProp(string $pl, array $a): ?string
    {
        if ($pl === 'linkedin' && strpos((string)($a['handle'] ?? ''), 'in/') === 0) return self::WD_SOCIAL['linkedin_in'];
        return self::WD_SOCIAL[$pl] ?? null;
    }

    /** GitHub - профіль бренду лише для SaaS і особистого сайту (розробники, технічні фахівці). */
    private static function githubCounts(string $type): bool
    {
        return in_array($type, ['saas', 'personal'], true);
    }

    /** URL профілю з ідентифікатора Wikidata. */
    private static function wdSocialUrl(string $pl, string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        $tpl = ['facebook' => 'https://www.facebook.com/%s', 'instagram' => 'https://www.instagram.com/%s', 'linkedin' => 'https://www.linkedin.com/company/%s/',
            'youtube' => 'https://www.youtube.com/channel/%s', 'telegram' => 'https://t.me/%s', 'x' => 'https://x.com/%s', 'tiktok' => 'https://www.tiktok.com/@%s',
            'github' => 'https://github.com/%s'];
        return isset($tpl[$pl]) ? sprintf($tpl[$pl], rawurlencode($v)) : '';
    }

    /** Додає сторінки з відповіді exturlusage у стан і запам'ятовує продовження. */
    private function euAbsorb(array &$st, array $d): void
    {
        $st['batches']++;
        foreach ((array)($d['query']['pages'] ?? []) as $p) {
            if (!is_array($p) || (int)($p['ns'] ?? 0) !== 0 || !isset($p['title'])) continue;
            $t = (string)$p['title'];
            $st['pages'][$t] = ['title' => $t, 'qid' => (string)($p['pageprops']['wikibase_item'] ?? '')];
        }
        $c = $d['continue'] ?? null;
        $cont = is_array($c) ? array_map('strval', array_filter($c, 'is_scalar')) : [];
        $st['cont'] = $cont ? $cont : null;
    }

    private function evalWikidata(array $wm, string $core, string $host, string $homeUrl, bool $isUa, array $langs, string $type, ?array $social, ?array $orgInfo, array &$findings, array &$passed, array &$data): ?int
    {
        $ver = $wm['verified'];
        $semi = $wm['semi'];
        $profiles = ($social['best'] ?? []) + ($social['meta'] ?? []); // + профілі з twitter:site / article:publisher
        $org = $orgInfo['main'] ?? null;

        if ($ver) {
            $id = $ver['id'];
            $label = self::entLabel($ver);
            $p856 = $ver['p856'];
            $class = (string)($ver['class'] ?? 'other');
            $passed[] = 'Сутність у Wikidata підтверджена доменом: ' . $id . ' «' . $label . '», P856 = ' . self::listSome($p856, 2);
            [$missing, $present] = self::entityGaps($ver, $profiles, $class);
            $total = 1 + count(self::WD_CLASS_PROPS[$class]) + 4; // P31 + твердження класу + соцмережі + Luk/Len/Duk
            $filled = $total - count(array_filter($missing, function ($m) { return $m['counts']; }));
            $score = 60 + (int)round(40 * max(0, $filled) / $total);
            $data['wikidata'] = ['status' => 'verified', 'id' => $id, 'label' => $label, 'p856' => $p856, 'via' => $wm['verified_via'], 'class' => $class,
                'missing' => array_values(array_filter(array_column($missing, 'label'), 'strlen')), 'others' => $wm['others_verified']];
            $req = array_merge(['P31'], array_keys(self::WD_CLASS_PROPS[$class]));
            if (!$missing) {
                $passed[] = 'Елемент ' . $id . ' заповнений: ' . implode(', ', $req) . ', ідентифікатори соцмереж, назва й опис uk/en';
            } else {
                [$qs, $manual] = $this->qsLines($id, $ver, $core, $homeUrl, $isUa, $langs, $type, $class, $profiles, $org, $missing);
                $missLabels = array_values(array_filter(array_column($missing, 'label'), 'strlen'));
                $whyWhat = ['org' => 'країна, дата заснування, штаб-квартира, галузь', 'software' => 'розробник, ліцензія, дата випуску',
                    'website' => 'країна, дата запуску, мова', 'other' => 'країна, дата заснування', 'person' => 'рід занять, громадянство, ім\'я та прізвище'][$class];
                $findings[] = $this->finding('wikidata_incomplete', 'low', $class === 'person' ? 'Елемент Wikidata про особу заповнений не повністю' : 'Елемент Wikidata заповнений не повністю', [
                    'evidence' => $id . ' «' . $label . '»' . (in_array($class, ['software', 'website', 'person'], true) ? ' (клас: ' . ['software' => 'програмне забезпечення', 'website' => 'сайт / видання', 'person' => 'людина'][$class] . ')' : '')
                        . ': немає ' . implode(', ', $missLabels) . '.' . ($present ? ' Заповнено: ' . implode(', ', $present) . '.' : ''),
                    'why' => 'Чим повніший елемент (' . $whyWhat . ', профілі в соцмережах), тим точніше AI-системи описують ' . self::WD_CLASS_NOUN[$class] . ' і зіставляють з профілями.',
                    'fix' => 'Додайте відсутні твердження з джерелами.' . ($qs ? ' Рядки, які можна заповнити з даних сайту, - у форматі QuickStatements (quickstatements.toolforge.org, V1).' : '') . ($manual ? ' Вручну: ' . implode(', ', $manual) . '.' : ''),
                    'bad' => $id . ': ' . ($present ? implode(', ', $present) : 'лише базові твердження') . "\nНемає: " . implode(', ', $missLabels),
                    'good' => $id . ': ' . implode(', ', $req) . ', P856 + ідентифікатори профілів (P2013, P2003, ' . ($class === 'person' ? 'P6634' : 'P4264') . ', P2397, P3789) + назва й опис uk/en',
                    'code' => implode("\n", $qs),
                    'verify' => 'https://www.wikidata.org/wiki/' . $id . ' - нові твердження видно на сторінці елемента; повторний аудит не покаже цього зауваження.',
                    'effort' => 'S',
                ]);
            }
            return $score;
        }

        $termsShown = self::listSome(array_map(function ($t) { return '«' . $t . '»'; }, $data['search_terms']), 3);
        if ($semi) {
            $e = $semi['entity'];
            $a = $semi['article'];
            $id = $e['id'];
            $label = self::entLabel($e);
            $p856 = $e['p856'];
            $data['wikidata'] = ['status' => 'no_p856', 'id' => $id, 'label' => $label, 'p856' => $p856, 'article' => $a['wiki'] . ':' . $a['title']];
            $findings[] = $this->finding('wikidata_no_p856', 'medium', 'Елемент Wikidata бренду не прив\'язаний до домену ' . $host, [
                'evidence' => 'Стаття ' . $a['wiki'] . '.wikipedia.org «' . $a['title'] . '» посилається на ' . $host . ', її елемент Wikidata - ' . $id . ' «' . $label . '». '
                    . 'P856 (офіційний сайт): ' . ($p856 ? self::listSome($p856, 3) . ' - вашого домену серед них немає' : 'не вказано') . '.',
                'why' => 'Офіційний сайт у Wikidata задає властивість P856. Без неї системам, що беруть дані з Wikidata, складніше зв\'язати бренд із доменом, і AI може не назвати ваш сайт офіційним.',
                'fix' => 'Додайте до ' . $id . ' твердження P856 = ' . $homeUrl . ' з джерелом.' . ($p856 ? ' Якщо старий домен більше не працює, не видаляйте його: додайте кваліфікатор P582 (дата закінчення), а новому сайту дайте бажаний ранг.' : ''),
                'bad' => $id . ': P856 = ' . ($p856 ? implode(', ', $p856) : 'не вказано'),
                'good' => $id . ': P856 = ' . $homeUrl,
                'code' => $id . '|P856|"' . $homeUrl . '"',
                'verify' => 'https://www.wikidata.org/wiki/' . $id . ' - у твердженні «official website» є ' . $homeUrl . '; повторний аудит покаже «сутність підтверджена».',
                'effort' => 'S',
            ]);
            return 50;
        }

        // ---- підтвердженої сутності немає
        if ($this->personal) return $this->noWikidataPerson($wm, $core, $host, $homeUrl, $isUa, $langs, $type, $profiles, $org, $termsShown, $findings, $data);
        $newClass = $type === 'media' ? 'website' : 'org';
        [$qs, $manual] = $this->qsLines(null, null, $core, $homeUrl, $isUa, $langs, $type, $newClass, $profiles, $org, null);
        $ev = ['Пошук у Wikidata: ' . $termsShown . ' (uk, en) і зворотний пошук за P856 = ' . $host . '.',
            'Елемента, у якого офіційний сайт (P856) - ' . $host . ', не знайдено.'];
        foreach ($wm['possible'] as $p) $ev[] = 'Можливий збіг без P856: ' . $p['id'] . ' «' . self::entLabel($p) . '»' . (self::entDesc($p) !== '' ? ' - ' . self::entDesc($p) : '') . ' (не підтверджено, окреме зауваження нижче).';
        foreach ($wm['other_site'] as $p) $ev[] = 'Однойменний елемент з іншим сайтом: ' . $p['id'] . ' «' . self::entLabel($p) . '», P856 = ' . self::listSome($p['p856'], 2) . ' - це інша компанія або ваш інший домен (тоді додайте до елемента P856 = ' . $homeUrl . ').';
        $ev[] = 'Статей у uk/en Вікіпедії з назвою бренду, які посилаються на ' . $host . ', теж не знайдено.';
        $data['wikidata'] = ['status' => $wm['possible'] ? 'possible' : 'none',
            'possible' => array_map(function ($p) { return $p['id']; }, $wm['possible']),
            'other_site' => array_map(function ($p) { return $p['id'] . ' ' . implode(' ', $p['p856']); }, $wm['other_site']),
            'weak_candidates' => $wm['weak']]; // однойменні елементи без ознак зв'язку з брендом (поняття, інша країна) - лише для довідки

        $isMediaNew = $newClass === 'website';
        $findings[] = $this->finding('no_wikidata', 'high', 'Бренд не має сутності у Wikidata, прив\'язаної до домену ' . $host, [
            'evidence' => implode("\n", $ev),
            'why' => 'Wikidata - відкрита база знань, дані якої використовують пошукові системи, голосові асистенти й AI-сервіси. Елемент з офіційним сайтом (P856) однозначно пов\'язує назву бренду з доменом: AI рідше плутає вас з однойменними компаніями і може назвати ваш сайт як офіційний.',
            'fix' => 'Створіть елемент Wikidata: назва й опис uk/en, ' . ($isMediaNew ? 'P31 (інтернет-видання)' : 'P31 (бізнес/компанія)') . ', P856 - офіційний сайт, ' . ($isUa ? 'P17 - Україна, ' : 'P17 - країна, ')
                . ($isMediaNew ? 'P571 - дата запуску, P407 - мова, ' : 'P571 - дата заснування, P159 - місто штаб-квартири, P452 - галузь, ')
                . 'ідентифікатори соцмереж (P2013 Facebook, P2003 Instagram, P4264 LinkedIn, P2397 YouTube, P3789 Telegram). '
                . 'Додайте джерела (реєстр, публікації в ЗМІ): поріг значущості Wikidata нижчий, ніж у Вікіпедії, але рекламні елементи без джерел видаляють. '
                . 'Статтю у Вікіпедії самі не пишіть: вона має з\'явитися завдяки незалежним публікаціям про бренд в авторитетних медіа, інакше її видалять як незначущу або рекламну.',
            'bad' => 'Wikidata: немає елемента з P856 = ' . $homeUrl . "\nВікіпедія uk/en: статті про бренд не знайдено",
            'good' => 'Елемент «' . $core . '»: ' . ($isMediaNew ? 'P31 = інтернет-видання' : 'P31 = бізнес') . ', P856 = ' . $homeUrl . ($isUa ? ', P17 = Україна' : '')
                . ($isMediaNew ? ', P571, P407' : ', P571, P159, P452') . ', ідентифікатори профілів, джерела; посилання на елемент - у sameAs розмітки Organization'
                . ($manual ? "\nВручну додайте: " . implode(', ', $manual) : ''),
            'code' => implode("\n", $qs),
            'verify' => 'Після створення: Wikidata Query Service (query.wikidata.org) - SELECT ?item WHERE { ?item wdt:P856 <' . $homeUrl . '> } повертає ваш елемент; повторний аудит покаже «сутність підтверджена».',
            'effort' => 'M',
        ]);

        if ($wm['possible']) {
            $p = $wm['possible'][0];
            $sl = [];
            if ($p['sitelinks']['ukwiki']) $sl[] = 'uk.wikipedia «' . $p['sitelinks']['ukwiki'] . '»';
            if ($p['sitelinks']['enwiki']) $sl[] = 'en.wikipedia «' . $p['sitelinks']['enwiki'] . '»';
            $findings[] = $this->finding('wikidata_possible', 'low', 'У Wikidata є можливий збіг ' . $p['id'] . ' «' . self::entLabel($p) . '» - не підтверджено (немає P856 з вашим доменом)', [
                'evidence' => $p['id'] . ' «' . self::entLabel($p) . '»' . (self::entDesc($p) !== '' ? ' - ' . self::entDesc($p) : '') . '. P856 (офіційний сайт): немає.'
                    . ($sl ? ' Статті: ' . implode(', ', $sl) . '.' : '') . ' Без P856 з доменом ' . $host . ' не можна підтвердити, що це ваш бренд.',
                'why' => 'Без P856 системам, що користуються Wikidata, складніше надійно зв\'язати елемент із сайтом, і однойменні бренди легше сплутати.',
                'fix' => 'Якщо це ваша компанія - додайте P856 = ' . $homeUrl . ' з джерелом. Якщо ні - створіть окремий елемент (див. попереднє зауваження).',
                'bad' => $p['id'] . ': P856 - не вказано',
                'good' => $p['id'] . ': P856 = ' . $homeUrl,
                'code' => $p['id'] . '|P856|"' . $homeUrl . '"',
                'verify' => 'https://www.wikidata.org/wiki/' . $p['id'] . ' - твердження «official website» з вашим доменом; повторний аудит покаже «сутність підтверджена».',
                'effort' => 'S',
            ]);
            return 15;
        }
        return 0;
    }

    /** Особистий сайт без елемента Wikidata з P856: ті самі перевірки, але про людину (P31 = Q5), а не компанію. */
    private function noWikidataPerson(array $wm, string $core, string $host, string $homeUrl, bool $isUa, array $langs, string $type, array $profiles, ?array $org, string $termsShown, array &$findings, array &$data): int
    {
        [$qs, $manual] = $this->qsLines(null, null, $core, $homeUrl, $isUa, $langs, $type, 'person', $profiles, $org, null);
        $ev = ['Пошук у Wikidata: ' . $termsShown . ' (uk, en) і зворотний пошук за P856 = ' . $host . '.',
            'Елемента, у якого офіційний сайт (P856) - ' . $host . ', не знайдено.'];
        foreach ($wm['possible'] as $p) $ev[] = 'Можливий збіг без P856: ' . $p['id'] . ' «' . self::entLabel($p) . '»' . (self::entDesc($p) !== '' ? ' - ' . self::entDesc($p) : '') . ' (не підтверджено).';
        foreach ($wm['other_site'] as $p) $ev[] = 'Однойменний елемент з іншим сайтом: ' . $p['id'] . ' «' . self::entLabel($p) . '», P856 = ' . self::listSome($p['p856'], 2) . ' - це інша особа або ваш інший сайт (тоді додайте до елемента P856 = ' . $homeUrl . ').';
        $data['wikidata'] = ['status' => $wm['possible'] ? 'possible' : 'none',
            'possible' => array_map(function ($p) { return $p['id']; }, $wm['possible']),
            'other_site' => array_map(function ($p) { return $p['id'] . ' ' . implode(' ', $p['p856']); }, $wm['other_site']),
            'weak_candidates' => $wm['weak']];
        $findings[] = $this->finding('no_wikidata', 'medium', 'Немає елемента Wikidata про особу, прив\'язаного до домену ' . $host, [
            'evidence' => implode("\n", $ev),
            'why' => 'Дані Wikidata використовують пошукові системи, голосові асистенти й AI-сервіси. Елемент людини з офіційним сайтом (P856) і профілями однозначно пов\'язує ваше ім\'я з доменом: AI рідше плутає вас з тезками і може назвати сайт вашим.',
            'fix' => 'Створіть елемент Wikidata про себе: ім\'я й опис uk/en, P31 - людина (Q5), P106 - рід занять, ' . ($isUa ? 'P27 - Україна, ' : 'P27 - громадянство, ')
                . 'P856 - сайт, ідентифікатори профілів (P6634 LinkedIn, P2037 GitHub, P2397 YouTube, P3789 Telegram, P2013 Facebook). '
                . 'Додайте джерела (публікації про вас у медіа, профілі на конференціях): елементи без джерел видаляють. Статтю у Вікіпедії про себе не пишіть - це конфлікт інтересів.',
            'bad' => 'Wikidata: немає елемента з P856 = ' . $homeUrl,
            'good' => 'Елемент «' . $core . '»: P31 = людина (Q5), P106, ' . ($isUa ? 'P27 = Україна, ' : 'P27, ') . 'P856 = ' . $homeUrl . ', ідентифікатори профілів, джерела; посилання на елемент - у sameAs розмітки Person'
                . ($manual ? "\nВручну додайте: " . implode(', ', $manual) : ''),
            'code' => implode("\n", $qs),
            'verify' => 'Після створення: query.wikidata.org - SELECT ?item WHERE { ?item wdt:P856 <' . $homeUrl . '> } повертає ваш елемент; повторний аудит покаже «сутність підтверджена».',
            'effort' => 'M',
        ]);
        return $wm['possible'] ? 15 : 0;
    }

    private function evalWikipedia(array $wm, ?array $entity, string $core, bool $isLocal, array &$findings, array &$passed, array &$data): ?int
    {
        if (!$entity) {
            // немає сутності - відсутність статті вже описана в no_wikidata
            $data['wikipedia'] = ['uk' => null, 'en' => null];
            return 0;
        }
        $uk = $entity['sitelinks']['ukwiki'];
        $en = $entity['sitelinks']['enwiki'];
        $other = count($entity['sitelinks_other']);
        $data['wikipedia'] = ['uk' => $uk, 'en' => $en, 'other_count' => $other, 'entity' => $entity['id']];
        $id = $entity['id'];
        if ($uk && $en) {
            $passed[] = 'Є статті у Вікіпедії: uk - «' . $uk . '», en - «' . $en . '»';
            return 100;
        }
        if ($uk || $en) {
            $has = $uk ? 'uk' : 'en';
            $miss = $uk ? 'en' : 'uk';
            $title = $uk ?: $en;
            $passed[] = 'Є стаття у Вікіпедії (' . $has . '): «' . $title . '»';
            $findings[] = $this->finding('wikipedia_one_lang', 'low', 'Стаття у Вікіпедії є лише ' . ($uk ? 'українською' : 'англійською'), [
                'evidence' => 'Елемент ' . $id . ': ' . $has . 'wiki - «' . $title . '», ' . $miss . 'wiki - немає' . ($other ? '; ще статей в інших мовних розділах: ' . $other : '') . '.',
                'why' => $uk ? 'Англомовна Вікіпедія - одне з найвагоміших джерел у навчальних даних мовних моделей; без неї англомовні запити до AI про ' . ($this->personal ? 'вас' : 'бренд') . ' мають менше опори.'
                    : 'Для запитів українською AI-системи частіше спираються на україномовні джерела; стаття uk.wikipedia допомагає коректно описати ' . ($this->personal ? 'вас' : 'бренд') . ' українською.',
                'fix' => 'Не перекладайте рекламний текст самостійно: стаття потребує незалежних джерел мовою розділу. Якщо такі публікації є, ' . ($uk ? 'англомовну' : 'україномовну') . ' версію може створити незалежний автор.',
                'bad' => $has . '.wikipedia.org: «' . $title . '»' . "\n" . $miss . '.wikipedia.org: статті немає',
                'good' => 'uk.wikipedia.org і en.wikipedia.org: статті, пов\'язані з елементом ' . $id,
                'verify' => 'https://www.wikidata.org/wiki/' . $id . ' - розділ «Вікіпедія» містить ukwiki і enwiki.',
                'effort' => 'L',
            ]);
            return 65;
        }
        if ($this->personal) {
            $findings[] = $this->finding('no_wikipedia', 'low', 'Про особу немає статті у Вікіпедії (uk, en)', [
                'evidence' => 'Елемент ' . $id . ' «' . self::entLabel($entity) . '» не має статей у uk.wikipedia і en.wikipedia' . ($other ? '; є статті в інших мовних розділах: ' . $other : '') . '.',
                'why' => 'Вікіпедія - одне з найвагоміших джерел у навчальних даних мовних моделей. Для більшості експертів статті немає, і це нормально; але якщо вона з\'явиться, моделі, навчені після цього, зможуть описати вас без пошуку.',
                'fix' => 'Не пишіть статтю про себе і не замовляйте її: це конфлікт інтересів, такі статті видаляють. Значущість людини доводять незалежні джерела (інтерв\'ю, профільні публікації про вас, нагороди, рейтинги). Коли їх достатньо, статтю може створити незалежний автор.',
                'bad' => "uk.wikipedia.org: статті немає\nen.wikipedia.org: статті немає",
                'good' => 'Стаття про «' . $core . '» з 3+ незалежними джерелами, пов\'язана з елементом ' . $id,
                'verify' => 'https://www.wikidata.org/wiki/' . $id . ' - у розділі «Вікіпедія» з\'являються ukwiki / enwiki.',
                'effort' => 'L',
            ]);
            return $other ? 30 : 0;
        }
        $sev = $isLocal ? 'low' : 'medium';
        $findings[] = $this->finding('no_wikipedia', $sev, 'Про бренд немає статті у Вікіпедії (uk, en)', [
            'evidence' => 'Елемент ' . $id . ' «' . self::entLabel($entity) . '» не має статей у uk.wikipedia і en.wikipedia' . ($other ? '; є статті в інших мовних розділах: ' . $other : '') . '.',
            'why' => 'Вікіпедія - одне з найвагоміших джерел у навчальних даних мовних моделей і часте джерело відповідей AI-пошуку. Стаття допомагає моделям «знати», хто ви, без пошуку.',
            'fix' => 'Не пишіть статтю самі і не замовляйте рекламну: її видалять через рекламний характер або незначущість. Значущість доводять незалежні публікації в авторитетних ЗМІ (огляди, рейтинги, інтерв\'ю, галузеві видання) - збирайте їх через PR, а статтю нехай створить незалежний автор, коли джерел буде достатньо.',
            'bad' => "uk.wikipedia.org: статті немає\nen.wikipedia.org: статті немає",
            'good' => 'Стаття про «' . $core . '» з 3+ незалежними джерелами, пов\'язана з елементом ' . $id,
            'verify' => 'https://www.wikidata.org/wiki/' . $id . ' - у розділі «Вікіпедія» з\'являються ukwiki / enwiki.',
            'effort' => 'L',
        ]);
        return $other ? 30 : 0;
    }

    private function evalMentions(array $wm, ?array $entity, string $host, string $type, array &$findings, array &$passed, array &$data): ?int
    {
        $uk = $wm['mentions']['uk'];
        $en = $wm['mentions']['en'];
        if ($uk === null && $en === null) return null;
        $ownQ = $entity['id'] ?? '';
        $ownTitles = ['uk' => $entity['sitelinks']['ukwiki'] ?? null, 'en' => $entity['sitelinks']['enwiki'] ?? null];
        $counts = [];
        $titles = [];
        $more = false;
        $moreBy = [];
        $own = [];
        foreach (['uk' => $uk, 'en' => $en] as $l => $m) {
            if ($m === null) { $counts[$l] = null; continue; }
            $n = 0;
            foreach ($m['pages'] as $p) {
                if (($ownQ !== '' && $p['qid'] === $ownQ) || ($ownTitles[$l] !== null && $p['title'] === $ownTitles[$l])) { $own[] = $l . ': «' . $p['title'] . '»'; continue; }
                $n++;
                if (count($titles) < 6) $titles[] = $l . ': «' . $p['title'] . '»';
            }
            $counts[$l] = $n;
            if ($m['more']) { $more = true; $moreBy[$l] = true; }
        }
        $total = (int)($counts['uk'] ?? 0) + (int)($counts['en'] ?? 0);
        $data['wiki_mentions'] = ['uk' => $counts['uk'], 'en' => $counts['en'], 'more' => $more, 'examples' => array_slice($titles, 0, 4), 'own_article' => $own];
        if ($total === 0 && $more) {
            // у перших порціях лише посилання з інших просторів назв - точної кількості не знаємо, висновку не робимо
            $data['wiki_mentions']['note'] = 'у перших порціях exturlusage статей немає, але є продовження - кількість не визначено';
            return null;
        }
        $cnt = function ($l) use ($counts, $moreBy) { if ($counts[$l] === null) return 'н/д'; if (!empty($moreBy[$l])) return $counts[$l] > 0 ? $counts[$l] . '+' : 'не визначено'; return (string)$counts[$l]; };
        if ($total > 0) {
            $passed[] = 'На ' . $host . ' ' . self::plural($total, 'посилається ', 'посилаються ', 'посилаються ') . ($more ? 'щонайменше ' : '') . $total . ' ' . self::plural($total, 'стаття', 'статті', 'статей')
                . ' Вікіпедії (uk: ' . $cnt('uk') . ', en: ' . $cnt('en') . '), напр. ' . self::listSome($titles, 3);
        } else {
            $findings[] = $this->finding('no_wiki_mentions', 'low', 'Жодна стаття uk/en Вікіпедії не посилається на ' . $host, [
                'evidence' => 'Пошук зовнішніх посилань (exturlusage) на ' . $host . ' в основному просторі статей: uk.wikipedia - ' . $cnt('uk') . ', en.wikipedia - ' . $cnt('en')
                    . ($own ? ' (окрім статті про сам бренд: ' . implode(', ', $own) . ')' : '') . '.',
                'why' => 'Посилання з тематичних статей Вікіпедії показують, що сайт вважають джерелом. Вага для ранжування не гарантована (Вікіпедія ставить nofollow, а Google вважає його лише підказкою), але моделі бачать ' . ($this->personal ? 'ваше ім\'я' : 'бренд') . ' у контексті теми.',
                'fix' => 'Не додавайте посилання на свій сайт у Вікіпедію самі - це конфлікт інтересів, такі правки відкочують. ' . ($type === 'media'
                    ? 'Публікуйте ексклюзивні новини, інтерв\'ю й дані, на які посилаються як на джерело.'
                    : ($this->personal
                        ? 'Публікуйте власні дослідження, дані й експертні матеріали, які цитують профільні медіа, - згодом вони стають джерелами для статей Вікіпедії.'
                        : 'Публікуйте власні дослідження, статистику, експертні матеріали, які цитують ЗМІ, - згодом вони стають джерелами для статей Вікіпедії.')),
                'bad' => 'uk.wikipedia.org: ' . $cnt('uk') . ' статей з посиланням на ' . $host . "\nen.wikipedia.org: " . $cnt('en'),
                'good' => 'Кілька тематичних статей цитують ваші дані чи матеріали в розділі «Примітки»',
                'verify' => 'Спеціальна сторінка пошуку посилань: https://uk.wikipedia.org/wiki/Special:LinkSearch?target=' . $host . ' (так само для en.wikipedia.org).',
                'effort' => 'L',
            ]);
        }
        if ($total <= 0) return 0;
        if ($total === 1) return 30;
        if ($total <= 3) return 50;
        if ($total <= 9) return 70;
        if ($total <= 24) return 85;
        return 100;
    }

    // ================================================================== соцмережі / sameAs

    /** Профілі з посилань на головній, «Контакти», «Про нас». */
    private function collectSocial(array $pages, string $type, array $keys): array
    {
        $agg = [];
        foreach ($pages as $role => $p) {
            if (!$p instanceof Page) continue;
            foreach ($p->links() as $l) {
                if (!empty($l['internal'])) continue;
                $s = self::parseSocial((string)$l['href']);
                if (!$s) continue;
                if ($this->personal && $s['kind'] === 'personal') {
                    // особистий профіль LinkedIn (linkedin.com/in/...) - головний профіль експерта
                    $s['kind'] = 'profile'; $s['counted'] = true; $s['sameas'] = true;
                }
                if ($s['platform'] === 'github' && !self::githubCounts($type)) $s['counted'] = false;
                $k = $s['key'];
                if (!isset($agg[$k])) $agg[$k] = $s + ['pages' => [], 'in_nav' => false, 'brandlike' => self::handleLike($s['handle'], $keys)];
                $agg[$k]['pages'][$role] = true;
                if (!empty($l['in_nav'])) $agg[$k]['in_nav'] = true;
            }
        }
        $best = [];
        $extra = [];
        $contacts = [];
        foreach ($agg as $k => $a) {
            if (!$a['counted']) {
                if (in_array($a['kind'], ['phone', 'group', 'bot', 'invite'], true) || ($a['platform'] === 'github')) {
                    $contacts[] = $a['url'] . ($a['kind'] === 'bot' ? ' (бот)' : ($a['kind'] === 'invite' ? ' (запрошення в чат)' : ''));
                }
                continue;
            }
            $accepted = $a['in_nav'] || $a['brandlike'] || count($a['pages']) >= 2 || isset($a['pages']['contact']) || isset($a['pages']['about']) || $type !== 'media';
            if (!$accepted) { $extra[] = $a['url']; continue; }
            $score = ($a['brandlike'] ? 3 : 0) + ($a['in_nav'] ? 2 : 0) + count($a['pages']) + (in_array($a['kind'], ['profile', 'channel_id'], true) ? 1 : 0);
            $pl = $a['platform'];
            if (!isset($best[$pl]) || $score > $best[$pl]['_score']) {
                if (isset($best[$pl])) $extra[] = $best[$pl]['url'];
                $a['_score'] = $score;
                $best[$pl] = $a;
            } else {
                $extra[] = $a['url'];
            }
        }
        $ordered = [];
        foreach (array_keys(self::PLATFORMS) as $pl) if (isset($best[$pl])) $ordered[$pl] = $best[$pl];

        // профілі, оголошені в метатегах головної (twitter:site, article:publisher): не посилання для людей,
        // тому не рахуються, але підставляються в готовий код замість заглушок
        $meta = [];
        $home = $pages['home'] ?? null;
        if ($home instanceof Page) {
            $mt = $home->metaTags();
            foreach (['twitter:site', 'twitter:creator', 'article:publisher'] as $tag) {
                $v = trim((string)($mt[$tag] ?? ''));
                if ($v === '') continue;
                if (strpos($tag, 'twitter:') === 0 && preg_match('/^@?(\w{1,15})$/', $v, $m)) $v = 'https://x.com/' . $m[1];
                $s = self::parseSocial($v);
                if (!$s || !$s['counted'] || !$s['sameas'] || isset($ordered[$s['platform']]) || isset($meta[$s['platform']])) continue;
                if ($s['platform'] === 'github' && !self::githubCounts($type)) continue;
                if (!self::handleLike($s['handle'], $keys)) continue; // шаблонні @username / @wordpress не беремо
                $meta[$s['platform']] = $s + ['tag' => $tag, 'raw' => trim((string)$mt[$tag])];
            }
        }
        return ['best' => $ordered, 'meta' => $meta, 'extra' => array_slice(array_values(array_unique($extra)), 0, 6), 'contacts' => array_slice(array_values(array_unique($contacts)), 0, 4)];
    }

    private function evalSocial(array $social, array $orgInfo, string $type, array $pages, array $pageUrls, array $pageNotes, array &$findings, array &$passed, array &$data): int
    {
        $best = $social['best'];
        // профілі, оголошені лише в sameAs (наприклад, іконки малює JavaScript)
        $sameAsOnly = [];
        foreach ($orgInfo['same_as_parsed'] as $sa) {
            if (!empty($sa['social']) && $sa['social']['counted'] && !isset($best[$sa['social']['platform']])
                && ($sa['social']['platform'] !== 'github' || self::githubCounts($type))) {
                $sameAsOnly[$sa['social']['platform']] = $sa['social']['url'];
            }
        }
        $platforms = array_merge(array_keys($best), array_keys($sameAsOnly));
        $n = count(array_unique($platforms));
        $data['social'] = [];
        foreach ($best as $pl => $a) $data['social'][$pl] = $a['url'];
        foreach ($sameAsOnly as $pl => $u) $data['social'][$pl] = $u . ' (лише sameAs)';
        if ($social['extra']) $data['social_other'] = $social['extra'];
        if ($social['contacts']) $data['social_contacts'] = $social['contacts'];
        $metaOnly = array_diff_key($social['meta'], $sameAsOnly);
        if ($metaOnly) $data['social_meta'] = array_map(function ($a) { return $a['tag'] . ' = ' . $a['raw']; }, $metaOnly);
        $metaS = implode(', ', array_map(function ($a) { return $a['tag'] . ' = ' . $a['raw']; }, $metaOnly));

        $checked = [];
        foreach (['home', 'contact', 'about'] as $r) {
            if (!isset($pageUrls[$r])) continue;
            $checked[] = $this->roleLabel($r) . ($r !== 'home' ? ' (' . $pageUrls[$r] . ')' : '') . (isset($pageNotes[$r]) ? ' - не завантажилась: ' . $pageNotes[$r] : '');
        }
        $checkedS = implode('; ', $checked);

        $names = [];
        foreach ($best as $pl => $a) $names[] = self::PLATFORMS[$pl];
        foreach ($sameAsOnly as $pl => $u) $names[] = self::PLATFORMS[$pl] . ' (лише в sameAs)';

        if ($n > 0) {
            $list = [];
            foreach ($best as $pl => $a) $list[] = self::PLATFORMS[$pl] . ' - ' . self::shortUrl($a['url']);
            $passed[] = 'Сайт посилається на профілі ' . ($this->personal ? 'особи' : 'бренду') . ': ' . self::listSome($list, 7) . ($sameAsOnly ? '; лише в sameAs: ' . implode(', ', array_map(function ($pl) { return self::PLATFORMS[$pl]; }, array_keys($sameAsOnly))) : '');
        }
        if ($n === 0) {
            $home = $pages['home'];
            $words = $home ? $home->wordCount() : 0;
            $spa = $home && ($home->emptyAppRoot() || ($home->frameworkMarkers() && $words < 60));
            $ev = 'Перевірено: ' . $checkedS . '. Посилань на профілі Facebook, Instagram, LinkedIn, YouTube, Telegram, TikTok, X не знайдено.';
            if ($social['contacts']) $ev .= ' Є лише контакти/службові посилання: ' . self::listSome(array_map(function ($u) { return self::shortUrl((string)$u); }, $social['contacts']), 3) . ' - це не профіль бренду.';
            if ($metaS !== '') $ev .= ' У метатегах головної є ' . $metaS . ' - це не посилання для відвідувачів і не замінює їх, але адресу підставлено в код нижче.';
            if ($spa) $ev .= ' Головна віддає в HTML лише ' . $words . ' ' . self::plural($words, 'слово', 'слова', 'слів') . ' (сторінку будує JavaScript): якщо іконки соцмереж з\'являються після рендерингу, боти без JS їх не бачать.';
            elseif ($home && $words < 60) $ev .= ' На головній загалом лише ' . $words . ' ' . self::plural($words, 'слово', 'слова', 'слів') . ' видимого тексту.';
            $who = $this->personal ? 'особи' : 'бренду';
            $text = $spa ? 'У HTML без JavaScript немає посилань на профілі ' . $who . ' в соцмережах'
                : 'На перевірених сторінках немає посилань на профілі ' . $who . ' в соцмережах';
            $findings[] = $this->finding('no_social', 'medium', $text, [
                'evidence' => $ev,
                'why' => $this->personal
                    ? 'Профілі (LinkedIn, GitHub, YouTube, Telegram) - незалежні точки, за якими AI-системи зіставляють особу: ім\'я, фото, посаду, досвід. Без посилань із сайту зв\'язок між сайтом і профілями доводиться вгадувати.'
                    : 'Профілі в соцмережах - незалежні точки, за якими AI-системи зіставляють бренд (назва, логотип, опис, контакти). Без посилань із сайту зв\'язок між сайтом і профілями доводиться вгадувати.',
                'fix' => ($spa ? 'Виведіть у серверному HTML (футер або статичний блок поза JS-застосунком) звичайні посилання <a href> на офіційні профілі, які ви реально ведете'
                        : 'Додайте у футер звичайні посилання <a href> на офіційні профілі, які ви реально ведете')
                    . ' (порожні профілі заради кількості не потрібні), і продублюйте їх у sameAs розмітки ' . ($this->personal ? 'Person' : 'Organization') . '.',
                'bad' => 'Посилань на соцмережі на перевірених сторінках: 0' . ($metaS !== '' ? "
У метатегах: " . $metaS : ''),
                'good' => 'Футер з посиланнями на 3-5 активних профілів (' . ($type === 'saas' ? 'LinkedIn, X, GitHub, YouTube' : ($this->personal ? 'LinkedIn, YouTube, Telegram, GitHub' : 'Facebook, Instagram, LinkedIn, YouTube, Telegram-канал')) . ') і ті самі URL у sameAs',
                'code' => self::footerSnippet($best, $type, $metaOnly),
                'verify' => 'Відкрийте вихідний код сторінки (Ctrl+U) і знайдіть посилання на профілі - вони мають бути в HTML без виконання JavaScript.',
                'effort' => 'S',
            ]);
            return 0;
        }
        if ($n <= 2) {
            $pool = $type === 'saas' ? ['linkedin' => 'LinkedIn', 'x' => 'X', 'github' => 'GitHub', 'youtube' => 'YouTube']
                : ($this->personal ? ['linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'telegram' => 'Telegram-канал', 'x' => 'X']
                : (in_array($type, ['services', 'corporate'], true) ? ['linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'telegram' => 'Telegram-канал', 'facebook' => 'Facebook']
                : ($type === 'media' ? ['telegram' => 'Telegram-канал', 'youtube' => 'YouTube', 'x' => 'X', 'facebook' => 'Facebook']
                : ['instagram' => 'Instagram', 'facebook' => 'Facebook', 'telegram' => 'Telegram-канал', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'])));
            foreach (array_unique($platforms) as $pl) unset($pool[$pl]);
            $suggest = implode(', ', array_slice($pool, 0, 3));
            $findings[] = $this->finding('few_social', 'low', 'Сайт посилається лише на ' . $n . ' ' . self::plural($n, 'профіль', 'профілі', 'профілів') . ' у соцмережах', [
                'evidence' => 'Знайдено: ' . implode(', ', $names) . '. Перевірено: ' . $checkedS . '.'
                    . ($social['contacts'] ? ' Не враховано (контакт, а не профіль бренду): ' . self::listSome(array_map(function ($u) { return self::shortUrl((string)$u); }, $social['contacts']), 3) . '.' : '')
                    . ($metaS !== '' ? ' У метатегах головної: ' . $metaS . ' (посиланням не рахується).' : ''),
                'why' => 'Кожен пов\'язаний профіль - ще одне незалежне підтвердження сутності бренду для AI. Один-два профілі - слабкий сигнал, якщо бренд насправді присутній і в інших мережах.',
                'fix' => 'Якщо бренд веде інші профілі (для вашого типу сайту зазвичай: ' . $suggest . '), додайте посилання на них у футер і в sameAs. Створювати порожні профілі заради кількості не варто.',
                'bad' => 'Профілі на сайті: ' . implode(', ', $names),
                'good' => 'Профілі на сайті: ' . implode(', ', $names) . ' + ' . $suggest . ' (якщо ведете)',
                'code' => self::footerSnippet($best, $type, $metaOnly),
                'verify' => 'Вихідний код футера (Ctrl+U) містить посилання на всі активні профілі.',
                'effort' => 'S',
            ]);
        }
        if ($n >= 4) return 100;
        return [1 => 30, 2 => 55, 3 => 80][$n];
    }

    private function evalSameAs(array $social, array $orgInfo, array $wm, string $brand, string $homeUrl, array $hosts, string $type, Page $home, array &$findings, array &$passed, array &$data): ?int
    {
        $main = $orgInfo['main'];
        $parsed = $orgInfo['same_as_parsed'];
        $expected = []; // key => [url, label, kind, platform, a]
        foreach ($social['best'] as $pl => $a) {
            if ($a['sameas']) $expected[$a['key']] = ['url' => $a['url'], 'label' => self::PLATFORMS[$pl], 'kind' => 'social', 'platform' => $pl, 'a' => $a];
        }
        $ver = $wm['verified'];
        if ($ver) $expected['wikidata:' . $ver['id']] = ['url' => 'https://www.wikidata.org/wiki/' . $ver['id'], 'label' => 'Wikidata', 'kind' => 'wikidata', 'platform' => 'wikidata', 'a' => null];

        $have = [];
        $havePlatforms = []; // platform => [parsed sameAs, ...]
        $invalid = [];
        foreach ($parsed as $sa) {
            if ($sa['invalid'] !== '') { $invalid[] = $sa['raw'] . ' - ' . $sa['invalid']; continue; }
            if ($sa['key'] !== '') { $have[$sa['key']] = true; if ($sa['platform'] !== '') $havePlatforms[$sa['platform']][] = $sa; }
        }
        $missing = [];
        $mismatch = [];
        $chosen = []; // platform => [url, звідки]
        foreach ($expected as $k => $e) {
            if (isset($have[$k])) continue;
            if ($e['kind'] === 'social' && !empty($havePlatforms[$e['platform']])) {
                $pl = $e['platform'];
                $wdVal = $ver ? (string)($ver['social'][$pl] ?? '') : '';
                $chosen[$pl] = self::pickProfile($pl, $e['a'], $havePlatforms[$pl], $wdVal);
                $mismatch[] = $e['label'] . ': у sameAs ' . self::listSome(array_column($havePlatforms[$pl], 'raw'), 2) . ', а на сайті - ' . $e['url']
                    . (self::legacyProfile($pl, $e['a']) ? ' (застаріла адреса facebook.com/pages/...)' : '')
                    . ($wdVal !== '' ? ', у Wikidata - ' . $wdVal : '');
            }
            $missing[$k] = $e;
        }
        $valid = array_values(array_filter($parsed, function ($sa) { return $sa['invalid'] === ''; }));
        $data['same_as'] = ['org_found' => (bool)$main, 'values' => array_slice(array_column($parsed, 'raw'), 0, 12), 'missing' => array_values(array_column($missing, 'url')), 'invalid' => $invalid];
        if ($chosen) $data['same_as']['conflicts'] = array_map(function ($c) { return $c[0] . ' (' . $c[1] . ')'; }, $chosen);

        // ---- готовий код: одна адреса на платформу, де є розбіжність
        $merged = [];
        $mergedPl = [];
        foreach ($valid as $sa) {
            if ($sa['platform'] !== '' && isset($chosen[$sa['platform']])) continue;
            $val = ($sa['social'] && strpos($sa['raw'], '?') !== false) ? $sa['social']['url'] : $sa['raw']; // без трекінгових параметрів
            $merged[$sa['key'] !== '' ? $sa['key'] : $sa['raw']] = $val;
            if ($sa['platform'] !== '') $mergedPl[$sa['platform']] = true;
        }
        foreach ($expected as $k => $e) {
            if ($e['kind'] === 'social' && isset($chosen[$e['platform']])) {
                $merged['pick:' . $e['platform']] = $chosen[$e['platform']][0];
            } elseif (!isset($merged[$k])) {
                $merged[$k] = $e['url'];
            }
            $mergedPl[$e['platform']] = true;
        }
        foreach ($social['meta'] as $pl => $a) {
            if (!isset($mergedPl[$pl])) { $merged[$a['key']] = $a['url']; $mergedPl[$pl] = true; }
        }
        if ($ver) {
            foreach (['ukwiki' => 'uk', 'enwiki' => 'en'] as $sk => $l) {
                if ($ver['sitelinks'][$sk]) {
                    $wu = 'https://' . $l . '.wikipedia.org/wiki/' . str_replace(' ', '_', (string)$ver['sitelinks'][$sk]);
                    $exists = false;
                    foreach ($merged as $mv) if (strcasecmp(rawurldecode((string)$mv), $wu) === 0) $exists = true;
                    if (!$exists) $merged['wp:' . $l] = $wu;
                }
            }
        }
        $code = $this->personal ? self::personSnippet($main, $brand, $homeUrl, array_values($merged), $home)
            : self::orgSnippet($main, $brand, $homeUrl, $type, array_values($merged), $home);
        $ent = $this->personal ? 'Person' : 'Organization';
        $metaNote = '';
        $metaAdded = array_filter($social['meta'], function ($a) use ($merged) { return in_array($a['url'], $merged, true); });
        if ($metaAdded) $metaNote = ' Профілі з метатегів (' . implode(', ', array_map(function ($a) { return $a['tag'] . ' = ' . $a['raw']; }, $metaAdded)) . ') додано в код, але на сайті на них немає звичайних посилань.';
        $pickNote = '';
        if ($chosen) {
            $pp = [];
            foreach ($chosen as $pl => $c) $pp[] = self::PLATFORMS[$pl] . ' - ' . $c[0] . ' (' . $c[1] . ')';
            $pickNote = ' Для платформ із розбіжностями в коді лишено одну адресу: ' . implode('; ', $pp) . '. Перед вставкою підтвердіть, який акаунт офіційний, і приберіть інший із sameAs і з сайту.';
        }

        $innerNote = '';
        if ($main && (($main['id'] !== '' && !self::homeLike($main['id'], $homeUrl, $home->url())) || ($main['url'] !== '' && !self::homeLike($main['url'], $homeUrl, $home->url())))) {
            $innerNote = ' Наявний вузол прив\'язаний до внутрішньої сторінки (' . ($main['id'] !== '' ? '@id ' . $main['id'] : 'url ' . $main['url']) . '): для бренду в коді вказано @id і url головної.';
        }
        $verify = 'validator.schema.org або Google Rich Results Test для ' . $homeUrl . ': у вузлі ' . ($main ? $main['types'][0] : $ent) . ' є sameAs з усіма URL профілів' . ($ver ? ' і Wikidata' : '') . '.';
        $why = $this->personal
            ? 'sameAs у розмітці Person прямо каже пошуковим і AI-системам, що профілі (LinkedIn, GitHub, соцмережі)' . ($ver ? ' і елемент Wikidata' : '') . ' належать саме цій людині. Це основний машинний спосіб зв\'язати сайт з особою.'
            : 'sameAs у розмітці Organization прямо каже пошуковим і AI-системам, що профілі в соцмережах' . ($ver ? ' і елемент Wikidata' : '') . ' належать саме цій компанії. Це основний машинний спосіб зв\'язати сайт із сутністю бренду.';

        if (!$main && $this->personal) {
            if (!$expected) return null;
            $typesHome = $home->schemaTypes();
            $findings[] = $this->finding('same_as_missing', 'medium', 'Немає розмітки Person із sameAs', [
                'evidence' => 'На перевірених сторінках немає вузла Person для ' . $brand . ', тож посилання на ' . self::listSome(array_column($expected, 'label'), 7) . ' не зв\'язані з сайтом машинно.' . $metaNote,
                'why' => $why,
                'fix' => 'Додайте на головну JSON-LD ProfilePage з mainEntity -> Person: name, url, image, jobTitle і sameAs. Повний набір полів Person перевіряє категорія «Структуровані дані».',
                'bad' => 'JSON-LD на головній: ' . ($typesHome ? self::listSome($typesHome, 8) : 'немає жодного типу'),
                'good' => 'Person «' . $brand . '» з sameAs: ' . self::listSome(array_map(function ($u) { return self::shortUrl((string)$u); }, array_values($merged)), 8),
                'code' => $code,
                'verify' => $verify,
                'effort' => 'S',
            ]);
            return 0;
        }
        if (!$main) {
            if (!$expected) return null; // немає що зв'язувати
            $typesHome = $home->schemaTypes();
            $others = array_values(array_filter(array_map(function ($n) { return $n['name']; }, $orgInfo['all']), 'strlen'));
            $stubs = [];
            foreach ($orgInfo['stubs'] as $n) $stubs[] = self::ROLE_LABELS[$n['role']] . ($n['id'] !== '' ? ', @id ' . $n['id'] : ($n['url'] !== '' ? ', url ' . $n['url'] : ''));
            $hasSocialExp = count(array_filter($expected, function ($e) { return $e['kind'] === 'social'; })) > 0;
            $findings[] = $this->finding('same_as_missing', $hasSocialExp ? 'medium' : 'low', 'Немає розмітки Organization із sameAs', [
                'evidence' => 'На перевірених сторінках немає вузла Organization/LocalBusiness для бренду' . ($others ? ' (є Organization з іншою назвою: ' . self::listSome(array_unique($others), 3) . ')' : '') . ', тож посилання на '
                    . self::listSome(array_column($expected, 'label'), 7) . ' не зв\'язані з сайтом машинно.'
                    . ($stubs ? ' Є лише вкладений фрагмент Organization без name (' . self::listSome(array_unique($stubs), 2) . ') - як опис бренду він не годиться.' : '') . $metaNote,
                'why' => $why,
                'fix' => 'Додайте на всі сторінки (або хоча б на головну) JSON-LD Organization з name, url, logo і sameAs. Повний набір полів Organization перевіряє категорія «Структуровані дані».',
                'bad' => 'JSON-LD на головній: ' . ($typesHome ? self::listSome($typesHome, 8) : 'немає жодного типу'),
                'good' => 'Organization «' . $brand . '» з name, url, logo і sameAs: ' . self::listSome(array_map(function ($u) { return self::shortUrl((string)$u); }, array_values($merged)), 8),
                'code' => $code,
                'verify' => $verify,
                'effort' => 'S',
            ]);
            return 0;
        }

        $cur = array_column($parsed, 'raw');
        $curS = $cur ? '"sameAs": ' . self::jsonPretty(array_slice($cur, 0, 10)) : $ent . ' «' . $main['name'] . '» (' . $main['types'][0] . ') без властивості sameAs';
        if (!$expected) {
            if ($valid) $passed[] = 'sameAs в ' . $ent . ' містить ' . count($valid) . ' ' . self::plural(count($valid), 'посилання', 'посилання', 'посилань');
            $score = null; // нема з чим звіряти, але й не мінус
        } else {
            $covered = count($expected) - count($missing);
            $score = (int)round(100 * $covered / count($expected));
            if (!$missing) {
                $passed[] = 'sameAs в ' . $ent . ' містить усі знайдені профілі' . ($ver ? ' і Wikidata' : '') . ' (' . count($expected) . ' з ' . count($expected) . ')';
            } else {
                $onlyRefs = count(array_filter($missing, function ($e) { return $e['kind'] === 'social'; })) === 0;
                $sev = $onlyRefs ? 'low' : 'medium';
                // особистий сайт: sameAs у Person уже є - це не «відсутність», а лише неповний перелік
                if ($this->personal && $cur) $sev = 'low';
                if (!$cur) {
                    $text = 'У розмітці ' . $ent . ' немає sameAs';
                } elseif ($onlyRefs) {
                    $text = 'sameAs не містить посилання на елемент Wikidata';
                } else {
                    $text = 'sameAs містить не всі профілі ' . ($this->personal ? 'особи' : 'бренду') . ' (' . $covered . ' з ' . count($expected) . ')';
                }
                $ev = $ent . ' «' . $main['name'] . '» (' . implode(', ', $main['types']) . ', ' . $this->roleLabel($main['role']) . '). Немає в sameAs: '
                    . implode(', ', array_map(function ($e) { return $e['label'] . ' ' . $e['url']; }, $missing)) . '.';
                if ($mismatch) $ev .= ' Розбіжності: ' . implode('; ', $mismatch) . '.';
                if ($invalid) $ev .= ' Некоректні значення: ' . implode('; ', $invalid) . '.';
                $ev .= $metaNote;
                $findings[] = $this->finding('same_as_missing', $sev, $text, [
                    'evidence' => $ev,
                    'why' => $why,
                    'fix' => 'Додайте у властивість sameAs наявного блоку ' . $ent . ' усі URL нижче (замініть масив повністю)' . ($invalid ? ' і приберіть некоректні значення' : '') . '.' . $pickNote . $innerNote,
                    'bad' => $curS,
                    'good' => '"sameAs": ' . self::jsonPretty(array_values($merged)),
                    'code' => $code,
                    'verify' => $verify,
                    'effort' => 'S',
                ]);
                $invalid = []; // уже враховано
            }
        }
        if ($invalid) {
            $findings[] = $this->finding('same_as_invalid', 'low', 'У sameAs є некоректні значення', [
                'evidence' => implode('; ', $invalid) . '.',
                'why' => 'sameAs має містити лише URL публічних профілів і довідкових сторінок про компанію. Інші значення валідатори ігнорують, а посилання на власний сайт нічого не додає.',
                'fix' => 'Залиште в sameAs тільки https-посилання на профілі бренду, Wikidata і Вікіпедію.',
                'bad' => $curS,
                'good' => '"sameAs": ' . self::jsonPretty(array_values($merged)),
                'code' => $code,
                'verify' => $verify,
                'effort' => 'S',
            ]);
            if ($score !== null) $score = max(0, $score - 10);
        }
        return $score;
    }

    /** facebook.com/pages/<назва>/<id> - стара форма адреси сторінки, Facebook перенаправляє її на актуальну. */
    private static function legacyProfile(string $pl, ?array $a): bool
    {
        return $pl === 'facebook' && $a !== null && strpos((string)$a['handle'], '/') !== false;
    }

    /**
     * Одна адреса для платформи, де sameAs і посилання на сайті ведуть на різні акаунти:
     * збіг з ідентифікатором Wikidata -> посилання з сайту (якщо не застаріле) -> Wikidata -> наявний sameAs.
     * @return array{0:string,1:string} [url, звідки]
     */
    private static function pickProfile(string $pl, array $siteA, array $saList, string $wdVal): array
    {
        $wd = strtolower(trim($wdVal));
        $cands = [['url' => $siteA['url'], 'a' => $siteA, 'src' => 'посилання з сайту']];
        foreach ($saList as $sa) {
            $cands[] = ['url' => ($sa['social'] && strpos($sa['raw'], '?') !== false) ? $sa['social']['url'] : $sa['raw'], 'a' => $sa['social'], 'src' => 'з наявного sameAs'];
        }
        if ($wd !== '') {
            foreach ($cands as $c) {
                $v = $c['a'] ? self::wdSocialValue($pl, $c['a']) : null;
                if ($v !== null && strtolower($v) === $wd) return [$c['url'], $c['src'] . ', збігається з Wikidata'];
            }
        }
        if (!self::legacyProfile($pl, $siteA)) return [$siteA['url'], 'посилання з сайту'];
        if ($wd !== '' && ($u = self::wdSocialUrl($pl, $wdVal)) !== '') return [$u, 'з Wikidata; посилання на сайті - застаріла адреса'];
        foreach ($saList as $sa) if ($sa['social'] && !self::legacyProfile($pl, $sa['social'])) return [$sa['raw'], 'з наявного sameAs; посилання на сайті - застаріла адреса'];
        return [$siteA['url'], 'посилання з сайту'];
    }

    // ================================================================== узгодженість назви

    private function evalConsistency(Page $home, array $orgInfo, string $core, string $brand, string $homeUrl, array &$findings, array &$passed, array &$data): int
    {
        $title = $home->title();
        $og = trim((string)$home->og('site_name'));
        $orgName = $orgInfo['main']['name'] ?? '';
        $orgKey = $this->personal ? 'Person.name' : 'Organization.name';
        $wsName = '';
        foreach ($home->schemaItemsOfType(['WebSite'], false) as $it) {
            $n = self::firstString($it['data']['name'] ?? null);
            if ($n !== '') { $wsName = $n; break; }
        }
        $data['names'] = ['title' => self::mbTrunc($title, 120), 'og_site_name' => $og, 'organization' => $orgName, 'website' => $wsName];
        $bk = self::normKey($core);
        $tk = self::normKey($title);
        $inTitle = $bk !== '' && strpos($tk, $bk) !== false;
        // скорочена назва («mono» для monobank, «Varvar» для Varvar Brew): окреме слово title, що є початком назви бренду
        $short = '';
        if (!$inTitle && $bk !== '' && $title !== '') {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
                $k = self::normKey($tok);
                if (mb_strlen($tok) >= 4 && strlen($k) >= 4 && strlen($k) * 2 >= strlen($bk) && strpos($bk, $k) === 0) { $short = $tok; break; }
            }
        }

        $issues = [];
        $score = 100;
        if ($og === '') {
            $score -= $wsName !== '' ? 10 : 30;
            $issues[] = 'og:site_name: немає' . ($wsName !== '' ? ' (є WebSite.name «' . $wsName . '»)' : '');
        }
        if (!$inTitle && $short !== '') {
            $score -= 10;
            $passed[] = 'У title головної є скорочена назва бренду «' . $short . '» (повна - «' . $core . '»)';
        } elseif (!$inTitle) {
            $score -= 40;
            $issues[] = $title === '' ? 'title головної порожній' : 'title головної «' . self::mbTrunc($title, 110) . '» - назви «' . $core . '» немає';
        }
        // назви в метаданих: однакові (ключ збігається), одна містить іншу з доповненням (слоган, опис, юр. форма) або різні
        $named = array_filter(['og:site_name' => $og, $orgKey => $orgName, 'WebSite.name' => $wsName], 'strlen');
        $mism = [];
        $partial = [];
        $pairs = array_keys($named);
        for ($i = 0; $i < count($pairs); $i++) {
            for ($j = $i + 1; $j < count($pairs); $j++) {
                $a = $named[$pairs[$i]];
                $b = $named[$pairs[$j]];
                if (self::sameName($a, $b)) continue;
                if (self::namesMatch($a, $b)) {
                    [$lp, $sp] = mb_strlen($a) >= mb_strlen($b) ? [$pairs[$i], $pairs[$j]] : [$pairs[$j], $pairs[$i]];
                    $partial[] = $lp . ' «' . $named[$lp] . '» = ' . $sp . ' «' . $named[$sp] . '» + додаткові слова';
                } else {
                    $mism[] = $pairs[$i] . ' «' . $a . '» ≠ ' . $pairs[$j] . ' «' . $b . '»';
                }
            }
        }
        if ($mism) {
            $score -= 30;
            $issues[] = 'різні назви: ' . implode('; ', $mism);
        }
        if ($partial) {
            $score -= 10;
            $issues[] = 'назва з доповненням (слоган, опис або юридична форма): ' . implode('; ', $partial);
        }
        $score = max(0, $score);

        if ($og !== '' && self::sameName($og, $core)) $passed[] = 'og:site_name «' . $og . '» відповідає назві бренду';
        elseif ($og !== '' && !$partial && self::namesMatch($og, $core)) $passed[] = 'og:site_name «' . $og . '» містить назву бренду «' . $core . '»';
        if ($inTitle) $passed[] = 'Назва бренду «' . $core . '» є в title головної';
        if (count($named) >= 2 && !$mism && !$partial) {
            $vals = [];
            foreach ($named as $f => $v) $vals[] = $f . ' «' . $v . '»';
            $passed[] = 'Назва бренду однакова в метаданих: ' . implode(', ', $vals);
        }

        if ($issues) {
            $cur = "<title>" . self::mbTrunc($title, 140) . "</title>\n"
                . ($og !== '' ? '<meta property="og:site_name" content="' . $og . '">' : '<!-- og:site_name немає -->')
                . ($orgName !== '' ? "\n" . $orgKey . ': «' . $orgName . '»' : '')
                . ($wsName !== '' ? "\nWebSite.name: «" . $wsName . '»' : '');
            $main = $orgName !== '' ? $orgName : ($og !== '' ? $og : $core);
            if ($partial) {
                // основна назва - найкоротша з однойменних полів (без слогана)
                foreach ($named as $v) if (self::namesMatch($v, $main) && mb_strlen($v) < mb_strlen($main)) $main = $v;
            }
            $alt = [];
            foreach ($named as $v) if (!self::namesMatch($v, $main)) $alt[] = $v; // інше написання (латиниця / кирилиця), не слоган
            $alt = array_values(array_unique($alt));
            $ws = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $main, 'url' => $homeUrl];
            if ($alt) $ws['alternateName'] = count($alt) === 1 ? $alt[0] : $alt;
            $needOg = $og === '' || !self::sameName($og, $main);
            $needWs = $wsName === '' || !self::sameName($wsName, $main);
            $code = ($needOg ? '<meta property="og:site_name" content="' . htmlspecialchars($main, ENT_QUOTES) . '">' : '')
                . ($needWs ? ($needOg ? "\n\n" : '') . self::jsonLdScript($ws) : '')
                . ($orgName !== '' && !self::sameName($orgName, $main) ? "\n\n<!-- " . $orgKey . ': «' . htmlspecialchars($main, ENT_NOQUOTES) . '» -->' : '');
            $findings[] = $this->finding('name_consistency', 'low', 'Назва бренду в метаданих неповна або непослідовна', [
                'evidence' => implode('; ', $issues) . '.',
                'why' => 'Google визначає назву сайту насамперед з WebSite.name, а також з og:site_name, title, заголовків і згадок сайту в інтернеті. Одна стабільна назва в усіх полях допомагає зіставити сайт, профілі й згадки як одну сутність.',
                'fix' => 'Використовуйте одну основну назву «' . $main . '» в og:site_name, ' . $orgKey . ', WebSite.name і в title головної'
                    . ($partial ? '; слоган чи опис лишіть у title після назви, а не в полях назви' : '')
                    . ($alt ? '; інші варіанти написання (латиницею / кирилицею) - у alternateName' : '') . '.',
                'bad' => $cur,
                'good' => 'og:site_name, ' . $orgKey . ', WebSite.name = «' . $main . '», title головної містить «' . $main . '»' . ($alt ? ', alternateName: ' . implode(', ', $alt) : ''),
                'code' => $code,
                'verify' => 'Перегляньте <head> головної (Ctrl+U) і перевірте WebSite у validator.schema.org; назва сайту в Google оновлюється після переобходу.',
                'effort' => 'S',
            ]);
        }
        return $score;
    }

    // ================================================================== локальний бізнес

    private function evalLocal(array $pages, array $pageUrls, array $pageNotes, array $orgInfo, string $brand, array &$findings, array &$passed, array &$data): ?int
    {
        $links = [];
        $embeds = [];
        $placeEmbed = false;
        foreach ($pages as $role => $p) {
            if (!$p instanceof Page) continue;
            foreach ($p->links() as $l) {
                if (self::isMapsLink((string)$l['href']) && !isset($links[(string)$l['href']])) $links[(string)$l['href']] = $role;
            }
            foreach ($p->iframes() as $f) {
                if (preg_match('#^https?://(www\.|maps\.)?google\.[a-z.]+/maps(/embed|\?)|^https?://maps\.google\.[a-z.]+/#i', $f) && preg_match('#embed|output=embed#i', $f)) {
                    if (!isset($embeds[$f])) $embeds[$f] = $role;
                    if (preg_match('/!1s0x[0-9a-f]+:0x[0-9a-f]+|place_id|[?&]cid=|!1s(ChIJ|0x)/i', $f)) $placeEmbed = true;
                }
            }
        }
        foreach ($orgInfo['map_urls'] as $u) if (!isset($links[$u])) $links[$u] = 'schema';
        $data['maps'] = ['links' => array_slice(array_keys($links), 0, 4), 'embeds' => count($embeds)];

        if ($links) {
            $first = array_key_first($links);
            $where = $links[$first] === 'schema' ? 'розмітка hasMap/sameAs' : self::ROLE_LABELS[$links[$first]] ?? $links[$first];
            $passed[] = 'Сайт посилається на Google Maps / Business Profile: ' . self::shortUrl($first) . ' (' . $where . ')' . (count($links) > 1 ? ', усього посилань: ' . count($links) : '');
            return 100;
        }
        $checked = [];
        foreach (['home', 'contact', 'about'] as $r) if (isset($pageUrls[$r])) $checked[] = self::ROLE_LABELS[$r] . ($r !== 'home' ? ' (' . $pageUrls[$r] . ')' : '') . ($pages[$r] ? '' : ' - не завантажилась');
        $main = $orgInfo['main'] ?? null;
        $homeUrl = (string)($pageUrls['home'] ?? '');
        $lb = ['@context' => 'https://schema.org', '@type' => $main ? (string)$main['types'][0] : 'LocalBusiness'];
        // hasMap додається в наявний вузол, тож його @id/url лишаємо (url філії може бути сторінкою філії)
        if ($main && $main['id'] !== '') $lb['@id'] = $main['id'];
        elseif ($homeUrl !== '') $lb['@id'] = rtrim(Crawler::baseUrl($homeUrl), '/') . '/#organization';
        $lb['name'] = ($main && $main['name'] !== '') ? $main['name'] : ($brand !== '' ? $brand : '[ВКАЖІТЬ назву як у картці]');
        if ($homeUrl !== '') $lb['url'] = ($main && $main['url'] !== '') ? $main['url'] : Crawler::baseUrl($homeUrl) . '/';
        $lb['hasMap'] = 'https://maps.app.goo.gl/[ВКАЖІТЬ посилання на картку]';
        $code = '<a href="https://maps.app.goo.gl/[ВКАЖІТЬ посилання з кнопки «Поділитися» в картці]" target="_blank" rel="noopener">Ми на Google Maps</a>'
            . "\n\n<!-- hasMap додайте в наявний блок " . $lb['@type'] . ", а не окремим дублем -->\n" . self::jsonLdScript($lb);
        $contactFailed = isset($pageUrls['contact']) && !($pages['contact'] ?? null);
        if ($embeds) {
            $passed[] = 'На сайті є вбудована карта Google';
            if ($placeEmbed) return 85;
            $findings[] = $this->finding('maps_embed_only', 'low', 'Є лише вбудована карта за координатами, без посилання на картку компанії', [
                'evidence' => 'Перевірено: ' . implode('; ', $checked) . '. Знайдено iframe Google Maps (' . self::mbTrunc((string)array_key_first($embeds), 120) . '), але немає посилання на картку Business Profile (maps.app.goo.gl, g.page, google.com/maps?cid=).',
                'why' => 'Вбудована карта за координатами показує місце, але не вказує на конкретну картку компанії з відгуками і годинами роботи. Пряме посилання допомагає пов\'язати сайт і картку як одну сутність.',
                'fix' => 'Додайте поруч з картою посилання на картку (кнопка «Поділитися» в Google Maps) і властивість hasMap у LocalBusiness.',
                'bad' => '<iframe src="' . self::mbTrunc((string)array_key_first($embeds), 140) . '">',
                'good' => '<a href="https://maps.app.goo.gl/...">Ми на Google Maps</a> + "hasMap" у ' . $lb['@type'],
                'code' => $code,
                'verify' => 'Посилання відкриває саме картку компанії в Google Maps; у validator.schema.org LocalBusiness має hasMap.',
                'effort' => 'S',
            ]);
            return 60;
        }
        if ($contactFailed) {
            // сторінка контактів (де зазвичай і є карта) не завантажилась - відсутність не підтверджена
            $findings[] = $this->finding('no_maps_link', 'low', 'Посилання на картку Google Maps не знайдено (сторінку контактів перевірити не вдалося)', [
                'evidence' => 'Перевірено: ' . implode('; ', $checked) . ' (' . ($pageNotes['contact'] ?? 'помилка завантаження') . '). На доступних сторінках посилань на Google Maps / Business Profile і вбудованої карти немає; у розмітці немає hasMap. Показник «н/д», у бал не враховано.',
                'why' => 'Google радить для AI Overviews і AI Mode тримати дані Business Profile актуальними (адреса, години роботи, категорії). Взаємні посилання сайту й картки - усталена практика локального SEO: так простіше зв\'язати їх як одну сутність.',
                'fix' => 'Перевірте сторінку контактів вручну. Якщо посилання немає - додайте «Ми на Google Maps» (у картці: «Поділитися» - дає maps.app.goo.gl/...) і властивість hasMap у розмітці.',
                'bad' => 'Посилань на Google Maps на доступних сторінках: 0; «Контакти» (' . $pageUrls['contact'] . ') - не завантажилась',
                'good' => '<a href="https://maps.app.goo.gl/...">Ми на Google Maps</a> на сторінці контактів + "hasMap" у ' . $lb['@type'],
                'code' => $code,
                'verify' => 'Відкрийте ' . $pageUrls['contact'] . ' і перевірте, що посилання веде саме на картку компанії в Google Maps.',
                'effort' => 'S',
            ]);
            return null;
        }
        $findings[] = $this->finding('no_maps_link', 'medium', 'Сайт не посилається на картку компанії в Google Maps', [
            'evidence' => 'Перевірено: ' . implode('; ', $checked) . '. Посилань на Google Maps / Business Profile (maps.app.goo.gl, google.com/maps, g.page) і вбудованої карти не знайдено; у розмітці немає hasMap.'
                . (!isset($pageUrls['contact']) ? ' Сторінку контактів автоматично не знайдено - перевірено лише перелічені сторінки.' : ''),
            'why' => 'Google радить для AI Overviews і AI Mode тримати дані Business Profile актуальними (адреса, години роботи, категорії). Взаємні посилання сайту й картки - усталена практика локального SEO: так простіше зв\'язати їх як одну сутність.',
            'fix' => 'Додайте на сторінку контактів посилання «Ми на Google Maps» (у картці: «Поділитися» - дає maps.app.goo.gl/...) і властивість hasMap у розмітці LocalBusiness. Переконайтеся, що в картці вказано цей сайт.',
            'bad' => 'Посилань на Google Maps на перевірених сторінках: 0',
            'good' => '<a href="https://maps.app.goo.gl/...">Ми на Google Maps</a> на сторінці контактів + "hasMap" у ' . $lb['@type'],
            'code' => $code,
            'verify' => 'Посилання відкриває саме картку компанії; у Google Business Profile у полі «Веб-сайт» - ' . ($pageUrls['home'] ?? 'ваш сайт') . '.',
            'effort' => 'S',
        ]);
        return 0;
    }

    // ================================================================== Organization

    /**
     * Вузли Organization (з підтипами) бренду з головної, «Про нас», «Контакти».
     * Вузли з однаковим @id зливаються (JSON-LD описує одну сутність кількома фрагментами).
     * Брендом вважається лише вузол з name; вкладений (не верхнього рівня / не з @graph) - тільки якщо name збігається з брендом.
     * Безіменні фрагменти (напр. worksFor-заглушка всередині BreadcrumbList) - у 'stubs'.
     */
    private function orgNodes(array $pages, array $keys, array $hosts): array
    {
        $groups = [];
        $anon = 0;
        foreach (['home', 'about', 'contact'] as $role) {
            $p = $pages[$role] ?? null;
            if (!$p instanceof Page) continue;
            $top = self::topLevelHashes($p);
            foreach ($p->schemaItemsOfType(['Organization']) as $it) {
                $d = is_array($it['data'] ?? null) ? $it['data'] : [];
                $name = self::firstString($d['name'] ?? null);
                $u = self::firstString($d['url'] ?? null);
                $id = is_string($d['@id'] ?? null) ? trim($d['@id']) : '';
                $idAbs = $id !== '' ? (Crawler::absolutize($id, $p->url(), false) ?? '') : '';
                $uAbs = $u !== '' ? (Crawler::absolutize($u, $p->url(), false) ?? '') : '';
                $isTop = ($it['source'] ?? '') !== 'json-ld' || isset($top[md5(serialize($d))]);
                $node = [
                    'role' => $role, 'types' => $it['types'], 'name' => $name, 'url' => $uAbs, 'id' => $idAbs, 'same_as' => self::flattenStrings($d['sameAs'] ?? null),
                    'logo' => self::logoUrl($d['logo'] ?? null, $p->url()), 'founding' => self::firstString($d['foundingDate'] ?? null),
                    'localities' => self::localities($d['address'] ?? null), 'has_map' => self::flattenStrings($d['hasMap'] ?? null), 'top' => $isTop,
                ];
                $gk = $idAbs !== '' ? 'id:' . strtolower($idAbs) : 'anon:' . ($anon++);
                if (!isset($groups[$gk])) { $groups[$gk] = $node; continue; }
                $g = &$groups[$gk];
                if (($g['name'] === '' && $node['name'] !== '') || ($node['top'] && !$g['top'])) $g['role'] = $node['role'];
                foreach (['name', 'url', 'logo', 'founding'] as $f) if ($g[$f] === '' && $node[$f] !== '') $g[$f] = $node[$f];
                $g['same_as'] = array_values(array_unique(array_merge($g['same_as'], $node['same_as'])));
                $g['has_map'] = array_values(array_unique(array_merge($g['has_map'], $node['has_map'])));
                $g['localities'] = array_values(array_unique(array_merge($g['localities'], $node['localities'])));
                $g['types'] = array_values(array_unique(array_merge($g['types'], $node['types'])));
                $g['top'] = $g['top'] || $node['top'];
                unset($g);
            }
        }
        $all = [];
        $stubs = [];
        foreach ($groups as $n) {
            if ($n['name'] === '') { $stubs[] = $n; continue; }
            $nameOk = self::nameMatchesKeys($n['name'], $keys);
            $score = 0;
            if ($nameOk) $score += 2;
            if (($n['url'] !== '' && self::onHosts($n['url'], $hosts)) || ($n['id'] !== '' && self::onHosts($n['id'], $hosts))) $score += 2;
            if ($n['same_as']) $score += 1;
            if ($n['top']) $score += 1;
            $n['score'] = $score;
            $n['brand'] = $score >= 2 && ($n['top'] || $nameOk);
            $all[] = $n;
        }
        $mine = array_values(array_filter($all, function ($n) { return $n['brand']; }));
        $main = null;
        foreach ($mine as $n) {
            if ($main === null || $n['score'] > $main['score'] || ($n['score'] === $main['score'] && count($n['same_as']) > count($main['same_as']))) $main = $n;
        }
        // sameAs з усіх вузлів бренду (часто Organization + LocalBusiness)
        $raw = [];
        $mapUrls = [];
        $founding = '';
        $localities = [];
        $logo = '';
        foreach ($mine as $n) {
            foreach ($n['same_as'] as $s) {
                $raw[$s] = true;
                if (self::isMapsLink($s)) $mapUrls[] = $s;
            }
            foreach ($n['has_map'] as $s) if (self::isMapsLink($s)) $mapUrls[] = $s;
            if ($founding === '' && $n['founding'] !== '') $founding = $n['founding'];
            if ($logo === '' && $n['logo'] !== '') $logo = $n['logo'];
            $localities = array_merge($localities, $n['localities']);
        }
        if ($main) {
            $main['founding'] = $founding;
            $main['localities'] = array_values(array_unique($localities));
            if ($main['logo'] === '') $main['logo'] = $logo;
        }
        $parsed = [];
        foreach (array_keys($raw) as $s) $parsed[] = self::parseSameAs((string)$s, $hosts);
        return ['main' => $main, 'mine' => $mine, 'all' => array_values(array_filter($all, function ($n) { return !$n['brand']; })), 'stubs' => array_slice($stubs, 0, 3),
            'same_as_parsed' => $parsed, 'map_urls' => array_values(array_unique($mapUrls))];
    }

    /**
     * Особистий сайт: вузли Person головної особи з головної, «Про мене», «Контакти» у форматі orgNodes().
     * Головна особа - SiteProfile::homePerson() (той самий @id або ім'я); без неї - Person з ім'ям, як у назві сайту.
     * Інші особи (автори статей, колеги, засновник іншої компанії) не враховуються.
     */
    private function personNodes(array $pages, array $keys, array $hosts): array
    {
        $hp = ($pages['home'] ?? null) instanceof Page ? SiteProfile::homePerson($pages['home']) : null;
        $mainId = ($hp && $hp['id'] !== '') ? strtolower(rtrim($hp['id'], '/')) : '';
        $main = null;
        foreach (['home', 'about', 'contact'] as $role) {
            $p = $pages[$role] ?? null;
            if (!$p instanceof Page) continue;
            $top = self::topLevelHashes($p);
            foreach ($p->schemaItemsOfType(['Person']) as $it) {
                $d = is_array($it['data'] ?? null) ? $it['data'] : [];
                $name = self::firstString($d['name'] ?? null);
                $id = is_string($d['@id'] ?? null) ? trim($d['@id']) : '';
                $mine = $hp
                    ? (($mainId !== '' && strtolower(rtrim($id, '/')) === $mainId) || ($name !== '' && SiteProfile::personNameMatches($hp, $name)))
                    : ($name !== '' && self::nameMatchesKeys($name, $keys));
                if (!$mine || ($name === '' && $main === null)) continue;
                $node = [
                    'role' => $role, 'types' => $it['types'], 'name' => $name,
                    'url' => ($u = self::firstString($d['url'] ?? null)) !== '' ? (Crawler::absolutize($u, $p->url(), false) ?? '') : '',
                    'id' => $id !== '' ? (Crawler::absolutize($id, $p->url(), false) ?? '') : '',
                    'same_as' => self::flattenStrings($d['sameAs'] ?? null), 'logo' => self::logoUrl($d['image'] ?? null, $p->url()),
                    'founding' => '', 'localities' => [], 'has_map' => [],
                    'top' => ($it['source'] ?? '') !== 'json-ld' || isset($top[md5(serialize($d))]), 'score' => 4, 'brand' => true,
                ];
                if ($main === null) { $main = $node; continue; }
                foreach (['name', 'url', 'id', 'logo'] as $f) if ($main[$f] === '' && $node[$f] !== '') $main[$f] = $node[$f];
                $main['same_as'] = array_values(array_unique(array_merge($main['same_as'], $node['same_as'])));
                $main['top'] = $main['top'] || $node['top'];
            }
        }
        $parsed = [];
        if ($main) foreach ($main['same_as'] as $s) $parsed[] = self::parseSameAs((string)$s, $hosts);
        return ['main' => $main, 'mine' => $main ? [$main] : [], 'all' => [], 'stubs' => [], 'same_as_parsed' => $parsed, 'map_urls' => []];
    }

    /** Готовий Person з sameAs для особистого сайту (доповнення наявного вузла). */
    private static function personSnippet(?array $main, string $brand, string $homeUrl, array $sameAs, Page $home): string
    {
        $p = ['@context' => 'https://schema.org', '@type' => 'Person'];
        $p['@id'] = ($main && $main['id'] !== '' && self::homeLike($main['id'], $homeUrl, $home->url())) ? $main['id'] : rtrim($homeUrl, '/') . '/#person';
        $p['name'] = ($main && $main['name'] !== '') ? $main['name'] : $brand;
        $p['url'] = ($main && self::homeLike($main['url'], $homeUrl, $home->url())) ? $main['url'] : $homeUrl;
        $p['sameAs'] = array_values(array_unique($sameAs));
        return self::jsonLdScript($p);
    }

    /** md5 JSON-вузлів верхнього рівня (сам блок, елементи масиву-блоку, елементи @graph). */
    private static function topLevelHashes(Page $p): array
    {
        $out = [];
        foreach ($p->jsonLdRaw() as $b) {
            if (!is_array($b)) continue;
            $list = ($b === [] || array_keys($b) === range(0, count($b) - 1)) ? $b : [$b];
            foreach ($list as $n) {
                if (!is_array($n)) continue;
                if (isset($n['@type'])) $out[md5(serialize($n))] = true;
                if (isset($n['@graph']) && is_array($n['@graph'])) {
                    foreach ($n['@graph'] as $g) if (is_array($g)) $out[md5(serialize($g))] = true;
                }
            }
        }
        return $out;
    }

    /**
     * URL (@id з фрагментом або без) - головна: корінь домену, фактична адреса головної або мовна версія (/ua/, /en/),
     * а не внутрішня сторінка на кшталт /contacts.
     */
    private static function homeLike(string $u, string $homeUrl, string $finalUrl = ''): bool
    {
        if ($u === '') return false;
        $pi = parse_url($u);
        $ph = parse_url($homeUrl);
        if (!$pi || empty($pi['host']) || !$ph || empty($ph['host']) || !empty($pi['query'])) return false;
        $hi = (string)preg_replace('/^www\./', '', strtolower((string)$pi['host']));
        $hh = (string)preg_replace('/^www\./', '', strtolower((string)$ph['host']));
        if ($hi !== $hh) return false;
        $path = trim((string)($pi['path'] ?? ''), '/');
        if ($path === '' || preg_match('#^(ua|uk|en|ru|pl|de|ro)$#i', $path)) return true;
        $pf = $finalUrl !== '' ? parse_url($finalUrl) : null;
        return is_array($pf) && strcasecmp($path, trim((string)($pf['path'] ?? ''), '/')) === 0;
    }

    private static function parseSameAs(string $raw, array $hosts): array
    {
        $out = ['raw' => $raw, 'key' => '', 'platform' => '', 'invalid' => '', 'social' => null];
        $v = trim($raw);
        if (!preg_match('#^https?://#i', $v)) { $out['invalid'] = 'не https-посилання'; return $out; }
        if (self::onHosts($v, $hosts)) { $out['invalid'] = 'посилання на власний сайт'; return $out; }
        if (preg_match('#wikidata\.org/(?:wiki|entity)/(Q\d+)#i', $v, $m)) { $out['key'] = 'wikidata:' . strtoupper($m[1]); $out['platform'] = 'wikidata'; return $out; }
        if (preg_match('#^https?://([a-z\-]+)\.(?:m\.)?wikipedia\.org/wiki/(.+)$#i', $v, $m)) {
            $out['key'] = 'wp:' . strtolower($m[1]) . ':' . mb_strtolower(str_replace(' ', '_', rawurldecode($m[2])));
            $out['platform'] = 'wikipedia';
            return $out;
        }
        if (preg_match('#(facebook\.com/(sharer|share|dialog)|twitter\.com/(intent|share)|x\.com/(intent|share)|linkedin\.com/(shareArticle|sharing)|t\.me/share|api\.whatsapp\.com|wa\.me/)#i', $v)) {
            $out['invalid'] = 'посилання «поділитися» / месенджер, а не профіль';
            return $out;
        }
        $s = self::parseSocial($v);
        if ($s) {
            $out['key'] = $s['key'];
            $out['platform'] = $s['platform'];
            $out['social'] = $s;
        } else {
            $out['key'] = 'url:' . strtolower(rtrim((string)preg_replace('#^https?://(www\.)?#i', '', $v), '/'));
        }
        return $out;
    }

    // ================================================================== профілі

    /**
     * Розбирає URL профілю соцмережі.
     * @return array{platform:string,handle:string,url:string,key:string,kind:string,counted:bool,sameas:bool}|null
     */
    public static function parseSocial(string $href): ?array
    {
        $p = parse_url(trim($href));
        if (!$p || empty($p['host']) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) return null;
        $host = strtolower((string)$p['host']);
        $h2 = (string)preg_replace('/^(www|m|mobile|web|mbasic|touch|[a-z]{2}(-[a-z]{2})?)\./', '', $host);
        if (strpos($h2, '.') !== false) $host = $h2;
        $segs = array_values(array_filter(explode('/', (string)($p['path'] ?? '')), function ($s) { return $s !== ''; }));
        $segs = array_map('rawurldecode', $segs);
        $q = (string)($p['query'] ?? '');
        $n = count($segs);
        $s0 = $n ? $segs[0] : '';
        $mk = function (string $platform, string $handle, string $url, string $kind = 'profile') {
            // бот підтримки, запрошення в чат, номер телефону - контакт, а не публічний профіль бренду
            $counted = !in_array($kind, ['personal', 'phone', 'group', 'bot', 'invite'], true);
            return ['platform' => $platform, 'handle' => $handle, 'url' => $url, 'key' => $platform . ':' . strtolower($handle),
                'kind' => $kind, 'counted' => $counted, 'sameas' => in_array($kind, ['profile', 'channel_id'], true)];
        };

        if (in_array($host, ['facebook.com', 'fb.com', 'fb.me'], true)) {
            $reserved = ['sharer', 'sharer.php', 'share', 'share.php', 'dialog', 'plugins', 'tr', 'login', 'login.php', 'policies', 'privacy',
                'help', 'business', 'events', 'watch', 'photo.php', 'photo', 'photos', 'story.php', 'permalink.php', 'hashtag', 'ads', 'gaming',
                'marketplace', 'legal', 'terms', 'settings', 'home.php', 'l.php', 'search', 'video.php', 'reel', 'reels', 'notes', 'media', 'about', 'pg'];
            if ($s0 === 'profile.php' && preg_match('/(?:^|&)id=(\d+)/', $q, $m)) return $mk('facebook', 'id:' . $m[1], 'https://www.facebook.com/profile.php?id=' . $m[1]);
            if ($s0 === 'groups' && $n >= 2) return $mk('facebook', 'group:' . $segs[1], 'https://www.facebook.com/groups/' . $segs[1], 'group');
            if ($s0 === 'pages' && $n >= 2) return $mk('facebook', implode('/', array_slice($segs, 1, 2)), 'https://www.facebook.com/pages/' . implode('/', array_slice($segs, 1, 2)));
            if ($n >= 1 && $n <= 2 && !in_array(strtolower($s0), $reserved, true) && preg_match('/^[\w.\-]{2,80}$/u', $s0)
                && ($n === 1 || in_array(strtolower($segs[1]), ['about', 'reviews', 'community', 'posts', 'photos', 'videos'], true))) {
                return $mk('facebook', $s0, 'https://www.facebook.com/' . $s0);
            }
            return null;
        }
        if (in_array($host, ['instagram.com', 'instagr.am'], true)) {
            $reserved = ['p', 'reel', 'reels', 'tv', 'explore', 'accounts', 'stories', 'direct', 'about', 'legal', 'developer', 'web', 'share'];
            if ($n === 1 && !in_array(strtolower($s0), $reserved, true) && preg_match('/^[\w.]{1,30}$/', $s0)) return $mk('instagram', $s0, 'https://www.instagram.com/' . $s0);
            return null;
        }
        if ($host === 'linkedin.com') {
            $t = strtolower($s0);
            if (in_array($t, ['company', 'school', 'showcase'], true) && $n >= 2 && preg_match('/^[\w.\-%]{2,100}$/u', $segs[1])) {
                return $mk('linkedin', $t . '/' . $segs[1], 'https://www.linkedin.com/' . $t . '/' . $segs[1] . '/');
            }
            if ($t === 'in' && $n >= 2) return $mk('linkedin', 'in/' . $segs[1], 'https://www.linkedin.com/in/' . $segs[1] . '/', 'personal');
            return null;
        }
        if ($host === 'youtube.com') {
            $t = strtolower($s0);
            if ($t === 'channel' && $n >= 2 && preg_match('/^UC[\w\-]{20,}$/', $segs[1])) return $mk('youtube', 'channel/' . $segs[1], 'https://www.youtube.com/channel/' . $segs[1], 'channel_id');
            if (in_array($t, ['c', 'user'], true) && $n >= 2) return $mk('youtube', $t . '/' . $segs[1], 'https://www.youtube.com/' . $t . '/' . $segs[1]);
            if ($s0 !== '' && $s0[0] === '@' && strlen($s0) > 1) return $mk('youtube', $s0, 'https://www.youtube.com/' . $s0);
            return null;
        }
        if (in_array($host, ['t.me', 'telegram.me', 'telegram.dog'], true)) {
            if ($s0 === 's' && $n >= 2) $s0 = $segs[1];
            if ($s0 === '' || in_array(strtolower($s0), ['share', 'addstickers', 'addemoji', 'proxy', 'socks', 'iv', 'login', 'setlanguage', 'addtheme', 'confirmphone'], true)) return null;
            if (strtolower($s0) === 'joinchat' || ($s0[0] === '+' && !preg_match('/^\+\d{7,15}$/', $s0))) {
                $code = strtolower($s0) === 'joinchat' ? ($segs[1] ?? '') : substr($s0, 1);
                return $mk('telegram', 'invite:' . $code, 'https://t.me/' . (strtolower($s0) === 'joinchat' ? 'joinchat/' . $code : '+' . $code), 'invite');
            }
            if (preg_match('/^\+\d{7,15}$/', $s0)) return $mk('telegram', 'phone:' . $s0, 'https://t.me/' . $s0, 'phone');
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $s0)) {
                return $mk('telegram', $s0, 'https://t.me/' . $s0, preg_match('/bot$/i', $s0) ? 'bot' : 'profile');
            }
            return null;
        }
        if ($host === 'tiktok.com') {
            if ($n === 1 && $s0[0] === '@' && strlen($s0) > 1) return $mk('tiktok', $s0, 'https://www.tiktok.com/' . $s0);
            return null;
        }
        if (in_array($host, ['twitter.com', 'x.com'], true)) {
            $reserved = ['intent', 'share', 'home', 'hashtag', 'search', 'i', 'explore', 'settings', 'login', 'signup', 'privacy', 'tos', 'messages', 'notifications', 'compose'];
            if ($n === 1 && !in_array(strtolower($s0), $reserved, true) && preg_match('/^\w{1,15}$/', $s0)) return $mk('x', $s0, 'https://x.com/' . $s0);
            return null;
        }
        if ($host === 'github.com') {
            $reserved = ['features', 'about', 'pricing', 'login', 'join', 'marketplace', 'topics', 'sponsors', 'site', 'contact', 'security',
                'enterprise', 'team', 'customer-stories', 'readme', 'collections', 'trending', 'explore', 'settings', 'notifications', 'apps', 'orgs', 'search'];
            if ($n >= 1 && $n <= 2 && !in_array(strtolower($s0), $reserved, true) && preg_match('/^[A-Za-z0-9\-]{1,39}$/', $s0)) return $mk('github', $s0, 'https://github.com/' . $s0);
            return null;
        }
        return null;
    }

    private static function isMapsLink(string $u): bool
    {
        return (bool)preg_match('#^https?://(maps\.app\.goo\.gl/|goo\.gl/maps/|g\.page/|g\.co/kgs/|business\.google\.com/|search\.google\.com/local/|(www\.)?google\.[a-z.]{2,6}/maps|maps\.google\.[a-z.]{2,6}/)#i', trim($u))
            && !preg_match('#/maps/embed|output=embed#i', $u);
    }

    // ================================================================== QuickStatements

    /**
     * Рядки QuickStatements (V1) для створення елемента ($qid=null) або доповнення відсутніх тверджень.
     * Повертає [рядки, що заповнити вручну].
     */
    private function qsLines(?string $qid, ?array $ent, string $label, string $homeUrl, bool $isUa, array $langs, string $type, string $class, array $profiles, ?array $org, ?array $missing): array
    {
        $lines = [];
        $manual = [];
        $subj = $qid ?? 'LAST';
        $q = function (string $s) { return '"' . str_replace('"', '', $s) . '"'; };
        $miss = [];
        if ($missing !== null) foreach ($missing as $m) $miss[$m['prop']] = true;
        $need = function (string $prop) use ($qid, $miss, $class) {
            if ($qid === null) return in_array($prop, ['P31'], true) || isset(self::WD_CLASS_PROPS[$class][$prop]) || strpos($prop, 'P') !== 0 || in_array($prop, self::WD_SOCIAL, true);
            return isset($miss[$prop]);
        };
        $cyr = function (string $s) { return (bool)preg_match('/\p{Cyrillic}/u', $s); };
        if ($qid === null) {
            $lines[] = 'CREATE';
            $lines[] = 'LAST|Luk|' . $q($label);
            if (!$cyr($label)) $lines[] = 'LAST|Len|' . $q($label);
            else $manual[] = 'назва англійською (Len) латиницею';
            $lines[] = 'LAST|P31|' . ($class === 'person' ? 'Q5' : ($class === 'website' ? 'Q1153191' : 'Q4830453'));
            $lines[] = 'LAST|P856|' . $q($homeUrl);
        } else {
            // назва uk: наявна мітка елемента (en), якщо назва бренду латиницею; кириличну назву бренду беремо з сайту
            if ($need('Luk')) {
                $uk = $cyr($label) ? $label : ((string)($ent['label_en'] ?? '') !== '' ? (string)$ent['label_en'] : $label);
                $lines[] = $subj . '|Luk|' . $q($uk);
            }
            if ($need('Len')) {
                $en = '';
                foreach ([(string)($ent['label_uk'] ?? ''), $label] as $c) if ($c !== '' && !$cyr($c)) { $en = $c; break; }
                if ($en !== '') $lines[] = $subj . '|Len|' . $q($en);
                else $manual[] = 'назва англійською (Len) латиницею';
            }
            if ($need('P31')) $manual[] = $class === 'person' ? 'P31 (є одним із: людина, Q5)' : 'P31 (є одним із: бізнес, компанія, вебсайт тощо)';
        }
        // особа: громадянство, рід занять, ім'я та прізвище (елементи імен шукайте на wikidata.org)
        if ($class === 'person') {
            if ($need('P27')) {
                if ($isUa) $lines[] = $subj . '|P27|Q212';
                else $manual[] = 'P27 (громадянство)';
            }
            if ($need('P106')) $manual[] = 'P106 (рід занять, напр. елемент професії «спеціаліст з пошукової оптимізації»)';
            if ($need('P735')) $manual[] = 'P735 (ім\'я - елемент імені)';
            if ($need('P734')) $manual[] = 'P734 (прізвище - елемент прізвища)';
        }
        if ($need('P17')) {
            if ($isUa) $lines[] = $subj . '|P17|Q212';
            else $manual[] = 'P17 (країна)';
        }
        if ($need('P571')) {
            $fd = (string)($org['founding'] ?? '');
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $fd, $m)) $lines[] = $subj . '|P571|+' . $m[1] . '-' . $m[2] . '-' . $m[3] . 'T00:00:00Z/11';
            elseif (preg_match('/^(\d{4})$/', $fd, $m)) $lines[] = $subj . '|P571|+' . $m[1] . '-00-00T00:00:00Z/9';
            else $manual[] = $class === 'website' ? 'P571 (дата запуску)' : 'P571 (дата заснування)';
        }
        if ($need('P159')) {
            $city = null;
            foreach ((array)($org['localities'] ?? []) as $loc) {
                $k = mb_strtolower(trim((string)preg_replace('/^(м\.|г\.|місто|город)\s*/u', '', trim((string)$loc))));
                if (isset(self::UA_CITIES[$k])) { $city = self::UA_CITIES[$k]; break; }
            }
            if ($city) $lines[] = $subj . '|P159|' . $city;
            else $manual[] = 'P159 (місто штаб-квартири)';
        }
        if ($need('P452')) $manual[] = 'P452 (галузь)';
        if ($need('P178')) $manual[] = 'P178 (розробник - елемент компанії)';
        if ($need('P275')) $manual[] = 'P275 (ліцензія)';
        if ($need('P577')) $manual[] = 'P577 (дата першого випуску)';
        if ($need('P407')) {
            if ($langs) foreach ($langs as $l) $lines[] = $subj . '|P407|' . self::WD_LANGS[$l];
            else $manual[] = 'P407 (мова)';
        }
        foreach ($profiles as $pl => $a) {
            $prop = self::wdSocialProp((string)$pl, $a);
            if (!$prop || !$need($prop) || !$a['sameas']) continue;
            if ($pl === 'github' && !self::githubCounts($type)) continue;
            $val = self::wdSocialValue((string)$pl, $a);
            if ($val !== null) $lines[] = $subj . '|' . $prop . '|' . $q($val);
            elseif ($pl === 'youtube') $manual[] = 'P2397 (ID YouTube-каналу виду UC...)';
        }
        if ($qid === null || isset($miss['Duk'])) $manual[] = 'опис українською (Duk): ' . ($class === 'software' ? 'що це за продукт і хто його розробляє'
            : ($class === 'website' ? 'що це за сайт чи видання і про що він' : ($class === 'person' ? 'хто ця людина і чим займається' : 'що це за компанія і де працює')));
        if ($qid === null) $manual[] = 'джерела до тверджень (S854 - URL джерела)';
        return [$lines, $manual];
    }

    /** Відсутні в елементі твердження (за класом елемента): [[prop,label,counts], ...], [заповнені]. */
    private static function entityGaps(array $ent, array $profiles, string $class): array
    {
        $missing = [];
        $present = [];
        $req = ['P31' => 'P31 (є одним із)'] + self::WD_CLASS_PROPS[$class];
        foreach ($req as $p => $lbl) {
            $has = !empty($ent['props'][$p]) || ($p === 'P577' && !empty($ent['props']['P571']));
            if ($has) $present[] = ($p === 'P577' && empty($ent['props']['P577'])) ? 'P571' : $p;
            else $missing[] = ['prop' => $p, 'label' => $lbl, 'counts' => true];
        }
        $socialHave = array_filter($ent['social']);
        $socialMissing = [];
        foreach ($profiles as $pl => $a) {
            $prop = self::wdSocialProp((string)$pl, $a);
            $sk = $prop === 'P6634' ? 'linkedin_in' : $pl;
            if ($prop && empty($ent['social'][$sk]) && $a['sameas']) $socialMissing[] = ['prop' => $prop, 'label' => $prop . ' ' . ($pl === 'x' ? 'X' : self::PLATFORMS[$pl]), 'counts' => false];
        }
        if (!$socialHave) {
            $missing[] = ['prop' => 'social', 'label' => 'ідентифікатори соцмереж' . ($socialMissing ? ' (' . implode(', ', array_column($socialMissing, 'label')) . ')' : ''), 'counts' => true];
            foreach ($socialMissing as $sm) { $sm['label'] = ''; $missing[] = $sm; }
        } else {
            foreach ($socialHave as $pl => $v) $present[] = self::WD_SOCIAL[$pl];
            foreach ($socialMissing as $sm) $missing[] = $sm;
        }
        // мітка 'mul' (мовно-нейтральна) діє для всіх мов, тож закриває і uk, і en
        if ($ent['label_uk'] === '' && $ent['label_mul'] === '') $missing[] = ['prop' => 'Luk', 'label' => 'назви українською', 'counts' => true];
        if ($ent['label_en'] === '' && $ent['label_mul'] === '') $missing[] = ['prop' => 'Len', 'label' => 'назви англійською', 'counts' => true];
        if ($ent['desc_uk'] === '') $missing[] = ['prop' => 'Duk', 'label' => 'опису українською', 'counts' => true];
        $present[] = 'P856';
        return [$missing, array_values(array_unique($present))];
    }

    private static function parseEntity(array $e, string $searchDesc): array
    {
        $claims = is_array($e['claims'] ?? null) ? $e['claims'] : [];
        $vals = function (string $p) use ($claims): array {
            $out = [];
            foreach ((array)($claims[$p] ?? []) as $c) {
                if (!is_array($c) || ($c['rank'] ?? '') === 'deprecated') continue;
                $v = $c['mainsnak']['datavalue']['value'] ?? null;
                if (is_array($v) && isset($v['id'])) $v = $v['id'];
                elseif (is_array($v) && isset($v['time'])) $v = $v['time'];
                if (is_string($v) && $v !== '') $out[] = $v;
            }
            return $out;
        };
        $props = [];
        foreach (self::WD_PROPS as $p) $props[$p] = (bool)$vals($p);
        $social = [];
        foreach (self::WD_SOCIAL as $pl => $p) { $v = $vals($p); $social[$pl] = $v ? $v[0] : ''; }
        $sl = is_array($e['sitelinks'] ?? null) ? $e['sitelinks'] : [];
        $other = [];
        foreach ($sl as $site => $info) {
            if (!preg_match('/^[a-z_]+wiki$/', (string)$site) || in_array($site, ['ukwiki', 'enwiki', 'commonswiki', 'specieswiki', 'metawiki',
                    'wikidatawiki', 'mediawikiwiki', 'sourceswiki', 'incubatorwiki', 'outreachwiki', 'wikimaniawiki', 'foundationwiki'], true)) continue;
            $other[] = (string)$site;
        }
        $lbl = function (string $l) use ($e) { return (string)($e['labels'][$l]['value'] ?? ''); };
        $dsc = function (string $l) use ($e) { return (string)($e['descriptions'][$l]['value'] ?? ''); };
        return [
            'id' => (string)($e['id'] ?? ''),
            'label_uk' => $lbl('uk'), 'label_en' => $lbl('en'), 'label_mul' => $lbl('mul'),
            'desc_uk' => $dsc('uk'), 'desc_en' => $dsc('en'), 'desc_search' => $searchDesc,
            'p856' => $vals('P856'), 'p31' => $vals('P31'), 'p17' => $vals('P17'), 'p279' => (bool)$vals('P279'), 'props' => $props, 'social' => $social,
            'sitelinks' => ['ukwiki' => isset($sl['ukwiki']['title']) ? (string)$sl['ukwiki']['title'] : null,
                'enwiki' => isset($sl['enwiki']['title']) ? (string)$sl['enwiki']['title'] : null],
            'sitelinks_other' => $other,
        ];
    }

    private static function orgLike(array $ent): bool
    {
        if (array_intersect($ent['p31'], self::P31_BLOCK)) return false;
        if (array_intersect($ent['p31'], self::P31_ORG)) return true;
        return (bool)preg_match(self::RX_ORG_DESC, $ent['desc_uk'] . ' ' . $ent['desc_en'] . ' ' . $ent['desc_search']);
    }

    private static function entLabel(array $e): string
    {
        foreach (['label_uk', 'label_en', 'label_mul'] as $k) if (($e[$k] ?? '') !== '') return (string)$e[$k];
        return (string)($e['id'] ?? '');
    }

    private static function entDesc(array $e): string
    {
        foreach (['desc_uk', 'desc_en', 'desc_search'] as $k) if (($e[$k] ?? '') !== '') return (string)$e[$k];
        return '';
    }

    // ================================================================== HTTP

    /** Паралельні GET до API. [ключ => decoded array|null]; помилки - у $this->apiErrors. */
    private function apiGetMulti(array $req): array
    {
        if (!$req) return [];
        $res = Crawler::fetchMulti(array_values($req), self::UA, self::API_TIMEOUT);
        $out = [];
        foreach ($req as $k => $u) {
            $r = $res[$u] ?? null;
            $status = $r ? (int)$r['status'] : 0;
            $data = null;
            if ($r && $status === 200 && (string)$r['body'] !== '') {
                $d = json_decode((string)$r['body'], true);
                if (is_array($d) && !isset($d['error'])) $data = $d;
            }
            if ($data === null) {
                $host = (string)parse_url($u, PHP_URL_HOST);
                $err = $status ? 'HTTP ' . $status : (($r['error'] ?? '') !== '' ? (string)$r['error'] : 'немає відповіді');
                $this->apiErrors[] = $host . ' - ' . self::mbTrunc($err, 80);
            }
            $out[$k] = $data;
        }
        return $out;
    }

    // ================================================================== snippets

    private static function orgSnippet(?array $main, string $brand, string $homeUrl, string $type, array $sameAs, Page $home): string
    {
        $t = $main ? (string)$main['types'][0] : ($type === 'local' ? 'LocalBusiness' : ($type === 'media' ? 'NewsMediaOrganization' : 'Organization'));
        $org = ['@context' => 'https://schema.org', '@type' => $t];
        // @id і url беремо з наявного вузла, лише якщо вони вказують на головну (не на внутрішню сторінку на кшталт /contacts)
        $org['@id'] = ($main && self::homeLike($main['id'], $homeUrl, $home->url())) ? $main['id'] : rtrim($homeUrl, '/') . '/#organization';
        $org['name'] = ($main && $main['name'] !== '') ? $main['name'] : $brand;
        $org['url'] = ($main && self::homeLike($main['url'], $homeUrl, $home->url())) ? $main['url'] : $homeUrl;
        $logo = $main['logo'] ?? '';
        if ($logo === '') $logo = self::findLogo($home);
        $org['logo'] = $logo !== '' ? $logo : '[ВКАЖІТЬ URL логотипу]';
        $org['sameAs'] = array_values(array_unique($sameAs));
        return self::jsonLdScript($org);
    }

    private static function findLogo(Page $home): string
    {
        foreach ($home->query('//header//img[contains(translate(@src,"LOGO","logo"),"logo") or contains(translate(@alt,"LOGO","logo"),"logo") or contains(translate(@class,"LOGO","logo"),"logo")] | //*[contains(translate(@class,"LOGO","logo"),"logo") or contains(translate(@id,"LOGO","logo"),"logo")]//img | //img[contains(translate(@class,"LOGO","logo"),"logo") or contains(translate(@id,"LOGO","logo"),"logo")]') as $img) {
            /** @var DOMElement $img */
            $src = trim($img->getAttribute('src'));
            if ($src === '' || stripos($src, 'data:') === 0) $src = trim($img->getAttribute('data-src'));
            if ($src === '' || stripos($src, 'data:') === 0) continue;
            $abs = Crawler::absolutize($src, $home->url());
            if ($abs) return $abs;
        }
        return '';
    }

    private static function footerSnippet(array $best, string $type, array $meta = []): string
    {
        foreach ($meta as $pl => $a) if (!isset($best[$pl])) $best[$pl] = $a; // профілі з twitter:site / article:publisher замість заглушок
        $want = $type === 'saas' ? ['linkedin', 'x', 'github', 'youtube', 'telegram']
            : (in_array($type, ['services', 'corporate'], true) ? ['linkedin', 'facebook', 'youtube', 'telegram', 'instagram']
            : ($type === 'media' ? ['telegram', 'facebook', 'x', 'youtube', 'instagram'] : ['instagram', 'facebook', 'telegram', 'tiktok', 'youtube']));
        $tpl = ['facebook' => 'https://www.facebook.com/[ВКАЖІТЬ сторінку]', 'instagram' => 'https://www.instagram.com/[ВКАЖІТЬ акаунт]',
            'linkedin' => 'https://www.linkedin.com/company/[ВКАЖІТЬ ID компанії]/', 'youtube' => 'https://www.youtube.com/@[ВКАЖІТЬ канал]',
            'telegram' => 'https://t.me/[ВКАЖІТЬ канал]', 'tiktok' => 'https://www.tiktok.com/@[ВКАЖІТЬ акаунт]', 'x' => 'https://x.com/[ВКАЖІТЬ акаунт]',
            'github' => 'https://github.com/[ВКАЖІТЬ організацію]'];
        if ($type === 'personal') {
            $want = ['linkedin', 'youtube', 'telegram', 'github', 'x'];
            $tpl['linkedin'] = 'https://www.linkedin.com/in/[ВКАЖІТЬ профіль]/';
            $tpl['github'] = 'https://github.com/[ВКАЖІТЬ акаунт]';
        }
        $rows = [];
        foreach ($best as $pl => $a) if ($pl !== 'github' || self::githubCounts($type)) $rows[$pl] = $a['url'];
        foreach ($want as $pl) {
            if (count($rows) >= 5) break;
            if (!isset($rows[$pl])) $rows[$pl] = $tpl[$pl];
        }
        $out = "<nav class=\"footer-social\" aria-label=\"Ми в соцмережах\">\n";
        foreach ($rows as $pl => $u) $out .= '  <a href="' . $u . '" target="_blank" rel="me noopener">' . self::PLATFORMS[$pl] . "</a>\n";
        return $out . '</nav>';
    }

    // ================================================================== small helpers

    private function collectPages(array $ctx): array
    {
        $pages = ['home' => null, 'contact' => null, 'about' => null];
        $urls = ['home' => (string)($ctx['url'] ?? '')];
        $notes = [];
        if (($ctx['page'] ?? null) instanceof Page) $pages['home'] = $ctx['page'];
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            $role = (string)($s['role'] ?? '');
            if (($role !== 'contact' && $role !== 'about') || isset($urls[$role])) continue;
            $urls[$role] = (string)($s['url'] ?? '');
            if (($s['page'] ?? null) instanceof Page) {
                $pages[$role] = $s['page'];
            } else {
                $ch = (string)($s['challenge'] ?? '');
                $st = (int)($s['res']['status'] ?? ($s['status'] ?? 0));
                $notes[$role] = $ch !== '' ? 'захист ' . $ch : ('HTTP ' . $st);
            }
        }
        return [$pages, $urls, $notes];
    }

    /** Терміни пошуку у Wikidata: [[термін, [мови]], ...], максимум 3. */
    private static function searchTerms(string $core, string $brand, string $hostLabel): array
    {
        $out = [];
        $seen = [];
        foreach ([$core, $brand, str_replace(['-', '_'], ' ', $hostLabel)] as $t) {
            $t = trim($t);
            $k = self::normKey($t);
            if ($t === '' || $k === '' || isset($seen[mb_strtolower($t)]) || mb_strlen($t) > 60) continue;
            $seen[mb_strtolower($t)] = true;
            $out[] = [$t, preg_match('/\p{Cyrillic}/u', $t) ? ['uk'] : ['uk', 'en']];
            if (count($out) >= 3) break;
        }
        return $out;
    }

    /** «Example.com.ua» -> «Example». */
    private static function brandCore(string $brand): string
    {
        $b = trim($brand);
        if (preg_match('/^(.{2,}?)\.(com\.ua|org\.ua|net\.ua|in\.ua|kiev\.ua|kyiv\.ua|co\.ua|com|ua|net|org|io|app|shop|store|online|site|co|me|info|biz|eu|pl|de|tech|dev|ai)$/iu', $b, $m)) $b = $m[1];
        return $b !== '' ? $b : $brand;
    }

    /** Ключ для порівняння назв: транслітерація, лише [a-z0-9] (як у SiteProfile). */
    public static function normKey(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z',
            'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
            'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ь' => '', 'ю' => 'iu', 'я' => 'ia', 'ы' => 'y', 'э' => 'e', 'ё' => 'e', 'ъ' => '', '\'' => '', 'ʼ' => '', '’' => ''];
        $s = strtr($s, $map);
        $s = (string)preg_replace('/[^a-z0-9]/', '', $s);
        return strtr($s, ['ks' => 'x', 'kh' => 'h', 'ck' => 'k', 'ie' => 'e', 'iu' => 'u', 'ia' => 'a', 'y' => 'i', 'c' => 'k', 'w' => 'v']);
    }

    /** Та сама назва з точністю до регістру, пробілів і розділових знаків. */
    private static function sameName(string $a, string $b): bool
    {
        $ka = self::normKey($a);
        return $ka !== '' && $ka === self::normKey($b);
    }

    /** Одна назва містить іншу (або однакові). */
    private static function namesMatch(string $a, string $b): bool
    {
        $ka = self::normKey($a);
        $kb = self::normKey($b);
        if ($ka === '' || $kb === '') return false;
        if ($ka === $kb) return true;
        return min(strlen($ka), strlen($kb)) >= 3 && (strpos($ka, $kb) !== false || strpos($kb, $ka) !== false);
    }

    private static function nameMatchesKeys(string $name, array $keys): bool
    {
        $k = self::normKey($name);
        if ($k === '') return false;
        foreach ($keys as $key) {
            if (strlen($key) < 3) { if ($k === $key) return true; continue; }
            if (strpos($k, $key) !== false || (strlen($k) >= 3 && strpos($key, $k) !== false)) return true;
        }
        return false;
    }

    private static function handleLike(string $handle, array $keys): bool
    {
        $h = (string)preg_replace('#^(id|group|invite|phone|in|company|school|showcase|channel|c|user)[:/]#i', '', ltrim($handle, '@'));
        $k = self::normKey($h);
        if (strlen($k) < 3) return false;
        foreach ($keys as $key) {
            if (strlen($key) < 3) continue;
            if (strpos($k, $key) !== false || (strlen($k) >= 4 && strpos($key, $k) !== false)) return true;
        }
        return false;
    }

    private static function onHosts(string $u, array $hosts): bool
    {
        $u = trim($u);
        if ($u === '') return false;
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $u)) $u = 'http://' . ltrim($u, '/');
        $h = Crawler::registrableHost($u);
        return $h !== '' && in_array($h, $hosts, true);
    }

    private static function firstString($v): string
    {
        if (is_string($v)) return trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (is_array($v)) {
            if (isset($v['@value']) && is_string($v['@value'])) return trim($v['@value']);
            foreach ($v as $x) { $s = self::firstString($x); if ($s !== '') return $s; }
        }
        return '';
    }

    /** Усі рядки з вкладених масивів (sameAs може бути рядком, масивом, масивом об'єктів з @id). */
    private static function flattenStrings($v, int $depth = 0): array
    {
        if ($depth > 4) return [];
        if (is_string($v)) { $v = trim($v); return $v !== '' ? [$v] : []; }
        if (!is_array($v)) return [];
        if (isset($v['@id']) && is_string($v['@id'])) return [trim($v['@id'])];
        if (isset($v['url']) && is_string($v['url'])) return [trim($v['url'])];
        $out = [];
        foreach ($v as $x) $out = array_merge($out, self::flattenStrings($x, $depth + 1));
        return array_values(array_unique(array_filter($out, 'strlen')));
    }

    private static function logoUrl($v, string $base): string
    {
        $s = '';
        if (is_string($v)) $s = $v;
        elseif (is_array($v)) $s = self::firstString($v['url'] ?? ($v['contentUrl'] ?? ($v['@id'] ?? ($v[0] ?? ''))));
        $s = trim($s);
        if ($s === '') return '';
        if (substr_count($s, 'http') > 1) return ''; // зламане значення на кшталт https://a.uahttps://a.ua/...
        return Crawler::absolutize($s, $base) ?? '';
    }

    private static function localities($addr, int $depth = 0): array
    {
        if ($depth > 3) return [];
        if (is_array($addr)) {
            if (isset($addr['addressLocality'])) return array_filter([self::firstString($addr['addressLocality'])], 'strlen');
            $out = [];
            foreach ($addr as $x) if (is_array($x)) $out = array_merge($out, self::localities($x, $depth + 1));
            return $out;
        }
        return [];
    }

    private static function shortUrl(string $u): string
    {
        $s = (string)preg_replace('#^https?://(www\.)?#i', '', $u);
        return self::mbTrunc(rtrim($s, '/'), 70);
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

    private function summary(array $wm, array $sub, ?array $social, array $data, bool $hasHtml): string
    {
        $parts = [];
        $wd = $data['wikidata']['status'] ?? null;
        if ($sub['wikidata'] === null) $parts[] = 'Wikidata не перевірено';
        elseif ($wd === 'verified') $parts[] = 'Сутність підтверджена у Wikidata (' . $data['wikidata']['id'] . ')';
        elseif ($wd === 'no_p856') $parts[] = 'Елемент Wikidata є (' . $data['wikidata']['id'] . '), але без вашого домену в P856';
        elseif ($wd === 'possible') $parts[] = 'У Wikidata лише можливий збіг без підтвердження доменом';
        else $parts[] = 'Немає елемента Wikidata, прив\'язаного до домену сайту';
        $wp = $data['wikipedia'] ?? null;
        if ($sub['wikidata'] !== null && in_array($wd, ['verified', 'no_p856'], true)) {
            $langs = array_keys(array_filter(['uk' => $wp['uk'] ?? null, 'en' => $wp['en'] ?? null]));
            $parts[] = $langs ? 'є стаття у Вікіпедії (' . implode(', ', $langs) . ')' : 'статті у Вікіпедії uk/en немає';
        }
        if ($hasHtml && $social !== null) {
            $n = count($data['social'] ?? []);
            $parts[] = $n ? $n . ' ' . self::plural($n, 'профіль', 'профілі', 'профілів') . ' у соцмережах' : 'посилань на соцмережі немає';
            if (($sub['same_as'] ?? null) !== null) {
                $sa = $data['same_as'] ?? [];
                $parts[] = $sub['same_as'] >= 100 ? 'sameAs повний' : ($sub['same_as'] === 0 ? 'sameAs відсутній'
                    : (empty($sa['missing']) && !empty($sa['invalid']) ? 'sameAs містить усі профілі, але має некоректні значення' : 'sameAs неповний'));
            }
        } elseif (!$hasHtml) {
            $parts[] = 'HTML-частину не перевірено (сайт не віддав сторінку)';
        }
        if (!empty($data['partial'])) {
            $na = (array)$data['partial']['not_assessed'];
            $parts[] = 'оцінка часткова: не перевірено ' . count($na) . ' з ' . (int)$data['partial']['of'] . ' складових (' . implode(', ', $na) . ')'
                . (isset($data['partial']['score_uncapped']) ? ', тому бал обмежено ' . (int)$data['partial']['cap'] : '');
        }
        return implode('; ', $parts) . '.';
    }
}
