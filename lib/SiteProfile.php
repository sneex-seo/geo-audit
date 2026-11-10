<?php
declare(strict_types=1);

require_once __DIR__ . '/Crawler.php';
require_once __DIR__ . '/Page.php';

/**
 * Профіль сайту: тип (магазин / локальний бізнес / медіа / SaaS / послуги / корпоративний / особистий сайт експерта),
 * бренд, мови - і вибір внутрішніх сторінок для вибірки.
 *
 *   $cands   = SiteProfile::pickSamples($homePage, $url, $robotsRes);   // [['role','url','via'], ...]
 *   $profile = SiteProfile::detect($homePage, $samples, $url, $robotsBody);
 */
class SiteProfile
{
    // 'personal' - останній: за рівних балів інші типи мають пріоритет (порядок впливає на вибір)
    const LABELS = [
        'ecommerce' => 'Інтернет-магазин',
        'local'     => 'Локальний бізнес',
        'media'     => 'Медіа / блог',
        'saas'      => 'SaaS / продукт',
        'services'  => 'Послуги / B2B',
        'corporate' => 'Корпоративний сайт',
        'personal'  => 'Особистий сайт експерта',
    ];
    /** Типи, які особистий сайт перемагає, якщо головна сутність головної - Person. */
    const PERSONAL_BEATS = ['local', 'services', 'corporate'];
    const MAX_SAMPLES = 4;
    const MIN_POINTS = 3.0;   // менше - fallback 'corporate'

    // ---- шляхи / анкори для ролей сторінок (останній сегмент шляху без розширення)
    const SEG_ABOUT = ['about', 'about-us', 'about_us', 'aboutus', 'pro-nas', 'pro_nas', 'pronas', 'pro-kompaniyu',
        'pro-kompaniiu', 'pro-kompaniju', 'pro-kompaniu', 'pro-kompaniji', 'o-nas', 'o_nas', 'onas', 'o-kompanii',
        'company', 'kompaniya', 'kompaniia', 'kompania', 'who-we-are', 'nasha-kompaniya', 'about-company',
        'pro-brend', 'pro-magazyn', 'o-magazine', 'about-store', 'pro-kliniku', 'o-klinike', 'pro-nas-2'];
    const SEG_CONTACT = ['contacts', 'contact', 'contact-us', 'contacts-us', 'contactus', 'kontakty', 'kontakti',
        'kontakt', 'kontakts', 'kontaktu', 'kontaktna-informatsiia', 'kontaktna-informaciya', 'svyaz', 'zvyazok'];
    const SEG_BLOG = ['blog', 'blogs', 'news', 'novyny', 'novini', 'novosti', 'statti', 'stati', 'statyi', 'statji',
        'articles', 'article', 'insights', 'media', 'journal', 'magazine', 'press', 'press-center', 'presscenter',
        'press-tsentr', 'publikatsii', 'publikacii', 'publications', 'poradi', 'porady', 'korysne', 'korisne',
        'posts', 'stories', 'knowledge', 'baza-znan', 'useful', 'tips'];
    const SEG_SERVICE = ['services', 'service', 'poslugy', 'posluhy', 'poslugi', 'posluhi', 'uslugi', 'usluga',
        'posluga', 'servisy', 'servises', 'solutions', 'rishennya'];
    const SEG_CATALOG = ['catalog', 'katalog', 'catalogue', 'category', 'categories', 'kategoriya', 'kategoriia',
        'kategorii', 'kategorija', 'shop', 'collections', 'collection', 'products', 'tovary', 'goods'];
    const SEG_PRODUCT = ['product', 'products', 'tovar', 'tovary', 'item', 'items', 'goods', 'p', 'pdp'];
    /** «Пропозиція» локального бізнесу: номери, меню, лікарі, ціни - як сторінка послуги. */
    const SEG_OFFER = ['hotel', 'hotels', 'eat', 'food', 'rooms', 'room', 'nomery', 'nomera', 'accommodation', 'menu', 'menyu', 'spa', 'restoran', 'restaurant',
        'doctors', 'likari', 'vrachi', 'specialists', 'fakhivtsi', 'prices', 'price', 'tsiny', 'ceny', 'price-list', 'prais'];

    const RX_ANCHOR_ABOUT = '/^(про нас|про компанію|про компанию|о нас|о компании|about|about us|company|компанія|компания|хто ми|who we are|наша компанія|про бренд|про магазин|про клініку|о клинике|наша історія)$/iu';
    const RX_ANCHOR_CONTACT = '/^(контакти|контакты|contacts?|contact us|зв[\'’ʼ]язатися з нами|наші контакти|зв[\'’ʼ]язок з нами|связаться с нами|адреси магазинів)$/iu';
    const RX_ANCHOR_BLOG = '/^(блог|новини|новости|статті|статьи|blog|news|articles|insights|журнал|медіа|корисне|поради|публікації|прес-центр|пресцентр|press|корисні статті|наш блог)$/iu';
    const RX_ANCHOR_SERVICE = '/^(послуги|услуги|services|our services|наші послуги|наши услуги|послуги та ціни)$/iu';
    const RX_ANCHOR_CATALOG = '/^(каталог|каталог товарів|каталог товаров|catalog|catalogue|всі товари|все товары|shop|магазин|products|продукція)$/iu';
    /** «Про мене» особистого сайту (анкор або останній сегмент шляху). */
    const RX_ANCHOR_ABOUT_ME = '/^(про мене|обо мне|about me|біографія|биография|biography|bio|про автора|об авторе|about the author|моя історія|хто я)$/iu';
    const SEG_ABOUT_ME = ['about-me', 'aboutme', 'pro-mene', 'promene', 'obo-mne', 'bio', 'biography', 'biografiia', 'biohrafiia',
        'biografiya', 'biografia', 'pro-avtora', 'about-author', 'about-the-author', 'my-story'];

    const RX_EXCLUDE_PATH = '#/(cart|basket|korzina|koshyk|checkout|order|orders|login|logout|signin|sign-in|signup|sign-up|register|registration|account|my-account|profile|cabinet|kabinet|search|poisk|compare|comparison|wishlist|favorites|favourites|izbrannoe|obrane|bookmarks|wp-admin|wp-login\.php|admin|feed|rss|tag|tags|author|amp|cdn-cgi|print|share|download)(/|$|\.)#i';
    const RX_ASSET = '/\.(jpe?g|png|gif|webp|svg|avif|ico|bmp|pdf|zip|rar|7z|gz|docx?|xlsx?|pptx?|csv|css|js|json|xml|txt|mp4|webm|mp3|wav|woff2?|ttf|eot|apk|exe|dmg)$/i';

    const UK_MONTHS = 'січня|лютого|березня|квітня|травня|червня|липня|серпня|вересня|жовтня|листопада|грудня';
    const RU_MONTHS = 'января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря';
    const EN_MONTHS = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';

    // ================================================================== detect

