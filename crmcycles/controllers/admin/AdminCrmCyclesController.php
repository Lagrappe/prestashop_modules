<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'crmcycles/classes/CrmCyclesApi.php';
require_once _PS_MODULE_DIR_ . 'crmcycles/classes/CrmCyclesImporter.php';
require_once _PS_MODULE_DIR_ . 'crmcycles/classes/CrmCyclesGoogleMerchant.php';

class AdminCrmCyclesController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        parent::initContent();

        // Handle AJAX actions
        if (Tools::isSubmit('ajax') && Tools::getValue('action_crm')) {
            $this->processAjaxAction();
            return;
        }

        // Cleanup orphaned marquage associations
        $this->cleanupMarquageOrphans();

        // Handle form submissions
        if (Tools::isSubmit('submitCrmCyclesConfig')) {
            $this->processConfiguration();
        }

        if (Tools::isSubmit('submitTestConnection')) {
            $this->processTestConnection();
        }

        if (Tools::isSubmit('submitImportCategories')) {
            $this->processImportCategories();
        }

        if (Tools::isSubmit('submitImportFeatures')) {
            $this->processImportFeatures();
        }

        if (Tools::isSubmit('submitGenerateMenu')) {
            $this->processGenerateMenu();
        }

        if (Tools::isSubmit('submitGoogleMerchantConfig')) {
            $this->processGoogleMerchantConfig();
        }

        if (Tools::isSubmit('submitTestGoogleMerchant')) {
            $this->processTestGoogleMerchant();
        }

        $this->context->smarty->assign($this->getTemplateVars());
        $this->setTemplate('configure.tpl');
    }

    private function getTemplateVars(): array
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        // Sync logs (last 20)
        $logs = $db->executeS(
            'SELECT * FROM `' . $prefix . 'crmcycles_sync_log`
             ORDER BY `date_start` DESC LIMIT 20'
        ) ?: [];

        // Stats
        $catCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . $prefix . 'crmcycles_category_map`');
        $prodCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . $prefix . 'crmcycles_product_map`');
        $combiCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . $prefix . 'crmcycles_combination_map`');
        $featCount = (int) $db->getValue('SELECT COUNT(*) FROM `' . $prefix . 'crmcycles_feature_map`');

        // Categories for parent selection (flat list with depth)
        $categories = $this->getCategoryList();

        // Marquage configurations
        $marquageConfigs = $db->executeS(
            'SELECT mc.`id_category`, mc.`id_product_marquage`,
                    cl.`name` AS category_name, pl.`name` AS product_name
             FROM `' . $prefix . 'marquage_category` mc
             LEFT JOIN `' . $prefix . 'category_lang` cl
                ON mc.`id_category` = cl.`id_category` AND cl.`id_lang` = ' . (int) $this->context->language->id . '
             LEFT JOIN `' . $prefix . 'product_lang` pl
                ON mc.`id_product_marquage` = pl.`id_product` AND pl.`id_lang` = ' . (int) $this->context->language->id . '
                AND pl.`id_shop` = ' . (int) $this->context->shop->id
        ) ?: [];

        // Trial categories
        $trialCategories = $db->executeS(
            'SELECT tc.`id_category`, cl.`name` AS category_name
             FROM `' . $prefix . 'crmcycles_trial_category` tc
             LEFT JOIN `' . $prefix . 'category_lang` cl
                ON tc.`id_category` = cl.`id_category` AND cl.`id_lang` = ' . (int) $this->context->language->id . '
             ORDER BY cl.`name` ASC'
        ) ?: [];

        // Trial requests (last 20)
        $trialRequests = $db->executeS(
            'SELECT st.*, pl.`name` AS product_name
             FROM `' . $prefix . 'crmcycles_store_trial` st
             LEFT JOIN `' . $prefix . 'product_lang` pl
                ON st.`id_product` = pl.`id_product` AND pl.`id_lang` = ' . (int) $this->context->language->id . '
                AND pl.`id_shop` = ' . (int) $this->context->shop->id . '
             ORDER BY st.`date_add` DESC LIMIT 20'
        ) ?: [];

        return [
            'module_dir' => _MODULE_DIR_ . 'crmcycles/',
            'api_url' => Configuration::get('CRMCYCLES_API_URL'),
            'api_secret' => Configuration::get('CRMCYCLES_API_SECRET'),
            'store_key' => Configuration::get('CRMCYCLES_STORE_KEY') ?: 'guidel',
            'portal_url' => Configuration::get('CRMCYCLES_PORTAL_URL'),
            'root_category' => (int) Configuration::get('CRMCYCLES_ROOT_CATEGORY'),
            'invoice_override' => (int) Configuration::get('CRMCYCLES_INVOICE_OVERRIDE'),
            'dev_mode' => (int) Configuration::get('CRMCYCLES_DEV_MODE'),
            'last_sync' => Configuration::get('CRMCYCLES_LAST_SYNC'),
            'sync_logs' => $logs,
            'stat_categories' => $catCount,
            'stat_products' => $prodCount,
            'stat_combinations' => $combiCount,
            'stat_features' => $featCount,
            'categories' => $categories,
            'marquage_configs' => $marquageConfigs,
            'trial_categories' => $trialCategories,
            'trial_requests' => $trialRequests,
            'cron_token' => Tools::substr(Tools::hash('crmcycles/cron'), 0, 10),
            'cron_url' => $this->context->link->getModuleLink('crmcycles', 'cron', [
                'token' => Tools::substr(Tools::hash('crmcycles/cron'), 0, 10),
            ]),
            'ajax_url' => $this->context->link->getAdminLink('AdminCrmCycles'),
            'token' => Tools::getAdminTokenLite('AdminCrmCycles'),
            'gmerchant_enabled' => (int) Configuration::get('CRMCYCLES_GMERCHANT_ENABLED'),
            'gmerchant_description' => Configuration::get('CRMCYCLES_GMERCHANT_DESCRIPTION') ?: '',
            'gmerchant_only_instock' => (int) Configuration::get('CRMCYCLES_GMERCHANT_ONLY_INSTOCK'),
            'gmerchant_merchant_id' => Configuration::get('CRMCYCLES_GMERCHANT_MERCHANT_ID') ?: '',
            'gmerchant_country' => Configuration::get('CRMCYCLES_GMERCHANT_COUNTRY') ?: 'FR',
            'gmerchant_has_credentials' => !empty(Configuration::get('CRMCYCLES_GMERCHANT_CREDENTIALS')),
            'gmerchant_service_email' => $this->getGoogleServiceEmail(),
            'gmerchant_feed_url' => $this->context->link->getModuleLink('crmcycles', 'googlefeed', [
                'token' => Tools::substr(Tools::hash('crmcycles/googlefeed'), 0, 10),
            ]),
        ];
    }

    private function processConfiguration(): void
    {
        Configuration::updateValue('CRMCYCLES_API_URL', Tools::getValue('CRMCYCLES_API_URL'));
        Configuration::updateValue('CRMCYCLES_API_SECRET', Tools::getValue('CRMCYCLES_API_SECRET'));
        Configuration::updateValue('CRMCYCLES_STORE_KEY', Tools::getValue('CRMCYCLES_STORE_KEY'));
        Configuration::updateValue('CRMCYCLES_PORTAL_URL', Tools::getValue('CRMCYCLES_PORTAL_URL'));
        Configuration::updateValue('CRMCYCLES_ROOT_CATEGORY', (int) Tools::getValue('CRMCYCLES_ROOT_CATEGORY'));
        Configuration::updateValue('CRMCYCLES_INVOICE_OVERRIDE', (int) Tools::getValue('CRMCYCLES_INVOICE_OVERRIDE'));
        Configuration::updateValue('CRMCYCLES_DEV_MODE', (int) Tools::getValue('CRMCYCLES_DEV_MODE'));

        $this->confirmations[] = $this->l('Configuration sauvegardée.');
    }

    private function processGoogleMerchantConfig(): void
    {
        Configuration::updateValue('CRMCYCLES_GMERCHANT_ENABLED', (int) Tools::getValue('CRMCYCLES_GMERCHANT_ENABLED'));
        Configuration::updateValue('CRMCYCLES_GMERCHANT_DESCRIPTION', Tools::getValue('CRMCYCLES_GMERCHANT_DESCRIPTION'));
        Configuration::updateValue('CRMCYCLES_GMERCHANT_ONLY_INSTOCK', (int) Tools::getValue('CRMCYCLES_GMERCHANT_ONLY_INSTOCK'));
        Configuration::updateValue('CRMCYCLES_GMERCHANT_MERCHANT_ID', trim(Tools::getValue('CRMCYCLES_GMERCHANT_MERCHANT_ID')));
        Configuration::updateValue('CRMCYCLES_GMERCHANT_COUNTRY', trim(Tools::getValue('CRMCYCLES_GMERCHANT_COUNTRY')) ?: 'FR');

        // Handle service account JSON
        $credentialsInput = trim(Tools::getValue('CRMCYCLES_GMERCHANT_CREDENTIALS_INPUT'));
        if (!empty($credentialsInput)) {
            $decoded = json_decode($credentialsInput, true);
            if ($decoded && !empty($decoded['private_key']) && !empty($decoded['client_email'])) {
                Configuration::updateValue('CRMCYCLES_GMERCHANT_CREDENTIALS', $credentialsInput, true);
                $this->confirmations[] = $this->l('Compte de service Google configuré : ') . $decoded['client_email'];
            } else {
                $this->errors[] = $this->l('Le JSON du compte de service est invalide. Il doit contenir au moins « private_key » et « client_email ».');
            }
        }

        if (empty($this->errors)) {
            $this->confirmations[] = $this->l('Configuration Google Merchant sauvegardée.');
        }
    }

    private function processTestGoogleMerchant(): void
    {
        $gm = new CrmCyclesGoogleMerchant();
        $result = $gm->testConnection();

        if ($result['success']) {
            $this->confirmations[] = $this->l('Connexion Google Merchant Center réussie !');
        } else {
            $this->errors[] = $this->l('Erreur Google Merchant : ') . ($result['message'] ?? 'Erreur inconnue');
        }
    }

    private function processTestConnection(): void
    {
        $api = new CrmCyclesApi();
        $result = $api->testConnection();

        if ($result['success']) {
            $data = $result['data'] ?? [];
            $companyName = $data['company_name'] ?? '';
            if ($companyName) {
                Configuration::updateValue('CRMCYCLES_COMPANY_NAME', $companyName);
            }
            $this->confirmations[] = $this->l('Connexion réussie !') . ' ' . $companyName;
        } else {
            $this->errors[] = $this->l('Erreur de connexion : ')
                . ($result['error']['message'] ?? 'Erreur inconnue');
        }
    }

    private function processImportCategories(): void
    {
        $importer = new CrmCyclesImporter();
        $result = $importer->importCategories();

        if ($result['success']) {
            $this->confirmations[] = $result['message'];
        } else {
            $this->errors[] = $result['message'];
        }

        foreach ($importer->getLog() as $log) {
            $this->warnings[] = $log;
        }
    }

    private function processImportFeatures(): void
    {
        $importer = new CrmCyclesImporter();
        $result = $importer->importFeatures();

        if ($result['success']) {
            $this->confirmations[] = $result['message'];
        } else {
            $this->errors[] = $result['message'];
        }

        foreach ($importer->getLog() as $log) {
            $this->warnings[] = $log;
        }
    }

    private function cleanupMarquageOrphans(): void
    {
        $prefix = _DB_PREFIX_;
        $db = Db::getInstance();

        // Remove marquage entries where the category no longer exists
        $deleted = $db->execute(
            'DELETE mc FROM `' . $prefix . 'marquage_category` mc
             LEFT JOIN `' . $prefix . 'category` c ON mc.`id_category` = c.`id_category`
             WHERE c.`id_category` IS NULL'
        );

        // Remove marquage entries where the product no longer exists
        $db->execute(
            'DELETE mc FROM `' . $prefix . 'marquage_category` mc
             LEFT JOIN `' . $prefix . 'product` p ON mc.`id_product_marquage` = p.`id_product`
             WHERE p.`id_product` IS NULL'
        );

        // Remove feature associations where the category no longer exists
        $db->execute(
            'DELETE mcf FROM `' . $prefix . 'marquage_category_feature` mcf
             LEFT JOIN `' . $prefix . 'category` c ON mcf.`id_category` = c.`id_category`
             WHERE c.`id_category` IS NULL'
        );

        // Remove feature associations where the feature no longer exists
        $db->execute(
            'DELETE mcf FROM `' . $prefix . 'marquage_category_feature` mcf
             LEFT JOIN `' . $prefix . 'feature` f ON mcf.`id_feature` = f.`id_feature`
             WHERE f.`id_feature` IS NULL'
        );
    }

    private function processGenerateMenu(): void
    {
        $db = Db::getInstance();
        $prefix = _DB_PREFIX_;

        // Get all family categories from our mapping (top-level items for the menu)
        $families = $db->executeS(
            'SELECT cm.`id_category`, cl.`name`
             FROM `' . $prefix . 'crmcycles_category_map` cm
             LEFT JOIN `' . $prefix . 'category_lang` cl
                ON cm.`id_category` = cl.`id_category` AND cl.`id_lang` = ' . (int) $this->context->language->id . '
             WHERE cm.`crm_type` = "family"
             ORDER BY cm.`crm_id` ASC'
        );

        if (empty($families)) {
            $this->errors[] = $this->l('Aucune famille importée. Importez d\'abord les catégories.');
            return;
        }

        // Read current menu items
        $currentItems = Configuration::get('MOD_BLOCKTOPMENU_ITEMS') ?: '';
        $currentArray = $currentItems ? explode(',', $currentItems) : [];

        // Build the new items list: keep existing non-CRM items, then add CRM families
        $crmCatIds = [];
        foreach ($families as $fam) {
            $crmCatIds[] = 'CAT' . (int) $fam['id_category'];
        }

        // Remove any previously added CRM categories to avoid duplicates
        $allCrmCatIds = $db->executeS(
            'SELECT `id_category` FROM `' . $prefix . 'crmcycles_category_map`'
        );
        $allCrmCatEntries = [];
        foreach ($allCrmCatIds ?: [] as $row) {
            $allCrmCatEntries[] = 'CAT' . (int) $row['id_category'];
        }

        $filteredItems = array_filter($currentArray, function ($item) use ($allCrmCatEntries) {
            return !in_array($item, $allCrmCatEntries);
        });

        // Merge: existing items + CRM family categories
        $newItems = array_merge($filteredItems, $crmCatIds);
        $newValue = implode(',', array_unique(array_filter($newItems)));

        Configuration::updateValue('MOD_BLOCKTOPMENU_ITEMS', $newValue);

        $familyNames = array_column($families, 'name');
        $this->confirmations[] = sprintf(
            $this->l('Menu principal mis à jour avec %d familles : %s'),
            count($families),
            implode(', ', $familyNames)
        );
    }

    private function processAjaxAction(): void
    {
        $action = Tools::getValue('action_crm');
        $importer = new CrmCyclesImporter();

        switch ($action) {
            case 'importCategories':
                $result = $importer->importCategories();
                break;

            case 'importFeatures':
                $result = $importer->importFeatures();
                break;

            case 'fetchPriceStockQueue':
                $result = $importer->buildPriceStockQueue();
                break;

            case 'syncSinglePriceStock':
                $originalPriceTtc = Tools::getValue('original_price_ttc', '');
                $productData = [
                    'sku' => Tools::getValue('sku'),
                    'price_ttc' => (float) Tools::getValue('price_ttc'),
                    'original_price_ttc' => $originalPriceTtc !== '' ? (float) $originalPriceTtc : null,
                    'tva_rate' => (float) Tools::getValue('tva_rate'),
                    'stock' => (int) Tools::getValue('stock'),
                    'promotion' => json_decode(Tools::getValue('promotion', ''), true) ?: null,
                ];
                $result = $importer->syncSinglePriceStock($productData);
                break;

            case 'startSyncLog':
                $syncType = Tools::getValue('sync_type', 'products');
                Db::getInstance()->insert('crmcycles_sync_log', [
                    'sync_type' => pSQL($syncType),
                    'status' => 'running',
                    'date_start' => date('Y-m-d H:i:s'),
                ]);
                $result = ['success' => true, 'log_id' => (int) Db::getInstance()->Insert_ID()];
                break;

            case 'endSyncLog':
                $logId = (int) Tools::getValue('log_id');
                $status = Tools::getValue('status', 'success');
                $summary = Tools::getValue('summary', '');
                Db::getInstance()->update('crmcycles_sync_log', [
                    'status' => pSQL($status),
                    'summary' => pSQL($summary),
                    'date_end' => date('Y-m-d H:i:s'),
                ], 'id_crmcycles_sync_log = ' . $logId);
                $result = ['success' => true];
                break;

            case 'fetchProductQueue':
                $includeOutOfStock = (bool) Tools::getValue('include_out_of_stock');
                $result = $importer->buildProductImportQueue($includeOutOfStock);
                break;

            case 'importSingleProduct':
                $crmId = (int) Tools::getValue('crm_id');
                $result = $importer->importSingleProduct($crmId);
                break;

            case 'importSingleCollection':
                $crmId = (int) Tools::getValue('crm_id');
                $colId = (int) Tools::getValue('collection_id');
                $variants = json_decode(Tools::getValue('variants', '[]'), true) ?: [];
                $result = $importer->importSingleCollection($crmId, $colId, $variants);
                break;

            case 'gmFetchPushQueue':
                $gm = new CrmCyclesGoogleMerchant();
                $onlyInStock = (bool) Configuration::get('CRMCYCLES_GMERCHANT_ONLY_INSTOCK');
                $result = $gm->buildPushQueue($onlyInStock);
                break;

            case 'gmPushSingleProduct':
                $gm = new CrmCyclesGoogleMerchant();
                $idProduct = (int) Tools::getValue('id_product');
                $result = $gm->pushProduct($idProduct);
                if (!isset($result['log'])) {
                    $result['log'] = $gm->getLog();
                }
                break;

            default:
                $result = ['success' => false, 'message' => 'Action inconnue'];
        }

        if (!isset($result['log'])) {
            $result['log'] = $importer->getLog();
        }

        header('Content-Type: application/json');
        die(json_encode($result));
    }

    private function getGoogleServiceEmail(): string
    {
        $credentials = Configuration::get('CRMCYCLES_GMERCHANT_CREDENTIALS');
        if (!$credentials) {
            return '';
        }

        $data = json_decode($credentials, true);
        return $data['client_email'] ?? '';
    }

    /**
     * Build a flat category list with indentation for select dropdown
     */
    private function getCategoryList(): array
    {
        $idLang = (int) $this->context->language->id;
        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_category`, c.`id_parent`, c.`level_depth`, cl.`name`
             FROM `' . _DB_PREFIX_ . 'category` c
             LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON c.`id_category` = cl.`id_category` AND cl.`id_lang` = ' . $idLang . '
             ' . Shop::addSqlAssociation('category', 'c') . '
             WHERE c.`active` = 1
             ORDER BY c.`level_depth` ASC, c.`position` ASC'
        );

        $list = [];
        foreach ($rows ?: [] as $row) {
            $depth = max(0, (int) $row['level_depth'] - 1);
            $list[] = [
                'id_category' => (int) $row['id_category'],
                'name' => str_repeat('— ', $depth) . $row['name'],
            ];
        }

        return $list;
    }
}
