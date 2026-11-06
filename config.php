<?php
session_start();

// Função para ler e carregar o arquivo .env
function carregarEnv($caminho) {
    if (!file_exists($caminho)) {
        return;
    }

    $linhas = file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        
        // Ignora comentários
        if ($linha === '' || str_starts_with($linha, '#')) {
            continue;
        }

        [$chave, $valor] = explode('=', $linha, 2);
        $chave = trim($chave);
        $valor = trim($valor);

        // Remove aspas simples ou duplas ao redor do valor se existirem
        $valor = trim($valor, "\"'");

        if (!array_key_exists($chave, $_SERVER) && !array_key_exists($chave, $_ENV)) {
            putenv("$chave=$valor");
            $_ENV[$chave] = $valor;
            $_SERVER[$chave] = $valor;
        }
    }
}

// Carrega as variáveis do .env na raiz
carregarEnv(__DIR__ . '/.env');

// Leitura das credenciais do ambiente
$host    = getenv('DB_HOST') ?: 'localhost';
$db      = getenv('DB_NAME') ?: 'seguranca_checklist';
$user    = getenv('DB_USER') ?: 'root';
$pass    = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Em produção, evita exibir detalhes de senha/usuário no erro
    die('Erro ao conectar ao banco de dados. Verifique suas configurações.');
}

function exigirLogin() {
    if (!isset($_SESSION['usuario'])) {
        header('Location: login.php');
        exit;
    }
}

function usuarioAtual() {
    return $_SESSION['usuario'] ?? null;
}

function e($texto) {
    return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
}
?>