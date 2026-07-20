<?php

/**
 * Página de configuração do plugin (Configurar > Plugins > Login Único gov.br).
 *
 * @license GPLv3+
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Govbrsso\Config;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);

global $CFG_GLPI;

if (isset($_POST['save_config'])) {
    // MODO DEBUG SEMPRE ATIVO
    // Nota: o GLPI core (inc/includes.php) já valida e consome o token CSRF
    // automaticamente para plugins com csrf_compliant=true. Se o token fosse
    // inválido, o core já teria abortado antes de chegar aqui.
    // Portanto, se este código está executando, o CSRF foi validado com sucesso.
    $adminName = $_SESSION['glpiname'] ?? 'Desconhecido';
    Toolbox::logInFile('govbrsso', "[ADMIN] Configurações do plugin govbrsso alteradas pelo usuário {$adminName} com os seguintes dados:\n" . print_r($_POST, true) . "\n");

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
        <td style="text-align:center; vertical-align:middle;"><button type="button" class="btn btn-outline-danger btn-sm btn-remove-rule" title="Remover"><i class="fas fa-trash"></i></button></td>
    </tr>
TR;
}

$defProfOpts = str_replace('value="' . (int)($c['default_profile_id'] ?? 0) . '"', 'value="' . (int)($c['default_profile_id'] ?? 0) . '" selected', $profilesOptions);
$defEntOpts  = str_replace('value="' . (int)($c['default_entity_id'] ?? 0) . '"', 'value="' . (int)($c['default_entity_id'] ?? 0) . '" selected', $entitiesOptions);

$params = [
    'callbackUrl' => $callbackUrl,
    'logoutUrl' => $logoutUrl,
    'root_doc' => $CFG_GLPI['root_doc'],
    'formAction' => $formAction,
    'csrf' => $csrf,
    'c' => $c,
    'domainRulesHtml' => $domainRulesHtml,
    'profilesOptions' => $profilesOptions,
    'entitiesOptions' => $entitiesOptions,
    'defProfOpts' => $defProfOpts,
    'defEntOpts' => $defEntOpts,
];

TemplateRenderer::getInstance()->display('@govbrsso/config.html.twig', $params);

Html::footer();
