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
        <td><input type="text" name="domain_rule_domain[]" value="{$domain}" class="form-control" required></td>
        <td><select name="domain_rule_profile_id[]" class="form-select searchable-select" required>{$profOpts}</select></td>
        <td><select name="domain_rule_entity_id[]" class="form-select searchable-select" required>{$entOpts}</select></td>
        <td style="text-align:center;"><button type="button" class="btn btn-outline-danger btn-sm btn-remove-rule" title="Remover"><i class="fas fa-trash"></i></button></td>
    </tr>
TR;
}

$defProfOpts = str_replace('value="' . (int)($c['default_profile_id'] ?? 0) . '"', 'value="' . (int)($c['default_profile_id'] ?? 0) . '" selected', $profilesOptions);
$defEntOpts  = str_replace('value="' . (int)($c['default_entity_id'] ?? 0) . '"', 'value="' . (int)($c['default_entity_id'] ?? 0) . '" selected', $entitiesOptions);

echo <<<HTML
<div class="card col-12 col-lg-8 mx-auto my-4">
    <div class="card-header">
        <h3 class="card-title">Login Único gov.br</h3>
    </div>
    
    <div class="card-body">
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
                <li>Preencha os campos do formulário abaixo com suas credenciais.</li>
                <li>Configure a <strong>Provider URL (sso)</strong> conforme o ambiente:
                    <ul style="margin-top: 0.5rem; margin-bottom: 0.5rem; list-style-type: disc;">
                        <li>Homologação: <code>https://sso.staging.acesso.gov.br</code></li>
                        <li>Produção: <code>https://sso.acesso.gov.br</code></li>
                    </ul>
                </li>
                <li>Marque a opção <strong>Ativar o botão "Entrar com gov.br"</strong> no final do formulário e clique em Salvar.</li>
            </ol>
        </div>

        <form method="post" action="{$formAction}">
            <input type="hidden" name="_glpi_csrf_token" value="{$csrf}">

            <div class="mb-3">
                <label class="form-label">Provider URL (sso)</label>
                <input type="text" name="provider_url" class="form-control" value="{$f('provider_url')}">
            </div>

            <div class="mb-3">
                <label class="form-label">client_id</label>
                <input type="text" name="client_id" class="form-control" value="{$f('client_id')}">
            </div>

            <div class="mb-3">
                <label class="form-label">client_secret <small class="text-muted">(deixe em branco para manter o atual)</small></label>
                <input type="password" name="client_secret" class="form-control" value="">
            </div>

            <div class="mb-3">
                <label class="form-label">Escopos</label>
                <input type="text" name="scopes" class="form-control" value="{$f('scopes')}">
            </div>

            <div class="mb-3">
                <label class="form-label">Campo de login do GLPI</label>
                <select name="login_field" class="form-select">
                    <option value="cpf" {$selLogin('cpf')}>CPF (sub)</option>
                    <option value="email" {$selLogin('email')}>E-mail</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Nível mínimo de confiabilidade</label>
                <select name="min_level" class="form-select">
                    <option value="" {$selLevel('')}>Qualquer</option>
                    <option value="bronze" {$selLevel('bronze')}>Bronze</option>
                    <option value="silver" {$selLevel('silver')}>Prata</option>
                    <option value="gold" {$selLevel('gold')}>Ouro</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-check form-switch">
                    <input type="hidden" name="auto_create" value="0">
                    <input class="form-check-input" type="checkbox" name="auto_create" id="auto_create_chk" value="1" {$autocreate}>
                    <span class="form-check-label">Criar usuário automaticamente no primeiro login</span>
                </label>
            </div>

            <div id="domain_rules_section">
                <hr class="my-4">
                <h4>Regras de Perfil e Entidade por Domínio</h4>
                <div class="alert alert-secondary">
                    Adicione regras para definir qual perfil e entidade o usuário receberá com base em seu domínio de e-mail (Opcional).
                </div>
                
                <table class="table table-sm table-bordered" id="domain-rules-table" style="table-layout: fixed;">
                    <thead>
                        <tr>
                            <th style="width: 33%;">Domínio (depois do @)</th>
                            <th style="width: 33%;">Perfil</th>
                            <th style="width: 33%;">Entidade</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {$domainRulesHtml}
                    </tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="btn-add-rule">
                    <i class="fas fa-plus"></i> Adicionar Regra
                </button>

                <table style="display:none;">
                    <tbody id="rule-template">
                    <tr>
                        <td><input type="text" name="domain_rule_domain[]" class="form-control" placeholder="ex: aluno.edu.br" required disabled></td>
                        <td><select name="domain_rule_profile_id[]" class="form-select" required disabled>{$profilesOptions}</select></td>
                        <td><select name="domain_rule_entity_id[]" class="form-select" required disabled>{$entitiesOptions}</select></td>
                        <td style="text-align:center;"><button type="button" class="btn btn-outline-danger btn-sm btn-remove-rule" title="Remover"><i class="fas fa-trash"></i></button></td>
                    </tr>
                    </tbody>
                </table>

                <hr class="my-4">

                <h4>Regra Padrão (Fallback)</h4>
                <div class="alert alert-secondary">
                    Se o e-mail do usuário não corresponder a nenhuma das regras acima, este perfil e entidade serão usados. Obrigatório se a criação automática estiver ativada.
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Perfil Padrão (ELSE):</label>
                        <select name="default_profile_id" class="form-select searchable-select">{$defProfOpts}</select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Entidade Padrão (ELSE):</label>
                        <select name="default_entity_id" class="form-select searchable-select">{$defEntOpts}</select>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <div class="mb-3">
                <label class="form-check form-switch">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active_chk" value="1" {$active}>
                    <span class="form-check-label">Ativar o botão "Entrar com gov.br"</span>
                </label>
            </div>

            <div class="text-end">
                <button type="submit" name="save_config" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salvar
                </button>
            </div>
        </form>
    </div>
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
    function initSearchable(ctx) {
        var sels = ctx.find('.searchable-select');
        if (typeof $.fn.select2 === 'function') {
            sels.select2({width: '100%'});
        } else if (typeof TomSelect !== 'undefined') {
            sels.each(function() {
                if (!this.tomselect) new TomSelect(this);
            });
        }
    }
    initSearchable($(document));

    $('#btn-add-rule').on('click', function() {
        var newRow = $('#rule-template tr').clone();
        newRow.find(':input').prop('disabled', false);
        $('#domain-rules-table tbody').first().append(newRow);
        initSearchable(newRow);
    });

    $('#domain-rules-table').on('click', '.btn-remove-rule', function() {
        $(this).closest('tr').remove();
    });
});
</script>
HTML;

Html::footer();
