<?php

namespace GlpiPlugin\Govbrsso;

use Config as GlpiConfig;
use GLPIKey;
use Html;

/**
 * Configuração do plugin gov.br.
 *
 * Usa a API de configuração do GLPI (glpi_configs, contexto 'plugin:govbrsso'),
 * evitando tabelas próprias. O client_secret é guardado cifrado com a chave do
 * GLPI (GLPIKey).
 *
 * @license GPLv3+
 */
final class Config
{
    /** Contexto de configuração no GLPI. */
    public const CONTEXT = 'plugin:govbrsso';

    /** Nome da variável SSO dedicada criada na instalação. */
    public const SSO_VARIABLE_NAME = 'HTTP_GOVBRSSO_REMOTE_USER';

    /** Defaults de homologação. */
    private const DEFAULTS = [
        'provider_url'  => 'https://sso.staging.acesso.gov.br',
        'client_id'     => '',
        'client_secret' => '', // armazenado cifrado
        'scopes'        => 'openid email profile govbr_confiabilidades govbr_confiabilidades_idtoken',
        'login_field'   => 'cpf',   // 'cpf' (sub) ou 'email'
        'auto_create'   => '1',     // criar usuário no primeiro login
        'min_level'     => '',      // '', 'bronze', 'silver', 'gold' — barra abaixo deste nível
        'is_active'     => '0',
        'default_profile_id' => '0',
        'default_entity_id'  => '0',
        'domain_rules'       => '[]',
        'ext_api_url'        => '',
        'ext_api_key'        => '',
        'ext_api_active'     => '0',
    ];

    /** @return array<string,string> */
    public static function getAll(): array
    {
        $values = GlpiConfig::getConfigurationValues(self::CONTEXT);
        return array_merge(self::DEFAULTS, is_array($values) ? $values : []);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::getAll();
        return $all[$key] ?? $default;
    }

