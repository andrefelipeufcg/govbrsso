<?php
$cpf = '10884431762';
$extApiKey = 'b69450d7bcb9900eaadf022a2054e06b71b10ade399d23a043014422db7c88f8';
$t = time();
$apiKeyHash = hash('sha256', $extApiKey);
$s = hash_hmac('sha256', $cpf . $t, $apiKeyHash);

echo "\nCole o comando abaixo no terminal para testar a nova API:\n\n";
echo "curl -k 'https://psi.copy.sti.ufcg.edu.br/psi/PSI/pessoas/recuperarEmailsGsuitePorCpf/$cpf?t=$t&s=$s'\n\n";
