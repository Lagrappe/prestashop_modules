<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_0($module)
{
    Configuration::updateValue('CRMCYCLES_GMERCHANT_ENABLED', 0);
    Configuration::updateValue('CRMCYCLES_GMERCHANT_DESCRIPTION', '');
    Configuration::updateValue('CRMCYCLES_GMERCHANT_ONLY_INSTOCK', 1);

    return true;
}
