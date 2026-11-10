<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';

/**
 * Цитованість контенту (v2).
 *
 * Чи зручно AI-пошуковикам (ChatGPT search, Perplexity, Google AI Overviews / AI Mode, Copilot)
 * витягати з сайту самодостатні фрагменти й цитувати їх. Оцінюються головна і внутрішні сторінки
 * вибірки (стаття / послуга / про компанію / товар / категорія); контентні сторінки важать більше
 * за головну. Оцінюються обсяг, абзаци-відповіді,
 * заголовки, вступ «спершу відповідь», списки й таблиці, факти й цифри, свіжість, title/description, OG.
 */
class CitabilityCheck extends BaseCheck
{
    /** Ролі сторінок, контент яких оцінюємо (contact / other - лише title й description). */
    const CONTENT_ROLES = ['home' => 1, 'article' => 1, 'service' => 1, 'about' => 1, 'product' => 1, 'category' => 1];
    const ROLE_LABELS = [
        'home' => 'Головна', 'article' => 'Стаття', 'service' => 'Послуга', 'about' => 'Про компанію',
        'product' => 'Товар', 'category' => 'Категорія', 'contact' => 'Контакти', 'other' => 'Інша сторінка',
    ];
    /** Вага сторінки в бленді: контентні сторінки важать більше за головну. */
    const ROLE_WEIGHT = ['home' => 1.0, 'article' => 2.0, 'service' => 1.5, 'about' => 1.2, 'product' => 2.0, 'category' => 1.0];
    /** Орієнтир обсягу (слова основного тексту; для товару - слова описових абзаців). */
    const MIN_WORDS = ['home' => 250, 'article' => 700, 'service' => 400, 'about' => 400, 'product' => 150, 'category' => 150];
    /** Скільки абзаців 40-150 слів очікуємо на сторінці. */
    const PASSAGE_TARGET = ['home' => 2, 'article' => 4, 'service' => 3, 'about' => 3];
    /** Ваги підоцінок у підсумковому балі. */
    const SUB_WEIGHTS = ['volume' => 15, 'passages' => 20, 'headings' => 15, 'answer_first' => 10, 'structure' => 10,
        'facts' => 10, 'freshness' => 8, 'meta' => 7, 'og' => 5];
    const SUB_LABELS = [
        'volume'       => 'Обсяг тексту',
        'passages'     => 'Абзаци-відповіді (40-150 слів)',
        'headings'     => 'Структура заголовків',
        'answer_first' => 'Короткий вступ після H1',
        'structure'    => 'Списки й таблиці',
        'facts'        => 'Конкретні факти й цифри',
        'freshness'    => 'Дата й свіжість статей',
        'meta'         => 'Title і meta description',
        'og'           => 'Open Graph',
    ];

    /**
     * H1 вікна перевірки віку / cookie-банера. Потрібен саме контекст питання про вік
     * («вам є 18», «виповнилося 18», «18 років?», «18+?»), а не будь-яке «18 років» («18 років досвіду»).
     */
    const RX_MODAL_H1 = '/((?<!\p{L})(вам|тобі|вы)\s+(вже\s+|уже\s+)?(є\s+|есть\s+|більше\s+|больше\s+|виповнил\S*\s+|исполнил\S*\s+)?(18|21)(?!\d)|виповнил\S*\s+(18|21)(?!\d)|исполнилось\s+(18|21)(?!\d)|(?<!\d)(18|21)\s*(років|рокiв|роки|лет|years(\s+old)?)\s*\?|(?<!\d)(18|21)\s*\+\s*\?|are\s+you\s+(over\s+|at\s+least\s+)?(18|21)\b|age\s+verification|підтвердіть\s+(свій\s+)?вік|подтвердите\s+(свой\s+)?возраст|підтвердіть\s+(своє\s+)?повноліття|\bcookie)/iu';
    /** Текст вікна віку / cookie (не рахуємо як контент). Той самий принцип: лише питання про вік. */
    const RX_MODAL_TEXT = '/(\bcookies?\b|кукі|(?<!\p{L})(вам|тобі|вы)\s+(вже\s+|уже\s+)?(є\s+|есть\s+|більше\s+|больше\s+|виповнил\S*\s+|исполнил\S*\s+)?(18|21)(?!\d)|виповнил\S*\s+(18|21)(?!\d)|исполнилось\s+(18|21)(?!\d)|(?<!\d)(18|21)\s*(років|рокiв|роки|лет|years(\s+old)?)\s*\?|підтвердіть\s+(своє\s+)?повноліття|досягли\s+повноліття\s*\?|совершеннолет|are\s+you\s+(over\s+)?(18|21)\b|age\s+verification)/iu';
    /** Клас / id контейнера спливного вікна (попап, модальне вікно, лайтбокс, форма-результат). */
    const RX_MODAL_CLASS = '/(?:^|[\s_-])(modal|popup|pop-up|mfp|fancybox|lightbox|result-form|age-gate|agegate|age-verification|age-check)(?:[\s_-]|$)/i';
    /** Посилання на джерело даних (рахується як перевірюваний факт). */
    const RX_SOURCE = '/(за\s+даними|згідно\s+з\s+(даними|дослідженн|звіт|рекомендац|опитуванн)|за\s+інформацією|за\s+оцінками|за\s+результатами\s+(дослідженн|опитуванн|аналіз)|опублікован\S*\s+(в|у)\s+(журнал|звіт|дослідженн)|according\s+to|a\s+study\s+(published|by)|по\s+данным|согласно\s+(данным|исследован))/iu';
    const RX_QUESTION = '/^(як|що|чому|навіщо|скільки|чи|коли|де|куди|звідки|хто|який|яка|яке|які|якого|яку|в\s+чому|у\s+чому|чим|для\s+чого|как|что|почему|зачем|сколько|когда|где|кто|какой|какая|какое|какие|чем|how|what|why|when|where|which|who)(\s|$)/iu';
    /** Заголовки-питання, що не несуть відповіді: відгуки, CTA, контакти. */
    const RX_NONINFO_HEAD = '/(говорять|кажуть|пишуть|думають|відгук|отзыв|reviews?|testimonials?|say\s+about|готові|хочете|бажаєте|залишил(ись|ися)\s+питання|є\s+питання|маєте\s+питання|остались\s+вопросы|есть\s+вопросы|ready\s+to|want\s+to|got\s+questions|questions\?|де\s+нас\s+знайти|як\s+(з\s+нами\s+)?зв\S*язатися|як\s+нас\s+знайти|як\s+доїхати)/iu';
    const RX_SPECS = '/(характеристик|специфікац|технічні\s+(дані|параметри)|параметри|specifications?|specs\b|характеристики|технические\s+характеристики)/iu';
    const RX_VAGUE = '/(якісн|професійн|найкращ|індивідуальн\S*\s+підх|унікальн|інноваційн|комплексн|високого\s+рівня|високої\s+якості|багаторічн|досвідчен|неперевершен|широк\S*\s+(асортимент|спектр|вибір)|найвищ|лідер|надійн\S*\s+партнер|вигідн\S*\s+(ціни|умови)|доступн\S*\s+ціни|quality|professional|best|unique|innovative|leading|качествен|лучш|профессиональн|уникальн|инновацион)/iu';
    /** Розмиті кількісні слова, де напрошується цифра. */
    const RX_HEDGE = '/(?<!\p{L})(зазвичай|часто|іноді|кілька|декілька|багато|значно|швидше|довше|чимало|деяких|деякі|тривалий\s+час|usually|often|several|many|significantly|faster|longer|обычно|несколько|много|значительно|быстрее|дольше)(?!\p{L})/iu';
    /** Інструкції / CTA, які не є вступом. */
    const RX_CTA_START = '/^(знайдіть|оберіть|виберіть|залиште|запишіться|записатися|зателефонуйте|дізнайтеся|перегляньте|замовте|отримайте|підпишіться|завантажте|встановіть|натисніть|find|choose|select|get|book|call|download|sign\s+up|subscribe|найдите|выберите|оставьте|запишитесь|позвоните|узнайте|закажите)(?!\p{L})/iu';
    /** Кандидат у телефонний номер; чи це справді телефон, вирішує isPhone(). */
    const RX_PHONE_CAND = '/\+?\(?\d[\d\s\-().]{7,}\d/u';

    const MONTHS = [
        'січня' => 1, 'лютого' => 2, 'березня' => 3, 'квітня' => 4, 'травня' => 5, 'червня' => 6, 'липня' => 7,
        'серпня' => 8, 'вересня' => 9, 'жовтня' => 10, 'листопада' => 11, 'грудня' => 12,
        'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6, 'июля' => 7,
        'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
        'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9,
        'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    const CHROME_TAGS = ['nav' => 1, 'header' => 1, 'footer' => 1, 'aside' => 1];
    const CHROME_ROLES = ['navigation' => 1, 'banner' => 1, 'contentinfo' => 1, 'complementary' => 1];
    const SKIP_ANCESTORS = ['script' => 1, 'style' => 1, 'noscript' => 1, 'template' => 1, 'svg' => 1, 'head' => 1,
        'select' => 1, 'button' => 1, 'form' => 1, 'iframe' => 1, 'object' => 1];
    const INLINE = ['a' => 1, 'span' => 1, 'b' => 1, 'strong' => 1, 'i' => 1, 'em' => 1, 'u' => 1, 'small' => 1,
        'mark' => 1, 'sup' => 1, 'sub' => 1, 'abbr' => 1, 'time' => 1, 'q' => 1, 'cite' => 1, 'code' => 1,
        'font' => 1, 'br' => 1, 'label' => 1, 'del' => 1, 'ins' => 1, 's' => 1, 'bdi' => 1, 'data' => 1, 'wbr' => 1];

    private int $now = 0;
    private string $siteType = 'corporate';
    private string $brand = '';
    /** Спрацювало no_content: бал обмежується, похвала структурі не виводиться. */
    private bool $noContent = false;
    /** Підпис ролі 'about': для особистого сайту експерта - «Про мене». */
    private static string $aboutLabel = 'Про компанію';

    public function id(): string { return 'citability'; }
    public function name(): string { return 'Цитованість контенту'; }
    public function weight(): float { return 0.20; }

    // ================================================================== run

    public function run(string $url, array $ctx): array
    {
        $home = $ctx['page'] ?? null;
        $access = (array)($ctx['access'] ?? []);
        if (!($home instanceof Page)) {
            $why = self::blockReasonUk((string)($access['block_reason'] ?? ''), (int)($access['homepage_status'] ?? 0));
            return $this->skipped(
                'Головна сторінка не віддала HTML нашому боту ні як боту, ні як браузеру' . ($why !== '' ? ' (' . $why . ')' : '')
                . ' - тексту для оцінки цитованості немає. Спершу відкрийте доступ (див. перевірку «Доступ AI-ботів та індексація»).',
                ['homepage_status' => (int)($access['homepage_status'] ?? 0), 'block_reason' => $access['block_reason'] ?? null]
            );
        }

        $this->noContent = false;
        $site = (array)($ctx['site'] ?? []);
        $this->siteType = (string)($site['type'] ?? 'corporate');
        self::$aboutLabel = $this->siteType === 'personal' ? 'Про мене' : self::ROLE_LABELS['about'];
        $this->brand = trim((string)($site['brand'] ?? ''));
        $this->now = self::responseTime((array)($ctx['homepage'] ?? []));

        // ---- сторінки
        $homeUrl = (string)($ctx['url'] ?? $url);
        $pages = [$this->analyzePage('home', $homeUrl, $home)];
        $metaOnly = [];
        $unavailable = [];
        $seen = [self::urlKey($homeUrl) => true];
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $role = (string)($s['role'] ?? 'other');
            if (!isset(self::ROLE_LABELS[$role])) $role = 'other';
            $sUrl = (string)(($s['res']['final_url'] ?? '') ?: ($s['url'] ?? ''));
            $sp = $s['page'] ?? null;
            if (!($sp instanceof Page)) {
                $unavailable[] = ['role' => $role, 'url' => (string)($s['url'] ?? $sUrl), 'reason' => self::sampleReasonUk($s)];
                continue;
            }
            $k = self::urlKey($sUrl);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            if (isset(self::CONTENT_ROLES[$role])) $pages[] = $this->analyzePage($role, $sUrl, $sp);
            else $metaOnly[] = ['role' => $role, 'url' => $sUrl, 'page' => $sp, 'label' => self::pageLabel($role, $sUrl)];
        }

        $findings = [];
        $passed = [];
        $sub = [];

        $this->evalVolume($pages, $sub, $findings, $passed);
        $this->evalPassages($pages, $sub, $findings, $passed);
        $this->evalHeadings($pages, $sub, $findings, $passed);
        $this->evalAnswerFirst($pages, $sub, $findings, $passed);
        $this->evalStructure($pages, $sub, $findings, $passed);
        $this->evalFacts($pages, $sub, $findings, $passed);
        $this->evalFreshness($pages, $unavailable, $sub, $findings, $passed);
        $this->evalMeta($pages, $metaOnly, $sub, $findings, $passed);
        $this->evalOg($pages[0], $pages, $metaOnly, $sub, $findings, $passed);

        // ---- підсумок
        $ordered = [];
        foreach (self::SUB_WEIGHTS as $k => $w) $ordered[$k] = array_key_exists($k, $sub) ? $sub[$k] : null;
        $comp = [];
        foreach ($ordered as $k => $v) $comp[] = [$v, self::SUB_WEIGHTS[$k]];
        $score = $this->scoreFrom($comp);
        // тексту для цитування немає: заповнені title / OG / прихований H1 не мають тягнути бал угору
        if ($this->noContent) $score = min($score, 15);

        $summary = $this->buildSummary($pages, $unavailable, $ordered);

        // стиль: довгі тире -> дефіс у тексті, який генерує перевірка (evidence / bad - дослівні значення з сайту)
        foreach ($findings as &$f) {
            foreach (['text', 'good', 'code', 'fix'] as $k) {
                if (isset($f[$k]) && is_string($f[$k])) $f[$k] = self::dashes($f[$k]);
            }
        }
        unset($f);
        $passed = array_map(function ($s) { return self::dashes((string)$s); }, $passed);
        $summary = self::dashes($summary);

        $dataPages = [];
        foreach ($pages as $a) $dataPages[] = $a['data'];
        foreach ($metaOnly as $m) {
            $dataPages[] = ['role' => $m['role'], 'url' => $m['url'], 'title_len' => mb_strlen($m['page']->title()),
                'desc_len' => mb_strlen($m['page']->metaDescription()), 'note' => 'оцінено лише title і description'];
        }
        $data = [
            'site_type'   => $this->siteType,
            'pages'       => $dataPages,
            'unavailable' => $unavailable,
            'sub_weights' => self::SUB_WEIGHTS,
            'role_weight' => self::ROLE_WEIGHT,
        ];

        return $this->result($score, $ordered, self::SUB_LABELS, $findings, $passed, $data, $summary);
    }

    // ================================================================== page analysis

