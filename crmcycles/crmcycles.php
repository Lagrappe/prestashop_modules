<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

class CrmCycles extends Module
{
    private static $cartProcessing = false;

    public function __construct()
    {
        $this->name = 'crmcycles';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'Cycle X';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('CRM Cycles');
        $this->description = $this->l('Importation et synchronisation des produits depuis CRM Cycles. Inclut le marquage antivol et les caractéristiques par défaut des catégories.');
    }

    public function install()
    {
        // Disable marquage module if active
        $this->disableMarquageModule();

        return parent::install()
            && $this->installDb()
            && $this->installTab()
            && $this->registerHook('actionCategoryFormBuilderModifier')
            && $this->registerHook('actionAfterCreateCategoryFormHandler')
            && $this->registerHook('actionAfterUpdateCategoryFormHandler')
            && $this->registerHook('actionObjectCartUpdateAfter')
            && $this->registerHook('actionAfterDeleteProductInCart')
            && $this->registerHook('actionObjectProductAddAfter')
            && $this->registerHook('displayBackOfficeHeader')
            && Configuration::updateValue('CRMCYCLES_API_URL', '')
            && Configuration::updateValue('CRMCYCLES_API_SECRET', '')
            && Configuration::updateValue('CRMCYCLES_STORE_KEY', 'guidel')
            && Configuration::updateValue('CRMCYCLES_ROOT_CATEGORY', (int) Configuration::get('PS_HOME_CATEGORY'))
            && Configuration::updateValue('CRMCYCLES_DEV_MODE', 1)
            && Configuration::updateValue('CRMCYCLES_LAST_SYNC', '');
    }

    public function uninstall()
    {
        return $this->uninstallDb()
            && $this->uninstallTab()
            && Configuration::deleteByName('CRMCYCLES_API_URL')
            && Configuration::deleteByName('CRMCYCLES_API_SECRET')
            && Configuration::deleteByName('CRMCYCLES_STORE_KEY')
            && Configuration::deleteByName('CRMCYCLES_ROOT_CATEGORY')
            && Configuration::deleteByName('CRMCYCLES_DEV_MODE')
            && Configuration::deleteByName('CRMCYCLES_LAST_SYNC')
            && parent::uninstall();
    }

    private function disableMarquageModule(): void
    {
        $marquage = Module::getInstanceByName('marquage');
        if ($marquage && Module::isInstalled('marquage') && Module::isEnabled('marquage')) {
            $marquage->disable();
            PrestaShopLogger::addLog('CRM Cycles: module "marquage" désactivé automatiquement (fonctionnalités intégrées dans CRM Cycles)', 1);
        }
    }

