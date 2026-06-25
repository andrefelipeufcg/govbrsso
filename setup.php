<?php

/**
 * Plugin "govbr" — Login Único gov.br (OIDC/PKCE) para GLPI 11.
 *
 * @license GPLv3+
 * @link    https://acesso.gov.br/roteiro-tecnico/
 */

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Govbr\Config;

define('PLUGIN_GOVBR_VERSION', '1.0.0');

// Versão mínima (inclusiva) e máxima (exclusiva) do GLPI suportada.
define('PLUGIN_GOVBR_MIN_GLPI', '11.0.0');
define('PLUGIN_GOVBR_MAX_GLPI', '11.0.99');

/**
 * Boot hook (GLPI 11): libera os scripts de início de login e de callback para
 * rodarem SEM sessão autenticada. Sem isso, o GLPI 11 redireciona qualquer
 * script PHP do plugin para a tela de login e o fluxo OAuth nunca completa.
 */
function plugin_govbr_boot(): void
{
    Firewall::addPluginStrategyForLegacyScripts(
        'govbr',
        '#^/front/redirect\\.php$#',
        Firewall::STRATEGY_NO_CHECK,
    );
    Firewall::addPluginStrategyForLegacyScripts(
        'govbr',
        '#^/front/callback\\.php$#',
        Firewall::STRATEGY_NO_CHECK,
    );
    Firewall::addPluginStrategyForLegacyScripts(
        'govbr',
        '#^/front/logout\\.php$#',
        Firewall::STRATEGY_NO_CHECK,
    );
}

/**
 * Init hook — registra ganchos do plugin.
 */
function plugin_init_govbr(): void
{
    global $PLUGIN_HOOKS;

    // Conformidade CSRF.
    $PLUGIN_HOOKS['csrf_compliant']['govbr'] = true;

    // Página de configuração no menu Configurar > Plugins.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['govbr'] = 'front/config.form.php';

    // CSS aplicado também nas páginas anônimas (tela de login).
    $PLUGIN_HOOKS[Hooks::ADD_CSS_ANONYMOUS_PAGE]['govbr'] = ['public/css/login.css'];

    // Botão "Entrar com gov.br" na tela de login.
    // 'display_login' é o gancho histórico que injeta conteúdo abaixo do
    // formulário de login. Caso sua build do GLPI 11 não dispare esse gancho,
    // veja o README para a alternativa via POST_INIT/JS — o botão também
    // funciona como link direto para /plugins/govbr/front/redirect.php.
    $PLUGIN_HOOKS['display_login']['govbr'] = 'plugin_govbr_display_login';
}

/**
 * Metadados exibidos em Configurar > Plugins.
 *
 * @return array<string, mixed>
 */
function plugin_version_govbr(): array
{
    return [
        'name'         => 'Login Único gov.br',
        'version'      => PLUGIN_GOVBR_VERSION,
        'author'       => 'Seu Órgão',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://acesso.gov.br/roteiro-tecnico/',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GOVBR_MIN_GLPI,
                'max' => PLUGIN_GOVBR_MAX_GLPI,
            ],
            'php' => [
                'min'  => '8.2',
                'exts' => [
                    'openssl' => ['required' => true],
                    'curl'    => ['required' => true],
                    'json'    => ['required' => true],
                ],
            ],
        ],
    ];
}

/**
 * Pré-requisitos.
 */
function plugin_govbr_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_GOVBR_MIN_GLPI, '<')) {
        echo 'Este plugin requer GLPI >= ' . PLUGIN_GOVBR_MIN_GLPI;
        return false;
    }
    return true;
}

/**
 * Verificação de configuração (sempre OK; a config é validada no fluxo).
 */
function plugin_govbr_check_config(): bool
{
    return true;
}

/**
 * Renderiza o botão "Entrar com gov.br" na tela de login.
 */
function plugin_govbr_display_login(): void
{
    echo Config::renderLoginButton();
}
