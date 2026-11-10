<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';
require_once __DIR__ . '/../SiteProfile.php';

/**
 * llms.txt - неофіційна пропозиція (llmstxt.org). Максимальна серйозність знахідок - low.
 * Перевіряє /llms.txt і /llms-full.txt (з розпізнаванням soft-404), структуру за специфікацією
 * (H1, цитата-опис, розділи H2 зі списками посилань), до 3 посилань на працездатність.
 * Коли файлу немає або він слабкий - дає чернетку з реальних даних сайту.
 */
class LlmsTxtCheck extends BaseCheck
{
    const MAX_DRAFT_LINKS = 15;
    /** Мінімум посилань на сторінки сайту, щоб файл давав огляд сайту, а не одного розділу. */
    const MIN_OWN_LINKS = 5;
    const LINK_TIMEOUT = 10;
    const WHY = 'llms.txt - неофіційна пропозиція (llmstxt.org, 2024): markdown-файл зі стислим описом сайту і ключовими посиланнями для мовних моделей. Google у документації Пошуку пише, що llms.txt не використовує, а OpenAI, Perplexity та інші AI-пошуковики публічно не підтверджували, що читають цей файл. Тож це дешевий експеримент на майбутнє, а не фактор видимості.';
    const RX_JUNK_ANCHOR = '/^(детальніше|докладніше|читати далі|читати|більше|ще|дивитися всі|всі|усі|переглянути|read more|more|see all|view all|learn more|купити|замовити|в кошик|кошик|увійти|вхід|реєстрація|login|log in|sign in|sign up|ua|uk|en|ru|укр|eng|рус|ру|english|українська|русский|меню|menu|головна|home|пошук|search|close|закрити|назад|back|top|вгору)$/iu';
    const RX_LEGAL_ANCHOR = '/(оферт|конфіденційн|персональн|cookie|кукі|умови використання|угода користувача|правила користування|privacy|terms|policy|політика|статус замовлення|відстежити замовлення|відстеження|відсл[іi]дк|\btrack|карта сайту|sitemap)/iu';
    /** Довідка для клієнтів: анкор ПОЧИНАЄТЬСЯ з такого слова (а не просто містить «delivery», як фільтр «З USB-C Power Delivery»). */
    const RX_HELP_ANCHOR = '/^(доставк|оплат|гаранті|поверненн|обмін|умови (доставки|оплати|повернення|гарантії|обміну)|faq|часті питання|питання та відповіді|питання і відповіді|допомог|центр допомоги|як (купувати|купити|замовити|оформити)|shipping|delivery|payment|returns?|warranty|help|support)/iu';
    /** Тимчасові акції та сторінки фільтрів - не ключові сторінки для llms.txt. */
    const RX_SKIP_PATH = '#/(actions?|promotions?|promo|shares|akcii|akciyi|aktsii|akczii|filter|filters|_filter|mfp)(/|$)|,#i';

    /** Особисті / службові розділи (кабінет, кошик, вхід), яким не місце ні в карті, ні в llms.txt. */
    const RX_PRIVATE_PATH = '#/(user|users|customer|customers|my|me|lk|garage|personal|personal-info|personal-data|history[\w-]*|orders?|wishlist|favorites|favourites|compare|cart|basket|checkout|login|logout|register|registration|account|my-account|profile|auth|signin|sign-in|signup|sign-up|cabinet|kabinet|password|forgot[\w-]*)(/|$)#i';
    const RX_PRIVATE_QUERY = '#route=(account|checkout|affiliate|product/compare|product/search)#i';

    private string $final = '';
    private string $base = '';
    private ?string $mode = null;
    /** Що з реальних даних потрапило в останню чернетку: кількість посилань і чи опис узято з сайту. */
    private array $draftInfo = ['links' => 0, 'summary' => false];
    /** Українська версія головної для чернетки (кеш на один запуск). */
    private ?Page $ukPage = null;
    private bool $ukTried = false;

    public function id(): string { return 'llmstxt'; }
    public function name(): string { return 'llms.txt'; }
    public function weight(): float { return 0.03; }