    private function analyzePage(string $role, string $url, Page $p): array
    {
        $main = $p->mainText();
        $rawWords = $this->wc($main);
        $root = $this->mainRoot($p);

        // Текстові блоки основного контенту: без меню / футера, спливних вікон (попапи, форми, вік / cookie),
        // блоків, що складаються з посилань, і повторів (той самий текст у 10 картках рахуємо один раз).
        $blocks = []; $seenTxt = []; $dupWords = 0; $modalWords = 0; $statBlocks = [];
        foreach ($this->walkBlocks($p) as $b) {
            if ($b['in_nav']) continue;
            if ($main !== '' && strpos($main, $b['text']) === false) continue;
            if ($b['modal']) { $modalWords += $b['words']; continue; }
            $key = mb_strtolower($b['text']);
            if (isset($seenTxt[$key])) { $dupWords += $b['words']; continue; }
            $seenTxt[$key] = true;
            // пункти меню / списки посилань; але абзац-речення всередині картки-посилання (<a><p>...</p></a>) лишаємо
            if ($b['link_words'] / max(1, $b['words']) > 0.6 && ($b['words'] < 20 || !preg_match('/[.!?…]/u', $b['text']))) continue;
            if ($b['words'] < 12 && $this->isStatBlock($b['text'])) $statBlocks[] = $b['text'];
            if ($b['words'] < 8) continue;
            if ($b['words'] < 60 && preg_match(self::RX_MODAL_TEXT, $b['text'])) continue;
            if (!$this->isProse($b['text'], $b['words'])) continue;
            $blocks[] = $b;
        }
        $words = max(0, $rawWords - $dupWords - $modalWords);
        $descWords = 0; $proseWords = 0;
        foreach ($blocks as $b) {
            if ($b['words'] >= 12 || ($b['tag'] === 'li' && $b['words'] >= 5)) $descWords += $b['words'];
            if ($b['words'] >= 12 && $b['tag'] !== 'li') $proseWords += $b['words'];
        }
        if ($role === 'category') $descWords = $proseWords;

        // абзаци
        $p40 = 0; $p151 = 0; $short = []; $walls = []; $good = [];
        foreach ($blocks as $b) {
            if ($b['words'] > 250) $walls[] = $b;
            elseif ($b['words'] > 150) $p151++;
            elseif ($b['words'] >= 40) { $p40++; if (count($good) < 3) $good[] = $b; }
            elseif ($b['words'] >= 15 && count($short) < 3) $short[] = $b;
        }
        usort($walls, function ($x, $y) { return $y['words'] <=> $x['words']; });

        // заголовки
        $h = $p->headings();
        $h1all = $h['h1'];
        $h1 = array_values(array_filter($h1all, function ($t) { return $t !== ''; }));
        $h1modal = array_values(array_filter($h1, function ($t) { return (bool)preg_match(self::RX_MODAL_H1, $t); }));
        $h1real = array_values(array_filter($h1, function ($t) { return !preg_match(self::RX_MODAL_H1, $t); }));
        // H2-H4 зі спливних вікон (форма заявки, «Оберіть басейн:») не є структурою сторінки
        $modalHeads = [];
        foreach ($p->query('//body//*[self::h2 or self::h3 or self::h4]') as $hn) {
            if ($hn instanceof DOMElement && $this->inModal($hn)) $modalHeads[self::headKey($this->nodeText($p, $hn))] = true;
        }
        $keepHead = function ($t) use ($modalHeads) { return $t !== '' && !isset($modalHeads[self::headKey($t)]); };
        $h2 = array_values(array_filter($h['h2'], $keepHead));
        $h3 = array_values(array_filter($h['h3'], $keepHead));
        $h4 = array_values(array_filter($h['h4'], $keepHead));
        // заголовки-питання й заголовки-абзаци: без карток-посилань, CTA, блоків відгуків і попапів
        $questions = [];
        $longHeads = [];
        foreach ($p->query('//body//*[self::h2 or self::h3]') as $hn) {
            if (!($hn instanceof DOMElement) || $this->isHiddenOrSkipped($hn) || $this->inModal($hn)) continue;
            $t = $this->nodeText($p, $hn);
            if ($t === '') continue;
            if ($p->query('ancestor::a', $hn) || $this->linkShare($p, $hn, $t) > 0.8) continue;
            // заголовок-картка «назва + підзаголовок» (<span>eSIM</span><span>Ви можете...</span>): питання шукаємо лише в назві
            [$first, $rest] = $this->splitHeading($p, $hn);
            $qText = $first !== '' ? $first : $t;
            if ($this->wc($qText) <= 15 && $this->isQuestion($qText) && !preg_match(self::RX_NONINFO_HEAD, $qText)) $questions[] = $qText;
            if ($this->wc($t) > 20 && !isset($longHeads[$t])) $longHeads[$t] = ['tag' => strtolower($hn->nodeName), 'first' => $first, 'rest' => $rest];
        }
        $questions = array_values(array_unique($questions));

        $title = $p->title();
        $desc = $p->metaDescription();
        // чи вдалося перевірити, що H1 описує сторінку (порівняння з title потребує змістовного title)
        $h1Tested = count($h1real) === 1 && mb_strlen($title) >= 20;
        $h1Generic = $h1Tested && !$this->overlaps($h1real[0], $title)
            && ($desc === '' || !$this->overlaps($h1real[0], $desc));

        // вступ після H1
        $intro = null;
        $afApplicable = in_array($role, ['article', 'service', 'about'], true)
            || ($role === 'home' && !in_array($this->siteType, ['ecommerce', 'media'], true));
        if ($afApplicable && $h1real && $words >= 80) {
            $h1node = $this->findH1Node($p, $h1real[0]);
            if ($h1node) $intro = $this->introAfterH1($p, $h1node);
        }

        // списки / таблиці
        $struct = $root ? $this->structureCounts($p, $root) : ['lists' => 0, 'tables' => 0, 'dls' => 0];
        $specsHeading = '';
        foreach (array_merge($h2, $h3, $h4) as $t) {
            if ($this->wc($t) <= 6 && preg_match(self::RX_SPECS, $t)) { $specsHeading = $t; break; }
        }

        // факти
        $sentences = [];
        foreach ($blocks as $b) {
            if ($b['words'] < 12 && !($b['tag'] === 'li' && $b['words'] >= 6)) continue;
            foreach ($this->sentences($b['text']) as $s) {
                if ($this->wc($s) >= 6) $sentences[] = $s;
            }
        }
        $withNum = 0; $sources = 0;
        foreach ($sentences as $s) {
            if ($this->hasFact($s)) $withNum++;
            if (preg_match(self::RX_SOURCE, $s)) $sources++;
        }
        $statPrice = false;
        foreach ($statBlocks as $sb) if (preg_match('/₴|грн|uah|\$|€/iu', $sb)) { $statPrice = true; break; }

        // дати (лише статті)
        $dates = $role === 'article' ? $this->articleDates($p, $h1real[0] ?? '') : null;

        $a = [
            'role' => $role, 'url' => $url, 'page' => $p, 'label' => self::pageLabel($role, $url),
            'words' => $words, 'desc_words' => $descWords, 'blocks' => $blocks,
            'p40' => $p40, 'p151' => $p151, 'walls' => $walls, 'short' => $short, 'good' => $good,
            'h1all' => $h1all, 'h1' => $h1, 'h1real' => $h1real, 'h1modal' => $h1modal, 'h1_generic' => $h1Generic,
            'h1_tested' => $h1Tested,
            'h2' => $h2, 'h3' => $h3, 'questions' => $questions, 'long_heads' => $longHeads,
            'title' => $title, 'intro' => $intro, 'struct' => $struct, 'specs_heading' => $specsHeading,
            'sentences' => $sentences, 'with_num' => $withNum, 'sources' => $sources,
            'stat_blocks' => count($statBlocks), 'stat_examples' => array_slice($statBlocks, 0, 3), 'stat_price' => $statPrice,
            'dates' => $dates, 'root' => $root,
        ];
        $a['data'] = [
            'role'        => $role,
            'url'         => $url,
            'words_main'  => $rawWords,
            'words_unique' => $words,
            'dup_words'   => $dupWords,
            'popup_words' => $modalWords,
            'main_source' => $p->mainSource(),
            'desc_words'  => ($role === 'product' || $role === 'category') ? $descWords : null,
            'blocks_40_150' => $p40,
            'blocks_151_250' => $p151,
            'walls_250'   => count($walls),
            'h1'          => array_slice($h1, 0, 3),
            'h2_count'    => count($h2),
            'question_headings' => count($questions),
            'intro_words' => $intro && $intro['type'] === 'p' ? $intro['words'] : null,
            'intro_type'  => $intro ? $intro['type'] : null,
            'lists'       => $struct['lists'],
            'tables'      => $struct['tables'] + $struct['dls'],
            'sentences'   => count($sentences),
            'fact_share'  => $sentences ? round($withNum / count($sentences), 2) : null,
            'stat_blocks' => count($statBlocks),
            'date'        => $dates && $dates['latest'] ? $dates['latest_day'] : null,
            'title_len'   => mb_strlen($title),
            'desc_len'    => mb_strlen($p->metaDescription()),
        ];
        return $a;
    }

    // ================================================================== 1. обсяг

    private function evalVolume(array $pages, array &$sub, array &$findings, array &$passed): void
    {
        $comp = []; $lines = []; $ok = []; $sev = null; $worst = null; $worstRatio = []; $jsThin = false;
        foreach ($pages as $a) {
            $role = $a['role'];
            $min = self::MIN_WORDS[$role];
            $w = ($role === 'product' || $role === 'category') ? $a['desc_words'] : $a['words'];
            $ratio = $w / $min;
            $comp[] = [min(100, $ratio * 100), self::ROLE_WEIGHT[$role]];
            if ($w >= $min) {
                $ok[] = mb_strtolower($a['label']) . ' - ' . $w . ' сл.';
                continue;
            }
            // у браузері тексту може бути більше: частину домальовує JavaScript із даних фреймворку
            $jsW = (int)$a['page']->jsOnlyText()['words'];
            $jsNote = $jsW >= 50 ? ' (ще ' . self::nw($jsW) . ' є лише в даних JavaScript: у браузері їх видно, а AI-боти їх не отримують)' : '';
            if ($jsNote !== '') $jsThin = true;
            if ($role === 'product') $lines[] = $a['label'] . ': опис товару - ' . self::nw($w) . ' (абзаци від 12 слів, без характеристик і «схожих товарів»), орієнтир - від ' . $min . $jsNote;
            elseif ($role === 'category') $lines[] = $a['label'] . ': описовий текст - ' . self::nw($w) . ' (без назв і цін товарів, фільтрів, меню), орієнтир - від ' . $min . $jsNote;
            else $lines[] = $a['label'] . ': основний текст - ' . self::nw($w) . ', орієнтир - від ' . $min . $jsNote;
            if ($role === 'home') $s = $w < 100 ? 'high' : 'medium';
            elseif ($role === 'category') $s = 'low';
            else $s = $ratio < 0.5 ? 'medium' : 'low';
            $sev = self::maxSev($sev, $s);
            // приклад: найсерйозніша сторінка, далі - за важливістю ролі, далі - найменший обсяг
            $prio = ['product' => 0, 'article' => 1, 'service' => 2, 'about' => 3, 'home' => 4, 'category' => 5];
            $rank = [self::sevRank($s), -($prio[$role] ?? 9), -$ratio];
            if ($worst === null || $rank > $worstRatio) { $worstRatio = $rank; $worst = $a; }
        }
        $sub['volume'] = $this->scoreFrom($comp);

        // сайт майже без тексту
        $maxWords = 0;
        foreach ($pages as $a) $maxWords = max($maxWords, $a['words']);
        if ($pages[0]['words'] < 30 && $maxWords < 100) {
            $this->noContent = true;
            $a = $pages[0];
            $p = $a['page'];
            $fw = $p->frameworkMarkers();
            $js = $p->emptyAppRoot() || $fw;
            $visW = $p->wordCount();
            $hiddenH1 = false;
            foreach ($p->query('//body//h1') as $hn) {
                if ($hn instanceof DOMElement && preg_match('/(visually-hidden|sr-only|screen-reader|visuallyhidden|hidden)/i', $hn->getAttribute('class'))) $hiddenH1 = true;
            }
            if ($a['words'] === 0) {
                $homeEv = 'Головна: основного тексту в HTML-відповіді сервера немає'
                    . ($visW > 0 ? ' - лише ' . self::nw($visW) . ' у шапці чи службових елементах' . (!empty($a['h1'][0]) ? ' (' . ($hiddenH1 ? 'візуально прихований ' : '') . 'H1 ' . self::qt($a['h1'][0]) . ')' : '') : '') . '.';
            } else {
                $homeEv = 'Головна: лише ' . self::nw($a['words']) . ' основного тексту в HTML-відповіді сервера.';
            }
            $findings[] = $this->finding('no_content', 'critical', 'На сайті майже немає тексту, який AI міг би процитувати', [
                'evidence' => $homeEv
                    . (count($pages) > 1 ? ' Інші перевірені сторінки - не більше ' . self::nw($maxWords) . '.' : ' Внутрішніх сторінок з текстом у вибірці немає.')
                    . ($js ? ' Ознаки клієнтського рендерингу: ' . ($fw ? implode(', ', $fw) : 'порожній корінь застосунку') . '.' : ''),
                'why' => 'AI-пошуковики цитують текст, який отримують у HTML. Якщо тексту немає, сайт може потрапити у відповідь хіба що за назвою, без пояснення, що він робить.',
                'fix' => $js
                    ? 'Віддавайте основний текст у HTML (SSR / пререндер) і додайте на головну 2-3 абзаци про продукт: що це, для кого, чим відрізняється.'
                    : 'Додайте на головну 2-3 абзаци по 40-120 слів: що це за компанія чи продукт, для кого, ключові факти й цифри.',
                'bad' => $a['words'] > 0 ? 'Весь текст головної: «' . self::mbTrunc($p->mainText(), 300) . '»'
                    : 'У HTML головної немає основного тексту' . (!empty($a['h1'][0]) ? ' - лише ' . ($hiddenH1 ? 'візуально прихований ' : '') . '<h1>' . self::mbTrunc($a['h1'][0], 80) . '</h1>' : '') . ($p->emptyAppRoot() ? ' і порожній контейнер застосунку, який наповнює JavaScript' : '') . '.',
                'good' => $this->volumeGood('home', $a),
                'verify' => 'curl -s ' . $a['url'] . ' | grep -c "<p" - абзаци мають бути в сирому HTML. Google Rich Results Test → «Переглянути перевірену сторінку» → HTML показує код уже після виконання JavaScript, тобто лише те, що бачить Google.',
                'effort' => $js ? 'L' : 'M',
            ]);
            return;
        }

        if ($lines) {
            $a = $worst ?: $pages[0];
            $p = $a['page'];
            if ($a['role'] === 'category') {
                $bad = 'Категорія ' . self::qt($this->pageTopic($a)) . ': ' . ($a['desc_words'] > 0 ? 'описового тексту лише ' . self::nw($a['desc_words']) : 'під H1 одразу фільтри й товари, описового тексту немає') . '.';
            } elseif ($a['role'] === 'product') {
                $desc = [];
                foreach ($a['blocks'] as $b) if ($b['words'] >= 12) $desc[] = $b['text'];
                $bad = $desc ? 'Опис товару ' . self::qt($this->pageTopic($a)) . ' повністю: «' . self::mbTrunc(self::innerQ(implode(' / ', $desc)), 300) . '»'
                    : 'На картці ' . self::qt($this->pageTopic($a)) . ' немає жодного описового абзацу - лише назва, ціна й кнопки.';
            } else {
                $txt = [];
                foreach ($a['blocks'] as $b) if ($b['words'] >= 8) $txt[] = $b['text'];
                $bad = $txt
                    ? $a['label'] . ' (' . self::nw($a['words']) . ' без повторів, разом із підписами), усі текстові абзаци сторінки: «' . self::mbTrunc(self::innerQ(implode(' / ', $txt)), 300) . '»'
                    : $a['label'] . ' (' . self::nw($a['words']) . '): жодного текстового абзацу від 8 слів - лише заголовки, підписи, кнопки й посилання.';
            }
            $findings[] = $this->finding('thin_content', $sev ?? 'low', 'Замало тексту для цитування на ' . (count($lines) === 1 ? 'сторінці' : count($lines) . ' сторінках'), [
                'evidence' => implode("\n", $lines),
                'why' => 'AI-асистент цитує сторінку, коли знаходить на ній повну відповідь на питання. На коротких сторінках такої відповіді немає, і асистент бере текст у конкурентів.',
                'fix' => ($jsThin ? 'Спершу віддайте в HTML текст, який зараз домальовує JavaScript (див. категорію «Рендеринг та індексованість»): він уже написаний, просто ботам не видно. ' : '')
                    . 'Розширте сторінки до орієнтиру змістовним текстом (не водою): що це, для кого, як працює, ціни чи терміни, конкретні цифри, відповіді на типові питання.',
                'bad' => $bad,
                'good' => $this->volumeGood($a['role'], $a),
                'verify' => 'Порахуйте слова основного тексту (без меню й футера) у Detailed SEO Extension або Screaming Frog (Word Count); повторний аудит.',
                'effort' => 'M',
            ]);
        }
        if ($ok) $passed[] = 'Достатній обсяг тексту: ' . self::listSome($ok, 4);
    }

    private function volumeGood(string $role, array $a): string
    {
        $topic = $this->pageTopic($a);
        $brand = $this->brand !== '' ? $this->brand : 'компанія';
        switch ($role) {
            case 'product':
                return 'Опис ' . self::qt($topic) . ' на 150-300 слів: для кого і для чого товар, ключові характеристики словами, чим відрізняється від схожих, комплектація, гарантія й доставка. Плюс 3-5 питань-відповідей покупців.';
            case 'article':
                return 'Стаття від 700 слів: коротка відповідь на головне питання одразу після H1, 4-6 розділів H2, приклади й цифри з джерелами, висновок.';
            case 'service':
                return self::qt($topic) . ': що входить (список), етапи й терміни, від чого залежить ціна або «ціна від», 1-2 кейси з цифрами, 3-5 питань-відповідей.';
            case 'about':
                return 'Хто такі ' . $brand . ' (1-2 речення з роком заснування і містом), цифри (роки на ринку, клієнти, команда), ліцензії й нагороди, команда з іменами й посадами.';
            case 'category':
                return 'Під списком товарів 150-300 слів: що є в категорії, як обрати, популярні бренди й ціновий діапазон, доставка.';
            default:
                return '2-3 абзаци по 40-120 слів: що таке ' . $brand . ', для кого, чим відрізняється, ключові цифри (роки, клієнти, географія).';
        }
    }

    // ================================================================== 2. абзаци-відповіді

