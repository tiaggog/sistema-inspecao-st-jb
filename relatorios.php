<?php
/**
 * Relatórios de Inspeções — listagem dos checklists concluídos.
 *
 * Acesso: requer login (qualquer perfil).
 *
 * Parâmetros GET (filtros opcionais):
 *   - data_inicio / data_fim : intervalo da data da inspeção (Y-m-d)
 *   - unidade / area         : unidade e área inspecionada
 *
 * Fluxo: aplica os filtros sobre as execuções com status 'concluido', lista os
 * resultados com link para visualizar_relatorio.php e permite selecionar várias
 * inspeções para impressão em lote (imprimir_multiplos_relatorios.php?ids[]=...).
 */
require_once 'config.php';
exigirLogin();

// 1. Recebe os parâmetros dos filtros GET
$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';
$filtroUnidade = $_GET['unidade'] ?? '';
$filtroArea = $_GET['area'] ?? '';

// 2. Monta as condições WHERE do SQL (Garantindo apenas concluídos)
// Inspeções ainda não finalizadas não aparecem nesta lista. Os filtros usam
// placeholders (?) para evitar SQL injection.
$where = ["e.status = 'concluido'"];
$params = [];

if ($dataInicio !== '') {
    $where[] = "e.data_inspecao >= ?";
    $params[] = $dataInicio;
}

if ($dataFim !== '') {
    $where[] = "e.data_inspecao <= ?";
    $params[] = $dataFim;
}

if ($filtroUnidade !== '') {
    $where[] = "e.unidade = ?";
    $params[] = $filtroUnidade;
}

if ($filtroArea !== '') {
    $where[] = "e.area_inspecionada = ?";
    $params[] = $filtroArea;
}

$whereSql = "WHERE " . implode(" AND ", $where);

// 3. Executa a busca das inspeções concluídas
// JOIN com usuarios traz o nome de quem executou a inspeção (coluna "Responsável").
$sql = "
    SELECT e.*, c.nome AS checklist_nome, u.nome AS usuario 
    FROM execucoes_checklist e 
    JOIN checklists c ON c.id = e.checklist_id 
    JOIN usuarios u ON u.id = e.usuario_id 
    $whereSql 
    ORDER BY e.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Busca opções únicas para preencher os selects dos filtros
