<?php

namespace GlpiPlugin\Govbrsso\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Teste unitário PURO para validação da lógica de matching de domínios
 * no login via gov.br SSO.
 *
 * Este teste NÃO depende do GLPI, banco de dados, sessão ou cache.
 * Ele replica e valida exatamente a lógica contida no método
 * UserManager::loginFromClaims().
 *
 * Para rodar no servidor de homologação:
 *   sudo php phpunit plugins/govbrsso/tests/UserManagerDomainTest.php --testdox
 */
class UserManagerDomainTest extends TestCase
{
    /**
     * Replica a lógica de matching de domínio do UserManager::loginFromClaims().
     * Retorna [profile_id, entity_id] para o email fornecido.
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
            'default_entity_id' => 0,
            'domain_rules' => [
                ['domain' => 'professor.ufcg.edu.br', 'profile_id' => 4, 'entity_id' => 0],
                ['domain' => 'tecnico.ufcg.edu.br',   'profile_id' => 3, 'entity_id' => 0],
                ['domain' => 'estudante.ufcg.edu.br',  'profile_id' => 2, 'entity_id' => 0],
                ['domain' => 'ufcg.edu.br',            'profile_id' => 5, 'entity_id' => 0],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Testes de subdomínios específicos da UFCG
    // ---------------------------------------------------------------

    public function testProfessorUfcg(): void
    {
        $config = $this->getMockConfig();
        [$profile, $entity] = $this->resolveProfile('joao@professor.ufcg.edu.br', $config);
        $this->assertEquals(4, $profile, 'Professor deve receber perfil 4');
        $this->assertEquals(0, $entity);
    }

    public function testTecnicoUfcg(): void
    {
        $config = $this->getMockConfig();
        [$profile, $entity] = $this->resolveProfile('maria@tecnico.ufcg.edu.br', $config);
        $this->assertEquals(3, $profile, 'Técnico deve receber perfil 3');
        $this->assertEquals(0, $entity);
    }

    public function testEstudanteUfcg(): void
    {
        $config = $this->getMockConfig();
        [$profile, $entity] = $this->resolveProfile('pedro@estudante.ufcg.edu.br', $config);
        $this->assertEquals(2, $profile, 'Estudante deve receber perfil 2');
        $this->assertEquals(0, $entity);
    }

    // ---------------------------------------------------------------
    // Testes do domínio genérico ufcg.edu.br
    // ---------------------------------------------------------------

    public function testDominioUfcgGenerico(): void
    {
        $config = $this->getMockConfig();
        [$profile, $entity] = $this->resolveProfile('admin@ufcg.edu.br', $config);
        $this->assertEquals(5, $profile, 'Domínio genérico ufcg.edu.br deve receber perfil 5');
    }

    public function testSubdominioDesconhecidoUfcg(): void
    {
        $config = $this->getMockConfig();
        [$profile, $entity] = $this->resolveProfile('user@outro.ufcg.edu.br', $config);
        $this->assertEquals(5, $profile, 'Subdomínio desconhecido de ufcg.edu.br deve cair na regra genérica (perfil 5)');
    }

    // ---------------------------------------------------------------
    // Testes de domínios externos (fallback para perfil padrão)
    // ---------------------------------------------------------------

    public function testDominioExterno(): void
    {
        $config = $this->getMockConfig();
        [$profile, $entity] = $this->resolveProfile('user@gmail.com', $config);
        $this->assertEquals(1, $profile, 'Domínios externos devem receber o perfil padrão (1)');
        $this->assertEquals(0, $entity);
    }

    public function testDominioExternoOutlook(): void
    {
        $config = $this->getMockConfig();
        [$profile, $entity] = $this->resolveProfile('user@outlook.com', $config);
        $this->assertEquals(1, $profile, 'Outlook deve receber o perfil padrão (1)');
    }

    // ---------------------------------------------------------------
    // Teste de case-insensitivity
    // ---------------------------------------------------------------

    public function testEmailMaiusculo(): void
    {
        $config = $this->getMockConfig();
        [$profile, $entity] = $this->resolveProfile('JOAO@PROFESSOR.UFCG.EDU.BR', $config);
        $this->assertEquals(4, $profile, 'Email em maiúsculas deve funcionar normalmente');
    }
}
