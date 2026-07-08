<?php

/**
 * Tela intermediária para coletar o e-mail de usuários novos que não possuem
 * e-mail validado no gov.br. Necessário para a aplicação de Regras de Domínio.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\UserManager;

include(__DIR__ . '/../../../inc/includes.php');

if (!isset($_SESSION['govbrsso_pending_claims'])) {
    // Acesso direto inválido
    Html::redirect($CFG_GLPI['root_doc'] . '/index.php');
}

$error = '';

if (isset($_POST['submit_email'])) {
    // Validação básica do CSRF usando o helper do GLPI
    if (!isset($_POST['_glpi_csrf_token']) || !Session::validateCSRF($_POST)) {
        $error = 'Sessão expirada ou requisição inválida. Tente novamente.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Por favor, informe um endereço de e-mail válido.';
        } else {
            // Injeta o e-mail coletado nas claims
            $claims = $_SESSION['govbrsso_pending_claims'];
            $claims['email'] = $email;
            $claims['email_verified'] = true; // Forçamos para true apenas para permitir o login

            // Limpa a sessão pendente
            unset($_SESSION['govbrsso_pending_claims']);

            // Retoma o processo de criação de usuário
            $result = UserManager::loginFromClaims($claims);

            if (!$result['ok']) {
                Toolbox::logInFile('govbrsso', 'Login negado (pós coleta e-mail): ' . $result['error'] . "\n", true);
                Html::displayErrorAndDie(htmlspecialchars($result['error']));
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
        }
    }
}

// Renderiza a interface
$csrf = Session::getNewCSRFToken();
$formAction = $_SERVER['REQUEST_URI'];
$logo = $CFG_GLPI['root_doc'] . '/plugins/govbrsso/assets/logo.png'; // caso tenha um logo

Html::header('gov.br - Coleta de E-mail', $_SERVER['REQUEST_URI'], 'config', 'plugins');

$errorHtml = '';
if ($error !== '') {
    $errorHtml = "<div style='color: white; background-color: #d9534f; padding: 10px; border-radius: 4px; margin-bottom: 15px;'><strong>Erro:</strong> " . htmlspecialchars($error) . "</div>";
}

echo <<<HTML
<div style="display: flex; justify-content: center; margin-top: 50px; font-family: Arial, sans-serif;">
    <div style="background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); padding: 30px; width: 100%; max-width: 450px;">
        <div style="text-align: center; margin-bottom: 20px;">
            <span style="font-size: 24px; font-weight: bold;">
              <span style="color:#1351b4">g</span><span style="color:#fcc400">o</span><span style="color:#00a859">v</span><span style="color:#1351b4">.b</span><span style="color:#fcc400">r</span>
            </span>
        </div>
        
        <h2 style="font-size: 1.3rem; margin-top: 0; color: #333; text-align: center;">Complemente seu cadastro</h2>
        <p style="color: #666; font-size: 0.95rem; line-height: 1.5; text-align: justify; margin-bottom: 20px;">
            Identificamos que a sua conta gov.br não possui um e-mail cadastrado ou não o compartilhou. 
            Para o seu primeiro acesso ao nosso sistema, é obrigatório informar o seu e-mail institucional.
        </p>

        {$errorHtml}

        <form method="post" action="{$formAction}">
            <input type="hidden" name="_glpi_csrf_token" value="{$csrf}">
            
            <div style="margin-bottom: 20px;">
                <label for="email" style="display: block; font-weight: bold; margin-bottom: 8px; color: #444;">Seu E-mail Institucional</label>
                <input type="email" id="email" name="email" required placeholder="nome@instituicao.edu.br" 
                       style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 1rem;">
            </div>

            <button type="submit" name="submit_email" 
                    style="width: 100%; padding: 12px; background-color: #1351b4; color: white; border: none; border-radius: 4px; font-size: 1rem; font-weight: bold; cursor: pointer;">
                Salvar E-mail e Entrar
            </button>
        </form>
    </div>
</div>
HTML;

Html::footer();
