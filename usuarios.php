<?php
/**
 * Gestão de usuários do sistema.
 *
 * Acesso: requer login e perfil "admin" (demais perfis recebem "Acesso Negado").
 *
 * POST cadastrar_usuario: nome, email, senha, perfil ("filial" | "admin"), unidade
 * POST excluir_usuario:   id do usuário a remover
 *
 * Fluxo: valida o perfil do usuário logado, processa o cadastro (com validação
 * e senha armazenada em hash) ou a exclusão, e por fim lista todos os usuários
 * na mesma página, exibindo mensagens de sucesso/erro.
 */
require_once 'config.php';
exigirLogin();

// Trava de segurança: apenas administradores gerenciam usuários.
// O perfil é lido do usuário da sessão, com fallback para $_SESSION['perfil'].
$usuarioLogado = usuarioAtual();
$perfilLogado = $usuarioLogado['perfil'] ?? $_SESSION['perfil'] ?? '';

if ($perfilLogado !== 'admin') {
    die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#dc2626;"><h2>Acesso Negado</h2><p>Apenas Administradores podem gerenciar usuários do sistema.</p><a href="nova_execucao.php" style="color:#0b5cab;">Voltar ao início</a></div>');
}

$erro = '';
$sucesso = '';

// Lista fixa de filiais do Grupo JB (usada no <select> de unidade)
$filiaisDisponiveis = [
    'FORTALEZA',
    'IMPERATRIZ',
    'JUAZEIRO',
    'SÃO LUIS',
    'SOBRAL',
    'TERESINA'
];

// 1. Processa o Cadastro de Novo Usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_usuario'])) {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $perfil = $_POST['perfil'] ?? 'filial';
    $unidade = trim($_POST['unidade'] ?? '');

    if (empty($nome) || empty($email) || empty($senha) || empty($unidade)) {
        $erro = 'Por favor, preencha todos os campos obrigatórios, incluindo a Filial.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um endereço de e-mail válido.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } else {
        try {
            // Verifica se o e-mail já está em uso
            $checkEmail = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
            $checkEmail->execute([$email]);

            if ($checkEmail->fetch()) {
                $erro = 'Este e-mail já está cadastrado no sistema.';
            } else {
                // Armazena apenas o hash (bcrypt via PASSWORD_DEFAULT), nunca a senha em texto puro;
                // a verificação no login é feita com password_verify()
                $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, unidade) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$nome, $email, $senhaHash, $perfil, $unidade]);

                $sucesso = "Usuário '{$nome}' cadastrado com sucesso na filial {$unidade}!";
            }
        } catch (Exception $e) {
            $erro = 'Erro ao cadastrar usuário: ' . $e->getMessage();
        }
    }
}

// 2. Processa a Exclusão de Usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_usuario'])) {
    $idExcluir = (int)($_POST['id'] ?? 0);

    // Impede o administrador de excluir a si próprio. Validado no servidor, além de
    // o botão já ficar oculto na listagem, pois o POST pode ser forjado
    if ($idExcluir === (int)$usuarioLogado['id']) {
        $erro = 'Você não pode excluir a sua própria conta logada.';
    } elseif ($idExcluir > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->execute([$idExcluir]);
            $sucesso = 'Usuário excluído com sucesso.';
        } catch (Exception $e) {
            // Falha típica: chave estrangeira impedindo apagar usuário com inspeções/registros vinculados
            $erro = 'Erro ao excluir usuário. É possível que ele possua registros vinculados no sistema.';
        }
    }
}

