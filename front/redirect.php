<?php

/**
 * Início do fluxo de login gov.br.
 * Gera state/nonce/PKCE, guarda na sessão e redireciona ao /authorize.
 * Roda sem sessão autenticada (liberado no boot via Firewall::STRATEGY_NO_CHECK).
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\Client;
use GlpiPlugin\Govbrsso\Config;

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;

if (!Config::isActive()) {
    Html::displayErrorAndDie(__('Login Único gov.br não está configurado/ativo.', 'govbrsso'));
}

$clientId = (string) Config::get('client_id');
$redirect = Config::callbackUrl();

$state     = Client::randomToken();
$nonce     = Client::randomToken();
$verifier  = Client::newCodeVerifier();
$challenge = Client::codeChallenge($verifier);

// Guarda o estado do PKCE/anti-CSRF na sessão para validar no callback.
$_SESSION['govbrsso_state']         = $state;
$_SESSION['govbrsso_nonce']         = $nonce;
$_SESSION['govbrsso_code_verifier'] = $verifier;

// Preserva eventual destino pós-login.
if (isset($_GET['redirect'])) {
    $_SESSION['govbrsso_redirect'] = (string) $_GET['redirect'];
}

$url = Client::buildAuthorizeUrl(
    $clientId,
    $redirect,
    (string) Config::get('scopes'),
    $state,
    $nonce,
    $challenge,
);

Html::redirect($url);
