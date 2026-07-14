<?php

namespace GlpiPlugin\Govbrsso\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Testes de segurança para a lógica de matching de domínios no gov.br SSO.
 *
 * Verifica proteção contra os principais vetores de ataque em sistemas
 * de autenticação baseados em domínio de email:
 *
 * - Domain suffix spoofing (evil-ufcg.edu.br passa como ufcg.edu.br)
 * - Double-@ injection (user@gmail.com@ufcg.edu.br)
 * - Null byte injection (user@ufcg.edu.br\0@gmail.com)
 * - Unicode/homograph attacks (caracteres visuais parecidos)
 * - Whitespace injection (espaços escondidos no domínio)
 * - Input malformado e edge cases
 *
 * Para rodar no servidor de homologação:
 *   sudo php phpunit plugins/govbrsso/tests/UserManagerSecurityTest.php --testdox
 */
class UserManagerSecurityTest extends TestCase
{
    /**
     * Replica a lógica de matching de domínio do UserManager::loginFromClaims().
     */
    private function resolveProfile(string $email, array $config): array
    {
        $domain = '';
        if ($email !== '') {
            $domain = strtolower(substr(strrchr($email, '@'), 1));
        }

        $profile_id = 0;
        $entity_id  = 0;

        foreach ($config['domain_rules'] as $rule) {
            // Matching seguro: exige igualdade exata OU que o domínio
            // seja um subdomínio real (separado por ponto).
            if ($domain === $rule['domain'] || str_ends_with($domain, '.' . $rule['domain'])) {
                $profile_id = $rule['profile_id'];
                $entity_id  = $rule['entity_id'];
                break;
            }
        }

        if ($profile_id === 0) {
            $profile_id = $config['default_profile_id'];
            $entity_id  = $config['default_entity_id'];
        }

        return [$profile_id, $entity_id];
    }

    private function getMockConfig(): array
    {
        return [
            'default_profile_id' => 1,
            'default_entity_id'  => 0,
            'domain_rules' => [
                ['domain' => 'professor.ufcg.edu.br', 'profile_id' => 4, 'entity_id' => 0],
                ['domain' => 'tecnico.ufcg.edu.br',   'profile_id' => 3, 'entity_id' => 0],
                ['domain' => 'estudante.ufcg.edu.br',  'profile_id' => 2, 'entity_id' => 0],
                ['domain' => 'ufcg.edu.br',            'profile_id' => 5, 'entity_id' => 0],
            ],
        ];
    }

    // ===================================================================
    // 1. DOMAIN SUFFIX SPOOFING
    // ===================================================================

    public function testSuffixSpoofingDominioBase(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('hacker@evil-ufcg.edu.br', $config);
        $this->assertEquals(1, $profile,
            'VULNERABILIDADE: evil-ufcg.edu.br NÃO deve receber perfil UFCG (5). Deve cair no padrão (1).');
    }

    public function testSuffixSpoofingNotUfcg(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('hacker@notufcg.edu.br', $config);
        $this->assertEquals(1, $profile,
            'VULNERABILIDADE: notufcg.edu.br NÃO deve receber perfil UFCG.');
    }

    public function testSuffixSpoofingSubdominioProfessor(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('hacker@fake-professor.ufcg.edu.br', $config);
        $this->assertEquals(5, $profile,
            'fake-professor.ufcg.edu.br deve cair na regra genérica de ufcg.edu.br, não na de professor.');
    }

    public function testSuffixSpoofingDominioTecnico(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('hacker@evil-tecnico.ufcg.edu.br', $config);
        $this->assertEquals(5, $profile,
            'evil-tecnico.ufcg.edu.br deve cair na regra genérica de ufcg.edu.br, não na de técnico.');
    }

    // ===================================================================
    // 2. DOUBLE-@ INJECTION
    // ===================================================================

    public function testDoubleAtInjection(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('user@gmail.com@ufcg.edu.br', $config);
        $this->assertEquals(5, $profile,
            'Double-@ extrai o último domínio. O gov.br previne isso na origem.');
    }

    public function testDoubleAtSpoofingProfessor(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('user@gmail.com@professor.ufcg.edu.br', $config);
        $this->assertEquals(4, $profile,
            'Double-@ extrai o último domínio (professor.ufcg.edu.br). gov.br previne na origem.');
    }