    private function evalPassages(array $pages, array &$sub, array &$findings, array &$passed): void
    {
        $comp = []; $okList = []; $none = []; $wallPages = [];
        $anyBlock = false;
        foreach ($pages as $a) {
            $role = $a['role'];
            if ($a['p40'] + $a['p151'] + count($a['walls']) > 0) $anyBlock = true;
            if (!isset(self::PASSAGE_TARGET[$role]) || ($role === 'home' && $this->siteType === 'media')) {
                if ($a['walls']) $wallPages[] = $a;
                continue;
            }
            $t = self::PASSAGE_TARGET[$role];
            $nw = count($a['walls']);
            $s = min(100, ($a['p40'] + 0.5 * $a['p151'] + 0.25 * $nw) / $t * 100) - 10 * $nw;
            $comp[] = [max(0, $s), self::ROLE_WEIGHT[$role]];
            if ($a['p40'] > 0) $okList[] = mb_strtolower($a['label']) . ' - ' . $a['p40'];
            if ($a['p40'] + $a['p151'] + $nw === 0) $none[] = $a;
            if ($a['walls']) $wallPages[] = $a;
        }
        $sub['passages'] = $comp ? $this->scoreFrom($comp) : null;

        $hasNoContent = false;
        foreach ($findings as $f) if ($f['id'] === 'citability.no_content') $hasNoContent = true;

        // немає жодного змістовного абзацу
        if ($none && !$hasNoContent) {
            $contentNone = array_values(array_filter($none, function ($a) { return $a['role'] !== 'home'; }));
            if (!$anyBlock) $sev = 'high';
            elseif ($contentNone) $sev = 'medium';
            else $sev = 'low';
            $lines = [];
            foreach ($none as $a) {
                $lines[] = $a['label'] . ': жодного абзацу від 40 слів' . ($a['short'] ? ', найдовші - ' . implode(', ', array_map(function ($b) { return $b['words']; }, $a['short'])) . ' ' . self::plural((int)end($a['short'])['words'], 'слово', 'слова', 'слів') : '');
            }
            $ex = $contentNone ? $contentNone[0] : $none[0];
            $shortB = $ex['short'][0] ?? null;
            $bad = $shortB
                ? $ex['label'] . ', один з найдовших абзаців (' . self::nw($shortB['words']) . '): «' . self::mbTrunc(self::innerQ($shortB['text']), 300) . '»'
                : $ex['label'] . ': текст складається з коротких підписів, кнопок і назв карток, абзаців від 15 слів немає.';
            $good = $shortB
                ? '«' . self::mbTrunc(self::innerQ(self::dropCutTail($shortB['text'])), 330) . ' [ВКАЖІТЬ 1-2 речення з конкретикою: цифра, термін, приклад, для кого це]» - абзац на 40-120 слів, зрозумілий без решти сторінки.'
                : 'Абзац на 40-120 слів, що відповідає на одне питання: «' . ($this->brand !== '' ? $this->brand : '[Назва]') . ' - [ВКАЖІТЬ, що ви робите і для кого]. [ВКАЖІТЬ ключовий факт: роки, кількість клієнтів, географія]. [ВКАЖІТЬ, чим відрізняєтесь]».';
            $findings[] = $this->finding('no_passages', $sev, $anyBlock ? 'На частині перевірених сторінок немає самодостатніх абзаців-відповідей' : 'На перевірених сторінках немає самодостатніх абзаців-відповідей', [
                'evidence' => implode("\n", $lines),
                'why' => 'AI-пошуковики розбивають сторінку на фрагменти й цитують ті, що відповідають на питання повністю. Короткі підписи й назви карток такими фрагментами не є.',
                'fix' => 'Додайте блоки по 40-150 слів, кожен відповідає на одне питання (що це, для кого, як працює, скільки коштує) і зрозумілий без контексту решти сторінки.',
                'bad' => $bad,
                'good' => $good,
                'verify' => 'Скопіюйте абзац окремо і перевірте, чи зрозуміло з нього, про що мова і про яку компанію; повторний аудит покаже кількість абзаців 40-150 слів.',
                'effort' => 'M',
            ]);
        }

        // «стіна тексту»
        if ($wallPages) {
            $lines = [];
            foreach ($wallPages as $a) {
                $ws = array_map(function ($b) { return $b['words'] . ' сл. (<' . $b['tag'] . '>)'; }, array_slice($a['walls'], 0, 3));
                $lines[] = $a['label'] . ': ' . count($a['walls']) . ' ' . self::plural(count($a['walls']), 'блок', 'блоки', 'блоків') . ' понад 250 слів - ' . implode(', ', $ws);
            }
            $wb = $wallPages[0]['walls'][0];
            $paras = $this->splitParagraphs($wb['text'], 90);
            $good = "<h3>[ВКАЖІТЬ підзаголовок-питання]</h3>\n";
            $shown = 0;
            foreach ($paras as $pp) {
                if ($shown >= 2) break;
                $good .= '<p>' . self::mbTrunc($pp, 210) . "</p>\n";
                $shown++;
            }
            if (count($paras) > 2) $good .= '<p>... ще ' . (count($paras) - 2) . ' ' . self::plural(count($paras) - 2, 'абзац', 'абзаци', 'абзаців') . ' по 40-100 слів</p>';
            if (count($paras) < 2) $good = "Розбийте блок на 3-5 абзаців по 40-120 слів, кожен з власною думкою, а над ними поставте підзаголовок-питання <h3>.";
            $findings[] = $this->finding('wall_of_text', 'medium', 'Довгі суцільні блоки тексту («стіна тексту»)', [
                'evidence' => implode("\n", $lines) . ($wb['tag'] !== 'p' ? "\nНайбільший блок - у тегу <" . $wb['tag'] . '>, тобто абзаци, ймовірно, розділені лише <br> або не розділені зовсім.' : ''),
                'why' => 'Блок понад 250 слів AI ріже на шматки довільно, і цитата виходить вирваною з контексту. Короткі абзаци з однією думкою цитуються цілими.',
                'fix' => 'Розбийте такі блоки на абзаци по 40-120 слів (окремі теги <p>), кожен починайте з головної тези; між смисловими частинами додайте підзаголовки H2/H3.',
                'bad' => $wallPages[0]['label'] . ', блок на ' . self::nw($wb['words']) . ': «' . self::mbTrunc(self::innerQ($wb['text']), 300) . '»',
                'good' => $good,
                'verify' => 'У DevTools перевірте, що текст розбитий на окремі <p>; повторний аудит - блоків понад 250 слів не має бути.',
                'effort' => 'S',
            ]);
        }

        if ($okList) $passed[] = 'Абзаци-відповіді на 40-150 слів: ' . self::listSome($okList, 4);
    }

    // ================================================================== 3. заголовки

    private function evalHeadings(array $pages, array &$sub, array &$findings, array &$passed): void
    {
        $comp = [];
        $noH1 = []; $multi = []; $modal = []; $generic = []; $fewH2 = []; $long = [];
        $allQuestions = []; $qApplicable = false; $qPagesChecked = 0; $headSample = []; $hasArticle = false;
        $okH1 = 0;
        foreach ($pages as $a) {
            $role = $a['role'];
            if ($role === 'article') $hasArticle = true;
            $nReal = count($a['h1real']);
            $distinct = array_values(array_unique(array_map('mb_strtolower', $a['h1real'])));
            // H1
            if ($nReal === 1) $h1c = $a['h1_generic'] ? 60 : 100;
            elseif ($nReal > 1) $h1c = count($distinct) === 1 ? 75 : 50;
            else $h1c = 0;
            if ($a['h1modal']) {
                $modal[] = $a;
                if ($nReal === 0) $h1c = 0;
            } elseif ($nReal === 0) {
                $noH1[] = $a;
            }
            if ($nReal > 1) $multi[] = ['a' => $a, 'same' => count($distinct) === 1];
            if ($nReal === 1 && !$a['h1modal'] && !$a['h1_generic']) $okH1++;
            if ($nReal === 1 && $a['h1_generic']) $generic[] = $a;
            // H2
            $h2c = null;
            if (in_array($role, ['article', 'service', 'about'], true) || ($role === 'home' && $a['words'] >= 400)) {
                $n2 = count($a['h2']);
                $h2c = $n2 >= 2 ? 100 : ($n2 === 1 ? 50 : 0);
                if ($n2 < 2 && $role !== 'home') $fewH2[] = $a;
            }
            // питання
            $qc = null;
            if (in_array($role, ['article', 'service', 'about'], true) || ($role === 'home' && $a['words'] >= 400 && $this->siteType !== 'media')) {
                $qApplicable = true;
                $qPagesChecked++;
                $qc = $a['questions'] ? 100 : 30;
                foreach (array_slice(array_merge($a['h2'], $a['h3']), 0, 6) as $t) $headSample[] = $t;
            } elseif ($role === 'product' && $a['questions']) {
                $qc = 100;
            }
            foreach ($a['questions'] as $q) $allQuestions[$q] = true;
            if ($a['long_heads']) $long[] = $a;
            $comp[] = [$this->scoreFrom([[$h1c, 5], [$h2c, 3], [$qc, 2]]), self::ROLE_WEIGHT[$role]];
        }
        $sub['headings'] = $this->scoreFrom($comp);

        // --- H1 відсутній / кілька H1
        if ($noH1 || $multi) {
            $lines = []; $sev = null;
            foreach ($noH1 as $a) {
                $empty = count($a['h1all']) > 0;
                $first = $a['h2'] ? ' (перший заголовок - H2 «' . self::mbTrunc($a['h2'][0], 80) . '»)' : '';
                $lines[] = $a['label'] . ': ' . ($empty ? 'тег <h1> є, але порожній (без тексту)' : '<h1> немає') . $first;
                $sev = self::maxSev($sev, 'high');
            }
            foreach ($multi as $m) {
                $a = $m['a'];
                $lines[] = $a['label'] . ': ' . count($a['h1real']) . ' H1 - «' . implode('», «', array_map(function ($t) { return self::mbTrunc($t, 70); }, array_slice($a['h1real'], 0, 4))) . '»' . ($m['same'] ? ' (однаковий текст, ймовірно мобільна і десктопна версії)' : '');
                $sev = self::maxSev($sev, $m['same'] ? 'low' : 'medium');
            }
            if ($noH1) {
                $a = $noH1[0];
                $heads = [];
                foreach (array_slice($a['h2'], 0, 3) as $t) $heads[] = '<h2>' . self::mbTrunc($t, 80) . '</h2>';
                $bad = $heads ? implode("\n", $heads) . "\n(H1 на сторінці немає)" : 'На сторінці «' . $a['label'] . '» немає жодного <h1>.';
                $sugg = $this->suggestH1($a);
                $good = '<h1>' . $sugg . '</h1>';
                $code = $good;
            } else {
                $a = $multi[0]['a'];
                $bad = implode("\n", array_map(function ($t) { return '<h1>' . self::mbTrunc($t, 90) . '</h1>'; }, array_slice($a['h1real'], 0, 3)));
                $good = '<h1>' . self::mbTrunc($a['h1real'][0], 90) . "</h1>\n" . implode("\n", array_map(function ($t) { return '<h2>' . self::mbTrunc($t, 90) . '</h2>'; }, array_slice($a['h1real'], 1, 2)));
                $code = '';
            }
            $findings[] = $this->finding('h1', $sev ?? 'medium', $noH1 ? 'Сторінки без заголовка H1' : 'Кілька заголовків H1 на сторінці', [
                'evidence' => implode("\n", $lines),
                'why' => 'H1 - головна підказка для AI, про що сторінка і яку відповідь вона дає. Без H1 або з кількома H1 модель гірше визначає тему фрагментів.',
                'fix' => $noH1 ? 'Додайте один H1 з темою сторінки (назва послуги, товару, статті); декоративні заголовки оформіть як H2 або <p>.' : 'Залиште один H1 з темою сторінки, решту заголовків переведіть в H2/H3 або звичайний текст.',
                'bad' => $bad,
                'good' => $good,
                'code' => $code,
                'verify' => 'У DevTools виконайте document.querySelectorAll("h1").length - має бути 1; або Detailed SEO Extension → вкладка Headings.',
                'effort' => 'S',
            ]);
        }

        // --- H1 вікна перевірки віку / cookie
        if ($modal) {
            $lines = []; $sev = 'medium';
            foreach ($modal as $a) {
                if (!$a['h1real']) {
                    $real = $this->realTitleHeading($a);
                    $lines[] = $a['label'] . ': єдиний H1 - «' . $a['h1modal'][0] . '»' . ($real !== '' ? '; справжня назва сторінки стоїть у H2 «' . self::mbTrunc($real, 80) . '»' : '');
                    if ($a['role'] !== 'about') $sev = 'high';
                } else {
                    $lines[] = $a['label'] . ': поруч зі справжнім H1 «' . self::mbTrunc($a['h1real'][0], 80) . '» є другий H1 - «' . $a['h1modal'][0] . '»';
                }
            }
            $a = $modal[0];
            $realName = $a['h1real'][0] ?? $this->realTitleHeading($a);
            if ($realName === '') $realName = $this->suggestH1($a);
            $code = "<div class=\"age-gate\">\n  <p class=\"age-gate__title\">" . $a['h1modal'][0] . "</p>\n  <!-- кнопки вікна без змін -->\n</div>\n\n<h1>" . self::mbTrunc($realName, 120) . "</h1>";
            $findings[] = $this->finding('h1_modal', $sev, 'Заголовок H1 зайнятий вікном перевірки віку або cookie-банером', [
                'evidence' => implode("\n", $lines),
                'why' => 'AI-боти читають HTML без кліків, тож вікно віку для них - частина сторінки. Якщо в ньому H1, головною темою сторінки модель вважає питання про вік, а не товар чи розділ.',
                'fix' => 'У шаблоні вікна замініть <h1> на <p> або <div> (стилі збережіть класом), а H1 сторінки зробіть з назви товару, категорії чи компанії.',
                'bad' => '<h1>' . $a['h1modal'][0] . '</h1>' . ($a['h1real'] ? '' : "\n" . ($this->realTitleHeading($a) !== '' ? '<h2>' . self::mbTrunc($this->realTitleHeading($a), 90) . '</h2>' : '')),
                'good' => '<p class="age-gate__title">' . $a['h1modal'][0] . "</p>\n<h1>" . self::mbTrunc($realName, 100) . '</h1>',
                'code' => $code,
                'verify' => 'curl -s ' . $a['url'] . ' | grep -o "<h1[^>]*>[^<]*" - має бути один H1 з назвою сторінки.',
                'effort' => 'S',
            ]);
        }

        // --- H1 не описує сторінку
        if ($generic) {
            $lines = []; $sev = 'low';
            foreach ($generic as $a) {
                $lines[] = $a['label'] . ': H1 ' . self::qt(self::mbTrunc($a['h1real'][0], 90)) . ' не має спільних слів ні з title ' . self::qt(self::mbTrunc($a['title'], 90)) . ', ні з meta description';
                if (in_array($a['role'], ['article', 'service', 'product', 'category'], true)) $sev = 'medium';
            }
            $a = $generic[0];
            $sugg = $this->suggestH1($a);
            $findings[] = $this->finding('h1_generic', $sev, 'H1 не називає тему сторінки (слоган або загальне слово)', [
                'evidence' => implode("\n", $lines),
                'why' => 'Для AI H1 - коротка відповідь на питання «про що ця сторінка». Слоган чи «Головна» не дають ні назви послуги, ні категорії, тому фрагменти сторінки гірше зіставляються із запитами.',
                'fix' => 'Сформулюйте H1 як тему сторінки (що ви пропонуєте і де); слоган залиште підзаголовком у <p>.',
                'bad' => '<h1>' . self::mbTrunc($a['h1real'][0], 100) . "</h1>\n(title: " . self::qt(self::mbTrunc($a['title'], 120)) . ')',
                'good' => '<h1>' . $sugg . '</h1>' . ($this->wc($a['h1real'][0]) >= 3 ? "\n<p class=\"slogan\">" . self::mbTrunc($a['h1real'][0], 100) . '</p>' : ''),
                'code' => '<h1>' . $sugg . '</h1>',
                'verify' => 'Detailed SEO Extension → Headings: H1 має містити ключову тему сторінки.',
                'effort' => 'S',
            ]);
        }

        // --- мало H2 на контентних сторінках
        if ($fewH2) {
            $lines = []; $sev = 'low';
            foreach ($fewH2 as $a) {
                $lines[] = $a['label'] . ': H2 - ' . count($a['h2']) . ($a['h2'] ? ' (' . self::qt(self::mbTrunc($a['h2'][0], 70)) . ')' : '') . ', текст - ' . self::nw($a['words']);
                if ($a['words'] >= 300) $sev = 'medium';
            }
            $a = $fewH2[0];
            $findings[] = $this->finding('few_h2', $sev, 'Контентні сторінки без розділів H2', [
                'evidence' => implode("\n", $lines),
                'why' => 'Підзаголовки H2 ділять текст на теми. AI-пошуковики вибирають фрагмент під конкретне питання саме за підзаголовками, тож без них сторінка виглядає одним неподільним шматком.',
                'fix' => 'Розбийте текст на 3-6 розділів з H2, кожен про одну тему; підзаголовки формулюйте як тему або питання.',
                'bad' => $a['h2'] ? '<h1>' . self::mbTrunc($a['h1real'][0] ?? '', 80) . "</h1>\n<h2>" . self::mbTrunc($a['h2'][0], 80) . '</h2>' : '<h1>' . self::mbTrunc($a['h1real'][0] ?? '(немає)', 80) . "</h1>\n(далі " . self::nw($a['words']) . ' без жодного H2)',
                'good' => $this->h2Outline($a),
                'verify' => 'Detailed SEO Extension → Headings: на сторінці щонайменше 2-3 H2.',
                'effort' => 'S',
            ]);
        }

        // --- заголовки-абзаци
        if ($long) {
            $lines = []; $ex = null; $exIntro = null;
            foreach ($long as $a) {
                $introHead = ($a['intro'] && $a['intro']['type'] === 'heading') ? $a['intro']['next'] : '';
                foreach (array_slice($a['long_heads'], 0, 2, true) as $t => $info) {
                    $t = (string)$t;
                    $lines[] = $a['label'] . ': ' . strtoupper($info['tag']) . ' на ' . self::nw($this->wc($t)) . ' - «' . self::mbTrunc(self::innerQ($t), 110) . '»';
                    $isIntro = $introHead !== '' && $introHead === $t;
                    // для прикладу беремо заголовок, що не є вступом після H1 (той розібрано в answer_first)
                    if ($isIntro) { if ($exIntro === null) $exIntro = ['t' => $t] + $info; }
                    elseif ($ex === null) $ex = ['t' => $t] + $info;
                }
            }
            $useIntro = $ex === null;
            if ($useIntro) $ex = $exIntro;
            $tag = $ex['tag'];
            $bad = '<' . $tag . '>' . self::mbTrunc($ex['t'], 250) . '</' . $tag . '>';
            if ($useIntro) {
                // той самий заголовок стоїть одразу після H1: він і має стати вступним абзацом
                $good = '<p>' . self::mbTrunc($ex['t'], 250) . '</p>' . "\n(це перший блок після H1 - зробіть його вступним абзацом, без окремого заголовка)";
            } elseif ($ex['first'] !== '' && $this->wc($ex['first']) <= 12) {
                $bad = '<' . $tag . '><span>' . self::mbTrunc($ex['first'], 80) . '</span><span>' . self::mbTrunc($ex['rest'], 200) . '</span></' . $tag . '>';
                $good = '<' . $tag . '>' . self::mbTrunc($ex['first'], 80) . '</' . $tag . ">\n<p>" . self::mbTrunc($ex['rest'], 250) . '</p>';
            } else {
                $good = '<' . $tag . '>[ВКАЖІТЬ коротку тему до 10 слів]</' . $tag . ">\n<p>" . self::mbTrunc($ex['t'], 250) . '</p>';
            }
            $findings[] = $this->finding('long_headings', 'low', 'Заголовки H2/H3 використано як абзаци тексту', [
                'evidence' => implode("\n", array_slice($lines, 0, 5)),
                'why' => 'Заголовок на 20+ слів AI сприймає як назву розділу, а не як текст відповіді, тож цей зміст гірше цитується і розмиває структуру сторінки.',
                'fix' => 'Залиште в H2/H3 коротку тему (до 10 слів), а пояснення перенесіть в абзац <p> під заголовком. Якщо в заголовку вже є коротка назва і підзаголовок, назву залиште в H2/H3, а підзаголовок зробіть <p>.',
                'bad' => $bad,
                'good' => $good,
                'verify' => 'Detailed SEO Extension → Headings: жоден заголовок не довший за 10-12 слів.',
                'effort' => 'S',
            ]);
        }

        // --- заголовки-питання
        if ($qApplicable && !$allQuestions) {
            // у новинах підзаголовки-питання не обов'язкові, тож для медіа - лише порада
            $sev = ($hasArticle && $this->siteType !== 'media') ? 'medium' : 'low';
            $target = null;
            foreach (['service', 'article', 'about'] as $want) {
                foreach ($pages as $a) if ($a['role'] === $want) { $target = $a; break 2; }
            }
            if (!$target) $target = $pages[0];
            $qs = $this->questionIdeas($target);
            $code = "<section class=\"faq\">\n  <h2>Питання та відповіді</h2>\n";
            foreach ($qs as $q) $code .= '  <h3>' . $q . "</h3>\n  <p>[ВКАЖІТЬ відповідь на 40-80 слів: перше речення - пряма відповідь, далі деталі й цифри]</p>\n";
            $code .= '</section>';
            $badH = array_slice(array_values(array_unique($headSample)), 0, 4);
            $findings[] = $this->finding('no_question_headings', $sev, 'Немає заголовків у формі питань', [
                'evidence' => 'Перевірено H2/H3 на ' . $qPagesChecked . ' ' . self::plural($qPagesChecked, 'сторінці', 'сторінках', 'сторінках') . ' - жодного у формі питання (зі знаком «?» або «Як / Що / Чому / Скільки...»).'
                    . ($badH ? "\nПриклади поточних заголовків: " . implode(', ', array_map(function ($t) { return self::qt(self::mbTrunc($t, 60)); }, $badH)) : ''),
                'why' => 'Люди питають AI-асистентів питаннями. Підзаголовок-питання з короткою відповіддю під ним - цілісний фрагмент, який зручно процитувати у відповіді ChatGPT чи Perplexity.',
                'fix' => 'Додайте на ключові сторінки 3-5 підзаголовків-питань із реальних запитів клієнтів (People Also Ask, питання менеджерам) і відповіді на 40-80 слів під кожним.',
                'bad' => $badH ? implode("\n", array_map(function ($t) { return '<h2>' . self::mbTrunc($t, 80) . '</h2>'; }, array_slice($badH, 0, 3))) : 'Заголовків H2/H3 немає.',
                'good' => implode("\n", array_map(function ($q) { return '<h2>' . $q . '</h2>'; }, $qs)),
                'code' => $code,
                'verify' => 'Через 3-4 тижні після публікації задайте ці питання в Perplexity / ChatGPT search і перевірте, чи з\'являється посилання на сайт.',
                'effort' => 'M',
            ]);
        }

        // passed
        $n = count($pages);
        if ($okH1 === $n && !$this->noContent) {
            // «змістовний» пишемо лише там, де H1 справді звірено з title (title від 20 символів)
            $tested = 0;
            foreach ($pages as $a) if ($a['h1_tested']) $tested++;
            if ($n === 1) {
                $passed[] = 'На головній рівно один H1' . ($tested === 1 ? ', що збігається з темою title' : '') . ': ' . self::qt(self::mbTrunc($pages[0]['h1real'][0], 80));
            } else {
                $passed[] = 'Рівно один H1 на всіх ' . $n . ' перевірених сторінках' . ($tested === $n ? ', і кожен збігається з темою title' : '');
            }
        }
        if ($allQuestions) {
            $qs = array_slice(array_keys($allQuestions), 0, 3);
            $passed[] = 'Є заголовки-питання (' . count($allQuestions) . '): ' . implode(', ', array_map(function ($t) { return self::qt(self::mbTrunc((string)$t, 70)); }, $qs));
        }
    }

