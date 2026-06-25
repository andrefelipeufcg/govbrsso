<?php

namespace GlpiPlugin\Govbrsso;

/**
 * Cliente OIDC do Login Único gov.br (Authorization Code + PKCE S256).
 *
 * Cobre: montagem da URL de authorize, troca code->token (Basic auth no header
 * + code_verifier no corpo), validação de assinatura via JWKS e leitura de
 * claims. Sem dependências externas (usa curl/openssl).
 *
 * @license GPLv3+
 */
final class Client
{
    // ---------- PKCE ----------

    /** code_verifier conforme RFC 7636 (43–128 chars). */
    public static function newCodeVerifier(int $bytes = 32): string
    {
        if ($bytes < 32) {
            $bytes = 32;
        }
        return self::b64url(random_bytes($bytes));
    }

    /** code_challenge = BASE64URL( SHA256( code_verifier ) ). */
    public static function codeChallenge(string $verifier): string
    {
        return self::b64url(hash('sha256', $verifier, true));
    }

    public static function randomToken(int $bytes = 16): string
    {
        return bin2hex(random_bytes($bytes));
    }

    // ---------- Authorize ----------

    /**
     * Monta a URL do /authorize. Os escopos vão separados por '+' (padrão gov.br).
     */
    public static function buildAuthorizeUrl(
        string $clientId,
        string $redirectUri,
        string $scopes,
        string $state,
        string $nonce,
        string $codeChallenge,
    ): string {
        $scopePlus = implode('+', preg_split('/\s+/', trim($scopes)) ?: []);

        $params = [
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'nonce'                 => $nonce,
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        // scope montado à parte para preservar os '+' entre escopos.
        $query = 'scope=' . $scopePlus;
        foreach ($params as $k => $v) {
            $query .= '&' . $k . '=' . rawurlencode($v);
        }

        return Config::authorizeUrl() . '?' . $query;
    }

    // ---------- Token ----------

    /**
     * Troca o authorization code por tokens.
     *
     * @return array{access_token?:string,id_token?:string,token_type?:string,expires_in?:int,scope?:string,error?:string,error_description?:string}
     */
    public static function requestToken(
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri,
        string $codeVerifier,
    ): array {
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ], '', '&', PHP_QUERY_RFC3986);

        $basic = base64_encode($clientId . ':' . $clientSecret);

        [$status, $resp] = self::http('POST', Config::tokenUrl(), $body, [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $basic,
            'Accept: application/json',
        ]);

        $data = json_decode((string) $resp, true);
        if (!is_array($data)) {
            return ['error' => 'invalid_response', 'error_description' => "HTTP $status: " . substr((string) $resp, 0, 300)];
        }
        return $data;
    }

    // ---------- Userinfo ----------

    /**
     * Chama /userinfo/ com Bearer access_token.
     *
     * @return array<string,mixed>
     */
    public static function userinfo(string $accessToken): array
    {
        [, $resp] = self::http('GET', Config::userinfoUrl(), null, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
        $data = json_decode((string) $resp, true);
        return is_array($data) ? $data : [];
    }

    // ---------- JWT / JWKS ----------

    /**
     * Decodifica o payload de um JWT (sem validar assinatura).
     *
     * @return array<string,mixed>
     */
    public static function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }
        $payload = json_decode((string) self::b64urlDecode($parts[1]), true);
        return is_array($payload) ? $payload : [];
    }

    /**
     * Valida a assinatura RS256 de um JWT contra a JWKS do gov.br.
     * Retorna true se válida; false caso contrário (ou se não houver chave).
     */
    public static function verifySignature(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        [$h, $p, $s] = $parts;

        $header = json_decode((string) self::b64urlDecode($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256') {
            return false;
        }
        $kid = $header['kid'] ?? null;

        $pem = self::pemForKid($kid);
        if ($pem === null) {
            return false;
        }

        $ok = openssl_verify(
            $h . '.' . $p,
            self::b64urlDecode($s),
            $pem,
            OPENSSL_ALGO_SHA256,
        );
        return $ok === 1;
    }

    /** Busca a JWKS e converte a chave (kid) para PEM. */
    private static function pemForKid(?string $kid): ?string
    {
        [, $resp] = self::http('GET', Config::jwkUrl(), null, ['Accept: application/json']);
        $jwks = json_decode((string) $resp, true);
        if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            return null;
        }

        foreach ($jwks['keys'] as $key) {
            if (!is_array($key) || ($key['kty'] ?? '') !== 'RSA') {
                continue;
            }
            if ($kid !== null && ($key['kid'] ?? null) !== $kid) {
                continue;
            }
            $pem = self::rsaJwkToPem((string) ($key['n'] ?? ''), (string) ($key['e'] ?? ''));
            if ($pem !== null) {
                return $pem;
            }
        }
        return null;
    }

    /** Converte componentes RSA (n,e) de uma JWK em chave pública PEM. */
    private static function rsaJwkToPem(string $n64, string $e64): ?string
    {
        $n = self::b64urlDecode($n64);
        $e = self::b64urlDecode($e64);
        if ($n === '' || $e === '') {
            return null;
        }

        $components = [
            'modulus'        => $n,
            'publicExponent' => $e,
        ];

        // Monta SubjectPublicKeyInfo (DER) na mão e embala em PEM.
        $modulus  = self::derInteger($components['modulus']);
        $exponent = self::derInteger($components['publicExponent']);
        $rsaPubKey = self::derSequence($modulus . $exponent);

        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL
        $algId = self::derSequence(
            self::derOid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00",
        );
        $bitString = "\x03" . self::derLen(strlen($rsaPubKey) + 1) . "\x00" . $rsaPubKey;
        $spki = self::derSequence($algId . $bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    // ---------- DER helpers ----------

    private static function derLen(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xff) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derInteger(string $bytes): string
    {
        // Garante inteiro positivo (prefixo 0x00 se MSB setado).
        if ($bytes !== '' && (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLen(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $bytes): string
    {
        return "\x30" . self::derLen(strlen($bytes)) . $bytes;
    }

    private static function derOid(string $oidBytes): string
    {
        return "\x06" . self::derLen(strlen($oidBytes)) . $oidBytes;
    }

    // ---------- base64url ----------

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    // ---------- HTTP ----------

    /**
     * @param array<int,string> $headers
     * @return array{0:int,1:string} [status, body]
     */
    private static function http(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, is_string($resp) ? $resp : ''];
    }
}
