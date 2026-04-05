<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module)
{
    $sql = [];

    $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'crmcycles_trial_category` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `id_category` INT(11) UNSIGNED NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_category` (`id_category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'crmcycles_store_trial` (
        `id_store_trial` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_product` INT(11) UNSIGNED NOT NULL,
        `id_product_attribute` INT(11) UNSIGNED NOT NULL DEFAULT 0,
        `firstname` VARCHAR(100) NOT NULL,
        `lastname` VARCHAR(100) NOT NULL,
        `email` VARCHAR(255) NOT NULL,
        `phone` VARCHAR(32) NOT NULL,
        `desired_date` DATE NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT \'pending\',
        `date_add` DATETIME NOT NULL,
        PRIMARY KEY (`id_store_trial`),
        KEY `id_product` (`id_product`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    foreach ($sql as $query) {
        if (!Db::getInstance()->execute($query)) {
            return false;
        }
    }

    return $module->registerHook('displayProductAdditionalInfo')
        && $module->registerHook('actionFrontControllerSetMedia');
}
