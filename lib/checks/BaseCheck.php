<?php
declare(strict_types=1);

require_once __DIR__ . '/CheckInterface.php';
require_once __DIR__ . '/../Crawler.php';
require_once __DIR__ . '/../Page.php';

/**
 * Базовий клас перевірок v2. Дає єдиний формат результату (див. контракт CheckResult/Finding)
 * і дрібні хелпери для доказів («як зараз» / «як треба»).
 *
 *   class SchemaCheck extends BaseCheck {
 *       public function id(): string { return 'schema'; }
 *       public function name(): string { return 'Структуровані дані'; }
 *       public function weight(): float { return 0.12; }
 *       public function run(string $url, array $ctx): array {
 *           $page = $ctx['page'] ?? null;
 *           if (!$page) return $this->skipped('Головна сторінка недоступна для бота');
 *           ...
 *           return $this->result($score, $sub, $labels, $findings, $passed, $data, $summary);
 *       }
 *   }
 */
abstract class BaseCheck implements CheckInterface
{
    const SEVERITIES = ['critical' => 1, 'high' => 1, 'medium' => 1, 'low' => 1];
    const EFFORTS = ['S' => 1, 'M' => 1, 'L' => 1];

    /** Ліміти довжини полів знахідки (символи). */
    const MAX_TEXT = 300;
    const MAX_EVIDENCE = 900;
    const MAX_EXAMPLE = 600;   // bad / good
    const MAX_PROSE = 1200;    // why / fix / verify
    const MAX_CODE = 6000;

    /** Стабільний slug перевірки, напр. 'schema'. */
    abstract public function id(): string;

    abstract public function name(): string;

    abstract public function weight(): float;

    abstract public function run(string $url, array $ctx): array;

    // ------------------------------------------------------------------ result builders

    /**
     * Знахідка за контрактом. $id без крапки автоматично отримує префікс "<id перевірки>.".
     * $opts: evidence, why, fix, bad, good, code, verify, effort ('S'|'M'|'L').
     */
    protected function finding(string $id, string $severity, string $text, array $opts = []): array
    {
        if (strpos($id, '.') === false) $id = $this->id() . '.' . $id;
        $severity = strtolower(trim($severity));
        if (!isset(self::SEVERITIES[$severity])) $severity = 'medium';
        $effort = strtoupper(trim((string)($opts['effort'] ?? '')));
        if ($effort !== '' && !isset(self::EFFORTS[$effort])) $effort = 'M';

        return [
            'id'       => $id,
            'severity' => $severity,
            'text'     => self::mbTrunc(self::clean($text), self::MAX_TEXT),
            'evidence' => self::mbTrunc(self::clean((string)($opts['evidence'] ?? '')), self::MAX_EVIDENCE),
            'why'      => self::mbTrunc(self::clean((string)($opts['why'] ?? '')), self::MAX_PROSE),
            'fix'      => self::mbTrunc(self::clean((string)($opts['fix'] ?? '')), self::MAX_PROSE),
            'bad'      => self::mbTrunc(self::cleanBlock((string)($opts['bad'] ?? '')), self::MAX_EXAMPLE),
            'good'     => self::mbTrunc(self::cleanBlock((string)($opts['good'] ?? '')), self::MAX_EXAMPLE),
            'code'     => self::mbTrunc(self::cleanBlock((string)($opts['code'] ?? '')), self::MAX_CODE),
            'verify'   => self::mbTrunc(self::clean((string)($opts['verify'] ?? '')), self::MAX_PROSE),
            'effort'   => $effort,
        ];
    }

