<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseCheck.php';
require_once __DIR__ . '/../SiteProfile.php';

/**
 * E-E-A-T сигнали: сторінка «Про нас» або «Про мене», контакти, юридичні сторінки,
 * реквізити, авторство й дати статей, відгуки та репутація, конкретні маркери довіри.
 * Для YMYL-ніш (медицина / фінанси / право) вимоги суворіші.
 *
 * Джерела: видимий текст і посилання головної + сторінки вибірки (about / contact / article / ...).
 * Додаткові запити (лише коли без них висновок був би неточним): сторінка «Про нас», якщо її немає у вибірці;
 * перебір типових адрес «Про нас», якщо посилання не знайдено; «Контакти», якщо їх немає у вибірці й бракує даних;
 * оферта / політика - пошук ЄДРПОУ, якщо його немає на головній, «Контактах» і «Про нас».
 */
class EeatCheck extends BaseCheck
{
    public function id(): string { return 'eeat'; }
    public function name(): string { return 'E-E-A-T сигнали'; }
    public function weight(): float { return 0.14; }

    const LABELS = [
        'about'         => 'Сторінка «Про нас»',
        'contacts'      => 'Контакти й адреса',
        'legal'         => 'Юридичні сторінки',
        'identity'      => 'Реквізити (ЄДРПОУ / ФОП / ТОВ)',
        'authorship'    => 'Автори й дати статей',
        'reviews'       => 'Відгуки та репутація',
        'trust_markers' => 'Конкретні маркери довіри',
    ];
    /** Підписи для особистого сайту експерта. */
    const LABELS_PERSONAL = [
        'about'         => 'Сторінка «Про мене»',
        'contacts'      => 'Канали зв\'язку',
        'legal'         => 'Політика конфіденційності (якщо є форма)',
        'identity'      => 'Реквізити (необов\'язково)',
        'authorship'    => 'Автори й дати статей',
        'reviews'       => 'Відгуки (враховано в маркерах довіри)',
        'trust_markers' => 'Підтвердження експертизи: кейси, публікації, виступи, відгуки, профілі',
    ];

    /** Ваги підоцінок за типом сайту (перенормовуються, null не враховується). */
    const WEIGHTS = [
        'personal'  => ['about' => 24, 'contacts' => 16, 'legal' => 6, 'identity' => 4, 'authorship' => 16, 'reviews' => 4, 'trust_markers' => 30],
        'services'  => ['about' => 18, 'contacts' => 18, 'legal' => 10, 'identity' => 8, 'authorship' => 14, 'reviews' => 14, 'trust_markers' => 18],
        'corporate' => ['about' => 20, 'contacts' => 20, 'legal' => 12, 'identity' => 8, 'authorship' => 12, 'reviews' => 10, 'trust_markers' => 18],
        'ecommerce' => ['about' => 14, 'contacts' => 18, 'legal' => 18, 'identity' => 10, 'authorship' => 8, 'reviews' => 18, 'trust_markers' => 14],
        'local'     => ['about' => 14, 'contacts' => 26, 'legal' => 8, 'identity' => 6, 'authorship' => 8, 'reviews' => 20, 'trust_markers' => 18],
        'media'     => ['about' => 18, 'contacts' => 12, 'legal' => 12, 'identity' => 5, 'authorship' => 35, 'reviews' => 3, 'trust_markers' => 15],
        'saas'      => ['about' => 18, 'contacts' => 16, 'legal' => 16, 'identity' => 6, 'authorship' => 12, 'reviews' => 14, 'trust_markers' => 18],
    ];

    const CONTACT_W = [
        'local'     => ['phone' => 35, 'address' => 35, 'email' => 10, 'page' => 20],
        'ecommerce' => ['phone' => 30, 'address' => 20, 'email' => 25, 'page' => 25],
        'services'  => ['phone' => 30, 'address' => 25, 'email' => 25, 'page' => 20],
        'corporate' => ['phone' => 30, 'address' => 25, 'email' => 25, 'page' => 20],
        'media'     => ['phone' => 15, 'address' => 15, 'email' => 35, 'page' => 35],
        'saas'      => ['phone' => 20, 'address' => 15, 'email' => 35, 'page' => 30],
    ];

    const TRUST_W = [
        'ecommerce' => ['years' => 20, 'credentials' => 15, 'partners' => 25, 'guarantees' => 25, 'cases' => 0, 'numbers' => 15],
        'local'     => ['years' => 25, 'credentials' => 30, 'partners' => 10, 'guarantees' => 10, 'cases' => 5, 'numbers' => 20],
        'services'  => ['years' => 20, 'credentials' => 20, 'partners' => 20, 'guarantees' => 5, 'cases' => 20, 'numbers' => 15],
        'corporate' => ['years' => 25, 'credentials' => 20, 'partners' => 15, 'guarantees' => 5, 'cases' => 15, 'numbers' => 20],
        'saas'      => ['years' => 15, 'credentials' => 25, 'partners' => 15, 'guarantees' => 5, 'cases' => 20, 'numbers' => 20],
        'media'     => ['years' => 30, 'credentials' => 30, 'partners' => 0, 'guarantees' => 0, 'cases' => 0, 'numbers' => 40],
        'personal'  => ['years' => 15, 'credentials' => 10, 'partners' => 0, 'guarantees' => 0, 'cases' => 20, 'numbers' => 0,
            'publications' => 15, 'talks' => 10, 'reviews' => 15, 'profiles' => 15],
    ];
    const TRUST_LABELS = [
        'years'        => 'роки на ринку',
        'credentials'  => 'ліцензії / сертифікати / нагороди',
        'partners'     => 'партнерські статуси',
        'guarantees'   => 'гарантії',
        'cases'        => 'кейси / клієнти',
        'numbers'      => 'цифри досягнень',
        // лише для особистого сайту експерта
        'publications' => 'публікації / експертні коментарі',
        'talks'        => 'виступи / конференції',
        'reviews'      => 'відгуки клієнтів',
        'profiles'     => 'профілі на зовнішніх майданчиках',
    ];
    /** Особистий сайт: «роки на ринку» - це досвід особи. */
    const TRUST_LABELS_PERSONAL = ['years' => 'роки досвіду', 'credentials' => 'сертифікати / освіта / нагороди'];
    /** Профілі експерта поза сайтом (для маркерів довіри особистого сайту). */
    const RX_PROFILE_HOSTS = '#^(?:linkedin\.com/in/|github\.com/[^/]+/?$|youtube\.com/(?:channel/|c/|user/|@)|t\.me/|instagram\.com/|facebook\.com/|x\.com/|twitter\.com/|tiktok\.com/@|orcid\.org/|scholar\.google\.[a-z.]+/citations|researchgate\.net/profile/|medium\.com/@|dou\.ua/users/|behance\.net/|dribbble\.com/|wikidata\.org/wiki/Q|collaborator\.pro/(?:ua/)?author/|threads\.net/@)#i';
    const PROFILE_NAMES = ['linkedin.com' => 'LinkedIn', 'github.com' => 'GitHub', 'youtube.com' => 'YouTube', 't.me' => 'Telegram', 'instagram.com' => 'Instagram',
        'facebook.com' => 'Facebook', 'x.com' => 'X', 'twitter.com' => 'X', 'tiktok.com' => 'TikTok', 'orcid.org' => 'ORCID', 'researchgate.net' => 'ResearchGate',
        'medium.com' => 'Medium', 'dou.ua' => 'DOU', 'behance.net' => 'Behance', 'dribbble.com' => 'Dribbble', 'wikidata.org' => 'Wikidata',
        'collaborator.pro' => 'Collaborator', 'threads.net' => 'Threads'];

    // ------------------------------------------------------------------ патерни: сторінки
    const RX_ABOUT_ANCHOR = '/^(про нас|про компанію|про компанию|о нас|о компании|about|about us|about company|company|компанія|компания|хто ми|who we are|наша компанія|наша історія|історія компанії|our story|про бренд|про магазин|про клініку|про клініки|о клинике|про агентство|про агенцію|про агенство|про проєкт|про проект|о проекте|about project|про редакцію|редакція|про видання|про броварню|про пивоварню|про готель|про курорт|про комплекс|про банк|о банке|про фонд|про школу|про університет|про академію|про коледж|про центр|про студію|про групу|про холдинг|про завод|про фірму|про організацію|про сервіс|про платформу|про ресторан|про заклад)$/iu';
    const ABOUT_SEGS_EXTRA = ['pro-proekt', 'pro-proiekt', 'pro-projekt', 'about-project', 'redaktsiia', 'redaktsiya', 'redakciya',
        'redakcija', 'editorial', 'pro-redaktsiiu', 'pro-agentstvo', 'pro-ahentstvo', 'about-agency', 'our-story', 'pro-kurort', 'pro-hotel'];
    const RX_ABOUT_TITLE = '/(про нас|про компанію|о нас|о компании|about|компанія|компания|хто ми|наша історія|історія|who we are|our story|про клініку|про магазин|про агентство|про агенцію|про проєкт|про проект|редакція)/iu';
    const RX_CONTACT_ANCHOR = '/^(контакти|контакты|contacts?|contact us|наші контакти|зв[\'’ʼ]язатися з нами|зв[\'’ʼ]язатись з нами|зв[\'’ʼ]язок з нами|зв[\'’ʼ]язатися|зворотній зв[\'’ʼ]язок|зворотний зв[\'’ʼ]язок|адреси магазинів|де нас знайти|як нас знайти|get in touch|контакти редакції)$/iu';
    const RX_TEAM_ANCHOR = '/^(наша команда|команда|команда проєкту|команда проекту|наші лікарі|лікарі|наші фахівці|фахівці|спеціалісти|наші спеціалісти|експерти|наші експерти|редакція|автори|наші автори|правління|керівництво|керівництво компанії|менеджмент|топ-менеджмент|наглядова рада|засновники|team|our team|meet the team|people|doctors|our doctors|experts|authors|management|leadership|board of directors)$/iu';
    const RX_TEAM_PATH = '#/(team|our-team|komanda|nasha-komanda|doctors|vrachi|likari|specialists|spetsialisty|fakhivtsi|fahivtsi|experts|eksperty|authors|avtory|redaktsiia|people|management|leadership|kerivnytstvo|pravlinnia)/?$#i';
    const FACET_LABELS_PERSONAL = ['year' => 'досвід (скільки років у професії)', 'team' => 'ім\'я і спеціалізація', 'geo' => 'місто / географія роботи',
        'achievements' => 'досягнення (кейси, публікації, виступи, сертифікати)'];
    /** «Про мене» особистого сайту (див. SiteProfile::RX_ANCHOR_ABOUT_ME). */
    const RX_ABOUT_ME_TITLE = '/(про мене|обо мне|about me|біографі|биографи|biography|про автора|об авторе|моя історія|хто я)/iu';
    const RX_TRUSTPAGE_ANCHOR ='/^(сертифікати|наші сертифікати|сертифікати якості|сертифікати та нагороди|сертифікати і нагороди|сертификаты|certificates|ліцензії|ліцензія|ліцензії та сертифікати|сертифікати та ліцензії|сертифікати і ліцензії|ліцензії і сертифікати|нагороди|наші нагороди|нагороди та відзнаки|licenses|licences|awards|certificates and licenses)$/iu';
    /** Сторінки футера, які аналізуються додатково (довірчі - першими). */
    const FOOTER_MAX = 20;
    const RX_FOOTER_TRUST = '/(сертиф|ліценз|лиценз|нагород|відзнак|гарант|оферт|умов|правил|політик|политик|конфіден|конфиден|персональн|доставк|оплат|повернен|возврат|обмін|обмен|відгук|отзыв|проєкт|проект|кейс|портфол|партнер|клієнт|клиент|антикоруп|комплаєнс|реквізит|реквизит|команд|історі|истори|виробництв|производств|якіст|качеств|certif|licen|award|warrant|guarantee|terms|privacy|policy|delivery|return|review|case|portfolio|partner|compliance|quality|sertyf|harant|garant|oferta|polityk|konfiden|dostavk|vidhuk|otzyv|proekt|keys|antykoru|komplaien)/iu';
    const RX_CASES_PATH = '#/(cases|case-studies|keysy|keisy|kejsy|portfolio|portfolio-[a-z]+|clients|nashi-kliienty|nashi-klienty|projects|proekty|proiekty)/?$#i';

    /** Юридичні сторінки: анкор (lowercase) і шлях URL. */
    const LEGAL = [
        'privacy' => [
            'anchor' => '/(конфіденційн|privacy|персональн\w* дан|конфиденциальн|обробк\w* (та захист\w* )?персональн|захист\w* персональн)/u',
            'path'   => '#(privacy|konfiden|confiden|personal-?data|personalni-dani|personalnykh-danykh|personalnyh-dannyh|persdan|gdpr|polityka-konf|politika-konf|zakhyst-personal|obrobka-personal)#i',
        ],
        'terms' => [
            'anchor' => '/(оферт|умов\w* (використання|користування|надання|обслуговування|продажу|співпраці)|угод\w* користувача|політик\w* користування|правила (користування|сайту|магазину|відпочинку|сервісу|надання|проживання)|публічн\w* договір|договір (публічної )?оферти|умови, правила|terms|user agreement|пользовательск\w* соглашени|условия (использования|пользования))/u',
            'path'   => '#(oferta|oferty|public-?offer|offer-agreement|terms|umovy|ugoda|uhoda|user-?agreement|polzovatelskoe|rules|pravyla|dogovir)#i',
        ],
        'delivery' => [
            'anchor' => '/(доставк|оплат[аиі](?! частин)|shipping|delivery)/u',
            'path'   => '#(dostav|oplat|delivery|shipping)#i',
        ],
        'returns' => [
            'anchor' => '/(повернен|обмін|returns?\b|refund|возврат|обмен)/u',
            'path'   => '#(povernen|povern|obmin|vozvrat|obmen|returns?(/|$|-)|refund)#i',
        ],
        'warranty' => [
            'anchor' => '/(гаранті|гаранти|warranty)/u',
            'path'   => '#(garant|harant|warrant|guarantee|guaran)#i',
        ],
        'editorial' => [
            'anchor' => '/(редакційн\w* (?:політик|кодекс|стандарт|принцип|правила|статут)|редакционн\w* (?:политик|кодекс|стандарт)|editorial (?:policy|policies|standards|code|guidelines|principles|charter)|(?:стандарти|принципи|правила|кодекс|статут) (?:редакції|журналістики|видання|журналіста)|кодекс (?:етики|професійної етики)|етичн\w* кодекс|політики (?:і|та) стандарти|структура власності|власник\w* (?:видання|медіа|сайту)|ownership|ethics policy|code of ethics)/u',
            'path'   => '#(redak\w*-(?:polit|kodeks|standart)|editorial-(?:polic|standard|code|guideline|principle)|ownership|struktura-vlasnost|(?:^|/)(?:code|standards|kodeks|ethics|etyka|editorial)(?:\.html?|/|$))#i',
        ],
    ];
    /** Сторонні сервіси, чиї політики / умови часто є на сайтах, але не належать власнику сайту. */
    const RX_THIRD_PARTY = '/(^|\.)(google\.[a-z.]+|gstatic\.com|youtube\.com|facebook\.com|instagram\.com|twitter\.com|x\.com|linkedin\.com|tiktok\.com|apple\.com|microsoft\.com|cloudflare\.com|hcaptcha\.com|recaptcha\.net|liqpay\.ua|wayforpay\.com|fondy\.(ua|eu|io)|portmone\.com\.ua|privatbank\.ua|monobank\.ua|novaposhta\.ua|ukrposhta\.ua|meest\.(com|us)|shopify\.com|wix\.com|tilda\.(cc|ws)|prom\.ua|rozetka\.com\.ua|hotline\.ua|olx\.ua|telegram\.org|t\.me|viber\.com|whatsapp\.com|amazon\.[a-z.]+|paypal\.com|stripe\.com|binotel\.(ua|com)|jivo\.(ru|chat)|ringostat\.com|hubspot\.com|mailchimp\.com)$/i';

    const LEGAL_LABELS = [
        'privacy'   => 'політика конфіденційності',
        'terms'     => 'умови використання / публічна оферта',
        'delivery'  => 'доставка й оплата',
        'returns'   => 'обмін і повернення',
        'warranty'  => 'гарантія',
        'editorial' => 'редакційна політика / структура власності',
    ];
    /** Знахідний відмінок (після «посилань на ...»). */
    const LEGAL_ACC = [
        'privacy'   => 'політику конфіденційності',
        'terms'     => 'умови використання / публічну оферту',
        'delivery'  => 'сторінку доставки й оплати',
        'returns'   => 'сторінку обміну й повернення',
        'warranty'  => 'сторінку гарантії',
        'editorial' => 'редакційну політику / структуру власності',
    ];

    /** Міста України: форми відмінків => називний (для географії й addressLocality). */
    const CITY_FORMS = [
        'Ки[їє]в(?:а|і|ом|у)?' => 'Київ', 'Льв[іо]в(?:а|і|ом|у)?' => 'Львів', 'Одес(?:а|и|і|у|ою)' => 'Одеса',
        'Харк[іо]в(?:а|і|ом|у)?' => 'Харків', 'Дніпр(?:о|а|і|ом|у)' => 'Дніпро', 'Запоріжж(?:я|і|ям)' => 'Запоріжжя',
        'Вінниц(?:я|і|ю|ею)' => 'Вінниця', 'Полтав(?:а|и|і|у|ою)' => 'Полтава', 'Черніг[іо]в(?:а|і|ом|у)?' => 'Чернігів',
        'Чернів(?:ці|ців|цях)' => 'Чернівці', 'Івано-Франківськ(?:а|у|ом)?' => 'Івано-Франківськ', 'Ужгород(?:а|і|ом|у)?' => 'Ужгород',
        'Луцьк(?:а|у|ом)?' => 'Луцьк', 'Рівн(?:е|ого|ому|им)' => 'Рівне', 'Терноп(?:іль|оля|олі|олем)' => 'Тернопіль',
        'Житомир(?:а|і|ом|у)?' => 'Житомир', 'Херсон(?:а|і|ом|у)?' => 'Херсон', 'Микола(?:їв|єва|єві|євом)' => 'Миколаїв',
        'Черкас(?:и|ах|ам)' => 'Черкаси', 'Кропивницьк(?:ий|ого|ому)' => 'Кропивницький', 'Ірп(?:інь|еня|ені)' => 'Ірпінь',
        'Бровар(?:и|ах)' => 'Бровари', 'Біл(?:а|ої|ій) Церкв(?:а|и|і)' => 'Біла Церква', 'Кременчу(?:к|ка|ці)' => 'Кременчук',
        'Мукачев(?:о|а|і)' => 'Мукачево', 'Буковел(?:ь|я|і)' => 'Буковель', 'Трускав(?:ець|ця|ці)' => 'Трускавець',
        'Kyiv' => 'Київ', 'Kiev' => 'Київ', 'Lviv' => 'Львів', 'Odesa' => 'Одеса', 'Odessa' => 'Одеса', 'Kharkiv' => 'Харків', 'Dnipro' => 'Дніпро',
    ];

    // ------------------------------------------------------------------ патерни: контакти
    const RX_PHONES = [
        '/(?<![\d+])\+?\s?38\s?[\s(.-]*0\d{2}[\s).-]*(?:\d[\s.-]?){6}\d(?!\d)/u',
        '/(?<!\d)\(0\d{2,4}\)\s?(?:\d[\s.-]?){4,6}\d(?!\d)/u',
        // без коду країни - лише з роздільниками 0XX XXX XX XX (суцільні 10 цифр часто є артикулами, напр. Bosch 0451103290)
        '/(?<![\d.,:\/])0[3-9]\d[\s-]\d{3}[\s-]\d{2}[\s-]\d{2}(?![\d-])/u',
        '/(?<![\d.,])0[\s-]800[\s-]\d{3}[\s-]?\d{3}(?!\d)|(?<![\d.,])0800[\s-]\d{3}[\s-]?\d{3}(?!\d)/u',
        '/(?<![\w+])\+(?!38)[1-9]\d{0,2}[\s.-]?\(?\d{1,4}\)?(?:[\s.-]?\d){6,10}(?!\d)/u',
    ];
    const RX_EMAIL = '/(?<![\w.%+-])([a-z0-9][a-z0-9._%+-]{0,63}@(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+([a-z]{2,24}))(?![\w-])/iu';
    const BAD_EMAIL_TLD = '/^(png|jpe?g|gif|webp|svg|avif|ico|bmp|tiff?|css|js|json|pdf|zip|mp4|webm|mp3|woff2?|ttf|eot|php|html?|xml|txt)$/i';
    const BAD_EMAIL_DOMAIN = '/(^|\.)(example\.(com|org|net)|domain\.com|yourdomain\.[a-z]+|mysite\.[a-z]+|site\.com|email\.com|sentry\.io|wixpress\.com|sentry-next\.wixpress\.com)$/i';
    const RX_STREET = '/(?<![\p{L}])(?:(?i:вул\.|вулиц[яіею]|ул\.|улица)\s?[\p{Lu}\d«"]|(?i:просп\.|проспект|пр-т|пров\.|провулок|бульв\.|бульвар|б-р|пл\.|площа|шосе|наб\.|набережна|узвіз|пер\.|переулок)\s?[\p{Lu}«"])/u';
    const RX_STREET_EN = '/(?<![\w])\d{1,5}[A-Za-z]?,?\s+(?:[A-Z][A-Za-z\'-]+\s+){1,3}(?:Street|St\.|Avenue|Ave\.?|Road|Rd\.?|Boulevard|Blvd\.?|Lane|Drive|Square|Place|Way)(?![\w])/u';
    const RX_CITY = '/(?<![\p{L}])(?:м\.|місто|г\.)\s?(\p{Lu}[\p{Ll}\'’ʼ-]{2,})/u';

    // ------------------------------------------------------------------ патерни: реквізити
    const RX_ID_CODE = [
        '/(?:ЄДРПОУ|ЕДРПОУ|EDRPOU|USREOU|(?i:ідентифікаційний код)(?: юридичної особи)?)\D{0,30}?(\d{8})(?!\d)/u',
        '/(?:ІПН|ИНН|РНОКПП|ІНН)\D{0,20}?(\d{10}|\d{12})(?!\d)/u',
        '/(?i:ідентифікатор медіа)\s*[-–:]?\s*(R\d{2}-\d{3,6})/u',
    ];
    const RX_ID_NAME = [
        '/(?<![\p{L}])(?:ФОП|(?i:фізична особа[- ]підприємець))\s+[\p{Lu}][\p{Ll}\'’ʼ-]+\s+(?:[\p{Lu}][\p{Ll}\'’ʼ-]+(?:\s+[\p{Lu}][\p{Ll}\'’ʼ-]+)?|[\p{Lu}]\.\s?[\p{Lu}]\.)/u',
        '/(?<![\p{L}])(?:ТОВ|ТзОВ|ПП|ПрАТ|ПАТ|АТ|ДП|ТДВ|КНП|КП|(?i:товариство з обмеженою відповідальністю|приватне підприємство|акціонерне товариство))\s*[«"„“]\s*[^»"”“\n]{2,60}[»"”“]/u',
    ];
    const RX_ID_INTL = '/(?<![\p{L}])[\p{Lu}][\p{L}\d&.\'-]*(?:\s+[\p{Lu}\d][\p{L}\d&.\'-]*){0,3},?\s+(?:LLC|L\.L\.C\.|Inc\.|Ltd\.?|Limited|GmbH|Corp\.|s\.r\.o\.|sp\.\s?z\s?o\.o\.|B\.V\.|LLP|OÜ)(?![\p{L}])/u';

    // ------------------------------------------------------------------ патерни: авторство
    const RX_AUTHOR_PATH = '~/(?:author|authors|avtor|avtory|avtori|autor|autors|blogger|bloggers|bloher|expert|experts|eksperty|ekspert|team|komanda|people|person|doctor|doctors|likar|likari|vrach|vrachi|specialist|specialists|fakhivets|fakhivtsi|redaktsiia|editor|editors|profile)/[^/?#]+~i';
    const RX_BYLINE_TEXT = '/(?:(?:[Аа]втор(?:ка)?(?: статті| матеріалу| тексту)?|Author|Written by|Posted by|[Тт]екст)\s*[:\-–]\s*|(?:^|\s)By\s+)(\p{Lu}[\p{Ll}\'’ʼ-]+(?:\s+\p{Lu}[\p{Ll}\'’ʼ.-]+){1,2})/u';
    const RX_GENERIC_AUTHOR = '/^(?:(?:admin|administrator|адмін\w*|адміністратор\w*|администратор\w*|webmaster|вебмайстер|root|moderator|модератор\w*)(?![\p{L}])|(?:user|editor|manager|менеджер|редактор|редакция|support|content manager|контент-менеджер)$)/iu';
    const RX_CRED_EXPERT = '/(?<![\p{L}])(?:лікар\w*|к\.\s?м\.\s?н\.?|д\.\s?м\.\s?н\.?|к\.\s?е\.\s?н\.?|к\.\s?ю\.\s?н\.?|кандидат\w*\s+\w+\s+наук|доктор\w*\s+\w+\s+наук|PhD|Ph\.D|MD|MBA|CFA|ACCA|сертифікован\w*|адвокат\w*|юрист\w*|нотаріус\w*|аудитор\w*|фінансов\w*\s+(?:аналітик|консультант|радник|експерт)\w*|стоматолог\w*|хірург\w*|терапевт\w*|педіатр\w*|кардіолог\w*|гінеколог\w*|невролог\w*|дерматолог\w*|уролог\w*|онколог\w*|ендокринолог\w*|офтальмолог\w*|психолог\w*|психотерапевт\w*|професор\w*|доцент\w*|експерт\w*|врач\w*|doctor|dr\.)(?![\p{L}])/iu';
    const RX_CRED_ROLE = '/(?<![\p{L}])(?:журналіст\w*|редактор\w*|оглядач\w*|кореспондент\w*|аналітик\w*|засновни\w*|співзасновни\w*|CEO|CTO|CMO|COO|head of|керівни\w*|директор\w*|маркетолог\w*|спеціаліст\w*|фахів\w*|консультант\w*|копірайтер\w*|journalist|editor|analyst|founder|specialist|consultant)(?![\p{L}])/iu';
    const RX_REVIEWER = '/(?:перевірено\s+(?:експертом|лікарем|фахівцем|юристом|спеціалістом)|перевірив(?:ла)?\s+(?:лікар|експерт|фахівець|юрист)|медичн\w*\s+(?:експерт|рецензент|редактор|консультант)|рецензент\w*|експертн\w*\s+перевірк\w*|науков\w*\s+редактор\w*|експерт\s+статті|консультант\s+статті|reviewed by|medically reviewed|fact[- ]checked|перевірк\w*\s+фактів)/iu';
    const RX_DATE_VIS = '/(?:(?<!\d)(?:0?[1-9]|[12]\d|3[01])\.(?:0?[1-9]|1[0-2])\.(?:19|20)\d{2}(?!\d)|(?<!\d)(?:0?[1-9]|[12]\d|3[01])\s+(?:січня|лютого|березня|квітня|травня|червня|липня|серпня|вересня|жовтня|листопада|грудня|января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)(?:\s+(?:19|20)\d{2})?|(?:January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\.?\s+\d{1,2},\s+(?:19|20)\d{2}|(?<!\d)(?:19|20)\d{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])(?!\d))/u';
    const RX_UPDATED_VIS = '/(?:[Оо]новлено|[Оо]новлена|[Дд]ата оновлення|[Оо]бновлено|[Uu]pdated|[Ll]ast updated|[Оо]стання редакція)/u';
    const RX_AUTHORITY = '#(?:\.gov\.ua|\.gov(?:/|$)|\.gov\.[a-z]{2}(?:/|$)|\.edu(?:/|$)|\.edu\.ua|\.ac\.uk|who\.int|nih\.gov|ncbi\.nlm|pubmed|cochrane|europa\.eu|uk\.wikipedia\.org|en\.wikipedia\.org|nature\.com|thelancet\.com|nejm\.org|bmj\.com|sciencedirect\.com|springer\.com|oecd\.org|imf\.org|worldbank\.org|ukrstat|cdc\.gov|mayoclinic\.org|nice\.org\.uk)#i';
    const RX_SOCIAL = '#(?:^|\.)(facebook\.com|instagram\.com|linkedin\.com|youtube\.com|youtu\.be|t\.me|telegram\.me|twitter\.com|x\.com|tiktok\.com|threads\.net|pinterest\.com|viber\.com|wa\.me|whatsapp\.com)$#i';

    // ------------------------------------------------------------------ патерни: відгуки
    const RX_REVIEWS_HEAD = '/(?<![\p{L}])(?:відгук\w*|отзыв\w*|reviews?|testimonials?|що (?:кажуть|говорять) (?:про нас|наші клієнти|клієнти|пацієнти)|клієнти про нас|пацієнти про нас|рекомендаці\w* клієнтів)(?![\p{L}])/iu';
    const RX_REVIEWS_ANCHOR = '/^(відгуки|відгуки клієнтів|відгуки пацієнтів|відгуки покупців|відгуки гостей|всі відгуки|клієнти та відгуки|клієнти і відгуки|reviews|testimonials|отзывы|дивитись всі відгуки)$/iu';
    const RX_REVIEWS_PATH = '#/(reviews?|vidguky|vidhuky|vidguki|vidhuki|otzyvy|otzivi|otzyvi|testimonials)/?$#i';
    const RX_REVIEW_COUNT = '/(?<![\d.,])(\d{1,3}(?:[\s\x{00A0}]?\d{3})*)\s+(?:відгук\w*|отзыв\w*|reviews?|оцін(?:ок|ки|ка))(?![\p{L}])/iu';
    const RX_RATING = '/(?<![\d.,])([1-5][.,]\d{1,2})\s?(?:\/|з|із|out of|of)\s?5(?![\d.,])/u';
    const REVIEW_PLATFORMS = [
        'Google Maps'             => '#(?:google\.[a-z.]+/maps|maps\.google\.|goo\.gl/maps|maps\.app\.goo\.gl|g\.page/|search\.google\.com/local|g\.co/kgs)#i',
        'Google Customer Reviews' => '#google\.[a-z.]+/shopping/customerreviews#i',
        'Hotline.ua'              => '#(?:^|\.)hotline\.ua/#i',
        'Clutch'                  => '#(?:^|\.)clutch\.co/#i',
        'Trustpilot'              => '#trustpilot\.com/#i',
        'Tripadvisor'             => '#tripadvisor\.#i',
        'Booking.com'             => '#booking\.com/hotel#i',
        'GoodFirms'               => '#goodfirms\.co/#i',
        'G2'                      => '#(?:^|\.)g2\.com/products#i',
        'Capterra'                => '#capterra\.#i',
        'DesignRush'              => '#designrush\.com/#i',
        'Doc.ua'                  => '#(?:^|\.)doc\.ua/#i',
        'Helsi'                   => '#helsi\.me/#i',
        'Facebook (відгуки)'      => '#facebook\.com/[^?]+/reviews#i',
        'Untappd'                 => '#(?:^|\.)untappd\.com/#i',
        'RateBeer'                => '#(?:^|\.)ratebeer\.com/#i',
        'Google (відгуки)'        => '~(?:google\.[a-z.]+/search\?[^#]*(?:ludocid|lrd)=|search\.google\.com/local/(?:writereview|reviews))~i',
        'Prom.ua'                 => '#(?:^|\.)prom\.ua/(?:ua/)?(?:c\d+[-\w]*\.html|[^?]*testimonials)#i',
        'Rozetka (продавець)'     => '#(?:^|\.)rozetka\.com\.ua/(?:ua/)?seller/#i',
        'Yelp'                    => '#(?:^|\.)yelp\.[a-z.]+/biz/#i',
        'App Store'               => '#(?:apps|itunes)\.apple\.com/#i',
        'Google Play'             => '#play\.google\.com/store/apps#i',
    ];

