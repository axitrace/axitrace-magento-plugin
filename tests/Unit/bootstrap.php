<?php

declare(strict_types=1);

/**
 * Framework-free bootstrap for the module's unit tests.
 *
 * The module ships no `vendor/` of its own, so CI installs Magento and runs
 * `vendor/bin/phpunit --configuration tests/phpunit.xml` against the composer
 * autoloader. This bootstrap is the local alternative: a PSR-4 autoloader for
 * `AxiTrace\Tracking\` that loads no Magento class, so the framework-free parts
 * of the module (for example Model/Consent) can be tested with any PHPUnit
 * binary available in this repository.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'AxiTrace\\Tracking\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    // `AxiTrace\Tracking\Tests\...` lives under the lowercase `tests/` directory;
    // everything else maps straight onto the module root.
    if (str_starts_with($relative, 'Tests\\')) {
        $relative = 'tests\\' . substr($relative, strlen('Tests\\'));
    }

    $file = dirname(__DIR__, 2) . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