    /**
     * Результат зі status 'ok'. Якщо $score === null - рахується як середнє ненульових $subScores;
     * якщо й таких немає, повертається skipped.
     */
    protected function result(?int $score, array $subScores, array $subLabels, array $findings, array $passed = [], array $data = [], string $summary = ''): array
    {
        $sub = [];
        foreach ($subScores as $k => $v) {
            $sub[(string)$k] = ($v === null) ? null : max(0, min(100, (int)round((float)$v)));
        }
        if ($score === null) {
            $vals = array_filter($sub, function ($v) { return $v !== null; });
            if (!$vals) {
                return $this->skipped($summary !== '' ? $summary : 'Немає даних для оцінки', $data);
            }
            $score = (int)round(array_sum($vals) / count($vals));
        }
        $labels = [];
        foreach ($sub as $k => $_) {
            $labels[$k] = isset($subLabels[$k]) && $subLabels[$k] !== '' ? (string)$subLabels[$k] : self::humanizeKey($k);
        }
        $passedOut = [];
        foreach ($passed as $p) {
            $p = self::clean((string)$p);
            if ($p !== '') $passedOut[] = self::mbTrunc($p, self::MAX_TEXT);
        }

        return [
            'id'         => $this->id(),
            'name'       => $this->name(),
            'weight'     => $this->weight(),
            'status'     => 'ok',
            'score'      => max(0, min(100, (int)$score)),
            'summary'    => self::mbTrunc(self::clean($summary), 400),
            'sub_scores' => $sub,
            'sub_labels' => $labels,
            'passed'     => array_values(array_unique($passedOut)),
            'findings'   => self::sortFindings($findings),
            'data'       => $data,
        ];
    }

    /** Перевірку не вдалося оцінити: score null, не входить у загальний бал. */
    protected function skipped(string $reason, array $data = []): array
    {
        $reason = self::clean($reason);
        if ($reason === '') $reason = 'Немає даних для оцінки';
        $f = $this->finding('skipped', 'low', 'Перевірку «' . $this->name() . '» не виконано', [
            'evidence' => $reason,
            'why'      => 'Без цих даних оцінка була б вигаданою, тому бал перевірки не враховано в загальній оцінці.',
            'fix'      => 'Усуньте причину (доступ для ботів, помилку сервера) і запустіть аудит повторно.',
            'bad'      => $reason,
            'good'     => 'Сторінка віддає повний HTML звичайному боту, перевірка отримує дані й виставляє бал.',
            'verify'   => 'Повторний аудит: перевірка має отримати числовий бал.',
            'effort'   => 'S',
        ]);
        return [
            'id'         => $this->id(),
            'name'       => $this->name(),
            'weight'     => $this->weight(),
            'status'     => 'skipped',
            'score'      => null,
            'summary'    => self::mbTrunc('Не оцінено: ' . $reason, 400),
            'sub_scores' => [],
            'sub_labels' => [],
            'passed'     => [],
            'findings'   => [$f],
            'data'       => $data + ['skip_reason' => $reason],
        ];
    }

    /**
     * Зважене середнє: [key => [int|null score, float weight]] (або [key => int|null] з вагою 1).
     * null ігнорується; якщо все null - 0.
     */
    protected function scoreFrom(array $components): int
    {
        $sum = 0.0; $w = 0.0;
        foreach ($components as $c) {
            if (is_array($c)) { $s = $c[0] ?? null; $wt = (float)($c[1] ?? 1.0); }
            else { $s = $c; $wt = 1.0; }
            if ($s === null || $wt <= 0) continue;
            $sum += max(0, min(100, (float)$s)) * $wt;
            $w += $wt;
        }
        return $w > 0 ? (int)round($sum / $w) : 0;
    }

    // ------------------------------------------------------------------ static helpers

