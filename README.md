# Plugin GLPI 11 — Login Único gov.br

Autenticação no GLPI 11 via **Login Único gov.br** (OpenID Connect, fluxo Authorization Code com **PKCE S256**), seguindo rigorosamente o roteiro técnico oficial em https://acesso.gov.br/roteiro-tecnico/.

O plugin adiciona um botão **"Entrar com gov.br"** na tela de login, conduz o fluxo OIDC/PKCE, valida o token e cria a sessão do GLPI usando o caminho de **autenticação externa** do core (que dispara o motor de Regras de habilitação).

## Principais Recursos e Diferenciais

- **Fluxo OIDC com PKCE S256**: Autenticação moderna e segura exigida pelo MGI.
- **Integração Visual Inteligente**: O botão utiliza as cores exatas (Azul, Verde, Amarelo) e a tipografia padrão do gov.br. Além disso, introduz uma arquitetura de layout moderna via **CSS Grid** (`display: contents`) que alinha botões SSO lado a lado no Desktop de forma nativa e sem acoplamento entre os plugins. No mobile, eles ficam perfeitamente empilhados.
- **Baixo Acoplamento**: Renderiza via gancho nativo do GLPI (`display_login`) apoiado por um pequeno script autônomo que assegura o posicionamento correto do botão logo abaixo do formulário.
- **Pronto para Marketplace**: Estrutura contendo metadados oficiais (`govbrsso.xml`) e logo, facilitando sua eventual listagem oficial no diretório do GLPI.
- **Níveis de Confiabilidade**: Permite barrar acessos baseados nos níveis Bronze, Prata ou Ouro (suporte às claims `amr` e `reliability_info`).
- **Tela de Consentimento Explícito**: Durante a criação automática de usuários, o plugin exibe uma tela limpa e amigável para que o usuário concorde com a criação da sua conta local no GLPI, reforçando a conformidade com as regras de transparência.
- **Formatação Inteligente de Nomes**: Ao receber o nome completo do Gov.br, o plugin separa automaticamente a primeira palavra para o campo *Nome* (firstname) e o restante para o *Sobrenome* (realname), adequando-se ao padrão brasileiro no GLPI.
- **Validação Rigorosa de E-mail**: Por questões de segurança, se o gov.br não fornecer um e-mail validado, o plugin bloqueará a criação da conta e instruirá o usuário a adicionar e validar um e-mail no próprio portal do gov.br antes de tentar novamente.
- **Rastreabilidade Aprimorada**: Registra a origem "via Gov.BR (SSO)" no log de eventos nativo de login do GLPI.

## Requisitos

- GLPI **11.0.x**
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
| Nível mínimo | Barra contas abaixo de Bronze/Prata/Ouro (através das claims `amr` ou `reliability_info`) |
| Criar usuário | Cria a conta local no GLPI no primeiro acesso via gov.br (se marcado) |
| Regras de Domínio | Define perfil e entidade baseados no domínio de e-mail (ex: `aluno.edu.br`) no momento da criação |
| Regra Padrão (Fallback) | Perfil e entidade padrão para novos usuários que não se enquadram nas regras de domínio |
| Ativar botão | Exibe e habilita o botão na tela de login |

## Atribuição de Habilitações

Após o primeiro login via gov.br, o GLPI precisa conceder um perfil e uma entidade ao usuário, caso contrário o acesso será negado. Você tem duas opções para gerenciar isso:

1. **Via Regras de Domínio do Plugin (Recomendado):**
   Nas configurações do próprio plugin, ao ativar a "Criação de usuário automática", você pode definir mapeamentos de perfil e entidade baseados no domínio do e-mail do usuário, além de uma regra padrão (fallback). A regra anulará a atribuição de perfil global do GLPI para evitar duplicidades (dois perfis na conta nova).
   
2. **Via Motor de Regras do GLPI:**
   Se preferir, você pode criar regras nativas em **Administração > Regras > Regras de atribuição de habilitações a um usuário**. Exemplo: Se o usuário foi autenticado externamente, recebe o perfil *Self-Service* na entidade raiz.

O plugin registra rejeições (acesso sem perfil) no arquivo de log `files/_log/govbrsso*`.

## Como funciona (resumo técnico)

1. `front/redirect.php` gera `state`, `nonce`, `code_verifier`/`code_challenge` (S256) e redireciona ao `/authorize`.
2. `front/callback.php` valida o `state`, troca o `code` por tokens no `/token` (Basic auth + `code_verifier`), valida a assinatura via `/jwk`, confere o `nonce` e salva as informações obtidas (`claims`) em sessão, redirecionando o usuário para a validação final (consentimento).
3. `front/consent.php` exibe um formulário com uma estética familiar ao Gov.br para colher a anuência do titular. Ao submeter, valida o estado via POST e despacha a ordem de login.
4. `src/UserManager.php` tenta casar os `claims` (CPF ou email) com o usuário no GLPI. Se configurada a criação de usuário: processa as Regras de Domínio, separa o nome em *Nome/Sobrenome*, cria o usuário e limpa o auto-assingment nativo do GLPI para injetar o perfil final com precisão. Por fim, executa a autenticação manual no GLPI (`Session::init()`), criando a sessão local e logando como "via Gov.BR (SSO)".

Os scripts do fluxo (`redirect.php`, `callback.php`, `logout.php`) têm execução anônima garantida via `Firewall::addPluginStrategyForLegacyScripts` no GLPI 11 (declarados no `setup.php`).

## Pontos a validar no seu ambiente

- **Endpoints de produção:** Não esqueça de trocar a Provider URL para o host de produção do gov.br após homologado.
- **Botão e Tema Customizado:** O plugin usa o gancho `display_login` com injeção JavaScript de DOM (no `Config.php`) para mover o botão para fora do formulário base e alinhá-lo via CSS Grid com outros SSOs. Se você usa temas profundamente customizados que removem as classes base do GLPI (como `.col-md-5` ou `.login_card`), o botão recorrerá ao comportamento de fallback (aparecer no final da tela).
- **Variável SSO:** A instalação cria `HTTP_GOVBRSSO_REMOTE_USER` em `glpi_ssovariables`. O login externo depende dela; **não a remova**.
- **2FA nativo do GLPI:** Podem ocorrer conflitos entre o OAuth SSO e o 2FA nativo dependendo da versão do GLPI. Se o login falhar após a autenticação no gov.br, teste desligando o 2FA do usuário.
- **E-mails:** Mantenha a opção "Remover o domínio dos logins" **desligada** em Configurar > Autenticação se for usar o e-mail como chave, evitando a mesclagem indevida de contas.

## LGPD

Ao integrar este plugin, o órgão torna-se responsável pelo tratamento dos dados recebidos do MGI (Lei 13.709/2018): publique Aviso de Privacidade em seu portal GLPI e mantenha canais abertos para o atendimento de solicitações de privacidade.

## Licença

GPLv3+

## Autores / Contribuidores

Este plugin foi desenvolvido em conjunto por:
* **Daniel Ramos** - [@danielrramos](https://github.com/danielrramos)
* **Andre Felipe** - [@andrefelipeufcg](https://github.com/andrefelipeufcg)