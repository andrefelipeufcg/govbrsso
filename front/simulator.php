<?php

/**
 * Simulador de Regras de Criação de Conta do Gov.br SSO
 *
 * Simula o fluxo completo incluindo o comportamento do core do GLPI 11.0.7
 * (User::post_addItem, Profile::getDefault, etc.) para demonstrar exatamente
 * o que aconteceria em um login real.
 *
 * @license GPLv3+
 */

include_once '../../../inc/includes.php';

use GlpiPlugin\Govbrsso\Config;
use Glpi\Application\View\TemplateRenderer;

global $CFG_GLPI, $DB;

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
$nome = trim((string)($_POST['nome'] ?? ''));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

$result = null;

if ($isPost && ($cpf !== '' || $email !== '')) {
    $timeline = []; // Array de etapas detalhadas
    $finalStatus = 'success';
    $finalMessage = '';
    $details = [];
    
    // --- Configuração Atual do Plugin ---
    $loginField = (string) Config::get('login_field', 'cpf');
    $autoCreate = Config::get('auto_create') === '1';
    $minLevel = (string) Config::get('min_level', '');
    
    $timeline[] = [
        'step' => 'Configuração Lida',
        'source' => 'Plugin',
        'icon' => '⚙️',
        'desc' => sprintf(
            'Campo de login: <strong>%s</strong> | Criação automática: <strong>%s</strong> | Nível mínimo: <strong>%s</strong>',
            $loginField === 'email' ? 'E-mail' : 'CPF',
            $autoCreate ? 'Sim' : 'Não',
            $minLevel !== '' ? ucfirst($minLevel) : 'Nenhum'
        )
    ];
    
    // --- Etapa 1: Busca do Usuário ---
    $user = new User();
    $found = false;
    $login = preg_replace('/\D+/', '', $cpf);
    
    if ($loginField === 'email') {
        if ($email === '') {
            $timeline[] = [
                'step' => 'Validação de E-mail',
                'source' => 'Plugin',
                'icon' => '🚫',
                'desc' => 'E-mail não fornecido. O plugin bloquearia o acesso com a mensagem: <em>"Seu cadastro no gov.br não possui um e-mail validado."</em>'
            ];
            $finalStatus = 'error';
            $finalMessage = __('Login bloqueado: e-mail validado obrigatório quando o campo de login é E-mail.', 'govbrsso');
        } else {
            $timeline[] = [
                'step' => 'Validação de E-mail',
                'source' => 'Plugin',
                'icon' => '✅',
                'desc' => sprintf('E-mail validado presente: <strong>%s</strong>', htmlspecialchars($email))
            ];
            
            if ($user->getFromDBbyName($email)) {
                $found = true;
                $login = $email;
                $timeline[] = [
                    'step' => 'Busca no Banco de Dados',
                    'source' => 'Plugin',
                    'icon' => '🔍',
                    'desc' => sprintf('Usuário encontrado pelo e-mail: <strong>%s</strong> (ID: %d)', htmlspecialchars($email), $user->fields['id'])
                ];
            } else {
                $login = $email;
                $timeline[] = [
                    'step' => 'Busca no Banco de Dados',
                    'source' => 'Plugin',
                    'icon' => '🔍',
                    'desc' => sprintf('Nenhum usuário encontrado com o login <strong>%s</strong>', htmlspecialchars($email))
                ];
            }
        }
    } else {
        if ($cpf === '') {
            $timeline[] = [
                'step' => 'Validação de CPF',
                'source' => 'Plugin',
                'icon' => '🚫',
                'desc' => 'CPF não fornecido.'
            ];
            $finalStatus = 'error';
            $finalMessage = __('Login bloqueado: CPF obrigatório quando o campo de login é CPF.', 'govbrsso');
        } else {
            $cpfClean = preg_replace('/\D+/', '', $cpf);
            $login = $cpfClean;
            $found = $user->getFromDBbyName($login);
            $timeline[] = [
                'step' => 'Busca no Banco de Dados',
                'source' => 'Plugin',
                'icon' => '🔍',
                'desc' => $found 
                    ? sprintf('Usuário encontrado pelo CPF: <strong>%s</strong> (ID: %d)', $cpfClean, $user->fields['id'])
                    : sprintf('Nenhum usuário encontrado com o login <strong>%s</strong>', $cpfClean)
            ];
        }
    }
    
    // --- Etapa 2: Decisão de criação ou login ---
    if ($finalStatus !== 'error') {
        if ($found) {
            $timeline[] = [
                'step' => 'Login em Conta Existente',
                'source' => 'Plugin',
                'icon' => '🔑',
                'desc' => sprintf('O sistema faria login na conta existente <strong>%s</strong> (ID: %d). Nenhuma conta nova seria criada.', htmlspecialchars($user->fields['name']), $user->fields['id'])
            ];
            $finalStatus = 'success';
            $finalMessage = sprintf(__('Usuário "%s" já existe. Login seria realizado com sucesso.', 'govbrsso'), $login);
        } elseif (!$autoCreate) {
            $timeline[] = [
                'step' => 'Criação Automática Desativada',
                'source' => 'Plugin',
                'icon' => '⚠️',
                'desc' => 'A configuração "Criar usuário" está <strong>DESATIVADA</strong>. O login seria bloqueado.'
            ];
            $finalStatus = 'warning';
            $finalMessage = sprintf(__('O usuário "%s" NÃO existe e a criação automática está desativada.', 'govbrsso'), $login);
        } else {
            // --- Fluxo de Criação ---
            
            // Tela de Consentimento
            $timeline[] = [
                'step' => 'Tela de Consentimento',
                'source' => 'Plugin',
                'icon' => '🛡️',
                'desc' => 'O plugin exibiria a tela de consentimento perguntando se o usuário deseja criar a conta. Simulando que o usuário clicou <strong>"Sim"</strong>.'
            ];
            
            // Separação de Nome/Sobrenome
            if ($nome !== '') {
                $nameParts = preg_split('/\s+/', $nome, 2);
                $firstName = $nameParts[0] ?? '';
                $lastName  = $nameParts[1] ?? '';
                $timeline[] = [
                    'step' => 'Formatação do Nome',
                    'source' => 'Plugin',
                    'icon' => '👤',
                    'desc' => sprintf(
                        'Nome completo "<strong>%s</strong>" separado em: Nome = <strong>%s</strong>, Sobrenome = <strong>%s</strong>',
                        htmlspecialchars($nome),
                        htmlspecialchars($firstName),
                        $lastName !== '' ? htmlspecialchars($lastName) : '<em>(vazio)</em>'
                    )
                ];
            }
            
            // Regras de Domínio
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
                    $ruleApplied = sprintf('Regra de Domínio para "%s"', $rule['domain']);
                    break;
                }
            }
            
            if ($profile_id === 0) {
                $profile_id = (int)Config::get('default_profile_id', '0');
                $entity_id  = (int)Config::get('default_entity_id', '0');
            }
            
            if ($domain !== '') {
                $timeline[] = [
                    'step' => 'Regras de Domínio',
                    'source' => 'Plugin',
                    'icon' => '📋',
                    'desc' => sprintf(
                        'Domínio extraído: <strong>@%s</strong>. Regra aplicada: <strong>%s</strong>',
                        htmlspecialchars($domain),
                        $ruleApplied
                    )
                ];
            } else {
                $timeline[] = [
                    'step' => 'Regras de Domínio',
                    'source' => 'Plugin',
                    'icon' => '📋',
                    'desc' => 'Nenhum domínio de e-mail disponível. Usando <strong>Regra Padrão (Fallback)</strong>.'
                ];
            }
            
            $pluginProfileName = $profile_id > 0 ? Dropdown::getDropdownName('glpi_profiles', $profile_id) : '(nenhum)';
            $pluginEntityName  = Dropdown::getDropdownName('glpi_entities', $entity_id);
            
            $timeline[] = [
                'step' => 'Perfil Determinado pelo Plugin',
                'source' => 'Plugin',
                'icon' => '🎯',
                'desc' => sprintf(
                    'Perfil: <strong>%s</strong> (ID: %d) | Entidade: <strong>%s</strong> (ID: %d)',
                    htmlspecialchars($pluginProfileName), $profile_id,
                    htmlspecialchars($pluginEntityName), $entity_id
                )
            ];
            
            // ============================================================
            // SIMULAÇÃO DO COMPORTAMENTO DO CORE DO GLPI 11.0.7
            // ============================================================
            
            // Etapa: User::add() -> post_addItem()
            $timeline[] = [
                'step' => 'User::add() chamado',
                'source' => 'GLPI Core',
                'icon' => '🏗️',
                'desc' => sprintf(
                    'O GLPI core cria o registro do usuário "<strong>%s</strong>" na tabela <code>glpi_users</code>. O plugin <strong>NÃO</strong> envia <code>_profiles_id</code> no input (removido por design).',
                    htmlspecialchars($login)
                )
            ];
            
            // post_addItem -> applyRightRules
            $timeline[] = [
                'step' => 'User::post_addItem() → applyRightRules()',
                'source' => 'GLPI Core',
                'icon' => '⚡',
                'desc' => 'O core executa <code>applyRightRules()</code>. Como não há <code>_ldap_rules</code> no input (não é LDAP), retorna <strong>false</strong>. O bloco de "Add default profile" será executado.'
            ];
            
            // Busca o perfil padrão global do GLPI
            $glpiDefaultProfileId = 0;
            $glpiDefaultProfileName = '(nenhum)';
            $defaultProfiles = $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM' => 'glpi_profiles',
                'WHERE' => ['is_default' => 1],
                'LIMIT' => 1,
            ]);
            foreach ($defaultProfiles as $dp) {
                $glpiDefaultProfileId = (int)$dp['id'];
                $glpiDefaultProfileName = $dp['name'];
            }
            
            $timeline[] = [
                'step' => 'Profile::getDefault()',
                'source' => 'GLPI Core',
                'icon' => '🏷️',
                'desc' => sprintf(
                    'Sem <code>_profiles_id</code> no input, o core chama <code>Profile::getDefault()</code>, que busca o perfil com <code>is_default=1</code> na tabela <code>glpi_profiles</code>. Resultado: <strong>%s</strong> (ID: %d). Este perfil é criado como <strong>dinâmico</strong> (<code>is_dynamic=1</code>).',
                    htmlspecialchars($glpiDefaultProfileName), $glpiDefaultProfileId
                )
            ];
            
            if ($glpiDefaultProfileId > 0) {
                $timeline[] = [
                    'step' => 'Profile_User criado pelo Core',
                    'source' => 'GLPI Core',
                    'icon' => '📝',
                    'desc' => sprintf(
                        'O core cria um registro em <code>glpi_profiles_users</code> vinculando o novo usuário ao perfil <strong>%s</strong>. Este perfil é marcado como <code>is_default_profile=1</code>.',
                        htmlspecialchars($glpiDefaultProfileName)
                    )
                ];
            }
            
            // Etapa: Plugin retoma o controle
            $timeline[] = [
                'step' => 'User::add() retorna → Plugin retoma controle',
                'source' => 'Plugin',
                'icon' => '🔄',
                'desc' => 'O <code>User::add()</code> terminou. O plugin agora executa a limpeza de perfis.'
            ];
            
            if ($profile_id > 0) {
                // Limpeza
                if ($glpiDefaultProfileId > 0 && $glpiDefaultProfileId !== $profile_id) {
                    $timeline[] = [
                        'step' => 'Limpeza de Perfis Auto-Atribuídos',
                        'source' => 'Plugin',
                        'icon' => '🧹',
                        'desc' => sprintf(
                            'O plugin <strong>REMOVE</strong> o perfil <strong>%s</strong> (ID: %d) que o GLPI core atribuiu automaticamente. Todos os registros em <code>glpi_profiles_users</code> para este usuário são apagados.',
                            htmlspecialchars($glpiDefaultProfileName), $glpiDefaultProfileId
                        )
                    ];
                } elseif ($glpiDefaultProfileId === $profile_id) {
                    $timeline[] = [
                        'step' => 'Limpeza de Perfis Auto-Atribuídos',
                        'source' => 'Plugin',
                        'icon' => '🧹',
                        'desc' => sprintf(
                            'O perfil padrão do GLPI (<strong>%s</strong>) coincide com o perfil do plugin. O plugin ainda assim remove e recria para garantir que a entidade e recursividade estejam corretas.',
                            htmlspecialchars($glpiDefaultProfileName)
                        )
                    ];
                }
                
                // Adição do perfil correto
                $timeline[] = [
                    'step' => 'Perfil Final Atribuído',
                    'source' => 'Plugin',
                    'icon' => '✅',
                    'desc' => sprintf(
                        'O plugin cria o registro definitivo em <code>glpi_profiles_users</code>: Perfil = <strong>%s</strong> (ID: %d), Entidade = <strong>%s</strong> (ID: %d), Recursivo = <strong>Sim</strong>.',
                        htmlspecialchars($pluginProfileName), $profile_id,
                        htmlspecialchars($pluginEntityName), $entity_id
                    )
                ];
                
                $finalStatus = 'success';
                $finalMessage = sprintf('O usuário "%s" seria criado com sucesso.', $login);
                $details = [
                    'Login' => $login,
                    'Perfil Final' => $pluginProfileName,
                    'Entidade Final' => $pluginEntityName,
                    'Regra Utilizada' => $ruleApplied,
                ];
                if ($nome !== '') {
                    $nameParts = preg_split('/\s+/', $nome, 2);
                    $details['Nome (firstname)'] = $nameParts[0] ?? '';
                    $details['Sobrenome (realname)'] = $nameParts[1] ?? '';
                }
            } else {
                $timeline[] = [
                    'step' => 'Sem Perfil Configurado no Plugin',
                    'source' => 'Plugin',
                    'icon' => '⚠️',
                    'desc' => sprintf(
                        'Nenhum perfil válido (ID > 0) encontrado nas regras do plugin. O perfil <strong>%s</strong> atribuído pelo GLPI core será mantido.',
                        htmlspecialchars($glpiDefaultProfileName)
                    )
                ];
                
                if ($glpiDefaultProfileId > 0) {
                    $finalStatus = 'warning';
                    $finalMessage = sprintf('O usuário "%s" seria criado, porém ficaria com o perfil padrão global do GLPI (%s) em vez de um perfil configurado no plugin. Configure um perfil válido nas Regras de Domínio ou na Regra Padrão (Fallback).', $login, $glpiDefaultProfileName);
                } else {
                    $finalStatus = 'error';
                    $finalMessage = sprintf('O usuário "%s" seria criado, porém sem NENHUM perfil. O acesso ao GLPI seria negado.', $login);
                }
            }
            
            // Etapa final: Session::init
            if ($finalStatus === 'success') {
                $timeline[] = [
                    'step' => 'Session::init() — Login Efetivado',
                    'source' => 'GLPI Core',
                    'icon' => '🔐',
                    'desc' => 'O plugin chama <code>Session::init()</code> com o objeto <code>Auth</code> configurado. O GLPI core carrega os perfis do usuário a partir de <code>glpi_profiles_users</code> e inicia a sessão. O usuário é redirecionado para o painel.'
                ];
            }
        }
    }
    
    $result = [
        'status' => $finalStatus,
        'message' => $finalMessage,
        'details' => $details ?? [],
        'timeline' => $timeline,
    ];
}

$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/simulator.php';

$params = [
    'root_doc' => $CFG_GLPI['root_doc'],
    'selfUrl'  => $selfUrl,
    'cpf'      => htmlspecialchars($cpf),
    'email'    => htmlspecialchars($email),
    'nome'     => htmlspecialchars($nome),
    'result'   => $result,
];

TemplateRenderer::getInstance()->display('@govbrsso/simulator.html.twig', $params);

Html::footer();
