<?php
require_once 'config.php';
exigirLogin();

$usuarioLogado = usuarioAtual();
$isAdmin = ($usuarioLogado['perfil'] ?? $_SESSION['perfil'] ?? '') === 'admin';

// 1. Recebe os parâmetros dos filtros GET
$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';
$filtroUnidade = $_GET['unidade'] ?? '';
$filtroArea = $_GET['area'] ?? '';
$filtroStatus = $_GET['status'] ?? '';

// 2. Monta as condições WHERE do SQL
$where = ["r.resultado = 'NC'"];
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

// 3. Filtro HAVING para o Status do Plano
$having = [];
if ($filtroStatus === 'TRATADO') {
    $having[] = "COUNT(r.id) = SUM(CASE WHEN r.status IN ('Concluído', 'Concluido') THEN 1 ELSE 0 END)";
} elseif ($filtroStatus === 'ABERTO') {
    $having[] = "COUNT(r.id) > SUM(CASE WHEN r.status IN ('Concluído', 'Concluido') THEN 1 ELSE 0 END)";
}

$havingSql = !empty($having) ? "HAVING " . implode(" AND ", $having) : "";

// 4. Busca as inspeções
$sql = "
    SELECT 
        e.id AS id,
        e.data_inspecao,
        e.unidade,
        e.area_inspecionada,
        e.checklist_id,
        COALESCE(c.nome, 'Modelo não encontrado') AS checklist_nome,
        COUNT(r.id) AS total_nc,
        SUM(CASE WHEN r.status IN ('Concluído', 'Concluido') THEN 1 ELSE 0 END) AS nc_concluidas,
        SUM(CASE WHEN (r.acao_tomada IS NOT NULL AND r.acao_tomada <> '') OR (r.status IS NOT NULL AND r.status <> 'Pendente') THEN 1 ELSE 0 END) AS itens_preenchidos
    FROM execucoes_checklist e
    LEFT JOIN checklists c ON c.id = e.checklist_id
    JOIN respostas_checklist r ON r.execucao_id = e.id
    $whereSql
    GROUP BY e.id
    $havingSql
    ORDER BY e.data_inspecao DESC, e.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inspecoesNC = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Opções dos Filtros
