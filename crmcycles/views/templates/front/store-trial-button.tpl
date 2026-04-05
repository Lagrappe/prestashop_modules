<div id="crmcycles-store-trial"
     class="crmcycles-store-trial"
     data-action="{$crmcycles_trial_action|escape:'htmlall':'UTF-8'}"
     data-token="{$crmcycles_trial_token|escape:'htmlall':'UTF-8'}"
     data-id-product="{$crmcycles_trial_id_product|intval}"
     style="display:none;">

  <button type="button" class="btn btn-outline-primary crmcycles-trial-btn" id="crmcycles-trial-toggle">
    <i class="material-icons">&#xE52F;</i>
    Essayer en magasin
  </button>

  <div class="crmcycles-trial-form-wrapper" id="crmcycles-trial-form-wrapper" style="display:none;">
    <div id="crmcycles-trial-form" class="crmcycles-trial-form">
      <p class="crmcycles-trial-intro">
        Remplissez ce formulaire pour planifier un essai en magasin. Nous vous recontacterons pour confirmer le rendez-vous.
      </p>

      <div class="form-group">
        <label for="trial_lastname">Nom <span class="required">*</span></label>
        <input type="text" id="trial_lastname" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="trial_firstname">Prénom <span class="required">*</span></label>
        <input type="text" id="trial_firstname" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="trial_email">Email <span class="required">*</span></label>
        <input type="email" id="trial_email" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="trial_phone">Téléphone <span class="required">*</span></label>
        <input type="tel" id="trial_phone" class="form-control" required>
      </div>

      <div class="form-group">
        <label for="trial_date">Date souhaitée <span class="required">*</span></label>
        <input type="date" id="trial_date" class="form-control" required
               min="{$smarty.now|date_format:'%Y-%m-%d'}">
      </div>

      <div class="crmcycles-trial-actions">
        <button type="button" class="btn btn-primary" id="crmcycles-trial-submit">
          Envoyer ma demande
        </button>
        <button type="button" class="btn btn-outline-secondary" id="crmcycles-trial-cancel">
          Annuler
        </button>
      </div>

      <div class="crmcycles-trial-message" id="crmcycles-trial-message" style="display:none;"></div>
    </div>
  </div>
</div>
