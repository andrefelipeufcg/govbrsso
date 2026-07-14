<?php

namespace GlpiPlugin\Govbrsso;

use Auth;
use Profile_User;
use User;

/**
 * Casa os claims do gov.br com um usuário do GLPI e estabelece a sessão.
 *
 * O login efetivo usa o caminho de AUTENTICAÇÃO EXTERNA do GLPI: definimos
 * temporariamente a variável SSO dedicada do plugin e chamamos Auth::login(),
 * que executa todo o pipeline (inclusive o motor de Regras de atribuição de
 * habilitações) e cria a sessão. Esse é o mecanismo suportado no GLPI 11 para
 * um plugin logar um usuário sem conhecer a senha.
 *
 * @license GPLv3+
 */
final class UserManager
{
    /**
     * @param array<string,mixed> $claims  claims combinados (id_token + userinfo)
     * @return array{ok:bool,error:string}
     */
    public static function loginFromClaims(array $claims): array
    {
        $cpf   = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
        $name  = trim((string) ($claims['name'] ?? ''));

        if ($cpf === '') {
            return ['ok' => false, 'error' => 'Claim "sub" (CPF) ausente no token gov.br.'];
        }

        // --- Extração de múltiplos e-mails ---
        $verifiedEmails = [];
        $mainEmailVerified = ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? '') === 'true';

        if (isset($claims['email']) && trim((string)$claims['email']) !== '' && $mainEmailVerified) {
            $verifiedEmails[] = trim((string)$claims['email']);
        }

        if (isset($claims['emails']) && is_array($claims['emails'])) {
            foreach ($claims['emails'] as $em) {
                $emStr = trim((string)$em);
                if ($emStr !== '' && !in_array($emStr, $verifiedEmails, true)) {
                    $verifiedEmails[] = $emStr;
                }
            }
        }

        if (isset($claims['email_institucional']) && trim((string)$claims['email_institucional']) !== '') {
            $emStr = trim((string)$claims['email_institucional']);
            if (!in_array($emStr, $verifiedEmails, true)) {
                $verifiedEmails[] = $emStr;
            }
        }
        
        $primaryEmail = $verifiedEmails[0] ?? '';

        $level = self::getLevel($claims);
        $levelNames = [
            'gold'   => 'ouro',
            'silver' => 'prata',
            'bronze' => 'bronze'
        ];
        $levelPt = $levelNames[$level] ?? 'bronze';

        // Verificação opcional de nível mínimo de confiabilidade.
        $minLevel = (string) Config::get('min_level', '');
        if ($minLevel !== '' && !self::meetsLevel($level, $minLevel)) {
            $minLevelPt = strtoupper($levelNames[$minLevel] ?? $minLevel);
            return ['ok' => false, 'error' => "Sua conta gov.br não atinge o nível mínimo exigido ($minLevelPt)."];
        }

        $loginField = (string) Config::get('login_field', 'cpf');
        
        // 1) Garante o usuário no GLPI.
        $user = new User();
        $found = false;
        $login = $cpf;

        if ($loginField === 'email') {
            if (empty($verifiedEmails)) {
                return ['ok' => false, 'error' => 'Seu cadastro no gov.br não possui um e-mail validado. Por favor, acesse gov.br, adicione e valide seu e-mail antes de acessar o sistema.'];
            }
            
            // Varre todos os e-mails fornecidos pelo Gov.br procurando o cadastro no GLPI
            foreach ($verifiedEmails as $possibleEmail) {
                if ($user->getFromDBbyName($possibleEmail)) {
                    $found = true;
                    $login = $possibleEmail;
                    break;
                }
            }
            
            // Se não encontrou nenhuma conta e formos auto-criar, usamos o primeiro e-mail validado
            if (!$found) {
                $login = $primaryEmail;
            }
        } else {
            $found = $user->getFromDBbyName($login);
        }

        if (!$found && Config::get('auto_create') === '1') {
            $input = [
                'name'     => $login,
                'realname' => $name !== '' ? $name : null,
                'authtype' => Auth::EXTERNAL,
                'comment'  => 'Criado via Login Único gov.br',
            ];
            if ($primaryEmail !== '') {
                $input['_useremails'] = [-1 => $primaryEmail];
            }

            // --- Lógica de Regras de Domínio ---
            $domain = '';
            if ($primaryEmail !== '') {
                $domain = strtolower(substr(strrchr($primaryEmail, '@'), 1));
            }
            
            $profile_id = 0;
            $entity_id  = 0;
            
            $domainRules = json_decode((string)Config::get('domain_rules', '[]'), true) ?: [];
            
            foreach ($domainRules as $rule) {
                if ($domain === $rule['domain'] || str_ends_with($domain, '.' . $rule['domain'])) {
                    $profile_id = (int)$rule['profile_id'];
                    $entity_id  = (int)$rule['entity_id'];
                    break;
                }
            }
            
            if ($profile_id === 0) {
                $profile_id = (int)Config::get('default_profile_id', '0');
                $entity_id  = (int)Config::get('default_entity_id', '0');
            }
            
            if ($profile_id > 0) {
                $input['_profiles_id']  = $profile_id;
                $input['_entities_id']  = $entity_id;
                $input['_is_recursive'] = 1;
            }
            // -----------------------------------

            $id = $user->add($input);
            if (!$id) {
                return ['ok' => false, 'error' => 'Falha ao criar o usuário no GLPI.'];
            }
            $user->getFromDB($id);
        } elseif (!$found) {
            return ['ok' => false, 'error' => "Usuário '$login' não existe e a criação automática está desativada."];
        }

