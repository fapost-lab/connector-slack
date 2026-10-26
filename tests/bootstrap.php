<?php

declare(strict_types=1);

/**
 * Test bootstrap: the package is tested inside the real FaPost Core application, with Core's testing kit.
 *
 * Core is found from FAPOST_CORE_PATH or, without it, as the first ancestor that has `artisan` and
 * `vendor/autoload.php` — so packages/connector-slack and its git worktrees under
 * packages/connector-slack.worktrees/<id> both find it. The kit then maps this package's own `src/` and `tests/`
 * ahead of Core's autoloader. See https://docs.fapost.in/extending/testing.
 */

$core = getenv('FAPOST_CORE_PATH') ?: (static function (string $dir): string {
    while (dirname($dir) !== $dir) {
        $dir = dirname($dir);

        if (is_file($dir . '/artisan') && is_file($dir . '/vendor/autoload.php')) {
            return $dir;
        }
    }

    fwrite(STDERR, "FaPost Core was not found. Set FAPOST_CORE_PATH, or check the package out into Core's packages/ directory.\n");

    exit(1);
})(__DIR__);

require $core . '/vendor/autoload.php';

Fapost\Testing\PackageAutoloader::register(dirname(__DIR__));