    public function run(string $url, array $ctx): array
    {
        $this->final = (string)($ctx['url'] ?? '') !== '' ? (string)$ctx['url'] : $url;
        $this->base = Crawler::baseUrl($this->final);
        $this->mode = (($ctx['access']['fetched_as'] ?? 'bot') === 'browser') ? 'browser' : null;
        $page = (($ctx['page'] ?? null) instanceof Page) ? $ctx['page'] : null;

        $labels = [
            'exists'    => 'Файл доступний',
            'structure' => 'Структура за llmstxt.org',
            'links_ok'  => 'Посилання працюють',
        ];
        $mainUrl = $this->base . '/llms.txt';
        $fullUrl = $this->base . '/llms-full.txt';
        $docs = $this->fetchTxt([$mainUrl, $fullUrl]);
        $main = $docs[$mainUrl];
        $full = $docs[$fullUrl];
        $data = [
            'llms_txt'      => $this->stateLabel($main),
            'llms_full_txt' => $this->stateLabel($full),
        ];

        if (in_array($main['state'], ['blocked', 'timeout'], true)) {
            $why = $main['state'] === 'blocked'
                ? 'сервер віддає сторінку перевірки захисту від ботів (' . ($main['challenge'] ?? 'WAF') . ') і нашому боту, і браузерному запиту'
                : 'сервер не відповів вчасно';
            return $this->skipped('Не вдалося перевірити ' . $mainUrl . ': ' . $why . '. Наявність файлу не підтверджено і не спростовано.', $data);
        }

        if ($main['state'] !== 'ok') {
            return $this->missingResult($main, $full, $page, $ctx, $data, $labels);
        }

        // ------------------------------------------------------------ аналіз наявного файлу
        $body = $main['body'];
        $encIssue = false;
        if (!mb_check_encoding($body, 'UTF-8')) {
            $encIssue = true;
            $conv = @mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
            $body = is_string($conv) ? $conv : mb_scrub($body, 'UTF-8');
        }
        $st = $this->parseStructure($body);
        $data += [
            'bytes'     => $main['bytes'],
            'h1'        => $st['h1'],
            'h2'        => array_slice($st['h2'], 0, 20),
            'links'     => count($st['links']),
            'same_site' => $st['same'],
            'external'  => $st['external'],
        ];

        $issues = [];
        $structure = 0;
        if ($st['h1'] !== null) {
            $structure += 30;
            if (!$st['h1_first']) $issues[] = 'H1 стоїть не на початку файлу (за специфікацією файл починається з «# Назва»)';
        } elseif ($st['h1_nospace'] !== null) {
            $issues[] = 'заголовок «#' . self::mbTrunc($st['h1_nospace'], 50) . '» записано без пробілу після «#» - markdown не розпізнає його як H1 (треба «# ' . self::mbTrunc($st['h1_nospace'], 50) . '»)';
        } else {
            $issues[] = 'немає заголовка H1 («# Назва сайту») на початку файлу';
        }
        if ($st['h2_nospace']) {
            $issues[] = 'розділи без пробілу після «##» не розпізнаються як H2: ' . self::listSome(array_map(function ($s) { return '«##' . $s . '»'; }, $st['h2_nospace']), 3);
        }
        if ($st['quote'] !== null) $structure += 20;
        else $issues[] = 'немає короткого опису в цитаті («> ...») під заголовком';
        if ($st['h2']) $structure += 20;
        else $issues[] = 'немає розділів H2 («## Розділ») зі списками посилань';
        $nLinks = count($st['links']);
        if ($nLinks >= 3) $structure += 20;
        else {
            $structure += 7 * $nLinks;
            $issues[] = $nLinks === 0 ? 'немає жодного посилання у форматі [назва](URL)' : 'лише ' . $nLinks . ' посилання у форматі [назва](URL)';
        }
        if ($nLinks > 0) {
            if ($st['same'] >= 0.5 * $nLinks) $structure += 10;
            else $issues[] = 'більшість посилань (' . $st['external'] . ' з ' . $nLinks . ') ведуть на інші домени, а не на сторінки сайту';
        }
        if ($encIssue) {
            $structure = max(0, $structure - 10);
            $issues[] = 'файл не в кодуванні UTF-8 - частина читачів побачить «кракозябри»';
        }
        // файл має давати огляд сайту: кілька посилань на одну тему - формально за специфікацією, але слабко
        $thin = $nLinks >= 3 && $st['same'] < self::MIN_OWN_LINKS;
        if ($thin) {
            $structure = max(0, $structure - 10);
            if ($issues) $issues[] = 'лише ' . self::plural($st['same'], 'посилання', 'посилання', 'посилань') . ' на сторінки самого сайту';
        }

        $findings = [];
        $passed = [];
        $sub = ['exists' => 100, 'structure' => $structure, 'links_ok' => null];
        $redir = $main['final_url'] !== $mainUrl ? ' (після редиректу на ' . $main['final_url'] . ')' : '';
        $passed[] = 'llms.txt доступний: HTTP 200' . ($main['ctype'] !== '' ? ', ' . $main['ctype'] : '') . ', ' . self::kb($main['bytes']) . $redir;
        if ($st['h1'] !== null) $passed[] = 'Заголовок H1: «' . self::mbTrunc($st['h1'], 80) . '»';
        if ($st['quote'] !== null) $passed[] = 'Короткий опис у цитаті: «' . self::mbTrunc($st['quote'], 120) . '»';
        if ($st['h2']) $passed[] = self::plural(count($st['h2']), 'розділ', 'розділи', 'розділів') . ' H2 (' . self::listSome($st['h2'], 4) . '), ' . self::plural($nLinks, 'посилання', 'посилання', 'посилань') . ', з них на цей сайт - ' . $st['same'];
        if ($full['state'] === 'ok') $passed[] = 'Є також llms-full.txt (' . self::kb($full['bytes']) . ')';

        if ($issues) {
            $draft = $this->draft($this->draftPage($page), $ctx, $st['h1'] ?? $st['h1_nospace'], $st['quote']);
            $findings[] = $this->finding('structure', 'low', 'llms.txt не відповідає структурі llmstxt.org', [
                'evidence' => 'Файл ' . $mainUrl . ' (' . self::kb($main['bytes']) . '): ' . implode('; ', $issues) . '.',
                'why'      => self::WHY . ' Якщо файл уже є, варто привести його до формату специфікації - інакше інструменти, які його читають, не розберуть розділи і посилання.',
                'fix'      => 'Приведіть файл до формату: «# Назва», під нею «> опис в 1-2 речення», далі розділи «## ...» зі списками «- [назва](повний URL): пояснення».',
                'bad'      => self::excerpt($body, 12, 500),
                'good'     => self::excerpt($draft, 10, 500),
                'code'     => $draft,
                'verify'   => 'curl -s ' . $mainUrl . ' | head -20 - перший рядок «# ...», далі «> ...» і розділи «## ...» з посиланнями.',
                'effort'   => 'S',
            ]);
        }

        if ($thin && !$issues) {
            $exist = [];
            foreach ($st['links'] as $l) $exist[self::looseKey($l['href'])] = true;
            $L = $this->draftLabels($page, $ctx);
            [$extraBuckets, $extraN] = $this->draftLinks($this->draftPage($page), $ctx, $exist, $L);
            $extra = trim($this->sectionsMd($extraBuckets, $L));
            $names = [];
            foreach ($extraBuckets as $b) foreach ($b as $row) $names[] = $row[0];
            $ownList = [];
            foreach ($st['links'] as $l) if ($l['same']) $ownList[] = $l['href'];
            $findings[] = $this->finding('thin', 'low', 'llms.txt охоплює лише невелику частину сайту', [
                'evidence' => 'У файлі ' . self::plural($nLinks, 'посилання', 'посилання', 'посилань') . ', з них на сторінки сайту - ' . $st['same'] . ' (' . self::listSome($ownList, 3) . ').'
                    . ($names ? ' Немає ключових розділів, на які посилається головна: ' . self::listSome($names, 6) . '.' : ''),
                'why'      => self::WHY . ' Якщо файл уже є, корисніше, щоб він описував сайт загалом (основні розділи, про компанію, контакти), а не одну тему.',
                'fix'      => 'Додайте до файлу розділи з ключовими сторінками сайту - 5-20 посилань з короткими поясненнями.' . ($extra !== '' ? ' Заготовку з реальних посилань головної дано нижче, її можна дописати в кінець файлу.' : ''),
                'bad'      => self::excerpt($body, 12, 500),
                'good'     => $extra !== '' ? self::excerpt($extra, 10, 500) : '## [Розділ]' . "\n" . '- [Назва сторінки](' . $this->base . '/...): що на ній',
                'code'     => $extra,
                'verify'   => 'curl -s ' . $mainUrl . ' - у файлі є розділи «## ...» з посиланнями на основні сторінки сайту.',
                'effort'   => 'S',
            ]);
        }

        $cyr = (bool)preg_match('/\p{Cyrillic}/u', $body);
        $noCharset = strpos($main['ctype'], 'charset') === false;
        if (strpos($main['ctype'], 'html') === false && $cyr && $noCharset) {
            $sub['exists'] = 95;
            $cyrLine = '';
            foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $ln) if (preg_match('/\p{Cyrillic}/u', $ln)) { $cyrLine = self::mbTrunc(trim($ln), 90); break; }
            $findings[] = $this->finding('charset', 'low', 'llms.txt з кирилицею віддається без charset=utf-8', [
                'evidence' => 'GET ' . $mainUrl . ' -> Content-Type: ' . ($main['ctype'] !== '' ? $main['ctype'] : '(не вказано)') . ', без параметра charset. У файлі кирилиця, напр.: «' . $cyrLine . '».'
                    . ($main['bom'] ? ' На початку файлу є BOM, тож браузери визначать UTF-8, але частина HTTP-клієнтів його не враховує.' : ''),
                'why'      => 'Для text/plain без charset частина HTTP-клієнтів за замовчуванням читає текст як Latin-1 (так робить, наприклад, бібліотека requests у Python) - і замість кирилиці отримує «кракозябри».',
                'fix'      => 'Віддавайте .txt-файли з заголовком Content-Type: text/plain; charset=utf-8.',
                'bad'      => 'Content-Type: ' . ($main['ctype'] !== '' ? $main['ctype'] : '(не вказано)'),
                'good'     => 'Content-Type: text/plain; charset=utf-8',
                'code'     => implode("\n", ['# nginx (у блоці server)', 'charset utf-8;', '', '# Apache (.htaccess)', 'AddCharset utf-8 .txt']),
                'verify'   => 'curl -sI ' . $mainUrl . ' | grep -i content-type - очікуємо text/plain; charset=utf-8.',
                'effort'   => 'S',
            ]);
        }

