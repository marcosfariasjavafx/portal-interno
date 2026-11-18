<?php

declare(strict_types=1);

final class FalhaHttp extends RuntimeException
{
}

final class Seguranca
{
    public const ADMIN = 'administrador';
    public const COMUM = 'comum';
    private const COOKIE = '__Host-portal';
    private const COOKIE_VISITANTE = '__Host-portal-csrf';
    private const COOKIE_OPCOES = [
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    private const ROTAS = [
        '/' => [['GET', 'HEAD'], null],
        '/login' => [['GET', 'HEAD', 'POST'], null],
        '/cadastro' => [['GET', 'HEAD', 'POST'], self::ADMIN],
        '/administradores' => [['GET', 'HEAD', 'POST'], self::ADMIN],
        '/usuarios' => [['GET', 'HEAD', 'POST'], self::COMUM],
    ];

    private static ?PDO $banco = null;
    private static ?array $conta = null;
    private static ?string $tokenSessao = null;
    private static ?string $tokenVisitante = null;

    public static function iniciarErros(callable $responder): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        set_error_handler(static function (int $nivel, string $mensagem, string $arquivo, int $linha): never {
            throw new ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
        });
        set_exception_handler(static function (Throwable $erro) use ($responder): void {
            if ($erro instanceof FalhaHttp) {
                $responder($erro->getCode(), $erro->getMessage());
                return;
            }

            // Os detalhes de credenciais e parâmetros não entram no log.
            error_log(sprintf(
                'Portal: %s [%s] em %s:%d',
                get_class($erro),
                $erro->getCode(),
                basename($erro->getFile()),
                $erro->getLine(),
            ));
            $responder(500, 'Não foi possível concluir a operação. Tente novamente.');
        });
    }

    public static function iniciarWeb(): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        $httpsNaVercel = getenv('PORTAL_VERCEL') === '1'
            && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        if (($_SERVER['HTTPS'] ?? '') !== 'on' && !$httpsNaVercel) {
            throw new FalhaHttp('Use uma conexão HTTPS para acessar o portal.', 400);
        }

