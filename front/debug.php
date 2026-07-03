<?php

/**
 * Página de diagnóstico do plugin gov.br SSO.
 * Exibe toda a configuração (sem expor o secret completo) e testa a
 * conectividade com os endpoints do provedor.
 *
 * Acesso restrito a quem tenha permissão de configuração (Super-Admin).
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\Client;
use GlpiPlugin\Govbrsso\Config;

include(__DIR__ . '/../../../inc/includes.php');

Session::checkRight('config', UPDATE);

// ---------- Coleta de dados ----------

$cfg = Config::getAll();
$secret = Config::getClientSecret();

$data = [];

// 1. Configuração atual
$data['Configuração'] = [
    'is_active'     => $cfg['is_active'] ?? '(não definido)',
    'provider_url'  => $cfg['provider_url'] ?? '(não definido)',
    'client_id'     => $cfg['client_id'] ?? '(não definido)',
    'client_secret' => $secret === '' ? '(VAZIO!)' : substr($secret, 0, 6) . '…' . substr($secret, -4) . ' (' . strlen($secret) . ' chars)',
    'scopes'        => $cfg['scopes'] ?? '(não definido)',
    'login_field'   => $cfg['login_field'] ?? '(não definido)',
    'auto_create'   => $cfg['auto_create'] ?? '(não definido)',
    'min_level'     => $cfg['min_level'] ?? '(não definido)',
];

// 2. URLs derivadas
$data['URLs Derivadas'] = [
    'authorize_url' => Config::authorizeUrl(),
    'token_url'     => Config::tokenUrl(),
    'jwk_url'       => Config::jwkUrl(),
    'userinfo_url'  => Config::userinfoUrl(),
    'callback_url'  => Config::callbackUrl(),
    'logout_url'    => Config::pluginLogoutUrl(),
];

// 3. GLPI url_base
global $CFG_GLPI;
$data['GLPI'] = [
    'url_base'  => $CFG_GLPI['url_base'] ?? '(não definido)',
    'root_doc'  => $CFG_GLPI['root_doc'] ?? '(não definido)',
    'version'   => GLPI_VERSION ?? '?',
    'php'       => PHP_VERSION,
    'curl'      => function_exists('curl_version') ? curl_version()['version'] : 'NÃO INSTALADO',
    'openssl'   => OPENSSL_VERSION_TEXT ?? '?',
];

// 4. URL que seria gerada pelo /authorize
$verifier  = Client::newCodeVerifier();
$challenge = Client::codeChallenge($verifier);
$authorizeUrl = Client::buildAuthorizeUrl(
    (string) ($cfg['client_id'] ?? ''),
    Config::callbackUrl(),
    (string) ($cfg['scopes'] ?? ''),
    'TEST_STATE',
    'TEST_NONCE',
    $challenge,
);
$data['URL Authorize (exemplo)'] = $authorizeUrl;

// 5. Teste de conectividade com o token endpoint (sem enviar dados reais)
$tokenUrl = Config::tokenUrl();
$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_NOBODY         => true,  // HEAD request
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$data['Teste de Conexão (token endpoint)'] = [
    'url'        => $tokenUrl,
    'http_code'  => $httpCode ?: '(sem resposta)',
    'curl_error' => $curlError ?: '(nenhum)',
];

// 6. Teste de conectividade com JWK
$jwkUrl = Config::jwkUrl();
$ch2 = curl_init($jwkUrl);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$jwkResp = curl_exec($ch2);
$jwkCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$jwkErr  = curl_error($ch2);
curl_close($ch2);

$data['Teste de Conexão (JWK)'] = [
    'url'        => $jwkUrl,
    'http_code'  => $jwkCode ?: '(sem resposta)',
    'curl_error' => $jwkErr ?: '(nenhum)',
    'resposta'   => $jwkResp ? substr($jwkResp, 0, 300) . '...' : '(vazia)',
];

// 7. Sessão govbrsso_*
$sessionKeys = ['govbrsso_state', 'govbrsso_nonce', 'govbrsso_code_verifier', 'govbrsso_redirect'];
$sessionData = [];
foreach ($sessionKeys as $sk) {
    $sessionData[$sk] = isset($_SESSION[$sk]) ? '(definido, ' . strlen($_SESSION[$sk]) . ' chars)' : '(não definido)';
}
$data['Sessão'] = $sessionData;

// ---------- Renderização ----------

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Gov.br SSO — Diagnóstico</title></head><body>";
echo "<div style='max-width:900px; margin:2rem auto; font-family:monospace;'>";
echo "<h1 style='color:#178abb;'>🔍 Diagnóstico do Plugin Gov.br SSO</h1>";
echo "<p style='color:#666;'>Gerado em: " . date('Y-m-d H:i:s T') . "</p>";
echo "<hr>";

foreach ($data as $section => $content) {
    echo "<h2 style='margin-top:1.5rem; color:#333;'>{$section}</h2>";
    if (is_string($content)) {
        echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ddd; word-break:break-all; white-space:pre-wrap;'>" . htmlspecialchars($content) . "</pre>";
    } elseif (is_array($content)) {
        echo "<table style='border-collapse:collapse; width:100%;'>";
        foreach ($content as $k => $v) {
            $display = is_array($v) ? print_r($v, true) : (string) $v;
            $color = ($display === '(VAZIO!)' || $display === '(não definido)' || $display === 'NÃO INSTALADO') ? 'color:red;font-weight:bold;' : '';
            echo "<tr style='border-bottom:1px solid #eee;'>";
            echo "<td style='padding:6px 10px; font-weight:bold; vertical-align:top; width:30%; background:#fafafa;'>" . htmlspecialchars($k) . "</td>";
            echo "<td style='padding:6px 10px; {$color} word-break:break-all;'><pre style='margin:0; white-space:pre-wrap;'>" . htmlspecialchars($display) . "</pre></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

echo "<hr>";
echo "<h2 style='color:#333;'>📋 Checklist de Problemas Comuns</h2>";
echo "<ul style='line-height:2;'>";

// Verifica client_id
if (empty($cfg['client_id'])) {
    echo "<li style='color:red;'>❌ <strong>client_id</strong> está vazio! Preencha na configuração do plugin.</li>";
} else {
    echo "<li style='color:green;'>✅ <strong>client_id</strong> configurado: " . htmlspecialchars($cfg['client_id']) . "</li>";
}

// Verifica secret
if ($secret === '') {
    echo "<li style='color:red;'>❌ <strong>client_secret</strong> está vazio! Preencha na configuração do plugin.</li>";
} else {
    echo "<li style='color:green;'>✅ <strong>client_secret</strong> configurado (" . strlen($secret) . " caracteres)</li>";
}

// Verifica scopes
$scopes = $cfg['scopes'] ?? '';
if (strpos($scopes, 'govbr_confiabilidades_idtoken') !== false) {
    echo "<li style='color:orange;'>⚠️ O escopo <strong>govbr_confiabilidades_idtoken</strong> está habilitado. Nem todas as credenciais têm esse escopo autorizado. Se o erro for 'invalid_grant' no /authorize, tente remover este escopo.</li>";
}

// Verifica provider_url
$providerUrl = $cfg['provider_url'] ?? '';
if (strpos($providerUrl, 'staging') !== false) {
    echo "<li style='color:blue;'>ℹ️ Ambiente de <strong>HOMOLOGAÇÃO</strong> (staging) detectado.</li>";
} elseif (strpos($providerUrl, 'acesso.gov.br') !== false) {
    echo "<li style='color:blue;'>ℹ️ Ambiente de <strong>PRODUÇÃO</strong> detectado.</li>";
}

// Verifica callback URL
$callbackUrl = Config::callbackUrl();
if (strpos($callbackUrl, 'http://') === 0) {
    echo "<li style='color:red;'>❌ A URL de callback usa <strong>HTTP</strong> em vez de <strong>HTTPS</strong>. O gov.br exige HTTPS.</li>";
} else {
    echo "<li style='color:green;'>✅ URL de callback usa HTTPS.</li>";
}

echo "</ul>";

echo "<hr>";
echo "<p style='color:#999; font-size:0.9em;'>⚠️ Esta página contém informações sensíveis. Remova o arquivo <code>front/debug.php</code> após o diagnóstico.</p>";
echo "</div></body></html>";
