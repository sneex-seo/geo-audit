<?php
declare(strict_types=1);

/**
 * Змінні з .env у корені проєкту потрапляють у середовище процесу (getenv).
 * Змінні, задані на сервері, мають пріоритет і не перезаписуються.
 */
function geo_load_env(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $file = dirname(__DIR__) . '/.env';
    if (!is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\"'");
        if ($name === '' || $value === '' || getenv($name) !== false) continue;
        putenv($name . '=' . $value);
    }
}

geo_load_env();
