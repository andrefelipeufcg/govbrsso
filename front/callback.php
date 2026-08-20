<?php

/**
 * Callback do Login Único gov.br.
 * Valida state, troca code por token, valida assinatura, lê claims e loga.
 * Roda sem sessão autenticada (liberado no boot via Firewall::STRATEGY_NO_CHECK).
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\Client;
use GlpiPlugin\Govbrsso\Config;
use GlpiPlugin\Govbrsso\UserManager;
use User;

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;

global $CFG_GLPI;


function displayFriendlyError($msg, $debug = null) {
    global $CFG_GLPI;
    $title = __('Erro de Autenticação', 'govbrsso');
    $backText = __('Voltar para o Login', 'govbrsso');
    
    Html::nullHeader($title, $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1');
    
    $params = [
        'title'     => $title,
        'msg'       => $msg,
        'back_url'  => $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1',
        'back_text' => $backText
    ];
    
    \Glpi\Application\View\TemplateRenderer::getInstance()->display('@govbrsso/error.html.twig', $params);
    
    Html::nullFooter();
    die();
}

if (!Config::isActive()) {
    displayFriendlyError(__('Login Único gov.br não está configurado/ativo.', 'govbrsso'));
}

// Erro retornado pelo provedor.
if (isset($_GET['error'])) {
    $desc = (string) ($_GET['error_description'] ?? $_GET['error']);
    Toolbox::logInFile('govbrsso', 'Erro do provedor: ' . $desc . "\n");
    displayFriendlyError(__('Falha na autenticação gov.br: ', 'govbrsso') . $_GET['error'] . ' - ' . ($_GET['error_description'] ?? ''));
}

$code  = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');

// Validação do state (anti-CSRF): tem que bater com o emitido na sessão.
$expectedState = (string) ($_SESSION['govbrsso_state'] ?? '');
if ($code === '' || $state === '' || !hash_equals($expectedState, $state)) {
    displayFriendlyError(__('Requisição de callback inválida (state/code).', 'govbrsso'));
}

$verifier = (string) ($_SESSION['govbrsso_code_verifier'] ?? '');
unset($_SESSION['govbrsso_state'], $_SESSION['govbrsso_code_verifier']);

$token = Client::requestToken(
    (string) Config::get('client_id'),
    Config::getClientSecret(),
    $code,
    Config::callbackUrl(),
    $verifier,
);

if (isset($token['error']) || empty($token['access_token'])) {
    $debug = [
        'client_id' => Config::get('client_id'),
        'client_secret_length' => strlen(Config::getClientSecret()),
        'redirect_uri' => Config::callbackUrl(),
        'code_verifier' => $verifier,
        'code_challenge_sent_in_auth' => Client::codeChallenge($verifier),
        'token_response' => $token,
    ];
    Toolbox::logInFile('govbrsso', "[TOKEN_ERROR] " . print_r($debug, true));
    displayFriendlyError(__('Erro ao obter token do gov.br', 'govbrsso'));
}

$accessToken = (string) $token['access_token'];
$idToken     = (string) ($token['id_token'] ?? '');

// Claims do id_token (validação de assinatura como defesa em profundidade).
$claims = [];
if ($idToken !== '') {
    if (!Client::verifySignature($idToken)) {
        Toolbox::logInFile('govbrsso', "Assinatura do id_token não validada (JWKS).\n");
        displayFriendlyError(__('Assinatura do id_token inválida.', 'govbrsso'));
    }
    $claims = Client::decodeJwtPayload($idToken);

    $expectedIss = rtrim((string) Config::get('provider_url'), '/');
    $iss = rtrim((string) ($claims['iss'] ?? ''), '/');
    if ($iss !== $expectedIss) {
        displayFriendlyError(__('Emissor (iss) do id_token inválido.', 'govbrsso'));
    }

    $expectedAud = (string) Config::get('client_id');
    $aud = $claims['aud'] ?? '';
    $auds = is_array($aud) ? $aud : [$aud];
    if (!in_array($expectedAud, $auds, true)) {
        displayFriendlyError(__('Audiência (aud) do id_token inválida.', 'govbrsso'));
    }

    // Validação do nonce.
    $expectedNonce = (string) ($_SESSION['govbrsso_nonce'] ?? '');
    if ($expectedNonce !== '' && ($claims['nonce'] ?? '') !== $expectedNonce) {
        unset($_SESSION['govbrsso_nonce']);
        displayFriendlyError(__('Nonce inválido no id_token.', 'govbrsso'), ['esperado' => $expectedNonce, 'recebido' => $claims['nonce'] ?? null]);
    }
}
unset($_SESSION['govbrsso_nonce']);

// Complementa com /userinfo (fonte autoritativa dos dados do cidadão).
$userinfo = Client::userinfo($accessToken);
$claims   = array_merge($claims, array_filter($userinfo, static fn ($v) => $v !== null && $v !== ''));

// --- Log estruturado de diagnóstico (todas as claims recebidas) ---
$cpfLog = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : 'N/A';
$claimsSafe = $claims;
unset($claimsSafe['picture']); // Remove foto (base64 gigante) do log
Toolbox::logInFile(
    'govbrsso',
    "[CLAIMS] CPF=$cpfLog | " . json_encode($claimsSafe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

// Integração com API Externa para múltiplos e-mails
$extApiActive = Config::get('ext_api_active', '0') === '1';
$extApiUrl = trim((string) Config::get('ext_api_url', ''));
$extApiKey = trim((string) Config::get('ext_api_key', ''));

if ($extApiActive && $extApiUrl !== '' && Config::get('login_field', 'cpf') === 'email') {
    $cpfToQuery = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
    if ($cpfToQuery !== '') {
        $ch = curl_init();
        $url = rtrim($extApiUrl, '/') . '/' . $cpfToQuery;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Força IPv4 para evitar timeouts de IPv6 quebrado
        if ($extApiKey !== '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Glpi-Api-Key: ' . $extApiKey]);
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);
        
        Toolbox::logInFile('govbrsso', "[EXT_API] URL=$url | HTTP=$httpCode | Time={$totalTime}s | Error=$error | Resp=" . substr((string)$response, 0, 200) . "\n");
        
        if ($httpCode === 200 && $response) {
            $extApiEmails = json_decode($response, true);
            if (is_array($extApiEmails) && count($extApiEmails) > 0) {
                // Verifica se o e-mail do Gov.br já não veio na API institucional
                $govbrEmail = isset($claims['email']) ? trim((string)$claims['email']) : '';
                $alreadyExists = false;
                foreach ($extApiEmails as $e) {
                    if (strcasecmp($e['email'], $govbrEmail) === 0) {
                        $alreadyExists = true;
                        break;
                    }
                }
                
                // Adiciona o e-mail pessoal (Gov.br) como uma opção extra, se for diferente
                if ($govbrEmail !== '' && !$alreadyExists) {
                    $extApiEmails[] = [
                        'email' => $govbrEmail,
                        'tipoVinculo' => 'Pessoal (Gov.br)',
                        'ativo' => true
                    ];
                }
                
                if (count($extApiEmails) > 1) {
                    $_SESSION['govbrsso_pending_claims'] = $claims;
                    $_SESSION['govbrsso_ext_api_emails'] = $extApiEmails;
                    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/email.php');
                } else {
                    $claims['email'] = $extApiEmails[0]['email'];
                    $claims['email_verified'] = true;
                }
            }
        } else {
            Toolbox::logInFile('govbrsso', "[EXT_API] FALHA na consulta (Server-Side). Iniciando fallback Client-Side...\n");
            
            $t = time();
            $apiKeyHash = hash('sha256', $extApiKey);
            $dataToSign = $cpfToQuery . $t;
            $signature = hash_hmac('sha256', $dataToSign, $apiKeyHash);
            
            $jsUrl = rtrim($extApiUrl, '/') . '/' . $cpfToQuery . '?t=' . $t . '&s=' . $signature;
            
            // Gera um token único para validar o redirect do client-side
            $csToken = bin2hex(random_bytes(16));
            $_SESSION['govbrsso_pending_claims'] = $claims;
            $_SESSION['govbrsso_cs_token'] = $csToken;
            
            $clientUrl = $CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/callback_client.php';
            $loginUrl = $CFG_GLPI['root_doc'] . '/index.php?noAUTO=1';
            
            echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Autenticando - Gov.br</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f6fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.08); padding: 48px 40px; max-width: 440px; width: 90%; text-align: center; }
  .spinner { width: 48px; height: 48px; border: 4px solid #e0e0e0; border-top-color: #1351b4; border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 24px; }
  @keyframes spin { to { transform: rotate(360deg); } }
  h2 { color: #1351b4; font-size: 20px; margin-bottom: 8px; }
  .status { color: #555; font-size: 14px; margin-bottom: 20px; }
  .progress-wrap { background: #e9ecef; border-radius: 8px; height: 6px; overflow: hidden; margin-bottom: 12px; }
  .progress-bar { height: 100%; background: linear-gradient(90deg, #1351b4, #2670d8); border-radius: 8px; width: 0%; transition: width .3s ease; }
  .timer { color: #888; font-size: 12px; margin-bottom: 4px; }
  .error-box { display: none; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; padding: 16px; margin-top: 20px; text-align: left; }
  .error-box h3 { color: #b91c1c; font-size: 15px; margin-bottom: 6px; }
  .error-box p { color: #7f1d1d; font-size: 13px; word-break: break-word; }
  .error-box .detail { color: #999; font-size: 11px; margin-top: 8px; font-family: monospace; }
  .btn { display: inline-block; margin-top: 16px; padding: 10px 28px; background: #1351b4; color: #fff; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; }
  .btn:hover { background: #0c3d8a; }
  .btn-outline { background: transparent; color: #1351b4; border: 1px solid #1351b4; margin-left: 8px; }
  .btn-outline:hover { background: #f0f4ff; }
  .hidden { display: none; }
</style>
</head>
<body>
<div class="card">
  <div id="loading-section">
    <div class="spinner" id="spinner"></div>
    <h2>Autenticando com o Gov.br</h2>
    <p class="status" id="status-text">Buscando seus vínculos institucionais...</p>
    <div class="progress-wrap"><div class="progress-bar" id="progress-bar"></div></div>
    <p class="timer" id="timer-text"></p>
  </div>
  <div class="error-box" id="error-box">
    <h3>⚠ Não foi possível buscar seus vínculos</h3>
    <p id="error-msg">Ocorreu um erro na comunicação com o servidor.</p>
    <p class="detail" id="error-detail"></p>
  </div>
  <div id="actions" class="hidden">
    <a class="btn" id="btn-continue" href="$clientUrl?t=$csToken">Continuar sem vínculos</a>
    <a class="btn btn-outline" href="$loginUrl">Voltar ao Login</a>
  </div>
</div>
<script>
(function() {
  var TIMEOUT = 5;
  var elapsed = 0;
  var bar = document.getElementById('progress-bar');
  var timerEl = document.getElementById('timer-text');
  var statusEl = document.getElementById('status-text');
  var done = false;

  var ticker = setInterval(function() {
    if (done) return;
    elapsed++;
    var pct = Math.min((elapsed / TIMEOUT) * 100, 100);
    bar.style.width = pct + '%';
    var remaining = Math.max(TIMEOUT - elapsed, 0);
    timerEl.textContent = remaining > 0 ? 'Aguardando... ' + remaining + 's' : '';
  }, 1000);

  function showError(msg, detail) {
    done = true;
    clearInterval(ticker);
    document.getElementById('spinner').style.display = 'none';
    statusEl.textContent = 'A requisição falhou.';
    bar.style.width = '100%';
    bar.style.background = '#f87171';
    timerEl.textContent = '';
    document.getElementById('error-box').style.display = 'block';
    document.getElementById('error-msg').textContent = msg;
    if (detail) document.getElementById('error-detail').textContent = detail;
    document.getElementById('actions').classList.remove('hidden');
  }

  function redirect(emailsJson) {
    done = true;
    clearInterval(ticker);
    bar.style.width = '100%';
    statusEl.textContent = 'Redirecionando...';
    var url = '$clientUrl?t=$csToken';
    if (emailsJson) url += '&emails=' + encodeURIComponent(emailsJson);
    window.location.href = url;
  }

  var controller = new AbortController();
  setTimeout(function() { controller.abort(); }, TIMEOUT * 1000);

  fetch('$jsUrl', { signal: controller.signal })
    .then(function(r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(function(data) {
      redirect(JSON.stringify(data));
    })
    .catch(function(e) {
      var msg, detail;
      if (e.name === 'AbortError') {
        msg = 'A requisição excedeu o tempo limite de ' + TIMEOUT + ' segundos.';
        detail = 'O servidor de vínculos pode estar indisponível ou há um bloqueio de rede.';
      } else if (e.message && (e.message.indexOf('Failed to fetch') !== -1 || e.message.indexOf('Mixed Content') !== -1 || e.message.indexOf('Network') !== -1)) {
        msg = 'Bloqueio de segurança do navegador (Mixed Content).';
        detail = 'O navegador bloqueou a requisição HTTP a partir de uma página HTTPS. É necessário que o servidor PSI suporte HTTPS ou que a comunicação seja liberada pela infra.';
      } else {
        msg = 'Erro ao buscar vínculos: ' + (e.message || String(e));
        detail = 'URL: $jsUrl';
      }
      console.error('govbrsso client-side fetch error:', e);
      showError(msg, detail);
    });
})();
</script>
</body>
</html>
HTML;
            die();
        }
    }
}

// Efetua o login no GLPI.
$result = UserManager::loginFromClaims($claims);

if (isset($result['consent_required']) && $result['consent_required']) {
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/consent.php');
}

if (!$result['ok']) {
    $cpf   = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
    $email = isset($claims['email']) ? trim((string) $claims['email']) : '';
    $emailLog = $email !== '' ? $email : 'não informado';
    Toolbox::logInFile('govbrsso', 'Login negado (CPF: ' . $cpf . ' / E-mail: ' . $emailLog . '): ' . $result['error'] . "\n");
    displayFriendlyError(htmlspecialchars($result['error']));
}

// Destino pós-login.
$dest = $CFG_GLPI['root_doc'] . '/index.php';
if (!empty($_SESSION['govbrsso_redirect'])) {
    $r = (string) $_SESSION['govbrsso_redirect'];
    unset($_SESSION['govbrsso_redirect']);
    if (str_starts_with($r, '/')) {
        $dest = $CFG_GLPI['root_doc'] . '/index.php?redirect=' . rawurlencode($r);
    }
}

Html::redirect($dest);