        if (strpos($main['ctype'], 'html') !== false) {
            $sub['exists'] = 90;
            $findings[] = $this->finding('content_type', 'low', 'llms.txt віддається як HTML-документ', [
                'evidence' => 'GET ' . $mainUrl . ' -> HTTP 200, Content-Type: ' . $main['ctype'] . '. Вміст при цьому текстовий (markdown).',
                'why'      => 'Інструменти, які читають llms.txt, очікують простий текст. З типом text/html частина з них спробує розібрати файл як веб-сторінку і втратить розмітку markdown.',
                'fix'      => 'Налаштуйте сервер віддавати .txt з типом text/plain; charset=utf-8.',
                'bad'      => 'Content-Type: ' . $main['ctype'],
                'good'     => 'Content-Type: text/plain; charset=utf-8',
                'code'     => implode("\n", ['# nginx', 'location = /llms.txt {', '    types { }', '    default_type "text/plain; charset=utf-8";', '}', '', '# Apache (.htaccess)', 'AddType text/plain .txt', 'AddCharset utf-8 .txt']),
                'verify'   => 'curl -sI ' . $mainUrl . ' | grep -i content-type - очікуємо text/plain; charset=utf-8.',
                'effort'   => 'S',
            ]);
        }

        // ------------------------------------------------------------ перевірка посилань
        $toCheck = $this->pickLinks($st['links']);
        if ($toCheck) {
            $res = $this->checkLinks($toCheck);
            $data['link_checks'] = array_map(function ($c) { return $c['label']; }, $res);
            $known = array_filter($res, function ($c) { return $c['state'] !== 'unknown'; });
            if ($known) {
                $broken = array_filter($known, function ($c) { return $c['state'] === 'broken'; });
                $sub['links_ok'] = (int)round(100 * (count($known) - count($broken)) / count($known));
                if ($broken) {
                    $lines = [];
                    foreach ($broken as $u => $c) $lines[] = $u . ' - ' . $c['label'];
                    $findings[] = $this->finding('broken_links', 'low', 'У llms.txt є неробочі посилання', [
                        'evidence' => 'Перевірено ' . self::plural(count($known), 'посилання', 'посилання', 'посилань') . ' з llms.txt, неробочих: ' . count($broken) . ".\n" . implode("\n", $lines),
                        'why'      => 'Якщо модель або інструмент піде за посиланням з llms.txt і отримає помилку, файл подає застарілу картину сайту.',
                        'fix'      => 'Оновіть або приберіть неробочі посилання; найкраще генерувати llms.txt автоматично з актуальної структури сайту.',
                        'bad'      => implode("\n", $lines),
                        'good'     => 'Кожне посилання з llms.txt відповідає HTTP 200 (допустимо після одного редиректу).',
                        'code'     => '',
                        'verify'   => 'curl -s -o /dev/null -w "%{http_code}" для кожного посилання з файлу - очікуємо 200.',
                        'effort'   => 'S',
                    ]);
                } else {
                    $passed[] = 'Вибіркові посилання з файлу (' . count($known) . ' з ' . count($known) . ') відповідають 200';
                }
            }
        }

        $summary = 'llms.txt є (' . self::kb($main['bytes']) . ', ' . self::plural(count($st['h2']), 'розділ', 'розділи', 'розділів') . ' H2, ' . self::plural($nLinks, 'посилання', 'посилання', 'посилань') . ')';
        if ($issues) $summary .= ', але структура неповна: ' . $issues[0];
        elseif (!empty($broken)) $summary .= ', але є неробочі посилання';
        $summary .= '. Файл неофіційний, вага перевірки мінімальна.';

