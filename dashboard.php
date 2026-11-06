<?php
require_once 'config.php';
exigirLogin();

function extrairCampoObs($texto, $campo)
{
    $linhas = explode("\n", (string)$texto);

    foreach ($linhas as $linha) {
        if (stripos($linha, $campo . ':') === 0) {
            return trim(substr($linha, strlen($campo . ':')));
        }
    }

    return '';
}

function percentual($parte, $total)
{
    if ($total <= 0) {
        return 0;
    }

    return round(($parte / $total) * 100, 1);
}

function badgeResultado($resultado)
{
    if ($resultado === 'C') {
        return '<span class="badge badge-c">C</span>';
    }

    if ($resultado === 'NC') {
        return '<span class="badge badge-nc">NC</span>';
    }

    if ($resultado === 'NA') {
        return '<span class="badge badge-na">NA</span>';
    }

    return '<span class="badge badge-sem">Sem marcação</span>';
}

$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';
$filtroUnidade = $_GET['unidade'] ?? '';
$filtroArea = $_GET['area'] ?? '';
$filtroResultado = $_GET['resultado'] ?? '';

$where = [];
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

if ($filtroResultado !== '') {
    if ($filtroResultado === 'SEM') {
        $where[] = "(r.resultado IS NULL OR r.resultado = '')";
    } else {
        $where[] = "r.resultado = ?";
        $params[] = $filtroResultado;
    }
}

$whereSql = '';

if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$sql = "
    SELECT 
        e.id AS execucao_id,
        e.unidade,
        e.data_inspecao,
        e.observacao_geral,
        e.area_inspecionada,
        c.nome AS checklist,
        r.resultado,
        r.setor_local,
        r.titulo_nao_conformidade,
        r.detalhamento,
        i.ordem,
        i.titulo AS item_titulo,
        i.setor_local AS item_setor
    FROM execucoes_checklist e
    JOIN checklists c ON c.id = e.checklist_id
    JOIN respostas_checklist r ON r.execucao_id = e.id
    LEFT JOIN checklist_itens i ON i.id = r.item_id
    $whereSql
    ORDER BY e.data_inspecao DESC, e.id DESC, i.ordem ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dadosBrutos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dados = [];

foreach ($dadosBrutos as $linha) {
    $area = trim($linha['area_inspecionada'] ?? '');

    if ($area === '') {
        $area = extrairCampoObs($linha['observacao_geral'] ?? '', 'Área inspecionada');
    }

    if ($area === '') {
        $area = 'Não informado';
    }

    if ($filtroArea !== '' && $area !== $filtroArea) {
        continue;
    }

    $linha['area_inspecionada'] = $area;
    $dados[] = $linha;
}

