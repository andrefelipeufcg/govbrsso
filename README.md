# Plugin GLPI 11 e 12 — Login Único gov.br
[![GLPI 11](https://img.shields.io/badge/GLPI-11-blue.svg)](https://glpi-project.org/)
[![GLPI 12](https://img.shields.io/badge/GLPI-12-FFD700.svg)](https://glpi-project.org/)
[![License: GPL v3+](https://img.shields.io/badge/License-GPL%20v3%2B-green.svg)](https://www.gnu.org/licenses/gpl-3.0.html)

Autenticação no GLPI 11 e 12 via **Login Único gov.br** (OpenID Connect, fluxo Authorization Code com **PKCE S256**), seguindo rigorosamente o roteiro técnico oficial em https://acesso.gov.br/roteiro-tecnico/.

O plugin adiciona um botão **"Entrar com gov.br"** na tela de login, conduz o fluxo OIDC/PKCE, valida o token e cria a sessão do GLPI usando o caminho de **autenticação externa** do core (que dispara o motor de Regras de habilitação).

## Principais Recursos e Diferenciais

- **Compatibilidade com GLPI 11 e 12**: Desenvolvido com suporte nativo e retrocompatibilidade para o GLPI 11 e o GLPI 12, adotando caminhos dinâmicos de plugin via `Plugin::getPhpDir`, detecção resiliente de IP via `IPUtilities` e interoperabilidade de componentes de interface.
- **Fluxo OIDC com PKCE S256**: Autenticação moderna e segura exigida pelo MGI.
- **Integração Visual Inteligente**: O botão utiliza as cores exatas (Azul, Verde, Amarelo) e a tipografia padrão do gov.br. Além disso, introduz uma arquitetura de layout moderna via **CSS Grid** (`display: contents`) que alinha botões SSO lado a lado no Desktop de forma nativa e sem acoplamento entre os plugins. No mobile, eles ficam perfeitamente empilhados.
- **Baixo Acoplamento**: Renderiza via gancho nativo do GLPI (`display_login`) apoiado por um pequeno script autônomo que assegura o posicionamento correto do botão logo abaixo do formulário.
- **Pronto para Marketplace**: Estrutura contendo metadados oficiais (`govbrsso.xml`) e logo, facilitando sua eventual listagem oficial no diretório do GLPI.
- **Níveis de Confiabilidade**: Permite barrar acessos baseados nos níveis Bronze, Prata ou Ouro (suporte às claims `amr` e `reliability_info`).
- **Tela de Consentimento Explícito**: Durante a criação automática de usuários, o plugin exibe uma tela limpa e amigável para que o usuário concorde com a criação da sua conta local no GLPI, reforçando a conformidade com as regras de transparência.
- **Integração com API Externa para Múltiplos Vínculos (E-mails)**: Permite consultar uma API externa institucional a partir do CPF do usuário para recuperar todos os e-mails e vínculos atrelados àquele titular no ecossistema do órgão.
- **Tela de Seleção de Vínculo/E-mail**: Quando múltiplos e-mails institucionais são encontrados, o usuário visualiza uma tela de seleção com cards interativos para escolher com qual identidade deseja ingressar no GLPI.
- **Header Customizável e Prévia de Comando**: O cabeçalho HTTP da API externa é totalmente configurável (padrão `Glpi-Api-Key` ou personalizado), conta com armazenamento encriptado do secret e gera uma prévia interativa do comando `curl` em tempo real na interface administrativa.
- **Preferências Padrão de Entidade e Perfil**: Ao aprovisionar novos usuários via regras de domínio ou fallback, o plugin define automaticamente o perfil e a entidade padrão nas preferências do usuário (`profiles_id` e `entities_id`), assegurando acesso direto sem telas desconfiguradas.
- **Simulador Interativo de Regras**: Ferramenta embutida para simulação visual de cenários (CPF, nome e e-mail), exibindo a linha do tempo exata de criação de conta e atribuição de perfis sem requerer contas de teste no gov.br.
- **Diagnóstico de Claims e Gestão de Logs**: Painel administrativo para auditoria de claims recebidas filtrando por CPF e expurgo de arquivos de log antigos (`.bak`) com apenas um clique.
- **Diagnóstico Avançado de Transporte (cURL)**: Em casos de falha de comunicação ou indisponibilidade de rede ao solicitar tokens, o plugin registra detalhadamente o código e a mensagem de erro do cURL (`curl_errno` e `curl_error`) nos logs, mantendo uma experiência limpa para o usuário final.
- **Formatação Inteligente de Nomes**: Ao receber o nome completo do Gov.br, o plugin separa automaticamente a primeira palavra para o campo *Nome* (firstname) e o restante para o *Sobrenome* (realname), adequando-se ao padrão brasileiro no GLPI.
- **Validação Rigorosa de E-mail**: Por questões de segurança, se o gov.br não fornecer um e-mail validado, o plugin bloqueará a criação da conta e instruirá o usuário a adicionar e validar um e-mail no próprio portal do gov.br antes de tentar novamente.
- **Rastreabilidade Aprimorada**: Registra a origem "via Gov.BR (SSO)" no log de eventos nativo de login do GLPI.

## Requisitos

- GLPI **11.0.x** ou **12.0.x** (`>= 11.0.0`)
- PHP **8.2+** com extensões `openssl`, `curl`, `json`
- Credencial do Login Único (client_id + client_secret) — ambiente de homologação ou produção.

## Instalação

1. Copie a pasta `govbrsso/` para dentro do diretório `<GLPI>/plugins/` (o nome da pasta **precisa** ser `govbrsso`).
2. No GLPI, acesse **Configurar > Plugins**, instale e ative o "Login Único gov.br".
3. Abra a aba de configuração do plugin (clique na engrenagem) e preencha os campos obrigatórios.

## Configuração

Na página do plugin você verá duas URLs geradas automaticamente — cadastre-as exatamente assim na solicitação de credencial do gov.br:

- **URL de retorno (redirect_uri):** `.../plugins/govbrsso/front/callback.php`
- **URL de Log Out:** `.../plugins/govbrsso/front/logout.php`

### Campos Disponíveis:

| Campo | Descrição |
|---|---|
| Provider URL | `https://sso.staging.acesso.gov.br` (homologação) ou produção (`https://sso.acesso.gov.br`) |
| client_id / client_secret | Da credencial gov.br (o secret é guardado de forma segura, cifrado com a GLPIKey) |
| Escopos | Padrão recomendado: `openid email profile govbr_confiabilidades govbr_confiabilidades_idtoken` |
| Campo de login do GLPI | `CPF` (claim `sub`) ou `E-mail` |
| Habilitar integração com API Externa | Consulta API externa por CPF para descobrir múltiplos e-mails vinculados ao usuário (ativo quando o login é por E-mail) |
| URL da API Externa | Endpoint GET que recebe o CPF (`<url>/<cpf>`) para retornar a lista de e-mails/vínculos |
| Header | Nome do cabeçalho HTTP de autenticação enviado à API externa (padrão: `Glpi-Api-Key`) |
| Chave de API | Chave do cabeçalho de autenticação (armazenada de forma cifrada com `GLPIKey`) |
| Nível mínimo | Barra contas abaixo de Bronze/Prata/Ouro (através das claims `amr` ou `reliability_info`) |
| Criar usuário | Cria a conta local no GLPI no primeiro acesso via gov.br (se marcado) |
| Regras de Domínio | Define perfil e entidade baseados no domínio de e-mail (ex: `aluno.edu.br`) no momento da criação |
| Regra Padrão (Fallback) | Perfil e entidade padrão para novos usuários que não se enquadram nas regras de domínio |
| Ativar botão | Exibe e habilita o botão na tela de login |

## Atribuição de Habilitações

Após o primeiro login via gov.br, o GLPI precisa conceder um perfil e uma entidade ao usuário, caso contrário o acesso será negado. Você tem duas opções para gerenciar isso:

1. **Via Regras de Domínio do Plugin (Recomendado):**
   Nas configurações do próprio plugin, ao ativar a "Criação de usuário automática", você pode definir mapeamentos de perfil e entidade baseados no domínio do e-mail do usuário, além de uma regra padrão (fallback). A regra anulará a atribuição de perfil global do GLPI para evitar duplicidades (dois perfis na conta nova) e configurará automaticamente a entidade e o perfil ativos nas preferências do usuário.
   
2. **Via Motor de Regras do GLPI:**
   Se preferir, você pode criar regras nativas em **Administração > Regras > Regras de atribuição de habilitações a um usuário**. Exemplo: Se o usuário foi autenticado externamente, recebe o perfil *Self-Service* na entidade raiz.

O plugin registra rejeições (acesso sem perfil) no arquivo de log `files/_log/govbrsso*`.

## Integração com API Externa (Múltiplos E-mails e Vínculos)

Em instituições e órgãos públicos onde um mesmo CPF pode estar atrelado a múltiplos e-mails ou papéis (ex.: docente, discente, servidor técnico ou prestador), o plugin disponibiliza suporte à integração com APIs corporativas:

- **Funcionamento:** Quando o campo de login for configurado como `E-mail` e a integração estiver ativa, o plugin consulta a API externa via `GET <URL_DA_API>/<CPF>`.
- **Cabeçalho Customizável:** O nome do header HTTP é flexível (padrão `Glpi-Api-Key` ou qualquer outro nome exigido pela API) e a chave secreta é armazenada com a criptografia nativa do GLPI (`GLPIKey`).
- **Prévia Dinâmica do Comando:** A tela de configurações exibe em tempo real o comando `curl` equivalente, garantindo que o valor da chave nunca seja revelado (sempre mascarado como `[KEY]`).
- **Tela de Seleção de Vínculo:** Se a API retornar mais de um e-mail cadastrado para o CPF, o usuário é direcionado para a tela de escolha (`front/email.php`), exibindo cada e-mail em cards informativos. O e-mail pessoal registrado no gov.br também é disponibilizado como alternativa caso seja diferente dos corporativos.
- **Resiliência e Fallback:** A chamada externa é configurada com resolução forçada em IPv4 e timeouts curtos (1s de conexão / 2s total). Caso a API externa esteja fora do ar ou sem resposta, o login prossegue de forma transparente utilizando o e-mail validado do Gov.br.

## Ferramentas de Administração e Diagnóstico

O plugin conta com interfaces especializadas integradas para apoiar os administradores:

### 1. Simulador de Regras de Criação de Contas (`front/simulator.php`)
Permite validar visualmente como as **Regras de Domínio** e a **Regra Padrão (Fallback)** processarão novos usuários:
- Simule informando qualquer CPF, Nome e E-mail.
- O simulador testa as regras ativas no GLPI e gera uma linha do tempo (timeline) passo a passo detalhando: parâmetros lidos, regras de domínio casadas, fallback, anulação de perfis padrão e as preferências finais atribuídas.
- Não requer contas reais de homologação do gov.br nem realiza modificações reais no banco de dados.

### 2. Diagnóstico de Claims e Limpeza de Logs (`front/claims.php`)
- **Consulta por CPF:** Permite aos Super-Administradores inspecionar o histórico de claims e payloads OIDC retornados pelo gov.br para um determinado CPF, simplificando a depuração de inconsistências cadastrais ou falhas de login.
- **Expurgo Rápido de Logs:** Oferece um botão para purgar arquivos de backup antigos (`govbrsso.log*.bak`), facilitando a manutenção e a adequação às políticas de retenção de dados da LGPD.

## Como funciona (resumo técnico)

1. `front/redirect.php` gera `state`, `nonce`, `code_verifier`/`code_challenge` (S256) e redireciona o usuário para o `/authorize` do gov.br.
2. `front/callback.php` valida o `state`, troca o `code` por tokens no `/token` (Basic auth + `code_verifier` com tratamento e captura detalhada de erros de transporte cURL), valida a assinatura via `/jwk`, confere o `nonce` e grava os `claims` em sessão. Caso a integração com API Externa esteja ativa, realiza a consulta de e-mails via CPF. Havendo múltiplos e-mails, redireciona o usuário para `front/email.php`.
3. `front/email.php` (quando aplicável): exibe a interface de seleção para que o usuário escolha qual vínculo institucional deseja utilizar no acesso.
4. `front/consent.php` exibe um formulário com a identidade visual familiar do Gov.br para colher a anuência do titular para a criação da conta. Ao submeter, valida o estado via POST e despacha a ordem de login.
5. `src/UserManager.php` tenta casar os `claims` (CPF ou email) com o usuário no GLPI. Se configurada a criação de usuário: processa as Regras de Domínio/Fallback, separa o nome em *Nome/Sobrenome*, cria o usuário, remove o auto-assignment de perfis nativo do GLPI, associa a entidade e perfil corretos e salva as preferências padrão (`entities_id` e `profiles_id`). Por fim, executa a autenticação manual no GLPI (`Session::init()`), criando a sessão local e logando como "via Gov.BR (SSO)".

Os scripts do fluxo (`redirect.php`, `callback.php`, `logout.php`, `consent.php`, `email.php`) têm execução anônima garantida via `Firewall::addPluginStrategyForLegacyScripts` no GLPI 11 e GLPI 12 (declarados no `setup.php`).

## Pontos a validar no seu ambiente

- **Endpoints de produção:** Não esqueça de trocar a Provider URL para o host de produção do gov.br após homologado.
- **Botão e Tema Customizado:** O plugin usa o gancho `display_login` com injeção JavaScript de DOM (no `Config.php`) para mover o botão para fora do formulário base e alinhá-lo via CSS Grid com outros SSOs. Se você usa temas profundamente customizados que removem as classes base do GLPI (como `.col-md-5` ou `.login_card`), o botão recorrerá ao comportamento de fallback (aparecer no final da tela).
- **Variável SSO:** A instalação cria `HTTP_GOVBRSSO_REMOTE_USER` em `glpi_ssovariables`. O login externo depende dela; **não a remova**.
- **2FA nativo do GLPI:** Podem ocorrer conflitos entre o OAuth SSO e o 2FA nativo dependendo da versão do GLPI. Se o login falhar após a autenticação no gov.br, teste desligando o 2FA do usuário.
- **E-mails:** Mantenha a opção "Remover o domínio dos logins" **desligada** em Configurar > Autenticação se for usar o e-mail como chave, evitando a mesclagem indevida de contas.

## LGPD

Ao integrar este plugin, o órgão torna-se responsável pelo tratamento dos dados recebidos do MGI (Lei 13.709/2018): publique Aviso de Privacidade em seu portal GLPI e mantenha canais abertos para o atendimento de solicitações de privacidade.

**Nota sobre Retenção de Dados e Logs (Diagnóstico):** O plugin grava as *claims* de identidade dos usuários (incluindo CPF, nome e e-mails de forma parcial ou total) nos arquivos de log estáticos do GLPI (`files/_log/govbrsso.log`) com o propósito exclusivo de diagnóstico e rastreabilidade técnica. O GLPI nativamente não aplica uma política automática de rotação e exclusão (expurgo) sobre plugins de terceiros. Portanto, recomenda-se configurar rotinas automatizadas no servidor (ex: *logrotate* ou tarefas programadas) para expurgar periodicamente estes logs e não reter dados pessoais sensíveis indefinidamente, garantindo conformidade com a LGPD. Opcionalmente, administradores também podem realizar a limpeza manual dos logs antigos (arquivos `.bak`) a qualquer momento, diretamente através do botão "Limpar Logs Antigos" disponível na tela de Diagnóstico de Claims do próprio plugin.

## Licença

GPLv3+

## Autores / Contribuidores

Este plugin foi desenvolvido em conjunto por:
* **Daniel Ramos** - [@danielrramos](https://github.com/danielrramos)
* **Andre Felipe** - [@andrefelipeufcg](https://github.com/andrefelipeufcg)