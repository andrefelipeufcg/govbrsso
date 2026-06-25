<?php

/**
 * Início do fluxo de login gov.br.
 * Gera state/nonce/PKCE, guarda na sessão e redireciona ao /authorize.
 * Roda sem sessão autenticada (liberado no boot via Firewall::STRATEGY_NO_CHECK).
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbr\Client;
use GlpiPlugin\Govbr\Config;

include(__DIR__ . '/../../../inc/includes.php');

if (!Config::isActive()) {
    Html::displayErrorAndDie('Login Único gov.br não está configurado/ativo.');
}

$clientId = (string) Config::get('client_id');
$redirect = Config::callbackUrl();

$state     = Client::randomToken();
$nonce     = Client::randomToken();
$verifier  = Client::newCodeVerifier();
$challenge = Client::codeChallenge($verifier);

// Guarda o estado do PKCE/anti-CSRF na sessão para validar no callback.
$_SESSION['govbr_state']         = $state;
$_SESSION['govbr_nonce']         = $nonce;
$_SESSION['govbr_code_verifier'] = $verifier;

// Preserva eventual destino pós-login.
if (isset($_GET['redirect'])) {
    $_SESSION['govbr_redirect'] = (string) $_GET['redirect'];
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
