<div class="crmcycles-admin">

    {* Header with logo *}
    <div class="panel">
        <div class="panel-heading" style="display: flex; align-items: center; gap: 15px;">
            <img src="{$module_dir}logo.png" alt="CRM Cycles" style="height: 40px;">
            <span style="font-size: 18px; font-weight: bold;">CRM Cycles — Synchronisation Produits</span>
        </div>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="well text-center">
                        <h4>{$stat_categories}</h4>
                        <small>Catégories mappées</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="well text-center">
                        <h4>{$stat_products}</h4>
                        <small>Produits mappés</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="well text-center">
                        <h4>{$stat_combinations}</h4>
                        <small>Déclinaisons mappées</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="well text-center">
                        <h4>{$stat_features}</h4>
                        <small>Caractéristiques mappées</small>
                    </div>
                </div>
            </div>
            {if $last_sync}
                <p class="text-muted">Dernière synchronisation : {$last_sync}</p>
            {/if}
        </div>
    </div>

    {* Configuration *}
    <div class="panel">
        <div class="panel-heading" style="cursor: pointer;" id="config-panel-heading">
            <i class="icon-cogs"></i> Configuration API
            {if $api_url && $api_secret}
                <span class="badge badge-success" style="margin-left: 10px; background-color: #72c279;">Configuré</span>
            {/if}
            <span class="pull-right">
                <i id="config-toggle-icon" class="icon-chevron-{if $api_url && $api_secret}down{else}up{/if}"></i>
            </span>
        </div>
        <form method="post" class="form-horizontal" id="config-panel-body" {if $api_url && $api_secret}style="display: none;"{/if}>
            <div class="form-group">
                <label class="control-label col-lg-3">URL de l'API CRM Cycles</label>
                <div class="col-lg-6">
                    <input type="url" name="CRMCYCLES_API_URL" value="{$api_url|escape:'html':'UTF-8'}"
                           class="form-control" placeholder="https://crm.cycle-x.dev" required>
                    <p class="help-block">URL de base sans /api/v1 (ex: https://crm.cycle-x.dev)</p>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">Clé secrète API</label>
                <div class="col-lg-6">
                    <input type="password" name="CRMCYCLES_API_SECRET" value="{$api_secret|escape:'html':'UTF-8'}"
                           class="form-control" required>
                    <p class="help-block">Header X-Api-Secret pour l'authentification</p>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">Clé boutique (store_key)</label>
                <div class="col-lg-6">
                    <input type="text" name="CRMCYCLES_STORE_KEY" value="{$store_key|escape:'html':'UTF-8'}"
                           class="form-control" placeholder="guidel">
                    <p class="help-block">Identifiant de la boutique CRM (ex: guidel, formation)</p>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">Catégorie parente d'import</label>
                <div class="col-lg-6">
                    <select name="CRMCYCLES_ROOT_CATEGORY" class="form-control">
                        {foreach $categories as $cat}
                            <option value="{$cat.id_category}"
                                {if $cat.id_category == $root_category}selected{/if}>
                                {$cat.name|escape:'html':'UTF-8'}
                            </option>
                        {/foreach}
                    </select>
                    <p class="help-block">Les familles CRM seront créées comme sous-catégories de cette catégorie</p>
                </div>
            </div>
            <div class="form-group">
                <label class="control-label col-lg-3">Mode développement</label>
                <div class="col-lg-6">
                    <span class="switch prestashop-switch fixed-width-lg">
                        <input type="radio" name="CRMCYCLES_DEV_MODE" id="CRMCYCLES_DEV_MODE_on" value="1"
                            {if $dev_mode}checked="checked"{/if}>
                        <label for="CRMCYCLES_DEV_MODE_on">Oui</label>
                        <input type="radio" name="CRMCYCLES_DEV_MODE" id="CRMCYCLES_DEV_MODE_off" value="0"
                            {if !$dev_mode}checked="checked"{/if}>
                        <label for="CRMCYCLES_DEV_MODE_off">Non</label>
                        <a class="slide-button btn"></a>
                    </span>
                    <p class="help-block">Désactive la vérification SSL pour les environnements de développement local. <strong>Désactiver en production.</strong></p>
                </div>
            </div>
            <div class="form-group">
                <div class="col-lg-offset-3 col-lg-6">
                    <button type="submit" name="submitCrmCyclesConfig" class="btn btn-primary">
                        <i class="icon-save"></i> Sauvegarder la configuration
                    </button>
                    <button type="submit" name="submitTestConnection" class="btn btn-default">
                        <i class="icon-exchange"></i> Tester la connexion
                    </button>
                </div>
            </div>
        </form>
    </div>

    {* Import actions *}
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-download"></i> Actions d'import / synchronisation
        </div>
        <div class="panel-body">
            <div class="alert alert-info">
                <strong>Import complet :</strong> Importe la structure complète (catégories, produits, déclinaisons, caractéristiques).<br>
                <strong>Prix / Stock uniquement :</strong> Met à jour prix, prix promo et quantités via le SKU. Ne modifie pas la structure.
            </div>

            <div class="row">
                <div class="col-md-6">
                    <h4>Import structurel</h4>
                    <form method="post" style="margin-bottom: 10px;">
                        <button type="submit" name="submitImportCategories" class="btn btn-info btn-block">
                            <i class="icon-sitemap"></i> Importer les catégories
                        </button>
                    </form>
                    <form method="post" style="margin-bottom: 10px;">
                        <button type="submit" name="submitImportFeatures" class="btn btn-info btn-block">
                            <i class="icon-tags"></i> Importer les caractéristiques
                        </button>
                    </form>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label style="font-weight: normal; cursor: pointer;">
                            <input type="checkbox" id="include-out-of-stock" style="margin-right: 5px;">
                            Inclure les produits hors stock
                        </label>
                    </div>
                    <button type="button" id="btn-import-products" class="btn btn-info btn-block" style="margin-bottom: 10px;">
                        <i class="icon-cube"></i> Importer les produits
                    </button>
                    <button type="button" id="btn-full-sync" class="btn btn-success btn-block" style="margin-bottom: 10px;">
                        <i class="icon-refresh"></i> Synchronisation complète
                    </button>
                </div>
                <div class="col-md-6">
                    <h4>Synchronisation légère (SKU)</h4>
                    <button type="button" id="btn-sync-prices" class="btn btn-warning btn-block" style="margin-bottom: 10px;">
                        <i class="icon-euro"></i> Synchroniser prix, promos et stocks uniquement
                    </button>
                    <div class="alert alert-warning" style="margin-top: 15px;">
                        <i class="icon-info-circle"></i>
                        Utilise le <strong>SKU</strong> comme identifiant commun pour retrouver les produits et déclinaisons dans PrestaShop.
                        Ne crée aucun produit — met uniquement à jour les prix (HT), prix promo (specific_price) et quantités.
                    </div>
                </div>
            </div>

            {* Progress panel *}
            <div id="import-progress" style="display: none; margin-top: 20px;">
                <h4 id="import-progress-title">Import en cours...</h4>
                <div class="progress">
                    <div id="import-progress-bar" class="progress-bar progress-bar-striped active" role="progressbar"
                         style="width: 0%; min-width: 30px;">
                        0%
                    </div>
                </div>
                <p id="import-progress-status" class="text-muted"></p>
                <div id="import-progress-log" style="max-height: 200px; overflow-y: auto; font-size: 12px; background: #f9f9f9; padding: 8px; border-radius: 4px; display: none;"></div>
            </div>
        </div>
    </div>

    {* Cron / Tâche planifiée *}
    <div class="panel">
        <div class="panel-heading" style="cursor: pointer;" id="cron-panel-heading">
            <i class="icon-time"></i> Tâche planifiée (CRON)
            <span class="pull-right">
                <i id="cron-toggle-icon" class="icon-chevron-down"></i>
            </span>
        </div>
        <div class="panel-body" id="cron-panel-body" style="display: none;">
            <p>Utilisez les URL ci-dessous pour configurer une tâche CRON de synchronisation automatique.</p>

            <div class="form-group">
                <label><strong>Synchronisation prix / stocks uniquement</strong> <small class="text-muted">(recommandé toutes les 15-30 min)</small></label>
                <div class="input-group">
                    <input type="text" class="form-control cron-url-field" readonly
                           value="{$cron_url|escape:'html':'UTF-8'}&action=prices_stock">
                    <span class="input-group-btn">
                        <button class="btn btn-default btn-copy-cron" type="button"><i class="icon-copy"></i></button>
                    </span>
                </div>
            </div>

            <h5 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 5px;">Produits en stock uniquement</h5>

            <div class="form-group">
                <label><strong>Import complet (en stock)</strong> <small class="text-muted">(catégories + caractéristiques + produits en stock — 1x/jour)</small></label>
                <div class="input-group">
                    <input type="text" class="form-control cron-url-field" readonly
                           value="{$cron_url|escape:'html':'UTF-8'}&action=full">
                    <span class="input-group-btn">
                        <button class="btn btn-default btn-copy-cron" type="button"><i class="icon-copy"></i></button>
                    </span>
                </div>
            </div>

            <div class="form-group">
                <label><strong>Import produits seuls (en stock)</strong></label>
                <div class="input-group">
                    <input type="text" class="form-control cron-url-field" readonly
                           value="{$cron_url|escape:'html':'UTF-8'}&action=products">
                    <span class="input-group-btn">
                        <button class="btn btn-default btn-copy-cron" type="button"><i class="icon-copy"></i></button>
                    </span>
                </div>
            </div>

            <h5 style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 5px;">Tous les produits (y compris hors stock)</h5>

            <div class="form-group">
                <label><strong>Import complet (tous)</strong> <small class="text-muted">(catégories + caractéristiques + tous produits — 1x/jour)</small></label>
                <div class="input-group">
                    <input type="text" class="form-control cron-url-field" readonly
                           value="{$cron_url|escape:'html':'UTF-8'}&action=full&all=1">
                    <span class="input-group-btn">
                        <button class="btn btn-default btn-copy-cron" type="button"><i class="icon-copy"></i></button>
                    </span>
                </div>
            </div>

            <div class="form-group">
                <label><strong>Import produits seuls (tous)</strong></label>
                <div class="input-group">
                    <input type="text" class="form-control cron-url-field" readonly
                           value="{$cron_url|escape:'html':'UTF-8'}&action=products&all=1">
                    <span class="input-group-btn">
                        <button class="btn btn-default btn-copy-cron" type="button"><i class="icon-copy"></i></button>
                    </span>
                </div>
            </div>

            <div class="form-group">
                <label><strong>Import catégories seules</strong></label>
                <div class="input-group">
                    <input type="text" class="form-control cron-url-field" readonly
                           value="{$cron_url|escape:'html':'UTF-8'}&action=categories">
                    <span class="input-group-btn">
                        <button class="btn btn-default btn-copy-cron" type="button"><i class="icon-copy"></i></button>
                    </span>
                </div>
            </div>

            <div class="alert alert-info" style="margin-top: 10px;">
                <i class="icon-info-circle"></i>
                <strong>Exemple crontab :</strong><br>
                <code>*/15 * * * * curl -s "{$cron_url|escape:'html':'UTF-8'}&action=prices_stock" > /dev/null 2>&1</code><br>
                <code>0 3 * * * curl -s "{$cron_url|escape:'html':'UTF-8'}&action=full" > /dev/null 2>&1</code><br>
                <small class="text-muted">Ajoutez <code>&all=1</code> pour inclure les produits hors stock.</small>
            </div>
        </div>
    </div>

    {* Sync log *}
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-history"></i> Historique des synchronisations
        </div>
        {if $sync_logs}
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Date début</th>
                        <th>Type</th>
                        <th>Statut</th>
                        <th>Résumé</th>
                        <th>Date fin</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $sync_logs as $log}
                        <tr class="{if $log.status == 'error'}danger{elseif $log.status == 'success'}success{else}info{/if}">
                            <td>{$log.date_start}</td>
                            <td>
                                {if $log.sync_type == 'categories'}Catégories
                                {elseif $log.sync_type == 'products'}Produits
                                {elseif $log.sync_type == 'features'}Caractéristiques
                                {elseif $log.sync_type == 'prices_stock'}Prix / Stock
                                {elseif $log.sync_type == 'full'}Complète
                                {else}{$log.sync_type}{/if}
                            </td>
                            <td>
                                {if $log.status == 'running'}<span class="label label-info">En cours</span>
                                {elseif $log.status == 'success'}<span class="label label-success">Succès</span>
                                {elseif $log.status == 'error'}<span class="label label-danger">Erreur</span>
                                {/if}
                            </td>
                            <td>{$log.summary|escape:'html':'UTF-8'}</td>
                            <td>{$log.date_end|default:'-'}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        {else}
            <div class="panel-body">
                <p class="text-muted">Aucune synchronisation effectuée.</p>
            </div>
        {/if}
    </div>