$unidades = $pdo->query("
    SELECT DISTINCT unidade 
    FROM execucoes_checklist 
    WHERE unidade IS NOT NULL AND unidade <> ''
    ORDER BY unidade
")->fetchAll(PDO::FETCH_COLUMN);

$areasDisponiveis = [];

$areasColuna = $pdo->query("
    SELECT DISTINCT area_inspecionada
    FROM execucoes_checklist
    WHERE area_inspecionada IS NOT NULL AND area_inspecionada <> ''
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($areasColuna as $area) {
    $areasDisponiveis[$area] = $area;
}

$obsAreas = $pdo->query("
    SELECT DISTINCT observacao_geral 
    FROM execucoes_checklist 
    WHERE observacao_geral IS NOT NULL AND observacao_geral <> ''
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($obsAreas as $obs) {
    $area = extrairCampoObs($obs, 'Área inspecionada');

    if ($area !== '') {
        $areasDisponiveis[$area] = $area;
    }
}

ksort($areasDisponiveis);

$totalInspecoesIds = [];
$totalItens = 0;
$totalC = 0;
$totalNC = 0;
$totalNA = 0;
$totalSem = 0;

$porUnidade = [];
$porArea = [];
$porGrupo = [];

$linhasTabela = [];

foreach ($dados as $linha) {
    $execId = $linha['execucao_id'];

    $unidade = trim($linha['unidade'] ?? '');

    if ($unidade === '') {
        $unidade = 'Não informado';
    }

    $area = $linha['area_inspecionada'];

    $grupo = trim($linha['setor_local'] ?? '');

    if ($grupo === '') {
        $grupo = trim($linha['item_setor'] ?? '');
    }

    if ($grupo === '') {
        $grupo = 'Sem grupo';
    }

    $titulo = trim($linha['titulo_nao_conformidade'] ?? '');

    if ($titulo === '') {
        $titulo = trim($linha['item_titulo'] ?? '');
    }

    $partes = explode(' - ', $titulo, 2);

    if (count($partes) === 2) {
        $numeroItem = $partes[0];
        $textoItem = $partes[1];
    } else {
        $numeroItem = $linha['ordem'] ?? '';
        $textoItem = $titulo;
    }

    $resultado = $linha['resultado'] ?? null;

    $totalInspecoesIds[$execId] = true;
    $totalItens++;

    if (!isset($porUnidade[$unidade])) {
        $porUnidade[$unidade] = [
            'total' => 0,
            'C' => 0,
            'NC' => 0,
            'NA' => 0,
            'SEM' => 0
        ];
    }

    if (!isset($porArea[$area])) {
        $porArea[$area] = [
            'total' => 0,
            'C' => 0,
            'NC' => 0,
            'NA' => 0,
            'SEM' => 0
        ];
    }

    if (!isset($porGrupo[$grupo])) {
        $porGrupo[$grupo] = [
            'total' => 0,
            'C' => 0,
            'NC' => 0,
            'NA' => 0,
            'SEM' => 0
        ];
    }

    $porUnidade[$unidade]['total']++;
    $porArea[$area]['total']++;
    $porGrupo[$grupo]['total']++;

    if ($resultado === 'C') {
        $totalC++;
        $porUnidade[$unidade]['C']++;
        $porArea[$area]['C']++;
        $porGrupo[$grupo]['C']++;
    } elseif ($resultado === 'NC') {
        $totalNC++;
        $porUnidade[$unidade]['NC']++;
        $porArea[$area]['NC']++;
        $porGrupo[$grupo]['NC']++;
    } elseif ($resultado === 'NA') {
        $totalNA++;
        $porUnidade[$unidade]['NA']++;
        $porArea[$area]['NA']++;
        $porGrupo[$grupo]['NA']++;
    } else {
        $totalSem++;
        $porUnidade[$unidade]['SEM']++;
        $porArea[$area]['SEM']++;
        $porGrupo[$grupo]['SEM']++;
    }

    $linhasTabela[] = [
        'execucao_id' => $execId,
        'data' => $linha['data_inspecao'],
        'unidade' => $unidade,
        'area' => $area,
        'grupo' => $grupo,
        'numero_item' => $numeroItem,
        'texto_item' => $textoItem,
        'resultado' => $resultado,
        'observacao' => trim($linha['detalhamento'] ?? '')
    ];
}

$totalInspecoes = count($totalInspecoesIds);

$percC = percentual($totalC, $totalItens);
$percNC = percentual($totalNC, $totalItens);
$percNA = percentual($totalNA, $totalItens);
$percSem = percentual($totalSem, $totalItens);

uasort($porUnidade, function($a, $b) {
    return $b['total'] <=> $a['total'];
});

uasort($porArea, function($a, $b) {
    return $b['total'] <=> $a['total'];
});

uasort($porGrupo, function($a, $b) {
    return $b['total'] <=> $a['total'];
});

$linhasTabela = array_slice($linhasTabela, 0, 100);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Dashboard - Inspeções SST</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#f4f6f8;
    color:#1f2937;
    font-family:Arial, Helvetica, sans-serif;
}

.dashboard{
    max-width:1280px;
    margin:24px auto 60px;
    padding:0 18px;
}

.topo{
    background:#ffffff;
    border:1px solid #e5e7eb;
    border-left:7px solid #0b5cab;
    border-radius:14px;
    padding:22px 24px;
    margin-bottom:18px;
    box-shadow:0 4px 14px rgba(0,0,0,.05);
}

.topo h1{
    margin:0;
    color:#102744;
    font-size:28px;
}

.topo p{
    margin:7px 0 0;
    color:#64748b;
}

.filtros{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:18px;
    margin-bottom:18px;
    box-shadow:0 4px 14px rgba(0,0,0,.04);
}

.grid-filtros{
    display:grid;
    grid-template-columns:repeat(6, 1fr);
    gap:12px;
    align-items:end;
}

.campo label{
    display:block;
    font-size:13px;
    font-weight:bold;
    color:#334155;
    margin-bottom:6px;
}

.campo input,
.campo select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:10px;
    background:#fff;
    color:#222;
}

.btn-filtrar{
    background:#0b5cab;
    color:#fff;
    border:0;
    border-radius:8px;
    padding:11px 14px;
    font-weight:bold;
    cursor:pointer;
    width:100%;
}

.btn-limpar{
    display:block;
    background:#64748b;
    color:#fff;
    border-radius:8px;
    padding:11px 14px;
    font-weight:bold;
    text-decoration:none;
    text-align:center;
}

.cards{
    display:grid;
    grid-template-columns:repeat(6, 1fr);
    gap:14px;
    margin-bottom:18px;
}

.card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:18px;
    box-shadow:0 4px 14px rgba(0,0,0,.04);
}

