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
     * @param bool $forceCreate Se true, cria o usuário diretamente (após o consentimento).
     * @return array{ok:bool,error?:string,consent_required?:bool}
     */
    public static function loginFromClaims(array $claims, bool $forceCreate = false): array
    {
        $cpf   = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
        $name  = trim((string) ($claims['name'] ?? ''));

        if ($cpf === '') {
            return ['ok' => false, 'error' => __('Claim "sub" (CPF) ausente no token gov.br.', 'govbrsso')];
        }

        // --- Extração de múltiplos e-mails ---
        $verifiedEmails = [];
        $mainEmailVerified = ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? '') === 'true';

        if (isset($claims['email']) && trim((string)$claims['email']) !== '' && $mainEmailVerified) {
            $verifiedEmails[] = trim((string)$claims['email']);
        }
        
        $primaryEmail = $verifiedEmails[0] ?? '';

        $level = self::getLevel($claims);
        $levelMap = ['gold' => __('ouro', 'govbrsso'), 'silver' => __('prata', 'govbrsso'), 'bronze' => __('bronze', 'govbrsso')];
        $levelPt = $levelMap[$level] ?? __('bronze', 'govbrsso');

        // Verificação opcional de nível mínimo de confiabilidade.
        $minLevel = (string) Config::get('min_level', '');
        if ($minLevel !== '' && !self::meetsLevel($level, $minLevel)) {
            return ['ok' => false, 'error' => sprintf(__('Sua conta gov.br não atinge o nível mínimo exigido (%s).', 'govbrsso'), mb_strtoupper((string)($levelMap[$minLevel] ?? $minLevel), 'UTF-8'))];
        }

        $loginField = (string) Config::get('login_field', 'cpf');
        
        // 1) Garante o usuário no GLPI.
        $user = new User();
        $found = false;
        $login = $cpf;

        if ($loginField === 'email') {
            if (empty($verifiedEmails)) {
                return ['ok' => false, 'error' => __('Seu cadastro no gov.br não possui um e-mail validado. Por favor, acesse gov.br, adicione e valide seu e-mail antes de acessar o sistema.', 'govbrsso')];
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
            
            if (!$forceCreate) {
                $_SESSION['govbrsso_pending_claims'] = $claims;
                return ['ok' => false, 'consent_required' => true];
            }
            
            // Separa o nome completo do Gov.br em Nome (firstname) e Sobrenome (realname)
            $nameParts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
            $firstName = $nameParts[0] ?? '';
            $lastName  = $nameParts[1] ?? '';
            
            $input = [
                'name'      => $login,
                'firstname' => $firstName !== '' ? $firstName : null,
                'realname'  => $lastName !== '' ? $lastName : null,
                'authtype'  => Auth::EXTERNAL,
                'is_active' => 1,
                'comment'   => __('Criado via Login Único gov.br', 'govbrsso'),
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
            
            // NÃO passamos _profiles_id no add() — o GLPI 11 ignora e adiciona
            // o perfil padrão global (Self-Service) de qualquer forma. Vamos
            // gerenciar o perfil manualmente após a criação.
            // -----------------------------------

            $id = $user->add($input);
            if (!$id) {
                $glpiErrors = '';
                if (isset($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR])) {
                    $glpiErrors = implode(' ', $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR]);
                    unset($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR]);
                }
                $errMsg = __('Falha ao criar o usuário no GLPI.', 'govbrsso');
                if ($glpiErrors !== '') {
                    $errMsg .= ' ' . __('Detalhes:', 'govbrsso') . ' ' . $glpiErrors;
                }
                return ['ok' => false, 'error' => $errMsg];
            }
            
            // Remove TODOS os perfis auto-atribuídos pelo GLPI (ex: Self-Service)
            // e adiciona apenas o perfil configurado no plugin.
            if ($profile_id > 0) {
                $profUser = new \Profile_User();
                // Apaga qualquer perfil que o core tenha atribuído automaticamente
                $autoProfiles = $profUser->find(['users_id' => $id]);
                foreach ($autoProfiles as $ap) {
                    $profUser->delete(['id' => $ap['id']], true);
                }
                // Adiciona o perfil correto conforme regras do plugin
                $profUser->add([
                    'users_id'     => $id,
                    'profiles_id'  => $profile_id,
                    'entities_id'  => $entity_id,
                    'is_recursive' => 1
                ]);
            }
            
            $user->getFromDB($id);
        } elseif (!$found) {
            return ['ok' => false, 'error' => sprintf(__("Usuário '%s' não existe e a criação automática está desativada.", 'govbrsso'), $login)];
        }

        // 2) Login via auth externa (dispara o motor de regras e cria a sessão).
        return self::performExternalLogin($user, $primaryEmail, $levelPt);
    }

    /**
     * @return array{ok:bool,error:string}
     */
    private static function performExternalLogin(User $user, string $email, string $levelPt): array
    {
        $login = (string) $user->fields['name'];

        $auth                = new Auth();
        $auth->auth_succeded = true;
        $auth->user_present  = true;
        $auth->extauth       = 1;
        $auth->user          = $user;
        $auth->user->fields['authtype'] = Auth::EXTERNAL;

        $eventClass = \class_exists('\Glpi\Event') ? '\Glpi\Event' : (\class_exists('\Event') ? '\Event' : null);
        if ($eventClass) {
            $ip = getenv("HTTP_X_FORWARDED_FOR") ?: getenv("REMOTE_ADDR");
            $eventClass::log(
                $user->fields['id'],
                "system", 
                3, 
                "login", 
                sprintf(__('%1$s log in from IP %2$s'), $login, $ip) . " via Gov.BR nível {$levelPt} (SSO)"
            );
        }

        \Session::init($auth);
        
        $ok = (\Session::getLoginUserID() !== false);

        if (!$ok) {
            // Diagnóstico: usuário sem habilitação => falta regra.
            $hasProfile = countElementsInTable(
                (new Profile_User())->getTable(),
                ['users_id' => (int) $user->fields['id']],
            ) > 0;

            $msg = $hasProfile
                ? sprintf(__("Usuário '%s' não autorizado a conectar no GLPI.", 'govbrsso'), $login)
                : sprintf(__("Usuário '%s' sem habilitação. Crie uma Regra de atribuição de habilitações (Administração > Regras).", 'govbrsso'), $login);
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
