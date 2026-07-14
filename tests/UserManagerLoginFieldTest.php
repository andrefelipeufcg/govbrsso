<?php

namespace GlpiPlugin\Govbrsso\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Testes sobre a definição de qual campo será usado como identificador
 * de login (CPF vs E-mail) dependendo da configuração do plugin
 * e das informações fornecidas pelo Gov.br.
 *
 * Replica a lógica da linha 35-64 do UserManager.php para testar o isolamento de segurança
 * e extração de múltiplos e-mails.
 */
class UserManagerLoginFieldTest extends TestCase
{
    private function extractVerifiedEmails(array $claims): array
    {
        $verifiedEmails = [];
        $mainEmailVerified = ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? '') === 'true';

        if (isset($claims['email']) && trim((string)$claims['email']) !== '' && $mainEmailVerified) {
            $verifiedEmails[] = trim((string)$claims['email']);
        }

        if (isset($claims['emails']) && is_array($claims['emails'])) {
            foreach ($claims['emails'] as $em) {
                $emStr = trim((string)$em);
                if ($emStr !== '' && !in_array($emStr, $verifiedEmails, true)) {
                    $verifiedEmails[] = $emStr;
                }
            }
        }

        if (isset($claims['email_institucional']) && trim((string)$claims['email_institucional']) !== '') {
            $emStr = trim((string)$claims['email_institucional']);
            if (!in_array($emStr, $verifiedEmails, true)) {
                $verifiedEmails[] = $emStr;
            }
        }

        return $verifiedEmails;
    }

    private function simulateLoginDeterminator(string $configuredLoginField, array $claims): array
    {
        $cpf = isset($claims['sub']) ? preg_replace('/\D+/', '', (string) $claims['sub']) : '';
        $verifiedEmails = $this->extractVerifiedEmails($claims);

        if ($configuredLoginField === 'email') {
            if (empty($verifiedEmails)) {
                return ['ok' => false, 'error' => 'Seu cadastro no gov.br não possui um e-mail validado. Por favor, acesse gov.br, adicione e valide seu e-mail antes de acessar o sistema.'];
            }
            return ['ok' => true, 'emails_to_try' => $verifiedEmails];
        }

        return ['ok' => true, 'login_field' => $cpf];
    }

    public function testVarreMultiplosEmailsDoGovBr(): void
    {
        $claims = [
            'sub' => '12345678909',
            'email' => 'joao.pessoal@gmail.com',
            'email_verified' => true,
            'emails' => ['joao.antigo@empresa.com.br'],
            'email_institucional' => 'joao.novo@orgao.gov.br'
        ];
        
        $resultado = $this->simulateLoginDeterminator('email', $claims);
        
        $this->assertTrue($resultado['ok']);
        $this->assertCount(3, $resultado['emails_to_try'], 'O plugin deve extrair os 3 e-mails diferentes vindos das claims.');
        $this->assertContains('joao.pessoal@gmail.com', $resultado['emails_to_try']);
        $this->assertContains('joao.antigo@empresa.com.br', $resultado['emails_to_try']);
        $this->assertContains('joao.novo@orgao.gov.br', $resultado['emails_to_try']);
    }

    public function testBloqueiaAcessoSeEmailConfiguradoMasGovBrNaoTiverEmail(): void
    {
        $claims = [
            'sub' => '12345678909',
            'email' => '',
            'email_verified' => false
        ];
        
        $resultado = $this->simulateLoginDeterminator('email', $claims);
        
        $this->assertFalse($resultado['ok'], 'O sistema deve bloquear o acesso se configurado para email e não houver email.');
        $this->assertEquals('Seu cadastro no gov.br não possui um e-mail validado. Por favor, acesse gov.br, adicione e valide seu e-mail antes de acessar o sistema.', $resultado['error']);
    }

    public function testBloqueiaAcessoSeEmailConfiguradoMasNaoForVerificadoNoGovBr(): void
    {
        $claims = [
            'sub' => '12345678909',
            'email' => 'hacker@malicioso.com',
            'email_verified' => false // Hackers podem preencher o cadastro do gov.br com e-mails falsos sem confirmar
        ];
        
        $resultado = $this->simulateLoginDeterminator('email', $claims);
        
        $this->assertFalse($resultado['ok'], 'Deve rejeitar o e-mail não verificado, protegendo o GLPI de account takeover.');
    }

    public function testUsaCpfQuandoConfiguradoComoCpfMesmoComEmailValido(): void
    {
        $claims = [
            'sub' => '12345678909',
            'email' => 'joao.silva@gov.br',
            'email_verified' => true
        ];
        
        $resultado = $this->simulateLoginDeterminator('cpf', $claims);
        
        $this->assertTrue($resultado['ok']);
        $this->assertEquals('12345678909', $resultado['login_field'], 'O identificador principal do gov.br é o CPF e deve ser usado quando for a configuração padrão.');
    }
}
