<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

class Marquage extends Module
{
    /**
     * Guard contre la récursion lors de la synchronisation du panier
     */
    private static $processing = false;

    public function __construct()
    {
        $this->name = 'marquage';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'Seb Gemeline';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Marquage antivol et caractéristiques des catégories');
        $this->description = $this->l('Ajoute automatiquement un produit de marquage au panier pour chaque produit d\'une catégorie configurée et associe des caractéristiques par défaut aux nouveaux produits.');
    }

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->registerHook('actionCategoryFormBuilderModifier')
            && $this->registerHook('actionAfterCreateCategoryFormHandler')
            && $this->registerHook('actionAfterUpdateCategoryFormHandler')
            && $this->registerHook('actionObjectCartUpdateAfter')
            && $this->registerHook('actionAfterDeleteProductInCart')
            && $this->registerHook('actionObjectProductAddAfter')
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        return $this->uninstallDb() && parent::uninstall();
    }

    private function installDb(): bool
    {
        $queries = preg_split('/;\s*[\r\n]+/', str_replace(
            'PREFIX_',
            _DB_PREFIX_,
            file_get_contents(__DIR__ . '/sql/install.sql')
        ));

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if (!Db::getInstance()->execute($query)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function uninstallDb(): bool
    {
        $queries = preg_split('/;\s*[\r\n]+/', str_replace(
            'PREFIX_',
            _DB_PREFIX_,
            file_get_contents(__DIR__ . '/sql/uninstall.sql')
        ));

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                Db::getInstance()->execute($query);
            }
        }

        return true;
    }

    // =========================================================================
    // ADMIN : Ajout du champ "Produit de marquage" dans le formulaire catégorie
    // =========================================================================

    /**
     * Ajoute un champ select dans le formulaire d'édition de catégorie (back-office PS8 Symfony)
     */
    public function hookActionCategoryFormBuilderModifier(array $params): void
    {
        $categoryId = (int) ($params['id'] ?? 0);

        /** @var FormBuilderInterface $formBuilder */
        $formBuilder = $params['form_builder'];

        $products = Product::getProducts(
            $this->context->language->id,
            0,
            0,
            'name',
            'ASC',
            false,
            true
        );

        $choices = ['-- Aucun --' => 0];
        foreach ($products as $product) {
            $label = $product['name'] . ' (ID: ' . $product['id_product'] . ')';
            $choices[$label] = (int) $product['id_product'];
        }

        $currentProductId = $this->getMarquageProductId($categoryId);

        $formBuilder->add('id_product_marquage', ChoiceType::class, [
            'label' => $this->l('Produit de marquage'),
            'choices' => $choices,
            'required' => false,
            'data' => $currentProductId,
            'attr' => [
                'class' => 'custom-select',
            ],
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
            'attr' => [
                'class' => 'custom-select',
                'size' => 10,
            ],
            'help' => $this->l('Les caractéristiques sélectionnées seront automatiquement ajoutées aux nouveaux produits créés dans cette catégorie.'),
        ]);

        $params['data']['default_features'] = $currentFeatures;
    }

    /**
     * Sauvegarde du produit de marquage à la création d'une catégorie
     */
    public function hookActionAfterCreateCategoryFormHandler(array $params): void
    {
        $this->saveCategoryMarquage($params);
    }

    /**
     * Sauvegarde du produit de marquage à la mise à jour d'une catégorie
     */
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
        Db::getInstance()->delete(
            'marquage_category',
            'id_category = ' . $categoryId
        );

        if ($idProductMarquage > 0) {
            Db::getInstance()->insert('marquage_category', [
                'id_category' => $categoryId,
                'id_product_marquage' => $idProductMarquage,
            ]);
        }

        // --- Caractéristiques par défaut ---
        Db::getInstance()->delete(
            'marquage_category_feature',
            'id_category = ' . $categoryId
        );

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

    /**
     * Récupère l'ID du produit de marquage pour une catégorie
     */
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

    /**
     * Récupère les IDs de caractéristiques par défaut d'une catégorie
     */
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
    // PRODUIT : Ajout auto des caractéristiques à la création
    // =========================================================================

    /**
     * À la création d'un produit, ajoute les caractéristiques par défaut
     * de sa catégorie par défaut (et de ses autres catégories).
     */
    public function hookActionObjectProductAddAfter(array $params): void
    {
        $product = $params['object'] ?? null;
        if (!$product instanceof Product || !$product->id) {
            return;
        }

        // Récupérer toutes les catégories du produit
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

        $idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT');
        $languages = Language::getLanguages(false);

        foreach (array_keys($featureIdsToAdd) as $idFeature) {
            // Vérifier que la caractéristique n'est pas déjà associée
            $exists = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*)
                 FROM `' . _DB_PREFIX_ . 'feature_product`
                 WHERE `id_product` = ' . (int) $product->id . '
                   AND `id_feature` = ' . (int) $idFeature
            );

            if ($exists > 0) {
                continue;
            }

            // Créer une valeur de caractéristique vide pour ce produit
            $featureValue = new FeatureValue();
            $featureValue->id_feature = (int) $idFeature;
            $featureValue->custom = 1;

            foreach ($languages as $lang) {
                $featureValue->value[(int) $lang['id_lang']] = '';
            }

            $featureValue->add();

            // Associer au produit
            Db::getInstance()->insert('feature_product', [
                'id_product' => (int) $product->id,
                'id_feature' => (int) $idFeature,
                'id_feature_value' => (int) $featureValue->id,
            ]);
        }
    }

    // =========================================================================
    // FRONT : Synchronisation automatique du produit de marquage dans le panier
    // =========================================================================

    /**
     * Déclenché après chaque mise à jour du panier (ajout, modification de quantité)
     */
    public function hookActionObjectCartUpdateAfter(array $params): void
    {
        if (self::$processing) {
            return;
        }

        $cart = $params['object'] ?? null;
        if (!$cart instanceof Cart) {
            return;
        }

        self::$processing = true;
        try {
            $this->syncMarquageProducts($cart);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'Marquage: erreur sync panier – ' . $e->getMessage(),
                3,
                $e->getCode(),
                'Cart',
                (int) $cart->id,
                true
            );
        } finally {
            self::$processing = false;
        }
    }

    /**
     * Déclenché après la suppression d'un produit du panier
     */
    public function hookActionAfterDeleteProductInCart(array $params): void
    {
        if (self::$processing) {
            return;
        }

        $cart = $this->context->cart;
        if (!$cart || !$cart->id) {
            return;
        }

        self::$processing = true;
        try {
            $this->syncMarquageProducts($cart);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'Marquage: erreur sync panier – ' . $e->getMessage(),
                3,
                $e->getCode(),
                'Cart',
                (int) $cart->id,
                true
            );
        } finally {
            self::$processing = false;
        }
    }

    /**
     * Synchronise les produits de marquage dans le panier :
     * - Pour chaque catégorie ayant un produit de marquage configuré,
     *   on s'assure que la quantité du produit de marquage = somme des
     *   quantités des produits de cette catégorie dans le panier.
     */
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

            // Quantité totale des produits de cette catégorie dans le panier
            // (on exclut le produit de marquage lui-même pour éviter les boucles)
            $targetQty = (int) Db::getInstance()->getValue(
                'SELECT COALESCE(SUM(cp.`quantity`), 0)
                 FROM `' . _DB_PREFIX_ . 'cart_product` cp
                 INNER JOIN `' . _DB_PREFIX_ . 'category_product` catp
                    ON cp.`id_product` = catp.`id_product`
                 WHERE cp.`id_cart` = ' . (int) $cart->id . '
                   AND catp.`id_category` = ' . $idCategory . '
                   AND cp.`id_product` != ' . $idProductMarquage
            );

            // Quantité actuelle du produit de marquage dans le panier
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
                    // Ajouter le produit de marquage
                    $cart->updateQty($targetQty, $idProductMarquage);
                } elseif ($targetQty > $currentQty) {
                    // Augmenter la quantité
                    $cart->updateQty($targetQty - $currentQty, $idProductMarquage, null, false, 'up');
                } else {
                    // Diminuer la quantité
                    $cart->updateQty($currentQty - $targetQty, $idProductMarquage, null, false, 'down');
                }
            } else {
                // Retirer le produit de marquage
                $cart->deleteProduct($idProductMarquage);
            }
        }

        // Invalider le cache produits du panier
        $cart->_products = null;
    }

    // =========================================================================
    // ADMIN PRODUIT : Injection JS pour ajout dynamique des features
    // =========================================================================

    /**
     * Injecte le JS et les données de mapping sur la page produit du back-office.
     */
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

    /**
     * Vérifie si la page courante est la page produit du back-office.
     */
    private function isProductAdminPage(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        // PS8 Symfony routes : uniquement edition/creation (exclure la liste)
        if (preg_match('#/sell/catalog/products/(\d+|new)#', $requestUri)) {
            return true;
        }

        // Legacy fallback
        if (Tools::getValue('controller') === 'AdminProducts') {
            return true;
        }

        return false;
    }

    /**
     * Retourne un tableau associatif id_category => [id_feature, ...] pour toutes les catégories configurées.
     */
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
    // Helpers
    // =========================================================================

    /**
     * Récupère toutes les configurations de marquage
     */
    public function getAllMarquages(): array
    {
        $results = Db::getInstance()->executeS(
            'SELECT mc.`id_category`, mc.`id_product_marquage`, cl.`name` AS category_name, pl.`name` AS product_name
             FROM `' . _DB_PREFIX_ . 'marquage_category` mc
             LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON mc.`id_category` = cl.`id_category` AND cl.`id_lang` = ' . (int) $this->context->language->id . '
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON mc.`id_product_marquage` = pl.`id_product` AND pl.`id_lang` = ' . (int) $this->context->language->id . '
                AND pl.`id_shop` = ' . (int) $this->context->shop->id
        );

        return $results ?: [];
    }
}
