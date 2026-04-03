<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class CrmCyclesApi
{
    private $apiUrl;
    private $apiSecret;
    private $storeKey;
    private $devMode;

    public function __construct()
    {
        $this->apiUrl = rtrim(Configuration::get('CRMCYCLES_API_URL'), '/');
        $this->apiSecret = Configuration::get('CRMCYCLES_API_SECRET');
        $this->storeKey = Configuration::get('CRMCYCLES_STORE_KEY') ?: 'guidel';
        $this->devMode = (bool) Configuration::get('CRMCYCLES_DEV_MODE');
    }

    /**
     * Test API connection
     */
    public function testConnection(): array
    {
        return $this->get('/shop/branding');
    }

    /**
     * Get category hierarchy (families > categories > subcategories)
     */
    public function getCategories(): array
    {
        return $this->get('/shop/categories');
    }

    /**
     * Get all characteristic types with their values
     */
    public function getCharacteristics(): array
    {
        return $this->get('/shop/characteristics');
    }

    /**
     * Get products with pagination
     */
    public function getProducts(int $page = 1, int $perPage = 100, bool $allProducts = false): array
    {
        $params = [
            'page' => $page,
            'per_page' => $perPage,
        ];
        if ($allProducts) {
            $params['all'] = 1;
        }
        return $this->get('/shop/products', $params);
    }

    /**
     * Get single product detail (with serialized items, collection variants, characteristics)
     */
    public function getProduct(int $id): array
    {
        return $this->get('/shop/products/' . $id);
    }

    /**
     * Get all products on promotion
     */
    public function getPromotions(int $perPage = 100): array
    {
        return $this->get('/shop/promotions', [
            'per_page' => $perPage,
        ]);
    }

    /**
     * Fetch all products across all pages
     */
    public function getAllProducts(bool $allProducts = false): array
    {
        $allData = [];
        $page = 1;

        do {
            $response = $this->getProducts($page, 100, $allProducts);

            if (!$response['success'] || empty($response['data'])) {
                break;
            }

            $allData = array_merge($allData, $response['data']);
            $pageCount = $response['meta']['page_count'] ?? 1;
            $page++;
        } while ($page <= $pageCount);

        return $allData;
    }

    private function get(string $endpoint, array $params = []): array
    {
        $url = $this->apiUrl . '/api/v1' . $endpoint;

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $this->request('GET', $url);
    }

    private function request(string $method, string $url): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => !$this->devMode,
            CURLOPT_SSL_VERIFYHOST => $this->devMode ? 0 : 2,
            CURLOPT_HTTPHEADER => [
                'X-Api-Secret: ' . $this->apiSecret,
                'X-Store-Key: ' . $this->storeKey,
                'Accept: application/json',
                'Accept-Language: fr-FR',
            ],
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => ['message' => 'cURL error: ' . $error]];
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400 || !$data) {
            return [
                'success' => false,
                'error' => [
                    'code' => $httpCode,
                    'message' => $data['error']['message'] ?? 'HTTP ' . $httpCode,
                ],
            ];
        }

        return $data;
    }
}
