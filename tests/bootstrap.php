<?php

spl_autoload_register(static function ($class) {
    $prefix = 'LlmsCatalog\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $path = dirname(__DIR__) . '/llmscatalog/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

/*
 * Optional PrestaShop bootstrap for the integration tests.
 *
 * Set PS_TEST_SHOP_ROOT to the root of a PrestaShop 9 shop (a directory that
 * contains config/config.inc.php) to expose the core classes (Product,
 * StockAvailable, Db) and the shop database. The Docker test shop exposes its
 * shop at /var/www/html, so run the suite inside the container with:
 *
 *   PS_TEST_SHOP_ROOT=/var/www/html vendor/bin/phpunit -c phpunit.xml.dist
 *
 * When the variable is unset, the integration tests self-skip and the unit
 * suite still runs standalone.
 */
$shopRoot = getenv('PS_TEST_SHOP_ROOT');
if (is_string($shopRoot) && $shopRoot !== '' && !defined('_PS_VERSION_')) {
    $configFile = rtrim($shopRoot, '/') . '/config/config.inc.php';
    if (!is_file($configFile)) {
        fwrite(STDERR, sprintf("PS_TEST_SHOP_ROOT is set but %s was not found.\n", $configFile));
    } else {
        if (PHP_SAPI === 'cli') {
            $_SERVER['HTTP_HOST'] ??= 'localhost';
            $_SERVER['SERVER_NAME'] ??= 'localhost';
            $_SERVER['REQUEST_URI'] ??= '/';
            $_SERVER['REQUEST_METHOD'] ??= 'GET';
            $_SERVER['SCRIPT_NAME'] ??= '/index.php';
            $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
            $_SERVER['HTTPS'] ??= 'off';
        }
        // PrestaShop may emit notices while booting from CLI; keep PHPUnit output clean.
        ob_start();
        try {
            require $configFile;
        } finally {
            ob_end_clean();
        }
    }
}
