CREATE TABLE IF NOT EXISTS `PREFIX_crmcycles_category_map` (
    `id_crmcycles_category_map` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `crm_type` VARCHAR(20) NOT NULL COMMENT 'family, category, subcategory',
    `crm_id` INT(11) UNSIGNED NOT NULL,
    `crm_slug` VARCHAR(100) DEFAULT NULL,
    `id_category` INT(11) UNSIGNED NOT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_crmcycles_category_map`),
    UNIQUE KEY `crm_type_id` (`crm_type`, `crm_id`),
    KEY `id_category` (`id_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_crmcycles_product_map` (
    `id_crmcycles_product_map` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `crm_product_id` INT(11) UNSIGNED NOT NULL,
    `crm_sku` VARCHAR(64) NOT NULL,
    `id_product` INT(11) UNSIGNED NOT NULL,
    `is_collection` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `crm_collection_id` INT(11) UNSIGNED DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_crmcycles_product_map`),
    UNIQUE KEY `crm_product_id` (`crm_product_id`),
    UNIQUE KEY `crm_sku` (`crm_sku`),
    KEY `id_product` (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_crmcycles_combination_map` (
    `id_crmcycles_combination_map` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `crm_product_id` INT(11) UNSIGNED NOT NULL,
    `crm_sku` VARCHAR(64) NOT NULL,
    `id_product` INT(11) UNSIGNED NOT NULL,
    `id_product_attribute` INT(11) UNSIGNED NOT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_crmcycles_combination_map`),
    UNIQUE KEY `crm_product_id` (`crm_product_id`),
    UNIQUE KEY `crm_sku` (`crm_sku`),
    KEY `id_product_attribute` (`id_product_attribute`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_crmcycles_feature_map` (
    `id_crmcycles_feature_map` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `crm_characteristic_name` VARCHAR(255) NOT NULL,
    `id_feature` INT(11) UNSIGNED NOT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_crmcycles_feature_map`),
    UNIQUE KEY `crm_characteristic_name` (`crm_characteristic_name`),
    KEY `id_feature` (`id_feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_crmcycles_feature_value_map` (
    `id_crmcycles_feature_value_map` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `crm_characteristic_name` VARCHAR(255) NOT NULL,
    `crm_value` VARCHAR(255) NOT NULL,
    `id_feature_value` INT(11) UNSIGNED NOT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_crmcycles_feature_value_map`),
    UNIQUE KEY `crm_name_value` (`crm_characteristic_name`, `crm_value`),
    KEY `id_feature_value` (`id_feature_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_marquage_category` (
    `id_marquage` INT(11) NOT NULL AUTO_INCREMENT,
    `id_category` INT(11) UNSIGNED NOT NULL,
    `id_product_marquage` INT(11) UNSIGNED NOT NULL,
    PRIMARY KEY (`id_marquage`),
    UNIQUE KEY `uk_category` (`id_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_marquage_category_feature` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `id_category` INT(11) UNSIGNED NOT NULL,
    `id_feature` INT(11) UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_category_feature` (`id_category`, `id_feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_crmcycles_order_map` (
    `id_crmcycles_order_map` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order` INT(11) UNSIGNED NOT NULL,
    `crm_customer_id` INT(11) UNSIGNED NOT NULL,
    `crm_invoice_id` INT(11) UNSIGNED NOT NULL,
    `crm_invoice_number` VARCHAR(50) DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_crmcycles_order_map`),
    UNIQUE KEY `id_order` (`id_order`),
    KEY `crm_invoice_id` (`crm_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_crmcycles_sync_log` (
    `id_crmcycles_sync_log` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `sync_type` VARCHAR(50) NOT NULL COMMENT 'full, categories, products, prices_stock, features',
    `status` VARCHAR(20) NOT NULL DEFAULT 'running' COMMENT 'running, success, error',
    `summary` TEXT,
    `date_start` DATETIME NOT NULL,
    `date_end` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id_crmcycles_sync_log`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
