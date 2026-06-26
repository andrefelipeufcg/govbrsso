# Plugin GLPI 11 — Login Único gov.br

Autenticação no GLPI 11 via **Login Único gov.br** (OpenID Connect, fluxo Authorization Code com **PKCE S256**), seguindo rigorosamente o roteiro técnico oficial em https://acesso.gov.br/roteiro-tecnico/.

O plugin adiciona um botão **"Entrar com gov.br"** na tela de login, conduz o fluxo OIDC/PKCE, valida o token e cria a sessão do GLPI usando o caminho de **autenticação externa** do core (que dispara o motor de Regras de habilitação).

## Principais Recursos e Diferenciais

- **Fluxo OIDC com PKCE S256**: Autenticação moderna e segura exigida pelo MGI.
- **Integração Visual Inteligente**: O botão utiliza as cores exatas (Azul, Verde, Amarelo) e a tipografia padrão do gov.br. Além disso, introduz uma arquitetura de layout moderna via **CSS Grid** (`display: contents`) que alinha botões SSO lado a lado no Desktop de forma nativa e sem acoplamento entre os plugins. No mobile, eles ficam perfeitamente empilhados.
- **Baixo Acoplamento**: Renderiza via gancho nativo do GLPI (`display_login`) apoiado por um pequeno script autônomo que assegura o posicionamento correto do botão logo abaixo do formulário.
- **Pronto para Marketplace**: Estrutura contendo metadados oficiais (`govbrsso.xml`) e logo, facilitando sua eventual listagem oficial no diretório do GLPI.
- **Níveis de Confiabilidade**: Permite barrar acessos baseados nos níveis Bronze, Prata ou Ouro.

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
| Nível mínimo | Barra contas abaixo de Bronze/Prata/Ouro (através da validação `reliability_info`) |
| Criar usuário | Cria a conta local no GLPI no primeiro acesso via gov.br (se marcado) |
| Ativar botão | Exibe e habilita o botão na tela de login |

## Regra de habilitação (obrigatória)

O SSO do gov.br **apenas autentica** o usuário. Após o primeiro login, o GLPI precisa conceder um perfil e uma entidade, caso contrário o acesso será negado.
Crie ao menos uma regra em **Administração > Regras > Regras de atribuição de habilitações a um usuário**.
Exemplo: Se o usuário foi autenticado externamente, ele recebe o perfil *Self-Service* na entidade raiz.
O plugin registra rejeições (acesso sem perfil) no arquivo de log `files/_log/govbrsso*`.

## Como funciona (resumo técnico)

1. `front/redirect.php` gera `state`, `nonce`, `code_verifier`/`code_challenge` (S256) e redireciona ao `/authorize`.
2. `front/callback.php` valida o `state`, troca o `code` por tokens no `/token` (Basic auth + `code_verifier`), valida a assinatura via `/jwk`, confere o `nonce`, complementa com `/userinfo` e chama o login.
3. `src/UserManager.php` casa os claims (`sub`=CPF / e-mail) com um usuário do GLPI e executa `Auth::login()` no caminho EXTERNAL, usando uma variável SSO dedicada criada na instalação (`HTTP_GOVBRSSO_REMOTE_USER`) — isso aciona o motor de regras e cria a sessão local.
4. `front/logout.php` encerra a sessão local e faz o logout federado no gov.br.

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
