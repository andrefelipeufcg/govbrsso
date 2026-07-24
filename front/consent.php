<?php

/**
 * Tela de Consentimento de Criação de Conta
 * Exibida apenas quando o auto-create está habilitado e a conta não existe.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\UserManager;
use Glpi\Application\View\TemplateRenderer;

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;
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

            $title = __('Erro ao Criar Conta', 'govbrsso');
            $backText = __('Voltar para o Login', 'govbrsso');
            $backUrl = $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1';

            Html::nullHeader($title, $backUrl);

            $params = [
                'title'     => $title,
                'msg'       => $result['error'],
                'back_url'  => $backUrl,
                'back_text' => $backText
            ];

            \Glpi\Application\View\TemplateRenderer::getInstance()->display('@govbrsso/error.html.twig', $params);

            Html::nullFooter();
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