    // ===================================================================
    // 3. WHITESPACE INJECTION
    // ===================================================================

    public function testEspacoNoDominio(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('user@ ufcg.edu.br', $config);
        $this->assertEquals(1, $profile,
            'Espaço no domínio deve invalidar o matching — cair no perfil padrão.');
    }

    public function testEspacoNoFinal(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('user@ufcg.edu.br ', $config);
        $this->assertEquals(1, $profile,
            'Espaço no final do domínio deve invalidar o matching.');
    }

    public function testTabNoDominio(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile("user@\tufcg.edu.br", $config);
        $this->assertEquals(1, $profile,
            'Tab no domínio deve invalidar o matching.');
    }

    // ===================================================================
    // 4. NULL BYTE INJECTION
    // ===================================================================

    public function testNullByteNoDominio(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile("user@ufcg.edu.br\0@gmail.com", $config);
        $this->assertNotEquals(5, $profile,
            'Null byte injection não deve conceder acesso ao domínio UFCG.');
    }

    // ===================================================================
    // 5. UNICODE / HOMOGRAPH ATTACKS
    // ===================================================================

    public function testHomographCirilico(): void
    {
        $config = $this->getMockConfig();
        $emailFalso = "user@ufcg.edu.b" . "\xD1\x80"; // "р" cirílico
        [$profile, ] = $this->resolveProfile($emailFalso, $config);
        $this->assertEquals(1, $profile,
            'Caracteres Unicode/cirílicos similares NÃO devem dar match no domínio.');
    }

    // ===================================================================
    // 6. INPUTS MALFORMADOS E EDGE CASES
    // ===================================================================

    public function testEmailSemDominio(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('user@', $config);
        $this->assertEquals(1, $profile,
            'Email sem domínio deve cair no perfil padrão.');
    }

    public function testApenasArroba(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('@', $config);
        $this->assertEquals(1, $profile,
            'Apenas "@" deve cair no perfil padrão.');
    }

    public function testEmailVazio(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('', $config);
        $this->assertEquals(1, $profile,
            'Email vazio deve cair no perfil padrão, sem conceder acesso especial.');
    }

    public function testDominioMuitoLongo(): void
    {
        $config = $this->getMockConfig();
        $dominioLongo = str_repeat('a', 500) . '@ufcg.edu.br';
        [$profile, ] = $this->resolveProfile($dominioLongo, $config);
        $this->assertEquals(5, $profile,
            'Domínio com local-part muito longo deve ser tratado normalmente.');
    }

    // ===================================================================
    // 7. PATH TRAVERSAL / SPECIAL CHARACTERS
    // ===================================================================

    public function testCaracteresEspeciaisNoEmail(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile("user'OR'1'='1@ufcg.edu.br", $config);
        $this->assertEquals(5, $profile,
            'SQL injection no local-part não deve afetar o matching de domínio.');
    }

    public function testHTMLInjectionNoEmail(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('<script>alert(1)</script>@ufcg.edu.br', $config);
        $this->assertEquals(5, $profile,
            'HTML/XSS no local-part não deve afetar o matching de domínio.');
    }

    // ===================================================================
    // 8. ORDEM DAS REGRAS (PRIORITY BYPASS)
    // ===================================================================

    public function testOrdemRegrasMaisEspecificaPrimeiro(): void
    {
        $config = $this->getMockConfig();
        [$profile, ] = $this->resolveProfile('user@professor.ufcg.edu.br', $config);
        $this->assertEquals(4, $profile,
            'A regra mais específica (professor) deve ter prioridade sobre a genérica (ufcg).');
    }

    public function testOrdemRegrasInvertida(): void
    {
        $config = [
            'default_profile_id' => 1,
            'default_entity_id'  => 0,
            'domain_rules' => [
                ['domain' => 'ufcg.edu.br',            'profile_id' => 5, 'entity_id' => 0],
                ['domain' => 'professor.ufcg.edu.br',  'profile_id' => 4, 'entity_id' => 0],
            ],
        ];
        [$profile, ] = $this->resolveProfile('user@professor.ufcg.edu.br', $config);
        $this->assertEquals(5, $profile,
            'Com regras na ordem errada, professor pega perfil genérico. A ORDEM DAS REGRAS IMPORTA.');
    }
}