    // ================================================================== 4. вступ «спершу відповідь»

    private function evalAnswerFirst(array $pages, array &$sub, array &$findings, array &$passed): void
    {
        $comp = []; $issues = []; $ok = [];
        $hasContent = false;
        foreach ($pages as $a) if (in_array($a['role'], ['article', 'service', 'about'], true) && $a['intro']) $hasContent = true;
        foreach ($pages as $a) {
            $in = $a['intro'];
            if (!$in) continue;
            if ($a['role'] === 'home' && $hasContent) {
                // головну рахуємо з меншою вагою, але без окремого зауваження, якщо є контентні сторінки
            }
            switch ($in['type']) {
                case 'p':
                    $s = $in['words'] <= 80 ? 100 : ($in['words'] <= 120 ? 60 : 40);
                    break;
                case 'list':
                    $s = 70;
                    break;
                case 'heading':
                    $s = 35;
                    break;
                default:
                    $s = 30;
            }
            $comp[] = [$s, self::ROLE_WEIGHT[$a['role']]];
            if ($in['type'] === 'p' && $in['words'] <= 80) $ok[] = mb_strtolower($a['label']) . ' (' . $in['words'] . ' сл.)';
            $flag = ($in['type'] === 'p' && $in['words'] > 80) || $in['type'] === 'heading' || $in['type'] === 'none';
            if ($flag && ($a['role'] !== 'home' || !$hasContent)) $issues[] = $a;
        }
        $sub['answer_first'] = $comp ? $this->scoreFrom($comp) : null;

        if ($issues) {
            $lines = [];
            foreach ($issues as $a) {
                $in = $a['intro'];
                if ($in['type'] === 'p') $lines[] = $a['label'] . ': перший абзац після H1 - ' . self::nw($in['words']);
                elseif ($in['type'] === 'heading' && $this->wc($in['next']) > 20) $lines[] = $a['label'] . ': після H1 одразу ' . strtoupper($in['next_tag']) . ' на ' . self::nw($this->wc($in['next'])) . ' - вступ оформлено заголовком, а не абзацом';
                elseif ($in['type'] === 'heading') $lines[] = $a['label'] . ': після H1 одразу ' . strtoupper($in['next_tag']) . ' ' . self::qt(self::mbTrunc($in['next'], 70)) . ', вступного абзацу немає';
                else $lines[] = $a['label'] . ': після H1 не знайдено вступного абзацу';
            }
            $a = $issues[0];
            $in = $a['intro'];
            $h1 = self::mbTrunc($a['h1real'][0] ?? '', 100);
            if ($in['type'] === 'p') {
                $sents = $this->sentences($in['text']);
                $lead = ''; $rest = [];
                foreach ($sents as $s) {
                    if ($lead === '' || ($this->wc($lead) + $this->wc($s) <= 45 && count($rest) === 0)) $lead = trim($lead . ' ' . $s);
                    else $rest[] = $s;
                }
                $bad = '<h1>' . $h1 . "</h1>\n<p>" . self::mbTrunc($in['text'], 300) . '</p> (' . self::nw($in['words']) . ')';
                $good = $rest
                    ? '<h1>' . $h1 . "</h1>\n<p>" . self::mbTrunc($lead, 250) . "</p>\n<p>" . self::mbTrunc(implode(' ', $rest), 180) . '</p>'
                    : '<h1>' . $h1 . "</h1>\n<p>[ВКАЖІТЬ відповідь у 1-2 реченнях до 50 слів]</p>\n<p>" . self::mbTrunc($in['text'], 200) . '</p>';
                $code = '';
            } elseif ($in['type'] === 'heading' && $this->wc($in['next']) > 20) {
                // одразу після H1 стоїть «заголовок-абзац»: він і є вступом, його треба лише зробити <p>
                $bad = '<h1>' . $h1 . "</h1>\n<" . $in['next_tag'] . '>' . self::mbTrunc($in['next'], 250) . '</' . $in['next_tag'] . '>';
                $good = '<h1>' . $h1 . "</h1>\n<p>" . self::mbTrunc($in['next'], 250) . '</p>';
                $code = '';
            } else {
                $next = $in['type'] === 'heading' ? "\n<" . $in['next_tag'] . '>' . self::mbTrunc($in['next'], 80) . '</' . $in['next_tag'] . '>' : '';
                $bad = '<h1>' . $h1 . '</h1>' . $next;
                $good = '<h1>' . $h1 . "</h1>\n<p>" . $this->leadTemplate($a) . '</p>' . $next;
                $code = $good;
            }
            $findings[] = $this->finding('answer_first', 'low', 'Немає короткого вступу з відповіддю одразу після H1', [
                'evidence' => implode("\n", $lines),
                'why' => 'AI-пошуковики охоче цитують перший абзац після заголовка, якщо він одразу відповідає на питання сторінки. Довгий вступ або його відсутність змушують модель шукати відповідь у глибині тексту.',
                'fix' => 'Під H1 поставте 1-2 речення до 50-80 слів: що це, для кого і ключовий факт. Деталі - нижче, в окремих абзацах.',
                'bad' => $bad,
                'good' => $good,
                'code' => $code,
                'verify' => 'Прочитайте лише H1 і перший абзац: з них має бути зрозуміло, що пропонується і чим це корисно. Повторний аудит.',
                'effort' => 'S',
            ]);
        }
        if ($ok) $passed[] = 'Короткий вступ одразу після H1: ' . self::listSome($ok, 4);
    }

    // ================================================================== 5. списки й таблиці

    private function evalStructure(array $pages, array &$sub, array &$findings, array &$passed): void
    {
        $comp = []; $noStruct = []; $specs = []; $ok = [];
        foreach ($pages as $a) {
            $role = $a['role'];
            $st = $a['struct'];
            $hasList = $st['lists'] > 0;
            $hasTable = ($st['tables'] + $st['dls']) > 0;
            $s = null;
            if ($role === 'product') {
                if ($hasTable || ($a['specs_heading'] !== '' && $hasList)) $s = 100;
                elseif ($a['specs_heading'] !== '') { $s = 40; $specs[] = $a; }
            } elseif ($role !== 'category' && !($role === 'home' && in_array($this->siteType, ['media', 'ecommerce'], true))) {
                if ($a['words'] >= 500) {
                    $s = ($hasList && $hasTable) ? 100 : (($hasList || $hasTable) ? 85 : 30);
                    if (!$hasList && !$hasTable) $noStruct[] = $a;
                } elseif ($hasList || $hasTable) {
                    $s = 100;
                }
            }
            if ($s !== null) $comp[] = [$s, self::ROLE_WEIGHT[$role]];
            if ($hasList || $hasTable) {
                $parts = [];
                if ($st['lists']) $parts[] = $st['lists'] . ' ' . self::plural($st['lists'], 'список', 'списки', 'списків');
                if ($st['tables'] + $st['dls']) $parts[] = ($st['tables'] + $st['dls']) . ' ' . self::plural($st['tables'] + $st['dls'], 'таблиця', 'таблиці', 'таблиць');
                $ok[] = mb_strtolower($a['label']) . ' - ' . implode(', ', $parts);
            }
        }
        $sub['structure'] = $comp ? $this->scoreFrom($comp) : null;

        if ($noStruct) {
            $lines = [];
            foreach ($noStruct as $a) $lines[] = $a['label'] . ': ' . self::nw($a['words']) . ', у контенті 0 списків і 0 таблиць (списки меню й футера не враховано)';
            [$enumSent, $items] = $this->findEnumeration($noStruct);
            if ($enumSent !== '' && count($items) >= 3) {
                $bad = '«' . self::mbTrunc($enumSent, 300) . '»';
                $ul = "<ul>\n";
                foreach (array_slice($items, 0, 8) as $it) $ul .= '  <li>' . self::mbTrunc($it, 120) . "</li>\n";
                $ul .= '</ul>';
                $good = $ul;
                $code = $ul;
            } else {
                $bad = $noStruct[0]['label'] . ': текст подано лише абзацами, без <ul>/<ol>/<table>.';
                $good = "<ul>\n  <li>[ВКАЖІТЬ пункт: що входить / перевага / етап]</li>\n  <li>[ВКАЖІТЬ пункт]</li>\n  <li>[ВКАЖІТЬ пункт]</li>\n</ul>";
                $code = '';
            }
            $findings[] = $this->finding('no_lists', 'low', 'Довгі сторінки без списків і таблиць', [
                'evidence' => implode("\n", $lines),
                'why' => 'Переліки (що входить, етапи, переваги) і таблиці (ціни, порівняння) AI витягує й цитує точніше, ніж ті самі дані, розмазані по абзацу.',
                'fix' => 'Оформіть переліки через <ul>/<ol>, а ціни, терміни й порівняння - через <table> з заголовками колонок.',
                'bad' => $bad,
                'good' => $good,
                'code' => $code,
                'verify' => 'У DevTools: document.querySelectorAll("main ul, main ol, main table").length > 0 (або в межах основного блоку контенту).',
                'effort' => 'S',
            ]);
        }

        if ($specs) {
            $a = $specs[0];
            [$pairs, $raw] = $this->specPairs($a['page'], $a['specs_heading']);
            $lines = [];
            foreach ($specs as $x) $lines[] = $x['label'] . ': є блок «' . $x['specs_heading'] . '», але в ньому немає <table> чи <dl> - характеристики зверстані div-ами';
            if (count($pairs) >= 2) {
                $code = "<table class=\"specs\">\n  <tbody>\n";
                foreach (array_slice($pairs, 0, 12) as [$k, $v]) $code .= '    <tr><th scope="row">' . self::mbTrunc($k, 60) . '</th><td>' . self::mbTrunc($v, 80) . "</td></tr>\n";
                $code .= "  </tbody>\n</table>";
            } else {
                $code = "<table class=\"specs\">\n  <tbody>\n    <tr><th scope=\"row\">[ВКАЖІТЬ характеристику]</th><td>[ВКАЖІТЬ значення]</td></tr>\n  </tbody>\n</table>";
            }
            $findings[] = $this->finding('specs_not_table', 'low', 'Характеристики товару не оформлені таблицею', [
                'evidence' => implode("\n", $lines),
                'why' => 'Пари «характеристика - значення» в <table> або <dl> AI читає однозначно. З div-ів без структури значення легко склеюються з назвами сусідніх полів.',
                'fix' => 'Виведіть характеристики в шаблоні картки через <table> (або <dl>), стилі можна залишити ті самі.',
                'bad' => $raw !== '' ? '«' . self::mbTrunc($raw, 300) . '» (div-и без table/dl)' : 'Блок «' . $a['specs_heading'] . '» зверстаний div-ами без table/dl.',
                'good' => $code,
                'code' => $code,
                'verify' => 'Відкрийте сирий HTML картки товару (Ctrl+U): блок характеристик має бути всередині <table> або <dl>.',
                'effort' => 'S',
            ]);
        }
        if ($ok) $passed[] = 'Списки й таблиці в контенті: ' . self::listSome($ok, 4);
    }

    // ================================================================== 6. факти й цифри