    /**
     * Визначає тип сайту, бренд і мови.
     * $samples - елементи ctx['samples'] (потрібні 'role' і 'page').
     * $robotsTxt - тіло robots.txt (дає підказки, навіть коли сайт закрив HTML від нас).
     */
    public static function detect(?Page $home, array $samples, string $url, ?string $robotsTxt = null): array
    {
        $host = Crawler::registrableHost($url);
        $pts = array_fill_keys(array_keys(self::LABELS), 0.0);
        $sig = array_fill_keys(array_keys(self::LABELS), []);
        $add = function (string $type, float $p, string $signal) use (&$pts, &$sig) {
            $pts[$type] += $p;
            $sig[$type][] = $signal;
        };

        $samplePages = [];
        foreach ($samples as $s) {
            if (!empty($s['page']) && $s['page'] instanceof Page) $samplePages[] = ['role' => (string)($s['role'] ?? 'other'), 'page' => $s['page']];
        }

        // ---------------- schema.org
        $allPages = [];
        if ($home) $allPages[] = ['role' => 'home', 'page' => $home];
        foreach ($samplePages as $sp) $allPages[] = $sp;

        // особа - головна сутність головної: вкладені в неї організації (alumniOf, worksFor, affiliation)
        // описують біографію людини, а не тип сайту, тому для сигналів типу їх не беремо
        $person = self::homePerson($home);
        $skipNodes = $person ? $person['nested'] : [];
        $typeHit = function (array $types) use ($allPages, $skipNodes): ?array {
            foreach ($allPages as $ap) {
                $items = $ap['page']->schemaItemsOfType($types);
                if ($items && $skipNodes) {
                    $items = array_values(array_filter($items, function ($it) use ($skipNodes) {
                        return ($it['source'] ?? '') !== 'json-ld' || !isset($skipNodes[md5(serialize($it['data']))]);
                    }));
                }
                if ($items) return ['role' => $ap['role'], 'items' => $items];
            }
            return null;
        };
        // «фізичні» типи: клініки, готелі, заклади харчування, салони, СТО, спортзали ...
        $strongLocalTypes = ['MedicalOrganization', 'MedicalBusiness', 'LodgingBusiness', 'FoodEstablishment',
            'HealthAndBeautyBusiness', 'AutomotiveBusiness', 'SportsActivityLocation', 'EntertainmentBusiness',
            'ChildCare', 'DryCleaningOrLaundry', 'HomeAndConstructionBusiness', 'TravelAgency', 'RealEstateAgent',
            'EducationalOrganization', 'TouristAttraction'];
        $strongLocal = $typeHit($strongLocalTypes);

        // Product: на головній / картці / категорії - магазин; на сторінці послуги - послуги, розмічені як товар
        $productDone = false;
        if ($home && ($n = count($home->schemaItemsOfType(['Product'])))) {
            $add('ecommerce', $strongLocal ? 1.5 : 3, 'schema Product на головній (' . $n . ')');
            $productDone = true;
        }
        foreach ($samplePages as $sp) {
            if ($productDone) break;
            if (!$sp['page']->schemaItemsOfType(['Product'])) continue;
            if ($sp['role'] === 'service') {
                if (!in_array('послуги розмічені як Product', $sig['services'], true)) $add('services', 1, 'послуги розмічені як Product');
                continue;
            }
            $main = $sp['role'] === 'product' || $sp['role'] === 'category';
            $where = $sp['role'] === 'product' ? 'на картці товару' : ($sp['role'] === 'category' ? 'на сторінці категорії' : 'на внутрішній сторінці');
            $add('ecommerce', $strongLocal ? 1.5 : ($main ? 3 : 2), 'schema Product ' . $where);
            $productDone = true;
        }
        if (!$productDone && !$strongLocal && $typeHit(['Offer', 'AggregateOffer'])) $add('ecommerce', 1, 'schema Offer');
        if ($typeHit(['OnlineStore'])) $add('ecommerce', 3, 'schema OnlineStore');
        if ($typeHit(['NewsMediaOrganization'])) $add('media', 4, 'schema NewsMediaOrganization');
        if ($h = $typeHit(['SoftwareApplication'])) {
            $t = $h['items'][0]['types'][0] ?? 'SoftwareApplication';
            $add('saas', 3, 'schema ' . $t);
        }
        if ($typeHit(['Service'])) $add('services', 2, 'schema Service');

        // LocalBusiness і «фізичні» організації
        $lb = $typeHit(array_merge(['LocalBusiness'], $strongLocalTypes));
        if ($lb) {
            $best = null;
            foreach ($lb['items'] as $it) {
                $hasAddr = !empty($it['data']['address']) || !empty($it['data']['geo']);
                if ($best === null || ($hasAddr && !$best[1])) $best = [$it, $hasAddr];
            }
            [$it, $hasAddr] = $best;
            $t = $it['types'][0];
            $lt = array_map('strtolower', $it['types']);
            $isStore = (bool)array_intersect($lt, array_keys(Page::expandTypes(['Store'])));
            $isProf = (bool)array_intersect($lt, array_keys(Page::expandTypes(['ProfessionalService', 'LegalService', 'FinancialService'])));
            $isStrong = (bool)array_intersect($lt, array_keys(Page::expandTypes($strongLocalTypes)));
            if ($isStrong) {
                $add('local', $hasAddr ? 4 : 3.5, 'schema ' . $t . ($hasAddr ? ' з адресою' : ''));
            } elseif ($isStore) {
                $add('ecommerce', 1, 'schema ' . $t);
                if ($hasAddr) $add('local', 1, 'schema ' . $t . ' з адресою');
            } elseif ($isProf) {
                $add('services', 2, 'schema ' . $t);
                if ($hasAddr) $add('local', 1, 'schema ' . $t . ' з адресою');
            } else {
                $add('local', $hasAddr ? 3 : 1.5, 'schema ' . $t . ($hasAddr ? ' з адресою' : ''));
            }
            if (!empty($it['data']['openingHours']) || !empty($it['data']['openingHoursSpecification'])) $add('local', 1, 'графік роботи в розмітці');
        }
        // статті в розмітці головної
        if ($home) {
            $arts = count($home->schemaItemsOfType(['Article']));
            if ($arts >= 3) $add('media', 3, $arts . ' статей у розмітці головної');
            elseif ($arts > 0) $add('media', 1, 'Article у розмітці головної');
        }
        // особа в розмітці головної
        if ($person) {
            if ($person['strong']) $add('personal', 5, 'schema Person - головна сутність головної (' . $person['via'] . ')');
            else $add('personal', 1.5, 'schema Person - видавець сайту (' . $person['via'] . ')');
        }

        // ---------------- robots.txt (працює навіть для заблокованих сайтів)
        if ($robotsTxt !== null && $robotsTxt !== '' && stripos(ltrim($robotsTxt), '<') !== 0) {
            if (preg_match('#^\s*disallow:\s*\S*(cart|basket|checkout|korzina|koshyk|/order|wishlist|compare)#im', $robotsTxt)) {
                $add('ecommerce', 1.5, 'robots.txt закриває кошик / checkout');
            }
            if (preg_match('#^\s*disallow:\s*\S*(\?|\*)(sort|filter|price|order)=#im', $robotsTxt) || preg_match('#^\s*disallow:\s*\S*/(filter|filtr)/#im', $robotsTxt)) {
                $add('ecommerce', 1, 'robots.txt закриває фільтри / сортування');
            }
            if (preg_match('#^\s*disallow:\s*/wp-admin#im', $robotsTxt) && preg_match('#product|woocommerce#i', $robotsTxt)) {
                $add('ecommerce', 0.5, 'WooCommerce у robots.txt');
            }
        }

        // ---------------- UI головної
        if ($home) {
            $text = mb_strtolower($home->visibleText());
            $links = $home->links();
            $anchors = [];
            foreach ($links as $l) $anchors[] = mb_strtolower($l['text']);
            $anchorBlob = ' ' . implode(' | ', $anchors) . ' ';
            $buttons = [];
            foreach ($home->query('//button | //input[@type="submit" or @type="button"]') as $b) {
                /** @var DOMElement $b */
                $bt = $b->nodeName === 'input' ? $b->getAttribute('value')
                    : $b->textContent . ' ' . $b->getAttribute('aria-label') . ' ' . $b->getAttribute('title');
                $buttons[] = mb_strtolower(Page::norm($bt));
            }
            $buttonBlob = ' ' . implode(' | ', $buttons) . ' ';
            $uiBlob = $text . ' ' . $anchorBlob . ' ' . $buttonBlob;
            $html = $home->html();

            // кошик
            $cart = false;
            foreach ($links as $l) {
                if (!$l['internal']) continue;
                if (preg_match('#/(cart|basket|korzina|koshyk|kosik|checkout|shopping-cart|shopping_cart)(/|$|\?|\.)|route=checkout/cart#i', $l['href'])) { $cart = true; break; }
                if (preg_match('/^(кошик|корзина|cart|basket|мій кошик|моя корзина|кошик товарів)\b/iu', $l['text'])) { $cart = true; break; }
            }
            if (!$cart && preg_match('/^(кошик|корзина|cart|basket)\b|\| (кошик|корзина|cart|basket)\b/iu', trim($buttonBlob))) $cart = true;
            if (!$cart && $home->query('//header//*[contains(@class,"cart") or contains(@class,"basket") or contains(@class,"korzin") or contains(@id,"cart") or contains(@class,"minicart")]')) $cart = true;
            if ($cart) $add('ecommerce', 2, 'кошик у шапці');

            $buy = preg_match_all('/(додати (до|в|у) кошик|до кошика|в кошик|у кошик|купити|в корзину|купить|add to cart|add to basket|buy now|оформити замовлення|купить в 1 клик|купити в 1 клік|купити в один клік)/u', $text . ' ' . $buttonBlob);
            if ($buy >= 3) $add('ecommerce', 2, 'кнопки «Купити» / «В кошик» (' . $buy . ')');
            elseif ($buy > 0) $add('ecommerce', 0.5, 'кнопка «Купити»');

            $prices = preg_match_all('/\d[\d \x{00A0}\x{202F}]*(?:[.,]\d{1,2})?\s?(?:₴|грн\.?|uah)(?![\p{L}])/iu', $text);
            if ($prices >= 6) $add('ecommerce', 2, 'ціни в гривнях на головній (' . $prices . ')');
            elseif ($prices >= 2) $add('ecommerce', 1, 'ціни в гривнях (' . $prices . ')');

            $productLinks = 0; $catLinks = 0; $serviceLinks = []; $articleLinks = 0; $mapLinks = 0; $appLinks = 0;
            foreach ($links as $l) {
                if (preg_match('#(apps\.apple\.com|itunes\.apple\.com|play\.google\.com/store)#i', $l['href'])) $appLinks++;
                if (preg_match('#(google\.[a-z.]+/maps|maps\.google\.|goo\.gl/maps|maps\.app\.goo\.gl)#i', $l['href'])) $mapLinks++;
                if (!$l['internal']) continue;
                $role = self::roleOfUrl($l['href']);
                if ($role === 'product') $productLinks++;
                elseif ($role === 'category') $catLinks++;
                elseif ($role === 'service' || $role === 'service_index') { if ($role === 'service') $serviceLinks[$l['href']] = true; }
                elseif ($role === 'article' && !$l['in_nav']) $articleLinks++;
            }
            if ($productLinks >= 8) $add('ecommerce', 1.5, 'посилання на картки товарів (' . $productLinks . ')');
            elseif ($productLinks >= 3) $add('ecommerce', 0.5, 'посилання на товари (' . $productLinks . ')');
            if ($catLinks >= 5 || preg_match('/\| (каталог|каталог товарів|catalog|всі товари) \|/u', $anchorBlob)) $add('ecommerce', 1, 'розділ «Каталог»');

            // локальний бізнес
            $mapFrame = false;
            foreach ($home->iframes() as $src) {
                if (preg_match('#(google\.[a-z.]+/maps|maps\.google\.|goo\.gl/maps|openstreetmap|maps\.app\.goo\.gl|visicom)#i', $src)) { $mapFrame = true; break; }
            }
            if (!$mapFrame && preg_match('#(maps\.googleapis\.com/maps/api/js|data-map|class=["\'][^"\']*\bmap\b|leaflet\.js|google-map)#i', $html)) $mapFrame = true;
            if ($mapFrame) $add('local', 2, 'вбудована карта');
            elseif ($mapLinks > 0) $add('local', 1, 'посилання на Google Maps');
            if (preg_match('/(графік роботи|години роботи|режим роботи|час роботи|график работы|режим работы|working hours|opening hours|пн\s*[-–]\s*(пт|сб|нд)|пн\.?\s*[-–]\s*(пт|сб|нд)|щодня з|щоденно з|без вихідних|ежедневно с|без выходных)/u', $text)) {
                $add('local', 1.5, 'графік роботи');
            }
            if (preg_match('/(^|[\s,(])(вул\.|вулиця|просп\.|проспект|бульв\.|бульвар|пров\.|провулок|ул\.|улица|пл\.|площа)\s*[\p{Lu}\d«"]/u', $home->visibleText())) {
                $add('local', 1, 'адреса на сторінці');
            }
            if (preg_match('/(записатися|запис на прийом|записатись|запис онлайн|онлайн-запис|записаться|забронювати|бронювання|забронировать|book now|book a table|book online|reserve)/u', $uiBlob)) {
                $add('local', 1.5, 'онлайн-запис / бронювання');
            }

            // медіа
            $timeTags = count($home->query('//body//time'));
            $dates = preg_match_all('/(\b\d{1,2}\.\d{1,2}\.\d{2,4}\b|\b\d{1,2}\s+(' . self::UK_MONTHS . '|' . self::RU_MONTHS . ')(\s+\d{4})?|\b(' . self::EN_MONTHS . ')\.?\s+\d{1,2},\s+\d{4}|\b(сьогодні|вчора|сегодня|вчера),?\s+(о\s+)?\d{1,2}:\d{2}|\b\d+\s+(хв|хвилин|годин[иу]?|minutes?|hours?)\s+(тому|назад|ago)\b)/u', $text);
            // час публікації «17:50» у стрічці (без діапазонів графіка «09:00-18:00», «з 9:00 до 18:00»)
            $times = preg_match_all('/(?<![-–—\d:])(?<![-–—]\s)(?<!з\s)(?<!до\s)(?<!with\s)\b([01]?\d|2[0-3]):[0-5]\d\b(?!\s?[-–—]\s?\d)(?!\s+до\s)/u', $text);
            $dated = max($timeTags, $dates + (int)$times);
            if ($dated >= 10) $add('media', 3, 'багато датованих матеріалів на головній (' . $dated . ')');
            elseif ($dated >= 5) $add('media', 1.5, 'датовані матеріали на головній (' . $dated . ')');
            if ($articleLinks >= 30) $add('media', 3.5, 'багато посилань на статті / новини (' . $articleLinks . ')');
            elseif ($articleLinks >= 15) $add('media', 2.5, 'посилання на статті / новини (' . $articleLinks . ')');
            elseif ($articleLinks >= 6) $add('media', 1, 'посилання на статті (' . $articleLinks . ')');
            if (preg_match('/(головний редактор|шеф-редактор|редакція|редакция|editorial|editor-in-chief|головне за день|стрічка новин|лента новостей|всі новини|все новости)/u', $uiBlob)) {
                $add('media', 1.5, 'ознаки редакції / стрічки новин');
            }

            // SaaS / продукт
            $pricing = false;
            foreach ($links as $l) {
                if ($l['internal'] && preg_match('#/(pricing|prices|tarify|tarifi|taryfy|tariffs|tariff|plans|plany|tsiny-i-tarify)(/|$|\.)#i', $l['href'])) { $pricing = true; break; }
            }
            if (!$pricing && preg_match('/\| (тарифи|тарифы|pricing|plans|тарифні плани|ціни та тарифи) \|/u', $anchorBlob)) $pricing = true;
            if ($pricing) $add('saas', 2, 'сторінка тарифів');
            $ctaSaas = [];
            foreach (['спробувати безкоштовно', 'безкоштовний період', 'free trial', 'start free', 'try for free', 'try it free', 'try free', 'get started', 'почати безкоштовно', 'request a demo', 'book a demo', 'get a demo', 'замовити демо', 'демо-доступ', 'попробовать бесплатно', 'тестовий період', 'тестовый период', 'безкоштовно 14 днів', 'sign up free'] as $w) {
                if (strpos($uiBlob, $w) !== false) $ctaSaas[] = $w;
            }
            if ($ctaSaas) $add('saas', min(3.0, 1.5 * count($ctaSaas)), 'заклики «' . implode('», «', array_slice($ctaSaas, 0, 3)) . '»');
            if ($appLinks > 0) $add('saas', 1.5, 'посилання на мобільний застосунок');
            $tagline = mb_strtolower($home->title() . ' ' . $home->metaDescription());
            if (preg_match('/(\btool\b|\bplatform\b|\bsoftware\b|\bapp\b|\bsaas\b|\bcrm\b|\berp\b|online editor|онлайн-сервіс|сервіс для|платформа для|платформа |інструмент для|програма для|програмне забезпечення|застосунок|додаток для|приложение|сервис для)/u', $tagline)) {
                $add('saas', 1.5, 'title / description описує продукт-інструмент');
            }
            if ($home->emptyAppRoot() || ($home->frameworkMarkers() && $home->wordCount() < 120)) $add('saas', 1.5, 'клієнтський застосунок (SPA)');
            if (preg_match('/\| (api|документація|documentation|docs|інтеграції|integrations|developers|для розробників) \|/u', $anchorBlob)) $add('saas', 1, 'документація / API / інтеграції');

            // послуги
            $svcNav = false;
            foreach ($links as $l) {
                if (!$l['internal']) continue;
                if (preg_match(self::RX_ANCHOR_SERVICE, $l['text'])) { $svcNav = true; break; }
                $r = self::roleOfUrl($l['href']);
                if ($r === 'service_index') { $svcNav = true; break; }
            }
            if ($svcNav) $add('services', 2, 'розділ «Послуги»');
            if (count($serviceLinks) >= 3) $add('services', 1.5, 'сторінки окремих послуг (' . count($serviceLinks) . ')');
            if (preg_match('/(замовити консультацію|отримати консультацію|безкоштовна консультація|безкоштовну консультацію|залишити заявку|залиште заявку|залишити запит|обговорити проєкт|обговорити проект|заказать консультацию|оставить заявку|get a quote|request a quote|get in touch|розрахувати вартість|прорахувати вартість|отримати пропозицію|комерційна пропозиція)/u', $uiBlob)) {
                $add('services', 1.5, 'заклики до заявки / консультації');
            }
            if (preg_match('/(кейси|кейсы|портфоліо|портфолио|наші клієнти|наши клиенты|case studies|portfolio|our clients|наші роботи|наши работы|реалізовані проєкти|реалізовані проекти)/u', $uiBlob)) {
                $add('services', 1.5, 'кейси / портфоліо / клієнти');
            }

            // корпоративний
            $corp = [];
            foreach (['інвесторам' => 'інвесторам', 'investors' => 'investors', 'кар\'єра' => 'кар\'єра', 'карʼєра' => 'кар\'єра', 'вакансії' => 'вакансії', 'careers' => 'careers', 'прес-центр' => 'прес-центр', 'пресцентр' => 'прес-центр', 'сталий розвиток' => 'сталий розвиток', 'sustainability' => 'sustainability', 'партнерам' => 'партнерам', 'дилерам' => 'дилерам', 'виробництво' => 'виробництво'] as $w => $label) {
                if (strpos($anchorBlob, $w) !== false) $corp[$label] = true;
            }
            if ($corp) $add('corporate', min(2.5, 1.0 * count($corp)), 'розділи «' . implode('», «', array_slice(array_keys($corp), 0, 3)) . '»');

            // особистий сайт експерта: «Про мене» в меню, назва сайту = ім'я особи, немає кошика й каталогу
            $aboutMe = self::aboutMeLink($home);
            if ($aboutMe !== null) $add('personal', 2, 'пункт меню «' . ($aboutMe['text'] !== '' ? self::trimAnchor($aboutMe['text']) : (string)parse_url($aboutMe['href'], PHP_URL_PATH)) . '»');
            if ($person || $aboutMe !== null) {
                $brandName = self::brand($home, $url);
                if ($person && self::personNameMatches($person, $brandName)) $add('personal', 1.5, 'назва сайту збігається з іменем у Person («' . $person['name'] . '»)');
                elseif (self::looksLikePersonName($brandName)) $add('personal', 1, 'назва сайту схожа на ім\'я та прізвище («' . $brandName . '»)');
                if (!$cart && $buy === 0 && $catLinks < 5 && $productLinks < 3 && !$productDone) $add('personal', 0.5, 'немає кошика й каталогу товарів');
            }
        }

        // ---------------- внутрішні сторінки
        foreach ($samplePages as $sp) {
            /** @var Page $p */
            $p = $sp['page'];
            if ($sp['role'] === 'product' && !$productDone) {
                $t = mb_strtolower($p->visibleText());
                if (preg_match('/(додати (до|в|у) кошик|в кошик|у кошик|купити|в корзину|add to cart)/u', $t) && preg_match('/\d\s?(₴|грн|uah)/iu', $t)) {
                    $add('ecommerce', 2, 'картка товару з ціною та кнопкою «Купити»');
                }
            }
            if ($sp['role'] === 'article' && $p->hasSchemaType(['Article'])) $add('media', 0.5, 'Article на сторінці статті');
            if ($sp['role'] === 'contact') {
                foreach ($p->iframes() as $src) {
                    if (preg_match('#(google\.[a-z.]+/maps|maps\.google\.|openstreetmap|visicom)#i', $src) && !in_array('вбудована карта', $sig['local'], true)) {
                        $add('local', 1, 'карта на сторінці контактів');
                        break;
                    }
                }
            }
        }

        // ---------------- вибір
        $order = array_keys(self::LABELS);
        $ranked = $order;
        usort($ranked, function ($a, $b) use ($pts, $order) {
            if (abs($pts[$a] - $pts[$b]) < 0.001) return array_search($a, $order, true) <=> array_search($b, $order, true);
            return $pts[$b] <=> $pts[$a];
        });
        // головна сутність головної - Person: особистий сайт перемагає локальний бізнес, послуги й корпоративний сайт
        // (організації з біографії вже відсіяно; бали послуг - курси, консультації, кейси - типові для експерта)
        $personalOverride = false;
        if ($person && $person['strong'] && $ranked[0] !== 'personal' && in_array($ranked[0], self::PERSONAL_BEATS, true)) {
            $ranked = array_values(array_merge(['personal'], array_diff($ranked, ['personal'])));
            $personalOverride = true;
        }
        $top = $ranked[0];
        $second = $ranked[1];
        $s1 = $pts[$top];
        $s2 = $pts[$second];
        if ($s1 < self::MIN_POINTS || $top === 'corporate') {
            $type = 'corporate';
            $signals = $sig['corporate'];
            if ($s1 > 0 && $top !== 'corporate') $signals[] = 'слабкі ознаки типу «' . self::LABELS[$top] . '»: ' . implode(', ', array_slice($sig[$top], 0, 3));
            if (!$home && !$samplePages) {
                $signals[] = 'сайт не віддав HTML - тип визначено без вмісту сторінок';
                $conf = $s1 > 0 ? 0.25 : 0.1;
                if ($s1 >= 1.5 && $top !== 'corporate') { $type = $top; $signals = $sig[$top]; $signals[] = 'сайт не віддав HTML - висновок лише за robots.txt'; }
            } else {
                $conf = $top === 'corporate' ? min(0.6, 0.35 + 0.08 * $s1) : 0.3;
                if (!$signals) $signals[] = 'немає виразних ознак магазину, медіа, SaaS, послуг чи локального бізнесу';
            }
        } else {
            $type = $top;
            $signals = $sig[$top];
            // max(0, ...) потрібен лише для пріоритету Person: в інших випадках $s1 >= $s2
            $conf = 0.3 + min($s1, 10) * 0.045 + max(0.0, min($s1 - $s2, 8)) * 0.04;
            if ($personalOverride) {
                $conf += 0.1;
                $signals[] = 'Person як головна сутність має пріоритет над ознаками типу «' . self::LABELS[$second] . '»';
            }
            if ($s2 >= self::MIN_POINTS) $signals[] = 'також ознаки типу «' . self::LABELS[$second] . '»: ' . implode(', ', array_slice($sig[$second], 0, 2));
        }
        $conf = round(max(0.1, min(0.95, $conf)), 2);

        $langs = self::languages($home);
        return [
            'type'       => $type,
            'type_label' => self::LABELS[$type],
            'confidence' => $conf,
            'signals'    => array_values(array_unique($signals)),
            'brand'      => self::brand($home, $url),
            'host'       => $host,
            'lang'       => $langs['lang'],
            'languages'  => $langs['languages'],
            'scores'     => array_map(function ($v) { return round($v, 1); }, $pts),
        ];
    }

