<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';

class Database
{
    private $pdo;
    private static $instance = null;

    /**
     * Каталог даних: GEO_AUDIT_DATA або data/ у корені проєкту. На nginx, який ігнорує .htaccess,
     * винесіть дані за межі webroot через GEO_AUDIT_DATA, інакше їх можна завантажити за прямим посиланням.
     */
    public static function dataDir(): string
    {
        $env = getenv('GEO_AUDIT_DATA');
        if ($env) return rtrim($env, '/');
        return __DIR__ . '/../data';
    }

    private function __construct()
    {
        $dataDir = self::dataDir();
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $dbPath = $dataDir . '/jobs.sqlite';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("PRAGMA journal_mode = WAL");
        $this->migrate();
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
            id TEXT PRIMARY KEY,
            url TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'queued',
            progress INTEGER NOT NULL DEFAULT 0,
            stage TEXT,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            finished_at INTEGER,
            score INTEGER,
            result_json TEXT,
            error TEXT
        )");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_created ON jobs(created_at DESC)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)");
    }

    public function createJob(string $url): string
    {
        $id = bin2hex(random_bytes(8));
        $now = time();
        $stmt = $this->pdo->prepare("INSERT INTO jobs (id, url, status, created_at, updated_at) VALUES (?, ?, 'queued', ?, ?)");
        $stmt->execute([$id, $url, $now, $now]);
        return $id;
    }

    public function getJob(string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateProgress(string $id, int $progress, string $stage): void
    {
        $stmt = $this->pdo->prepare("UPDATE jobs SET progress = ?, stage = ?, updated_at = ?, status = 'running' WHERE id = ?");
        $stmt->execute([$progress, $stage, time(), $id]);
    }

    public function setRunning(string $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE jobs SET status = 'running', updated_at = ? WHERE id = ?");
        $stmt->execute([time(), $id]);
    }

    public function finishJob(string $id, int $score, string $resultJson): void
    {
        $now = time();
        $stmt = $this->pdo->prepare("UPDATE jobs SET status = 'done', progress = 100, stage = 'completed', score = ?, result_json = ?, updated_at = ?, finished_at = ? WHERE id = ?");
        $stmt->execute([$score, $resultJson, $now, $now, $id]);
    }

    public function failJob(string $id, string $error): void
    {
        $now = time();
        $stmt = $this->pdo->prepare("UPDATE jobs SET status = 'error', error = ?, updated_at = ?, finished_at = ? WHERE id = ?");
        $stmt->execute([$error, $now, $now, $id]);
    }

    public function countJobs(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
    }

    /**
     * Видаляє аудити за id. Аудити, що виконуються, не чіпає (лише завислі: без оновлень понад 30 хв).
     * Повертає id видалених.
     */
    public function deleteJobs(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, function ($id) { return is_string($id) && preg_match('/^[a-f0-9]{16}$/', $id); })));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id FROM jobs WHERE id IN ($in) AND (status NOT IN ('queued', 'running') OR updated_at < ?)");
        $stmt->execute(array_merge($ids, [time() - 1800]));
        $del = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$del) return [];
        $in = implode(',', array_fill(0, count($del), '?'));
        $this->pdo->prepare("DELETE FROM jobs WHERE id IN ($in)")->execute($del);
        return array_values(array_map('strval', $del));
    }

    public function listJobs(int $limit = 50): array
    {
        // coverage: частка ваги оцінених перевірок, рахується з result_json (працює і для старих звітів)
        $cov = "(SELECT SUM(CASE WHEN COALESCE(json_extract(c.value, '$.status'), 'ok') = 'ok' AND json_extract(c.value, '$.score') IS NOT NULL
                    THEN json_extract(c.value, '$.weight') ELSE 0 END) * 1.0 / NULLIF(SUM(json_extract(c.value, '$.weight')), 0)
                 FROM json_each(CASE WHEN json_valid(jobs.result_json) THEN jobs.result_json END, '$.checks') c)";
        try {
            $stmt = $this->pdo->prepare("SELECT id, url, status, progress, stage, score, created_at, finished_at, $cov AS coverage FROM jobs ORDER BY created_at DESC LIMIT ?");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            // SQLite без JSON1: список без покриття
        }
        $stmt = $this->pdo->prepare("SELECT id, url, status, progress, stage, score, created_at, finished_at FROM jobs ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
