<?php

declare(strict_types=1);

namespace Screenshots;

final class Config
{
    /** @return array<string, mixed> config.php merged over config.defaults.php */
    public static function load(string $root): array
    {
        $file = $root . '/config.php';
        if (!is_file($file)) {
            throw new \RuntimeException('config.php is missing, see config.defaults.php');
        }

        return (require $file) + (require $root . '/config.defaults.php');
    }
}
