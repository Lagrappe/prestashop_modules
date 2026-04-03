<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'crmcycles/classes/CrmCyclesApi.php';
require_once _PS_MODULE_DIR_ . 'crmcycles/classes/CrmCyclesImporter.php';

class CrmCyclesCronModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        // Token validation (skip in CLI)
        if (!Tools::isPHPCLI()) {
            $expectedToken = Tools::substr(Tools::hash('crmcycles/cron'), 0, 10);
            if ($expectedToken !== Tools::getValue('token')) {
                header('HTTP/1.1 403 Forbidden');
                die('Bad token');
            }
        }

        $action = Tools::getValue('action', 'prices_stock');
        $includeOOS = (bool) Tools::getValue('all', 0);

        $importer = new CrmCyclesImporter();
        $results = [];

        switch ($action) {
            case 'full':
                $results[] = $importer->importCategories();
                $results[] = $importer->importFeatures();

                // Products one by one
                $queue = $importer->buildProductImportQueue($includeOOS);
                $prodStats = ['products' => 0, 'errors' => 0];
                if (!empty($queue['queue'])) {
                    foreach ($queue['queue'] as $item) {
                        if ($item['type'] === 'collection') {
                            $r = $importer->importSingleCollection(
                                (int) $item['crm_id'],
                                (int) $item['collection_id'],
                                $item['variants']
                            );
                        } else {
                            $r = $importer->importSingleProduct((int) $item['crm_id']);
                        }
                        $r['success'] ? $prodStats['products']++ : $prodStats['errors']++;
                    }
                }
                $results[] = [
                    'success' => true,
                    'message' => $prodStats['products'] . ' produits importés, ' . $prodStats['errors'] . ' erreurs',
                ];
                break;

            case 'categories':
                $results[] = $importer->importCategories();
                break;

            case 'features':
                $results[] = $importer->importFeatures();
                break;

            case 'products':
                $queue = $importer->buildProductImportQueue($includeOOS);
                $prodStats = ['products' => 0, 'errors' => 0];
                if (!empty($queue['queue'])) {
                    foreach ($queue['queue'] as $item) {
                        if ($item['type'] === 'collection') {
                            $r = $importer->importSingleCollection(
                                (int) $item['crm_id'],
                                (int) $item['collection_id'],
                                $item['variants']
                            );
                        } else {
                            $r = $importer->importSingleProduct((int) $item['crm_id']);
                        }
                        $r['success'] ? $prodStats['products']++ : $prodStats['errors']++;
                    }
                }
                $results[] = [
                    'success' => true,
                    'message' => $prodStats['products'] . ' produits importés, ' . $prodStats['errors'] . ' erreurs',
                ];
                break;

            case 'prices_stock':
            default:
                $api = new CrmCyclesApi();
                $products = $api->getAllProducts();
                $stats = ['updated' => 0, 'not_found' => 0, 'errors' => 0];

                foreach ($products as $product) {
                    $r = $importer->syncSinglePriceStock($product);
                    if ($r['success']) {
                        $stats['updated']++;
                    } elseif (!empty($r['not_found'])) {
                        $stats['not_found']++;
                    } else {
                        $stats['errors']++;
                    }
                }

                $results[] = [
                    'success' => true,
                    'message' => $stats['updated'] . ' mis à jour, ' . $stats['not_found'] . ' non trouvés, ' . $stats['errors'] . ' erreurs',
                ];
                break;
        }

        Configuration::updateValue('CRMCYCLES_LAST_SYNC', date('Y-m-d H:i:s'));

        // Output
        header('Content-Type: application/json');
        die(json_encode([
            'success' => true,
            'action' => $action,
            'results' => $results,
            'log' => $importer->getLog(),
        ]));
    }
}
