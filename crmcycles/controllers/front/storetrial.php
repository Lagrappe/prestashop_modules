<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class CrmCyclesStoreTrialModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        if (!$this->isTokenValid()) {
            $this->ajaxResponse(['success' => false, 'message' => 'Token invalide.']);
        }

        if (!Tools::isSubmit('submitStoreTrial')) {
            $this->ajaxResponse(['success' => false, 'message' => 'Requête invalide.']);
        }

        $idProduct = (int) Tools::getValue('id_product');
        $idProductAttribute = (int) Tools::getValue('id_product_attribute', 0);
        $firstname = trim(Tools::getValue('trial_firstname'));
        $lastname = trim(Tools::getValue('trial_lastname'));
        $email = trim(Tools::getValue('trial_email'));
        $phone = trim(Tools::getValue('trial_phone'));
        $desiredDate = trim(Tools::getValue('trial_date'));

        // Validation
        $errors = [];

        if (!$idProduct || !Validate::isLoadedObject(new Product($idProduct))) {
            $errors[] = 'Produit invalide.';
        }

        if (empty($firstname) || !Validate::isName($firstname)) {
            $errors[] = 'Prénom invalide.';
        }

        if (empty($lastname) || !Validate::isName($lastname)) {
            $errors[] = 'Nom invalide.';
        }

        if (empty($email) || !Validate::isEmail($email)) {
            $errors[] = 'Adresse email invalide.';
        }

        if (empty($phone) || !Validate::isPhoneNumber($phone)) {
            $errors[] = 'Numéro de téléphone invalide.';
        }

        if (empty($desiredDate) || !Validate::isDate($desiredDate)) {
            $errors[] = 'Date souhaitée invalide.';
        } else {
            $dateTs = strtotime($desiredDate);
            $today = strtotime(date('Y-m-d'));
            if ($dateTs < $today) {
                $errors[] = 'La date souhaitée doit être dans le futur.';
            }
        }

        if (!empty($errors)) {
            $this->ajaxResponse(['success' => false, 'message' => implode(' ', $errors)]);
        }

        // Check stock
        if ($idProductAttribute > 0) {
            $qty = StockAvailable::getQuantityAvailableByProduct($idProduct, $idProductAttribute);
        } else {
            $qty = StockAvailable::getQuantityAvailableByProduct($idProduct);
        }

        if ($qty <= 0) {
            $this->ajaxResponse(['success' => false, 'message' => 'Ce produit n\'est pas disponible en stock pour un essai.']);
        }

        // Save request
        $result = Db::getInstance()->insert('crmcycles_store_trial', [
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'firstname' => pSQL($firstname),
            'lastname' => pSQL($lastname),
            'email' => pSQL($email),
            'phone' => pSQL($phone),
            'desired_date' => pSQL($desiredDate),
            'status' => 'pending',
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        if (!$result) {
            $this->ajaxResponse(['success' => false, 'message' => 'Erreur lors de l\'enregistrement. Veuillez réessayer.']);
        }

        // Build product description for emails
        $product = new Product($idProduct, false, $this->context->language->id);
        $productName = $product->name;

        if ($idProductAttribute > 0) {
            $combination = new Combination($idProductAttribute);
            $attrs = $combination->getAttributesName($this->context->language->id);
            $attrNames = array_map(function ($a) { return $a['name']; }, $attrs);
            if (!empty($attrNames)) {
                $productName .= ' — ' . implode(', ', $attrNames);
            }
        }

        $formattedDate = date('d/m/Y', strtotime($desiredDate));

        // Send customer confirmation email
        $this->sendCustomerEmail($email, $firstname, $lastname, $productName, $formattedDate, $phone);

        // Send admin notification email
        $this->sendAdminEmail($firstname, $lastname, $email, $phone, $productName, $formattedDate);

        $this->ajaxResponse([
            'success' => true,
            'message' => 'Votre demande d\'essai a bien été enregistrée. Vous recevrez un email de confirmation.',
        ]);
    }

    private function sendCustomerEmail(string $email, string $firstname, string $lastname, string $productName, string $date, string $phone): void
    {
        try {
            $shopName = Configuration::get('PS_SHOP_NAME');

            Mail::send(
                $this->context->language->id,
                'store_trial_customer',
                sprintf('Votre demande d\'essai en magasin — %s', $shopName),
                [
                    '{firstname}' => htmlspecialchars($firstname),
                    '{lastname}' => htmlspecialchars($lastname),
                    '{product_name}' => htmlspecialchars($productName),
                    '{desired_date}' => $date,
                    '{phone}' => htmlspecialchars($phone),
                    '{shop_name}' => htmlspecialchars($shopName),
                ],
                $email,
                $firstname . ' ' . $lastname,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'crmcycles/mails/'
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'CRM Cycles Store Trial: erreur envoi email client — ' . $e->getMessage(),
                3, 0, 'CrmCyclesStoreTrial', 0
            );
        }
    }

    private function sendAdminEmail(string $firstname, string $lastname, string $email, string $phone, string $productName, string $date): void
    {
        try {
            $shopName = Configuration::get('PS_SHOP_NAME');
            $adminEmail = Configuration::get('PS_SHOP_EMAIL');

            if (empty($adminEmail)) {
                // Fallback: get all admin emails
                $admins = Employee::getEmployeesByProfile(_PS_ADMIN_PROFILE_);
                if (empty($admins)) {
                    return;
                }
                $adminEmail = $admins[0]['email'];
            }

            Mail::send(
                $this->context->language->id,
                'store_trial_admin',
                sprintf('Nouvelle demande d\'essai en magasin — %s %s', $firstname, $lastname),
                [
                    '{firstname}' => htmlspecialchars($firstname),
                    '{lastname}' => htmlspecialchars($lastname),
                    '{email}' => htmlspecialchars($email),
                    '{phone}' => htmlspecialchars($phone),
                    '{product_name}' => htmlspecialchars($productName),
                    '{desired_date}' => $date,
                    '{shop_name}' => htmlspecialchars($shopName),
                ],
                $adminEmail,
                null,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . 'crmcycles/mails/'
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                'CRM Cycles Store Trial: erreur envoi email admin — ' . $e->getMessage(),
                3, 0, 'CrmCyclesStoreTrial', 0
            );
        }
    }

    private function isTokenValid(): bool
    {
        $token = Tools::getValue('token');
        return !empty($token) && $token === Tools::getToken(false);
    }

    private function ajaxResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
