<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/CrmCyclesApi.php';

class CrmCyclesImporter
{
    private $api;
    private $idLang;
    private $idShop;
    private $db;
    private $prefix;
    private $log = [];

    public function __construct()
    {
        $this->api = new CrmCyclesApi();
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $this->idShop = (int) Context::getContext()->shop->id;
        $this->db = Db::getInstance();
        $this->prefix = _DB_PREFIX_;
    }

    public function getLog(): array
    {
        return $this->log;
    }

    // =========================================================================
    // CATEGORIES IMPORT
    // =========================================================================

    public function importCategories(): array
    {
        $logId = $this->startLog('categories');
        $stats = ['families' => 0, 'categories' => 0, 'subcategories' => 0, 'errors' => 0];

        $response = $this->api->getCategories();
        if (!$response['success']) {
            $this->endLog($logId, 'error', 'API error: ' . ($response['error']['message'] ?? 'unknown'));
            return ['success' => false, 'message' => $response['error']['message'] ?? 'API error'];
        }

        $rootCategoryId = (int) Configuration::get('CRMCYCLES_ROOT_CATEGORY');
        if (!$rootCategoryId) {
            $rootCategoryId = (int) Configuration::get('PS_HOME_CATEGORY');
        }

        foreach ($response['data'] as $family) {
            try {
                $familyCatId = $this->syncCategory('family', $family, $rootCategoryId);
                $stats['families']++;

                foreach ($family['categories'] ?? [] as $category) {
                    try {
                        $catId = $this->syncCategory('category', $category, $familyCatId);
                        $stats['categories']++;

                        foreach ($category['subcategories'] ?? [] as $subcategory) {
                            try {
                                $this->syncCategory('subcategory', $subcategory, $catId);
                                $stats['subcategories']++;
                            } catch (Exception $e) {
                                $stats['errors']++;
                                $this->log[] = 'Erreur sous-catégorie "' . ($subcategory['name'] ?? '?') . '": ' . $e->getMessage();
                            }
                        }
                    } catch (Exception $e) {
                        $stats['errors']++;
                        $this->log[] = 'Erreur catégorie "' . ($category['name'] ?? '?') . '": ' . $e->getMessage();
                    }
                }
            } catch (Exception $e) {
                $stats['errors']++;
                $this->log[] = 'Erreur famille "' . ($family['name'] ?? '?') . '": ' . $e->getMessage();
            }
        }

        $summary = sprintf(
            '%d familles, %d catégories, %d sous-catégories importées (%d erreurs)',
            $stats['families'], $stats['categories'], $stats['subcategories'], $stats['errors']
        );
        $this->endLog($logId, $stats['errors'] > 0 ? 'error' : 'success', $summary);

        return ['success' => true, 'stats' => $stats, 'message' => $summary];
    }