        $score = $this->scoreFrom([[$sub['exists'], 50], [$sub['structure'], 35], [$sub['links_ok'], 15]]);
        return $this->result($score, $sub, $labels, $findings, $passed, $data, $summary);
    }

    // ================================================================== missing / soft-404

    private function missingResult(array $main, array $full, ?Page $page, array $ctx, array $data, array $labels): array
    {
        $mainUrl = $main['url'];
        $draft = $this->draft($this->draftPage($page), $ctx, null, null);
        $brand = $this->brand($ctx, $page);
        $state = $main['state'];
        $ct = $main['ctype'] !== '' ? $main['ctype'] : 'тип не вказано';
        $fullNote = '';
        if ($full['state'] === 'soft404') $fullNote = ' /llms-full.txt теж не файл: ' . $this->stateLabel($full) . '.';
        elseif ($full['state'] === 'ok') $fullNote = ' При цьому /llms-full.txt є (' . self::kb($full['bytes']) . ').';
        elseif ($full['state'] !== 'timeout' && $full['state'] !== 'blocked') $fullNote = ' /llms-full.txt: ' . $this->stateLabel($full) . '.';

        $id = 'missing';
        if ($state === 'soft404') {
            $id = 'soft_404';
            $what = $main['redirect_home'] ? 'редирект на головну, яка відповідає 200' : ($main['soft_text'] ? 'текстова заглушка про відсутність файлу з кодом 200' : 'HTML-сторінка з кодом 200 замість текстового файлу');
            $text = $main['soft_text'] ? 'Замість llms.txt сервер віддає заглушку «не знайдено» з кодом 200 (soft-404)' : 'Замість llms.txt сервер віддає HTML-сторінку (soft-404)';
            $evidence = 'GET ' . $mainUrl . ' -> ' . $what . ' (' . $ct . '). Файлу llms.txt фактично немає.' . $fullNote;
            $bad = 'GET /llms.txt -> 200, ' . $ct . ($main['redirect_home'] ? ' (після редиректу на ' . $main['final_url'] . ')' : '') . "\n" . self::mbTrunc($main['head'], 220);
            $fix = 'Якщо вирішите додати файл - розмістіть llms.txt у корені за чернеткою нижче (перевірте опис і посилання). Окремо варто налаштувати, щоб неіснуючі адреси відповідали кодом 404, а не сторінкою з кодом 200.';
            $summary = 'Замість llms.txt сервер віддає ' . ($main['soft_text'] ? 'заглушку «не знайдено»' : 'HTML-сторінку') . ' з кодом 200 (soft-404) - файлу немає. Стандарт неофіційний, вага перевірки мінімальна.';
        } elseif ($state === 'empty') {
            $text = 'Файл llms.txt порожній';
            $evidence = 'GET ' . $mainUrl . ' -> HTTP 200, але тіло відповіді порожнє.' . $fullNote;
            $bad = 'GET /llms.txt -> 200, 0 байт змістовного тексту';
            $fix = 'Заповніть файл за чернеткою нижче (перевірте опис і посилання) або приберіть порожній файл.';
            $summary = 'llms.txt порожній. Стандарт неофіційний, вага перевірки мінімальна.';
        } elseif ($state === 'forbidden') {
            $id = 'forbidden';
            $probe = $this->forbiddenProbe();
            $robotsOk = (int)($ctx['robots_txt']['status'] ?? 0) === 200;
            $reason = [401 => 'Unauthorized', 403 => 'Forbidden', 406 => 'Not Acceptable', 429 => 'Too Many Requests'][$main['status']] ?? '';
            $text = 'llms.txt недоступний (HTTP ' . $main['status'] . ')';
            if ($probe === true) {
                // неіснуючий .txt теж 403: правило сервера закриває .txt-файли загалом, існування llms.txt не перевірити
                $evidence = 'GET ' . $mainUrl . ' -> HTTP ' . $main['status'] . ' і для бота, і для браузера. Так само сервер відповідає на довільний неіснуючий .txt'
                    . ($robotsOk ? ' (при цьому robots.txt відкритий)' : '') . ' - схоже, правило сервера закриває доступ до .txt-файлів у корені. Чи є сам llms.txt, з\'ясувати не можна, але навіть доданий файл буде недоступний, доки правило не змінити.';
                $fix = 'Якщо вирішите додати llms.txt - зробіть для нього виняток у правилі сервера, яке закриває .txt (.htaccess / nginx / WAF), і розмістіть файл за чернеткою нижче.';
            } elseif ($probe === false) {
                $evidence = 'GET ' . $mainUrl . ' -> HTTP ' . $main['status'] . ' і для бота, і для браузера. На неіснуючий .txt сервер відповідає інакше - отже файл, ймовірно, є, але доступ до нього закрито правилом сервера.';
                $fix = 'Відкрийте доступ до /llms.txt у правилах сервера (.htaccess / nginx / WAF) і перевірте вміст за чернеткою нижче.';
            } else {
                $evidence = 'GET ' . $mainUrl . ' -> HTTP ' . $main['status'] . ' і для бота, і для браузера.';
                $fix = 'Якщо файл потрібен - розмістіть llms.txt за чернеткою нижче і перевірте, що правила сервера (.htaccess / nginx / WAF) не забороняють доступ до нього.';
            }
            $evidence .= $fullNote;
            $bad = 'GET /llms.txt -> ' . $main['status'] . ($reason !== '' ? ' ' . $reason : '') . ($probe === true ? "\nGET /довільний-неіснуючий.txt -> " . $main['status'] . ' (так само)' : '');
            $summary = 'llms.txt недоступний (HTTP ' . $main['status'] . ')' . ($probe === true ? ', сервер закриває .txt-файли загалом' : '') . '. Стандарт неофіційний, вага перевірки мінімальна.';
        } elseif ($state === 'error') {
            // 5xx / незавершений редирект: файл не віддається, але «немає файлу» стверджувати не можна
            $id = 'unavailable';
            $text = 'llms.txt не віддається (HTTP ' . $main['status'] . ')';
            $evidence = 'GET ' . $mainUrl . ' -> ' . $this->stateLabel($main) . '. Сервер повернув помилку, тож наявність файлу не підтверджено.' . $fullNote;
            $bad = 'GET /llms.txt -> ' . $main['status'];
            $fix = 'Перевірте, чому сервер повертає помилку для /llms.txt. Якщо файлу немає і він потрібен - розмістіть його за чернеткою нижче.';
            $summary = 'llms.txt не віддається (HTTP ' . $main['status'] . '). Стандарт неофіційний, вага перевірки мінімальна.';
        } else {
            $text = 'Немає файлу llms.txt';
            $evidence = 'GET ' . $mainUrl . ' -> ' . $this->stateLabel($main) . '.' . $fullNote;
            $bad = 'GET /llms.txt -> ' . ($main['status'] ?: 'немає відповіді') . ($main['status'] === 404 ? ' Not Found' : '');
            $fix = 'Якщо хочете підстрахуватися на майбутнє - розмістіть у корені сайту llms.txt за чернеткою нижче (перевірте опис і посилання) і віддавайте його як text/plain; charset=utf-8.';
            $summary = 'Файлу llms.txt немає (' . ($main['status'] ?: 'немає відповіді') . '). Стандарт неофіційний, вага перевірки мінімальна.';
        }

        if (!$page) {
            $note = ' Головна сторінка недоступна для нашого бота, тому чернетка нижче - лише заготовка: заповніть позначені місця.';
        } elseif ($this->draftInfo['links'] === 0) {
            $note = ' На головній не знайшлося придатних посилань меню, тому в чернетці ' . ($this->draftInfo['summary'] ? 'реальні лише назва й опис' : 'реальна лише назва') . ' - решту заповніть вручну.';
        } else {
            $note = ' Чернетку нижче складено з реальних даних сайту: ' . ($this->draftInfo['summary'] ? 'назва, опис з метатегів, ' : 'назва, ') . self::plural($this->draftInfo['links'], 'посилання', 'посилання', 'посилань') . ' з головної та вибірки сторінок.';
        }
        $f = $this->finding($id, 'low', $text, [
            'evidence' => $evidence . $note,
            'why'      => self::WHY,
            'fix'      => $fix,
            'bad'      => $bad,
            'good'     => "GET /llms.txt -> 200, text/plain; charset=utf-8\n" . self::excerpt($draft, 6, 400),
            'code'     => $draft,
            'verify'   => 'curl -s -i ' . $mainUrl . ' | head -15 - очікуємо HTTP 200, Content-Type: text/plain і текст, що починається з «# ' . $brand . '».',
            'effort'   => 'S',
        ]);
        $sub = ['exists' => 0, 'structure' => null, 'links_ok' => null];
        return $this->result(0, $sub, $labels, [$f], [], $data, $summary);
    }

    // ================================================================== fetch / classify

    private function fetchTxt(array $urls): array
    {
        $res = Crawler::fetchMulti($urls, $this->mode, 15);
        $out = []; $retry = [];
        foreach ($urls as $u) {
            $out[$u] = $this->classify($u, $res[$u] ?? null);
            if ($this->mode === null && $out[$u]['retry']) $retry[] = $u;
        }
        if ($retry) {
            $res2 = Crawler::fetchMulti($retry, 'browser', 15);
            foreach ($retry as $u) {
                $c2 = $this->classify($u, $res2[$u] ?? null);
                if (!in_array($c2['state'], ['blocked', 'timeout'], true)) {
                    $out[$u] = $c2;
                } elseif ($out[$u]['state'] !== 'blocked') {
                    $out[$u]['state'] = 'blocked';
                    if ($out[$u]['challenge'] === null) $out[$u]['challenge'] = $c2['challenge'];
                }
            }
        }
        return $out;
    }

    private function classify(string $u, ?array $r): array
    {
        $c = ['url' => $u, 'state' => 'timeout', 'status' => 0, 'ctype' => '', 'final_url' => $u, 'bytes' => 0, 'body' => '',
            'head' => '', 'challenge' => null, 'retry' => false, 'redirect_home' => false, 'soft_text' => false, 'bom' => false];
        if (!$r || (int)($r['status'] ?? 0) === 0) return $c;
        $status = (int)$r['status'];
        $c['status'] = $status;
        $c['ctype'] = strtolower((string)($r['content_type'] ?? ''));
        $c['final_url'] = (string)($r['final_url'] ?? $u);
        if (!empty(((array)($r['headers'] ?? []))['x-amzn-waf-action'])) {
            $c['state'] = 'blocked'; $c['challenge'] = 'aws-waf'; $c['retry'] = true;
            return $c;
        }
        $body = (string)($r['body'] ?? '');
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) { $body = substr($body, 3); $c['bom'] = true; }
        $c['bytes'] = strlen($body);
        $c['head'] = trim((string)preg_replace('/\s+/u', ' ', mb_substr(mb_scrub(ltrim($body), 'UTF-8'), 0, 300)));
        $start = ltrim(substr($body, 0, 2048));
        $isHtml = (bool)preg_match('/^<(!doctype|html|head|body|\?xml|meta|script|title)\b/i', $start) || stripos($start, '<html') !== false;
        if ($isHtml) {
            $ch = self::safeChallenge($body);
            if ($ch !== null && $ch !== 'access_denied') {
                $c['state'] = 'blocked';
                $c['challenge'] = $ch;
                $c['retry'] = true;
                return $c;
            }
        }
        if (in_array($status, [401, 403, 406, 429], true)) { $c['state'] = 'forbidden'; $c['retry'] = true; return $c; }
        if ($status === 503) { $c['state'] = 'error'; $c['retry'] = true; return $c; }
        if ($status === 404 || $status === 410) { $c['state'] = 'missing'; return $c; }
        if ($status >= 300) { $c['state'] = 'error'; return $c; }
        // 2xx
        $redirected = (int)($r['redirect_count'] ?? 0) > 0;
        if ($redirected) {
            $fp = rtrim((string)parse_url($c['final_url'], PHP_URL_PATH), '/');
            $hp = rtrim((string)parse_url($this->final, PHP_URL_PATH), '/');
            if (Crawler::sameSite($c['final_url'], $this->final) && ($fp === '' || $fp === $hp || preg_match('#^/(uk|ua|en|ru)$#i', $fp))) {
                $c['redirect_home'] = true;
                $c['state'] = 'soft404';
                return $c;
            }
        }
        if ($isHtml) {
            // 202/204/206 з HTML - заглушка захисту, відсутність файлу не підтверджено
            if ($status !== 200) { $c['state'] = 'blocked'; $c['challenge'] = 'нестандартна відповідь'; $c['retry'] = true; return $c; }
            $c['state'] = 'soft404';
            return $c;
        }
        if (trim($body) === '') { $c['state'] = 'empty'; return $c; }
        // коротка текстова заглушка «Not found» з кодом 200 - теж soft-404
        if (strlen($body) < 300 && !preg_match('/^\s*#/m', $body) && preg_match('/(not\s*found|\b404\b|не\s+знайдено|не\s+найден|no\s+such\s+file)/iu', $body)) {
            $c['state'] = 'soft404';
            $c['soft_text'] = true;
            return $c;
        }
        $c['state'] = 'ok';
        $c['body'] = $body;
        return $c;
    }

    private function stateLabel(array $c): string
    {
        $st = (int)$c['status'];
        switch ($c['state']) {
            case 'ok': return 'HTTP 200, ' . self::kb($c['bytes']) . ($c['ctype'] !== '' ? ', ' . $c['ctype'] : '');
            case 'missing': return 'HTTP ' . $st;
            case 'soft404': return ($c['soft_text'] ? 'текстова заглушка «' . self::mbTrunc($c['head'], 60) . '» з кодом ' . ($st ?: 200) . ' (soft-404)' : 'HTML-сторінка з кодом ' . ($st ?: 200) . ' (soft-404)') . ($c['redirect_home'] ? ', редирект на ' . $c['final_url'] : '');
            case 'empty': return 'HTTP 200, порожній файл';
            case 'forbidden': return 'HTTP ' . $st . ' (доступ заборонено)';
            case 'blocked': return 'перевірка захисту від ботів' . ($c['challenge'] ? ' (' . $c['challenge'] . ')' : '') . ($st ? ', HTTP ' . $st : '');
            case 'error': return 'HTTP ' . $st;
            default: return 'немає відповіді';
        }
    }

    /** true - неіснуючий .txt теж 403 (файлу немає); false - відповідь інша; null - не з'ясовано. */
    private function forbiddenProbe(): ?bool
    {
        $u = $this->base . '/geo-audit-probe-' . substr(md5($this->base), 0, 8) . '.txt';
        $r = $this->mode === 'browser' ? Crawler::fetchBrowser($u, 10) : Crawler::fetch($u, 10);
        $s = (int)($r['status'] ?? 0);
        if ($s === 0) return null;
        return in_array($s, [401, 403], true);
    }

    // ================================================================== structure

    private function parseStructure(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $h1 = null; $h1First = false; $quote = null; $h2 = [];
        $h1NoSpace = null; $h2NoSpace = [];
        $firstSeen = false; $inQuote = false; $quoteLines = []; $afterH2 = false;
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') { if ($inQuote) $inQuote = false; continue; }
            $isFirst = !$firstSeen;
            $firstSeen = true;
            // «#Назва» / «##Розділ» без пробілу: markdown не вважає це заголовком
            if (preg_match('/^#(?!#)(\S.*)$/u', $t, $m)) { if ($h1NoSpace === null) $h1NoSpace = trim($m[1]); continue; }
            if (preg_match('/^##(?!#)(\S.*)$/u', $t, $m)) { $h2NoSpace[] = trim($m[1]); continue; }
            if (preg_match('/^#\s+(.+?)\s*#*$/u', $t, $m)) {
                if ($h1 === null) { $h1 = trim($m[1]); $h1First = $isFirst; }
                continue;
            }
            if (preg_match('/^##\s+(.+?)\s*#*$/u', $t, $m)) {
                $h2[] = trim($m[1]);
                $afterH2 = true;
                $inQuote = false;
                continue;
            }
            if (!$afterH2 && $t[0] === '>') {
                if ($quote === null || $inQuote) {
                    $quoteLines[] = trim(ltrim($t, '>'));
                    $inQuote = true;
                }
            } elseif ($inQuote) {
                $inQuote = false;
            }
        }
        if ($quoteLines) {
            $q = trim(implode(' ', array_filter($quoteLines, function ($s) { return $s !== ''; })));
            $quote = $q !== '' ? $q : null;
        }
        $links = [];
        $same = 0; $external = 0;
        if (preg_match_all('/\[([^\]\n]+)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/u', $body, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $href = trim($m[2]);
                $abs = Crawler::absolutize($href, $this->base . '/');
                if ($abs === null) continue;
                $isSame = Crawler::sameSite($abs, $this->final);
                if ($isSame) $same++; else $external++;
                $links[] = ['text' => trim($m[1]), 'href' => $abs, 'same' => $isSame];
            }
        }
        return ['h1' => $h1, 'h1_first' => $h1First, 'quote' => $quote, 'h2' => $h2, 'links' => $links, 'same' => $same, 'external' => $external,
            'h1_nospace' => $h1NoSpace, 'h2_nospace' => $h2NoSpace];
    }

    /** До 3 посилань: спершу на свій сайт, рівномірно по файлу. */
    private function pickLinks(array $links): array
    {
        $pool = []; $seen = [];
        foreach ($links as $l) {
            if (!$l['same'] || isset($seen[$l['href']])) continue;
            $seen[$l['href']] = true;
            $pool[] = $l['href'];
        }
        if (!$pool) {
            foreach ($links as $l) {
                if (isset($seen[$l['href']])) continue;
                $seen[$l['href']] = true;
                $pool[] = $l['href'];
            }
        }
        $n = count($pool);
        if ($n <= 3) return $pool;
        return array_values(array_unique([$pool[0], $pool[(int)floor($n / 2)], $pool[$n - 1]]));
    }

    /** [url => ['state'=>ok|broken|unknown, 'label']] */
    private function checkLinks(array $urls): array
    {
        $res = Crawler::fetchMulti($urls, $this->mode, self::LINK_TIMEOUT);
        $out = []; $retry = [];
        foreach ($urls as $u) {
            $out[$u] = $this->classifyLink($res[$u] ?? null);
            if ($this->mode === null && $out[$u]['retry']) $retry[] = $u;
        }
        if ($retry) {
            $res2 = Crawler::fetchMulti($retry, 'browser', self::LINK_TIMEOUT);
            foreach ($retry as $u) {
                $c2 = $this->classifyLink($res2[$u] ?? null);
                if ($c2['state'] === 'unknown' || $out[$u]['state'] === 'unknown') {
                    if ($c2['state'] === 'ok') $out[$u] = $c2;
                    else { $out[$u]['state'] = 'unknown'; }
                } else {
                    $out[$u] = $c2;
                }
            }
        }
        return $out;
    }

    private function classifyLink(?array $r): array
    {
        $c = ['state' => 'unknown', 'label' => 'немає відповіді', 'retry' => false];
        if (!$r || (int)($r['status'] ?? 0) === 0) return $c;
        $s = (int)$r['status'];
        $body = (string)($r['body'] ?? '');
        $ch = ($body !== '' && preg_match('/^\s*</', $body)) ? self::safeChallenge($body) : null;
        if ($ch !== null && $ch !== 'access_denied') {
            $c['label'] = 'перевірка захисту від ботів (' . $ch . ')';
            $c['retry'] = true;
            return $c;
        }
        $redir = (int)($r['redirect_count'] ?? 0) > 0 ? ' після редиректу на ' . (string)$r['final_url'] : '';
        if ($s >= 200 && $s < 400) {
            $c['state'] = 'ok';
            $c['label'] = 'HTTP ' . $s . $redir;
            return $c;
        }
        $c['state'] = 'broken';
        $c['label'] = 'HTTP ' . $s . $redir;
        if (in_array($s, [401, 403, 429, 503], true)) $c['retry'] = true;
        return $c;
    }

    // ================================================================== draft

    /** Підписи розділів чернетки мовою сайту (uk за замовчуванням, en для англомовних сайтів). */
    private function draftLabels(?Page $page, array $ctx): array
    {
        $site = (array)($ctx['site'] ?? []);
        $lang = strtolower((string)($site['lang'] ?? ($page ? (string)$page->lang() : '')));
        $en = strpos($lang, 'en') === 0;
        $L = $en
            ? ['catalog' => 'Catalog', 'services' => 'Services', 'sections' => 'Sections', 'product' => 'Product', 'blog' => 'Articles and news', 'info' => 'Customer help', 'company' => 'Company', 'about' => 'About us', 'contact' => 'Contacts', 'home' => 'Home', 'ph_summary' => '[ВКАЖІТЬ 1-2 речення англійською: хто ви, що пропонуєте, для кого і де працюєте]', 'ph_link' => '[ВКАЖІТЬ короткий опис]', 'ph_name' => 'ВКАЖІТЬ назву ключової сторінки', 'ph_path' => '[ВКАЖІТЬ-шлях]']
            : ['catalog' => 'Каталог', 'services' => 'Послуги', 'sections' => 'Розділи', 'product' => 'Продукт', 'blog' => 'Статті та новини', 'info' => 'Довідка для клієнтів', 'company' => 'Про компанію', 'about' => 'Про нас', 'contact' => 'Контакти', 'home' => 'Головна', 'ph_summary' => '[ВКАЖІТЬ 1-2 речення: хто ви, що пропонуєте, для кого і де працюєте]', 'ph_link' => '[ВКАЖІТЬ короткий опис; додайте так 3-10 ключових сторінок]', 'ph_name' => 'ВКАЖІТЬ назву ключової сторінки', 'ph_path' => '[ВКАЖІТЬ-шлях]'];
        $type = (string)($site['type'] ?? 'corporate');
        if ($type === 'personal') {
            // особистий сайт: розділ про особу, а не про компанію
            $L['company'] = $en ? 'About me' : 'Про мене';
            $L['about'] = $en ? 'About me' : 'Про мене';
        }
        $L['_en'] = $en;
        $L['_type'] = $type;
        $L['_primary'] = $L[['ecommerce' => 'catalog', 'services' => 'services', 'local' => 'services', 'media' => 'sections', 'saas' => 'product', 'corporate' => 'sections', 'personal' => 'services'][$type] ?? 'sections'];
        return $L;
    }

    /** Чернетка llms.txt з реальних даних: назва, опис, посилання меню й вибірки. */
    private function draft(?Page $page, array $ctx, ?string $h1, ?string $summary): string
    {
        $L = $this->draftLabels($page, $ctx);
        $title = $h1 !== null && $h1 !== '' ? $h1 : $this->brand($ctx, $page);
        $summaryReal = $summary !== null && $summary !== '';
        if ($summary === null || $summary === '') {
            $summary = '';
            if ($page) {
                foreach ([$page->og('description'), $page->metaDescription()] as $cand) {
                    $cand = self::cleanText((string)$cand);
                    if (mb_strlen($cand) >= 30) { $summary = $cand; break; }
                }
            }
            $summaryReal = $summary !== '';
            $summary = $summary !== '' ? self::cutSentence($summary, 300) : $L['ph_summary'];
        }

        [$buckets, $total] = $this->draftLinks($page, $ctx, [], $L);
        $md = '# ' . self::mdText($title) . "\n\n> " . $summary . "\n";
        $sections = $this->sectionsMd($buckets, $L);
        if ($sections !== '') {
            $md .= $sections;
        } else {
            $md .= "\n## " . $L['_primary'] . "\n- [" . $L['home'] . '](' . self::mdUrl($this->final) . ")\n- [" . $L['ph_name'] . '](' . $this->base . '/' . $L['ph_path'] . '): ' . $L['ph_link'] . "\n";
        }
        $this->draftInfo = ['links' => $total, 'summary' => $summaryReal];
        return rtrim($md) . "\n";
    }

    /** Розділи «## ...» зі списками посилань. */
    private function sectionsMd(array $buckets, array $L): string
    {
        $sections = ['primary' => $L['_primary'], 'blog' => $L['blog'], 'info' => $L['info'], 'company' => $L['company']];
        $md = '';
        foreach ($sections as $k => $label) {
            if (empty($buckets[$k])) continue;
            $md .= "\n## " . $label . "\n";
            foreach ($buckets[$k] as [$t, $u, $d]) $md .= '- [' . self::mdText($t) . '](' . self::mdUrl($u) . ')' . ($d !== '' ? ': ' . $d : '') . "\n";
        }
        return $md;
    }

    /**
     * Посилання для чернетки: [buckets, total]. buckets: primary|blog|info|company => [[текст, URL, опис], ...].
     * $exclude - looseKey адрес, які вже є у файлі (для доповнення наявного llms.txt).
     */
    private function draftLinks(?Page $page, array $ctx, array $exclude, array $L): array
    {
        $type = $L['_type'];
        $en = $L['_en'];
        $buckets = ['primary' => [], 'blog' => [], 'info' => [], 'company' => []];
        $caps = ['primary' => 8, 'blog' => 3, 'info' => 3, 'company' => 3];
        $seenUrl = $exclude + [self::looseKey($this->final) => true, self::looseKey($this->base . '/') => true];
        $homeUrl = $page ? $page->url() : $this->final;
        $seenUrl[self::looseKey($homeUrl)] = true;
        $seenText = [];
        $total = 0;
        $homePrefix = preg_match('#^/(uk|ua|en|ru)(/|$)#i', (string)parse_url($homeUrl, PHP_URL_PATH), $hm) ? strtolower($hm[1]) : null;
        // посилання перемикача мов (hreflang-альтернативи головної) - не розділи сайту
        $altSet = [];
        if ($page) foreach ($page->hreflangs() as $h) if (!empty($h['href'])) $altSet[self::looseKey((string)$h['href'])] = true;
        $anchorFor = [];
        if ($page) foreach ($page->internalLinks() as $l) {
            $k = self::looseKey($l['href']);
            if (!isset($anchorFor[$k]) && $l['text'] !== '') $anchorFor[$k] = $l['text'];
        }
        // короткі описи з метатегів сторінок вибірки (лише для них у нас є реальні дані)
        $descFor = [];
        // шаблонний опис (той самий, що на головній, або однаковий на кількох сторінках) нічого не пояснює - не беремо
        $homeDesc = $page ? [mb_strtolower(self::cleanText($page->metaDescription())), mb_strtolower(self::cleanText((string)$page->og('description')))] : [];
        $descCount = [];
        $descRaw = [];
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            $p = $s['page'] ?? null;
            if (!($p instanceof Page) || (int)($s['res']['status'] ?? 0) !== 200) continue;
            $d = self::cleanText($p->metaDescription());
            if ($d === '') $d = self::cleanText((string)$p->og('description'));
            if (mb_strlen($d) < 30 || in_array(mb_strtolower($d), $homeDesc, true)) continue;
            $descCount[mb_strtolower($d)] = ($descCount[mb_strtolower($d)] ?? 0) + 1;
            $descRaw[] = [$s, $d];
        }
        foreach ($descRaw as [$s, $d]) {
            if ($descCount[mb_strtolower($d)] > 1) continue;
            $d = self::cutSentence($d, 120);
            $descFor[self::looseKey((string)$s['url'])] = $d;
            if (!empty($s['res']['final_url'])) $descFor[self::looseKey((string)$s['res']['final_url'])] = $d;
        }
        $push = function (string $bucket, string $href, string $text) use (&$buckets, $caps, &$seenUrl, &$seenText, &$total, $descFor) {
            if ($total >= self::MAX_DRAFT_LINKS || count($buckets[$bucket]) >= $caps[$bucket]) return;
            $k = self::looseKey($href);
            $tk = mb_strtolower($text);
            if (isset($seenUrl[$k]) || isset($seenText[$tk])) return;
            $seenUrl[$k] = true;
            $seenText[$tk] = true;
            $d = $descFor[$k] ?? '';
            // опис, що просто повторює назву, не потрібен
            if ($d !== '' && mb_stripos($d, $text) === 0 && mb_strlen($d) < mb_strlen($text) + 15) $d = '';
            $buckets[$bucket][] = [$text, $href, $d];
            $total++;
        };

        // про нас / контакти з вибірки
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            $role = (string)($s['role'] ?? '');
            if ($role !== 'about' && $role !== 'contact') continue;
            if ((int)($s['res']['status'] ?? 0) !== 200) continue;
            $u = (string)($s['res']['final_url'] ?? $s['url']);
            if (!Crawler::sameSite($u, $this->final)) continue;
            $t = self::cleanAnchor((string)($anchorFor[self::looseKey((string)$s['url'])] ?? ''));
            if ($t === '' || preg_match(self::RX_JUNK_ANCHOR, $t)) $t = $role === 'about' ? $L['about'] : $L['contact'];
            $push('company', $u, $t);
        }
        // ролі сторінок вибірки (товар / категорія / послуга / стаття) - надійніші за здогадку зі шляху
        $sampleRole = [];
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            $r = (string)($s['role'] ?? '');
            if ($r === '' || $r === 'other') continue;
            $sampleRole[self::looseKey((string)($s['url'] ?? ''))] = $r;
            if (!empty($s['res']['final_url'])) $sampleRole[self::looseKey((string)$s['res']['final_url'])] = $r;
        }
        // посилання головної: меню / шапка, далі тіло сторінки, футер - в останню чергу
        if ($page) {
            foreach (self::orderedLinks($page) as $l) {
                $href = $l['href'];
                if (SiteProfile::isExcluded($href, $this->final)) continue;
                $q = (string)parse_url($href, PHP_URL_QUERY);
                if ($q !== '' && (!preg_match('#route=#i', $q) || preg_match(self::RX_PRIVATE_QUERY, $q))) continue;
                $path = (string)parse_url($href, PHP_URL_PATH);
                if (preg_match(self::RX_PRIVATE_PATH, $path) || preg_match(self::RX_SKIP_PATH, $path)) continue;
                if (isset($altSet[self::looseKey($href)])) continue;
                if (preg_match('#^/(es|pl|de|fr|it|cs|ro|hu|tr|kk|be|pt|nl|zh|ja)(/|$)#i', $path)) continue;
                // глибокі адреси з тіла сторінки (банери, підбірки, фільтри) - не ключові розділи; з меню беремо будь-які
                $segs = array_values(array_filter(explode('/', $path), function ($x) { return $x !== ''; }));
                if ($segs && preg_match('/^(uk|ua|en|ru)$/i', $segs[0])) array_shift($segs);
                if (!$l['in_nav'] && count($segs) >= 3) continue;
                // глибока адреса з «параметром» у slug (/b-u-iphone/brand-apple/sostoyanie_novyy-20/) - сторінка фільтра
                if (count($segs) >= 3 && strpos((string)end($segs), '_') !== false) continue;
                // мовні версії: українську лишаємо завжди, ru/en - лише якщо головна сама в цьому розділі
                if (preg_match('#^/(en|ru)(/|$)#i', $path, $pm) && strtolower($pm[1]) !== $homePrefix && !($en && strtolower($pm[1]) === 'en')) continue;
                $text = self::cleanAnchor($l['text']);
                if ($text === '' || preg_match(self::RX_LEGAL_ANCHOR, $text) || self::slugLike($text, $href)) continue;
                if (preg_match('/^(українська|укр|ua|uk|русский|рус|ru|english|eng|en|español|espanol|spanish|polski|deutsch)$/iu', $text)) continue;
                $lastSeg = (string)preg_replace('/\.(html?|php)$/i', '', basename(rtrim($path, '/')));
                // загальна назва розділу («Статті», «Блог»), що веде на окрему статтю - випадкове посилання, не розділ
                if (preg_match(SiteProfile::RX_ANCHOR_BLOG, $text) && substr_count($lastSeg, '-') >= 3) continue;
                $role = SiteProfile::roleOfUrl($href);
                $sr = $sampleRole[self::looseKey($href)] ?? null;
                // article_weak - лише довгий slug; в інтернет-магазині це найчастіше картка товару, а не стаття
                // сторінки районів обслуговування (/service-areas/...) - послуги за містом, а не статті
                $weakArticle = $role === 'article_weak' && $type !== 'ecommerce' && !preg_match('#/(service-areas?|areas?-we-serve|locations?|cities|regions?)/#i', $path);
                // «Оплата за QR-кодом» - продукт, а не довідка: довідкою вважаємо лише сторінки зі шляхом довідки/оплати
                $helpLike = preg_match(self::RX_HELP_ANCHOR, $text) || preg_match('/(питання-відповідь|запитання)/iu', $text);
                if ($helpLike && preg_match('/^оплат[аи]? за /iu', $text) && !preg_match('#/(help|faq|support|dopomoga|pidtrymka|oplata|payment|delivery|dostavka)#i', $path)) $helpLike = false;
                if ($role === 'about' || $role === 'contact' || preg_match(SiteProfile::RX_ANCHOR_ABOUT, $text) || preg_match(SiteProfile::RX_ANCHOR_CONTACT, $text)) $bucket = 'company';
                elseif (in_array($sr, ['product', 'category', 'service'], true)) $bucket = 'primary';
                elseif ($type !== 'media' && ($sr === 'article' || in_array($role, ['blog_index', 'article'], true) || $weakArticle || preg_match(SiteProfile::RX_ANCHOR_BLOG, $text))) $bucket = 'blog';
                elseif ($helpLike) $bucket = 'info';
                else $bucket = 'primary';
                $push($bucket, $href, $text);
            }
        }
        // ключові сторінки з вибірки, якщо меню не дало основного розділу
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            $role = (string)($s['role'] ?? '');
            $p = $s['page'] ?? null;
            if (!($p instanceof Page) || (int)($s['res']['status'] ?? 0) !== 200) continue;
            $u = (string)($s['res']['final_url'] ?? $s['url']);
            if (!Crawler::sameSite($u, $this->final)) continue;
            $t = self::cleanAnchor((string)preg_split('/\s+[|\-–—:]\s+/u', $p->title())[0]);
            if ($t === '') continue;
            if (in_array($role, ['product', 'service', 'category'], true) && count($buckets['primary']) < 3) $push('primary', $u, $t);
            elseif ($role === 'article' && !$buckets['blog']) $push('blog', $u, $t);
        }
        return [$buckets, $total];
    }

    /**
     * Сторінка-джерело для чернетки. Якщо корінь сайту російськомовний, а українська версія оголошена в hreflang,
     * чернетку будуємо з неї (ринок України: лише uk/en).
     */
    private function draftPage(?Page $page): ?Page
    {
        if (!$page) return null;
        $lang = strtolower((string)$page->lang());
        $ruRoot = $lang !== '' ? strpos($lang, 'ru') === 0 : (bool)preg_match('/[ыэъё]/u', mb_substr($page->visibleText(), 0, 3000));
        if (!$ruRoot) return $page;
        if ($this->ukTried) return $this->ukPage ?? $page;
        $this->ukTried = true;
        $alt = null;
        foreach ($page->hreflangs() as $h) {
            $hl = strtolower((string)($h['lang'] ?? ''));
            if (($hl === 'uk' || strpos($hl, 'uk-') === 0) && !empty($h['href'])) { $alt = (string)$h['href']; break; }
        }
        if ($alt === null || self::looseKey($alt) === self::looseKey($page->url()) || !Crawler::sameSite($alt, $this->final)) return $page;
        $r = $this->mode === 'browser' ? Crawler::fetchBrowser($alt, 12) : Crawler::fetch($alt, 12);
        $p = (int)($r['status'] ?? 0) === 200 ? Page::fromCrawl($r) : null;
        if ($p && $p->challenge() === null) $this->ukPage = $p;
        return $this->ukPage ?? $page;
    }

    /** Анкор, який насправді є slug-ом адреси («odesa» -> /odesa/) або технічним ідентифікатором («main_banner_29_09_», «Les Nereides_main»). */
    private static function slugLike(string $text, string $href): bool
    {
        if (strpos($text, '_') !== false) return true;
        if (preg_match('/\s/u', $text)) return false;
        $last = strtolower((string)preg_replace('/\.(html?|php)$/i', '', basename(rtrim((string)parse_url($href, PHP_URL_PATH), '/'))));
        return $last !== '' && preg_match('/^[a-z0-9\-]+$/', $text) && $text === rawurldecode($last);
    }

    private function brand(array $ctx, ?Page $page): string
    {
        $b = trim((string)($ctx['site']['brand'] ?? ''));
        if ($b === '') $b = SiteProfile::brand($page, $this->final);
        if (!preg_match('/\p{L}/u', $b) && $page) $b = self::cleanAnchor((string)preg_split('/\s+[|\-–—:]\s+/u', $page->title())[0]);
        return preg_match('/\p{L}/u', $b) ? $b : (string)parse_url($this->final, PHP_URL_HOST);
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

    private static function cleanText(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(['—', '–', '…', "\u{00A0}", '’', 'ʼ', '`'], ['-', '-', '...', ' ', "'", "'", "'"], $s);
        // емодзі, «галочки», стрілки, декоративні дужки 【】 з рекламних метатегів - у llms.txt їм не місце.
        // Вони часто працюють як роздільники («ПК ✔ Гарантія ✔ Доставка»), тож замінюємо їх комою, а не просто прибираємо.
        $s = (string)preg_replace('/\x{3010}\s*([^\x{3010}\x{3011}]{1,40}?)\s*\x{3011}/u', '$1: ', $s);
        $s = (string)preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{25A0}-\x{25FF}\x{3010}\x{3011}\x{FE0F}\x{200D}\x{20E3}]+/u', "\x01", $s);
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        $s = (string)preg_replace('/^[\s\x01:]+|[\s\x01]+$/u', '', $s);
        $s = (string)preg_replace_callback('/(\S)?\s*\x01[\s\x01]*/u', function ($m) {
            $p = $m[1] ?? '';
            if ($p === '') return '';
            return preg_match('/[.!?:;,]/u', $p) ? $p . ' ' : $p . ', ';
        }, $s);
        $s = str_replace("\x01", '', $s);
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        return trim((string)preg_replace('/\s+([.,;:!?])/u', '$1', $s));
    }

    private static function cleanAnchor(string $s): string
    {
        $s = self::cleanText($s);
        // лічильники «Ноутбуки (123)» / «Ноутбуки 1234»; «iPhone 15» і «Новий 2.0» не чіпаємо
        $s = trim((string)preg_replace('/\s*[\(\[]\d[\d\s]*[\)\]]$|\s+\d{3,}$/u', '', $s));
        $len = mb_strlen($s);
        if ($len < 2 || $len > 60) return '';
        if (!preg_match('/\p{L}/u', $s) || preg_match(self::RX_JUNK_ANCHOR, $s)) return '';
        if (preg_match('/@|^\+?\d[\d\s\-()]{6,}$/u', $s)) return '';
        return $s;
    }

    private static function cutSentence(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) return $s;
        $cut = mb_substr($s, 0, $max);
        // кінець останнього повного речення в першій половині і далі
        if (preg_match_all('/[.!?](?=\s)/u', $cut, $mm, PREG_OFFSET_CAPTURE)) {
            $last = end($mm[0]);
            $pos = mb_strlen(substr($cut, 0, $last[1])) + 1;
            if ($pos > $max * 0.5) return mb_substr($cut, 0, $pos);
        }
        // інакше обрізаємо по слову і чесно ставимо «...»
        $sp = mb_strrpos($cut, ' ');
        return rtrim(mb_substr($cut, 0, $sp !== false ? $sp : $max), ' ,;:-') . '...';
    }

    private static function mdText(string $s): string
    {
        return str_replace(['[', ']'], ['(', ')'], $s);
    }

    private static function mdUrl(string $u): string
    {
        return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $u);
    }

    private static function looseKey(string $u): string
    {
        $p = parse_url($u);
        if (!$p || empty($p['host'])) return strtolower($u);
        $h = strtolower($p['host']);
        if (strpos($h, 'www.') === 0) $h = substr($h, 4);
        $path = rawurldecode((string)($p['path'] ?? '/'));
        if (strlen($path) > 1) $path = rtrim($path, '/');
        return $h . $path . (isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '');
    }

    private static function excerpt(string $s, int $lines, int $chars): string
    {
        $ls = preg_split('/\r\n|\r|\n/', trim($s)) ?: [];
        $out = implode("\n", array_slice($ls, 0, $lines));
        if (count($ls) > $lines) $out .= "\n...";
        return mb_strlen($out) > $chars ? mb_substr($out, 0, $chars) . ' ...' : $out;
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

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $m10 = $n % 10; $m100 = $n % 100;
        $w = ($m10 === 1 && $m100 !== 11) ? $one : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? $few : $many);
        return $n . ' ' . $w;
    }

    private static function kb(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' байт';
        return str_replace('.', ',', (string)round($bytes / 1024, 1)) . ' КБ';
    }
}
