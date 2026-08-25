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

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Govbrsso\Config;

$inc = __DIR__ . '/../../../inc/includes.php';
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/inc/includes.php'; }
if (!file_exists($inc)) { $inc = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../inc/includes.php'; }
include $inc;

global $CFG_GLPI;

Session::checkRight('config', UPDATE);

if (isset($_POST['purge_bak_logs'])) {
    $purged = 0;
    $logFiles = glob(GLPI_LOG_DIR . '/govbrsso.log*.bak');
    if ($logFiles !== false) {
        foreach ($logFiles as $logFile) {
            if (is_file($logFile) && is_writable($logFile)) {
                unlink($logFile);
                $purged++;
            }
        }
    }
    Session::addMessageAfterRedirect(
        sprintf(__('Foram apagados %d arquivos de log antigos (.bak).', 'govbrsso'), $purged)
    );
    Html::redirect($_SERVER['REQUEST_URI']);
}

Html::header('Gov.br SSO - Diagnóstico de Claims', $_SERVER['REQUEST_URI'], 'config', 'plugins');

$filterCpf = trim((string)($_GET['cpf'] ?? ''));
$filterCpf = preg_replace('/\D+/', '', $filterCpf);

$entries = [];

if ($filterCpf !== '') {
    $logFiles = glob(GLPI_LOG_DIR . '/govbrsso.log*');
    if ($logFiles !== false) {
        usort($logFiles, function($a, $b) {
            if ($a === $b) return 0;
            $baseA = basename($a);
            $baseB = basename($b);
            if ($baseA === 'govbrsso.log') return -1;
            if ($baseB === 'govbrsso.log') return 1;
            return strcmp($baseB, $baseA); // Ordem decrescente para os .bak
        });

        foreach ($logFiles as $logFile) {
            if (!is_readable($logFile)) {
                continue;
            }
            
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                $reversedLines = array_reverse($lines);
                $count = count($reversedLines);
                for ($i = 0; $i < $count; $i++) {
                    $line = $reversedLines[$i];
                    if (strpos($line, '[CLAIMS]') === false) {
                        continue;
                    }

                    // A data no GLPI fica na linha ANTERIOR no log original (ou seja, a PRÓXIMA linha no array reverso)
                    $timestamp = '';
                    if ($i + 1 < $count) {
                        $prevLine = $reversedLines[$i + 1];
                        // Formato do log do GLPI: "YYYY-MM-DD HH:MM:SS [@hostname]" ou similar
                        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?)/', $prevLine, $m)) {
                            $dt = date_create($m[1]);
                            if ($dt) {
                                $timestamp = date_format($dt, 'd-m-Y H:i:s');
                            } else {
                                $timestamp = $m[1];
                            }
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

                    // Limita a 50 entradas para não travar a interface
                    if (count($entries) >= 50) {
                        break 2;
                    }
                }
            }
        }
    }
}

$selfUrl = $CFG_GLPI['root_doc'] . '/plugins/govbrsso/front/claims.php';
$filterCpfSafe = htmlspecialchars($filterCpf);

$params = [
    'root_doc'      => $CFG_GLPI['root_doc'],
    'selfUrl'       => $selfUrl,
    'filterCpf'     => $filterCpf,
    'filterCpfSafe' => $filterCpfSafe,
    'entries'       => [],
    'total'         => count($entries),
];

if (!empty($entries)) {
    foreach ($entries as $i => &$entry) {
        $tsFallback = htmlspecialchars($entry['timestamp']);
        $cpf = htmlspecialchars($entry['cpf']);
        $nome = htmlspecialchars($entry['claims']['name'] ?? 'Sem Nome');

        // Usa a data do log em vez do auth_time do token (pois o auth_time pode ser o mesmo em vários logins se a sessão no provedor ainda for válida)
        $authTimeStr = $tsFallback;

        // Conta nível
        $levelCode = \GlpiPlugin\Govbrsso\UserManager::getLevel($entry['claims']);
        if ($levelCode === '') $levelCode = 'bronze'; // fallback

        $levelColors = [
            'gold'   => '#d4900a',
            'silver' => '#6b7b8a',
            'bronze' => '#cd7f32',
        ];
        $lvlColor = $levelColors[$levelCode] ?? '#cd7f32';

        $levelNames = ['gold' => __('ouro', 'govbrsso'), 'silver' => __('prata', 'govbrsso'), 'bronze' => __('bronze', 'govbrsso')];
        $levelPt = $levelNames[$levelCode] ?? __('bronze', 'govbrsso');

        // Monta a tabela de claims
        $accountText = __('conta', 'govbrsso');
        $claimsRows = "<tr><td style='padding: 6px 12px; border-bottom: 1px solid rgba(128,128,128,0.3); font-weight: 700; color: {$lvlColor}; background: rgba(0,0,0,0.05); white-space: nowrap; vertical-align: top;'>{$accountText}</td><td style='padding: 6px 12px; border-bottom: 1px solid rgba(128,128,128,0.3); font-weight: 700; color: {$lvlColor}; background: rgba(0,0,0,0.05); word-break: break-all;'>{$levelPt}</td></tr>";

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
                $valHtml = "<pre style='margin:0; font-size: 12px; white-space: pre-wrap; color: inherit;'>{$valSafe}</pre>";
            } else {
                $valSafe = htmlspecialchars((string)$value);
                $valHtml = $valSafe;
            }

            // Destaca claims relacionadas a e-mail
            $rowBg = '';
            if (stripos($key, 'email') !== false) {
                $rowBg = 'background-color: rgba(76, 175, 80, 0.1);';
            }

            $claimsRows .= "<tr style='{$rowBg}'><td style='padding: 6px 12px; border-bottom: 1px solid rgba(128,128,128,0.3); font-weight: 600; color: inherit; opacity: 0.8; white-space: nowrap; vertical-align: top;'>{$keySafe}</td><td style='padding: 6px 12px; border-bottom: 1px solid rgba(128,128,128,0.3); word-break: break-all;'>{$valHtml}</td></tr>";
        }

        $cpfFormatted = strlen($cpf) === 11
            ? substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2)
            : $cpf;

        $entry['authTimeStr'] = $authTimeStr;
        $entry['levelPt'] = $levelPt;
        $entry['lvlColor'] = $lvlColor;
        $entry['claimsRows'] = $claimsRows;
        $entry['cpfFormatted'] = $cpfFormatted;
        $entry['nome'] = $nome;
    }
    unset($entry);
    $params['entries'] = $entries;
}

TemplateRenderer::getInstance()->display('@govbrsso/claims.html.twig', $params);

Html::footer();
