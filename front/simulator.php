<?php

/**
 * Simulador de Regras de Criação de Conta do Gov.br SSO
 *
 * @license GPLv3+
 */

include_once '../../../inc/includes.php';

use GlpiPlugin\Govbrsso\Config;
use Glpi\Application\View\TemplateRenderer;

global $CFG_GLPI;

// Verifica direitos
Session::checkRight('config', UPDATE);

Html::header(
    __('Simulador de Regras', 'govbrsso'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugin'
);

$cpf = trim((string)($_POST['cpf'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

$result = null;

if ($isPost && ($cpf !== '' || $email !== '')) {
    $result = [
        'status' => 'info',
        'message' => '',
        'details' => []
    ];
    
    // Simula a lógica de UserManager::loginFromClaims
    $loginField = (string) Config::get('login_field', 'cpf');
    $autoCreate = Config::get('auto_create') === '1';
    
    $user = new User();
    $found = false;
    $login = $cpf;
    
    if ($loginField === 'email') {
        if ($email === '') {
            $result['status'] = 'error';
            $result['message'] = __('Simulação Falhou: E-mail não fornecido e o campo de login está configurado como E-mail.', 'govbrsso');
        } else {
            if ($user->getFromDBbyName($email)) {
                $found = true;
                $login = $email;
            } else {
                $login = $email;
            }
        }
    } else {
        if ($cpf === '') {
            $result['status'] = 'error';
            $result['message'] = __('Simulação Falhou: CPF não fornecido e o campo de login está configurado como CPF.', 'govbrsso');
        } else {
            $cpfOnlyNumbers = preg_replace('/\D+/', '', $cpf);
            $login = $cpfOnlyNumbers;
            $found = $user->getFromDBbyName($login);
        }
    }
    
    if ($result['status'] !== 'error') {
        if ($found) {
            $result['status'] = 'success';
            $result['message'] = sprintf(__('Usuário Encontrado: O sistema fará o login na conta existente "%s" (ID: %d). Nenhuma conta nova será criada.', 'govbrsso'), $user->fields['name'], $user->fields['id']);
        } else {
            if (!$autoCreate) {
                $result['status'] = 'warning';
                $result['message'] = sprintf(__('O usuário "%s" NÃO existe no GLPI e a configuração "Criar usuário" está DESATIVADA. O login seria bloqueado.', 'govbrsso'), $login);
            } else {
                // Simula criação e aplicação de regras
                $domain = '';
                if ($email !== '') {
                    $domain = strtolower(substr(strrchr($email, '@'), 1));
                }
                
                $profile_id = 0;
                $entity_id  = 0;
                $ruleApplied = 'Regra Padrão (Fallback)';
                
                $domainRules = json_decode((string)Config::get('domain_rules', '[]'), true) ?: [];
                
                foreach ($domainRules as $rule) {
                    if ($domain === $rule['domain'] || str_ends_with($domain, '.' . $rule['domain'])) {
                        $profile_id = (int)$rule['profile_id'];
                        $entity_id  = (int)$rule['entity_id'];
                        $ruleApplied = sprintf(__('Regra de Domínio para "%s"', 'govbrsso'), $rule['domain']);
                        break;
                    }
                }
                
                if ($profile_id === 0) {
                    $profile_id = (int)Config::get('default_profile_id', '0');
                    $entity_id  = (int)Config::get('default_entity_id', '0');
                }
                
                if ($profile_id > 0) {
                    $profileName = Dropdown::getDropdownName('glpi_profiles', $profile_id);
                    $entityName = Dropdown::getDropdownName('glpi_entities', $entity_id);
                    
                    $result['status'] = 'success';
                    $result['message'] = sprintf(__('Nova Conta Criada: O usuário "%s" SERÁ CRIADO com sucesso.', 'govbrsso'), $login);
                    $result['details'] = [
                        __('Perfil Atribuído', 'govbrsso') => $profileName,
                        __('Entidade Atribuída', 'govbrsso') => $entityName,
                        __('Regra Utilizada', 'govbrsso') => $ruleApplied
                    ];
                } else {
                    $result['status'] = 'error';
                    $result['message'] = sprintf(__('O usuário "%s" seria criado, porém NENHUM perfil válido (ID > 0) foi encontrado na %s. Sem perfil, o acesso ao GLPI é negado.', 'govbrsso'), $login, $ruleApplied);
                }
            }
        }
    }
}

$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/simulator.php';

$params = [
    'root_doc' => $CFG_GLPI['root_doc'],
    'selfUrl'  => $selfUrl,
    'cpf'      => htmlspecialchars($cpf),
    'email'    => htmlspecialchars($email),
    'result'   => $result,
];

TemplateRenderer::getInstance()->display('@govbrsso/simulator.html.twig', $params);

Html::footer();
