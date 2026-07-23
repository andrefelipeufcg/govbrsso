<?php

/**
 * Tela de Consentimento de Criação de Conta
 * Exibida apenas quando o auto-create está habilitado e a conta não existe.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\UserManager;
use Glpi\Application\View\TemplateRenderer;

include(__DIR__ . '/../../../inc/includes.php');
global $CFG_GLPI;

// Verifica se há claims pendentes na sessão
if (!isset($_SESSION['govbrsso_pending_claims'])) {
    // Sem claims pendentes: volta para o login de forma limpa
    echo "<!DOCTYPE html><html><head><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($CFG_GLPI['root_doc']) . "/index.php?noAUTO=1'></head><body></body></html>";
    die();
}
$claims = $_SESSION['govbrsso_pending_claims'];

// Processamento da Confirmação
if (isset($_GET['confirm'])) {
    Toolbox::logInFile('govbrsso', "[CONSENT] Ação recebida. confirm=" . ($_GET['confirm'] ?? 'N/A') . " csrf_present=" . (isset($_GET['govbrsso_custom_csrf']) ? 'sim' : 'não') . "\n");
    
    // Validação de CSRF Customizada (Bypass para sessão anônima do GLPI)
    $reqCsrf = $_GET['govbrsso_custom_csrf'] ?? '';
    if (!isset($_SESSION['govbrsso_custom_csrf']) || $reqCsrf === '' || !hash_equals($_SESSION['govbrsso_custom_csrf'], $reqCsrf)) {
        Toolbox::logInFile('govbrsso', "[CONSENT] Falha na validação do CSRF customizado.\n");
        echo "<!DOCTYPE html><html><body><h2>Erro de Segurança (CSRF)</h2><p>Sua requisição expirou ou é inválida. Volte para a página de login.</p></body></html>";
        die();
    }

        Toolbox::logInFile('govbrsso', "[CONSENT] Iniciando criação do usuário...\n");
        
        // Tenta criar e logar o usuário com forceCreate = true
        try {
            $result = UserManager::loginFromClaims($claims, true);
            Toolbox::logInFile('govbrsso', "[CONSENT] loginFromClaims retornou: " . json_encode($result) . "\n");
        } catch (\Throwable $e) {
            // No GLPI 11, Html::redirect() lança RedirectException
            if (is_a($e, 'Glpi\Exception\RedirectException') || str_contains(get_class($e), 'RedirectException')) {
                unset($_SESSION['govbrsso_pending_claims']);
                throw $e;
            }
            Toolbox::logInFile('govbrsso', "[CONSENT] Exceção em loginFromClaims: " . get_class($e) . " - " . $e->getMessage() . "\n");
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Limpa a sessão temporária
        unset($_SESSION['govbrsso_pending_claims']);

        if (!$result['ok']) {
            $cpf = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
            Toolbox::logInFile('govbrsso', 'Falha na criação de conta (CPF: ' . $cpf . '): ' . $result['error'] . "\n");

            // HTML puro para evitar 401 do Html::nullHeader()
            $title = __('Erro ao Criar Conta', 'govbrsso');
            $backText = __('Voltar para o Login', 'govbrsso');
            $backUrl = htmlspecialchars($CFG_GLPI['root_doc']) . '/index.php?noAUTO=1';

            echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>" . htmlspecialchars($title) . "</title></head>";
            echo "<body style='background:#f4f6f8; font-family:sans-serif; margin:0; padding:0;'>";
            echo "<div style='display:flex; justify-content:center; align-items:center; min-height:100vh; padding:20px;'>";
            echo "<div style='background:#fff; border-left:5px solid #d9534f; padding:40px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); max-width:500px; width:100%;'>";
            echo "<h2 style='color:#d9534f; margin-top:0;'>" . htmlspecialchars($title) . "</h2>";
            echo "<p style='font-size:16px; color:#444; line-height:1.6; margin-bottom:30px;'>" . htmlspecialchars($result['error']) . "</p>";
            echo "<a href='" . $backUrl . "' style='display:inline-block; padding:12px 24px; background:#1351b4; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;'>" . htmlspecialchars($backText) . "</a>";
            echo "</div></div></body></html>";
            die();
        }

        // Login OK — redireciona para central/helpdesk
        Toolbox::logInFile('govbrsso', "[CONSENT] Login OK! Redirecionando...\n");
        $dest = $CFG_GLPI['root_doc'] . '/index.php';
        if (!empty($_SESSION['govbrsso_redirect'])) {
            $r = (string) $_SESSION['govbrsso_redirect'];
            unset($_SESSION['govbrsso_redirect']);
            if (str_starts_with($r, '/')) {
                $dest = $CFG_GLPI['root_doc'] . '/index.php?redirect=' . rawurlencode($r);
            }
        }

        Html::redirect($dest);
    }

// Preparação para exibição da tela
$name = trim((string) ($claims['name'] ?? ''));

// Descobre o email que será usado para exibição (mesma lógica do UserManager)
$verifiedEmails = [];
$mainEmailVerified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
if (isset($claims['email']) && trim((string)$claims['email']) !== '' && $mainEmailVerified) {
    $verifiedEmails[] = trim((string)$claims['email']);
}
$primaryEmail = $verifiedEmails[0] ?? '';

// Mascara/Anonimiza o e-mail (ex: and***@gmail.com)
$maskedEmail = 'não informado';
if ($primaryEmail !== '') {
    $parts = explode('@', $primaryEmail);
    if (count($parts) === 2) {
        $userPart = $parts[0];
        $domain = $parts[1];
        if (strlen($userPart) > 3) {
            $maskedEmail = substr($userPart, 0, 3) . str_repeat('*', strlen($userPart) - 3) . '@' . $domain;
        } else {
            $maskedEmail = substr($userPart, 0, 1) . str_repeat('*', strlen($userPart) - 1) . '@' . $domain;
        }
    }
}

Html::nullHeader(__('Confirmação de Criação de Conta', 'govbrsso'), $CFG_GLPI['root_doc'] . '/index.php');

if (!isset($_SESSION['govbrsso_custom_csrf'])) {
    $_SESSION['govbrsso_custom_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['govbrsso_custom_csrf'];

$params = [
    'root_doc'   => $CFG_GLPI['root_doc'],
    'selfUrl'    => $CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/consent.php',
    'csrf'       => $csrf,
    'name'       => $name,
    'email'      => $maskedEmail
];

TemplateRenderer::getInstance()->display('@govbrsso/consent.html.twig', $params);

Html::nullFooter();
