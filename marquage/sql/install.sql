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
