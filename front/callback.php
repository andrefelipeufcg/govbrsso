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
    
    $params = [
        'title'     => $title,
        'msg'       => $msg,
        'back_url'  => $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1',
        'back_text' => $backText
    ];
    
    \Glpi\Application\View\TemplateRenderer::getInstance()->display('@govbrsso/error.html.twig', $params);
    
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

// Integração com API Externa para múltiplos e-mails
$extApiActive = Config::get('ext_api_active', '0') === '1';
$extApiUrl = trim((string) Config::get('ext_api_url', ''));
$extApiKey = trim((string) Config::get('ext_api_key', ''));

if ($extApiActive && $extApiUrl !== '' && Config::get('login_field', 'cpf') === 'email') {
    $cpfToQuery = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
    if ($cpfToQuery !== '') {
        $ch = curl_init();
        $url = rtrim($extApiUrl, '/') . '/' . $cpfToQuery;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($extApiKey !== '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Glpi-Api-Key: ' . $extApiKey]);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $extApiEmails = json_decode($response, true);
            if (is_array($extApiEmails) && count($extApiEmails) > 0) {
                if (count($extApiEmails) > 1) {
                    $_SESSION['govbrsso_pending_claims'] = $claims;
                    $_SESSION['govbrsso_ext_api_emails'] = $extApiEmails;
                    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/email.php');
                } else {
                    $claims['email'] = $extApiEmails[0]['email'];
                    $claims['email_verified'] = true;
                }
            }
        }
    }
}

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
