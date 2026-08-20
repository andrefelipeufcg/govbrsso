<?php

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
if (file_exists($inc)) {
    include $inc;
}

// Vamos simular a chamada que o callback.php faz para a API do PSI
$cpf = '10884431762'; // CPF de teste (pode trocar aqui)
$url = 'https://psi.copy.sti.ufcg.edu.br/psi/PSI/pessoas/recuperarEmailsGsuitePorCpf/' . $cpf;
$extApiKey = 'b69450d7bcb9900eaadf022a2054e06b71b10ade399d23a043014422db7c88f8'; // Chave de teste

echo "<h1>Debug da API GSuite (GovBr SSO)</h1>";
echo "<b>URL:</b> $url <br>";
echo "<b>Chave:</b> $extApiKey <br><br>";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
if ($extApiKey !== '') {
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Glpi-Api-Key: ' . $extApiKey]);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "<b>HTTP Code:</b> $httpCode <br>";
echo "<b>cURL Error:</b> " . ($error ? $error : "Nenhum") . "<br>";
echo "<b>Raw Response:</b> <pre>" . htmlentities($response) . "</pre><br>";

if ($httpCode === 200 && $response) {
    $extApiEmails = json_decode($response, true);
    echo "<b>JSON Decoded (is_array = " . (is_array($extApiEmails) ? 'Sim' : 'Nao') . "):</b> <pre>";
    print_r($extApiEmails);
    echo "</pre>";

    if (is_array($extApiEmails) && count($extApiEmails) > 0) {
        $govbrEmail = 'teste_govbr@gmail.com'; // E-mail falso do gov.br
        $alreadyExists = false;
        foreach ($extApiEmails as $e) {
            if (strcasecmp($e['email'], $govbrEmail) === 0) {
                $alreadyExists = true;
                break;
            }
        }

        if ($govbrEmail !== '' && !$alreadyExists) {
            $extApiEmails[] = [
                'email' => $govbrEmail,
                'tipoVinculo' => 'Pessoal (Gov.br)',
                'ativo' => true
            ];
        }

        echo "<b>Array Final (com GovBr):</b> <pre>";
        print_r($extApiEmails);
        echo "</pre>";
        
        if (count($extApiEmails) > 1) {
            echo "<h2 style='color: green;'>SUCESSO! O plugin redirecionaria para a tela de escolha (email.php).</h2>";
        } else {
            echo "<h2 style='color: orange;'>O plugin seguiria direto com 1 email.</h2>";
        }
    } else {
        echo "<h2 style='color: red;'>FALHA: A API nao retornou um array valido de emails!</h2>";
    }
} else {
    echo "<h2 style='color: red;'>FALHA: O request falhou ou o HTTP Code nao e 200!</h2>";
}

?>
