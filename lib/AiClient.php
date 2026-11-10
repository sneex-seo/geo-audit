<?php
declare(strict_types=1);

/**
 * AI-висновок до GEO-аудиту (Anthropic Messages API, raw HTTP через curl).
 *
 * run($url, $checkResults, $context) -> AiResult:
 *   ['enabled'=>bool, 'model'=>string|null, 'usage'=>['in'=>int,'out'=>int],
 *    'message'=>string (лише коли enabled=false), 'sections'=>[...], 'attempts'=>[...]]
 *
 * Модель: основна claude-sonnet-5-5 (адаптивне мислення + effort), резервна claude-sonnet-4-6.
 * Перевизначення для тестів/операцій: env GEO_AI_MODEL, GEO_AI_FALLBACK_MODEL, GEO_AI_EFFORT, GEO_AI_MAX_TOKENS.
 *
 * Важливо про claude-sonnet-5-5: адаптивне мислення увімкнене за замовчуванням, блок "thinking"
 * приходить з порожнім текстом (display=omitted), а його токени рахуються в max_tokens. При малому
 * max_tokens відповідь = лише thinking-блок, stop_reason=max_tokens і порожній текст. Тому текст
 * збираємо з УСІХ блоків type=text, max_tokens беремо з запасом, а порожню відповідь повторюємо
 * з більшим лімітом і effort=low. Відповідь у форматі JSON гарантує output_config.format (json_schema).
 */
class AiClient
{
    const MODEL = 'claude-sonnet-5-5';
    const FALLBACK_MODEL = 'claude-sonnet-4-6';
    const API_URL = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';
    const SERVER_FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    const MAX_TOKENS = 12000;        // мислення (до ~3-4k) + JSON українською (~3-5k)
    const RETRY_MAX_TOKENS = 16000;  // повтор після обрізаної/порожньої відповіді
    const DEFAULT_EFFORT = 'medium';
    const TIMEOUT = 150;             // секунд на один запит
    const CONNECT_TIMEOUT = 15;
    const TOTAL_BUDGET = 480;        // секунд на всі спроби разом

    const PAGE_TEXT_LIMIT = 6000;
    const SAMPLE_TEXT_LIMIT = 1500;
    const EVIDENCE_LIMIT = 300;
    const MAX_PRIORITIES = 5;
    const MAX_QUICK_WINS = 6;
    const MAX_STRATEGIC = 5;
    const MAX_TEST_PROMPTS = 10;

    public static function isConfigured(): bool
    {
        return self::apiKey() !== '';
    }

    private static function apiKey(): string
    {
        // .env у корені проєкту вже завантажено в середовище (lib/env.php)
        return trim((string)getenv('ANTHROPIC_API_KEY'));
    }

    public static function primaryModel(): string
    {
        $m = trim((string)getenv('GEO_AI_MODEL'));
        return $m !== '' ? $m : self::MODEL;
    }

    public static function fallbackModel(): string
    {
        $m = trim((string)getenv('GEO_AI_FALLBACK_MODEL'));
        return $m !== '' ? $m : self::FALLBACK_MODEL;
    }