    private function installTab(): bool
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminCrmCycles';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');

        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'CRM Cycles';
        }

        return $tab->add();
    }

    private function uninstallTab(): bool
    {
        $id = (int) Tab::getIdFromClassName('AdminCrmCycles');
        if ($id) {
            $tab = new Tab($id);
            return $tab->delete();
        }
        return true;
    }

    private function installDb(): bool
    {
        $sql = str_replace('PREFIX_', _DB_PREFIX_, file_get_contents(__DIR__ . '/sql/install.sql'));
        $queries = preg_split('/;\s*[\r\n]+/', $sql);

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query) && !Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    private function uninstallDb(): bool
    {
        $sql = str_replace('PREFIX_', _DB_PREFIX_, file_get_contents(__DIR__ . '/sql/uninstall.sql'));
        $queries = preg_split('/;\s*[\r\n]+/', $sql);

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                Db::getInstance()->execute($query);
            }
        }

        return true;
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminCrmCycles'));
    }

    // =========================================================================
    // MARQUAGE : Formulaire catégorie (back-office)
    // =========================================================================

    public function hookActionCategoryFormBuilderModifier(array $params): void
    {
        $categoryId = (int) ($params['id'] ?? 0);

        /** @var FormBuilderInterface $formBuilder */
        $formBuilder = $params['form_builder'];

        // --- Produit de marquage ---
        $products = Product::getProducts(
            $this->context->language->id, 0, 0, 'name', 'ASC', false, true
        );

        $choices = ['-- Aucun --' => 0];
        foreach ($products as $product) {
            $choices[$product['name'] . ' (ID: ' . $product['id_product'] . ')'] = (int) $product['id_product'];
        }

        $currentProductId = $this->getMarquageProductId($categoryId);

        $formBuilder->add('id_product_marquage', ChoiceType::class, [
            'label' => $this->l('Produit de marquage'),
            'choices' => $choices,
            'required' => false,
            'data' => $currentProductId,
            'attr' => ['class' => 'custom-select'],
            'help' => $this->l('Le produit sélectionné sera automatiquement ajouté au panier pour chaque produit de cette catégorie.'),
        ]);

        $params['data']['id_product_marquage'] = $currentProductId;

        // --- Caractéristiques par défaut ---
        $features = Feature::getFeatures($this->context->language->id);
        $featureChoices = [];
        foreach ($features as $feature) {
            $featureChoices[$feature['name'] . ' (ID: ' . $feature['id_feature'] . ')'] = (int) $feature['id_feature'];
        }

        $currentFeatures = $this->getCategoryFeatureIds($categoryId);

        $formBuilder->add('default_features', ChoiceType::class, [
            'label' => $this->l('Caractéristiques par défaut'),
            'choices' => $featureChoices,
            'required' => false,
            'multiple' => true,
            'expanded' => false,
            'data' => $currentFeatures,
            'attr' => ['class' => 'custom-select', 'size' => 10],
            'help' => $this->l('Les caractéristiques sélectionnées seront automatiquement ajoutées aux nouveaux produits créés dans cette catégorie.'),
        ]);

        $params['data']['default_features'] = $currentFeatures;
    }

    public function hookActionAfterCreateCategoryFormHandler(array $params): void
    {
        $this->saveCategoryMarquage($params);
    }

    public function hookActionAfterUpdateCategoryFormHandler(array $params): void
    {
        $this->saveCategoryMarquage($params);
    }

    private function saveCategoryMarquage(array $params): void
    {
        $categoryId = (int) ($params['id'] ?? 0);
        $formData = $params['form_data'] ?? [];
        $idProductMarquage = (int) ($formData['id_product_marquage'] ?? 0);

        if ($categoryId <= 0) {
            return;
        }

        // --- Produit de marquage ---
        Db::getInstance()->delete('marquage_category', 'id_category = ' . $categoryId);

        if ($idProductMarquage > 0) {
            Db::getInstance()->insert('marquage_category', [
                'id_category' => $categoryId,
                'id_product_marquage' => $idProductMarquage,
            ]);
        }

        // --- Caractéristiques par défaut ---
        Db::getInstance()->delete('marquage_category_feature', 'id_category = ' . $categoryId);

        $featureIds = $formData['default_features'] ?? [];
        if (is_array($featureIds)) {
            foreach ($featureIds as $idFeature) {
                $idFeature = (int) $idFeature;
                if ($idFeature > 0) {
                    Db::getInstance()->insert('marquage_category_feature', [
                        'id_category' => $categoryId,
                        'id_feature' => $idFeature,
                    ]);
                }
            }
        }
    }

    private function getMarquageProductId(int $categoryId): int
    {
        if ($categoryId <= 0) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT `id_product_marquage`
             FROM `' . _DB_PREFIX_ . 'marquage_category`
             WHERE `id_category` = ' . $categoryId
        );
    }

    private function getCategoryFeatureIds(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $results = Db::getInstance()->executeS(
            'SELECT `id_feature`
             FROM `' . _DB_PREFIX_ . 'marquage_category_feature`
             WHERE `id_category` = ' . $categoryId
        );

        if (!$results) {
            return [];
        }

        return array_map('intval', array_column($results, 'id_feature'));
    }

    // =========================================================================
    // MARQUAGE : Ajout auto des caractéristiques à la création produit
    // =========================================================================

    public function hookActionObjectProductAddAfter(array $params): void
    {
        $product = $params['object'] ?? null;
        if (!$product instanceof Product || !$product->id) {
            return;
        }

        $categoryIds = $product->getCategories();
        if (empty($categoryIds)) {
            $categoryIds = [(int) $product->id_category_default];
        }

        $featureIdsToAdd = [];

        foreach ($categoryIds as $idCategory) {
            $featureIds = $this->getCategoryFeatureIds((int) $idCategory);
            foreach ($featureIds as $idFeature) {
                $featureIdsToAdd[$idFeature] = true;
            }
        }

        if (empty($featureIdsToAdd)) {
            return;
        }

        $languages = Language::getLanguages(false);

        foreach (array_keys($featureIdsToAdd) as $idFeature) {
            $exists = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*)
                 FROM `' . _DB_PREFIX_ . 'feature_product`
                 WHERE `id_product` = ' . (int) $product->id . '
                   AND `id_feature` = ' . (int) $idFeature
            );

            if ($exists > 0) {
                continue;
            }

            $featureValue = new FeatureValue();
            $featureValue->id_feature = (int) $idFeature;
            $featureValue->custom = 1;

            foreach ($languages as $lang) {
                $featureValue->value[(int) $lang['id_lang']] = '';
            }

            $featureValue->add();

            Db::getInstance()->insert('feature_product', [
                'id_product' => (int) $product->id,
                'id_feature' => (int) $idFeature,
                'id_feature_value' => (int) $featureValue->id,
            ]);
        }
    }

    // =========================================================================
    // MARQUAGE : Sync panier (produit de marquage auto)
    // =========================================================================

    public function hookActionObjectCartUpdateAfter(array $params): void
    {
        if (self::$cartProcessing) {
            return;
        }

        $cart = $params['object'] ?? null;
        if (!$cart instanceof Cart) {
            return;
        }

        self::$cartProcessing = true;
        try {
            $this->syncMarquageProducts($cart);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'CRM Cycles Marquage: erreur sync panier – ' . $e->getMessage(),
                3, $e->getCode(), 'Cart', (int) $cart->id, true
            );
        } finally {
            self::$cartProcessing = false;
        }
    }

    public function hookActionAfterDeleteProductInCart(array $params): void
    {
        if (self::$cartProcessing) {
            return;
        }

        $cart = $this->context->cart;
        if (!$cart || !$cart->id) {
            return;
        }

        self::$cartProcessing = true;
        try {
            $this->syncMarquageProducts($cart);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'CRM Cycles Marquage: erreur sync panier – ' . $e->getMessage(),
                3, $e->getCode(), 'Cart', (int) $cart->id, true
            );
        } finally {
            self::$cartProcessing = false;
        }
    }

    private function syncMarquageProducts(Cart $cart): void
    {
        if (!$cart->id) {
            return;
        }

        $marquages = Db::getInstance()->executeS(
            'SELECT `id_category`, `id_product_marquage`
             FROM `' . _DB_PREFIX_ . 'marquage_category`
             WHERE `id_product_marquage` > 0'
        );

        if (!$marquages) {
            return;
        }

        foreach ($marquages as $marquage) {
            $idCategory = (int) $marquage['id_category'];
            $idProductMarquage = (int) $marquage['id_product_marquage'];

            $targetQty = (int) Db::getInstance()->getValue(
                'SELECT COALESCE(SUM(cp.`quantity`), 0)
                 FROM `' . _DB_PREFIX_ . 'cart_product` cp
                 INNER JOIN `' . _DB_PREFIX_ . 'category_product` catp
                    ON cp.`id_product` = catp.`id_product`
                 WHERE cp.`id_cart` = ' . (int) $cart->id . '
                   AND catp.`id_category` = ' . $idCategory . '
                   AND cp.`id_product` != ' . $idProductMarquage
            );

            $currentQty = (int) Db::getInstance()->getValue(
                'SELECT COALESCE(SUM(`quantity`), 0)
                 FROM `' . _DB_PREFIX_ . 'cart_product`
                 WHERE `id_cart` = ' . (int) $cart->id . '
                   AND `id_product` = ' . $idProductMarquage
            );

            if ($targetQty === $currentQty) {
                continue;
            }

            if ($targetQty > 0) {
                if ($currentQty === 0) {
                    $cart->updateQty($targetQty, $idProductMarquage);
                } elseif ($targetQty > $currentQty) {
                    $cart->updateQty($targetQty - $currentQty, $idProductMarquage, null, false, 'up');
                } else {
                    $cart->updateQty($currentQty - $targetQty, $idProductMarquage, null, false, 'down');
                }
            } else {
                $cart->deleteProduct($idProductMarquage);
            }
        }

        $cart->_products = null;
    }

    // =========================================================================
    // MARQUAGE : JS back-office (ajout dynamique features sur page produit)
    // =========================================================================

    public function hookDisplayBackOfficeHeader(array $params): string
    {
        if (!$this->isProductAdminPage()) {
            return '';
        }

        $mappings = $this->getAllCategoryFeatureMappings();
        if (empty($mappings)) {
            return '';
        }

        $json = json_encode($mappings);
        $jsPath = $this->getPathUri() . 'views/js/admin-product-features.js';

        return '<script>var marquageCategoryFeatures = ' . $json . ';</script>'
            . '<script src="' . $jsPath . '"></script>';
    }

    private function isProductAdminPage(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        if (preg_match('#/sell/catalog/products/(\d+|new)#', $requestUri)) {
            return true;
        }

        if (Tools::getValue('controller') === 'AdminProducts') {
            return true;
        }

        return false;
    }

    private function getAllCategoryFeatureMappings(): array
    {
        $results = Db::getInstance()->executeS(
            'SELECT `id_category`, `id_feature`
             FROM `' . _DB_PREFIX_ . 'marquage_category_feature`
             ORDER BY `id_category`'
        );

        if (!$results) {
            return [];
        }

        $mappings = [];
        foreach ($results as $row) {
            $catId = (int) $row['id_category'];
            $mappings[$catId][] = (int) $row['id_feature'];
        }

        return $mappings;
    }
}
