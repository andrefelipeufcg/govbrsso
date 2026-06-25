# Plugin GLPI 11 — Login Único gov.br

Autenticação no GLPI 11 via **Login Único gov.br** (OpenID Connect, fluxo
Authorization Code com **PKCE S256**), seguindo o roteiro técnico em
https://acesso.gov.br/roteiro-tecnico/.

O plugin adiciona um botão **"Entrar com gov.br"** na tela de login, conduz o
fluxo OIDC/PKCE, valida o token e cria a sessão do GLPI usando o caminho de
**autenticação externa** do core (que dispara o motor de Regras de habilitação).

## Requisitos

- GLPI **11.0.x**
- PHP **8.2+** com extensões `openssl`, `curl`, `json`
- Credencial do Login Único (client_id + client_secret) — homologação ou produção

## Instalação

1. Copie a pasta `govbrsso/` para `<GLPI>/plugins/` (o nome da pasta **precisa** ser
   `govbrsso`).
2. Em **Configurar > Plugins**, instale e ative o "Login Único gov.br".
3. Abra a configuração do plugin (engrenagem) e preencha os campos.

## Configuração

Na página do plugin você verá duas URLs geradas automaticamente — cadastre-as na
credencial gov.br:

- **URL de retorno (redirect_uri):** `.../plugins/govbrsso/front/callback.php`
- **URL de Log Out:** `.../plugins/govbrsso/front/logout.php`

Campos:

| Campo | Descrição |
|---|---|
| Provider URL | `https://sso.staging.acesso.gov.br` (homologação) ou produção |
| client_id / client_secret | da credencial gov.br (o secret é guardado cifrado) |
| Escopos | padrão: `openid email profile govbr_confiabilidades govbr_confiabilidades_idtoken` |
| Campo de login | `CPF` (claim `sub`) ou `E-mail` |
| Nível mínimo | barra contas abaixo de Bronze/Prata/Ouro (via `reliability_info`) |
| Criar usuário automaticamente | cria o usuário no primeiro login |
| Ativar | exibe o botão na tela de login |

## Regra de habilitação (obrigatória)

SSO **só autentica**. Após o primeiro login, crie ao menos uma regra em
**Administração > Regras > Regras de atribuição de habilitações a um usuário**
(ex.: quem entra por gov.br recebe o perfil *Self-Service* e uma entidade). Sem
isso o login conclui no gov.br mas o GLPI nega o acesso — o plugin registra essa
causa em `files/_log/govbrsso*` e exibe a mensagem correspondente.

## Como funciona (resumo técnico)

1. `front/redirect.php` gera `state`, `nonce`, `code_verifier`/`code_challenge`
   (S256) e redireciona ao `/authorize`.
2. `front/callback.php` valida o `state`, troca o `code` por tokens no `/token`
   (Basic auth + `code_verifier`), valida a assinatura via `/jwk`, confere o
   `nonce`, complementa com `/userinfo` e chama o login.
3. `src/UserManager.php` casa os claims (`sub`=CPF / e-mail) com um usuário do
   GLPI e executa `Auth::login()` no caminho EXTERNAL, usando uma variável SSO
   dedicada criada na instalação (`HTTP_GOVBRSSO_REMOTE_USER`) — isso aciona o motor
   de regras e cria a sessão.
4. `front/logout.php` encerra a sessão local e faz o logout federado no gov.br.

Os dois scripts de fluxo (`redirect.php`, `callback.php`) e o `logout.php` são
liberados para acesso anônimo no GLPI 11 via
`Firewall::addPluginStrategyForLegacyScripts(... STRATEGY_NO_CHECK)` no
`plugin_govbrsso_boot()` (`setup.php`).

## Pontos a validar no seu ambiente

Este plugin foi escrito contra os padrões públicos do GLPI 11 e do roteiro
gov.br. Antes de produção, confirme:

- **Endpoints de produção:** troque o Provider URL para o host de produção do
  gov.br conforme a credencial recebida.
- **Botão na tela de login:** o plugin usa o gancho `display_login`. Se a sua
  build do GLPI 11 não o renderizar, o login continua acessível pela URL
  `.../plugins/govbrsso/front/redirect.php` (você pode linká-la na tela de login
  por um tema/HTML próprio), ou adapte para `POST_INIT` + injeção via JS.
- **Variável SSO:** a instalação cria `HTTP_GOVBRSSO_REMOTE_USER` em
  `glpi_ssovariables`. O login externo depende dela; não a remova.
- **2FA nativo do GLPI:** há relato de conflito entre OAuth SSO e o 2FA nativo em
  versões beta do GLPI 11. Se o login falhar com 2FA ligado, teste sem ele para
  isolar.
- **Opção "Remover o domínio dos logins login@domínio"** (Configurar >
  Autenticação): mantenha **desligada** ao usar e-mail como login, para evitar
  mesclagem indevida de contas.

## LGPD

Ao integrar, o órgão torna-se responsável pelo tratamento dos dados recebidos
(Lei 13.709/2018): publique Aviso de Privacidade e mantenha canal para
solicitações de privacidade.

## Licença

GPLv3+
