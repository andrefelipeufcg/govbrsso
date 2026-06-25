<?php

namespace GlpiPlugin\Govbr;

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
        $email = isset($claims['email']) ? trim((string) $claims['email']) : '';
        $emailVerified = ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? '') === 'true';
        $name  = trim((string) ($claims['name'] ?? ''));

        if ($cpf === '') {
            return ['ok' => false, 'error' => 'Claim "sub" (CPF) ausente no token gov.br.'];
        }

        // Verificação opcional de nível mínimo de confiabilidade.
        $minLevel = (string) Config::get('min_level', '');
        if ($minLevel !== '' && !self::meetsLevel($claims, $minLevel)) {
            return ['ok' => false, 'error' => "Conta gov.br não atinge o nível mínimo exigido ($minLevel)."];
        }

        $loginField = (string) Config::get('login_field', 'cpf');
        $login = $loginField === 'email'
            ? ($emailVerified && $email !== '' ? $email : $cpf)
            : $cpf;

        // 1) Garante o usuário no GLPI.
        $user = new User();
        $found = $user->getFromDBbyName($login);

        if (!$found && Config::get('auto_create') === '1') {
            $input = [
                'name'     => $login,
                'realname' => $name !== '' ? $name : null,
                'authtype' => Auth::EXTERNAL,
                'comment'  => 'Criado via Login Único gov.br',
            ];
            if ($emailVerified && $email !== '') {
                $input['_useremails'] = [$email];
            }
            $id = $user->add($input);
            if (!$id) {
                return ['ok' => false, 'error' => 'Falha ao criar o usuário no GLPI.'];
            }
            $user->getFromDB($id);
        } elseif (!$found) {
            return ['ok' => false, 'error' => "Usuário '$login' não existe e a criação automática está desativada."];
        }

        // 2) Login via auth externa (dispara o motor de regras e cria a sessão).
        return self::performExternalLogin($user, $emailVerified ? $email : '');
    }

    /**
     * @return array{ok:bool,error:string}
     */
    private static function performExternalLogin(User $user, string $email): array
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
            $CFG_GLPI['email1_ssofield'] = 'GOVBR_EMAIL';
            $_SERVER['GOVBR_EMAIL'] = $email;
        }

        try {
            $auth = new Auth();
            $ok = $auth->login($login, '', false);
        } finally {
            // Limpeza do contexto temporário (em qualquer caso).
            $CFG_GLPI['ssovariables_id'] = $origSso;
            unset($_SERVER[Config::SSO_VARIABLE_NAME]);
            $CFG_GLPI['email1_ssofield'] = $origEmailField;
            unset($_SERVER['GOVBR_EMAIL'], $_SESSION['glpi_remote_user']);
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

    /**
     * Verifica o nível de confiabilidade mínimo a partir de reliability_info.
     *
     * @param array<string,mixed> $claims
     */
    private static function meetsLevel(array $claims, string $min): bool
    {
        $order = ['bronze' => 1, 'silver' => 2, 'gold' => 3];
        $level = '';
        if (isset($claims['reliability_info']['level'])) {
            $level = strtolower((string) $claims['reliability_info']['level']);
        }
        if ($level === '' || !isset($order[$level], $order[$min])) {
            return false;
        }
        return $order[$level] >= $order[$min];
    }
}
