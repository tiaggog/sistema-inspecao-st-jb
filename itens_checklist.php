<?php 
/**
 * Gerenciamento dos itens (quesitos) de um modelo de checklist.
 *
 * Acesso: requer login (qualquer perfil).
 *
 * GET  id:      id do checklist (obrigatório)
 * GET  editar:  id do item a carregar no formulário para edição (opcional)
 * POST item_id (0 = novo item), titulo, setor_local, ordem
 *
 * Fluxo: carrega o checklist pai, opcionalmente o item em edição, processa a
 * inserção/atualização e redireciona (Post/Redirect/Get); por fim lista os
 * itens ordenados pelo campo "ordem". Cada item é avaliado como C/NC/NA
 * nas inspeções.
 */
require_once 'config.php'; 
exigirLogin();

$id = (int)($_GET['id'] ?? 0);

// Busca dados do checklist pai
$check = $pdo->prepare('SELECT * FROM checklists WHERE id=?');
$check->execute([$id]);
$check = $check->fetch(); 

if (!$check) {
    die('Checklist não encontrado');
}

// Verifica se está no modo de edição de um item específico
$editId = (int)($_GET['editar'] ?? 0);
$itemEdit = null;

if ($editId > 0) {
    // Filtra também por checklist_id: impede carregar um item de outro checklist via URL
    $stmtEdit = $pdo->prepare('SELECT * FROM checklist_itens WHERE id=? AND checklist_id=?');
    $stmtEdit->execute([$editId, $id]);
    $itemEdit = $stmtEdit->fetch();
}

// Processa salvamento (Inserção ou Edição)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $setorLocal = trim($_POST['setor_local'] ?? '');
    // Observação: o formulário atual não envia "base_legal", portanto o campo é gravado vazio
    $baseLegal = trim($_POST['base_legal'] ?? '');
    $ordem = (int)($_POST['ordem'] ?? 1);

    if ($itemId > 0) {
        // Atualiza item existente (restrito ao checklist atual, mesma proteção da edição)
        $stmt = $pdo->prepare('UPDATE checklist_itens SET titulo=?, setor_local=?, base_legal=?, ordem=? WHERE id=? AND checklist_id=?');
        $stmt->execute([$titulo, $setorLocal, $baseLegal, $ordem, $itemId, $id]);
    } else {
        // Insere novo item
        $stmt = $pdo->prepare('INSERT INTO checklist_itens(checklist_id, titulo, setor_local, base_legal, ordem) VALUES(?,?,?,?,?)');
        $stmt->execute([$id, $titulo, $setorLocal, $baseLegal, $ordem]);
    }

    // Post/Redirect/Get: volta à listagem e evita reenvio ao atualizar a página
    header('Location: itens_checklist.php?id=' . $id); 
    exit;
}

// Busca todos os itens do checklist (id como desempate quando a ordem se repete)
$itens = $pdo->prepare('SELECT * FROM checklist_itens WHERE checklist_id=? ORDER BY ordem, id');
$itens->execute([$id]);
$itens = $itens->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Itens do Checklist - SST</title>
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

/* Card Formulário de Cadastro/Edição */
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
    grid-template-columns: 2fr 2fr 100px 180px;
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
.btn-salvar,
.btn-cancelar-acao {
    height: 38px;
    border-radius: 8px;
    font-size: 13px;
    box-sizing: border-box;
    margin: 0;
}

.campo-form input {
    width: 100%;
    padding: 0 10px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #0f172a;
    outline: none;
    transition: border-color 0.2s;
}

.campo-form input:focus {
    border-color: #0b5cab;
}

.acoes-botoes {
    display: flex;
    gap: 8px;
}

.btn-salvar {
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
.btn-salvar:hover { background: #15803d; }

.btn-cancelar-acao {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #64748b;
    color: #ffffff;
    text-decoration: none;
    font-weight: 700;
    font-size: 12px;
    padding: 0 12px;
    transition: background 0.2s;
}
.btn-cancelar-acao:hover { background: #475569; }

/* Card Tabela */
.card-tabela {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    overflow: hidden;
}

.tabela-itens { width: 100%; border-collapse: collapse; font-size: 14px; }
.tabela-itens th, .tabela-itens td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.tabela-itens th { background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }

.tabela-itens tr:last-child td { border-bottom: 0; }
.tabela-itens tr:hover td { background: #f8fafc; }

/* Modelo padrão compactado para o botão de editar */
.btn-editar-item {
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
    background: #0b5cab;
    color: #ffffff;
    transition: background 0.2s;
}
.btn-editar-item:hover { background: #084985; }

@media (max-width: 1024px) {
    .grid-form { grid-template-columns: 1fr; }
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="container-pagina">

    <!-- CABEÇALHO -->
    <div class="top-header">
        <h1>Gerenciamento de Itens</h1>
        <p>Modelo: <strong><?= e($check['nome']) ?></strong></p>
    </div>

    <!-- FORMULÁRIO DE INSERÇÃO / EDIÇÃO -->
    <div class="card-cadastro">
        <h2><?= $itemEdit ? '✏️ Editar Item do Checklist' : '➕ Adicionar Novo Item' ?></h2>

        <form method="post">
            <input type="hidden" name="item_id" value="<?= $itemEdit['id'] ?? 0 ?>">

            <div class="grid-form">
                <div class="campo-form">
                    <label>Item à Inspecionar *</label>
                    <input name="titulo" value="<?= e($itemEdit['titulo'] ?? '') ?>" required placeholder="Ex.: 1.1 - Lixeiras limpas e higienizadas">
                </div>

                <div class="campo-form">
                    <label>Setor / Local</label>
                    <input name="setor_local" value="<?= e($itemEdit['setor_local'] ?? '') ?>" placeholder="Ex.: Organização e Limpeza">
                </div>

                <div class="campo-form">
                    <label>Ordem *</label>
                    <input type="number" name="ordem" value="<?= e($itemEdit['ordem'] ?? (count($itens) + 1)) ?>" required>
                </div>

                <div class="campo-form">
                    <label>&nbsp;</label>
                    <div class="acoes-botoes">
                        <button type="submit" class="btn-salvar"><?= $itemEdit ? 'Salvar' : 'Adicionar' ?></button>
                        
                        <?php if ($itemEdit): ?>
                            <a href="itens_checklist.php?id=<?= $id ?>" class="btn-cancelar-acao">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- TABELA COM LISTAGEM DOS ITENS -->
    <div class="card-tabela">
        <table class="tabela-itens">
            <thead>
                <tr>
                    <th style="width: 80px;">Ordem</th>
                    <th>Título / Quesito</th>
                    <th>Setor</th>
                    <th style="width: 120px; text-align: center;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($itens as $i): ?>
                    <tr style="<?= ($itemEdit && $itemEdit['id'] == $i['id']) ? 'background:#e0f2fe !important;' : '' ?>">
                        <td>#<?= e($i['ordem']) ?></td>
                        <td><?= e($i['titulo']) ?></td>
                        <td><?= e($i['setor_local'] ?: '-') ?></td>
                        <td style="text-align: center;">
                            <a href="itens_checklist.php?id=<?= $id ?>&editar=<?= $i['id'] ?>" class="btn-editar-item">
                                ✏️ Editar
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($itens)): ?>
                    <tr>
                        <td colspan="4" style="text-align:center; color:#64748b; padding:24px;">
                            Nenhum item cadastrado para este checklist.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>