    private static function effort(): string
    {
        $e = strtolower(trim((string)getenv('GEO_AI_EFFORT')));
        return in_array($e, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $e : self::DEFAULT_EFFORT;
    }

    private static function maxTokens(): int
    {
        $n = (int)getenv('GEO_AI_MAX_TOKENS');
        return $n >= 64 && $n <= 32000 ? $n : self::MAX_TOKENS;
    }

    // ------------------------------------------------------------------ public entry

    public static function run(string $url, array $checkResults, array $context = []): array
    {
        $usage = ['in' => 0, 'out' => 0];
        $attempts = [];

        if (!self::isConfigured()) {
            return [
                'enabled' => false,
                'model' => null,
                'usage' => $usage,
                'message' => 'AI-аналіз вимкнено: не знайдено ключ ANTHROPIC_API_KEY. Додайте його у файл .env у корені проєкту (див. .env.example).',
                'sections' => [],
            ];
        }

        try {
            $system = self::systemPrompt();
            $user = self::buildUserMessage($url, $checkResults, $context);
        } catch (Throwable $e) {
            return self::failure('не вдалося підготувати дані для AI (' . $e->getMessage() . ')', $usage, $attempts, null);
        }

        $deadline = microtime(true) + self::TOTAL_BUDGET;
        $messages = [['role' => 'user', 'content' => $user]];
        $res = self::callChain([self::primaryModel(), self::fallbackModel()], $system, $messages, [], $usage, $attempts, $deadline);
        if (empty($res['ok'])) {
            return self::failure(self::describeError($res), $usage, $attempts, null);
        }

        $parsed = self::extractJson($res['text']);
        if ($parsed === null) {
            // Одна спроба відновлення: та сама модель, що відповіла, далі резервна.
            $chain = [$res['requested'], self::fallbackModel()];
            if (trim($res['text']) === '') {
                // Увесь бюджет пішов на мислення - повторюємо з більшим лімітом і меншим effort.
                $res2 = self::callChain($chain, $system, $messages, ['max_tokens' => self::RETRY_MAX_TOKENS, 'effort' => 'low'], $usage, $attempts, $deadline);
            } else {
                $repair = array_merge($messages, [
                    ['role' => 'assistant', 'content' => rtrim(mb_substr($res['text'], 0, 30000))],
                    ['role' => 'user', 'content' => self::repairPrompt((string)($res['stop_reason'] ?? ''))],
                ]);
                $res2 = self::callChain($chain, $system, $repair, ['max_tokens' => self::RETRY_MAX_TOKENS], $usage, $attempts, $deadline);
            }
            if (!empty($res2['ok'])) {
                $parsed = self::extractJson($res2['text']);
                if ($parsed !== null) {
                    $res = $res2;
                }
            }
            if ($parsed === null) {
                $out = self::failure('AI повернув відповідь не у форматі JSON навіть після повторної спроби'
                    . (($res['stop_reason'] ?? '') === 'max_tokens' ? ' (відповідь обрізано лімітом max_tokens)' : ''),
                    $usage, $attempts, $res['model'] ?? null);
                $out['raw'] = mb_substr((string)$res['text'], 0, 3000);
                return $out;
            }
        }

        $sections = self::normalizeSections($parsed, $checkResults);
        if ($sections['executive_summary'] === '' && !$sections['top_priorities']) {
            return self::failure('AI повернув порожній результат (немає резюме і пріоритетів)', $usage, $attempts, $res['model'] ?? null);
        }

        return [
            'enabled' => true,
            'model' => $res['model'] ?? $res['requested'],
            'usage' => $usage,
            'sections' => $sections,
            'attempts' => $attempts,
        ];
    }

    private static function failure(string $reason, array $usage, array $attempts, ?string $model): array
    {
        $trail = [];
        foreach ($attempts as $a) {
            $trail[] = $a['model'] . ': ' . ($a['code'] ? 'HTTP ' . $a['code'] : 'без відповіді') . ($a['outcome'] !== 'ok' ? ' (' . $a['outcome'] . ')' : '');
        }
        $msg = 'AI-аналіз не виконано: ' . $reason . '.';
        if (count($trail) > 1) {
            $msg .= ' Спроби: ' . implode('; ', $trail) . '.';
        }
        return [
            'enabled' => false,
            'model' => $model,
            'usage' => $usage,
            'message' => self::scrub($msg),
            'sections' => [],
            'attempts' => $attempts,
        ];
    }

    // ------------------------------------------------------------------ API calls

    /**
     * Ланцюг моделей: на кожній - до одного повтору при 429/5xx/529/тайм-ауті,
     * перехід до наступної моделі при помилці моделі, відмові (refusal) або повторній тимчасовій помилці.
     * Фатальні помилки (401/403/400 баланс тощо) повертаються одразу - інша модель їх не виправить.
     */
    private static function callChain(array $models, string $system, array $messages, array $opt, array &$usage, array &$attempts, float $deadline): array
    {
        $models = array_values(array_unique(array_filter($models, 'strlen')));
        $last = ['ok' => false, 'kind' => 'budget', 'code' => 0, 'error_message' => '', 'curl_error' => ''];
        foreach ($models as $model) {
            $o = $opt;
            $retried = false;
            for ($guard = 0; $guard < 6; $guard++) {
                $remaining = $deadline - microtime(true);
                if ($remaining < 20) {
                    return $last; // остання реальна помилка або kind=budget, якщо спроб не було
                }
                $r = self::request($model, $system, $messages, $o, (int)min(self::TIMEOUT, $remaining));
                $usage['in'] += $r['usage']['in'];
                $usage['out'] += $r['usage']['out'];
                $attempts[] = [
                    'model' => $model,
                    'served_by' => $r['model'],
                    'code' => $r['code'],
                    'outcome' => $r['ok'] ? 'ok' : $r['kind'],
                    'stop_reason' => $r['stop_reason'],
                    'ms' => $r['ms'],
                    'in' => $r['usage']['in'],
                    'out' => $r['usage']['out'],
                    'thinking' => $r['thinking_tokens'],
                ];
                if ($r['ok']) {
                    $r['requested'] = $model;
                    return $r;
                }
                $last = $r;
                $kind = $r['kind'];
                if (strpos($kind, 'feature:') === 0) {
                    $flag = 'no_' . substr($kind, 8);
                    if (empty($o[$flag])) {
                        $o[$flag] = true;   // прибираємо необов'язкову опцію і пробуємо ще раз
                        continue;
                    }
                    $kind = 'fatal';
                }
                if ($kind === 'transient' && !$retried) {
                    $retried = true;
                    $wait = $r['retry_after'] > 0 ? min(30, $r['retry_after']) : 3 + mt_rand(0, 2);
                    if (microtime(true) + $wait + 20 < $deadline) {
                        sleep($wait);
                        continue;
                    }
                }
                if (in_array($kind, ['transient', 'model', 'refusal'], true)) {
                    break; // наступна модель
                }
                return $last; // fatal
            }
        }
        return $last;
    }

    private static function request(string $model, string $system, array $messages, array $opt, int $timeout): array
    {
        $out = [
            'ok' => false, 'kind' => 'fatal', 'code' => 0, 'text' => '', 'stop_reason' => null,
            'model' => null, 'usage' => ['in' => 0, 'out' => 0], 'thinking_tokens' => 0,
            'error_type' => '', 'error_message' => '', 'curl_errno' => 0, 'curl_error' => '',
            'retry_after' => 0, 'ms' => 0, 'sent' => [],
        ];

        $body = [
            'model' => $model,
            'max_tokens' => (int)($opt['max_tokens'] ?? self::maxTokens()),
            'system' => $system,
            'messages' => $messages,
        ];
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . self::apiKey(),
            'anthropic-version: ' . self::API_VERSION,
        ];
        $sent = [];
        $oc = [];
        if (empty($opt['no_effort']) && self::isGen5($model)) {
            // Адаптивне мислення вмикається за замовчуванням; effort - єдиний важіль глибини.
            $oc['effort'] = $opt['effort'] ?? self::effort();
            $sent[] = 'effort';
        }
        if (empty($opt['no_format'])) {
            $oc['format'] = ['type' => 'json_schema', 'schema' => self::schema()];
            $sent[] = 'format';
        }
        if ($oc) {
            $body['output_config'] = $oc;
        }
        if (empty($opt['no_fallbacks']) && self::supportsServerFallback($model)) {
            // Серверний фолбек на випадок відмови класифікатора безпеки (cyber/frontier_llm).
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: ' . self::SERVER_FALLBACK_BETA;
            $sent[] = 'fallbacks';
        }
        $out['sent'] = $sent;

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            $out['error_message'] = 'json_encode: ' . json_last_error_msg();
            return $out;
        }