    // ------------------------------------------------------------------ стан одного запуску
    private string $url = '';
    private string $base = '';
    private string $host = '';
    private string $type = 'corporate';
    private string $brand = '';
    private ?string $ymyl = null;
    private string $ymylSignal = '';
    private bool $uaSite = true;
    private ?string $fetchMode = null;
    private ?Page $home = null;
    /** @var array<int, array{role:string,url:string,page:Page}> */
    private array $pages = [];
    /** @var array<string, array> role => sample */
    private array $samples = [];
    private array $findings = [];
    private array $passed = [];
    private array $goods = [];
    private array $gaps = [];
    private array $notes = [];
    private string $jsNote = '';
    private array $textCache = [];
    private array $extraFetched = [];
    private bool $teamLinkDone = false;
    private ?array $teamLinkCache = null;
    /** YMYL-вимоги до статті (false для новин / оголошень на YMYL-сайті). */
    private bool $ymylArt = false;
    /** Особистий сайт експерта: Person - головна сутність головної (SiteProfile::homePerson) або null. */
    private bool $personal = false;
    private ?array $person = null;
    /** Відгуки, знайдені на особистому сайті (враховуються в маркерах довіри). */
    private array $reviewHits = [];
    /** зібрані факти для коду: телефон, email, адреса (точна цитата), місто (називний), область / район, індекс, рік */
    private array $facts = self::FACTS0;
    const FACTS0 = ['phone' => null, 'email' => null, 'address' => null, 'city' => null, 'region' => null, 'district' => null, 'postal' => null, 'street' => null, 'year' => null];

    public function run(string $url, array $ctx): array
    {
        $this->reset();
        $page = $ctx['page'] ?? null;
        if (!($page instanceof Page)) {
            $reason = (string)($ctx['access']['block_reason'] ?? '');
            $status = (int)($ctx['access']['homepage_status'] ?? ($ctx['homepage']['status'] ?? 0));
            return $this->skipped('Головна сторінка не віддала HTML боту (HTTP ' . $status . ($reason !== '' ? ', ' . $reason : '')
                . ') - сигнали E-E-A-T (контакти, «Про нас», автори, відгуки) неможливо оцінити без вмісту сторінок.', [
                'homepage_status' => $status,
                'block_reason'    => $reason !== '' ? $reason : null,
            ]);
        }

        $this->home = $page;
        $this->url = $url;
        $site = (array)($ctx['site'] ?? []);
        $this->type = isset(self::WEIGHTS[(string)($site['type'] ?? '')]) ? (string)$site['type'] : 'corporate';
        $this->personal = $this->type === 'personal';
        $this->person = $this->personal ? SiteProfile::homePerson($page) : null;
        $this->host = Crawler::registrableHost($page->url() !== '' ? $page->url() : $url);
        $this->base = Crawler::baseUrl($page->url() !== '' ? $page->url() : $url);
        $brand = trim((string)($site['brand'] ?? ''));
        $this->brand = $brand !== '' ? $brand : SiteProfile::hostLabel($url);
        $this->fetchMode = (($ctx['access']['fetched_as'] ?? 'bot') === 'browser') ? 'browser' : null;
        $lang = strtolower((string)($site['lang'] ?? ''));
        $langs = array_map('strtolower', (array)($site['languages'] ?? []));
        $this->uaSite = (bool)preg_match('/\.ua$/', $this->host) || in_array($lang, ['uk', 'ru'], true) || in_array('uk', $langs, true);

        $this->pages = [['role' => 'home', 'url' => $page->url(), 'page' => $page]];
        foreach ((array)($ctx['samples'] ?? []) as $s) {
            if (!is_array($s) || empty($s['role'])) continue;
            $role = (string)$s['role'];
            if (!isset($this->samples[$role])) $this->samples[$role] = $s;
            if (($s['page'] ?? null) instanceof Page) {
                $this->pages[] = ['role' => $role, 'url' => (string)($s['res']['final_url'] ?? $s['url']), 'page' => $s['page']];
            }
        }
        $homeWords = $page->wordCount();
        if ($homeWords < 80 && ($page->emptyAppRoot() || $page->frameworkMarkers())) {
            $this->jsNote = ' HTML головної містить лише ' . $homeWords . ' ' . $this->wordsWord($homeWords) . ' (контент рендериться JavaScript-ом): AI-боти, які не виконують JS, цих сигналів не бачать.';
        } elseif ($homeWords < 80) {
            $this->jsNote = ' На головній загалом ' . $homeWords . ' ' . $this->wordsWord($homeWords) . ' видимого тексту.';
        }
        [$this->ymyl, $this->ymylSignal] = $this->detectYmyl();
        $this->addFooterPages();

        $sub = [];
        $sub['contacts'] = $this->evalContacts();          // першими: факти (телефон, адреса) потрібні для коду інших знахідок
        $sub['about'] = $this->evalAbout();
        $legal = $this->evalLegal();
        $sub['legal'] = $legal['score'];
        $sub['identity'] = $this->evalIdentity($legal['found']);
        $sub['authorship'] = $this->evalAuthorship();
        $sub['reviews'] = $this->evalReviews();
        $sub['trust_markers'] = $this->evalTrust();

        $order = ['about', 'contacts', 'legal', 'identity', 'authorship', 'reviews', 'trust_markers'];
        $subOrdered = [];
        foreach ($order as $k) $subOrdered[$k] = $sub[$k];
        $w = self::WEIGHTS[$this->type];
        if ($this->ymyl !== null) {
            $w['authorship'] = (int)round($w['authorship'] * 1.5);
            $w['trust_markers'] = (int)round($w['trust_markers'] * 1.25);
        }
        $components = [];
        foreach ($subOrdered as $k => $v) $components[$k] = [$v, $w[$k]];
        $score = $this->scoreFrom($components);

        $data = [
            'site_type'      => $this->type,
            'ymyl'           => $this->ymyl,
            'ymyl_signal'    => $this->ymylSignal !== '' ? $this->ymylSignal : null,
            'weights'        => $w,
            'extra_requests' => $this->extraFetched,
        ] + $this->notes;

        return $this->result($score, $subOrdered, $this->personal ? self::LABELS_PERSONAL : self::LABELS, $this->findings, $this->passed, $data, $this->summary());
    }

    /**
     * Типографіка звіту: дефіс замість довгих тире, «...» замість «…», пряма апостроф, прямі лапки замість “ ”.
     * У коді (JSON-LD / HTML) кучеряві лапки стають «», щоб не зламати JSON-рядки.
     */
    private static function typo(string $s, bool $code = false): string
    {
        $map = ['—' => '-', '–' => '-', '…' => '...', '’' => '\'', 'ʼ' => '\'', '‘' => '\''];
        $map += $code ? ['“' => '«', '”' => '»', '„' => '«'] : ['“' => '"', '”' => '"', '„' => '"'];
        return strtr($s, $map);
    }

    protected function finding(string $id, string $severity, string $text, array $opts = []): array
    {
        foreach ($opts as $k => $v) if (is_string($v)) $opts[$k] = self::typo($v, $k === 'code');
        return parent::finding($id, $severity, self::typo($text), $opts);
    }

    protected function result(?int $score, array $subScores, array $subLabels, array $findings, array $passed = [], array $data = [], string $summary = ''): array
    {
        $passed = array_map(function ($p) { return self::typo((string)$p); }, $passed);
        return parent::result($score, $subScores, $subLabels, $findings, $passed, $data, self::typo($summary));
    }

    private function reset(): void
    {
        $this->findings = []; $this->passed = []; $this->goods = []; $this->gaps = []; $this->notes = [];
        $this->pages = []; $this->samples = []; $this->textCache = []; $this->extraFetched = [];
        $this->jsNote = ''; $this->ymyl = null; $this->ymylSignal = '';
        $this->teamLinkDone = false; $this->teamLinkCache = null; $this->ymylArt = false;
        $this->facts = self::FACTS0;
        $this->personal = false; $this->person = null; $this->reviewHits = [];
    }

    // ================================================================== 1. «Про нас»

    private function evalAbout(): ?int
    {
        $best = null;
        foreach ($this->pages as $pi => $pg) {
            foreach ($pg['page']->links() as $l) {
                if (!$this->sameOrg($l['href'])) continue;
                $anchor = mb_strtolower(trim($l['text']));
                $byAnchor = $anchor !== '' && mb_strlen($anchor) <= 40 && (preg_match(self::RX_ABOUT_ANCHOR, $anchor)
                    || ($this->personal && preg_match(SiteProfile::RX_ANCHOR_ABOUT_ME, $anchor)));
                $byPath = $this->isAboutPath($l['href']);
                if (!$byAnchor && !$byPath) continue;
                $sc = ($byAnchor ? 2 : 0) + ($byPath ? 1.5 : 0) + ($pi === 0 ? 0.5 : 0);
                if ($best === null || $sc > $best['sc']) $best = ['sc' => $sc, 'href' => $l['href'], 'anchor' => trim($l['text']), 'on' => $pg['role']];
            }
        }
        $sample = $this->samples['about'] ?? null;
        $info = ['url' => null, 'linked' => false, 'via' => null, 'status' => null, 'words' => null, 'facets' => []];
        $aboutPage = null;
        $status = null;
        $blocked = false;

        if ($best !== null && $this->isHomeUrl($best['href'])) {
            // «Про нас» веде на блок головної (#about)
            return $this->aboutInline($best);
        }

        if ($sample !== null) {
            $info['url'] = (string)($sample['res']['final_url'] ?? $sample['url']);
            $info['via'] = (string)($sample['via'] ?? 'link');
            $info['linked'] = $best !== null || $info['via'] === 'link';
            $status = (int)($sample['res']['status'] ?? 0);
            $aboutPage = ($sample['page'] ?? null) instanceof Page ? $sample['page'] : null;
            $blocked = $aboutPage === null && (($sample['challenge'] ?? null) !== null || in_array($status, [0, 401, 403, 429, 503], true));
        } elseif ($best !== null) {
            $info['url'] = $best['href'];
            $info['via'] = 'link';
            $info['linked'] = true;
            $f = $this->fetchPage($best['href']);
            $status = $f['status'];
            $aboutPage = $f['page'];
            $blocked = $f['blocked'];
            if ($aboutPage) $info['url'] = $aboutPage->url();
        } else {
            $probe = $this->probeAbout();
            if ($probe !== null) {
                $info['url'] = $probe['url'];
                $info['via'] = 'probe';
                $info['linked'] = false;
                $status = 200;
                $aboutPage = $probe['page'];
            }
        }
        $info['status'] = $status;
        $anchorLabel = $best !== null && $best['anchor'] !== '' ? '«' . $this->cleanAnchor($best['anchor']) . '»' : ($this->personal ? '«Про мене»' : '«Про нас»');

        if ($info['url'] === null && $this->personal) return $this->aboutMissingPersonal($info);
        if ($info['url'] === null) {
            $this->notes['about'] = $info;
            $this->gaps[] = 'сторінки «Про нас»';
            $sev = 'high';
            $this->findings[] = $this->finding('about_missing', $sev, 'Немає сторінки «Про нас» / «Про компанію»', [
                'evidence' => 'На головній і сторінках вибірки немає посилання з анкором «Про нас», «Про компанію», About тощо або з URL /about, /pro-nas, /o-nas, /company; типові адреси (/about, /about-us, /pro-nas, /pro-kompaniyu, /o-nas, /company) не відкрились як сторінка про компанію.' . $this->jsNote,
                'why'      => 'Сторінка «Про нас» - головне джерело фактів про компанію (хто стоїть за сайтом, з якого року, де працює). AI-системи беруть ці факти в описи бренду і зважають на них, коли вирішують, чи можна довіряти джерелу.',
                'fix'      => 'Створіть окрему сторінку «Про нас» (від 300 слів): історія і рік заснування, команда з іменами, адреса, досягнення; додайте посилання на неї в меню або футер.',
                'bad'      => 'Посилань на «Про нас» у меню і футері немає. ' . $this->aboutLinksSeen(),
                'good'     => $this->aboutOutlineHtml(),
                'code'     => $this->aboutJsonLd($this->base . '/about/'),
                'verify'   => 'Відкрийте нову сторінку з футера головної: HTTP 200, текст видно у вихідному коді (Ctrl+U). Перевірте розмітку AboutPage у validator.schema.org.',
                'effort'   => 'M',
            ]);
            return 0;
        }

        if ($aboutPage === null) {
            $this->notes['about'] = $info;
            if ($blocked) {
                $this->passed[] = 'Посилання ' . $anchorLabel . ' є: ' . $info['url'] . ' (вміст не перевірено: сторінка не віддалася боту, HTTP ' . $status . ')';
                $this->goods[] = 'посилання на ' . $anchorLabel;
                return 60;
            }
            // 404 / 410 / 5xx
            $this->gaps[] = 'робочої сторінки «Про нас»';
            $this->findings[] = $this->finding('about_broken', 'high', 'Посилання ' . $anchorLabel . ' веде на непрацюючу сторінку', [
                'evidence' => 'URL ' . $info['url'] . ' віддає HTTP ' . $status . '.',
                'why'      => 'Бот, який іде за посиланням «Про нас», отримує помилку, тож фактів про компанію немає ні для AI, ні для користувача.',
                'fix'      => 'Відновіть сторінку або виправте посилання в меню / футері на робочу адресу.',
                'bad'      => 'GET ' . $info['url'] . ' -> HTTP ' . $status,
                'good'     => 'GET ' . $info['url'] . ' -> HTTP 200, сторінка з історією, командою, адресою',
                'code'     => '',
                'verify'   => 'curl -I ' . $info['url'] . ' має повернути 200; перевірка в GSC (Перевірка URL-адреси).',
                'effort'   => 'S',
            ]);
            return 10;
        }

        // ---- аналіз вмісту
        $main = $aboutPage->mainText();
        $words = $aboutPage->wordCount($main);
        $info['words'] = $words;
        $facets = $this->aboutFacets($aboutPage, $main);
        $info['facets'] = array_map(function ($f) { return $f['phrase']; }, $facets);
        $this->notes['about'] = $info;
        $haveCnt = count($facets);
        $missing = array_diff(['year', 'team', 'geo', 'achievements'], array_keys($facets));
        $facetLabels = $this->personal ? self::FACET_LABELS_PERSONAL
            : ['year' => 'рік заснування / років на ринку', 'team' => 'команда або імена людей', 'geo' => 'адреса / географія', 'achievements' => 'досягнення (цифри, нагороди, клієнти)'];
        $aboutName = $this->personal ? '«Про мене»' : '«Про нас»';

        if ($words < 150) {
            $score = 35 + 5 * $haveCnt;
            $first = $this->firstSentenceOf($aboutPage, $main);
            $this->gaps[] = 'змістовного тексту на ' . $aboutName;
            $this->findings[] = $this->finding('about_thin', 'medium', 'Сторінка ' . $aboutName . ' занадто коротка (' . $words . ' слів)', [
                'evidence' => 'URL ' . $info['url'] . ': ' . $words . ' слів основного тексту (поріг - 150).'
                    . ($facets ? ' Є: ' . $this->facetList($facets) . '.' : '') . ($missing ? ' Немає: ' . implode(', ', array_map(function ($k) use ($facetLabels) { return $facetLabels[$k]; }, $missing)) . '.' : ''),
                'why'      => $this->personal
                    ? 'Коротка «Про мене» не дає AI-системам фактів для опису експерта: на чому спеціалізується, скільки років у професії, де працював, чим підтверджена експертиза.'
                    : 'Коротка «Про нас» не дає AI-системам фактів для опису компанії: з якого року працює, хто відповідає за роботу, де знаходиться, чим підтверджена експертиза.',
                'fix'      => $this->personal
                    ? 'Розширте сторінку до 300+ слів конкретикою: спеціалізація, досвід у роках і місця роботи, ключові кейси з цифрами, публікації й виступи, освіта та сертифікати, місто, фото і посилання на профілі (LinkedIn тощо).'
                    : 'Розширте сторінку до 300+ слів конкретикою: історія і рік заснування, керівник / засновник з іменем, команда, адреса, цифри (клієнти, проєкти), нагороди й сертифікати.',
                'bad'      => $words . ' слів. Початок: «' . $first . '»',
                'good'     => $this->aboutOutlineHtml(),
                'code'     => $this->aboutJsonLd($info['url']),
                'verify'   => 'Порахуйте слова в основному тексті сторінки (без меню й футера) і перевірте, що факти є у вихідному HTML (Ctrl+U), а не лише у JS-віджетах.',
                'effort'   => 'M',
            ]);
        } else {
            $score = 60 + 10 * $haveCnt;
            $this->goods[] = $aboutName . ' (' . $words . ' ' . $this->wordsWord($words) . ')';
        }
        $this->passed[] = 'Сторінка ' . $anchorLabel . ': ' . $info['url'] . ' (' . $words . ' ' . $this->wordsWord($words) . ', HTTP 200)';
        if ($facets) $this->passed[] = 'На ' . $aboutName . ' є: ' . $this->facetList($facets);

        if (!$info['linked']) {
            $score -= 15;
            $this->findings[] = $this->finding('about_not_linked', 'medium', 'Сторінка ' . $aboutName . ' існує, але на неї немає посилання з головної', [
                'evidence' => 'Сторінку ' . $info['url'] . ' знайдено ' . ($info['via'] === 'probe' ? 'перебором типових адрес' : 'через sitemap.xml') . '; у меню і футері головної посилання на неї немає.',
                'why'      => 'Краулери AI-систем знаходять сторінки переважно за внутрішніми посиланнями. Сторінка без посилань рідко потрапляє в індекс і майже не передає довіри решті сайту.',
                'fix'      => 'Додайте посилання ' . $aboutName . ' у головне меню або футер усіх сторінок.',
                'bad'      => $this->aboutLinksSeen(),
                'good'     => "<footer>\n  <a href=\"" . htmlspecialchars($this->relPath($info['url']), ENT_QUOTES) . "\">" . ($this->personal ? 'Про мене' : 'Про нас') . "</a>\n  <a href=\"/contacts/\">Контакти</a>\n</footer>",
                'code'     => '',
                'verify'   => 'Перегляньте вихідний код головної (Ctrl+U) і знайдіть посилання на ' . $this->relPath($info['url']) . '.',
                'effort'   => 'S',
            ]);
        }

        if ($words >= 150) {
            $teamMissingYmyl = $this->ymyl !== null && in_array('team', $missing, true);
            if (count($missing) >= 2 || $teamMissingYmyl) {
                $sev = $teamMissingYmyl ? 'medium' : 'low';
                $this->findings[] = $this->finding('about_facts', $sev, 'На ' . $aboutName . ' бракує фактів: ' . implode(', ', array_map(function ($k) use ($facetLabels) { return $facetLabels[$k]; }, $missing)), [
                    'evidence' => 'URL ' . $info['url'] . ' (' . $words . ' слів). ' . ($facets ? 'Знайдено: ' . $this->facetList($facets) . '. ' : '') . 'Не знайдено: ' . implode(', ', array_map(function ($k) use ($facetLabels) { return $facetLabels[$k]; }, $missing)) . '.',
                    'why'      => $this->personal
                        ? 'Конкретні факти (спеціалізація, роки досвіду, місто, кейси з цифрами, виступи) AI-системи цитують в описах експерта; загальні фрази на кшталт «досвідчений фахівець» нічого не додають.'
                        : ($teamMissingYmyl
                            ? 'У ' . $this->ymylLabel() . ' AI-системи особливо зважають на те, хто конкретно надає послуги: імена, кваліфікація і досвід фахівців - ключовий сигнал експертності.'
                            : 'Конкретні факти (рік, люди, місто, цифри) AI-системи цитують в описах компанії; загальні фрази на кшталт «ми - команда професіоналів» нічого не додають.'),
                    'fix'      => 'Додайте на сторінку відсутні факти: ' . implode(', ', array_map(function ($k) use ($facetLabels) { return $facetLabels[$k]; }, $missing)) . '.',
                    'bad'      => 'Початок основного тексту: «' . $this->firstSentenceOf($aboutPage, $main) . '»',
                    'good'     => $this->aboutFactsExample($missing),
                    'code'     => in_array('team', $missing, true) ? $this->teamJsonLd() : '',
                    'verify'   => $this->personal
                        ? 'Запитайте ChatGPT або Perplexity «Хто такий ' . $this->brand . ' і на чому спеціалізується?» - відповідь має збігатися з сайтом.'
                        : 'Запитайте ChatGPT або Perplexity «Хто засновник ' . $this->brand . ' і з якого року компанія працює?» - відповідь має збігатися з сайтом.',
                    'effort'   => 'S',
                ]);
            }
        }
        return max(0, min(100, $score));
    }

    private function aboutInline(array $best): int
    {
        $home = $this->home;
        $facets = $this->aboutFacets($home, $home->visibleText());
        $score = 45 + 5 * count($facets);
        $this->notes['about'] = ['url' => $home->url(), 'linked' => true, 'via' => 'inline', 'facets' => array_map(function ($f) { return $f['phrase']; }, $facets)];
        $this->passed[] = 'Блок «' . self::mbTrunc($best['anchor'], 40) . '» є на головній (якір)';
        $this->findings[] = $this->finding('about_inline', 'low', 'Окремої сторінки ' . ($this->personal ? '«Про мене»' : '«Про нас»') . ' немає, лише блок на головній', [
            'evidence' => 'Посилання «' . self::mbTrunc($best['anchor'], 40) . '» веде на ' . $best['href'] . ' (якір на головній).' . ($facets ? ' На головній є: ' . $this->facetList($facets) . '.' : ''),
            'why'      => 'Окрема сторінка з фактами про ' . ($this->personal ? 'експерта' : 'компанію') . ' простіше потрапляє в індекс і цитування як джерело «хто це», ніж блок серед інших секцій головної.',
            'fix'      => $this->personal
                ? 'Винесіть блок в окрему сторінку /about/ і розширте фактами: спеціалізація, досвід, кейси з цифрами, публікації й виступи, освіта.'
                : 'Винесіть блок в окрему сторінку /about/ і розширте фактами: рік заснування, команда, адреса, досягнення.',
            'bad'      => '<a href="#about">' . self::mbTrunc($best['anchor'], 40) . '</a> - якір на головній',
            'good'     => $this->aboutOutlineHtml(),
            'code'     => $this->aboutJsonLd($this->base . '/about/'),
            'verify'   => 'Нова сторінка відкривається за власним URL з HTTP 200 і є в sitemap.xml.',
            'effort'   => 'M',
        ]);
        return $score;
    }

    /**
     * Особистий сайт без окремої сторінки «Про мене»: якщо біографія є на головній - часткова оцінка й низький пріоритет,
     * інакше - знахідка середнього рівня (для експерта це головне джерело фактів про нього).
     */
    private function aboutMissingPersonal(array $info): int
    {
        $home = $this->home;
        $main = $home->mainText();
        $words = $home->wordCount($main);
        $facets = $this->aboutFacets($home, $main);
        $this->notes['about'] = $info + ['home_words' => $words, 'home_facets' => array_map(function ($f) { return $f['phrase']; }, $facets)];
        $tried = 'На головній і сторінках вибірки немає посилання «Про мене», «Біографія», «Про автора», About me тощо або з URL /about, /about-me, /pro-mene, /bio; типові адреси не відкрились як сторінка про особу.';
        if ($words >= 150 && count($facets) >= 2) {
            $this->passed[] = 'Біографія є на головній (' . $words . ' ' . $this->wordsWord($words) . '): ' . $this->facetList($facets);
            $this->goods[] = 'біографія на головній';
            $this->findings[] = $this->finding('about_inline', 'low', 'Окремої сторінки «Про мене» немає - біографія лише на головній', [
                'evidence' => $tried . ' На головній є: ' . $this->facetList($facets) . '.',
                'why'      => 'Окрема сторінка «Про мене» простіше потрапляє в індекс і в цитування як джерело відповіді «хто це»; на головній біографія конкурує з послугами і закликами.',
                'fix'      => 'Винесіть біографію на окрему сторінку /about/ (300+ слів): спеціалізація, досвід, кейси з цифрами, публікації й виступи, освіта, посилання на профілі; додайте її в меню.',
                'bad'      => 'Окремої сторінки «Про мене» немає; посилання меню: ' . $this->aboutLinksSeen(),
                'good'     => $this->aboutOutlineHtml(),
                'code'     => $this->aboutJsonLd($this->base . '/about/'),
                'verify'   => 'Нова сторінка відкривається за власним URL з HTTP 200, є в меню і в sitemap.xml.',
                'effort'   => 'M',
            ]);
            return 45 + 5 * count($facets);
        }
        $this->gaps[] = 'сторінки «Про мене»';
        $this->findings[] = $this->finding('about_missing', 'medium', 'Немає сторінки «Про мене»', [
            'evidence' => $tried . ' На головній ' . $words . ' ' . $this->wordsWord($words) . ' основного тексту' . ($facets ? ', є: ' . $this->facetList($facets) : '') . '.' . $this->jsNote,
            'why'      => 'Сторінка «Про мене» - головне джерело фактів про експерта: спеціалізація, досвід, кейси, публікації. AI-системи беруть ці факти, коли описують людину і вирішують, чи можна довіряти її порадам.',
            'fix'      => 'Створіть сторінку «Про мене» (від 300 слів): спеціалізація, досвід у роках і місця роботи, кейси з цифрами, публікації й виступи, освіта та сертифікати, фото і посилання на LinkedIn та інші профілі; додайте її в меню.',
            'bad'      => 'Посилання «Про мене» в меню і футері немає. ' . $this->aboutLinksSeen(),
            'good'     => $this->aboutOutlineHtml(),
            'code'     => $this->aboutJsonLd($this->base . '/about/'),
            'verify'   => 'Відкрийте нову сторінку з меню головної: HTTP 200, текст видно у вихідному коді (Ctrl+U). Перевірте ProfilePage і Person у validator.schema.org.',
            'effort'   => 'M',
        ]);
        return min(30, 10 * count($facets));
    }

    /** Факти на «Про нас»: year, team, geo, achievements => ['phrase'=>..]. */
    private function aboutFacets(Page $p, string $text): array
    {
        $out = [];
        $text = $this->stripInvitations($text);
        $y = $this->findPhrases($this->stripPersonExperience($text), $this->trustPatterns()['years'], 1, [$this, 'validYear']);
        if ($y) { $out['year'] = ['phrase' => $y[0]]; $this->facts['year'] = $this->facts['year'] ?? $this->yearFrom($y[0]); }
        // команда: лише з іменами людей (роль + ім'я прізвище, ПІБ з по батькові), Person у розмітці, заголовок блоку команди або посилання на сторінку команди
        $persons = $p->schemaItemsOfType(['Person']);
        $notBrand = function (array $m) { return !$this->isBrandName((string)($m[1] ?? '')); };
        $team = $this->findPhrases($text, [
            '/(?<![\p{L}])(?i:засновни\w*|співзасновни\w*|генеральн\w* директор\w*|директор\w*|керівни[кця]\w*|власни[кц]\w*|головн\w* лікар\w*|головн\w* редактор\w*|шеф-редактор\w*|CEO|COO|CTO|founder|co-founder|managing director|editor-in-chief)(?:\s+(?:\p{Ll}[\p{Ll}\'’ʼ-]*|[«"][^»"]{1,30}[»"])){0,4}?\s*[:,-]?\s+(\p{Lu}[\p{Ll}\'’ʼ]+(?:-\p{L}[\p{Ll}\'’ʼ]+)*\s+\p{Lu}[\p{Ll}\'’ʼ]{2,}(?:-\p{L}[\p{Ll}\'’ʼ]+)*)(?![\p{L}])/u',
            '/(?<![\p{L}])(\p{Lu}[\p{Ll}\'’ʼ]+(?:-\p{L}[\p{Ll}\'’ʼ]+)*\s+\p{Lu}[\p{Ll}\'’ʼ]{2,}(?:-\p{L}[\p{Ll}\'’ʼ]+)*)(?:\s+\p{Lu}[\p{Ll}\'’ʼ-]+)?\s*[,-]\s*(?i:засновни\w*|співзасновни\w*|генеральн\w* директор\w*|директор\w*|керівни[кця]\w*|власни[кц]\w*|головн\w* лікар\w*|головн\w* редактор\w*|CEO|founder|co-founder)(?![\p{L}])/u',
            '/(?<![\p{L}])(\p{Lu}[\p{Ll}\'’ʼ-]+\s+\p{Lu}[\p{Ll}\'’ʼ-]+\s+\p{Lu}[\p{Ll}\'’ʼ-]+(?:ович|евич|йович|івна|ївна|овна|ічна))(?![\p{L}])/u',
        ], 1, $notBrand);
        $teamHead = null;
        foreach (['h2', 'h3', 'h4'] as $h) {
            foreach ($p->headings()[$h] as $t) {
                if (preg_match('/^(наша команда|команда|наші лікарі|лікарі|наші фахівці|наші спеціалісти|фахівці|спеціалісти|наші експерти|експерти|керівництво|засновники|редакція|склад редакції|our team|team|meet the team|leadership)$/iu', trim($t))) { $teamHead = trim($t); break 2; }
            }
        }
        if ($persons) {
            $n = $persons[0]['data']['name'] ?? '';
            if (is_array($n)) $n = (string)(reset($n) ?: '');
            $out['team'] = ['phrase' => 'Person у розмітці' . (is_string($n) && $n !== '' ? ' («' . self::mbTrunc($n, 40) . '»)' : '')];
        } elseif ($team) {
            $out['team'] = ['phrase' => $team[0]];
        } elseif ($teamHead !== null) {
            $out['team'] = ['phrase' => 'заголовок «' . self::mbTrunc($teamHead, 40) . '»'];
        } elseif (($tl = $this->teamLink()) !== null) {
            $out['team'] = ['phrase' => 'посилання «' . $tl['text'] . '»'];
        } elseif ($this->personal && SiteProfile::looksLikePersonName($this->brand) && mb_stripos($text, $this->brand) !== false) {
            $out['team'] = ['phrase' => 'ім\'я «' . $this->brand . '» у тексті'];
        }
        $addrAbout = $this->addressOf($text);
        $geo = $addrAbout !== null ? [$addrAbout] : $this->findPhrases($text, ['/(?<![\p{L}])(?:' . implode('|', array_keys(self::CITY_FORMS)) . ')(?![\p{L}])|по всій Україні|(?<![\p{L}])офіс\w* (?:у|в) |(?<![\d])\d+ країн/u'], 1);
        if (!$geo && ($sa = $this->schemaAddressOf($p)) !== null) $geo = [$sa . ' (розмітка)'];
        if (!$geo && $this->personal && ($pl = $this->personLocality($p)) !== null) $geo = [$pl . ' (розмітка Person)'];
        if ($geo) $out['geo'] = ['phrase' => $geo[0]];
        $tp = $this->trustPatterns();
        $achRx = array_merge($tp['credentials'], $tp['partners'], $this->clientPatterns(), ['/(?<![\p{L}])(?:топ-?\d+\s+(?:компаній|агенці\w*|клінік|магазин\w*|видань|брендів)|лідер\w* ринку|перш\w* в Україні)(?![\p{L}])/iu'], $tp['numbers']);
        if ($this->personal) $achRx = array_merge($achRx, $tp['cases'], $tp['publications'], $tp['talks']);
        $ach = $this->findPhrases($text, $achRx, 1);
        if ($ach) $out['achievements'] = ['phrase' => $ach[0]];
        return $out;
    }

