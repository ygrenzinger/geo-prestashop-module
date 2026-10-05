<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Invalidates derived documents after canonical route and metadata changes. */
function upgrade_module_1_2_0($module): bool
{
    $version = (int) Configuration::get('LLMSCATALOG_CONFIG_VERSION', null, 0, 0);
    $generation = (int) Configuration::get('LLMSCATALOG_CACHE_GENERATION', null, 0, 0);
    if (!Configuration::updateValue('LLMSCATALOG_CONFIG_VERSION', (string) ($version + 1), false, 0, 0)) return false;
    if (!Configuration::updateValue('LLMSCATALOG_CACHE_GENERATION', (string) ($generation + 1), false, 0, 0)) return false;

    // Clear the persisted PrestaShop/Symfony routing cache so the updated
    // moduleRoutes hook is visible immediately after the upgrade.
    if (class_exists('Tools') && method_exists('Tools', 'clearSf2Cache')) Tools::clearSf2Cache();
    return true;
}
