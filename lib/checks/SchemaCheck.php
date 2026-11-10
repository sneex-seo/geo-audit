<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';
require_once __DIR__ . '/../SiteProfile.php';

/**
 * Перевірка структурованих даних (schema.org) v2.
 *
 * Що дивимось (головна + внутрішні сторінки вибірки, з урахуванням ролі сторінки):
 *  1) синтаксис JSON-LD (суворий JSON, як у Google) + HTML-сутності в значеннях, відсутній @context;
 *  2) сутність організації (Organization і підтипи, зокрема LocalBusiness) на головній / «Про нас» / «Контакти»:
 *     name, url, logo, sameAs (2+ профілі), contactPoint/telephone, address; помилки значень між сторінками;
 *  3) WebSite з name (назва сайту в Google). SearchAction не вимагаємо - Google прибрав sitelinks search box у 2024;
 *  4) BreadcrumbList на внутрішніх сторінках;
 *  5) розмітка під тип сайту: Product+Offer (магазин), LocalBusiness (локальний бізнес), Article (медіа і блоги),
 *     SoftwareApplication (SaaS), Service (послуги);
 *  6) FAQ-контент без FAQPage (низький пріоритет: розширені результати FAQ Google прибрав 7 травня 2026 року);
 *  7) лише microdata/RDFa без JSON-LD.
 * Підоцінки: jsonld_valid, entity, entity_props, breadcrumbs, type_specific, website (null = не застосовно).
 */
class SchemaCheck extends BaseCheck
{
    public function id(): string { return 'schema'; }
    public function name(): string { return 'Структуровані дані'; }
    public function weight(): float { return 0.12; }

    const WEIGHTS = [
        'jsonld_valid'  => 0.10,
        'entity'        => 0.25,
        'entity_props'  => 0.20,
        'breadcrumbs'   => 0.10,
        'type_specific' => 0.30,
        'website'       => 0.05,
    ];

    const LABELS = [
        'jsonld_valid'  => 'Валідність JSON-LD',
        'entity'        => 'Сутність організації',
        'entity_props'  => 'Повнота даних організації',
        'breadcrumbs'   => 'BreadcrumbList на внутрішніх сторінках',
        'type_specific' => 'Розмітка під тип сайту',
        'website'       => 'WebSite з назвою сайту',
    ];

    const ROLE_UK = [
        'home' => 'головна', 'about' => '«Про нас»', 'contact' => '«Контакти»', 'article' => 'стаття',
        'product' => 'картка товару', 'category' => 'категорія', 'service' => 'сторінка послуги', 'other' => 'внутрішня сторінка',
        'branch' => 'сторінка філії',
    ];

    /** Максимальна довжина готового коду (BaseCheck обрізає на 6000 і ламає JSON). */
    const CODE_MAX = 5800;

    /** Довгі списки, які в готовому коді можна скоротити до перших елементів. */
    const LONG_LISTS = ['mainEntity', 'review', 'itemListElement', 'hasOfferCatalog', 'makesOffer', 'offers', 'hasPart', 'subjectOf',
        'openingHoursSpecification', 'department', 'employee', 'member', 'sameAs', 'image', 'acceptedAnswer', 'suggestedAnswer', 'itemOffered'];

    /** Вкладені властивості, через які Organization ще вважається сутністю сайту (а не автором, продавцем тощо). */
    const ENTITY_PROPS_OK = ['publisher', 'mainEntity', 'about', 'provider', 'owner', 'copyrightHolder', 'sourceOrganization'];

    const UP = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const LO = 'abcdefghijklmnopqrstuvwxyz';

    const PH_PHONE = '[ВКАЖІТЬ телефон у форматі +380...]';

    /** Форми назв міст => називний відмінок (для адреси з тексту сторінки). */
    const CITIES = [
        'Київ' => 'Київ', 'Києві' => 'Київ', 'Києва' => 'Київ', 'Львів' => 'Львів', 'Львові' => 'Львів', 'Львова' => 'Львів',
        'Одеса' => 'Одеса', 'Одесі' => 'Одеса', 'Одеси' => 'Одеса', 'Харків' => 'Харків', 'Харкові' => 'Харків', 'Харкова' => 'Харків',
        'Дніпро' => 'Дніпро', 'Дніпрі' => 'Дніпро', 'Запоріжжя' => 'Запоріжжя', 'Запоріжжі' => 'Запоріжжя',
        'Вінниця' => 'Вінниця', 'Вінниці' => 'Вінниця', 'Полтава' => 'Полтава', 'Полтаві' => 'Полтава',
        'Чернігів' => 'Чернігів', 'Чернігові' => 'Чернігів', 'Черкаси' => 'Черкаси', 'Черкасах' => 'Черкаси',
        'Житомир' => 'Житомир', 'Житомирі' => 'Житомир', 'Суми' => 'Суми', 'Сумах' => 'Суми', 'Рівне' => 'Рівне', 'Рівному' => 'Рівне',
        'Луцьк' => 'Луцьк', 'Луцьку' => 'Луцьк', 'Тернопіль' => 'Тернопіль', 'Тернополі' => 'Тернопіль',
        'Івано-Франківськ' => 'Івано-Франківськ', 'Івано-Франківську' => 'Івано-Франківськ', 'Ужгород' => 'Ужгород', 'Ужгороді' => 'Ужгород',
        'Чернівці' => 'Чернівці', 'Чернівцях' => 'Чернівці', 'Хмельницький' => 'Хмельницький', 'Хмельницькому' => 'Хмельницький',
        'Миколаїв' => 'Миколаїв', 'Миколаєві' => 'Миколаїв', 'Кропивницький' => 'Кропивницький', 'Кропивницькому' => 'Кропивницький',
        'Біла Церква' => 'Біла Церква', 'Білій Церкві' => 'Біла Церква', 'Бровари' => 'Бровари', 'Броварах' => 'Бровари',
        'Ірпінь' => 'Ірпінь', 'Ірпені' => 'Ірпінь', 'Буча' => 'Буча', 'Бучі' => 'Буча', 'Вишгород' => 'Вишгород', 'Вишгороді' => 'Вишгород',
        'Бориспіль' => 'Бориспіль', 'Борисполі' => 'Бориспіль', 'Кривий Ріг' => 'Кривий Ріг', 'Кривому Розі' => 'Кривий Ріг',
        'Кременчук' => 'Кременчук', 'Кременчуці' => 'Кременчук', 'Мукачево' => 'Мукачево', 'Мукачеві' => 'Мукачево',
    ];

    // ----------------------------------------------------------------- state (один run на екземпляр)

    private array $ctx = [];
    private array $site = [];
    private string $stype = 'corporate';
    private string $base = '';
    private string $homeUrl = '';
    private string $brand = '';
    /** @var array<int, array> */
    private array $pages = [];
    /** Глобальний індекс @id => data (з усіх сторінок). */
    private array $gIds = [];
    /** Зібрані з HTML факти для готового коду. */
    private array $facts = [];
    /** Обрана сутність організації (злиті дані) або null. */
    private ?array $E = null;
    private array $findings = [];
    private array $passed = [];
    private array $data = [];
    /** LocalBusiness-вузли філій з адресою (поза основною сутністю): [['pi' => int, 'node' => array], ...]. */
    private array $branchLb = [];
    /** Результат пошуку сторінок філій: null - не шукали; ['candidates' => n, 'checked' => [...], 'failed' => [...]]. */
    private ?array $branchProbe = null;
    /** Поля статті з некоректними датами (у готовий код не копіюємо). */
    private array $badDates = [];
    /** Примітка, якщо в готовий код підставлено вулицю з тексту сторінки. */
    private string $codeAddrNote = '';
    /** Особистий сайт: злиті дані Person (головна особа сайту) або null. */
    private ?array $P = null;
    /** Особистий сайт: SiteProfile::homePerson() головної або null. */
    private ?array $homePerson = null;

    // ================================================================= run

    public function run(string $url, array $ctx): array
    {
        $this->ctx = $ctx;
        $this->findings = []; $this->passed = []; $this->data = []; $this->E = null;
        $this->branchLb = []; $this->branchProbe = null; $this->badDates = []; $this->codeAddrNote = '';
        $this->P = null; $this->homePerson = null;
        $this->site = (array)($ctx['site'] ?? []);
        $this->stype = (string)($this->site['type'] ?? 'corporate');
        $finalUrl = (string)($ctx['url'] ?? $url);
        $this->base = Crawler::baseUrl($finalUrl);
        $path = (string)(parse_url($finalUrl, PHP_URL_PATH) ?: '/');
        $this->homeUrl = preg_match('#^/(ua|uk|en|ru)/?$#i', $path)
            ? $this->base . '/' . trim($path, '/') . '/'
            : $this->base . '/';
        $this->brand = trim((string)($this->site['brand'] ?? ''));
        if ($this->brand === '') $this->brand = (string)Crawler::registrableHost($finalUrl);

        // ---- сторінки
        $home = $ctx['page'] ?? null;
        $this->pages = [];
        if ($home instanceof Page) {
            $this->pages[] = $this->mkPage('home', $finalUrl, $home, '');
        } else {
            $this->pages[] = ['role' => 'home', 'url' => $finalUrl, 'page' => null, 'note' => $this->homeNote()];
        }
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $role = (string)($s['role'] ?? 'other');
            $surl = (string)(($s['res']['final_url'] ?? '') ?: ($s['url'] ?? ''));
            $sp = $s['page'] ?? null;
            if ($sp instanceof Page) $this->pages[] = $this->mkPage($role, $surl, $sp, (string)($s['note'] ?? ''));
            else $this->pages[] = ['role' => $role, 'url' => $surl, 'page' => null, 'note' => $this->sampleNote($s)];
        }
        if (!$this->fetchedPages()) {
            return $this->skipped($this->pages[0]['note'] !== '' ? $this->pages[0]['note'] : 'Сторінки сайту недоступні для бота - розмітку неможливо прочитати',
                ['site_type' => $this->stype]);
        }

        // магазин без картки товару у вибірці - пробуємо знайти одну (1 запит)
        if ($this->stype === 'ecommerce' && !$this->pagesByRole('product', true)) $this->discoverProduct();

        $this->buildGlobalIds();
        $this->collectFacts();
        // локальний бізнес без LocalBusiness на головній / «Контактах» - перевіряємо 1-2 сторінки філій
        if ($this->stype === 'local' && $this->needBranchPages()) {
            $this->discoverBranches();
            $this->buildGlobalIds();
        }

        $sub = [];
        $sub['jsonld_valid'] = $this->checkJsonLd();
        // особистий сайт експерта: сутність - Person (з ProfilePage), а не Organization / LocalBusiness
        if ($this->stype === 'personal') [$sub['entity'], $sub['entity_props']] = $this->checkPerson();
        else [$sub['entity'], $sub['entity_props']] = $this->checkEntity();
        $sub['website'] = $this->checkWebSite();
        $sub['breadcrumbs'] = $this->checkBreadcrumbs();
        $sub['type_specific'] = $this->checkTypeSpecific();
        $this->checkFaq();
        $this->checkMicrodataOnly();
        $this->checkInnerSanity();

        $parts = [];
        foreach (self::WEIGHTS as $k => $w) $parts[$k] = [$sub[$k] ?? null, $w];
        $anyScore = false;
        foreach ($sub as $v) if ($v !== null) $anyScore = true;
        $score = $anyScore ? $this->scoreFrom($parts) : null;

