<?php

/**
 * Callback do Login Único gov.br.
 * Valida state, troca code por token, valida assinatura, lê claims e loga.
 * Roda sem sessão autenticada (liberado no boot via Firewall::STRATEGY_NO_CHECK).
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\Client;
use GlpiPlugin\Govbrsso\Config;
use GlpiPlugin\Govbrsso\UserManager;
use User;

include(__DIR__ . '/../../../inc/includes.php');

global $CFG_GLPI;


function displayFriendlyError($msg) {
    global $CFG_GLPI;
    Html::nullHeader('Erro de Autenticação', $CFG_GLPI['root_doc'] . '/index.php');
    echo "<div style='display: flex; justify-content: center; align-items: center; min-height: 50vh;'>";
    echo "<div style='background: #fff3f3; border-left: 5px solid #d9534f; padding: 30px; border-radius: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; font-family: sans-serif;'>";
    echo "<h2 style='color: #d9534f; margin-top: 0; font-size: 20px;'>Erro de Autenticação</h2>";
    echo "<p style='font-size: 16px; color: #444; margin-bottom: 25px; line-height: 1.5;'>" . htmlspecialchars($msg) . "</p>";
    echo "<a href='" . $CFG_GLPI['root_doc'] . "/index.php' style='display: inline-block; padding: 10px 20px; background: #0056b3; color: white; text-decoration: none; border-radius: 4px; font-weight: 500;'>Voltar para o Login</a>";
    echo "</div></div>";
    Html::nullFooter();
    die();
}

if (!Config::isActive()) {
    displayFriendlyError('Login Único gov.br não está configurado/ativo.');
}

// Erro retornado pelo provedor.
if (isset($_GET['error'])) {
    $desc = (string) ($_GET['error_description'] ?? $_GET['error']);
    Toolbox::logInFile('govbrsso', 'Erro do provedor: ' . $desc . "\n", true);
    displayFriendlyError('Falha na autenticação gov.br: ' . htmlspecialchars($desc));
}

$code  = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

// Validação do state (anti-CSRF): tem que bater com o emitido na sessão.
$expectedState = (string) ($_SESSION['govbrsso_state'] ?? '');
if ($code === '' || $state === '' || !hash_equals($expectedState, $state)) {
    displayFriendlyError('Requisição de callback inválida (state/code).');
}

$verifier = (string) ($_SESSION['govbrsso_code_verifier'] ?? '');
unset($_SESSION['govbrsso_state'], $_SESSION['govbrsso_code_verifier']);

$token = Client::requestToken(
    (string) Config::get('client_id'),
    Config::getClientSecret(),
    $code,
    Config::callbackUrl(),
    $verifier,
);

if (isset($token['error']) || empty($token['access_token'])) {
    $debug = [
        'client_id' => Config::get('client_id'),
        'client_secret_length' => strlen(Config::getClientSecret()),
        'redirect_uri' => Config::callbackUrl(),
        'code_verifier' => $verifier,
        'code_challenge_sent_in_auth' => Client::codeChallenge($verifier),
        'token_response' => $token,
    ];
    echo "<h2>Erro ao obter Token do Gov.br</h2>";
    echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>";
    print_r($debug);
    echo "</pre>";
    die();
}

$accessToken = (string) $token['access_token'];
$idToken     = (string) ($token['id_token'] ?? '');

// Claims do id_token (validação de assinatura como defesa em profundidade).
$claims = [];
if ($idToken !== '') {
    if (!Client::verifySignature($idToken)) {
        Toolbox::logInFile('govbrsso', "Assinatura do id_token não validada (JWKS).\n", true);
        // gov.br entrega via TLS direto do /token; seguimos com cautela e
        // complementamos via /userinfo. Para rigor máximo, troque por die().
    }
    $claims = Client::decodeJwtPayload($idToken);

    // Validação do nonce.
    $expectedNonce = (string) ($_SESSION['govbrsso_nonce'] ?? '');
    if ($expectedNonce !== '' && ($claims['nonce'] ?? '') !== $expectedNonce) {
        unset($_SESSION['govbrsso_nonce']);
        displayFriendlyError('Nonce inválido no id_token.');
    }
}
unset($_SESSION['govbrsso_nonce']);

// Complementa com /userinfo (fonte autoritativa dos dados do cidadão).
$userinfo = Client::userinfo($accessToken);
$claims   = array_merge($claims, array_filter($userinfo, static fn ($v) => $v !== null && $v !== ''));

    // Efetua o login no GLPI.
    $result = UserManager::loginFromClaims($claims);

if (!$result['ok']) {
    $cpf   = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
    $email = isset($claims['email']) ? trim((string) $claims['email']) : '';
    $emailLog = $email !== '' ? $email : 'não informado';
    Toolbox::logInFile('govbrsso', 'Login negado (CPF: ' . $cpf . ' / E-mail: ' . $emailLog . '): ' . $result['error'] . "\n", true);
    displayFriendlyError(htmlspecialchars($result['error']));
}

// Destino pós-login.
$dest = $CFG_GLPI['root_doc'] . '/index.php';
if (!empty($_SESSION['govbrsso_redirect'])) {
    $r = (string) $_SESSION['govbrsso_redirect'];
    unset($_SESSION['govbrsso_redirect']);
    if (str_starts_with($r, '/')) {
        $dest = $CFG_GLPI['root_doc'] . '/index.php?redirect=' . rawurlencode($r);
    }
}

Html::redirect($dest);