    // ================================================================== brand

    public static function brand(?Page $home, string $url): string
    {
        $hostLabel = self::hostLabel($url);
        $cands = [];
        if ($home) {
            $sn = $home->og('site_name');
            if ($sn) $cands[] = [$sn, 3.0];
            foreach ($home->schemaItemsOfType(['Organization']) as $it) {
                $n = $it['data']['name'] ?? null;
                if (is_array($n)) $n = $n[0] ?? null;
                if (is_string($n) && trim($n) !== '') { $cands[] = [$n, 3.0]; break; }
            }
            foreach ($home->schemaItemsOfType(['WebSite'], false) as $it) {
                $n = $it['data']['name'] ?? null;
                if (is_array($n)) $n = $n[0] ?? null;
                if (is_string($n) && trim($n) !== '') { $cands[] = [$n, 2.0]; break; }
            }
            $title = $home->title();
            if ($title !== '') {
                $parts = preg_split('/\s+[|\-–—:·•»\/]\s+|\s*[|–—·•»]\s*/u', $title);
                $parts = array_values(array_filter(array_map('trim', (array)$parts), function ($s) { return $s !== ''; }));
                if ($parts) {
                    $cands[] = [$parts[0], 1.0];
                    if (count($parts) > 1) $cands[] = [end($parts), 1.2];
                }
            }
        }
        if ($hostLabel !== '') $cands[] = [self::prettyHostLabel($hostLabel), 0.5];

        // особистий сайт: ім'я з Person, якщо особа - головна сутність головної
        if ($home && ($person = self::homePerson($home)) !== null && $person['strong']) $cands[] = [$person['name'], 3.0];

        $generic = '/^(головна|главная|home|homepage|офіційний сайт|официальный сайт|інтернет-магазин|интернет-магазин|online store|main|index|сайт|website)$/iu';
        $hostNorm = self::normKey($hostLabel);
        $best = null; $bestScore = -INF;
        foreach ($cands as [$c, $base]) {
            // (trim() з «» у списку символів ріже UTF-8 побайтово - тому regex)
            $c = (string)preg_replace('/^[\s«»"\'.,]+|[\s«»"\'.,]+$/u', '', html_entity_decode((string)$c, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($c === '') continue;
            $s = $base;
            $words = self::words($c);
            $key = self::normKey($c);
            if ($hostNorm !== '' && $key !== '') {
                if ($key === $hostNorm) $s += 4;
                elseif (strpos($key, $hostNorm) !== false || strpos($hostNorm, $key) !== false) $s += 2.5;
                else {
                    // перше слово кандидата збігається з хостом («Acme Ukraine»)
                    $fw = self::normKey((string)preg_split('/\s+/u', $c)[0]);
                    if ($fw !== '' && ($fw === $hostNorm || (strlen($fw) >= 4 && strpos($hostNorm, $fw) === 0))) $s += 2;
                }
            }
            if ($words > 4) $s -= 3;
            elseif ($words > 2) $s -= 0.7;
            if (mb_strlen($c) > 30) $s -= 2;
            if (preg_match($generic, $c)) $s -= 6;
            if ($s > $bestScore) { $bestScore = $s; $best = $c; }
        }
        if ($best === null) return self::prettyHostLabel($hostLabel);
        // «Acme Ukraine» -> «Acme», якщо хост дорівнює першому слову
        $first = (string)preg_split('/\s+/u', $best)[0];
        if ($hostNorm !== '' && self::normKey($best) !== $hostNorm && self::normKey($first) === $hostNorm) return $first;
        return mb_strlen($best) > 40 ? self::prettyHostLabel($hostLabel) : $best;
    }

    /** Мітка домену без публічного суфікса: acme.ua -> acme, my-clinic.com.ua -> my-clinic. */
    public static function hostLabel(string $url): string
    {
        $h = Crawler::registrableHost($url);
        $parts = explode('.', $h);
        $n = count($parts);
        if ($n <= 1) return $h;
        $sld2 = ['com', 'org', 'net', 'gov', 'edu', 'in', 'kiev', 'kyiv', 'od', 'dp', 'lviv', 'kharkov', 'co', 'ltd', 'biz', 'info', 'ac'];
        if ($n >= 3 && in_array($parts[$n - 2], $sld2, true) && strlen($parts[$n - 1]) <= 3) return $parts[$n - 3];
        return $parts[$n - 2];
    }

    /** Кількість слів (через пробіли), стійко до невалідного UTF-8. */
    private static function words(string $s): int
    {
        $s = trim($s);
        if ($s === '') return 0;
        $parts = preg_split('/\s+/u', $s);
        if ($parts === false) $parts = preg_split('/\s+/', $s);
        return is_array($parts) ? count($parts) : 1;
    }

    private static function prettyHostLabel(string $label): string
    {
        if ($label === '') return '';
        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    /** Ключ для порівняння бренду з доменом: транслітерація, лише [a-z0-9]. */
    private static function normKey(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie', 'ж' => 'zh', 'з' => 'z',
            'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
            'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ь' => '', 'ю' => 'iu', 'я' => 'ia', 'ы' => 'y', 'э' => 'e', 'ё' => 'e', 'ъ' => '', '\'' => '', 'ʼ' => '', '’' => ''];
        $s = strtr($s, $map);
        $s = (string)preg_replace('/[^a-z0-9]/', '', $s);
        // зближуємо варіанти транслітерації: «Сіті» ~ city, «Медікал» ~ medical
        return strtr($s, ['ks' => 'x', 'kh' => 'h', 'ck' => 'k', 'ie' => 'e', 'iu' => 'u', 'ia' => 'a', 'y' => 'i', 'c' => 'k', 'w' => 'v']);
    }

    /** Ті самі назви / імена з точністю до регістру, розділових знаків і транслітерації. */
    public static function sameName(string $a, string $b): bool
    {
        $ka = self::normKey($a);
        return $ka !== '' && $ka === self::normKey($b);
    }

    // ================================================================== особистий сайт

    /**
     * Person - головна сутність головної (JSON-LD):
     *  - ProfilePage верхнього рівня з mainEntity / about -> Person;
     *  - WebPage головної з mainEntity / about -> Person;
     *  - Person верхнього рівня з url / @id головної, якщо поруч немає окремої організації з іншою назвою;
     *  - WebSite.publisher / author -> Person: без окремої організації - головна сутність, інакше лише слабка ознака.
     * Особа лише як автор статті чи співробітник (вкладений вузол) головною сутністю не вважається.
     * @return array{name:string,id:string,data:array,via:string,strong:bool,alt:string[],nested:array<string,bool>}|null
     *         nested - md5 вузлів, вкладених у Person (alumniOf, worksFor тощо), для відсіювання з ознак типу
     */
    public static function homePerson(?Page $home): ?array
    {
        if (!$home) return null;
        $top = []; $ids = [];
        $index = function ($node, int $d) use (&$index, &$ids) {
            if ($d > 12 || !is_array($node)) return;
            if (isset($node['@id']) && is_string($node['@id'])) {
                $k = self::idKey($node['@id']);
                if ($k !== '' && (!isset($ids[$k]) || count($node) > count($ids[$k]))) $ids[$k] = $node;
            }
            foreach ($node as $v) if (is_array($v)) $index($v, $d + 1);
        };
        foreach ($home->jsonLdRaw() as $b) {
            if (!is_array($b)) continue;
            $index($b, 0);
            $list = ($b === [] || array_keys($b) === range(0, count($b) - 1)) ? $b : [$b];
            foreach ($list as $n) {
                if (!is_array($n)) continue;
                if (isset($n['@type'])) $top[] = $n;
                if (isset($n['@graph']) && is_array($n['@graph'])) foreach ($n['@graph'] as $g) if (is_array($g) && isset($g['@type'])) $top[] = $g;
            }
        }
        if (!$top) return null;

        $types = function (array $n): array {
            $out = [];
            foreach ((array)($n['@type'] ?? []) as $t) if (is_string($t) && ($st = Page::shortType($t)) !== '') $out[] = strtolower($st);
            return $out;
        };
        $isA = function (array $n, array $want) use ($types): bool {
            return (bool)array_intersect($types($n), array_keys(Page::expandTypes($want)));
        };
        $deref = function ($v) use ($ids) {
            if (is_array($v) && isset($v['@id']) && is_string($v['@id']) && !array_diff(array_map('strval', array_keys($v)), ['@id', '@type'])) {
                return $ids[self::idKey($v['@id'])] ?? $v;
            }
            return $v;
        };
        $name = function (array $n): string {
            $v = $n['name'] ?? null;
            if (is_array($v)) $v = $v[0] ?? ($v['@value'] ?? null);
            return is_string($v) ? trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        };
        // Person у властивостях $props вузла (значення може бути списком або посиланням @id)
        $personIn = function (array $n, array $props) use ($deref, $isA, $name): ?array {
            foreach ($props as $p) {
                if (!isset($n[$p])) continue;
                $vals = is_array($n[$p]) && ($n[$p] === [] || array_keys($n[$p]) === range(0, count($n[$p]) - 1)) ? $n[$p] : [$n[$p]];
                foreach ($vals as $v) {
                    $v = $deref($v);
                    if (is_array($v) && $isA($v, ['Person']) && $name($v) !== '') return [$v, $p];
                }
            }
            return null;
        };
        $homeUrl = $home->url();
        $homeLike = function ($u) use ($homeUrl): bool {
            if (!is_string($u) || trim($u) === '') return false;
            $abs = Crawler::absolutize(trim($u), $homeUrl, false);
            if ($abs === null || !Crawler::sameSite($abs, $homeUrl)) return false;
            return (bool)preg_match('#^/?((uk|ua|en|ru)/?)?(index\.(php|html?))?$#i', (string)parse_url($abs, PHP_URL_PATH));
        };

        $found = null; $via = ''; $viaWeak = null;
        // 1-2) ProfilePage / WebPage головної -> mainEntity / about
        foreach ($top as $n) {
            if (!$isA($n, ['WebPage'])) continue;
            $isProfile = $isA($n, ['ProfilePage']);
            $u = $n['url'] ?? ($n['@id'] ?? null);
            if (!$isProfile && !$homeLike($u)) continue;
            if ($isProfile && $u !== null && !$homeLike($u)) continue;
            $hit = $personIn($n, ['mainEntity', 'about']);
            if ($hit) {
                $found = $hit[0];
                $via = ($isProfile ? 'ProfilePage' : Page::shortType((string)(((array)$n['@type'])[0] ?? 'WebPage'))) . '.' . $hit[1];
                break;
            }
        }
        // окремі організації (з назвою, що не збігається з іменем особи)
        $orgNames = [];
        foreach ($top as $n) if ($isA($n, ['Organization', 'LocalBusiness']) && $name($n) !== '') $orgNames[] = $name($n);
        $distinctOrg = function (array $p) use ($orgNames, $name): bool {
            $names = array_merge([$name($p)], self::stringsOf($p['alternateName'] ?? null));
            foreach ($orgNames as $on) {
                $same = false;
                foreach ($names as $pn) if (self::sameName($on, $pn)) { $same = true; break; }
                if (!$same) return true;
            }
            return false;
        };
        // 3) Person верхнього рівня з адресою головної
        if ($found === null) {
            foreach ($top as $n) {
                if (!$isA($n, ['Person']) || $name($n) === '') continue;
                $u = $n['url'] ?? null;
                $id = is_string($n['@id'] ?? null) ? preg_replace('/#.*$/', '', $n['@id']) : null;
                if (($homeLike($u) || ($u === null && $homeLike($id))) && !$distinctOrg($n)) { $found = $n; $via = 'Person з url головної'; break; }
            }
        }
        // 4) WebSite.publisher / author -> Person
        if ($found === null) {
            foreach ($top as $n) {
                if (!$isA($n, ['WebSite'])) continue;
                $hit = $personIn($n, ['publisher', 'author', 'creator', 'copyrightHolder']);
                if (!$hit) continue;
                if (!$distinctOrg($hit[0])) { $found = $hit[0]; $via = 'WebSite.' . $hit[1]; break; }
                if ($viaWeak === null) $viaWeak = [$hit[0], 'WebSite.' . $hit[1]];
            }
        }
        $strong = $found !== null;
        if (!$strong) {
            if ($viaWeak === null) return null;
            [$found, $via] = $viaWeak;
        }
        // найповніша версія вузла з тим самим @id
        if (isset($found['@id']) && is_string($found['@id']) && isset($ids[self::idKey($found['@id'])]) && count($ids[self::idKey($found['@id'])]) > count($found)) {
            $found = $ids[self::idKey($found['@id'])];
        }
        $nested = [];
        $walk = function ($node, int $d) use (&$walk, &$nested) {
            if ($d > 10 || !is_array($node)) return;
            foreach ($node as $v) {
                if (!is_array($v)) continue;
                if (isset($v['@type'])) $nested[md5(serialize($v))] = true;
                $walk($v, $d + 1);
            }
        };
        $walk($found, 0);
        return [
            'name'   => $name($found),
            'id'     => is_string($found['@id'] ?? null) ? (string)$found['@id'] : '',
            'data'   => $found,
            'via'    => $via,
            'strong' => $strong,
            'alt'    => self::stringsOf($found['alternateName'] ?? null),
            'nested' => $nested,
        ];
    }

    /** Ім'я особи (або alternateName) збігається з назвою. */
    public static function personNameMatches(array $person, string $name): bool
    {
        if (trim($name) === '') return false;
        foreach (array_merge([(string)$person['name']], (array)($person['alt'] ?? [])) as $n) {
            if (self::sameName($n, $name)) return true;
        }
        return false;
    }

    /** «Олексій Матузний», «Anna Smith»: 2-3 слова з великої літери без цифр. */
    public static function looksLikePersonName(string $s): bool
    {
        $s = trim($s);
        if ($s === '' || mb_strlen($s) > 40 || preg_match('/\d/', $s)) return false;
        $w = preg_split('/\s+/u', $s);
        if (!is_array($w) || count($w) < 2 || count($w) > 3) return false;
        foreach ($w as $x) if (!preg_match('/^\p{Lu}[\p{Ll}\'’ʼ]+(?:-\p{Lu}?[\p{Ll}\'’ʼ]+)?$/u', $x)) return false;
        return true;
    }

    /** Внутрішнє посилання «Про мене» / «Біографія» / «Про автора» (анкор або шлях) або null; посилання меню - першими. */
    public static function aboutMeLink(?Page $home): ?array
    {
        if (!$home) return null;
        $best = null;
        foreach ($home->internalLinks() as $l) {
            $text = trim($l['text']);
            $byText = $text !== '' && mb_strlen($text) <= 40 && preg_match(self::RX_ANCHOR_ABOUT_ME, $text);
            $segs = array_values(array_filter(explode('/', strtolower(rawurldecode((string)parse_url($l['href'], PHP_URL_PATH)))), 'strlen'));
            if ($segs && preg_match('/^(uk|ua|en|ru)$/', $segs[0])) array_shift($segs);
            $byPath = $segs && count($segs) <= 2 && in_array(preg_replace('/\.(html?|php)$/', '', end($segs)), self::SEG_ABOUT_ME, true);
            if (!$byText && !$byPath) continue;
            if ($l['in_nav']) return $l;
            if ($best === null) $best = $l;
        }
        return $best;
    }

    private static function trimAnchor(string $t): string
    {
        $t = trim($t);
        return mb_strlen($t) > 40 ? mb_substr($t, 0, 37) . '...' : $t;
    }

    private static function idKey(string $id): string
    {
        return strtolower(rtrim(trim($id), '/'));
    }

    /** Рядки зі значення (рядок або список рядків). */
    private static function stringsOf($v): array
    {
        if (is_string($v)) return trim($v) !== '' ? [trim($v)] : [];
        $out = [];
        if (is_array($v)) foreach ($v as $x) if (is_string($x) && trim($x) !== '') $out[] = trim($x);
        return $out;
    }

    // ================================================================== languages

    /** ['lang'=>'uk'|null, 'languages'=>['uk','en',...]] */
    public static function languages(?Page $home): array
    {
        if (!$home) return ['lang' => null, 'languages' => []];
        $langs = [];
        $primary = self::normLang((string)$home->lang());
        foreach ($home->hreflangs() as $h) {
            $l = self::normLang($h['lang']);
            if ($l !== null) $langs[$l] = true;
        }
        $anchorMap = ['en' => 'en', 'eng' => 'en', 'english' => 'en', 'ua' => 'uk', 'укр' => 'uk', 'uk' => 'uk', 'українська' => 'uk',
            'укр.' => 'uk', 'ukr' => 'uk', 'ru' => 'ru', 'рус' => 'ru', 'русский' => 'ru', 'ру' => 'ru', 'рус.' => 'ru', 'pl' => 'pl', 'polski' => 'pl', 'de' => 'de', 'deutsch' => 'de'];
        $host = Crawler::registrableHost($home->url());
        foreach ($home->links() as $l) {
            $path = (string)parse_url($l['href'], PHP_URL_PATH);
            $lh = (string)parse_url($l['href'], PHP_URL_HOST);
            if ($l['internal'] && preg_match('#^/(en|uk|ua|ru|pl|de)(/|$)#i', $path, $m)) {
                $t = mb_strtolower(trim($l['text']));
                // лише перемикач мов: короткий анкор або точний шлях /en/
                if (isset($anchorMap[$t]) || $path === '/' . $m[1] || $path === '/' . $m[1] . '/') $langs[self::normLang($m[1])] = true;
                continue;
            }
            $t = mb_strtolower(trim($l['text']));
            if (isset($anchorMap[$t]) && ($l['internal'] || preg_match('/^(en|uk|ua|ru)\.' . preg_quote($host, '/') . '$/i', $lh))) {
                $langs[$anchorMap[$t]] = true;
            }
        }
        if ($primary === null) {
            // визначаємо за літерами
            $txt = mb_substr($home->visibleText(), 0, 20000);
            $uk = preg_match_all('/[іїєґ]/iu', $txt);
            $ru = preg_match_all('/[ыэъё]/iu', $txt);
            $cyr = preg_match_all('/\p{Cyrillic}/u', $txt);
            $lat = preg_match_all('/[a-z]/i', $txt);
            if ($cyr > $lat * 0.5) $primary = $uk >= $ru ? 'uk' : 'ru';
            elseif ($lat > 0) $primary = 'en';
        }
        $out = [];
        if ($primary !== null) $out[] = $primary;
        foreach (array_keys($langs) as $l) if ($l !== null && $l !== '' && !in_array($l, $out, true)) $out[] = $l;
        return ['lang' => $primary, 'languages' => $out];
    }

    private static function normLang(string $l): ?string
    {
        $l = strtolower(trim($l));
        if ($l === '' || $l === 'x-default') return null;
        $l = preg_split('/[-_]/', $l)[0];
        if ($l === 'ua') $l = 'uk';
        return preg_match('/^[a-z]{2,3}$/', $l) ? $l : null;
    }

    // ================================================================== samples

    /**
     * Кандидати у вибірку: до 4 шт. [['role'=>..,'url'=>..,'via'=>'link'|'sitemap','index'=>bool], ...]
     * 'index' => true означає сторінку блогу/новин: рушій має взяти з неї першу статтю (firstArticleLink).
     */
    public static function pickSamples(?Page $home, string $url, array $robotsRes, float $budgetSec = 12.0): array
    {
        $t0 = microtime(true);
        $found = []; // role => ['url','score','via','index']
        $homeUrl = $home ? $home->url() : $url;
        $langPrefix = preg_match('#^/(uk|ua|en|ru)(/|$)#i', (string)parse_url($homeUrl, PHP_URL_PATH), $m) ? strtolower($m[1]) : null;
        $homeLang = $home ? self::normLang((string)$home->lang()) : null;

        $consider = function (string $role, string $href, float $score, string $via, bool $index = false, bool $weak = false) use (&$found) {
            if (!isset($found[$role]) || $score > $found[$role]['score'] + 0.001) {
                $found[$role] = ['url' => $href, 'score' => $score, 'via' => $via, 'index' => $index, 'weak' => $weak];
            }
        };

        if ($home) {
            $pos = 0;
            foreach ($home->internalLinks() as $l) {
                $pos++;
                $href = $l['href'];
                if (self::isExcluded($href, $homeUrl)) continue;
                $text = trim($l['text']);
                $role = self::roleOfUrl($href);
                $bonus = -$pos * 0.0005; // раніші в документі трохи важливіші
                $path = (string)parse_url($href, PHP_URL_PATH);
                if (preg_match('#^/ru(/|$)#i', $path) && $homeLang !== 'ru') $bonus -= 1.5;
                if ($langPrefix && !preg_match('#^/' . $langPrefix . '(/|$)#i', $path) && preg_match('#^/(uk|ua|en|ru)(/|$)#i', $path)) $bonus -= 1.0;

                if (preg_match(self::RX_ANCHOR_ABOUT, $text)) $consider('about', $href, 3 + $bonus + ($role === 'about' ? 1 : 0), 'link');
                elseif ($role === 'about') $consider('about', $href, 2 + $bonus, 'link');
                if (preg_match(self::RX_ANCHOR_CONTACT, $text)) $consider('contact', $href, 3 + $bonus + ($role === 'contact' ? 1 : 0), 'link');
                elseif ($role === 'contact') $consider('contact', $href, 2 + $bonus, 'link');

                if ($role === 'article') {
                    $w = self::words($text);
                    $consider('article', $href, 2 + ($w >= 4 ? 1 : 0) + ($l['in_nav'] ? -0.5 : 0) + $bonus, 'link');
                } elseif ($role === 'article_weak' && !$l['in_nav'] && self::words($text) >= 4) {
                    $consider('article', $href, 1 + $bonus, 'link', false, true);
                }
                if ($role === 'blog_index' || preg_match(self::RX_ANCHOR_BLOG, $text)) $consider('blog_index', $href, 2 + ($role === 'blog_index' ? 1 : 0) + $bonus, 'link', true);
                if ($role === 'product') {
                    $w = self::words($text);
                    $consider('product', $href, 2 + ($w >= 2 ? 0.5 : 0) + ($l['in_nav'] ? -0.5 : 0) + $bonus, 'link');
                }
                if ($role === 'service') $consider('service', $href, 3 + $bonus, 'link');
                elseif ($role === 'service_index' || preg_match(self::RX_ANCHOR_SERVICE, $text)) $consider('service', $href, 2 + $bonus, 'link');
                if ($role === 'category') $consider('category', $href, 2 + ($l['in_nav'] ? 0.3 : 0) + $bonus, 'link');
                elseif (preg_match(self::RX_ANCHOR_CATALOG, $text)) $consider('category', $href, 1.5 + $bonus, 'link');
            }
        }

        // «Про мене» / «Біографія» особистого сайту - лише коли звичайної «Про нас» не знайдено
        if ($home && !isset($found['about']) && ($am = self::aboutMeLink($home)) !== null && !self::isExcluded($am['href'], $homeUrl)) {
            $consider('about', $am['href'], 2.5, 'link');
        }

        // товар із картки з ціною: для магазинів із «пласкими» URL товарів (/nazva-tovaru/)
        if ($home && !isset($found['product'])) {
            $pc = self::productFromCards($home);
            if ($pc !== null) $consider('product', $pc, 2.5, 'card');
        }

        // sitemap - якщо немає статті або товару/послуги/категорії (або головна недоступна)
        $needArticle = !isset($found['article']) && !isset($found['blog_index']);
        $needMain = !isset($found['product']) && !isset($found['service']) && !isset($found['category']);
        if (($needArticle || $needMain || !$home) && (microtime(true) - $t0) < $budgetSec - 3) {
            $fromSm = self::samplesFromSitemap($url, $robotsRes, $homeUrl, max(3.0, $budgetSec - (microtime(true) - $t0)), $langPrefix);
            foreach ($fromSm as $role => $href) {
                if ($role === 'article' && isset($found['article'])) continue;
                if (!isset($found[$role])) $consider($role, $href, 1.0, 'sitemap');
            }
        }

        // збірка: about, contact, article, product|service|category, далі добір
        $out = [];
        $used = [];
        $push = function (string $role, array $f, bool $index = false) use (&$out, &$used) {
            $key = rtrim(strtolower($f['url']), '/');
            if (isset($used[$key]) || count($out) >= self::MAX_SAMPLES) return;
            $used[$key] = true;
            $out[] = ['role' => $role, 'url' => $f['url'], 'via' => $f['via'], 'index' => $index, 'weak' => !empty($f['weak'])];
        };
        if (isset($found['about'])) $push('about', $found['about']);
        if (isset($found['contact'])) $push('contact', $found['contact']);
        if (isset($found['article'])) $push('article', $found['article']);
        elseif (isset($found['blog_index'])) $push('article', $found['blog_index'], true);
        $mainRole = isset($found['product']) ? 'product' : (isset($found['service']) ? 'service' : (isset($found['category']) ? 'category' : null));
        if ($mainRole) $push($mainRole, $found[$mainRole]);
        foreach (['category', 'service', 'product'] as $r) {
            if ($r !== $mainRole && isset($found[$r])) $push($r, $found[$r]);
        }
        if (isset($found['article']) && isset($found['blog_index'])) $push('other', $found['blog_index']);
        return $out;
    }

    /**
     * Товар із картки на головній: посилання з назвою поруч із ціною (₴ / грн) і кнопкою покупки.
     * Для магазинів, де URL товару не відрізнити від категорії. null, якщо карток не знайдено.
     */
    public static function productFromCards(Page $home): ?string
    {
        $homeUrl = $home->url();
        $xp = $home->xpath();
        $nodes = $xp->query('//body//text()[contains(., "₴") or contains(., "грн")]');
        if (!$nodes) return null;
        $tried = 0;
        foreach ($nodes as $tn) {
            // сума і символ валюти часто в різних <span>: перевіряємо текст найближчих батьків
            $ok = false;
            for ($pr = $tn->parentNode, $i = 0; $pr instanceof DOMElement && $i < 3; $i++, $pr = $pr->parentNode) {
                if (preg_match('/\d[\d \x{00A0}\x{202F}]*(?:[.,]\d{1,2})?\s*(?:₴|грн)/u', Page::norm($pr->textContent))) { $ok = true; break; }
            }
            if (!$ok) continue;
            if (++$tried > 30) break;
            $el = $tn->parentNode;
            for ($depth = 0; $el instanceof DOMElement && $depth < 10; $depth++, $el = $el->parentNode) {
                if (in_array(strtolower($el->nodeName), ['body', 'main', 'header', 'footer', 'nav'], true)) break;
                $as = $xp->query('.//a[@href]', $el);
                if (!$as || $as->length === 0) continue;
                // href => найдовший підпис: у картці зазвичай два посилання на товар - фото (без тексту) і назва
                $hrefs = [];
                foreach ($as as $a) {
                    $h = Crawler::absolutize($a->getAttribute('href'), $homeUrl);
                    if ($h === null) continue;
                    $t = Page::norm($a->textContent);
                    if ($t === '') $t = Page::norm($a->getAttribute('title'));
                    if ($t === '') foreach ($xp->query('.//img[@alt]', $a) as $img) { $t = Page::norm($img->getAttribute('alt')); if ($t !== '') break; }
                    if (!isset($hrefs[$h]) || mb_strlen($t) > mb_strlen($hrefs[$h])) $hrefs[$h] = $t;
                }
                if (count($hrefs) > 4) break; // вийшли за межі однієї картки
                // ще не вся картка (напр. лише кнопка «Реєстрація» біля ціни): піднімаємось вище
                if (!preg_match('/(купити|у кошик|в кошик|до кошика|додати|в корзину|купить|buy|add to cart)/iu', $el->textContent)) continue;
                foreach ($hrefs as $h => $text) {
                    if (!Crawler::sameSite($h, $homeUrl) || self::isExcluded($h, $homeUrl)) continue;
                    if (trim((string)parse_url($h, PHP_URL_PATH), '/') === '' || preg_match('#/(ajax|login|cart|checkout|basket|kosyk|korzina|compare|wishlist)(/|$)#i', $h)) continue;
                    if (in_array(self::roleOfUrl($h), ['category', 'blog_index', 'article', 'about', 'contact', 'service', 'service_index'], true)) continue;
                    if (self::words($text) >= 2) return $h;
                }
            }
        }
        return null;
    }

    /** Перше посилання на статтю зі сторінки блогу / новин. */
    public static function firstArticleLink(Page $blogIndex): ?string
    {
        $indexUrl = $blogIndex->url();
        $indexPath = rtrim((string)parse_url($indexUrl, PHP_URL_PATH), '/');
        $fallback = null;
        $best = null;
        foreach ($blogIndex->internalLinks() as $l) {
            $href = $l['href'];
            if ($l['in_nav'] || self::isExcluded($href, $indexUrl)) continue;
            $path = rtrim((string)parse_url($href, PHP_URL_PATH), '/');
            if ($path === $indexPath || $path === '') continue;
            if (preg_match('#/(page|p)/\d+$|[?&](page|p|paged)=\d+|/(category|kategoriya|tag|author|rubric|rubryka|topics?)/#i', $href)) continue;
            $deeper = $indexPath !== '' && strpos($path, $indexPath . '/') === 0;
            $role = self::roleOfUrl($href);
            if (!$deeper && $role !== 'article' && $role !== 'article_weak') continue;
            $last = basename($path);
            $slugLike = strlen($last) >= 8 || preg_match('/\d/', $last) || substr_count($last, '-') >= 1;
            if (!$slugLike) continue;
            $words = self::words($l['text']);
            if ($words >= 3) { $best = $href; break; }
            if ($fallback === null) $fallback = $href;
        }
        return $best ?? $fallback;
    }

    /**
     * Роль URL за шляхом: about | contact | blog_index | article | article_weak | product | category | service | service_index | null.
     * article_weak - лише довгий slug без розділу блогу / дати (приймаємо тільки з анкором-заголовком).
     */
    public static function roleOfUrl(string $href): ?string
    {
        $p = parse_url($href);
        if (!$p) return null;
        $path = strtolower(rawurldecode($p['path'] ?? '/'));
        $query = $p['query'] ?? '';
        if ($query !== '') {
            if (preg_match('#route=product/product#i', $query)) return 'product';
            if (preg_match('#route=product/category#i', $query)) return 'category';
            if (preg_match('#route=information/contact#i', $query)) return 'contact';
            if (preg_match('#route=(blog|information/news|extension/blog)#i', $query)) return 'blog_index';
        }
        $segs = array_values(array_filter(explode('/', $path), function ($s) { return $s !== ''; }));
        // мовний префікс не рахуємо
        if ($segs && preg_match('/^(uk|ua|en|ru|uk-ua|ru-ua|en-us)$/', $segs[0])) array_shift($segs);
        if (!$segs) return null;
        $n = count($segs);
        $last = preg_replace('/\.(html?|php|aspx?)$/', '', $segs[$n - 1]);

        if ($n <= 2 && in_array($last, self::SEG_ABOUT, true)) return 'about';
        if ($n <= 2 && in_array($last, self::SEG_CONTACT, true)) return 'contact';

        // дата в шляху: /2026/10/19/slug
        if (preg_match('#(^|/)(19|20)\d{2}/\d{1,2}(/\d{1,2})?/[^/]+#', implode('/', $segs)) && strlen($last) >= 6) return 'article';

        // товари
        if (preg_match('/^p\d{4,}$/', $last) && $n >= 2) return 'product';                 // rozetka: /.../p123456/
        if (preg_match('/-p\d{3,}$|^\d{5,}-[a-z0-9-]+$|-\d{5,}$/', $last) && $n >= 1 && !in_array($segs[0], self::SEG_BLOG, true)) {
            if ($n >= 2 || preg_match('/-p\d{3,}$/', $last)) return 'product';
        }
        for ($i = 0; $i < $n - 1; $i++) {
            if (in_array($segs[$i], ['product', 'tovar', 'item', 'p', 'pdp', 'goods', 'products', 'tovary'], true)) {
                if (in_array($segs[$i], ['products', 'tovary', 'goods'], true) && $n - $i - 1 === 1 && strlen($last) < 6) break;
                return 'product';
            }
        }
        // категорії
        if (preg_match('/^c\d{3,}$/', $last) || ($n >= 2 && preg_match('/^c\d{3,}$/', $segs[$n - 2]))) return 'category';  // rozetka: /c80004/
        foreach ($segs as $i => $s) {
            if (in_array($s, self::SEG_CATALOG, true)) {
                return 'category';
            }
        }
        // послуги
        foreach ($segs as $i => $s) {
            if (in_array($s, self::SEG_SERVICE, true)) return $i < $n - 1 ? 'service' : 'service_index';
        }
        if ($n <= 2 && in_array($segs[0], self::SEG_OFFER, true)) return 'service_index';
        // блог / новини
        foreach ($segs as $i => $s) {
            if (in_array($s, self::SEG_BLOG, true)) {
                if ($i === $n - 1) return 'blog_index';
                $rest = array_slice($segs, $i + 1);
                if (in_array($rest[0], ['page', 'category', 'tag', 'author', 'rubric', 'topics', 'topic'], true)) return 'blog_index';
                $lastRest = preg_replace('/\.(html?|php)$/', '', end($rest));
                if (strlen($lastRest) >= 8 || preg_match('/\d/', $lastRest) || substr_count($lastRest, '-') >= 2) return 'article';
                return 'blog_index';
            }
        }
        // довгий slug з 4+ дефісами на глибині 1-2 - можливо стаття (WordPress /slug/), слабка ознака
        if ($n <= 2 && substr_count($last, '-') >= 4 && strlen($last) >= 25) return 'article_weak';
        return null;
    }

    /** Технічні / службові / сторонні URL, які не беремо у вибірку. */
    public static function isExcluded(string $href, string $homeUrl): bool
    {
        if (!Crawler::sameSite($href, $homeUrl)) return true;
        $p = parse_url($href);
        if (!$p) return true;
        $path = $p['path'] ?? '/';
        if (preg_match(self::RX_ASSET, $path)) return true;
        $lp = strtolower(rawurldecode($path));
        if (preg_match(self::RX_EXCLUDE_PATH, $lp)) return true;
        if (preg_match('/(thank-?you|thanks|dyakuiemo|dyakuyemo|spasibo|podyaka|success-page|404)/', $lp)) return true;
        // головна (включно з мовними версіями і index.*)
        if (preg_match('#^/?((uk|ua|en|ru)/?)?(index\.(php|html?))?$#i', $path)) {
            $q = $p['query'] ?? '';
            if ($q === '' || preg_match('/^(lang|language)=/i', $q) || preg_match('#route=common/home#i', $q)) return true;
        }
        if (!empty($p['query'])) {
            parse_str($p['query'], $qs);
            if (count($qs) > 2) return true;
            foreach (array_keys($qs) as $k) {
                if (preg_match('/^(utm_|sort|order|filter|limit|page|paged|q|s|search|query|lang|currency|ref|gclid|fbclid|yclid|add-to-cart|replytocom|price)/i', (string)$k)) return true;
            }
            if (!preg_match('#route=#i', $p['query']) && count($qs) > 1) return true;
        }
        return false;
    }

    /**
     * URL з sitemap для ролей, яких бракує: ['article'=>url, 'product'=>url, 'category'=>url, 'service'=>url, 'about'=>.., 'contact'=>..].
     */
    public static function samplesFromSitemap(string $url, array $robotsRes, string $homeUrl, float $budgetSec = 8.0, ?string $langPrefix = null): array
    {
        $t0 = microtime(true);
        $base = Crawler::baseUrl($homeUrl ?: $url);
        $smUrls = [];
        $rb = (string)($robotsRes['body'] ?? '');
        if ((int)($robotsRes['status'] ?? 0) === 200 && $rb !== '' && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $rb, $mm)) {
            foreach ($mm[1] as $u) {
                $u = trim($u);
                if (Crawler::sameSite($u, $base)) $smUrls[] = $u;
                if (count($smUrls) >= 3) break;
            }
        }
        if (!$smUrls) $smUrls = [$base . '/sitemap.xml'];

        // мовна версія головної: з префіксом (/uk/) - беремо той самий префікс, без префікса - адреси без мовного префікса
        $sameLang = function (string $loc) use ($langPrefix): bool {
            $path = (string)parse_url($loc, PHP_URL_PATH);
            return $langPrefix ? (bool)preg_match('#^/' . $langPrefix . '(/|$)#i', $path) : !preg_match('#^/(uk|ua|en|ru)(/|$)#i', $path);
        };

        $timeout = (int)max(3, min(8, $budgetSec / 2));
        $first = Crawler::fetchMulti(array_slice($smUrls, 0, 2), null, $timeout);
        // карту закрито від нашого бота захистом: повтор запасним агентом (браузер або AI-бот)
        $retry = [];
        foreach ($first as $u => $res) if (in_array((int)($res['status'] ?? 0), [401, 403, 429, 503], true)) $retry[] = $u;
        if ($retry) foreach (Crawler::fetchMulti($retry, 'browser', $timeout) as $u => $res) if (self::sitemapBody($res) !== '') $first[$u] = $res;
        $out = [];
        $preferred = [];
        $children = [];
        $locs = [];
        foreach ($first as $res) {
            $xml = self::sitemapBody($res);
            if ($xml === '') continue;
            [$kind, $list] = self::parseSitemap($xml);
            if ($kind === 'index') $children = array_merge($children, $list);
            else $locs = array_merge($locs, $list);
        }
        // дочірні карти: обираємо за назвою
        if ($children && (microtime(true) - $t0) < $budgetSec - 2) {
            $want = [];
            $pick = function (string $rx) use ($children, &$want) {
                foreach ($children as $c) {
                    if (preg_match($rx, (string)parse_url($c, PHP_URL_PATH) . '?' . (string)parse_url($c, PHP_URL_QUERY))) {
                        if (!in_array($c, $want, true)) $want[] = $c;
                        return;
                    }
                }
            };
            $pick('/(post|blog|news|article|stat|novyn|novost|novini)/i');
            $pick('/(product|tovar|goods|item)(?!_cat|-cat|_tag)/i');
            $pick('/(categor|product_cat|catalog|collection)/i');
            $pick('/(service|poslug|uslug)/i');
            $pick('/(^|[\/_-])page/i');
            if (!$want) $want = array_slice($children, 0, 2);
            $want = array_slice($want, 0, 3);
            $remaining = $budgetSec - (microtime(true) - $t0);
            $timeout2 = (int)max(3, min(8, $remaining - 1));
            foreach (Crawler::fetchMulti($want, $retry ? 'browser' : null, $timeout2) as $cu => $res) {
                $xml = self::sitemapBody($res);
                if ($xml === '') continue;
                [$kind, $list] = self::parseSitemap($xml);
                if ($kind !== 'urlset') continue;
                $isPostMap = (bool)preg_match('/(post|blog|news|article|stat|novyn|novost|novini)/i', (string)parse_url($cu, PHP_URL_PATH));
                foreach ($list as $loc) {
                    if (self::isExcluded($loc, $homeUrl)) continue;
                    $role = self::roleOfUrl($loc);
                    if ($isPostMap && ($role === null || $role === 'article' || $role === 'article_weak')) $role = 'article';
                    if ($role === 'service_index') $role = 'service';
                    if ($role === 'blog_index' || $role === 'article_weak') continue;
                    if ($role === null) continue;
                    $ok = $sameLang($loc);
                    if (!isset($out[$role]) || (!$preferred[$role] && $ok)) { $out[$role] = $loc; $preferred[$role] = $ok; }
                }
            }
        }
        // звичайний urlset
        foreach (array_slice($locs, 0, 5000) as $loc) {
            if (self::isExcluded($loc, $homeUrl)) continue;
            $role = self::roleOfUrl($loc);
            if ($role === 'service_index') $role = 'service';
            if ($role === null || $role === 'blog_index' || $role === 'article_weak') continue;
            $samePrefix = $sameLang($loc);
            if (!isset($out[$role]) || (!($preferred[$role] ?? true) && $samePrefix)) {
                $out[$role] = $loc;
                $preferred[$role] = $samePrefix;
            }
        }
        return $out;
    }

    private static function sitemapBody(array $res): string
    {
        if ((int)($res['status'] ?? 0) !== 200) return '';
        $b = (string)($res['body'] ?? '');
        if ($b === '') return '';
        if (strncmp($b, "\x1f\x8b", 2) === 0) {
            $d = @gzdecode($b);
            $b = is_string($d) ? $d : '';
        }
        return (stripos($b, '<urlset') !== false || stripos($b, '<sitemapindex') !== false) ? $b : '';
    }

    /** ['index'|'urlset', [loc, ...]] */
    private static function parseSitemap(string $xml): array
    {
        $kind = stripos($xml, '<sitemapindex') !== false ? 'index' : 'urlset';
        $list = [];
        if (preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)\s*(?:\]\]>)?\s*</loc>#i', $xml, $m)) {
            foreach ($m[1] as $u) {
                $u = html_entity_decode(trim($u), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (preg_match('#^https?://#i', $u)) $list[] = $u;
                if (count($list) >= 20000) break;
            }
        }
        return [$kind, $list];
    }
}