        $this->data['site_type'] = $this->stype;
        $this->data['pages'] = $this->pagesData();
        $labels = self::LABELS;
        if ($this->stype === 'personal') {
            $labels['entity'] = 'Сутність особи (Person)';
            $labels['entity_props'] = 'Повнота даних Person';
            $labels['type_specific'] = 'ProfilePage з mainEntity -> Person';
        }
        return $this->result($score, $sub, $labels, $this->findings, $this->passed, $this->data, $this->summary());
    }

    // ================================================================= pages

    private function mkPage(string $role, string $url, Page $p, string $note): array
    {
        $nodes = [];
        // лише синтаксично валідні блоки: зламані Google відкидає повністю
        $blocks = $this->rawBlocks($p);
        foreach ($blocks as $b) if ($b['ok']) $this->walkLd($b['data'], $nodes, true, null, 0);
        // microdata / RDFa: верхній рівень - itemscope без itemprop, typeof без property
        $top = [];
        foreach ($p->query('//*[@itemscope and not(@itemprop)]') as $el) {
            foreach (preg_split('/\s+/', trim((string)$el->getAttribute('itemtype'))) ?: [] as $t) {
                $st = Page::shortType((string)$t);
                if ($st !== '') $top[strtolower($st)] = true;
            }
        }
        foreach ($p->query('//*[@typeof and not(@property)]') as $el) {
            foreach (preg_split('/\s+/', trim((string)$el->getAttribute('typeof'))) ?: [] as $t) {
                $st = Page::shortType((string)$t);
                if ($st !== '') $top[strtolower($st)] = true;
            }
        }
        foreach ($p->schemaItems() as $it) {
            if (($it['source'] ?? '') === 'json-ld') continue;
            $isTop = false;
            foreach ($it['types'] as $t) if (isset($top[strtolower($t)])) $isTop = true;
            $nodes[] = ['types' => $it['types'], 'data' => $it['data'], 'source' => $it['source'], 'top' => $isTop, 'prop' => null];
        }
        $ids = [];
        foreach ($nodes as $n) {
            if ($n['source'] !== 'json-ld' || !isset($n['data']['@id']) || !is_string($n['data']['@id'])) continue;
            $k = self::normId($n['data']['@id']);
            if (!isset($ids[$k]) || count($n['data']) > count($ids[$k])) $ids[$k] = $n['data'];
        }
        return ['role' => $role, 'url' => $url, 'page' => $p, 'note' => $note, 'nodes' => $nodes, 'ids' => $ids,
            'blocks' => $blocks];
    }

    private function walkLd($node, array &$out, bool $top, ?string $prop, int $depth): void
    {
        if ($depth > 12 || !is_array($node)) return;
        if (self::isList($node)) {
            foreach ($node as $v) $this->walkLd($v, $out, $top, $prop, $depth + 1);
            return;
        }
        $types = self::typesOf($node);
        if ($types) $out[] = ['types' => $types, 'data' => $node, 'source' => 'json-ld', 'top' => $top, 'prop' => $prop];
        foreach ($node as $k => $v) {
            if (!is_array($v)) continue;
            $k = (string)$k;
            if ($k === '@graph') $this->walkLd($v, $out, $top && !$types, null, $depth + 1);
            else $this->walkLd($v, $out, false, $k, $depth + 1);
        }
    }

    /** Сирі JSON-LD блоки сторінки з класифікацією суворим парсером. */
    private function rawBlocks(Page $p): array
    {
        $out = [];
        foreach ($p->query('//script[@type]') as $s) {
            if (strpos(strtolower((string)$s->getAttribute('type')), 'ld+json') === false) continue;
            $raw = trim((string)$s->textContent);
            if ($raw === '') continue;
            $ok = true; $err = ''; $off = 0; $decoded = null;
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // HTML-коментар / CDATA навколо JSON - звичайна практика, Google це читає
                $stripped = trim((string)preg_replace(['/^\s*<!--/', '/-->\s*$/', '/^\s*(\/\/\s*)?<!\[CDATA\[/', '/(\/\/\s*)?\]\]>\s*$/'], '', $raw));
                $data = json_decode($stripped, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $ok = false;
                    $data = null;
                    [$off, $err] = self::jsonErrorAt($stripped);
                    $raw = $stripped;
                    $decoded = self::repairJson($stripped);
                }
            }
            if ($ok && !is_array($data)) { $ok = false; $err = 'блок не є JSON-об\'єктом чи масивом'; $data = null; }
            $types = [];
            if (!$ok && is_array($decoded)) {
                $tmp = [];
                $this->walkLd($decoded, $tmp, true, null, 0);
                foreach ($tmp as $n) if ($n['top']) foreach ($n['types'] as $t) $types[$t] = true;
            }
            $out[] = ['ok' => $ok, 'raw' => $ok ? '' : $raw, 'error' => $err, 'offset' => $off, 'repaired' => $decoded,
                'data' => $data, 'types' => array_keys($types)];
        }
        return $out;
    }

    private function fetchedPages(): array
    {
        return array_values(array_filter($this->pages, function ($pg) { return $pg['page'] instanceof Page; }));
    }

    /** @return array<int, array> сторінки ролі (лише завантажені, якщо $fetched). */
    private function pagesByRole(string $role, bool $fetched = true): array
    {
        $out = [];
        foreach ($this->pages as $i => $pg) {
            if ($pg['role'] !== $role) continue;
            if ($fetched && !($pg['page'] instanceof Page)) continue;
            $out[$i] = $pg;
        }
        return $out;
    }

    private function homeNote(): string
    {
        $a = (array)($this->ctx['access'] ?? []);
        $st = (int)($a['homepage_status'] ?? ($this->ctx['homepage']['status'] ?? 0));
        $reason = (string)($a['block_reason'] ?? '');
        $why = self::blockLabel($reason, $st);
        return 'Головна сторінка недоступна для бота (' . $why . ') - розмітку schema.org неможливо прочитати';
    }

    private function sampleNote(array $s): string
    {
        $st = (int)($s['res']['status'] ?? ($s['status'] ?? 0));
        $ch = (string)($s['challenge'] ?? '');
        return self::blockLabel($ch, $st);
    }

    private static function blockLabel(string $reason, int $status): string
    {
        $names = ['cloudflare' => 'захист Cloudflare', 'ddos-guard' => 'захист DDoS-Guard', 'imperva' => 'захист Imperva',
            'sucuri' => 'захист Sucuri', 'akamai' => 'захист Akamai', 'datadome' => 'захист DataDome', 'vercel' => 'захист Vercel',
            'captcha' => 'капча', 'access_denied' => 'доступ заборонено', 'connection' => 'немає з\'єднання', 'empty_body' => 'порожня відповідь'];
        $parts = [];
        if ($reason !== '' && isset($names[$reason])) $parts[] = $names[$reason];
        if ($status > 0) $parts[] = 'HTTP ' . $status;
        elseif (!$parts) $parts[] = 'сторінку не вдалося завантажити';
        return implode(', ', $parts);
    }

    private function where(array $pg): string
    {
        $role = ($pg['role'] === 'about' && $this->stype === 'personal') ? '«Про мене»' : (self::ROLE_UK[$pg['role']] ?? 'сторінка');
        return $pg['role'] === 'home' ? 'головна' : $role . ' ' . self::shortUrl((string)$pg['url']);
    }

    private static function shortUrl(string $u): string
    {
        $p = parse_url($u);
        if (!$p) return $u;
        $s = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        if ($s === '' ) $s = '/';
        return mb_strlen($s) > 70 ? mb_substr($s, 0, 66) . ' ...' : $s;
    }

    private function typesOnPage(array $pg, bool $topOnly = true): array
    {
        $t = [];
        foreach ($pg['nodes'] ?? [] as $n) {
            if ($topOnly && !$n['top']) continue;
            foreach ($n['types'] as $x) $t[$x] = true;
        }
        return array_keys($t);
    }

    /** «JSON-LD на сторінці: A, B; microdata: C» або «розмітки schema.org немає». */
    private function typesLine(array $pg): string
    {
        $by = [];
        foreach ($pg['nodes'] ?? [] as $n) {
            if (!$n['top']) continue;
            foreach ($n['types'] as $x) $by[$n['source']][$x] = true;
        }
        if (!$by) return 'розмітки schema.org немає';
        $names = ['json-ld' => 'JSON-LD', 'microdata' => 'microdata', 'rdfa' => 'RDFa'];
        $out = [];
        foreach ($by as $src => $types) $out[] = ($names[$src] ?? $src) . ': ' . self::listSome(array_keys($types), 6);
        return implode('; ', $out);
    }

    private function buildGlobalIds(): void
    {
        $this->gIds = [];
        foreach ($this->fetchedPages() as $pg) {
            foreach ($pg['ids'] as $k => $d) {
                if (!isset($this->gIds[$k]) || count($d) > count($this->gIds[$k])) $this->gIds[$k] = $d;
            }
        }
    }

    private function pagesData(): array
    {
        $out = [];
        foreach ($this->pages as $pg) {
            $row = ['role' => $pg['role'], 'url' => $pg['url']];
            if ($pg['page'] instanceof Page) {
                $by = [];
                foreach ($pg['nodes'] as $n) if ($n['top']) foreach ($n['types'] as $t) $by[$n['source']][$t] = true;
                $row['types'] = array_map('array_keys', $by);
                $row['jsonld_blocks'] = count($pg['blocks']);
                $row['jsonld_errors'] = count(array_filter($pg['blocks'], function ($b) { return !$b['ok']; }));
                if (!empty($pg['via'])) $row['via'] = $pg['via'];
            } else {
                $row['not_checked'] = $pg['note'];
            }
            $out[] = $row;
        }
        return $out;
    }

    // ================================================================= node helpers

    private static function typesOf(array $node): array
    {
        $out = [];
        foreach ((array)($node['@type'] ?? []) as $t) {
            if (!is_string($t)) continue;
            $st = Page::shortType($t);
            if ($st !== '') $out[] = $st;
        }
        return array_values(array_unique($out));
    }

    private static function isType(array $node, array $types, bool $sub = true): bool
    {
        $want = Page::expandTypes($types, $sub);
        foreach ($node['types'] ?? self::typesOf($node['data'] ?? $node) as $t) {
            if (isset($want[strtolower((string)$t)])) return true;
        }
        return false;
    }

    /** Вузли сторінки заданих типів. */
    private static function nodesOf(array $pg, array $types, bool $topOnly = false): array
    {
        $out = [];
        foreach ($pg['nodes'] ?? [] as $n) {
            if ($topOnly && !$n['top']) continue;
            if (self::isType($n, $types)) $out[] = $n;
        }
        return $out;
    }

    private static function normId(string $id): string
    {
        return strtolower(rtrim(trim($id), '/'));
    }

    /** Значення властивості з розв'язанням @id-посилань і заглушок microdata. Порожнє => null. */
    private function val(array $data, string $key, ?array $pg = null)
    {
        if (!array_key_exists($key, $data)) return null;
        return $this->resolve($data[$key], $pg, 0);
    }

    private function resolve($v, ?array $pg, int $d)
    {
        if (is_string($v)) { $v = trim($v); return $v === '' ? null : $v; }
        if (is_int($v) || is_float($v) || is_bool($v)) return $v;
        if (!is_array($v) || $v === []) return null;
        if (self::isList($v)) {
            $out = [];
            foreach ($v as $x) {
                $r = $this->resolve($x, $pg, $d + 1);
                if ($r !== null) $out[] = $r;
            }
            return $out ?: null;
        }
        if ($d > 4) return $v;
        $meaningful = array_diff(array_map('strval', array_keys($v)), ['@id', '@type', '@context']);
        if (!$meaningful && isset($v['@id']) && is_string($v['@id'])) {
            $k = self::normId($v['@id']);
            if ($pg && isset($pg['ids'][$k])) return $pg['ids'][$k];
            if (isset($this->gIds[$k])) return $this->gIds[$k];
            return $v; // посилання є, але ціль не знайдено - вважаємо наявним
        }
        if (!$meaningful && isset($v['@type']) && $pg) {
            // заглушка вкладеного itemscope з microdata: беремо перший такий елемент сторінки
            $types = self::typesOf($v);
            if ($types) {
                foreach ($pg['nodes'] as $n) {
                    if ($n['source'] === 'json-ld') continue;
                    if (array_intersect(array_map('strtolower', $n['types']), array_map('strtolower', $types)) && count($n['data']) > 1) return $n['data'];
                }
            }
        }
        return $v;
    }

    private static function isRef($v): bool
    {
        return is_array($v) && !self::isList($v) && isset($v['@id']) && !array_diff(array_map('strval', array_keys($v)), ['@id', '@type', '@context']);
    }

    private static function str($v): ?string
    {
        if (is_string($v)) { $s = trim($v); return $s === '' ? null : $s; }
        if (is_int($v) || is_float($v)) return (string)$v;
        if (is_array($v)) {
            if (self::isList($v)) {
                foreach ($v as $x) { $s = self::str($x); if ($s !== null) return $s; }
                return null;
            }
            foreach (['name', '@value', 'text'] as $k) {
                if (isset($v[$k])) { $s = self::str($v[$k]); if ($s !== null) return $s; }
            }
        }
        return null;
    }

    private static function urlOf($v): ?string
    {
        if (is_string($v)) { $s = trim($v); return $s === '' ? null : $s; }
        if (is_array($v)) {
            if (self::isList($v)) {
                foreach ($v as $x) { $s = self::urlOf($x); if ($s !== null) return $s; }
                return null;
            }
            foreach (['url', 'contentUrl', '@id'] as $k) {
                if (isset($v[$k]) && is_string($v[$k]) && trim($v[$k]) !== '') return trim($v[$k]);
            }
        }
        return null;
    }

    private static function first($v): ?array
    {
        if (!is_array($v)) return null;
        if (self::isList($v)) {
            foreach ($v as $x) if (is_array($x) && !self::isList($x)) return $x;
            return null;
        }
        return $v;
    }

    private static function badUrl(string $u): ?string
    {
        $u = trim($u);
        if ($u === '') return 'порожнє значення';
        if (preg_match('#^data:#i', $u)) return 'вбудоване зображення data: замість URL';
        if (preg_match('#^https?://#i', $u)) {
            if (preg_match('#^https?://[^/?\#]*https?:#i', $u)) return 'дві адреси злиплися в одну';
            $h = (string)parse_url($u, PHP_URL_HOST);
            if ($h === '' || strpos($h, '.') === false) return 'некоректний домен';
        }
        return null;
    }

    private static function normUrl(string $u): string
    {
        $p = parse_url(trim($u));
        if (!$p || empty($p['host'])) return strtolower(rtrim(trim($u), '/'));
        $h = strtolower($p['host']);
        if (strpos($h, 'www.') === 0) $h = substr($h, 4);
        return $h . rtrim($p['path'] ?? '', '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    // ================================================================= 1. JSON-LD

    private function checkJsonLd(): ?int
    {
        $total = 0; $bad = [];
        foreach ($this->fetchedPages() as $pg) {
            foreach ($pg['blocks'] as $b) {
                $total++;
                if (!$b['ok']) $bad[] = ['pg' => $pg, 'b' => $b];
            }
        }
        $this->data['jsonld_blocks'] = $total;
        $this->data['jsonld_invalid'] = count($bad);
        if ($total === 0) return null;
        $score = (int)round(100 * ($total - count($bad)) / $total);

        if ($bad) {
            $first = $bad[0];
            $ev = [];
            $lostTypes = [];
            foreach (array_slice($bad, 0, 4) as $x) {
                $ev[] = self::ucf($this->where($x['pg'])) . ': ' . $x['b']['error'] . ' (' . self::errorPos((string)$x['b']['raw'], (int)$x['b']['offset']) . ')'
                    . ($x['b']['types'] ? ', у блоці ' . implode(', ', $x['b']['types']) : '');
            }
            foreach ($bad as $x) foreach ($x['b']['types'] as $t) $lostTypes[$t] = true;
            $rest = count($bad) - 4;
            $raw = (string)$first['b']['raw'];
            $frag = self::errorFragment($raw, (int)$first['b']['offset']);
            $good = 'Валідний JSON: ключі й рядки в подвійних лапках, без коми після останнього елемента, переноси рядків усередині тексту як \\n.';
            $code = '';
            if (is_array($first['b']['repaired'])) {
                $code = self::ldCode($first['b']['repaired'], $cnote);
                $good = $code !== '' ? 'Той самий блок у валідному JSON (див. готовий код нижче).' . ($cnote !== '' ? ' ' . $cnote : '')
                    : $good . ' ' . $cnote;
            }
            $this->findings[] = $this->finding('jsonld_errors', 'high', 'JSON-LD із синтаксичними помилками: Google і AI-парсери такий блок відкидають', [
                'evidence' => 'Невалідних блоків: ' . count($bad) . ' з ' . $total . '. ' . implode('; ', $ev) . ($rest > 0 ? '; та ще ' . $rest : '') . '.'
                    . ($lostTypes ? ' Типи в зламаних блоках (' . implode(', ', array_keys($lostTypes)) . ') у цій перевірці вважаються відсутніми, бо Google їх не прочитає.' : ''),
                'why'      => 'Пошукові системи розбирають JSON-LD суворим парсером: одна зайва кома чи неекранований перенос - і весь блок ігнорується, разом з усіма типами в ньому.',
                'fix'      => 'Генеруйте JSON-LD функцією серіалізації (json_encode / JSON.stringify), а не склеюванням рядків у шаблоні, і виправте позначене місце.',
                'bad'      => 'Помилка: ' . $first['b']['error'] . "\n" . $frag,
                'good'     => $good,
                'code'     => $code,
                'verify'   => 'Вставте URL у https://validator.schema.org/ - блок JSON-LD має розібратися без синтаксичних помилок. Те саме в Google Rich Results Test; у Search Console - без помилок у звіті «Unparsable structured data».',
                'effort'   => 'S',
            ]);
        } else {
            $this->passed[] = 'JSON-LD без синтаксичних помилок: ' . $total . ' ' . self::plural($total, 'блок', 'блоки', 'блоків') . ' на ' . count($this->fetchedPages()) . ' ' . self::plural(count($this->fetchedPages()), 'сторінці', 'сторінках', 'сторінках');
        }

        // HTML-сутності в значеннях (усередині <script> браузер їх не декодує)
        $ent = [];
        $decodedHome = null; $entPages = []; $bestRank = 99;
        foreach ($this->fetchedPages() as $pg) {
            $found = false;
            foreach ($pg['blocks'] as $bb) {
                if (!$bb['ok']) continue;
                $block = $bb['data'];
                $hits = [];
                self::findEntities($block, $hits, '', 0);
                if ($hits) {
                    $found = true;
                    foreach ($hits as $h) if (count($ent) < 3) $ent[] = $h + ['where' => $this->where($pg)];
                    // для коду: спершу не-BreadcrumbList, потім хлібні крихти внутрішньої сторінки, останньою - головна
                    $isCrumbs = is_array($block) && !self::isList($block) && self::typesOf($block) === ['BreadcrumbList'];
                    $rank = !$isCrumbs ? 0 : ($pg['role'] === 'home' ? 4 : (in_array($pg['role'], ['about', 'contact'], true) ? 2 : 1));
                    if ($isCrumbs && $rank < 4) {
                        // крихти, де останній пункт не схожий на назву сторінки, як зразок не беремо, якщо є кращі
                        $li = (array)($block['itemListElement'] ?? []);
                        $lastLi = $li ? end($li) : null;
                        $ln = is_array($lastLi) ? self::str($lastLi['name'] ?? null) : null;
                        $h1 = trim((string)($pg['page']->headings()['h1'][0] ?? ''));
                        if ($ln !== null && !$this->nameMatches($ln, [$h1, $pg['page']->title()])) $rank++;
                    }
                    if ($rank < $bestRank) {
                        $dec = self::decodeEntities($block);
                        if ($isCrumbs) $dec = self::dedupeCrumbs($dec);
                        if (strlen(self::jsonPretty($dec)) < 3000) { $decodedHome = $dec; $bestRank = $rank; }
                    }
                }
            }
            if ($found) $entPages[] = $this->where($pg);
        }
        if ($ent) {
            $ex = [];
            foreach ($ent as $h) $ex[] = '«' . $h['key'] . '»: «' . self::mbTrunc($h['value'], 150) . '» (' . $h['where'] . ')';
            $h0 = $ent[0];
            $score = max(0, $score - 10);
            $entSample = preg_match('/&(#\d{2,7}|#x[0-9a-f]{2,6}|[a-z]{2,6});/i', $h0['value'], $em) ? $em[0] : '&#...;';
            $this->findings[] = $this->finding('jsonld_entities', 'low', 'Текст у JSON-LD містить HTML-сутності («' . $entSample . '» замість звичайних символів)', [
                'evidence' => 'Сторінок із проблемою: ' . count($entPages) . ' (' . self::listSome($entPages, 4) . '). Приклади: ' . implode('; ', $ex) . '.',
                'why'      => 'Усередині <script> HTML-сутності не перетворюються на символи, тому машина отримує коди на кшталт «' . $entSample . '». Парсери, що їх не декодують, побачать спотворений текст замість назви чи опису.',
                'fix'      => 'Виводьте в JSON-LD звичайний UTF-8 текст (json_encode з JSON_UNESCAPED_UNICODE), без htmlspecialchars / HTML-кодування значень.',
                'bad'      => '"' . $h0['key'] . '": "' . self::mbTrunc($h0['value'], 160) . '"',
                'good'     => '"' . $h0['key'] . '": "' . self::mbTrunc(html_entity_decode($h0['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 160) . '"',
                'code'     => is_array($decodedHome) ? self::ldCode($decodedHome) : '',
                'verify'   => 'Відкрийте view-source: сторінки і знайдіть application/ld+json - назви мають читатися звичайними літерами. Перевірте в https://validator.schema.org/.',
                'effort'   => 'S',
            ]);
        }

        // @context
        $noCtx = [];
        foreach ($this->fetchedPages() as $pg) {
            foreach ($pg['blocks'] as $bb) {
                if (!$bb['ok']) continue;
                $block = $bb['data'];
                $items = self::isList($block) ? $block : [$block];
                foreach ($items as $it) {
                    if (is_array($it) && !self::isList($it) && (isset($it['@type']) || isset($it['@graph'])) && empty($it['@context'])) {
                        $noCtx[$this->where($pg)] = self::typesOf($it) ?: ['@graph'];
                    }
                }
            }
        }
        if ($noCtx) {
            $w = array_key_first($noCtx);
            $types = $noCtx[$w];
            $this->findings[] = $this->finding('jsonld_context', 'low', 'У JSON-LD блоці немає @context', [
                'evidence' => 'Блоки без "@context": ' . self::listSome(array_map(function ($k, $v) { return $k . ' (' . implode(', ', $v) . ')'; }, array_keys($noCtx), $noCtx), 4) . '.',
                'why'      => 'Без "@context": "https://schema.org" тип на кшталт "Organization" не прив\'язаний до словника schema.org, і валідатори не розпізнають розмітку.',
                'fix'      => 'Додайте "@context": "https://schema.org" на верхній рівень кожного JSON-LD блоку.',
                'bad'      => '{ "@type": "' . ($types[0] ?? 'Thing') . '", ... }',
                'good'     => '{ "@context": "https://schema.org", "@type": "' . ($types[0] ?? 'Thing') . '", ... }',
                'verify'   => 'https://validator.schema.org/ має показати тип ' . ($types[0] ?? '') . ' без помилок.',
                'effort'   => 'S',
            ]);
            $score = max(0, $score - 10);
        }
        return $score;
    }

    private static function findEntities($node, array &$hits, string $key, int $d): void
    {
        if ($d > 10 || count($hits) >= 5) return;
        if (is_string($node)) {
            if (preg_match('/&(#\d{2,7}|#x[0-9a-f]{2,6}|quot|amp|apos|nbsp|laquo|raquo|lt|gt|mdash|ndash|rsquo|lsquo|ldquo|rdquo|hellip);/i', $node, $m, PREG_OFFSET_CAPTURE)) {
                $off = (int)$m[0][1];
                $value = $node;
                if (strlen($node) > 120) {
                    $start = max(0, $off - 60);
                    $value = ($start > 0 ? '... ' : '') . mb_strcut($node, $start, 140, 'UTF-8') . (strlen($node) > $start + 140 ? ' ...' : '');
                }
                $hits[] = ['key' => $key, 'value' => $value];
            }
            return;
        }
        if (!is_array($node)) return;
        foreach ($node as $k => $v) self::findEntities($v, $hits, is_int($k) ? $key : (string)$k, $d + 1);
    }

    /** BreadcrumbList без пунктів з однаковою адресою (лишається перший), позиції перенумеровано. */
    private static function dedupeCrumbs(array $bl): array
    {
        $items = $bl['itemListElement'] ?? null;
        if (!is_array($items) || !self::isList($items)) return $bl;
        $seen = []; $out = [];
        foreach ($items as $li) {
            if (!is_array($li)) continue;
            $u = self::urlOf($li['item'] ?? null);
            if ($u !== null) {
                $k = self::normUrl($u);
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
            }
            $li['position'] = count($out) + 1;
            $out[] = $li;
        }
        $bl['itemListElement'] = $out;
        return $bl;
    }

    private static function decodeEntities($node)
    {
        if (is_string($node)) return html_entity_decode($node, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (is_array($node)) {
            $out = [];
            foreach ($node as $k => $v) $out[$k] = self::decodeEntities($v);
            return $out;
        }
        return $node;
    }

    /** Мінімальний суворий JSON-сканер: [позиція в байтах, опис помилки українською]. */
    private static function jsonErrorAt(string $s): array
    {
        $i = 0;
        $err = self::jsonValue($s, $i, 0);
        if ($err === null) {
            self::jsonWs($s, $i);
            if ($i < strlen($s)) $err = 'зайві символи після кінця JSON (можливо, два об\'єкти підряд без масиву)';
        }
        if ($err === null) return [0, 'некоректний JSON (' . json_last_error_msg() . ')'];
        return [$i, $err];
    }

    private static function jsonWs(string $s, int &$i): void
    {
        $n = strlen($s);
        while ($i < $n && strpos(" \t\r\n", $s[$i]) !== false) $i++;
    }

    private static function jsonValue(string $s, int &$i, int $depth): ?string
    {
        $n = strlen($s);
        if ($depth > 200) return 'занадто глибока вкладеність';
        self::jsonWs($s, $i);
        if ($i >= $n) return 'неочікуваний кінець блоку';
        $c = $s[$i];
        if ($c === '{' || $c === '[') {
            $close = $c === '{' ? '}' : ']';
            $isObj = $c === '{';
            $i++;
            self::jsonWs($s, $i);
            if ($i < $n && $s[$i] === $close) { $i++; return null; }
            while (true) {
                self::jsonWs($s, $i);
                if ($isObj) {
                    if ($i >= $n) return 'неочікуваний кінець блоку';
                    if ($s[$i] === "'") return 'одинарні лапки замість подвійних';
                    if ($s[$i] !== '"') return 'очікувався ключ у подвійних лапках';
                    $e = self::jsonString($s, $i);
                    if ($e !== null) return $e;
                    self::jsonWs($s, $i);
                    if ($i >= $n || $s[$i] !== ':') return 'очікувалась двокрапка після ключа';
                    $i++;
                }
                $e = self::jsonValue($s, $i, $depth + 1);
                if ($e !== null) return $e;
                self::jsonWs($s, $i);
                if ($i >= $n) return 'неочікуваний кінець блоку (не закрито ' . $close . ')';
                if ($s[$i] === ',') {
                    $i++;
                    self::jsonWs($s, $i);
                    if ($i < $n && $s[$i] === $close) return 'зайва кома перед ' . $close;
                    continue;
                }
                if ($s[$i] === $close) { $i++; return null; }
                return 'очікувалась кома або ' . $close;
            }
        }
        if ($c === '"') return self::jsonString($s, $i);
        if ($c === "'") return 'одинарні лапки замість подвійних';
        if (preg_match('/-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?/A', $s, $m, 0, $i)) { $i += strlen($m[0]); return null; }
        foreach (['true', 'false', 'null'] as $lit) {
            if (substr($s, $i, strlen($lit)) === $lit) { $i += strlen($lit); return null; }
        }
        return 'неочікуваний символ «' . mb_substr(mb_strcut($s, $i, 4, 'UTF-8'), 0, 1) . '»';
    }

    private static function jsonString(string $s, int &$i): ?string
    {
        $n = strlen($s);
        $i++; // відкриваюча лапка
        while ($i < $n) {
            $ch = $s[$i];
            if ($ch === '"') { $i++; return null; }
            if ($ch === '\\') {
                $nx = $s[$i + 1] ?? '';
                if ($nx === 'u') {
                    if (!preg_match('/[0-9a-fA-F]{4}/A', $s, $m, 0, $i + 2)) return 'неправильна послідовність \\u';
                    $i += 6;
                    continue;
                }
                if ($nx === '' || strpos('"\\/bfnrt', $nx) === false) return 'неправильне екранування «\\' . $nx . '»';
                $i += 2;
                continue;
            }
            if (ord($ch) < 0x20) return 'сирий перенос рядка або табуляція всередині рядка';
            $i++;
        }
        return 'незакритий рядок (немає лапки в кінці)';
    }

    /** Ремонт для «як треба»: висячі коми, сирі керуючі символи, кілька об'єктів поспіль. */
    private static function repairJson(string $s): ?array
    {
        $t = (string)preg_replace('/[\x00-\x1F]/', ' ', $s);
        $t = (string)preg_replace('/,\s*([}\]])/', '$1', $t);
        $t = (string)preg_replace('/;\s*$/', '', $t);
        $d = json_decode($t, true);
        if (is_array($d)) return $d;
        $d = json_decode('[' . preg_replace('/}\s*{/', '},{', $t) . ']', true);
        return is_array($d) ? $d : null;
    }

    /** Позиція помилки для людини: «рядок L, символ C» (символи, не байти - кирилиця займає 2 байти). */
    private static function errorPos(string $raw, int $off): string
    {
        $before = mb_strcut($raw, 0, max(0, $off), 'UTF-8');
        $line = substr_count($before, "\n") + 1;
        $nl = strrpos($before, "\n");
        $col = mb_strlen($nl === false ? $before : substr($before, $nl + 1)) + 1;
        return $line > 1 ? 'рядок ' . $line . ', символ ' . $col : 'символ ' . $col;
    }

    private static function errorFragment(string $raw, int $off): string
    {
        $start = max(0, $off - 160);
        $before = mb_strcut($raw, $start, $off - $start, 'UTF-8');
        $after = mb_strcut($raw, $off, 120, 'UTF-8');
        $norm = function ($x) { return (string)preg_replace('/\s+/u', ' ', $x); };
        return ($start > 0 ? '... ' : '') . $norm($before) . ' <<< тут >>> ' . $norm($after) . (strlen($raw) > $off + 120 ? ' ...' : '');
    }

    // ================================================================= 2. Entity

    /** @return array{0:?int,1:?int} [entity, entity_props] */
    private function checkEntity(): array
    {
        $local = $this->stype === 'local';
        $entityPages = [];
        foreach ($this->pages as $i => $pg) {
            if (in_array($pg['role'], ['home', 'about', 'contact'], true)) $entityPages[$i] = $pg;
        }

        $groups = $this->entityGroups();

        // основна група: є на головній / «Про нас» / «Контакти», найбагатша
        $best = null; $bestScore = -1;
        foreach ($groups as $key => $list) {
            if (strpos((string)$key, 'b:') === 0) continue; // окремі вузли сторінок філій - не головна сутність
            $onEntityPage = false; $onHome = false; $top = false;
            foreach ($list as $x) {
                if (isset($entityPages[$x['pi']])) $onEntityPage = true;
                if ($this->pages[$x['pi']]['role'] === 'home') $onHome = true;
                if ($x['node']['top']) $top = true;
            }
            if (!$onEntityPage) continue;
            $m = $this->mergeEntity($list);
            $s = $m['present_count'] + ($onHome ? 2 : 0) + ($top ? 1 : 0);
            if ($s > $bestScore) { $bestScore = $s; $best = $key; }
        }
        if ($best === null) {
            // на головній / «Про нас» немає, але є окремий блок на інших сторінках (напр. головна недоступна)
            foreach ($groups as $key => $list) {
                if (strpos((string)$key, 'b:') === 0) continue;
                $top = false;
                foreach ($list as $x) if ($x['node']['top'] && $this->pages[$x['pi']]['role'] !== 'branch') $top = true;
                if (!$top) continue;
                $m = $this->mergeEntity($list);
                if ($m['present_count'] > $bestScore) { $bestScore = $m['present_count']; $best = $key; }
            }
        }
        $orgElsewhere = [];
        foreach ($groups as $key => $list) {
            if ($key === $best) continue;
            foreach ($list as $x) if (!$x['node']['top']) $orgElsewhere[$this->where($this->pages[$x['pi']])] = true;
        }

        $this->collectBranchLb($groups, $best);

        if ($best === null) {
            $this->E = null;
            $this->noEntityFinding($entityPages, array_keys($orgElsewhere));
            $this->data['entity'] = null;
            // філії розмічені, хоча загальної сутності немає - часткова оцінка
            return [$this->branchLb ? 50 : 0, null];
        }

        $list = $groups[$best];
        $E = $this->mergeEntity($list);
        $this->E = $E;
        $this->data['entity'] = ['type' => $E['type'], 'name' => $E['name'], 'found_on' => $E['where'], 'present' => $E['present'], 'missing' => $E['missing']];

        // entity score
        $entityScore = $E['top'] ? 100 : 60;
        if ($local && !$E['is_lb'] && !$this->branchLb) $entityScore = min($entityScore, 70);

        // props score
        $w = $local
            ? ['name' => 15, 'url' => 10, 'logo' => 10, 'sameAs' => 10, 'contact' => 20, 'address' => 35]
            : ['name' => 20, 'url' => 15, 'logo' => 20, 'sameAs' => 15, 'contact' => 20, 'address' => 10];
        $grade = $E['grade'];
        // мережа: адреси - у блоках філій, від головної сутності адреса не потрібна
        if ($local && !$E['is_lb'] && $this->branchLb) $grade['address'] = 1.0;
        $ps = 0.0;
        foreach ($w as $k => $wt) $ps += $wt * ($grade[$k] ?? 0.0);

        // помилки значень (між сторінками)
        $issues = $this->entityValueIssues($list, $E, $groups, (string)$best);
        $hard = array_filter($issues, function ($x) { return $x['hard']; });
        if ($hard) $ps -= 10 * min(2, count($hard));
        $propsScore = (int)max(0, min(100, round($ps)));

        $typeLabel = $E['type'];
        $whereStr = self::listSome($E['where'], 3);
        $sameN = count($E['sameAs']);
        $presentStr = [];
        foreach ($E['present'] as $p) $presentStr[] = $p === 'sameAs' ? 'sameAs (' . $sameN . ' ' . self::plural($sameN, 'профіль', 'профілі', 'профілів') . ')' : $p;
        $this->passed[] = $typeLabel . ' «' . self::mbTrunc((string)$E['name'], 40) . '» (' . $whereStr . '): ' . implode(', ', $presentStr);
        if (!$E['top']) {
            $this->passed[] = 'Організацію описано лише вкладеним об\'єктом (' . $E['nested_prop'] . '), окремого блоку Organization немає';
        }

        // знахідка про відсутні властивості (для local її поглинає local_* у checkLocal)
        $missingCore = $E['missing'];
        $this->data['entity_missing'] = $missingCore;
        if ($missingCore && !$local) {
            // для нелокальних сайтів адреса - рекомендована: якщо бракує лише її, пріоритет низький
            $epSev = array_diff($missingCore, ['address']) ? 'medium' : 'low';
            $epCode = $this->entityCode(false);
            $this->findings[] = $this->finding('entity_props', $epSev, 'У розмітці ' . $typeLabel . ' бракує: ' . implode(', ', $missingCore), [
                'evidence' => $typeLabel . ' знайдено (' . $whereStr . '). Є: ' . implode(', ', $presentStr) . '. Немає: ' . implode(', ', $this->missingExplained($E)) . '.' . $this->factsLine()
                    . $this->descNote($E) . ($E['address'] !== null ? $this->codeAddrNote : ''),
                'why'      => 'Organization - картка компанії для пошукових систем і AI: логотип, контакти й профілі в соцмережах (sameAs) допомагають однозначно впізнати бренд і пов\'язати згадки про нього в мережі.',
                'fix'      => 'Доповніть наявний блок відсутніми властивостями (готовий код нижче вже містить знайдені на сайті дані) і розмістіть його в спільному шаблоні.',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($E['node'])), 560),
                'good'     => $this->goodEntityShort($E),
                'code'     => self::ldCode($epCode),
                'verify'   => 'Перевірте головну в https://validator.schema.org/ (тип ' . $typeLabel . ' без помилок) і в Google Rich Results Test. Логотип і профілі мають відкриватися за посиланнями.',
                'effort'   => 'S',
            ]);
        }

        if ($issues) $this->entityValuesFinding($issues, $E);
        return [$entityScore, $propsScore];
    }

    // ================================================================= 2b. Person (особистий сайт експерта)

    /**
     * Особистий сайт: головна сутність - Person (name, url, image, jobTitle, sameAs, knowsAbout), бажано разом з ProfilePage.
     * Organization / LocalBusiness замість Person не рекомендуємо.
     * @return array{0:?int,1:?int} [entity, entity_props]
     */
    private function checkPerson(): array
    {
        $homePg = $this->pages[0];
        $hp = ($homePg['page'] instanceof Page) ? SiteProfile::homePerson($homePg['page']) : null;
        $this->homePerson = $hp;
        $mainId = ($hp && $hp['id'] !== '') ? self::normId($hp['id']) : '';
        $list = [];
        foreach ($this->pages as $pi => $pg) {
            if (!($pg['page'] instanceof Page) || !in_array($pg['role'], ['home', 'about', 'contact'], true)) continue;
            foreach ($pg['nodes'] as $n) {
                if (!self::isType($n, ['Person'])) continue;
                $name = self::str($n['data']['name'] ?? null);
                if ($hp) {
                    // лише головна особа сайту: той самий @id або те саме ім'я (автори статей, колеги - інші особи)
                    $same = ($mainId !== '' && self::nodeId($n['data']) === $mainId) || ($name !== null && SiteProfile::personNameMatches($hp, $name));
                    if (!$same) continue;
                } elseif ($name === null || (!$n['top'] && !in_array((string)$n['prop'], ['mainEntity', 'about', 'publisher'], true))) {
                    continue;
                }
                $list[] = ['pi' => $pi, 'node' => $n];
            }
        }
        if (!$hp && $list) {
            // головну особу на головній не визначено: особа з ім'ям, як у назві сайту, інакше перша знайдена
            $byBrand = array_values(array_filter($list, function ($x) { return SiteProfile::sameName((string)self::str($x['node']['data']['name'] ?? null), $this->brand); }));
            $firstName = (string)self::str($list[0]['node']['data']['name'] ?? null);
            $list = $byBrand ?: array_values(array_filter($list, function ($x) use ($firstName) { return SiteProfile::sameName((string)self::str($x['node']['data']['name'] ?? null), $firstName); }));
        }
        if (!$list) {
            $this->data['entity'] = null;
            $this->noPersonFinding();
            return [0, null];
        }

        $P = $this->mergePerson($list);
        $this->P = $P;
        $isMain = $hp && $hp['strong'];
        $this->data['entity'] = ['type' => 'Person', 'name' => $P['name'], 'found_on' => $P['where'], 'present' => $P['present'], 'missing' => $P['missing'],
            'main_entity' => $isMain ? $hp['via'] : null];
        $entityScore = ($P['top'] || $isMain) ? 100 : 60;
        $w = ['name' => 15, 'url' => 10, 'image' => 15, 'jobTitle' => 15, 'sameAs' => 25, 'knowsAbout' => 20];
        $ps = 0.0;
        foreach ($w as $k => $wt) $ps += $wt * ($P['grade'][$k] ?? 0.0);
        $propsScore = (int)max(0, min(100, round($ps)));

        $whereStr = self::listSome($P['where'], 3);
        $this->passed[] = 'Person «' . self::mbTrunc((string)$P['name'], 40) . '» (' . $whereStr . '): ' . implode(', ', $P['present']);
        if ($isMain) $this->passed[] = 'Person - головна сутність головної (' . $hp['via'] . ')';
        elseif (!$P['top']) $this->passed[] = 'Особу описано лише вкладеним об\'єктом (' . $P['nested_prop'] . '), окремого блоку Person немає';

        if ($P['missing']) {
            $core = array_intersect($P['missing'], ['name', 'url', 'jobTitle', 'sameAs']);
            $this->findings[] = $this->finding('person_props', $core ? 'medium' : 'low', 'У розмітці Person бракує: ' . implode(', ', $P['missing']), [
                'evidence' => 'Person «' . (string)$P['name'] . '» (' . $whereStr . '). Є: ' . implode(', ', $P['present']) . '. Немає: ' . implode(', ', $P['missing']) . '.' . $this->factsLine(),
                'why'      => 'Person - картка експерта для пошукових систем і AI: посада (jobTitle), теми експертизи (knowsAbout), фото і профілі (sameAs) допомагають однозначно впізнати людину і пов\'язати згадки про неї в мережі з цим сайтом.',
                'fix'      => 'Доповніть наявний блок Person відсутніми властивостями (готовий код нижче зберігає поточні значення і додає знайдені на сайті профілі).',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($P['node'])), 560),
                'good'     => '{"@type": "Person", "name": "' . (string)$P['name'] . '", "url": "' . $this->homeUrl . '", "image": "...", "jobTitle": "...", "knowsAbout": [...], "sameAs": [...]}',
                'code'     => self::ldCode($this->personCode()),
                'verify'   => 'Перевірте головну в https://validator.schema.org/: Person без помилок, ProfilePage посилається на нього через mainEntity.',
                'effort'   => 'S',
            ]);
        }
        return [$entityScore, $propsScore];
    }

    /** Злиття вузлів однієї особи (головна першою, верхній рівень першим). */
    private function mergePerson(array $list): array
    {
        usort($list, function ($a, $b) {
            $ra = $this->pages[$a['pi']]['role'] === 'home' ? 0 : 1;
            $rb = $this->pages[$b['pi']]['role'] === 'home' ? 0 : 1;
            if ($ra !== $rb) return $ra <=> $rb;
            $ta = $a['node']['top'] ? 0 : 1; $tb = $b['node']['top'] ? 0 : 1;
            if ($ta !== $tb) return $ta <=> $tb;
            return $a['pi'] <=> $b['pi'];
        });
        $get = function (string $key) use ($list) {
            foreach ($list as $x) {
                $v = $this->val($x['node']['data'], $key, $this->pages[$x['pi']]);
                if ($v !== null) return $v;
            }
            return null;
        };
        $where = []; $top = false; $nestedProp = '';
        foreach ($list as $x) {
            $pg = $this->pages[$x['pi']];
            $where[$pg['role'] === 'home' ? 'головна' : $this->where($pg)] = true;
            if ($x['node']['top']) $top = true;
            elseif ($nestedProp === '') $nestedProp = (string)$x['node']['prop'];
        }
        $name = self::str($get('name'));
        $url = self::urlOf($get('url'));
        $image = self::urlOf($get('image'));
        $job = self::str($get('jobTitle'));
        $same = [];
        foreach ((array)($get('sameAs') ?? []) as $s) {
            if (!is_string($s) || !preg_match('#^https?://#i', trim($s)) || Crawler::sameSite(trim($s), $this->base)) continue;
            $k = self::profileKey(trim($s));
            if (!isset($same[$k])) $same[$k] = trim($s);
        }
        $same = array_values($same);
        $ka = $get('knowsAbout');
        $knows = [];
        foreach ((is_array($ka) && self::isList($ka)) ? $ka : ($ka !== null ? [$ka] : []) as $v) {
            $t = self::str($v);
            if ($t !== null) $knows[] = $t;
        }
        $grade = [
            'name' => $name !== null ? 1.0 : 0.0, 'url' => $url !== null ? 1.0 : 0.0, 'image' => $image !== null ? 1.0 : 0.0,
            'jobTitle' => $job !== null ? 1.0 : 0.0, 'sameAs' => count($same) >= 2 ? 1.0 : (count($same) === 1 ? 0.75 : 0.0),
            'knowsAbout' => $knows ? 1.0 : 0.0,
        ];
        $present = []; $missing = [];
        foreach (['name', 'url', 'image', 'jobTitle', 'sameAs', 'knowsAbout'] as $k) {
            if ($grade[$k] <= 0) { $missing[] = $k; continue; }
            if ($k === 'sameAs') $present[] = 'sameAs (' . count($same) . ' ' . self::plural(count($same), 'профіль', 'профілі', 'профілів') . ')';
            elseif ($k === 'knowsAbout') $present[] = 'knowsAbout (' . count($knows) . ' ' . self::plural(count($knows), 'тема', 'теми', 'тем') . ')';
            else $present[] = $k;
        }
        if ($job === null && $get('hasOccupation') !== null) $present[] = 'hasOccupation (jobTitle краще вказати окремо)';
        $first = $list[0]['node']['data'];
        return [
            'name' => $name, 'url' => $url, 'image' => $image, 'jobTitle' => $job, 'sameAs' => $same, 'knowsAbout' => $knows,
            'description' => self::str($get('description')), 'alternateName' => $get('alternateName'),
            'where' => array_keys($where), 'top' => $top, 'nested_prop' => $nestedProp, 'grade' => $grade,
            'present' => $present, 'missing' => $missing, 'node' => $first,
            'id' => isset($first['@id']) && is_string($first['@id']) ? $first['@id'] : null,
        ];
    }

    private function noPersonFinding(): void
    {
        $checked = []; $orgs = [];
        foreach ($this->pages as $pg) {
            if (!in_array($pg['role'], ['home', 'about', 'contact'], true)) continue;
            if (!($pg['page'] instanceof Page)) { $checked[] = $this->where($pg) . ' (' . $pg['note'] . ')'; continue; }
            $checked[] = $this->where($pg) . ' - ' . $this->typesLine($pg);
            foreach (self::nodesOf($pg, ['Organization'], true) as $n) {
                $nm = self::str($n['data']['name'] ?? null);
                if ($nm !== null) $orgs[$nm] = true;
            }
        }
        $home = $this->pages[0];
        $this->findings[] = $this->finding('no_person', 'high', 'Немає розмітки Person: особу не описано як сутність', [
            'evidence' => 'Перевірено: ' . implode('; ', $checked) . '.'
                . ($orgs ? ' Є лише Organization «' . self::listSome(array_keys($orgs), 2) . '» - для особистого сайту експерта головною сутністю має бути Person (Organization можна лишити як допоміжну).' : '')
                . $this->factsLine(),
            'why'      => 'Person з посадою, темами експертизи і профілями (sameAs) пов\'язує сайт, ім\'я і профілі в LinkedIn, GitHub, YouTube в одну сутність. Без неї AI гірше розуміє, хто стоїть за сайтом, і рідше впевнено називає вас як експерта.',
            'fix'      => 'Додайте на головну (і «Про мене») JSON-LD ProfilePage з mainEntity -> Person: name, url, image, jobTitle, knowsAbout, sameAs. Готовий код нижче заповнено даними із сайту; перевірте поля [ВКАЖІТЬ ...].',
            'bad'      => $home['page'] instanceof Page ? 'Розмітка на головній: ' . $this->typesLine($home) : 'Головна недоступна: ' . $home['note'],
            'good'     => '{"@type": "ProfilePage", "mainEntity": {"@type": "Person", "name": "' . $this->brand . '", "url": "' . $this->homeUrl . '", "image": "...", "jobTitle": "...", "knowsAbout": [...], "sameAs": [...]}}',
            'code'     => self::ldCode($this->personCode()),
            'verify'   => 'Перевірте головну в https://validator.schema.org/ - мають з\'явитися ProfilePage і Person без помилок.',
            'effort'   => 'S',
        ]);
    }

    private function personId(): string
    {
        $id = $this->P['id'] ?? ($this->homePerson['id'] ?? '');
        if ($id !== '' && preg_match('#^https?://#i', (string)$id) && Crawler::sameSite((string)$id, $this->base)) return (string)$id;
        return $this->base . '/#person';
    }

    /** Готовий блок ProfilePage + Person з реальними даними сайту. */
    private function personCode(): array
    {
        $P = $this->P;
        $f = $this->facts;
        $p = ['@type' => 'Person', '@id' => $this->personId()];
        $p['name'] = ($P && $P['name']) ? $P['name'] : $this->brand;
        if ($P && $P['alternateName'] !== null) $p['alternateName'] = $P['alternateName'];
        $p['url'] = $this->homeUrl;
        $p['image'] = ($P && $P['image']) ? $P['image'] : ($f['og_image'] ?? '[ВКАЖІТЬ URL фото]');
        $p['jobTitle'] = ($P && $P['jobTitle']) ? $P['jobTitle'] : '[ВКАЖІТЬ посаду / спеціалізацію]';
        $desc = ($P && $P['description']) ? $P['description'] : ($f['description'] ?? null);
        if ($this->isRussianText($desc)) $desc = '[ВКАЖІТЬ короткий опис українською]';
        if ($desc) $p['description'] = self::mbTrunc($desc, 250);
        $p['knowsAbout'] = ($P && $P['knowsAbout']) ? array_slice($P['knowsAbout'], 0, 12) : ['[ВКАЖІТЬ тему експертизи]', '[ВКАЖІТЬ ще одну тему]'];
        $same = [];
        foreach (array_merge($P['sameAs'] ?? [], $f['socials'] ?? []) as $s) {
            $k = self::profileKey($s);
            if (!isset($same[$k])) $same[$k] = $s;
        }
        $same = array_slice(array_values($same), 0, 12);
        if (count($same) < 2) $same[] = '[ВКАЖІТЬ URL ' . ($same ? 'ще одного профілю' : 'профілю') . ': LinkedIn / GitHub / YouTube]';
        $p['sameAs'] = $same;
        $page = ['@type' => 'ProfilePage', '@id' => $this->homeUrl . '#profilepage', 'url' => $this->homeUrl, 'mainEntity' => ['@id' => $p['@id']]];
        return ['@context' => 'https://schema.org', '@graph' => [$page, $p]];
    }

    /** ProfilePage з mainEntity -> Person на головній або «Про мене». */
    private function checkProfilePage(): ?int
    {
        $best = null;
        foreach ($this->pages as $pg) {
            if (!($pg['page'] instanceof Page) || !in_array($pg['role'], ['home', 'about'], true)) continue;
            foreach (self::nodesOf($pg, ['ProfilePage']) as $n) {
                $me = $this->val($n['data'], 'mainEntity', $pg) ?? $this->val($n['data'], 'about', $pg);
                $isP = false;
                foreach ((is_array($me) && self::isList($me)) ? $me : [$me] as $x) {
                    if (is_array($x) && self::isType($x, ['Person'])) $isP = true;
                }
                if ($best === null || ($isP && !$best['person'])) $best = ['pg' => $pg, 'node' => $n, 'person' => $isP];
            }
        }
        if ($best && $best['person']) {
            $this->passed[] = 'ProfilePage з mainEntity -> Person (' . $this->where($best['pg']) . ')';
            return 100;
        }
        if (!$this->P) return 0; // no_person уже описано, з готовим кодом
        if ($best) {
            $this->findings[] = $this->finding('profilepage_props', 'low', 'ProfilePage не вказує, чий це профіль: немає mainEntity -> Person', [
                'evidence' => 'ProfilePage знайдено (' . $this->where($best['pg']) . '), але mainEntity / about не посилається на Person «' . (string)$this->P['name'] . '».',
                'why'      => 'mainEntity каже пошуковим системам, що сторінка - профіль саме цієї людини. Так факти зі сторінки (досвід, кейси) прив\'язуються до особи.',
                'fix'      => 'Додайте в ProfilePage "mainEntity": {"@id": "' . $this->personId() . '"} - посилання на наявний блок Person.',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($best['node']['data'])), 400),
                'good'     => '"mainEntity": {"@id": "' . $this->personId() . '"}',
                'code'     => self::ldCode($this->personCode()),
                'verify'   => 'https://validator.schema.org/ - у ProfilePage є mainEntity з типом Person.',
                'effort'   => 'S',
            ]);
            return 70;
        }
        $via = (string)($this->homePerson['via'] ?? '');
        $webPageMain = $via !== '' && strpos($via, 'Page.') !== false;
        $this->findings[] = $this->finding('profilepage_missing', 'low', 'Немає ProfilePage: сторінку не позначено як профіль особи', [
            'evidence' => 'Person «' . (string)$this->P['name'] . '» є (' . self::listSome($this->P['where'], 2) . '), але ProfilePage на головній і «Про мене» немає'
                . ($webPageMain ? '; зараз особу вказано як ' . $via . '.' : '.'),
            'why'      => 'ProfilePage - тип, яким Google пропонує позначати сторінки профілю авторів (людей чи організацій): разом з Person він прямо каже, що сторінка описує цю особу, і допомагає Google зрозуміти, хто автор публікацій.',
            'fix'      => 'Додайте ProfilePage з mainEntity -> Person на головну (або на сторінку «Про мене»). Готовий код нижче зберігає наявні дані Person.',
            'bad'      => 'Розмітка на головній: ' . $this->typesLine($this->pages[0]),
            'good'     => '{"@type": "ProfilePage", "mainEntity": {"@id": "' . $this->personId() . '"}}',
            'code'     => self::ldCode($this->personCode()),
            'verify'   => 'Google Rich Results Test для головної - тип «Сторінка профілю» (ProfilePage) без помилок.',
            'effort'   => 'S',
        ]);
        return $webPageMain ? 80 : 60;
    }

    /**
     * Групи вузлів Organization (і підтипів): спершу за @id (різні @id - різні сутності, напр. філії мережі),
     * вузли без @id приєднуються за назвою. Вузли без @id зі сторінок філій лишаються окремими ('b:...').
     * @return array<string, array<int, array{pi:int, node:array}>>
     */
    private function entityGroups(): array
    {
        $cands = [];
        foreach ($this->pages as $pi => $pg) {
            if (!($pg['page'] instanceof Page)) continue;
            foreach ($pg['nodes'] as $n) {
                if (!self::isType($n, ['Organization'])) continue;
                if (!$n['top'] && !in_array((string)$n['prop'], self::ENTITY_PROPS_OK, true)) continue;
                $cands[] = ['pi' => $pi, 'node' => $n];
            }
        }
        $groups = []; $nameKeys = [];
        // 1) за @id
        foreach ($cands as $x) {
            $id = self::nodeId($x['node']['data']);
            if ($id === '') continue;
            $key = 'i:' . $id;
            $groups[$key][] = $x;
            $nm = $this->nodeName($x['node']['data']);
            if ($nm !== '') $nameKeys[$nm][$key] = true;
        }
        // 2) без @id: за назвою
        foreach ($cands as $i => $x) {
            if (self::nodeId($x['node']['data']) !== '') continue;
            $nm = $this->nodeName($x['node']['data']);
            if ($this->pages[$x['pi']]['role'] === 'branch') { $groups['b:' . $x['pi'] . ':' . $i][] = $x; continue; }
            if ($nm === '') { $groups['a:' . $x['pi'] . ':' . $i][] = $x; continue; }
            $keys = array_keys($nameKeys[$nm] ?? []);
            $key = null;
            if (count($keys) === 1) {
                $key = $keys[0];
            } elseif (count($keys) > 1) {
                // кілька сутностей з такою назвою (мережа) - приєднуємо до тієї, де та сама вулиця
                $st = $this->streetKey($x['node']['data'], $this->pages[$x['pi']]);
                if ($st !== '') foreach ($keys as $k) {
                    if (in_array($st, $this->groupStreets($groups[$k]), true)) { $key = $k; break; }
                }
            }
            $groups[$key ?? 'n:' . $nm][] = $x;
        }
        return $groups;
    }

    private static function nodeId(array $d): string
    {
        return isset($d['@id']) && is_string($d['@id']) && trim($d['@id']) !== '' ? self::normId($d['@id']) : '';
    }

    private function nodeName(array $d): string
    {
        $n = self::str($d['name'] ?? null);
        return $n === null ? '' : mb_strtolower(trim(html_entity_decode($n, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** Нормалізована вулиця вузла ('' якщо немає). */
    private function streetKey(array $d, ?array $pg): string
    {
        $a = self::addressParts($this->val($d, 'address', $pg));
        $st = $a['street'] ?? $a['string'];
        if ($st === null) return '';
        return trim(mb_strtolower((string)preg_replace('/[\s.,]+/u', ' ', html_entity_decode($st, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }

    private function groupStreets(array $list): array
    {
        $out = [];
        foreach ($list as $x) {
            $s = $this->streetKey($x['node']['data'], $this->pages[$x['pi']]);
            if ($s !== '') $out[$s] = true;
        }
        return array_keys($out);
    }

    /**
     * Філії: LocalBusiness-вузли з адресою поза основною сутністю - зі сторінок філій,
     * або з інших сторінок, якщо їхня вулиця відрізняється від адреси основної сутності.
     */
    private function collectBranchLb(array $groups, ?string $best): void
    {
        $this->branchLb = [];
        $eStreets = ($best !== null && isset($groups[$best])) ? $this->groupStreets($groups[$best]) : [];
        $other = []; $seen = [];
        foreach ($groups as $key => $list) {
            if ($key === $best) continue;
            foreach ($list as $x) {
                if (!$x['node']['top'] || !self::isType($x['node'], ['LocalBusiness'])) continue;
                $pg = $this->pages[$x['pi']];
                $st = $this->streetKey($x['node']['data'], $pg);
                if ($st === '' || in_array($st, $eStreets, true) || isset($seen[$st])) continue;
                $seen[$st] = true;
                if ($pg['role'] === 'branch') $this->branchLb[] = $x;
                else $other[] = $x;
            }
        }
        // з головної / «Контактів»: лише якщо в основної сутності є своя вулиця або таких блоків кілька (мережа)
        if ($other && ($eStreets || count($other) >= 2)) $this->branchLb = array_merge($this->branchLb, $other);
        $this->data['branches_marked'] = count($this->branchLb);
    }

    /** Злиття вузлів однієї організації (головна першою, верхній рівень першим). */
    private function mergeEntity(array $list): array
    {
        usort($list, function ($a, $b) {
            $ra = $this->pages[$a['pi']]['role'] === 'home' ? 0 : 1;
            $rb = $this->pages[$b['pi']]['role'] === 'home' ? 0 : 1;
            if ($ra !== $rb) return $ra <=> $rb;
            $ta = $a['node']['top'] ? 0 : 1; $tb = $b['node']['top'] ? 0 : 1;
            if ($ta !== $tb) return $ta <=> $tb;
            return $a['pi'] <=> $b['pi'];
        });
        $types = []; $where = []; $top = false; $nestedProp = '';
        $get = function (string $key) use ($list) {
            foreach ($list as $x) {
                $pg = $this->pages[$x['pi']];
                $v = $this->val($x['node']['data'], $key, $pg);
                if ($v !== null) return $v;
            }
            return null;
        };
        foreach ($list as $x) {
            foreach ($x['node']['types'] as $t) $types[$t] = true;
            $role = $this->pages[$x['pi']]['role'];
            if ($x['node']['top'] || in_array($role, ['home', 'about', 'contact'], true)) {
                $where[$role === 'home' ? 'головна' : $this->where($this->pages[$x['pi']])] = true;
            }
            if ($x['node']['top']) $top = true;
            elseif ($nestedProp === '') $nestedProp = (string)$x['node']['prop'];
        }
        $types = array_keys($types);
        $lbSet = Page::expandTypes(['LocalBusiness']);
        $isLb = false;
        foreach ($types as $t) if (isset($lbSet[strtolower($t)])) $isLb = true;
        // найконкретніший тип для підпису
        $type = $types[0] ?? 'Organization';
        foreach ($types as $t) if (!in_array($t, ['Organization', 'LocalBusiness', 'Place', 'Thing'], true)) { $type = $t; break; }
        if (in_array($type, ['Organization', 'Place', 'Thing'], true) && in_array('LocalBusiness', $types, true)) $type = 'LocalBusiness';

        $name = self::str($get('name'));
        $url = self::urlOf($get('url'));
        $logoV = $get('logo');
        $logo = self::urlOf($logoV);
        if ($logo === null && is_array($logoV) && self::isRef($logoV)) $logo = '(посилання ' . (string)$logoV['@id'] . ')';
        $image = self::urlOf($get('image'));
        $sameRaw = $get('sameAs');
        $same = [];
        foreach ((array)($sameRaw ?? []) as $s) {
            if (!is_string($s)) continue;
            $s = trim($s);
            if (!preg_match('#^https?://#i', $s)) continue;
            if (Crawler::sameSite($s, $this->base)) continue;
            $k = self::profileKey($s);
            if (!isset($same[$k])) $same[$k] = $s;
        }
        $same = array_values($same);
        $tel = self::str($get('telephone'));
        $email = self::str($get('email'));
        $cp = self::first($get('contactPoint'));
        $cpTel = $cp ? self::str($cp['telephone'] ?? null) : null;
        $cpEmail = $cp ? self::str($cp['email'] ?? null) : null;
        $addrV = $get('address');
        $addr = self::addressParts($addrV);
        $logoBad = ($logo !== null && strpos($logo, '(посилання') !== 0) ? self::badUrl($logo) : null;

        $grade = [];
        $grade['name'] = $name !== null ? 1.0 : 0.0;
        $grade['url'] = $url !== null ? 1.0 : 0.0;
        $hasLogo = ($logo !== null && $logoBad === null) || ($isLb && $image !== null && $logo === null);
        $grade['logo'] = $hasLogo ? 1.0 : 0.0;
        $grade['sameAs'] = count($same) >= 2 ? 1.0 : (count($same) === 1 ? 0.5 : 0.0);
        $grade['contact'] = ($tel !== null || $cpTel !== null || ($cp !== null && $cpEmail !== null)) ? 1.0 : (($email !== null || $cp !== null) ? 0.5 : 0.0);
        $grade['address'] = $addrV === null ? 0.0 : ($addr['street'] !== null && $addr['locality'] !== null ? 1.0 : (is_string($addrV) ? 0.8 : 0.5));

        $present = []; $missing = [];
        foreach (['name' => 'name', 'url' => 'url', 'logo' => 'logo', 'sameAs' => 'sameAs', 'contact' => 'contactPoint/telephone', 'address' => 'address'] as $k => $label) {
            if ($k === 'logo' && $logo !== null && $logoBad !== null) { $missing[] = 'logo (некоректний URL)'; continue; }
            if ($k === 'sameAs' && count($same) === 1) { $missing[] = 'sameAs (лише 1 профіль, потрібно 2+)'; continue; }
            if ($k === 'address' && $grade['address'] > 0 && $grade['address'] < 1.0) { $present[] = 'address (' . self::addrIncomplete($addr) . ')'; continue; }
            if ($grade[$k] >= 0.5) {
                $present[] = $k === 'contact' ? ($tel !== null ? 'telephone' : 'contactPoint') . ($grade[$k] < 1 ? ' (без телефону)' : '') : ($k === 'logo' && $logo === null ? 'image' : $label);
            } else {
                $missing[] = $label;
            }
        }
        if ($this->stype !== 'local') {
            // для нелокальних сайтів адреса - рекомендована, не обов'язкова
            $missing = array_values(array_filter($missing, function ($m) { return $m !== 'address'; }));
            if ($addrV === null) $missing[] = 'address';
        }

        $first = $list[0]['node']['data'];
        return [
            'types' => $types, 'type' => $type, 'is_lb' => $isLb, 'top' => $top, 'nested_prop' => $nestedProp,
            'where' => array_keys($where), 'name' => $name, 'url' => $url, 'logo' => $logo, 'logo_bad' => $logoBad,
            'image' => $image, 'sameAs' => $same, 'telephone' => $tel, 'email' => $email ?? $cpEmail, 'cp' => $cp, 'cp_tel' => $cpTel,
            'address' => $addrV, 'addr' => $addr, 'grade' => $grade, 'present' => $present, 'missing' => $missing,
            'present_count' => count($present), 'node' => $first,
            'id' => isset($first['@id']) && is_string($first['@id']) ? $first['@id'] : null,
            'description' => self::str($get('description')),
            'alternateName' => self::str($get('alternateName')),
            'opening' => $get('openingHoursSpecification') ?? $get('openingHours'),
            'geo' => self::first($get('geo')),
            'priceRange' => self::str($get('priceRange')),
            'hasMap' => self::urlOf($get('hasMap')),
            'list' => $list,
        ];
    }

    private function missingExplained(array $E): array
    {
        $out = [];
        foreach ($E['missing'] as $m) {
            if ($m === 'logo (некоректний URL)') $out[] = 'logo (зараз «' . self::mbTrunc((string)$E['logo'], 80) . '» - ' . $E['logo_bad'] . ')';
            else $out[] = $m;
        }
        return $out;
    }

    /** Опис неповної адреси: «неповна: лише місто і країна», «текстом, без розбиття на поля». */
    private static function addrIncomplete(array $a): string
    {
        if ($a['string'] !== null) return 'текстом, без розбиття на поля';
        if ($a['street'] === null) {
            $has = [];
            if ($a['locality'] !== null) $has[] = 'місто';
            if ($a['region'] !== null) $has[] = 'область';
            if ($a['postal'] !== null) $has[] = 'індекс';
            if ($a['country'] !== null) $has[] = 'країна';
            return $has ? 'неповна: лише ' . implode(' і ', $has) . ', без вулиці' : 'неповна: без вулиці';
        }
        return 'неповна: без міста';
    }

    private static function addressParts($v): array
    {
        $out = ['street' => null, 'locality' => null, 'postal' => null, 'country' => null, 'region' => null, 'string' => null];
        if (is_string($v)) { $out['string'] = $v; return $out; }
        $a = self::first($v);
        if (!$a) return $out;
        $out['street'] = self::str($a['streetAddress'] ?? null);
        $out['locality'] = self::str($a['addressLocality'] ?? null);
        $out['postal'] = self::str($a['postalCode'] ?? null);
        $out['region'] = self::str($a['addressRegion'] ?? null);
        $c = $a['addressCountry'] ?? null;
        $out['country'] = is_array($c) ? self::str($c['name'] ?? ($c['@id'] ?? null)) : self::str($c);
        return $out;
    }

    /** Порожні / сміттєві поля прибираємо для показу «як зараз». */
    private static function trimNode(array $node): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            if (in_array((string)$k, ['articleBody', 'review', 'hasOfferCatalog', 'makesOffer', 'mainEntity'], true)) { $out[$k] = '...'; continue; }
            if (is_string($v) && mb_strlen($v) > 160) $v = mb_substr($v, 0, 150) . ' ...';
            $out[$k] = $v;
        }
        return $out;
    }

    /** Для «як зараз»: розкриває заглушки microdata ({"@type": "Offer"}) у реальні значення зі сторінки. */
    private function showNode(array $data, ?array $pg): array
    {
        return self::trimNode($this->unstub($data, $pg));
    }

    private function unstub(array $data, ?array $pg): array
    {
        if (!$pg) return $data;
        foreach ($data as $k => $v) {
            if (is_array($v) && !self::isList($v) && isset($v['@type']) && !isset($v['@id']) && count($v) === 1) {
                $r = $this->resolve($v, $pg, 0);
                if (is_array($r)) $data[$k] = $r;
            }
        }
        return $data;
    }

    /** Вузол для готового коду: без articleBody, з @context першим. */
    private static function codeNode(array $d): array
    {
        unset($d['articleBody'], $d['@context']);
        return ['@context' => 'https://schema.org'] + $d;
    }

    /**
     * Готовий JSON-LD у <script> з обмеженням розміру: довгі списки скорочуються до перших елементів,
     * якщо й так задовго - ''. $note отримує пояснення для «як треба», якщо щось скорочено.
     */
    private static function ldCode(array $data, ?string &$note = null): string
    {
        $note = '';
        $s = self::jsonLdScript($data);
        if (mb_strlen($s) <= self::CODE_MAX && self::codeDecodes($s)) return $s;
        foreach ([3, 2, 1] as $keep) {
            $cut = [];
            $t = self::shrinkLists($data, $keep, $cut, 0);
            $s = self::jsonLdScript($t);
            if (mb_strlen($s) <= self::CODE_MAX && self::codeDecodes($s)) {
                $note = 'Блок великий, тому в готовому коді довгі списки (' . implode(', ', array_slice(array_keys($cut), 0, 4)) . ') скорочено до '
                    . $keep . ' ' . self::plural($keep, 'першого елемента', 'перших елементів', 'перших елементів') . ' - решту додайте за тим самим зразком.';
                return $s;
            }
        }
        $note = 'Блок завеликий для готового коду в звіті - виправте його у шаблоні за прикладом вище.';
        return '';
    }

    private static function shrinkLists($v, int $keep, array &$cut, int $d)
    {
        if (!is_array($v) || $d > 12) return $v;
        $out = [];
        foreach ($v as $k => $x) {
            if (is_string($k) && is_array($x) && self::isList($x) && count($x) > $keep && in_array($k, self::LONG_LISTS, true)) {
                $cut[$k] = true;
                $x = array_slice($x, 0, $keep);
            } elseif (is_string($k) && $k === 'articleBody') {
                $cut[$k] = true;
                continue;
            }
            $out[$k] = self::shrinkLists($x, $keep, $cut, $d + 1);
        }
        return $out;
    }

    private static function codeDecodes(string $script): bool
    {
        if (!preg_match('#^<script type="application/ld\+json">\n(.*)\n</script>$#s', $script, $m)) return false;
        return is_array(json_decode($m[1], true));
    }

    private function entityValueIssues(array $list, array $E, array $groups, string $best): array
    {
        $issues = [];
        // 1) некоректний logo / url на будь-якій сторінці
        $seen = [];
        foreach ($list as $x) {
            $pg = $this->pages[$x['pi']];
            $d = $x['node']['data'];
            $logo = self::urlOf($this->val($d, 'logo', $pg));
            if ($logo !== null && ($why = self::badUrl($logo)) !== null && !isset($seen['logo' . $logo])) {
                $seen['logo' . $logo] = true;
                $issues[] = ['hard' => true, 'text' => 'властивість logo на сторінці ' . $this->where($pg) . ': «' . self::mbTrunc($logo, 90) . '» - ' . $why, 'bad' => '"logo": "' . $logo . '"'];
            }
        }
        // 2) url організації = адреса поточної внутрішньої сторінки (шаблон підставляє поточний URL)
        $wrongUrl = [];
        foreach ($list as $x) {
            $pg = $this->pages[$x['pi']];
            if ($pg['role'] === 'home' || !$x['node']['top']) continue;
            $u = self::urlOf($this->val($x['node']['data'], 'url', $pg));
            if ($u === null) continue;
            if (self::normUrl($u) === self::normUrl((string)$pg['url']) && self::normUrl($u) !== self::normUrl($this->homeUrl) && self::normUrl($u) !== self::normUrl($this->base)) {
                $wrongUrl[$this->where($pg)] = ['where' => $this->where($pg), 'url' => $u];
            }
        }
        $wrongUrl = array_values($wrongUrl);
        if ($wrongUrl) {
            $issues[] = ['hard' => true, 'text' => 'властивість url організації на внутрішніх сторінках дорівнює адресі самої сторінки замість головної: '
                . self::listSome(array_map(function ($w) { return $w['where'] . ' -> «' . $w['url'] . '»'; }, $wrongUrl), 3),
                'bad' => '"url": "' . $wrongUrl[0]['url'] . '"'];
        }
        // 3) суперечлива адреса між сторінками. Група вже зібрана за @id; сторінку зі списком
        //    кількох різних адрес (перелік філій) не порівнюємо - це не суперечність
        $addrs = []; $perPage = [];
        foreach ($list as $x) {
            $pg = $this->pages[$x['pi']];
            $a = self::addressParts($this->val($x['node']['data'], 'address', $pg));
            $st = $a['street'] ?? $a['string'];
            if ($st === null) continue;
            $k = $this->streetKey($x['node']['data'], $pg);
            $perPage[$x['pi']][$k] = true;
            $addrs[$k][$this->where($pg)] = $st;
        }
        $listing = false;
        foreach ($perPage as $set) if (count($set) > 1) $listing = true;
        if (count($addrs) > 1 && !$listing) {
            $parts = [];
            foreach ($addrs as $byWhere) {
                foreach ($byWhere as $w => $st) { $parts[] = $w . ': «' . self::mbTrunc($st, 60) . '»'; break; }
            }
            $idsInGroup = [];
            foreach ($list as $x) $idsInGroup[self::nodeId($x['node']['data'])] = true;
            $sameId = count($idsInGroup) === 1 && !isset($idsInGroup['']);
            $issues[] = ['hard' => true, 'kind' => 'addr', 'text' => 'різні адреси однієї організації на різних сторінках (' . ($sameId ? 'блоки з тим самим @id' : 'блоки з тією самою назвою') . '): ' . implode('; ', array_slice($parts, 0, 3))
                . ' (яка з них актуальна - уточніть; у готовому коді адреса з головної)', 'bad' => implode("\n", array_slice($parts, 0, 3))];
        }
        // 4) дублікати на одній сторінці: зайві блоки без @id або з тією самою адресою.
        //    Блоки з різними @id і різними адресами - філії, а не дублікати
        $byPage = [];
        foreach ($list as $x) {
            if (!$x['node']['top']) continue;
            $byPage[$x['pi']][] = $x['node'];
        }
        $dupDone = false;
        foreach ($byPage as $pi => $nodes) {
            if (count($nodes) < 2) continue;
            $ids = []; $streets = [];
            foreach ($nodes as $n) {
                $ids[] = self::nodeId($n['data']);
                $streets[] = $this->streetKey($n['data'], $this->pages[$pi]);
            }
            if (count(array_unique($ids)) === 1 && $ids[0] !== '') continue;
            $filled = array_filter($streets, function ($s) { return $s !== ''; });
            if (count($filled) === count($streets) && count(array_unique($filled)) === count($filled)) continue; // різні адреси - філії
            $desc = [];
            foreach ($nodes as $n) $desc[] = implode('/', $n['types']) . (self::nodeId($n['data']) !== '' ? ' (@id ' . $n['data']['@id'] . ')' : ' (без @id)');
            $issues[] = ['hard' => false, 'kind' => 'dup', 'text' => ($this->pages[$pi]['role'] === 'home' ? 'на головній' : 'на сторінці ' . $this->where($this->pages[$pi])) . ' одну організацію описано ' . count($nodes) . ' окремими блоками: ' . implode(', ', $desc)
                . ' - машини можуть вважати їх різними сутностями', 'bad' => implode("\n", $desc)];
            $dupDone = true;
            break;
        }
        // 5) та сама організація під різними @id (та сама назва, та сама адреса або без адреси, і це не філія)
        if (!$dupDone) {
            $eName = $this->nodeName($list[0]['node']['data']);
            $eStreets = $this->groupStreets($list);
            $branchKeys = [];
            foreach ($this->branchLb as $b) $branchKeys[self::nodeId($b['node']['data']) . '|' . $b['pi']] = true;
            $sib = [];
            foreach ($groups as $key => $g) {
                if ($key === $best || strpos((string)$key, 'i:') !== 0 || $eName === '') continue;
                $x = $g[0];
                if ($this->nodeName($x['node']['data']) !== $eName || !$x['node']['top']) continue;
                if ($this->pages[$x['pi']]['role'] === 'branch' || isset($branchKeys[self::nodeId($x['node']['data']) . '|' . $x['pi']])) continue;
                $gs = $this->groupStreets($g);
                if ($gs && $eStreets && !array_intersect($gs, $eStreets)) continue; // інша адреса - окрема точка
                $sib[] = implode('/', $x['node']['types']) . ' (@id ' . $x['node']['data']['@id'] . ', ' . $this->where($this->pages[$x['pi']]) . ')';
            }
            if ($sib) {
                $first = implode('/', $list[0]['node']['types']) . (self::nodeId($list[0]['node']['data']) !== '' ? ' (@id ' . $list[0]['node']['data']['@id'] . ')' : ' (без @id)');
                $issues[] = ['hard' => false, 'kind' => 'dup', 'text' => 'одну організацію «' . self::mbTrunc((string)$E['name'], 40) . '» описано блоками з різними ідентифікаторами: ' . $first . ', ' . implode(', ', array_slice($sib, 0, 2))
                    . ' - машини можуть вважати їх різними сутностями', 'bad' => $first . "\n" . implode("\n", array_slice($sib, 0, 2))];
            }
        }
        return $issues;
    }

    private function entityValuesFinding(array $issues, array $E): void
    {
        $hard = array_filter($issues, function ($x) { return $x['hard']; });
        $sev = $hard ? 'medium' : 'low';
        $texts = array_map(function ($x) { return self::ucf($x['text']); }, $issues);
        $bad = implode("\n", array_map(function ($x) { return $x['bad']; }, array_slice($issues, 0, 3)));
        $title = $hard ? 'Помилки в даних організації: ' . self::listSome(array_map(function ($x) {
            if (strpos($x['text'], 'властивість logo') === 0) return 'некоректний logo';
            if (strpos($x['text'], 'властивість url') === 0) return 'url вказує не на головну';
            if (strpos($x['text'], 'різні адреси') === 0) return 'різні адреси';
            return 'дублікати сутності';
        }, $issues), 4) : 'Одну організацію описано кількома окремими блоками';
        $code = $this->entityCode($this->stype === 'local');
        $this->findings[] = $this->finding('entity_values', $sev, $title, [
            'evidence' => implode('. ', $texts) . '.' . $this->descNote($E) . $this->codeAddrNote,
            'why'      => 'Суперечливі або биті значення (злиплий URL логотипа, різні адреси, url поточної сторінки замість головної) змушують пошукові системи сумніватися, яким даним вірити, і логотип чи адреса можуть не потрапити в картку бренду.',
            'fix'      => 'Виведіть одну сутність організації з постійним @id і однаковими значеннями на всіх сторінках: url - головна, logo - абсолютний робочий URL, адреса - актуальна. Окремі філії (якщо є) - окремими блоками з власним @id і адресою.',
            'bad'      => $bad,
            'good'     => '"@id": "' . $this->orgId() . '",' . "\n" . '"url": "' . $this->homeUrl . '",' . "\n" . '"logo": "' . ($this->facts['logo'] ?? '[ВКАЖІТЬ URL логотипа]') . '"',
            'code'     => self::ldCode($code),
            'verify'   => 'Порівняйте блок організації на головній і на 2-3 внутрішніх сторінках у https://validator.schema.org/ - значення url, logo, address мають збігатися; URL логотипа має відкриватися в браузері.',
            'effort'   => 'S',
        ]);
    }

    private function noEntityFinding(array $entityPages, array $orgElsewhere): void
    {
        $local = $this->stype === 'local';
        $anyOrg = (bool)$orgElsewhere;
        $probe = $this->branchProbe;
        $branchesUnchecked = $probe && $probe['failed'] && !$probe['checked'];
        $chain = $local && (bool)$this->branchLb;
        // критично лише коли розмітки організації немає взагалі, зокрема на перевірених сторінках філій
        $sev = ($local && !$anyOrg && !$chain && !$branchesUnchecked) ? 'critical' : 'high';
        $checked = []; $notChecked = [];
        foreach ($entityPages as $pg) {
            if ($pg['page'] instanceof Page) $checked[] = $this->where($pg) . ' - ' . $this->typesLine($pg);
            else $notChecked[] = $this->where($pg) . ' (' . $pg['note'] . ')';
        }
        if ($probe && $probe['checked'] && !$chain) $checked[] = 'сторінки філій (за посиланнями з адресами): ' . self::listSome($probe['checked'], 2);
        if ($branchesUnchecked) $notChecked[] = 'сторінки філій не вдалося завантажити (' . self::listSome($probe['failed'], 2) . '), тож розмітку на них не перевірено';
        $roles = array_map(function ($pg) { return $pg['role']; }, $entityPages);
        if (!in_array('about', $roles, true)) $notChecked[] = 'сторінку «Про нас» у вибірці не знайдено';
        $brokenOrg = [];
        foreach ($entityPages as $pg) {
            if (!($pg['page'] instanceof Page)) continue;
            foreach ($pg['blocks'] as $b) {
                if ($b['ok']) continue;
                foreach ($b['types'] as $t) if (self::isType(['types' => [$t]], ['Organization'])) $brokenOrg[$this->where($pg)] = $t;
            }
        }
        if ($brokenOrg) {
            $notChecked[] = 'блок ' . implode(', ', array_unique(array_values($brokenOrg))) . ' є (' . implode(', ', array_keys($brokenOrg)) . '), але з синтаксичною помилкою JSON - Google його не прочитає (див. окреме зауваження)';
        }
        $home = $this->pages[0];
        $bad = $home['page'] instanceof Page ? 'Розмітка на головній: ' . $this->typesLine($home) : 'Головна недоступна: ' . $home['note'];
        // мережа з розміченими філіями: бракує лише загальної сутності - код Organization, не ще одна філія
        $code = $this->entityCode($local && !$chain);
        $typeName = (string)$code['@type'];
        $branchLine = $chain ? ' Філії розмічені окремо: ' . $this->branchesLine() . '.' : '';
        $title = $chain ? 'Немає загальної розмітки Organization для мережі (філії розмічені окремо)'
            : ($local ? 'Немає розмітки LocalBusiness / Organization: адреса, телефон і години роботи не передані в машиночитаному вигляді'
                : 'Немає розмітки Organization: компанія не описана як сутність');
        $this->findings[] = $this->finding('no_entity', $sev, $title, [
            'evidence' => 'Перевірено: ' . implode('; ', $checked) . '.' . ($notChecked ? ' Також: ' . implode('; ', $notChecked) . '.' : '')
                . ($anyOrg ? ' Organization трапляється лише як вкладений об\'єкт (автор, видавець, продавець) на: ' . self::listSome($orgElsewhere, 3) . '.' : '')
                . $branchLine . $this->factsLine(),
            'why'      => $chain
                ? 'Блоки філій описують окремі точки, але не бренд у цілому. Organization з логотипом і профілями (sameAs) пов\'язує всі філії й згадки про бренд в одну сутність.'
                : ($local
                    ? 'Адреса й телефон є в тексті сторінки, але без розмітки їх доводиться вгадувати. Розмітка з адресою, телефоном і годинами роботи дає ці факти в машиночитаному вигляді для відповідей «де», «коли працює», «як зв\'язатися» у пошуку й AI-асистентах. Для Google Карт головне джерело - профіль Google Business Profile, тож дані в розмітці мають з ним збігатися.'
                    : 'Organization пов\'язує сайт, логотип, контакти й профілі в соцмережах в одну сутність. Без неї AI гірше розуміє, хто стоїть за сайтом, і рідше впевнено називає бренд.'),
            'fix'      => $chain
                ? 'Додайте JSON-LD ' . $typeName . ' головної сутності мережі (з @id) у спільний шаблон, а в блоках філій пошліться на неї через "parentOrganization": {"@id": "' . $this->orgId() . '"}.'
                : 'Додайте JSON-LD ' . $typeName . ' у спільний шаблон (на всі сторінки або щонайменше на головну і «Про нас»). Готовий код нижче заповнено даними, знайденими на сайті; перевірте позначені [ВКАЖІТЬ ...] поля.',
            'bad'      => $bad,
            'good'     => '{"@context": "https://schema.org", "@type": "' . $typeName . '", "name": "' . $this->brand . '", "url": "' . $this->homeUrl . '", "logo": "...", '
                . ($local && !$chain ? '"address": {...}, "telephone": "...", "openingHoursSpecification": [...]' : '"contactPoint": {...}, "sameAs": [...]') . '}',
            'code'     => self::ldCode($code),
            'verify'   => 'Перевірте головну в https://validator.schema.org/ - має з\'явитися ' . $typeName . ' без помилок; потім у Google Rich Results Test (окремого звіту для Organization і LocalBusiness у Search Console немає).',
            'effort'   => 'S',
        ]);
    }

    private function goodEntityShort(array $E): string
    {
        $o = ['@type' => $E['type'], 'name' => $E['name'] ?? $this->brand, 'url' => $this->homeUrl];
        $o['logo'] = $this->facts['logo'] ?? '[ВКАЖІТЬ URL логотипа]';
        $same = $this->sameAsList();
        if (count($same) < 2) $same[] = '[ще один профіль]';
        $o['sameAs'] = array_slice($same, 0, 3);
        $ph = $E['telephone'] ?? $E['cp_tel'] ?? ($this->facts['phones'][0] ?? null);
        $em = $E['email'] ?? ($this->facts['emails'][0] ?? null);
        if ($ph !== null) $o['contactPoint'] = ['@type' => 'ContactPoint', 'telephone' => $ph, 'contactType' => 'customer service'];
        elseif ($em !== null) $o['contactPoint'] = ['@type' => 'ContactPoint', 'email' => $em, 'contactType' => 'customer service'];
        else $o['contactPoint'] = ['@type' => 'ContactPoint', 'telephone' => $this->phPhone(), 'contactType' => 'customer service'];
        return self::jsonPretty($o);
    }

    /** Сайт для українського ринку: домен .ua або мова uk. */
    private function isUa(): bool
    {
        $host = strtolower((string)parse_url($this->base, PHP_URL_HOST));
        if (preg_match('/\.ua$/', $host)) return true;
        if (strtolower((string)($this->site['lang'] ?? '')) === 'uk') return true;
        return in_array('uk', array_map('strtolower', array_map('strval', (array)($this->site['languages'] ?? []))), true);
    }

    private function phPhone(): string
    {
        return $this->isUa() ? self::PH_PHONE : '[ВКАЖІТЬ телефон у міжнародному форматі, з кодом країни]';
    }

    /** Чи текст ймовірно російською (для uk-сайту такий текст у готовий код не копіюємо). */
    private function isRussianText(?string $t): bool
    {
        if ($t === null || $t === '') return false;
        if (!$this->isUa()) return false;
        $l = mb_strtolower($t);
        if (preg_match('/[іїєґ]/u', $l)) return false;
        if (preg_match('/[ыэъё]/u', $l)) return true;
        // довгий кириличний текст зовсім без і/ї/є - майже напевно російською
        return preg_match_all('/[а-я]/u', $l) >= 30;
    }

    /** Примітка, якщо наявний description у розмітці російською і в код його не скопійовано. */
    private function descNote(?array $E): string
    {
        if (!$E || !$this->isRussianText($E['description'] ?? null)) return '';
        return ' Наявний description у розмітці російською мовою («' . self::mbTrunc((string)$E['description'], 70) . '») - у готовому коді його замінено '
            . (!empty($this->facts['description']) && !$this->isRussianText($this->facts['description']) ? 'на українську meta description' : 'полем для українського опису') . '.';
    }

    /** «MedicalBusiness «Київ, вул. ...» (сторінка філії /ua/...): адреса, графік роботи» - перелік розмічених філій. */
    private function branchesLine(): string
    {
        $parts = [];
        foreach (array_slice($this->branchLb, 0, 2) as $b) {
            $pg = $this->pages[$b['pi']];
            $R = $this->mergeEntity([$b]);
            $has = [];
            $a = $R['addr'];
            if ($a['street'] !== null || $a['string'] !== null) $has[] = 'адреса';
            if ($R['telephone'] !== null || $R['cp_tel'] !== null) $has[] = 'телефон';
            if ($R['opening'] !== null) $has[] = 'години роботи';
            if ($R['geo'] !== null) $has[] = 'geo';
            if ($R['hasMap'] !== null) $has[] = 'hasMap';
            $parts[] = $R['type'] . ' «' . self::mbTrunc((string)($R['name'] ?? ''), 50) . '» (' . $this->where($pg) . ')' . ($has ? ': ' . implode(', ', $has) : '');
        }
        $n = count($this->branchLb);
        return $n . ' ' . self::plural($n, 'блок', 'блоки', 'блоків') . ' LocalBusiness на перевірених сторінках, напр. ' . implode('; ', $parts);
    }

    private function orgId(): string
    {
        // особистий сайт: видавець і постачальник послуг - особа
        if ($this->stype === 'personal') return $this->personId();
        if ($this->E && $this->E['id'] && preg_match('#^https?://#i', (string)$this->E['id']) && Crawler::sameSite((string)$this->E['id'], $this->base)) return (string)$this->E['id'];
        return $this->base . '/#organization';
    }

    /** Готовий блок організації з реальними даними сайту. */
    private function entityCode(bool $local): array
    {
        $E = $this->E;
        $f = $this->facts;
        if ($local) {
            $type = ($E && $E['is_lb']) ? $E['type'] : $this->guessLocalType();
        } elseif ($E) {
            $type = $E['type'];
        } else {
            $type = $this->stype === 'ecommerce' ? 'OnlineStore' : ($this->stype === 'media' ? 'NewsMediaOrganization' : 'Organization');
        }
        $o = ['@context' => 'https://schema.org', '@type' => $type, '@id' => $this->orgId()];
        $o['name'] = ($E && $E['name']) ? $E['name'] : $this->brand;
        if ($E && $E['alternateName']) $o['alternateName'] = $E['alternateName'];
        $o['url'] = $this->homeUrl;
        $o['logo'] = $f['logo'] ?? '[ВКАЖІТЬ URL логотипа (PNG/SVG від 112x112 px)]';
        if ($local) $o['image'] = ($E && $E['image']) ? $E['image'] : ($f['og_image'] ?? $o['logo']);
        $desc = ($E && $E['description']) ? $E['description'] : ($f['description'] ?? null);
        if ($this->isRussianText($desc)) {
            // російськомовний опис на uk-сайті в рекомендований код не переносимо
            $desc = (!empty($f['description']) && !$this->isRussianText($f['description'])) ? $f['description'] : '[ВКАЖІТЬ короткий опис компанії українською]';
        }
        if ($desc) $o['description'] = self::mbTrunc($desc, 250);
        $phone = $E['telephone'] ?? $E['cp_tel'] ?? ($f['phones'][0] ?? null);
        $email = $E['email'] ?? ($f['emails'][0] ?? null);
        if ($local) {
            $o['telephone'] = $phone ?? $this->phPhone();
            if ($email) $o['email'] = $email;
        } else {
            if ($E && $E['telephone']) $o['telephone'] = $E['telephone'];
            if ($email) $o['email'] = $email;
            // ContactPoint - лише з реальним каналом зв'язку (телефон або email), без вигаданих заглушок
            if ($phone !== null || $email) {
                $cp = ['@type' => 'ContactPoint'];
                if ($phone !== null) $cp['telephone'] = $phone;
                if ($email) $cp['email'] = $email;
                $cp['contactType'] = 'customer service';
                if ($this->isUa()) $cp['areaServed'] = 'UA';
                $cp['availableLanguage'] = $this->langNames();
                $o['contactPoint'] = $cp;
            }
        }
        $addr = $this->addressCode($local);
        if ($addr) $o['address'] = $addr;
        if ($local) {
            $geo = $E['geo'] ?? null;
            if ($geo && isset($geo['latitude'], $geo['longitude'])) {
                $o['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $geo['latitude'], 'longitude' => $geo['longitude']];
            } elseif (!empty($f['geo'])) {
                $o['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $f['geo'][0], 'longitude' => $f['geo'][1]];
            } else {
                $o['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => '[ВКАЖІТЬ широту, напр. 50.4501]', 'longitude' => '[ВКАЖІТЬ довготу, напр. 30.5234]'];
            }
            $map = $E['hasMap'] ?? ($f['map'] ?? null);
            $o['hasMap'] = $map ?? '[ВКАЖІТЬ посилання на Google Maps]';
            if ($E && $E['opening']) {
                $o['openingHoursSpecification'] = $E['opening'];
            } else {
                $lodging = isset(Page::expandTypes(['LodgingBusiness', 'FoodEstablishment'])[strtolower($type)]);
                $o['openingHoursSpecification'] = [[
                    '@type' => 'OpeningHoursSpecification',
                    'dayOfWeek' => $lodging ? ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] : ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                    'opens' => '[ВКАЖІТЬ, напр. 09:00]',
                    'closes' => '[ВКАЖІТЬ, напр. 18:00]',
                ]];
            }
            $o['priceRange'] = ($E && $E['priceRange']) ? $E['priceRange'] : '[ВКАЖІТЬ, напр. ₴₴ або 500-3000 UAH]';
        }
        $same = $this->sameAsList();
        if (count($same) < 2) $same[] = '[ВКАЖІТЬ URL ' . ($same ? 'ще одного профілю' : 'профілю') . ': Facebook / Instagram / LinkedIn / YouTube]';
        $o['sameAs'] = $same;
        return $o;
    }

    /** Адресу з тексту підставляємо в код лише для локального бізнесу або якщо вона зі сторінки «Контакти». */
    private function addrUsable(bool $local): bool
    {
        $a = $this->facts['address'] ?? [];
        if (!$a) return false;
        return $local || ($a['role'] ?? '') === 'contact';
    }

    private function addressCode(bool $local): ?array
    {
        $E = $this->E;
        $ex = $E ? $E['addr'] : ['street' => null, 'locality' => null, 'postal' => null, 'country' => null, 'region' => null, 'string' => null];
        $det = $this->addrUsable($local) ? ($this->facts['address'] ?? []) : [];
        $street = $ex['street'] ?? ($det['street'] ?? null);
        $loc = $ex['locality'] ?? ($det['locality'] ?? null);
        $postal = $ex['postal'] ?? ($det['postal'] ?? null);
        $region = $ex['region'] ?? ($det['region'] ?? null);
        if (!$local && $street === null && $loc === null && $ex['string'] === null) return null;
        if ($street === null && $ex['string'] !== null) $street = $ex['string'];
        if ($ex['street'] === null && $ex['string'] === null && $street !== null && !empty($det['street'])) {
            $this->codeAddrNote = ' У готовому коді streetAddress «' . $det['street'] . '» взято з тексту сторінки (' . ($det['where'] ?? '') . ')'
                . ($E && $E['address'] !== null ? ', бо в наявній розмітці адреса без вулиці' : '') . ' - перевірте, що це адреса компанії.';
        }
        $a = ['@type' => 'PostalAddress',
            'streetAddress' => $street ?? '[ВКАЖІТЬ вулицю і номер будинку]',
            'addressLocality' => $loc ?? '[ВКАЖІТЬ місто / населений пункт]'];
        if ($region) $a['addressRegion'] = $region;
        $a['postalCode'] = $postal ?? '[ВКАЖІТЬ поштовий індекс]';
        $a['addressCountry'] = $this->isUa() ? 'UA' : (($ex['country'] ?? null) ?: '[ВКАЖІТЬ код країни, напр. UA]');
        return $a;
    }

    private function langNames(): array
    {
        $map = ['uk' => 'Ukrainian', 'en' => 'English', 'pl' => 'Polish', 'de' => 'German'];
        $out = [];
        foreach ((array)($this->site['languages'] ?? []) as $l) {
            $l = strtolower((string)$l);
            if (isset($map[$l])) $out[] = $map[$l];
        }
        if (!$out) $out = [$this->isUa() ? 'Ukrainian' : 'English'];
        return array_values(array_unique($out));
    }

    private function sameAsList(): array
    {
        $out = [];
        if ($this->E) foreach ($this->E['sameAs'] as $s) { $k = self::profileKey($s); if (!isset($out[$k])) $out[$k] = $s; }
        foreach ($this->facts['socials'] ?? [] as $s) {
            $k = self::profileKey($s);
            if (!isset($out[$k])) $out[$k] = $s;
        }
        return array_slice(array_values($out), 0, 8);
    }

    /** Ключ профілю для дедуплікації: без www/m., t.me = telegram.me, без query і кінцевого слеша. */
    private static function profileKey(string $u): string
    {
        $p = parse_url(trim($u));
        if (!$p || empty($p['host'])) return strtolower(trim($u));
        $h = strtolower($p['host']);
        $h = (string)preg_replace('/^(www\.|m\.)/', '', $h);
        if ($h === 'telegram.me') $h = 't.me';
        if ($h === 'twitter.com') $h = 'x.com';
        return $h . strtolower(rtrim((string)($p['path'] ?? ''), '/'));
    }

    private function guessLocalType(): string
    {
        $home = $this->pages[0]['page'] ?? null;
        $txt = '';
        if ($home instanceof Page) {
            $txt = mb_strtolower($home->title() . ' ' . $home->metaDescription() . ' ' . implode(' ', $home->headings()['h1']) . ' ' . mb_substr($home->mainText(), 0, 1500));
        }
        if ($this->E) {
            $t = $this->E['types'];
            if (array_intersect($t, ['MedicalOrganization', 'MedicalClinic', 'Hospital', 'Physician'])) return 'MedicalClinic';
            if (in_array('Dentist', $t, true)) return 'Dentist';
        }
        $map = [
            'Dentist' => '/стоматолог|dental|дентал/u',
            'MedicalClinic' => '/клінік|медичн|медцентр|діагностичн|лікар|clinic|medical/u',
            'Resort' => '/курорт|resort|заміськ\p{L}* комплекс|база відпочинку/u',
            'Hotel' => '/готел|hotel|хостел|hostel/u',
            'Brewery' => '/броварн|brewery/u',
            'Restaurant' => '/ресторан|restaurant/u',
            'CafeOrCoffeeShop' => '/кав[\'’ʼ]?ярн|coffee|кафе/u',
            'BeautySalon' => '/салон краси|beauty|косметолог/u',
            'HairSalon' => '/перукарн|барбершоп|barber/u',
            'AutoRepair' => '/автосервіс|сто\b|шиномонтаж|ремонт авто/u',
            'ExerciseGym' => '/фітнес|спортзал|тренажерн|fitness|gym/u',
            'LegalService' => '/юридичн|адвокат|юрист|law firm/u',
            'RealEstateAgent' => '/нерухом|real estate|ріелтор/u',
            'Store' => '/магазин|shop|store/u',
        ];
        $head = '';
        if ($home instanceof Page) $head = mb_strtolower($home->title() . ' ' . $home->metaDescription() . ' ' . implode(' ', $home->headings()['h1']));
        foreach ([$head, $txt] as $hay) {
            if ($hay === '') continue;
            foreach ($map as $type => $rx) if (preg_match($rx, $hay)) return $type;
        }
        return 'LocalBusiness';
    }

    // ================================================================= facts for code

    private function collectFacts(): void
    {
        $f = ['phones' => [], 'emails' => [], 'socials' => [], 'logo' => null, 'logo_src' => '', 'og_image' => null,
            'description' => null, 'address' => [], 'geo' => null, 'map' => null];
        $home = $this->pages[0]['page'] ?? null;
        $src = [];
        foreach ($this->pages as $pg) {
            if ($pg['page'] instanceof Page && in_array($pg['role'], ['home', 'contact', 'about'], true)) $src[] = $pg;
        }
        // home першою, потім контакти
        usort($src, function ($a, $b) {
            $o = ['home' => 0, 'contact' => 1, 'about' => 2];
            return ($o[$a['role']] ?? 3) <=> ($o[$b['role']] ?? 3);
        });
        foreach ($src as $pg) {
            /** @var Page $p */
            $p = $pg['page'];
            foreach ($p->query('//a[@href]') as $a) {
                $h = trim((string)$a->getAttribute('href'));
                if (stripos($h, 'tel:') === 0) {
                    $ph = self::normPhone(substr($h, 4));
                    if ($ph !== null && !in_array($ph, $f['phones'], true) && count($f['phones']) < 3) $f['phones'][] = $ph;
                } elseif (stripos($h, 'mailto:') === 0) {
                    $em = strtolower(trim(rawurldecode(explode('?', substr($h, 7))[0])));
                    if (filter_var($em, FILTER_VALIDATE_EMAIL) && !in_array($em, $f['emails'], true) && count($f['emails']) < 2) $f['emails'][] = $em;
                }
            }
            foreach ($p->externalLinks() as $l) {
                $s = self::socialProfile((string)$l['href']);
                if ($s === null) continue;
                $net = $s[0];
                if (!isset($f['socials'][$net])) $f['socials'][$net] = $s[1];
                if ($f['map'] === null && $net === '_map') $f['map'] = $s[1];
            }
            foreach ($p->iframes() as $src2) {
                if ($f['geo'] === null && preg_match('#google\.[a-z.]+/maps#i', $src2)) {
                    if (preg_match('/!3d(-?\d{1,2}\.\d{3,})/', $src2, $mLat) && preg_match('/!2d(-?\d{1,3}\.\d{3,})/', $src2, $mLng)) {
                        $f['geo'] = [round((float)$mLat[1], 6), round((float)$mLng[1], 6)];
                    }
                }
            }

        }
        // адреса з тексту: спершу «Контакти», потім головна, «Про нас»
        $order = ['contact' => 0, 'home' => 1, 'about' => 2];
        $bySrc = $src;
        usort($bySrc, function ($a, $b) use ($order) { return ($order[$a['role']] ?? 3) <=> ($order[$b['role']] ?? 3); });
        foreach ($bySrc as $pg) {
            $addr = self::detectAddress($pg['page']->mainText());
            if ($addr) { $f['address'] = $addr + ['where' => $this->where($pg), 'role' => $pg['role']]; break; }
        }
        unset($f['socials']['_map']);
        $f['socials'] = array_values($f['socials']);
        if ($f['map'] === null && $f['geo']) $f['map'] = 'https://www.google.com/maps?q=' . $f['geo'][0] . ',' . $f['geo'][1];
        if (!$f['emails']) {
            foreach ($src as $pg) {
                if ($pg['role'] !== 'contact' && $pg['role'] !== 'home') continue;
                if (preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,10}/', $pg['page']->visibleText(), $mm)) {
                    foreach ($mm[0] as $em) {
                        $em = strtolower($em);
                        if (preg_match('/\.(png|jpe?g|gif|webp|svg)$/', $em)) continue;
                        if (filter_var($em, FILTER_VALIDATE_EMAIL)) { $f['emails'][] = $em; break 2; }
                    }
                }
            }
        }
        if ($home instanceof Page) {
            $f['og_image'] = $home->og('image') ? (Crawler::absolutize((string)$home->og('image'), $home->url()) ?? null) : null;
            $d = $home->metaDescription();
            if ($d !== '' && mb_strlen($d) >= 20) $f['description'] = $d;
        }
        // логотип
        if ($this->entityLogoCandidate() !== null) {
            $f['logo'] = $this->entityLogoCandidate();
            $f['logo_src'] = 'schema';
        } elseif ($home instanceof Page) {
            [$f['logo'], $f['logo_src']] = $this->findLogo($home);
            if ($f['logo'] === null && $f['og_image']) { $f['logo'] = $f['og_image']; $f['logo_src'] = 'og:image'; }
        }
        $this->facts = $f;
    }

    /** Робочий logo з будь-якої розмітки організації на головній / «Про нас». */
    private function entityLogoCandidate(): ?string
    {
        foreach ($this->pages as $pg) {
            if (!($pg['page'] instanceof Page) || !in_array($pg['role'], ['home', 'about', 'contact'], true)) continue;
            foreach (self::nodesOf($pg, ['Organization']) as $n) {
                $l = self::urlOf($this->val($n['data'], 'logo', $pg));
                if ($l === null || self::badUrl($l) !== null) continue;
                $abs = Crawler::absolutize($l, (string)$pg['url']);
                if ($abs) return $abs;
            }
        }
        return null;
    }

    /** @return array{0:?string,1:string} логотип з HTML головної: шапка -> посилання на головну -> alt з брендом -> apple-touch-icon. */
    private function findLogo(Page $p): array
    {
        $lc = function (string $attr) { return 'translate(' . $attr . ",'" . self::UP . "','" . self::LO . "')"; };
        $has = function (string $attr, string $needle) use ($lc) { return 'contains(' . $lc($attr) . ",'" . $needle . "')"; };
        $logoAttr = $has('@src', 'logo') . ' or ' . $has('@alt', 'logo') . ' or ' . $has('@class', 'logo') . ' or ' . $has('@id', 'logo')
            . ' or ancestor::*[position()<=2][' . $has('@class', 'logo') . ' or ' . $has('@id', 'logo') . ']';
        $headerArea = '(//header | //*[@role="banner"] | //*[' . $has('@class', 'header') . ' or ' . $has('@id', 'header') . ' or ' . $has('@class', 'navbar') . '])';
        $homePaths = ['/', '/ua/', '/uk/', '/en/', '/ua', '/uk', $this->homeUrl, $this->base, $this->base . '/'];
        $hrefCond = implode(' or ', array_map(function ($h) { return '@href="' . str_replace('"', '', $h) . '"'; }, array_unique($homePaths)));
        $label = SiteProfile::hostLabel($this->base);
        $brandLc = mb_strtolower($this->brand);
        $queries = [
            $headerArea . '//img[' . $logoAttr . ']',
            '//a[' . $hrefCond . ']//img',
        ];
        if ($label !== '' && preg_match('/^[a-z0-9-]+$/', $label)) $queries[] = '//img[' . $has('@src', strtolower($label)) . ' and (' . $has('@src', 'logo') . ' or ' . $has('@class', 'logo') . ')]';
        foreach ($queries as $q) {
            foreach ($p->query($q) as $img) {
                $abs = self::imgSrc($img, $p);
                if ($abs) return [$abs, 'img'];
            }
        }
        if ($brandLc !== '') {
            foreach ($p->query('//img[@alt]') as $img) {
                $alt = mb_strtolower(trim((string)$img->getAttribute('alt')));
                if ($alt !== '' && mb_strlen($alt) <= 60 && ($alt === $brandLc || strpos($alt, $brandLc . ' ') === 0 || strpos($alt, 'логотип ' . $brandLc) !== false || strpos($alt, $brandLc . ' logo') !== false)) {
                    $abs = self::imgSrc($img, $p);
                    if ($abs) return [$abs, 'img'];
                }
            }
        }
        foreach ($p->query('//link[@rel][@href]') as $l) {
            if (stripos((string)$l->getAttribute('rel'), 'apple-touch-icon') !== false) {
                $abs = Crawler::absolutize((string)$l->getAttribute('href'), $p->url());
                if ($abs) return [$abs, 'apple-touch-icon'];
            }
        }
        return [null, ''];
    }

    private static function imgSrc(DOMNode $img, Page $p): ?string
    {
        if (!($img instanceof DOMElement)) return null;
        $src = trim((string)$img->getAttribute('src'));
        if ($src === '' || stripos($src, 'data:') === 0) $src = trim((string)$img->getAttribute('data-src'));
        if ($src === '' || stripos($src, 'data:') === 0) return null;
        return Crawler::absolutize($src, $p->url());
    }

    private static function normPhone(string $raw): ?string
    {
        $raw = rawurldecode($raw);
        $plus = strpos(ltrim($raw), '+') === 0;
        $d = (string)preg_replace('/\D/', '', $raw);
        if (strlen($d) === 12 && strpos($d, '380') === 0) return '+' . $d;
        if (strlen($d) === 10 && $d[0] === '0') return '+38' . $d;
        if (strlen($d) === 11 && strpos($d, '80') === 0) return '+3' . $d;
        if ($plus && strlen($d) >= 9 && strlen($d) <= 15) return '+' . $d;
        if (strlen($d) >= 9 && strlen($d) <= 15) return $d;
        return null;
    }

    /** [мережа, URL профілю] або null (поширення, інтенти, боти, пости - не профілі). */
    private static function socialProfile(string $href): ?array
    {
        $p = parse_url($href);
        if (!$p || empty($p['host'])) return null;
        $host = strtolower($p['host']);
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        if (strpos($host, 'm.') === 0) $host = substr($host, 2);
        $path = (string)($p['path'] ?? '/');
        $segs = array_values(array_filter(explode('/', $path), function ($s) { return $s !== ''; }));
        $clean = 'https://' . ($p['host'] ?? $host) . rtrim($path, '/');
        if (preg_match('#^(maps\.app\.goo\.gl|goo\.gl)$#', $host) && ($host === 'maps.app.goo.gl' || ($segs[0] ?? '') === 'maps')) return ['_map', $href];
        if (preg_match('#^google\.[a-z.]+$#', $host) && ($segs[0] ?? '') === 'maps' && !preg_match('#/maps/embed#', $path)) return ['_map', $href];
        if (!$segs) return null;
        $s0 = strtolower($segs[0]);
        switch (true) {
            case in_array($host, ['facebook.com', 'fb.com'], true):
                if (in_array($s0, ['sharer', 'sharer.php', 'share', 'share.php', 'dialog', 'plugins', 'tr', 'login', 'groups', 'events', 'hashtag', 'watch'], true)) return null;
                if ($s0 === 'profile.php') return ['facebook', 'https://www.facebook.com/profile.php' . (isset($p['query']) ? '?' . $p['query'] : '')];
                return ['facebook', $clean];
            case $host === 'instagram.com':
                if (in_array($s0, ['p', 'reel', 'reels', 'explore', 'stories', 'accounts'], true)) return null;
                return ['instagram', $clean];
            case in_array($host, ['t.me', 'telegram.me'], true):
                if (in_array($s0, ['share', 'joinchat', 'addstickers', 's'], true) || $s0[0] === '+' || preg_match('/bot$/i', $s0)) return null;
                return ['telegram', 'https://t.me/' . $segs[0]];
            case in_array($host, ['youtube.com'], true):
                if ($s0 === 'channel' || $s0 === 'c' || $s0 === 'user' || strpos($s0, '@') === 0) return ['youtube', $clean];
                return null;
            case $host === 'linkedin.com':
                if (in_array($s0, ['company', 'in', 'school', 'showcase'], true) && isset($segs[1])) return ['linkedin', $clean];
                return null;
            case in_array($host, ['x.com', 'twitter.com'], true):
                if (in_array($s0, ['intent', 'share', 'home', 'search', 'hashtag', 'i'], true)) return null;
                return ['x', $clean];
            case $host === 'tiktok.com':
                return strpos($s0, '@') === 0 ? ['tiktok', $clean] : null;
            case $host === 'threads.net':
                return strpos($s0, '@') === 0 ? ['threads', $clean] : null;
            case $host === 'pinterest.com' || substr($host, -14) === '.pinterest.com':
                return in_array($s0, ['pin', 'search'], true) ? null : ['pinterest', $clean];
            case $host === 'github.com':
                return count($segs) === 1 ? ['github', $clean] : null;
            case in_array($host, ['uk.wikipedia.org', 'en.wikipedia.org'], true):
                return $s0 === 'wiki' ? ['wikipedia_' . substr($host, 0, 2), $clean] : null;
            case $host === 'wikidata.org':
                return $s0 === 'wiki' ? ['wikidata', $clean] : null;
            case $host === 'crunchbase.com':
                return $s0 === 'organization' ? ['crunchbase', $clean] : null;
            case $host === 'clutch.co':
                return $s0 === 'profile' ? ['clutch', $clean] : null;
        }
        return null;
    }

    /** Адреса з видимого тексту: «вул. Приморська, 1», місто, область, індекс. */
    private static function detectAddress(string $text): ?array
    {
        if ($text === '') return null;
        $rx = '/(?<!\p{L})((?:вул\.|вулиця|просп\.|проспект|пр-т|бульв\.|бульвар|пров\.|провулок|пл\.|площа|наб\.|набережна|шосе)\s*[«"]?\p{Lu}[\p{L}\'’ʼ.\- ]{1,40}?[»"]?,?\s*(?:буд\.\s*)?\d{1,4}[\p{L}]?(?:[\/-]\d{1,4}[\p{L}]?)?)(?!\d)/u';
        if (!preg_match($rx, $text, $m, PREG_OFFSET_CAPTURE)) return null;
        $street = trim((string)preg_replace('/\s+/u', ' ', $m[1][0]), " ,");
        $pos = (int)$m[1][1];
        $all = [];
        if (preg_match_all($rx, $text, $mm)) {
            foreach ($mm[1] as $x) {
                $x = trim((string)preg_replace('/\s+/u', ' ', $x), " ,");
                $all[mb_strtolower($x)] = $x;
            }
        }
        $win = mb_strcut($text, max(0, $pos - 200), 200 + strlen($m[1][0]) + 160, 'UTF-8');
        $out = ['street' => $street, 'locality' => null, 'region' => null, 'postal' => null, 'all' => array_values($all)];
        foreach (self::CITIES as $form => $canon) {
            if (preg_match('/(?<!\p{L})' . preg_quote($form, '/') . '(?!\p{L})/u', $win)) { $out['locality'] = $canon; break; }
        }
        if (preg_match('/(?<!\p{L})(\p{Lu}\p{Ll}+ська)\s+(?:обл\.|область)/u', $win, $r)) $out['region'] = $r[1] . ' область';
        if (preg_match('/(?<![\d+\-(])(0[1-9]\d{3}|[1-9]\d{4})(?![\d\-)])/', $win, $z)) {
            // індекс приймаємо лише поруч з адресою/містом (не номер телефону)
            $zp = strpos($win, $z[1]);
            $sp = strpos($win, $street);
            if ($zp !== false && $sp !== false && abs($zp - $sp) < 120) $out['postal'] = $z[1];
        }
        return $out;
    }

    /** «Для коду знайдено на сайті: ...» - звідки взято дані для готового коду. */
    private function factsLine(): string
    {
        $f = $this->facts;
        $src = ['schema' => 'з наявної розмітки', 'img' => 'з шапки сайту', 'apple-touch-icon' => 'apple-touch-icon', 'og:image' => 'og:image - перевірте, що це саме логотип'];
        $parts = [];
        if (!empty($f['logo'])) $parts[] = 'логотип (' . ($src[$f['logo_src']] ?? $f['logo_src']) . ')';
        if (!empty($f['phones'])) $parts[] = 'телефон ' . implode(', ', array_slice($f['phones'], 0, 2));
        if (!empty($f['emails'])) $parts[] = 'email ' . $f['emails'][0];
        if (!empty($f['address'])) {
            $aTxt = '«' . $f['address']['street'] . ($f['address']['locality'] ? ', ' . $f['address']['locality'] : '') . '» (' . $f['address']['where'] . ')';
            $parts[] = $this->addrUsable($this->stype === 'local') ? 'адресу ' . $aTxt
                : 'у тексті є адреса ' . $aTxt . ', але в код її не підставлено - не факт, що це адреса компанії';
            $allA = $f['address']['all'] ?? [];
            if (count($allA) > 1) $parts[] = 'на тій самій сторінці ще ' . (count($allA) - 1) . ' ' . self::plural(count($allA) - 1, 'адреса', 'адреси', 'адрес') . ' (' . self::listSome(array_slice($allA, 1), 2) . ') - якщо це філії, кожній потрібен окремий блок з власною адресою';
        }
        if (!empty($f['socials'])) {
            $nets = array_map(function ($u) { return (string)preg_replace('/^www\./', '', (string)parse_url($u, PHP_URL_HOST)); }, $f['socials']);
            $parts[] = 'профілі: ' . self::listSome($nets, 6);
        }
        if (!empty($f['geo'])) $parts[] = 'координати з Google-карти';
        return $parts ? ' Для готового коду знайдено на сайті: ' . implode('; ', $parts) . '.' : '';
    }

    /** Назва послуги: перша частина title / H1; тип - останній пункт хлібних крихт. @return array{0:string,1:string} [name, serviceType] */
    private function serviceName(array $pg): array
    {
        /** @var Page $p */
        $p = $pg['page'];
        $crumb = null;
        foreach (self::nodesOf($pg, ['BreadcrumbList']) as $bl) {
            $items = (array)($bl['data']['itemListElement'] ?? []);
            $last = $items ? end($items) : null;
            if (is_array($last)) {
                $n = self::str($last['name'] ?? null) ?? (is_array($last['item'] ?? null) ? self::str($last['item']['name'] ?? null) : null);
                if ($n !== null) { $crumb = html_entity_decode($n, ENT_QUOTES | ENT_HTML5, 'UTF-8'); break; }
            }
        }
        $title = $p->title();
        $tp = null;
        if ($title !== '') {
            $parts = preg_split('/\s+[|\-–—:]\s+|\s*[|–—]\s*/u', $title) ?: [];
            $first = trim((string)($parts[0] ?? ''));
            if (mb_strlen($first) >= 3) $tp = $first;
        }
        $h1 = $p->headings()['h1'][0] ?? '';
        $name = $tp ?? ($h1 !== '' ? $h1 : ($crumb ?? '[ВКАЖІТЬ назву послуги]'));
        $type = $crumb ?? $tp ?? ($h1 !== '' ? $h1 : '[ВКАЖІТЬ тип послуги]');
        return [self::mbTrunc($name, 110), self::mbTrunc($type, 110)];
    }

    // ================================================================= 3. WebSite

    private function checkWebSite(): ?int
    {
        $home = $this->pages[0];
        $cands = [];
        foreach ($this->fetchedPages() as $pg) {
            foreach (self::nodesOf($pg, ['WebSite']) as $n) $cands[] = ['pg' => $pg, 'n' => $n];
            if ($cands && $pg['role'] === 'home') break;
        }
        if (!$cands) {
            if (!($home['page'] instanceof Page)) return null;
            $o = ['@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => $this->base . '/#website', 'url' => $this->homeUrl, 'name' => $this->brand];
            $lang = (string)($this->site['lang'] ?? '');
            if ($lang !== '') $o['inLanguage'] = $lang;
            $o['publisher'] = ['@id' => $this->orgId()];
            $og = $home['page']->og('site_name');
            $this->findings[] = $this->finding('no_website', 'low', 'Немає розмітки WebSite з назвою сайту', [
                'evidence' => 'На головній: ' . $this->typesLine($home) . '. og:site_name: ' . ($og ? '«' . $og . '»' : 'немає') . '.',
                'why'      => 'WebSite.name - основне джерело, з якого Google бере назву сайту в результатах пошуку. Ефект для AI-відповідей невеликий, але це дешевий і однозначний сигнал назви бренду.',
                'fix'      => 'Додайте на головну блок WebSite з name (і alternateName, якщо бренд пишуть по-різному). SearchAction додавати не обов\'язково: Google прибрав поле пошуку в результатах (sitelinks search box) у листопаді 2024.',
                'bad'      => 'JSON-LD на головній: ' . ($this->typesOnPage($home) ? implode(', ', $this->typesOnPage($home)) : 'немає'),
                'good'     => '{"@context": "https://schema.org", "@type": "WebSite", "name": "' . $this->brand . '", "url": "' . $this->homeUrl . '"}',
                'code'     => self::ldCode($o),
                'verify'   => 'https://validator.schema.org/ для головної: тип WebSite з name. Назву сайту в Google видно через кілька тижнів після переіндексації.',
                'effort'   => 'S',
            ]);
            return 0;
        }
        $n = $cands[0]['n'];
        $name = self::str($n['data']['name'] ?? null);
        $search = isset($n['data']['potentialAction']);
        $where = $this->where($cands[0]['pg']);
        if ($name === null) {
            $this->findings[] = $this->finding('website_name', 'low', 'У розмітці WebSite немає name', [
                'evidence' => 'WebSite знайдено (' . $where . '), але без властивості name.',
                'why'      => 'Без name Google вгадує назву сайту з title та og:site_name; явна назва в WebSite - найнадійніший сигнал.',
                'fix'      => 'Додайте в WebSite властивість "name": "' . $this->brand . '".',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($n['data'])), 400),
                'good'     => '"name": "' . $this->brand . '"',
                'verify'   => 'https://validator.schema.org/ - WebSite з name.',
                'effort'   => 'S',
            ]);
            return 50;
        }
        $this->passed[] = 'WebSite з назвою «' . self::mbTrunc($name, 40) . '» (' . $where . ')' . ($search ? '; SearchAction теж є, хоча з листопада 2024 року Google його не використовує (видаляти не обов\'язково)' : '');
        return 100;
    }

    // ================================================================= 4. Breadcrumbs

    private function checkBreadcrumbs(): ?int
    {
        $inner = [];
        foreach ($this->fetchedPages() as $pg) if ($pg['role'] !== 'home') $inner[] = $pg;
        $notFetched = [];
        foreach ($this->pages as $pg) if ($pg['role'] !== 'home' && !($pg['page'] instanceof Page)) $notFetched[] = $this->where($pg) . ' (' . $pg['note'] . ')';
        if (!$inner) {
            $this->data['breadcrumbs'] = $notFetched ? 'внутрішні сторінки не завантажились' : 'немає внутрішніх сторінок у вибірці';
            return null;
        }
        $with = []; $without = [];
        foreach ($inner as $pg) {
            if (self::nodesOf($pg, ['BreadcrumbList'])) $with[] = $pg; else $without[] = $pg;
        }
        $total = count($inner);
        $score = (int)round(100 * count($with) / $total);
        $this->data['breadcrumbs'] = count($with) . '/' . $total;
        $score = max(0, $score - $this->crumbContentFinding());
        if (!$without) {
            $this->passed[] = 'BreadcrumbList на всіх перевірених внутрішніх сторінках (' . $total . ')';
            return $score;
        }
        if ($with) $this->passed[] = 'BreadcrumbList є на ' . count($with) . ' з ' . $total . ' внутрішніх сторінок';
        // приклад для коду: пріоритет - товар/послуга/стаття/категорія
        usort($without, function ($a, $b) {
            $o = ['product' => 0, 'service' => 1, 'article' => 2, 'category' => 3, 'other' => 4, 'about' => 5, 'contact' => 6];
            return ($o[$a['role']] ?? 9) <=> ($o[$b['role']] ?? 9);
        });
        $ex = $without[0];
        $sev = (!$with || count($with) / $total < 0.5) ? 'medium' : 'low';
        $list = array_map(function ($pg) { return $this->where($pg) . ' (' . $this->typesLine($pg) . ')'; }, $without);
        $this->findings[] = $this->finding('breadcrumbs', $sev, $with
            ? 'BreadcrumbList є не на всіх внутрішніх сторінках'
            : 'На внутрішніх сторінках немає розмітки BreadcrumbList', [
            'evidence' => 'Без BreadcrumbList: ' . implode('; ', array_slice($list, 0, 4)) . '.' . ($with ? ' З розміткою: ' . self::listSome(array_map(function ($pg) { return $this->where($pg); }, $with), 3) . '.' : '')
                . ($notFetched ? ' Не завантажились: ' . self::listSome($notFetched, 2) . '.' : ''),
            'why'      => 'Хлібні крихти показують машинам ієрархію сайту: до якого розділу належить сторінка. Це допомагає правильно зрозуміти контекст сторінки, а в десктопній видачі Google показує цей шлях поруч із доменом (на мобільних із січня 2025 року лише домен).',
            'fix'      => 'Додайте BreadcrumbList у шаблони внутрішніх сторінок; пункти мають збігатися з видимими хлібними крихтами.',
            'bad'      => self::ucf($this->where($ex)) . ': ' . $this->typesLine($ex),
            'good'     => 'Головна -> розділ -> поточна сторінка у вигляді BreadcrumbList з position, name та item.',
            'code'     => self::ldCode($this->breadcrumbCode($ex)),
            'verify'   => 'Google Rich Results Test для ' . $ex['url'] . ' - розділ «Навігаційні ланцюжки» без помилок.',
            'effort'   => 'S',
        ]);
        return $score;
    }

    private static function ucf(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    /**
     * Зміст хлібних крихт: пункти з однаковою адресою, останній пункт (поточна сторінка) не схожий на H1 / title.
     * Додає знахідку breadcrumbs_content (low) і повертає штраф для підоцінки.
     */
    private function crumbContentFinding(): int
    {
        $issues = [];
        foreach ($this->fetchedPages() as $pg) {
            $bl = self::nodesOf($pg, ['BreadcrumbList']);
            if (!$bl) continue;
            $items = $bl[0]['data']['itemListElement'] ?? null;
            if (!is_array($items) || !self::isList($items) || !$items) continue;
            $rows = [];
            foreach ($items as $li) {
                if (!is_array($li)) continue;
                $it = $li['item'] ?? null;
                $name = self::str($li['name'] ?? null) ?? (is_array($it) ? self::str($it['name'] ?? null) : null);
                $u = self::urlOf($it ?? ($li['url'] ?? null));
                if ($u !== null) $u = Crawler::absolutize($u, (string)$pg['url']) ?? $u;
                $rows[] = ['name' => $name !== null ? html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null, 'url' => $u];
            }
            if (!$rows) continue;
            $msgs = [];
            $byUrl = [];
            foreach ($rows as $i => $r) if ($r['url'] !== null) $byUrl[self::normUrl($r['url'])][] = $i + 1;
            foreach ($byUrl as $k => $pos) {
                if (count($pos) > 1) { $msgs[] = 'пункти ' . implode(' і ', $pos) . ' ведуть на ту саму адресу ' . self::shortUrl((string)$rows[$pos[0] - 1]['url']); break; }
            }
            $last = end($rows);
            $isCurrent = $last['url'] === null || self::normUrl((string)$last['url']) === self::normUrl((string)$pg['url']);
            if ($pg['role'] !== 'home' && $isCurrent && $last['name'] !== null && count($rows) >= 2) {
                /** @var Page $p */
                $p = $pg['page'];
                $h1 = trim((string)($p->headings()['h1'][0] ?? ''));
                $title = $p->title();
                if (($h1 !== '' || $title !== '') && !$this->nameMatches((string)$last['name'], [$h1, $title])) {
                    $msgs[] = 'останній пункт (поточна сторінка) називається «' . self::mbTrunc((string)$last['name'], 50) . '», а сторінка - «' . self::mbTrunc($h1 !== '' ? $h1 : $title, 60) . '»';
                }
            }
            if ($msgs) {
                $show = array_map(function ($r, $i) { return ($i + 1) . '. ' . ($r['name'] ?? '(без name)') . ($r['url'] !== null ? ' -> ' . self::shortUrl((string)$r['url']) : ''); }, $rows, array_keys($rows));
                $issues[] = ['where' => $this->where($pg), 'text' => implode('; ', $msgs), 'bad' => self::ucf($this->where($pg)) . ":\n" . implode("\n", array_slice($show, 0, 5)), 'pg' => $pg];
            }
        }
        if (!$issues) return 0;
        $ex = $issues[0]['pg'];
        foreach ($issues as $x) if ($x['pg']['role'] !== 'home') { $ex = $x['pg']; break; }
        $code = ($ex['role'] !== 'home') ? self::ldCode($this->breadcrumbCode($ex)) : '';
        $this->findings[] = $this->finding('breadcrumbs_content', 'low', 'Хлібні крихти в розмітці не відповідають сторінкам', [
            'evidence' => implode('. ', array_map(function ($x) { return self::ucf($x['where']) . ': ' . $x['text']; }, array_slice($issues, 0, 3))) . '.',
            'why'      => 'BreadcrumbList має показувати реальний шлях до сторінки. Пункти з тією самою адресою чи назвою бренду замість назви сторінки дають машинам хибну ієрархію, і Google може не показати шлях у сніпеті.',
            'fix'      => 'Формуйте пункти з реальної ієрархії: «Головна» -> розділ -> поточна сторінка, назва останнього пункту - як H1 сторінки, мова - як мова сторінки, без повторів адрес. На головній BreadcrumbList не потрібен.',
            'bad'      => $issues[0]['bad'],
            'good'     => $ex['role'] === 'home' ? 'На головній BreadcrumbList не потрібен: приберіть блок або залиште один пункт «Головна».'
                : self::ucf($this->where($ex)) . ': Головна -> розділ -> «' . self::mbTrunc((string)($ex['page']->headings()['h1'][0] ?? 'назва сторінки'), 60) . '» (кожен пункт з власною адресою, див. готовий код)',
            'code'     => $code,
            'verify'   => 'Google Rich Results Test для ' . $ex['url'] . ' - «Навігаційні ланцюжки»: пункти мають відповідати видимим хлібним крихтам.',
            'effort'   => 'S',
        ]);
        return min(20, 10 * count($issues));
    }

    /** Чи схожа назва пункту на H1 / title (спільна основа слова, без слів бренду). */
    private function nameMatches(string $crumb, array $refs): bool
    {
        $stems = function (string $s): array {
            $out = [];
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: [] as $t) {
                if (mb_strlen($t) >= 3) $out[mb_substr($t, 0, 5)] = true;
            }
            return $out;
        };
        $lc = function (string $s) { return trim(mb_strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))); };
        if (isset($refs[0]) && $refs[0] !== '' && $lc($crumb) === $lc((string)$refs[0])) return true; // дослівно як H1
        $brand = $stems($this->brand . ' ' . SiteProfile::hostLabel($this->base));
        $c = array_diff_key($stems($crumb), $brand);
        if (!$c) return false; // пункт - лише назва бренду
        foreach ($refs as $r) {
            if ($r === '') continue;
            if (array_intersect_key($c, array_diff_key($stems($r), $brand))) return true;
        }
        return false;
    }

    /** Текст посилання, що не є назвою сторінки: перемикач мови, «Детальніше», стрілки. */
    private static function isServiceAnchor(string $t): bool
    {
        return (bool)preg_match('/^(ua|uk|ukr|укр|українська|ukrainian|en|eng|english|ru|rus|рус|русский|pl|de|fr|детальніше|докладніше|читати далі|читати більше|більше|ще|details|more|read more|learn more|[←→»«<>]+)$/iu', trim($t));
    }

    private function breadcrumbCode(array $pg): array
    {
        /** @var Page $p */
        $p = $pg['page'];
        $url = (string)$pg['url'];
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
        $segs = array_values(array_filter(explode('/', $path), function ($s) { return $s !== ''; }));
        $lang = ($segs && preg_match('/^(ua|uk|en|ru)$/i', $segs[0])) ? array_shift($segs) : null;
        $prefix = $this->base . ($lang ? '/' . $lang : '');
        $slash = substr($path, -1) === '/' ? '/' : '';
        // найчастіший змістовний текст посилання на кожну адресу (без перемикачів мови й «Детальніше»)
        $cnt = [];
        foreach ($p->internalLinks() as $l) {
            $t = trim((string)$l['text']);
            if ($t === '' || mb_strlen($t) > 60 || mb_strlen($t) <= 3 || self::isServiceAnchor($t)) continue;
            $k = self::normUrl($l['href']);
            $cnt[$k][$t] = ($cnt[$k][$t] ?? 0) + 1;
        }
        $anchors = [];
        foreach ($cnt as $k => $texts) { arsort($texts); $anchors[$k] = (string)array_key_first($texts); }
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Головна', 'item' => $this->homeUrl]];
        $acc = $prefix;
        $n = count($segs);
        for ($i = 0; $i < $n - 1; $i++) {
            $acc .= '/' . $segs[$i];
            $u = $acc . $slash;
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $anchors[self::normUrl($u)] ?? $anchors[self::normUrl($acc)] ?? '[ВКАЖІТЬ назву розділу]', 'item' => $u];
        }
        // поточна сторінка: H1, потім перша частина title, потім текст посилань
        $h1 = trim((string)($p->headings()['h1'][0] ?? ''));
        $tp = '';
        if ($p->title() !== '') {
            $parts = preg_split('/\s+[|\-–—:]\s+|\s*[|–—]\s*/u', $p->title()) ?: [];
            $tp = trim((string)($parts[0] ?? ''));
        }
        $name = ($h1 !== '' && mb_strlen($h1) <= 110) ? $h1 : ($tp !== '' ? $tp : ($anchors[self::normUrl($url)] ?? ''));
        $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => self::mbTrunc($name !== '' ? $name : '[ВКАЖІТЬ назву сторінки]', 110), 'item' => $url];
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    // ================================================================= 5. Type-specific

    private function checkTypeSpecific(): ?int
    {
        $primary = null;
        switch ($this->stype) {
            case 'ecommerce': $primary = $this->checkEcommerce(); break;
            case 'local':     $primary = $this->checkLocal(); break;
            case 'media':     $primary = $this->checkArticles(true); break;
            case 'saas':      $primary = $this->checkSaas(); break;
            case 'services':  $primary = $this->checkServices(); break;
            case 'personal':  $primary = $this->checkProfilePage(); break;
            default:          $primary = null;
        }
        $article = null;
        if ($this->stype !== 'media' && $this->pagesByRole('article', true)) $article = $this->checkArticles(false);
        $this->data['type_specific'] = ['primary' => $primary, 'article' => $article];
        if ($primary === null && in_array($this->stype, ['ecommerce', 'local', 'media', 'saas', 'services'], true)) {
            // головну для типу сайту розмітку не перевірено - стаття блогу не може її замінити в оцінці
            $this->data['type_specific']['note'] = 'розмітку під тип сайту не оцінено: ключову сторінку не перевірено';
            return null;
        }
        if ($primary === null && $article === null) return null;
        return $this->scoreFrom(['p' => [$primary, 1.0], 'a' => [$article, 0.5]]);
    }

    // ---------------- ecommerce

    private function discoverProduct(): void
    {
        $cands = [];
        foreach ($this->pages as $pg) {
            if (!($pg['page'] instanceof Page) || !in_array($pg['role'], ['home', 'category'], true)) continue;
            $crumbs = [];
            foreach (self::nodesOf($pg, ['BreadcrumbList']) as $bl) {
                foreach ((array)($bl['data']['itemListElement'] ?? []) as $li) {
                    $cu = is_array($li) ? self::urlOf($li['item'] ?? ($li['url'] ?? null)) : null;
                    if ($cu) $crumbs[self::normUrl($cu)] = true;
                }
            }
            foreach ($pg['nodes'] as $n) {
                if (self::isType($n, ['ListItem'], false)) {
                    $lu = self::urlOf($n['data']['url'] ?? ($n['data']['item'] ?? null));
                    if ($lu === null || isset($crumbs[self::normUrl($lu)])) continue;
                }
                if (self::isType($n, ['Product']) || self::isType($n, ['ListItem'], false)) {
                    $u = self::urlOf($n['data']['url'] ?? ($n['data']['item'] ?? null));
                    if ($u && Crawler::sameSite($u, $this->base) && self::normUrl($u) !== self::normUrl((string)$pg['url'])) $cands[] = $u;
                }
            }
            foreach ($pg['page']->internalLinks() as $l) {
                if (!$l['in_nav'] && SiteProfile::roleOfUrl($l['href']) === 'product') $cands[] = $l['href'];
            }
        }
        $cands = array_values(array_unique($cands));
        if (!$cands) return;
        $u = $cands[0];
        $asBrowser = (($this->ctx['access']['fetched_as'] ?? 'bot') === 'browser');
        $r = $asBrowser ? Crawler::fetchBrowser($u, 12) : Crawler::fetch($u, 12);
        $p = ((int)$r['status'] >= 200 && (int)$r['status'] < 400) ? Page::fromCrawl($r) : null;
        if (!$p || $p->challenge() !== null) {
            $this->data['product_discovery'] = ['url' => $u, 'result' => 'не завантажилась (HTTP ' . (int)$r['status'] . ')'];
            return;
        }
        $isProduct = $p->hasSchemaType('Product') || (preg_match('/(додати (до|в|у) кошик|в кошик|у кошик|купити|add to cart)/u', mb_strtolower($p->visibleText()))
            && preg_match('/\d\s?(₴|грн|uah)/iu', $p->visibleText()));
        if (!$isProduct) {
            $this->data['product_discovery'] = ['url' => $u, 'result' => 'не схожа на картку товару'];
            return;
        }
        $pg = $this->mkPage('product', (string)($r['final_url'] ?: $u), $p, 'знайдено перевіркою розмітки');
        $pg['via'] = 'schema-check';
        $this->pages[] = $pg;
        $this->data['product_discovery'] = ['url' => $u, 'result' => 'додано у вибірку'];
    }

    // ---------------- local: сторінки філій

    /** Чи треба дивитися сторінки філій: на головній / «Про нас» / «Контактах» немає LocalBusiness з адресою. */
    private function needBranchPages(): bool
    {
        foreach ($this->pages as $pg) {
            if (!($pg['page'] instanceof Page) || !in_array($pg['role'], ['home', 'about', 'contact'], true)) continue;
            foreach (self::nodesOf($pg, ['LocalBusiness']) as $n) {
                if ($n['top'] && $this->streetKey($n['data'], $pg) !== '') return false;
            }
        }
        return true;
    }

    /**
     * Знаходить 1-2 сторінки філій за посиланнями з головної / «Контактів» (текст посилання - адреса,
     * або URL на кшталт /kliniky/..., /filial/...) і додає їх у вибірку з роллю 'branch'.
     */
    private function discoverBranches(): void
    {
        $known = [];
        foreach ($this->pages as $pg) $known[self::normUrl((string)$pg['url'])] = true;
        $known[self::normUrl($this->homeUrl)] = true;
        $known[self::normUrl($this->base)] = true;
        $streetRx = '/(?<!\p{L})(?:вул\.|вулиця|просп\.|проспект|пр-т|бульв\.|бульвар|пров\.|провулок|пл\.|площа|наб\.|набережна|шосе)\s*\p{Lu}[\p{L}\'’ʼ.\- ]{1,40}?,?\s*\d{1,4}/u';
        $urlRx = '#/(?:[^/]*-)?(klinik|clinic|filia|filiy|filial|branch|apteka|apteky|pharmacy|magazyny|magazin-na|store-locator|stores|viddilen|lokaci|location)[^/]*/?#i';
        $score = []; $freq = [];
        foreach ($this->pages as $pg) {
            if (!($pg['page'] instanceof Page) || !in_array($pg['role'], ['home', 'contact', 'about'], true)) continue;
            foreach ($pg['page']->internalLinks() as $l) {
                $u = (string)$l['href'];
                $k = self::normUrl($u);
                if (isset($known[$k]) || !Crawler::sameSite($u, $this->base) || preg_match('/\.(pdf|jpe?g|png|webp|svg|docx?)$/i', $u)) continue;
                $path = (string)(parse_url($u, PHP_URL_PATH) ?? '');
                if (trim($path, '/') === '' || preg_match('#^/(ua|uk|en|ru)/?$#i', $path)) continue;
                $s = 0;
                if (preg_match($streetRx, (string)$l['text'])) $s = 3;
                elseif (preg_match($urlRx, $path) && substr_count(trim($path, '/'), '/') >= 1) $s = 2;
                if ($s === 0) continue;
                $score[$u] = max($score[$u] ?? 0, $s);
                $freq[$u] = ($freq[$u] ?? 0) + 1;
            }
        }
        $this->branchProbe = ['candidates' => count($score), 'checked' => [], 'failed' => [], 'by_address' => false];
        if (!$score) return;
        $urls = array_keys($score);
        usort($urls, function ($a, $b) use ($score, $freq) {
            return [$score[$b], $freq[$b]] <=> [$score[$a], $freq[$a]];
        });
        $pick = array_slice($urls, 0, 2);
        $this->branchProbe['by_address'] = $score[$pick[0]] >= 3;
        $asBrowser = (($this->ctx['access']['fetched_as'] ?? 'bot') === 'browser');
        $res = Crawler::fetchMulti($pick, $asBrowser ? 'browser' : null, 12);
        foreach ($pick as $u) {
            $r = $res[$u] ?? ['status' => 0, 'body' => ''];
            $p = ((int)$r['status'] >= 200 && (int)$r['status'] < 400) ? Page::fromCrawl($r) : null;
            if (!$p || $p->challenge() !== null) {
                $this->branchProbe['failed'][] = self::shortUrl($u) . ' - ' . ((int)$r['status'] === 0 && !$p ? 'немає відповіді' : self::blockLabel($p ? (string)$p->challenge() : '', (int)$r['status']));
                continue;
            }
            $pg = $this->mkPage('branch', (string)(($r['final_url'] ?? '') ?: $u), $p, 'сторінка філії (знайдено перевіркою розмітки)');
            $pg['via'] = 'schema-check';
            $this->pages[] = $pg;
            $this->branchProbe['checked'][] = self::shortUrl($u) . ' - ' . $this->typesLine($pg);
        }
        $this->data['branch_pages'] = ['candidates' => count($score), 'checked' => count($this->branchProbe['checked']), 'failed' => count($this->branchProbe['failed'])];
    }

    private function checkEcommerce(): ?int
    {
        $prods = $this->pagesByRole('product', true);
        $score = null;
        if (!$prods) {
            $nf = $this->pagesByRole('product', false);
            $why = $nf ? 'картка товару ' . self::shortUrl((string)reset($nf)['url']) . ' не завантажилась (' . reset($nf)['note'] . ')'
                : 'у вибірці немає жодної картки товару' . (isset($this->data['product_discovery']) ? ' (спроба знайти: ' . $this->data['product_discovery']['url'] . ' - ' . $this->data['product_discovery']['result'] . ')' : '');
            $this->findings[] = $this->finding('product_unchecked', 'low', 'Розмітку Product на картках товарів не перевірено автоматично', [
                'evidence' => 'Причина: ' . $why . '. Висновок про наявність чи відсутність Product+Offer зробити не можна.',
                'why'      => 'Для магазину розмітка Product з ціною і наявністю - ключова: разом із фідом Merchant Center це основне джерело, з якого Google бере ціну, наявність і рейтинг товару; на структуровані дані про товари спирається і AI-шопінг.',
                'fix'      => 'Перевірте вручну 2-3 картки популярних товарів у Google Rich Results Test.',
                'bad'      => 'Картку товару не перевірено: ' . $why,
                'good'     => 'Кожна картка товару містить Product з name, image, offers (price, priceCurrency "UAH", availability).',
                'verify'   => 'Google Rich Results Test -> URL картки товару -> розділ «Фрагменти товарів» / «Оголошення продавців».',
                'effort'   => 'S',
            ]);
        } else {
            $pg = reset($prods);
            $score = $this->checkProduct($pg);
        }
        // категорія - необов'язково
        foreach ($this->pagesByRole('category', true) as $cg) {
            $lists = self::nodesOf($cg, ['ItemList', 'CollectionPage', 'OfferCatalog']);
            $lists = array_values(array_filter($lists, function ($n) { return !self::isType($n, ['BreadcrumbList']); }));
            if ($lists) {
                $this->passed[] = 'Категорія ' . self::shortUrl((string)$cg['url']) . ' має ' . implode('/', $lists[0]['types']);
            } else {
                /** @var Page $p */
                $p = $cg['page'];
                $h1 = $p->headings()['h1'][0] ?? $p->title();
                $code = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $h1 !== '' ? $h1 : '[ВКАЖІТЬ назву категорії]', 'url' => $cg['url'],
                    'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'url' => '[ВКАЖІТЬ URL першого товару в лістингу]'],
                        ['@type' => 'ListItem', 'position' => 2, 'url' => '[ВКАЖІТЬ URL другого товару в лістингу]'],
                    ]]];
                $this->findings[] = $this->finding('category_itemlist', 'low', 'Сторінка категорії без ItemList / CollectionPage', [
                    'evidence' => 'Категорія ' . $cg['url'] . ': ' . $this->typesLine($cg) . '.',
                    'why'      => 'ItemList на лістингу - необов\'язкова, але корисна підказка, які товари входять у категорію. Для видимості в AI важливіша розмітка самих карток товарів.',
                    'fix'      => 'За бажанням додайте CollectionPage з ItemList перших 10-20 товарів лістингу (лише URL і позиції).',
                    'bad'      => 'Розмітка категорії: ' . $this->typesLine($cg),
                    'good'     => 'CollectionPage -> mainEntity: ItemList з ListItem (position, url) для товарів на сторінці.',
                    'code'     => self::ldCode($code),
                    'verify'   => 'https://validator.schema.org/ для сторінки категорії - ItemList без помилок.',
                    'effort'   => 'M',
                ]);
            }
            break;
        }
        return $score;
    }

    private function checkProduct(array $pg): int
    {
        /** @var Page $p */
        $p = $pg['page'];
        $items = self::nodesOf($pg, ['Product']);
        // найповніший: з offers, верхній рівень
        usort($items, function ($a, $b) {
            $sa = (isset($a['data']['offers']) ? 2 : 0) + ($a['top'] ? 1 : 0);
            $sb = (isset($b['data']['offers']) ? 2 : 0) + ($b['top'] ? 1 : 0);
            return $sb <=> $sa;
        });
        $facts = $this->productFacts($p, $items[0]['data'] ?? []);
        if (!$items) {
            $this->findings[] = $this->finding('product_missing', 'high', 'На картці товару немає розмітки Product з ціною і наявністю', [
                'evidence' => 'Картка ' . $pg['url'] . ': ' . $this->typesLine($pg) . '. На сторінці видно назву «' . self::mbTrunc($facts['name'], 80) . '»'
                    . ($facts['price_seen'] ? ' і ціну ' . $facts['price_seen'] : '') . ', але машиночитаних даних про товар немає.',
                'why'      => 'Product з Offer - джерело ціни, валюти і наявності для Google (товарні сніпети, Merchant listings); AI-шопінг (ChatGPT, Google AI Mode) теж спирається на структуровані дані про товари, зокрема фіди продавців. Без неї товар складніше потрапляє в порівняння і відповіді «скільки коштує».',
                'fix'      => 'Додайте JSON-LD Product у шаблон картки товару: name, image, description, sku, brand, offers (price, priceCurrency "UAH", availability), за наявності відгуків - aggregateRating.',
                'bad'      => 'Розмітка картки: ' . $this->typesLine($pg),
                'good'     => '{"@type": "Product", "name": "...", "image": "...", "offers": {"@type": "Offer", "price": "...", "priceCurrency": "UAH", "availability": "https://schema.org/InStock"}}',
                'code'     => self::ldCode($this->productCode($pg, [], $facts)),
                'verify'   => 'Google Rich Results Test для ' . $pg['url'] . ' - «Фрагменти товарів» / «Оголошення продавців» без критичних помилок. Після впровадження - звіти «Товари» в GSC.',
                'effort'   => 'M',
            ]);
            return 0;
        }
        $node = $items[0];
        $d = $node['data'];
        $name = self::str($this->val($d, 'name', $pg));
        $image = self::urlOf($this->val($d, 'image', $pg));
        $offersV = $this->val($d, 'offers', $pg);
        $offer = self::first($offersV);
        $price = $offer ? (self::str($this->val($offer, 'price', $pg)) ?? self::str($this->val($offer, 'lowPrice', $pg))) : null;
        if ($offer && $price === null && isset($offer['priceSpecification'])) {
            $ps = self::first($this->val($offer, 'priceSpecification', $pg));
            if ($ps) $price = self::str($ps['price'] ?? null);
        }
        $cur = $offer ? self::str($this->val($offer, 'priceCurrency', $pg)) : null;
        $avail = $offer ? self::str($this->val($offer, 'availability', $pg)) : null;
        $brand = $this->val($d, 'brand', $pg);
        $sku = null;
        foreach (['sku', 'gtin', 'gtin13', 'gtin12', 'gtin14', 'gtin8', 'mpn'] as $k) {
            $v = self::str($this->val($d, $k, $pg));
            if ($v !== null) { $sku = $k; break; }
        }
        $rating = $this->val($d, 'aggregateRating', $pg) ?? $this->val($d, 'review', $pg);

        $s = 35 + ($name ? 5 : 0) + ($image ? 5 : 0) + ($offer ? 20 : 0) + ($price !== null ? 10 : 0) + ($cur ? 7 : 0) + ($avail ? 8 : 0)
            + ($brand !== null ? 3 : 0) + ($sku ? 4 : 0) + ($rating !== null ? 3 : 0);

        $present = []; $req = []; $rec = [];
        if ($name) $present[] = 'name'; else $req[] = 'name';
        if ($image) $present[] = 'image'; else $req[] = 'image';
        if ($offer) {
            $present[] = 'offers (' . (self::typesOf($offer)[0] ?? 'Offer') . ')';
            if ($price !== null) $present[] = 'price ' . $price; else $req[] = 'offers.price';
            if ($cur) $present[] = 'priceCurrency ' . $cur; else $req[] = 'offers.priceCurrency';
            if ($avail) $present[] = 'availability ' . preg_replace('#^https?://schema\.org/#i', '', $avail); else $req[] = 'offers.availability';
        }
        if ($brand !== null) $present[] = 'brand'; else $rec[] = 'brand';
        if ($sku) $present[] = $sku; else $rec[] = 'sku / gtin';
        if ($rating !== null) $present[] = 'aggregateRating/review'; else $rec[] = 'aggregateRating або review (якщо на сторінці є відгуки)';
        foreach ($this->offerSubIssues($offer ? $this->unstub($offer, $pg) : null) as $x) $rec[] = $x;
        $curNote = ($cur && strtoupper($cur) !== 'UAH') ? ' Валюта «' . $cur . '», а не UAH - перевірте, чи це правильно для українського магазину.' : '';
        if (preg_grep('/^shippingDetails/', $rec)) $curNote .= ' У готовому коді до наявного shippingRate додано поле value - вкажіть реальну вартість доставки.';

        if (!$offer) {
            $this->findings[] = $this->finding('product_offer', 'high', 'У розмітці Product немає offers: ціни, валюти і наявності', [
                'evidence' => 'Картка ' . $pg['url'] . ': Product містить ' . implode(', ', $present) . '. Блоку offers немає.' . ($facts['price_seen'] ? ' Видима ціна на сторінці: ' . $facts['price_seen'] . '.' : ''),
                'why'      => 'Ціна і наявність - те, що AI-шопінг і Google показують насамперед. Без Offer товар не потрапить у Merchant listings, а товарний сніпет можливий лише за рейтингом чи відгуком і без ціни.',
                'fix'      => 'Доповніть Product блоком offers з ціною в UAH і наявністю (Offer, для варіантів - AggregateOffer).',
                'bad'      => self::mbTrunc(self::jsonPretty($this->showNode($d, $pg)), 560),
                'good'     => '"offers": {"@type": "Offer", "price": "' . ($facts['price'] ?? '...') . '", "priceCurrency": "UAH", "availability": "https://schema.org/InStock"}',
                'code'     => self::ldCode($this->productCode($pg, $this->unstub($d, $pg), $facts)),
                'verify'   => 'Google Rich Results Test для ' . $pg['url'] . ' - «Фрагменти товарів» без помилки «Missing field offers».',
                'effort'   => 'M',
            ]);
        } elseif ($req || $rec) {
            $sev = $req ? 'medium' : 'low';
            $this->findings[] = $this->finding('product_props', $sev, 'У розмітці Product бракує: ' . implode(', ', array_merge($req, array_map(function ($x) { return preg_replace('/ \(.*$/', '', $x); }, $rec))), [
                'evidence' => 'Картка ' . $pg['url'] . '. Є: ' . implode(', ', $present) . '. ' . ($req ? 'Немає важливих: ' . implode(', ', $req) . '. ' : '') . ($rec ? 'Немає рекомендованих: ' . implode(', ', $rec) . '.' : '') . $curNote,
                'why'      => $req
                    ? 'Ціна й валюта обов\'язкові для Merchant listings у Google, наявність рекомендована; у фіді Google Merchant Center обов\'язкові і ціна, і наявність. Без цих полів Google і AI-шопінг не можуть повноцінно показати пропозицію.'
                    : 'Бренд, артикул (sku/gtin) і рейтинг допомагають однозначно зіставити товар з іншими джерелами й обрати його у порівнянні. Рейтинг додавайте лише за реальними відгуками на сторінці.',
                'fix'      => 'Доповніть наявний Product відсутніми полями (готовий код нижче зберігає поточні значення).',
                'bad'      => self::mbTrunc(self::jsonPretty($this->showNode($d, $pg)), 560),
                'good'     => $req ? '"offers": {"price": "...", "priceCurrency": "UAH", "availability": "https://schema.org/InStock"}'
                    : implode(', ', array_filter([$brand === null ? '"brand": {"@type": "Brand", "name": "..."}' : '', $sku ? '' : '"sku": "...", "gtin13": "..."', $rating !== null ? '' : '"aggregateRating": {"@type": "AggregateRating", "ratingValue": "4.8", "reviewCount": "37"}',
                        preg_grep('/^shippingDetails/', $rec) ? '"shippingRate": {"@type": "MonetaryAmount", "value": "80", "currency": "UAH"}' : '',
                        preg_grep('/^hasMerchantReturnPolicy/', $rec) ? '"returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow"' : ''])),
                'code'     => self::ldCode($this->productCode($pg, $this->unstub($d, $pg), $facts)),
                'verify'   => 'Google Rich Results Test для ' . $pg['url'] . ' - без помилок і з мінімумом попереджень у «Фрагменти товарів».',
                'effort'   => 'S',
            ]);
        }
        if (!$req && $offer) $this->passed[] = 'Product на картці товару з ціною' . ($price !== null ? ' ' . $price . ' ' . ($cur ?? '') : '') . ($avail ? ' і наявністю' : '') . ' (' . self::shortUrl((string)$pg['url']) . ')';
        return min(100, $s);
    }

    private function productFacts(Page $p, array $d): array
    {
        $vt = $p->mainText();
        $name = self::str($d['name'] ?? null) ?? ($p->headings()['h1'][0] ?? '') ?: ($p->og('title') ?? $p->title());
        $priceSeen = null; $price = null;
        if (preg_match('/(?<![\d.,])(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+|\d+)(?:[.,](\d{2}))?\s?(₴|грн\.?|uah)(?!\p{L})/iu', $vt, $m)) {
            $priceSeen = trim($m[0]);
            $price = (string)preg_replace('/\D/u', '', $m[1]) . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : '');
        } elseif (preg_match('/(₴|uah)\s?(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+|\d+)(?:[.,](\d{2}))?(?![\d])/iu', $vt, $m)) {
            $priceSeen = trim($m[0]);
            $price = (string)preg_replace('/\D/u', '', $m[2]) . (isset($m[3]) && $m[3] !== '' ? '.' . $m[3] : '');
        }
        $avail = null;
        $low = mb_strtolower($vt);
        if (preg_match('/(немає в наявності|нема в наявності|закінчився|відсутній|out of stock)/u', $low)) $avail = 'https://schema.org/OutOfStock';
        elseif (preg_match('/(є в наявності|в наявності|у наявності|in stock)/u', $low)) $avail = 'https://schema.org/InStock';
        $img = $p->og('image');
        return ['name' => (string)$name, 'price_seen' => $priceSeen, 'price' => $price, 'availability' => $avail,
            'image' => $img ? Crawler::absolutize($img, $p->url()) : null, 'description' => $p->metaDescription()];
    }

    private function productCode(array $pg, array $d, array $facts): array
    {
        $o = ['@context' => 'https://schema.org', '@type' => 'Product'];
        $o['name'] = self::str($d['name'] ?? null) ?? ($facts['name'] !== '' ? $facts['name'] : '[ВКАЖІТЬ назву товару]');
        $img = $d['image'] ?? null;
        $o['image'] = $img ? $img : ($facts['image'] ?? '[ВКАЖІТЬ URL фото товару]');
        $desc = self::str($d['description'] ?? null) ?? ($facts['description'] !== '' ? $facts['description'] : null);
        if ($this->isRussianText($desc)) $desc = ($facts['description'] !== '' && !$this->isRussianText($facts['description'])) ? $facts['description'] : '[ВКАЖІТЬ короткий опис товару українською]';
        $o['description'] = $desc ? self::mbTrunc($desc, 300) : '[ВКАЖІТЬ короткий опис товару]';
        foreach (['sku', 'gtin13', 'gtin', 'mpn'] as $k) if (isset($d[$k]) && self::str($d[$k]) !== null) $o[$k] = $d[$k];
        if (!isset($o['sku']) && !isset($o['gtin13']) && !isset($o['gtin'])) $o['sku'] = '[ВКАЖІТЬ артикул]';
        $o['brand'] = $d['brand'] ?? ['@type' => 'Brand', 'name' => '[ВКАЖІТЬ бренд товару]'];
        $offer = self::first($d['offers'] ?? null) ?? [];
        $no = ['@type' => self::typesOf($offer)[0] ?? 'Offer', 'url' => $offer['url'] ?? $pg['url']];
        if (($no['@type']) === 'AggregateOffer') {
            foreach (['lowPrice', 'highPrice', 'offerCount'] as $k) if (isset($offer[$k])) $no[$k] = $offer[$k];
        }
        $no['price'] = $offer['price'] ?? ($facts['price'] ?? '[ВКАЖІТЬ ціну, напр. 1299.00]');
        if ($no['@type'] === 'AggregateOffer' && isset($no['lowPrice']) && !isset($offer['price'])) unset($no['price']);
        $no['priceCurrency'] = $offer['priceCurrency'] ?? 'UAH';
        $no['availability'] = $offer['availability'] ?? ($facts['availability'] ?? '[ВКАЖІТЬ: https://schema.org/InStock або https://schema.org/OutOfStock]');
        $no['itemCondition'] = $offer['itemCondition'] ?? 'https://schema.org/NewCondition';
        foreach (['priceValidUntil', 'seller', 'shippingDetails', 'hasMerchantReturnPolicy'] as $k) if (isset($offer[$k])) $no[$k] = $offer[$k];
        // скопійовані вкладені об'єкти доповнюємо обов'язковими полями, яких бракує
        if (isset($no['shippingDetails']) && is_array($no['shippingDetails'])) {
            $no['shippingDetails'] = self::fixShipping($no['shippingDetails']);
        }
        if (isset($no['hasMerchantReturnPolicy']) && is_array($no['hasMerchantReturnPolicy']) && !self::isList($no['hasMerchantReturnPolicy'])) {
            $rp = $no['hasMerchantReturnPolicy'];
            if (!isset($rp['returnPolicyCategory'])) $rp['returnPolicyCategory'] = '[ВКАЖІТЬ: https://schema.org/MerchantReturnFiniteReturnWindow або MerchantReturnNotPermitted]';
            if (!isset($rp['applicableCountry'])) $rp['applicableCountry'] = 'UA';
            $no['hasMerchantReturnPolicy'] = $rp;
        }
        $o['offers'] = $no;
        if (isset($d['aggregateRating'])) $o['aggregateRating'] = $d['aggregateRating'];
        return $o;
    }

    /** OfferShippingDetails: shippingRate без value отримує поле-заглушку (Google вимагає вартість доставки). */
    private static function fixShipping(array $sd): array
    {
        if (self::isList($sd)) {
            foreach ($sd as $i => $x) if (is_array($x)) $sd[$i] = self::fixShipping($x);
            return $sd;
        }
        $rate = $sd['shippingRate'] ?? null;
        if (is_array($rate) && !self::isList($rate) && !isset($rate['value']) && !isset($rate['maxValue'])) {
            $rate['value'] = '[ВКАЖІТЬ вартість доставки, 0 - безкоштовно]';
            $sd['shippingRate'] = $rate;
        }
        return $sd;
    }

    /** Відомі проблеми вкладених об'єктів Offer: shippingRate без value, політика повернення без категорії. */
    private function offerSubIssues(?array $offer): array
    {
        $out = [];
        if (!$offer) return $out;
        $sds = $offer['shippingDetails'] ?? null;
        if (is_array($sds)) {
            foreach (self::isList($sds) ? $sds : [$sds] as $sd) {
                $rate = is_array($sd) ? ($sd['shippingRate'] ?? null) : null;
                if (is_array($rate) && !self::isList($rate) && !isset($rate['value']) && !isset($rate['maxValue'])) {
                    $out[] = 'shippingDetails.shippingRate.value (зараз лише ' . implode(', ', array_diff(array_keys($rate), ['@type'])) . ')';
                    break;
                }
            }
        }
        $rp = $offer['hasMerchantReturnPolicy'] ?? null;
        if (is_array($rp) && !self::isList($rp) && !isset($rp['returnPolicyCategory'])) $out[] = 'hasMerchantReturnPolicy.returnPolicyCategory';
        return $out;
    }

    // ---------------- local

    private function checkLocal(): ?int
    {
        $E = $this->E;
        $R = null; $viaBranch = false;
        if ($E && $E['is_lb']) $R = $E;
        elseif ($this->branchLb) { $R = $this->bestBranch(); $viaBranch = true; }
        if (!$E && !$R) return 0; // no_entity (critical) вже з кодом LocalBusiness
        if ($R === null) return $this->localNotLb($E);

        $ev = $this->evalLocal($R, $viaBranch);
        $miss = $ev['miss'];
        $whereR = $viaBranch ? $this->where($this->pages[$R['list'][0]['pi']]) : self::listSome($R['where'], 3);
        if ($viaBranch) {
            $n = count($this->branchLb);
            $this->passed[] = 'Філії розмічені окремими блоками LocalBusiness (' . $n . ' на перевірених сторінках): ' . $R['type'] . ' «' . self::mbTrunc((string)$R['name'], 50) . '» (' . $whereR . ')'
                . ($ev['present'] ? ' - ' . implode(', ', array_slice($ev['present'], 0, 6)) : '');
        }
        if ($miss) {
            $sev = $ev['core'] ? 'medium' : 'low';
            $code = $viaBranch ? $this->branchCode($R) : $this->entityCode(true);
            $this->findings[] = $this->finding('local_props', $sev, 'У розмітці ' . $R['type'] . ($viaBranch ? ' філії' : '') . ' бракує: ' . self::listSome($miss, 5), [
                'evidence' => $R['type'] . ' «' . self::mbTrunc((string)$R['name'], 60) . '» (' . $whereR . '). Є: ' . implode(', ', $ev['present']) . '. Немає: ' . implode(', ', $miss) . '.'
                    . ($viaBranch ? '' : $this->factsLine()),
                'why'      => 'Адреса, телефон і графік роботи - факти, які AI-асистенти і Google AI Overviews наводять у відповідях про локальний бізнес. Чого немає в розмітці, те доводиться вгадувати з тексту.',
                'fix'      => $viaBranch
                    ? 'Доповніть шаблон блоку філії відсутніми властивостями (готовий код нижче зберігає поточні значення цієї філії) - так само на сторінках усіх філій.'
                    : 'Доповніть наявний блок відсутніми властивостями (готовий код нижче зберігає поточні значення і додає знайдені на сайті).',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($R['node'])), 560),
                'good'     => $this->localGood($miss, $R),
                'code'     => self::ldCode($code),
                'verify'   => 'Google Rich Results Test для сторінки з розміткою - тип ' . $R['type'] . ' без помилок і попереджень; дані мають збігатися з Google Business Profile.',
                'effort'   => 'S',
            ]);
        }
        if (!$ev['core'] && !$viaBranch) $this->passed[] = $R['type'] . ' з адресою, телефоном і годинами роботи';
        if (!$viaBranch && $this->branchLb) {
            $bl = [];
            foreach (array_slice($this->branchLb, 0, 3) as $b) {
                $a = self::addressParts($this->val($b['node']['data'], 'address', $this->pages[$b['pi']]));
                $bl[] = implode('/', $b['node']['types']) . ' (' . self::mbTrunc((string)($a['street'] ?? $a['string'] ?? ''), 50) . ')';
            }
            $n = count($this->branchLb);
            $this->passed[] = 'Ще ' . $n . ' ' . self::plural($n, 'точку', 'точки', 'точок') . ' мережі розмічено окремими блоками з власною адресою: ' . implode(', ', $bl);
        }
        return min(100, $ev['s']);
    }

    /** Оцінка LocalBusiness-запису: бал, відсутні поля (core - адреса, телефон, години роботи, назва), перелік наявних. */
    private function evalLocal(array $R, bool $branch): array
    {
        $a = $R['addr'];
        $hasOpen = $R['opening'] !== null;
        $geo = $R['geo'];
        $hasGeo = $geo !== null && (isset($geo['latitude']) || self::isRef($geo));
        $hasPrice = $R['priceRange'] !== null;
        $hasMap = $R['hasMap'] !== null;
        if (!$hasMap) foreach ($R['sameAs'] as $s) if (preg_match('#(google\.[a-z.]+/maps|maps\.app\.goo\.gl|goo\.gl/maps)#i', $s)) $hasMap = true;
        $phone = $R['telephone'] ?? $R['cp_tel'];
        $country = $a['country'];
        $countryOk = $country !== null && in_array(mb_strtolower($country), ['ua', 'ukr', 'україна', 'ukraine'], true);
        $s = ($R['is_lb'] ? 30 : 0) + ($a['street'] || $a['string'] ? 10 : 0) + ($a['locality'] ? 6 : 0) + ($a['postal'] ? 4 : 0)
            + ($countryOk ? 5 : 0) + ($phone ? 10 : 0) + ($hasOpen ? 15 : 0) + ($hasGeo ? 8 : 0) + ($hasPrice ? 4 : 0) + ($hasMap ? 8 : 0);

        $miss = []; $core = false; $present = [];
        if ($R['name'] !== null) $present[] = 'name'; else { $miss[] = 'name'; $core = true; }
        if ($R['url'] !== null) $present[] = 'url'; elseif (!$branch) $miss[] = 'url';
        if (!$branch) {
            if ($R['grade']['logo'] >= 1.0) $present[] = $R['logo'] !== null ? 'logo' : 'image';
            else $miss[] = $R['logo_bad'] ? 'logo (некоректний URL)' : 'logo';
            $sn = count($R['sameAs']);
            if ($sn >= 2) $present[] = 'sameAs (' . $sn . ' ' . self::plural($sn, 'профіль', 'профілі', 'профілів') . ')';
            else $miss[] = $sn === 1 ? 'sameAs (лише 1 профіль, потрібно 2+)' : 'sameAs';
        }
        $parentPhone = false;
        if ($branch && !$phone) {
            $po = self::first($R['node']['parentOrganization'] ?? null);
            $pcp = $po ? self::first($po['contactPoint'] ?? null) : null;
            if ($po && (self::str($po['telephone'] ?? null) !== null || ($pcp && self::str($pcp['telephone'] ?? null) !== null))) $parentPhone = true;
        }
        if ($phone) $present[] = 'telephone';
        elseif ($parentPhone) $miss[] = 'telephone (є лише загальний у parentOrganization)';
        else { $miss[] = 'telephone'; $core = true; }
        if ($R['address'] === null) { $miss[] = 'address'; $core = true; }
        else {
            $present[] = ($a['street'] && $a['locality']) ? 'address' : 'address (' . self::addrIncomplete($a) . ')';
            if (!$a['street'] && !$a['string']) { $miss[] = 'address.streetAddress'; $core = true; }
            if (!$a['locality'] && !$a['string']) { $miss[] = 'address.addressLocality'; $core = true; }
            if (!$a['postal'] && !$a['string']) $miss[] = 'address.postalCode';
            if (!$a['string'] && !$countryOk) $miss[] = $country === null ? 'address.addressCountry' : 'addressCountry (зараз «' . $country . '», краще «UA»)';
        }
        if ($hasOpen) $present[] = 'години роботи'; else { $miss[] = 'openingHoursSpecification'; $core = true; }
        if ($hasGeo) $present[] = 'geo'; else $miss[] = 'geo';
        if ($hasPrice) $present[] = 'priceRange'; else $miss[] = 'priceRange';
        if ($hasMap) $present[] = 'hasMap'; else $miss[] = 'hasMap';
        return ['s' => $s, 'miss' => array_values(array_unique($miss)), 'core' => $core, 'present' => $present];
    }

    /** «Як треба» лише для властивостей, яких бракує. */
    private function localGood(array $miss, array $R): string
    {
        $out = [];
        $addrDone = false;
        foreach ($miss as $m) {
            $k = (string)preg_replace('/ \(.*$/', '', $m);
            if (strpos($k, 'address') === 0 || strpos($k, 'addressCountry') === 0) {
                if ($addrDone) continue;
                $addrDone = true;
                $out[] = '"address": {"@type": "PostalAddress", "streetAddress": "вул. ..., 1", "addressLocality": "Київ", "postalCode": "01001", "addressCountry": "UA"}';
                continue;
            }
            switch ($k) {
                case 'sameAs':
                    $s = array_slice($this->sameAsList(), 0, 2);
                    $s[] = '[ще один профіль: Instagram / Facebook / YouTube]';
                    $out[] = '"sameAs": ' . json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    break;
                case 'telephone': $out[] = '"telephone": "' . ($this->facts['phones'][0] ?? '+380...') . '"'; break;
                case 'openingHoursSpecification': $out[] = '"openingHoursSpecification": [{"@type": "OpeningHoursSpecification", "dayOfWeek": ["Monday", "Friday"], "opens": "09:00", "closes": "18:00"}]'; break;
                case 'geo': $out[] = '"geo": {"@type": "GeoCoordinates", "latitude": 50.4501, "longitude": 30.5234}'; break;
                case 'priceRange': $out[] = '"priceRange": "₴₴"'; break;
                case 'hasMap': $out[] = '"hasMap": "https://maps.google.com/?cid=..."'; break;
                case 'logo': $out[] = '"logo": "' . ($this->facts['logo'] ?? 'https://.../logo.png') . '"'; break;
                case 'url': $out[] = '"url": "' . $this->homeUrl . '"'; break;
                case 'name': $out[] = '"name": "' . (($R['name'] ?? null) ?: $this->brand) . '"'; break;
            }
        }
        return $out ? implode(",\n", $out) : 'Усі ключові властивості ' . $R['type'] . ' заповнені реальними даними.';
    }

    /** Найповніший розмічений блок філії. */
    private function bestBranch(): ?array
    {
        $best = null; $bs = -1;
        foreach ($this->branchLb as $b) {
            $R = $this->mergeEntity([$b]);
            $sc = count($this->evalLocal($R, true)['present']);
            if ($sc > $bs) { $bs = $sc; $best = $R; }
        }
        return $best;
    }

    /** Код для блоку філії: поточні значення + поля, яких бракує (з позначками [ВКАЖІТЬ ...]). */
    private function branchCode(array $R): array
    {
        $c = self::codeNode($R['node']);
        if (($R['telephone'] ?? $R['cp_tel']) === null) $c['telephone'] = $this->phPhone();
        $a = $R['addr'];
        if ($R['address'] === null || (is_array($c['address'] ?? null) && !self::isList($c['address']))) {
            $addr = is_array($c['address'] ?? null) ? $c['address'] : ['@type' => 'PostalAddress'];
            if ($a['street'] === null) $addr['streetAddress'] = '[ВКАЖІТЬ вулицю і номер будинку]';
            if ($a['locality'] === null) $addr['addressLocality'] = '[ВКАЖІТЬ місто / населений пункт]';
            if ($a['postal'] === null) $addr['postalCode'] = '[ВКАЖІТЬ поштовий індекс]';
            if ($a['country'] === null) $addr['addressCountry'] = 'UA';
            $c['address'] = $addr;
        }
        if ($R['opening'] === null) {
            $c['openingHoursSpecification'] = [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'opens' => '[ВКАЖІТЬ, напр. 09:00]', 'closes' => '[ВКАЖІТЬ, напр. 18:00]']];
        }
        if ($R['geo'] === null) $c['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => '[ВКАЖІТЬ широту філії]', 'longitude' => '[ВКАЖІТЬ довготу філії]'];
        if ($R['hasMap'] === null) $c['hasMap'] = '[ВКАЖІТЬ посилання на цю філію в Google Maps]';
        if ($R['priceRange'] === null) $c['priceRange'] = '[ВКАЖІТЬ, напр. ₴₴ або 500-3000 UAH]';
        if (!isset($c['parentOrganization']) && $this->E) $c['parentOrganization'] = ['@id' => $this->orgId()];
        return $c;
    }

    /** Організація не LocalBusiness і розмічених філій немає: local_branches (мережа) або local_not_lb. */
    private function localNotLb(array $E): int
    {
        $ev = $this->evalLocal($E, false);
        $miss = $ev['miss'];
        $lbCode = $this->entityCode(true);
        $lbType = (string)$lbCode['@type'];
        $allAddr = $this->facts['address']['all'] ?? [];
        $probe = $this->branchProbe;
        if (count($allAddr) >= 3) {
            // мережа філій: головна сутність може лишитися Organization, а кожна філія - окремий LocalBusiness
            $br = $lbCode;
            $br['@id'] = $this->base . '/#branch-1';
            $br = array_slice($br, 0, 3, true) + ['parentOrganization' => ['@id' => $this->orgId()]] + array_slice($br, 3, null, true);
            unset($br['sameAs'], $br['logo'], $br['description']);
            $br['hasMap'] = '[ВКАЖІТЬ посилання на цю філію в Google Maps]';
            $missBr = array_values(array_filter($E['missing'], function ($m) { return $m !== 'address'; }));
            $aw = $this->facts['address']['where'];
            $awText = $aw === 'головна' ? 'На головній' : 'На сторінці ' . $aw;
            $checkedBranches = $probe && $probe['checked'];
            $pagesChecked = [];
            foreach ($this->pages as $pg) if ($pg['page'] instanceof Page && in_array($pg['role'], ['home', 'about', 'contact'], true)) $pagesChecked[] = $this->where($pg);
            $scope = $checkedBranches
                ? 'Розмітки LocalBusiness для філій немає ні на перевірених сторінках (' . self::listSome($pagesChecked, 3) . '), ні на сторінках філій: ' . self::listSome($probe['checked'], 2)
                : 'На перевірених сторінках (' . self::listSome($pagesChecked, 3) . ') розмітки філій не знайдено; '
                    . ($probe && $probe['failed'] ? 'сторінки філій завантажити не вдалося (' . self::listSome($probe['failed'], 2) . ')' : 'окремих сторінок філій за посиланнями не знайдено, тож їх не перевірено');
            $this->findings[] = $this->finding('local_branches', $checkedBranches ? 'medium' : 'low', $checkedBranches
                ? 'На сайті ' . count($allAddr) . ' ' . self::plural(count($allAddr), 'адреса', 'адреси', 'адрес') . ', але філії не розмічені як LocalBusiness'
                : 'На сайті ' . count($allAddr) . ' ' . self::plural(count($allAddr), 'адреса', 'адреси', 'адрес') . ', розмітки філій на перевірених сторінках не знайдено', [
                'evidence' => 'Знайдено ' . $E['type'] . ' «' . $E['name'] . '» (' . self::listSome($E['where'], 3) . ') без адреси. ' . $awText . ' ' . count($allAddr) . ' ' . self::plural(count($allAddr), 'адреса', 'адреси', 'адрес')
                    . ': ' . self::listSome($allAddr, 3) . '. ' . $scope . ($missBr ? '. У головній сутності також немає: ' . implode(', ', $missBr) : '') . '.',
                'why'      => 'Для запитів «найближча клініка», «де знаходиться», «графік роботи» Google спирається насамперед на Business Profile кожної точки, а LocalBusiness на сторінці філії дає ті самі факти (адресу, телефон, години, координати) у машиночитаному вигляді для пошуку й AI. Одна загальна організація без адрес цих фактів не дає.',
                'fix'      => ($checkedBranches ? '' : 'Перевірте 1-2 сторінки філій у https://validator.schema.org/: якщо LocalBusiness там уже є - зауваження можна закрити. ')
                    . 'Залиште ' . $E['type'] . ' як головну сутність мережі (з @id), а для кожної філії додайте окремий блок ' . $lbType . ' з власною адресою, телефоном, графіком, координатами і parentOrganization - на сторінці філії або на сторінці контактів. Нижче - шаблон для першої адреси.',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($E['node'])), 560),
                'good'     => '{"@type": "' . $lbType . '", "@id": "' . $this->base . '/#branch-1", "parentOrganization": {"@id": "' . $this->orgId() . '"}, "address": {...}, "telephone": "...", "openingHoursSpecification": [...], "geo": {...}}',
                'code'     => self::ldCode($br),
                'verify'   => 'Google Rich Results Test для сторінки філії - тип ' . $lbType . ' з адресою без помилок; адреси й телефони мають збігатися з профілями філій у Google Business Profile.',
                'effort'   => 'M',
            ]);
        } else {
            $this->findings[] = $this->finding('local_not_lb', 'medium', 'Організацію розмічено як ' . $E['type'] . ', а не як локальний бізнес (LocalBusiness)' . ($miss ? '; бракує ' . self::listSome($miss, 4) : ''), [
                'evidence' => 'Знайдено ' . $E['type'] . ' «' . $E['name'] . '» (' . self::listSome($E['where'], 3) . '). Тип не належить до LocalBusiness, тому в ньому не передбачені години роботи, координати й ціновий діапазон. '
                    . ($miss ? 'Немає: ' . implode(', ', $miss) . '.' : '') . $this->factsLine(),
                'why'      => 'Для запитів «поруч», «де знаходиться», «коли працює» Google спирається насамперед на Business Profile, а LocalBusiness на сайті дає ті самі факти (адресу, телефон, графік, координати) у машиночитаному вигляді для пошуку й AI. ' . $E['type'] . ' без адреси не дає цих фактів.',
                'fix'      => 'Змініть тип на відповідний підтип LocalBusiness (готовий код нижче - ' . $lbType . ') і додайте адресу, телефон, години роботи та координати. Якщо філій кілька - окремий блок на сторінці кожної філії.',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($E['node'])), 560),
                'good'     => '{"@type": "' . $lbType . '", "name": "' . $E['name'] . '", "address": {"@type": "PostalAddress", ...}, "telephone": "...", "openingHoursSpecification": [...], "geo": {...}}',
                'code'     => self::ldCode($lbCode),
                'verify'   => 'Google Rich Results Test для головної / сторінки контактів - тип ' . $lbType . ' з адресою без помилок; https://validator.schema.org/ - без попереджень про address.',
                'effort'   => 'S',
            ]);
        }
        return min(100, $ev['s']);
    }

    // ---------------- articles

    private function checkArticles(bool $primary): ?int
    {
        $arts = $this->pagesByRole('article', true);
        if (!$arts) {
            if (!$primary) return null;
            $nf = $this->pagesByRole('article', false);
            $why = $nf ? 'сторінка статті ' . self::shortUrl((string)reset($nf)['url']) . ' не завантажилась (' . reset($nf)['note'] . ')' : 'у вибірці немає сторінки статті';
            $this->findings[] = $this->finding('article_unchecked', 'low', 'Розмітку Article на статтях не перевірено автоматично', [
                'evidence' => 'Причина: ' . $why . '. Висновок про наявність Article зробити не можна.',
                'why'      => 'Для медіа розмітка статті (заголовок, дати, автор, видавець) - основний машиночитаний сигнал свіжості та авторства.',
                'fix'      => 'Перевірте вручну 2-3 свіжі статті в Google Rich Results Test; якщо сайт блокує ботів - спершу відкрийте доступ (див. перевірку доступу).',
                'bad'      => 'Статтю не перевірено: ' . $why,
                'good'     => 'Кожна стаття містить NewsArticle / BlogPosting з headline, datePublished, dateModified, author (Person з url), publisher з logo, image.',
                'verify'   => 'Google Rich Results Test -> URL статті -> розділ «Статті».',
                'effort'   => 'S',
            ]);
            return null;
        }
        $pg = reset($arts);
        /** @var Page $p */
        $p = $pg['page'];
        $items = self::nodesOf($pg, ['Article']);
        usort($items, function ($a, $b) { return ($b['top'] ? 1 : 0) <=> ($a['top'] ? 1 : 0); });
        $h1 = $p->headings()['h1'][0] ?? '';
        $title = $h1 !== '' ? $h1 : (string)($p->og('title') ?? $p->title());
        if (!$items) {
            $sev = $primary ? 'high' : 'medium';
            $this->findings[] = $this->finding('article_missing', $sev, 'На сторінці статті немає розмітки Article / BlogPosting', [
                'evidence' => 'Стаття ' . $pg['url'] . ' («' . self::mbTrunc($title, 80) . '»): ' . $this->typesLine($pg) . '.',
                'why'      => 'Article передає машинам заголовок, дати публікації й оновлення, автора і видавця. Google використовує цю розмітку, щоб краще зрозуміти сторінку і точніше показати заголовок, зображення й дату; для AI-відповідей окремої розмітки Google не вимагає, але явні дати й автор полегшують машинам оцінку свіжості й авторства.',
                'fix'      => 'Додайте JSON-LD ' . ($primary ? 'NewsArticle' : 'BlogPosting') . ' у шаблон статті; автор - Person з посиланням на сторінку автора.',
                'bad'      => 'Розмітка статті: ' . $this->typesLine($pg),
                'good'     => '{"@type": "' . ($primary ? 'NewsArticle' : 'BlogPosting') . '", "headline": "...", "datePublished": "...", "dateModified": "...", "author": {"@type": "Person", "name": "...", "url": "..."}, "publisher": {...}}',
                'code'     => self::ldCode($this->articleCode($pg, [], $primary)),
                'verify'   => 'Google Rich Results Test для ' . $pg['url'] . ' - розділ «Статті» без помилок.',
                'effort'   => 'S',
            ]);
            return 0;
        }
        $n = $items[0];
        $d = $n['data'];
        $type = $n['types'][0];
        $headline = self::str($this->val($d, 'headline', $pg)) ?? self::str($this->val($d, 'name', $pg));
        $pub = self::str($this->val($d, 'datePublished', $pg));
        $mod = self::str($this->val($d, 'dateModified', $pg));
        $authorV = $this->val($d, 'author', $pg);
        $author = self::first($authorV);
        $authorIsRef = $author && self::isRef($author);
        $authorTypes = $author ? self::typesOf($author) : [];
        $authorName = $author ? self::str($author['name'] ?? null) : (is_string($authorV) ? $authorV : null);
        $authorPerson = $authorIsRef || ($author && self::isType(['types' => $authorTypes], ['Person']));
        $authorUrl = $author ? (self::urlOf($author['url'] ?? null) ?? self::urlOf($author['sameAs'] ?? null)) : null;
        $publisher = self::first($this->val($d, 'publisher', $pg));
        $pubIsRef = $publisher && self::isRef($publisher);
        $pubLogo = $publisher ? ($pubIsRef ? true : (self::urlOf($this->val($publisher, 'logo', $pg)) !== null)) : false;
        $image = self::urlOf($this->val($d, 'image', $pg));
        $dates = $this->articleDateIssues($pg, $pub, $mod);
        $this->badDates = $dates['bad'];

        $s = 40 + ($headline ? 10 : 0) + ($pub ? 12 : 0) + ($mod ? 8 : 0)
            + (($authorName || $authorIsRef) && $authorPerson ? 8 : (($authorName || $authorIsRef) ? 4 : 0)) + (($authorUrl || $authorIsRef) && $authorPerson ? 4 : 0)
            + ($publisher ? 4 : 0) + ($pubLogo ? 4 : 0) + ($image ? 10 : 0)
            - ($dates['generated'] ? 10 : ($dates['issues'] ? 5 : 0));

        $present = []; $miss = []; $major = false;
        if ($headline) $present[] = 'headline'; else { $miss[] = 'headline'; $major = true; }
        if ($pub) $present[] = 'datePublished'; else { $miss[] = 'datePublished'; $major = true; }
        if ($mod) $present[] = 'dateModified'; else $miss[] = 'dateModified';
        if (!$author && !$authorName) { $miss[] = 'author'; $major = true; }
        elseif (!$authorPerson) $miss[] = 'author як Person (зараз автор - ' . ($authorTypes[0] ?? 'текст') . ')';
        else {
            $present[] = 'author (Person' . ($authorName ? ' «' . self::mbTrunc($authorName, 40) . '»' : '') . ')';
            if (!$authorUrl && !$authorIsRef) $miss[] = 'author.url (сторінка автора)';
        }
        // publisher і publisher.logo Google для Article не вимагає і не рекомендує, тож у «бракує» не йдуть
        if ($publisher) $present[] = $pubLogo ? 'publisher з logo' : 'publisher';
        if ($image) $present[] = 'image'; else $miss[] = 'image';
        // порожні значення
        foreach (['datePublished', 'dateModified', 'headline'] as $k) {
            if (array_key_exists($k, $d) && is_string($d[$k]) && trim($d[$k]) === '') {
                foreach ($miss as $i => $m) if ($m === $k) $miss[$i] = $k . ' (поле є, але порожнє)';
            }
        }

        if ($miss) {
            $sev = $primary ? ($major ? 'medium' : 'low') : 'low';
            if ($primary && !$authorPerson && $author) $sev = 'medium';
            $this->findings[] = $this->finding('article_props', $sev, 'У розмітці ' . $type . ' бракує: ' . self::listSome(array_map(function ($m) { return preg_replace('/ \(.*$/', '', $m); }, $miss), 5), [
                'evidence' => 'Стаття ' . $pg['url'] . '. Є: ' . implode(', ', $present) . '. Немає: ' . implode(', ', $miss) . '.',
                'why'      => 'Дати й автор - сигнали свіжості та експертизи (E-E-A-T), які машинам простіше зчитати з розмітки. Google не має обов\'язкових полів для Article і рекомендує author, datePublished, dateModified, headline та image; publisher з logo допомагає пов\'язати статтю з видавцем.',
                'fix'      => 'Доповніть розмітку статті відсутніми полями. Автор - Person з name і url сторінки автора на сайті (біографія, досвід).',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($d)), 560),
                'good'     => '"author": {"@type": "Person", "name": "Ім\'я Прізвище", "url": "' . $this->base . '/author/..."}, "dateModified": "2026-10-01T10:00:00+03:00"',
                'code'     => self::ldCode($this->articleCode($pg, $d, $primary)),
                'verify'   => 'Google Rich Results Test для ' . $pg['url'] . ' - розділ «Статті» без попереджень; дати в розмітці збігаються з видимими на сторінці.',
                'effort'   => 'S',
            ]);
        }
        if ($dates['issues']) {
            $gen = $dates['generated'];
            $labels = [];
            foreach ($dates['issues'] as $is) $labels[] = $is['label'];
            $goodPub = (!isset($dates['bad']['datePublished']) && $pub) ? $pub : '2026-10-20T09:00:00+03:00';
            $this->findings[] = $this->finding('article_dates', $gen ? 'medium' : 'low', $gen
                ? 'Дата оновлення статті (dateModified) змінюється при кожному завантаженні сторінки'
                : 'Некоректні дати в розмітці ' . $type . ': ' . implode(', ', array_unique($labels)), [
                'evidence' => 'Стаття ' . $pg['url'] . ' (завантажено ' . $dates['fetched'] . '). ' . implode('. ', array_map(function ($x) { return $x['text']; }, $dates['issues'])) . '.',
                'why'      => 'Дати - основний машиночитаний сигнал свіжості. Google радить змінювати дату лише після суттєвих змін змісту: дата, що оновлюється при кожному запиті, - штучна свіжість, і довіра до дат сайту падає. Суперечливих дат (оновлення раніше за публікацію, публікація в майбутньому) Google прямо радить уникати: дату він визначає за кількома ознаками і може показати не ту, що в розмітці.',
                'fix'      => 'Беріть datePublished і dateModified з бази даних: час публікації і час останньої реальної зміни тексту, у форматі ISO 8601 з часовим поясом. dateModified не може бути раніше за datePublished, обидві дати - не в майбутньому.',
                'bad'      => '"datePublished": "' . ($pub ?? '') . '",' . "\n" . '"dateModified": "' . ($mod ?? '') . '"' . ($dates['second'] !== null ? "\n" . '(повторне завантаження: "dateModified": "' . $dates['second'] . '")' : ''),
                'good'     => '"datePublished": "' . $goodPub . '",' . "\n" . '"dateModified": "[час останньої реальної зміни тексту, не раніше за datePublished]"',
                'code'     => self::ldCode($this->articleCode($pg, $d, $primary)),
                'verify'   => 'Завантажте статтю двічі з інтервалом у хвилину (view-source: або curl -s URL | grep dateModified) - значення не має змінюватися; у Google Rich Results Test дати мають збігатися з видимими на сторінці.',
                'effort'   => 'S',
            ]);
        }
        if ($headline && $pub && !$dates['issues']) $this->passed[] = $type . ' на сторінці статті з headline і датою публікації' . ($mod ? ' та оновлення' : '');
        return min(100, max(0, $s));
    }

    /**
     * Перевірка дат статті відносно часу завантаження: дата в майбутньому, оновлення раніше за публікацію,
     * dateModified = момент запиту (підтверджується повторним завантаженням).
     * @return array{issues: array, generated: bool, bad: array, fetched: string, second: ?string}
     */
    private function articleDateIssues(array $pg, ?string $pub, ?string $mod): array
    {
        $out = ['issues' => [], 'generated' => false, 'bad' => [], 'fetched' => '', 'second' => null];
        /** @var Page $p */
        $p = $pg['page'];
        $h = $p->headers()['date'] ?? null;
        $hv = is_array($h) ? (string)end($h) : (string)$h;
        $fetchTs = ($hv !== '' && ($t = strtotime($hv)) !== false) ? $t : time();
        $out['fetched'] = self::fmtTime($fetchTs);
        $P = self::parseDate($pub);
        $M = self::parseDate($mod);
        if ($P && $P[0] > $fetchTs + ($P[1] ? 26 * 3600 : 3600)) {
            $out['issues'][] = ['label' => 'datePublished у майбутньому', 'text' => 'datePublished «' . $pub . '» - пізніше за час завантаження сторінки на ' . self::fmtDiff($P[0] - $fetchTs)];
            $out['bad']['datePublished'] = true;
        }
        if ($M && $M[0] > $fetchTs + ($M[1] ? 26 * 3600 : 3600)) {
            $out['issues'][] = ['label' => 'dateModified у майбутньому', 'text' => 'dateModified «' . $mod . '» - пізніше за час завантаження сторінки на ' . self::fmtDiff($M[0] - $fetchTs)];
            $out['bad']['dateModified'] = true;
        }
        if ($P && $M && ($M[1] || $P[1] ? $M[0] < $P[0] - 86399 : $M[0] < $P[0] - 120)) {
            $out['issues'][] = ['label' => 'dateModified раніше за datePublished', 'text' => 'dateModified «' . $mod . '» раніше за datePublished «' . $pub . '» на ' . self::fmtDiff($P[0] - $M[0])];
            $out['bad']['dateModified'] = true;
        }
        // dateModified майже збігається з моментом запиту, а стаття не нова - перевіряємо повторним завантаженням
        if ($M && !$M[1] && abs($fetchTs - $M[0]) <= 300 && !($P && $fetchTs - $P[0] < 3600)) {
            $asBrowser = (($this->ctx['access']['fetched_as'] ?? 'bot') === 'browser');
            $wait = 3 - (time() - $fetchTs);
            if ($wait > 0) usleep(min(3, $wait) * 1000000); // щоб час на сервері встиг змінитися (мікрокеш)
            $r = $asBrowser ? Crawler::fetchBrowser((string)$pg['url'], 12) : Crawler::fetch((string)$pg['url'], 12);
            $p2 = ((int)$r['status'] >= 200 && (int)$r['status'] < 400) ? Page::fromCrawl($r) : null;
            if ($p2 && $p2->challenge() === null) {
                $pg2 = $this->mkPage('article', (string)$pg['url'], $p2, '');
                $mod2 = null;
                foreach (self::nodesOf($pg2, ['Article']) as $n2) {
                    $v = self::str($n2['data']['dateModified'] ?? null);
                    if ($v !== null) { $mod2 = $v; break; }
                }
                $this->data['article_refetch'] = ['dateModified_1' => $mod, 'dateModified_2' => $mod2];
                if ($mod2 !== null && $mod2 !== $mod) {
                    $out['generated'] = true;
                    $out['second'] = $mod2;
                    $out['bad']['dateModified'] = true;
                    array_unshift($out['issues'], ['label' => 'dateModified = час запиту', 'text' => 'dateModified «' . $mod . '» відрізняється від часу завантаження лише на '
                        . self::fmtDiff(abs($fetchTs - $M[0])) . ', а повторне завантаження дало «' . $mod2 . '» - дата підставляється в момент запиту (штучна свіжість), а не береться з часу реальної зміни статті']);
                }
            }
        }
        return $out;
    }

    /** [timestamp, dateOnly] або null. Дата без часового поясу - за Києвом. */
    private static function parseDate(?string $s): ?array
    {
        if ($s === null || trim($s) === '') return null;
        $s = trim($s);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return null;
        try {
            $dt = new DateTimeImmutable($s, new DateTimeZone('Europe/Kyiv'));
        } catch (\Throwable $e) {
            return null;
        }
        return [$dt->getTimestamp(), (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)];
    }

    private static function fmtTime(int $ts): string
    {
        try {
            return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('Y-m-d H:i:s P');
        } catch (\Throwable $e) {
            return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
        }
    }

    private static function fmtDiff(int $sec): string
    {
        $sec = abs($sec);
        if ($sec < 120) return $sec . ' с';
        if ($sec < 7200) return (int)round($sec / 60) . ' хв';
        if ($sec < 172800) return (int)round($sec / 3600) . ' год';
        return (int)round($sec / 86400) . ' дн.';
    }

    private function articleCode(array $pg, array $d, bool $news): array
    {
        /** @var Page $p */
        $p = $pg['page'];
        $meta = $p->metaTags();
        $h1 = $p->headings()['h1'][0] ?? '';
        $o = ['@context' => 'https://schema.org', '@type' => self::typesOf($d)[0] ?? ($news ? 'NewsArticle' : 'BlogPosting')];
        $o['mainEntityOfPage'] = ['@type' => 'WebPage', '@id' => $pg['url']];
        $o['headline'] = self::str($d['headline'] ?? null) ?? ($h1 !== '' ? self::mbTrunc($h1, 110) : self::mbTrunc((string)($p->og('title') ?? $p->title()), 110));
        $desc = self::str($d['description'] ?? null) ?? ($p->metaDescription() !== '' ? $p->metaDescription() : null);
        if ($desc) $o['description'] = self::mbTrunc(trim($desc), 250);
        $img = $d['image'] ?? ($p->og('image') ? Crawler::absolutize((string)$p->og('image'), $p->url()) : null);
        $o['image'] = $img ?: '[ВКАЖІТЬ URL головного зображення статті]';
        $timeEl = $p->query('//time[@datetime]');
        $timeVal = $timeEl ? trim((string)$timeEl[0]->getAttribute('datetime')) : '';
        $o['datePublished'] = self::str($d['datePublished'] ?? null) ?? ($meta['article:published_time'] ?? ($timeVal !== '' ? $timeVal : '[ВКАЖІТЬ дату публікації, ISO 8601]'));
        $o['dateModified'] = self::str($d['dateModified'] ?? null) ?? ($meta['article:modified_time'] ?? '[ВКАЖІТЬ дату останнього оновлення, ISO 8601]');
        // некоректні дати (майбутнє, оновлення раніше публікації, час запиту) у готовий код не переносимо
        if (isset($this->badDates['datePublished'])) $o['datePublished'] = '[ВКАЖІТЬ фактичну дату публікації з бази, ISO 8601]';
        if (isset($this->badDates['dateModified'])) $o['dateModified'] = '[ВКАЖІТЬ час останньої реальної зміни тексту з бази, ISO 8601]';
        if (isset($o['description']) && $this->isRussianText($o['description'])) {
            $md = $p->metaDescription();
            $o['description'] = ($md !== '' && !$this->isRussianText($md)) ? self::mbTrunc($md, 250) : '[ВКАЖІТЬ короткий опис статті українською]';
        }
        $author = self::first($d['author'] ?? null);
        $aName = $author ? self::str($author['name'] ?? null) : null;
        $isPerson = $author && self::isType(['types' => self::typesOf($author)], ['Person']);
        $o['author'] = ['@type' => 'Person', 'name' => ($isPerson && $aName) ? $aName : '[ВКАЖІТЬ ім\'я автора]',
            'url' => ($isPerson && $author && self::urlOf($author['url'] ?? null)) ? self::urlOf($author['url']) : '[ВКАЖІТЬ URL сторінки автора на сайті]'];
        if ($this->stype === 'personal') {
            // особистий сайт: автор (якщо не вказаний інший) і видавець - сама особа
            if (!($isPerson && $aName)) $o['author'] = ['@type' => 'Person', '@id' => $this->personId(), 'name' => ($this->P['name'] ?? null) ?: $this->brand, 'url' => $this->homeUrl];
            $o['publisher'] = ['@id' => $this->personId()];
            return $o;
        }
        $logo = $this->facts['logo'] ?? '[ВКАЖІТЬ URL логотипа]';
        $o['publisher'] = ['@type' => 'Organization', '@id' => $this->orgId(), 'name' => ($this->E['name'] ?? null) ?: $this->brand,
            'logo' => ['@type' => 'ImageObject', 'url' => $logo]];
        return $o;
    }

    // ---------------- saas

    private function checkSaas(): ?int
    {
        $best = null; $bestPg = null; $bestN = -1;
        foreach ($this->fetchedPages() as $pg) {
            foreach (self::nodesOf($pg, ['SoftwareApplication']) as $n) {
                if (count($n['data']) > $bestN) { $best = $n; $bestPg = $pg; $bestN = count($n['data']); }
            }
        }
        $home = $this->pages[0];
        if (!$best) {
            $checked = array_map(function ($pg) { return $this->where($pg); }, $this->fetchedPages());
            $appLinks = [];
            foreach ($this->fetchedPages() as $pg) foreach ($pg['page']->externalLinks() as $l) {
                if (preg_match('#(apps\.apple\.com|play\.google\.com/store/apps)#i', $l['href'])) $appLinks[$l['href']] = true;
            }
            $isMobile = (bool)$appLinks;
            $o = ['@context' => 'https://schema.org', '@type' => $isMobile ? 'SoftwareApplication' : 'WebApplication',
                'name' => $this->brand, 'url' => $this->homeUrl];
            if (!empty($this->facts['description'])) $o['description'] = self::mbTrunc((string)$this->facts['description'], 250);
            $o['applicationCategory'] = '[ВКАЖІТЬ категорію, напр. BusinessApplication / DesignApplication / FinanceApplication]';
            $o['operatingSystem'] = $isMobile ? 'Android, iOS' : 'Web';
            $o['offers'] = ['@type' => 'Offer', 'price' => '[ВКАЖІТЬ ціну, 0 для безкоштовного тарифу]', 'priceCurrency' => '[ВКАЖІТЬ валюту, напр. UAH або USD]'];
            $o['publisher'] = ['@id' => $this->orgId()];
            if ($isMobile) $o['sameAs'] = array_slice(array_keys($appLinks), 0, 2);
            $this->findings[] = $this->finding('app_missing', 'medium', 'Продукт не описано розміткою SoftwareApplication / WebApplication', [
                'evidence' => 'Перевірено: ' . implode(', ', $checked) . '. На головній: ' . ($home['page'] instanceof Page ? $this->typesLine($home) : $home['note']) . '.'
                    . ($appLinks ? ' Є посилання на застосунки: ' . self::listSome(array_keys($appLinks), 2) . '.' : ''),
                'why'      => 'SoftwareApplication повідомляє машинам, що це за продукт: категорію, платформу, ціну і рейтинг. Ці факти AI-асистенти використовують у відповідях на запити «найкращий сервіс для ...» і порівняннях.',
                'fix'      => 'Додайте на головну (або сторінку продукту / тарифів) JSON-LD ' . $o['@type'] . ' з applicationCategory, operatingSystem і offers; aggregateRating - лише за реальними відгуками.',
                'bad'      => 'Розмітка на головній: ' . ($home['page'] instanceof Page ? $this->typesLine($home) : 'немає даних'),
                'good'     => '{"@type": "' . $o['@type'] . '", "name": "' . $this->brand . '", "applicationCategory": "...", "operatingSystem": "' . $o['operatingSystem'] . '", "offers": {"@type": "Offer", "price": "0", "priceCurrency": "USD"}}',
                'code'     => self::ldCode($o),
                'verify'   => 'Google Rich Results Test для головної - розділ «Програмне забезпечення» (Software App) без помилок.',
                'effort'   => 'S',
            ]);
            return 0;
        }
        $d = $best['data'];
        $pg = $bestPg;
        $offers = self::first($this->val($d, 'offers', $pg));
        $cat = self::str($this->val($d, 'applicationCategory', $pg));
        $os = self::str($this->val($d, 'operatingSystem', $pg));
        $rating = $this->val($d, 'aggregateRating', $pg) ?? $this->val($d, 'review', $pg);
        $s = 50 + ($offers ? 15 : 0) + ($cat ? 10 : 0) + ($os ? 10 : 0) + ($rating !== null ? 15 : 0);
        $present = ['name']; $miss = [];
        if ($offers) $present[] = 'offers'; else $miss[] = 'offers';
        if ($cat) $present[] = 'applicationCategory ' . $cat; else $miss[] = 'applicationCategory';
        if ($os) $present[] = 'operatingSystem ' . $os; else $miss[] = 'operatingSystem';
        if ($rating !== null) $present[] = 'aggregateRating'; else $miss[] = 'aggregateRating';
        $type = $best['types'][0];
        if ($miss) {
            // Google вимагає і offers.price, і рейтинг чи відгук: бракує хоч одного - розширеного результату не буде
            $sev = (!$offers || $rating === null) ? 'medium' : 'low';
            $code = self::codeNode($d);
            if (!$offers) $code['offers'] = ['@type' => 'Offer', 'price' => '[ВКАЖІТЬ ціну, 0 для безкоштовного тарифу]', 'priceCurrency' => 'UAH'];
            if (!$cat) $code['applicationCategory'] = '[ВКАЖІТЬ категорію, напр. BusinessApplication]';
            if (!$os) $code['operatingSystem'] = '[ВКАЖІТЬ платформи, напр. Web, Android, iOS]';
            if ($rating === null) $code['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => '[ВКАЖІТЬ середню оцінку]', 'ratingCount' => '[ВКАЖІТЬ кількість оцінок]'];
            $this->findings[] = $this->finding('app_props', $sev, 'У розмітці ' . $type . ' бракує: ' . implode(', ', $miss), [
                'evidence' => $type . ' «' . (self::str($d['name'] ?? null) ?? '') . '» (' . $this->where($pg) . '). Є: ' . implode(', ', $present) . '. Немає: ' . implode(', ', $miss) . '.',
                'why'      => 'Для Google Software App обов\'язкові offers.price (0 для безкоштовного) і рейтинг чи відгук (aggregateRating або review); категорія і платформа (рекомендовані поля) допомагають AI коректно віднести продукт до класу рішень. Рейтинг додавайте лише з реальних оцінок (наприклад, з App Store / Google Play).',
                'fix'      => 'Доповніть наявний блок відсутніми полями.',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($d)), 560),
                'good'     => '"aggregateRating": {"@type": "AggregateRating", "ratingValue": "4.8", "ratingCount": "1520"}',
                'code'     => self::ldCode($code),
                'verify'   => 'Google Rich Results Test - розділ «Програмне забезпечення» без помилок.',
                'effort'   => 'S',
            ]);
        }
        if ($offers || $rating !== null) $this->passed[] = $type . ' (' . $this->where($pg) . '): ' . implode(', ', $present);
        return min(100, $s);
    }

    // ---------------- services

    private function checkServices(): ?int
    {
        $svc = $this->pagesByRole('service', true);
        if (!$svc) {
            $nf = $this->pagesByRole('service', false);
            $why = $nf ? 'сторінка послуги ' . self::shortUrl((string)reset($nf)['url']) . ' не завантажилась (' . reset($nf)['note'] . ')' : 'у вибірці немає сторінки окремої послуги';
            $this->data['service'] = $why;
            $this->findings[] = $this->finding('service_unchecked', 'low', 'Розмітку Service на сторінках послуг не перевірено автоматично', [
                'evidence' => 'Причина: ' . $why . '.',
                'why'      => 'Service описує, що саме ви надаєте, кому і де. Без перевірки сторінки послуги висновок зробити не можна.',
                'fix'      => 'Перевірте вручну 2-3 сторінки ключових послуг у https://validator.schema.org/.',
                'bad'      => 'Сторінку послуги не перевірено: ' . $why,
                'good'     => 'Сторінка кожної послуги містить Service з name, serviceType, provider і areaServed.',
                'verify'   => 'https://validator.schema.org/ для URL сторінки послуги - тип Service.',
                'effort'   => 'S',
            ]);
            return null;
        }
        $pg = reset($svc);
        /** @var Page $p */
        $p = $pg['page'];
        $items = self::nodesOf($pg, ['Service']);
        [$name, $stype] = $this->serviceName($pg);
        if (!$items) {
            $other = array_values(array_filter($this->typesOnPage($pg), function ($t) { return $t !== 'ListItem'; }));
            $o = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => $name, 'serviceType' => $stype, 'url' => $pg['url']];
            if ($p->metaDescription() !== '') $o['description'] = self::mbTrunc($p->metaDescription(), 250);
            $o['provider'] = ['@type' => 'Organization', '@id' => $this->orgId(), 'name' => ($this->E['name'] ?? null) ?: $this->brand, 'url' => $this->homeUrl];
            $o['areaServed'] = $this->isUa() ? ['@type' => 'Country', 'name' => 'Україна'] : '[ВКАЖІТЬ країну / регіон обслуговування]';
            $this->findings[] = $this->finding('service_missing', 'medium', 'Сторінка послуги без розмітки Service', [
                'evidence' => 'Сторінка ' . $pg['url'] . ' («' . self::mbTrunc($name, 80) . '»): ' . $this->typesLine($pg) . '.'
                    . (in_array('Product', $other, true) ? ' Послугу розмічено як Product - для послуг коректніший тип Service.' : ''),
                'why'      => 'Service прямо каже машинам, яку послугу ви надаєте, хто постачальник і для якого регіону. Це допомагає AI зіставити сайт із запитами на кшталт «агенція для ... в Україні».',
                'fix'      => 'Додайте JSON-LD Service у шаблон сторінки послуги з serviceType, provider (посилання на вашу Organization) і areaServed.',
                'bad'      => 'Розмітка сторінки послуги: ' . $this->typesLine($pg),
                'good'     => '{"@type": "Service", "name": "...", "serviceType": "...", "provider": {"@id": "' . $this->orgId() . '"}, "areaServed": "UA"}',
                'code'     => self::ldCode($o),
                'verify'   => 'https://validator.schema.org/ для ' . $pg['url'] . ' - тип Service без помилок.',
                'effort'   => 'S',
            ]);
            return 0;
        }
        $d = $items[0]['data'];
        $st = self::str($this->val($d, 'serviceType', $pg));
        $prov = $this->val($d, 'provider', $pg);
        $area = $this->val($d, 'areaServed', $pg);
        $s = 55 + ($st ? 15 : 0) + ($prov !== null ? 15 : 0) + ($area !== null ? 15 : 0);
        $miss = [];
        if (!$st) $miss[] = 'serviceType';
        if ($prov === null) $miss[] = 'provider';
        if ($area === null) $miss[] = 'areaServed';
        if ($miss) {
            $code = self::codeNode($d);
            if (!$st) $code['serviceType'] = $stype;
            if ($prov === null) $code['provider'] = ['@id' => $this->orgId()];
            if ($area === null) $code['areaServed'] = $this->isUa() ? ['@type' => 'Country', 'name' => 'Україна'] : '[ВКАЖІТЬ країну / регіон обслуговування]';
            $this->findings[] = $this->finding('service_props', 'low', 'У розмітці Service бракує: ' . implode(', ', $miss), [
                'evidence' => 'Сторінка ' . $pg['url'] . ': Service є, але без ' . implode(', ', $miss) . '.',
                'why'      => 'provider пов\'язує послугу з вашою компанією, areaServed - з регіоном. Без них послуга «нічия» і без географії.',
                'fix'      => 'Доповніть блок Service відсутніми полями.',
                'bad'      => self::mbTrunc(self::jsonPretty(self::trimNode($d)), 500),
                'good'     => '"provider": {"@id": "' . $this->orgId() . '"}, "areaServed": {"@type": "Country", "name": "Україна"}',
                'code'     => self::ldCode($code),
                'verify'   => 'https://validator.schema.org/ для ' . $pg['url'] . '.',
                'effort'   => 'S',
            ]);
        } else {
            $this->passed[] = 'Service на сторінці послуги з serviceType, provider і areaServed';
        }
        return min(100, $s);
    }

    // ================================================================= 6. FAQ

    private function checkFaq(): void
    {
        $flagged = []; $withFaq = [];
        foreach ($this->fetchedPages() as $pg) {
            $hasFaq = (bool)self::nodesOf($pg, ['FAQPage', 'QAPage', 'Question']);
            $q = $this->questionsOn($pg['page']);
            if ($hasFaq) {
                $n = 0;
                foreach (self::nodesOf($pg, ['FAQPage']) as $f) {
                    $me = $f['data']['mainEntity'] ?? [];
                    $n = max($n, is_array($me) ? (self::isList($me) ? count($me) : 1) : 0);
                }
                $withFaq[] = $this->where($pg) . ($n ? ' (' . $n . ' ' . self::plural($n, 'питання', 'питання', 'питань') . ')' : '');
                continue;
            }
            $strong = $q['strong']; $weak = $q['weak'];
            $ok = count($strong) >= 3 || (count($strong) >= 2 && $q['container']) || ($q['container'] && count($strong) >= 1 && count($strong) + count($weak) >= 3);
            // у статті підзаголовки-питання - звичайна структура тексту, а не FAQ: потрібен явний FAQ-блок
            if ($ok && $pg['role'] === 'article' && !$q['container']) $ok = false;
            if ($ok) $flagged[] = ['pg' => $pg, 'q' => $q];
        }
        if ($withFaq) $this->passed[] = 'FAQPage: ' . self::listSome($withFaq, 3);
        if (!$flagged) return;
        $f0 = $flagged[0];
        $qs = array_merge($f0['q']['strong'], $f0['q']['container'] ? $f0['q']['weak'] : []);
        $ev = [];
        foreach ($flagged as $x) {
            $all = array_merge($x['q']['strong'], $x['q']['container'] ? $x['q']['weak'] : []);
            $ev[] = self::ucf($this->where($x['pg'])) . ': ' . count($all) . ' ' . self::plural(count($all), 'питання', 'питання', 'питань') . ' (напр. «' . self::mbTrunc($all[0]['q'], 70) . '»)';
        }
        $main = [];
        foreach (array_slice($qs, 0, 5) as $q) {
            $main[] = ['@type' => 'Question', 'name' => $q['q'], 'acceptedAnswer' => ['@type' => 'Answer',
                'text' => $q['a'] !== '' ? $q['a'] : '[ВКАЖІТЬ відповідь - той самий текст, що на сторінці]']];
        }
        $code = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $main];
        $badLines = array_map(function ($q) { return '<' . $q['tag'] . '>' . self::mbTrunc($q['q'], 90) . '</' . $q['tag'] . '>'; }, array_slice($qs, 0, 3));
        $this->findings[] = $this->finding('faq_missing', 'low', 'Є блок питань і відповідей, але без розмітки FAQPage', [
            'evidence' => implode('; ', $ev) . '. Розмітки FAQPage на цих сторінках немає.',
            'why'      => 'Розширених результатів FAQ у Google більше немає: з 2023 року їх показували лише авторитетним державним і медичним сайтам, а з 7 травня 2026 року прибрали зовсім. Але FAQPage явно позначає пари «питання - відповідь», і машинам простіше їх розібрати й процитувати; головне - сам текст відповідей.',
            'fix'      => 'Розмітьте наявний FAQ-блок JSON-LD FAQPage; текст у розмітці має дослівно збігатися з видимим на сторінці.',
            'bad'      => implode("\n", $badLines) . "\n(FAQPage немає)",
            'good'     => '{"@type": "FAQPage", "mainEntity": [{"@type": "Question", "name": "' . self::mbTrunc($qs[0]['q'], 80) . '", "acceptedAnswer": {"@type": "Answer", "text": "..."}}]}',
            'code'     => self::ldCode($code),
            'verify'   => 'https://validator.schema.org/ для ' . $f0['pg']['url'] . ' - FAQPage з усіма питаннями без помилок (Rich Results Test FAQ більше не перевіряє, бо Google прибрав цей розширений результат - це нормально).',
            'effort'   => 'S',
        ]);
    }

    /** Питання-заголовки сторінки: strong - закінчуються «?», weak - починаються питальним словом (лише у FAQ-блоці). */
    private function questionsOn(Page $p): array
    {
        $lc = function (string $attr) { return 'translate(' . $attr . ",'" . self::UP . "','" . self::LO . "')"; };
        $contQ = '//*[contains(' . $lc('@class') . ",'faq') or contains(" . $lc('@id') . ",'faq') or contains(" . $lc('@class') . ",'accordion') or contains("
            . $lc('@class') . ",'question') or contains(" . $lc('@class') . ",'pytann') or contains(" . $lc('@class') . ",'zapytann')]";
        $containers = $p->query($contQ);
        $container = (bool)$containers;
        if (!$container) {
            foreach ($p->headings() as $lvl => $list) {
                foreach ($list as $h) {
                    if (preg_match('/(часті|поширені|популярні|актуальні)\s+(питання|запитання)|faq|питання\s+(та|і|й)\s+відповіді|запитання\s+(та|і|й)\s+відповіді|frequently asked/iu', $h)) { $container = true; break 2; }
                }
            }
        }
        $cands = $p->query('//body//*[self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::summary or self::dt]');
        foreach ($containers as $c) {
            foreach ($p->query('.//*', $c) as $el) $cands[] = $el;
        }
        $strong = []; $weak = []; $seen = [];
        foreach ($cands as $el) {
            /** @var DOMElement $el */
            if (!($el instanceof DOMElement)) continue;
            $tag = strtolower($el->nodeName);
            if (in_array($tag, ['script', 'style', 'svg', 'a', 'nav', 'footer', 'header'], true)) continue;
            if ($p->query('ancestor::a | ancestor::nav | ancestor::footer', $el)) continue;
            $t = self::nodeText($el);
            $len = mb_strlen($t);
            if ($len < 8 || $len > 200) continue;
            $k = mb_strtolower($t);
            if (isset($seen[$k])) continue;
            $isStrong = mb_substr($t, -1) === '?';
            $isWeak = !$isStrong && $len <= 120 && strpos($t, '?') === false
                && (bool)preg_match('/^(як|що|чому|скільки|чи|коли|де|навіщо|хто|який|яка|яке|які|how|what|why|when|where|who|which)(?!\p{L})/iu', $t);
            if (!$isStrong && !$isWeak) continue;
            // лише «листові» елементи: якщо всередині є дочірній з тим самим питанням - беремо дочірній
            $childSame = false;
            foreach ($el->childNodes as $ch) {
                if ($ch instanceof DOMElement && self::nodeText($ch) === $t) { $childSame = true; break; }
            }
            if ($childSame) continue;
            $inContainer = false;
            foreach ($containers as $c) {
                if ($c === $el || self::isAncestor($c, $el)) { $inContainer = true; break; }
            }
            if ($isWeak && !$inContainer) continue;
            if (!in_array($tag, ['h2', 'h3', 'h4', 'h5', 'h6', 'summary', 'dt'], true) && !$inContainer) continue;
            $seen[$k] = true;
            $item = ['q' => $t, 'a' => self::answerFor($el, $t), 'tag' => $tag];
            if ($isStrong) $strong[] = $item; else $weak[] = $item;
            if (count($strong) + count($weak) >= 30) break;
        }
        return ['strong' => $strong, 'weak' => $weak, 'container' => $container];
    }

    private static function isAncestor(DOMNode $anc, DOMNode $n): bool
    {
        for ($x = $n->parentNode; $x !== null; $x = $x->parentNode) if ($x === $anc) return true;
        return false;
    }

    /**
     * Повна відповідь на питання (для коду FAQPage текст має дослівно збігатися з видимим).
     * '' - якщо відповідь не вдалося виділити цілком або вона довша за 500 символів (тоді в коді заглушка).
     */
    private static function answerFor(DOMElement $el, string $q): string
    {
        $tag = strtolower($el->nodeName);
        if ($tag === 'summary' && $el->parentNode instanceof DOMElement) {
            $all = self::nodeText($el->parentNode);
            $a = trim((string)mb_substr($all, mb_strlen($q)));
            return (mb_strlen($a) >= 20 && mb_strlen($a) <= 500) ? $a : '';
        }
        $node = $el;
        for ($level = 0; $level <= 2 && $node instanceof DOMElement; $level++) {
            $buf = [];
            $steps = 0;
            $complete = true;
            for ($s = $node->nextSibling; $s !== null; $s = $s->nextSibling) {
                if ($steps >= 6 || mb_strlen(implode(' ', $buf)) > 500) { $complete = false; break; }
                if ($s instanceof DOMElement) {
                    $st = strtolower($s->nodeName);
                    if (preg_match('/^h[1-6]$/', $st) || $st === 'summary') break;
                    $t = self::nodeText($s);
                    if ($t !== '' && mb_substr($t, -1) === '?' && mb_strlen($t) < 200) break;
                    if ($t !== '') { $buf[] = $t; $steps++; }
                } elseif ($s instanceof DOMText) {
                    $t = trim((string)$s->nodeValue);
                    if ($t !== '') $buf[] = $t;
                }
            }
            $a = Page::norm(implode(' ', $buf));
            if (mb_strlen($a) >= 20) return ($complete && mb_strlen($a) <= 500) ? $a : '';
            $node = $node->parentNode;
        }
        return '';
    }

    private static function nodeText(DOMNode $n): string
    {
        $parts = [];
        self::textInto($n, $parts);
        return Page::norm(implode(' ', $parts));
    }

    private static function textInto(DOMNode $n, array &$parts): void
    {
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) { $parts[] = (string)$c->nodeValue; continue; }
            if (!($c instanceof DOMElement)) continue;
            $t = strtolower($c->nodeName);
            if (in_array($t, ['script', 'style', 'noscript', 'template', 'svg'], true)) continue;
            self::textInto($c, $parts);
        }
    }

    // ================================================================= 7. microdata only

    private function checkMicrodataOnly(): void
    {
        $ld = 0; $micro = []; $example = null; $exPg = null;
        foreach ($this->fetchedPages() as $pg) {
            $ld += count($pg['blocks']);
            foreach ($pg['nodes'] as $n) {
                if ($n['source'] === 'json-ld') continue;
                foreach ($n['types'] as $t) $micro[$t] = $n['source'];
                if ($n['top'] && ($example === null || self::isType($n, ['Organization', 'Product', 'Article']))) {
                    if ($example === null || !self::isType(['types' => $example['types']], ['Organization', 'Product', 'Article'])) { $example = $n; $exPg = $pg; }
                }
            }
        }
        if ($micro) {
            $srcs = array_unique(array_values($micro));
            $this->passed[] = 'Розмітка ' . implode(' / ', array_map(function ($s) { return $s === 'rdfa' ? 'RDFa' : 'microdata'; }, $srcs)) . ': ' . self::listSome(array_keys($micro), 6);
        }
        if ($ld > 0 || !$micro || !$example) return;
        $code = self::ldCode(['@context' => 'https://schema.org'] + $example['data'], $cnote);
        $this->findings[] = $this->finding('microdata_only', 'low', 'Розмітка лише у форматі microdata / RDFa, JSON-LD немає', [
            'evidence' => 'На перевірених сторінках немає жодного JSON-LD блоку; типи в microdata/RDFa: ' . self::listSome(array_keys($micro), 8) . '.',
            'why'      => 'Microdata працює, але її легко зламати при зміні верстки, а вкладені об\'єкти часто губляться. Google рекомендує JSON-LD: окремий блок, який простіше підтримувати й перевіряти.',
            'fix'      => 'Робочу microdata можна залишити; нові типи (Organization, Product, Article) додавайте JSON-LD. Дані в обох форматах мають збігатися.',
            'bad'      => 'Microdata на ' . $this->where($exPg) . ': ' . implode(', ', $example['types']) . ' (itemscope / itemprop у HTML)',
            'good'     => 'Той самий об\'єкт окремим блоком <script type="application/ld+json">.' . ($cnote !== '' ? ' ' . $cnote : ''),
            'code'     => $code,
            'verify'   => 'https://validator.schema.org/ - тип має відображатися з усіма властивостями в обох форматах без конфліктів.',
            'effort'   => 'M',
        ]);
    }

    // ================================================================= 8. sanity на всіх сторінках вибірки

    /**
     * Легка перевірка решти сторінок вибірки незалежно від типу сайту: Article з порожніми обов'язковими полями
     * і Product замість Service на сторінці послуги.
     */
    private function checkInnerSanity(): void
    {
        $arts = $this->pagesByRole('article', true);
        $analyzed = $arts ? self::normUrl((string)reset($arts)['url']) : '';
        $firstSvc = $this->pagesByRole('service', true);
        $firstSvcUrl = $firstSvc ? self::normUrl((string)reset($firstSvc)['url']) : '';
        $empty = []; $prodSvc = [];
        foreach ($this->fetchedPages() as $pg) {
            $u = self::normUrl((string)$pg['url']);
            if (!($pg['role'] === 'article' && $u === $analyzed)) {
                foreach (self::nodesOf($pg, ['Article']) as $n) {
                    $f = [];
                    foreach (['headline', 'datePublished', 'dateModified', 'author'] as $k) {
                        if (array_key_exists($k, $n['data']) && ((is_string($n['data'][$k]) && trim($n['data'][$k]) === '') || $n['data'][$k] === [] || $n['data'][$k] === null)) $f[] = $k;
                    }
                    if ($f) { $empty[] = ['pg' => $pg, 'node' => $n, 'fields' => $f]; break; }
                }
            }
            if ($pg['role'] === 'service' && !self::nodesOf($pg, ['Service']) && !($this->stype === 'services' && $u === $firstSvcUrl)) {
                $pr = self::nodesOf($pg, ['Product']);
                if ($pr) $prodSvc[] = ['pg' => $pg, 'node' => $pr[0]];
            }
        }
        if (!$empty && !$prodSvc) return;
        $ev = []; $bad = []; $labels = [];
        foreach (array_slice($empty, 0, 2) as $x) {
            $nm = self::str($x['node']['data']['headline'] ?? null) ?? self::str($x['node']['data']['name'] ?? null);
            $ev[] = self::ucf($this->where($x['pg'])) . ': ' . $x['node']['types'][0] . ($nm !== null ? ' «' . self::mbTrunc($nm, 50) . '»' : '') . ' з порожніми полями ' . implode(', ', $x['fields']);
            $bad[] = implode(",\n", array_map(function ($k) use ($x) { return '"' . $k . '": ' . json_encode($x['node']['data'][$k], JSON_UNESCAPED_UNICODE); }, $x['fields']));
        }
        if ($empty) $labels[] = 'порожні поля в ' . $empty[0]['node']['types'][0];
        foreach (array_slice($prodSvc, 0, 1) as $x) {
            $d = $this->unstub($x['node']['data'], $x['pg']);
            $of = self::first($d['offers'] ?? null);
            $ofTxt = $of ? (self::typesOf($of)[0] ?? 'Offer') . ' ' . (self::str($of['lowPrice'] ?? null) ?? self::str($of['price'] ?? null) ?? '') . ' ' . (self::str($of['priceCurrency'] ?? null) ?? '') : '';
            $ar = self::first($d['aggregateRating'] ?? null);
            $arTxt = $ar ? 'aggregateRating ' . (self::str($ar['ratingValue'] ?? null) ?? '?') . ' (' . (self::str($ar['reviewCount'] ?? null) ?? self::str($ar['ratingCount'] ?? null) ?? '?') . ' оцінок)' : '';
            $ev[] = self::ucf($this->where($x['pg'])) . ': послугу «' . self::mbTrunc((string)(self::str($d['name'] ?? null) ?? ''), 50) . '» розмічено як Product'
                . (trim($ofTxt . $arTxt) !== '' ? ' (' . implode(', ', array_filter([trim($ofTxt), $arTxt])) . ')' : '') . ', типу Service немає';
            $bad[] = self::mbTrunc(self::jsonPretty(self::trimNode($d)), 300);
            $labels[] = 'послугу розмічено як Product';
        }
        $code = '';
        if ($prodSvc) {
            $pg = $prodSvc[0]['pg'];
            $d = $this->unstub($prodSvc[0]['node']['data'], $pg);
            [$name, $st] = $this->serviceName($pg);
            $o = ['@context' => 'https://schema.org', '@type' => 'Service', 'name' => self::str($d['name'] ?? null) ?? $name, 'serviceType' => $st, 'url' => $pg['url']];
            if ($pg['page']->metaDescription() !== '' && !$this->isRussianText($pg['page']->metaDescription())) $o['description'] = self::mbTrunc($pg['page']->metaDescription(), 250);
            $o['provider'] = $this->stype === 'personal'
                ? ['@type' => 'Person', '@id' => $this->personId(), 'name' => ($this->P['name'] ?? null) ?: $this->brand, 'url' => $this->homeUrl]
                : ['@type' => 'Organization', '@id' => $this->orgId(), 'name' => ($this->E['name'] ?? null) ?: $this->brand, 'url' => $this->homeUrl];
            $o['areaServed'] = $this->isUa() ? ['@type' => 'Country', 'name' => 'Україна'] : '[ВКАЖІТЬ країну / регіон обслуговування]';
            if (isset($d['offers'])) $o['offers'] = $d['offers'];
            $code = self::ldCode($o);
        } elseif ($empty) {
            $c = self::codeNode($empty[0]['node']['data']);
            foreach ($empty[0]['fields'] as $k) {
                $c[$k] = $k === 'author' ? ['@type' => 'Person', 'name' => '[ВКАЖІТЬ ім\'я автора]', 'url' => '[ВКАЖІТЬ URL сторінки автора]']
                    : '[ВКАЖІТЬ ' . ($k === 'headline' ? 'заголовок' : 'дату, ISO 8601') . ' або приберіть поле]';
            }
            $code = self::ldCode($c);
        }
        $this->findings[] = $this->finding('inner_markup', 'low', 'Помилки розмітки на внутрішніх сторінках: ' . implode(', ', $labels), [
            'evidence' => implode('. ', $ev) . '.',
            'why'      => ($empty ? 'Порожнє значення - не дата і не заголовок: валідатори позначають його помилкою, а машини отримують «статтю без дати». ' : '')
                . ($prodSvc ? 'За schema.org Product формально може описувати й послугу, але Service точніше передає суть (постачальник, регіон обслуговування), а товарні розширені результати Google розраховані насамперед на товари.' : ''),
            'fix'      => ($empty ? 'Заповнюйте поля реальними значеннями або не виводьте їх зовсім. ' : '')
                . ($prodSvc ? 'Для сторінок послуг використовуйте Service (ціни - в offers), рейтинг додавайте лише з реальних відгуків на сторінці.' : ''),
            'bad'      => implode("\n\n", $bad),
            'good'     => $prodSvc ? '{"@type": "Service", "name": "...", "serviceType": "...", "provider": {"@id": "' . $this->orgId() . '"}, "offers": {...}}'
                : '"datePublished": "2025-03-14T10:00:00+02:00" (реальна дата) або поле відсутнє',
            'code'     => $code,
            'verify'   => 'https://validator.schema.org/ для ' . ($prodSvc ? $prodSvc[0]['pg']['url'] : $empty[0]['pg']['url']) . ' - без помилок і порожніх значень.',
            'effort'   => 'S',
        ]);
    }

    // ================================================================= summary / utils

    private function summary(): string
    {
        $types = [];
        foreach ($this->fetchedPages() as $pg) foreach ($pg['nodes'] as $n) {
            if (!$n['top']) continue;
            foreach ($n['types'] as $t) if (!in_array($t, ['ListItem', 'WebPage', 'ImageObject', 'PostalAddress', 'ContactPoint', 'Place', 'Question', 'Answer'], true)) $types[$t] = true;
        }
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $fs = $this->findings;
        usort($fs, function ($a, $b) use ($order) { return ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3); });
        $head = $types ? 'Є ' . self::listSome(array_keys($types), 5) . '.' : 'Розмітки schema.org не знайдено.';
        $top = [];
        foreach ($fs as $f) {
            if ($f['severity'] === 'low' && $top) break;
            if (in_array($f['id'], ['schema.product_unchecked', 'schema.article_unchecked', 'schema.service_unchecked'], true)) continue;
            $t = rtrim($f['text'], '.');
            // з малої лише звичайне українське слово; назви типів (BreadcrumbList, JSON-LD) лишаємо як є
            if (preg_match('/^[А-ЯІЇЄҐ](?![А-ЯІЇЄҐA-Z])/u', $t)) $t = mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1);
            $top[] = self::mbTrunc($t, 130);
            if (count($top) >= 2) break;
        }
        $tail = $top ? ' Головне: ' . implode('; ', $top) : '';
        if ($tail !== '' && substr($tail, -3) !== '...') $tail .= '.';
        $un = '';
        if (isset($this->data['type_specific']['note'])) {
            $what = ['ecommerce' => 'карток товарів', 'media' => 'статей', 'services' => 'сторінок послуг', 'saas' => 'продукту', 'local' => 'філій', 'personal' => 'сторінки профілю'];
            $un = ' Розмітку ' . ($what[$this->stype] ?? 'ключових сторінок') . ' автоматично не перевірено.';
        }
        return $head . ($top ? $tail : ($un !== '' ? '' : ' Суттєвих проблем не знайдено.')) . $un;
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

    private static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}