    private function evalFacts(array $pages, array &$sub, array &$findings, array &$passed): void
    {
        $comp = []; $low = []; $ok = []; $okStat = [];
        $hasContent = false;
        foreach ($pages as $a) {
            if (in_array($a['role'], ['article', 'service', 'about'], true) && $a['words'] >= 150 && count($a['sentences']) >= 5) $hasContent = true;
        }
        foreach ($pages as $a) {
            $role = $a['role'];
            if (!in_array($role, ['home', 'article', 'service', 'about'], true)) continue;
            $n = count($a['sentences']);
            $stats = $a['stat_blocks'];
            // короткі блоки з цифрами (лічильники «1 300 фондів», рядки прайсу «1200 ₴») - теж конкретика,
            // хоч і не речення: при 3+ таких блоках оцінка не падає до нуля
            $statScore = $stats >= 3 ? min(70, 15 * $stats) : null;
            if ($a['words'] < 150 || $n < 5) {
                if ($statScore !== null && $a['words'] >= 100) {
                    $comp[] = [$statScore, self::ROLE_WEIGHT[$role]];
                    $okStat[] = mb_strtolower($a['label']) . ' - ' . $stats . ' ' . self::plural($stats, 'блок', 'блоки', 'блоків') . ' з цифрами (' . self::qt(self::mbTrunc($a['stat_examples'][0], 60)) . ')';
                }
                continue;
            }
            $share = $a['with_num'] / $n;
            $s = min(100, $share / 0.25 * 100);
            if ($statScore !== null) $s = max($s, $statScore);
            $comp[] = [$s, self::ROLE_WEIGHT[$role]];
            if ($n >= 15 && $share < 0.10 && ($role !== 'home' || ($a['words'] >= 400 && !$hasContent))) $low[] = $a;
            elseif ($share >= 0.2) $ok[] = mb_strtolower($a['label']) . ' - ' . (int)round($share * 100) . '% речень';
            elseif ($statScore !== null) $okStat[] = mb_strtolower($a['label']) . ' - ' . $stats . ' ' . self::plural($stats, 'блок', 'блоки', 'блоків') . ' з цифрами (' . self::qt(self::mbTrunc($a['stat_examples'][0], 60)) . ')';
        }
        $sub['facts'] = $comp ? $this->scoreFrom($comp) : null;

        if ($low) {
            $lines = []; $soft = true; $hasPrices = false;
            foreach ($low as $a) {
                $n = count($a['sentences']);
                $line = $a['label'] . ': ' . $a['with_num'] . ' з ' . $n . ' речень (' . (int)round($a['with_num'] / $n * 100) . '%) містять цифри чи посилання на джерело даних';
                if ($a['stat_blocks'] >= 3) $line .= '; окремо є ' . $a['stat_blocks'] . ' ' . self::plural($a['stat_blocks'], 'короткий блок', 'короткі блоки', 'коротких блоків') . ' з цифрами (напр. ' . self::qt(self::mbTrunc($a['stat_examples'][0], 50)) . ') - їх враховано, але в самому тексті цифр майже немає';
                if ($a['sources'] > 0) $line .= '; є ' . $a['sources'] . ' ' . self::plural($a['sources'], 'посилання', 'посилання', 'посилань') . ' на джерела («за даними...»)';
                $lines[] = $line;
                // м'якше, якщо сторінка вже має прайс / лічильники або посилається на джерела
                if ($a['stat_blocks'] < 3 && $a['sources'] === 0) $soft = false;
                if ($a['stat_price']) $hasPrices = true;
            }
            [$vague, $kind] = $this->pickVague($low);
            $a = $low[0];
            $ph = $this->factPlaceholder($a['role'], $hasPrices);
            $bad = $vague !== '' ? '«' . self::mbTrunc(self::innerQ($vague), 300) . '»' : $a['label'] . ': твердження без цифр і перевірюваних фактів.';
            if ($vague !== '' && $kind === 'hedge') {
                $hint = 'наскільки саме';
                if (preg_match(self::RX_HEDGE, $vague, $hm)) {
                    $hw = mb_strtolower($hm[1]);
                    if (preg_match('/^(часто|іноді|зазвичай|usually|often|обычно)$/u', $hw)) $hint = 'як часто або в якій частці випадків';
                    elseif (preg_match('/^(кілька|декілька|багато|чимало|деяких|деякі|several|many|несколько|много)$/u', $hw)) $hint = 'скільки саме';
                    elseif (preg_match('/тривалий/u', $hw)) $hint = 'скільки саме часу';
                }
                $good = '«' . self::mbTrunc(self::innerQ(rtrim($vague, ' .!')), 260) . ' ([ВКАЖІТЬ, ' . $hint . ': цифра, діапазон або %])» + джерело даних (дослідження, протокол, власна статистика).';
            } elseif ($vague !== '') {
                $good = '«' . self::mbTrunc(self::innerQ(rtrim($vague, ' .!')), 260) . ' - ' . $ph . '»';
            } else {
                $good = 'Кожне ключове твердження підкріпіть цифрою або фактом: ' . $ph . '.';
            }
            $findings[] = $this->finding('few_facts', $soft ? 'low' : 'medium', $soft ? 'Ймовірно, замало конкретних цифр у самому тексті' : 'Мало конкретних фактів і цифр у тексті', [
                'evidence' => implode("\n", $lines),
                'why' => 'AI-пошуковики охочіше цитують перевірювані твердження: цифри, терміни, ціни, роки, дані з названим джерелом. Загальні фрази («якісно», «професійно») не дають моделі, що процитувати.',
                'fix' => $hasPrices
                    ? 'Ціни на сторінці вже є - додайте конкретику і в текст: терміни, кількість процедур чи проєктів, досвід у роках, результати в цифрах, дані з джерелом.'
                    : 'Замініть загальні фрази на факти: досвід у роках, кількість клієнтів чи проєктів, терміни, ціни «від», результати кейсів у цифрах з джерелом.',
                'bad' => $bad,
                'good' => $good,
                'verify' => 'Порахуйте в оновленому тексті речення з цифрами: на сторінках послуг і в статтях бажано від 20%. Повторний аудит.',
                'effort' => 'M',
            ]);
        }
        if ($ok) $passed[] = 'Багато конкретних цифр і фактів: ' . self::listSome($ok, 4);
        if ($okStat) $passed[] = 'Конкретні цифри в коротких блоках (лічильники, ціни, терміни): ' . self::listSome($okStat, 3);
    }

    // ================================================================== 7. свіжість статей

