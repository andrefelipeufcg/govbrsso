<?php

/**
 * Logout federado: encerra a sessão GLPI e redireciona ao /logout do gov.br,
 * que invalida a sessão no provedor e volta para a post_logout_redirect_uri.
 *
 * Cadastre a URL deste script como "URL de Log Out" na credencial gov.br.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbr\Config;

include(__DIR__ . '/../../../inc/includes.php');

// Encerra a sessão local do GLPI.
Session::destroy();

if (!Config::isActive()) {
    Html::redirect($CFG_GLPI['root_doc'] . '/index.php');
}

// post_logout_redirect_uri precisa estar previamente liberada na credencial.
$back = $CFG_GLPI['url_base'] . '/index.php';
$url  = Config::logoutUrl() . '?post_logout_redirect_uri=' . rawurlencode($back);

Html::redirect($url);
