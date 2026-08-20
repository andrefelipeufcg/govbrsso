<?php
/**
 * Callback Client-Side: recebe os emails via GET (vindos do fetch client-side)
 * e finaliza o login. Usa token de sessão para validar a requisição.
 */
include '../../../inc/includes.php';

use GlpiPlugin\Govbrsso\Config;

global $CFG_GLPI;

// Valida que existe uma autenticação pendente
if (!isset($_SESSION['govbrsso_pending_claims'])) {
    Html::redirect($CFG_GLPI['root_doc'] . '/');
    die();
}

// Valida o token anti-forgery
$expectedToken = $_SESSION['govbrsso_cs_token'] ?? '';
$receivedToken = $_GET['t'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    Toolbox::logInFile('govbrsso', "[CALLBACK_CLIENT] Token inválido. Esperado=$expectedToken Recebido=$receivedToken\n");
    Html::redirect($CFG_GLPI['root_doc'] . '/index.php?noAUTO=1');
    die();
}

// Limpa o token (uso único)
unset($_SESSION['govbrsso_cs_token']);

$claims = $_SESSION['govbrsso_pending_claims'];
unset($_SESSION['govbrsso_pending_claims']);

// Processa os emails se vieram do fetch client-side
if (isset($_GET['emails']) && $_GET['emails'] !== '') {
    $extApiEmails = json_decode($_GET['emails'], true);
    
    if (is_array($extApiEmails) && count($extApiEmails) > 0) {
        $govbrEmail = isset($claims['email']) ? trim((string)$claims['email']) : '';
        $alreadyExists = false;
        foreach ($extApiEmails as $e) {
            if (strcasecmp($e['email'], $govbrEmail) === 0) {
                $alreadyExists = true;
                break;
            }
        }
        
        if ($govbrEmail !== '' && !$alreadyExists) {
            $extApiEmails[] = [
                'email' => $govbrEmail,
                'tipoVinculo' => 'Pessoal (Gov.br)',
                'ativo' => true
            ];
        }
        
        if (count($extApiEmails) > 1) {
            $_SESSION['govbrsso_pending_claims'] = $claims;
            $_SESSION['govbrsso_ext_api_emails'] = $extApiEmails;
            Html::redirect($CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/email.php');
            die();
        } else {
            $claims['email'] = $extApiEmails[0]['email'];
            $claims['email_verified'] = true;
        }
    }
}

Toolbox::logInFile('govbrsso', "[CALLBACK_CLIENT] Efetuando login. Email=" . ($claims['email'] ?? 'N/A') . "\n");

// Efetua o login no GLPI
$result = \GlpiPlugin\Govbrsso\UserManager::loginFromClaims($claims);

if (isset($result['consent_required']) && $result['consent_required']) {
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/consent.php');
}

if (!$result['ok']) {
    $cpf   = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
    $email = isset($claims['email']) ? trim((string) $claims['email']) : '';
    Toolbox::logInFile('govbrsso', "[CALLBACK_CLIENT] Login negado (CPF=$cpf / Email=$email): " . $result['error'] . "\n");
    
    // Redireciona para o login com mensagem de erro
    Session::addMessageAfterRedirect(htmlspecialchars($result['error']), false, ERROR);
    Html::redirect($CFG_GLPI['root_doc'] . '/index.php?noAUTO=1');
    die();
}

// Destino pós-login
$dest = $CFG_GLPI['root_doc'] . '/index.php';
if (!empty($_SESSION['govbrsso_redirect'])) {
    $r = (string) $_SESSION['govbrsso_redirect'];
    unset($_SESSION['govbrsso_redirect']);
    if (str_starts_with($r, '/')) {
        $dest = $CFG_GLPI['root_doc'] . '/index.php?redirect=' . rawurlencode($r);
    }
}

Html::redirect($dest);