    private function syncCategory(string $crmType, array $data, int $parentId): int
    {
        $crmId = (int) $data['id'];
        $name = $data['name'];
        $slug = $data['slug'] ?? null;

        // Check if mapping exists
        $existing = $this->db->getRow(
            'SELECT * FROM `' . $this->prefix . 'crmcycles_category_map`
             WHERE `crm_type` = "' . pSQL($crmType) . '" AND `crm_id` = ' . $crmId
        );

        if ($existing) {
            // Update existing category
            $idCategory = (int) $existing['id_category'];
            $category = new Category($idCategory);

            if (!Validate::isLoadedObject($category)) {
                // Category was deleted in PS, recreate
                $idCategory = $this->createPsCategory($name, $parentId);
                $this->db->update('crmcycles_category_map', [
                    'id_category' => $idCategory,
                    'crm_slug' => pSQL($slug),
                    'date_upd' => date('Y-m-d H:i:s'),
                ], 'id_crmcycles_category_map = ' . (int) $existing['id_crmcycles_category_map']);
            } else {
                // Update name if changed
                $category->name[$this->idLang] = $name;
                $category->link_rewrite[$this->idLang] = Tools::str2url($name);
                if ((int) $category->id_parent !== $parentId) {
                    $category->id_parent = $parentId;
                }
                $category->update();

                $this->db->update('crmcycles_category_map', [
                    'crm_slug' => pSQL($slug),
                    'date_upd' => date('Y-m-d H:i:s'),
                ], 'id_crmcycles_category_map = ' . (int) $existing['id_crmcycles_category_map']);
            }

            return $idCategory;
        }

        // Create new
        $idCategory = $this->createPsCategory($name, $parentId);

        $this->db->insert('crmcycles_category_map', [
            'crm_type' => pSQL($crmType),
            'crm_id' => $crmId,
            'crm_slug' => pSQL($slug),
            'id_category' => $idCategory,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return $idCategory;
    }

    private function createPsCategory(string $name, int $parentId): int
    {
        $category = new Category();
        $category->id_parent = $parentId;
        $category->active = 1;
        $category->id_shop_default = $this->idShop;

        foreach (Language::getLanguages(false) as $lang) {
            $category->name[$lang['id_lang']] = $name;
            $category->link_rewrite[$lang['id_lang']] = Tools::str2url($name);
        }

        $category->add();
        $category->addShop($this->idShop);

        return (int) $category->id;
    }

    // =========================================================================
    // FEATURES IMPORT
    // =========================================================================

    public function importFeatures(): array
    {
        $logId = $this->startLog('features');
        $stats = ['features' => 0, 'values' => 0, 'errors' => 0];

        // Single API call: get all characteristic types + values from CRM database
        $response = $this->api->getCharacteristics();
        if (!$response['success']) {
            $this->endLog($logId, 'error', 'API error: ' . ($response['error']['message'] ?? 'unknown'));
            return ['success' => false, 'message' => $response['error']['message'] ?? 'API error'];
        }

        foreach ($response['data'] ?? [] as $charType) {
            $charName = $charType['name'] ?? '';
            if (empty($charName)) {
                continue;
            }

            try {
                $idFeature = $this->syncFeature($charName);
                $stats['features']++;

                foreach ($charType['values'] ?? [] as $valueData) {
                    $value = $valueData['value'] ?? '';
                    if (empty($value)) {
                        continue;
                    }
                    try {
                        $this->syncFeatureValue($charName, $value, $idFeature);
                        $stats['values']++;
                    } catch (Exception $e) {
                        $stats['errors']++;
                        $this->log[] = 'Erreur valeur "' . $value . '" pour "' . $charName . '": ' . $e->getMessage();
                    }
                }
            } catch (Exception $e) {
                $stats['errors']++;
                $this->log[] = 'Erreur caractéristique "' . $charName . '": ' . $e->getMessage();
            }
        }

        $summary = sprintf('%d caractéristiques, %d valeurs importées (%d erreurs)', $stats['features'], $stats['values'], $stats['errors']);
        $this->endLog($logId, $stats['errors'] > 0 ? 'error' : 'success', $summary);

        return ['success' => true, 'stats' => $stats, 'message' => $summary];
    }

    private function syncFeature(string $charName): int
    {
        $existing = $this->db->getRow(
            'SELECT * FROM `' . $this->prefix . 'crmcycles_feature_map`
             WHERE `crm_characteristic_name` = "' . pSQL($charName) . '"'
        );

        if ($existing) {
            return (int) $existing['id_feature'];
        }

        // Check if a PS feature with same name already exists
        $existingFeatureId = (int) $this->db->getValue(
            'SELECT `id_feature` FROM `' . $this->prefix . 'feature_lang`
             WHERE `name` = "' . pSQL($charName) . '" AND `id_lang` = ' . $this->idLang
        );

        if ($existingFeatureId) {
            $idFeature = $existingFeatureId;
        } else {
            $feature = new Feature();
            foreach (Language::getLanguages(false) as $lang) {
                $feature->name[$lang['id_lang']] = $charName;
            }
            $feature->add();
            $idFeature = (int) $feature->id;
        }

        $this->db->insert('crmcycles_feature_map', [
            'crm_characteristic_name' => pSQL($charName),
            'id_feature' => $idFeature,
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return $idFeature;
    }

    private function syncFeatureValue(string $charName, string $value, int $idFeature): int
    {
        $existing = $this->db->getRow(
            'SELECT * FROM `' . $this->prefix . 'crmcycles_feature_value_map`
             WHERE `crm_characteristic_name` = "' . pSQL($charName) . '"
               AND `crm_value` = "' . pSQL($value) . '"'
        );

        if ($existing) {
            return (int) $existing['id_feature_value'];
        }

        // Check if value already exists in PS for this feature
        $existingValueId = (int) $this->db->getValue(
            'SELECT fv.`id_feature_value`
             FROM `' . $this->prefix . 'feature_value` fv
             INNER JOIN `' . $this->prefix . 'feature_value_lang` fvl
                ON fv.`id_feature_value` = fvl.`id_feature_value`
             WHERE fv.`id_feature` = ' . $idFeature . '
               AND fvl.`value` = "' . pSQL($value) . '"
               AND fvl.`id_lang` = ' . $this->idLang . '
               AND fv.`custom` = 0'
        );

        if ($existingValueId) {
            $idFeatureValue = $existingValueId;
        } else {
            $featureValue = new FeatureValue();
            $featureValue->id_feature = $idFeature;
            $featureValue->custom = 0;
            foreach (Language::getLanguages(false) as $lang) {
                $featureValue->value[$lang['id_lang']] = $value;
            }
            $featureValue->add();
            $idFeatureValue = (int) $featureValue->id;
        }

        $this->db->insert('crmcycles_feature_value_map', [
            'crm_characteristic_name' => pSQL($charName),
            'crm_value' => pSQL($value),
            'id_feature_value' => $idFeatureValue,
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return $idFeatureValue;
    }

    // =========================================================================
    // PRODUCTS IMPORT
    // =========================================================================

    /**
     * Fetch product list from CRM API and build import tasks.
     * Returns a list of items to import one by one via AJAX.
     */
    public function buildProductImportQueue(bool $includeOutOfStock = false): array
    {
        $products = $this->api->getAllProducts($includeOutOfStock);

        if (empty($products)) {
            return ['success' => false, 'message' => 'Aucun produit récupéré', 'queue' => []];
        }

        $queue = [];
        $seenCollections = [];

        foreach ($products as $product) {
            if (!empty($product['collection'])) {
                $colId = (int) $product['collection']['id'];
                if (!isset($seenCollections[$colId])) {
                    $seenCollections[$colId] = true;
                    $queue[] = [
                        'type' => 'collection',
                        'crm_id' => (int) $product['id'],
                        'collection_id' => $colId,
                        'name' => $product['collection']['name'],
                        'variants' => $product['collection']['variants'] ?? [],
                    ];
                }
            } else {
                $queue[] = [
                    'type' => 'product',
                    'crm_id' => (int) $product['id'],
                    'name' => $product['name'] ?? 'Produit #' . $product['id'],
                ];
            }
        }

        return ['success' => true, 'queue' => $queue, 'total' => count($queue)];
    }

    /**
     * Import a single product by CRM ID (called via AJAX).
     */
    public function importSingleProduct(int $crmId): array
    {
        $detail = $this->api->getProduct($crmId);
        if (!$detail['success']) {
            return ['success' => false, 'message' => 'Impossible de récupérer le produit #' . $crmId];
        }

        $productData = $detail['data'];

        try {
            if ($productData['serialized'] && !empty($productData['serialized_items'])) {
                $this->syncSerializedProduct($productData);
                $combiCount = count($productData['serialized_items']);
            } else {
                $this->syncSimpleProduct($productData);
                $combiCount = 0;
            }

            return [
                'success' => true,
                'message' => $productData['name'] . ' importé',
                'combinations' => $combiCount,
                'log' => $this->log,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
                'log' => $this->log,
            ];
        }
    }

    /**
     * Import a single collection by main product CRM ID (called via AJAX).
     */
    public function importSingleCollection(int $crmId, int $colId, array $variants): array
    {
        $mainDetail = $this->api->getProduct($crmId);
        if (!$mainDetail['success']) {
            return ['success' => false, 'message' => 'Impossible de récupérer le produit principal #' . $crmId];
        }

        try {
            $collection = [
                'name' => $mainDetail['data']['collection']['name'] ?? 'Collection #' . $colId,
                'variants' => $variants,
                'main_product' => $mainDetail['data'],
            ];

            $this->syncCollectionProduct($colId, $collection, $mainDetail['data']);

            return [
                'success' => true,
                'message' => $collection['name'] . ' importée (' . count($variants) . ' variantes)',
                'combinations' => count($variants),
                'log' => $this->log,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
                'log' => $this->log,
            ];
        }
    }

    private function syncSimpleProduct(array $data): int
    {
        $crmId = (int) $data['id'];
        $sku = $data['sku'];

        $existing = $this->db->getRow(
            'SELECT * FROM `' . $this->prefix . 'crmcycles_product_map`
             WHERE `crm_product_id` = ' . $crmId
        );

        if ($existing) {
            $idProduct = (int) $existing['id_product'];
            $product = new Product($idProduct);
            if (!Validate::isLoadedObject($product)) {
                // Product deleted in PS, recreate
                $product = new Product();
            }
        } else {
            // Check by SKU
            $idProduct = $this->getProductIdBySku($sku);
            if ($idProduct) {
                $product = new Product($idProduct);
            } else {
                $product = new Product();
            }
        }

        $this->fillProductData($product, $data);
        $product->save();
        $idProduct = (int) $product->id;

        // Category association
        $this->assignProductCategory($product, $data);

        // Features
        $this->assignProductFeatures($idProduct, $data['characteristics'] ?? []);

        // Images
        $this->syncProductImages($product, $data);

        // Update stock
        StockAvailable::setQuantity($idProduct, 0, (int) ($data['stock'] ?? 0), $this->idShop);

        // Promotion
        if (!empty($data['promotion'])) {
            $this->syncPromotion($idProduct, 0, $data['promotion'], $data['tva_rate'] ?? 20);
        } else {
            $this->removePromotion($idProduct, 0);
        }

        // Save mapping
        $this->saveProductMapping($crmId, $sku, $idProduct, false, null);

        return $idProduct;
    }

    private function syncSerializedProduct(array $data): int
    {
        $crmId = (int) $data['id'];
        $sku = $data['sku'];

        // Create/update the base product
        $existing = $this->db->getRow(
            'SELECT * FROM `' . $this->prefix . 'crmcycles_product_map`
             WHERE `crm_product_id` = ' . $crmId
        );

        if ($existing) {
            $idProduct = (int) $existing['id_product'];
            $product = new Product($idProduct);
            if (!Validate::isLoadedObject($product)) {
                $product = new Product();
            }
        } else {
            $idProduct = $this->getProductIdBySku($sku);
            $product = $idProduct ? new Product($idProduct) : new Product();
        }

        $this->fillProductData($product, $data);
        $product->save();
        $idProduct = (int) $product->id;

        $this->assignProductCategory($product, $data);
        $this->assignProductFeatures($idProduct, $data['characteristics'] ?? []);
        $this->syncProductImages($product, $data);
        $this->saveProductMapping($crmId, $sku, $idProduct, false, null);

        // Create attribute group "Numéro de série" if needed
        $idAttributeGroup = $this->getOrCreateAttributeGroup('Numéro de série');

        // Create combinations for each serialized item
        $totalStock = 0;
        foreach ($data['serialized_items'] ?? [] as $serialItem) {
            $serialSku = $sku . '-' . $serialItem['serial_number'];
            $label = $serialItem['serial_number'];
            if (!empty($serialItem['condition'])) {
                $label .= ' (' . $this->translateCondition($serialItem['condition']) . ')';
            }

            $idAttribute = $this->getOrCreateAttribute($idAttributeGroup, $label);
            $idCombination = $this->getOrCreateCombination($idProduct, $idAttribute, $serialSku);

            // Set combination price (difference from base price)
            $combination = new Combination($idCombination);
            $basePriceTTC = (float) ($data['price_ttc'] ?? 0);
            $serialPriceTTC = (float) ($serialItem['price_ttc'] ?? $basePriceTTC);
            $taxRate = (float) ($data['tva_rate'] ?? 20);

            $basePriceHT = $basePriceTTC / (1 + $taxRate / 100);
            $serialPriceHT = $serialPriceTTC / (1 + $taxRate / 100);

            $combination->price = round($serialPriceHT - $basePriceHT, 6);
            $combination->reference = $serialSku;
            $combination->save();

            // Stock: each serialized item = 1 unit
            StockAvailable::setQuantity($idProduct, $idCombination, 1, $this->idShop);
            $totalStock++;

            // Save combination mapping
            $this->saveCombinationMapping((int) $serialItem['id'], $serialSku, $idProduct, $idCombination);
        }

        // Update total stock on product
        StockAvailable::setQuantity($idProduct, 0, $totalStock, $this->idShop);

        return $idProduct;
    }

    private function syncCollectionProduct(int $colId, array $collection, array $mainProductData): int
    {
        $mainCrmId = (int) $mainProductData['id'];
        $sku = $mainProductData['sku'];

        // Check if this collection is already mapped
        $existing = $this->db->getRow(
            'SELECT * FROM `' . $this->prefix . 'crmcycles_product_map`
             WHERE `crm_collection_id` = ' . $colId . ' AND `is_collection` = 1'
        );

        if ($existing) {
            $idProduct = (int) $existing['id_product'];
            $product = new Product($idProduct);
            if (!Validate::isLoadedObject($product)) {
                $product = new Product();
            }
        } else {
            $product = new Product();
        }

        // Use collection name as product name
        $this->fillProductData($product, $mainProductData);
        foreach (Language::getLanguages(false) as $lang) {
            $product->name[$lang['id_lang']] = $collection['name'];
        }
        $product->save();
        $idProduct = (int) $product->id;

        $this->assignProductCategory($product, $mainProductData);
        $this->assignProductFeatures($idProduct, $mainProductData['characteristics'] ?? []);
        $this->syncProductImages($product, $mainProductData);

        // Determine which characteristics differ between variants to use as attributes
        $variantChars = $this->detectVariantCharacteristics($collection['variants']);

        $totalStock = 0;

        foreach ($collection['variants'] as $variant) {
            $variantSku = $variant['slug'] ?? ($sku . '-v' . $variant['product_id']);

            // Get full variant detail to get SKU
            $variantDetail = $this->api->getProduct((int) $variant['product_id']);
            if ($variantDetail['success']) {
                $variantSku = $variantDetail['data']['sku'] ?? $variantSku;
            }

            // Build attribute combination from variant characteristics
            $attributeIds = [];
            $chars = $variant['characteristics'] ?? [];
            foreach ($variantChars as $charName) {
                $charValue = $chars[$charName] ?? '';
                if (empty($charValue)) {
                    continue;
                }
                $idAttributeGroup = $this->getOrCreateAttributeGroup($charName);
                $idAttribute = $this->getOrCreateAttribute($idAttributeGroup, $charValue);
                $attributeIds[] = $idAttribute;
            }

            if (empty($attributeIds)) {
                // Fallback: use variant product name as attribute
                $idAttributeGroup = $this->getOrCreateAttributeGroup('Variante');
                $idAttribute = $this->getOrCreateAttribute($idAttributeGroup, $variant['name'] ?? 'Variante ' . $variant['product_id']);
                $attributeIds[] = $idAttribute;
            }

            $idCombination = $this->getOrCreateCombinationMulti($idProduct, $attributeIds, $variantSku);

            // Price impact
            $combination = new Combination($idCombination);
            $basePriceTTC = (float) ($mainProductData['price_ttc'] ?? 0);
            $variantPriceTTC = (float) ($variant['price_ttc'] ?? $basePriceTTC);
            $taxRate = (float) ($mainProductData['tva_rate'] ?? 20);

            $basePriceHT = $basePriceTTC / (1 + $taxRate / 100);
            $variantPriceHT = $variantPriceTTC / (1 + $taxRate / 100);

            $combination->price = round($variantPriceHT - $basePriceHT, 6);
            $combination->reference = $variantSku;
            $combination->save();

            $variantStock = (int) ($variant['stock'] ?? 0);
            StockAvailable::setQuantity($idProduct, $idCombination, $variantStock, $this->idShop);
            $totalStock += $variantStock;

            // Map each variant product
            $this->saveCombinationMapping((int) $variant['product_id'], $variantSku, $idProduct, $idCombination);
        }

        StockAvailable::setQuantity($idProduct, 0, $totalStock, $this->idShop);

        // Save collection mapping
        $this->saveProductMapping($mainCrmId, $sku, $idProduct, true, $colId);

        return $idProduct;
    }

    /**
     * Detect which characteristics differ between variants (to use as PS attributes)
     */
    private function detectVariantCharacteristics(array $variants): array
    {
        $charValues = [];
        foreach ($variants as $variant) {
            foreach ($variant['characteristics'] ?? [] as $name => $value) {
                $charValues[$name][] = $value;
            }
        }

        $varying = [];
        foreach ($charValues as $name => $values) {
            if (count(array_unique($values)) > 1) {
                $varying[] = $name;
            }
        }

        return $varying;
    }

    // =========================================================================
    // PRICES & STOCK ONLY (SKU-based sync)
    // =========================================================================

    /**
     * Build queue of SKUs for price/stock sync via AJAX.
     */
    public function buildPriceStockQueue(): array
    {
        $products = $this->api->getAllProducts();

        if (empty($products)) {
            return ['success' => false, 'message' => 'Aucun produit récupéré', 'queue' => []];
        }

        $queue = [];
        foreach ($products as $product) {
            $sku = $product['sku'] ?? '';
            if (empty($sku)) {
                continue;
            }
            $queue[] = [
                'sku' => $sku,
                'name' => $product['name'] ?? $sku,
                'price_ttc' => $product['price_ttc'] ?? 0,
                'original_price_ttc' => $product['original_price_ttc'] ?? null,
                'tva_rate' => $product['tva_rate'] ?? 20,
                'stock' => $product['stock'] ?? 0,
                'promotion' => $product['promotion'] ?? null,
            ];
        }

        return ['success' => true, 'queue' => $queue, 'total' => count($queue)];
    }

    /**
     * Sync price/stock for a single product by SKU (called via AJAX).
     */
    public function syncSinglePriceStock(array $crmProduct): array
    {
        $sku = $crmProduct['sku'] ?? '';
        if (empty($sku)) {
            return ['success' => false, 'message' => 'SKU vide'];
        }

        try {
            $idProduct = 0;
            $idCombination = 0;
            $label = '';

            // Try product mapping first
            $mapped = $this->db->getRow(
                'SELECT `id_product` FROM `' . $this->prefix . 'crmcycles_product_map`
                 WHERE `crm_sku` = "' . pSQL($sku) . '"'
            );

            if ($mapped) {
                $idProduct = (int) $mapped['id_product'];
                $label = $sku . ' mis à jour';
            }

            // Try combination mapping
            if (!$idProduct) {
                $mappedCombi = $this->db->getRow(
                    'SELECT `id_product`, `id_product_attribute` FROM `' . $this->prefix . 'crmcycles_combination_map`
                     WHERE `crm_sku` = "' . pSQL($sku) . '"'
                );
                if ($mappedCombi) {
                    $idProduct = (int) $mappedCombi['id_product'];
                    $idCombination = (int) $mappedCombi['id_product_attribute'];
                    $label = $sku . ' (déclinaison) mis à jour';
                }
            }

            // Fallback: search by reference in PS
            if (!$idProduct) {
                $idProduct = $this->getProductIdBySku($sku);
                if ($idProduct) {
                    $label = $sku . ' mis à jour (par référence)';
                }
            }

            // Search in combinations by reference
            if (!$idProduct) {
                $combiId = $this->getCombinationIdBySku($sku);
                if ($combiId) {
                    $combi = new Combination($combiId);
                    $idProduct = (int) $combi->id_product;
                    $idCombination = $combiId;
                    $label = $sku . ' (déclinaison par ref) mis à jour';
                }
            }

            if (!$idProduct) {
                return ['success' => false, 'message' => $sku . ' non trouvé dans PrestaShop', 'not_found' => true];
            }

            // Update price & stock
            $this->updateProductPriceAndStock($idProduct, $idCombination, $crmProduct);

            // Handle promotion
            $hasPromo = false;
            if (!empty($crmProduct['promotion'])) {
                $this->syncPromotion($idProduct, $idCombination, $crmProduct['promotion'], $crmProduct['tva_rate'] ?? 20);
                $hasPromo = true;
            } else {
                $this->removePromotion($idProduct, $idCombination);
            }

            return ['success' => true, 'message' => $label, 'promo' => $hasPromo];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $sku . ': ' . $e->getMessage()];
        }
    }

    private function updateProductPriceAndStock(int $idProduct, int $idCombination, array $data): void
    {
        // Always use original price (before promo) as base
        $priceTTC = !empty($data['original_price_ttc'])
            ? (float) $data['original_price_ttc']
            : (float) ($data['price_ttc'] ?? 0);
        $taxRate = (float) ($data['tva_rate'] ?? 20);
        $priceHT = $priceTTC / (1 + $taxRate / 100);
        $stock = (int) ($data['stock'] ?? 0);

        if ($idCombination > 0) {
            // Update combination
            $combi = new Combination($idCombination);
            if (Validate::isLoadedObject($combi)) {
                // Get base product price HT
                $product = new Product($idProduct);
                $basePriceHT = (float) $product->price;
                $combi->price = round($priceHT - $basePriceHT, 6);
                $combi->save();
            }
            StockAvailable::setQuantity($idProduct, $idCombination, $stock, $this->idShop);
        } else {
            $product = new Product($idProduct);
            if (Validate::isLoadedObject($product)) {
                $product->price = round($priceHT, 6);
                $product->save();
            }
            StockAvailable::setQuantity($idProduct, 0, $stock, $this->idShop);
        }
    }

    private function syncPromotion(int $idProduct, int $idCombination, array $promo, float $taxRate): void
    {
        $discountPct = (float) ($promo['discount_percentage'] ?? 0);
        $endsAt = $promo['ends_at'] ?? null;

        if ($discountPct <= 0) {
            return;
        }

        $reductionType = 'percentage';
        $reduction = round($discountPct / 100, 6);

        $dateFrom = date('Y-m-d', strtotime('-1 day')) . ' 00:00:00';
        $dateTo = $endsAt ? $endsAt . ' 23:59:59' : '0000-00-00 00:00:00';

        // Check existing specific price for this product from CRM
        $existingRule = $this->db->getRow(
            'SELECT `id_specific_price` FROM `' . $this->prefix . 'specific_price`
             WHERE `id_product` = ' . $idProduct . '
               AND `id_product_attribute` = ' . $idCombination . '
               AND `id_shop` = ' . $this->idShop . '
               AND `id_customer` = 0
               AND `id_group` = 0
               AND `from_quantity` = 1'
        );

        if ($existingRule) {
            $this->db->update('specific_price', [
                'reduction' => (float) $reduction,
                'reduction_type' => pSQL($reductionType),
                'reduction_tax' => 0,
                'from' => pSQL($dateFrom),
                'to' => pSQL($dateTo),
            ], 'id_specific_price = ' . (int) $existingRule['id_specific_price']);
        } else {
            $specificPrice = new SpecificPrice();
            $specificPrice->id_product = $idProduct;
            $specificPrice->id_product_attribute = $idCombination;
            $specificPrice->id_shop = $this->idShop;
            $specificPrice->id_currency = 0;
            $specificPrice->id_country = 0;
            $specificPrice->id_group = 0;
            $specificPrice->id_customer = 0;
            $specificPrice->price = -1;
            $specificPrice->from_quantity = 1;
            $specificPrice->reduction = (float) $reduction;
            $specificPrice->reduction_type = $reductionType;
            $specificPrice->reduction_tax = 0;
            $specificPrice->from = $dateFrom;
            $specificPrice->to = $dateTo;
            $specificPrice->add();
        }
    }

    private function removePromotion(int $idProduct, int $idCombination): void
    {
        $this->db->delete('specific_price',
            '`id_product` = ' . $idProduct .
            ' AND `id_product_attribute` = ' . $idCombination .
            ' AND `id_shop` = ' . $this->idShop .
            ' AND `id_customer` = 0 AND `id_group` = 0 AND `from_quantity` = 1'
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function fillProductData(Product $product, array $data): void
    {
        // Always use original price (before promo) as the base product price
        $priceTTC = !empty($data['original_price_ttc'])
            ? (float) $data['original_price_ttc']
            : (float) ($data['price_ttc'] ?? 0);
        $taxRate = (float) ($data['tva_rate'] ?? 20);
        $priceHT = $priceTTC / (1 + $taxRate / 100);

        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $product->name[$idLang] = $data['name'] ?? '';
            $product->description_short[$idLang] = $data['description_short'] ?? '';
            $product->description[$idLang] = $data['description_long'] ?? $data['description_short'] ?? '';
            $product->link_rewrite[$idLang] = Tools::str2url($data['name'] ?? 'product');
        }

        $product->reference = $data['sku'] ?? '';
        $product->ean13 = $data['ean'] ?? '';
        $product->price = round($priceHT, 6);
        $product->weight = (float) ($data['weight'] ?? 0);
        $product->active = 1;
        $product->id_shop_default = $this->idShop;
        $product->visibility = 'both';
        $product->available_for_order = 1;
        $product->show_price = 1;
        $product->id_category_default = $this->resolveCategoryId($data);

        // Tax rule
        $product->id_tax_rules_group = $this->getTaxRulesGroupByRate($taxRate);

        // Manufacturer (brand)
        if (!empty($data['brand'])) {
            $product->id_manufacturer = $this->getOrCreateManufacturer($data['brand']);
        }
    }

    private function resolveCategoryId(array $data): int
    {
        $cat = $data['category'] ?? [];

        // Try subcategory first
        if (!empty($cat['subcategory_slug'])) {
            $mapped = $this->db->getValue(
                'SELECT `id_category` FROM `' . $this->prefix . 'crmcycles_category_map`
                 WHERE `crm_type` = "subcategory" AND `crm_slug` = "' . pSQL($cat['subcategory_slug']) . '"'
            );
            if ($mapped) {
                return (int) $mapped;
            }
        }

        // Try category
        if (!empty($cat['category_slug'])) {
            $mapped = $this->db->getValue(
                'SELECT `id_category` FROM `' . $this->prefix . 'crmcycles_category_map`
                 WHERE `crm_type` = "category" AND `crm_slug` = "' . pSQL($cat['category_slug']) . '"'
            );
            if ($mapped) {
                return (int) $mapped;
            }
        }

        // Try family
        if (!empty($cat['family_slug'])) {
            $mapped = $this->db->getValue(
                'SELECT `id_category` FROM `' . $this->prefix . 'crmcycles_category_map`
                 WHERE `crm_type` = "family" AND `crm_slug` = "' . pSQL($cat['family_slug']) . '"'
            );
            if ($mapped) {
                return (int) $mapped;
            }
        }

        return (int) Configuration::get('CRMCYCLES_ROOT_CATEGORY') ?: (int) Configuration::get('PS_HOME_CATEGORY');
    }

    private function assignProductCategory(Product $product, array $data): void
    {
        $categoryId = $this->resolveCategoryId($data);
        $product->id_category_default = $categoryId;

        // Build full category path
        $categoryIds = [$categoryId];
        $cat = $data['category'] ?? [];

        if (!empty($cat['family_slug'])) {
            $famId = (int) $this->db->getValue(
                'SELECT `id_category` FROM `' . $this->prefix . 'crmcycles_category_map`
                 WHERE `crm_type` = "family" AND `crm_slug` = "' . pSQL($cat['family_slug']) . '"'
            );
            if ($famId) {
                $categoryIds[] = $famId;
            }
        }
        if (!empty($cat['category_slug'])) {
            $catId = (int) $this->db->getValue(
                'SELECT `id_category` FROM `' . $this->prefix . 'crmcycles_category_map`
                 WHERE `crm_type` = "category" AND `crm_slug` = "' . pSQL($cat['category_slug']) . '"'
            );
            if ($catId) {
                $categoryIds[] = $catId;
            }
        }

        $rootId = (int) Configuration::get('CRMCYCLES_ROOT_CATEGORY') ?: (int) Configuration::get('PS_HOME_CATEGORY');
        $categoryIds[] = $rootId;

        $product->updateCategories(array_unique($categoryIds));
        $product->save();
    }

    private function assignProductFeatures(int $idProduct, array $characteristics): void
    {
        if (empty($characteristics)) {
            return;
        }

        foreach ($characteristics as $charName => $charValue) {
            if (empty($charValue)) {
                continue;
            }

            $idFeature = $this->syncFeature($charName);
            $idFeatureValue = $this->syncFeatureValue($charName, $charValue, $idFeature);

            // Remove existing assignment for this feature on this product
            $this->db->delete('feature_product',
                '`id_product` = ' . $idProduct . ' AND `id_feature` = ' . $idFeature
            );

            $this->db->insert('feature_product', [
                'id_product' => $idProduct,
                'id_feature' => $idFeature,
                'id_feature_value' => $idFeatureValue,
            ]);
        }
    }

    private function syncProductImages(Product $product, array $data): void
    {
        $images = $data['images'] ?? [];
        if (empty($images) && !empty($data['image_url'])) {
            $images = [$data['image_url']];
        }

        if (empty($images)) {
            return;
        }

        // Check if product already has valid images (with actual files on disk)
        $existingImages = Image::getImages($this->idLang, (int) $product->id);
        if (!empty($existingImages)) {
            $hasValidImage = false;
            foreach ($existingImages as $existingImg) {
                $imgObj = new Image((int) $existingImg['id_image']);
                $imgPath = $imgObj->getPathForCreation() . '.jpg';
                if (file_exists($imgPath)) {
                    $hasValidImage = true;
                    break;
                }
            }

            if ($hasValidImage) {
                return;
            }

            // Clean up orphaned image records without files
            foreach ($existingImages as $existingImg) {
                $imgObj = new Image((int) $existingImg['id_image']);
                $imgObj->delete();
            }
        }

        $devMode = (bool) Configuration::get('CRMCYCLES_DEV_MODE');

        $cover = true;
        foreach ($images as $imageUrl) {
            if (empty($imageUrl) || !is_string($imageUrl) || !preg_match('#^https?://#i', $imageUrl)) {
                continue;
            }

            try {
                // Download image via cURL (handles SSL like the API client)
                $tmpFile = tempnam(_PS_TMP_IMG_DIR_, 'crm');
                $downloaded = $this->downloadFile($imageUrl, $tmpFile, $devMode);

                if (!$downloaded || !filesize($tmpFile)) {
                    @unlink($tmpFile);
                    $this->log[] = 'Image non téléchargée: ' . $imageUrl;
                    continue;
                }

                $image = new Image();
                $image->id_product = (int) $product->id;
                $image->cover = $cover ? 1 : 0;
                $image->position = Image::getHighestPosition((int) $product->id) + 1;

                foreach (Language::getLanguages(false) as $lang) {
                    $image->legend[$lang['id_lang']] = $product->name[$lang['id_lang']] ?? '';
                }

                $image->add();

                $newPath = $image->getPathForCreation();
                ImageManager::resize($tmpFile, $newPath . '.jpg');

                // Generate thumbnails
                $types = ImageType::getImagesTypes('products');
                foreach ($types as $type) {
                    ImageManager::resize(
                        $tmpFile,
                        $newPath . '-' . stripslashes($type['name']) . '.jpg',
                        (int) $type['width'],
                        (int) $type['height']
                    );
                }

                @unlink($tmpFile);
                $cover = false;
            } catch (Exception $e) {
                $this->log[] = 'Erreur image produit #' . $product->id . ': ' . $e->getMessage();
            }
        }
    }

    private function downloadFile(string $url, string $destPath, bool $devMode = false): bool
    {
        $ch = curl_init();
        $fp = fopen($destPath, 'wb');

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => !$devMode,
            CURLOPT_SSL_VERIFYHOST => $devMode ? 0 : 2,
            CURLOPT_USERAGENT => 'PrestaShop CrmCycles/1.0',
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($error || $httpCode >= 400) {
            return false;
        }

        return true;
    }

    private function getOrCreateAttributeGroup(string $name): int
    {
        $idGroup = (int) $this->db->getValue(
            'SELECT `id_attribute_group` FROM `' . $this->prefix . 'attribute_group_lang`
             WHERE `name` = "' . pSQL($name) . '" AND `id_lang` = ' . $this->idLang
        );

        if ($idGroup) {
            return $idGroup;
        }

        $group = new AttributeGroup();
        $group->group_type = 'select';
        $group->is_color_group = 0;

        foreach (Language::getLanguages(false) as $lang) {
            $group->name[$lang['id_lang']] = $name;
            $group->public_name[$lang['id_lang']] = $name;
        }

        $group->add();
        $group->associateTo([$this->idShop]);

        return (int) $group->id;
    }

    private function getOrCreateAttribute(int $idAttributeGroup, string $value): int
    {
        $idAttribute = (int) $this->db->getValue(
            'SELECT a.`id_attribute`
             FROM `' . $this->prefix . 'attribute` a
             INNER JOIN `' . $this->prefix . 'attribute_lang` al
                ON a.`id_attribute` = al.`id_attribute`
             WHERE a.`id_attribute_group` = ' . $idAttributeGroup . '
               AND al.`name` = "' . pSQL($value) . '"
               AND al.`id_lang` = ' . $this->idLang
        );

        if ($idAttribute) {
            return $idAttribute;
        }

        $attr = new ProductAttribute();
        $attr->id_attribute_group = $idAttributeGroup;

        foreach (Language::getLanguages(false) as $lang) {
            $attr->name[$lang['id_lang']] = $value;
        }

        $attr->add();
        $attr->associateTo([$this->idShop]);

        return (int) $attr->id;
    }

    private function getOrCreateCombination(int $idProduct, int $idAttribute, string $reference): int
    {
        return $this->getOrCreateCombinationMulti($idProduct, [$idAttribute], $reference);
    }

    private function getOrCreateCombinationMulti(int $idProduct, array $attributeIds, string $reference): int
    {
        // Check if combination with this reference already exists
        $existingId = (int) $this->db->getValue(
            'SELECT `id_product_attribute`
             FROM `' . $this->prefix . 'product_attribute`
             WHERE `id_product` = ' . $idProduct . '
               AND `reference` = "' . pSQL($reference) . '"'
        );

        if ($existingId) {
            return $existingId;
        }

        $combination = new Combination();
        $combination->id_product = $idProduct;
        $combination->reference = $reference;
        $combination->default_on = 0;
        $combination->add();

        // Associate attributes
        $combination->setAttributes($attributeIds);

        return (int) $combination->id;
    }

    private function getOrCreateManufacturer(string $name): int
    {
        $id = (int) $this->db->getValue(
            'SELECT `id_manufacturer` FROM `' . $this->prefix . 'manufacturer`
             WHERE `name` = "' . pSQL($name) . '"'
        );

        if ($id) {
            return $id;
        }

        $manufacturer = new Manufacturer();
        $manufacturer->name = $name;
        $manufacturer->active = 1;
        $manufacturer->add();

        return (int) $manufacturer->id;
    }

    private function getTaxRulesGroupByRate(float $rate): int
    {
        // Find an existing tax rules group matching this rate for France
        $id = (int) $this->db->getValue(
            'SELECT trg.`id_tax_rules_group`
             FROM `' . $this->prefix . 'tax_rules_group` trg
             INNER JOIN `' . $this->prefix . 'tax_rule` tr ON trg.`id_tax_rules_group` = tr.`id_tax_rules_group`
             INNER JOIN `' . $this->prefix . 'tax` t ON tr.`id_tax` = t.`id_tax`
             WHERE t.`rate` = ' . (float) $rate . '
               AND tr.`id_country` = (SELECT `id_country` FROM `' . $this->prefix . 'country` WHERE `iso_code` = "FR" LIMIT 1)
               AND trg.`active` = 1
               AND trg.`deleted` = 0'
        );

        return $id ?: 1; // Fallback to default tax group
    }

    private function getProductIdBySku(string $sku): int
    {
        return (int) $this->db->getValue(
            'SELECT `id_product` FROM `' . $this->prefix . 'product`
             WHERE `reference` = "' . pSQL($sku) . '"'
        );
    }

    private function getCombinationIdBySku(string $sku): int
    {
        return (int) $this->db->getValue(
            'SELECT `id_product_attribute` FROM `' . $this->prefix . 'product_attribute`
             WHERE `reference` = "' . pSQL($sku) . '"'
        );
    }

    private function saveProductMapping(int $crmId, string $sku, int $idProduct, bool $isCollection, ?int $colId): void
    {
        $existing = $this->db->getRow(
            'SELECT * FROM `' . $this->prefix . 'crmcycles_product_map`
             WHERE `crm_product_id` = ' . $crmId
        );

        if ($existing) {
            $this->db->update('crmcycles_product_map', [
                'crm_sku' => pSQL($sku),
                'id_product' => $idProduct,
                'is_collection' => (int) $isCollection,
                'crm_collection_id' => $colId ? (int) $colId : null,
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'crm_product_id = ' . $crmId);
        } else {
            $this->db->insert('crmcycles_product_map', [
                'crm_product_id' => $crmId,
                'crm_sku' => pSQL($sku),
                'id_product' => $idProduct,
                'is_collection' => (int) $isCollection,
                'crm_collection_id' => $colId ? (int) $colId : null,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function saveCombinationMapping(int $crmProductId, string $sku, int $idProduct, int $idCombination): void
    {
        $existing = $this->db->getRow(
            'SELECT * FROM `' . $this->prefix . 'crmcycles_combination_map`
             WHERE `crm_product_id` = ' . $crmProductId
        );

        if ($existing) {
            $this->db->update('crmcycles_combination_map', [
                'crm_sku' => pSQL($sku),
                'id_product' => $idProduct,
                'id_product_attribute' => $idCombination,
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'crm_product_id = ' . $crmProductId);
        } else {
            $this->db->insert('crmcycles_combination_map', [
                'crm_product_id' => $crmProductId,
                'crm_sku' => pSQL($sku),
                'id_product' => $idProduct,
                'id_product_attribute' => $idCombination,
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function translateCondition(string $condition): string
    {
        $map = [
            'new' => 'Neuf',
            'used' => 'Occasion',
            'refurbished' => 'Reconditionné',
        ];
        return $map[$condition] ?? $condition;
    }

    private function startLog(string $type): int
    {
        $this->db->insert('crmcycles_sync_log', [
            'sync_type' => pSQL($type),
            'status' => 'running',
            'date_start' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->Insert_ID();
    }

    private function endLog(int $id, string $status, string $summary): void
    {
        $this->db->update('crmcycles_sync_log', [
            'status' => pSQL($status),
            'summary' => pSQL($summary),
            'date_end' => date('Y-m-d H:i:s'),
        ], 'id_crmcycles_sync_log = ' . $id);
    }
}
