<?php

/**
 * Página de configuração do plugin (Configurar > Plugins > Login Único gov.br).
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\Config;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['save_config'])) {
    // MODO DEBUG SEMPRE ATIVO
    // Nota: o GLPI core (inc/includes.php) já valida e consome o token CSRF
    // automaticamente para plugins com csrf_compliant=true. Se o token fosse
    // inválido, o core já teria abortado antes de chegar aqui.
    // Portanto, se este código está executando, o CSRF foi validado com sucesso.
    Toolbox::logInFile('govbrsso', "DEBUG config.form POST recebido:\n" . print_r($_POST, true) . "\n", true);

    Config::save($_POST);
    Session::addMessageAfterRedirect('Configuração do gov.br salva com sucesso.', true, INFO);
    Html::redirect($_SERVER['REQUEST_URI']);
}

$csrf = Session::getNewCSRFToken();

Html::header('Login Único gov.br', $_SERVER['REQUEST_URI'], 'config', 'plugins');

$c = Config::getAll();

$active     = $c['is_active'] === '1' ? 'checked' : '';
$autocreate = $c['auto_create'] === '1' ? 'checked' : '';

$callbackUrl = Html::cleanInputText(Config::callbackUrl());
$logoutUrl   = Html::cleanInputText(Config::pluginLogoutUrl());

$f = static fn (string $k): string => Html::cleanInputText((string) ($c[$k] ?? ''));

$selLogin = static fn (string $v): string => $c['login_field'] === $v ? 'selected' : '';
$selLevel = static fn (string $v): string => ($c['min_level'] ?? '') === $v ? 'selected' : '';

$formAction = $_SERVER['REQUEST_URI'];

global $DB;
$profilesOptions = '<option value="0">---</option>';
foreach ($DB->request('glpi_profiles') as $p) {
    $profilesOptions .= sprintf('<option value="%d">%s</option>', $p['id'], Html::cleanInputText($p['name']));
}

$entitiesOptions = '<option value="0">---</option>';
foreach ($DB->request('glpi_entities') as $e) {
    $entitiesOptions .= sprintf('<option value="%d">%s</option>', $e['id'], Html::cleanInputText($e['completename']));
}

$domainRules = json_decode($c['domain_rules'] ?? '[]', true) ?: [];
$domainRulesHtml = '';
foreach ($domainRules as $rule) {
    $profOpts = str_replace('value="' . $rule['profile_id'] . '"', 'value="' . $rule['profile_id'] . '" selected', $profilesOptions);
    $entOpts  = str_replace('value="' . $rule['entity_id'] . '"', 'value="' . $rule['entity_id'] . '" selected', $entitiesOptions);
    
    $domain = Html::cleanInputText($rule['domain']);
    $domainRulesHtml .= <<<TR
    <tr>
        <td><input type="text" name="domain_rule_domain[]" value="{$domain}" style="width:100%" required></td>
        <td><select name="domain_rule_profile_id[]" style="width:100%" required>{$profOpts}</select></td>
        <td><select name="domain_rule_entity_id[]" style="width:100%" required>{$entOpts}</select></td>
        <td style="text-align:center;"><button type="button" class="btn-remove-rule" style="color:red; font-weight:bold; cursor:pointer;">X</button></td>
    </tr>
TR;
}

$defProfOpts = str_replace('value="' . (int)($c['default_profile_id'] ?? 0) . '"', 'value="' . (int)($c['default_profile_id'] ?? 0) . '" selected', $profilesOptions);
$defEntOpts  = str_replace('value="' . (int)($c['default_entity_id'] ?? 0) . '"', 'value="' . (int)($c['default_entity_id'] ?? 0) . '" selected', $entitiesOptions);

echo <<<HTML
<div class="card" style="max-width:820px;margin:1rem auto;padding:1rem">
<h2>Login Único gov.br</h2>

<div style="background: #eef9fd; border-left: 4px solid #178abb; padding: 1rem; margin-bottom: 1.5rem; border-radius: 4px;">
  <h3 style="margin-top: 0; font-size: 1.2rem; color: #178abb;">Como habilitar a integração com o gov.br?</h3>
  <ol style="line-height: 1.6; margin-bottom: 0; padding-left: 1.2rem;">
    <li>Acesse o <a href="https://manual-roteiro-integracao-login-unico.servicos.gov.br/pt/latest/" target="_blank">Roteiro de Integração do gov.br</a> e inicie o processo de solicitação de uso para o seu órgão/entidade.</li>
    <li>Durante o cadastro do sistema, você precisará informar as URLs de redirecionamento. Copie e cole exatamente as URLs abaixo:
      <ul style="margin-top: 0.5rem; margin-bottom: 0.5rem; list-style-type: disc;">
        <li><strong>URL de retorno (redirect_uri):</strong> <code>{$callbackUrl}</code></li>
        <li><strong>URL de Log Out:</strong> <code>{$logoutUrl}</code></li>
      </ul>
    </li>
    <li>Após a aprovação da integração pelo Ministério da Gestão e da Inovação (MGI), você receberá as credenciais: <strong>client_id</strong> e <strong>client_secret</strong>.</li>
    <li>Preencha os campos do formulário abaixo com o seu <strong>client_id</strong> e <strong>client_secret</strong> recebidos.</li>
    <li>Configure a <strong>Provider URL (sso)</strong> conforme o ambiente fornecido pelo gov.br:
      <ul style="margin-top: 0.5rem; margin-bottom: 0.5rem; list-style-type: disc;">
        <li>Ambiente de Homologação: <code>https://sso.staging.acesso.gov.br</code></li>
        <li>Ambiente de Produção: <code>https://sso.acesso.gov.br</code></li>
      </ul>
    </li>
    <li>Defina os <strong>Escopos</strong> liberados na sua integração (geralmente: <code>openid email profile govbr_confiabilidades</code>).</li>
    <li>Marque a opção <strong>Ativar o botão "Entrar com gov.br"</strong> no final do formulário e clique em <strong>Salvar</strong>.</li>
  </ol>
</div>

<form method="post" action="{$formAction}">
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

  <p><label><input type="checkbox" name="auto_create" id="auto_create_chk" value="1" {$autocreate}>
    Criar usuário automaticamente no primeiro login</label></p>

  <div id="domain_rules_section" style="border: 1px solid #ccc; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
    <h4>Regras de Perfil e Entidade por Domínio</h4>
    <p style="font-size: 0.9em; color: #666;">Adicione regras para definir qual perfil e entidade o usuário receberá com base em seu domínio de e-mail.</p>
    
    <table class="tab_cadre_fixe" id="domain-rules-table" style="width: 100%; margin-bottom: 10px;">
        <thead>
            <tr class="headerRow">
                <th>Domínio (depois do @)</th>
                <th>Perfil</th>
                <th>Entidade</th>
                <th style="width: 40px;"></th>
            </tr>
        </thead>
        <tbody>
            {$domainRulesHtml}
        </tbody>
    </table>
    <button type="button" id="btn-add-rule" style="margin-bottom: 20px;">+ Adicionar Regra</button>

    <hr style="margin: 20px 0;">

    <h4>Regra Padrão (Fallback)</h4>
    <p style="font-size: 0.9em; color: #666;">Se o e-mail do usuário não corresponder a nenhuma das regras acima, este perfil e entidade serão usados. Obrigatório se a criação automática estiver ativada.</p>
    <p>
        <label style="display:inline-block; width:150px;">Perfil Padrão (ELSE):</label>
        <select name="default_profile_id">{$defProfOpts}</select>
    </p>
    <p>
        <label style="display:inline-block; width:150px;">Entidade Padrão (ELSE):</label>
        <select name="default_entity_id">{$defEntOpts}</select>
    </p>
  </div>

  <p><label><input type="checkbox" name="is_active" value="1" {$active}>
    Ativar o botão "Entrar com gov.br"</label></p>

  <p><button type="submit" name="save_config" class="btn btn-primary">Salvar</button></p>
</form>
</div>

<script>
$(function() {
    function toggleDomainRules() {
        if ($('#auto_create_chk').is(':checked')) {
            $('#domain_rules_section').show();
        } else {
            $('#domain_rules_section').hide();
        }
    }
    
    $('#auto_create_chk').on('change', toggleDomainRules);
    toggleDomainRules();

    $('#btn-add-rule').on('click', function() {
        var newRow = `
        <tr>
            <td><input type="text" name="domain_rule_domain[]" style="width:100%" placeholder="ex: aluno.edu.br" required></td>
            <td><select name="domain_rule_profile_id[]" style="width:100%" required>{$profilesOptions}</select></td>
            <td><select name="domain_rule_entity_id[]" style="width:100%" required>{$entitiesOptions}</select></td>
            <td style="text-align:center;"><button type="button" class="btn-remove-rule" style="color:red; font-weight:bold; cursor:pointer;">X</button></td>
        </tr>`;
        $('#domain-rules-table tbody').append(newRow);
    });

    $('#domain-rules-table').on('click', '.btn-remove-rule', function() {
        $(this).closest('tr').remove();
    });
});
</script>
HTML;

Html::footer();