    /** Місто особи з розмітки Person (address / workLocation / homeLocation -> addressLocality). */
    private function personLocality(Page $p): ?string
    {
        foreach ($p->schemaItemsOfType(['Person']) as $it) {
            if ($this->person && !SiteProfile::personNameMatches($this->person, (string)(is_string($it['data']['name'] ?? null) ? $it['data']['name'] : ''))) continue;
            foreach (['address', 'workLocation', 'homeLocation'] as $k) {
                $v = $it['data'][$k] ?? null;
                if (is_array($v) && isset($v[0]) && is_array($v[0])) $v = $v[0];
                if (!is_array($v)) continue;
                $a = isset($v['address']) && is_array($v['address']) ? $v['address'] : $v;
                if (isset($a[0]) && is_array($a[0])) $a = $a[0];
                $loc = is_string($a['addressLocality'] ?? null) ? trim($a['addressLocality']) : (is_string($v['name'] ?? null) ? trim($v['name']) : '');
                if ($loc !== '') return self::mbTrunc(Page::norm($loc), 60);
            }
        }
        return null;
    }

    /** Прибирає запрошення на кшталт «раді бачити Вас серед наших клієнтів» (це не перелік клієнтів). */
    private function stripInvitations(string $text): string
    {
        return (string)preg_replace('/(?i:бачити|стати|станьте|стань|приєднуйтесь|приєднуйтеся|долучайтесь|долучайтеся|будьте|бути|запрошуємо)\s+(?:\S+\s+){0,3}?(?i:серед наших клієнтів|одним із наших клієнтів|нашим клієнтом|нашими клієнтами)/u', ' ', $text);
    }

    /**
     * «47+ років досвіду» у картках лікарів / фахівців - це досвід людей, а не компанії.
     * Якщо фраза повторюється 3+ рази з різними числами, вилучаємо такі входження перед пошуком «років на ринку».
     */
    private function stripPersonExperience(string $text): string
    {
        $rx = '/(?<![\d.,])(\d{1,2})\+?\s*(?i:років|роки|рік)\s+(?i:досвіду|практики|стажу)/u';
        if (preg_match_all($rx, $text, $mm) && count($mm[0]) >= 3 && count(array_unique($mm[1])) >= 2) {
            return (string)preg_replace($rx, ' ', $text);
        }
        return $text;
    }

    private function facetList(array $facets): string
    {
        $names = $this->personal ? ['year' => 'досвід', 'team' => 'особа', 'geo' => 'географія', 'achievements' => 'досягнення']
            : ['year' => 'рік', 'team' => 'команда', 'geo' => 'географія', 'achievements' => 'досягнення'];
        $parts = [];
        foreach ($facets as $k => $f) $parts[] = $names[$k] . ' («' . self::mbTrunc($f['phrase'], 50) . '»)';
        return implode(', ', $parts);
    }

    private function probeAbout(): ?array
    {
        $homePath = (string)parse_url($this->home->url(), PHP_URL_PATH);
        $prefix = preg_match('#^/(ua|uk|en|ru)(/|$)#i', $homePath, $m) ? '/' . strtolower($m[1]) : '';
        $paths = $prefix !== ''
            ? [$prefix . '/about-us/', $prefix . '/about/', $prefix . '/pro-nas/', '/about/', '/about-us/', '/pro-nas/']
            : ['/about', '/about-us', '/pro-nas', '/pro-kompaniyu', '/o-nas', '/company'];
        if ($this->personal) {
            $paths = $prefix !== ''
                ? [$prefix . '/about/', $prefix . '/about-me/', $prefix . '/pro-mene/', '/about/', '/about-me/', '/pro-mene/']
                : ['/about', '/about-me', '/pro-mene', '/bio', '/biography', '/pro-avtora'];
        }
        $urls = array_map(function ($p) { return $this->base . $p; }, $paths);
        $this->extraFetched[] = 'probe:' . implode(',', $paths);
        $res = Crawler::fetchMulti($urls, $this->fetchMode, 8);
        $homeTitle = mb_strtolower($this->home->title());
        foreach ($urls as $u) {
            $r = $res[$u] ?? null;
            if (!$r || (int)$r['status'] !== 200) continue;
            $final = (string)($r['final_url'] ?? $u);
            if (!$this->sameOrg($final) || $this->isHomeUrl($final)) continue;
            $p = Page::fromCrawl($r);
            if (!$p || $p->challenge() !== null) continue;
            $title = mb_strtolower($p->title());
            $h1 = mb_strtolower(implode(' ', $p->headings()['h1']));
            if ($title === $homeTitle && $h1 === mb_strtolower(implode(' ', $this->home->headings()['h1']))) continue; // SPA / soft-404
            if (preg_match('/(404|не знайдено|не найдено|not found|сторінку не знайдено)/iu', $title . ' ' . $h1)) continue;
            if (!preg_match(self::RX_ABOUT_TITLE, $title . ' ' . $h1) && !($this->personal && preg_match(self::RX_ABOUT_ME_TITLE, $title . ' ' . $h1))) continue;
            return ['url' => $p->url(), 'page' => $p];
        }
        return null;
    }

    private function isAboutPath(string $href): bool
    {
        if (SiteProfile::roleOfUrl($href) === 'about') return true;
        $segs = $this->pathSegs($href);
        // корпоративний піддомен (about.pumb.ua, company.example.com): його корінь і є сторінкою про компанію
        if (count($segs) <= 1 && $this->sameOrg($href) && preg_match('/^(about|company|corporate|corp)\./i', (string)parse_url($href, PHP_URL_HOST))) return true;
        if (!$segs || count($segs) > 2) return false;
        $last = preg_replace('/\.(html?|php)$/', '', end($segs));
        return in_array($last, self::ABOUT_SEGS_EXTRA, true) || ($this->personal && in_array($last, SiteProfile::SEG_ABOUT_ME, true));
    }

    private function aboutLinksSeen(): string
    {
        $nav = [];
        foreach ($this->home->links() as $l) {
            if (!$l['in_nav'] || !$l['internal'] || $l['text'] === '' || mb_strlen($l['text']) > 30) continue;
            $nav[mb_strtolower($l['text'])] = $l['text'];
            if (count($nav) >= 10) break;
        }
        if (!$nav) return 'У меню / футері головної немає текстових внутрішніх посилань.';
        return 'Пункти меню / футера головної: ' . implode(', ', array_map(function ($t) { return '«' . $t . '»'; }, array_values($nav))) . '.';
    }

    private function teamLink(): ?array
    {
        if ($this->teamLinkDone) return $this->teamLinkCache;
        $found = null; $byPath = null;
        foreach ($this->pages as $pg) {
            foreach ($pg['page']->links() as $l) {
                if (!$this->sameOrg($l['href'])) continue;
                $a = mb_strtolower(trim($l['text']));
                $path = rtrim(strtolower(rawurldecode((string)parse_url($l['href'], PHP_URL_PATH))), '/') . '/';
                $rec = ['text' => trim($l['text']) !== '' ? self::mbTrunc(trim($l['text']), 40) : $path, 'href' => $l['href']];
                // анкор («Команда», «Правління») точніший за адресу: /management може вести на сторінку для інвесторів
                if ($a !== '' && preg_match(self::RX_TEAM_ANCHOR, $a)) { $found = $rec; break 2; }
                if ($byPath === null && preg_match(self::RX_TEAM_PATH, $path)) $byPath = $rec;
            }
        }
        if ($found === null) $found = $byPath;
        $this->teamLinkDone = true;
        return $this->teamLinkCache = $found;
    }

    // ================================================================== 2. Контакти

    private function evalContacts(): ?int
    {
        $contactSample = $this->samples['contact'] ?? null;
        $contactLink = null;
        foreach ($this->pages as $pg) {
            foreach ($pg['page']->links() as $l) {
                if (!$this->sameOrg($l['href'])) continue;
                $a = mb_strtolower(trim($l['text']));
                if (SiteProfile::roleOfUrl($l['href']) === 'contact' || ($a !== '' && preg_match(self::RX_CONTACT_ANCHOR, $a))) {
                    $contactLink = $l['href'];
                    break 2;
                }
            }
        }
        $sources = [];
        foreach ($this->pages as $pg) {
            if (in_array($pg['role'], ['home', 'contact', 'about'], true)) $sources[] = $pg;
        }
        $contactPage = ($contactSample && ($contactSample['page'] ?? null) instanceof Page) ? $contactSample['page'] : null;
        $contactUrl = $contactSample ? (string)($contactSample['res']['final_url'] ?? $contactSample['url']) : $contactLink;

        $addrInfo = null; $schemaCity = null;
        $collect = function (array $sources) use (&$addrInfo, &$schemaCity) {
            $phones = []; $emails = []; $addr = null; $city = null; $schemaAddr = null; $addrInfo = null; $schemaCity = null;
            foreach ($sources as $pg) {
                foreach ($this->phonesOf($pg['page']) as $k => $ph) if (!isset($phones[$k])) $phones[$k] = $ph;
                foreach ($this->emailsOf($pg['page']) as $k => $em) if (!isset($emails[$k])) $emails[$k] = $em;
                if ($addr === null && ($ai = $this->addressInfo($this->textOf($pg['page']))) !== null) { $addr = $ai['addr']; $addrInfo = $ai; }
                if ($city === null) $city = $this->cityOf($this->textOf($pg['page']));
                if ($schemaAddr === null) $schemaAddr = $this->schemaAddressOf($pg['page']);
                if ($schemaCity === null) $schemaCity = $this->schemaLocalityOf($pg['page']);
            }
            return [$phones, $emails, $addr, $city, $schemaAddr];
        };
        [$phones, $emails, $addr, $city, $schemaAddr] = $collect($sources);

        // «Контакти» не у вибірці, але посилання є і даних бракує - дочитуємо сторінку
        $contactFetchNote = '';
        if (!$contactSample && $contactLink && (!$phones || (!$addr && !$schemaAddr) || !$emails)) {
            $f = $this->fetchPage($contactLink);
            if ($f['page']) {
                $contactPage = $f['page'];
                $contactUrl = $f['page']->url();
                $this->pages[] = ['role' => 'contact', 'url' => $contactUrl, 'page' => $f['page']];
                $sources[] = ['role' => 'contact', 'url' => $contactUrl, 'page' => $f['page']];
                [$phones, $emails, $addr, $city, $schemaAddr] = $collect($sources);
            } else {
                $contactFetchNote = ' Сторінку ' . $contactLink . ' завантажити не вдалося (HTTP ' . $f['status'] . ').';
            }
        } elseif ($contactSample && !$contactPage) {
            $contactFetchNote = ' Сторінка контактів ' . $contactUrl . ' не віддалася боту (HTTP ' . (int)($contactSample['res']['status'] ?? 0) . '), тому перевірено лише головну та інші сторінки.';
        }

        $hasPhone = (bool)$phones;
        $hasEmail = (bool)$emails;
        $addrLevel = $addr !== null ? 'full' : ($schemaAddr !== null ? 'schema' : ($city !== null ? 'city' : 'none'));
        $hasPage = $contactUrl !== null;

        if ($hasPhone) $this->facts['phone'] = reset($phones);
        if ($hasEmail) $this->facts['email'] = reset($emails)['email'];
        if ($addr !== null) $this->facts['address'] = $addr;
        elseif ($schemaAddr !== null) $this->facts['address'] = $schemaAddr;
        if ($this->facts['address'] !== null) {
            $sp = $this->splitAddress((string)$this->facts['address']);
            $this->facts['street'] = $sp['street'];
            $this->facts['postal'] = $sp['postal'] ?? ($addrInfo['postal'] ?? null);
        }
        if ($addr !== null && $addrInfo !== null) {
            $this->facts['region'] = $addrInfo['region'];
            $this->facts['district'] = $addrInfo['district'];
        }
        $cityNom = $city !== null ? ($this->cityNominative($city) ?? $city) : null;
        $this->facts['city'] = $this->cityFromAddress((string)$this->facts['address']) ?? ($addrInfo['city'] ?? null) ?? $schemaCity ?? $cityNom;

        if ($this->personal) return $this->contactsPersonal($sources, $phones, $emails, $contactUrl, $contactFetchNote);

        $w = self::CONTACT_W[$this->type];
        $score = ($hasPhone ? $w['phone'] : 0) + ($hasEmail ? $w['email'] : 0) + ($hasPage ? $w['page'] : 0)
            + ($addrLevel === 'full' || $addrLevel === 'schema' ? $w['address'] : ($addrLevel === 'city' ? (int)round($w['address'] / 2) : 0));

        $this->notes['contacts'] = [
            'phones'      => array_values(array_map(function ($p) { return $p; }, array_slice($phones, 0, 4))),
            'emails'      => array_values(array_map(function ($e) { return $e['email']; }, array_slice($emails, 0, 4))),
            'address'     => $addr ?? $schemaAddr,
            'address_lvl' => $addrLevel,
            'city'        => $city,
            'contact_url' => $contactUrl,
        ];

        // passed
        if ($hasPhone) {
            $ph = array_slice($phones, 0, 3);
            $viaTel = false;
            foreach ($sources as $pg) if ($pg['page']->query('//a[starts-with(translate(normalize-space(@href),"TEL","tel"),"tel:")]')) { $viaTel = true; break; }
            $this->passed[] = 'Телефон: ' . implode(', ', $ph) . ($viaTel ? ' (клікабельне посилання tel:)' : '');
        }
        if ($hasEmail) $this->passed[] = 'Email: ' . implode(', ', array_map(function ($e) { return $e['email']; }, array_slice($emails, 0, 3)));
        if ($addrLevel === 'full') {
            $near = [];
            if (($addrInfo['city'] ?? null) !== null && $this->splitAddress($addr)['locality'] === null) $near[] = 'місто ' . $addrInfo['city'];
            elseif (($addrInfo['city'] ?? null) === null && $this->splitAddress($addr)['locality'] === null) {
                if (!empty($addrInfo['district'])) $near[] = $addrInfo['district'];
                if (!empty($addrInfo['region'])) $near[] = $addrInfo['region'];
            }
            $this->passed[] = 'Адреса у видимому тексті: «' . $addr . '»' . ($near ? ' (поруч указано: ' . implode(', ', $near) . ')' : '');
        }
        elseif ($addrLevel === 'schema') $this->passed[] = 'Адреса є в розмітці: «' . $schemaAddr . '»';
        if ($hasPage) $this->passed[] = 'Сторінка контактів: ' . $contactUrl;
        if ($hasPhone && ($addrLevel === 'full' || $addrLevel === 'schema')) $this->goods[] = 'телефон і адреса';
        elseif ($hasPhone || $hasEmail) $this->goods[] = 'контакти';

        // знахідки
        if (!$hasPhone && !$hasEmail && $addrLevel === 'none' && !$hasPage) {
            $sev = $this->type === 'local' ? 'critical' : 'high';
            $this->gaps[] = 'контактів';
            $this->findings[] = $this->finding('contacts_missing', $sev, 'На сайті не знайдено жодних контактів', [
                'evidence' => 'Головна' . (count($sources) > 1 ? ' і сторінки вибірки' : '') . ': немає посилань tel: / mailto:, телефону, email чи адреси у видимому тексті, немає посилання на сторінку «Контакти».' . $this->jsNote,
                'why'      => $this->type === 'local'
                    ? 'Для локального бізнесу телефон і адреса - основа: без них AI-системи не можуть зіставити сайт із компанією на карті й рекомендувати її на запити «поруч» чи «у місті».'
                    : 'Сайт без контактів виглядає анонімним. AI-системи обережніше цитують і рекомендують джерела, власника яких неможливо встановити.',
                'fix'      => 'Додайте у футер усіх сторінок телефон (посилання tel:), email (mailto:), адресу і посилання на окрему сторінку «Контакти».',
                'bad'      => 'Контактів у HTML немає. Футер головної: «' . $this->footerExcerpt() . '»',
                'good'     => $this->contactsGood([]),
                'code'     => $this->contactsHtml(),
                'verify'   => 'curl -s ' . $this->base . '/ | grep -E "tel:|mailto:" має знайти посилання; адреса збігається з Google Business Profile.',
                'effort'   => 'S',
            ]);
            return 0;
        }

        $sevMap = [
            'local'     => ['phone' => 'high', 'address' => 'high', 'address_city' => 'medium', 'email' => 'low', 'page' => 'low'],
            'ecommerce' => ['phone' => 'medium', 'address' => 'medium', 'address_city' => 'low', 'email' => 'low', 'page' => 'low'],
            'services'  => ['phone' => 'medium', 'address' => 'medium', 'address_city' => 'low', 'email' => 'low', 'page' => 'low'],
            'corporate' => ['phone' => 'medium', 'address' => 'medium', 'address_city' => 'low', 'email' => 'low', 'page' => 'low'],
            'media'     => ['phone' => 'low', 'address' => 'low', 'address_city' => null, 'email' => 'low', 'page' => 'low'],
            'saas'      => ['phone' => 'low', 'address' => 'low', 'address_city' => null, 'email' => 'low', 'page' => 'low'],
        ][$this->type];
        if ($this->ymyl !== null && in_array($this->type, ['media', 'saas'], true)) { $sevMap['phone'] = 'medium'; $sevMap['address'] = 'medium'; }
        $missing = []; $sev = null;
        $bump = function (?string $s) use (&$sev) {
            if ($s === null) return;
            $o = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
            if ($sev === null || $o[$s] > $o[$sev]) $sev = $s;
        };
        if (!$hasPhone) { $missing[] = 'телефону'; $bump($sevMap['phone']); }
        if (!$hasEmail) { $missing[] = 'email'; $bump($sevMap['email']); }
        if ($addrLevel === 'none') { $missing[] = 'адреси'; $bump($sevMap['address']); }
        elseif ($addrLevel === 'city' && $sevMap['address_city'] !== null) { $missing[] = 'повної адреси (є лише місто: «' . $city . '»)'; $bump($sevMap['address_city']); }
        if (!$hasPage) { $missing[] = 'окремої сторінки «Контакти»'; $bump($sevMap['page']); }
        if (!$hasPhone && !$hasEmail) $bump(in_array($this->type, ['media', 'saas'], true) ? 'medium' : 'high');

        if ($missing && $sev !== null) {
            $have = [];
            if ($hasPhone) $have[] = 'телефон ' . reset($phones);
            if ($hasEmail) $have[] = 'email ' . reset($emails)['email'];
            if ($addrLevel === 'full') $have[] = 'адреса «' . $addr . '»';
            elseif ($addrLevel === 'schema') $have[] = 'адреса в розмітці «' . $schemaAddr . '»';
            elseif ($addrLevel === 'city') $have[] = 'місто «' . $city . '»';
            if ($hasPage) $have[] = 'сторінка ' . $contactUrl;
            if (in_array('адреси', $missing, true) || $addrLevel === 'city') $this->gaps[] = 'адреси';
            elseif (!$hasPhone) $this->gaps[] = 'телефону';
            $this->findings[] = $this->finding('contacts_incomplete', $sev, 'Неповні контакти: немає ' . implode(', ', $missing), [
                'evidence' => 'Перевірено видимий текст і посилання tel: / mailto: на ' . $this->rolesList($sources) . '. Знайдено: ' . ($have ? implode('; ', $have) : 'нічого') . '.' . $contactFetchNote . $this->jsNote,
                'why'      => $this->type === 'local'
                    ? 'Для локальних запитів («клініка поруч», «де купити у Києві») Google радить повні й точні дані в Business Profile; однакові назва, адреса й телефон (NAP) на сайті, у профілі й довідниках - усталена практика локального SEO, щоб усі джерела описували одну компанію.'
                    : 'Повні контакти (телефон, email, фізична адреса) - базовий сигнал, що за сайтом стоїть реальна компанія; AI-системи враховують це, коли обирають джерела для рекомендацій.',
                'fix'      => 'Додайте у футер і на сторінку «Контакти» відсутнє: ' . implode(', ', $missing) . '. Телефон оформіть посиланням tel:, email - mailto:, адресу - текстом (не лише картинкою чи картою).',
                'bad'      => $have ? 'Зараз: ' . implode('; ', $have) : 'Футер головної: «' . $this->footerExcerpt() . '»',
                'good'     => $this->contactsGood($missing),
                'code'     => $this->contactsHtml(),
                'verify'   => 'curl -s ' . ($contactUrl ?? $this->base . '/') . ' | grep -E "tel:|mailto:"; адреса й телефон мають збігатися з Google Business Profile.',
                'effort'   => 'S',
            ]);
        }
        return max(0, min(100, $score));
    }

    /**
     * Контакти особистого сайту: достатньо одного прямого каналу - email, Telegram, LinkedIn або форми зв'язку.
     * Телефон і адреса не обов'язкові (якщо є - це ще один канал).
     */
    private function contactsPersonal(array $sources, array $phones, array $emails, ?string $contactUrl, string $fetchNote): int
    {
        $channels = [];
        if ($emails) $channels['email'] = 'email ' . reset($emails)['email'];
        $tg = null; $li = null;
        foreach ($sources as $pg) {
            foreach ($pg['page']->externalLinks() as $l) {
                $h = Crawler::registrableHost($l['href']);
                $path = trim((string)parse_url($l['href'], PHP_URL_PATH), '/');
                if ($tg === null && in_array($h, ['t.me', 'telegram.me'], true) && $path !== '' && !preg_match('#^(share|joinchat)#i', $path)) $tg = $l['href'];
                if ($li === null && $h === 'linkedin.com' && preg_match('#^(in|company)/[^/]+#i', $path)) $li = $l['href'];
            }
        }
        if ($tg !== null) $channels['telegram'] = 'Telegram ' . preg_replace('#^https?://(www\.)?#i', '', rtrim($tg, '/'));
        if ($li !== null) $channels['linkedin'] = 'LinkedIn ' . preg_replace('#^https?://(www\.)?#i', '', rtrim($li, '/'));
        $formOn = $this->contactFormPage($sources);
        if ($formOn !== null) $channels['form'] = 'форма зв\'язку (' . $this->roleLabel($formOn['role']) . ($formOn['role'] !== 'home' ? ' ' . $this->relPath($formOn['url']) : '') . ')';
        if ($phones) $channels['phone'] = 'телефон ' . reset($phones);
        $hasPage = $contactUrl !== null;
        $n = count($channels);
        $score = $n > 0 ? min(100, 70 + 15 * ($n - 1)) : ($hasPage ? 40 : 0);

        $this->notes['contacts'] = [
            'channels'    => array_keys($channels),
            'emails'      => array_values(array_map(function ($e) { return $e['email']; }, array_slice($emails, 0, 4))),
            'phones'      => array_values(array_slice($phones, 0, 4)),
            'telegram'    => $tg,
            'linkedin'    => $li,
            'form'        => $formOn['url'] ?? null,
            'contact_url' => $contactUrl,
            'note'        => 'особистий сайт: телефон і адреса не обов\'язкові',
        ];
        if ($channels) {
            $this->passed[] = 'Канали зв\'язку: ' . implode('; ', array_values($channels));
            $this->goods[] = 'канали зв\'язку';
        }
        if ($hasPage) $this->passed[] = 'Сторінка контактів: ' . $contactUrl;
        if ($n > 0) return $score;

        $this->gaps[] = 'каналу зв\'язку';
        $this->findings[] = $this->finding('contacts_missing', 'medium', $hasPage
            ? 'Сторінка контактів є, але без прямого каналу зв\'язку: email, Telegram, LinkedIn чи форми'
            : 'Немає прямого каналу зв\'язку: email, Telegram, LinkedIn чи форми', [
            'evidence' => 'Перевірено посилання mailto:, t.me, linkedin.com, форми й видимий текст на ' . $this->rolesList($sources) . '. Нічого з цього не знайдено' . ($hasPage ? '; є сторінка ' . $contactUrl : '') . '.' . $fetchNote . $this->jsNote,
            'why'      => 'Для особистого сайту телефон і адреса не обов\'язкові, але має бути хоча б один прямий канал зв\'язку з експертом. Це базовий сигнал, що за сайтом стоїть реальна людина, до якої можна звернутися.',
            'fix'      => 'Додайте у футер або на сторінку контактів email (посилання mailto:), Telegram або LinkedIn, чи просту форму зв\'язку.',
            'bad'      => 'Футер головної: «' . $this->footerExcerpt() . '»',
            'good'     => $this->brand . "\nEmail: " . ($this->facts['email'] ?? '[EMAIL]@' . $this->host) . "\nTelegram: https://t.me/[НІК]\nLinkedIn: https://www.linkedin.com/in/[ПРОФІЛЬ]/",
            'code'     => "<footer>\n  <a href=\"mailto:" . htmlspecialchars((string)($this->facts['email'] ?? '[EMAIL]@' . $this->host), ENT_QUOTES) . "\">Написати email</a>\n  <a href=\"https://t.me/[НІК]\" rel=\"me noopener\">Telegram</a>\n  <a href=\"https://www.linkedin.com/in/[ПРОФІЛЬ]/\" rel=\"me noopener\">LinkedIn</a>\n</footer>",
            'verify'   => 'curl -s ' . ($contactUrl ?? $this->base . '/') . ' | grep -E "mailto:|t.me/|linkedin.com" має знайти посилання.',
            'effort'   => 'S',
        ]);
        return $score;
    }

    /** Перша сторінка з формою зв'язку (поле email / телефону / textarea, не пошук і не підписка без тексту) або null. */
    private function contactFormPage(array $pages): ?array
    {
        foreach ($pages as $pg) {
            foreach ($pg['page']->query('//form') as $f) {
                /** @var DOMElement $f */
                $attrs = strtolower($f->getAttribute('role') . ' ' . $f->getAttribute('action') . ' ' . $f->getAttribute('class') . ' ' . $f->getAttribute('id'));
                if (preg_match('/search|poisk|login|signin|cart|checkout/', $attrs)) continue;
                $fields = $pg['page']->query('.//input[@type="email" or @type="tel" or contains(@name,"email") or contains(@name,"phone") or contains(@name,"tel")] | .//textarea', $f);
                if ($fields) return $pg;
            }
        }
        return null;
    }

    /** Чи є на перевірених сторінках форма, що збирає контактні дані. */
    private function siteHasForm(): bool
    {
        return $this->contactFormPage($this->pages) !== null;
    }

    /** Телефони сторінки: [digits => display]. */
    private function phonesOf(Page $p): array
    {
        $out = [];
        foreach ($p->query('//a[starts-with(translate(normalize-space(@href),"TEL","tel"),"tel:")]') as $a) {
            $raw = rawurldecode(trim(substr(trim($a->getAttribute('href')), 4)));
            $d = preg_replace('/\D/', '', $raw);
            if (strlen($d) < 3 || strlen($d) > 15) continue;
            $k = $this->phoneKey($d);
            if (!isset($out[$k])) $out[$k] = strlen($d) <= 6 ? $d . ' (короткий номер)' : $this->phoneDisplay($d, $raw);
        }
        $text = $this->textOf($p);
        foreach (self::RX_PHONES as $rx) {
            if (!preg_match_all($rx, $text, $mm)) continue;
            foreach ($mm[0] as $m) {
                $d = preg_replace('/\D/', '', $m);
                if (strlen($d) < 9 || strlen($d) > 15) continue;
                $k = $this->phoneKey($d);
                if (!isset($out[$k])) $out[$k] = trim($m);
                if (count($out) >= 8) break 2;
            }
        }
        return $out;
    }

    private function phoneKey(string $d): string
    {
        if (strlen($d) === 10 && $d[0] === '0') return '38' . $d;
        if (strlen($d) === 11 && substr($d, 0, 2) === '80') return '3' . $d;
        return $d;
    }

    private function phoneDisplay(string $d, string $raw): string
    {
        $k = $this->phoneKey($d);
        if (strlen($k) === 12 && substr($k, 0, 6) === '380800') {
            return '0 800 ' . substr($k, 6, 3) . ' ' . substr($k, 9, 3);
        }
        if (strlen($k) === 12 && substr($k, 0, 3) === '380') {
            return '+38 (' . substr($k, 2, 3) . ') ' . substr($k, 5, 3) . ' ' . substr($k, 8, 2) . ' ' . substr($k, 10, 2);
        }
        $raw = trim($raw);
        return $raw !== '' ? $raw : '+' . $d;
    }

    /** Email-и сторінки: [lower => ['email'=>..,'src'=>..]]. */
    private function emailsOf(Page $p): array
    {
        $cands = [];
        foreach ($p->query('//a[starts-with(translate(normalize-space(@href),"MAILTO","mailto"),"mailto:")]') as $a) {
            $v = rawurldecode(trim(substr(trim($a->getAttribute('href')), 7)));
            $v = trim(explode('?', $v)[0]);
            foreach (preg_split('/[,;]/', $v) as $e) $cands[] = [trim($e), 'mailto'];
        }
        // Cloudflare Email Obfuscation
        foreach ($p->query('//*[@data-cfemail]') as $n) {
            $e = self::cfDecode((string)$n->getAttribute('data-cfemail'));
            if ($e !== null) $cands[] = [$e, 'cf'];
        }
        foreach ($p->query('//a[contains(@href,"/cdn-cgi/l/email-protection#")]') as $n) {
            $href = (string)$n->getAttribute('href');
            $hp = strpos($href, '#');
            if ($hp === false) continue;
            $e = self::cfDecode(substr($href, $hp + 1));
            if ($e !== null) $cands[] = [$e, 'cf'];
        }
        if (preg_match_all(self::RX_EMAIL, $this->textOf($p), $mm)) {
            foreach ($mm[1] as $e) $cands[] = [$e, 'text'];
        }
        $out = [];
        foreach ($cands as [$e, $src]) {
            $e = trim($e, " \t\n\r\0\x0B.,;:<>()[]\"'");
            if (!preg_match('/^[a-z0-9][a-z0-9._%+-]{0,63}@(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/i', $e)) continue;
            $domain = strtolower(substr($e, strpos($e, '@') + 1));
            $tld = substr($domain, strrpos($domain, '.') + 1);
            if (preg_match(self::BAD_EMAIL_TLD, $tld) || preg_match(self::BAD_EMAIL_DOMAIN, $domain)) continue;
            $k = strtolower($e);
            if (!isset($out[$k])) $out[$k] = ['email' => $e, 'src' => $src];
            if (count($out) >= 6) break;
        }
        return $out;
    }

    private static function cfDecode(string $hex): ?string
    {
        $hex = trim($hex);
        if (!preg_match('/^[0-9a-f]{4,400}$/i', $hex) || strlen($hex) % 2 !== 0) return null;
        $key = hexdec(substr($hex, 0, 2));
        $out = '';
        for ($i = 2; $i < strlen($hex); $i += 2) $out .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
        return (strpos($out, '@') !== false && mb_check_encoding($out, 'UTF-8')) ? $out : null;
    }

    /** Фрагмент адреси з вулицею (точна цитата з тексту) або null. */
    private function addressOf(string $text): ?string
    {
        $i = $this->addressInfo($text);
        return $i !== null ? $i['addr'] : null;
    }

