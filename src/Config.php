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
                return (string) (new GLPIKey())->decrypt($enc);
            } catch (\Throwable) {
                return '';
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
                continue;
            }
            $value = trim((string) $input[$key]);

            if ($key === 'client_secret') {
                if ($value === '') {
                    continue; // não sobrescreve o secret existente com vazio
                }
                if (class_exists(GLPIKey::class)) {
                    $value = (new GLPIKey())->encrypt($value);
                }
            }
            $to_save[$key] = $value;
        }

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
        return $CFG_GLPI['url_base'] . '/plugins/govbrsso/front/callback.php';
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
        return $CFG_GLPI['url_base'] . '/plugins/govbrsso/front/logout.php';
    }

    /** Botão "Entrar com gov.br" para a tela de login. */
    public static function renderLoginButton(): string
    {
        if (!self::isActive()) {
            return '';
        }
        $url = Html::cleanInputText(self::startUrl());

        return <<<HTML
<div class="govbrsso-login-wrapper" id="govbrsso-login-wrapper">
  <a href="{$url}" class="govbrsso-signin" role="button" aria-label="Entrar com gov.br">
    <span class="govbrsso-signin__text">Entrar com</span>
    <span class="govbrsso-signin__brand">gov.br</span>
  </a>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var wrapper = document.getElementById('govbrsso-login-wrapper');
    if (!wrapper) return;
    
    // Procura por links de oauth, botões com logo do google, ou o formulário
    var googleBtn = document.querySelector('a[href*="oauth" i], a.oauth-button, a[href*="Google" i]');
    var form = document.querySelector('form[action*="login.php"], form.login-form');
    
    if (googleBtn) {
        var container = googleBtn.closest('div');
        if (container && container.parentNode) {
            container.parentNode.appendChild(wrapper);
        }
    } else if (form) {
        form.appendChild(wrapper);
    } else {
        var loginCard = document.querySelector('.login_card, .login-box, .card-body');
        if (loginCard) {
            loginCard.appendChild(wrapper);
        }
    }
});
</script>
HTML;
    }
}
