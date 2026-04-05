<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class CrmCyclesGoogleFeedModuleFrontController extends ModuleFrontController
{
    /** @var int */
    private $idLang;

    /** @var int */
    private $idShop;

    public function initContent()
    {
        // Token check
        $token = Tools::getValue('token');
        $expected = Tools::substr(Tools::hash('crmcycles/googlefeed'), 0, 10);

        if (empty($token) || $token !== $expected) {
            header('HTTP/1.1 403 Forbidden');
            die('Access denied.');
        }

        if (!(bool) Configuration::get('CRMCYCLES_GMERCHANT_ENABLED')) {
            header('HTTP/1.1 503 Service Unavailable');
            die('Google Merchant feed is disabled.');
        }

        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $this->idShop = (int) Context::getContext()->shop->id;

        header('Content-Type: application/xml; charset=utf-8');
        echo $this->generateFeed();
        exit;
    }

    private function generateFeed(): string
    {
        $shopName = Configuration::get('PS_SHOP_NAME');
        $shopUrl = Tools::getShopDomainSsl(true);
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $currencyIso = $currency->iso_code;
        $description = Configuration::get('CRMCYCLES_GMERCHANT_DESCRIPTION') ?: $shopName;

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . $this->esc($shopName) . '</title>' . "\n";
        $xml .= '<link>' . $this->esc($shopUrl) . '</link>' . "\n";
        $xml .= '<description>' . $this->esc($description) . '</description>' . "\n";

        $products = $this->getProducts();

        foreach ($products as $product) {
            $xml .= $this->buildItem($product, $currencyIso, $shopUrl);
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    private function getProducts(): array
    {
        $onlyInStock = (bool) Configuration::get('CRMCYCLES_GMERCHANT_ONLY_INSTOCK');
        $rootCategory = (int) Configuration::get('CRMCYCLES_ROOT_CATEGORY');

        $sql = 'SELECT p.`id_product`, p.`reference`, p.`ean13`, p.`upc`, p.`isbn`,
                       p.`weight`, p.`condition`, p.`active`,
                       pl.`name`, pl.`description`, pl.`description_short`, pl.`link_rewrite`,
                       cl.`name` AS category_name, cl.`link_rewrite` AS category_rewrite,
                       m.`name` AS brand,
                       sa.`quantity` AS stock_quantity,
                       p.`id_category_default`
                FROM `' . _DB_PREFIX_ . 'product` p
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON p.`id_product` = pl.`id_product` AND pl.`id_lang` = ' . $this->idLang . '
                    AND pl.`id_shop` = ' . $this->idShop . '
                LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON p.`id_category_default` = cl.`id_category` AND cl.`id_lang` = ' . $this->idLang . '
                LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                    ON p.`id_manufacturer` = m.`id_manufacturer`
                LEFT JOIN `' . _DB_PREFIX_ . 'stock_available` sa
                    ON p.`id_product` = sa.`id_product` AND sa.`id_product_attribute` = 0
                    AND sa.`id_shop` = ' . $this->idShop . '
                ' . Shop::addSqlAssociation('product', 'p') . '
                WHERE p.`active` = 1
                    AND product_shop.`visibility` IN ("both", "catalog")';

        if ($onlyInStock) {
            $sql .= ' AND sa.`quantity` > 0';
        }

        $sql .= ' ORDER BY p.`id_product` ASC';

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) ?: [];
    }

    private function buildItem(array $product, string $currencyIso, string $shopUrl): string
    {
        $idProduct = (int) $product['id_product'];
        $link = Context::getContext()->link;

        // Prices
        $priceTaxIncl = Product::getPriceStatic($idProduct, true, null, 2);
        $priceWithoutReduction = Product::getPriceStatic($idProduct, true, null, 2, null, false, false);

        // URL
        $productUrl = $link->getProductLink($idProduct, $product['link_rewrite'], $product['category_rewrite'], null, $this->idLang, $this->idShop);

        // Image
        $imageUrl = $this->getMainImageUrl($idProduct);

        // Availability
        $inStock = (int) $product['stock_quantity'] > 0;
        $availability = $inStock ? 'in_stock' : 'out_of_stock';

        // Condition
        $conditionMap = ['new' => 'new', 'used' => 'used', 'refurbished' => 'refurbished'];
        $condition = $conditionMap[$product['condition']] ?? 'new';

        // Category breadcrumb
        $breadcrumb = $this->getCategoryBreadcrumb((int) $product['id_category_default']);

        // Description: strip tags from short description
        $description = strip_tags($product['description_short'] ?: $product['description'] ?: '');
        $description = mb_substr(trim($description), 0, 5000);

        // Combinations
        $combinations = $this->getCombinations($idProduct);

        $xml = '';

        if (!empty($combinations)) {
            foreach ($combinations as $combi) {
                $xml .= '<item>' . "\n";
                $xml .= '<g:id>' . $this->esc($idProduct . '-' . $combi['id_product_attribute']) . '</g:id>' . "\n";
                $xml .= '<g:item_group_id>' . $this->esc((string) $idProduct) . '</g:item_group_id>' . "\n";
                $xml .= '<title>' . $this->esc($product['name'] . ' — ' . $combi['attribute_name']) . '</title>' . "\n";
                $xml .= '<description>' . $this->esc($description) . '</description>' . "\n";
                $xml .= '<link>' . $this->esc($productUrl . '#/' . $combi['id_product_attribute']) . '</link>' . "\n";

                if ($imageUrl) {
                    $combiImageUrl = $this->getCombinationImageUrl($idProduct, (int) $combi['id_product_attribute']) ?: $imageUrl;
                    $xml .= '<g:image_link>' . $this->esc($combiImageUrl) . '</g:image_link>' . "\n";
                }

                $combiPrice = Product::getPriceStatic($idProduct, true, (int) $combi['id_product_attribute'], 2);
                $combiPriceNoReduc = Product::getPriceStatic($idProduct, true, (int) $combi['id_product_attribute'], 2, null, false, false);
                $xml .= '<g:price>' . number_format($combiPriceNoReduc, 2, '.', '') . ' ' . $currencyIso . '</g:price>' . "\n";
                if ($combiPrice < $combiPriceNoReduc) {
                    $xml .= '<g:sale_price>' . number_format($combiPrice, 2, '.', '') . ' ' . $currencyIso . '</g:sale_price>' . "\n";
                }

                $combiStock = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, (int) $combi['id_product_attribute']);
                $xml .= '<g:availability>' . ($combiStock > 0 ? 'in_stock' : 'out_of_stock') . '</g:availability>' . "\n";
                $xml .= '<g:condition>' . $condition . '</g:condition>' . "\n";

                if (!empty($product['brand'])) {
                    $xml .= '<g:brand>' . $this->esc($product['brand']) . '</g:brand>' . "\n";
                }

                $ean = $combi['ean13'] ?: $product['ean13'];
                if (!empty($ean)) {
                    $xml .= '<g:gtin>' . $this->esc($ean) . '</g:gtin>' . "\n";
                }

                $mpn = $combi['reference'] ?: $product['reference'];
                if (!empty($mpn)) {
                    $xml .= '<g:mpn>' . $this->esc($mpn) . '</g:mpn>' . "\n";
                }

                if (empty($ean) && empty($mpn)) {
                    $xml .= '<g:identifier_exists>false</g:identifier_exists>' . "\n";
                }

                if ($breadcrumb) {
                    $xml .= '<g:product_type>' . $this->esc($breadcrumb) . '</g:product_type>' . "\n";
                }

                // Variant attributes (color, size, etc.)
                foreach ($combi['attributes'] as $groupName => $valueName) {
                    $groupLower = mb_strtolower($groupName);
                    if (strpos($groupLower, 'couleur') !== false || strpos($groupLower, 'color') !== false) {
                        $xml .= '<g:color>' . $this->esc($valueName) . '</g:color>' . "\n";
                    } elseif (strpos($groupLower, 'taille') !== false || strpos($groupLower, 'size') !== false) {
                        $xml .= '<g:size>' . $this->esc($valueName) . '</g:size>' . "\n";
                    }
                }

                $xml .= '</item>' . "\n";
            }
        } else {
            // Simple product
            $xml .= '<item>' . "\n";
            $xml .= '<g:id>' . $this->esc((string) $idProduct) . '</g:id>' . "\n";
            $xml .= '<title>' . $this->esc($product['name']) . '</title>' . "\n";
            $xml .= '<description>' . $this->esc($description) . '</description>' . "\n";
            $xml .= '<link>' . $this->esc($productUrl) . '</link>' . "\n";

            if ($imageUrl) {
                $xml .= '<g:image_link>' . $this->esc($imageUrl) . '</g:image_link>' . "\n";
            }

            $xml .= '<g:price>' . number_format($priceWithoutReduction, 2, '.', '') . ' ' . $currencyIso . '</g:price>' . "\n";
            if ($priceTaxIncl < $priceWithoutReduction) {
                $xml .= '<g:sale_price>' . number_format($priceTaxIncl, 2, '.', '') . ' ' . $currencyIso . '</g:sale_price>' . "\n";
            }

            $xml .= '<g:availability>' . $availability . '</g:availability>' . "\n";
            $xml .= '<g:condition>' . $condition . '</g:condition>' . "\n";

            if (!empty($product['brand'])) {
                $xml .= '<g:brand>' . $this->esc($product['brand']) . '</g:brand>' . "\n";
            }

            if (!empty($product['ean13'])) {
                $xml .= '<g:gtin>' . $this->esc($product['ean13']) . '</g:gtin>' . "\n";
            }

            if (!empty($product['reference'])) {
                $xml .= '<g:mpn>' . $this->esc($product['reference']) . '</g:mpn>' . "\n";
            }

            if (empty($product['ean13']) && empty($product['reference'])) {
                $xml .= '<g:identifier_exists>false</g:identifier_exists>' . "\n";
            }

            if ($breadcrumb) {
                $xml .= '<g:product_type>' . $this->esc($breadcrumb) . '</g:product_type>' . "\n";
            }

            $xml .= '</item>' . "\n";
        }

        return $xml;
    }

    private function getCombinations(int $idProduct): array
    {
        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT pa.`id_product_attribute`, pa.`reference`, pa.`ean13`
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             ' . Shop::addSqlAssociation('product_attribute', 'pa') . '
             WHERE pa.`id_product` = ' . $idProduct
        );

        if (!$rows) {
            return [];
        }

        $combinations = [];
        foreach ($rows as $row) {
            $idPa = (int) $row['id_product_attribute'];

            // Get attribute names for this combination
            $attrs = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
                'SELECT agl.`name` AS group_name, al.`name` AS attr_name
                 FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                 INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON pac.`id_attribute` = a.`id_attribute`
                 INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                    ON a.`id_attribute` = al.`id_attribute` AND al.`id_lang` = ' . $this->idLang . '
                 INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                    ON a.`id_attribute_group` = agl.`id_attribute_group` AND agl.`id_lang` = ' . $this->idLang . '
                 WHERE pac.`id_product_attribute` = ' . $idPa
            );

            $attrMap = [];
            $attrNames = [];
            foreach ($attrs ?: [] as $a) {
                $attrMap[$a['group_name']] = $a['attr_name'];
                $attrNames[] = $a['attr_name'];
            }

            $combinations[] = [
                'id_product_attribute' => $idPa,
                'reference' => $row['reference'],
                'ean13' => $row['ean13'],
                'attribute_name' => implode(', ', $attrNames),
                'attributes' => $attrMap,
            ];
        }

        return $combinations;
    }

    private function getMainImageUrl(int $idProduct): string
    {
        $cover = Image::getCover($idProduct);
        if (!$cover) {
            return '';
        }

        $link = Context::getContext()->link;

        return $link->getImageLink(
            Product::getProductName($idProduct),
            $idProduct . '-' . (int) $cover['id_image'],
            'large_default'
        );
    }

    private function getCombinationImageUrl(int $idProduct, int $idProductAttribute): string
    {
        $images = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT pai.`id_image`
             FROM `' . _DB_PREFIX_ . 'product_attribute_image` pai
             WHERE pai.`id_product_attribute` = ' . $idProductAttribute . '
             LIMIT 1'
        );

        if (!$images) {
            return '';
        }

        $link = Context::getContext()->link;

        return $link->getImageLink(
            Product::getProductName($idProduct),
            $idProduct . '-' . (int) $images[0]['id_image'],
            'large_default'
        );
    }

    private function getCategoryBreadcrumb(int $idCategory): string
    {
        $parts = [];
        $maxDepth = 10;

        while ($idCategory > 0 && $maxDepth-- > 0) {
            $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
                'SELECT c.`id_parent`, cl.`name`
                 FROM `' . _DB_PREFIX_ . 'category` c
                 LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                    ON c.`id_category` = cl.`id_category` AND cl.`id_lang` = ' . $this->idLang . '
                 WHERE c.`id_category` = ' . $idCategory . '
                   AND c.`level_depth` > 1'
            );

            if (!$row) {
                break;
            }

            $parts[] = $row['name'];
            $idCategory = (int) $row['id_parent'];
        }

        return implode(' > ', array_reverse($parts));
    }

    private function esc(string $str): string
    {
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