    /**
     * Адреса з вулицею + місто / область / район з найближчого контексту.
     * @return array{addr:string,city:?string,region:?string,district:?string}|null
     */
    private function addressInfo(string $text): ?array
    {
        foreach ([self::RX_STREET, self::RX_STREET_EN] as $rx) {
            if (!preg_match_all($rx, $text, $mm, PREG_OFFSET_CAPTURE)) continue;
            foreach (array_slice($mm[0], 0, 15) as $m) {
                $addr = $this->addressAt($text, (int)$m[1]);
                // санітарна перевірка: є номер будинку / індекс і адреса не обрізана до «вул. Б»
                if ($addr === null || mb_strlen($addr) < 10 || !preg_match('/\d/', $addr)) continue;
                $pos = mb_strlen(substr($text, 0, (int)$m[1]));
                $before = mb_substr($text, max(0, $pos - 160), min(160, $pos));
                $after = mb_substr($text, $pos + mb_strlen($addr), 80);
                $split = $this->splitAddress($addr);
                $city = $split['locality'];
                if ($city === null) {
                    // найближче до адреси згадане місто (у тексті перед адресою - останнє, після - перше)
                    $cb = $this->citiesIn($before);
                    $city = $cb ? end($cb) : (($ca = $this->citiesIn($after)) ? $ca[0] : null);
                }
                $region = preg_match_all('/(?<![\p{L}])(\p{Lu}[\p{Ll}\'’ʼ-]+(?:ська|цька|зька))\s+(?:область|обл\.)/u', $before . ' ' . $addr, $rm) ? end($rm[1]) . ' область' : null;
                $district = preg_match_all('/(?<![\p{L}])(\p{Lu}[\p{Ll}\'’ʼ-]+(?:ський|цький|зький))\s+(?:район|р-н)/u', $before . ' ' . $addr, $dm) ? end($dm[1]) . ' район' : null;
                if ($city === null && preg_match_all('/(?<![\p{L}])(?:с\.|село|смт|селище|м\.|місто)\s?(\p{Lu}[\p{Ll}\'’ʼ-]{2,})/u', $before, $sm)) $city = end($sm[1]);
                return ['addr' => $addr, 'city' => $city, 'region' => $region, 'district' => $district, 'postal' => $split['postal'], 'street' => $split['street']];
            }
        }
        return null;
    }

    /** Міста (називний) у тексті в порядку появи. */
    private function citiesIn(string $text): array
    {
        static $rx = null;
        if ($rx === null) $rx = '/(?<![\p{L}])(' . implode('|', array_map(function ($k) { return '(?:' . $k . ')'; }, array_keys(self::CITY_FORMS))) . ')(?![\p{L}-])/u';
        if (!preg_match_all($rx, $text, $mm)) return [];
        $out = [];
        foreach ($mm[1] as $form) {
            $n = $this->cityNominative($form);
            if ($n !== null) $out[] = $n;
        }
        return $out;
    }

    /** Форма міста («Києві», «Львова», «Kyiv») -> називний; null, якщо це не відоме місто. */
    private function cityNominative(string $s): ?string
    {
        $s = trim($s);
        foreach (self::CITY_FORMS as $rx => $nom) {
            if (preg_match('/^(?:' . $rx . ')$/u', $s)) return $nom;
        }
        return null;
    }

    /**
     * Розбір рядка адреси на індекс, місто й вулицю: «04080, Київ, Україна, вул. Оленівська, 23».
     * @return array{street:string,locality:?string,postal:?string}
     */
    private function splitAddress(string $addr): array
    {
        $postal = null; $locality = null; $street = [];
        foreach (preg_split('/\s*,\s*/u', trim($addr)) as $part) {
            $part = trim((string)preg_replace('/[\s,]*(?:Україна|Ukraine)$/u', '', trim($part)));
            if ($part === '') continue;
            if (preg_match('/^(\d{5})(?:\s+(.+))?$/u', $part, $m)) { $postal = $postal ?? $m[1]; $part = trim($m[2] ?? ''); if ($part === '') continue; }
            if (preg_match('/^(.+?)\s+(\d{5})$/u', $part, $m)) { $postal = $postal ?? $m[2]; $part = trim($m[1]); }
            if ($locality === null && preg_match('/^(?:м\.|місто|г\.)\s?(\p{Lu}[\p{Ll}\'’ʼ-]+(?:-\p{Lu}[\p{Ll}\'’ʼ-]+)?)$/u', $part, $m)) { $locality = $this->cityNominative($m[1]) ?? $m[1]; continue; }
            if ($locality === null && ($n = $this->cityNominative($part)) !== null) { $locality = $n; continue; }
            if (preg_match('/^(?:Україна|Ukraine)$/u', $part)) continue;
            $street[] = $part;
        }
        $st = implode(', ', $street);
        return ['street' => $st !== '' ? $st : $addr, 'locality' => $locality, 'postal' => $postal];
    }

    /** Адреса для коду / прикладів: вулиця + місто (або район / область) + індекс; без вигаданих частин. */
    private function fullAddress(): ?string
    {
        if (!$this->facts['address']) return null;
        $street = (string)($this->facts['street'] ?? $this->facts['address']);
        $parts = [$street];
        if ($this->facts['city'] && mb_stripos($street, (string)$this->facts['city']) === false) $parts[] = 'м. ' . $this->facts['city'];
        elseif (!$this->facts['city']) {
            if ($this->facts['district']) $parts[] = $this->facts['district'];
            if ($this->facts['region']) $parts[] = $this->facts['region'];
        }
        if ($this->facts['postal'] && strpos($street, (string)$this->facts['postal']) === false) $parts[] = $this->facts['postal'];
        return implode(', ', $parts);
    }

    /** Одна кандидатура адреси, що починається з вулиці на байтовій позиції $byteOff. */
    private function addressAt(string $text, int $byteOff): ?string
    {
        $pos = mb_strlen(substr($text, 0, $byteOff));
        $start = $pos;
        $before = mb_substr($text, max(0, $pos - 45), min(45, $pos));
        if (preg_match('/((?:(?:\d{5}|(?:м\.\s?)?\p{Lu}[\p{Ll}\'’ʼ-]{2,}),\s*){1,3})$/u', $before, $mm)) {
            $start = $pos - mb_strlen($mm[1]);
        }
        $lead = $pos - $start;                       // «Київ, » перед вулицею
        $snip = mb_substr($text, $start, 130);
        $minCut = strlen(mb_substr($snip, 0, $lead + 6));
        // обрізаємо на телефоні / графіку / email / копірайті / перемикачі мов
        if (preg_match('/\s(?:\+?\d[\d\s()-]{8,}|[Тт]ел\.?|[Тт]елефон|Пн|Вт|Графік|[Гг]рафік|[Ee]-?mail|©|Ⓒ|Copyright|Контакти|Працюємо|Режим)(?![\p{L}])|\s(?:UA|RU|EN|UK|УКР|РУС)(?=\s|\||$)|\s\|/u', $snip, $cut, PREG_OFFSET_CAPTURE, $minCut)) {
            $snip = substr($snip, 0, (int)$cut[0][1]);
        }
        // кінець речення («Приморська, 1. Публічна оферта»), крім скорочень (м. / буд. / оф. ...)
        if (preg_match_all('/\.\s*(?=\p{Lu})/u', $snip, $dots, PREG_OFFSET_CAPTURE)) {
            foreach ($dots[0] as $d) {
                $off = (int)$d[1];
                if ($off < $minCut) continue;
                $prev = preg_match('/([\p{L}-]+)$/u', substr($snip, 0, $off), $pw) ? mb_strtolower($pw[1]) : '';
                if (in_array($prev, ['м', 'вул', 'просп', 'пров', 'бульв', 'пл', 'буд', 'оф', 'кв', 'корп', 'обл', 'с', 'смт', 'г', 'ул', 'д', 'пр', 'р-н', 'наб', 'пер', 'ім', 'св', 'акад', 'ген', 'st', 'ave', 'rd', 'blvd'], true)) continue;
                if (mb_strlen($prev) === 1) continue;   // ініціал у назві вулиці: «вул. Б. Хмельницького», «просп. С. Бандери»
                $snip = substr($snip, 0, $off);
                break;
            }
        }
        // якщо є номер будинку - обрізаємо після нього (+ корпус / офіс / місто / індекс)
        $rxHouse = '/^(.*?\p{Lu}[\p{Ll}\'’ʼ-]{2,}.*?(?:,\s*|\s)(?:буд\.\s*)?\d{1,4}(?:[\/-]\d{1,4})?(?:-?[А-ЯҐЄІЇа-яґєіїA-Za-z](?![\p{L}]))?(?![\d\p{L}])(?:\s*\([^)]{1,40}\))?(?:,\s*(?:оф(?:іс)?\.?|кв\.?|корп(?:ус)?\.?|секція|поверх|прим\.|Suite|Floor)\s*\d+[\p{L}\d-]*)*(?:,\s*(?:м\.\s?)?\p{Lu}[\p{Ll}\'’ʼ-]+(?:\s+(?:обл\.|область))?)?(?:,?\s*(?:Україна|Ukraine))?(?:,?\s*\d{5})?)/u';
        if (preg_match($rxHouse, $snip, $hm) && mb_strlen($hm[1]) > $lead + 5) $snip = $hm[1];
        // повтор у сусідніх елементах: «просп. Миколи Бажана просп. Миколи Бажана, 12-А»
        $snip = (string)preg_replace('/^(.{6,60}?)\s+\1(?=[\s,]|$)/u', '$1', trim($snip));
        $snip = trim($snip);
        if (mb_strlen($snip) > 100) {
            $snip = mb_substr($snip, 0, 100);
            $sp = mb_strrpos($snip, ' ');
            if ($sp !== false && $sp > 60) $snip = mb_substr($snip, 0, $sp);
        }
        return rtrim($snip, " ,;:-");
    }

    private function cityOf(string $text): ?string
    {
        if (!preg_match(self::RX_CITY, $text, $m)) return null;
        return trim($m[1]);
    }

    private function cityFromAddress(string $addr): ?string
    {
        if ($addr === '') return null;
        $loc = $this->splitAddress($addr)['locality'];
        if ($loc !== null) return $loc;
        // «Київ, вул. ...» - місто перед вулицею; або «м. Київ» будь-де
        if (preg_match('/^(?:м\.\s?)?(\p{Lu}[\p{Ll}\'’ʼ-]{2,}),\s*(?i:вул|просп|пров|бульв|пл\.|площа|наб|шосе|ул\.)/u', $addr, $m)) return $this->cityNominative($m[1]) ?? $m[1];
        if (preg_match('/(?<![\p{L}])м\.\s?(\p{Lu}[\p{Ll}\'’ʼ-]{2,})/u', $addr, $m)) return $this->cityNominative($m[1]) ?? $m[1];
        return null;
    }

    /** Місто з розмітки PostalAddress (addressLocality). */
    private function schemaLocalityOf(Page $p): ?string
    {
        foreach ($p->schemaItemsOfType(['Organization', 'LocalBusiness', 'Place']) as $it) {
            $a = $it['data']['address'] ?? null;
            if (is_array($a) && isset($a[0]) && is_array($a[0])) $a = $a[0];
            if (is_array($a) && is_string($a['addressLocality'] ?? null) && trim($a['addressLocality']) !== '') {
                $l = Page::norm($a['addressLocality']);
                return $this->cityNominative((string)preg_replace('/^(?:м\.|місто)\s*/u', '', $l)) ?? $l;
            }
        }
        return null;
    }

    private function schemaAddressOf(Page $p): ?string
    {
        foreach ($p->schemaItemsOfType(['Organization', 'LocalBusiness', 'Place']) as $it) {
            $a = $it['data']['address'] ?? null;
            if (is_string($a) && trim($a) !== '' && preg_match('/\p{L}/u', $a)) return self::mbTrunc(Page::norm($a), 100);
            if (is_array($a)) {
                if (isset($a[0]) && is_array($a[0])) $a = $a[0];
                $street = is_string($a['streetAddress'] ?? null) ? trim($a['streetAddress']) : '';
                $loc = is_string($a['addressLocality'] ?? null) ? trim($a['addressLocality']) : '';
                if ($street !== '') return self::mbTrunc(Page::norm(trim($loc . ($loc !== '' ? ', ' : '') . $street)), 100);
            }
        }
        return null;
    }

    private function footerExcerpt(): string
    {
        $parts = [];
        foreach ($this->home->query('//footer | //*[@role="contentinfo"]') as $f) {
            $t = Page::norm($f->textContent);
            if ($t !== '') { $parts[] = $t; break; }
        }
        $t = $parts ? $parts[0] : $this->textOf($this->home);
        if ($t === '') return 'тексту немає';
        $t = preg_replace('/\s+/u', ' ', $t);
        return mb_strlen($t) > 220 ? '... ' . mb_substr($t, -220) : $t;
    }

    // ================================================================== 3. Юридичні сторінки

    private function evalLegal(): array
    {
        $found = [];
        foreach ($this->pages as $pg) {
            foreach ($pg['page']->links() as $l) {
                // сторонній домен приймаємо лише для політики / умов за анкором (сайт юрособи, напр. банку),
                // але не для сервісів на кшталт Google reCAPTCHA, платіжних систем, соцмереж
                $ext = !$this->sameOrg($l['href']);
                if ($ext && preg_match(self::RX_THIRD_PARTY, Crawler::registrableHost($l['href']))) continue;
                $anchor = mb_strtolower(trim($l['text']));
                $words = $anchor === '' ? 0 : count((array)preg_split('/\s+/u', $anchor));
                $anchorOk = $anchor !== '' && $words <= 8 && mb_strlen($anchor) <= 80 && substr($anchor, -1) !== '?';
                $path = strtolower(rawurldecode((string)parse_url($l['href'], PHP_URL_PATH)));
                foreach (self::LEGAL as $key => $rx) {
                    if (isset($found[$key]) && $found[$key]['sc'] >= 3) continue;
                    if ($ext && $key !== 'privacy' && $key !== 'terms') continue;
                    $byAnchor = $anchorOk && preg_match($rx['anchor'], $anchor);
                    $byPath = !$ext && $path !== '' && $path !== '/' && preg_match($rx['path'], $path) && ($anchor === '' || $anchorOk);
                    $sc = $ext ? ($byAnchor ? 1 : 0) : ($byAnchor ? 2 : 0) + ($byPath ? 1 : 0);
                    if ($sc > 0 && (!isset($found[$key]) || $sc > $found[$key]['sc'])) {
                        $found[$key] = ['url' => $l['href'], 'anchor' => trim($l['text']), 'sc' => $sc];
                    }
                }
            }
        }
        // посилання з анкором «Детальніше» / «PDF»: тип визначаємо за текстом батьківського блоку
        foreach ($this->pages as $pg) {
            $p = $pg['page'];
            foreach ($p->query('//a[@href]') as $a) {
                $at = mb_strtolower(Page::norm($a->textContent));
                if (!preg_match('/^(детальніше|докладніше|читати|читати більше|більше|переглянути|завантажити|відкрити|ознайомитися|pdf|learn more|read more|more|details|тут|here)$/u', $at)) continue;
                $href = Crawler::absolutize((string)$a->getAttribute('href'), $p->url());
                if ($href === null || !$this->sameOrg($href)) continue;
                $ctxText = '';
                for ($n = $a->parentNode, $i = 0; $n instanceof DOMElement && $i < 3; $n = $n->parentNode, $i++) {
                    $ctxText = mb_strtolower(Page::norm($n->textContent));
                    if (mb_strlen($ctxText) > mb_strlen($at) + 15) break;
                }
                if ($ctxText === '' || mb_strlen($ctxText) > 300) continue;
                foreach (self::LEGAL as $key => $rx) {
                    if (isset($found[$key]) && $found[$key]['sc'] >= 1.5) continue;
                    if (preg_match($rx['anchor'], $ctxText)) {
                        $label = trim(str_replace($at, '', $ctxText));
                        $found[$key] = ['url' => $href, 'anchor' => self::mbTrunc($label, 60) . ' (посилання «' . Page::norm($a->textContent) . '»)', 'sc' => 1.5];
                    }
                }
            }
        }
        if ($this->personal) return $this->legalPersonal($found);
        $keys = ['privacy', 'terms'];
        if ($this->type === 'ecommerce') $keys = ['privacy', 'terms', 'delivery', 'returns', 'warranty'];
        if ($this->type === 'media') $keys = ['privacy', 'terms', 'editorial'];
        $weights = [
            'ecommerce' => ['privacy' => 20, 'terms' => 25, 'delivery' => 30, 'returns' => 25, 'warranty' => 0],
            'media'     => ['privacy' => 35, 'terms' => 30, 'editorial' => 35],
        ][$this->type] ?? ['privacy' => 60, 'terms' => 40];
        $score = 0;
        foreach ($keys as $k) if (isset($found[$k])) $score += $weights[$k];
        $this->notes['legal'] = array_map(function ($f) { return $f['url']; }, array_intersect_key($found, array_flip($keys)));

        foreach ($keys as $k) {
            if (!isset($found[$k])) continue;
            $a = $found[$k]['anchor'] !== '' ? '«' . $this->cleanAnchor($found[$k]['anchor']) . '» ' : '';
            $this->passed[] = mb_strtoupper(mb_substr(self::LEGAL_LABELS[$k], 0, 1)) . mb_substr(self::LEGAL_LABELS[$k], 1) . ': ' . $a . $found[$k]['url'];
        }
        if (isset($found['privacy']) && isset($found['terms'])) $this->goods[] = 'політика конфіденційності й умови';

        $found = array_intersect_key($found, array_flip($keys));
        $seen = $this->legalSeen($found);
        // посилання на політику немає: можливо, сторінка існує, але на неї нічого не веде
        $privacyOrphan = isset($found['privacy']) ? null : $this->probePrivacy();
        if ($privacyOrphan !== null) $score = min(100, $score + (int)round($weights['privacy'] / 2));
        // базові: privacy / terms (+ редакційна політика для медіа)
        $missBase = []; $sev = null;
        $ord = ['low' => 1, 'medium' => 2, 'high' => 3];
        $bump = function (string $s) use (&$sev, $ord) { if ($sev === null || $ord[$s] > $ord[$sev]) $sev = $s; };
        // політика може бути розділом «Угоди користувача» / «Документів»: якщо такі сторінки є - знижуємо до low
        $privacyMaybeInside = !isset($found['privacy']) && $privacyOrphan === null && isset($found['terms']);
        $missAcc = [];
        if (!isset($found['privacy']) && $privacyOrphan === null) { $missBase[] = self::LEGAL_LABELS['privacy']; $missAcc[] = self::LEGAL_ACC['privacy']; $bump($privacyMaybeInside ? 'low' : 'medium'); }
        if (!isset($found['terms'])) {
            $missBase[] = $this->type === 'ecommerce' ? 'публічна оферта' : self::LEGAL_LABELS['terms'];
            $missAcc[] = $this->type === 'ecommerce' ? 'публічну оферту' : self::LEGAL_ACC['terms'];
            $bump(($this->type === 'ecommerce' || $this->ymyl !== null) ? 'medium' : 'low');
        }
        if ($this->type === 'media' && !isset($found['editorial'])) { $missBase[] = self::LEGAL_LABELS['editorial']; $missAcc[] = self::LEGAL_ACC['editorial']; $bump('medium'); }
        if ($missBase) {
            $gen = [];
            if (!isset($found['privacy']) && $privacyOrphan === null) $gen[] = 'політики конфіденційності';
            if (!isset($found['terms'])) $gen[] = $this->type === 'ecommerce' ? 'публічної оферти' : 'умов використання';
            if ($this->type === 'media' && !isset($found['editorial'])) $gen[] = 'редакційної політики';
            $this->gaps[] = implode(' і ', array_slice($gen, 0, 2));
            $hasForms = (bool)$this->home->query('//form//input[@type="email" or @type="tel" or contains(@name,"phone") or contains(@name,"email")]');
            $insideNote = $privacyMaybeInside
                ? ' Можливо, політика є всередині «' . $this->cleanAnchor($found['terms']['anchor'] !== '' ? $found['terms']['anchor'] : 'умов') . '» (' . $this->relPath($found['terms']['url']) . ') - автоматично не підтверджено; окремого посилання на неї немає.'
                : '';
            $this->findings[] = $this->finding('legal_missing', (string)$sev, 'Немає посилань на ' . implode(', ', $missAcc), [
                'evidence' => 'Перевірено посилання (меню, футер, текст) на ' . $this->rolesList($this->pages) . ': немає посилань з анкором або URL на ' . implode(', ', $missAcc) . '. Посилання на сторонні домени (наприклад, політики Google для reCAPTCHA) не враховувались.' . $insideNote . $this->jsNote,
                'why'      => 'Політика конфіденційності й умови використання - мінімальна юридична прозорість: відвідувач бачить, хто відповідає за сайт і як обробляє його дані, а це додає сайту довіри'
                    . ($this->type === 'media' ? '; для медіа редакційна політика і дані про власників - прямий сигнал відповідальності за контент.'
                        : ($hasForms ? '; крім того, на сайті є форми з телефоном / email, а збір персональних даних без політики суперечить Закону «Про захист персональних даних».' : '.')),
                'fix'      => $privacyMaybeInside && count($missBase) === 1
                    ? 'Винесіть політику конфіденційності на окрему сторінку (або якір) і дайте на неї пряме посилання у футері та біля кожної форми.'
                    : 'Створіть сторінки (' . implode(', ', $missBase) . ') і додайте посилання на них у футер усіх сторінок.',
                'bad'      => $seen,
                'good'     => $this->legalGood($keys, $found),
                'code'     => $this->legalFooterHtml($keys, $found),
                'verify'   => 'Перейдіть з футера головної на кожну юридичну сторінку: HTTP 200, без noindex, текст у вихідному HTML.',
                'effort'   => 'S',
            ]);
        }
        if ($privacyOrphan !== null) {
            $rel = $this->relPath($privacyOrphan);
            $this->passed[] = 'Сторінка політики конфіденційності існує: ' . $privacyOrphan . ' (але посилань на неї з сайту не знайдено)';
            $this->findings[] = $this->finding('privacy_unlinked', 'low', 'Політика конфіденційності є, але на неї не веде жодне посилання', [
                'evidence' => 'Сторінка ' . $privacyOrphan . ' відкривається (HTTP 200), але на ' . $this->rolesList($this->pages) . ' посилань на неї немає. Сторінку знайдено перебором типових адрес.',
                'why'      => 'Сторінку без посилань відвідувачі не знайдуть, а пошукові й AI-боти можуть її не просканувати: для них політики на сайті фактично немає. Крім того, Закон «Про захист персональних даних» вимагає повідомляти людей про обробку даних там, де дані збирають, тобто біля форм.',
                'fix'      => 'Додайте посилання на ' . $rel . ' у футер усіх сторінок і біля кожної форми з телефоном чи email.',
                'bad'      => 'Футер головної без посилання на ' . $rel . ': «' . $this->footerExcerpt() . '»',
                'good'     => "У футері всіх сторінок:\nПолітика конфіденційності - " . $rel . "\nБіля кожної форми: «Надсилаючи форму, ви погоджуєтеся з політикою конфіденційності»",
                'code'     => '<a href="' . htmlspecialchars($rel, ENT_QUOTES) . '">Політика конфіденційності</a>',
                'verify'   => 'Відкрийте головну, знайдіть у футері посилання «Політика конфіденційності» і перейдіть за ним: HTTP 200.',
                'effort'   => 'S',
            ]);
        }
        if ($this->type === 'ecommerce') {
            // гарантія доречна не для всіх товарів (їжа, напої), тому окремо не штрафуємо: лише додаємо до переліку, якщо бракує й інших сторінок
            $missShop = []; $sevShop = 'medium';
            foreach (['delivery', 'returns'] as $k) {
                if (!isset($found[$k])) $missShop[] = self::LEGAL_LABELS[$k];
            }
            if ($missShop && !isset($found['warranty'])) $missShop[] = self::LEGAL_LABELS['warranty'] . ' (якщо доречно для ваших товарів)';
            if ($missShop) {
                $this->gaps[] = 'сторінок «' . implode('», «', $missShop) . '»';
                $this->findings[] = $this->finding('shop_policies_missing', $sevShop, 'Немає сторінок для покупців: ' . implode(', ', $missShop), [
                    'evidence' => 'На ' . $this->rolesList($this->pages) . ' немає посилань на сторінки ' . implode(', ', array_map(function ($s) { return '«' . self::ucfirstMb($s) . '»'; }, $missShop)) . '.',
                    'why'      => 'На питання «чи безпечно купувати в ...», «як повернути товар у ...» AI-асистенти відповідають, спираючись на сторінки умов магазину. Немає сторінки - немає і відповіді на користь магазину.',
                    'fix'      => 'Створіть окремі сторінки з конкретикою (служби доставки, терміни, вартість, способи оплати, 14 днів на повернення за Законом «Про захист прав споживачів», гарантійні строки) і посилання у футер.',
                    'bad'      => $seen,
                    'good'     => $this->legalGood(['delivery', 'returns', 'warranty'], $found),
                    'code'     => $this->legalFooterHtml(['delivery', 'returns', 'warranty'], $found),
                    'verify'   => 'Запитайте ChatGPT / Perplexity: «Як повернути товар у ' . $this->brand . '?» - відповідь має посилатися на вашу сторінку.',
                    'effort'   => 'M',
                ]);
            }
        }
        return ['score' => max(0, min(100, $score)), 'found' => $found];
    }

    /**
     * Особистий сайт: умови використання не потрібні, політика конфіденційності - рекомендація лише за наявності форми.
     * Без форми й без політики - «н/д».
     */
    private function legalPersonal(array $found): array
    {
        $found = array_intersect_key($found, ['privacy' => 1, 'terms' => 1]);
        $this->notes['legal'] = array_map(function ($f) { return $f['url']; }, $found);
        foreach ($found as $k => $f) {
            $a = $f['anchor'] !== '' ? '«' . $this->cleanAnchor($f['anchor']) . '» ' : '';
            $this->passed[] = self::ucfirstMb(self::LEGAL_LABELS[$k]) . ': ' . $a . $f['url'];
        }
        if (isset($found['privacy'])) {
            $this->goods[] = 'політика конфіденційності';
            return ['score' => 100, 'found' => $found];
        }
        $form = $this->contactFormPage($this->pages);
        if ($form === null) {
            $this->notes['legal_note'] = 'н/д: форм, що збирають контактні дані, не знайдено - політика конфіденційності для особистого сайту не обов\'язкова';
            return ['score' => null, 'found' => $found];
        }
        $formWhere = $this->roleLabel($form['role'], 'loc') . ($form['role'] !== 'home' ? ' (' . $this->relPath($form['url']) . ')' : '');
        $orphan = $this->probePrivacy();
        if ($orphan !== null) {
            $rel = $this->relPath($orphan);
            $this->passed[] = 'Сторінка політики конфіденційності існує: ' . $orphan . ' (але посилань на неї з сайту не знайдено)';
            $this->findings[] = $this->finding('privacy_unlinked', 'low', 'Політика конфіденційності є, але на неї не веде жодне посилання', [
                'evidence' => 'Сторінка ' . $orphan . ' відкривається (HTTP 200), але на ' . $this->rolesList($this->pages) . ' посилань на неї немає. Форма зв\'язку є на ' . $formWhere . '.',
                'why'      => 'Форма збирає ім\'я, email чи телефон. Закон «Про захист персональних даних» вимагає повідомляти людей про обробку даних там, де їх збирають, тобто біля форми.',
                'fix'      => 'Додайте посилання на ' . $rel . ' біля форми і у футер.',
                'bad'      => 'Футер головної без посилання на ' . $rel . ': «' . $this->footerExcerpt() . '»',
                'good'     => "Біля форми: «Надсилаючи форму, ви погоджуєтеся з політикою конфіденційності» (посилання на " . $rel . ')',
                'code'     => '<a href="' . htmlspecialchars($rel, ENT_QUOTES) . '">Політика конфіденційності</a>',
                'verify'   => 'Відкрийте сторінку з формою і перейдіть за посиланням на політику: HTTP 200.',
                'effort'   => 'S',
            ]);
            return ['score' => 50, 'found' => $found];
        }
        $this->gaps[] = 'політики конфіденційності для форми';
        $this->findings[] = $this->finding('legal_missing', 'low', 'Немає політики конфіденційності, хоча сайт збирає дані через форму', [
            'evidence' => 'Форма з полями контактних даних є на ' . $formWhere . '. Посилань на політику конфіденційності на ' . $this->rolesList($this->pages) . ' немає, типові адреси (/privacy, /privacy-policy) не відкрились. Умови використання для особистого сайту не обов\'язкові й не перевірялись.',
            'why'      => 'Це рекомендація: форма збирає ім\'я, email чи телефон, а Закон «Про захист персональних даних» вимагає повідомляти людей, хто і навіщо обробляє їхні дані. Коротка політика також додає довіри до сайту.',
            'fix'      => 'Додайте коротку сторінку «Політика конфіденційності» (хто обробляє дані, які, навіщо, як видалити) і посилання на неї біля форми та у футері.',
            'bad'      => 'Форма є (' . $formWhere . '), посилання на політику немає',
            'good'     => "Біля форми: «Надсилаючи форму, ви погоджуєтеся з політикою конфіденційності»\nУ футері: Політика конфіденційності - /privacy-policy/",
            'code'     => '<p class="form-consent">Надсилаючи форму, ви погоджуєтеся з <a href="/privacy-policy/">політикою конфіденційності</a>.</p>',
            'verify'   => 'Відкрийте сторінку з формою: біля кнопки є посилання на політику, сторінка віддає HTTP 200.',
            'effort'   => 'S',
        ]);
        return ['score' => 0, 'found' => $found];
    }

    /** Сторінка політики конфіденційності за типовою адресою (коли посилань на неї немає) або null. */
    private function probePrivacy(): ?string
    {
        $homePath = (string)parse_url($this->home->url(), PHP_URL_PATH);
        $prefix = preg_match('#^/(ua|uk|en|ru)(/|$)#i', $homePath, $m) ? '/' . strtolower($m[1]) : '';
        $slugs = ['/privacy/', '/privacy-policy/', '/polityka-konfidentsiinosti/'];
        $paths = [];
        if ($prefix !== '') foreach ($slugs as $sl) $paths[] = $prefix . $sl;
        foreach ($slugs as $sl) $paths[] = $sl;
        $urls = array_map(function ($p) { return $this->base . $p; }, $paths);
        $this->extraFetched[] = 'probe:' . implode(',', $paths);
        $res = Crawler::fetchMulti($urls, $this->fetchMode, 8);
        foreach ($urls as $u) {
            $r = $res[$u] ?? null;
            if (!$r || (int)$r['status'] !== 200) continue;
            $final = (string)($r['final_url'] ?? $u);
            if (!$this->sameOrg($final) || $this->isHomeUrl($final)) continue;
            $p = Page::fromCrawl($r);
            if (!$p || $p->challenge() !== null) continue;
            $th = mb_strtolower($p->title() . ' ' . implode(' ', $p->headings()['h1']));
            if (!preg_match('/(конфіденц|конфиденц|персональн|privacy)/iu', $th)) continue;
            return $final;
        }
        return null;
    }