// Considera apenas inspeções concluídas, para não oferecer opções sem resultado.
$unidades = $pdo->query("
    SELECT DISTINCT unidade 
    FROM execucoes_checklist 
    WHERE status = 'concluido' AND unidade IS NOT NULL AND unidade <> '' 
    ORDER BY unidade
")->fetchAll(PDO::FETCH_COLUMN);

$areas = $pdo->query("
    SELECT DISTINCT area_inspecionada 
    FROM execucoes_checklist 
    WHERE status = 'concluido' AND area_inspecionada IS NOT NULL AND area_inspecionada <> '' 
    ORDER BY area_inspecionada
")->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatórios - SST</title>
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

/* Oculto por padrão via CSS */
.btn-imprimir-lote {
    display: none;
    background: #0b5cab;
    color: #ffffff;
    border: 0;
    padding: 11px 20px;
    border-radius: 8px;
    font-weight: bold;
    font-size: 14px;
    cursor: pointer;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 12px rgba(11, 92, 171, 0.25);
    transition: background 0.2s, transform 0.1s;
    text-decoration: none;
}

.btn-imprimir-lote:hover {
    background: #084985;
    transform: translateY(-1px);
}

/* Card Filtros */
.card-filtros {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
}

.grid-filtros { 
    display: grid; 
    grid-template-columns: repeat(4, 1fr) 90px 90px; 
    gap: 12px; 
    align-items: end;
}

.campo-filtro { display: flex; flex-direction: column; }
.campo-filtro label { 
    display: block; 
    font-size: 11px; 
    font-weight: 700; 
    color: #475569; 
    margin-bottom: 6px; 
    text-transform: uppercase; 
    letter-spacing: 0.5px;
}

.campo-filtro input, 
.campo-filtro select,
.btn-filtrar,
.btn-limpar { 
    height: 36px; 
    border-radius: 8px; 
    font-size: 13px; 
    box-sizing: border-box;
    margin: 0;
}

.campo-filtro input, 
.campo-filtro select { 
    width: 100%; 
    padding: 0 10px; 
    border: 1px solid #cbd5e1; 
    background: #ffffff; 
    color: #0f172a; 
    outline: none;
    transition: border-color 0.2s;
}

.campo-filtro input:focus, .campo-filtro select:focus {
    border-color: #0b5cab;
}

.btn-filtrar, .btn-limpar {
    display: inline-flex; 
    align-items: center; 
    justify-content: center; 
    width: 100%; 
    font-weight: 700; 
    font-size: 12px; 
    text-decoration: none; 
    border: 0; 
    cursor: pointer; 
    transition: all 0.2s ease;
}

.btn-filtrar { background: #16a34a; color: #ffffff; }
.btn-filtrar:hover { background: #15803d; }

.btn-limpar { background: #dc2626; color: #ffffff; }
.btn-limpar:hover { background: #b91c1c; }

/* Card Tabela */
.card-tabela { 
    background: #ffffff; 
    border: 1px solid #e2e8f0; 
    border-radius: 12px; 
    padding: 0; 
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); 
    overflow-x: auto;
}

.tabela-relatorios { width: 100%; border-collapse: collapse; font-size: 14px; }
.tabela-relatorios th, .tabela-relatorios td { padding: 14px 16px; text-align: left; vertical-align: middle; }
.tabela-relatorios th { 
    background: #f8fafc; 
    color: #475569; 
    font-size: 12px; 
    font-weight: 700; 
    text-transform: uppercase; 
    letter-spacing: 0.5px; 
    white-space: nowrap; 
    border-bottom: 1px solid #e2e8f0; 
}

.tabela-relatorios td {
    border-bottom: 1px solid #f1f5f9;
}

.tabela-relatorios tr:last-child td {
    border-bottom: 0;
}

.tabela-relatorios tbody tr:hover td {
    background-color: #f8fafc;
}

.checkbox-lote {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #0b5cab;
}

.btn-visualizar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: #0b5cab;
    color: #ffffff;
    height: 30px;
    padding: 0 12px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: bold;
    font-size: 11px;
    transition: background 0.2s;
    white-space: nowrap;
}

.btn-visualizar:hover {
    background: #084985;
}

@media (max-width: 1024px) {
    .grid-filtros { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); }
    .btn-filtrar, .btn-limpar { width: 100%; }
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="container-pagina">

    <!-- CABEÇALHO SUPERIOR -->
    <div class="top-header">
        <div>
            <h1>Relatórios de Inspeções</h1>
            <p>Lista de relatórios salvos referentes aos checklists concluídos.</p>
        </div>

        <div>
            <button type="button" id="btnImprimirLote" class="btn-imprimir-lote" onclick="imprimirSelecionados()">
                📄 Imprimir Selecionados
            </button>
        </div>
    </div>

    <!-- PAINEL DE FILTROS -->
    <div class="card-filtros">
        <form method="get">
            <div class="grid-filtros">
                <div class="campo-filtro">
                    <label>Data Inicial</label>
                    <input type="date" name="data_inicio" value="<?= e($dataInicio) ?>">
                </div>

                <div class="campo-filtro">
                    <label>Data Final</label>
                    <input type="date" name="data_fim" value="<?= e($dataFim) ?>">
                </div>

                <div class="campo-filtro">
                    <label>Unidade</label>
                    <select name="unidade">
                        <option value="">Todas</option>
                        <?php foreach ($unidades as $u): ?>
                            <option value="<?= e($u) ?>" <?= $filtroUnidade === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo-filtro">
                    <label>Área</label>
                    <select name="area">
                        <option value="">Todas</option>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?= e($a) ?>" <?= $filtroArea === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo-filtro">
                    <button type="submit" class="btn-filtrar">Filtrar</button>
                </div>

                <div class="campo-filtro">
                    <a href="relatorios.php" class="btn-limpar">Limpar</a>
                </div>
            </div>
        </form>
    </div>

    <!-- TABELA DE RESULTADOS -->
    <div class="card-tabela">
        <?php if (empty($lista)): ?>
            <p style="padding:24px; color:#64748b; margin:0; text-align:center;">Nenhum relatório concluído encontrado para os filtros selecionados.</p>
        <?php else: ?>
            <form id="formImpressaoLote" method="get" action="imprimir_multiplos_relatorios.php" target="_blank">
                <table class="tabela-relatorios">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">
                                <input type="checkbox" id="selectAll" class="checkbox-lote" onclick="toggleSelecionarTodos(this)" title="Selecionar Todos">
                            </th>
                            <th style="width: 80px;">ID</th>
                            <th>Checklist</th>
                            <th>Unidade</th>
                            <th>Área</th>
                            <th>Data</th>
                            <th>Responsável</th>
                            <th style="width: 130px; text-align: center;">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($lista as $l): ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="ids[]" value="<?= $l['id'] ?>" class="check-inspecao checkbox-lote" onchange="atualizarBotaoImprimir()">
                                </td>
                                <td>#<?= $l['id'] ?></td>
                                <td><?= e($l['checklist_nome']) ?></td>
                                <td><?= e($l['unidade'] ?: '-') ?></td>
                                <td><?= e($l['area_inspecionada'] ?: '-') ?></td>
                                <td><?= date('d/m/Y', strtotime($l['data_inspecao'])) ?></td>
                                <td><?= e($l['usuario']) ?></td>
                                <td style="text-align: center;">
                                    <a class="btn-visualizar" href="visualizar_relatorio.php?id=<?= $l['id'] ?>">
                                        📄 Visualizar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>
    </div>

</div>

<script>
// Atualiza a visibilidade do botão de impressão dinamicamente
function atualizarBotaoImprimir() {
    var marcados = document.querySelectorAll('.check-inspecao:checked').length;
    var btn = document.getElementById('btnImprimirLote');
    if (btn) {
        btn.style.display = marcados > 0 ? 'inline-flex' : 'none';
    }
}

// Selecionar / Deselecionar todos os checkboxes
function toggleSelecionarTodos(master) {
    var checkboxes = document.querySelectorAll('.check-inspecao');
    checkboxes.forEach(function(cb) {
        cb.checked = master.checked;
    });
    atualizarBotaoImprimir();
}

// Envia o formulário de impressão em lote
function imprimirSelecionados() {
    var marcados = document.querySelectorAll('.check-inspecao:checked');
    if (marcados.length === 0) {
        alert('⚠️ Selecione pelo menos um relatório para imprimir.');
        return;
    }
    document.getElementById('formImpressaoLote').submit();
}
</script>

</body>
</html>