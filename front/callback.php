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

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;

global $CFG_GLPI;


function displayFriendlyError($msg, $debug = null) {
    global $CFG_GLPI;
    $title = __('Erro de Autenticação', 'govbrsso');
    $backText = __('Voltar para o Login', 'govbrsso');
    
    Html::nullHeader($title, $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1');
    
    echo "<div style='display: flex; justify-content: center; align-items: center; min-height: 50vh; padding: 20px;'>";
    echo "<div style='background: #fff3f3; border-left: 5px solid #d9534f; padding: 30px; border-radius: 4px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 600px; font-family: sans-serif; width: 100%; text-align: left;'>";
    echo "<h2 style='color: #d9534f; margin-top: 0; font-size: 20px; font-weight: bold;'>" . $title . "</h2>";
    echo "<p style='font-size: 16px; color: #444; margin-bottom: 25px; line-height: 1.5;'>" . htmlspecialchars($msg) . "</p>";
    
    if ($debug !== null) {
        echo "<pre style='text-align:left; background:#f8f9fa; padding:15px; border:1px solid #ddd; font-size: 13px; color: #333; overflow-x: auto; margin-bottom: 25px;'>" . htmlspecialchars(print_r($debug, true), ENT_QUOTES, 'UTF-8') . "</pre>";
    }
    
    echo "<a href='" . $CFG_GLPI['root_doc'] . "/index.php?noAUTO=1' style='display: inline-block; padding: 10px 20px; background: #0056b3; color: white; text-decoration: none; border-radius: 4px; font-weight: 500; text-align: center;'>" . $backText . "</a>";
    
    echo "</div></div>";
    
    Html::nullFooter();
    die();
}

if (!Config::isActive()) {
    displayFriendlyError(__('Login Único gov.br não está configurado/ativo.', 'govbrsso'));
}

// Erro retornado pelo provedor.
if (isset($_GET['error'])) {
    $desc = (string) ($_GET['error_description'] ?? $_GET['error']);
    Toolbox::logInFile('govbrsso', 'Erro do provedor: ' . $desc . "\n");
    displayFriendlyError(__('Falha na autenticação gov.br: ', 'govbrsso') . $_GET['error'] . ' - ' . ($_GET['error_description'] ?? ''));
}

$code  = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

// Validação do state (anti-CSRF): tem que bater com o emitido na sessão.
$expectedState = (string) ($_SESSION['govbrsso_state'] ?? '');
if ($code === '' || $state === '' || !hash_equals($expectedState, $state)) {
    displayFriendlyError(__('Requisição de callback inválida (state/code).', 'govbrsso'));
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
    Toolbox::logInFile('govbrsso', "[TOKEN_ERROR] " . print_r($debug, true));
    displayFriendlyError(__('Erro ao obter token do gov.br', 'govbrsso'));
}

$accessToken = (string) $token['access_token'];
$idToken     = (string) ($token['id_token'] ?? '');

// Claims do id_token (validação de assinatura como defesa em profundidade).
$claims = [];
if ($idToken !== '') {
    if (!Client::verifySignature($idToken)) {
        Toolbox::logInFile('govbrsso', "Assinatura do id_token não validada (JWKS).\n");
        displayFriendlyError(__('Assinatura do id_token inválida.', 'govbrsso'));
    }
    $claims = Client::decodeJwtPayload($idToken);

    $expectedIss = rtrim((string) Config::get('provider_url'), '/');
    $iss = rtrim((string) ($claims['iss'] ?? ''), '/');
    if ($iss !== $expectedIss) {
        displayFriendlyError(__('Emissor (iss) do id_token inválido.', 'govbrsso'));
    }

    $expectedAud = (string) Config::get('client_id');
    $aud = $claims['aud'] ?? '';
    $auds = is_array($aud) ? $aud : [$aud];
    if (!in_array($expectedAud, $auds, true)) {
        displayFriendlyError(__('Audiência (aud) do id_token inválida.', 'govbrsso'));
    }

    // Validação do nonce.
    $expectedNonce = (string) ($_SESSION['govbrsso_nonce'] ?? '');
    if ($expectedNonce !== '' && ($claims['nonce'] ?? '') !== $expectedNonce) {
        unset($_SESSION['govbrsso_nonce']);
        displayFriendlyError(__('Nonce inválido no id_token.', 'govbrsso'), ['esperado' => $expectedNonce, 'recebido' => $claims['nonce'] ?? null]);
    }
}
unset($_SESSION['govbrsso_nonce']);

// Complementa com /userinfo (fonte autoritativa dos dados do cidadão).
$userinfo = Client::userinfo($accessToken);
$claims   = array_merge($claims, array_filter($userinfo, static fn ($v) => $v !== null && $v !== ''));

// --- Log estruturado de diagnóstico (todas as claims recebidas) ---
$cpfLog = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : 'N/A';
$claimsSafe = $claims;
unset($claimsSafe['picture']); // Remove foto (base64 gigante) do log
Toolbox::logInFile(
    'govbrsso',
    "[CLAIMS] CPF=$cpfLog | " . json_encode($claimsSafe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

// Efetua o login no GLPI.
$result = UserManager::loginFromClaims($claims);

if (isset($result['consent_required']) && $result['consent_required']) {
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/consent.php');
}

if (!$result['ok']) {
    $cpf   = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
    $email = isset($claims['email']) ? trim((string) $claims['email']) : '';
    $emailLog = $email !== '' ? $email : 'não informado';
    Toolbox::logInFile('govbrsso', 'Login negado (CPF: ' . $cpf . ' / E-mail: ' . $emailLog . '): ' . $result['error'] . "\n");
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
