/**
 * Module Marquage - Gestion automatique des caractéristiques produit
 *
 * Ce script s'exécute sur la page d'édition/création produit du back-office PS8.
 * Il ajoute automatiquement les caractéristiques (features) associées aux catégories
 * sélectionnées, selon le mapping défini dans la configuration du module.
 *
 * Le mapping est injecté par le hook displayBackOfficeHeader sous forme de variable
 * globale `marquageCategoryFeatures` (objet { id_category: [id_feature, ...] }).
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // Vérifie que le mapping catégories/features a été injecté par le PHP
    if (typeof marquageCategoryFeatures === 'undefined') {
        return;
    }

    // Garde en mémoire les features déjà ajoutées par ce script (évite les doublons)
    var trackedFeatures = new Set();

    // Sauvegarde des catégories sélectionnées (utile quand la modale se ferme
    // et que les checkboxes ne sont plus dans le DOM)
    var lastKnownCategoryIds = [];

    /**
     * Utilitaire debounce : retarde l'exécution d'une fonction tant qu'elle
     * continue d'être appelée. Évite les appels multiples lors de clics rapides.
     */
    function debounce(fn, ms) {
        var timer;
        return function () {
            var args = arguments;
            var ctx = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    /**
     * Récupère les IDs des catégories actuellement sélectionnées.
     *
     * PS8 affiche les catégories dans une modale avec un arbre de checkboxes.
     * Quand la modale est ouverte, on lit directement les checkboxes cochées.
     * Quand elle est fermée (après clic sur "Appliquer"), les checkboxes
     * disparaissent du DOM — on utilise alors des stratégies de fallback :
     * tags affichés, inputs hidden, ou dernière capture mémorisée.
     */
    function getSelectedCategoryIds() {
        var ids = [];

        // Stratégie 1 : checkboxes dans la modale/arbre (quand elle est ouverte)
        var checkboxes = document.querySelectorAll('.category-tree input[type="checkbox"]:checked');
        if (checkboxes.length > 0) {
            checkboxes.forEach(function (cb) {
                var v = parseInt(cb.value, 10);
                if (v > 0) ids.push(v);
            });
            lastKnownCategoryIds = ids;
            return ids;
        }

        // Stratégie 2 : tags/badges de catégories affichés après validation de la modale
        var tagSelectors = [
            '.pstaggerAddTagWrapper .tag-item',
            '.pstagger-wrapper .tag',
            '.categories-list .tag',
            '[data-categories] .tag',
            '.category-tag',
            '.js-selected-categories .tag'
        ];
        for (var s = 0; s < tagSelectors.length; s++) {
            var tags = document.querySelectorAll(tagSelectors[s]);
            if (tags.length > 0) {
                tags.forEach(function (tag) {
                    var v = parseInt(tag.getAttribute('data-value') || tag.getAttribute('data-id') || '', 10);
                    if (v > 0) ids.push(v);
                });
                if (ids.length > 0) {
                    lastKnownCategoryIds = ids;
                    return ids;
                }
            }
        }

        // Stratégie 3 : inputs hidden contenant les catégories
        var hiddens = document.querySelectorAll('input[type="hidden"][name*="categor"]');
        if (hiddens.length > 0) {
            hiddens.forEach(function (h) {
                var v = parseInt(h.value, 10);
                if (v > 0) ids.push(v);
            });
            if (ids.length > 0) {
                lastKnownCategoryIds = ids;
                return ids;
            }
        }

        // Stratégie 4 : fallback vers la dernière capture (avant fermeture modale)
        if (lastKnownCategoryIds.length > 0) {
            return lastKnownCategoryIds;
        }

        return ids;
    }

    /**
     * Récupère les IDs des features déjà présentes dans le formulaire produit.
     * Chaque ligne de feature contient un <select> avec le feature_id sélectionné.
     */
    function getExistingFeatureIds() {
        var selects = document.querySelectorAll('select[name*="[feature_id]"]');
        var ids = new Set();
        selects.forEach(function (sel) {
            var v = parseInt(sel.value, 10);
            if (v > 0) ids.add(v);
        });
        return ids;
    }

    /**
     * Calcule l'union des features attendues pour les catégories sélectionnées,
     * en se basant sur le mapping `marquageCategoryFeatures`.
     */
    function getTargetFeatureIds(categoryIds) {
        var features = new Set();
        categoryIds.forEach(function (catId) {
            var catFeatures = marquageCategoryFeatures[catId] || marquageCategoryFeatures[catId.toString()] || [];
            // Le mapping peut être un objet ou un tableau selon l'encodage JSON
            var featureList = Array.isArray(catFeatures) ? catFeatures : Object.values(catFeatures);
            featureList.forEach(function (fId) { features.add(fId); });
        });
        return features;
    }

    /**
     * Cherche le bouton "Ajouter une caractéristique" dans le formulaire PS8.
     * Essaie d'abord plusieurs sélecteurs CSS connus, puis en dernier recours
     * recherche par le texte du bouton.
     */
    function findAddFeatureButton() {
        var selectors = [
            'button[id$="_feature_values_add"]',
            '#product_specifications_features_feature_values_add',
            '#product_details_features_feature_values_add',
            '.product-features-list .add-collection-btn',
            '.feature-collection [data-action="add"]',
            '.js-add-feature-btn'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var btn = document.querySelector(selectors[i]);
            if (btn) return btn;
        }

        // Fallback : recherche par texte du bouton
        var allBtns = document.querySelectorAll('button, a.btn');
        for (var j = 0; j < allBtns.length; j++) {
            var text = allBtns[j].textContent.trim().toLowerCase();
            if (text.indexOf('ajouter') !== -1 && (text.indexOf('caract') !== -1 || text.indexOf('feature') !== -1)) {
                return allBtns[j];
            }
        }

        return null;
    }

    /**
     * Ajoute une ligne de feature dans le formulaire :
     * 1. Clique sur le bouton "Ajouter une caractéristique" (crée une nouvelle ligne)
     * 2. Attend que le DOM se mette à jour (setTimeout)
     * 3. Sélectionne la feature voulue dans le dernier <select> ajouté
     */
    function addFeatureRow(featureId) {
        var addBtn = findAddFeatureButton();
        if (!addBtn) return;

        addBtn.click();

        // Délai pour laisser PS8 ajouter la nouvelle ligne dans le DOM
        setTimeout(function () {
            var selects = document.querySelectorAll('select[name*="[feature_id]"]');
            if (selects.length === 0) return;

            var lastSelect = selects[selects.length - 1];
            var option = lastSelect.querySelector('option[value="' + featureId + '"]');
            if (option) {
                lastSelect.value = featureId;
                // Déclenche l'event change pour que PS8 mette à jour ses composants
                lastSelect.dispatchEvent(new Event('change', { bubbles: true }));
                trackedFeatures.add(featureId);
            }
        }, 250);
    }

    /**
     * Fonction principale de synchronisation :
     * Compare les features attendues (selon les catégories) avec celles déjà
     * présentes dans le formulaire, et ajoute les manquantes.
     * N'en retire jamais (pour ne pas écraser des saisies manuelles).
     * Debounce de 500ms pour éviter les exécutions multiples.
     */
    var syncFeatures = debounce(function () {
        var categoryIds = getSelectedCategoryIds();
        var targetFeatures = getTargetFeatureIds(categoryIds);
        var existingFeatures = getExistingFeatureIds();

        // Ne garder que les features manquantes
        var toAdd = [];
        targetFeatures.forEach(function (fId) {
            if (!existingFeatures.has(fId)) {
                toAdd.push(fId);
            }
        });

        if (toAdd.length === 0) return;

        // Ajout séquentiel avec délai entre chaque (évite les conflits DOM)
        toAdd.forEach(function (featureId, index) {
            setTimeout(function () {
                addFeatureRow(featureId);
            }, index * 400);
        });
    }, 500);

    // =========================================================================
    // Écouteurs d'événements
    // =========================================================================

    /**
     * Écoute le clic sur le bouton "Appliquer" de la modale catégories.
     * Capture les catégories cochées AVANT que la modale se ferme (et que
     * les checkboxes disparaissent du DOM), puis lance la sync après un délai.
     * `capture: true` pour intercepter l'event avant les handlers PS8.
     */
    document.addEventListener('click', function (e) {
        var el = e.target;
        if (el.matches && el.matches('.js-apply-categories-btn, [name*="apply_btn"]')) {
            var checkboxes = document.querySelectorAll('.category-tree input[type="checkbox"]:checked');
            var ids = [];
            checkboxes.forEach(function (cb) {
                var v = parseInt(cb.value, 10);
                if (v > 0) ids.push(v);
            });
            lastKnownCategoryIds = ids;
            // Délai pour laisser la modale se fermer et le DOM se stabiliser
            setTimeout(function () {
                syncFeatures();
            }, 800);
        }
    }, true);

    /**
     * Écoute les changements sur les checkboxes de l'arbre catégories
     * (pendant que la modale est ouverte).
     */
    var categorySelector = '.category-tree input[type="checkbox"]';

    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches(categorySelector)) {
            syncFeatures();
        }
    });

    /**
     * Observe les mutations du DOM pour détecter le chargement dynamique
     * de l'arbre de catégories (chargé en asynchrone par PS8/Vue).
     */
    var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var added = mutations[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
                var node = added[j];
                if (node.nodeType === 1 && node.querySelector && node.querySelector(categorySelector)) {
                    return;
                }
            }
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Sync initial au chargement (au cas où des catégories sont déjà sélectionnées)
    syncFeatures();

});
