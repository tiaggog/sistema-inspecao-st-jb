<?php
/**
 * Listagem dos modelos de checklist de inspeção.
 *
 * Acesso: requer login (qualquer perfil).
 *
 * POST excluir_checklist: id do modelo a remover
 *
 * Fluxo: processa a exclusão (somente de modelos sem inspeções registradas) e
 * lista todos os modelos com atalhos para gerenciar itens (itens_checklist.php),
 * iniciar uma inspeção (nova_execucao.php) ou excluir.
 */
require_once 'config.php';
exigirLogin();

$erro = '';
$sucesso = '';

// Exclusão de modelo: bloqueada se já houver execuções vinculadas,
// para não perder o histórico de inspeções e relatórios já emitidos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_checklist'])) {
    $idExcluir = (int)($_POST['id'] ?? 0);

    if ($idExcluir > 0) {
        try {
            $verifica = $pdo->prepare("SELECT COUNT(*) FROM execucoes_checklist WHERE checklist_id = ?");
            $verifica->execute([$idExcluir]);
            $totalExecucoes = (int)$verifica->fetchColumn();

            if ($totalExecucoes > 0) {
                $erro = 'Não foi possível excluir este checklist, pois ele já possui inspeções salvas. Para manter o histórico, ele não pode ser apagado.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM checklists WHERE id = ?");
                $stmt->execute([$idExcluir]);

                $sucesso = 'Checklist excluído com sucesso.';
            }
        } catch (Exception $e) {
            $erro = 'Erro ao excluir checklist: ' . $e->getMessage();
        }
    }
}

// Todos os modelos, do mais recente para o mais antigo
$lista = $pdo->query("SELECT * FROM checklists ORDER BY id DESC")->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Modelos de Checklist - SST</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f8fafc; color: #1e293b; font-family: Arial, Helvetica, sans-serif; }

.container-pagina { max-width: 1180px; margin: 28px auto 60px; padding: 0 20px; }

/* Cabeçalho Topo */
.top-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 15px;
}

.top-header div h1 {
    margin: 0;
    font-size: 24px;
    color: #0f172a;
    font-weight: 700;
}

.top-header div p {
    margin: 4px 0 0;
    color: #64748b;
    font-size: 14px;
}

.btn-novo-checklist {
    background: #16a34a;
    color: #ffffff;
    border: 0;
    padding: 11px 20px;
    border-radius: 8px;
    font-weight: bold;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
    transition: background 0.2s, transform 0.1s;
    text-decoration: none;
}

.btn-novo-checklist:hover {
    background: #15803d;
    transform: translateY(-1px);
}

/* Alertas */
.alerta-sucesso {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
    padding: 14px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: bold;
    font-size: 14px;
}

.alerta-erro {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fca5a5;
    padding: 14px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: bold;
    font-size: 14px;
}

/* Card Tabela */
.card-tabela { 
    background: #ffffff; 
    border: 1px solid #e2e8f0; 
    border-radius: 12px; 
    padding: 0; 
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); 
    overflow-x: auto;
}

.tabela-checklists { width: 100%; border-collapse: collapse; font-size: 14px; }
.tabela-checklists th, .tabela-checklists td { padding: 14px 16px; text-align: left; vertical-align: middle; }
.tabela-checklists th { 
    background: #f8fafc; 
    color: #475569; 
    font-size: 12px; 
    font-weight: 700; 
    text-transform: uppercase; 
    letter-spacing: 0.5px; 
    white-space: nowrap; 
    border-bottom: 1px solid #e2e8f0; 
}

/* Linha sutil interna entre os registros */
.tabela-checklists td {
    border-bottom: 1px solid #f1f5f9;
}

.tabela-checklists tr:last-child td {
    border-bottom: 0;
}

.tabela-checklists tbody tr:hover td {
    background-color: #f8fafc;
}

/* Coluna de Ações */
.col-acoes {
    width: 1%;
    white-space: nowrap;
}

.acoes-flex {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    flex-wrap: nowrap;
    white-space: nowrap;
}

.form-excluir {
    margin: 0;
    padding: 0;
    display: inline-flex;
}

.btn-acao {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 7px 12px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: bold;
    font-size: 12px;
    white-space: nowrap;
    border: 0;
    cursor: pointer;
    transition: opacity 0.2s, background 0.2s;
}

.btn-itens { background: #0b5cab; color: #ffffff; }
.btn-itens:hover { background: #084985; }

.btn-executar { background: #16a34a; color: #ffffff; }
.btn-executar:hover { background: #15803d; }

.btn-excluir { background: #dc2626; color: #ffffff; }
.btn-excluir:hover { background: #b91c1c; }
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="container-pagina">

    <?php if ($sucesso): ?>
        <div class="alerta-sucesso">
            <?= e($sucesso) ?>
        </div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alerta-erro">
            <?= e($erro) ?>
        </div>
    <?php endif; ?>

    <!-- CABEÇALHO SUPERIOR -->
    <div class="top-header">
        <div>
            <h1>Modelos de Checklist</h1>
            <p>Gerenciamento e criação de modelos de inspeção para SST.</p>
        </div>

        <a href="cadastrar_checklist.php" class="btn-novo-checklist">
            <span>+</span> Novo Checklist
        </a>
    </div>

    <!-- TABELA DE CHECKLISTS -->
    <div class="card-tabela">
        <?php if (empty($lista)): ?>
            <p style="padding:24px; color:#64748b; margin:0; text-align:center;">Nenhum checklist cadastrado no momento.</p>
        <?php else: ?>
            <table class="tabela-checklists">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Unidade</th>
                        <th>Código</th>
                        <th class="col-acoes">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista as $c): ?>
                        <tr>
                            <td><?= e($c['nome']) ?></td>
                            <td><?= e($c['unidade'] ?: '-') ?></td>
                            <td><?= e($c['codigo_documento'] ?: '-') ?></td>
                            <td class="col-acoes">
                                <div class="acoes-flex">
                                    <a class="btn-acao btn-itens" href="itens_checklist.php?id=<?= $c['id'] ?>">
                                        📝 Itens
                                    </a>

                                    <a class="btn-acao btn-executar" href="nova_execucao.php?checklist_id=<?= $c['id'] ?>">
                                        ▶️ Executar
                                    </a>

                                    <form method="post" class="form-excluir" onsubmit="return confirm('Tem certeza que deseja excluir este checklist? Esta ação não poderá ser desfeita.');">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" name="excluir_checklist" value="1" class="btn-acao btn-excluir">
                                            🗑️ Excluir
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>