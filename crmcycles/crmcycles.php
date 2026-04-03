<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class CrmCycles extends Module
{
    public function __construct()
    {
        $this->name = 'crmcycles';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Cycle X';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('CRM Cycles');
        $this->description = $this->l('Importation et synchronisation des produits depuis CRM Cycles : catégories, produits, déclinaisons, caractéristiques, prix et stocks.');
    }

    public function install()
    {
        return parent::install()
            && $this->installDb()
            && $this->installTab()
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
}
