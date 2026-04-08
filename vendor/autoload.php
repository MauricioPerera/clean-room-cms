<?php
/**
 * Clean Room CMS - Vendor Autoloader
 *
 * PSR-4 style autoloader for vendored libraries.
 */

spl_autoload_register(function (string $class): void {
    $map = [
        'PHPVectorStore\\' => __DIR__ . '/php-vector-store/',
    ];

    foreach ($map as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relative = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
