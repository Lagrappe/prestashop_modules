document.addEventListener('DOMContentLoaded', function () {
  var container = document.getElementById('crmcycles-store-trial');
  if (!container) return;

  var toggleBtn = document.getElementById('crmcycles-trial-toggle');
  var formWrapper = document.getElementById('crmcycles-trial-form-wrapper');
  var formDiv = document.getElementById('crmcycles-trial-form');
  var cancelBtn = document.getElementById('crmcycles-trial-cancel');
  var submitBtn = document.getElementById('crmcycles-trial-submit');
  var messageDiv = document.getElementById('crmcycles-trial-message');

  var action = container.dataset.action;
  var token = container.dataset.token;
  var idProduct = parseInt(container.dataset.idProduct, 10);

  // Field references
  var fields = {
    trial_lastname: document.getElementById('trial_lastname'),
    trial_firstname: document.getElementById('trial_firstname'),
    trial_email: document.getElementById('trial_email'),
    trial_phone: document.getElementById('trial_phone'),
    trial_date: document.getElementById('trial_date')
  };

  function resetFields() {
    for (var key in fields) {
      if (fields[key]) fields[key].value = '';
    }
  }

  // Show/hide based on stock availability of selected combination
  function updateVisibility() {
    var availEl = document.getElementById('product-availability');
    if (!availEl) {
      container.style.display = '';
      return;
    }

    var icon = availEl.querySelector('.material-icons');
    if (icon) {
      var iconText = icon.textContent.trim();
      if (iconText === '\uE5CA' || iconText === '\uE002' || iconText === 'check_circle' || iconText === 'warning') {
        container.style.display = '';
      } else {
        container.style.display = 'none';
      }
    } else {
      container.style.display = '';
    }
  }

  function getSelectedCombinationId() {
    var input = document.querySelector(
      '#add-to-cart-or-refresh input[name="id_product_attribute"], ' +
      'form.add-to-cart input[name="id_product_attribute"]'
    );
    return input ? parseInt(input.value, 10) || 0 : 0;
  }

  // Initial check
  updateVisibility();

  // Listen for PrestaShop combination change events
  if (typeof prestashop !== 'undefined') {
    prestashop.on('updatedProduct', function () {
      updateVisibility();
    });
  }

  // Also observe DOM changes on #product-availability
  var availEl = document.getElementById('product-availability');
  if (availEl) {
    var observer = new MutationObserver(function () {
      updateVisibility();
    });
    observer.observe(availEl, { childList: true, subtree: true, characterData: true });
  }

  // Toggle form
  toggleBtn.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    if (formWrapper.style.display === 'none') {
      formWrapper.style.display = '';
      toggleBtn.style.display = 'none';
    }
  });

  cancelBtn.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();
    formWrapper.style.display = 'none';
    toggleBtn.style.display = '';
    messageDiv.style.display = 'none';
    resetFields();
  });

  // Submit via button click (no <form>, no submit event)
  submitBtn.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();

    messageDiv.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Envoi en cours...';

    var formData = new FormData();
    formData.append('submitStoreTrial', '1');
    formData.append('token', token);
    formData.append('id_product', idProduct);
    formData.append('id_product_attribute', getSelectedCombinationId());

    for (var key in fields) {
      formData.append(key, fields[key] ? fields[key].value : '');
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', action, true);
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.onload = function () {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Envoyer ma demande';

      try {
        var resp = JSON.parse(xhr.responseText);
        messageDiv.style.display = '';
        messageDiv.textContent = resp.message || 'Réponse inattendue.';

        if (resp.success) {
          messageDiv.className = 'crmcycles-trial-message success';
          resetFields();
          setTimeout(function () {
            formWrapper.style.display = 'none';
            toggleBtn.style.display = '';
            messageDiv.style.display = 'none';
          }, 5000);
        } else {
          messageDiv.className = 'crmcycles-trial-message error';
        }
      } catch (err) {
        messageDiv.style.display = '';
        messageDiv.className = 'crmcycles-trial-message error';
        messageDiv.textContent = 'Erreur de communication avec le serveur.';
      }
    };

    xhr.onerror = function () {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Envoyer ma demande';
      messageDiv.style.display = '';
      messageDiv.className = 'crmcycles-trial-message error';
      messageDiv.textContent = 'Erreur de communication avec le serveur.';
    };

    xhr.send(formData);
  });
});
