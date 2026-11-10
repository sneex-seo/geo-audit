<?php
declare(strict_types=1);

require_once __DIR__ . '/Crawler.php';

/**
 * DOM-парсер сторінки. Будується один раз на сторінку, усі важкі обчислення кешуються.
 *
 *   $page = Page::fromHtml($html, $url);            // або Page::fromCrawl($crawlerResult)
 *   $page->title(); $page->schemaTypes(); $page->textBlocks(40); ...
 */
class Page
{
    /** Теги, вміст яких ніколи не є видимим текстом. */
    const SKIP_TAGS = [
        'script' => 1, 'style' => 1, 'noscript' => 1, 'template' => 1, 'svg' => 1, 'head' => 1,
        'iframe' => 1, 'object' => 1, 'embed' => 1, 'canvas' => 1, 'math' => 1, 'video' => 1, 'audio' => 1,
        'select' => 1, 'datalist' => 1, 'link' => 1, 'meta' => 1, 'title' => 1,
    ];
    /** Інлайн-теги, які не розділяють слова (усі інші обгортаємо пробілами). */
    const INLINE_NOSPACE = [
        'b' => 1, 'strong' => 1, 'i' => 1, 'em' => 1, 'u' => 1, 's' => 1, 'mark' => 1, 'small' => 1,
        'sub' => 1, 'sup' => 1, 'abbr' => 1, 'q' => 1, 'cite' => 1, 'code' => 1, 'kbd' => 1, 'font' => 1,
        'wbr' => 1, 'bdi' => 1, 'bdo' => 1, 'time' => 1, 'data' => 1, 'var' => 1, 'del' => 1, 'ins' => 1,
        'dfn' => 1, 'samp' => 1, 'big' => 1, 'tt' => 1, 'strike' => 1,
    ];
    /** Кандидати на текстовий блок (абзац). */
    const BLOCK_CAND = ['p' => 1, 'li' => 1, 'blockquote' => 1, 'dd' => 1, 'td' => 1, 'figcaption' => 1, 'dt' => 1, 'th' => 1];
    /** Контейнери, які стають блоком, лише якщо мають власний (прямий) текст. */
    const CONTAINER_CAND = ['div' => 1, 'section' => 1, 'article' => 1, 'main' => 1, 'aside' => 1];
    /** Блочні елементи: якщо вони є всередині кандидата, кандидат не «листовий». */
    const BLOCKISH = [
        'p' => 1, 'li' => 1, 'blockquote' => 1, 'dd' => 1, 'dt' => 1, 'td' => 1, 'th' => 1, 'tr' => 1, 'div' => 1,
        'section' => 1, 'article' => 1, 'main' => 1, 'aside' => 1, 'ul' => 1, 'ol' => 1, 'dl' => 1, 'table' => 1,
        'tbody' => 1, 'thead' => 1, 'tfoot' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1,
        'form' => 1, 'header' => 1, 'footer' => 1, 'nav' => 1, 'figure' => 1, 'figcaption' => 1, 'pre' => 1,
        'address' => 1, 'fieldset' => 1, 'details' => 1, 'hr' => 1,
    ];
    const NAV_TAGS = ['nav' => 1, 'header' => 1, 'footer' => 1];
    const NAV_ROLES = ['navigation' => 1, 'banner' => 1, 'contentinfo' => 1];

