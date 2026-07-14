<?php

/**
 * Tela de diagnóstico de claims do Gov.br.
 * Lê o arquivo de log do plugin e exibe as claims recebidas,
 * filtrável por CPF, para auxiliar na depuração de problemas
 * de login e criação duplicada de contas.
 *
 * Acesso restrito a Super-Admins.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Govbrsso\Config;

include(__DIR__ . '/../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight('config', UPDATE);

Html::header('Gov.br SSO - Diagnóstico de Claims', $_SERVER['REQUEST_URI'], 'config', 'plugins');

$filterCpf = trim((string)($_GET['cpf'] ?? ''));
$filterCpf = preg_replace('/\D+/', '', $filterCpf);

$logFile = GLPI_LOG_DIR . '/govbrsso.log';
$entries = [];

if ($filterCpf !== '' && file_exists($logFile) && is_readable($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        // Lê do final para o começo (mais recentes primeiro)
        foreach (array_reverse($lines) as $line) {
            if (strpos($line, '[CLAIMS]') === false) {
                continue;
            }

            // Extrai a data/hora do formato padrão do GLPI log
            $timestamp = '';
            if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2})(?::\d{2})?/', $line, $m)) {
                $dt = date_create($m[1]);
                if ($dt) {
                    $timestamp = date_format($dt, 'd-m-Y H:i');
                } else {
                    $timestamp = $m[1];
                }
            }

            // Extrai o CPF
            $cpf = '';
            if (preg_match('/CPF=(\d+)/', $line, $m)) {
                $cpf = $m[1];
            }

            if ($cpf !== $filterCpf) {
                continue;
            }

            // Extrai o JSON das claims
            $claims = [];
            $jsonStart = strpos($line, '| {');
            if ($jsonStart !== false) {
                $jsonStr = substr($line, $jsonStart + 2);
                $decoded = json_decode($jsonStr, true);
                if (is_array($decoded)) {
                    $claims = $decoded;
                }
            }

            $entries[] = [
                'timestamp' => $timestamp,
                'cpf'       => $cpf,
                'claims'    => $claims,
            ];

            // Limita a 100 entradas para não travar a interface
            if (count($entries) >= 100) {
                break;
            }
        }
    }
}

$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/debug_claims.php';
$filterCpfSafe = htmlspecialchars($filterCpf);

echo <<<HTML
<div style="max-width: 1100px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; color: #1351b4;">
            <span style="font-size: 24px;">🔍</span> Diagnóstico de Claims Gov.br
        </h2>
        <span style="background: #fff3cd; color: #856404; padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: 500;">
            ⚠️ Acesso restrito a Super-Admins
        </span>
    </div>

    <div style="background: #e8f0fe; border: 1px solid #b3d4fc; border-radius: 6px; padding: 16px; margin-bottom: 20px; font-size: 14px; color: #1a3e72; line-height: 1.6;">
        <strong>Como funciona:</strong> Cada vez que um usuário faz login via Gov.br, todas as informações (claims) 
        recebidas do provedor são gravadas no log. Digite o CPF abaixo para buscar os registros de login 
        e investigar problemas de criação duplicada de contas ou divergência de e-mails.
    </div>

    <form method="get" action="{$selfUrl}" style="display: flex; gap: 10px; margin-bottom: 25px; align-items: center;">
        <label for="cpf" style="font-weight: 600; color: #333; white-space: nowrap;">Buscar por CPF:</label>
        <input type="text" id="cpf" name="cpf" value="{$filterCpfSafe}" placeholder="000.000.000-00" 
               maxlength="14" inputmode="numeric"
               style="flex: 1; max-width: 300px; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; font-family: monospace;">
        <button type="submit" style="padding: 8px 20px; background: #1351b4; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 14px;">
            Buscar
        </button>
    </form>

    <script>
    (function() {
        var cpfInput = document.getElementById('cpf');

        function maskCpf(v) {
            v = v.replace(/\D/g, '').substring(0, 11);
            if (v.length > 9) {
                v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
            } else if (v.length > 6) {
                v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
            } else if (v.length > 3) {
                v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
            }
            return v;
        }

        // Formata o valor inicial (caso venha da URL)
        cpfInput.value = maskCpf(cpfInput.value);

        cpfInput.addEventListener('input', function() {
            var pos = this.selectionStart;
            var oldLen = this.value.length;
            this.value = maskCpf(this.value);
            var newLen = this.value.length;
            pos += (newLen - oldLen);
            this.setSelectionRange(pos, pos);
        });

        cpfInput.addEventListener('keypress', function(e) {
            if (!/\d/.test(e.key) && e.key !== 'Enter') {
                e.preventDefault();
            }
        });
    })();
    </script>
HTML;


if ($filterCpf === '') {
    echo '<div style="text-align: center; padding: 60px 20px; color: #999;"><span style="font-size: 48px; display: block; margin-bottom: 15px;">🔎</span><p style="font-size: 15px; margin: 0;">Digite um CPF acima para consultar os dados recebidos do Gov.br.</p></div>';
} elseif (empty($entries)) {
    echo "<div style='text-align: center; padding: 40px; color: #888;'>Nenhum registro encontrado para o CPF <strong>{$filterCpfSafe}</strong>.</div>";
} else {
    $total = count($entries);
    echo "<p style='color: #666; font-size: 13px; margin-bottom: 15px;'><strong>{$total}</strong> registro(s) encontrado(s) (mais recentes primeiro):</p>";

    foreach ($entries as $i => $entry) {
        $tsFallback = htmlspecialchars($entry['timestamp']);
        $cpf = htmlspecialchars($entry['cpf']);

        $nome = htmlspecialchars($entry['claims']['name'] ?? 'Sem Nome');

        // Pega o auth_time das claims para a data (unix timestamp)
        $authTimeStr = $tsFallback;
        if (isset($entry['claims']['auth_time']) && is_numeric($entry['claims']['auth_time'])) {
            $authTimeStr = date('d-m-Y H:i:s', (int)$entry['claims']['auth_time']);
        }

        // Conta nível
        $levelCode = \GlpiPlugin\Govbrsso\UserManager::getLevel($entry['claims']);
        if ($levelCode === '') $levelCode = 'bronze'; // fallback

        $levelColors = [
            'gold'   => '#d4900a',
            'silver' => '#6b7b8a',
            'bronze' => '#cd7f32',
        ];
        $lvlColor = $levelColors[$levelCode] ?? '#cd7f32';

        $levelNames = ['gold' => 'ouro', 'silver' => 'prata', 'bronze' => 'bronze'];
        $levelPt = $levelNames[$levelCode] ?? 'bronze';

        // Monta a tabela de claims
        $claimsRows = "<tr><td style='padding: 6px 12px; border-bottom: 1px solid #eee; font-weight: 700; color: {$lvlColor}; background: #ffffff; white-space: nowrap; vertical-align: top;'>conta</td><td style='padding: 6px 12px; border-bottom: 1px solid #eee; font-weight: 700; color: {$lvlColor}; background: #ffffff; word-break: break-all;'>{$levelPt}</td></tr>";

        $importantKeys = ['sub', 'name', 'social_name', 'email', 'email_verified', 'amr', 'profile', 'kid', 'iss', 'preferred_username', 'nonce', 'aud', 'auth_time', 'scope'];
        $orderedClaims = [];
        foreach ($importantKeys as $ik) {
            if (isset($entry['claims'][$ik])) {
                $orderedClaims[$ik] = $entry['claims'][$ik];
            }
        }
        foreach ($entry['claims'] as $k => $v) {
            if (!isset($orderedClaims[$k])) {
                $orderedClaims[$k] = $v;
            }
        }

        foreach ($orderedClaims as $key => $value) {
            $keySafe = htmlspecialchars((string)$key);
            if (is_array($value)) {
                $valSafe = htmlspecialchars(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $valHtml = "<pre style='margin:0; font-size: 12px; white-space: pre-wrap;'>{$valSafe}</pre>";
            } else {
                $valSafe = htmlspecialchars((string)$value);
                $valHtml = $valSafe;
            }

            // Destaca claims relacionadas a e-mail
            $rowBg = '';
            if (stripos($key, 'email') !== false) {
                $rowBg = 'background-color: #e8f5e9;';
            }

            $claimsRows .= "<tr style='{$rowBg}'><td style='padding: 6px 12px; border-bottom: 1px solid #eee; font-weight: 600; color: #555; white-space: nowrap; vertical-align: top;'>{$keySafe}</td><td style='padding: 6px 12px; border-bottom: 1px solid #eee; word-break: break-all;'>{$valHtml}</td></tr>";
        }

        $cpfFormatted = strlen($cpf) === 11
            ? substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2)
            : $cpf;

        echo <<<CARD
        <details style="margin-bottom: 10px; border: 1px solid #ddd; border-radius: 6px; overflow: hidden;">
            <summary style="padding: 12px 16px; background: #f8f9fa; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 12px;">
                <span style="background: {$lvlColor}; color: white; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">{$authTimeStr}</span>
                <span style="font-weight: 600;">CPF: {$cpfFormatted}</span>
                <span style="color: #888; font-size: 12px;">({$nome})</span>
            </summary>
            <div style="padding: 12px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="background: #f1f3f5;">
                            <th style="padding: 8px 12px; text-align: left; border-bottom: 2px solid #dee2e6; width: 200px;">Claim</th>
                            <th style="padding: 8px 12px; text-align: left; border-bottom: 2px solid #dee2e6;">Valor</th>
                        </tr>

                    </thead>
                    <tbody>
                        {$claimsRows}
                    </tbody>
                </table>
            </div>
        </details>
CARD;
    }
}

echo '</div>';

Html::footer();