.card span{
    display:block;
    color:#64748b;
    font-size:13px;
    margin-bottom:8px;
}

.card strong{
    display:block;
    font-size:32px;
    color:#0f172a;
}

.card small{
    display:block;
    margin-top:6px;
    color:#64748b;
}

.card-c{
    border-top:5px solid #16a34a;
}

.card-c strong{
    color:#15803d;
}

.card-nc{
    border-top:5px solid #dc2626;
}

.card-nc strong{
    color:#dc2626;
}

.card-na{
    border-top:5px solid #737373;
}

.card-na strong{
    color:#525252;
}

.card-sem{
    border-top:5px solid #d97706;
}

.card-sem strong{
    color:#b45309;
}

.grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:18px;
    margin-bottom:18px;
}

.box{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:14px;
    padding:18px;
    box-shadow:0 4px 14px rgba(0,0,0,.04);
}

.box-full{
    grid-column:1 / -1;
}

.box h2{
    margin:0 0 15px;
    color:#102744;
    font-size:20px;
}

.tabela{
    width:100%;
    border-collapse:collapse;
    font-size:14px;
}

.tabela th,
.tabela td{
    border-bottom:1px solid #e5e7eb;
    padding:10px 8px;
    text-align:left;
    vertical-align:top;
}

.tabela th{
    background:#f8fafc;
    color:#334155;
    font-size:12px;
    text-transform:uppercase;
}

.tabela tr:hover td{
    background:#f8fafc;
}

.badge{
    display:inline-block;
    padding:5px 9px;
    border-radius:999px;
    font-size:12px;
    font-weight:bold;
    white-space:nowrap;
}

.badge-c{
    background:#dcfce7;
    color:#166534;
}

.badge-nc{
    background:#fee2e2;
    color:#991b1b;
}

.badge-na{
    background:#e5e7eb;
    color:#374151;
}

.badge-sem{
    background:#fef3c7;
    color:#92400e;
}

.barra-total{
    display:flex;
    width:100%;
    height:14px;
    background:#e5e7eb;
    border-radius:999px;
    overflow:hidden;
    margin-top:7px;
}

.barra-c{
    background:#22c55e;
}

.barra-nc{
    background:#ef4444;
}

.barra-na{
    background:#9ca3af;
}

.barra-sem{
    background:#f59e0b;
}

.obs{
    margin-top:7px;
    padding:8px;
    border-radius:7px;
    background:#fff7ed;
    color:#9a3412;
    border:1px solid #fed7aa;
    font-size:13px;
}

.link-relatorio{
    color:#0b5cab;
    text-decoration:none;
    font-weight:bold;
}

.link-relatorio:hover{
    text-decoration:underline;
}

.sem-dados{
    text-align:center;
    background:#f8fafc;
    color:#64748b;
    padding:25px;
    border-radius:10px;
}

@media(max-width:1100px){
    .grid-filtros{
        grid-template-columns:1fr 1fr 1fr;
    }

    .cards{
        grid-template-columns:1fr 1fr 1fr;
    }

    .grid{
        grid-template-columns:1fr;
    }
}

@media(max-width:700px){
    .grid-filtros{
        grid-template-columns:1fr;
    }

    .cards{
        grid-template-columns:1fr 1fr;
    }

    .topo h1{
        font-size:22px;
    }

    .tabela{
        font-size:12px;
    }

    .tabela th,
    .tabela td{
        padding:8px 5px;
    }
}