    /** client_secret decifrado (vazio se não definido). */
    public static function getClientSecret(): string
    {
        $enc = (string) self::get('client_secret', '');
        if ($enc === '') {
            return '';
        }
        if (class_exists(GLPIKey::class)) {
            try {
                $decrypted = (new GLPIKey())->decrypt($enc);
                if ($decrypted !== false && $decrypted !== null && $decrypted !== '') {
                    return (string) $decrypted;
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        return $enc;
    }

    /** ext_api_key decifrada (vazia se não definida). */
    public static function getExtApiKey(): string
    {
        $enc = (string) self::get('ext_api_key', '');
        if ($enc === '') {
            return '';
        }
        if (class_exists(GLPIKey::class)) {
            try {
                $decrypted = (new GLPIKey())->decrypt($enc);
                if ($decrypted !== false && $decrypted !== null && $decrypted !== '') {
                    return (string) $decrypted;
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        return $enc;
    }

    /**
     * Persiste a configuração. Cifra o secret quando um novo valor for enviado.
     *
     * @param array<string,string> $input
     */
    public static function save(array $input): void
    {
        $allowed = array_keys(self::DEFAULTS);
        $to_save = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $input)) {
                if (in_array($key, ['auto_create', 'is_active', 'ext_api_active'], true)) {
                    $value = '0';
                } else {
                    continue;
                }
            } else {
                $value = trim((string) $input[$key]);
            }

            if ($key === 'client_secret' || $key === 'ext_api_key') {
                if ($value === '') {
                    continue; // não sobrescreve o secret existente com vazio
                }
                if (class_exists(GLPIKey::class)) {
                    $value = (new GLPIKey())->encrypt($value);
                }
            }
            $to_save[$key] = $value;
        }

        // Processa regras de domínio dinâmicas
        $rules = [];
        if (isset($input['domain_rule_domain']) && is_array($input['domain_rule_domain'])) {
            foreach ($input['domain_rule_domain'] as $idx => $domain) {
                $domain = strtolower(trim((string)$domain));
                $domain = ltrim($domain, '@');
                if ($domain !== '') {
                    $rules[] = [
                        'domain'     => $domain,
                        'profile_id' => (int)($input['domain_rule_profile_id'][$idx] ?? 0),
                        'entity_id'  => (int)($input['domain_rule_entity_id'][$idx] ?? 0),
                    ];
                }
            }
        }
        $to_save['domain_rules'] = json_encode($rules);

        if ($to_save !== []) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, $to_save);
        }
    }

    public static function installDefaults(): void
    {
        $existing = GlpiConfig::getConfigurationValues(self::CONTEXT);
        if (!is_array($existing) || $existing === []) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, self::DEFAULTS);
        }
    }

    public static function removeAll(): void
    {
        GlpiConfig::deleteConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
    }

    public static function isActive(): bool
    {
        return self::get('is_active') === '1' && self::get('client_id') !== '';
    }

    // ---- Endpoints derivados do provider_url ----

    private static function base(): string
    {
        return rtrim((string) self::get('provider_url'), '/');
    }

    public static function authorizeUrl(): string
    {
        return self::base() . '/authorize';
    }

    public static function tokenUrl(): string
    {
        return self::base() . '/token';
    }

    public static function jwkUrl(): string
    {
        return self::base() . '/jwk';
    }

    public static function userinfoUrl(): string
    {
        return self::base() . '/userinfo/';
    }

    public static function logoutUrl(): string
    {
        return self::base() . '/logout';
    }

    /** URL de callback que deve ser cadastrada na credencial gov.br. */
    public static function callbackUrl(): string
    {
        global $CFG_GLPI;
        return rtrim($CFG_GLPI['url_base'], '/') . '/plugins/govbrsso/front/callback.php';
    }

    /** URL de início do fluxo (alvo do botão). */
    public static function startUrl(): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/redirect.php';
    }

    /** URL de logout do plugin (para cadastrar como "URL de Log Out"). */
    public static function pluginLogoutUrl(): string
    {
        global $CFG_GLPI;
        return rtrim($CFG_GLPI['url_base'], '/') . '/plugins/govbrsso/front/logout.php';
    }

    /** Botão "Entrar com gov.br" para a tela de login. */
    public static function renderLoginButton(): string
    {
        if (!self::isActive()) {
            return '';
        }
        $url = Html::cleanInputText(self::startUrl());

        $textOr = __('OU', 'govbrsso');
        $textAria = __('Entrar com gov.br', 'govbrsso');
        $textLogin = __('Entrar com', 'govbrsso');

        return <<<HTML
<div class="mt-3 text-center" id="govbrsso-login-wrapper">
  <div class="d-flex align-items-center my-2">
      <hr class="flex-grow-1 m-0">
      <span class="mx-3 text-secondary text-uppercase small">{$textOr}</span>
      <hr class="flex-grow-1 m-0">
  </div>
  <a href="{$url}" class="btn btn-outline-secondary w-100 mt-2 d-flex align-items-center justify-content-center" style="gap: 8px;" role="button" aria-label="{$textAria}">
    <span class="govbrsso-signin__text">{$textLogin}</span>
    <span class="govbrsso-signin__brand">
      <span style="color:#1351b4">g</span><span style="color:#fcc400">o</span><span style="color:#00a859">v</span><span style="color:#1351b4">.b</span><span style="color:#fcc400">r</span>
    </span>
  </a>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var wrapper = document.getElementById('govbrsso-login-wrapper');
    if (!wrapper) return;
    
    // Procura o conteiner principal do formulário de login no GLPI 10/11
    var target = document.querySelector('.col-md-5');
    if (!target) {
        // Fallbacks caso o tema tenha mudado
        target = document.querySelector('.login_card, .login-box, .card-body, form[action*="login.php"]');
    }
    
    if (target) {
        target.appendChild(wrapper);
    }
});
</script>
HTML;
    }
}
