<?php

declare(strict_types=1);

require __DIR__ . '/../app/Seguranca.php';
require __DIR__ . '/../app/paginas.php';

ob_start();
Seguranca::iniciarErros(static function (int $codigo, string $mensagem): void {
    ob_clean();
    http_response_code($codigo);
    pagina('erro', erro: $mensagem, status: $codigo);
});
Seguranca::iniciarWeb();

$rota = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
$conta = Seguranca::autorizarRota($rota, $metodo);

if ($rota === '/') {
    Seguranca::redirecionar('/login', 302);
}

switch ($rota) {
    case '/login':
        if ($metodo === 'POST') {
            if (Seguranca::autenticar($_POST)) {
                Seguranca::redirecionar(Seguranca::destino(Seguranca::sessaoAtual()['perfil']));
            }
            http_response_code(422);
            pagina('login', $conta, 'Login ou senha inválidos.', dados: $_POST);
            break;
        }
        pagina('login', $conta);
        break;

    case '/cadastro':
        if ($metodo === 'POST') {
            try {
                Seguranca::cadastrar($_POST);
            } catch (FalhaHttp $erro) {
                http_response_code($erro->getCode());
                pagina('cadastro', $conta, $erro->getMessage(), dados: $_POST);
                break;
            }
            Seguranca::confirmarCadastro();
            Seguranca::redirecionar('/cadastro');
        }
        $sucesso = $metodo === 'GET' && Seguranca::consumirConfirmacaoCadastro();
        pagina('cadastro', $conta, sucesso: $sucesso);
        break;

    case '/administradores':
    case '/usuarios':
        if ($metodo === 'POST') {
            Seguranca::sair();
        }
        pagina(ltrim($rota, '/'), $conta);
        break;
}