$unidades = $pdo->query("
    SELECT DISTINCT e.unidade 
    FROM execucoes_checklist e
    JOIN respostas_checklist r ON r.execucao_id = e.id
    WHERE r.resultado = 'NC' AND e.unidade IS NOT NULL AND e.unidade <> ''
    ORDER BY e.unidade
")->fetchAll(PDO::FETCH_COLUMN);

$areas = $pdo->query("
    SELECT DISTINCT e.area_inspecionada 
    FROM execucoes_checklist e
    JOIN respostas_checklist r ON r.execucao_id = e.id
    WHERE r.resultado = 'NC' AND e.area_inspecionada IS NOT NULL AND e.area_inspecionada <> ''
    ORDER BY e.area_inspecionada
")->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Planos de Ação - SST</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f8fafc; color: #1e293b; font-family: Arial, Helvetica, sans-serif; }

.container-pagina { max-width: 1200px; margin: 28px auto 60px; padding: 0 20px; }

.top-header { 
    display: flex; 
    justify-content: space-between; 
    align-items: flex-start; 
    margin-bottom: 24px; 
    flex-wrap: wrap; 
    gap: 16px;
}
.top-header h1 { margin: 0; font-size: 24px; color: #0f172a; font-weight: 700; }
.top-header p { margin: 4px 0 0; color: #64748b; font-size: 14px; }

.btn-imprimir-lote {
    display: none;
    align-items: center;
    gap: 8px;
    background: #0b5cab;
    color: #ffffff;
    border: 0;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(11, 92, 171, 0.25);
    transition: all 0.2s ease;
    animation: fadeIn 0.2s ease-in-out;
}
.btn-imprimir-lote:hover { background: #084985; }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.alerta-sucesso {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: bold;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

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
    grid-template-columns: repeat(5, 1fr) 90px 90px; 
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
    height: 38px; 
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

.campo-filtro input:focus, .campo-filtro select:focus { border-color: #0b5cab; }

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

.card-tabela { 
    background: #ffffff; 
    border: 1px solid #e2e8f0; 
    border-radius: 12px; 
    padding: 0; 
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); 
    overflow: hidden;
}

.tabela-planos { width: 100%; border-collapse: collapse; font-size: 14px; }
.tabela-planos th, .tabela-planos td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.tabela-planos th { background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }

.tabela-planos tr:last-child td { border-bottom: 0; }
.tabela-planos tr:hover td { background: #f8fafc; }

.th-check, .td-check { width: 40px; text-align: center !important; }
.th-check input, .td-check input { cursor: pointer; width: 16px; height: 16px; }

.badge-nc { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 999px; font-weight: 700; font-size: 11px; display: inline-block; white-space: nowrap; }

.box-progresso-tabela { width: 150px; }
.barra-bg { background: #e2e8f0; height: 7px; border-radius: 999px; overflow: hidden; margin-bottom: 3px; }
.barra-fill { height: 100%; transition: width 0.3s, background-color 0.3s; }
.texto-progresso { font-size: 11px; font-weight: bold; color: #475569; display: block; }

.col-acoes { width: 220px; text-align: center; }
.acoes-flex { display: flex; gap: 6px; align-items: center; justify-content: flex-start; }

.btn-plano, .btn-pdf {
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
    box-sizing: border-box;
    transition: all 0.2s ease;
}

.btn-plano { background: #0b5cab; color: #ffffff; }
.btn-plano:hover { background: #084985; }

.btn-pdf { background: #15803d; color: #ffffff; }
.btn-pdf:hover { background: #11632e; }

@media (max-width: 1024px) {
    .grid-filtros { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); }
    .btn-filtrar, .btn-limpar { width: 100%; }
    .col-acoes { width: auto; }
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="container-pagina">

    <div class="top-header">
        <div>
            <h1>Planos de Ação - SST</h1>
            <p>Acompanhe o percentual e a evolução do tratamento das Não Conformidades (NC).</p>
        </div>

        <button type="button" id="btnImprimirLote" class="btn-imprimir-lote" onclick="imprimirSelecionados()">
            📄 Imprimir Selecionados
        </button>
    </div>

    <?php if (isset($_GET['salvo'])): ?>
        <div class="alerta-sucesso">
            ✅ Alterações do Plano de Ação salvas com sucesso!
        </div>
    <?php endif; ?>

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
                    <label>Status do Plano</label>
                    <select name="status">
                        <option value="">Todos</option>
                        <option value="ABERTO" <?= $filtroStatus === 'ABERTO' ? 'selected' : '' ?>>Em Aberto</option>
                        <option value="TRATADO" <?= $filtroStatus === 'TRATADO' ? 'selected' : '' ?>>Tratado (100%)</option>
                    </select>
                </div>

                <div class="campo-filtro">
                    <button type="submit" class="btn-filtrar">Filtrar</button>
                </div>

                <div class="campo-filtro">
                    <a href="planos_acao.php" class="btn-limpar">Limpar</a>
                </div>
            </div>
        </form>
    </div>

    <div class="card-tabela">
        <?php if (empty($inspecoesNC)): ?>
            <p style="padding:24px; color:#64748b; margin:0; text-align:center;">Nenhuma inspeção com Não Conformidade (NC) encontrada para os filtros selecionados.</p>
        <?php else: ?>
            <table class="tabela-planos">
                <thead>
                    <tr>
                        <th class="th-check">
                            <input type="checkbox" id="checkTodos" onclick="alternarTodos(this)" title="Selecionar Todos">
                        </th>
                        <th>Data</th>
                        <th>Checklist</th>
                        <th>Unidade</th>
                        <th>Área</th>
                        <th>Itens NC</th>
                        <th style="width: 170px;">Status das NCs</th>
                        <th class="col-acoes">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inspecoesNC as $i): ?>
                        <?php 
                            $totalNC = (int)$i['total_nc'];
                            $concluidas = (int)$i['nc_concluidas'];
                            $itensPreenchidos = (int)$i['itens_preenchidos'];
                            $porcentagem = $totalNC > 0 ? round(($concluidas / $totalNC) * 100) : 0;
                            
                            if ($porcentagem <= 35) {
                                $corBarra = '#dc2626';
                            } elseif ($porcentagem <= 70) {
                                $corBarra = '#d97706';
                            } else {
                                $corBarra = '#16a34a';
                            }

                            $podeEditarEstaInspecao = $isAdmin && $porcentagem < 100;

                            if ($podeEditarEstaInspecao) {
                                $textoBotao = ($itensPreenchidos > 0) ? '✏️ Editar' : '✏️ Preencher';
                            } else {
                                $textoBotao = '👁️ Ver plano';
                            }
                        ?>
                        <tr>
                            <td class="td-check">
                                <input type="checkbox" class="check-item" value="<?= $i['id'] ?>" onchange="atualizarContador()">
                            </td>
                            <td><?= date('d/m/Y', strtotime($i['data_inspecao'])) ?></td>
                            <td><?= e($i['checklist_nome']) ?></td>
                            <td><?= e($i['unidade'] ?: '-') ?></td>
                            <td><?= e($i['area_inspecionada'] ?: '-') ?></td>
                            <td><span class="badge-nc"><?= $totalNC ?> NC(s)</span></td>
                            
                            <td>
                                <div class="box-progresso-tabela">
                                    <div class="barra-bg">
                                        <div class="barra-fill" style="width: <?= $porcentagem ?>%; background-color: <?= $corBarra ?>;"></div>
                                    </div>
                                    <span class="texto-progresso">
                                        <?= $porcentagem ?>% (<?= $concluidas ?>/<?= $totalNC ?> resolvidas)
                                    </span>
                                </div>
                            </td>

                            <td class="col-acoes">
                                <div class="acoes-flex">
                                    <a href="preencher_plano.php?id=<?= $i['id'] ?>" class="btn-plano">
                                        <?= $textoBotao ?>
                                    </a>
                                    <a href="relatorio_plano_acao.php?id=<?= $i['id'] ?>" class="btn-pdf">📄 Imprimir</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<script>
function alternarTodos(master) {
    const checkboxes = document.querySelectorAll('.check-item');
    checkboxes.forEach(cb => cb.checked = master.checked);
    atualizarContador();
}

function atualizarContador() {
    const selecionados = document.querySelectorAll('.check-item:checked');
    const total = selecionados.length;
    const btn = document.getElementById('btnImprimirLote');
    const master = document.getElementById('checkTodos');
    const todos = document.querySelectorAll('.check-item');

    if (total > 0) {
        btn.style.display = 'inline-flex';
    } else {
        btn.style.display = 'none';
    }

    if (master && todos.length > 0) {
        master.checked = (total === todos.length);
    }
}

function imprimirSelecionados() {
    const marcados = Array.from(document.querySelectorAll('.check-item:checked')).map(cb => cb.value);
    if (marcados.length === 0) {
        alert('Selecione pelo menos um plano de ação para imprimir.');
        return;
    }
    // Abre a página de múltiplos relatórios já existente no projeto
    window.open('imprimir_multiplos_relatorios.php?ids=' + marcados.join(','), '_blank');
}
</script>

</body>
</html>