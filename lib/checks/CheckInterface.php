<?php
declare(strict_types=1);

interface CheckInterface
{
    public function name(): string;
    public function weight(): float;
    public function run(string $url, array $ctx): array;
}
