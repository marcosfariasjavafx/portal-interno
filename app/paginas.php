<?php

declare(strict_types=1);

function pagina(
    string $tipo,
    ?array $conta = null,
    ?string $erro = null,
    bool $sucesso = false,
    array $dados = [],
    int $status = 200,
): void {
    [$titulo, $subtitulo, $rotulo] = match ($tipo) {
        'login' => ['Entre na sua conta', 'Use seu login e sua senha para acessar o portal.', 'Acesso à conta'],
        'cadastro' => ['Cadastrar usuário', 'Crie uma conta e defina o perfil de acesso ao portal.', 'Administração'],
        'administradores' => ['Página de acesso restrito aos usuários administradores', 'Nesta área, você pode cadastrar usuários e definir o perfil de cada conta.', 'Administração'],
        'usuarios' => ['Página de acesso restrito aos usuários comuns', 'Sua sessão está ativa. Encerre o acesso ao terminar.', 'Área do usuário'],
        'erro' => [match ($status) {
            403 => 'Acesso negado',
            404 => 'Página não encontrada',
            500 => 'Operação indisponível',
            default => 'Solicitação indisponível',
        }, '', 'Portal de acesso'],
    };
    $login = Seguranca::escapar(Seguranca::entrada($dados, 'login'));
    $perfil = Seguranca::entrada($dados, 'perfil');
    $formulario = in_array($tipo, ['login', 'cadastro'], true);
    $destino = $conta === null ? '/login' : Seguranca::destino($conta['perfil']);
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= Seguranca::escapar($titulo) ?> · Portal Interno</title>
        <link rel="stylesheet" href="/assets/estilos.css">
        <script src="/assets/portal.js" defer></script>
    </head>
    <body>
        <aside class="identidade" aria-label="Portal Interno">
            <div class="marca">
                <span class="simbolo" aria-hidden="true"></span>
                <span>Portal Interno</span>
            </div>
            <div class="ilustracao" aria-hidden="true">
                <span class="eixo"></span>
                <span class="moldura moldura-c"></span>
                <span class="moldura moldura-b"></span>
                <span class="moldura moldura-a"></span>
                <span class="chave"></span>
            </div>
            <div class="identidade-rodape">
                <div class="conexao"><span aria-hidden="true"></span> Conexão segura</div>
                <p>Acesso exclusivo a usuários cadastrados.</p>
            </div>
        </aside>
        <main class="area">
            <section class="conteudo" aria-labelledby="titulo">
                <?php if ($tipo === 'cadastro'): ?>
                    <a class="voltar" href="/administradores"><span aria-hidden="true">←</span> Voltar para administração</a>
                <?php endif; ?>
                <p class="rotulo"><?= Seguranca::escapar($rotulo) ?></p>
                <h1 id="titulo"><?= Seguranca::escapar($titulo) ?></h1>
                <?php if ($subtitulo !== ''): ?>
                    <p class="introducao"><?= Seguranca::escapar($subtitulo) ?></p>
                <?php endif; ?>
                <?php if ($erro !== null): ?>
                    <p class="aviso aviso-erro" role="alert"><?= Seguranca::escapar($erro) ?></p>
                <?php endif; ?>
                <?php if ($sucesso): ?>
                    <p class="aviso aviso-sucesso" role="status">Usuário cadastrado com sucesso.</p>
                <?php endif; ?>
                <?php if ($formulario): ?>
                    <form method="post" action="<?= $tipo === 'cadastro' ? '/cadastro' : '/login' ?>">
                        <input type="hidden" name="csrf" value="<?= Seguranca::escapar(Seguranca::csrf()) ?>">
                        <div class="campo">
                            <label for="login">Login</label>
                            <input id="login" name="login" type="text" value="<?= $login ?>"
                                autocomplete="<?= $tipo === 'cadastro' ? 'off' : 'username' ?>"
                                autocapitalize="none" spellcheck="false" placeholder="Digite seu login"
                                maxlength="32" minlength="3" required
                                <?= $tipo === 'cadastro' ? 'aria-describedby="ajuda-login"' : '' ?>>
                            <?php if ($tipo === 'cadastro'): ?>
                                <small id="ajuda-login">De 3 a 32 caracteres. Use letras, números, ponto, hífen ou sublinhado. Comece com uma letra ou um número.</small>
                            <?php endif; ?>
                        </div>
                        <div class="campo">
                            <label for="senha">Senha</label>
                            <div class="campo-senha">
                                <input id="senha" name="senha" type="password" placeholder="Digite a senha"
                                    autocomplete="<?= $tipo === 'cadastro' ? 'new-password' : 'current-password' ?>"
                                    maxlength="128" required
                                    <?= $tipo === 'cadastro' ? 'minlength="12" aria-describedby="ajuda-senha"' : '' ?>>
                                <button type="button" class="mostrar" aria-controls="senha" hidden>Mostrar</button>
                            </div>
                            <?php if ($tipo === 'cadastro'): ?>
                                <small id="ajuda-senha">De 12 a 128 bytes. Letras acentuadas podem ocupar mais de um byte.</small>
                            <?php endif; ?>
                        </div>
                        <?php if ($tipo === 'cadastro'): ?>
                            <div class="campo">
                                <label for="perfil">Tipo de usuário</label>
                                <select id="perfil" name="perfil" required>
                                    <option value="" <?= $perfil === '' ? 'selected' : '' ?> disabled>Selecione um perfil</option>
                                    <option value="comum" <?= $perfil === Seguranca::COMUM ? 'selected' : '' ?>>Usuário comum</option>
                                    <option value="administrador" <?= $perfil === Seguranca::ADMIN ? 'selected' : '' ?>>Usuário administrador</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        <button class="botao primario" type="submit">
                            <?= $tipo === 'cadastro' ? 'Cadastrar usuário' : 'Entrar' ?>
                            <span aria-hidden="true">→</span>
                        </button>
                    </form>
                    <?php if ($tipo === 'login' && $conta !== null): ?>
                        <p class="retorno">Sessão ativa como <strong><?= Seguranca::escapar($conta['login']) ?></strong>.
                            <a href="<?= $destino ?>">Voltar à sua área</a></p>
                    <?php endif; ?>
                <?php elseif ($tipo === 'erro'): ?>
                    <a class="botao secundario" href="/login">Ir para o login <span aria-hidden="true">→</span></a>
                <?php else: ?>
                    <div class="conta">
                        <span class="avatar" aria-hidden="true"><?= Seguranca::escapar(strtoupper(substr($conta['login'], 0, 1))) ?></span>
                        <div>
                            <span class="conta-rotulo">Conta conectada</span>
                            <strong><?= Seguranca::escapar($conta['login']) ?></strong>
                        </div>
                    </div>
                    <div class="acoes">
                        <?php if ($tipo === 'administradores'): ?>
                            <a class="botao primario" href="/cadastro">Cadastrar usuário <span aria-hidden="true">→</span></a>
                        <?php endif; ?>
                        <form method="post" action="<?= $destino ?>">
                            <input type="hidden" name="csrf" value="<?= Seguranca::escapar(Seguranca::csrf()) ?>">
                            <button class="botao secundario" type="submit">Encerrar sessão</button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </body>
    </html>
    <?php
}
