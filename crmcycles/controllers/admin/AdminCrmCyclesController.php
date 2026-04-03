<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'crmcycles/classes/CrmCyclesApi.php';
require_once _PS_MODULE_DIR_ . 'crmcycles/classes/CrmCyclesImporter.php';

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

        return [
            'module_dir' => _MODULE_DIR_ . 'crmcycles/',
            'api_url' => Configuration::get('CRMCYCLES_API_URL'),
            'api_secret' => Configuration::get('CRMCYCLES_API_SECRET'),
            'store_key' => Configuration::get('CRMCYCLES_STORE_KEY') ?: 'guidel',
            'root_category' => (int) Configuration::get('CRMCYCLES_ROOT_CATEGORY'),
            'dev_mode' => (int) Configuration::get('CRMCYCLES_DEV_MODE'),
            'last_sync' => Configuration::get('CRMCYCLES_LAST_SYNC'),
            'sync_logs' => $logs,
            'stat_categories' => $catCount,
            'stat_products' => $prodCount,
            'stat_combinations' => $combiCount,
            'stat_features' => $featCount,
            'categories' => $categories,
            'cron_token' => Tools::substr(Tools::hash('crmcycles/cron'), 0, 10),
            'cron_url' => $this->context->link->getModuleLink('crmcycles', 'cron', [
                'token' => Tools::substr(Tools::hash('crmcycles/cron'), 0, 10),
            ]),
            'ajax_url' => $this->context->link->getAdminLink('AdminCrmCycles'),
            'token' => Tools::getAdminTokenLite('AdminCrmCycles'),
        ];
    }

    private function processConfiguration(): void
    {
        Configuration::updateValue('CRMCYCLES_API_URL', Tools::getValue('CRMCYCLES_API_URL'));
        Configuration::updateValue('CRMCYCLES_API_SECRET', Tools::getValue('CRMCYCLES_API_SECRET'));
        Configuration::updateValue('CRMCYCLES_STORE_KEY', Tools::getValue('CRMCYCLES_STORE_KEY'));
        Configuration::updateValue('CRMCYCLES_ROOT_CATEGORY', (int) Tools::getValue('CRMCYCLES_ROOT_CATEGORY'));
        Configuration::updateValue('CRMCYCLES_DEV_MODE', (int) Tools::getValue('CRMCYCLES_DEV_MODE'));

        $this->confirmations[] = $this->l('Configuration sauvegardée.');
    }

    private function processTestConnection(): void
    {
        $api = new CrmCyclesApi();
        $result = $api->testConnection();

        if ($result['success']) {
            $data = $result['data'] ?? [];
            $this->confirmations[] = $this->l('Connexion réussie !') . ' '
                . ($data['company_name'] ?? '');
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

            default:
                $result = ['success' => false, 'message' => 'Action inconnue'];
        }

        if (!isset($result['log'])) {
            $result['log'] = $importer->getLog();
        }

        header('Content-Type: application/json');
        die(json_encode($result));
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
