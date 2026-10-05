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
