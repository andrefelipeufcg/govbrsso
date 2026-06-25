<?php

/**
 * Install / uninstall hooks do plugin govbrsso.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\Config;

/**
 * Instalação:
 *  - grava configuração padrão (contexto 'plugin:govbrsso');
 *  - garante uma variável SSO dedicada em glpi_ssovariables, usada no momento
 *    do login para acionar o caminho de autenticação EXTERNAL do GLPI (que roda
 *    o motor de regras de habilitação). Não altera as variáveis SSO já
 *    existentes do órgão.
 */
function plugin_govbrsso_install(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    Config::installDefaults();

    // Cria a variável SSO dedicada do plugin, se ainda não existir.
    $name = Config::SSO_VARIABLE_NAME;
    $exists = $DB->request([
        'FROM'  => 'glpi_ssovariables',
        'WHERE' => ['name' => $name],
        'LIMIT' => 1,
    ]);
    if (count($exists) === 0) {
        $DB->insert('glpi_ssovariables', [
            'name'    => $name,
            'comment' => 'Variável usada pelo plugin Login Único gov.br (não remover).',
        ]);
    }

    return true;
}

/**
 * Desinstalação: remove a configuração e a variável SSO dedicada.
 */
function plugin_govbrsso_uninstall(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    Config::removeAll();

    $DB->delete('glpi_ssovariables', ['name' => Config::SSO_VARIABLE_NAME]);

    return true;
}
