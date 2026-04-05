<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Google Content API for Shopping v2.1 — push products via service account.
 *
 * Authentication: JWT → OAuth2 access token (cached in Configuration).
 * No external SDK required.
 */
class CrmCyclesGoogleMerchant
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://shoppingcontent.googleapis.com/content/v2.1/';
    private const SCOPE = 'https://www.googleapis.com/auth/content';

    /** @var string */
    private $merchantId;

    /** @var array Service account credentials */
    private $credentials;

    /** @var string Cached access token */
    private $accessToken;

    /** @var int */
    private $idLang;

    /** @var int */
    private $idShop;

    /** @var string */
    private $currencyIso;

    /** @var string */
    private $targetCountry;

    /** @var array Log messages */
    private $log = [];

    public function __construct()
    {
        $this->merchantId = Configuration::get('CRMCYCLES_GMERCHANT_MERCHANT_ID');
        $this->targetCountry = Configuration::get('CRMCYCLES_GMERCHANT_COUNTRY') ?: 'FR';
        $this->idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $this->idShop = (int) Context::getContext()->shop->id;

        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $this->currencyIso = $currency->iso_code;

        $credentialsJson = Configuration::get('CRMCYCLES_GMERCHANT_CREDENTIALS');
        if ($credentialsJson) {
            $this->credentials = json_decode($credentialsJson, true);
        }
    }

    public function getLog(): array
    {
        return $this->log;
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    /**
     * Get a valid access token, refreshing if needed.
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        // Check cached token
        $cached = Configuration::get('CRMCYCLES_GMERCHANT_TOKEN');
        if ($cached) {
            $data = json_decode($cached, true);
            if (!empty($data['access_token']) && !empty($data['expires_at']) && $data['expires_at'] > time() + 60) {
                $this->accessToken = $data['access_token'];
                return $this->accessToken;
            }
        }

        // Generate new token via JWT
        $token = $this->requestAccessToken();
        $this->accessToken = $token;

        return $this->accessToken;
    }

    /**
     * Create a JWT, exchange it for an OAuth2 access token.
     */
    private function requestAccessToken(): string
    {
        if (empty($this->credentials['private_key']) || empty($this->credentials['client_email'])) {
            throw new \RuntimeException('Identifiants du compte de service Google manquants ou invalides.');
        }

        $now = time();
        $header = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64url(json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = $header . '.' . $claim;
        $privateKey = openssl_pkey_get_private($this->credentials['private_key']);

        if (!$privateKey) {
            throw new \RuntimeException('Clé privée du compte de service invalide.');
        }

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Échec de la signature JWT.');
        }

        $jwt = $signingInput . '.' . $this->base64url($signature);

        // Exchange JWT for access token
        $ch = curl_init(self::TOKEN_URI);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('Échec OAuth2 Google (HTTP ' . $httpCode . '): ' . $response);
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new \RuntimeException('Réponse OAuth2 Google invalide: ' . $response);
        }

        // Cache token
        Configuration::updateValue('CRMCYCLES_GMERCHANT_TOKEN', json_encode([
            'access_token' => $data['access_token'],
            'expires_at' => time() + (int) ($data['expires_in'] ?? 3600),
        ]));

        return $data['access_token'];
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // =========================================================================
    // API calls
    // =========================================================================

    /**
     * Test connection by listing products (limit 1).
     */
    public function testConnection(): array
    {
        try {
            $result = $this->apiGet('products', ['maxResults' => 1]);
            return ['success' => true, 'data' => $result];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Push a single PrestaShop product (all its combinations) to Google.
     */
    public function pushProduct(int $idProduct): array
    {
        $product = new Product($idProduct, false, $this->idLang);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return ['success' => false, 'message' => 'Produit inactif ou introuvable: ' . $idProduct];
        }

        $combinations = $this->getProductCombinations($idProduct);
        $pushed = 0;
        $errors = 0;

        if (!empty($combinations)) {
            foreach ($combinations as $combi) {
                $entry = $this->buildProductEntry($product, $combi);
                try {
                    $this->apiPost('products', $entry);
                    $pushed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->log[] = $product->name . ' [' . $combi['attribute_name'] . ']: ' . $e->getMessage();
                }
            }
        } else {
            $entry = $this->buildProductEntry($product);
            try {
                $this->apiPost('products', $entry);
                $pushed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->log[] = $product->name . ': ' . $e->getMessage();
            }
        }

        return [
            'success' => $errors === 0,
            'pushed' => $pushed,
            'errors' => $errors,
            'message' => $product->name . ': ' . $pushed . ' envoyé(s), ' . $errors . ' erreur(s)',
        ];
    }

    /**
     * Delete a product (all its variants) from Google Merchant.
     */
    public function deleteProduct(int $idProduct): array
    {
        $combinations = $this->getProductCombinations($idProduct);
        $deleted = 0;
        $errors = 0;

        if (!empty($combinations)) {
            foreach ($combinations as $combi) {
                $offerId = 'online:' . $this->targetCountry . ':' . $this->currencyIso . ':' . $idProduct . '-' . $combi['id_product_attribute'];
                // Simpler: use the offerId directly
                $offerId = $idProduct . '-' . $combi['id_product_attribute'];
                try {
                    $this->apiDelete('products/online:' . strtolower($this->targetCountry) . ':fr:' . $offerId);
                    $deleted++;
                } catch (\Throwable $e) {
                    $errors++;
                }
            }
        } else {
            try {
                $this->apiDelete('products/online:' . strtolower($this->targetCountry) . ':fr:' . $idProduct);
                $deleted++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return ['success' => $errors === 0, 'deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Push all active products. Returns stats.
     */
    public function pushAllProducts(bool $onlyInStock = false): array
    {
        $products = $this->getActiveProducts($onlyInStock);
        $stats = ['total' => count($products), 'pushed' => 0, 'errors' => 0];

        foreach ($products as $product) {
            $result = $this->pushProduct((int) $product['id_product']);
            $stats['pushed'] += $result['pushed'];
            $stats['errors'] += $result['errors'];
        }

        return $stats;
    }

    /**
     * Build a single product queue for AJAX progress.
     */
    public function buildPushQueue(bool $onlyInStock = false): array
    {
        $products = $this->getActiveProducts($onlyInStock);
        $queue = [];

        foreach ($products as $p) {
            $queue[] = [
                'id_product' => (int) $p['id_product'],
                'name' => $p['name'],
            ];
        }

        return ['success' => true, 'queue' => $queue, 'message' => count($queue) . ' produits à envoyer'];
    }

    // =========================================================================
    // Product data formatting
    // =========================================================================

    private function buildProductEntry(Product $product, ?array $combination = null): array
    {
        $idProduct = (int) $product->id;
        $link = Context::getContext()->link;

        $productUrl = $link->getProductLink($idProduct, $product->link_rewrite[$this->idLang] ?? null, null, null, $this->idLang, $this->idShop);
        $imageUrl = $this->getMainImageUrl($idProduct);

        // Brand
        $brand = '';
        if ($product->id_manufacturer) {
            $manufacturer = new Manufacturer((int) $product->id_manufacturer, $this->idLang);
            $brand = $manufacturer->name;
        }

        // Category breadcrumb
        $breadcrumb = $this->getCategoryBreadcrumb((int) $product->id_category_default);

        // Description
        $description = strip_tags($product->description_short[$this->idLang] ?? $product->description[$this->idLang] ?? '');
        $description = mb_substr(trim($description), 0, 5000);

        // Condition
        $conditionMap = ['new' => 'new', 'used' => 'used', 'refurbished' => 'refurbished'];
        $condition = $conditionMap[$product->condition] ?? 'new';

        $contentLanguage = Language::getIsoById($this->idLang) ?: 'fr';

        if ($combination) {
            $idPa = (int) $combination['id_product_attribute'];
            $offerId = $idProduct . '-' . $idPa;
            $title = $product->name . ' — ' . $combination['attribute_name'];

            $price = Product::getPriceStatic($idProduct, true, $idPa, 2, null, false, false);
            $salePrice = Product::getPriceStatic($idProduct, true, $idPa, 2);
            $stock = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $idPa);

            $combiImageUrl = $this->getCombinationImageUrl($idProduct, $idPa) ?: $imageUrl;
            $ean = $combination['ean13'] ?: $product->ean13;
            $mpn = $combination['reference'] ?: $product->reference;

            $entry = [
                'offerId' => $offerId,
                'title' => $title,
                'description' => $description,
                'link' => $productUrl . '#/' . $idPa,
                'imageLink' => $combiImageUrl,
                'contentLanguage' => $contentLanguage,
                'targetCountry' => $this->targetCountry,
                'channel' => 'online',
                'availability' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                'condition' => $condition,
                'price' => [
                    'value' => number_format($price, 2, '.', ''),
                    'currency' => $this->currencyIso,
                ],
                'itemGroupId' => (string) $idProduct,
            ];

            if ($salePrice < $price) {
                $entry['salePrice'] = [
                    'value' => number_format($salePrice, 2, '.', ''),
                    'currency' => $this->currencyIso,
                ];
            }

            // Variant attributes
            foreach ($combination['attributes'] as $groupName => $valueName) {
                $groupLower = mb_strtolower($groupName);
                if (strpos($groupLower, 'couleur') !== false || strpos($groupLower, 'color') !== false) {
                    $entry['color'] = $valueName;
                } elseif (strpos($groupLower, 'taille') !== false || strpos($groupLower, 'size') !== false) {
                    $entry['sizes'] = [$valueName];
                }
            }
        } else {
            $offerId = (string) $idProduct;
            $title = $product->name;

            $price = Product::getPriceStatic($idProduct, true, null, 2, null, false, false);
            $salePrice = Product::getPriceStatic($idProduct, true, null, 2);
            $stock = (int) StockAvailable::getQuantityAvailableByProduct($idProduct);

            $ean = $product->ean13;
            $mpn = $product->reference;

            $entry = [
                'offerId' => $offerId,
                'title' => $title,
                'description' => $description,
                'link' => $productUrl,
                'imageLink' => $imageUrl,
                'contentLanguage' => $contentLanguage,
                'targetCountry' => $this->targetCountry,
                'channel' => 'online',
                'availability' => $stock > 0 ? 'in_stock' : 'out_of_stock',
                'condition' => $condition,
                'price' => [
                    'value' => number_format($price, 2, '.', ''),
                    'currency' => $this->currencyIso,
                ],
            ];

            if ($salePrice < $price) {
                $entry['salePrice'] = [
                    'value' => number_format($salePrice, 2, '.', ''),
                    'currency' => $this->currencyIso,
                ];
            }
        }

        if (!empty($brand)) {
            $entry['brand'] = $brand;
        }

        if (!empty($ean)) {
            $entry['gtin'] = $ean;
        }

        if (!empty($mpn)) {
            $entry['mpn'] = $mpn;
        }

        if (empty($ean) && empty($mpn)) {
            $entry['identifierExists'] = false;
        }

        if ($breadcrumb) {
            $entry['productTypes'] = [$breadcrumb];
        }

        return $entry;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function getActiveProducts(bool $onlyInStock = false): array
    {
        $sql = 'SELECT p.`id_product`, pl.`name`
                FROM `' . _DB_PREFIX_ . 'product` p
                LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON p.`id_product` = pl.`id_product` AND pl.`id_lang` = ' . $this->idLang . '
                    AND pl.`id_shop` = ' . $this->idShop . '
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

    private function getProductCombinations(int $idProduct): array
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

        return Context::getContext()->link->getImageLink(
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

        return Context::getContext()->link->getImageLink(
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

    // =========================================================================
    // HTTP
    // =========================================================================

    private function apiGet(string $endpoint, array $params = []): array
    {
        $url = self::API_BASE . $this->merchantId . '/' . $endpoint;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url);
    }

    private function apiPost(string $endpoint, array $body): array
    {
        $url = self::API_BASE . $this->merchantId . '/' . $endpoint;
        return $this->request('POST', $url, $body);
    }

    private function apiDelete(string $endpoint): array
    {
        $url = self::API_BASE . $this->merchantId . '/' . $endpoint;
        return $this->request('DELETE', $url);
    }

    private function request(string $method, string $url, ?array $body = null): array
    {
        $token = $this->getAccessToken();

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($body !== null) {
            $json = json_encode($body);
            $opts[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \RuntimeException('Erreur cURL: ' . $curlError);
        }

        if ($httpCode === 204) {
            return [];
        }

        $data = json_decode($response, true) ?: [];

        if ($httpCode >= 400) {
            $errorMsg = $data['error']['message'] ?? $response;
            throw new \RuntimeException('Google API (' . $httpCode . '): ' . $errorMsg);
        }

        return $data;
    }
}
