<?php
/**
 * Runtime autoloader for the bundled Creators API SDK and Composer dependencies.
 * The repository contains the generated SDK under src/ but not its Composer
 * generated root directory, so register its PSR-4 namespace explicitly.
 */
require_once __DIR__ . '/vendor/autoload.php';

spl_autoload_register(function ($class) {
    $prefix = 'Amazon\\CreatorsAPI\\v1\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