    private function legalSeen(array $found): string
    {
        $parts = [];
        $seenUrls = [];
        foreach ($found as $k => $f) {
            $parts[] = ($f['anchor'] !== '' ? '«' . $this->cleanAnchor($f['anchor']) . '»' : self::LEGAL_LABELS[$k]) . ' (' . $this->relPath($f['url']) . ')';
            $seenUrls[$f['url']] = true;
        }
        // інші «юридичні на вигляд» посилання, які не підпали під жоден тип (щоб було видно, що їх переглянуто)
        $other = [];
        $known = [];
        foreach (self::LEGAL as $rx) $known[] = $rx['anchor'];
        foreach ($this->home->links() as $l) {
            if (!$this->sameOrg($l['href']) || isset($seenUrls[$l['href']]) || $l['text'] === '' || mb_strlen($l['text']) > 50) continue;
            $low = mb_strtolower($l['text']);
            if (!preg_match('/(agreement|policy|polic|політик|угод|правил|умов|compliance|договір|оферт|terms|rules|legal|cookie)/iu', $low)) continue;
            if (preg_match('/^(політика|политика|politics|policy|правила|умови)$/u', trim($low))) continue;   // рубрика новин «Політика», а не документ
            $isKnown = false;
            foreach ($known as $rx) if (preg_match($rx, $low)) { $isKnown = true; break; }
            if ($isKnown) continue;
            $other[$l['text']] = '«' . $this->cleanAnchor($l['text']) . '» (' . $this->relPath($l['href']) . ')';
            if (count($other) >= 4) break;
        }
        if (!$parts && !$other) return 'Жодного юридичного посилання на сайті не знайдено. Футер головної: «' . $this->footerExcerpt() . '»';
        $out = $parts ? 'Юридичні / сервісні посилання, які є: ' . implode(', ', $parts) . '.' : 'Посилань на політику конфіденційності чи умови немає.';
        if ($other) $out .= ' Інші юридичні посилання (не зараховані як відсутні сторінки): ' . implode(', ', array_values($other)) . '.';
        return $out;
    }

    private function legalGood(array $keys, array $found): string
    {
        $slugs = ['privacy' => '/privacy-policy/', 'terms' => $this->type === 'ecommerce' ? '/publichna-oferta/' : '/terms/',
            'delivery' => '/dostavka-i-oplata/', 'returns' => '/obmin-i-povernennya/', 'warranty' => '/garantiya/', 'editorial' => '/editorial-policy/'];
        $names = ['privacy' => 'Політика конфіденційності', 'terms' => $this->type === 'ecommerce' ? 'Публічна оферта' : 'Умови використання',
            'delivery' => 'Доставка і оплата', 'returns' => 'Обмін і повернення', 'warranty' => 'Гарантія', 'editorial' => 'Редакційна політика'];
        $lines = [];
        foreach ($keys as $k) $lines[] = (isset($found[$k]) ? '[є] ' : '[додати] ') . $names[$k] . ' - ' . (isset($found[$k]) ? $this->relPath($found[$k]['url']) : $slugs[$k]);
        return "У футері всіх сторінок:\n" . implode("\n", $lines);
    }

    private function legalFooterHtml(array $keys, array $found): string
    {
        $slugs = ['privacy' => '/privacy-policy/', 'terms' => $this->type === 'ecommerce' ? '/publichna-oferta/' : '/terms/',
            'delivery' => '/dostavka-i-oplata/', 'returns' => '/obmin-i-povernennya/', 'warranty' => '/garantiya/', 'editorial' => '/editorial-policy/'];
        $names = ['privacy' => 'Політика конфіденційності', 'terms' => $this->type === 'ecommerce' ? 'Публічна оферта' : 'Умови використання',
            'delivery' => 'Доставка і оплата', 'returns' => 'Обмін і повернення', 'warranty' => 'Гарантія', 'editorial' => 'Редакційна політика'];
        $out = "<nav class=\"footer-legal\" aria-label=\"Юридична інформація\">\n";
        foreach ($keys as $k) {
            $href = isset($found[$k]) ? $this->relPath($found[$k]['url']) : $slugs[$k];
            $out .= '  <a href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . $names[$k] . "</a>\n";
        }
        return $out . '</nav>';
    }

    // ================================================================== 3b. Реквізити

    private function evalIdentity(array $legalFound): ?int
    {
        $texts = [];
        foreach ($this->pages as $pg) {
            if (!in_array($pg['role'], ['home', 'contact', 'about'], true) && !self::isFooterRole($pg['role'])) continue;
            if ($pg['role'] === 'home' && $this->type === 'media') {
                // у стрічці новин згадуються чужі ТОВ / АТ: назви юросіб беремо лише з шапки / футера
                $texts[] = [$pg['role'], $pg['url'], $this->textOf($pg['page']), false];
                $texts[] = [$pg['role'], $pg['url'], $this->chromeText($pg['page']), true];
            } else {
                $texts[] = [$pg['role'], $pg['url'], $this->textOf($pg['page']), true];
            }
        }
        [$level, $phrase, $where] = $this->identityIn($texts);

        if ($this->personal) {
            // особистому сайту реквізити не обов'язкові: якщо є - плюс, якщо немає - «н/д», без знахідки
            $this->notes['identity'] = ['found' => $phrase, 'where' => $where, 'note' => 'для особистого сайту експерта реквізити (ФОП, ТОВ) не обов\'язкові'];
            if ($level > 0) {
                $this->passed[] = 'Реквізити у видимому тексті: «' . $phrase . '» (' . $this->roleLabel((string)$where[0]) . ')';
                return $level;
            }
            return null;
        }

        $fetchedLegal = null;
        $unverified = null;   // документ, який міг містити реквізити, але не перевірений (PDF / не завантажився)
        $unverifiedFetch = false;
        if ($level < 100 && $this->uaSite) {
            foreach (['terms', 'privacy'] as $k) {
                if (!isset($legalFound[$k])) continue;
                $u = $legalFound[$k]['url'];
                if (preg_match('/\.(pdf|docx?)$/i', (string)parse_url($u, PHP_URL_PATH))) {
                    $unverified = $unverified ?? $u . ' (документ PDF / DOC не аналізувався)';
                    continue;
                }
                $f = $this->fetchPage($u);
                if ($f['page']) {
                    $fetchedLegal = $u;
                    [$l2, $p2, $w2] = $this->identityIn([[$k, $u, $this->textOf($f['page']), true]]);
                    if ($l2 > $level) { $level = $l2; $phrase = $p2; $where = $w2; }
                } else {
                    $unverified = $u . ' (не вдалося завантажити, HTTP ' . $f['status'] . ')';
                    $unverifiedFetch = true;
                }
                break;
            }
        }
        $roleNames = ['home' => 'головна', 'contact' => '«Контакти»', 'about' => '«Про нас»', 'terms' => 'умови / оферта', 'privacy' => 'політика конфіденційності'];
        $this->notes['identity'] = ['found' => $phrase, 'where' => $where, 'checked_legal' => $fetchedLegal, 'unverified' => $unverified];

        if ($level > 0) {
            $this->passed[] = 'Реквізити у видимому тексті: «' . $phrase . '» (' . ($roleNames[$where[0]] ?? $where[0]) . ')';
            if ($level >= 100) $this->goods[] = 'реквізити';
            if ($level < 100 && $unverified === null && $this->uaSite && in_array($this->type, ['ecommerce', 'services', 'local'], true)) {
                $isFop = (bool)preg_match('/^(ФОП|фізична)/iu', $phrase);
                $this->findings[] = $this->finding('identity_code', 'low', 'Назва ' . ($isFop ? 'ФОП' : 'юрособи') . ' є, але без коду ' . ($isFop ? '(номера запису в ЄДР)' : 'ЄДРПОУ'), [
                    'evidence' => 'Знайдено «' . $phrase . '» (' . ($roleNames[$where[0]] ?? $where[0]) . '); коду ЄДРПОУ або ІПН на головній, «Контактах», «Про нас»' . ($fetchedLegal ? ' і ' . $fetchedLegal : '') . ' немає.',
                    'why'      => 'Реєстраційний код однозначно пов\'язує сайт із записом у державному реєстрі - це найсильніший доказ, що за брендом стоїть реальний суб\'єкт господарювання.',
                    'fix'      => $isFop
                        ? 'Додайте поруч із назвою ФОП номер запису в ЄДР (або РНОКПП, якщо готові його публікувати) - у футер або на сторінку «Контакти».'
                        : 'Додайте код ЄДРПОУ поруч із назвою юрособи - у футер або на сторінку «Контакти».',
                    'bad'      => $phrase,
                    'good'     => $phrase . ($isFop ? ', номер запису в ЄДР [ВКАЖІТЬ НОМЕР]' : ', код ЄДРПОУ [ВКАЖІТЬ 8 ЦИФР]'),
                    'code'     => '',
                    'verify'   => 'Знайдіть код у ЄДР (usr.minjust.gov.ua) і перевірте, що назва збігається з указаною на сайті.',
                    'effort'   => 'S',
                ]);
            }
            return $level;
        }
        if (!$this->uaSite) {
            $this->notes['identity']['note'] = 'н/д: сайт не орієнтований на Україну, юрособу (Inc., LLC, Ltd ...) у тексті не знайдено';
            return null;
        }
        $sev = (in_array($this->type, ['ecommerce', 'services', 'local'], true) || $this->ymyl !== null) ? 'medium' : 'low';
        if ($unverified !== null) $sev = 'low';
        $this->gaps[] = 'реквізитів (ЄДРПОУ / ФОП)';
        $copy = preg_match('/©[^©]{0,90}/u', $this->textOf($this->home), $cm) ? trim($cm[0]) : '';
        $entity = $this->type === 'ecommerce' ? 'ФОП [ПРІЗВИЩЕ ІМ\'Я ПО БАТЬКОВІ] або ТОВ «[НАЗВА]»' : 'ТОВ «[НАЗВА ЮРОСОБИ]»';
        $this->findings[] = $this->finding('identity_missing', $sev, $unverified === null
            ? 'Не вказано юрособу: немає ЄДРПОУ, ФОП чи ТОВ'
            : ($unverifiedFetch
                ? 'Реквізитів (ЄДРПОУ, ФОП, ТОВ) немає на головній, «Контактах», «Про нас» (оферту перевірити не вдалося)'
                : 'Реквізитів (ЄДРПОУ, ФОП, ТОВ) немає на HTML-сторінках сайту'), [
            'evidence' => 'У видимому тексті ' . $this->rolesList(array_map(function ($t) { return ['role' => $t[0]]; }, $texts), 'gen') . ($fetchedLegal ? ' і на ' . $fetchedLegal : '') . ' немає коду ЄДРПОУ / ІПН, назви ФОП, ТОВ, ПП чи АТ.'
                . ($unverified !== null ? ' Не перевірено: ' . $unverified . ' - реквізити можуть бути там, автоматично не підтверджено.' : '') . $this->jsNote,
            'why'      => 'Реквізити підтверджують, що бренд - зареєстрована компанія. Для AI-систем це один із сигналів надійності, а для інтернет-торгівлі ще й вимога Закону «Про електронну комерцію» (ст. 7: найменування, місцезнаходження, ідентифікаційний код продавця).',
            'fix'      => 'Додайте у футер (або на «Контакти») повну назву ТОВ / ФОП і код ЄДРПОУ (для ФОП - номер запису в ЄДР)' . ($unverified !== null ? ', а не лише в документі оферти.' : '.'),
            'bad'      => $copy !== '' ? 'Футер: «' . $copy . '»' : 'Футер головної: «' . $this->footerExcerpt() . '»',
            'good'     => $entity . ', код ЄДРПОУ / номер запису в ЄДР [НОМЕР]' . ($this->fullAddress() !== null ? ', ' . $this->fullAddress() : ', [ЮРИДИЧНА АДРЕСА]'),
            'code'     => "<p class=\"footer-legal-entity\">\n  © " . date('Y') . ' ' . htmlspecialchars($this->brand, ENT_NOQUOTES) . ' - ' . htmlspecialchars($entity, ENT_NOQUOTES) . ",\n  код ЄДРПОУ / номер запису в ЄДР [НОМЕР]" . ($this->fullAddress() !== null ? ', ' . htmlspecialchars((string)$this->fullAddress(), ENT_NOQUOTES) : '') . "\n</p>",
            'verify'   => 'Перевірте код у ЄДР (usr.minjust.gov.ua): назва і адреса мають збігатися із сайтом.',
            'effort'   => 'S',
        ]);
        return 0;
    }

    /** @return array{0:int,1:string,2:array} [рівень 0|70|100, фраза, [роль, url]] */
    private function identityIn(array $texts): array
    {
        $bestLevel = 0; $bestPhrase = ''; $bestWhere = [];
        foreach ($texts as [$role, $url, $t, $namesOk]) {
            if ($this->uaSite) {
                foreach (self::RX_ID_CODE as $rx) {
                    if (preg_match($rx, $t, $m)) return [100, self::mbTrunc(Page::norm($m[0]), 70), [$role, $url]];
                }
                if ($bestLevel < 70 && $namesOk) {
                    foreach (self::RX_ID_NAME as $rx) {
                        if (preg_match($rx, $t, $m)) { $bestLevel = 70; $bestPhrase = self::mbTrunc(Page::norm($m[0]), 70); $bestWhere = [$role, $url]; break; }
                    }
                }
            }
            // Inc. / LLC / Ltd: лише якщо назва збігається з брендом (інакше це може бути згадка чужої компанії)
            if ($bestLevel < 70 && $namesOk && preg_match_all(self::RX_ID_INTL, $t, $mm)) {
                foreach ($mm[0] as $cand) {
                    if ($this->isBrandName(preg_replace('/,?\s+(LLC|L\.L\.C\.|Inc\.|Ltd\.?|Limited|GmbH|Corp\.|s\.r\.o\.|sp\.\s?z\s?o\.o\.|B\.V\.|LLP|OÜ)$/u', '', $cand))) {
                        $bestLevel = 70; $bestPhrase = self::mbTrunc(Page::norm($cand), 70); $bestWhere = [$role, $url]; break;
                    }
                }
            }
        }
        return [$bestLevel, $bestPhrase, $bestWhere];
    }

    // ================================================================== 4. Авторство

    private function evalAuthorship(): ?int
    {
        $sample = $this->samples['article'] ?? null;
        $isMedia = $this->type === 'media';
        $ap = ($sample && ($sample['page'] ?? null) instanceof Page) ? $sample['page'] : null;
        $url = $sample ? (string)($sample['res']['final_url'] ?? $sample['url']) : '';
        $sourceNote = '';
        $listingUrl = null;
        // у вибірку замість статті потрапила сторінка-список (стрічка новин, рубрика): перевіряємо статтю з неї
        if ($ap !== null && $this->isListingPage($ap)) {
            $listingUrl = $url;
            $art = $this->articleFromListing($ap);
            if ($art !== null) {
                $ap = $art['page'];
                $url = $art['url'];
                $sourceNote = ' (у вибірці була сторінка-список ' . $listingUrl . ', тому перевірено першу статтю з неї)';
            } else {
                $ap = null;
            }
        }
        // статті у вибірці немає: беремо сторінку вибірки з розміткою статті (NewsArticle / BlogPosting)
        if ($ap === null) {
            foreach ($this->pages as $pg) {
                if (in_array($pg['role'], ['home', 'about', 'contact', 'article'], true) || self::isFooterRole($pg['role'])) continue;
                $p = $pg['page'];
                $isArt = $isMedia ? $p->hasSchemaType(['Article']) : $p->hasSchemaType(['NewsArticle', 'BlogPosting']);
                if (!$isArt || count($p->schemaItemsOfType(['Article'])) > 2 || $this->isListingPage($p)) continue;
                $ap = $p;
                $url = $pg['url'];
                $sourceNote = ' (сторінка вибірки з розміткою ' . $this->firstType($p, ['NewsArticle', 'BlogPosting', 'Article']) . ')';
                break;
            }
        }
        if ($ap === null && $sample === null && !$isMedia) {
            $this->notes['authorship'] = 'н/д: у вибірці немає статті';
            return null;
        }
        if ($ap === null && $listingUrl !== null && !$isMedia) {
            $this->notes['authorship'] = 'н/д: у вибірці лише сторінка-список ' . $listingUrl . ', статтю з неї завантажити не вдалося';
            return null;
        }
        if ($ap === null) {
            $status = $sample ? (int)($sample['res']['status'] ?? 0) : null;
            $homeAuthors = $this->homeAuthorLinks();
            $why = $listingUrl !== null
                ? 'у вибірці замість статті була сторінка-список ' . $listingUrl . ', а статтю з неї завантажити не вдалося'
                : ($sample ? 'стаття ' . $sample['url'] . ' не віддалася боту (HTTP ' . $status . ')' : 'у вибірці немає статті');
            $this->notes['authorship'] = ['article' => $sample['url'] ?? null, 'status' => $status, 'listing' => $listingUrl, 'home_authors' => array_slice($homeAuthors, 0, 6)];
            if ($isMedia && count($homeAuthors) >= 2) {
                $this->passed[] = 'На головній біля матеріалів вказані автори з посиланнями на їхні сторінки: ' . self::listSome(array_keys($homeAuthors), 4);
                $this->goods[] = 'автори матеріалів';
                $this->notes['authorship']['note'] = 'оцінено за головною: ' . $why;
                return 70;
            }
            if ($isMedia) {
                $this->findings[] = $this->finding('article_unverified', 'low', 'Авторство статей перевірити не вдалося', [
                    'evidence' => self::ucfirstMb($why) . '; на головній посилань на сторінки авторів (/author/...) біля матеріалів не знайдено.',
                    'why'      => 'Для медіа підпис автора з біографією - важливий сигнал E-E-A-T, а дата публікації показує актуальність; без доступу до статті оцінка була б вигаданою.',
                    'fix'      => 'Переконайтеся, що статті віддаються ботам (HTTP 200), і повторіть аудит.',
                    'bad'      => $listingUrl !== null ? 'У вибірці: ' . $listingUrl . ' - сторінка-список, не стаття' : ($sample ? 'GET ' . $sample['url'] . ' -> HTTP ' . $status : 'Статтю для перевірки не знайдено'),
                    'good'     => 'Стаття віддає HTTP 200, має підпис автора з посиланням на його сторінку, дату публікації та оновлення.',
                    'code'     => '',
                    'verify'   => 'curl -A "OAI-SearchBot" -I <URL статті> має повернути 200.',
                    'effort'   => 'S',
                ]);
            }
            return null;
        }

        $a = $this->analyzeArticle($ap, $url);
        $isNews = $this->isNewsPost($ap, $url, $a);
        // новини / оголошення на YMYL-сайті (графік, закриття філії) - без вимоги експерта-рецензента
        $this->ymylArt = $this->ymyl !== null && ($isMedia || !$isNews);
        $di = $this->dateIssues($ap, $url, $a);
        $pubOk = $a['published'] !== null && !isset($di['future']);
        $modOk = $a['modified'] !== null && !isset($di['order']) && !isset($di['fresh']);
        $this->notes['authorship'] = [
            'article' => $url, 'source_note' => $sourceNote !== '' ? trim($sourceNote, ' ()') : null, 'author' => $a['name'], 'author_src' => $a['name_src'], 'org_author' => $a['org'],
            'generic_author' => $a['generic'], 'author_url' => $a['author_url'], 'person_schema' => $a['person_schema'], 'published' => $a['published'],
            'modified' => $a['modified'], 'date_issues' => array_keys($di), 'news_post' => $isNews, 'credentials' => $a['cred'], 'reviewer' => $a['reviewer'], 'authority_links' => $a['auth_links'],
        ];
        $strictSev = $isMedia || $this->ymylArt;
        // для коду не переносимо суперечливі дати
        $aCode = $a;
        if (!$pubOk) $aCode['published'] = null;
        if (!$modOk) $aCode['modified'] = null;

        $pts = 0;
        if ($a['name'] !== null && $a['name_src'] !== 'meta') $pts += 40;
        elseif ($a['name'] !== null) $pts += 20;
        elseif ($a['org'] !== null) $pts += 15;
        if ($a['author_url'] !== null) $pts += 15;
        if ($a['person_schema']) $pts += 10;
        if ($a['person_linked']) $pts += 5;
        if ($pubOk) $pts += 12;
        if ($modOk) $pts += 8;
        if ($a['cred'] !== null || $a['reviewer'] !== null) $pts += 10;
        if ($a['generic'] !== null) $pts = min($pts, 30);

        // passed
        if ($a['name'] !== null) {
            $this->passed[] = 'Автор статті: ' . $a['name'] . ($a['author_url'] ? ' (сторінка автора: ' . $a['author_url'] . ')' : '') . ($a['person_schema'] ? ', Person у розмітці' : '') . $sourceNote;
            $this->goods[] = 'автор статті';
        }
        if ($a['cred'] !== null) $this->passed[] = 'Кваліфікація автора: «' . $a['cred'] . '»';
        if ($a['reviewer'] !== null) $this->passed[] = 'Експертна перевірка: «' . $a['reviewer'] . '»';
        if ($pubOk || $modOk) {
            $this->passed[] = 'Дати статті: ' . trim(($pubOk ? 'опубліковано ' . $a['published'] : '') . ($modOk ? ($pubOk ? ', ' : '') . 'оновлено ' . $a['modified'] : ''));
        }

        $headline = $a['headline'] !== '' ? $a['headline'] : $ap->title();
        $schemaNow = $this->schemaNow($ap, $a);
        if ($di) $this->datesFinding($di, $url, $a);
        if ($a['generic'] !== null) {
            $this->gaps[] = 'справжнього автора статей';
            $this->findings[] = $this->finding('author_generic', $strictSev ? 'high' : 'medium', 'Автором статті вказано «' . $a['generic'] . '»', [
                'evidence' => 'Стаття ' . $url . ': автором вказано технічний акаунт «' . $a['generic'] . '» (' . ($a['generic_src'] === 'schema' ? 'author у розмітці ' . ($a['art_type'] !== '' ? $a['art_type'] : 'Article') . ($a['generic_type'] !== '' ? ', @type ' . $a['generic_type'] : '') : 'видимий підпис') . '), а не людину з ім\'ям; іншого автора на сторінці немає.' . ($a['org'] !== null ? ' Організація в розмітці: «' . $a['org'] . '».' : ''),
                'why'      => 'Технічний акаунт замість людини - прямий сигнал, що за текстом немає відповідального експерта. AI-системи не можуть пов\'язати такий текст із чиєюсь експертизою.',
                'fix'      => 'Замініть технічний акаунт на справжнього автора: ім\'я, посада, коротке біо, сторінка автора; відобразіть це в розмітці author.',
                'bad'      => $schemaNow,
                'good'     => 'Автор: [ІМ\'Я ПРІЗВИЩЕ], [ПОСАДА / СПЕЦІАЛІЗАЦІЯ], [N] років досвіду - посилання на /author/[slug]/',
                'code'     => $this->articleJsonLd($ap, $url, $aCode),
                'verify'   => 'validator.schema.org: author має тип Person з name і url; на сторінці видно підпис автора.',
                'effort'   => 'M',
            ]);
        } elseif ($a['name'] === null) {
            $sev = $strictSev ? 'high' : 'medium';
            // медіа, де частина матеріалів підписана (автори з посиланнями на головній): проблема лише в непідписаних новинах
            $homeAuthors = $isMedia ? $this->homeAuthorLinks() : [];
            $partNote = '';
            if (count($homeAuthors) >= 2) {
                $sev = 'medium';
                $partNote = ' Водночас на головній частина матеріалів має авторів із посиланнями на їхні сторінки (' . self::listSome(array_keys($homeAuthors), 3) . '), тож проблема стосується непідписаних новин.';
            }
            $this->gaps[] = 'автора статей';
            $ymylExtra = $this->ymylArt ? ' Для ' . $this->ymylLabel('gen') . ' додайте також блок «Перевірено експертом» (ПІБ і кваліфікація фахівця) і посилання на першоджерела (дослідження, нормативні акти, офіційні настанови).' : '';
            $this->findings[] = $this->finding('author_missing', $sev, 'Стаття без автора-людини' . ($this->ymylArt ? ' і без експертної перевірки' : ''), [
                'evidence' => 'Стаття ' . $url . $sourceNote . ': ' . $this->authorEvidence($a) . ($this->ymylArt ? ' YMYL-тематика: ' . $this->ymylLabel('short') . ' (' . $this->ymylSignal . ').' : '') . $partNote,
                'why'      => $this->ymylArt
                    ? 'У ' . $this->ymylLabel() . ' Google надає ще більшої ваги текстам із сильним E-E-A-T, а настанови для оцінювачів вимагають, щоб частину порад давали саме фахівці. Порада без названого фахівця виглядає менш надійною і для пошуку, і для AI-систем.'
                    : 'Без названого автора AI-системи не можуть пов\'язати текст з чиєюсь експертизою і частіше обирають джерела, де автор і його досвід вказані явно.',
                'fix'      => 'Підписуйте статті реальним автором: ім\'я, посада / спеціалізація, посилання на сторінку автора з біо, Person у розмітці Article.' . $ymylExtra,
                'bad'      => $schemaNow,
                'good'     => $this->bylineGood($headline, $aCode),
                'code'     => $this->articleJsonLd($ap, $url, $aCode),
                'verify'   => 'validator.schema.org і Google Rich Results Test для ' . $url . ': author типу Person з name і url' . ($this->ymylArt && $this->ymyl === 'medical' ? ', reviewedBy у MedicalWebPage' : '') . '.',
                'effort'   => 'M',
            ]);
        } elseif ($a['author_url'] === null && !$a['person_linked']) {
            $this->findings[] = $this->finding('author_profile', $strictSev ? 'medium' : 'low', 'Автор вказаний, але без сторінки автора і профілів', [
                'evidence' => 'Стаття ' . $url . ': автор «' . $a['name'] . '» (' . $this->nameSrcLabel($a['name_src']) . '), але немає посилання на сторінку автора' . ($a['person_schema'] ? ', а в Person немає url / sameAs.' : ' і Person-розмітки з url / sameAs.'),
                'why'      => 'Сторінка автора з біо і посиланнями на профілі (LinkedIn, публікації) дає AI-системам змогу перевірити, що автор - реальний фахівець, і пов\'язати всі його тексти між собою.',
                'fix'      => 'Зробіть ім\'я автора посиланням на сторінку /author/[slug]/ з біо, досвідом, фото і sameAs-посиланнями; додайте url і sameAs у Person.',
                'bad'      => 'Автор: ' . $a['name'] . ' - простий текст без посилання',
                'good'     => '<a href="/author/[slug]/" rel="author">' . htmlspecialchars($a['name'], ENT_NOQUOTES) . '</a>, [ПОСАДА / СПЕЦІАЛІЗАЦІЯ]',
                'code'     => $this->articleJsonLd($ap, $url, $aCode),
                'verify'   => 'validator.schema.org: у author є url і sameAs; сторінка автора відкривається з HTTP 200.',
                'effort'   => 'M',
            ]);
        }

        if ($a['published'] === null && $a['modified'] === null) {
            $this->gaps[] = 'дат публікації';
            $this->findings[] = $this->finding('dates_missing', $isMedia ? 'high' : 'medium', 'Стаття без дати публікації та оновлення', [
                'evidence' => 'Стаття ' . $url . ': немає datePublished / dateModified у розмітці, meta article:published_time, тегу <time> і видимої дати біля заголовка.',
                'why'      => 'Без дати AI-системи не можуть оцінити актуальність тексту і для запитів, де важлива свіжість, обирають датовані джерела конкурентів.',
                'fix'      => 'Показуйте біля заголовка «Опубліковано» і «Оновлено» (тег <time datetime>) і дублюйте дати в розмітці Article.',
                'bad'      => 'Біля заголовка «' . self::mbTrunc($headline, 90) . '» дати немає',
                'good'     => '<p class="meta">Опубліковано <time datetime="[РРРР-ММ-ДД]">[ДАТА]</time> - оновлено <time datetime="[РРРР-ММ-ДД]">[ДАТА]</time></p>',
                'code'     => $this->articleJsonLd($ap, $url, $aCode),
                'verify'   => 'Вихідний код статті (Ctrl+U): є <time datetime>; Rich Results Test показує Article з datePublished і dateModified.',
                'effort'   => 'S',
            ]);
        }

        if ($this->ymylArt && $a['name'] !== null && $a['generic'] === null) {
            $noExpert = $a['cred'] === null && $a['reviewer'] === null;
            $noSources = $a['auth_links'] === 0;
            if ($noExpert || $noSources) {
                $what = [];
                if ($noExpert) $what[] = 'кваліфікації автора чи блоку «Перевірено експертом»';
                if ($noSources) $what[] = 'посилань на авторитетні джерела';
                $this->findings[] = $this->finding('ymyl_expert', $noExpert ? 'medium' : 'low', 'YMYL-стаття без ' . implode(' і ', $what), [
                    'evidence' => 'Стаття ' . $url . ' (YMYL: ' . $this->ymylLabel('short') . '): автор «' . $a['name'] . '»' . ($noExpert ? ', кваліфікації (лікар, к.м.н., адвокат, фінансовий аналітик ...) поруч з іменем і в розмітці немає, рецензента немає' : '') . ($noSources ? '; зовнішніх посилань на gov / edu / наукові джерела в тексті - 0' : '') . '.',
                    'why'      => 'Для тем здоров\'я, грошей і права AI-системи віддають перевагу текстам, де видно кваліфікацію автора або експерта-рецензента і є посилання на першоджерела.',
                    'fix'      => 'Додайте під заголовком кваліфікацію автора і блок «Перевірено експертом: [ПІБ], [спеціальність, стаж]», а в тексті - 2-3 посилання на першоджерела (МОЗ, НБУ, закон, дослідження).',
                    'bad'      => 'Автор: ' . $a['name'] . ($a['cred'] === null ? ' (без кваліфікації)' : ' (' . $a['cred'] . ')') . '; рецензента немає',
                    'good'     => 'Автор: ' . $a['name'] . ', [КВАЛІФІКАЦІЯ]. Перевірено експертом: [ПІБ], [СПЕЦІАЛЬНІСТЬ, СТАЖ]. Джерела: [МОЗ / НБУ / ДОСЛІДЖЕННЯ]',
                    'code'     => $this->articleJsonLd($ap, $url, $aCode),
                    'verify'   => 'validator.schema.org: є reviewedBy (MedicalWebPage / WebPage) з Person; у тексті є посилання на першоджерела.',
                    'effort'   => 'M',
                ]);
            }
        }
        return max(0, min(100, $pts));
    }