    /** Підтипи schema.org (батько => прямі нащадки). Розгортається рекурсивно. */
    const SUBTYPES = [
        'Thing' => ['Organization', 'Person', 'Place', 'CreativeWork', 'Product', 'Event', 'Intangible', 'Action'],
        'Organization' => ['Corporation', 'OnlineBusiness', 'NGO', 'EducationalOrganization', 'MedicalOrganization',
            'NewsMediaOrganization', 'LocalBusiness', 'Airline', 'Consortium', 'FundingScheme', 'GovernmentOrganization',
            'LibrarySystem', 'PerformingGroup', 'PoliticalParty', 'Project', 'ResearchOrganization',
            'SearchRescueOrganization', 'SportsOrganization', 'WorkersUnion', 'Cooperative'],
        'OnlineBusiness' => ['OnlineStore'],
        'Project' => ['FundingAgency', 'ResearchProject'],
        'EducationalOrganization' => ['CollegeOrUniversity', 'ElementarySchool', 'HighSchool', 'MiddleSchool', 'Preschool', 'School'],
        'MedicalOrganization' => ['Dentist', 'Hospital', 'Pharmacy', 'Physician', 'DiagnosticLab', 'MedicalClinic', 'VeterinaryCare'],
        'SportsOrganization' => ['SportsTeam'],
        'PerformingGroup' => ['DanceGroup', 'MusicGroup', 'TheaterGroup'],
        'LocalBusiness' => ['AnimalShelter', 'ArchiveOrganization', 'AutomotiveBusiness', 'ChildCare', 'Dentist',
            'DryCleaningOrLaundry', 'EmergencyService', 'EmploymentAgency', 'EntertainmentBusiness', 'FinancialService',
            'FoodEstablishment', 'GovernmentOffice', 'HealthAndBeautyBusiness', 'HomeAndConstructionBusiness',
            'InternetCafe', 'LegalService', 'Library', 'LodgingBusiness', 'MedicalBusiness', 'ProfessionalService',
            'RadioStation', 'RealEstateAgent', 'RecyclingCenter', 'SelfStorage', 'ShoppingCenter',
            'SportsActivityLocation', 'Store', 'TelevisionStation', 'TouristInformationCenter', 'TravelAgency',
            'EducationalOrganization'],
        'AutomotiveBusiness' => ['AutoBodyShop', 'AutoDealer', 'AutoPartsStore', 'AutoRental', 'AutoRepair', 'AutoWash',
            'GasStation', 'MotorcycleDealer', 'MotorcycleRepair'],
        'EmergencyService' => ['FireStation', 'Hospital', 'PoliceStation'],
        'EntertainmentBusiness' => ['AdultEntertainment', 'AmusementPark', 'ArtGallery', 'Casino', 'ComedyClub', 'MovieTheater', 'NightClub'],
        'FinancialService' => ['AccountingService', 'AutomatedTeller', 'BankOrCreditUnion', 'InsuranceAgency'],
        'FoodEstablishment' => ['Bakery', 'BarOrPub', 'Brewery', 'CafeOrCoffeeShop', 'Distillery', 'FastFoodRestaurant',
            'IceCreamShop', 'Restaurant', 'Winery'],
        'GovernmentOffice' => ['PostOffice'],
        'HealthAndBeautyBusiness' => ['BeautySalon', 'DaySpa', 'HairSalon', 'HealthClub', 'NailSalon', 'TattooParlor'],
        'HomeAndConstructionBusiness' => ['Electrician', 'GeneralContractor', 'HVACBusiness', 'HousePainter', 'Locksmith',
            'MovingCompany', 'Plumber', 'RoofingContractor'],
        'LegalService' => ['Attorney', 'Notary'],
        'LodgingBusiness' => ['BedAndBreakfast', 'Campground', 'Hostel', 'Hotel', 'Motel', 'Resort', 'VacationRental'],
        'Resort' => ['SkiResort'],
        'MedicalBusiness' => ['CommunityHealth', 'Dentist', 'Dermatology', 'DietNutrition', 'Emergency', 'Geriatric',
            'Gynecologic', 'MedicalClinic', 'Midwifery', 'Nursing', 'Obstetric', 'Oncologic', 'Optician', 'Optometric',
            'Otolaryngologic', 'Pediatric', 'Pharmacy', 'Physician', 'Physiotherapy', 'PlasticSurgery', 'Podiatric',
            'PrimaryCare', 'Psychiatric', 'PublicHealth'],
        'MedicalClinic' => ['CovidTestingFacility'],
        'ProfessionalService' => ['AccountingService', 'Attorney', 'Notary', 'Electrician', 'GeneralContractor',
            'HousePainter', 'Locksmith', 'Plumber', 'RoofingContractor', 'LegalService'],
        'SportsActivityLocation' => ['BowlingAlley', 'ExerciseGym', 'GolfCourse', 'HealthClub', 'PublicSwimmingPool',
            'SkiResort', 'SportsClub', 'StadiumOrArena', 'TennisComplex'],
        'Store' => ['AutoPartsStore', 'BikeStore', 'BookStore', 'ClothingStore', 'ComputerStore', 'ConvenienceStore',
            'DepartmentStore', 'ElectronicsStore', 'Florist', 'FurnitureStore', 'GardenStore', 'GroceryStore',
            'HardwareStore', 'HobbyShop', 'HomeGoodsStore', 'JewelryStore', 'LiquorStore', 'MensClothingStore',
            'MobilePhoneStore', 'MovieRentalStore', 'MusicStore', 'OfficeEquipmentStore', 'OutletStore', 'PawnShop',
            'PetStore', 'ShoeStore', 'SportingGoodsStore', 'TireShop', 'ToyStore', 'WholesaleStore'],
        'CreativeWork' => ['Article', 'WebPage', 'WebSite', 'Book', 'Course', 'HowTo', 'Recipe', 'Review', 'SoftwareApplication',
            'MediaObject', 'Comment', 'Dataset', 'Guide', 'Movie', 'MusicRecording', 'Photograph', 'Question', 'Answer',
            'SocialMediaPosting', 'WebPageElement', 'Blog', 'Collection', 'CreativeWorkSeries', 'Clip', 'Episode'],
        'Article' => ['NewsArticle', 'BlogPosting', 'TechArticle', 'Report', 'ScholarlyArticle', 'AdvertiserContentArticle',
            'SatiricalArticle', 'SocialMediaPosting'],
        'NewsArticle' => ['AnalysisNewsArticle', 'AskPublicNewsArticle', 'BackgroundNewsArticle', 'OpinionNewsArticle',
            'ReportageNewsArticle', 'ReviewNewsArticle'],
        'SocialMediaPosting' => ['BlogPosting', 'DiscussionForumPosting'],
        'BlogPosting' => ['LiveBlogPosting'],
        'TechArticle' => ['APIReference'],
        'ScholarlyArticle' => ['MedicalScholarlyArticle'],
        'Comment' => ['Answer', 'CorrectionComment'],
        'Product' => ['ProductGroup', 'IndividualProduct', 'Vehicle', 'ProductModel', 'SomeProducts', 'DietarySupplement', 'Drug'],
        'Vehicle' => ['Car', 'Motorcycle', 'BusOrCoach', 'MotorizedBicycle'],
        'WebPage' => ['AboutPage', 'ContactPage', 'CollectionPage', 'ItemPage', 'FAQPage', 'ProfilePage', 'QAPage',
            'SearchResultsPage', 'MedicalWebPage', 'CheckoutPage', 'RealEstateListing'],
        'CollectionPage' => ['MediaGallery'],
        'MediaGallery' => ['ImageGallery', 'VideoGallery'],
        'MediaObject' => ['ImageObject', 'VideoObject', 'AudioObject', '3DModel', 'DataDownload', 'MusicVideoObject'],
        'SoftwareApplication' => ['MobileApplication', 'WebApplication', 'VideoGame'],
        'Intangible' => ['Offer', 'Service', 'Rating', 'ItemList', 'Brand', 'ContactPoint', 'StructuredValue', 'Audience',
            'Demand', 'JobPosting', 'ListItem', 'Language', 'Trip', 'Reservation', 'Ticket', 'Order'],
        'Offer' => ['AggregateOffer', 'OfferForLease', 'OfferForPurchase'],
        'Rating' => ['AggregateRating', 'EndorsementRating'],
        'Review' => ['CriticReview', 'EmployerReview', 'MediaReview', 'Recommendation', 'UserReview'],
        'ItemList' => ['BreadcrumbList', 'HowToSection', 'HowToStep', 'OfferCatalog'],
        'Service' => ['BroadcastService', 'CableOrSatelliteService', 'FinancialProduct', 'FoodService', 'GovernmentService',
            'TaxiService', 'WebAPI'],
        'FinancialProduct' => ['BankAccount', 'CurrencyConversionService', 'InvestmentOrDeposit', 'LoanOrCredit', 'PaymentCard', 'PaymentService'],
        'StructuredValue' => ['ContactPoint', 'PostalAddress', 'GeoCoordinates', 'OpeningHoursSpecification', 'PriceSpecification',
            'QuantitativeValue', 'MonetaryAmount', 'ShippingDeliveryTime', 'OfferShippingDetails'],
        'ContactPoint' => ['PostalAddress'],
        'Place' => ['LocalBusiness', 'Accommodation', 'AdministrativeArea', 'CivicStructure', 'Landform', 'Residence',
            'TouristAttraction', 'TouristDestination'],
        'Person' => ['Patient'],
        'Event' => ['BusinessEvent', 'ChildrensEvent', 'ComedyEvent', 'CourseInstance', 'DanceEvent', 'EducationEvent',
            'ExhibitionEvent', 'Festival', 'FoodEvent', 'LiteraryEvent', 'MusicEvent', 'SaleEvent', 'ScreeningEvent',
            'SocialEvent', 'SportsEvent', 'TheaterEvent', 'VisualArtsEvent'],
    ];

    private string $url;
    private string $baseHref;
    private string $html;
    private int $rawBytes;
    private array $headers;
    private DOMDocument $dom;
    private DOMXPath $xp;
    private array $c = [];

    private static array $subtypeCache = [];

    // ---------------------------------------------------------------- construction

    public static function fromHtml(string $html, string $url, array $headers = []): Page
    {
        return new Page($html, $url, $headers);
    }

    /** Зручний конструктор з результату Crawler::fetch(). null, якщо тіла немає. */
    public static function fromCrawl(array $res): ?Page
    {
        $body = (string)($res['body'] ?? '');
        if (trim($body) === '') return null;
        return new Page($body, (string)($res['final_url'] ?? ($res['url'] ?? '')), (array)($res['headers'] ?? []));
    }