        // 2) Login via auth externa (dispara o motor de regras e cria a sessão).
        return self::performExternalLogin($user, $primaryEmail, $levelPt);
    }

    /**
     * @return array{ok:bool,error:string}
     */
    private static function performExternalLogin(User $user, string $email, string $levelPt): array
    {
        /** @var \DBmysql $DB */
        global $CFG_GLPI, $DB;

        $login = (string) $user->fields['name'];

        // Localiza a variável SSO dedicada criada na instalação.
        $ssoId = null;
        $rows = $DB->request([
            'FROM'  => 'glpi_ssovariables',
            'WHERE' => ['name' => Config::SSO_VARIABLE_NAME],
            'LIMIT' => 1,
        ]);
        foreach ($rows as $row) {
            $ssoId = (int) $row['id'];
            break;
        }
        if ($ssoId === null) {
            return ['ok' => false, 'error' => 'Variável SSO do plugin não encontrada (reinstale o plugin).'];
        }

        // Contexto temporário de auth externa.
        $origSso = $CFG_GLPI['ssovariables_id'] ?? 0;
        $CFG_GLPI['ssovariables_id'] = $ssoId;
        $_SERVER[Config::SSO_VARIABLE_NAME] = $login;

        // Mapeia o e-mail para o GLPI casar/atualizar (campo SSO de e-mail).
        $origEmailField = $CFG_GLPI['email1_ssofield'] ?? '';
        if ($email !== '') {
            $CFG_GLPI['email1_ssofield'] = 'GOVBRSSO_EMAIL';
            $_SERVER['GOVBRSSO_EMAIL'] = $email;
        }

        try {
            $auth = new Auth();
            $ok = $auth->login($login, '', false);

            if ($ok) {
                // Atualiza o evento de login recém-criado pelo Auth::login()
                // para incluir a tag SSO e o ID do usuário (items_id), independentemente
                // de o plugin authhistory estar ativado ou não.
                global $DB;
                $users_id = \Session::getLoginUserID();
                if ($users_id) {
                    $iterator = $DB->request([
                        'FROM'  => 'glpi_events',
                        'WHERE' => ['service' => 'login'],
                        'ORDER' => 'id DESC',
                        'LIMIT' => 1
                    ]);
                    if (count($iterator) > 0) {
                        $event = $iterator->current();
                        $message = $event['message'];
                        if (strpos($message, 'via Gov.BR') === false) {
                            $DB->update('glpi_events', [
                                'items_id' => $users_id,
                                'message'  => ltrim($message) . " via Gov.BR nível {$levelPt} (SSO)"
                            ], ['id' => $event['id']]);
                        }
                    }
                }
            }
        } finally {
            // Limpeza do contexto temporário (em qualquer caso).
            $CFG_GLPI['ssovariables_id'] = $origSso;
            unset($_SERVER[Config::SSO_VARIABLE_NAME]);
            $CFG_GLPI['email1_ssofield'] = $origEmailField;
            unset($_SERVER['GOVBRSSO_EMAIL'], $_SESSION['glpi_remote_user']);
        }

        if (!$ok) {
            // Diagnóstico: usuário sem habilitação => falta regra.
            $hasProfile = countElementsInTable(
                (new Profile_User())->getTable(),
                ['users_id' => (int) $user->fields['id']],
            ) > 0;

            $msg = $hasProfile
                ? "Usuário '$login' não autorizado a conectar no GLPI."
                : "Usuário '$login' sem habilitação. Crie uma Regra de atribuição de habilitações (Administração > Regras).";
            return ['ok' => false, 'error' => $msg];
        }

        return ['ok' => true, 'error' => ''];
    }

    public static function getLevel(array $claims): string
    {
        $level = '';

        // Tenta obter o nível pelo array AMR (padrão atual do gov.br)
        if (isset($claims['amr'])) {
            $amr = is_array($claims['amr']) ? $claims['amr'] : explode(',', $claims['amr']);
            if (in_array('govbr_nivel_ouro', $amr, true)) {
                $level = 'gold';
            } elseif (in_array('govbr_nivel_prata', $amr, true)) {
                $level = 'silver';
            } elseif (in_array('govbr_nivel_bronze', $amr, true)) {
                $level = 'bronze';
            }
        }

        // Fallback para reliability_info (caso exista ou formato antigo)
        if ($level === '' && isset($claims['reliability_info']['level'])) {
            $level = strtolower((string) $claims['reliability_info']['level']);
        }
        
        // Todo usuário autenticado no gov.br possui, no mínimo, nível bronze.
        // Se a API não retornou o nível explicitamente, assumimos bronze para que
        // não bloqueie quem configurou o mínimo como "bronze".
        if ($level === '') {
            $level = 'bronze';
        }

        return $level;
    }

    private static function meetsLevel(string $level, string $min): bool
    {
        $order = ['bronze' => 1, 'silver' => 2, 'gold' => 3];

        if (!isset($order[$level], $order[$min])) {
            return false;
        }
        
        return $order[$level] >= $order[$min];
    }
}