@media(max-width:480px){
    .cards{
        grid-template-columns:1fr;
    }
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="dashboard">

    <div class="topo">
        <h1>Dashboard - Inspeções SST</h1>
        <p>Resumo simples dos itens conformes, não conformes, não aplicáveis e pendentes de marcação.</p>
    </div>

    <div class="filtros">
        <form method="get">
            <div class="grid-filtros">

                <div class="campo">
                    <label>Data inicial</label>
                    <input type="date" name="data_inicio" value="<?= e($dataInicio) ?>">
                </div>

                <div class="campo">
                    <label>Data final</label>
                    <input type="date" name="data_fim" value="<?= e($dataFim) ?>">
                </div>

                <div class="campo">
                    <label>Filial / Unidade</label>
                    <select name="unidade">
                        <option value="">Todas</option>
                        <?php foreach ($unidades as $unidade): ?>
                            <option value="<?= e($unidade) ?>" <?= $filtroUnidade === $unidade ? 'selected' : '' ?>>
                                <?= e($unidade) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label>Área</label>
                    <select name="area">
                        <option value="">Todas</option>
                        <?php foreach ($areasDisponiveis as $area): ?>
                            <option value="<?= e($area) ?>" <?= $filtroArea === $area ? 'selected' : '' ?>>
                                <?= e($area) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label>Status</label>
                    <select name="resultado">
                        <option value="">Todos</option>
                        <option value="C" <?= $filtroResultado === 'C' ? 'selected' : '' ?>>C - Conforme</option>
                        <option value="NC" <?= $filtroResultado === 'NC' ? 'selected' : '' ?>>NC - Não Conforme</option>
                        <option value="NA" <?= $filtroResultado === 'NA' ? 'selected' : '' ?>>NA - Não se aplica</option>
                        <option value="SEM" <?= $filtroResultado === 'SEM' ? 'selected' : '' ?>>Sem marcação</option>
                    </select>
                </div>

                <div class="campo">
                    <button type="submit" class="btn-filtrar">Filtrar</button>
                </div>

            </div>

            <div style="margin-top:10px;">
                <a href="dashboard.php" class="btn-limpar" style="max-width:130px;">Limpar</a>
            </div>
        </form>
    </div>

    <div class="cards">

        <div class="card">
            <span>Inspeções</span>
            <strong><?= $totalInspecoes ?></strong>
            <small>Total no filtro</small>
        </div>

        <div class="card">
            <span>Itens avaliados</span>
            <strong><?= $totalItens ?></strong>
            <small>Total de respostas</small>
        </div>

        <div class="card card-c">
            <span>Conformes</span>
            <strong><?= $totalC ?></strong>
            <small><?= $percC ?>%</small>
        </div>

        <div class="card card-nc">
            <span>Não conformes</span>
            <strong><?= $totalNC ?></strong>
            <small><?= $percNC ?>%</small>
        </div>

        <div class="card card-na">
            <span>Não se aplica</span>
            <strong><?= $totalNA ?></strong>
            <small><?= $percNA ?>%</small>
        </div>

        <div class="card card-sem">
            <span>Sem marcação</span>
            <strong><?= $totalSem ?></strong>
            <small><?= $percSem ?>%</small>
        </div>

    </div>

    <div class="grid">

        <div class="box">
            <h2>Resumo por Filial</h2>

            <?php if (empty($porUnidade)): ?>
                <div class="sem-dados">Nenhum dado encontrado.</div>
            <?php else: ?>
                <table class="tabela">
                    <tr>
                        <th>Filial</th>
                        <th>Total</th>
                        <th>C</th>
                        <th>NC</th>
                        <th>NA</th>
                        <th>Distribuição</th>
                    </tr>

                    <?php foreach ($porUnidade as $unidade => $info): ?>
                        <?php
                            $pC = percentual($info['C'], $info['total']);
                            $pNC = percentual($info['NC'], $info['total']);
                            $pNA = percentual($info['NA'], $info['total']);
                            $pSEM = percentual($info['SEM'], $info['total']);
                        ?>
                        <tr>
                            <td><?= e($unidade) ?></td>
                            <td><?= $info['total'] ?></td>
                            <td><span class="badge badge-c"><?= $info['C'] ?></span></td>
                            <td><span class="badge badge-nc"><?= $info['NC'] ?></span></td>
                            <td><span class="badge badge-na"><?= $info['NA'] ?></span></td>
                            <td>
                                <div class="barra-total">
                                    <div class="barra-c" style="width:<?= $pC ?>%"></div>
                                    <div class="barra-nc" style="width:<?= $pNC ?>%"></div>
                                    <div class="barra-na" style="width:<?= $pNA ?>%"></div>
                                    <div class="barra-sem" style="width:<?= $pSEM ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="box">
            <h2>Resumo por Área</h2>

            <?php if (empty($porArea)): ?>
                <div class="sem-dados">Nenhum dado encontrado.</div>
            <?php else: ?>
                <table class="tabela">
                    <tr>
                        <th>Área</th>
                        <th>Total</th>
                        <th>C</th>
                        <th>NC</th>
                        <th>NA</th>
                        <th>Distribuição</th>
                    </tr>

                    <?php foreach ($porArea as $area => $info): ?>
                        <?php
                            $pC = percentual($info['C'], $info['total']);
                            $pNC = percentual($info['NC'], $info['total']);
                            $pNA = percentual($info['NA'], $info['total']);
                            $pSEM = percentual($info['SEM'], $info['total']);
                        ?>
                        <tr>
                            <td><?= e($area) ?></td>
                            <td><?= $info['total'] ?></td>
                            <td><span class="badge badge-c"><?= $info['C'] ?></span></td>
                            <td><span class="badge badge-nc"><?= $info['NC'] ?></span></td>
                            <td><span class="badge badge-na"><?= $info['NA'] ?></span></td>
                            <td>
                                <div class="barra-total">
                                    <div class="barra-c" style="width:<?= $pC ?>%"></div>
                                    <div class="barra-nc" style="width:<?= $pNC ?>%"></div>
                                    <div class="barra-na" style="width:<?= $pNA ?>%"></div>
                                    <div class="barra-sem" style="width:<?= $pSEM ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="box box-full">
            <h2>Resumo por Grupo do Checklist</h2>

            <?php if (empty($porGrupo)): ?>
                <div class="sem-dados">Nenhum dado encontrado.</div>
            <?php else: ?>
                <table class="tabela">
                    <tr>
                        <th>Grupo</th>
                        <th>Total</th>
                        <th>C</th>
                        <th>NC</th>
                        <th>NA</th>
                        <th>Sem</th>
                        <th>% NC</th>
                    </tr>

                    <?php foreach ($porGrupo as $grupo => $info): ?>
                        <tr>
                            <td><?= e($grupo) ?></td>
                            <td><?= $info['total'] ?></td>
                            <td><span class="badge badge-c"><?= $info['C'] ?></span></td>
                            <td><span class="badge badge-nc"><?= $info['NC'] ?></span></td>
                            <td><span class="badge badge-na"><?= $info['NA'] ?></span></td>
                            <td><span class="badge badge-sem"><?= $info['SEM'] ?></span></td>
                            <td><?= percentual($info['NC'], $info['total']) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="box box-full">
            <h2>Itens Avaliados</h2>

            <?php if (empty($linhasTabela)): ?>
                <div class="sem-dados">Nenhum item encontrado para os filtros selecionados.</div>
            <?php else: ?>
                <table class="tabela">
                    <tr>
                        <th>Data</th>
                        <th>Filial</th>
                        <th>Área</th>
                        <th>Grupo</th>
                        <th>Item</th>
                        <th>Status</th>
                        <th>Observação</th>
                        <th>Relatório</th>
                    </tr>

                    <?php foreach ($linhasTabela as $linha): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($linha['data'])) ?></td>
                            <td><?= e($linha['unidade']) ?></td>
                            <td><?= e($linha['area']) ?></td>
                            <td><?= e($linha['grupo']) ?></td>
                            <td>
                                <strong><?= e($linha['numero_item']) ?></strong>
                                <?= e($linha['texto_item']) ?>
                            </td>
                            <td><?= badgeResultado($linha['resultado']) ?></td>
                            <td>
                                <?php if ($linha['observacao'] !== ''): ?>
                                    <div class="obs"><?= nl2br(e($linha['observacao'])) ?></div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="link-relatorio" href="visualizar_relatorio.php?id=<?= $linha['execucao_id'] ?>">
                                    Abrir
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php if (count($linhasTabela) >= 100): ?>
                    <p style="color:#64748b;margin-top:12px;">
                        Mostrando os 100 registros mais recentes. Use os filtros para refinar a busca.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>

</div>

</body>
</html>