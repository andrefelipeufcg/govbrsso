<?php
$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
if (file_exists($inc)) {
    include $inc;
}

global $CFG_GLPI;

echo "<h1>Configurações de Proxy do GLPI</h1>";
echo "<pre>";
echo "Proxy Name: " . (isset($CFG_GLPI['proxy_name']) ? $CFG_GLPI['proxy_name'] : 'N/A') . "\n";
echo "Proxy Port: " . (isset($CFG_GLPI['proxy_port']) ? $CFG_GLPI['proxy_port'] : 'N/A') . "\n";
echo "Proxy User: " . (isset($CFG_GLPI['proxy_user']) ? $CFG_GLPI['proxy_user'] : 'N/A') . "\n";
echo "</pre>";

// Tentar bater no PSI usando o proxy do GLPI, se houver
if (!empty($CFG_GLPI['proxy_name'])) {
    $url = 'http://psi.copy.sti.ufcg.edu.br/psi/PSI/pessoas/recuperarEmailsGsuitePorCpf/10884431762';
    $extApiKey = 'b69450d7bcb9900eaadf022a2054e06b71b10ade399d23a043014422db7c88f8';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    // Configura o proxy do GLPI
    curl_setopt($ch, CURLOPT_PROXY, $CFG_GLPI['proxy_name']);
    curl_setopt($ch, CURLOPT_PROXYPORT, $CFG_GLPI['proxy_port']);
    if (!empty($CFG_GLPI['proxy_user'])) {
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, $CFG_GLPI['proxy_user'] . ':' . Toolbox::decrypt($CFG_GLPI['proxy_passwd'], GLPIKEY));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Glpi-Api-Key: ' . $extApiKey]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    echo "<h3>Teste com Proxy:</h3>";
    echo "<b>HTTP Code:</b> $httpCode <br>";
    echo "<b>cURL Error:</b> " . ($error ? $error : "Nenhum") . "<br>";
    echo "<b>Response:</b> " . htmlentities($response) . "<br>";
} else {
    echo "<h3>O GLPI nao possui proxy configurado.</h3>";
}
?>
