<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../app/Seguranca.php';

Seguranca::iniciarErros(static function (int $codigo, string $mensagem): never {
    fwrite(STDERR, $mensagem . PHP_EOL);
    exit(1);
});

if ($argc !== 2 || !stream_isatty(STDIN)) {
    fwrite(STDERR, 'Execute em um terminal interativo: php bin/criar-admin.php <login>' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Senha do primeiro administrador: ');
system('stty -echo', $status);
if ($status !== 0) {
    throw new RuntimeException('Falha ao ocultar a entrada no terminal.');
}

try {
    $senha = fgets(STDIN);
} finally {
    system('stty echo');
    fwrite(STDOUT, PHP_EOL);
}
if ($senha === false) {
    throw new FalhaHttp('A senha não foi informada.', 422);
}

Seguranca::criarPrimeiroAdministrador($argv[1], rtrim($senha, "\r\n"));
fwrite(STDOUT, 'Primeiro administrador cadastrado.' . PHP_EOL);