    private function evalFreshness(array $pages, array $unavailable, array &$sub, array &$findings, array &$passed): void
    {
        $arts = array_values(array_filter($pages, function ($a) { return $a['role'] === 'article'; }));
        if (!$arts) { $sub['freshness'] = null; return; }
        $comp = []; $none = []; $stale = []; $auto = []; $incons = [];
        foreach ($arts as $a) {
            $d = $a['dates'];
            $hasMachine = $d['machine'] !== [];
            $hasVisible = $d['visible'] !== [];
            if (!$hasMachine && !$hasVisible) { $none[] = $a; $comp[] = [10, 1]; continue; }
            $s = ($hasMachine && $hasVisible) ? 100 : 80;
            $age = $d['latest'] ? (int)floor(($this->now - $d['latest']) / 86400) : null;
            if ($age !== null && $age > 548) { $s = min($s, 45); $stale[] = $a + ['age' => $age]; }
            elseif ($age !== null && $age > 365) $s = min($s, 70);
            if ($d['autogen']) $auto[] = $a;
            if ($d['inconsistent']) { $incons[] = $a; $s = min($s, 85); }
            $comp[] = [$s, 1];
            if ($age !== null && $age <= 548) {
                $src = [];
                if ($d['latest_src']) $src[] = $d['latest_src'];
                if ($d['visible']) $src[] = $d['visible'][0]['src'];
                $passed[] = 'Стаття має дату: ' . $d['latest_day'] . ' (' . implode(', ', array_unique($src)) . ')';
            }
        }
        $sub['freshness'] = $this->scoreFrom($comp);

        if ($incons) {
            $a = $incons[0];
            $in = $a['dates']['inconsistent'];
            $lines = [];
            foreach ($incons as $x) {
                $i = $x['dates']['inconsistent'];
                $lines[] = $i['type'] === 'order'
                    ? $x['label'] . ': datePublished «' . $i['published'] . '» пізніша за dateModified «' . $i['modified'] . '»'
                    : $x['label'] . ': ' . $i['src'] . ' «' . $i['raw'] . '» - у майбутньому відносно часу відповіді сервера (' . gmdate('d.m.Y H:i', $this->now) . ' GMT)';
            }
            $findings[] = $this->finding('date_inconsistent', 'low', 'Дати статті в розмітці суперечать одна одній', [
                'evidence' => implode("\n", $lines),
                'why' => 'Дата публікації, пізніша за дату оновлення чи за поточний час, - ознака помилки в шаблоні. Пошукові системи можуть не довіряти таким датам і не враховувати свіжість статті.',
                'fix' => 'Перевірте, звідки шаблон бере datePublished і dateModified: публікація - дата першого виходу запису, оновлення - дата останнього редагування, обидві з часовим поясом і не пізніші за поточний час.',
                'bad' => $in['type'] === 'order'
                    ? '"datePublished": "' . $in['published'] . '", "dateModified": "' . $in['modified'] . '"'
                    : '"' . preg_replace('/^(JSON-LD|мікророзмітка)\s+/u', '', $in['src']) . '": "' . $in['raw'] . '"',
                'good' => '"datePublished": "[дата першої публікації, напр. 2026-10-21T09:00:00+03:00]", "dateModified": "[дата останнього редагування, не раніше за datePublished]"',
                'verify' => 'Google Rich Results Test для URL статті: datePublished не пізніша за dateModified і не в майбутньому.',
                'effort' => 'S',
            ]);
        }

        if ($none) {
            $a = $none[0];
            $h1 = $a['h1real'][0] ?? $a['title'];
            $ld = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => self::mbTrunc($h1, 110),
                'mainEntityOfPage' => $a['url'],
                'datePublished' => '[ВКАЖІТЬ дату публікації, напр. 2026-01-15T09:00:00+02:00]',
                'dateModified' => '[ВКАЖІТЬ дату останнього оновлення]',
                'author' => ['@type' => 'Person', 'name' => '[ВКАЖІТЬ автора]'],
            ];
            if ($this->brand !== '') $ld['publisher'] = ['@type' => 'Organization', 'name' => $this->brand];
            $code = "<p class=\"meta\">Опубліковано: <time datetime=\"[ВКАЖІТЬ 2026-01-15]\">[ВКАЖІТЬ 15 січня 2026]</time> · Оновлено: <time datetime=\"[ВКАЖІТЬ дату]\">[ВКАЖІТЬ дату]</time></p>\n\n" . self::jsonLdScript($ld);
            $findings[] = $this->finding('no_dates', 'medium', 'У статтях немає дати публікації чи оновлення', [
                'evidence' => implode("\n", array_map(function ($x) { return $x['label'] . ': немає ні видимої дати біля заголовка, ні datePublished/dateModified у JSON-LD чи article:published_time.'; }, $none)),
                'why' => 'Для запитів, де важлива актуальність, AI-пошуковики віддають перевагу джерелам з явною датою. Без дати модель не може оцінити, чи інформація ще чинна.',
                'fix' => 'Виведіть під заголовком статті дату публікації й оновлення в <time datetime> і продублюйте їх у JSON-LD Article (datePublished, dateModified).',
                'bad' => 'Стаття «' . self::mbTrunc($h1, 100) . '»: дати немає ні в тексті біля H1, ні в розмітці.',
                'good' => 'Опубліковано: 15 січня 2026 · Оновлено: 3 жовтня 2026 (видимо під H1 + те саме в JSON-LD).',
                'code' => $code,
                'verify' => 'Google Rich Results Test для URL статті: Article з datePublished і dateModified; дата видима на сторінці.',
                'effort' => 'S',
            ]);
        }
        if ($stale) {
            $a = $stale[0];
            $findings[] = $this->finding('stale_article', 'low', 'Стаття давно не оновлювалася (понад 18 місяців)', [
                'evidence' => implode("\n", array_map(function ($x) { return $x['label'] . ': остання дата - ' . $x['dates']['latest_day'] . ' (' . $x['age'] . ' ' . self::plural($x['age'], 'день', 'дні', 'днів') . ' тому), ' . $x['url']; }, $stale)),
                'why' => 'Для тем, що змінюються (ціни, правила, технології), AI-пошуковики частіше беруть свіжіші джерела. Стара дата - сигнал, що інформація могла застаріти.',
                'fix' => 'Перегляньте статті старші за рік: оновіть цифри, ціни й приклади, додайте блок «Що змінилося» і поставте нову dateModified (лише при реальних змінах).',
                'bad' => 'Остання дата статті «' . self::mbTrunc($a['h1real'][0] ?? $a['title'], 90) . '»: ' . $a['dates']['latest_day'],
                'good' => 'Оновлено: ' . self::ukDate($this->now) . ' - оновлено ціни й дані, додано розділ «Що змінилося у ' . date('Y', $this->now) . ' році».',
                'verify' => 'Rich Results Test: dateModified відповідає даті реального оновлення і видима на сторінці.',
                'effort' => 'M',
            ]);
        }
        if ($auto) {
            $a = $auto[0];
            $d = $a['dates'];
            $findings[] = $this->finding('date_autogen', 'low', 'Дата оновлення статті, ймовірно, підставляється автоматично', [
                'evidence' => $a['label'] . ': dateModified «' . $d['autogen']['raw'] . '» відрізняється від часу відповіді сервера (' . gmdate('d.m.Y H:i', $this->now) . ' GMT, заголовок Date) менш ніж на 15 хвилин. Це або щойно відредагована стаття, або дата, що генерується при кожному завантаженні - автоматично підтвердити не вдалося.',
                'why' => 'Якщо дата оновлення змінюється без змін у тексті, пошукові системи з часом перестають їй довіряти, і сигнал свіжості втрачається для всіх статей.',
                'fix' => 'Перевірте шаблон: dateModified має братися з дати останнього редагування запису в CMS, а не з поточного часу.',
                'bad' => '"dateModified": "' . $d['autogen']['raw'] . '"',
                'good' => '"dateModified": "[дата останнього реального редагування статті з CMS]"',
                'verify' => 'Відкрийте вихідний код статті двічі з інтервалом у кілька хвилин (Ctrl+U) і порівняйте dateModified: значення не має змінюватися.',
                'effort' => 'S',
            ]);
        }
    }

    // ================================================================== 8. title / description

    private function evalMeta(array $pages, array $metaOnly, array &$sub, array &$findings, array &$passed): void
    {
        $all = [];
        foreach ($pages as $a) $all[] = ['role' => $a['role'], 'url' => $a['url'], 'label' => $a['label'], 'page' => $a['page'], 'a' => $a];
        foreach ($metaOnly as $m) $all[] = $m + ['a' => null];

        $comp = []; $noTitle = []; $short = []; $long = []; $noDesc = []; $byTitle = [];
        foreach ($all as $x) {
            /** @var Page $p */
            $p = $x['page'];
            $t = $p->title();
            $d = $p->metaDescription();
            $tl = mb_strlen($t);
            if ($t === '') { $ts = 0; $noTitle[] = $x; }
            elseif ($tl < 20) { $ts = 40; $short[] = $x + ['t' => $t, 'len' => $tl]; }
            elseif ($tl > 150) { $ts = 70; $long[] = $x + ['t' => $t, 'len' => $tl]; }
            else $ts = 100;
            if ($t !== '') $byTitle[mb_strtolower($t)][] = $x;
            $ds = $d !== '' ? 100 : 0;
            if ($d === '') $noDesc[] = $x;
            $comp[] = [0.6 * $ts + 0.4 * $ds, $x['role'] === 'home' ? 2 : 1];
        }
        $dups = array_filter($byTitle, function ($g) { return count($g) >= 2; });
        $sub['meta'] = $this->scoreFrom($comp) - ($dups ? 10 : 0);
        if ($sub['meta'] < 0) $sub['meta'] = 0;

        // ---- title
        if ($noTitle || $short || $long || $dups) {
            $lines = []; $sev = 'low';
            foreach ($noTitle as $x) {
                $lines[] = $x['label'] . ': <title> відсутній або порожній';
                $sev = self::maxSev($sev, isset(self::CONTENT_ROLES[$x['role']]) ? 'critical' : 'high');
            }
            foreach ($short as $x) {
                $lines[] = $x['label'] . ': title ' . self::qt($x['t']) . ' - ' . $x['len'] . ' ' . self::plural($x['len'], 'символ', 'символи', 'символів');
                $sev = self::maxSev($sev, 'medium');
            }
            foreach ($long as $x) $lines[] = $x['label'] . ': title ' . $x['len'] . ' символів - ' . self::qt(self::mbTrunc($x['t'], 120));
            foreach ($dups as $g) $lines[] = 'Однаковий title на ' . count($g) . ' сторінках (' . implode(', ', array_map(function ($x) { return mb_strtolower($x['label']); }, $g)) . '): ' . self::qt(self::mbTrunc($g[0]['page']->title(), 90));

            $ex = $noTitle[0] ?? ($short[0] ?? ($long[0] ?? reset($dups)[1]));
            $exPage = $ex['page'];
            $curT = $exPage->title();
            $h1 = '';
            $hh = $exPage->headings();
            foreach ($hh['h1'] as $t) if ($t !== '' && !preg_match(self::RX_MODAL_H1, $t)) { $h1 = $t; break; }
            if ($long && !$noTitle && !$short && !$dups) {
                $sugg = self::mbTrunc($curT, 100);
                $good = '<title>' . ($h1 !== '' ? self::mbTrunc($h1, 80) : '[ВКАЖІТЬ основну тему сторінки]') . ($this->brand !== '' ? ' | ' . $this->brand : '') . '</title>';
            } else {
                $h1ok = $h1 !== '' && mb_strtolower($h1) !== mb_strtolower($curT) && ($curT === '' || $this->overlaps($h1, $curT));
                $base = $h1ok ? $h1 : ($curT !== '' ? $curT : '[ВКАЖІТЬ тему сторінки]');
                if ($ex['role'] === 'about' && $this->brand !== '') {
                    $sugg = 'Про ' . $this->brand . ': [ВКАЖІТЬ, чим займаєтесь, з якого року і де]';
                } else {
                    $sugg = self::mbTrunc($base, 80) . ' - [ВКАЖІТЬ уточнення: що саме, для кого / місто]' . ($this->brand !== '' && mb_stripos($base, $this->brand) === false ? ' | ' . $this->brand : '');
                }
                $good = '<title>' . $sugg . '</title>';
            }
            $findings[] = $this->finding('title', $sev, $noTitle ? 'Сторінки без <title>' : 'Проблеми з <title>: занадто короткі, задовгі або однакові', [
                'evidence' => implode("\n", array_slice($lines, 0, 8)),
                'why' => 'Title - перше, що AI-пошуковик бачить у результатах пошуку і в посиланні-джерелі. Дуже короткий або однаковий title не пояснює, чим сторінка відрізняється від інших.',
                'fix' => 'Для кожної сторінки - унікальний title з темою сторінки і брендом; довжину підбирайте за аналізом видачі, без жорсткого ліміту.',
                'bad' => $curT !== '' ? '<title>' . self::mbTrunc($curT, 200) . '</title>' : 'У <head> немає <title> (або він порожній).',
                'good' => $good,
                'code' => $good,
                'verify' => 'Screaming Frog → Page Titles (Missing / Duplicate / Below 20 chars) або Detailed SEO Extension на кожній сторінці.',
                'effort' => 'S',
            ]);
        }

        // ---- description
        if ($noDesc) {
            $homeMissing = false;
            foreach ($noDesc as $x) if ($x['role'] === 'home') $homeMissing = true;
            $ex = $noDesc[0];
            $suggest = $this->descriptionSuggestion($ex);
            $code = '<meta name="description" content="' . htmlspecialchars($suggest, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . '">';
            $findings[] = $this->finding('no_description', $homeMissing ? 'medium' : 'low', 'Немає (або порожній) meta description на ' . (count($noDesc) === 1 ? 'сторінці' : count($noDesc) . ' сторінках'), [
                'evidence' => implode("\n", array_map(function ($x) { return $x['label'] . ': ' . (array_key_exists('description', $x['page']->metaTags()) ? 'тег meta description є, але порожній (content="")' : 'meta description відсутній') . ' - ' . $x['url']; }, array_slice($noDesc, 0, 6))),
                'why' => 'Прямий вплив description на відповіді AI слабкий: моделі цитують текст сторінки. Але Google і Bing можуть показати description у сніпеті (Google - коли він точніше за текст сторінки описує її зміст), і частина AI-пошуковиків бере його як короткий опис джерела.',
                'fix' => 'Додайте унікальний description з суттю сторінки і конкретикою (що, для кого, ключовий факт); довжину визначайте за аналізом видачі.',
                'bad' => array_key_exists('description', $ex['page']->metaTags())
                    ? 'Сторінка ' . self::qt(self::mbTrunc($ex['page']->title() !== '' ? $ex['page']->title() : $ex['label'], 90)) . ': <meta name="description" content="">'
                    : 'У <head> сторінки ' . self::qt(self::mbTrunc($ex['page']->title() !== '' ? $ex['page']->title() : $ex['label'], 90)) . ' немає <meta name="description">.',
                'good' => $code,
                'code' => $code,
                'verify' => 'Ctrl+U → пошук «name="description"»; Screaming Frog → Meta Description → Missing.',
                'effort' => 'S',
            ]);
        }

        $n = count($all);
        if (!$noTitle && !$noDesc && $n > 0) $passed[] = 'Title і meta description заповнені на всіх ' . $n . ' перевірених сторінках';
        elseif (!$noTitle && !$short && !$long && $n > 0) $passed[] = 'Title заповнений і змістовний на всіх ' . $n . ' перевірених сторінках';
    }

    // ================================================================== 9. Open Graph

    private function evalOg(array $home, array $pages, array $metaOnly, array &$sub, array &$findings, array &$passed): void
    {
        /** @var Page $p */
        $p = $home['page'];
        $missing = [];
        foreach (['title', 'description', 'image'] as $k) if ($p->og($k) === null) $missing[] = 'og:' . $k;
        $sub['og'] = (int)round((3 - count($missing)) / 3 * 100);
        $innerNoImg = 0; $inner = 0;
        foreach (array_merge(array_slice($pages, 1), $metaOnly) as $x) {
            $inner++;
            if ($x['page']->og('image') === null) $innerNoImg++;
        }
        if (!$missing) {
            $passed[] = 'Open Graph на головній повний: og:title, og:description, og:image';
            return;
        }
        $title = $p->og('title') ?? ($p->title() !== '' ? $p->title() : ($this->brand !== '' ? $this->brand : '[ВКАЖІТЬ заголовок]'));
        $desc = $p->og('description') ?? ($p->metaDescription() !== '' ? $p->metaDescription() : $this->descriptionSuggestion(['page' => $p, 'role' => 'home', 'a' => $home]));
        $img = $p->og('image') ?? $this->imageCandidate($p);
        $code = '<meta property="og:type" content="website">' . "\n"
            . '<meta property="og:url" content="' . self::attr($home['url']) . '">' . "\n"
            . '<meta property="og:title" content="' . self::attr(self::mbTrunc($title, 120)) . '">' . "\n"
            . '<meta property="og:description" content="' . self::attr(self::mbTrunc($desc, 200)) . '">' . "\n"
            . '<meta property="og:image" content="' . self::attr($img !== '' ? $img : '[ВКАЖІТЬ URL зображення 1200x630]') . '">' . ($img !== '' && $p->og('image') === null ? ' <!-- знайдено в розмітці сайту; краще окреме зображення 1200x630 -->' : '')
            . ($this->brand !== '' && $p->og('site_name') === null ? "\n" . '<meta property="og:site_name" content="' . self::attr($this->brand) . '">' : '');
        $present = [];
        foreach (['title', 'description', 'image', 'type', 'url', 'site_name'] as $k) if ($p->og($k) !== null) $present[] = 'og:' . $k;
        // «як треба» - лише відсутні теги з короткими значеннями; повний набір - у блоці коду
        $vals = ['og:title' => self::mbTrunc($title, 70), 'og:description' => self::mbTrunc($desc, 110),
            'og:image' => $img !== '' ? self::mbTrunc($img, 110) : '[ВКАЖІТЬ URL зображення 1200x630]'];
        $goodLines = [];
        foreach ($missing as $m) $goodLines[] = '<meta property="' . $m . '" content="' . self::attr($vals[$m]) . '">';
        $good = implode("\n", $goodLines) . "\n(повний набір тегів - у блоці коду)";
        $findings[] = $this->finding('og_missing', 'low', 'Неповна розмітка Open Graph на головній', [
            'evidence' => 'Головна: немає ' . implode(', ', $missing) . '.' . ($inner > 0 && $innerNoImg > 0 ? ' Без og:image також ' . $innerNoImg . ' з ' . $inner . ' внутрішніх сторінок вибірки.' : ''),
            'why' => 'Open Graph формує прев\'ю посилання в месенджерах, соцмережах і частині AI-інтерфейсів (картки джерел). На ранжування у відповідях AI він майже не впливає, але робить посилання на сайт помітнішим.',
            'fix' => 'Додайте в <head> og:title, og:description і og:image (1200x630) - на головну і в шаблони внутрішніх сторінок.',
            'bad' => $present ? 'Зараз є: ' . implode(', ', $present) . '; немає: ' . implode(', ', $missing) : 'У <head> немає жодного тегу og:*',
            'good' => $good,
            'code' => $code,
            'verify' => 'Facebook Sharing Debugger (developers.facebook.com/tools/debug) або opengraph.xyz для URL головної.',
            'effort' => 'S',
        ]);
    }

    // ================================================================== summary

    private function buildSummary(array $pages, array $unavailable, array $sub): string
    {
        $labels = array_map(function ($a) { return mb_strtolower(self::roleName($a['role']) ?? $a['role']); }, $pages);
        $n = count($pages);
        $s = 'Оцінено ' . $n . ' ' . self::plural($n, 'сторінку', 'сторінки', 'сторінок') . ' (' . implode(', ', $labels) . ')';
        $vals = array_filter($sub, function ($v) { return $v !== null; });
        if ($vals) {
            asort($vals);
            $worst = array_slice($vals, 0, 2, true);
            arsort($vals);
            $best = array_slice($vals, 0, 1, true);
            $w = [];
            foreach ($worst as $k => $v) if ($v < 70) $w[] = '«' . self::SUB_LABELS[$k] . '» (' . $v . ')';
            if ($this->noContent) $s .= '. Тексту для цитування в HTML майже немає, тому бал обмежено';
            if ($w) $s .= '. Найслабше: ' . implode(', ', $w);
            if (!$this->noContent) foreach ($best as $k => $v) if ($v >= 70) $s .= '; найкраще: «' . self::SUB_LABELS[$k] . '» (' . $v . ')';
        }
        if ($unavailable) {
            $s .= '. Не завантажилися: ' . implode(', ', array_map(function ($u) { return mb_strtolower(self::roleName($u['role']) ?? $u['role']) . ' (' . $u['reason'] . ')'; }, array_slice($unavailable, 0, 3)));
        }
        return $s . '.';
    }

    // ================================================================== DOM helpers

    /** Корінь основного контенту відповідно до Page::mainSource(). */
    private function mainRoot(Page $p): ?DOMElement
    {
        $src = $p->mainSource();
        if ($src === 'main') {
            foreach ($p->query('//main | //*[@role="main"]') as $m) {
                if ($m instanceof DOMElement && $this->wc($this->nodeText($p, $m)) >= 30) return $m;
            }
        } elseif ($src === 'article') {
            $arts = [];
            foreach ($p->query('//article') as $a) if (!$p->query('ancestor::article', $a)) $arts[] = $a;
            if (count($arts) === 1 && $arts[0] instanceof DOMElement) return $arts[0];
        }
        $b = $p->query('//body');
        return ($b && $b[0] instanceof DOMElement) ? $b[0] : null;
    }

    /**
     * Текстові блоки сторінки - той самий обхід, що й Page::textBlocks() (той самий текст блоків),
     * плюс ознаки: блок у спливному вікні (modal) і скільки його слів - текст посилань (link_words).
     * @return array<int, array{tag:string,text:string,words:int,in_nav:bool,modal:bool,link_words:int}>
     */
    private function walkBlocks(Page $p): array
    {
        $body = $p->query('//body');
        $root = $body ? $body[0] : $p->dom()->documentElement;
        if (!($root instanceof DOMNode)) return [];
        $out = [];
        $this->blockWalk($root, false, false, false, $out);
        return array_values(array_filter($out));
    }

    /** @return array{0:string,1:bool,2:string} [повний текст, чи є блочні нащадки, повний текст посилань] */
    private function blockWalk(DOMNode $node, bool $inNav, bool $inModal, bool $inLink, array &$out): array
    {
        $tag = $node instanceof DOMElement ? strtolower($node->nodeName) : '';
        $isCand = isset(Page::BLOCK_CAND[$tag]) || !empty(Page::CONTAINER_CAND[$tag]);
        $slot = null;
        if ($isCand) { $slot = count($out); $out[] = null; }

        $full = []; $direct = []; $fullL = []; $directL = []; $hasBlock = false;
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMText) {
                if ($c instanceof DOMCdataSection) continue;
                $full[] = $c->nodeValue; $direct[] = $c->nodeValue;
                if ($inLink) { $fullL[] = $c->nodeValue; $directL[] = $c->nodeValue; }
                continue;
            }
            if (!($c instanceof DOMElement)) continue;
            $ct = strtolower($c->nodeName);
            if (isset(Page::SKIP_TAGS[$ct]) || self::hiddenEl($c)) continue;
            if ($ct === 'br' || $ct === 'hr') { $full[] = ' '; $direct[] = ' '; continue; }
            if ($ct === 'img') continue;
            $childNav = $inNav || isset(Page::NAV_TAGS[$ct]) || isset(Page::NAV_ROLES[strtolower($c->getAttribute('role'))]);
            $childModal = $inModal || $this->isModalEl($c);
            [$cf, $ch, $cfl] = $this->blockWalk($c, $childNav, $childModal, $inLink || $ct === 'a', $out);
            $sep = isset(Page::INLINE_NOSPACE[$ct]) ? '' : ' ';
            $piece = $sep . $cf . $sep;
            $pieceL = $sep . $cfl . $sep;
            $full[] = $piece; $fullL[] = $pieceL;
            if (isset(Page::BLOCKISH[$ct]) || $ch) $hasBlock = true;
            else { $direct[] = $piece; $directL[] = $pieceL; }
        }
        $fullS = implode('', $full);
        if ($isCand) {
            $text = Page::norm($hasBlock ? implode('', $direct) : $fullS);
            if ($text !== '') {
                $w = $this->wc($text);
                if ($w > 0) {
                    $lw = $this->wc(Page::norm(implode('', $hasBlock ? $directL : $fullL)));
                    $out[$slot] = ['tag' => $tag, 'text' => $text, 'words' => $w, 'in_nav' => $inNav, 'modal' => $inModal, 'link_words' => min($w, $lw)];
                }
            }
        }
        return [$fullS, $hasBlock, implode('', $fullL)];
    }

    private static function hiddenEl(DOMElement $el): bool
    {
        if ($el->hasAttribute('hidden')) return true;
        $st = $el->getAttribute('style');
        return $st !== '' && (bool)preg_match('/(display\s*:\s*none|visibility\s*:\s*hidden)/i', $st);
    }

    /**
     * Контейнер спливного вікна: <dialog>, role=dialog, aria-modal, або клас / id modal, popup, mfp, lightbox...
     * За класом - лише короткі контейнери (до 300 слів), щоб не відкинути обгортку всієї сторінки.
     */
    private function isModalEl(DOMElement $e): bool
    {
        $t = strtolower($e->nodeName);
        if ($t === 'dialog') return true;
        $r = strtolower($e->getAttribute('role'));
        if ($r === 'dialog' || $r === 'alertdialog' || strtolower($e->getAttribute('aria-modal')) === 'true') return true;
        if (!in_array($t, ['div', 'section', 'aside', 'form', 'ul'], true)) return false;
        $ci = $e->getAttribute('class') . ' ' . $e->getAttribute('id');
        if (trim($ci) === '' || !preg_match(self::RX_MODAL_CLASS, $ci)) return false;
        return $this->wc(Page::norm($e->textContent)) <= 300;
    }

    private function inModal(DOMNode $n): bool
    {
        for ($a = $n instanceof DOMElement ? $n : $n->parentNode; $a instanceof DOMElement; $a = $a->parentNode) {
            $t = strtolower($a->nodeName);
            if ($t === 'body' || $t === 'html') return false;
            if ($this->isModalEl($a)) return true;
        }
        return false;
    }

    /**
     * Заголовок з двох-чотирьох частин (<span>назва</span><span>підзаголовок</span>) без власного тексту:
     * [назва, решта]; інакше ['', ''].
     * @return array{0:string,1:string}
     */
    private function splitHeading(Page $p, DOMElement $h): array
    {
        $parts = [];
        foreach ($h->childNodes as $c) {
            if ($c instanceof DOMText) {
                if (trim(str_replace("\xC2\xA0", ' ', $c->nodeValue)) !== '') return ['', ''];
                continue;
            }
            if (!($c instanceof DOMElement)) continue;
            if (strtolower($c->nodeName) === 'br') continue;
            $t = $this->nodeText($p, $c);
            if ($t !== '') $parts[] = $t;
        }
        if (count($parts) < 2 || count($parts) > 4) return ['', ''];
        $rest = implode(' ', array_slice($parts, 1));
        if ($this->wc($rest) <= $this->wc($parts[0])) return ['', ''];
        return [$parts[0], $rest];
    }

    private static function headKey(string $t): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $t) ?? $t);
    }

    /** Видимий текст вузла (без script/style/template...), нормалізований. */
    private function nodeText(Page $p, DOMNode $n): string
    {
        $parts = [];
        foreach ($p->query('.//text()[not(ancestor::script or ancestor::style or ancestor::noscript or ancestor::template or ancestor::svg)]', $n) as $t) {
            $parts[] = $t->nodeValue;
        }
        return Page::norm(implode(' ', $parts));
    }

    /** Прямий текст контейнера: текстові вузли + інлайн-нащадки (без блочних дітей). */
    private function directText(Page $p, DOMElement $n): string
    {
        $parts = [];
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) { $parts[] = $c->nodeValue; continue; }
            if (!($c instanceof DOMElement)) continue;
            $t = strtolower($c->nodeName);
            if ($t === 'br') { $parts[] = ' '; continue; }
            if (!isset(self::INLINE[$t])) continue;
            if ($p->query('.//p|.//div|.//ul|.//ol|.//table|.//h1|.//h2|.//h3|.//h4|.//section', $c)) continue;
            $parts[] = ' ' . $this->nodeText($p, $c) . ' ';
        }
        return Page::norm(implode('', $parts));
    }

    private function linkShare(Page $p, DOMNode $n, string $text): float
    {
        $total = $this->wc($text);
        if ($total === 0) return 0.0;
        $lw = 0;
        foreach ($p->query('.//a', $n) as $a) $lw += $this->wc($this->nodeText($p, $a));
        return min(1.0, $lw / $total);
    }

    /** true, якщо вузол у меню / шапці / футері / сайдбарі (нижче $stop). */
    private function inChrome(DOMNode $n, ?DOMNode $stop = null): bool
    {
        for ($a = $n->parentNode; $a instanceof DOMElement; $a = $a->parentNode) {
            if ($stop !== null && $a->isSameNode($stop)) return false;
            $t = strtolower($a->nodeName);
            if (isset(self::CHROME_TAGS[$t])) return true;
            $r = strtolower($a->getAttribute('role'));
            if ($r !== '' && isset(self::CHROME_ROLES[$r])) return true;
            if ($t === 'body') return false;
        }
        return false;
    }

    private function isHiddenOrSkipped(DOMNode $n): bool
    {
        for ($a = $n instanceof DOMElement ? $n : $n->parentNode; $a instanceof DOMElement; $a = $a->parentNode) {
            $t = strtolower($a->nodeName);
            if (isset(self::SKIP_ANCESTORS[$t])) return true;
            if ($a->hasAttribute('hidden')) return true;
            $st = $a->getAttribute('style');
            if ($st !== '' && preg_match('/(display\s*:\s*none|visibility\s*:\s*hidden)/i', $st)) return true;
            if ($t === 'body') break;
        }
        return false;
    }

    /** Кількість змістовних списків / таблиць / dl в основному контенті. */
    private function structureCounts(Page $p, DOMElement $root): array
    {
        $lists = 0; $tables = 0; $dls = 0;
        foreach ($p->query('.//ul | .//ol', $root) as $l) {
            if ($this->inChrome($l, $root) || $this->isHiddenOrSkipped($l)) continue;
            if ($p->query('ancestor::li', $l)) continue;
            $lis = $p->query('./li', $l);
            if (count($lis) < 2) continue;
            $total = 0; $okItems = 0; $nonEmpty = 0;
            foreach ($lis as $li) {
                $w = $this->wc($this->nodeText($p, $li));
                $total += $w;
                if ($w >= 1) $nonEmpty++;
                if ($w >= 2) $okItems++;
            }
            if (!($okItems >= 2 || $nonEmpty >= 3) || $total < 4) continue;
            if ($this->linkShare($p, $l, $this->nodeText($p, $l)) > 0.6) continue;
            $lists++;
        }
        foreach ($p->query('.//table', $root) as $t) {
            if ($this->inChrome($t, $root) || $this->isHiddenOrSkipped($t)) continue;
            if ($p->query('.//table', $t)) continue;
            $rows = $p->query('.//tr', $t);
            if (count($rows) < 2) continue;
            $maxCells = 0;
            foreach ($rows as $r) $maxCells = max($maxCells, count($p->query('./td | ./th', $r)));
            if ($maxCells >= 2) $tables++;
        }
        foreach ($p->query('.//dl', $root) as $d) {
            if ($this->inChrome($d, $root) || $this->isHiddenOrSkipped($d)) continue;
            if (count($p->query('./dt | ./div/dt', $d)) >= 2) $dls++;
        }
        return ['lists' => $lists, 'tables' => $tables, 'dls' => $dls];
    }

    private function findH1Node(Page $p, string $text): ?DOMElement
    {
        foreach ($p->query('//body//h1') as $h) {
            if (!($h instanceof DOMElement) || $this->isHiddenOrSkipped($h)) continue;
            $t = $this->nodeText($p, $h);
            if ($t === '') continue;
            if ($t === $text || mb_strtolower($t) === mb_strtolower($text) || ($text !== '' && mb_strpos($t, mb_substr($text, 0, 20)) !== false)) return $h;
        }
        return null;
    }

    /**
     * Перший змістовний фрагмент після H1 (у порядку документа).
     * @return array{type:string,text:string,words:int,next:string,next_tag:string}
     */
    private function introAfterH1(Page $p, DOMElement $h1): array
    {
        // предки H1 (масив тримає посилання на вузли, тож spl_object_id не перевикористовується)
        $anc = [];
        for ($a = $h1->parentNode; $a instanceof DOMElement; $a = $a->parentNode) $anc[spl_object_id($a)] = $a;
        $nodes = $p->query('following::*[self::p or self::div or self::section or self::blockquote or self::span or self::h2 or self::h3 or self::h4 or self::ul or self::ol or self::table][position() <= 220]', $h1);
        $none = ['type' => 'none', 'text' => '', 'words' => 0, 'next' => '', 'next_tag' => ''];
        foreach ($nodes as $n) {
            if (!($n instanceof DOMElement)) continue;
            if ($this->isHiddenOrSkipped($n) || $this->inModal($n)) continue;
            // меню / футер, у яких H1 не лежить
            $skip = false;
            for ($a = $n->parentNode; $a instanceof DOMElement; $a = $a->parentNode) {
                $t = strtolower($a->nodeName);
                $r = strtolower($a->getAttribute('role'));
                if ((isset(self::CHROME_TAGS[$t]) || ($r !== '' && isset(self::CHROME_ROLES[$r]))) && !isset($anc[spl_object_id($a)])) { $skip = true; break; }
                if ($t === 'body') break;
            }
            $tag = strtolower($n->nodeName);
            if (!$skip && isset(self::CHROME_TAGS[$tag])) $skip = true;
            if ($skip) continue;
            if ($tag === 'h2' || $tag === 'h3' || $tag === 'h4') {
                $t = $this->nodeText($p, $n);
                if ($t !== '' && !preg_match(self::RX_MODAL_H1, $t)) return ['type' => 'heading', 'text' => '', 'words' => 0, 'next' => $t, 'next_tag' => $tag];
                continue;
            }
            if ($tag === 'ul' || $tag === 'ol' || $tag === 'table') {
                $t = $this->nodeText($p, $n);
                $w = $this->wc($t);
                if ($w >= 6 && $this->linkShare($p, $n, $t) < 0.6) return ['type' => 'list', 'text' => $t, 'words' => $w, 'next' => '', 'next_tag' => ''];
                continue;
            }
            $t = ($tag === 'p' || $tag === 'blockquote') ? $this->nodeText($p, $n) : $this->directText($p, $n);
            $w = $this->wc($t);
            if ($w < 12) continue;
            if (preg_match(self::RX_MODAL_TEXT, $t) && $w < 60) continue;
            if ($w < 25 && preg_match(self::RX_CTA_START, $t)) continue;
            if ($this->linkShare($p, $n, $t) > 0.7) continue;
            return ['type' => 'p', 'text' => $t, 'words' => $w, 'next' => '', 'next_tag' => ''];
        }
        return $none;
    }

    /** Пари «характеристика - значення» з блоку після заголовка характеристик. */
    private function specPairs(Page $p, string $heading): array
    {
        $pairs = []; $raw = '';
        foreach ($p->query('//body//*[self::h2 or self::h3 or self::h4]') as $h) {
            if ($this->nodeText($p, $h) !== $heading) continue;
            $container = $h->parentNode;
            if (!($container instanceof DOMElement)) break;
            $raw = trim(mb_substr($this->nodeText($p, $container), mb_strlen($heading)));
            $after = false;
            foreach ($container->childNodes as $c) {
                if ($c->isSameNode($h)) { $after = true; continue; }
                if (!$after || !($c instanceof DOMElement)) continue;
                $kids = [];
                foreach ($c->childNodes as $k) if ($k instanceof DOMElement && $this->nodeText($p, $k) !== '') $kids[] = $k;
                if (count($kids) === 2) {
                    $k1 = $this->nodeText($p, $kids[0]);
                    $v1 = $this->nodeText($p, $kids[1]);
                    if ($k1 !== '' && $v1 !== '' && $this->wc($k1) <= 8 && $this->wc($v1) <= 15) $pairs[] = [$k1, $v1];
                }
                if (count($pairs) >= 15) break;
            }
            break;
        }
        return [$pairs, $raw];
    }

    // ================================================================== dates

    /**
     * Дати статті: machine (JSON-LD / meta / time[datetime]) і visible (у тексті біля H1 або з підписом).
     * @return array{machine:array,visible:array,latest:?int,autogen:?array}
     */
    private function articleDates(Page $p, string $h1): array
    {
        $machine = []; $visible = []; $autogen = null; $inconsistent = null; $standalone = null;
        $want = Page::expandTypes(['Article', 'WebPage', 'HowTo', 'Recipe', 'Guide']);
        foreach ($p->schemaItems() as $it) {
            $match = false;
            foreach ($it['types'] as $t) if (isset($want[strtolower($t)])) { $match = true; break; }
            if (!$match) continue;
            foreach (['dateModified', 'datePublished'] as $k) {
                $v = $it['data'][$k] ?? null;
                if (is_array($v)) $v = $v['@value'] ?? (isset($v[0]) && is_string($v[0]) ? $v[0] : null);
                if (!is_string($v) || trim($v) === '') continue;
                $ts = $this->parseIso($v);
                if ($ts === null) continue;
                $machine[] = ['ts' => $ts, 'src' => ($it['source'] === 'json-ld' ? 'JSON-LD ' : 'мікророзмітка ') . $k, 'raw' => trim($v), 'kind' => $k];
            }
            // суперечливі дати в одному записі: публікація пізніша за оновлення
            $dp = $it['data']['datePublished'] ?? null; $dmod = $it['data']['dateModified'] ?? null;
            if (is_string($dp) && is_string($dmod) && $inconsistent === null) {
                $tp = $this->parseIso($dp); $tm = $this->parseIso($dmod);
                if ($tp !== null && $tm !== null && $tp - $tm > 3600) {
                    $inconsistent = ['type' => 'order', 'published' => trim($dp), 'modified' => trim($dmod)];
                }
            }
        }
        $meta = $p->metaTags();
        foreach (['article:modified_time' => 'dateModified', 'og:updated_time' => 'dateModified', 'article:published_time' => 'datePublished'] as $k => $kind) {
            if (!empty($meta[$k]) && ($ts = $this->parseIso($meta[$k])) !== null) $machine[] = ['ts' => $ts, 'src' => $k, 'raw' => $meta[$k], 'kind' => $kind];
        }
        $root = $this->mainRoot($p);
        if ($root) {
            foreach ($p->query('.//time', $root) as $t) {
                if (!($t instanceof DOMElement) || $this->isHiddenOrSkipped($t) || $p->query('ancestor::a', $t)) continue;
                if ($this->inChrome($t, $root) && !$p->query('ancestor::article', $t)) continue;
                $dt = trim($t->getAttribute('datetime'));
                $ts = $dt !== '' ? $this->parseIso($dt) : $this->parseVisibleDate($this->nodeText($p, $t));
                if ($ts !== null) {
                    $visible[] = ['ts' => $ts, 'src' => '<time> на сторінці', 'raw' => $dt !== '' ? $dt : $this->nodeText($p, $t)];
                    break;
                }
            }
        }
        if (!$visible) {
            $main = $p->mainText();
            $rxDate = '(\d{1,2}[.\/]\d{1,2}[.\/]\d{4}|\d{1,2}\s+\p{L}+\s+\d{4}|\p{L}+\s+\d{1,2},?\s+\d{4}|\d{4}-\d{2}-\d{2})';
            if (preg_match('/(опубліковано|оновлено|дата публікації|дата оновлення|останнє оновлення|опубликовано|обновлено|published|updated|last updated)\s*:?\s*' . $rxDate . '/iu', $main, $m)) {
                $ts = $this->parseVisibleDate($m[2]);
                if ($ts !== null) $visible[] = ['ts' => $ts, 'src' => 'видима дата («' . mb_strtolower($m[1]) . '»)', 'raw' => $m[2]];
            }
            if (!$visible && $h1 !== '') {
                $pos = mb_strpos($main, $h1);
                if ($pos !== false) {
                    $win = mb_substr($main, max(0, $pos - 200), 200 + mb_strlen($h1) + 300);
                    if (preg_match('/' . $rxDate . '/u', $win, $m)) {
                        $ts = $this->parseVisibleDate($m[1]);
                        if ($ts !== null) $visible[] = ['ts' => $ts, 'src' => 'видима дата біля заголовка', 'raw' => $m[1]];
                    }
                }
            }
            // окремий елемент, весь текст якого - дата (напр. <span><svg/> 13.08.2026</span> під статтею)
            if (!$visible && $root) {
                $rxOnly = '/^(\d{1,2}[.\/]\d{1,2}[.\/]\d{4}|\d{1,2}\s+\p{L}+\s+\d{4}(\s*р\.?)?|\d{4}-\d{2}-\d{2})$/u';
                foreach ($p->query('.//*[self::span or self::div or self::p or self::small or self::li or self::dd]', $root) as $el) {
                    if (!($el instanceof DOMElement) || $p->query('ancestor::a', $el) || $p->query('.//a', $el)) continue;
                    $t = $this->nodeText($p, $el);
                    if ($t === '' || mb_strlen($t) > 22 || !preg_match($rxOnly, $t)) continue;
                    if ($this->isHiddenOrSkipped($el) || $this->inModal($el)) continue;
                    if ($this->inChrome($el, $root) && !$p->query('ancestor::article', $el)) continue;
                    $ts = $this->parseVisibleDate($t);
                    if ($ts !== null) { $standalone = ['ts' => $ts, 'src' => 'видима дата на сторінці', 'raw' => $t]; break; }
                }
                if ($standalone) $visible[] = $standalone;
            }
        }
        // dateModified, що збігається з часом відповіді сервера (±15 хв), - ймовірно, генерується при кожному запиті
        foreach ($machine as $x) {
            if ($x['kind'] === 'dateModified' && preg_match('/T\d{2}:\d{2}/', $x['raw']) && abs($this->now - $x['ts']) <= 900) { $autogen = $x; break; }
        }
        $trusted = $machine;
        if ($autogen) {
            $rest = array_values(array_filter($machine, function ($x) use ($autogen) { return $x['ts'] !== $autogen['ts']; }));
            if ($rest || $visible) $trusted = $rest;
        }
        // дата в майбутньому відносно відповіді сервера (з поясом - точно; без поясу чи без часу - із запасом)
        $future = function (array $x): bool {
            $raw = (string)$x['raw'];
            if (preg_match('/T\d{2}:\d{2}.*([+-]\d{2}:?\d{2}|Z)$/i', $raw)) $tol = 1800;
            elseif (preg_match('/T\d{2}:\d{2}/', $raw)) $tol = 4 * 3600;
            else $tol = 86400;
            return $x['ts'] > $this->now + $tol;
        };
        foreach ($machine as $x) {
            if ($inconsistent === null && $future($x)) $inconsistent = ['type' => 'future', 'raw' => $x['raw'], 'src' => $x['src']];
        }
        $latest = null; $latestDay = null; $latestSrc = null;
        // окрема дата-елемент може належати блоку «інші статті», тож при машинних датах вона лише підтверджує видимість
        $cands = ($standalone && $trusted) ? $trusted : array_merge($trusted, $visible);
        foreach ($cands as $x) {
            if (!$future($x) && ($latest === null || $x['ts'] > $latest)) {
                $latest = $x['ts'];
                $latestSrc = $x['src'];
                $latestDay = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$x['raw'], $dm) ? $dm[3] . '.' . $dm[2] . '.' . $dm[1] : date('d.m.Y', $x['ts']);
            }
        }
        return ['machine' => $trusted, 'visible' => $visible, 'latest' => $latest, 'latest_day' => $latestDay, 'latest_src' => $latestSrc, 'autogen' => $autogen, 'inconsistent' => $inconsistent];
    }

    private function parseIso(string $v): ?int
    {
        $v = trim($v);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return $this->parseVisibleDate($v);
        $ts = strtotime($v);
        if ($ts === false) $ts = strtotime(substr($v, 0, 10));
        if ($ts === false) return null;
        $y = (int)date('Y', $ts);
        return ($y >= 1995 && $y <= (int)date('Y', $this->now) + 1) ? $ts : null;
    }

    private function parseVisibleDate(string $s): ?int
    {
        $s = mb_strtolower(trim($s));
        $d = $mo = $y = 0;
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $s, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
        elseif (preg_match('/(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', $s, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3]; }
        elseif (preg_match('/(\d{1,2})\s+(\p{L}+)\s+(\d{4})/u', $s, $m) && isset(self::MONTHS[$m[2]])) { $d = (int)$m[1]; $mo = self::MONTHS[$m[2]]; $y = (int)$m[3]; }
        elseif (preg_match('/(\p{L}+)\s+(\d{1,2}),?\s+(\d{4})/u', $s, $m) && isset(self::MONTHS[$m[1]])) { $mo = self::MONTHS[$m[1]]; $d = (int)$m[2]; $y = (int)$m[3]; }
        if (!$y || !checkdate($mo, $d, $y)) return null;
        if ($y < 1995 || $y > (int)date('Y', $this->now) + 1) return null;
        $ts = mktime(12, 0, 0, $mo, $d, $y);
        return $ts === false ? null : $ts;
    }

    // ================================================================== text helpers

    private function wc(string $s): int
    {
        if ($s === '') return 0;
        $n = preg_match_all('/[^\s\x{00A0}]*[\p{L}\p{N}][^\s\x{00A0}]*/u', $s);
        return $n === false ? 0 : (int)$n;
    }

    /** Речення (розбиття за .!? перед великою літерою / цифрою / лапками). */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?…])\s+(?=[\p{Lu}\d«"„(])/u', trim($text));
        if (!is_array($parts)) return [trim($text)];
        return array_values(array_filter(array_map('trim', $parts), function ($s) { return $s !== ''; }));
    }

    private function splitParagraphs(string $text, int $target): array
    {
        $out = []; $cur = ''; $cw = 0;
        foreach ($this->sentences($text) as $s) {
            $w = $this->wc($s);
            if ($cw > 0 && $cw + $w > $target) { $out[] = $cur; $cur = ''; $cw = 0; }
            $cur = $cur === '' ? $s : $cur . ' ' . $s;
            $cw += $w;
        }
        if ($cur !== '') $out[] = $cur;
        return $out;
    }

    /** Блок схожий на звичайний текст (не перелік назв / брендів / цін без розділових знаків). */
    private function isProse(string $t, int $words): bool
    {
        if ($words < 12) return true;
        if (!preg_match('/[.!?;:,]/u', $t)) return false;
        $caps = (int)preg_match_all('/(?<!\p{L})\p{Lu}/u', $t);
        return $caps / max(1, $words) <= 0.5;
    }

    /** Речення з перевірюваним фактом: цифра (не в телефоні) або посилання на джерело даних. */
    private function hasFact(string $s): bool
    {
        if (preg_match('/\d/u', $this->stripPhones($s))) return true;
        return (bool)preg_match(self::RX_SOURCE, $s);
    }

    /** Прибирає лише справжні телефонні номери, не чіпаючи «1 000 000», «2015-2020», «1500-3000 грн». */
    private function stripPhones(string $s): string
    {
        if (!preg_match('/\d/u', $s)) return $s;
        $out = preg_replace_callback(self::RX_PHONE_CAND, function ($m) {
            return self::isPhone($m[0]) ? ' ' : $m[0];
        }, $s);
        return $out ?? $s;
    }

    /**
     * Телефон: 10-13 цифр і типовий початок (+, (0xx), 0xx, 38 0xx, 80x), без груп-років.
     * Великі числа з пробілами (1 000 000) і діапазони (2015-2020) телефонами не є.
     */
    private static function isPhone(string $m): bool
    {
        $m = trim($m);
        $digits = preg_replace('/\D/', '', $m) ?? '';
        $n = strlen($digits);
        if ($n < 9 || $n > 13) return false;
        if (preg_match('/(?<!\d)(19|20)\d{2}\s*[-–]\s*(19|20)\d{2}(?!\d)/u', $m)) return false;
        if ($m[0] === '+') return true;
        if (preg_match('/^\(?0\d{2}\)?[\s\-.]?\d/', $m) && $n >= 9 && $n <= 10) return true;   // 067 123 45 67, (044) 123-45-67, 0 800 ...
        if (preg_match('/^0\s?800/', $m)) return true;
        if (preg_match('/^\(?38\s?\(?0/', $m) && $n >= 11 && $n <= 12) return true;           // 38 067 ..., 380671234567
        return false;
    }

    /**
     * Короткий блок з конкретною цифрою: лічильник («1 300 фондів»), рядок прайсу («1200 ₴»), «24/7 підтримка».
     * Не рахуємо: телефони, самі лише дати / номери, копірайт, нулі («Кошик 0»).
     */
    private function isStatBlock(string $t): bool
    {
        $t = $this->stripPhones($t);
        if (!preg_match('/[1-9]/u', $t)) return false;
        if (preg_match('/©|copyright|\(c\)/iu', $t)) return false;
        // службові лічильники: час читання, перегляди, коментарі, відгуки, пагінація
        if (preg_match('/(хв\.?\s*(читання|на\s+читання)|мин\.?\s*чтения|min\s+read|перегляд|просмотр|views?\b|коментар|комментар|comments?\b|відгук|отзыв|reviews?\b|сторінка\s+\d|страница\s+\d|page\s+\d)/iu', $t)) return false;
        if (preg_match('/^[\d\s.,:\/\-–—+()]+$/u', $t)) return false;
        if (preg_match('/^\s*(\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}|\d{4}-\d{2}-\d{2}|\d{1,2}\s+\p{L}+\s+\d{4})\s*$/u', $t)) return false;
        return (bool)preg_match('/\p{L}{2,}|[₴$€%]/u', $t);
    }

    private function isQuestion(string $t): bool
    {
        $t = trim($t);
        if ($t === '') return false;
        if (mb_strpos($t, '?') !== false) return true;
        return (bool)preg_match(self::RX_QUESTION, $t);
    }

    /** Чи мають H1 і title спільне значуще слово (за основою з 5 літер). */
    private function overlaps(string $a, string $b): bool
    {
        $la = mb_strtolower($a); $lb = mb_strtolower($b);
        if ($la === '' || $lb === '') return true;
        if (mb_strpos($lb, $la) !== false || mb_strpos($la, $lb) !== false) return true;
        $stems = function (string $s): array {
            preg_match_all('/\p{L}[\p{L}\'’ʼ-]*/u', $s, $m);
            $out = [];
            foreach ($m[0] as $w) {
                $w = str_replace(['’', 'ʼ'], "'", $w);
                if (mb_strlen($w) < 4) continue;
                $out[mb_substr($w, 0, 5)] = true;
            }
            return $out;
        };
        $sa = $stems($la);
        if (!$sa) return true; // H1 з коротких слів / цифр - не судимо
        return (bool)array_intersect_key($sa, $stems($lb));
    }

    /**
     * Речення без цифр для прикладу: розмиті кількісні слова («зазвичай швидше», «кілька») або маркетингові штампи.
     * @return array{0:string,1:string} [речення, 'hedge'|'vague'|'plain']
     */
    private function pickVague(array $pages): array
    {
        $best = ['', 'plain']; $rank = 0;
        foreach ($pages as $a) {
            $isArt = $a['role'] === 'article';
            foreach ($a['sentences'] as $s) {
                $w = $this->wc($s);
                if ($w < 9 || $w > 40 || $this->hasFact($s)) continue;
                if (!preg_match('/^[\p{Lu}«"]/u', $s) || !preg_match('/[.!]$/u', $s)) continue;
                if ($this->isQuestion($s)) continue;
                $hedge = (bool)preg_match(self::RX_HEDGE, $s);
                $vague = (bool)preg_match(self::RX_VAGUE, $s);
                if ($isArt) $r = $hedge ? 3 : ($vague ? 2 : 1);
                else $r = $vague ? 3 : ($hedge ? 2 : 1);
                if ($r > $rank) { $rank = $r; $best = [$s, ($isArt && $hedge) ? 'hedge' : ($vague ? 'vague' : ($hedge ? 'hedge' : 'plain'))]; }
                if ($rank === 3) return $best;
            }
        }
        return $best;
    }

    /** Речення-перелік (3+ коми) для прикладу списку. @return array{0:string,1:array} */
    private function findEnumeration(array $pages): array
    {
        $clauseStart = '/^(що|якщо|де|який|яка|яке|які|коли|щоб|бо|тому|але|а\s|проте|хоча|так\s|that|which|if|when|where|because|but|что|если|где|который|когда|чтобы|но)\b/iu';
        foreach ($pages as $a) {
            foreach ($a['sentences'] as $s) {
                $w = $this->wc($s);
                if ($w < 8 || $w > 70 || substr_count($s, ',') < 2) continue;
                $colon = mb_strpos($s, ':');
                $hasColon = $colon !== false && $colon < mb_strlen($s) - 10;
                $body = $hasColon ? mb_substr($s, $colon + 1) : $s;
                $parts = preg_split('/,\s*|\s+(?:та|і|й|and|и)\s+(?=[^,]*$)/u', $body) ?: [];
                $items = [];
                foreach ($parts as $x) {
                    $x = trim($x, " .;!\t");
                    if ($x !== '') $items[] = $x;
                }
                if (count($items) < 3) continue;
                $maxW = $hasColon ? 8 : 4;
                $bad = false;
                foreach ($items as $it) {
                    if ($this->wc($it) > $maxW || preg_match($clauseStart, $it)) { $bad = true; break; }
                }
                if ($bad || (!$hasColon && count($items) < 4)) continue;
                return [$s, array_map(function ($x) { $x = str_replace([' — ', ' – '], ' - ', $x); return mb_strtoupper(mb_substr($x, 0, 1)) . mb_substr($x, 1); }, $items)];
            }
        }
        return ['', []];
    }

    // ================================================================== suggestions

    /** Тема сторінки: H1 або змістовна частина title. */
    private function pageTopic(array $a): string
    {
        if (!empty($a['h1real'][0]) && empty($a['h1_generic'])) return self::mbTrunc($a['h1real'][0], 90);
        $real = $this->realTitleHeading($a);
        if ($real !== '') return self::mbTrunc($real, 90);
        return self::mbTrunc($this->titleCore($a['title']), 90);
    }

    /** H2, що збігається з title (для сторінок, де H1 зайнятий вікном). */
    private function realTitleHeading(array $a): string
    {
        foreach (array_slice($a['h2'], 0, 5) as $t) {
            if ($this->wc($t) <= 15 && $a['title'] !== '' && $this->overlaps($t, $a['title'])) return $t;
        }
        return '';
    }

    private function suggestH1(array $a): string
    {
        $core = $this->titleCore($a['title']);
        if ($a['role'] === 'about' && $this->brand !== '' && ($core === '' || $this->wc($core) <= 2) && mb_stripos($core, $this->brand) === false) {
            return 'Про ' . $this->brand;
        }
        if ($core === '') return '[ВКАЖІТЬ тему сторінки]';
        return self::mbTrunc(mb_strtoupper(mb_substr($core, 0, 1)) . mb_substr($core, 1), 100);
    }

    /** Найзмістовніша частина title без бренду. */
    private function titleCore(string $title): string
    {
        $title = trim($title);
        if ($title === '') return '';
        $parts = preg_split('/\s+[|—–\-·]\s+|\s*\|\s*|:\s+/u', $title) ?: [$title];
        $brand = mb_strtolower($this->brand);
        $first = '';
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            $lp = mb_strtolower($p);
            if ($brand !== '' && ($lp === $brand || (mb_strlen($lp) <= mb_strlen($brand) + 2 && mb_strpos($lp, $brand) !== false))) continue;
            if ($this->wc($p) >= 2) return $p;
            if ($first === '') $first = $p;
        }
        return $first !== '' ? $first : $title;
    }

    private function h2Outline(array $a): string
    {
        switch ($a['role']) {
            case 'about':
                if ($this->siteType === 'personal') {
                    return "<h2>Чим я займаюся і кому допомагаю</h2>\n<h2>Досвід і місця роботи</h2>\n<h2>Кейси з результатами в цифрах</h2>\n<h2>Публікації, виступи, сертифікати</h2>";
                }
                $b = $this->brand !== '' ? $this->brand : 'компанія';
                return "<h2>Хто такі " . $b . " і чим займаємося</h2>\n<h2>Наша історія в цифрах</h2>\n<h2>Команда та експерти</h2>\n<h2>Ліцензії, сертифікати й нагороди</h2>";
            case 'service':
                $t = $this->pageTopic($a);
                $first = $this->isShortTopic($t, 8) ? 'Що входить у ' . self::qt($t) : 'Що входить у послугу';
                return "<h2>" . $first . "</h2>\n<h2>Для кого це і в яких випадках підходить</h2>\n<h2>Ціни, терміни та умови</h2>\n<h2>Питання та відповіді</h2>";
            default:
                $t = $this->pageTopic($a);
                $first = $this->isShortTopic($t, 6) && $this->siteType !== 'media' ? 'Коротко: головне про ' . self::qt($t) : 'Коротко: головне в 2-3 реченнях';
                return "<h2>" . $first . "</h2>\n<h2>[ВКАЖІТЬ розділ 1 - питання читача]</h2>\n<h2>[ВКАЖІТЬ розділ 2]</h2>\n<h2>Висновки</h2>";
        }
    }

    /** Тема придатна для шаблону питання: коротка, без двокрапки, цифр і питального знаку. */
    private function isShortTopic(string $t, int $maxWords = 6): bool
    {
        $t = trim($t);
        if ($t === '' || $this->wc($t) > $maxWords) return false;
        if ($this->isQuestion($t)) return false; // «Як обрати лікаря» вже питання, у «Що таке ...?» не підставляємо
        return !preg_match('/[:?!\d]|\.\.\./u', $t);
    }

    private function questionIdeas(array $a): array
    {
        $t = $this->pageTopic($a);
        $b = $this->brand !== '' ? $this->brand : 'компанія';
        switch ($a['role']) {
            case 'service':
                if (!$this->isShortTopic($t, 8)) return ['Що входить у послугу і для кого вона?', 'Скільки коштує і від чого залежить ціна?', $this->siteType === 'local' ? 'Як записатися або забронювати?' : 'Скільки часу займає робота і коли буде результат?'];
                return ['Що входить у ' . self::qt($t) . '?', 'Скільки коштує ' . self::qt($t) . ' і від чого залежить ціна?', $this->siteType === 'local' ? 'Як записатися або забронювати?' : 'Скільки часу займає робота і коли буде результат?'];
            case 'article':
                // новина, добірка чи довгий заголовок у шаблон «Що таке ...?» не підставляємо
                if ($this->siteType === 'media' || !$this->isShortTopic($t, 6)) {
                    return ['[ВКАЖІТЬ головне питання, на яке відповідає стаття]', '[ВКАЖІТЬ уточнювальне питання читача: як, коли, для кого]', '[ВКАЖІТЬ практичне питання: що робити далі]'];
                }
                return ['Що таке ' . self::qt($t) . ' простими словами?', 'Як це працює на практиці?', '[ВКАЖІТЬ ще одне питання читача з People Also Ask]'];
            case 'about':
                if ($this->siteType === 'personal') return ['Хто такий ' . $b . ' і на чому спеціалізується?', 'Скільки років досвіду і з якими проєктами працював ' . $b . '?', 'Як замовити консультацію в ' . $b . '?'];
                return ['Чим займається ' . $b . '?', 'Скільки років ' . $b . ' на ринку і скільки має клієнтів?', 'Чим ' . $b . ' відрізняється від інших?'];
            default:
                return ['Що пропонує ' . $b . '?', 'Скільки коштують послуги / товари ' . $b . '?', 'Як замовити і скільки чекати?'];
        }
    }

    private function factPlaceholder(string $role, bool $hasPrices = false): string
    {
        switch ($role) {
            case 'article': return '[ВКАЖІТЬ конкретику: цифру, термін, частоту, % або дані дослідження з джерелом]';
            case 'service': return $hasPrices
                ? '[ВКАЖІТЬ конкретику: терміни, тривалість, кількість процедур чи проєктів, результат у цифрах]'
                : '[ВКАЖІТЬ конкретику: терміни, ціну «від», кількість проєктів, результат у цифрах]';
            case 'about': return $this->siteType === 'personal'
                ? '[ВКАЖІТЬ конкретику: роки досвіду, кількість проєктів чи клієнтів, результат кейсу в цифрах]'
                : '[ВКАЖІТЬ конкретику: рік заснування, кількість клієнтів, розмір команди, міста]';
            default: return '[ВКАЖІТЬ конкретику: роки на ринку, кількість клієнтів, асортимент, географія]';
        }
    }

    private function leadTemplate(array $a): string
    {
        $t = $this->pageTopic($a);
        switch ($a['role']) {
            case 'service':
                return self::qt($t) . ' - [ВКАЖІТЬ у 1-2 реченнях: що саме робите, для кого і ключовий факт - терміни, ціна від, результат]';
            case 'article':
                return '[ВКАЖІТЬ коротку відповідь на питання статті в 1-2 реченнях, до 50 слів]';
            case 'about':
                return ($this->brand !== '' ? $this->brand : '[Назва]') . ' - [ВКАЖІТЬ, хто ви, з якого року, де працюєте і скільки клієнтів]';
            default:
                return ($this->brand !== '' ? $this->brand : '[Назва]') . ' - [ВКАЖІТЬ у 1-2 реченнях: що пропонуєте, для кого і чим відрізняєтеся]';
        }
    }

    private function descriptionSuggestion(array $x): string
    {
        $a = $x['a'] ?? null;
        $src = '';
        if (is_array($a)) {
            if (!empty($a['intro']) && $a['intro']['type'] === 'p') $src = $a['intro']['text'];
            elseif (!empty($a['good'])) $src = $a['good'][0]['text'];
        }
        if ($src !== '') {
            $out = '';
            foreach ($this->sentences($src) as $s) {
                if ($out !== '' && mb_strlen($out . ' ' . $s) > 170) break;
                $out = trim($out . ' ' . $s);
            }
            if ($out !== '' && mb_strlen($out) <= 200) return $out;
            return self::mbTrunc($src, 160);
        }
        /** @var Page $p */
        $p = $x['page'];
        $topic = $p->title() !== '' ? $this->titleCore($p->title()) : '[ВКАЖІТЬ тему]';
        return $topic . ': [ВКАЖІТЬ суть сторінки і конкретику - що, для кого, ключовий факт]';
    }

    /** Зображення для og:image: link[rel=image_src] або logo/image з розмітки Organization. '' - не знайдено. */
    private function imageCandidate(Page $p): string
    {
        foreach ($p->query('//link[@rel="image_src"][@href]') as $l) {
            $u = Crawler::absolutize($l->getAttribute('href'), $p->url());
            if ($u) return $u;
        }
        foreach ($p->schemaItemsOfType(['Organization', 'WebSite']) as $it) {
            foreach (['image', 'logo'] as $k) {
                $v = $it['data'][$k] ?? null;
                if (is_array($v)) $v = $v['url'] ?? ($v['contentUrl'] ?? ($v[0] ?? null));
                if (is_array($v)) $v = $v['url'] ?? null;
                if (is_string($v) && preg_match('#^https?://#i', $v) && !preg_match('/\.svg(\?|$)/i', $v)) return $v;
            }
        }
        return '';
    }

    // ================================================================== misc static

    /** Текст у лапках «»; лапки всередині стають „“, щоб не було «...«...»...». */
    private static function qt(string $s): string
    {
        return '«' . self::innerQ($s) . '»';
    }

    private static function innerQ(string $s): string
    {
        return str_replace(['«', '»'], ['„', '“'], $s);
    }

    /** Довгі тире -> дефіс (стиль звіту). */
    private static function dashes(string $s): string
    {
        return str_replace([' — ', ' – ', '—', '–'], [' - ', ' - ', '-', '-'], $s);
    }

    /** Текст, обрізаний на сайті («...коли вперше гості пр...»): прибирає три крапки й недописане слово. */
    private static function dropCutTail(string $s): string
    {
        $s = rtrim($s);
        if (!preg_match('/(\.\.\.|…)$/u', $s)) return $s;
        $s = rtrim((string)preg_replace('/(\.\.\.|…)$/u', '', $s));
        // останнє слово, ймовірно, обрізане посередині
        $cut = preg_replace('/\s+\S+$/u', '', $s);
        return rtrim((string)($cut !== null && $cut !== '' ? $cut : $s), " ,;:-");
    }

    private static function sevRank(string $s): int
    {
        return ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3][$s] ?? 0;
    }

    private static function maxSev(?string $a, string $b): string
    {
        $o = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];
        if ($a === null) return $b;
        return ($o[$b] ?? 0) > ($o[$a] ?? 0) ? $b : $a;
    }

    /** «21 слово», «3 слова», «25 слів». */
    private static function nw(int $n): string
    {
        return $n . ' ' . self::plural($n, 'слово', 'слова', 'слів');
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

    /** Підпис ролі сторінки (для 'about' залежить від типу сайту) або null. */
    private static function roleName(string $role): ?string
    {
        return $role === 'about' ? self::$aboutLabel : (self::ROLE_LABELS[$role] ?? null);
    }

    private static function pageLabel(string $role, string $url): string
    {
        $label = self::roleName($role) ?? 'Сторінка';
        if ($role === 'home') return $label;
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $path = rawurldecode($path);
        if ($path === '' || $path === '/') return $label;
        return $label . ' (' . self::mbTrunc($path, 60) . ')';
    }

    private static function urlKey(string $u): string
    {
        $u = strtolower(preg_replace('#^https?://(www\.)?#i', '', $u) ?? $u);
        return rtrim(preg_replace('/[?#].*$/', '', $u) ?? $u, '/');
    }

    private static function attr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }

    private static function ukDate(int $ts): string
    {
        $m = ['', 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
        return (int)date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    }

    /** Час відповіді сервера за заголовком Date (або поточний). */
    private static function responseTime(array $res): int
    {
        $d = $res['headers']['date'] ?? null;
        if (is_array($d)) $d = end($d);
        if (is_string($d) && $d !== '') {
            $ts = strtotime($d);
            if ($ts !== false && abs($ts - time()) < 86400 * 2) return $ts;
        }
        return time();
    }

    private static function blockReasonUk(string $r, int $status): string
    {
        if ($r === '') return $status > 0 ? 'HTTP ' . $status : '';
        $map = ['cloudflare' => 'захист Cloudflare', 'ddos-guard' => 'захист DDoS-Guard', 'imperva' => 'захист Imperva',
            'sucuri' => 'захист Sucuri', 'akamai' => 'захист Akamai', 'datadome' => 'захист DataDome', 'vercel' => 'перевірка Vercel',
            'captcha' => 'капча', 'access_denied' => 'доступ заборонено', 'connection' => 'немає з\'єднання', 'empty_body' => 'порожня відповідь'];
        if (isset($map[$r])) return $map[$r];
        if (preg_match('/^http_(\d+)$/', $r, $m)) return 'HTTP ' . $m[1];
        return $r;
    }

    private static function sampleReasonUk(array $s): string
    {
        $ch = (string)($s['challenge'] ?? '');
        $st = (int)($s['res']['status'] ?? 0);
        if ($ch !== '') return self::blockReasonUk($ch, $st) . ($st ? ', HTTP ' . $st : '');
        if ($st === 0) return 'не вдалося завантажити';
        if ($st >= 400) return 'HTTP ' . $st;
        return 'не HTML або порожня відповідь';
    }
}
