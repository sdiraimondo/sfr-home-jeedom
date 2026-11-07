<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-key"></i> {{Authentification}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Identifiant SFR}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Adresse email ou identifiant SFR}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="username" placeholder="utilisateur@sfr.fr"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Mot de passe}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Mot de passe SFR}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="password" class="configKey form-control" data-l1key="password"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Test de connexion}}</label>
      <div class="col-md-4">
        <a class="btn btn-primary" id="bt_testSfrHome"><i class="fas fa-plug"></i> {{Tester la connexion}}</a>
        <span id="span_testSfrHomeResult" style="margin-left:10px;"></span>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Chemin des cookies}}</label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="cookiePath" value="/tmp/sfrhome_session_cookies.json"/>
      </div>
    </div>
    <legend><i class="fas fa-cogs"></i> {{Paramètres}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Intervalle de synchronisation (secondes)}}</label>
      <div class="col-md-4">
        <input type="number" class="configKey form-control" data-l1key="refreshInterval" value="60"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Synchronisation automatique}}</label>
      <div class="col-md-4">
        <label class="checkbox-inline"><input type="checkbox" class="configKey" data-l1key="autoSync" checked/>{{Activer la synchronisation automatique}}</label>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Actions}}</label>
      <div class="col-md-4">
        <a class="btn btn-success" id="bt_syncSfrHome"><i class="fas fa-sync"></i> {{Synchroniser les périphériques}}</a>
      </div>
    </div>
  </fieldset>
</form>

<script>
$('#bt_testSfrHome').on('click', function () {
  var $btn = $(this);
  var $result = $('#span_testSfrHomeResult');
  var username = $('.configKey[data-l1key=username]').val();
  var password = $('.configKey[data-l1key=password]').val();
  var cookiePath = $('.configKey[data-l1key=cookiePath]').val();

  $result.removeClass('label label-success label-danger').text('');
  $btn.prop('disabled', true);

  $.ajax({
    type: "POST",
    url: "plugins/sfrhome/core/ajax/sfrhome.ajax.php",
    data: {
      action: "test",
      username: username,
      password: password,
      cookiePath: cookiePath
    },
    dataType: 'json',
    error: function (request, status, error) {
      $btn.prop('disabled', false);
      handleAjaxError(request, status, error);
    },
    success: function (data) {
      $btn.prop('disabled', false);
      var result = data.result || {};
      if (data.state != 'ok') {
        $result.addClass('label label-danger').text(result.message || data.result || '{{Erreur inconnue}}');
        return;
      }
      if (result.ok) {
        $result.addClass('label label-success').text(result.message);
      } else {
        $result.addClass('label label-danger').text(result.message);
      }
    }
  });
});

$('#bt_syncSfrHome').on('click', function () {
  var $btn = $(this);
  $btn.prop('disabled', true);
  $.ajax({
    type: "POST",
    url: "plugins/sfrhome/core/ajax/sfrhome.ajax.php",
    data: {
      action: "sync"
    },
    dataType: 'json',
    error: function (request, status, error) {
      $btn.prop('disabled', false);
      handleAjaxError(request, status, error);
    },
    success: function (data) {
      $btn.prop('disabled', false);
      if (data.state != 'ok') {
        $('#div_alert').showAlert({message: data.result, level: 'danger'});
        return;
      }
      var result = data.result || {};
      var message = '{{Synchronisation réussie}}';
      if (result.count !== undefined) {
        message += ' (' + result.count + ' {{périphérique(s)}})';
      }
      $('#div_alert').showAlert({message: message, level: 'success'});
    }
  });
});
</script>
