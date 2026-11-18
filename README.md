# Portal Interno

Miniaplicação em PHP, HTML, CSS e JavaScript para Vercel e Supabase. O PHP autentica pelo nome de login e consulta o PostgreSQL do Supabase via PDO. As contas e sessões ficam no esquema privado `portal_interno`.

| URL | Quem pode acessar |
|---|---|
| `https://localhost/login` | Qualquer pessoa. A raiz `/` redireciona para cá. |
| `https://localhost/cadastro` | Administrador autenticado. Cria contas dos dois perfis. |
| `https://localhost/administradores` | Administrador autenticado. |
| `https://localhost/usuarios` | Usuário comum autenticado. |

O login redireciona para a área correspondente ao perfil. As duas áreas permitem encerrar a sessão. Uma visita anônima a uma rota restrita redireciona para o login. Um perfil autenticado sem permissão recebe HTTP 403.

## 1. Preparar o Supabase

1. Crie ou abra o projeto Supabase destinado ao trabalho. No **SQL Editor**, execute [supabase/estrutura.sql](supabase/estrutura.sql) e depois [supabase/sessoes.sql](supabase/sessoes.sql), uma vez cada. Se a estrutura de contas já estiver instalada, execute apenas o script de sessões.
2. No painel **Connect → Session pooler**, copie o host e a referência do projeto. Use a porta `5432`.
3. Em **Database Settings → SSL Configuration**, baixe o certificado CA do projeto e salve-o na pasta do projeto com o nome `supabase-ca.crt`. O arquivo deve estar em formato PEM.
4. Configure uma senha forte para a role `portal_interno_app`. Com Docker Desktop aberto, execute este comando na pasta do projeto, trocando `HOST_DO_POOLER` e `REF` pelos valores do passo 2.

```powershell
docker run --rm -it --mount "type=bind,source=$($PWD.Path)/supabase-ca.crt,target=/ca.crt,readonly" postgres:17-alpine psql "host=HOST_DO_POOLER port=5432 dbname=postgres user=postgres.REF sslmode=verify-full sslrootcert=/ca.crt" -W
```

Dentro do `psql`, informe a senha administrativa do banco quando o programa pedir. Depois execute os comandos abaixo e escolha a senha da role dedicada. O comando `\password` solicita a senha sem exibi-la no terminal.

```text
\password portal_interno_app
\q
```

5. Copie `.env.example` para `.env`. Preencha o host e o usuário `portal_interno_app.REF`. Em `DB_PASSWORD`, use a senha da role que você acabou de configurar. Em `APP_CSRF_KEY`, use uma chave aleatória de 32 bytes em hexadecimal. Você pode gerar uma com `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.

## 2. Iniciar em HTTPS

Com Docker Desktop em execução e a partir da pasta do projeto, rode estes comandos.

```powershell
docker compose up --build -d
docker compose exec app php bin/criar-admin.php admin
```

Escolha a senha do primeiro administrador quando o terminal pedir. Use de 12 a 128 bytes. O nome `admin` no comando é um exemplo que você pode substituir. A criação inicial funciona enquanto o banco estiver sem administradores.

O Caddy gera um certificado HTTPS local. Para o navegador confiar nele no Windows, execute uma vez:

```powershell
docker compose cp web:/data/caddy/pki/authorities/local/root.crt ./caddy-local.crt
Import-Certificate -FilePath (Join-Path $PWD 'caddy-local.crt') -CertStoreLocation Cert:\CurrentUser\Root
```

Abra [https://localhost/login](https://localhost/login). Entre com o primeiro administrador, abra **Cadastrar usuário**, crie uma conta comum e encerre a sessão. Entre com a conta comum para verificar a outra área. Para parar a aplicação, execute `docker compose down`.

## Publicar na Vercel

O arquivo [Dockerfile.vercel](Dockerfile.vercel) empacota o PHP 8.4 com FrankenPHP. A Vercel termina o HTTPS do domínio e encaminha as requisições ao contêiner. A configuração local continua disponível via Docker Compose.

Com o repositório do GitHub conectado ao projeto Vercel, os envios à branch de produção geram uma nova publicação. As variáveis do banco e do CSRF ficam nas configurações do projeto Vercel.

Para uma instalação nova, siga os passos abaixo.

1. Crie um projeto Vercel com o preset **Container** e vincule a pasta com `vercel link`.
2. No projeto Vercel, configure `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD` e `APP_CSRF_KEY` para o ambiente **Production**. Marque a senha e a chave CSRF como sensíveis. Para o pooler compartilhado, use o host do painel **Connect** e a porta `6543` do modo transação. O contêiner já inclui o certificado CA público do Supabase.
3. Crie o primeiro administrador com o comando da seção anterior, apontando o seu `.env` local para o mesmo banco. Para essa etapa, mantenha a porta local `5432` do pooler de sessão.
4. Execute `vercel deploy --prod` e abra `/login` no domínio HTTPS retornado.

## Segurança

O módulo [app/Seguranca.php](app/Seguranca.php) concentra as regras de rota, os perfis autorizados e os mecanismos de segurança no PHP. O controlador [public/index.php](public/index.php) consulta esse módulo antes de processar cada página.

- **Injeção.** PDO com consultas parametrizadas, validação de login, senha e perfil no servidor e escape de conteúdo HTML. O banco também valida o formato do login e os perfis aceitos.
- **CSRF.** Token HMAC vinculado à sessão ou ao cookie aleatório do visitante, conferido com `hash_equals` em todos os POSTs, inclusive no login e na saída. Os cookies usam `SameSite=Lax`.
- **Sessão.** Cookie `__Host-portal` com `Secure` e `HttpOnly`, token de 256 bits renovado ao autenticar, expiração após 15 minutos de inatividade e duração total de até 8 horas. O banco armazena o hash do token. A saída exclui a sessão. O perfil é consultado no banco em cada rota restrita.
- **Senhas e banco.** O PHP usa Argon2id com sal gerado pela API de hash. As credenciais do banco ficam no servidor. A role da aplicação tem acesso de leitura e inserção à tabela por políticas RLS, em um esquema privado. A conexão exige TLS e valida o certificado e o host do banco.
- **Erros.** Respostas genéricas para falhas inesperadas e detalhes técnicos limitados ao log do servidor. As páginas de sessão usam `Cache-Control: no-store` e uma política de segurança de conteúdo.

Documentação de referência. [Conexão PostgreSQL no Supabase](https://supabase.com/docs/guides/database/connecting-to-postgres) · [SSL no Supabase](https://supabase.com/docs/guides/platform/ssl-enforcement) · [PHP na Vercel](https://vercel.com/kb/guide/deploy-php-on-vercel-with-docker)