// 3. Busca a lista de usuários cadastrados (mais recentes primeiro; a coluna senha não é carregada)
$usuarios = $pdo->query("SELECT id, nome, email, perfil, unidade FROM usuarios ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Gestão de Usuários - SST</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f8fafc; color: #1e293b; font-family: Arial, Helvetica, sans-serif; }

.container-pagina { max-width: 1200px; margin: 28px auto 60px; padding: 0 20px; }

/* Topo Header */
.top-header { margin-bottom: 24px; }
.top-header h1 { margin: 0; font-size: 24px; color: #0f172a; font-weight: 700; }
.top-header p { margin: 4px 0 0; color: #64748b; font-size: 14px; }

/* Alertas */
.alerta-sucesso { background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; font-size: 14px; display: flex; align-items: center; gap: 8px; }
.alerta-erro { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; font-size: 14px; display: flex; align-items: center; gap: 8px; }

/* Card Formulário de Cadastro */
.card-cadastro {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 28px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
}

.card-cadastro h2 {
    margin: 0 0 16px 0;
    font-size: 18px;
    color: #1e293b;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 10px;
}

.grid-form {
    display: grid;
    grid-template-columns: repeat(5, 1fr) 120px;
    gap: 12px;
    align-items: end;
}

.campo-form { display: flex; flex-direction: column; }
.campo-form label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.campo-form input,
.campo-form select,
.btn-cadastrar {
    height: 38px;
    border-radius: 8px;
    font-size: 13px;
    box-sizing: border-box;
    margin: 0;
}

.campo-form input,
.campo-form select {
    width: 100%;
    padding: 0 10px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #0f172a;
    outline: none;
    transition: border-color 0.2s;
}

.campo-form input:focus,
.campo-form select:focus {
    border-color: #0b5cab;
}

.btn-cadastrar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    background: #16a34a;
    color: #ffffff;
    border: 0;
    font-weight: 700;
    font-size: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-cadastrar:hover { 
    background: #15803d; 
}

/* Card Tabela */
.card-tabela {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    overflow: hidden;
}

.tabela-usuarios { width: 100%; border-collapse: collapse; font-size: 14px; }
.tabela-usuarios th, .tabela-usuarios td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.tabela-usuarios th { background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

.tabela-usuarios tr:last-child td { border-bottom: 0; }
.tabela-usuarios tr:hover td { background: #f8fafc; }

.badge-perfil {
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    display: inline-block;
    text-transform: uppercase;
}

.badge-admin { background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
.badge-filial { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }

.badge-unidade {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
}

.btn-excluir {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    height: 32px;
    width: 95px;
    padding: 0 4px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 700;
    font-size: 11px;
    white-space: nowrap;
    background: #dc2626;
    color: #ffffff;
    border: 0;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-excluir:hover { background: #b91c1c; }

@media (max-width: 1024px) {
    .grid-form { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
    .btn-cadastrar { width: 100%; }
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="container-pagina">

    <!-- CABEÇALHO -->
    <div class="top-header">
        <h1>Gestão de Usuários</h1>
        <p>Cadastre novos acessos e vincule-os às respectivas filiais.</p>
    </div>

    <?php if ($sucesso): ?>
        <div class="alerta-sucesso">✅ <?= e($sucesso) ?></div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alerta-erro">⚠️ <?= e($erro) ?></div>
    <?php endif; ?>

    <!-- FORMULÁRIO DE NOVO USUÁRIO -->
    <div class="card-cadastro">
        <h2>➕ Cadastrar Novo Usuário</h2>
        <form method="post" autocomplete="off">
            <div class="grid-form">
                <div class="campo-form">
                    <label for="nome">Nome Completo *</label>
                    <input type="text" id="nome" name="nome" placeholder="Ex: João Silva" value="<?= e($_POST['nome'] ?? '') ?>" required>
                </div>

                <div class="campo-form">
                    <label for="email">E-mail de Acesso *</label>
                    <input type="email" id="email" name="email" placeholder="joao@grupojb.com.br" value="<?= e($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="campo-form">
                    <label for="senha">Senha *</label>
                    <input type="password" id="senha" name="senha" placeholder="Mínimo 6 caracteres" required>
                </div>

                <div class="campo-form">
                    <label for="unidade">Filial / Unidade *</label>
                    <select id="unidade" name="unidade" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($filiaisDisponiveis as $f): ?>
                            <option value="<?= $f ?>" <?= ($_POST['unidade'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo-form">
                    <label for="perfil">Perfil *</label>
                    <select id="perfil" name="perfil" required>
                        <option value="filial" <?= ($_POST['perfil'] ?? '') === 'filial' ? 'selected' : '' ?>>Filial (Usuário Comum)</option>
                        <option value="admin" <?= ($_POST['perfil'] ?? '') === 'admin' ? 'selected' : '' ?>>Administrador (Acesso Total)</option>
                    </select>
                </div>

                <div class="campo-form">
                    <button type="submit" name="cadastrar_usuario" value="1" class="btn-cadastrar">Cadastrar</button>
                </div>
            </div>
        </form>
    </div>

    <!-- TABELA DE USUÁRIOS CADASTRADOS -->
    <div class="card-tabela">
        <table class="tabela-usuarios">
            <thead>
                <tr>
                    <th style="width: 80px;">ID</th>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Filial / Unidade</th>
                    <th>Perfil</th>
                    <th style="width: 120px; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td>#<?= $u['id'] ?></td>
                        <td><?= e($u['nome']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td>
                            <span class="badge-unidade"><?= e($u['unidade'] ?: 'NÃO INFORMADA') ?></span>
                        </td>
                        <td>
                            <?php if (($u['perfil'] ?? '') === 'admin'): ?>
                                <span class="badge-perfil badge-admin">Administrador</span>
                            <?php else: ?>
                                <span class="badge-perfil badge-filial">Filial</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php if ((int)$u['id'] !== (int)$usuarioLogado['id']): ?>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Tem certeza que deseja excluir o usuário \'<?= e($u['nome']) ?>\'?');">
                                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                    <button type="submit" name="excluir_usuario" value="1" class="btn-excluir">🗑️ Excluir</button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:11px; color:#94a3b8; font-weight:bold;">(Você)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>