    private function __construct(string $html, string $url, array $headers)
    {
        $this->url = $url;
        $this->rawBytes = strlen($html);
        $this->headers = $headers;
        $ct = null;
        if (!empty($headers['content-type'])) {
            $ct = is_array($headers['content-type']) ? (string)end($headers['content-type']) : (string)$headers['content-type'];
        }
        $this->html = self::toUtf8($html, $ct);

        $this->dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $flags = LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT;
        if (defined('LIBXML_PARSEHUGE')) $flags |= LIBXML_PARSEHUGE;
        $this->dom->loadHTML('<?xml encoding="UTF-8">' . self::fixHeadingBlocks($this->html), $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $this->xp = new DOMXPath($this->dom);

        $this->baseHref = $url;
        $b = $this->xp->query('//base[@href]');
        if ($b && $b->length) {
            $abs = Crawler::absolutize((string)$b->item(0)->getAttribute('href'), $url);
            if ($abs) $this->baseHref = $abs;
        }
    }

    /**
     * libxml розбирає HTML за правилами HTML4 і закриває <h1>-<h6> на першому блоковому тезі всередині:
     * <h2><p>Текст</p></h2> дає порожній H2, хоча браузер лишає текст у заголовку. Блокові теги всередині
     * заголовка перед розбором замінюємо на <span>, щоб DOM збігався з тим, що бачить браузер.
     */
    private static function fixHeadingBlocks(string $html): string
    {
        if (!preg_match('/<h[1-6][^>]*>\s*(?:<[^>]+>\s*)*<(?:p|div|section|ul|ol|li|blockquote|table|figure)\b/i', $html)
            && !preg_match('/<h[1-6][^>]*>[^<]{0,300}<(?:p|div)\b/i', $html)) return $html;
        $out = preg_replace_callback('/<h([1-6])\b([^>]*)>(.{0,3000}?)<\/h\1\s*>/is', function ($m) {
            // інший заголовок усередині - розмітка зламана, не чіпаємо
            if (preg_match('/<h[1-6]\b/i', $m[3])) return $m[0];
            $inner = preg_replace('/<(\/?)(?:p|div|section|article|header|footer|ul|ol|li|dl|dt|dd|blockquote|table|tbody|thead|tr|td|th|figure|figcaption|address|pre|nav|aside)\b/i', '<$1span', $m[3]);
            return '<h' . $m[1] . $m[2] . '>' . (is_string($inner) ? $inner : $m[3]) . '</h' . $m[1] . '>';
        }, $html);
        return is_string($out) ? $out : $html;
    }

    /**
     * Контентні рядки з даних JS-фреймворку (window.__NUXT__, __NEXT_DATA__, __NUXT_DATA__, __INITIAL_STATE__,
     * <script type="application/json">): значення ключів на кшталт title / description / text або рядки з HTML-розміткою CMS.
     * Повертає [['key' => .., 'text' => текст без тегів], ...] без повторів. Це те, що браузер домалює після JS.
     */
    public function hydrationTexts(): array
    {
        if (isset($this->c['hydration'])) return $this->c['hydration'];
        $out = []; $seen = [];
        $rxKey = '/^(title|subtitle|heading|header|name|label|description|desc|text|content|body|html|answer|question|summary|lead|intro|caption|excerpt|info|note|details?|paragraph|seo_?text|short_?description)$/i';
        foreach ($this->query('//script') as $s) {
            /** @var DOMElement $s */
            $type = strtolower(trim($s->getAttribute('type')));
            $id = $s->getAttribute('id');
            $code = (string)$s->textContent;
            if (strlen($code) < 200) continue;
            $isData = ($type === 'application/json' && $id !== '' && preg_match('/(nuxt|next|state|data|apollo|props|initial|preload)/i', $id))
                || preg_match('/window\.__(NUXT|INITIAL_STATE|PRELOADED_STATE|APOLLO_STATE|APP_STATE|DATA|STATE)__\s*=/', $code)
                || $id === '__NEXT_DATA__' || $id === '__NUXT_DATA__';
            if (!$isData || $type === 'application/ld+json') continue;
            if (!preg_match_all('/(?:"([A-Za-z_]\w{0,40})"|\b([A-Za-z_]\w{0,40}))\s*:\s*"((?:[^"\\\\]++|\\\\.)*+)"/s', $code, $mm, PREG_SET_ORDER)) continue;
            foreach ($mm as $m) {
                $key = $m[1] !== '' ? $m[1] : $m[2];
                $raw = $m[3];
                if (strlen($raw) < 25) continue;
                $dec = json_decode('"' . $raw . '"');
                if (!is_string($dec)) $dec = stripcslashes($raw);
                $hasHtml = (bool)preg_match('/<(p|h[1-6]|li|strong|br|ul|ol|b|em|a)\b/i', $dec);
                if (!$hasHtml && !preg_match($rxKey, $key)) continue;
                if (preg_match('#^(https?:)?//|^/[\w\-/]+\.(jpe?g|png|webp|svg|css|js)$#i', $dec)) continue;
                $t = self::norm(html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/li|\/h[1-6])\b[^>]*>/i', ' ', $dec)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($t === '' || preg_match('/[{};]\s*\w+\s*[:=(]|function\s*\(|=>/', $t)) continue;
                if (count(preg_split('/\s+/u', $t)) < 5 || !preg_match('/\p{L}{3,}/u', $t)) continue;
                $k = mb_strtolower(mb_substr($t, 0, 80));
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $out[] = ['key' => $key, 'text' => $t];
                if (count($out) >= 300) break 2;
            }
        }
        return $this->c['hydration'] = $out;
    }

    /**
     * Контентні рядки з даних JS-фреймворку, яких немає у видимому тексті HTML:
     * ['words' => сума слів, 'items' => перші приклади, 'source' => назва даних].
     */
    public function jsOnlyText(): array
    {
        if (isset($this->c['js_only'])) return $this->c['js_only'];
        $items = $this->hydrationTexts();
        if (!$items) return $this->c['js_only'] = ['words' => 0, 'items' => [], 'source' => ''];
        $norm = function (string $s): string { return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)))); };
        $vis = ' ' . $norm($this->visibleText() . ' ' . $this->title() . ' ' . $this->metaDescription()) . ' ';
        $words = 0; $out = [];
        foreach ($items as $it) {
            $n = $norm($it['text']);
            if ($n === '') continue;
            $w = explode(' ', $n);
            // перші 8 слів рядка шукаємо у видимому тексті: так не заважають розбіжності в розмітці всередині
            if (strpos($vis, ' ' . implode(' ', array_slice($w, 0, 8)) . ' ') !== false) continue;
            $words += count($w);
            if (count($out) < 5) $out[] = $it['text'];
        }
        $h = $this->html;
        $source = preg_match('/window\.__NUXT__|id=["\']__NUXT_DATA__/', $h) ? 'window.__NUXT__ (Nuxt)'
            : (preg_match('/id=["\']__NEXT_DATA__/', $h) ? '__NEXT_DATA__ (Next.js)'
            : (preg_match('/window\.__(INITIAL_STATE|PRELOADED_STATE|APOLLO_STATE)__/', $h, $m) ? 'window.__' . $m[1] . '__' : 'JSON у <script>'));
        return $this->c['js_only'] = ['words' => $words, 'items' => $out, 'source' => $source];
    }

    /** Приводить HTML до валідного UTF-8 (cp1251/koi8/latin1 тощо) і нейтралізує meta charset. */
    private static function toUtf8(string $html, ?string $contentType): string
    {
        $html = str_replace("\0", '', $html);
        if (strncmp($html, "\xEF\xBB\xBF", 3) === 0) $html = substr($html, 3);
        $charset = null;
        if ($contentType && preg_match('/charset\s*=\s*["\']?\s*([\w\-:.]+)/i', $contentType, $m)) $charset = strtolower($m[1]);
        $head = substr($html, 0, 8192);
        if (!$charset && preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([\w\-:.]+)/i', $head, $m)) $charset = strtolower($m[1]);

        $isUtf8Name = ($charset === null || $charset === 'utf-8' || $charset === 'utf8');
        $valid = mb_check_encoding($html, 'UTF-8');
        if ($isUtf8Name) {
            if ($valid) return $html;
            if ($charset === null && preg_match('/[\xC0-\xFF]{3,}/', $html)) {
                // без оголошення і невалідний UTF-8: для UA-ринку найімовірніше cp1251
                $out = @mb_convert_encoding($html, 'UTF-8', 'Windows-1251');
            } else {
                $out = mb_scrub($html, 'UTF-8');
            }
            return self::fixMetaCharset((string)$out);
        }
        // оголошено інше кодування, але байти - валідний UTF-8 з кирилицею: віримо байтам
        if ($valid && preg_match('/[\xD0\xD1][\x80-\xBF]/', $html)) return self::fixMetaCharset($html);
        $map = [
            'cp1251' => 'Windows-1251', 'windows-1251' => 'Windows-1251', 'win-1251' => 'Windows-1251', 'x-cp1251' => 'Windows-1251',
            'koi8-r' => 'KOI8-R', 'koi8-u' => 'KOI8-U', 'iso-8859-1' => 'ISO-8859-1', 'latin1' => 'ISO-8859-1',
            'windows-1252' => 'Windows-1252', 'cp1252' => 'Windows-1252', 'iso-8859-5' => 'ISO-8859-5', 'iso-8859-2' => 'ISO-8859-2',
            'windows-1250' => 'Windows-1250', 'us-ascii' => 'ASCII', 'ascii' => 'ASCII',
        ];
        $enc = $map[$charset] ?? $charset;
        try {
            $out = mb_convert_encoding($html, 'UTF-8', $enc);
        } catch (\Throwable $e) {
            $out = mb_scrub($html, 'UTF-8');
        }
        if (!is_string($out) || $out === '') $out = mb_scrub($html, 'UTF-8');
        return self::fixMetaCharset($out);
    }

    private static function fixMetaCharset(string $html): string
    {
        $headLen = 8192;
        $head = substr($html, 0, $headLen);
        $fixed = preg_replace('/(<meta[^>]+charset\s*=\s*["\']?\s*)[\w\-:.]+/i', '${1}utf-8', $head);
        if (!is_string($fixed)) return $html;
        return $fixed . substr($html, $headLen);
    }

    // ---------------------------------------------------------------- low-level access

    public function url(): string { return $this->url; }
    /** HTML у UTF-8 (для snippet()/regex). */
    public function html(): string { return $this->html; }
    public function htmlBytes(): int { return $this->rawBytes; }
    public function dom(): DOMDocument { return $this->dom; }
    public function xpath(): DOMXPath { return $this->xp; }
    public function headers(): array { return $this->headers; }

    /** XPath-запит, повертає масив DOMNode. */
    public function query(string $xpath, ?DOMNode $ctx = null): array
    {
        $r = @$this->xp->query($xpath, $ctx);
        if (!$r) return [];
        $out = [];
        foreach ($r as $n) $out[] = $n;
        return $out;
    }

    /** Кількість елементів тегу (поза script/template). */
    public function tagCount(string $tag): int
    {
        $tag = strtolower(preg_replace('/[^a-z0-9]/i', '', $tag));
        if ($tag === '') return 0;
        $r = $this->xp->query('//body//' . $tag);
        return $r ? $r->length : 0;
    }

    // ---------------------------------------------------------------- head / meta

    public function title(): string
    {
        if (!isset($this->c['title'])) {
            $n = $this->query('/html/head/title');
            if (!$n) $n = $this->query('//title[not(ancestor::svg)]');
            $t = $n ? self::norm($n[0]->textContent) : '';
            $this->c['title'] = self::decodeLeftovers($t);
        }
        return $this->c['title'];
    }

    public function metaDescription(): string
    {
        $m = $this->metaTags();
        return $m['description'] ?? '';
    }

    /** name/property/itemprop(meta) => content (перше входження), ключі в нижньому регістрі. */
    public function metaTags(): array
    {
        if (!isset($this->c['meta'])) {
            $out = [];
            foreach ($this->query('//meta') as $m) {
                /** @var DOMElement $m */
                $key = $m->getAttribute('name');
                if ($key === '') $key = $m->getAttribute('property');
                if ($key === '' && $m->hasAttribute('http-equiv')) $key = 'http-equiv:' . $m->getAttribute('http-equiv');
                if ($key === '' || !$m->hasAttribute('content')) continue;
                $key = strtolower(trim($key));
                if (!array_key_exists($key, $out)) {
                    $out[$key] = self::decodeLeftovers(self::norm($m->getAttribute('content')));
                }
            }
            $this->c['meta'] = $out;
        }
        return $this->c['meta'];
    }

    public function og(string $prop): ?string
    {
        $prop = strtolower($prop);
        if (strpos($prop, 'og:') !== 0) $prop = 'og:' . $prop;
        $m = $this->metaTags();
        return isset($m[$prop]) && $m[$prop] !== '' ? $m[$prop] : null;
    }

    /** Директиви meta robots + googlebot, напр. ['noindex','nosnippet','max-snippet:0']. */
    public function metaRobots(): array
    {
        if (!isset($this->c['robots'])) {
            $out = [];
            foreach ($this->query('//meta[@name]') as $m) {
                $n = strtolower(trim($m->getAttribute('name')));
                if ($n !== 'robots' && $n !== 'googlebot') continue;
                foreach (explode(',', strtolower($m->getAttribute('content'))) as $d) {
                    $d = preg_replace('/\s+/', '', $d);
                    if ($d !== '') $out[] = $d;
                }
            }
            $this->c['robots'] = array_values(array_unique($out));
        }
        return $this->c['robots'];
    }

    /** Директиви з HTTP-заголовка X-Robots-Tag (якщо сторінку створено з fromCrawl). */
    public function xRobotsTag(): array
    {
        $out = [];
        foreach ((array)($this->headers['x-robots-tag'] ?? []) as $v) {
            foreach (explode(',', strtolower((string)$v)) as $d) {
                $d = preg_replace('/\s+/', '', $d);
                if ($d !== '') $out[] = $d;
            }
        }
        return array_values(array_unique($out));
    }

    public function canonical(): ?string
    {
        if (!array_key_exists('canonical', $this->c)) {
            $val = null;
            foreach ($this->query('//link[@rel][@href]') as $l) {
                $rels = preg_split('/\s+/', strtolower(trim($l->getAttribute('rel'))));
                if (in_array('canonical', $rels, true)) {
                    $val = Crawler::absolutize($l->getAttribute('href'), $this->baseHref, false);
                    break;
                }
            }
            $this->c['canonical'] = $val;
        }
        return $this->c['canonical'];
    }

    public function lang(): ?string
    {
        if (!array_key_exists('lang', $this->c)) {
            $v = null;
            $h = $this->query('/html');
            if ($h) {
                $v = trim($h[0]->getAttribute('lang'));
                if ($v === '') $v = trim($h[0]->getAttribute('xml:lang'));
            }
            if (!$v) {
                $m = $this->metaTags();
                $v = $m['http-equiv:content-language'] ?? null;
            }
            $this->c['lang'] = $v !== null && $v !== '' ? $v : null;
        }
        return $this->c['lang'];
    }

    /** [['lang'=>'uk','href'=>abs], ...] з <link rel="alternate" hreflang>. */
    public function hreflangs(): array
    {
        if (!isset($this->c['hreflang'])) {
            $out = [];
            foreach ($this->query('//link[@hreflang][@href]') as $l) {
                $rels = preg_split('/\s+/', strtolower(trim($l->getAttribute('rel'))));
                if (!in_array('alternate', $rels, true)) continue;
                $href = Crawler::absolutize($l->getAttribute('href'), $this->baseHref, false);
                if ($href === null) continue;
                $out[] = ['lang' => trim($l->getAttribute('hreflang')), 'href' => $href];
            }
            $this->c['hreflang'] = $out;
        }
        return $this->c['hreflang'];
    }

    /** ['h1'=>[...], 'h2'=>[...], ... 'h6'=>[...]] - тексти заголовків. */
    public function headings(): array
    {
        if (!isset($this->c['headings'])) {
            $out = ['h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => []];
            foreach ($this->query('//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $h) {
                if ($this->inSkipped($h)) continue;
                $t = $this->elementText($h);
                if ($t === '') {
                    foreach ($this->query('.//img[@alt]', $h) as $img) {
                        $t = self::norm($img->getAttribute('alt'));
                        if ($t !== '') break;
                    }
                }
                $out[strtolower($h->nodeName)][] = $t;
            }
            $this->c['headings'] = $out;
        }
        return $this->c['headings'];
    }

    // ---------------------------------------------------------------- text

    /** Увесь видимий текст сторінки (без script/style/noscript/template/svg), нормалізовані пробіли. */
    public function visibleText(): string
    {
        $this->walkBody();
        return $this->c['visible'];
    }

    /** Основний контент: <main>/[role=main]/єдиний <article>, інакше body без header/nav/footer/aside. */
    public function mainText(): string
    {
        if (!isset($this->c['main'])) {
            $chromeless = null;
            $getChromeless = function () use (&$chromeless) {
                if ($chromeless === null) {
                    $body = $this->query('//body');
                    $chromeless = $body ? $this->collectText($body[0], true) : '';
                }
                return $chromeless;
            };
            $text = null;
            $source = 'body';
            foreach ($this->query('//main | //*[@role="main"]') as $m) {
                if ($this->inSkipped($m)) continue;
                $t = $this->collectText($m, false);
                if ($this->wordCount($t) >= 30) { $text = $t; $source = 'main'; break; }
            }
            if ($text === null) {
                $arts = [];
                foreach ($this->query('//article') as $a) {
                    if (!$this->inSkipped($a) && !$this->query('ancestor::article', $a)) $arts[] = $a;
                }
                if (count($arts) === 1) {
                    $t = $this->collectText($arts[0], false);
                    if ($this->wordCount($t) >= 50) { $text = $t; $source = 'article'; }
                }
            }
            if ($text !== null) {
                // захист від <main>, що обгортає лише банер
                $mw = $this->wordCount($text);
                if ($mw < 100) {
                    $cw = $this->wordCount($getChromeless());
                    if ($cw > 0 && $mw < $cw * 0.25) { $text = null; }
                }
            }
            if ($text === null) { $text = $getChromeless(); $source = 'body'; }
            $this->c['main'] = $text;
            $this->c['main_source'] = $source;
        }
        return $this->c['main'];
    }

    /** Звідки взято mainText(): 'main' | 'article' | 'body'. */
    public function mainSource(): string
    {
        $this->mainText();
        return $this->c['main_source'];
    }

    /** Кількість слів (токени з літерою або цифрою), unicode. За замовчуванням - видимий текст. */
    public function wordCount(?string $text = null): int
    {
        if ($text === null) $text = $this->visibleText();
        if ($text === '') return 0;
        $n = preg_match_all('/[^\s\x{00A0}]*[\p{L}\p{N}][^\s\x{00A0}]*/u', $text);
        return $n === false ? 0 : $n;
    }

    /**
     * Текстові блоки: p, li, blockquote, dd, td, figcaption + «листові» div/section із власним текстом.
     * Вкладені блоки не дублюються: батьківський елемент дає лише свій прямий текст.
     * @return array<int, array{tag:string,text:string,words:int,in_nav:bool}>
     */
    public function textBlocks(int $minWords = 20): array
    {
        $this->walkBody();
        $out = [];
        foreach ($this->c['blocks'] as $b) {
            if ($b['words'] >= $minWords) $out[] = $b;
        }
        return $out;
    }

    // ---------------------------------------------------------------- links / images

    /**
     * [['href'=>abs,'text'=>..,'rel'=>..,'internal'=>bool,'in_nav'=>bool], ...]
     */
    public function links(): array
    {
        if (!isset($this->c['links'])) {
            $out = [];
            foreach ($this->query('//a[@href]') as $a) {
                $rec = $this->linkRec($a);
                if ($rec !== null) $out[] = $rec;
            }
            $this->c['links'] = $out;
        }
        return $this->c['links'];
    }

    /** Запис посилання у форматі links() або null (javascript:, mailto: тощо). */
    private function linkRec(DOMElement $a): ?array
    {
        $href = Crawler::absolutize($a->getAttribute('href'), $this->baseHref);
        if ($href === null) return null;
        $text = self::norm($a->textContent);
        if ($text === '') {
            foreach ($this->query('.//img[@alt]', $a) as $img) {
                $text = self::norm($img->getAttribute('alt'));
                if ($text !== '') break;
            }
        }
        if ($text === '') $text = self::norm($a->getAttribute('aria-label'));
        if ($text === '') $text = self::norm($a->getAttribute('title'));
        return [
            'href'     => $href,
            'text'     => self::mbCut($text, 200),
            'rel'      => strtolower(trim($a->getAttribute('rel'))),
            'internal' => Crawler::sameSite($href, $this->url),
            'in_nav'   => $this->inNav($a),
        ];
    }

    /**
     * Посилання футера (формат links(), без повторів href): <footer> / role=contentinfo, інакше блок
     * з class/id «footer» / «site-footer» (не «card-footer»), інакше останні 40 внутрішніх посилань сторінки.
     */
    public function footerLinks(): array
    {
        if (!isset($this->c['footer_links'])) {
            $anchors = $this->query('//footer//a[@href] | //*[@role="contentinfo"]//a[@href]');
            if (!$anchors) {
                foreach ($this->query('//*[@class or @id]') as $el) {
                    /** @var DOMElement $el */
                    $tokens = preg_split('/\s+/', strtolower(trim($el->getAttribute('class') . ' ' . $el->getAttribute('id'))));
                    if (!preg_grep('/^(site-|page-|main-|global-)?footer$/', $tokens)) continue;
                    $anchors = array_merge($anchors, $this->query('.//a[@href]', $el));
                }
            }
            $out = []; $seen = [];
            foreach ($anchors as $a) {
                $rec = $this->linkRec($a);
                if ($rec === null || isset($seen[$rec['href']])) continue;
                $seen[$rec['href']] = true;
                $out[] = $rec;
            }
            if (!$out) {
                foreach (array_slice($this->internalLinks(), -40) as $rec) {
                    if (isset($seen[$rec['href']])) continue;
                    $seen[$rec['href']] = true;
                    $out[] = $rec;
                }
            }
            $this->c['footer_links'] = $out;
        }
        return $this->c['footer_links'];
    }

    public function internalLinks(): array
    {
        return array_values(array_filter($this->links(), function ($l) { return $l['internal']; }));
    }

    public function externalLinks(): array
    {
        return array_values(array_filter($this->links(), function ($l) { return !$l['internal']; }));
    }

    /** [['src'=>abs|'' ,'alt'=>string|null], ...]; alt=null, якщо атрибута немає. */
    public function images(): array
    {
        if (!isset($this->c['images'])) {
            $out = [];
            foreach ($this->query('//img') as $img) {
                /** @var DOMElement $img */
                if ($this->inSkipped($img)) continue;
                $src = trim($img->getAttribute('src'));
                if ($src === '' || stripos($src, 'data:') === 0) {
                    foreach (['data-src', 'data-lazy-src', 'data-original', 'data-srcset', 'srcset'] as $attr) {
                        $v = trim($img->getAttribute($attr));
                        if ($v !== '') { $src = trim(explode(' ', $v)[0]); break; }
                    }
                }
                $abs = ($src !== '' && stripos($src, 'data:') !== 0) ? (Crawler::absolutize($src, $this->baseHref) ?? $src) : $src;
                $out[] = [
                    'src' => $abs,
                    'alt' => $img->hasAttribute('alt') ? self::norm($img->getAttribute('alt')) : null,
                ];
            }
            $this->c['images'] = $out;
        }
        return $this->c['images'];
    }

    /** src усіх iframe (карти, відео тощо). */
    public function iframes(): array
    {
        $out = [];
        foreach ($this->query('//iframe') as $f) {
            $s = trim($f->getAttribute('src'));
            if ($s === '') $s = trim($f->getAttribute('data-src'));
            if ($s !== '') $out[] = Crawler::absolutize($s, $this->baseHref) ?? $s;
        }
        return $out;
    }

    // ---------------------------------------------------------------- structured data

    /** Декодовані JSON-LD блоки (з ремонтом типових помилок). */
    public function jsonLdRaw(): array
    {
        $this->parseJsonLd();
        return $this->c['jsonld'];
    }

    /** Кількість JSON-LD блоків, які не вдалося розібрати навіть після ремонту. */
    public function jsonLdErrors(): int
    {
        $this->parseJsonLd();
        return $this->c['jsonld_errors'];
    }

    /** Деталі по блоках: [['ok'=>bool,'repaired'=>bool,'error'=>string,'excerpt'=>string], ...] */
    public function jsonLdBlocksInfo(): array
    {
        $this->parseJsonLd();
        return $this->c['jsonld_info'];
    }

    private function parseJsonLd(): void
    {
        if (isset($this->c['jsonld'])) return;
        $ok = []; $errors = 0; $info = [];
        foreach ($this->query('//script[@type]') as $s) {
            $type = strtolower(trim($s->getAttribute('type')));
            if (strpos($type, 'ld+json') === false) continue;
            $raw = trim($s->textContent);
            if ($raw === '') continue;
            $repaired = false;
            $data = json_decode($raw, true);
            $err = '';
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                $err = json_last_error_msg();
                $data = self::repairJson($raw);
                $repaired = $data !== null;
            }
            if ($data === null || (!is_array($data))) {
                $errors++;
                $info[] = ['ok' => false, 'repaired' => false, 'error' => $err ?: 'not an object', 'excerpt' => self::mbCut(self::norm($raw), 300)];
                continue;
            }
            $ok[] = $data;
            $info[] = ['ok' => true, 'repaired' => $repaired, 'error' => $repaired ? $err : '', 'excerpt' => self::mbCut(self::norm($raw), 160)];
        }
        $this->c['jsonld'] = $ok;
        $this->c['jsonld_errors'] = $errors;
        $this->c['jsonld_info'] = $info;
    }

    private static function repairJson(string $raw): ?array
    {
        $s = $raw;
        $s = preg_replace('/^\s*<!--|-->\s*$/', '', $s);
        $s = preg_replace('/^\s*\/\/\s*<!\[CDATA\[|\/\/\s*\]\]>\s*$/', '', $s);
        $s = preg_replace('/^\s*<!\[CDATA\[|\]\]>\s*$/', '', $s);
        $s = trim((string)$s);
        $s = preg_replace('/[\x00-\x1F]/', ' ', $s);         // сирі переноси/табуляції в рядках
        $s = preg_replace('/,\s*([}\]])/', '$1', (string)$s);   // висячі коми
        $s = preg_replace('/;\s*$/', '', (string)$s);
        $d = json_decode((string)$s, true);
        if (is_array($d)) return $d;
        // кілька об'єктів підряд без масиву
        $s2 = '[' . preg_replace('/}\s*{/', '},{', (string)$s) . ']';
        $d = json_decode($s2, true);
        if (is_array($d)) return $d;
        // HTML-сутності всередині JSON
        $d = json_decode(html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        return is_array($d) ? $d : null;
    }

    /**
     * Усі типізовані вузли: JSON-LD (вкладені, @graph), microdata, RDFa.
     * @return array<int, array{types:string[],data:array,source:string}>
     */
    public function schemaItems(): array
    {
        if (!isset($this->c['schema'])) {
            $items = [];
            foreach ($this->jsonLdRaw() as $block) $this->flattenJsonLd($block, $items, 0);
            $this->collectMicrodata($items);
            $this->collectRdfa($items);
            $this->c['schema'] = $items;
        }
        return $this->c['schema'];
    }

    private function flattenJsonLd($node, array &$items, int $depth): void
    {
        if ($depth > 12 || !is_array($node)) return;
        if (self::isList($node)) {
            foreach ($node as $v) $this->flattenJsonLd($v, $items, $depth + 1);
            return;
        }
        if (isset($node['@type'])) {
            $types = [];
            foreach ((array)$node['@type'] as $t) {
                if (is_string($t) && ($st = self::shortType($t)) !== '') $types[] = $st;
            }
            if ($types) $items[] = ['types' => array_values(array_unique($types)), 'data' => $node, 'source' => 'json-ld'];
        }
        foreach ($node as $k => $v) {
            if (is_array($v)) $this->flattenJsonLd($v, $items, $depth + 1);
        }
    }

    private function collectMicrodata(array &$items): void
    {
        $scopes = $this->query('//*[@itemscope]');
        if (!$scopes) return;
        $index = new SplObjectStorage();
        $data = [];
        foreach ($scopes as $i => $el) {
            $index[$el] = $i;
            $types = [];
            foreach (preg_split('/\s+/', trim($el->getAttribute('itemtype'))) as $t) {
                if ($t !== '' && ($st = self::shortType($t)) !== '') $types[] = $st;
            }
            $data[$i] = ['types' => $types, 'props' => []];
        }
        foreach ($this->query('//*[@itemprop]') as $p) {
            /** @var DOMElement $p */
            $owner = $p->parentNode;
            while ($owner instanceof DOMElement && !$index->contains($owner)) $owner = $owner->parentNode;
            if (!($owner instanceof DOMElement)) continue;
            $oi = $index[$owner];
            if (count($data[$oi]['props']) > 80) continue;
            if ($p->hasAttribute('itemscope')) {
                $sub = $index->contains($p) ? $data[$index[$p]]['types'] : [];
                $val = ['@type' => count($sub) === 1 ? $sub[0] : $sub];
            } else {
                $val = $this->itemValue($p);
            }
            foreach (preg_split('/\s+/', trim($p->getAttribute('itemprop'))) as $prop) {
                if ($prop === '') continue;
                if (!isset($data[$oi]['props'][$prop])) $data[$oi]['props'][$prop] = $val;
                elseif (is_array($data[$oi]['props'][$prop]) && self::isList($data[$oi]['props'][$prop])) $data[$oi]['props'][$prop][] = $val;
                else $data[$oi]['props'][$prop] = [$data[$oi]['props'][$prop], $val];
            }
        }
        foreach ($data as $d) {
            if (!$d['types']) continue;
            $items[] = ['types' => array_values(array_unique($d['types'])), 'data' => ['@type' => count($d['types']) === 1 ? $d['types'][0] : $d['types']] + $d['props'], 'source' => 'microdata'];
        }
    }

    private function itemValue(DOMElement $p)
    {
        foreach (['content', 'datetime'] as $a) if ($p->hasAttribute($a)) return self::norm($p->getAttribute($a));
        $tag = strtolower($p->nodeName);
        if (in_array($tag, ['a', 'link', 'area'], true)) return (string)(Crawler::absolutize($p->getAttribute('href'), $this->baseHref, false) ?? $p->getAttribute('href'));
        if (in_array($tag, ['img', 'audio', 'video', 'source', 'iframe', 'embed'], true)) return (string)(Crawler::absolutize($p->getAttribute('src'), $this->baseHref) ?? $p->getAttribute('src'));
        if ($tag === 'meta') return self::norm($p->getAttribute('content'));
        if ($tag === 'data' || $tag === 'meter') return self::norm($p->getAttribute('value'));
        return self::mbCut(self::norm($p->textContent), 300);
    }

    private function collectRdfa(array &$items): void
    {
        $nodes = $this->query('//*[@typeof]');
        if (!$nodes) return;
        foreach ($nodes as $el) {
            /** @var DOMElement $el */
            $vocab = '';
            for ($n = $el; $n instanceof DOMElement; $n = $n->parentNode) {
                if ($n->hasAttribute('vocab')) { $vocab = strtolower($n->getAttribute('vocab')); break; }
            }
            $types = [];
            foreach (preg_split('/\s+/', trim($el->getAttribute('typeof'))) as $t) {
                if ($t === '') continue;
                if (preg_match('#^(https?://schema\.org/|schema:)#i', $t)) $types[] = self::shortType($t);
                elseif (strpos($t, ':') === false && strpos($vocab, 'schema.org') !== false) $types[] = $t;
            }
            $types = array_values(array_unique(array_filter($types)));
            if (!$types) continue;
            $props = [];
            foreach ($this->query('.//*[@property]', $el) as $p) {
                // лише властивості, найближчий typeof-предок яких - цей вузол
                $owner = $p->parentNode;
                while ($owner instanceof DOMElement && !$owner->hasAttribute('typeof')) $owner = $owner->parentNode;
                if ($owner !== $el) continue;
                $prop = preg_replace('#^(https?://schema\.org/|schema:)#i', '', trim($p->getAttribute('property')));
                if ($prop === '' || strpos($prop, ':') !== false || isset($props[$prop])) continue;
                $props[$prop] = $p->hasAttribute('content') ? self::norm($p->getAttribute('content'))
                    : ($p->hasAttribute('href') ? $p->getAttribute('href') : self::mbCut(self::norm($p->textContent), 300));
                if (count($props) > 60) break;
            }
            $items[] = ['types' => $types, 'data' => ['@type' => count($types) === 1 ? $types[0] : $types] + $props, 'source' => 'rdfa'];
        }
    }

    /** Унікальні короткі назви типів (без http://schema.org/). */
    public function schemaTypes(): array
    {
        if (!isset($this->c['schema_types'])) {
            $t = [];
            foreach ($this->schemaItems() as $it) foreach ($it['types'] as $x) $t[$x] = true;
            $this->c['schema_types'] = array_keys($t);
        }
        return $this->c['schema_types'];
    }

    /** Типи з розбивкою за джерелом: ['json-ld'=>[...], 'microdata'=>[...], 'rdfa'=>[...]]. */
    public function schemaTypesBySource(): array
    {
        $out = [];
        foreach ($this->schemaItems() as $it) foreach ($it['types'] as $x) $out[$it['source']][$x] = true;
        return array_map('array_keys', $out);
    }

    /** Чи є хоч один з типів (з урахуванням підтипів schema.org). */
    public function hasSchemaType($types, bool $includeSubtypes = true): bool
    {
        $want = self::expandTypes((array)$types, $includeSubtypes);
        foreach ($this->schemaTypes() as $t) {
            if (isset($want[strtolower($t)])) return true;
        }
        return false;
    }

    /** Вузли, що мають хоч один з типів (з урахуванням підтипів). */
    public function schemaItemsOfType($types, bool $includeSubtypes = true): array
    {
        $want = self::expandTypes((array)$types, $includeSubtypes);
        $out = [];
        foreach ($this->schemaItems() as $it) {
            foreach ($it['types'] as $t) {
                if (isset($want[strtolower($t)])) { $out[] = $it; break; }
            }
        }
        return $out;
    }

    /** Множина (lowercase => true) типу та всіх його підтипів. */
    public static function expandTypes(array $types, bool $includeSubtypes = true): array
    {
        $out = [];
        foreach ($types as $t) {
            $t = self::shortType((string)$t);
            if ($t === '') continue;
            if (!$includeSubtypes) { $out[strtolower($t)] = true; continue; }
            if (!isset(self::$subtypeCache[$t])) {
                $set = [];
                $stack = [$t];
                while ($stack) {
                    $x = array_pop($stack);
                    if (isset($set[strtolower($x)])) continue;
                    $set[strtolower($x)] = true;
                    foreach (self::SUBTYPES[$x] ?? [] as $child) $stack[] = $child;
                }
                self::$subtypeCache[$t] = $set;
            }
            $out += self::$subtypeCache[$t];
        }
        return $out;
    }

    public static function shortType(string $t): string
    {
        $t = trim($t);
        $t = preg_replace('#^https?://(www\.)?schema\.org/#i', '', $t);
        $t = preg_replace('#^schema:#i', '', (string)$t);
        $t = trim((string)$t, '/ ');
        if ($t === '' || strlen($t) > 80) return '';
        return $t;
    }

    // ---------------------------------------------------------------- scripts / frameworks

    /** ['count'=>int, 'inline_bytes'=>int, 'external'=>[abs urls], 'jsonld_bytes'=>int] */
    public function scripts(): array
    {
        if (!isset($this->c['scripts'])) {
            $count = 0; $inline = 0; $ext = []; $ld = 0;
            foreach ($this->query('//script') as $s) {
                $count++;
                $src = trim($s->getAttribute('src'));
                if ($src !== '') {
                    $abs = Crawler::absolutize($src, $this->baseHref);
                    if ($abs) $ext[] = $abs;
                    continue;
                }
                $len = strlen($s->textContent);
                if (strpos(strtolower($s->getAttribute('type')), 'ld+json') !== false) $ld += $len;
                else $inline += $len;
            }
            $this->c['scripts'] = ['count' => $count, 'inline_bytes' => $inline, 'external' => array_values(array_unique($ext)), 'jsonld_bytes' => $ld];
        }
        return $this->c['scripts'];
    }

    /** Фреймворки за сильними маркерами: next, nuxt, react, vue, angular, svelte, gatsby, remix, astro. */
    public function frameworkMarkers(): array
    {
        if (!isset($this->c['fw'])) {
            $h = $this->html;
            $fw = [];
            if (preg_match('/id=["\']__next["\']|id=["\']__NEXT_DATA__["\']|\/_next\/static\/|self\.__next_f/', $h)) $fw['next'] = true;
            if (preg_match('/id=["\']__nuxt["\']|window\.__NUXT__|\/_nuxt\/|data-n-head=/', $h)) $fw['nuxt'] = true;
            if (preg_match('/id=["\']___gatsby["\']|window\.___gatsby|gatsby-focus-wrapper/', $h)) $fw['gatsby'] = true;
            if (preg_match('/window\.__remixContext|__remixManifest|window\.__reactRouterContext/', $h)) $fw['remix'] = true;
            if (preg_match('/<astro-island|data-astro-cid-|\/_astro\//', $h)) $fw['astro'] = true;
            if (preg_match('/\sng-version=["\']|_nghost-[a-z0-9]|_ngcontent-[a-z0-9]|<app-root[\s>]/i', $h)) $fw['angular'] = true;
            if (preg_match('/__sveltekit|class=["\'][^"\']*\bsvelte-[a-z0-9]{5,}|\/_app\/immutable\//', $h)) $fw['svelte'] = true;
            if (preg_match('/\sdata-v-[0-9a-f]{8}\b|data-server-rendered=["\']true|__vue_app__|\/vue(\.runtime)?(\.global)?(\.prod)?(\.min)?\.js/', $h) || isset($fw['nuxt'])) $fw['vue'] = true;
            if (isset($fw['next']) || isset($fw['gatsby']) || isset($fw['remix'])
                || preg_match('/data-reactroot|_reactRootContainer|react-dom(\.production)?(\.min)?\.js|\/react@\d|data-react-helmet/', $h)) $fw['react'] = true;
            $order = ['next', 'nuxt', 'gatsby', 'remix', 'astro', 'react', 'vue', 'angular', 'svelte'];
            $this->c['fw'] = array_values(array_filter($order, function ($k) use ($fw) { return isset($fw[$k]); }));
        }
        return $this->c['fw'];
    }

    /** Чи є порожній корінь SPA (div#root/#app/#__next/#__nuxt/... без змістовного тексту). */
    public function emptyAppRoot(): bool
    {
        if (!isset($this->c['empty_root'])) {
            $res = false;
            foreach ($this->query('//body//*[@id="root" or @id="app" or @id="__next" or @id="__nuxt" or @id="___gatsby" or @id="svelte" or @id="application" or @id="react-root" or @id="main-app"]') as $el) {
                if ($this->wordCount($this->collectText($el, false)) < 15) { $res = true; break; }
            }
            if ($res && $this->wordCount() >= 150) $res = false; // текст є поза коренем - це не порожній SPA
            $this->c['empty_root'] = $res;
        }
        return $this->c['empty_root'];
    }

    // ---------------------------------------------------------------- bot protection

    /** Тип сторінки-перевірки (WAF/капча) або null для звичайної сторінки. */
    public function challenge(): ?string
    {
        if (!array_key_exists('challenge', $this->c)) {
            $this->c['challenge'] = self::challengeFromHtml($this->html, $this->title(), $this->wordCount());
        }
        return $this->c['challenge'];
    }

    /**
     * Детектор сторінок захисту від ботів по сирому HTML (працює й на обрізаному тілі).
     * Щоб не було хибних спрацювань на звичайних сайтах за Cloudflare, вимагає малого тіла або збігу title.
     */
    public static function challengeFromHtml(string $html, ?string $title = null, ?int $words = null): ?string
    {
        if ($html === '') return null;
        if ($title === null) {
            $title = preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        }
        $small = strlen($html) < 40000;
        if ($words === null) {
            // mb_strcut не розрізає багатобайтовий символ: інакше preg_replace з /u повертає null на «битому» UTF-8
            $txt = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', strlen($html) > 200000 ? mb_strcut($html, 0, 200000, 'UTF-8') : $html);
            $txt = html_entity_decode(strip_tags((string)$txt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $txt = trim((string)(preg_replace('/\s+/u', ' ', $txt) ?? preg_replace('/\s+/', ' ', $txt)));
            $words = $txt === '' ? 0 : (int)preg_match_all('/\S*[\p{L}\p{N}]\S*/u', $txt);
        }
        $tiny = $words < 120 && $small;
        $t = mb_strtolower($title);

        // Cloudflare
        if (preg_match('/^(just a moment|один момент|attention required|please wait|verifying you are human|checking your browser)/u', $t)
            && preg_match('/cloudflare|cf-chl|challenge-platform|cf_chl|turnstile|cf-ray|__cf/i', $html)) return 'cloudflare';
        if ($tiny && preg_match('/cf-chl-|__cf_chl_|cf_chl_opt|window\._cf_chl|cf-browser-verification|challenges\.cloudflare\.com\/turnstile|id=["\']challenge-(form|running|stage)/i', $html)) return 'cloudflare';
        if ($tiny && preg_match('/cf-turnstile/i', $html) && $words < 60) return 'cloudflare';
        if ($tiny && stripos($html, 'cloudflare') !== false && preg_match('/(sorry, you have been blocked|you are unable to access|error code 1020|ray id)/i', $html)) return 'cloudflare';
        // DDoS-Guard
        if ((strpos($t, 'ddos-guard') !== false || strpos($t, 'ddos guard') !== false)
            || ($tiny && preg_match('/ddos-guard|ddos_guard|check\.ddos-guard\.net|__ddg[0-9_]/i', $html))) return 'ddos-guard';
        // Imperva / Incapsula
        if ($small && preg_match('/_Incapsula_Resource|Incapsula incident ID|incap_ses_|Request unsuccessful\. Incapsula/i', $html)) return 'imperva';
        // Sucuri
        if ($small && preg_match('/Sucuri WebSite Firewall|sucuri\.net\/privacy-policy|cloudproxy@sucuri/i', $html)) return 'sucuri';
        // Akamai
        if ($small && (preg_match('/errors\.edgesuite\.net|Reference&#32;&#35;|You don\'t have permission to access "?http/i', $html)
                || (strpos($t, 'access denied') !== false && stripos($html, 'akamai') !== false))) return 'akamai';
        // DataDome
        if ($small && preg_match('/captcha-delivery\.com|geo\.captcha-delivery|datadome/i', $html) && $words < 200) return 'datadome';
        // AWS WAF (challenge/captcha action): відповідь 202 з JS-перевіркою замість сторінки
        if ($small && preg_match('/awsWafCookieDomainList|window\.gokuProps|aws-waf-token|challenge\.compact\.js|token\.awswaf\.com|captcha\.awswaf\.com/i', $html)) return 'aws-waf';
        // Vercel / інші чекпоінти
        if ($tiny && preg_match('/Vercel Security Checkpoint|vercel-challenge/i', $html)) return 'vercel';
        // Загальна капча
        // (звичайна форма з reCAPTCHA на маленькій сторінці - не стіна: потрібна фраза-перевірка або captcha у title)
        if ($tiny && preg_match('/g-recaptcha|h-captcha|hcaptcha\.com|recaptcha\/api|captcha/i', $html)
            && (preg_match('/captcha|robot|робот|перевірка|проверка|security check/iu', $t)
                || ($words < 60 && preg_match('/(not a robot|are you a robot|verify you are human|human verification|підтвердіть, що ви не робот|подтвердите, что вы не робот|перевірка безпеки|проверка безопасности)/iu', $html)))) return 'captcha';
        // Access denied
        if ($tiny && preg_match('/^(access denied|403 forbidden|forbidden|доступ заборонено|доступ запрещ[её]н|request blocked|blocked)/u', $t)) return 'access_denied';
        return null;
    }

    // ---------------------------------------------------------------- internals: text walking

    private function walkBody(): void
    {
        if (isset($this->c['visible'])) return;
        $blocks = [];
        $body = $this->query('//body');
        $root = $body ? $body[0] : $this->dom->documentElement;
        if (!$root) {
            $this->c['visible'] = '';
            $this->c['blocks'] = [];
            return;
        }
        [$full] = $this->blockWalk($root, false, $blocks);
        $this->c['visible'] = self::norm($full);
        $this->c['blocks'] = array_values(array_filter($blocks));
    }

    /** @return array{0:string,1:string,2:bool} [повний текст, прямий текст, чи є блочні нащадки] */
    private function blockWalk(DOMNode $node, bool $inNav, array &$out): array
    {
        $tag = $node instanceof DOMElement ? strtolower($node->nodeName) : '';
        $isCand = isset(self::BLOCK_CAND[$tag]) || !empty(self::CONTAINER_CAND[$tag]);
        $slot = null;
        if ($isCand) { $slot = count($out); $out[] = null; }

        $full = []; $direct = []; $hasBlock = false;
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMText) {
                if ($c instanceof DOMCdataSection) continue;
                $full[] = $c->nodeValue; $direct[] = $c->nodeValue;
                continue;
            }
            if (!($c instanceof DOMElement)) continue;
            $ct = strtolower($c->nodeName);
            if (isset(self::SKIP_TAGS[$ct]) || self::isHidden($c)) continue;
            if ($ct === 'br' || $ct === 'hr') { $full[] = ' '; $direct[] = ' '; continue; }
            if ($ct === 'img') continue;
            $childNav = $inNav || isset(self::NAV_TAGS[$ct]) || isset(self::NAV_ROLES[strtolower($c->getAttribute('role'))]);
            [$cf, , $ch] = $this->blockWalk($c, $childNav, $out);
            $sep = isset(self::INLINE_NOSPACE[$ct]) ? '' : ' ';
            $piece = $sep . $cf . $sep;
            $full[] = $piece;
            $blockish = isset(self::BLOCKISH[$ct]);
            if ($blockish || $ch) $hasBlock = true;
            else $direct[] = $piece;
        }
        $fullS = implode('', $full);
        $directS = implode('', $direct);
        if ($isCand) {
            $text = self::norm($hasBlock ? $directS : $fullS);
            if ($text !== '') {
                $w = $this->wordCount($text);
                if ($w > 0) $out[$slot] = ['tag' => $tag, 'text' => $text, 'words' => $w, 'in_nav' => $inNav];
            }
        }
        return [$fullS, $directS, $hasBlock];
    }

    /**
     * Текст піддерева. $dropChrome: пропускати nav/header/footer/aside (і відповідні role),
     * крім тих, що всередині <article>.
     */
    private function collectText(DOMNode $node, bool $dropChrome): string
    {
        $parts = [];
        $this->collectInto($node, $dropChrome, false, $parts);
        return self::norm(implode('', $parts));
    }

    private function collectInto(DOMNode $node, bool $dropChrome, bool $inArticle, array &$parts): void
    {
        foreach ($node->childNodes as $c) {
            if ($c instanceof DOMText) {
                if (!($c instanceof DOMCdataSection)) $parts[] = $c->nodeValue;
                continue;
            }
            if (!($c instanceof DOMElement)) continue;
            $ct = strtolower($c->nodeName);
            if (isset(self::SKIP_TAGS[$ct]) || self::isHidden($c)) continue;
            if ($ct === 'br' || $ct === 'hr') { $parts[] = ' '; continue; }
            if ($dropChrome && !$inArticle) {
                $role = strtolower($c->getAttribute('role'));
                if (isset(self::NAV_TAGS[$ct]) || $ct === 'aside' || isset(self::NAV_ROLES[$role]) || $role === 'complementary') continue;
            }
            $sep = isset(self::INLINE_NOSPACE[$ct]) ? '' : ' ';
            $parts[] = $sep;
            $this->collectInto($c, $dropChrome, $inArticle || $ct === 'article', $parts);
            $parts[] = $sep;
        }
    }

    private function elementText(DOMElement $el): string
    {
        return $this->collectText($el, false);
    }

    private static function isHidden(DOMElement $el): bool
    {
        if ($el->hasAttribute('hidden')) return true;
        $st = $el->getAttribute('style');
        if ($st !== '' && preg_match('/(display\s*:\s*none|visibility\s*:\s*hidden)/i', $st)) return true;
        return false;
    }

    private function inSkipped(DOMNode $n): bool
    {
        for ($p = $n->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if (isset(self::SKIP_TAGS[strtolower($p->nodeName)]) && strtolower($p->nodeName) !== 'head') return true;
        }
        return false;
    }

    private function inNav(DOMNode $n): bool
    {
        for ($p = $n->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            $t = strtolower($p->nodeName);
            if (isset(self::NAV_TAGS[$t])) return true;
            if ($p->hasAttribute('role') && isset(self::NAV_ROLES[strtolower($p->getAttribute('role'))])) return true;
            if ($t === 'body') break;
        }
        return false;
    }

    // ---------------------------------------------------------------- string helpers

    /** Нормалізація пробілів (включно з nbsp і тонкими пробілами) + прибирання пробілу перед розділовими знаками. */
    public static function norm(string $s): string
    {
        if ($s === '') return '';
        $orig = $s;
        $s = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $s);
        if ($s === null) { // невалідний UTF-8 - чистимо і пробуємо ще раз
            $clean = mb_scrub($orig, 'UTF-8');
            return $clean === $orig ? trim(preg_replace('/\s+/', ' ', $orig)) : self::norm($clean);
        }
        $s = preg_replace('/ ([,.;:!?)\]»%])/u', '$1', $s);
        $s = preg_replace('/([(\[«]) /u', '$1', (string)$s);
        return trim((string)$s);
    }

    private static function decodeLeftovers(string $s): string
    {
        if (strpos($s, '&') !== false && preg_match('/&(#\d+|#x[0-9a-f]+|[a-z]{2,8});/i', $s)) {
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $s;
    }

    private static function mbCut(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n)) . ' ...' : $s;
    }

    private static function isList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}