</div>

<script>
(function() {
    // Copy cron URL buttons
    $('.btn-copy-cron').on('click', function() {
        var $input = $(this).closest('.input-group').find('input');
        $input[0].select();
        document.execCommand('copy');
        var $btn = $(this);
        $btn.find('i').removeClass('icon-copy').addClass('icon-check');
        setTimeout(function() { $btn.find('i').removeClass('icon-check').addClass('icon-copy'); }, 1500);
    });

    // Cron panel toggle
    $('#cron-panel-heading').on('click', function() {
        $('#cron-panel-body').slideToggle(200);
        $('#cron-toggle-icon').toggleClass('icon-chevron-up icon-chevron-down');
    });

    // Config panel toggle
    $('#config-panel-heading').on('click', function() {
        $('#config-panel-body').slideToggle(200);
        var $icon = $('#config-toggle-icon');
        $icon.toggleClass('icon-chevron-up icon-chevron-down');
    });

    var ajaxUrl = '{$ajax_url|escape:"javascript":"UTF-8"}';

    function ajaxCrm(params) {
        params.ajax = 1;
        return $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: params,
            dataType: 'json'
        });
    }

    function showProgress(title) {
        $('#import-progress').show();
        $('#import-progress-title').text(title);
        $('#import-progress-bar').css('width', '0%').text('0%')
            .addClass('active')
            .removeClass('progress-bar-success progress-bar-danger');
        $('#import-progress-status').text('Chargement de la liste...');
        $('#import-progress-log').empty().hide();
        $('#btn-import-products, #btn-full-sync, #btn-sync-prices').prop('disabled', true);
    }

    function updateProgress(current, total, message) {
        var pct = Math.round((current / total) * 100);
        $('#import-progress-bar').css('width', pct + '%').text(pct + '%');
        $('#import-progress-status').text(current + ' / ' + total + ' — ' + message);
    }

    function addLog(msg, isError) {
        var $log = $('#import-progress-log');
        $log.show();
        var color = isError ? '#c0392b' : '#27ae60';
        $log.append('<div style="color:' + color + '">' + $('<span>').text(msg).html() + '</div>');
        $log.scrollTop($log[0].scrollHeight);
    }

    function finishProgress(stats) {
        $('#import-progress-bar')
            .removeClass('active')
            .addClass(stats.errors > 0 ? 'progress-bar-danger' : 'progress-bar-success')
            .css('width', '100%').text('100%');
        $('#import-progress-status').html(
            '<strong>Terminé</strong> — ' + stats.products + ' produits, '
            + stats.combinations + ' déclinaisons, ' + stats.errors + ' erreurs'
        );
        $('#btn-import-products, #btn-full-sync').prop('disabled', false);
    }

    async function importProducts(withCategories) {
        var stats = { products: 0, combinations: 0, errors: 0 };
        var title = withCategories ? 'Synchronisation complète' : 'Import des produits';
        showProgress(title);

        // Step 1: categories (if full sync)
        if (withCategories) {
            $('#import-progress-status').text('Import des catégories...');
            try {
                var catResult = await ajaxCrm({ action_crm: 'importCategories' });
                addLog('Catégories: ' + (catResult.message || 'OK'), !catResult.success);
            } catch(e) {
                addLog('Erreur catégories: ' + e.statusText, true);
            }
        }

        // Step 2: fetch product queue
        var includeOOS = $('#include-out-of-stock').is(':checked') ? 1 : 0;
        $('#import-progress-status').text('Récupération de la liste des produits...');
        var queueResult;
        try {
            queueResult = await ajaxCrm({ action_crm: 'fetchProductQueue', include_out_of_stock: includeOOS });
        } catch(e) {
            addLog('Erreur: impossible de récupérer la liste', true);
            stats.errors++;
            finishProgress(stats);
            return;
        }

        if (!queueResult.success || !queueResult.queue || queueResult.queue.length === 0) {
            addLog(queueResult.message || 'Aucun produit à importer', true);
            finishProgress(stats);
            return;
        }

        var queue = queueResult.queue;
        var total = queue.length;

        // Step 3: import one by one
        for (var i = 0; i < total; i++) {
            var item = queue[i];
            updateProgress(i + 1, total, item.name);

            try {
                var result;
                if (item.type === 'collection') {
                    result = await ajaxCrm({
                        action_crm: 'importSingleCollection',
                        crm_id: item.crm_id,
                        collection_id: item.collection_id,
                        variants: JSON.stringify(item.variants)
                    });
                } else {
                    result = await ajaxCrm({
                        action_crm: 'importSingleProduct',
                        crm_id: item.crm_id
                    });
                }

                if (result.success) {
                    stats.products++;
                    stats.combinations += (result.combinations || 0);
                    addLog(result.message, false);
                } else {
                    stats.errors++;
                    addLog(item.name + ': ' + (result.message || 'Erreur'), true);
                }

                if (result.log && result.log.length) {
                    for (var j = 0; j < result.log.length; j++) {
                        addLog('  → ' + result.log[j], true);
                    }
                }
            } catch(e) {
                stats.errors++;
                addLog(item.name + ': Erreur réseau — ' + e.statusText, true);
            }
        }

        finishProgress(stats);
    }

    async function syncPrices() {
        var stats = { updated: 0, promos: 0, not_found: 0, errors: 0 };
        showProgress('Synchronisation prix / stocks');

        $('#btn-sync-prices').prop('disabled', true);

        // Step 1: fetch queue
        $('#import-progress-status').text('Récupération de la liste des produits...');
        var queueResult;
        try {
            queueResult = await ajaxCrm({ action_crm: 'fetchPriceStockQueue' });
        } catch(e) {
            addLog('Erreur: impossible de récupérer la liste', true);
            stats.errors++;
            finishPriceSync(stats);
            return;
        }

        if (!queueResult.success || !queueResult.queue || queueResult.queue.length === 0) {
            addLog(queueResult.message || 'Aucun produit à synchroniser', true);
            finishPriceSync(stats);
            return;
        }

        var queue = queueResult.queue;
        var total = queue.length;

        // Step 2: sync one by one
        for (var i = 0; i < total; i++) {
            var item = queue[i];
            updateProgress(i + 1, total, item.name);

            try {
                var params = {
                    action_crm: 'syncSinglePriceStock',
                    sku: item.sku,
                    price_ttc: item.price_ttc,
                    original_price_ttc: item.original_price_ttc || '',
                    tva_rate: item.tva_rate,
                    stock: item.stock,
                    promotion: item.promotion ? JSON.stringify(item.promotion) : ''
                };

                var result = await ajaxCrm(params);

                if (result.success) {
                    stats.updated++;
                    if (result.promo) stats.promos++;
                } else if (result.not_found) {
                    stats.not_found++;
                } else {
                    stats.errors++;
                    addLog(result.message, true);
                }
            } catch(e) {
                stats.errors++;
                addLog(item.sku + ': Erreur réseau — ' + e.statusText, true);
            }
        }

        finishPriceSync(stats);
    }

    function finishPriceSync(stats) {
        $('#import-progress-bar')
            .removeClass('active')
            .addClass(stats.errors > 0 ? 'progress-bar-danger' : 'progress-bar-success')
            .css('width', '100%').text('100%');
        $('#import-progress-status').html(
            '<strong>Terminé</strong> — ' + stats.updated + ' mis à jour, '
            + stats.promos + ' promos, ' + stats.not_found + ' non trouvés, '
            + stats.errors + ' erreurs'
        );
        $('#btn-import-products, #btn-full-sync, #btn-sync-prices').prop('disabled', false);
    }

    $('#btn-import-products').on('click', function() {
        importProducts(false);
    });

    $('#btn-full-sync').on('click', function() {
        importProducts(true);
    });

    $('#btn-sync-prices').on('click', function() {
        syncPrices();
    });
})();
</script>
