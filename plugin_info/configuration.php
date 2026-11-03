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
      <label class="col-md-4 control-label">{{Chemin des cookies}}</label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="cookiePath" value="/tmp/sfrhome_session_cookies.json"/>
      </div>
    </div>
    <legend><i class="fas fa-cogs"></i> {{Paramètres}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Fréquence de rafraîchissement (secondes)}}</label>
      <div class="col-md-4">
        <input type="number" class="configKey form-control" data-l1key="refreshInterval" value="60"/>
      </div>
    </div>
  </fieldset>
</form>