    private function analyzeArticle(Page $ap, string $url): array
    {
        $items = $ap->schemaItems();
        $arts = $ap->schemaItemsOfType(['Article']);
        $authors = []; $dp = null; $dm = null; $reviewer = null; $headline = ''; $artType = '';
        foreach ($arts as $it) {
            $d = $it['data'];
            if ($artType === '') $artType = (string)($it['types'][0] ?? '');
            if (isset($d['author'])) foreach ($this->resolveAuthors($d['author'], $items) as $x) $authors[] = $x;
            if ($dp === null && is_string($d['datePublished'] ?? null) && trim($d['datePublished']) !== '') $dp = trim($d['datePublished']);
            if ($dm === null && is_string($d['dateModified'] ?? null) && trim($d['dateModified']) !== '') $dm = trim($d['dateModified']);
            if ($headline === '' && is_string($d['headline'] ?? null)) $headline = Page::norm($d['headline']);
        }
        foreach ($ap->schemaItemsOfType(['WebPage']) as $it) {
            $d = $it['data'];
            if (!$authors && isset($d['author'])) foreach ($this->resolveAuthors($d['author'], $items) as $x) $authors[] = $x;
            if ($reviewer === null && isset($d['reviewedBy'])) {
                $r = $this->resolveAuthors($d['reviewedBy'], $items);
                if ($r) $reviewer = 'reviewedBy у розмітці' . ($r[0]['name'] !== '' ? ': ' . $r[0]['name'] : '');
            }
            if ($dp === null && is_string($d['datePublished'] ?? null) && trim($d['datePublished']) !== '') $dp = trim($d['datePublished']);
            if ($dm === null && is_string($d['dateModified'] ?? null) && trim($d['dateModified']) !== '') $dm = trim($d['dateModified']);
            if ($dm === null && is_string($d['lastReviewed'] ?? null) && trim($d['lastReviewed']) !== '') $dm = trim($d['lastReviewed']);
        }
        $meta = $ap->metaTags();
        if ($dp === null) foreach (['article:published_time', 'og:published_time', 'og:publish_date', 'datepublished', 'date', 'dc.date', 'publish-date'] as $k) if (!empty($meta[$k])) { $dp = $meta[$k]; break; }
        if ($dm === null) foreach (['article:modified_time', 'og:updated_time', 'datemodified', 'last-modified'] as $k) if (!empty($meta[$k])) { $dm = $meta[$k]; break; }

        $name = null; $nameSrc = null; $org = null; $authorUrl = null; $personSchema = false; $personLinked = false; $credText = ''; $generic = null;
        $genericSrc = null; $genericType = ''; $orgType = '';
        foreach ($authors as $x) {
            // технічний акаунт перевіряємо першим: «Администратор МС "Добробут"» містить бренд, але це не організація
            if ($x['name'] !== '' && preg_match(self::RX_GENERIC_AUTHOR, $x['name'])) {
                if ($generic === null) { $generic = $x['name']; $genericSrc = 'schema'; $genericType = $x['types'] ? (string)$x['types'][0] : ''; }
                continue;
            }
            if ($x['type'] === 'Organization' || ($x['name'] !== '' && $this->isBrandName($x['name']))) {
                if ($org === null && $x['name'] !== '') { $org = $x['name']; $orgType = $x['type']; }
                continue;
            }
            if ($x['name'] === '') continue;
            if ($x['type'] === 'Person' || $this->looksLikeName($x['name'])) {
                if ($name === null) { $name = $x['name']; $nameSrc = 'schema'; }
                if ($x['type'] === 'Person') $personSchema = true;
                if ($x['url'] !== '' || $x['sameAs']) $personLinked = true;
                if ($x['url'] !== '' && $authorUrl === null && preg_match('#^https?://#i', $x['url']) && !$this->isHomeUrl($x['url'])) $authorUrl = $x['url'];
                $credText .= ' ' . $x['job'];
            }
        }
        // підпис у DOM
        $bylineTexts = [];
        $xp = '//body//*[(contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"author") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"byline") or contains(translate(@itemprop,"AUTHOR","author"),"author") or contains(translate(@rel,"AUTHOR","author"),"author"))'
            . ' and not(contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"authoriz")) and not(contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"authent"))'
            . ' and not(ancestor-or-self::*[contains(translate(@class,"COMMENT","comment"),"comment")]) and not(ancestor::footer) and not(ancestor::nav) and not(ancestor::script) and not(ancestor::template) and not(ancestor::noscript)]';
        foreach (array_slice($ap->query($xp), 0, 12) as $n) {
            $t = Page::norm($n->textContent);
            if ($t === '' || mb_strlen($t) > 140) continue;
            $bylineTexts[] = $t;
            $clean = $this->stripBylinePrefix($t);
            if (preg_match(self::RX_GENERIC_AUTHOR, $clean)) { if ($generic === null && $name === null) { $generic = $clean; $genericSrc = 'byline'; } continue; }
            $nm = $this->nameFrom($clean);
            if ($nm !== null && !$this->isBrandName($nm) && $name === null) { $name = $nm; $nameSrc = 'byline'; }
        }
        $text = $this->textOf($ap);
        if ($name === null && preg_match(self::RX_BYLINE_TEXT, $text, $m)) {
            if (!$this->isBrandName($m[1])) { $name = trim($m[1]); $nameSrc = 'text'; }
        }
        // посилання на сторінку автора
        foreach ($ap->links() as $l) {
            if (!$l['internal'] || $l['in_nav']) continue;
            $isRel = strpos($l['rel'], 'author') !== false;
            $pathOk = (bool)preg_match(self::RX_AUTHOR_PATH, (string)parse_url($l['href'], PHP_URL_PATH));
            if (!$isRel && !$pathOk) continue;
            $lt = trim($l['text']);
            if ($name !== null && $lt !== '' && mb_strtolower($lt) === mb_strtolower($name)) { $authorUrl = $authorUrl ?? $l['href']; break; }
            if ($isRel && $authorUrl === null) $authorUrl = $l['href'];
            if ($name === null && $pathOk) {
                $nm = $this->nameFrom($lt);
                if ($nm !== null && !$this->isBrandName($nm)) { $name = $nm; $nameSrc = 'link'; $authorUrl = $l['href']; break; }
            }
        }
        if ($name === null && !empty($meta['author']) && $this->looksLikeName($meta['author']) && !$this->isBrandName($meta['author'])) { $name = Page::norm($meta['author']); $nameSrc = 'meta'; }
        if ($name !== null) $generic = null;

        // кваліфікація: підпис, Person, оточення імені
        $credPool = trim($credText . ' ' . implode(' | ', $bylineTexts));
        if ($name !== null) {
            $pos = mb_strpos($text, $name);
            if ($pos !== false) $credPool .= ' ' . mb_substr($text, max(0, $pos - 60), mb_strlen($name) + 200);
        }
        $cred = null;
        if ($credPool !== '' && (preg_match(self::RX_CRED_EXPERT, $credPool, $cm) || (!$this->ymyl && preg_match(self::RX_CRED_ROLE, $credPool, $cm)))) $cred = self::mbTrunc(Page::norm($cm[0]), 60);
        if ($reviewer === null && preg_match(self::RX_REVIEWER, $text, $rm, PREG_OFFSET_CAPTURE)) {
            $reviewer = self::mbTrunc(Page::norm(substr($text, (int)$rm[0][1], 90)), 70);
        }
        // видимі дати
        $visDate = null;
        $scope = mb_substr($ap->mainText(), 0, 1500);
        $h1 = $ap->headings()['h1'][0] ?? '';
        if ($h1 !== '' && ($hp = mb_strpos($text, $h1)) !== false) $scope .= ' ' . mb_substr($text, max(0, $hp - 300), mb_strlen($h1) + 600);
        if (preg_match(self::RX_DATE_VIS, $scope, $dmv)) $visDate = $dmv[0];
        $hasTime = (bool)$ap->query('//body//time[@datetime][not(ancestor::footer)]');
        if ($dp === null && $hasTime) {
            $t = $ap->query('//body//time[@datetime][not(ancestor::footer)]');
            $dp = trim((string)$t[0]->getAttribute('datetime'));
        }
        if ($dp === null && $visDate !== null) $dp = $visDate;
        if ($dm === null && preg_match(self::RX_UPDATED_VIS, $scope, $um, PREG_OFFSET_CAPTURE)) {
            $dm = self::mbTrunc(Page::norm(substr($scope, (int)$um[0][1], 40)), 40);
        }
        // джерела (для YMYL)
        $authLinks = 0;
        foreach ($ap->externalLinks() as $l) {
            if ($l['in_nav']) continue;
            $hp2 = strtolower((string)parse_url($l['href'], PHP_URL_HOST)) . (string)parse_url($l['href'], PHP_URL_PATH);
            if (preg_match(self::RX_AUTHORITY, $hp2)) $authLinks++;
        }
        return [
            'name' => $name, 'name_src' => $nameSrc, 'org' => $org, 'author_url' => $authorUrl, 'person_schema' => $personSchema,
            'person_linked' => $personLinked, 'cred' => $cred, 'reviewer' => $reviewer, 'published' => $dp !== null ? ($this->isoDate($dp) ?? self::mbTrunc($dp, 40)) : null,
            'modified' => $dm !== null ? ($this->isoDate($dm) ?? self::mbTrunc($dm, 40)) : null, 'auth_links' => $authLinks, 'generic' => $generic,
            'generic_src' => $genericSrc, 'generic_type' => $genericType, 'org_type' => $orgType,
            'headline' => $headline, 'art_type' => $artType, 'authors_raw' => $authors, 'byline_texts' => array_slice($bylineTexts, 0, 3),
            'meta_author' => $meta['author'] ?? null, 'published_raw' => $dp, 'modified_raw' => $dm,
        ];
    }

    // ------------------------------------------------------------------ стаття: список чи матеріал, новина, дати

    /** Сторінка-список (стрічка новин, рубрика, блог) замість окремого матеріалу. */
    private function isListingPage(Page $p): bool
    {
        $arts = count($p->schemaItemsOfType(['Article']));
        if ($arts >= 1 && $arts <= 2) return false;
        if ($arts === 0 && mb_strtolower((string)$p->og('type')) === 'article') return false;
        $teasers = []; $linkWords = 0;
        foreach ($p->links() as $l) {
            if (!$l['internal'] || $l['in_nav'] || isset($teasers[$l['href']])) continue;
            $w = $p->wordCount($l['text']);
            if ($w < 5) continue;
            $teasers[$l['href']] = true;
            $linkWords += $w;
        }
        if (count($teasers) < 8) return false;
        $h1 = $p->headings()['h1'];
        $h1w = $h1 ? $p->wordCount($h1[0]) : 0;
        $main = max(1, $p->wordCount($p->mainText()));
        return count($h1) !== 1 || $h1w <= 3 || $linkWords >= 0.8 * $main;
    }

    /** Перша стаття зі сторінки-списку (до 2 запитів). */
    private function articleFromListing(Page $lp): ?array
    {
        $self = rtrim(explode('#', $lp->url())[0], '/');
        $cands = [];
        foreach ($lp->links() as $l) {
            if (!$l['internal'] || $l['in_nav'] || !$this->sameOrg($l['href'])) continue;
            if ($lp->wordCount($l['text']) < 5) continue;
            $h = rtrim(explode('#', $l['href'])[0], '/');
            if ($h === $self || $this->isHomeUrl($l['href']) || preg_match('#[?&](?:page|p)=\d|/page/\d+#i', $l['href'])) continue;
            $cands[$h] = $l['href'];
            if (count($cands) >= 2) break;
        }
        foreach ($cands as $u) {
            $f = $this->fetchPage($u);
            $p = $f['page'];
            if ($p !== null && !$this->isListingPage($p) && $p->wordCount($p->mainText()) >= 120) return ['url' => $p->url(), 'page' => $p];
        }
        return null;
    }

    /** Новина / оголошення (а не експертна стаття): тип NewsArticle з коротким текстом або розділ новин в URL. */
    private function isNewsPost(Page $ap, string $url, array $a): bool
    {
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        if (preg_match('#/(?:news|novyny|novini|novosti|anons\w*|announcements?|press|press-?releases?|events?|podii|aktsii|akcii|promo)(?:/|$)#', $path)) return true;
        return $a['art_type'] === 'NewsArticle' && $ap->wordCount($ap->mainText()) < 400;
    }

    /** Unix-час для машинної дати ISO 8601 (з розмітки / meta / time); null для інших форматів. */
    private function parseTs(?string $s): ?int
    {
        if ($s === null || !preg_match('/^\s*(?:19|20)\d{2}-\d{2}-\d{2}/', $s)) return null;
        $t = strtotime(trim($s));
        return $t === false ? null : $t;
    }

    /** datePublished / dateModified з розмітки статті та meta (для повторної перевірки). */
    private function machineDates(Page $p): array
    {
        $dp = null; $dm = null;
        foreach (array_merge($p->schemaItemsOfType(['Article']), $p->schemaItemsOfType(['WebPage'])) as $it) {
            $d = $it['data'];
            if ($dp === null && is_string($d['datePublished'] ?? null) && trim($d['datePublished']) !== '') $dp = trim($d['datePublished']);
            if ($dm === null && is_string($d['dateModified'] ?? null) && trim($d['dateModified']) !== '') $dm = trim($d['dateModified']);
        }
        $meta = $p->metaTags();
        if ($dm === null) foreach (['article:modified_time', 'og:updated_time', 'datemodified', 'last-modified'] as $k) if (!empty($meta[$k])) { $dm = $meta[$k]; break; }
        if ($dp === null) foreach (['article:published_time', 'og:published_time', 'datepublished'] as $k) if (!empty($meta[$k])) { $dp = $meta[$k]; break; }
        return [$dp, $dm];
    }

    /** Момент завантаження сторінки (заголовок Date) або поточний час. */
    private function fetchTime(Page $p): int
    {
        $d = $p->headers()['date'] ?? null;
        if (is_array($d)) $d = end($d);
        $t = is_string($d) ? strtotime($d) : false;
        return $t !== false ? (int)$t : time();
    }

    private static function kyivTime(int $ts): string
    {
        try {
            $tz = in_array('Europe/Kyiv', DateTimeZone::listIdentifiers(), true) ? 'Europe/Kyiv' : 'Europe/Kiev';
            return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i');
        } catch (Throwable $e) {
            return gmdate('Y-m-d H:i', $ts) . ' UTC';
        }
    }

    /**
     * Недостовірні дати: datePublished у майбутньому, dateModified раніше за datePublished,
     * dateModified = час запиту (підтверджується повторним запитом).
     */
    private function dateIssues(Page $ap, string $url, array $a): array
    {
        $now = $this->fetchTime($ap);
        $tp = $this->parseTs($a['published_raw']);
        $tm = $this->parseTs($a['modified_raw']);
        $out = [];
        if ($tp !== null && $tp > $now + 6 * 3600) $out['future'] = ['now' => $now];
        elseif ($tp !== null && $tm !== null && $tm < $tp - 3600) $out['order'] = ['now' => $now];
        if ($tm !== null && !isset($out['order']) && preg_match('/T\d{1,2}:\d{2}/', (string)$a['modified_raw'])
            && abs($now - $tm) <= 900 && ($tp === null || $tp < $now - 2 * 86400)) {
            $f = $this->fetchPage($url);
            if ($f['page'] !== null) {
                [, $dm2] = $this->machineDates($f['page']);
                $tm2 = $this->parseTs($dm2);
                $now2 = $this->fetchTime($f['page']);
                if ($tm2 !== null && $tm2 > $tm && abs($now2 - $tm2) <= 900) {
                    $out['fresh'] = ['first' => (string)$a['modified_raw'], 'second' => (string)$dm2];
                }
            }
        }
        return $out;
    }

    private function datesFinding(array $di, string $url, array $a): void
    {
        $pub = (string)$a['published_raw'];
        $mod = (string)$a['modified_raw'];
        $ev = [];
        if (isset($di['future'])) {
            $ev[] = 'datePublished «' . $pub . '» пізніше за момент перевірки (' . self::kyivTime($di['future']['now']) . ' за Києвом)'
                . ($this->parseTs($mod) !== null && $this->parseTs($mod) < (int)$this->parseTs($pub) ? ', а dateModified «' . $mod . '» - раніше за дату публікації' : '');
        }
        if (isset($di['order'])) $ev[] = 'dateModified «' . $mod . '» раніше за datePublished «' . $pub . '»';
        if (isset($di['fresh'])) {
            $ev[] = 'dateModified збігається з часом запиту і змінюється з кожним завантаженням: «' . $di['fresh']['first'] . '», а при повторному запиті - «' . $di['fresh']['second'] . '»'
                . ($pub !== '' ? ' (datePublished - «' . $pub . '»)' : '');
        }
        $title = isset($di['fresh']) ? 'Дата оновлення статті підставляється автоматично при кожному запиті'
            : (isset($di['future']) ? 'Дата публікації статті в розмітці - у майбутньому' : 'Дата оновлення статті раніше за дату публікації');
        $bad = '"datePublished": "' . $pub . '",' . "\n" . '"dateModified": "' . $mod . '"';
        if (isset($di['fresh'])) $bad .= "\n" . 'повторний запит: "dateModified": "' . $di['fresh']['second'] . '"';
        $good = '"datePublished": "' . (isset($di['future']) || $pub === '' ? '[ДАТА ПЕРШОЇ ПУБЛІКАЦІЇ]' : $pub) . '",' . "\n"
            . '"dateModified": "[ДАТА ОСТАННЬОЇ СУТТЄВОЇ ПРАВКИ З CMS, НЕ РАНІШЕ ЗА datePublished]"';
        $this->findings[] = $this->finding('dates_suspicious', 'low', $title, [
            'evidence' => 'Стаття ' . $url . ': ' . implode('; ', $ev) . '.',
            'why'      => 'Google радить змінювати дату лише після суттєвого оновлення тексту. Дати з майбутнього Google радить не вказувати. Штучна «свіжість» вводить в оману щодо актуальності матеріалу, і пошукові та AI-системи можуть перестати довіряти датам на сайті.',
            'fix'      => 'Беріть datePublished з дати першої публікації, а dateModified - з дати останньої реальної правки в CMS (не з поточного часу); dateModified не може бути раніше за datePublished.',
            'bad'      => $bad,
            'good'     => $good,
            'code'     => '',
            'verify'   => 'Відкрийте статтю двічі з інтервалом у хвилину (curl -s ' . $url . ' | grep -o "dateModified[^,]*") - значення має бути однаковим; у Rich Results Test datePublished не пізніше за dateModified і не в майбутньому.',
            'effort'   => 'S',
        ]);
    }

