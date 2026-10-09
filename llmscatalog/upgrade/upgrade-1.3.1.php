<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reparent the configuration tab under the "Modules" menu parent.
 *
 * Tabs installed before 1.3.1 were attached to AdminModulesManage/AdminModulesSf.
 * PrestaShop's nav bar derives a parent tab link from its first active child, so
 * the "Module Manager" menu opened this module's configuration page and the
 * merchant could not return to the module list. Moving the tab next to the
 * Module Manager tab restores the expected navigation.
 */
function upgrade_module_1_3_1($module): bool
{
    $idTab = (int) Tab::getIdFromClassName('AdminLlmsCatalog');
    if ($idTab <= 0) {
        return true;
    }

    $parentId = 0;
    foreach (['AdminParentModulesSf', 'AdminModulesSf', 'AdminModulesManage'] as $className) {
        $candidate = (int) Tab::getIdFromClassName($className);
        if ($candidate > 0) {
            $parentId = $candidate;
            break;
        }
    }

    if ($parentId > 0) {
        $tab = new Tab($idTab);
        if ((int) $tab->id_parent !== $parentId) {
            $tab->id_parent = $parentId;
            if (!$tab->update()) {
                return false;
            }
        }
    }

    if (class_exists('Tools') && method_exists('Tools', 'clearSf2Cache')) {
        Tools::clearSf2Cache();
    }

    return true;
}
