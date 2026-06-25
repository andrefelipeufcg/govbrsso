<?php

/**
 * Página de configuração do plugin (Configurar > Plugins > Login Único gov.br).
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbr\Config;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    Session::checkCSRF($_POST);
    Config::save($_POST);
    Session::addMessageAfterRedirect('Configuração do gov.br salva.', true, INFO);
    Html::back();
}

Html::header('Login Único gov.br', $_SERVER['PHP_SELF'], 'config', 'plugins');

$c = Config::getAll();

$active     = $c['is_active'] === '1' ? 'checked' : '';
$autocreate = $c['auto_create'] === '1' ? 'checked' : '';
$csrf       = Session::getNewCSRFToken();

$callbackUrl = Html::cleanInputText(Config::callbackUrl());
$logoutUrl   = Html::cleanInputText(Config::pluginLogoutUrl());

$f = static fn (string $k): string => Html::cleanInputText((string) ($c[$k] ?? ''));

$selLogin = static fn (string $v): string => $c['login_field'] === $v ? 'selected' : '';
$selLevel = static fn (string $v): string => ($c['min_level'] ?? '') === $v ? 'selected' : '';

echo <<<HTML
<div class="card" style="max-width:820px;margin:1rem auto;padding:1rem">
<h2>Login Único gov.br</h2>

<p><strong>URL de retorno (redirect_uri)</strong> a cadastrar na credencial:<br>
<code>{$callbackUrl}</code></p>
<p><strong>URL de Log Out</strong> a cadastrar na credencial:<br>
<code>{$logoutUrl}</code></p>

<form method="post" action="{$_SERVER['PHP_SELF']}">
  <input type="hidden" name="_glpi_csrf_token" value="{$csrf}">

  <p><label>Provider URL (sso)<br>
    <input type="text" name="provider_url" size="60" value="{$f('provider_url')}"></label></p>

  <p><label>client_id<br>
    <input type="text" name="client_id" size="60" value="{$f('client_id')}"></label></p>

  <p><label>client_secret (deixe em branco para manter o atual)<br>
    <input type="password" name="client_secret" size="60" value=""></label></p>

  <p><label>Escopos<br>
    <input type="text" name="scopes" size="60" value="{$f('scopes')}"></label></p>

  <p><label>Campo de login do GLPI
    <select name="login_field">
      <option value="cpf" {$selLogin('cpf')}>CPF (sub)</option>
      <option value="email" {$selLogin('email')}>E-mail</option>
    </select></label></p>

  <p><label>Nível mínimo de confiabilidade
    <select name="min_level">
      <option value="" {$selLevel('')}>Qualquer</option>
      <option value="bronze" {$selLevel('bronze')}>Bronze</option>
      <option value="silver" {$selLevel('silver')}>Prata</option>
      <option value="gold" {$selLevel('gold')}>Ouro</option>
    </select></label></p>

  <p><label><input type="checkbox" name="auto_create" value="1" {$autocreate}>
    Criar usuário automaticamente no primeiro login</label></p>

  <p><label><input type="checkbox" name="is_active" value="1" {$active}>
    Ativar o botão "Entrar com gov.br"</label></p>

  <p><button type="submit" name="update" class="btn btn-primary">Salvar</button></p>
</form>
</div>
HTML;

Html::footer();
