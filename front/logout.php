<?php

/**
 * Logout federado: encerra a sessão GLPI e redireciona ao /logout do gov.br,
 * que invalida a sessão no provedor e volta para a post_logout_redirect_uri.
 *
 * Cadastre a URL deste script como "URL de Log Out" na credencial gov.br.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\Config;

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;

// Encerra a sessão local do GLPI.
Session::destroy();

if (!Config::isActive()) {
    Html::redirect($CFG_GLPI['root_doc'] . '/index.php');
}

// post_logout_redirect_uri precisa estar previamente liberada na credencial.
$back = $CFG_GLPI['url_base'] . '/index.php';
$url  = Config::logoutUrl() . '?post_logout_redirect_uri=' . rawurlencode($back);

Html::redirect($url);