        self::$tokenSessao = self::lerCookie(self::COOKIE);
        self::$tokenVisitante = self::lerCookie(self::COOKIE_VISITANTE);
    }

    public static function autorizarRota(string $rota, string $metodo): ?array
    {
        [$metodos, $perfil] = self::ROTAS[$rota] ?? throw new FalhaHttp('O endereço solicitado não existe.', 404);
        if (!in_array($metodo, $metodos, true)) {
            header('Allow: ' . implode(', ', $metodos));
            throw new FalhaHttp('Método indisponível para este endereço.', 405);
        }

        self::carregarSessao();
        if ($perfil !== null) {
            self::exigirPerfil($perfil);
        }
        if ($metodo === 'POST') {
            self::verificarCsrf();
        }
        self::registrarAtividade();
        if ($perfil !== null && self::$conta === null) {
            self::redirecionar('/login', 302);
        }
        return self::$conta;
    }

    public static function csrf(): string
    {
        if (self::$tokenSessao === null && self::$tokenVisitante === null) {
            self::$tokenVisitante = bin2hex(random_bytes(32));
            self::gravarCookie(self::COOKIE_VISITANTE, self::$tokenVisitante);
        }
        return self::assinarCsrf(self::$tokenSessao ?? self::$tokenVisitante);
    }

    private static function verificarCsrf(): void
    {
        $token = self::entrada($_POST, 'csrf');
        $origem = self::$tokenSessao ?? self::$tokenVisitante;
        if ($origem === null || !hash_equals(self::assinarCsrf($origem), $token)) {
            throw new FalhaHttp('Não foi possível confirmar a solicitação. Abra a página novamente e tente de novo.', 403);
        }
    }

    public static function sessaoAtual(): ?array
    {
        return self::$conta;
    }

    private static function carregarSessao(): void
    {
        if (self::$tokenSessao === null) {
            return;
        }
        $consulta = self::banco()->prepare(
            'SELECT c.id, c.login, c.perfil
             FROM portal_interno.sessoes s JOIN portal_interno.contas c ON c.id = s.conta_id
             WHERE s.chave = :chave AND s.inativa_em > CURRENT_TIMESTAMP'
        );
        $consulta->execute(['chave' => self::chaveSessao()]);
        $conta = $consulta->fetch();
        if ($conta === false) {
            self::limparCookieSessao();
            return;
        }
        self::$conta = $conta;
    }

    private static function exigirPerfil(string $perfil): void
    {
        if (self::$conta === null) {
            self::redirecionar('/login', 302);
        }
        if (self::$conta['perfil'] !== $perfil) {
            throw new FalhaHttp('Você não tem permissão para acessar esta página.', 403);
        }
    }

    private static function registrarAtividade(): void
    {
        if (self::$conta === null) {
            return;
        }
        $consulta = self::banco()->prepare(
            "UPDATE portal_interno.sessoes
             SET inativa_em = LEAST(expira_em, CURRENT_TIMESTAMP + INTERVAL '15 minutes')
             WHERE chave = :chave AND inativa_em > CURRENT_TIMESTAMP"
        );
        $consulta->execute(['chave' => self::chaveSessao()]);
        if ($consulta->rowCount() !== 1) {
            self::limparCookieSessao();
        }
    }

    public static function autenticar(array $dados): bool
    {
        $login = self::normalizarLogin(self::entrada($dados, 'login'));
        $senha = self::entrada($dados, 'senha');
        if (!self::loginValido($login) || !self::senhaValida($senha, 1)) {
            return false;
        }

        $consulta = self::banco()->prepare(
            'SELECT id, login, perfil, senha_hash FROM portal_interno.contas WHERE login = :login'
        );
        $consulta->execute(['login' => $login]);
        $conta = $consulta->fetch();
        if ($conta === false) {
            self::hashSenha($senha);
            return false;
        }
        if (!password_verify($senha, $conta['senha_hash'])) {
            return false;
        }

        unset($conta['senha_hash']);
        self::criarSessao($conta);
        return true;
    }

    public static function cadastrar(array $dados): void
    {
        self::inserirConta(
            self::entrada($dados, 'login'),
            self::entrada($dados, 'senha'),
            self::entrada($dados, 'perfil'),
        );
    }

    public static function criarPrimeiroAdministrador(string $login, string $senha): void
    {
        $banco = self::banco();
        $banco->beginTransaction();
        try {
            $banco->query('SELECT pg_advisory_xact_lock(668310451)');
            $administrador = $banco->query(
                "SELECT 1 FROM portal_interno.contas WHERE perfil = 'administrador' LIMIT 1"
            );
            if ($administrador->fetchColumn() !== false) {
                throw new FalhaHttp('Já existe um administrador. Use /cadastro para criar outras contas.', 409);
            }
            self::inserirConta($login, $senha, self::ADMIN);
            $banco->commit();
        } catch (Throwable $erro) {
            if ($banco->inTransaction()) {
                $banco->rollBack();
            }
            throw $erro;
        }
    }

    public static function sair(): never
    {
        self::apagarSessao(self::chaveSessao());
        self::limparCookieSessao();
        self::redirecionar('/login');
    }

    public static function confirmarCadastro(): void
    {
        $consulta = self::banco()->prepare(
            'UPDATE portal_interno.sessoes SET cadastro_confirmado = true WHERE chave = :chave'
        );
        $consulta->execute(['chave' => self::chaveSessao()]);
    }

    public static function consumirConfirmacaoCadastro(): bool
    {
        $consulta = self::banco()->prepare(
            'UPDATE portal_interno.sessoes SET cadastro_confirmado = false
             WHERE chave = :chave AND cadastro_confirmado'
        );
        $consulta->execute(['chave' => self::chaveSessao()]);
        return $consulta->rowCount() === 1;
    }

    public static function destino(string $perfil): string
    {
        return match ($perfil) {
            self::ADMIN => '/administradores',
            self::COMUM => '/usuarios',
            default => throw new FalhaHttp('Você não tem permissão para acessar esta página.', 403),
        };
    }

    public static function redirecionar(string $destino, int $codigo = 303): never
    {
        header('Location: ' . $destino, true, $codigo);
        exit;
    }

    public static function escapar(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function entrada(array $dados, string $campo): string
    {
        return is_string($dados[$campo] ?? null) ? $dados[$campo] : '';
    }

    private static function criarSessao(array $conta): void
    {
        $novoToken = bin2hex(random_bytes(32));
        $banco = self::banco();
        $banco->beginTransaction();
        try {
            if (self::$tokenSessao !== null) {
                self::apagarSessao(self::chaveSessao());
            }
            $banco->exec('DELETE FROM portal_interno.sessoes WHERE inativa_em <= CURRENT_TIMESTAMP');
            $consulta = $banco->prepare('INSERT INTO portal_interno.sessoes (chave, conta_id) VALUES (:chave, :conta)');
            $consulta->execute(['chave' => hash('sha256', $novoToken), 'conta' => $conta['id']]);
            $banco->commit();
        } catch (Throwable $erro) {
            if ($banco->inTransaction()) {
                $banco->rollBack();
            }
            throw $erro;
        }
        self::$conta = $conta;
        self::$tokenSessao = $novoToken;
        self::gravarCookie(self::COOKIE, $novoToken);
        self::gravarCookie(self::COOKIE_VISITANTE, '', 1);
    }

    private static function chaveSessao(): string
    {
        return hash('sha256', self::$tokenSessao);
    }

    private static function apagarSessao(string $chave): void
    {
        $consulta = self::banco()->prepare('DELETE FROM portal_interno.sessoes WHERE chave = :chave');
        $consulta->execute(['chave' => $chave]);
    }

    private static function limparCookieSessao(): void
    {
        self::$conta = null;
        self::$tokenSessao = null;
        self::gravarCookie(self::COOKIE, '', 1);
    }

    private static function lerCookie(string $nome): ?string
    {
        $valor = self::entrada($_COOKIE, $nome);
        return preg_match('/\A[a-f0-9]{64}\z/', $valor) === 1 ? $valor : null;
    }

    private static function gravarCookie(string $nome, string $valor, int $expiracao = 0): void
    {
        if (!setcookie($nome, $valor, self::COOKIE_OPCOES + ['expires' => $expiracao])) {
            throw new RuntimeException('Falha ao gravar o cookie.');
        }
    }

    private static function assinarCsrf(string $token): string
    {
        $chave = getenv('APP_CSRF_KEY');
        if (!is_string($chave) || preg_match('/\A[a-f0-9]{64}\z/', $chave) !== 1) {
            throw new RuntimeException('Configuração CSRF incompleta.');
        }
        return hash_hmac('sha256', $token, hex2bin($chave));
    }

    private static function normalizarLogin(string $login): string
    {
        return strtolower(trim($login));
    }

    private static function loginValido(string $login): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9._-]{2,31}\z/', $login) === 1;
    }

    private static function senhaValida(string $senha, int $minimo): bool
    {
        $tamanho = strlen($senha);
        return $tamanho >= $minimo && $tamanho <= 128 && !str_contains($senha, "\0");
    }

    private static function hashSenha(string $senha): string
    {
        return password_hash($senha, PASSWORD_ARGON2ID);
    }

    private static function inserirConta(string $login, string $senha, string $perfil): void
    {
        $login = self::normalizarLogin($login);
        if (!self::loginValido($login)) {
            throw new FalhaHttp('Informe um login de 3 a 32 caracteres. Comece com uma letra ou um número e use apenas letras, números, ponto, hífen e sublinhado.', 422);
        }
        if (!self::senhaValida($senha, 12)) {
            throw new FalhaHttp('Informe uma senha de 12 a 128 bytes.', 422);
        }
        if (!in_array($perfil, [self::ADMIN, self::COMUM], true)) {
            throw new FalhaHttp('Selecione um tipo de usuário válido.', 422);
        }

        $consulta = self::banco()->prepare(
            'INSERT INTO portal_interno.contas (login, senha_hash, perfil)
             VALUES (:login, :hash, :perfil) ON CONFLICT (login) DO NOTHING'
        );
        $consulta->execute(['login' => $login, 'hash' => self::hashSenha($senha), 'perfil' => $perfil]);
        if ($consulta->rowCount() !== 1) {
            throw new FalhaHttp('Este login já está em uso. Escolha outro.', 409);
        }
    }

    private static function banco(): PDO
    {
        if (self::$banco !== null) {
            return self::$banco;
        }
        $host = getenv('DB_HOST');
        $porta = getenv('DB_PORT') ?: '5432';
        $usuario = getenv('DB_USER');
        $senha = getenv('DB_PASSWORD');
        $certificado = getenv('DB_SSLROOTCERT') ?: '/run/certs/supabase-ca.crt';
        if (!is_string($host) || preg_match('/\A[A-Za-z0-9.-]+\z/', $host) !== 1
            || !ctype_digit($porta) || (int) $porta < 1 || (int) $porta > 65535
            || !is_string($usuario) || $usuario === '' || !is_string($senha) || $senha === ''
            || preg_match('/\A[A-Za-z0-9_\/:.\\\\-]+\z/', $certificado) !== 1) {
            throw new RuntimeException('Configuração do banco incompleta.');
        }

        $dsn = "pgsql:host={$host};port={$porta};dbname=postgres;sslmode=verify-full;sslrootcert={$certificado};connect_timeout=5";
        return self::$banco = new PDO($dsn, $usuario, $senha, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            Pdo\Pgsql::ATTR_DISABLE_PREPARES => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
