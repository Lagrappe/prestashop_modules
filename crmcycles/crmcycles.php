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
            && $this->registerHook('displayCustomerAccount')
            && $this->registerHook('actionObjectAddressAddAfter')
            && $this->registerHook('actionObjectAddressUpdateAfter')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionSetInvoice')
            && $this->registerHook('actionPDFInvoiceRender')
            && Configuration::updateValue('CRMCYCLES_API_URL', '')
            && Configuration::updateValue('CRMCYCLES_API_SECRET', '')
            && Configuration::updateValue('CRMCYCLES_STORE_KEY', 'guidel')
            && Configuration::updateValue('CRMCYCLES_PORTAL_URL', '')
            && Configuration::updateValue('CRMCYCLES_INVOICE_OVERRIDE', 0)
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
            && Configuration::deleteByName('CRMCYCLES_PORTAL_URL')
            && Configuration::deleteByName('CRMCYCLES_INVOICE_OVERRIDE')
            && Configuration::deleteByName('CRMCYCLES_COMPANY_NAME')
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

        // Invalider le cache produits du panier (getProducts force le recalcul)
        $cart->getProducts(true);
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

    // =========================================================================
    // PORTAIL CLIENT : lien vers l'espace client CRM Cycles
    // =========================================================================

    public function hookDisplayCustomerAccount(array $params): string
    {
        $portalUrl = Configuration::get('CRMCYCLES_PORTAL_URL');
        if (empty($portalUrl)) {
            return '';
        }

        $companyName = Configuration::get('CRMCYCLES_COMPANY_NAME') ?: 'CRM Cycles';

        return '
        <a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" href="' . htmlspecialchars($portalUrl) . '" target="_blank" rel="noopener">
            <span class="link-item">
                <i class="material-icons">&#xE80B;</i>
                ' . sprintf($this->l('Mon espace %s'), htmlspecialchars($companyName)) . '
            </span>
        </a>';
    }

    // =========================================================================
    // FACTURATION CRM : création facture + override PDF
    // =========================================================================

    // =========================================================================
    // SYNC CLIENT : adresses vers CRM Cycles
    // =========================================================================

    /**
     * Sync adresse vers le CRM quand un client crée ou modifie une adresse.
     */
    public function hookActionObjectAddressAddAfter(array $params): void
    {
        $this->syncAddressToCrm($params['object'] ?? null);
    }

    public function hookActionObjectAddressUpdateAfter(array $params): void
    {
        $this->syncAddressToCrm($params['object'] ?? null);
    }

    private function syncAddressToCrm($address): void
    {
        if (!(bool) Configuration::get('CRMCYCLES_INVOICE_OVERRIDE')) {
            return;
        }

        if (!$address instanceof Address || !$address->id_customer) {
            return;
        }

        try {
            require_once __DIR__ . '/classes/CrmCyclesApi.php';

            $api = new CrmCyclesApi();
            $customer = new Customer((int) $address->id_customer);

            if (!Validate::isLoadedObject($customer)) {
                return;
            }

            // Déterminer le type d'adresse (PS n'a pas de type explicite,
            // on se base sur l'alias ou on envoie en billing par défaut)
            $alias = strtolower($address->alias);
            $isBilling = (strpos($alias, 'factur') !== false || strpos($alias, 'billing') !== false);
            $isShipping = (strpos($alias, 'livraison') !== false || strpos($alias, 'delivery') !== false || strpos($alias, 'shipping') !== false);

            $addressData = [
                'address_line1' => $address->address1,
                'address_line2' => $address->address2,
                'postal_code' => $address->postcode,
                'city' => $address->city,
                'country' => Country::getIsoById((int) $address->id_country),
            ];

            $data = [
                'email' => $customer->email,
                'first_name' => $customer->firstname,
                'last_name' => $customer->lastname,
                'phone_mobile' => $address->phone_mobile ?: $address->phone,
            ];

            if ($isBilling && !$isShipping) {
                $data['billing_address'] = $addressData;
            } elseif ($isShipping && !$isBilling) {
                $data['shipping_address'] = $addressData;
            } else {
                // Par défaut, on envoie les deux
                $data['billing_address'] = $addressData;
                $data['shipping_address'] = $addressData;
            }

            $api->createCustomer($data);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'CRM Cycles: erreur sync adresse — ' . $e->getMessage(),
                3, 0, 'Address', (int) $address->id
            );
        }
    }

    // =========================================================================
    // FACTURATION CRM : création facture + override PDF
    // =========================================================================

    /**
     * À la validation d'une commande PS, créer le client + facture dans le CRM.
     */
    public function hookActionValidateOrder(array $params): void
    {
        if (!(bool) Configuration::get('CRMCYCLES_INVOICE_OVERRIDE')) {
            return;
        }

        require_once __DIR__ . '/classes/CrmCyclesApi.php';

        $order = $params['order'] ?? null;
        $customer = $params['customer'] ?? null;

        if (!$order || !$customer) {
            return;
        }

        try {
            $api = new CrmCyclesApi();

            // 1. Créer / retrouver le client dans le CRM
            $address = new Address((int) $order->id_address_delivery);
            $billingAddress = new Address((int) $order->id_address_invoice);

            $customerResult = $api->createCustomer([
                'email' => $customer->email,
                'first_name' => $customer->firstname,
                'last_name' => $customer->lastname,
                'phone_mobile' => $address->phone_mobile ?: $address->phone,
                'customer_type' => !empty($customer->company) ? 'company' : 'individual',
                'company_name' => $customer->company ?: null,
                'gender' => $customer->id_gender == 1 ? 'M' : ($customer->id_gender == 2 ? 'F' : null),
                'billing_address' => [
                    'address_line1' => $billingAddress->address1,
                    'address_line2' => $billingAddress->address2,
                    'postal_code' => $billingAddress->postcode,
                    'city' => $billingAddress->city,
                    'country' => Country::getIsoById((int) $billingAddress->id_country),
                ],
                'shipping_address' => [
                    'address_line1' => $address->address1,
                    'address_line2' => $address->address2,
                    'postal_code' => $address->postcode,
                    'city' => $address->city,
                    'country' => Country::getIsoById((int) $address->id_country),
                ],
            ]);

            if (!$customerResult['success']) {
                PrestaShopLogger::addLog(
                    'CRM Cycles: échec création client CRM pour commande #' . $order->id . ' — ' . ($customerResult['error']['message'] ?? 'Erreur inconnue'),
                    3, 0, 'Order', (int) $order->id
                );
                return;
            }

            $crmCustomerId = (int) $customerResult['data']['customer_id'];

            // 2. Construire les lignes produit
            // Tous les produits de la commande doivent exister dans le CRM.
            // Si un produit n'est pas trouvé, on abandonne et PS gère sa facture normalement.
            $orderDetails = $order->getProductsDetail();
            $items = [];
            $missingSkus = [];

            foreach ($orderDetails as $detail) {
                $sku = $detail['product_reference'] ?? '';
                $crmProductId = $this->getCrmProductIdBySku($sku);

                if ($crmProductId) {
                    $items[] = [
                        'productId' => $crmProductId,
                        'quantity' => (int) $detail['product_quantity'],
                    ];
                } else {
                    $missingSkus[] = $sku ?: '(sans réf.)';
                }
            }

            if (!empty($missingSkus)) {
                PrestaShopLogger::addLog(
                    'CRM Cycles: facture CRM non créée pour commande #' . $order->id
                    . ' — produits absents du CRM : ' . implode(', ', $missingSkus)
                    . '. La facturation PrestaShop standard s\'applique.',
                    2, 0, 'Order', (int) $order->id
                );
                return;
            }

            if (empty($items)) {
                return;
            }

            // 3. Créer la facture dans le CRM
            $shippingTTC = (float) $order->total_shipping_tax_incl;

            // Le statut PS indique si le paiement est déjà effectué
            $orderStatus = $params['orderStatus'] ?? null;
            $isPaid = $orderStatus && (bool) $orderStatus->paid;

            $orderResult = $api->createOrder([
                'payment_reference' => 'PS-' . $order->id . '-' . $order->reference,
                'customer_id' => $crmCustomerId,
                'items' => $items,
                'shipping_method' => $shippingTTC > 0 ? 'delivery' : 'pickup',
                'shipping_method_label' => $this->getCarrierName($order),
                'shipping_price_ttc' => $shippingTTC,
                'customer_note' => $order->getFirstMessage() ?: '',
                'internal_note' => 'Commande PrestaShop #' . $order->id . ' — Ref: ' . $order->reference,
                'payment_method_label' => $order->payment ?: 'Paiement en ligne',
                'paid' => $isPaid,
            ]);

            if (!$orderResult['success']) {
                PrestaShopLogger::addLog(
                    'CRM Cycles: échec création facture CRM pour commande #' . $order->id . ' — ' . ($orderResult['error']['message'] ?? 'Erreur inconnue'),
                    3, 0, 'Order', (int) $order->id
                );
                return;
            }

            // 4. Stocker le mapping
            Db::getInstance()->insert('crmcycles_order_map', [
                'id_order' => (int) $order->id,
                'crm_customer_id' => $crmCustomerId,
                'crm_invoice_id' => (int) $orderResult['data']['invoice_id'],
                'crm_invoice_number' => pSQL($orderResult['data']['invoice_number'] ?? ''),
                'date_add' => date('Y-m-d H:i:s'),
            ]);

            PrestaShopLogger::addLog(
                'CRM Cycles: facture CRM #' . ($orderResult['data']['invoice_number'] ?? '') . ' créée pour commande PS #' . $order->id,
                1, 0, 'Order', (int) $order->id
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'CRM Cycles: exception facturation — ' . $e->getMessage(),
                3, 0, 'Order', (int) $order->id
            );
        }
    }

    /**
     * Override du numéro de facture PS par celui du CRM.
     */
    public function hookActionSetInvoice(array $params): void
    {
        if (!(bool) Configuration::get('CRMCYCLES_INVOICE_OVERRIDE')) {
            return;
        }

        $order = $params['Order'] ?? null;
        if (!$order) {
            return;
        }

        $mapping = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'crmcycles_order_map`
             WHERE `id_order` = ' . (int) $order->id
        );

        if ($mapping && !empty($mapping['crm_invoice_number'])) {
            // Override le numéro de facture PS par celui du CRM
            $orderInvoice = $params['OrderInvoice'] ?? null;
            if ($orderInvoice instanceof OrderInvoice) {
                $orderInvoice->number = 0; // Empêche la numérotation PS
            }
        }
    }

    /**
     * Override du PDF facture : servir le PDF CRM à la place.
     */
    public function hookActionPDFInvoiceRender(array $params): void
    {
        if (!(bool) Configuration::get('CRMCYCLES_INVOICE_OVERRIDE')) {
            return;
        }

        $orderInvoice = $params['order_invoice_list'][0] ?? null;
        if (!$orderInvoice instanceof OrderInvoice) {
            return;
        }

        $mapping = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'crmcycles_order_map`
             WHERE `id_order` = ' . (int) $orderInvoice->id_order
        );

        if (!$mapping) {
            return;
        }

        require_once __DIR__ . '/classes/CrmCyclesApi.php';

        $api = new CrmCyclesApi();
        $pdfContent = $api->getInvoicePdf((int) $mapping['crm_invoice_id']);

        if ($pdfContent) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="facture-' . $mapping['crm_invoice_number'] . '.pdf"');
            header('Content-Length: ' . strlen($pdfContent));
            header('Cache-Control: private, max-age=0, must-revalidate');
            echo $pdfContent;
            exit;
        }
    }

    /**
     * Retrouver le CRM product_id via le SKU (référence PS).
     */
    private function getCarrierName($order): string
    {
        $idCarrier = (int) $order->id_carrier;
        if (!$idCarrier) {
            return 'Retrait en magasin';
        }

        $carrier = new Carrier($idCarrier, $this->context->language->id);
        if (Validate::isLoadedObject($carrier)) {
            return $carrier->name;
        }

        return 'Livraison';
    }

    private function getCrmProductIdBySku(string $sku): int
    {
        if (empty($sku)) {
            return 0;
        }

        // D'abord dans le mapping produit
        $crmId = (int) Db::getInstance()->getValue(
            'SELECT `crm_product_id` FROM `' . _DB_PREFIX_ . 'crmcycles_product_map`
             WHERE `crm_sku` = "' . pSQL($sku) . '"'
        );

        if ($crmId) {
            return $crmId;
        }

        // Puis dans le mapping combinaison → retrouver le produit parent CRM
        // (combination_map.crm_product_id = id du serialized_item, pas du produit)
        $idProduct = (int) Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'crmcycles_combination_map`
             WHERE `crm_sku` = "' . pSQL($sku) . '"'
        );

        if ($idProduct) {
            // Retrouver le CRM product_id via le produit PS parent
            $crmId = (int) Db::getInstance()->getValue(
                'SELECT `crm_product_id` FROM `' . _DB_PREFIX_ . 'crmcycles_product_map`
                 WHERE `id_product` = ' . $idProduct
            );
            return $crmId;
        }

        return 0;
    }
}
