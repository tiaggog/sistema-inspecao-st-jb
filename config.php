<?php
/**
 * Configuração central da aplicação.
 *
 * Incluído (require_once) por todas as páginas do sistema. Responsável por:
 *  - iniciar a sessão PHP (usada para guardar o usuário autenticado);
 *  - carregar as variáveis do arquivo .env da raiz, sem dependências externas;
 *  - abrir a conexão PDO com o MySQL, disponível globalmente em $pdo;
 *  - definir os helpers comuns: exigirLogin(), usuarioAtual() e e().
 *
 * As credenciais nunca ficam no código: são lidas do .env (fora do controle de
 * versão), com valores padrão adequados ao ambiente local do XAMPP.
 */
session_start();

/**
 * Lê um arquivo .env no formato CHAVE=valor e publica as variáveis em
 * getenv(), $_ENV e $_SERVER.
 *
 * Linhas vazias e iniciadas por "#" são ignoradas. Variáveis já definidas no
 * ambiente do servidor têm precedência e não são sobrescritas pelo arquivo.
 *
 * @param string $caminho Caminho absoluto do arquivo .env.
 * @return void Retorna silenciosamente se o arquivo não existir.
 */
function carregarEnv($caminho) {
    if (!file_exists($caminho)) {
        return;
    }

    $linhas = file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        
        // Ignora linhas vazias e comentários
        if ($linha === '' || str_starts_with($linha, '#')) {
            continue;
        }

        // Limite 2: preserva eventuais "=" dentro do valor (ex.: senhas)
        [$chave, $valor] = explode('=', $linha, 2);
        $chave = trim($chave);
        $valor = trim($valor);

        // Remove aspas simples ou duplas ao redor do valor se existirem
        $valor = trim($valor, "\"'");

        // Não sobrescreve variáveis já definidas pelo servidor/ambiente
        if (!array_key_exists($chave, $_SERVER) && !array_key_exists($chave, $_ENV)) {
            putenv("$chave=$valor");
            $_ENV[$chave] = $valor;
            $_SERVER[$chave] = $valor;
        }
    }
}

// Carrega as variáveis do .env na raiz
carregarEnv(__DIR__ . '/.env');

// Leitura das credenciais do ambiente (com padrões para o XAMPP local)
$host    = getenv('DB_HOST') ?: 'localhost';
$db      = getenv('DB_NAME') ?: 'seguranca_checklist';
$user    = getenv('DB_USER') ?: 'root';
$pass    = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

// Conexão PDO: erros viram exceções e os resultados vêm como arrays associativos
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Mensagem genérica de propósito: não expõe host, usuário ou senha na tela
    die('Erro ao conectar ao banco de dados. Verifique suas configurações.');
}

// ---------------------------------------------------------------------------
// Helpers globais
// ---------------------------------------------------------------------------

/**
 * Protege a página: redireciona para o login se não houver usuário na sessão.
 * Deve ser chamada logo após o require do config.php.
 *
 * @return void Encerra a execução (exit) quando o usuário não está autenticado.
 */
function exigirLogin() {
    if (!isset($_SESSION['usuario'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Retorna os dados do usuário autenticado, gravados na sessão pelo login.
 *
 * @return array|null Array do usuário ou null se não houver sessão ativa.
 */
function usuarioAtual() {
    return $_SESSION['usuario'] ?? null;
}

/**
 * Escapa um valor para saída segura em HTML (prevenção de XSS).
 * Usado em todo dado vindo do banco ou do usuário antes de ser exibido.
 *
 * @param mixed $texto Valor a ser exibido (convertido para string).
 * @return string Texto com caracteres especiais convertidos em entidades HTML.
 */
function e($texto) {
    return htmlspecialchars((string)$texto, ENT_QUOTES, 'UTF-8');
}
?>