    /** Обрізає рядок до $n символів (mb-safe) з « ...» в кінці. */
    public static function mbTrunc(string $s, int $n): string
    {
        if ($n <= 0 || mb_strlen($s) <= $n) return $s;
        $cut = mb_substr($s, 0, max(1, $n - 4));
        // не рвемо слово, якщо пробіл недалеко
        $sp = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > mb_strlen($cut) - 25) $cut = mb_substr($cut, 0, $sp);
        return rtrim($cut) . ' ...';
    }

    /**
     * Короткий фрагмент вихідного коду навколо першого збігу regex (для поля 'bad').
     * Пробіли згортаються, по краях « ...». '' якщо збігу немає.
     */
    public static function snippet(string $html, string $needleRegex, int $radius = 120): string
    {
        if ($html === '') return '';
        $ok = @preg_match($needleRegex, $html, $m, PREG_OFFSET_CAPTURE);
        if (!$ok) return '';
        $pos = (int)$m[0][1];
        $len = strlen($m[0][0]);
        $radius = max(0, $radius);
        $start = max(0, $pos - $radius * 2);           // байти: кирилиця - 2 байти на символ
        $end = min(strlen($html), $pos + $len + $radius * 2);
        $frag = mb_strcut($html, $start, $end - $start, 'UTF-8');
        $frag = trim((string)preg_replace('/\s+/u', ' ', $frag));
        if ($frag === '') $frag = trim(preg_replace('/\s+/', ' ', mb_scrub(substr($html, $start, $end - $start), 'UTF-8')));
        // обмежуємо за символами
        $maxChars = $len + $radius * 2;
        if (mb_strlen($frag) > $maxChars + 40) $frag = mb_substr($frag, 0, $maxChars + 40);
        return ($start > 0 ? '... ' : '') . $frag . ($end < strlen($html) ? ' ...' : '');
    }

    /** JSON для прикладів коду: pretty + кирилиця і слеші без екранування. */
    public static function jsonPretty($arr): string
    {
        $s = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $s === false ? '' : str_replace('    ', '  ', $s);
    }

    /** JSON-LD у тегу script, готовий до вставки. */
    public static function jsonLdScript(array $data): string
    {
        return "<script type=\"application/ld+json\">\n" . self::jsonPretty($data) . "\n</script>";
    }

    /** Перелік через кому з обрізанням: «a, b, c та ще 4». */
    public static function listSome(array $items, int $max = 5): string
    {
        $items = array_values(array_filter(array_map('strval', $items), function ($s) { return $s !== ''; }));
        if (!$items) return '';
        $head = array_slice($items, 0, $max);
        $rest = count($items) - count($head);
        return implode(', ', $head) . ($rest > 0 ? ' та ще ' . $rest : '');
    }

    // ------------------------------------------------------------------ internals

    private static function sortFindings(array $findings): array
    {
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $findings = array_values(array_filter($findings, 'is_array'));
        $idx = array_keys($findings);
        usort($idx, function ($a, $b) use ($findings, $order) {
            $sa = $order[$findings[$a]['severity'] ?? 'low'] ?? 3;
            $sb = $order[$findings[$b]['severity'] ?? 'low'] ?? 3;
            return $sa === $sb ? $a <=> $b : $sa <=> $sb;
        });
        $out = [];
        foreach ($idx as $i) $out[] = $findings[$i];
        return $out;
    }

    /** Однорядковий текст: прибирає керуючі символи та зайві пробіли. */
    private static function clean(string $s): string
    {
        if ($s === '') return '';
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_scrub($s, 'UTF-8');
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        // стиль звіту: дефіс замість довгих тире і «...» замість символу трикрапки (також у цитатах із сайту)
        $s = str_replace(['—', '–', '…'], ['-', '-', '...'], (string)$s);
        return trim((string)preg_replace('/[ \t]+/u', ' ', (string)$s));
    }

    /** Багаторядковий текст (код/приклади): зберігає переноси, прибирає керуючі символи. */
    private static function cleanBlock(string $s): string
    {
        if ($s === '') return '';
        if (!mb_check_encoding($s, 'UTF-8')) $s = mb_scrub($s, 'UTF-8');
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        return trim((string)$s, "\n\r ");
    }

    private static function humanizeKey(string $k): string
    {
        $s = str_replace(['_', '-'], ' ', $k);
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
