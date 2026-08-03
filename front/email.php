<?php

use GlpiPlugin\Govbrsso\UserManager;
use GlpiPlugin\Govbrsso\Config;

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;

global $CFG_GLPI;

if (!isset($_SESSION['govbrsso_pending_claims']) || !isset($_SESSION['govbrsso_ext_api_emails'])) {
    Html::redirect($CFG_GLPI['root_doc'] . '/index.php');
}

if (isset($_POST['selected_email'])) {
    $selectedEmail = $_POST['selected_email'];
    $claims = $_SESSION['govbrsso_pending_claims'];
    $validEmails = array_column($_SESSION['govbrsso_ext_api_emails'], 'email');
    
    if (in_array($selectedEmail, $validEmails, true)) {
        $claims['email'] = $selectedEmail;
        $claims['email_verified'] = true;
        
        // Remove emails da sessao
        unset($_SESSION['govbrsso_ext_api_emails']);
        
        $result = UserManager::loginFromClaims($claims);
        
        if (isset($result['consent_required']) && $result['consent_required']) {
            $_SESSION['govbrsso_pending_claims'] = $claims;
            Html::redirect($CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/consent.php');
        }

        if (!$result['ok']) {
            $title = __('Erro de Autenticação', 'govbrsso');
            $backText = __('Voltar para o Login', 'govbrsso');
            Html::nullHeader($title, $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1');
            \Glpi\Application\View\TemplateRenderer::getInstance()->display('@govbrsso/error.html.twig', [
                'title'     => $title,
                'msg'       => htmlspecialchars($result['error']),
                'back_url'  => $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1',
                'back_text' => $backText
            ]);
            Html::nullFooter();
            die();
        }

        $dest = $CFG_GLPI['root_doc'] . '/index.php';
        if (!empty($_SESSION['govbrsso_redirect'])) {
            $r = (string) $_SESSION['govbrsso_redirect'];
            unset($_SESSION['govbrsso_redirect']);
            if (str_starts_with($r, '/')) {
                $dest = $CFG_GLPI['root_doc'] . '/index.php?redirect=' . rawurlencode($r);
            }
        }
        
        unset($_SESSION['govbrsso_pending_claims']);
        Html::redirect($dest);
    }
}

Html::nullHeader(__('Selecione o seu Vínculo', 'govbrsso'), $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@govbrsso/email.html.twig', [
    'emails' => $_SESSION['govbrsso_ext_api_emails'],
    'formAction' => $CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/email.php',
    'csrf' => \Session::getNewCSRFToken()
]);

Html::nullFooter();