    private function resolveAuthors($v, array $items, int $depth = 0): array
    {
        if ($depth > 3 || $v === null) return [];
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '') return [];
            if (preg_match('#^https?://#i', $v)) return [['type' => '?', 'name' => '', 'url' => $v, 'sameAs' => [], 'job' => '', 'types' => []]];
            return [['type' => '?', 'name' => Page::norm($v), 'url' => '', 'sameAs' => [], 'job' => '', 'types' => []]];
        }
        if (!is_array($v)) return [];
        if ($v !== [] && array_keys($v) === range(0, count($v) - 1)) {
            $out = [];
            foreach ($v as $x) $out = array_merge($out, $this->resolveAuthors($x, $items, $depth + 1));
            return $out;
        }
        if (!isset($v['name']) && isset($v['@id']) && is_string($v['@id'])) {
            foreach ($items as $it) {
                if (($it['data']['@id'] ?? null) === $v['@id'] && isset($it['data']['name'])) { $v = $it['data'] + $v; break; }
            }
        }
        $types = [];
        foreach ((array)($v['@type'] ?? []) as $t) if (is_string($t) && ($st = Page::shortType($t)) !== '') $types[] = $st;
        $type = '?';
        $personSet = Page::expandTypes(['Person']);
        $orgSet = Page::expandTypes(['Organization']);
        foreach ($types as $t) {
            if (isset($personSet[strtolower($t)])) { $type = 'Person'; break; }
            if (isset($orgSet[strtolower($t)])) $type = 'Organization';
        }
        $name = $v['name'] ?? '';
        if (is_array($name)) {
            $first = '';
            foreach ($name as $nv) { if (is_string($nv) && trim($nv) !== '') { $first = $nv; break; } if (is_array($nv) && is_string($nv['@value'] ?? null)) { $first = $nv['@value']; break; } }
            $name = $first;
        }
        $sameAs = array_values(array_filter((array)($v['sameAs'] ?? []), function ($s) { return is_string($s) && trim($s) !== ''; }));
        $job = [];
        foreach (['jobTitle', 'description', 'honorificPrefix', 'honorificSuffix', 'hasCredential', 'knowsAbout'] as $k) {
            if (is_string($v[$k] ?? null)) $job[] = $v[$k];
        }
        return [[
            'type' => $type, 'name' => Page::norm((string)$name), 'url' => is_string($v['url'] ?? null) ? trim($v['url']) : '',
            'sameAs' => $sameAs, 'job' => implode(' ', $job), 'types' => $types,
        ]];
    }

    private function stripBylinePrefix(string $t): string
    {
        return trim((string)preg_replace('/^(?:[Аа]втор(?:ка)?(?: статті| матеріалу| тексту)?|AUTHOR|Author|[Bb]y|Written by|Posted by|[Тт]екст|[Оо]публіковано|[Пп]ублікує)\s*[:\-–]?\s*/u', '', $t));
    }

    private function nameFrom(string $t): ?string
    {
        $t = $this->stripBylinePrefix($t);
        if (preg_match('/^(\p{Lu}[\p{Ll}\'’ʼ-]+(?:\s+\p{Lu}[\p{Ll}\'’ʼ.-]*){1,3})(?![\p{L}])/u', $t, $m)) {
            $n = trim($m[1]);
            if (preg_match('/(?:Головна|Блог|Новини|Статті|Читати|Детальніше|Про нас|Контакти|Home|Blog|News|Read more)/u', $n)) return null;
            return $n;
        }
        return null;
    }

    private function looksLikeName(string $s): bool
    {
        $s = Page::norm($s);
        return mb_strlen($s) <= 60 && (bool)preg_match('/^\p{Lu}[\p{Ll}\'’ʼ-]+(?:\s+\p{Lu}[\p{Ll}\'’ʼ.-]*){1,3}$/u', $s);
    }

    private function isBrandName(string $name): bool
    {
        $k = $this->normKey($name);
        if ($k === '') return false;
        $b = $this->normKey($this->brand);
        $h = $this->normKey(SiteProfile::hostLabel($this->url !== '' ? $this->url : $this->base));
        foreach ([$b, $h] as $x) {
            if ($x === '' || strlen($x) < 3) continue;
            if (strpos($k, $x) !== false || strpos($x, $k) !== false) return true;
        }
        if (preg_match('/^(редакція|редакция|editorial|команда|team)(\s|$)/iu', $name)) return true;
        return false;
    }

    private function normKey(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'e', 'ж' => 'zh', 'з' => 'z',
            'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
            'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh',
            'ь' => '', 'ю' => 'u', 'я' => 'a', 'ы' => 'y', 'э' => 'e', 'ё' => 'e', 'ъ' => ''];
        $s = strtr($s, $map);
        $s = (string)preg_replace('/[^a-z0-9]/', '', $s);
        return strtr($s, ['ks' => 'x', 'kh' => 'h', 'ck' => 'k', 'y' => 'i', 'c' => 'k', 'w' => 'v']);
    }

    /** Автори з посиланнями на сторінки авторів на головній (для медіа): [name => href]. */
    private function homeAuthorLinks(): array
    {
        $out = [];
        foreach ($this->home->links() as $l) {
            if (!$l['internal'] || $l['in_nav']) continue;
            if (!preg_match('~/(?:author|authors|avtor|avtory|autor|blogger|bloggers)/[^/?#]+~i', (string)parse_url($l['href'], PHP_URL_PATH))) continue;
            $n = $this->nameFrom(trim($l['text']));
            if ($n === null || $this->isBrandName($n)) continue;
            if (!isset($out[$n])) $out[$n] = $l['href'];
        }
        return $out;
    }

    private function authorEvidence(array $a): string
    {
        $parts = [];
        $parts[] = $a['byline_texts']
            ? 'видимий підпис - «' . self::mbTrunc($a['byline_texts'][0], 60) . '» (не ім\'я людини)'
            : 'підпису автора у видимому тексті немає';
        if ($a['org'] !== null) $parts[] = 'у розмітці ' . ($a['art_type'] !== '' ? $a['art_type'] : 'Article') . ' автором вказано '
            . (($a['org_type'] ?? '') === 'Person' ? 'назву бренду «' . $a['org'] . '» (з типом Person), а не людину' : 'організацію «' . $a['org'] . '»');
        elseif ($a['art_type'] !== '') $parts[] = 'у розмітці ' . $a['art_type'] . ' немає author-людини';
        else $parts[] = 'розмітки Article немає';
        if (!empty($a['meta_author'])) $parts[] = 'meta author = «' . self::mbTrunc((string)$a['meta_author'], 40) . '»';
        $parts[] = 'посилання на сторінку автора немає';
        return implode('; ', $parts) . '.';
    }

    private function schemaNow(Page $ap, array $a): string
    {
        $lines = [];
        foreach ($a['authors_raw'] as $x) {
            $lines[] = '"author": ' . json_encode(['@type' => $x['types'] ? $x['types'][0] : '?', 'name' => $x['name']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (count($lines) >= 2) break;
        }
        if (!$lines) $lines[] = 'author у розмітці: немає';
        if (!empty($a['meta_author'])) $lines[] = '<meta name="author" content="' . self::mbTrunc((string)$a['meta_author'], 40) . '">';
        $types = $ap->schemaTypes();
        $lines[] = 'Типи розмітки на сторінці: ' . ($types ? self::listSome($types, 6) : 'немає');
        $lines[] = 'Видимий підпис автора: ' . ($a['byline_texts'] ? '«' . self::mbTrunc($a['byline_texts'][0], 60) . '»' : 'немає');
        return implode("\n", $lines);
    }

    private function nameSrcLabel(?string $src): string
    {
        return ['schema' => 'з розмітки', 'byline' => 'з підпису на сторінці', 'text' => 'з тексту «Автор: ...»', 'link' => 'з посилання на сторінку автора', 'meta' => 'лише з meta author'][$src ?? ''] ?? 'знайдено';
    }

    // ================================================================== 5. Відгуки

    private function evalReviews(): ?int
    {
        if ($this->type === 'media') {
            // відгуки клієнтів для видання не застосовні; репутацію медіа показують редакційна політика й автори
            $this->notes['reviews'] = 'н/д: медіа';
            return null;
        }
        $visible = []; $schema = null; $platforms = []; $widget = null;
        foreach ($this->pages as $pg) {
            $p = $pg['page'];
            foreach (['h2', 'h3', 'h4', 'h1'] as $h) {
                foreach ($p->headings()[$h] as $t) {
                    if ($t === '' || mb_strlen($t) > 80 || preg_match('/^(залиш|напиш|додати|оставь|write|leave)/iu', $t)) continue;
                    if ($this->type === 'media' && preg_match('/огляд/iu', $t)) continue;
                    if (preg_match(self::RX_REVIEWS_HEAD, $t)) { $visible['heading'] = $visible['heading'] ?? 'заголовок «' . self::mbTrunc($t, 50) . '»'; break 2; }
                }
            }
            $text = $this->textOf($p);
            if (!isset($visible['count']) && preg_match_all(self::RX_REVIEW_COUNT, $text, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $n = (int)preg_replace('/\D/', '', $m[1]);
                    if ($n > 0) { $visible['count'] = '«' . self::mbTrunc(Page::norm($m[0]), 40) . '»'; break; }
                }
            }
            if (!isset($visible['rating']) && preg_match(self::RX_RATING, $text, $rm)) $visible['rating'] = '«' . $rm[0] . '»';
            foreach ($p->internalLinks() as $l) {
                if (isset($visible['link'])) break;
                $a = mb_strtolower(trim($l['text']));
                $path = rtrim(strtolower((string)parse_url($l['href'], PHP_URL_PATH)), '/') . '/';
                $anchorHit = $a !== '' && preg_match(self::RX_REVIEWS_ANCHOR, $a);
                $pathHit = preg_match(self::RX_REVIEWS_PATH, $path) && $this->type !== 'media' && !preg_match('/огляд|review of/iu', $a);
                if ($anchorHit || $pathHit) $visible['link'] = 'посилання «' . ($l['text'] !== '' ? self::mbTrunc($l['text'], 40) : $path) . '» (' . $this->relPath($l['href']) . ')';
            }
            if ($schema === null) {
                foreach ($p->schemaItems() as $it) {
                    $isAgg = in_array('AggregateRating', $it['types'], true);
                    $d = $isAgg ? $it['data'] : (is_array($it['data']['aggregateRating'] ?? null) ? $it['data']['aggregateRating'] : null);
                    if ($d !== null) {
                        $rv = $d['ratingValue'] ?? null; $rc = $d['reviewCount'] ?? ($d['ratingCount'] ?? null);
                        $schema = 'AggregateRating' . (is_scalar($rv) ? ' ' . $rv : '') . (is_scalar($rc) ? ' (' . $rc . ' оцінок)' : '') . ($pg['role'] !== 'home' ? ' на ' . $this->roleLabel($pg['role'], 'loc') : '');
                        break;
                    }
                    if (in_array('Review', $it['types'], true) || array_intersect($it['types'], ['UserReview', 'CriticReview'])) { $schema = 'Review' . ($pg['role'] !== 'home' ? ' на ' . $this->roleLabel($pg['role'], 'loc') : ''); break; }
                }
            }
            foreach ($p->externalLinks() as $l) {
                $hp = strtolower((string)parse_url($l['href'], PHP_URL_HOST)) . (string)parse_url($l['href'], PHP_URL_PATH) . '?' . (string)parse_url($l['href'], PHP_URL_QUERY);
                foreach (self::REVIEW_PLATFORMS as $name => $rx) {
                    if (!isset($platforms[$name]) && preg_match($rx, $hp)) $platforms[$name] = $l['href'];
                }
            }
            foreach ($p->iframes() as $src) {
                if (preg_match('#widget\.clutch\.co#i', $src) && !isset($platforms['Clutch'])) $platforms['Clutch'] = $src;
                if (preg_match('#(google\.[a-z.]+/maps|maps\.google\.)#i', $src) && !isset($platforms['Google Maps'])) $platforms['Google Maps'] = $src;
            }
            if ($widget === null) {
                foreach ($p->scripts()['external'] as $s) {
                    if (preg_match('#(elfsight|trustindex|reviews\.io|embedsocial|featurable|widget\.trustpilot)#i', $s, $wm)) { $widget = $wm[1]; break; }
                }
            }
        }
        $apps = array_intersect_key($platforms, ['App Store' => 1, 'Google Play' => 1]);
        $realPlatforms = array_diff_key($platforms, $apps);
        if ($this->personal) {
            // особистий сайт: відгуки - один із маркерів довіри (оцінюються разом з кейсами, публікаціями, виступами)
            $hits = array_values($visible);
            if ($schema !== null) $hits[] = 'розмітка ' . $schema;
            if ($realPlatforms) $hits[] = 'посилання на ' . implode(', ', array_keys($realPlatforms));
            $this->reviewHits = $hits;
            $this->notes['reviews'] = 'н/д: для особистого сайту відгуки враховано в маркерах довіри' . ($hits ? ' (' . implode('; ', array_slice($hits, 0, 3)) . ')' : '');
            return null;
        }
        $scoreVis = $visible ? 40 : ($widget !== null ? 15 : 0);
        $scoreSchema = $schema !== null ? 30 : 0;
        $scorePlat = $realPlatforms ? 30 : ($apps ? 15 : 0);
        $score = $scoreVis + $scoreSchema + $scorePlat;
        $this->notes['reviews'] = ['visible' => array_values($visible), 'schema' => $schema, 'platforms' => array_keys($platforms), 'widget' => $widget];

        if ($visible) $this->passed[] = 'Відгуки на сайті: ' . implode(', ', array_slice(array_values($visible), 0, 3));
        if ($schema !== null) $this->passed[] = 'Розмітка відгуків: ' . $schema;
        if ($platforms) $this->passed[] = 'Посилання на зовнішні майданчики відгуків / рейтингів: ' . implode(', ', array_keys($platforms));
        if ($visible) $this->goods[] = 'відгуки на сайті';
        elseif ($realPlatforms) $this->goods[] = 'посилання на відгуки в ' . array_key_first($realPlatforms);

        $commercial = in_array($this->type, ['ecommerce', 'local', 'services'], true);
        if (!$visible && $schema === null && !$platforms) {
            $this->gaps[] = 'відгуків';
            $this->findings[] = $this->finding('reviews_missing', $commercial ? 'medium' : 'low', 'Не знайдено відгуків і посилань на зовнішні рейтинги', [
                'evidence' => 'На ' . $this->rolesList($this->pages) . ': немає блоку чи сторінки відгуків і розмітки AggregateRating / Review; посилань на перевірені майданчики відгуків (Google Maps, Hotline, Clutch, Trustpilot, Tripadvisor, Booking, Untappd, Prom.ua, Rozetka, Doc.ua, Helsi, Facebook-відгуки тощо) не знайдено.' . ($widget !== null ? ' Є скрипт віджета відгуків (' . $widget . '), але відгуки підвантажуються JavaScript-ом і в HTML їх немає.' : '') . $this->jsNote,
                'why'      => 'Відгуки клієнтів - репутаційні дані: настанови Google для оцінювачів радять враховувати їх, коли оцінюють довіру (Trust) до бізнесу. AI-асистенти у відповідях на кшталт «чи варто звертатися до ...» часто наводять відгуки з сайту і зовнішніх майданчиків.',
                'fix'      => $this->type === 'ecommerce'
                    ? 'Увімкніть відгуки на картках товарів (з розміткою AggregateRating у Product) і додайте посилання на профіль магазину на Hotline / Google.'
                    : 'Додайте блок 3-5 реальних відгуків (ім\'я, дата, текст) і посилання на профіль у Google Maps' . ($this->type === 'services' ? ', Clutch або GoodFirms' : '') . ', де клієнти можуть залишити відгук.',
                'bad'      => 'Відгуків у HTML немає' . ($widget !== null ? ' (лише JS-віджет ' . $widget . ')' : '') . '.',
                'good'     => $this->reviewsGood(),
                'code'     => $this->reviewsHtml(),
                'verify'   => 'Вихідний код (Ctrl+U) містить тексти відгуків; ' . ($this->type === 'ecommerce' ? 'Rich Results Test для картки товару показує AggregateRating.' : 'посилання на Google Maps відкриває профіль компанії з відгуками.'),
                'effort'   => 'M',
            ]);
        } elseif (!$visible && $schema === null && $commercial) {
            $this->findings[] = $this->finding('reviews_onsite', 'low', 'На сайті немає відгуків клієнтів - лише посилання на ' . implode(', ', array_keys($platforms)), [
                'evidence' => 'На ' . $this->rolesList($this->pages) . ' немає блоку чи сторінки відгуків і розмітки AggregateRating / Review; є посилання на ' . implode(', ', array_keys($platforms)) . '.' . ($widget !== null ? ' Скрипт віджета відгуків (' . $widget . ') підвантажує їх JavaScript-ом - у HTML їх немає.' : ''),
                'why'      => 'Відгуки у HTML сторінки AI-боти читають напряму; відгуки лише на зовнішньому майданчику потрапляють у відповідь, тільки якщо AI окремо звернеться до цього майданчика.',
                'fix'      => 'Додайте на головну або окрему сторінку 3-5 реальних відгуків текстом (ім\'я, дата, текст) і залиште посилання на повний профіль' . ($this->type === 'ecommerce' ? '; на картках товарів увімкніть відгуки з AggregateRating.' : '.'),
                'bad'      => 'Відгуків у HTML немає; є лише посилання: ' . implode(', ', array_map(function ($n) use ($platforms) { return $n . ' (' . self::mbTrunc($platforms[$n], 70) . ')'; }, array_keys($platforms))),
                'good'     => $this->reviewsGood(),
                'code'     => $this->reviewsHtml(),
                'verify'   => 'Вихідний код сторінки (Ctrl+U) містить тексти відгуків.',
                'effort'   => 'S',
            ]);
        } elseif (!$realPlatforms && $commercial) {
            $this->findings[] = $this->finding('reviews_external', 'low', 'Немає посилань на зовнішні відгуки (Google Maps, ' . ($this->type === 'ecommerce' ? 'Hotline' : ($this->type === 'services' ? 'Clutch' : 'довідники')) . ')', [
                'evidence' => 'Відгуки на сайті: ' . ($visible ? implode(', ', array_values($visible)) : ($schema ?? 'немає')) . '. Посилань на профілі на незалежних майданчиках не знайдено' . ($apps ? ' (є лише ' . implode(', ', array_keys($apps)) . ')' : '') . '.',
                'why'      => 'Незалежні відгуки AI-системи вважають надійнішими за відгуки на власному сайті; посилання з сайту на профіль допомагає зв\'язати бренд із цим профілем.',
                'fix'      => 'Додайте у футер або на «Контакти» посилання на профіль у Google Maps' . ($this->type === 'ecommerce' ? ', Hotline.ua' : ($this->type === 'services' ? ', Clutch / GoodFirms' : '')) . ' і додайте ці URL у sameAs розмітки Organization.',
                'bad'      => 'Зовнішніх посилань на відгуки немає',
                'good'     => '<a href="https://maps.app.goo.gl/[ВАШ-ПРОФІЛЬ]" rel="noopener">Відгуки про ' . htmlspecialchars($this->brand, ENT_NOQUOTES) . ' у Google</a>',
                'code'     => '',
                'verify'   => 'Посилання відкриває профіль саме вашої компанії з відгуками.',
                'effort'   => 'S',
            ]);
        }
        return max(0, min(100, $score));
    }

    // ================================================================== 6. Маркери довіри

    private function trustPatterns(): array
    {
        $p = $this->trustPatternsBase();
        if (!$this->personal) return $p;
        // особистий сайт: досвід особи, сертифікати й освіта, публікації, виступи
        $p['years'][] = '/(?<![\d.,])(\d{1,2})\+?[\s-]?(?:річн\w*)\s+(?:досвід\w*|стаж\w*|практик\w*)/iu';
        $p['years'][] = '/(?:за|понад|більше|вже)\s+(\d{1,2})\+?\s*(?:років|роки)(?![\p{L}])/iu';
        $p['credentials'][] = '/(?<![\p{L}])(?:сертифікат\w*|диплом\w*|кандидат\w*\s+\w+\s+наук|PhD|MBA|certified|certification|certificate)(?![\p{L}])/iu';
        // «Кейс COMFY: +198% ...», «Case study: ...»
        $p['cases'][] = '/(?<![\p{L}])(?i:кейс|case study)(?:\s*:|\s+[«"]?[\p{Lu}\d][\p{L}\d&.\'-]{1,30}[»"]?\s*[:-])/u';
        $p['publications'] = [
            '/(?<![\p{L}])(?:публікаці\w*|авторськ\w*\s+(?:статт\w*|колонк\w*|матеріал\w*|профіль)|експертн\w*\s+коментар\w*|коментар\w*\s+для\s+(?:ЗМІ|медіа)|інтерв\W?ю|публикаци\w*|publications?|featured in|as seen in)(?![\p{L}])/iu',
        ];
        $p['talks'] = [
            '/(?<![\p{L}])(?:виступ\w*\s+(?:на|у|в)\s+\S+|виступи\w*|спікер\w*|доповід\w*|конференці\w*|вебінар\w*|воркшоп\w*|майстер-клас\w*|speaker|keynote|conference talks?|выступлени\w*|спикер\w*)(?![\p{L}])/iu',
        ];
        return $p;
    }

    private function trustPatternsBase(): array
    {
        return [
            'years' => [
                '/(?<![\p{L}\d])(?:з|із|since)\s+(1[89]\d\d|20[0-2]\d)\s*(?:року|р\.)/iu',
                '/на ринку\s+(?:з|із)\s+(1[89]\d\d|20[0-2]\d)/iu',
                '/(?:заснован\w*|засновано|створен\w*|founded|established)\s+(?:у|в|in)?\s*(1[89]\d\d|20[0-2]\d)(?!\d)/iu',
                '/(?<![\p{L}])(?:у|в)\s+(1[89]\d\d|20[0-2]\d)\s+(?:році|р\.)[^.!?]{0,90}?(?:заснува|засновано|заснован|відкри|створи|створе|з\'явил|започаткува|запусти|розпоча)\w*/iu',
                '/(?<![\d.,])(\d{1,3})\+?\s*(?:років|роки|рік)\s+(?:на ринку|досвіду|роботи|практики|успішної роботи|в галузі|у сфері|в сфері|допомагаємо|працюємо)/iu',
                '/(?:понад|більше|близько|вже)\s+(\d{1,3})\s*(?:років|роки)(?![\p{L}])/iu',
                '/(?<![\d.,])(\d{1,3})\+?\s*years?\s+(?:of experience|in business|on the market|of expertise)/iu',
                '/(?:over|more than)\s+(\d{1,3})\s+years/iu',
                '/since\s+(1[89]\d\d|20[0-2]\d)(?!\d)/iu',
                // «ZAXID.NET (4 травня 2007 р.) - перше аналітичне видання ...»
                '/\((?:\d{1,2}\s+(?:січня|лютого|березня|квітня|травня|червня|липня|серпня|вересня|жовтня|листопада|грудня)\s+)?(1[89]\d\d|20[0-2]\d)\s*(?:р\.|року)?\)\s*[-–—]\s*\p{Ll}[\p{Ll}\'’ʼ-]+/u',
            ],
            'credentials' => [
                '/сертифікован\w*\s+(?:\w+\s+){0,2}?(?:фахів\w*|спеціаліст\w*|агенці\w*|партнер\w*|лікар\w*|майстр\w*|експерт\w*|центр\w*|сервіс\w*|виробни\w*|продукці\w*|товар\w*|обладнан\w*)/iu',
                '/сертифікат\w*\s+(?:якості|відповідності|ISO|Google|Meta|Microsoft|HACCP|GMP|ДСТУ|міжнародн\w*|європейськ\w*)/iu',
                '/(?<![\p{L}])ISO\s?\d{4,5}(?::\d{4})?/u',
                '/сертифікован\w*\s+згідно\s+з\s+[^.;]{3,90}/iu',
                '/(?<![\p{L}])NSF(?:\/ANSI)?(?:\/CAN)?\s?\d{1,3}(?!\d)/u',
                '/ліцензі\w*\s+(?:МОЗ|НБУ|Нацбанку|Міністерства|Національного банку|НКЦПФР|Нацкомфінпослуг|на\s+(?:медичну|провадження|здійснення|право))(?:\s+(?:України|охорони здоров\W?я|№\s*[\w\/-]+|серії\s+[\w-]+|від\s+\d{1,2}\.\d{1,2}\.\d{2,4}))*/iu',
                '/ліцензі\w*\s*№\s*[\w-]+/iu',
                '/(?:наші|отрима\w*|має|маємо|численн\w*)\s+нагород\w*|нагород\w*\s+(?:та|і)\s+(?:відзнак\w*|досягнен\w*)|нагород\w*\s+(?:від|за|у номінації|премії|конкурсу|рейтингу)\s+\S+/iu',
                '/(?:переможц\w*|переможець|лауреат\w*|фіналіст\w*)\s+(?:\w+\s+){0,3}?(?:премі\w*|конкурс\w*|рейтинг\w*|award\w*)[^.;]{0,30}/iu',
                '/(?:award[- ]winning|winner of|awarded)\s+\S+/iu',
                '/(?:[\p{L}\d-]+\s+){0,3}акредитован\w*(?:\s+[\p{L}\d-]+){0,2}|(?:[\p{L}\d-]+\s+){0,3}акредитаці\w*/iu',
                '/лікар\w*\s+(?:вищої|першої)\s+категорії/iu',
                '/(?:кандидат|доктор)\w*\s+медичних\s+наук/iu',
                '/(?:certified\s+(?:agency|partner|specialists?|experts?|professionals?)|Certified Agency)/iu',
                '/ідентифікатор медіа\s*[-–:]?\s*R\d{2}-\d{3,6}/iu',
                '/учасник\w*\s+Фонду гарантування вкладів(?:\s+фізичних осіб)?/iu',
            ],
            'partners' => [
                '/(?<![\p{L}])(?:Google\s+(?:Premier\s+)?Partner|Premier Partner|Meta\s+(?:Business|Marketing)\s+Partner|Facebook\s+Marketing\s+Partner|Microsoft\s+(?:Gold\s+|Advertising\s+)?Partner|AWS\s+Partner|HubSpot\s+(?:\w+\s+)?Partner|Shopify\s+Partner|Amazon\s+(?:SPN|Service Provider Network)|TikTok\s+(?:Marketing\s+)?Partner)(?![\p{L}])/iu',
                // офіційний дилер / партнер - лише з назвою бренду (латиницею або в лапках), інакше це загальна фраза
                '/(?<![\p{L}])(?i:офіційн\w*\s+(?:дилер\w*|партнер\w*|представни\w*|дистриб\W?ютор\w*|імпортер\w*|сервісн\w*\s+центр\w*|постачальни\w*)|авторизован\w*\s+(?:дилер\w*|партнер\w*|сервісн\w*\s+центр\w*))(?:\s+(?i:компанії|компаній|бренду|брендів|торгової марки|торгових марок|ТМ|TM|виробника|виробників|в Україні|у Україні))?\s*:?\s+(?:[A-Z][A-Za-z0-9&.\'+-]{1,30}(?:\s+[A-Z][A-Za-z0-9&.\'+-]{1,30})?|[«"„][^»"”\n]{2,40}[»"”])/u',
                '/(?<![\p{L}])(?i:official\s+(?:dealer|partner|distributor|reseller))\s+(?:of\s+|for\s+)?[A-Z][A-Za-z0-9&.\'+-]{1,30}/u',
            ],
            'guarantees' => [
                '/гаранті\w*\s+(?:якості|повернення|виробника|від виробника|до\s+\d+|\d+)\s*(?:міс\w*|рок\w*|рік|днів|дні)?/iu',
                '/(?:офіційн\w*|повн\w*)\s+гаранті\w*/iu',
                '/гарантуємо\s+\w+/iu',
                '/(?<![\p{L}])(?:money[- ]back guarantee|\d+[- ](?:day|year|month)s?\s+(?:warranty|guarantee))(?![\p{L}])/iu',
            ],
            'cases' => array_merge([
                // «кейси» лише в значенні «кейси клієнтів» (не «складні кейси» пацієнтів); заголовки й посилання на /cases/ перевіряються окремо
                '/(?<![\p{L}])(?:(?:наші|реалізовані|успішні|усі|всі|дивитися|переглянути|дивитись)\s+кейс\w*|кейс\w*\s+(?:клієнтів|компанії|агенції|з\s+(?:просування|розробки|SEO|PPC|SMM|реклами))|портфоліо|наші роботи|реалізован\w+\s+про[єе]кт\w*|case stud(?:y|ies)|portfolio)(?![\p{L}])/iu',
            ], $this->clientPatterns()),
            'numbers' => [
                // «1 300 фондів», «250 000+ пацієнтів», «1,6 млн людей», «100 мільярдів гривень», «500+ проєктів»
                '/(?<![\d.,])(?:(?:\d{1,3}(?:[\s\x{00A0}]\d{3})+|\d{2,6})\+?(?:\s?(?:тис\.?|тисяч\w*|млн|мільйон\w*|млрд|мільярд\w*))?|\d{1,4}(?:[.,]\d{1,2})?\+?\s?(?:тис\.?|тисяч\w*|млн|мільйон\w*|млрд|мільярд\w*))\+?\s*(?=\p{Ll})(?:клієнт\w*|пацієнт\w*|про[єе]кт\w*|замовлен\w*|покупц\w*|кейс\w*|партнер\w*|країн\w*|фахівц\w*|спеціаліст\w*|лікар\w*|співробітник\w*|магазин\w*|читач\w*|підписник\w*|людей|користувач\w*|фонд\w*|відвантажен\w*|відвідувач\w*|гостей|учнів|студент\w*|випускник\w*|customers|clients|projects|countries|users|employees|people)(?![\p{L}])/iu',
                // суми лише з множником (ціни на кшталт «250 грн» не рахуємо)
                '/(?<![\d.,])\d{1,4}(?:[.,]\d{1,2})?\+?\s?(?:млн|мільйон\w*|млрд|мільярд\w*)\s+(?:гривень|грн|доларів|євро)(?![\p{L}])/iu',
            ],
        ];
    }

    /** Перелік клієнтів: «Серед наших клієнтів: Rozetka, Prom, ...» - лише коли після фрази йдуть назви (а не запрошення стати клієнтом). */
    private function clientPatterns(): array
    {
        return [
            '/(?<![\p{L}])(?i:серед наших клієнтів|наші клієнти|нам довіряють|з нами працюють|our clients|trusted by|clients include)\s*[:-]?\s*(?:[«"]?[\p{Lu}][\p{L}\d&.\'-]*[»"]?(?:\s+[\p{Lu}\d][\p{L}\d&.\'-]*)?\s*,\s*)+[«"]?[\p{Lu}][\p{L}\d&.\'-]*/u',
        ];
    }

    private function evalTrust(): ?int
    {
        $sources = [];
        foreach ($this->pages as $pg) {
            $allowed = $this->type === 'media' ? ['home', 'about', 'contact'] : ['home', 'about', 'contact', 'service', 'product', 'category'];
            if (!in_array($pg['role'], $allowed, true) && !self::isFooterRole($pg['role'])) continue;
            if ($pg['role'] === 'home' && $this->type === 'media') $text = $this->chromeText($pg['page']);
            elseif ($pg['role'] === 'home') $text = $this->cleanText($pg['page']);
            else $text = $this->textOf($pg['page']);
            $sources[] = [$pg['role'], $this->stripInvitations($text), $pg['page']];
        }
        $found = [];
        $patterns = $this->trustPatterns();
        foreach ($patterns as $cat => $list) {
            $srcList = $sources;
            $passes = [$list];
            if ($cat === 'credentials') {
                // сторінка футера «Сертифікати» / «Ліцензії» / «Нагороди» - найкраще джерело доказу
                usort($srcList, function ($a, $b) {
                    $fa = self::isFooterRole($a[0]) && preg_match('/сертиф|ліценз|лиценз|нагород|certif|licen|award/iu', $a[0]);
                    $fb = self::isFooterRole($b[0]) && preg_match('/сертиф|ліценз|лиценз|нагород|certif|licen|award/iu', $b[0]);
                    return (int)$fb <=> (int)$fa;
                });
                // «має акредитацію» - слабкий доказ: лише якщо конкретних сертифікатів / ліцензій ніде немає
                $weak = array_values(array_filter($list, function ($rx) { return strpos($rx, 'акредит') !== false; }));
                $passes = [array_values(array_diff($list, $weak)), $weak];
            }
            foreach ($passes as $plist) {
                if (!$plist || isset($found[$cat])) continue;
                foreach ($srcList as [$role, $text, $_p]) {
                    if ($cat === 'years') $text = $this->stripPersonExperience($text);
                    $ph = $this->findPhrases($text, $plist, $cat === 'years' ? 1 : 2, $cat === 'years' ? [$this, 'validYear'] : null);
                    if ($ph) {
                        foreach ($ph as $p) {
                            $found[$cat][] = '«' . $p . '»' . ($role !== 'home' ? ' (' . $this->roleLabel($role) . ')' : '');
                        }
                        break;
                    }
                }
            }
        }
        // блок клієнтів / кейсів, оформлений заголовком (логотипи без тексту)
        if (!isset($found['cases']) && $this->type !== 'media') {
            foreach ($sources as [$role, $_t, $p]) {
                foreach (['h2', 'h3'] as $h) {
                    foreach ($p->headings()[$h] as $t) {
                        if (preg_match('/^(наші клієнти|нам довіряють|клієнти|серед наших клієнтів|з нами працюють|наші партнери та клієнти|кейси|наші кейси|портфоліо|наші роботи|наші проєкти|our clients|trusted by|clients|case studies|portfolio)$/iu', trim($t))) {
                            $found['cases'][] = 'заголовок «' . self::mbTrunc(trim($t), 40) . '»' . ($role !== 'home' ? ' (' . $this->roleLabel($role) . ')' : '');
                            break 3;
                        }
                    }
                }
            }
        }
        // розділи-підтвердження за посиланнями
        foreach ($this->home->internalLinks() as $l) {
            $a = mb_strtolower(trim($l['text']));
            if (!isset($found['credentials']) && $a !== '' && preg_match(self::RX_TRUSTPAGE_ANCHOR, $a)) $found['credentials'][] = 'розділ «' . trim($l['text']) . '» (' . $this->relPath($l['href']) . ')';
            if (!isset($found['cases']) && preg_match(self::RX_CASES_PATH, rtrim(strtolower((string)parse_url($l['href'], PHP_URL_PATH)), '/') . '/')) $found['cases'][] = 'розділ «' . ($l['text'] !== '' ? self::mbTrunc($l['text'], 30) : 'кейси') . '» (' . $this->relPath($l['href']) . ')';
        }
        if ($this->personal) {
            // відгуки клієнтів (див. evalReviews) і профілі експерта поза сайтом
            if ($this->reviewHits) $found['reviews'] = array_slice($this->reviewHits, 0, 2);
            $prof = $this->expertProfiles();
            if ($prof) $found['profiles'] = ['посилання на ' . implode(', ', array_slice(array_keys($prof), 0, 6))];
        }
        if (isset($found['years']) && $this->facts['year'] === null && !$this->personal) $this->facts['year'] = $this->yearFrom($found['years'][0]);

        $w = self::TRUST_W[$this->type];
        if ($this->ymyl === 'medical' || $this->ymyl === 'finance' || $this->ymyl === 'legal') $w['credentials'] += 10;
        $total = array_sum($w);
        $got = 0;
        foreach ($found as $cat => $_) $got += $w[$cat] ?? 0;
        $score = $total > 0 ? (int)round($got / $total * 100) : 0;
        $this->notes['trust_markers'] = $found;

        if ($found) {
            foreach ($found as $cat => $list) {
                $this->passed[] = 'Маркер довіри (' . $this->trustLabel($cat) . '): ' . implode(', ', array_slice($list, 0, 2));
            }
            if ($score >= 50) $this->goods[] = $this->personal ? 'підтвердження експертизи' : 'маркери довіри';
        }
        $relevant = array_keys(array_filter($w, function ($x) { return $x > 0; }));
        $missing = array_values(array_diff($relevant, array_keys($found)));
        $credMissingYmyl = $this->ymyl !== null && !isset($found['credentials']);
        if ($this->personal && ($score < 40 || $credMissingYmyl)) {
            $sev = $credMissingYmyl ? 'medium' : 'low';
            $this->gaps[] = 'підтверджень експертизи';
            $where = $this->rolesList(array_map(function ($s) { return ['role' => $s[0]]; }, $sources), 'nom');
            $this->findings[] = $this->finding('trust_markers_weak', $sev, $credMissingYmyl && $score >= 40
                ? 'Не вказано кваліфікації чи сертифікатів (YMYL: ' . $this->ymylLabel('short') . ')'
                : 'Мало підтверджень експертизи: кейсів, публікацій, виступів, відгуків', [
                'evidence' => 'Перевірено видимий текст (' . $where . ') і посилання на профілі. '
                    . ($found ? 'Знайдено: ' . implode('; ', array_map(function ($c) use ($found) { return $this->trustLabel($c) . ' ' . implode(', ', array_slice($found[$c], 0, 2)); }, array_keys($found))) . '. ' : 'Конкретних фраз не знайдено. ')
                    . 'Не знайдено: ' . implode(', ', array_map(function ($c) { return $this->trustLabel($c); }, $missing)) . '.' . $this->jsNote,
                'why'      => 'Експерту AI-системи довіряють за перевірюваними фактами: кейси з цифрами, публікації в медіа, виступи на конференціях, відгуки клієнтів і профілі, які підтверджують ту саму особу. Оцінні слова («досвідчений», «найкращий») нічого не додають.',
                'fix'      => 'Додайте на головну і «Про мене»: ' . implode(', ', array_map(function ($c) { return $this->trustLabel($c); }, $missing)) . ($this->ymyl !== null ? '; для ' . $this->ymylLabel('gen') . ' обов\'язково освіту, кваліфікацію і номер ліцензії (якщо є)' : '') . '.',
                'bad'      => $found ? 'Зараз: ' . implode('; ', array_map(function ($l) { return implode(', ', array_slice($l, 0, 2)); }, $found)) : 'Зараз: жодних кейсів, публікацій, виступів, відгуків чи посилань на профілі.',
                'good'     => $this->trustGood($missing),
                'code'     => '',
                'verify'   => 'Запитайте ChatGPT або Perplexity: «Хто такий ' . $this->brand . ' і які в нього кейси?» - відповідь має спиратися на ваш сайт.',
                'effort'   => 'S',
            ]);
        } elseif (!$this->personal && ($score < 40 || $credMissingYmyl)) {
            $commercial = in_array($this->type, ['ecommerce', 'local', 'services', 'corporate'], true);
            $sev = ($commercial || $this->ymyl !== null) ? 'medium' : 'low';
            if ($score >= 40 && $credMissingYmyl) $sev = 'medium';
            $this->gaps[] = 'конкретних маркерів довіри';
            $where = $this->rolesList(array_map(function ($s) { return ['role' => $s[0]]; }, $sources), 'nom');
            $this->findings[] = $this->finding('trust_markers_weak', $sev, $credMissingYmyl && $score >= 40
                ? 'Не вказано ліцензій чи кваліфікації (YMYL: ' . $this->ymylLabel('short') . ')'
                : 'Мало конкретних маркерів довіри', [
                'evidence' => 'Перевірено видимий текст (' . $where . ')' . ($this->type === 'media' ? ', для головної - лише шапка й футер' : ', без заголовків-посилань на статті') . '. '
                    . ($found ? 'Знайдено: ' . implode('; ', array_map(function ($c) use ($found) { return self::TRUST_LABELS[$c] . ' ' . implode(', ', array_slice($found[$c], 0, 2)); }, array_keys($found))) . '. ' : 'Конкретних фраз не знайдено. ')
                    . 'Не знайдено за шаблонами: ' . implode(', ', array_map(function ($c) { return self::TRUST_LABELS[$c]; }, $missing)) . '.' . $this->jsNote,
                'why'      => 'AI-системи цитують перевірювані факти («з 2006 року», «ліцензія МОЗ №...», «офіційний дилер Bosch»), а не оцінні слова («надійний», «якісний»). Конкретика робить бренд джерелом, яке можна процитувати.',
                'fix'      => 'Додайте на головну і «Про нас» конкретні факти: ' . implode(', ', array_map(function ($c) { return self::TRUST_LABELS[$c]; }, $missing)) . ($this->ymyl !== null ? '; для ' . $this->ymylLabel('gen') . ' обов\'язково номер ліцензії та кваліфікацію фахівців' : '') . '.',
                'bad'      => $found ? 'Зараз: ' . implode('; ', array_map(function ($l) { return implode(', ', array_slice($l, 0, 2)); }, $found)) : 'Зараз: жодної фрази про роки роботи, ліцензії, сертифікати, гарантії чи клієнтів.',
                'good'     => $this->trustGood($missing),
                'code'     => '',
                'verify'   => 'Запитайте ChatGPT або Perplexity: «З якого року працює ' . $this->brand . ' і які має ліцензії / сертифікати?» - відповідь має спиратися на ваш сайт.',
                'effort'   => 'S',
            ]);
        }
        return max(0, min(100, $score));
    }

    /** Підпис категорії маркерів довіри (для особистого сайту - про особу). */
    private function trustLabel(string $cat): string
    {
        if ($this->personal && isset(self::TRUST_LABELS_PERSONAL[$cat])) return self::TRUST_LABELS_PERSONAL[$cat];
        return self::TRUST_LABELS[$cat] ?? $cat;
    }

    /** Профілі експерта поза сайтом (головна і «Про мене»): [назва => URL]. */
    private function expertProfiles(): array
    {
        $out = [];
        foreach ($this->pages as $pg) {
            if (!in_array($pg['role'], ['home', 'about'], true)) continue;
            foreach ($pg['page']->externalLinks() as $l) {
                $hp = preg_replace('#^https?://(?:www\.|m\.)?#i', '', (string)$l['href']);
                if (!preg_match(self::RX_PROFILE_HOSTS, (string)$hp)) continue;
                if (preg_match('#(sharer|share\?|intent/|/share|/dialog/)#i', $l['href'])) continue;
                $name = self::PROFILE_NAMES[Crawler::registrableHost($l['href'])] ?? Crawler::registrableHost($l['href']);
                if (!isset($out[$name])) $out[$name] = $l['href'];
            }
        }
        return $out;
    }

    private function trustGood(array $missing): string
    {
        $b = $this->brand;
        $year = $this->facts['year'] ?? '[РІК]';
        $city = $this->facts['city'] !== null ? ' (м. ' . $this->facts['city'] . ')' : '';
        if ($this->personal && $this->ymyl === null) {
            return $b . ' - [СПЕЦІАЛІЗАЦІЯ], [N] років досвіду' . $city . ".\nКейси: [3 приклади з цифрами результату].\nВиступи: [конференція, рік]. Публікації: [медіа, тема].\nВідгуки клієнтів: [3-5 з іменами]. Профілі: LinkedIn, [інші].";
        }
        switch ($this->ymyl ?? $this->type) {
            case 'medical':
                return $b . $city . ' - медичний центр, працює з ' . $year . ' року. Ліцензія МОЗ України № [НОМЕР] від [ДАТА]. [N] лікарів, з них [N] - вищої категорії, [N] кандидатів медичних наук.';
            case 'finance':
                return $b . ' працює з ' . $year . ' року. Ліцензія НБУ № [НОМЕР] від [ДАТА]; учасник Фонду гарантування вкладів [якщо так]. [N] клієнтів.';
            case 'legal':
                return $b . ' - юридична компанія з ' . $year . ' року. Свідоцтво про право на заняття адвокатською діяльністю № [НОМЕР]; [N] адвокатів; [N] виграних справ.';
            case 'ecommerce':
                return $b . ' - з ' . $year . ' року, [офіційний дилер БРЕНДУ / власне виробництво]. [Гарантія від виробника N місяців], 14 днів на повернення. [N] замовлень на місяць, [нагороди / сертифікати якості].';
            case 'local':
                return $b . $city . ' працює з ' . $year . ' року. [Ліцензія / сертифікат / категорія - з номером]. [N] гостей / клієнтів на рік, рейтинг [4.8] у Google ([N] відгуків).';
            case 'media':
                return $b . ' - видання з ' . $year . ' року, ідентифікатор у Реєстрі суб\'єктів у сфері медіа [R..-.....]. [N] читачів на місяць, редакційна політика і склад редакції - на сторінці «Про нас».';
            case 'saas':
                return $b . ' - з ' . $year . ' року, [N] користувачів у [N] країнах. [ISO 27001 / SOC 2 / інший сертифікат]. Клієнти: [3-5 відомих компаній].';
            default:
                return $b . ' - з ' . $year . ' року, [N] реалізованих проєктів, сертифікований партнер [Google / Meta / ...]. Кейси: [3 приклади з цифрами результатів].';
        }
    }

    // ================================================================== YMYL

    private function detectYmyl(): array
    {
        $med = ['MedicalOrganization', 'MedicalBusiness', 'MedicalWebPage', 'Physician', 'Hospital', 'Dentist', 'Pharmacy', 'MedicalClinic', 'DiagnosticLab'];
        $fin = ['FinancialService', 'BankOrCreditUnion', 'InsuranceAgency', 'FinancialProduct', 'LoanOrCredit', 'AccountingService', 'InvestmentOrDeposit'];
        $leg = ['LegalService', 'Attorney', 'Notary'];
        foreach ($this->pages as $pg) {
            if ($pg['page']->hasSchemaType($med)) return ['medical', 'розмітка ' . $this->firstType($pg['page'], $med)];
            if ($this->type !== 'ecommerce' && $pg['page']->hasSchemaType($fin)) return ['finance', 'розмітка ' . $this->firstType($pg['page'], $fin)];
            if ($pg['page']->hasSchemaType($leg)) return ['legal', 'розмітка ' . $this->firstType($pg['page'], $leg)];
        }
        // ключові слова - лише title і H1 (в описі медіа згадують «інвестиції», «кредити» тощо як теми новин)
        $h = $this->home->headings();
        $tag = mb_strtolower($this->home->title() . ' | ' . implode(' ', array_slice($h['h1'], 0, 2)));
        if (preg_match('/(медичн\w*|клінік\w*|лікар\w*|стоматолог\w*|діагностичн\w*|лабораторі\w*|лікуванн\w*|медцентр\w*|аптек\w*|госпітал\w*|лікарн\w*|здоров\w*|hospital|clinic|medical|dental|pharmacy|health)/u', $tag, $m)) return ['medical', 'title / H1: «' . $m[1] . '»'];
        if ($this->type === 'ecommerce' || $this->type === 'media') return [null, ''];
        if (preg_match('/(банк\w*|bank\b|кредит\w*|позик\w*|страхов\w*|депозит\w*|інвестиці\w*|брокер\w*|іпотек\w*|мікрокредит\w*|loans?\b|insurance|mortgage|broker)/u', $tag, $m)) return ['finance', 'title / H1: «' . $m[1] . '»'];
        if (preg_match('/(юрист\w*|адвокат\w*|юридичн\w*|нотаріус\w*|правов\w* допомог\w*|law firm|attorney|lawyer|legal services)/u', $tag, $m)) return ['legal', 'title / H1: «' . $m[1] . '»'];
        return [null, ''];
    }

    private function firstType(Page $p, array $types): string
    {
        $items = $p->schemaItemsOfType($types);
        return $items ? (string)$items[0]['types'][0] : $types[0];
    }

    /** $case: loc («у медичній тематиці»), gen («для медичної тематики»), short («медицина»). */
    private function ymylLabel(string $case = 'loc'): string
    {
        $map = [
            'loc'   => ['medical' => 'медичній тематиці', 'finance' => 'фінансовій тематиці', 'legal' => 'юридичній тематиці', '' => 'YMYL-тематиці'],
            'gen'   => ['medical' => 'медичної тематики', 'finance' => 'фінансової тематики', 'legal' => 'юридичної тематики', '' => 'YMYL-тематики'],
            'short' => ['medical' => 'медицина', 'finance' => 'фінанси', 'legal' => 'право', '' => 'YMYL'],
        ];
        $m = $map[$case] ?? $map['loc'];
        return $m[$this->ymyl ?? ''] ?? $m[''];
    }

    // ================================================================== генератори прикладів / коду

    private function aboutOutlineHtml(): string
    {
        $b = htmlspecialchars($this->brand, ENT_NOQUOTES);
        if ($this->personal) {
            return "<h1>{$b}</h1>\n<p>[СПЕЦІАЛІЗАЦІЯ], [N] років досвіду. [Місто]. [Чим допомагаю і кому - 2-3 речення]</p>\n<h2>Досвід</h2>\n<ul><li>[Посада, компанія, роки]</li></ul>\n<h2>Кейси</h2>\n<ul><li>[Проєкт: результат у цифрах]</li></ul>\n<h2>Публікації та виступи</h2>\n<ul><li>[Конференція / медіа, рік, посилання]</li></ul>\n<h2>Освіта і сертифікати</h2>\n<p>[Заклад, курс, сертифікат]</p>\n<p>Профілі: <a href=\"https://www.linkedin.com/in/[ПРОФІЛЬ]/\">LinkedIn</a></p>";
        }
        $year = $this->facts['year'] ?? '[РІК]';
        $addr = $this->fullAddress() !== null ? htmlspecialchars((string)$this->fullAddress(), ENT_NOQUOTES) : '[МІСТО, ВУЛИЦЯ, БУДИНОК]';
        return "<h1>Про {$b}</h1>\n<p>{$b} - [ЩО ВИ РОБИТЕ І ДЛЯ КОГО] з {$year} року. Офіс: {$addr}.</p>\n<h2>Команда</h2>\n<p>[ПІБ засновника / керівника], [посада], [N] років досвіду. [Фото і 2-3 речення біо]</p>\n<h2>Досягнення</h2>\n<ul><li>[N клієнтів / проєктів / пацієнтів]</li><li>[Ліцензії, сертифікати, нагороди, партнерські статуси]</li></ul>";
    }

    private function aboutFactsExample(array $missing): string
    {
        $b = $this->brand;
        $lines = [];
        if ($this->personal) {
            if (in_array('year', $missing, true)) $lines[] = 'Працюю в [СФЕРА] з [РІК] року ([N] років досвіду).';
            if (in_array('team', $missing, true)) $lines[] = $b . ' - [ПОСАДА / СПЕЦІАЛІЗАЦІЯ]; зараз - [компанія або «незалежний консультант»].';
            if (in_array('geo', $missing, true)) $lines[] = 'Живу і працюю в [МІСТО]; консультую [онлайн / по всій Україні].';
            if (in_array('achievements', $missing, true)) $lines[] = 'Кейси: [проєкт - результат у цифрах]. Виступи: [конференція, рік]. Публікації: [медіа, тема].';
            return implode("\n", $lines);
        }
        if (in_array('year', $missing, true)) $lines[] = $b . ' працює з [РІК] року.';
        if (in_array('team', $missing, true)) $lines[] = $this->ymyl === 'medical' ? 'Головний лікар - [ПІБ], [спеціальність], [N] років практики; команда - [N] лікарів (сторінка «Лікарі»).' : 'Засновник - [ПІБ], [досвід]; команда - [N] фахівців (сторінка «Команда»).';
        if (in_array('geo', $missing, true)) $lines[] = 'Офіс: ' . ($this->fullAddress() ?? '[МІСТО, ВУЛИЦЯ, БУДИНОК]') . '.';
        if (in_array('achievements', $missing, true)) $lines[] = '[N] клієнтів / проєктів, [нагорода або сертифікат з назвою і роком].';
        return implode("\n", $lines);
    }

    private function aboutJsonLd(string $aboutUrl): string
    {
        if ($this->personal) {
            return self::jsonLdScript(['@context' => 'https://schema.org', '@type' => 'ProfilePage', 'url' => $aboutUrl, 'name' => 'Про мене - ' . $this->brand,
                'mainEntity' => $this->personNode()]);
        }
        $org = ['@type' => 'Organization', 'name' => $this->brand, 'url' => $this->base . '/'];
        $org['foundingDate'] = $this->facts['year'] ?? '[ВКАЖІТЬ РІК ЗАСНУВАННЯ]';
        $org['founder'] = ['@type' => 'Person', 'name' => '[ВКАЖІТЬ ПІБ ЗАСНОВНИКА]'];
        $addr = ['@type' => 'PostalAddress', 'streetAddress' => '[ВКАЖІТЬ ВУЛИЦЮ, БУДИНОК]', 'addressLocality' => $this->facts['city'] ?? '[ВКАЖІТЬ МІСТО]'];
        if ($this->facts['address']) $addr['streetAddress'] = (string)($this->facts['street'] ?? $this->facts['address']);
        if ($this->facts['region'] && !$this->facts['city']) $addr['addressRegion'] = (string)$this->facts['region'];
        if ($this->facts['postal']) $addr['postalCode'] = (string)$this->facts['postal'];
        $addr['addressCountry'] = $this->uaSite ? 'UA' : '[ВКАЖІТЬ КРАЇНУ]';
        $org['address'] = $addr;
        if ($this->facts['phone']) $org['telephone'] = $this->phoneE164((string)$this->facts['phone']);
        if ($this->facts['email']) $org['email'] = (string)$this->facts['email'];
        $same = $this->socialLinks();
        if ($same) $org['sameAs'] = $same;
        return self::jsonLdScript(['@context' => 'https://schema.org', '@type' => 'AboutPage', 'url' => $aboutUrl, 'name' => 'Про ' . $this->brand, 'mainEntity' => $org]);
    }

    /** Person для готового коду особистого сайту: наявні дані з розмітки, решта - заглушки. */
    private function personNode(): array
    {
        $d = $this->person['data'] ?? [];
        $s = function ($v) { if (is_array($v)) $v = $v[0] ?? null; return is_string($v) && trim($v) !== '' ? trim($v) : null; };
        $p = ['@type' => 'Person'];
        $p['@id'] = $s($d['@id'] ?? null) ?? $this->base . '/#person';
        $p['name'] = $s($d['name'] ?? null) ?? $this->brand;
        $p['url'] = $this->base . '/';
        $p['image'] = $s($d['image'] ?? null) ?? (is_array($d['image'] ?? null) ? ($s($d['image']['url'] ?? null) ?? '[ВКАЖІТЬ URL фото]') : '[ВКАЖІТЬ URL фото]');
        $p['jobTitle'] = $s($d['jobTitle'] ?? null) ?? '[ВКАЖІТЬ ПОСАДУ / СПЕЦІАЛІЗАЦІЮ]';
        $p['knowsAbout'] = !empty($d['knowsAbout']) ? array_slice((array)$d['knowsAbout'], 0, 6) : ['[ТЕМА 1]', '[ТЕМА 2]'];
        $same = !empty($d['sameAs']) ? array_values(array_filter((array)$d['sameAs'], 'is_string')) : $this->socialLinks();
        $p['sameAs'] = $same ? array_slice($same, 0, 6) : ['[ПОСИЛАННЯ НА LINKEDIN АБО ІНШИЙ ПРОФІЛЬ]'];
        return $p;
    }

    private function teamJsonLd(): string
    {
        if ($this->personal) return self::jsonLdScript(['@context' => 'https://schema.org'] + $this->personNode());
        $job = $this->ymyl === 'medical' ? 'Головний лікар' : 'Засновник';
        return self::jsonLdScript([
            '@context' => 'https://schema.org', '@type' => 'Person', 'name' => '[ВКАЖІТЬ ПІБ]', 'jobTitle' => $job,
            'worksFor' => ['@type' => 'Organization', 'name' => $this->brand, 'url' => $this->base . '/'],
            'sameAs' => ['[ПОСИЛАННЯ НА LINKEDIN АБО ПРОФІЛЬ]'],
        ]);
    }

    private function contactsGood(array $missing): string
    {
        $phone = $this->facts['phone'] ?? ($this->uaSite ? '+38 (0XX) XXX XX XX' : '+[КОД КРАЇНИ] [НОМЕР]');
        $email = $this->facts['email'] ?? '[EMAIL]@' . $this->host;
        $addr = $this->fullAddress() ?? '[МІСТО], вул. [ВУЛИЦЯ, БУДИНОК]';
        return $this->brand . "\nТел.: " . $phone . "\nEmail: " . $email . "\nАдреса: " . $addr . "\nГрафік: [Пн-Пт 9:00-18:00]\n+ посилання «Контакти» у футері";
    }

    private function contactsHtml(): string
    {
        $phone = $this->facts['phone'] ? (string)$this->facts['phone'] : ($this->uaSite ? '+38 (0XX) XXX XX XX' : '+[КОД КРАЇНИ] [НОМЕР]');
        $tel = $this->facts['phone'] ? $this->phoneE164((string)$this->facts['phone']) : ($this->uaSite ? '+380XXXXXXXXX' : '+[КОДКРАЇНИНОМЕР]');
        $email = $this->facts['email'] ? (string)$this->facts['email'] : '[EMAIL]@' . $this->host;
        $addr = $this->fullAddress() ?? '[МІСТО], вул. [ВУЛИЦЯ, БУДИНОК]';
        $e = function ($s) { return htmlspecialchars($s, ENT_QUOTES); };      // атрибути
        $t = function ($s) { return htmlspecialchars($s, ENT_NOQUOTES); };    // текст
        return "<footer>\n  <address>\n    <strong>" . $t($this->brand) . "</strong><br>\n    <a href=\"tel:" . $e($tel) . "\">" . $t($phone) . "</a><br>\n    <a href=\"mailto:" . $e($email) . "\">" . $t($email) . "</a><br>\n    " . $t($addr) . "<br>\n    [Пн-Пт 9:00-18:00]\n  </address>\n  <a href=\"/contacts/\">Контакти</a>\n</footer>";
    }

    private function phoneE164(string $display): string
    {
        $d = preg_replace('/\D/', '', $display);
        if ($d === '') return $display;
        $k = $this->phoneKey($d);
        return strlen($k) <= 6 ? $k : '+' . $k;
    }

    private function bylineGood(string $headline, array $a): string
    {
        $date = $a['published'] !== null ? $a['published'] : '[ДАТА]';
        $out = '<h1>' . self::mbTrunc($headline, 100) . "</h1>\n<p class=\"byline\">Автор: <a href=\"/author/[slug]/\" rel=\"author\">[ІМ'Я ПРІЗВИЩЕ]</a>, [ПОСАДА / СПЕЦІАЛІЗАЦІЯ]";
        if ($this->ymylArt) $out .= "<br>\nПеревірено експертом: [ПІБ], [КВАЛІФІКАЦІЯ, СТАЖ]";
        return $out . "<br>\nОпубліковано: " . $date . ' - оновлено: [ДАТА]</p>';
    }

    private function articleJsonLd(Page $ap, string $url, array $a): string
    {
        $headline = $a['headline'] !== '' ? $a['headline'] : $ap->title();
        $type = $a['art_type'] !== '' ? $a['art_type'] : 'Article';
        $authorName = ($a['name'] !== null && $a['generic'] === null) ? $a['name'] : '[ВКАЖІТЬ ПІБ АВТОРА]';
        $author = ['@type' => 'Person', 'name' => $authorName, 'url' => $a['author_url'] ?? ($this->base . '/author/[slug]/'), 'jobTitle' => '[ВКАЖІТЬ ПОСАДУ / СПЕЦІАЛІЗАЦІЮ]', 'sameAs' => ['[ПОСИЛАННЯ НА LINKEDIN АБО ПРОФІЛЬ]']];
        $art = [
            '@type' => $type, 'headline' => self::mbTrunc($headline, 110), 'url' => $url, 'mainEntityOfPage' => $url,
            'datePublished' => $this->isoDate($a['published']) ?? '[ВКАЖІТЬ ДАТУ У ФОРМАТІ 2026-01-31]',
            'dateModified' => $this->isoDate($a['modified']) ?? ($this->isoDate($a['published']) ?? '[ВКАЖІТЬ ДАТУ ОНОВЛЕННЯ]'),
            'author' => $author,
            'publisher' => ['@type' => 'Organization', 'name' => $this->brand, 'url' => $this->base . '/'],
        ];
        if ($this->ymylArt && in_array($this->ymyl, ['medical', 'finance', 'legal'], true)) {
            $page = ['@type' => $this->ymyl === 'medical' ? 'MedicalWebPage' : 'WebPage', '@id' => $url, 'url' => $url,
                'reviewedBy' => ['@type' => 'Person', 'name' => '[ВКАЖІТЬ ПІБ ЕКСПЕРТА-РЕЦЕНЗЕНТА]', 'jobTitle' => '[КВАЛІФІКАЦІЯ ЕКСПЕРТА]'],
                'lastReviewed' => '[ВКАЖІТЬ ДАТУ ПЕРЕВІРКИ]'];
            $art['mainEntityOfPage'] = ['@id' => $url];
            return self::jsonLdScript(['@context' => 'https://schema.org', '@graph' => [$page, $art]]);
        }
        return self::jsonLdScript(['@context' => 'https://schema.org'] + $art);
    }

    private function isoDate(?string $s): ?string
    {
        if ($s === null) return null;
        if (preg_match('/^(19|20)\d{2}-\d{2}-\d{2}/', $s, $m)) return substr($s, 0, 10);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.((?:19|20)\d{2})$/', trim($s), $m)) return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        $months = ['січня' => 1, 'лютого' => 2, 'березня' => 3, 'квітня' => 4, 'травня' => 5, 'червня' => 6, 'липня' => 7, 'серпня' => 8, 'вересня' => 9, 'жовтня' => 10, 'листопада' => 11, 'грудня' => 12];
        if (preg_match('/^(\d{1,2})\s+(\p{L}+)\s+((?:19|20)\d{2})$/u', trim($s), $m) && isset($months[mb_strtolower($m[2])])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], $months[mb_strtolower($m[2])], (int)$m[1]);
        }
        return null;
    }

    private function reviewsGood(): string
    {
        if ($this->type === 'ecommerce') return 'На картці товару: рейтинг 4.7 з 5 (32 відгуки) + 3-5 відгуків з іменем, датою і текстом; у розмітці Product - aggregateRating і review.';
        return "Блок «Відгуки клієнтів» на головній: 3-5 реальних відгуків (ім'я, компанія / місто, дата, текст)\n+ посилання «Усі відгуки в Google» на профіль компанії в Google Maps.";
    }

    private function reviewsHtml(): string
    {
        $b = htmlspecialchars($this->brand, ENT_NOQUOTES);
        return "<section class=\"reviews\">\n  <h2>Відгуки клієнтів</h2>\n  <blockquote>\n    <p>[ТЕКСТ ВІДГУКУ]</p>\n    <footer>[ІМ'Я], [МІСТО / КОМПАНІЯ], <time datetime=\"[РРРР-ММ-ДД]\">[ДАТА]</time></footer>\n  </blockquote>\n  <a href=\"https://maps.app.goo.gl/[ВАШ-ПРОФІЛЬ]\" rel=\"noopener\">Усі відгуки про {$b} у Google</a>\n</section>";
    }

    // ================================================================== утиліти

    private function fetchPage(string $url): array
    {
        $this->extraFetched[] = $url;
        $r = $this->fetchMode === 'browser' ? Crawler::fetchBrowser($url, 10) : Crawler::fetch($url, 10);
        $page = null; $challenge = null;
        $mk = function (array $r) use (&$challenge) {
            if ((string)($r['body'] ?? '') === '') return null;
            $p = Page::fromCrawl($r);
            if (!$p) return null;
            $challenge = $p->challenge();
            $ct = strtolower((string)($r['content_type'] ?? 'text/html'));
            $isHtml = $ct === '' || strpos($ct, 'html') !== false || strpos($ct, 'xml') !== false;
            return ($challenge === null && $isHtml && (int)$r['status'] >= 200 && (int)$r['status'] < 400) ? $p : null;
        };
        $page = $mk($r);
        if ($page === null && $this->fetchMode === null && ($challenge !== null || in_array((int)$r['status'], [401, 403, 429, 503], true))) {
            $this->extraFetched[] = $url . ' (browser)';
            $r = Crawler::fetchBrowser($url, 10);
            $page = $mk($r);
        }
        $status = (int)($r['status'] ?? 0);
        return ['status' => $status, 'page' => $page, 'blocked' => $page === null && ($challenge !== null || in_array($status, [0, 401, 403, 429, 503], true))];
    }

    private function textOf(Page $p): string
    {
        $k = spl_object_id($p);
        if (!isset($this->textCache[$k])) $this->textCache[$k] = $p->visibleText();
        return $this->textCache[$k];
    }

    /** Видимий текст без довгих анкорів (заголовки статей / новин у тизерах). */
    private function cleanText(Page $p): string
    {
        $k = 'clean' . spl_object_id($p);
        if (isset($this->textCache[$k])) return $this->textCache[$k];
        $t = $this->textOf($p);
        $anchors = [];
        foreach ($p->links() as $l) {
            if ($l['text'] !== '' && $p->wordCount($l['text']) >= 6 && mb_substr($l['text'], -4) !== ' ...') $anchors[$l['text']] = mb_strlen($l['text']);
        }
        arsort($anchors);
        foreach (array_keys($anchors) as $a) $t = str_replace($a, ' ', $t);
        return $this->textCache[$k] = $t;
    }

    /** Текст шапки / футера / навігації (для медіа, щоб не брати заголовки новин). */
    private function chromeText(Page $p): string
    {
        $parts = [];
        foreach ($p->textBlocks(1) as $b) if ($b['in_nav']) $parts[] = $b['text'];
        return implode(' ', $parts);
    }

    private function findPhrases(string $text, array $patterns, int $max = 3, ?callable $valid = null): array
    {
        $out = [];
        if ($text === '') return [];
        foreach ($patterns as $rx) {
            if (!preg_match_all($rx, $text, $mm, PREG_SET_ORDER)) continue;
            foreach ($mm as $m) {
                if ($valid && !$valid($m)) continue;
                $ph = trim(Page::norm($m[0]), " ,.;:-");
                if ($ph === '') continue;
                $key = mb_strtolower($ph);
                if (isset($out[$key])) continue;
                $out[$key] = self::mbTrunc($ph, 70);
                if (count($out) >= $max) break 2;
            }
        }
        return array_values($out);
    }

    /** Перевірка роки / кількості років у збігу. */
    public function validYear(array $m): bool
    {
        $n = (int)($m[1] ?? 0);
        $cur = (int)date('Y');
        if ($n >= 1800) return $n <= $cur - 1;
        return $n >= 1 && $n <= 150;
    }

    private function yearFrom(string $phrase): ?string
    {
        if (preg_match('/(1[89]\d\d|20[0-2]\d)/', $phrase, $m) && (int)$m[1] < (int)date('Y')) return $m[1];
        return null;
    }

    /** Перше речення першого змістовного блоку сторінки (не меню). */
    private function firstSentenceOf(Page $p, string $fallback): string
    {
        foreach ($p->textBlocks(12) as $b) {
            if ($b['in_nav']) continue;
            return $this->firstSentence($b['text']);
        }
        return $this->firstSentence($fallback);
    }

    private function firstSentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';
        $parts = preg_split('/(?<=[.!?])\s+/u', mb_substr($text, 0, 600));
        $s = (string)($parts[0] ?? $text);
        return self::mbTrunc($s, 200);
    }

    private function cleanAnchor(string $t): string
    {
        return self::mbTrunc(trim((string)preg_replace('/^[\s«»"\'“”„]+|[\s«»"\'“”„]+$/u', '', $t)), 50);
    }

    private static function ucfirstMb(string $s): string
    {
        return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private function wordsWord(int $n): string
    {
        $n10 = $n % 10; $n100 = $n % 100;
        if ($n10 === 1 && $n100 !== 11) return 'слово';
        if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) return 'слова';
        return 'слів';
    }

    private function sameOrg(string $href): bool
    {
        $h = Crawler::registrableHost($href);
        if ($h === '' || $this->host === '') return false;
        return $h === $this->host || substr($h, -strlen('.' . $this->host)) === '.' . $this->host;
    }

    private function isHomeUrl(string $href): bool
    {
        if (!$this->sameOrg($href)) return false;
        // корінь піддомену (about.example.com/) - не головна сайту
        $hh = preg_replace('/^www\./', '', strtolower((string)parse_url($href, PHP_URL_HOST)));
        $hm = preg_replace('/^www\./', '', strtolower((string)parse_url($this->home->url(), PHP_URL_HOST)));
        if ($hh !== '' && $hh !== $hm) return false;
        $p = rtrim((string)parse_url($href, PHP_URL_PATH), '/');
        $hp = rtrim((string)parse_url($this->home->url(), PHP_URL_PATH), '/');
        if ((string)parse_url($href, PHP_URL_QUERY) !== '') return false;
        return $p === $hp || $p === '' || (bool)preg_match('#^/(ua|uk|en|ru)$#i', $p) || (bool)preg_match('#^/index\.(php|html?)$#i', $p);
    }

    private function pathSegs(string $href): array
    {
        $path = strtolower(rawurldecode((string)parse_url($href, PHP_URL_PATH)));
        $segs = array_values(array_filter(explode('/', $path), function ($s) { return $s !== ''; }));
        if ($segs && preg_match('/^(uk|ua|en|ru|uk-ua|ru-ua|en-us)$/', $segs[0])) array_shift($segs);
        return $segs;
    }

    private function relPath(string $url): string
    {
        if (!$this->sameOrg($url) || Crawler::registrableHost($url) !== $this->host) return $url;
        $p = (string)parse_url($url, PHP_URL_PATH);
        $q = (string)parse_url($url, PHP_URL_QUERY);
        return ($p !== '' ? $p : '/') . ($q !== '' ? '?' . $q : '');
    }

    private function socialLinks(): array
    {
        $out = [];
        foreach ($this->home->externalLinks() as $l) {
            $h = Crawler::registrableHost($l['href']);
            if (!preg_match(self::RX_SOCIAL, $h)) continue;
            if (preg_match('#(sharer|share\?|intent/|/share|addthis|/dialog/)#i', $l['href'])) continue;
            $path = trim((string)parse_url($l['href'], PHP_URL_PATH), '/');
            if ($path === '') continue;
            $key = $h;
            if (!isset($out[$key])) $out[$key] = $l['href'];
            if (count($out) >= 6) break;
        }
        return array_values($out);
    }

    /** $case: nom («головна»), loc («на головній»), gen («тексті головної»). */
    private static function isFooterRole(string $role): bool
    {
        return strncmp($role, 'footer:', 7) === 0;
    }

    /**
     * Сторінки, на які веде футер головної (сертифікати, гарантія, оферта, кейси, антикорупційна програма...):
     * сигнали довіри часто лежать саме там. Роль 'footer:<анкор>'. «Про нас» і «Контакти» мають власні оцінювачі.
     */
    private function addFooterPages(): void
    {
        $key = function (string $u): string {
            $h = preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
            return $h . rtrim(strtolower(rawurldecode((string)parse_url($u, PHP_URL_PATH))), '/') . '?' . (string)parse_url($u, PHP_URL_QUERY);
        };
        $have = [];
        foreach ($this->pages as $pg) $have[$key($pg['url'])] = true;
        $trust = []; $rest = [];
        foreach ($this->home->footerLinks() as $l) {
            $href = (string)$l['href'];
            if (!$this->sameOrg($href) || $this->isHomeUrl($href) || strpos($href, '/cdn-cgi/') !== false) continue;
            $path = rawurldecode((string)parse_url($href, PHP_URL_PATH));
            if (preg_match('#\.(pdf|docx?|xlsx?|jpe?g|png|gif|webp|svg|zip|rar)$#i', $path)) continue;
            $k = $key($href);
            if (isset($have[$k])) continue;
            $have[$k] = true;
            $a = mb_strtolower(trim($l['text']));
            if ($a !== '' && (preg_match(self::RX_ABOUT_ANCHOR, $a) || preg_match(self::RX_CONTACT_ANCHOR, $a))) continue;
            if (in_array(SiteProfile::roleOfUrl($href), ['about', 'contact'], true)) continue;
            $rec = ['url' => $href, 'text' => trim($l['text'])];
            if (preg_match(self::RX_FOOTER_TRUST, $a . ' ' . $path)) $trust[] = $rec; else $rest[] = $rec;
        }
        $pick = array_slice(array_merge($trust, $rest), 0, self::FOOTER_MAX);
        if (!$pick) return;
        $this->extraFetched[] = 'footer:' . count($pick);
        $res = Crawler::fetchMulti(array_column($pick, 'url'), $this->fetchMode, 8);
        foreach ($pick as $f) {
            $r = $res[$f['url']] ?? null;
            if (!$r || (int)$r['status'] !== 200) continue;
            $p = Page::fromCrawl($r);
            if (!$p || $p->challenge() !== null) continue;
            $label = $f['text'] !== '' ? self::mbTrunc($f['text'], 40) : (trim($p->title()) !== '' ? self::mbTrunc(trim($p->title()), 40) : $this->relPath($f['url']));
            $this->pages[] = ['role' => 'footer:' . $label, 'url' => (string)($r['final_url'] ?? $f['url']), 'page' => $p];
        }
    }

    private function roleLabel(string $role, string $case = 'nom'): string
    {
        if (self::isFooterRole($role)) {
            $l = '«' . substr($role, 7) . '»';
            return ['nom' => 'сторінка ' . $l, 'loc' => 'сторінці ' . $l, 'gen' => 'сторінки ' . $l][$case] ?? 'сторінка ' . $l;
        }
        if ($this->personal && $role === 'about') {
            return ['nom' => '«Про мене»', 'loc' => 'сторінці «Про мене»', 'gen' => 'сторінки «Про мене»'][$case] ?? '«Про мене»';
        }
        $map = [
            'nom' => ['home' => 'головна', 'about' => '«Про нас»', 'contact' => '«Контакти»', 'article' => 'стаття', 'product' => 'картка товару',
                'category' => 'категорія', 'service' => 'сторінка послуги', 'other' => 'внутрішня сторінка', 'terms' => 'умови / оферта', 'privacy' => 'політика конфіденційності'],
            'loc' => ['home' => 'головній', 'about' => 'сторінці «Про нас»', 'contact' => 'сторінці «Контакти»', 'article' => 'сторінці статті', 'product' => 'картці товару',
                'category' => 'сторінці категорії', 'service' => 'сторінці послуги', 'other' => 'внутрішній сторінці', 'terms' => 'сторінці умов / оферти', 'privacy' => 'сторінці політики конфіденційності'],
            'gen' => ['home' => 'головної', 'about' => 'сторінки «Про нас»', 'contact' => 'сторінки «Контакти»', 'article' => 'статті', 'product' => 'картки товару',
                'category' => 'сторінки категорії', 'service' => 'сторінки послуги', 'other' => 'внутрішньої сторінки', 'terms' => 'умов / оферти', 'privacy' => 'політики конфіденційності'],
        ];
        return ($map[$case] ?? $map['nom'])[$role] ?? $role;
    }

    private function rolesList(array $pages, string $case = 'loc'): string
    {
        $r = []; $footer = [];
        foreach ($pages as $pg) {
            $role = (string)($pg['role'] ?? '');
            if (self::isFooterRole($role)) $footer['«' . substr($role, 7) . '»'] = true;
            else $r[$this->roleLabel($role, $case)] = true;
        }
        $out = array_keys($r);
        if ($footer) {
            // сторінки футера: перші три за назвою, решта - числом
            $names = array_keys($footer);
            $head = ['nom' => 'сторінки футера ', 'loc' => 'сторінках футера ', 'gen' => 'сторінок футера '][$case] ?? 'сторінки футера ';
            $out[] = $head . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' і ще ' . (count($names) - 3) : '');
        }
        return implode(', ', $out);
    }

    private function summary(): string
    {
        $goods = array_values(array_unique($this->goods));
        $gaps = array_values(array_unique($this->gaps));
        if (!$goods && !$gaps) return 'E-E-A-T сигнали оцінено.';
        if (!$gaps) return 'Базові сигнали довіри на місці: ' . implode(', ', array_slice($goods, 0, 4)) . '.';
        if (!$goods) return 'Сигналів довіри майже немає: бракує ' . implode(', ', array_slice($gaps, 0, 4)) . '.';
        return 'Є ' . implode(', ', array_slice($goods, 0, 3)) . '; бракує ' . implode(', ', array_slice($gaps, 0, 3)) . '.';
    }
}