        $respHeaders = [];
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => max(20, $timeout),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$respHeaders) {
                $p = explode(':', $h, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($h);
            },
        ]);
        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
        $out['code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $out['curl_errno'] = curl_errno($ch);
        $out['curl_error'] = curl_error($ch);
        curl_close($ch);
        $out['retry_after'] = isset($respHeaders['retry-after']) ? (int)$respHeaders['retry-after'] : 0;

        if ($raw === false || $out['curl_errno'] !== 0) {
            $out['kind'] = 'transient'; // тайм-аут або мережа
            return $out;
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $out['kind'] = ($out['code'] >= 500 || $out['code'] === 429 || $out['code'] === 0) ? 'transient' : 'fatal';
            $out['error_message'] = 'неочікувана відповідь: ' . mb_substr(trim(strip_tags((string)$raw)), 0, 200);
            return $out;
        }

        if (isset($data['usage']) && is_array($data['usage'])) {
            $u = $data['usage'];
            $out['usage']['in'] = (int)($u['input_tokens'] ?? 0) + (int)($u['cache_creation_input_tokens'] ?? 0) + (int)($u['cache_read_input_tokens'] ?? 0);
            $out['usage']['out'] = (int)($u['output_tokens'] ?? 0);
            $out['thinking_tokens'] = (int)($u['output_tokens_details']['thinking_tokens'] ?? 0);
        }

        if ($out['code'] !== 200) {
            $out['error_type'] = (string)($data['error']['type'] ?? '');
            $out['error_message'] = (string)($data['error']['message'] ?? '');
            $out['kind'] = self::classify($out['code'], $out['error_type'], $out['error_message'], $sent);
            return $out;
        }

        $out['model'] = isset($data['model']) ? (string)$data['model'] : $model;
        $out['stop_reason'] = $data['stop_reason'] ?? null;
        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $text .= (string)$block['text'];
            }
        }
        $out['text'] = $text;

        if ($out['stop_reason'] === 'refusal') {
            $out['kind'] = 'refusal';
            $out['error_message'] = (string)($data['stop_details']['explanation'] ?? '');
            $out['error_type'] = (string)($data['stop_details']['category'] ?? 'refusal');
            return $out;
        }
        $out['ok'] = true;
        $out['kind'] = 'ok';
        return $out;
    }

    private static function classify(int $code, string $type, string $msg, array $sent): string
    {
        if ($code === 429 || $code === 529 || $code >= 500 || $type === 'overloaded_error' || $type === 'rate_limit_error') {
            return 'transient';
        }
        if ($code === 400 || $code === 422) {
            if (stripos($msg, 'credit balance') !== false) {
                return 'fatal';
            }
            if (in_array('fallbacks', $sent, true) && preg_match('/fallback|anthropic-beta|beta/i', $msg)) {
                return 'feature:fallbacks';
            }
            if (in_array('format', $sent, true) && preg_match('/output_config\.format|json_schema|schema|structured output|output format/i', $msg)) {
                return 'feature:format';
            }
            if (in_array('effort', $sent, true) && preg_match('/effort/i', $msg)) {
                return 'feature:effort';
            }
            if (preg_match('/\bmodel\b/i', $msg)) {
                return 'model';
            }
            return 'fatal';
        }
        if ($code === 404) {
            return 'model';
        }
        return 'fatal'; // 401, 403, 413 та інше
    }

    private static function describeError(array $r): string
    {
        $code = (int)($r['code'] ?? 0);
        $msg = (string)($r['error_message'] ?? '');
        $kind = (string)($r['kind'] ?? '');
        if ($kind === 'budget' && empty($r['code'])) {
            return 'вичерпано загальний ліміт часу на звернення до API';
        }
        if (!empty($r['curl_errno'])) {
            return ((int)$r['curl_errno'] === 28)
                ? 'тайм-аут запиту до Anthropic API (понад ' . self::TIMEOUT . ' с)'
                : 'помилка мережі під час звернення до Anthropic API (' . ($r['curl_error'] ?: 'curl ' . $r['curl_errno']) . ')';
        }
        if ($kind === 'refusal') {
            $reason = 'модель відмовилася обробляти запит (stop_reason: refusal' . (!empty($r['error_type']) ? ', категорія ' . $r['error_type'] : '') . ')';
        } elseif ($code === 400 && stripos($msg, 'credit balance') !== false) {
            $reason = 'недостатньо кредитів на балансі API Anthropic - поповніть баланс у Console (HTTP 400)';
        } elseif ($code === 401) {
            $reason = 'ключ ANTHROPIC_API_KEY недійсний або відкликаний (HTTP 401)';
        } elseif ($code === 403) {
            $reason = 'ключ API не має доступу до цього ресурсу чи моделі (HTTP 403)';
        } elseif ($code === 404) {
            $reason = 'модель недоступна для цього ключа (HTTP 404)';
        } elseif ($code === 413) {
            $reason = 'запит завеликий для API (HTTP 413)';
        } elseif ($code === 429) {
            $reason = 'перевищено ліміт запитів до API (HTTP 429)';
        } elseif ($code === 529) {
            $reason = 'сервери Anthropic перевантажені (HTTP 529)';
        } elseif ($code >= 500) {
            $reason = 'помилка на боці Anthropic (HTTP ' . $code . ')';
        } elseif ($code === 400) {
            $reason = 'API відхилив запит (HTTP 400)';
        } else {
            $reason = 'невідома помилка API' . ($code ? ' (HTTP ' . $code . ')' : '');
        }
        if ($msg !== '') {
            $reason .= '. Відповідь API: ' . mb_substr($msg, 0, 300);
        }
        return $reason;
    }

    /** Ніколи не віддаємо ключ назовні, навіть якщо він опинився в тексті помилки. */
    private static function scrub(string $s): string
    {
        $k = self::apiKey();
        if ($k !== '') {
            $s = str_replace($k, '[ключ приховано]', $s);
        }
        return (string)preg_replace('/sk-ant-[A-Za-z0-9_\-]{8,}/', '[ключ приховано]', $s);
    }

    private static function isGen5(string $model): bool
    {
        return (bool)preg_match('/^claude-(sonnet|opus|haiku|fable|mythos)-5/', $model);
    }

    private static function supportsServerFallback(string $model): bool
    {
        return in_array($model, ['claude-sonnet-5-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1'], true);
    }

    // ------------------------------------------------------------------ prompts

    private static function schema(): array
    {
        $scoreNotes = [
            'type' => 'object',
            'properties' => [
                'score' => ['type' => 'integer'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['score', 'notes'],
            'additionalProperties' => false,
        ];
        $strList = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'properties' => [
                'executive_summary' => ['type' => 'string'],
                'eeat_assessment' => $scoreNotes,
                'content_quality' => $scoreNotes,
                'top_priorities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string'],
                            'impact' => ['type' => 'string', 'enum' => ['Критичний', 'Високий', 'Середній']],
                            'effort' => ['type' => 'string', 'enum' => ['S', 'M', 'L']],
                            'why' => ['type' => 'string'],
                            'example' => ['type' => 'string'],
                        ],
                        'required' => ['action', 'impact', 'effort', 'why', 'example'],
                        'additionalProperties' => false,
                    ],
                ],
                'quick_wins' => $strList,
                'strategic_recommendations' => $strList,
                'test_prompts' => $strList,
            ],
            'required' => ['executive_summary', 'eeat_assessment', 'content_quality', 'top_priorities', 'quick_wins', 'strategic_recommendations', 'test_prompts'],
            'additionalProperties' => false,
        ];
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
Ти - senior GEO/SEO-аналітик. Ринок - Україна. Ти пишеш експертний висновок до автоматичного аудиту готовності сайту до AI-пошуку (ChatGPT, Perplexity, Google AI Overviews і AI Mode, Gemini, Copilot). Висновок читатиме власник сайту або маркетолог клієнта, тому пиши зрозуміло і по суті.

У повідомленні користувача - дані аудиту: профіль сайту (тип, бренд, мови), доступ краулера, дані головної сторінки (title, description, заголовки, уривок тексту), вибірка внутрішніх сторінок і результати детермінованих перевірок з оцінками, знахідками й доказами.

Робота з фактами
- Кожен висновок спирай лише на надані дані: оцінки перевірок, знахідки та їхні докази, тексти й заголовки сторінок, вибірку сторінок, профіль сайту. Якщо в доказі є конкретне значення (кількість, рядок, URL) - посилайся на нього.
- Не вигадуй того, чого в даних немає: авторів і їхні профілі, кейси, нагороди, сертифікати, відгуки, рейтинги, роки на ринку, кількість клієнтів, адреси, телефони, імена людей, назви конкурентів. Якщо для поради потрібен такий факт, став плейсхолдер у квадратних дужках: [ім'я експерта], [рік заснування], [посилання на кейс]. Так само з URL: бери лише адреси з даних (головна, вибірка, URL у доказах), решту - плейсхолдером [URL карти сайту], [URL логотипа].
- Якщо даних бракує (перевірку пропущено, сторінка не завантажилась, немає уривку тексту, сайт блокує бота) - прямо так і напиши і не роби висновків про цю частину. Оцінки E-E-A-T і якості контенту тоді став обережно і поясни, на чому вони базуються.
- Частина перевірок може бути в старому форматі англійською і без доказів. Передавай їхню суть українською, але не додавай деталей, яких там немає.
- Не переказуй знахідки списком. Пріоритезуй: що найсильніше вплине на те, чи будуть AI-асистенти знаходити, розуміти, цитувати і рекомендувати цей сайт. Знахідки critical і high та низькі оцінки у вагомих перевірках - перші кандидати.

Поради залежно від типу сайту
- Інтернет-магазин: картки товарів (Product, Offer з ціною і наявністю, AggregateRating лише за наявності реальних відгуків), корисні описи категорій з FAQ, порівняння, умови доставки, оплати, повернення і гарантії.
- Локальний бізнес: однакові назва, адреса, телефон усюди, LocalBusiness з адресою і годинами роботи, Google Business Profile, сторінки міст і філій, відгуки.
- Медіа / блог: автори з біографіями і Person, дати публікації та оновлення, Article або NewsArticle, джерела, редакційна політика, цифри й факти в тексті.
- SaaS / продукт: визначення продукту в першому абзаці, функції, ціни, інтеграції, порівняння з альтернативами, документація, SoftwareApplication, кейси.
- Послуги / B2B: сторінки послуг з процесом, термінами і вартістю «від», кейси з цифрами, експерти команди, Service або ProfessionalService, FAQ.
- Корпоративний сайт: Organization з sameAs, сторінка «Про компанію», історія, керівництво, контакти, прес-центр.
- Особистий сайт експерта: Person з jobTitle, knowsAbout, image і sameAs (LinkedIn та інші профілі), бажано разом з ProfilePage; сторінка «Про мене» зі спеціалізацією, досвідом і кейсами з цифрами; публікації, виступи, відгуки клієнтів; канал зв'язку (email, Telegram, LinkedIn або форма). Не вимагай телефону, адреси, реквізитів юрособи, Google Maps і LocalBusiness / Organization замість Person.

Стиль
- Українська мова, проста і жива, як у досвідченого консультанта. Без канцеляриту, води й маркетингових штампів.
- Звичайний дефіс «-» замість довгого тире, лапки «», три крапки "..." замість символу трикрапки, апостроф '.
- Назви ботів, типів schema.org, тегів і файлів - латиницею як є: GPTBot, Organization, robots.txt, llms.txt.
- Звичайний текст без markdown: без **, #, `, списків із зірочками. Код і розмітка - лише в полі example.
- Не рекомендуй Яндекс, ru.wikipedia і російські сервіси. Мови сайту - лише українська та англійська.

Формат відповіді: лише один JSON-об'єкт, без markdown і пояснень навколо, з полями:
- executive_summary: 3-4 речення - загальна готовність до AI-пошуку, головна сильна сторона, головне обмеження, що дасть найбільший ефект.
- eeat_assessment: {score: ціле 0-100, notes: 2-4 речення - які сигнали досвіду, експертизи, авторитетності й довіри видно в даних і яких бракує}.
- content_quality: {score: ціле 0-100, notes: 2-4 речення - наскільки текст придатний для цитування AI: конкретика, структура, прямі відповіді на запитання, факти й цифри}.
- top_priorities: 3-5 об'єктів, від найвпливовішого: {action: що зробити, коротко, у наказовому способі; impact: "Критичний" | "Високий" | "Середній"; effort: "S" (до 2 годин) | "M" (до 2 днів) | "L" (більше 2 днів або потрібні інші команди); why: чому це важливо саме для цього сайту, з посиланням на факт із даних; example: конкретний приклад для ЦЬОГО сайту, до 700 символів - лише сам готовий фрагмент, без пояснень навколо і без markdown-огорож: точний текст, фрагмент розмітки або рядки robots.txt, які варто додати, з реальними даними сайту (бренд, домен, назви розділів, URL з вибірки) і плейсхолдерами для відсутніх фактів}.
- quick_wins: 3-6 рядків - конкретні дії на годину-дві роботи.
- strategic_recommendations: 3-5 рядків - напрями роботи на 1-3 місяці.
- test_prompts: 8-10 реалістичних запитів українською, які потенційний клієнт такого сайту (з урахуванням типу і ніші) поставив би ChatGPT, Perplexity чи Gemini. 2-3 брендові (з назвою бренду), решта - небрендові: комерційні («де замовити...», «скільки коштує...», «яке агентство/магазин обрати для...») та інформаційні (як, що, чому). Формулюй так, як люди реально пишуть в AI-чат, без назв конкурентів. Ці запити стануть базою для ручного моніторингу видимості в AI.

Шкала оцінок score: 80-100 - сильні сигнали, підтверджені даними; 60-79 - база є, але помітні прогалини; 40-59 - слабко; нижче 40 - сигналів майже немає. Не завищуй оцінку без доказів.
TXT;
    }

    private static function repairPrompt(string $stopReason): string
    {
        $p = 'Твоя попередня відповідь не є валідним JSON';
        if ($stopReason === 'max_tokens') {
            $p .= ' - вона обірвалася на ліміті довжини. Дай повну відповідь коротше: стисліші notes і why, example до 400 символів';
        }
        return $p . '. Поверни лише один валідний JSON-об\'єкт за схемою з системних інструкцій (executive_summary, eeat_assessment, content_quality, top_priorities, quick_wins, strategic_recommendations, test_prompts), без markdown, коментарів і тексту навколо.';
    }

    // ------------------------------------------------------------------ user message

    /** Публічний, щоб dev/ai_test.php міг показати, що саме йде в модель (--dry). */
    public static function buildUserMessage(string $url, array $checkResults, array $context = []): string
    {
        $site = is_array($context['site'] ?? null) ? $context['site'] : [];
        $host = (string)($site['host'] ?? (parse_url($url, PHP_URL_HOST) ?: $url));
        $brand = trim((string)($site['brand'] ?? ''));
        $brandNote = '';
        if ($brand === '') {
            $label = preg_replace('/^www\./i', '', $host);
            $brand = ucfirst((string)explode('.', (string)$label)[0]);
            $brandNote = ' (визначено з домену, може бути неточним)';
        }

        $L = [];
        $L[] = 'Сайт для аналізу: ' . $url;
        $L[] = 'Дата аудиту: ' . date('Y-m-d');
        $L[] = '';

        // --- профіль
        $L[] = '## ПРОФІЛЬ САЙТУ';
        if ($site) {
            $type = trim((string)($site['type_label'] ?? '')) ?: (string)($site['type'] ?? '');
            $conf = isset($site['confidence']) && is_numeric($site['confidence']) ? ' (впевненість ' . round((float)$site['confidence'], 2) . ')' : '';
            $L[] = 'Тип: ' . ($type !== '' ? $type . (isset($site['type']) ? ' [' . $site['type'] . ']' : '') . $conf : 'не визначено');
            $sig = self::strArray($site['signals'] ?? [], 8);
            if ($sig) {
                $L[] = 'Ознаки типу: ' . implode('; ', $sig);
            }
        } else {
            $L[] = 'Тип: не визначено (профіль сайту не передано) - визнач тип обережно з назви, заголовків і тексту.';
        }
        $L[] = 'Бренд: ' . $brand . $brandNote;
        $L[] = 'Хост: ' . $host;
        $langs = self::strArray($site['languages'] ?? [], 6);
        if (!empty($site['lang']) || $langs) {
            $L[] = 'Мова сторінки: ' . ($site['lang'] ?? '-') . ($langs ? '; мовні версії: ' . implode(', ', $langs) : '');
        }
        $L[] = '';

        // --- доступ
        $L[] = '## ДОСТУП КРАУЛЕРА';
        $access = is_array($context['access'] ?? null) ? $context['access'] : [];
        $home = is_array($context['homepage'] ?? null) ? $context['homepage'] : [];
        $status = $access['homepage_status'] ?? ($home['status'] ?? null);
        $line = 'Головна: ' . ($status !== null ? 'HTTP ' . $status : 'статус невідомий');
        if (!empty($access['fetched_as'])) {
            $line .= ', завантажено як ' . ($access['fetched_as'] === 'browser' ? (!empty($access['alt_agent']) ? $access['alt_agent'] : 'звичайний браузер') : ($access['fetched_as'] === 'bot' ? 'бот' : 'не вдалося'));
        }
        $L[] = $line;
        if (!empty($access['lang_switch']['to'])) {
            $L[] = 'Корінь сайту (' . ($access['lang_switch']['from'] ?? '') . ') за замовчуванням віддає російськомовну версію; аудит проведено по українській ' . $access['lang_switch']['to'] . '. Аналізуй українську версію, російську не рекомендуй як основну.';
        }
        if (!empty($access['blocked']) && !empty($access['alt_agent'])) {
            $L[] = 'Сайт блокує невідомих ботів і браузерні запити з сервера аудиту' . (!empty($access['block_reason']) ? ' (' . $access['block_reason'] . ')' : '')
                . ', але пропускає ' . $access['alt_agent'] . ': контент отримано від його імені, тож AI-пошукові боти сторінки бачать. Не називай це блокуванням AI-ботів.';
        } elseif (!empty($access['blocked'])) {
            $L[] = 'УВАГА: сайт блокує автоматичні запити' . (!empty($access['block_reason']) ? ' (' . $access['block_reason'] . ')' : '')
                . '. Частину перевірок могло бути пропущено, текст сторінки може бути неповним або відсутнім. AI-боти, ймовірно, теж отримують блокування.';
        }
        $L[] = '';

        // --- головна
        $L[] = '## ГОЛОВНА СТОРІНКА';
        $pi = self::pageInfo($context);
        if ($pi['title'] === '' && $pi['description'] === '' && !$pi['h1'] && $pi['text'] === '') {
            $L[] = 'Дані головної сторінки недоступні (сторінку не вдалося отримати або розібрати).';
        } else {
            $L[] = 'Title: ' . ($pi['title'] !== '' ? self::cut($pi['title'], 200) : '(відсутній)');
            $L[] = 'Meta description: ' . ($pi['description'] !== '' ? self::cut($pi['description'], 300) : '(відсутній)');
            $h1 = self::strArray($pi['h1'], 3);
            $L[] = 'H1: ' . ($h1 ? implode(' | ', array_map(function ($s) { return self::cut($s, 150); }, $h1)) : '(відсутній)');
            $h2 = self::strArray($pi['h2'], 15);
            if ($h2) {
                $L[] = 'H2 (' . count($pi['h2']) . ($h2 && count($pi['h2']) > 15 ? ', перші 15' : '') . '): ' . implode(' | ', array_map(function ($s) { return self::cut($s, 120); }, $h2));
            } else {
                $L[] = 'H2: (відсутні)';
            }
            $L[] = 'Уривок видимого тексту (до ' . self::PAGE_TEXT_LIMIT . ' символів):';
            $L[] = $pi['text'] !== '' ? self::cut($pi['text'], self::PAGE_TEXT_LIMIT) : '(текст не отримано)';
        }
        $L[] = '';

        // --- вибірка
        $L[] = '## ВИБІРКА ВНУТРІШНІХ СТОРІНОК';
        $samples = is_array($context['samples'] ?? null) ? $context['samples'] : [];
        if (!$samples) {
            $L[] = 'Внутрішні сторінки не аналізувалися.';
        } else {
            $roles = ['about' => 'про компанію', 'contact' => 'контакти', 'article' => 'стаття', 'product' => 'товар',
                'category' => 'категорія', 'service' => 'послуга', 'other' => 'інше'];
            foreach (array_slice($samples, 0, 10) as $s) {
                if (!is_array($s)) continue;
                $role = (string)($s['role'] ?? 'other');
                $res = is_array($s['res'] ?? null) ? $s['res'] : [];
                $st = $s['status'] ?? ($res['status'] ?? null);
                [$title, $h1] = self::sampleTitleH1($s);
                $L[] = '- [' . ($roles[$role] ?? $role) . '] ' . ($s['url'] ?? '?')
                    . ' - ' . ($st !== null ? 'HTTP ' . $st : 'статус невідомий')
                    . ($title !== '' ? ' - title «' . self::cut($title, 120) . '»' : '')
                    . ($h1 !== '' ? ' - H1 «' . self::cut($h1, 120) . '»' : '');
                $sh2 = self::strArray($s['h2'] ?? [], 8);
                if ($sh2) $L[] = '  H2: ' . implode(' | ', array_map(function ($x) { return self::cut($x, 100); }, $sh2));
                $stext = trim((string)($s['text_excerpt'] ?? ''));
                if ($stext !== '') $L[] = '  Уривок основного тексту (до ' . self::SAMPLE_TEXT_LIMIT . ' символів): ' . self::cut($stext, self::SAMPLE_TEXT_LIMIT);
                if (!empty($s['js_only_words'])) $L[] = '  Ще ' . (int)$s['js_only_words'] . ' слів цієї сторінки є лише в даних JavaScript (у браузері видно, AI-боти не отримують).';
            }
        }
        $L[] = '';

        // --- перевірки
        $comp = self::composite($checkResults);
        $L[] = '## РЕЗУЛЬТАТИ ПЕРЕВІРОК' . ($comp !== null ? ' (зважена загальна оцінка без пропущених: ' . $comp . '/100)' : '');
        foreach ($checkResults as $r) {
            if (!is_array($r)) continue;
            $name = (string)($r['name'] ?? ($r['id'] ?? 'Перевірка'));
            $id = isset($r['id']) ? ' [' . $r['id'] . ']' : '';
            $skipped = ($r['status'] ?? 'ok') === 'skipped' || !isset($r['score']) || !is_numeric($r['score']);
            $w = isset($r['weight']) && is_numeric($r['weight']) ? ', вага ' . round((float)$r['weight'], 2) : '';
            $L[] = '';
            $L[] = '### ' . $name . $id . ' - ' . ($skipped ? 'ПРОПУЩЕНО (не вдалося оцінити)' : (int)$r['score'] . '/100') . $w;
            if (!empty($r['summary']) && is_string($r['summary'])) {
                $L[] = 'Підсумок: ' . self::cut($r['summary'], 300);
            }
            if (!empty($r['sub_scores']) && is_array($r['sub_scores'])) {
                $labels = is_array($r['sub_labels'] ?? null) ? $r['sub_labels'] : [];
                $sub = [];
                foreach ($r['sub_scores'] as $k => $v) {
                    if ($v !== null && !is_scalar($v)) continue;
                    $sub[] = (string)($labels[$k] ?? $k) . ' ' . ($v === null ? 'н/д' : (is_numeric($v) ? (int)round((float)$v) : (string)$v));
                }
                if ($sub) {
                    $L[] = 'Підоцінки: ' . implode('; ', $sub);
                }
            }
            $findings = is_array($r['findings'] ?? null) ? $r['findings'] : [];
            if ($findings) {
                $L[] = 'Знахідки (' . count($findings) . '):';
                foreach ($findings as $f) {
                    if (!is_array($f)) continue;
                    $row = '- [' . ($f['severity'] ?? '?') . '] ' . self::cut((string)($f['text'] ?? ''), 200);
                    if (!empty($f['evidence']) && is_string($f['evidence'])) {
                        $row .= ' | Доказ: ' . self::cut($f['evidence'], self::EVIDENCE_LIMIT);
                    } elseif (!empty($f['fix']) && is_string($f['fix'])) {
                        $row .= ' | Рекомендація перевірки: ' . self::cut($f['fix'], 200);
                    }
                    $L[] = $row;
                }
            } else {
                $L[] = 'Знахідок немає.';
            }
            $passed = self::strArray($r['passed'] ?? [], 5);
            if ($passed) {
                $L[] = 'Вже добре: ' . implode('; ', array_map(function ($s) { return self::cut($s, 150); }, $passed));
            }
        }
        $L[] = '';
        $L[] = 'Сформуй висновок за схемою із системних інструкцій. Лише факти з цих даних; чого бракує - так і скажи. Бренд для брендових test_prompts: ' . $brand . '.';

        return implode("\n", $L);
    }

    private static function composite(array $results): ?int
    {
        $sum = 0.0;
        $w = 0.0;
        foreach ($results as $r) {
            if (!is_array($r) || ($r['status'] ?? 'ok') === 'skipped' || !isset($r['score']) || !is_numeric($r['score'])) continue;
            $wt = isset($r['weight']) && is_numeric($r['weight']) ? (float)$r['weight'] : 0.0;
            $sum += (float)$r['score'] * $wt;
            $w += $wt;
        }
        return $w > 0 ? (int)round($sum / $w) : null;
    }

    // ------------------------------------------------------------------ page facts

    /**
     * title/description/h1/h2/text головної: з Page (парсер рушія), а якщо його немає -
     * будуємо Page з сирого тіла відповіді краулера. Заблоковану сторінку (челендж) не розбираємо.
     */
    private static function pageInfo(array $context): array
    {
        $info = ['title' => '', 'description' => '', 'h1' => [], 'h2' => [], 'text' => ''];
        $page = $context['page'] ?? null;
        // AuditEngine::aiContext() передає головну масивом (title, description, h1, h2, text_excerpt), а не об'єктом Page
        if (is_array($page)) {
            $context['page_info'] = array_merge([
                'title' => $page['title'] ?? '', 'description' => $page['description'] ?? '',
                'h1' => $page['h1'] ?? [], 'h2' => $page['h2'] ?? [], 'text' => $page['text_excerpt'] ?? ($page['text'] ?? ''),
            ], is_array($context['page_info'] ?? null) ? $context['page_info'] : []);
            $page = null;
        }
        $home = is_array($context['homepage'] ?? null) ? $context['homepage'] : [];
        if (!$page && empty($context['access']['blocked']) && trim((string)($home['body'] ?? '')) !== '' && self::loadPage()) {
            try {
                $page = Page::fromCrawl($home);
            } catch (Throwable $e) {
                $page = null;
            }
        }
        if (is_object($page) && class_exists('Page', false) && $page instanceof Page) {
            try {
                $hs = $page->headings();
                $info = [
                    'title' => self::clean($page->title()),
                    'description' => self::clean($page->metaDescription()),
                    'h1' => self::strArray($hs['h1'] ?? [], 10),
                    'h2' => self::strArray($hs['h2'] ?? [], 40),
                    'text' => self::cut(self::clean($page->mainText()), self::PAGE_TEXT_LIMIT),
                ];
            } catch (Throwable $e) {
                // зламаний HTML не повинен валити AI-блок
            }
        }
        // Явні факти від виклику (dev-скрипти, старі рушії) мають пріоритет.
        foreach ((is_array($context['page_info'] ?? null) ? $context['page_info'] : []) as $k => $v) {
            if (!array_key_exists($k, $info) || $v === '' || $v === [] || $v === null) continue;
            $info[$k] = is_array($info[$k]) ? self::strArray((array)$v, 40) : self::cut(self::clean((string)$v), self::PAGE_TEXT_LIMIT);
        }
        return $info;
    }

    /** title і перший H1 сторінки вибірки (Page або плоскі поля з уже збереженого звіту). */
    private static function sampleTitleH1(array $s): array
    {
        $tv = $s['title'] ?? '';
        $hv = $s['h1'] ?? '';
        $title = is_array($tv) ? (string)($tv[0] ?? '') : (string)$tv;
        $h1 = is_array($hv) ? (string)($hv[0] ?? '') : (string)$hv;
        $page = $s['page'] ?? null;
        if (is_object($page) && class_exists('Page', false) && $page instanceof Page) {
            try {
                $title = $page->title() ?: $title;
                $h1 = ($page->headings()['h1'][0] ?? '') ?: $h1;
            } catch (Throwable $e) {
            }
        }
        return [self::clean($title), self::clean($h1)];
    }

    private static function loadPage(): bool
    {
        if (!class_exists('Page', false) && is_file(__DIR__ . '/Page.php')) {
            require_once __DIR__ . '/Page.php';
        }
        return class_exists('Page', false);
    }

    // ------------------------------------------------------------------ JSON parsing & normalization

    private static function extractJson(string $s): ?array
    {
        $s = trim(preg_replace('/^\xEF\xBB\xBF/', '', $s));
        if ($s === '') return null;
        $d = json_decode($s, true);
        if (is_array($d)) return $d;

        $cands = [];
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $s, $m)) {
            $cands[] = $m[1];
        }
        $obj = self::outermostObject($s);
        if ($obj !== null) $cands[] = $obj;
        $a = strpos($s, '{');
        $b = strrpos($s, '}');
        if ($a !== false && $b !== false && $b > $a) $cands[] = substr($s, $a, $b - $a + 1);

        foreach ($cands as $c) {
            $d = json_decode($c, true);
            if (is_array($d)) return $d;
            $fixed = preg_replace('/,\s*([}\]])/', '$1', $c); // хвостові коми
            $d = json_decode((string)$fixed, true);
            if (is_array($d)) return $d;
        }
        return null;
    }

    /** Перший збалансований {...} з урахуванням рядків і екранування. */
    private static function outermostObject(string $s): ?string
    {
        $start = strpos($s, '{');
        if ($start === false) return null;
        $depth = 0;
        $inStr = false;
        $esc = false;
        $n = strlen($s);
        for ($i = $start; $i < $n; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($esc) { $esc = false; }
                elseif ($c === '\\') { $esc = true; }
                elseif ($c === '"') { $inStr = false; }
                continue;
            }
            if ($c === '"') { $inStr = true; }
            elseif ($c === '{') { $depth++; }
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) return substr($s, $start, $i - $start + 1);
            }
        }
        return null;
    }

    private static function normalizeSections(array $d, array $checkResults): array
    {
        // v1 і близькі варіанти ключів
        $prio = $d['top_priorities'] ?? ($d['top_3_priorities'] ?? ($d['priorities'] ?? []));
        $out = [
            'executive_summary' => self::text($d['executive_summary'] ?? ($d['summary'] ?? '')),
            'eeat_assessment' => self::scoreNotes($d['eeat_assessment'] ?? ($d['eeat'] ?? null), self::checkScore($checkResults, 'eeat', '/E-?E-?A-?T/i')),
            'content_quality' => self::scoreNotes($d['content_quality'] ?? null, self::checkScore($checkResults, 'citability', '/citab|цитован/iu')),
            'top_priorities' => [],
            'quick_wins' => self::textList($d['quick_wins'] ?? [], self::MAX_QUICK_WINS),
            'strategic_recommendations' => self::textList($d['strategic_recommendations'] ?? ($d['recommendations'] ?? []), self::MAX_STRATEGIC),
            'test_prompts' => [],
        ];
        if (is_array($prio)) {
            foreach ($prio as $p) {
                if (is_string($p)) {
                    $p = ['action' => $p];
                }
                if (!is_array($p)) continue;
                $action = self::text($p['action'] ?? ($p['title'] ?? ($p['task'] ?? '')));
                if ($action === '') continue;
                $out['top_priorities'][] = [
                    'action' => $action,
                    'impact' => self::impact($p['impact'] ?? ($p['priority'] ?? '')),
                    'effort' => self::effortCode($p['effort'] ?? ''),
                    'why' => self::text($p['why'] ?? ($p['reason'] ?? '')),
                    'example' => self::example($p['example'] ?? ($p['code'] ?? '')),
                ];
                if (count($out['top_priorities']) >= self::MAX_PRIORITIES) break;
            }
        }
        $tp = [];
        foreach (self::textList($d['test_prompts'] ?? [], 30) as $q) {
            $q = trim($q, " \t\"'«»");
            $key = mb_strtolower($q);
            if ($q === '' || isset($tp[$key])) continue;
            $tp[$key] = $q;
        }
        $out['test_prompts'] = array_slice(array_values($tp), 0, self::MAX_TEST_PROMPTS);
        return $out;
    }

    private static function scoreNotes($v, ?int $fallback): array
    {
        $score = null;
        $notes = '';
        if (is_array($v)) {
            $score = self::score($v['score'] ?? ($v['value'] ?? null));
            $notes = self::text($v['notes'] ?? ($v['comment'] ?? ($v['text'] ?? '')));
        } elseif (is_numeric($v)) {
            $score = self::score($v);
        } elseif (is_string($v)) {
            $notes = self::text($v);
        }
        return ['score' => $score ?? ($fallback ?? 0), 'notes' => $notes];
    }

    private static function score($v): ?int
    {
        if (is_int($v) || is_float($v)) return max(0, min(100, (int)round($v)));
        if (is_string($v) && preg_match('/-?\d+(?:[.,]\d+)?/', $v, $m)) {
            return max(0, min(100, (int)round((float)str_replace(',', '.', $m[0]))));
        }
        return null;
    }

    private static function checkScore(array $results, string $id, string $nameRe): ?int
    {
        foreach ($results as $r) {
            if (!is_array($r)) continue;
            if ((($r['id'] ?? '') === $id || preg_match($nameRe, (string)($r['name'] ?? ''))) && isset($r['score']) && is_numeric($r['score'])) {
                return max(0, min(100, (int)$r['score']));
            }
        }
        return null;
    }

    private static function impact($v): string
    {
        $s = mb_strtolower(trim((string)(is_scalar($v) ? $v : '')));
        if ($s === '') return 'Середній';
        if (strpos($s, 'крит') !== false || strpos($s, 'critical') !== false) return 'Критичний';
        if (strpos($s, 'вис') !== false || strpos($s, 'high') !== false) return 'Високий';
        return 'Середній';
    }

    private static function effortCode($v): string
    {
        $raw = trim((string)(is_scalar($v) ? $v : ''));
        $u = strtoupper($raw);
        if (in_array($u, ['S', 'M', 'L'], true)) return $u;
        if ($u === 'XS') return 'S';
        if ($u === 'XL') return 'L';
        $s = mb_strtolower($raw);
        foreach (['low', 'small', 'easy', 'низ', 'мал', 'легк', 'годин', 'швидк'] as $k) {
            if (strpos($s, $k) !== false) return 'S';
        }
        foreach (['high', 'large', 'hard', 'вис', 'вели', 'склад', 'тиж', 'міс'] as $k) {
            if (strpos($s, $k) !== false) return 'L';
        }
        return 'M';
    }

    private static function textList($v, int $max): array
    {
        if (is_string($v)) {
            $v = preg_split('/\R+/u', $v);
        }
        if (!is_array($v)) return [];
        $out = [];
        $seen = [];
        foreach ($v as $item) {
            if (is_array($item)) {
                $item = implode(' - ', array_filter(array_map(function ($x) { return is_scalar($x) ? trim((string)$x) : ''; }, $item), 'strlen'));
            }
            if (!is_scalar($item)) continue;
            $s = self::text((string)$item);
            $s = preg_replace('/^\s*(?:[-*\x{2022}]|\d+[.)])\s+/u', '', $s);
            $k = mb_strtolower($s);
            if ($s === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $s;
            if (count($out) >= $max) break;
        }
        return $out;
    }

    /** Рядок + стильові правила (дефіс замість тире, «» замість кривих лапок, ... замість …, апостроф '). */
    private static function text($v, bool $collapse = true): string
    {
        if (is_array($v)) {
            $v = implode(' ', array_filter(array_map(function ($x) { return is_scalar($x) ? (string)$x : ''; }, $v), 'strlen'));
        }
        if (!is_scalar($v)) return '';
        $s = (string)$v;
        $s = str_replace(["\u{2014}", "\u{2013}", "\u{2012}", "\u{2015}", "\u{2011}"], '-', $s);
        $s = str_replace("\u{2026}", '...', $s);
        $s = (string)preg_replace('/\x{201E}([^\x{201C}\x{201D}\x{201E}]*)[\x{201C}\x{201D}]/u', '«$1»', $s);
        $s = (string)preg_replace('/\x{201C}([^\x{201C}\x{201D}]*)\x{201D}/u', '«$1»', $s);
        $s = str_replace(["\u{201E}", "\u{201C}", "\u{201D}"], ['«', '«', '»'], $s);
        $s = str_replace(["\u{2018}", "\u{2019}", "\u{02BC}"], "'", $s);
        if ($collapse) {
            // звичайний текст: без markdown, UI показує його як є
            $s = str_replace(['**', '__', '`'], '', $s);
            $s = (string)preg_replace('/^#{1,6}\s+/mu', '', $s);
            $s = (string)preg_replace('/[ \t]+/u', ' ', $s);
        }
        return trim($s);
    }

    /** Приклад (текст або код): переноси рядків зберігаються, markdown-огорожі ``` прибираються. */
    private static function example($v): string
    {
        $s = self::text($v, false);
        $s = (string)preg_replace('/^```[\w-]*[ \t]*\R?/u', '', $s);
        $s = (string)preg_replace('/\R?```\s*$/u', '', $s);
        return trim($s);
    }

    // ------------------------------------------------------------------ small helpers

    private static function strArray($v, int $max): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $x) {
            if (is_array($x)) $x = $x['text'] ?? '';
            if (!is_scalar($x)) continue;
            $s = self::clean((string)$x);
            if ($s !== '') $out[] = $s;
            if (count($out) >= $max) break;
        }
        return $out;
    }

    private static function clean(string $s): string
    {
        return trim((string)preg_replace('/[\s\x{00A0}\x{2007}\x{202F}]+/u', ' ', $s));
    }

    private static function cut(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n)) . '...' : $s;
    }
}
