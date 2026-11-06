<?php
require_once 'config.php';
/**
 * Visualização do relatório de uma inspeção semanal de SST, no layout do
 * formulário oficial (pronto para impressão / PDF pelo navegador).
 *
 * Acesso: requer usuário autenticado (qualquer perfil).
 *
 * Parâmetros GET:
 *   - id : ID da execução de checklist (execucoes_checklist.id)
 *
 * Fluxo: carrega a inspeção com dados do modelo de checklist e do usuário;
 * carrega as respostas na ordem dos itens; resolve os campos de cabeçalho
 * (com fallback para o formato legado em observacao_geral); renderiza a
 * tabela C/NC/NA agrupada por seção, com observação e foto das NCs, resumo,
 * assinaturas e rodapé do documento.
 */
exigirLogin();

$id = (int)($_GET['id'] ?? 0);

// --- Cabeçalho da inspeção ---
$stmt = $pdo->prepare("
    SELECT 
        e.*,
        c.nome AS checklist,
        c.codigo_documento,
        c.data_aprovacao,
        c.introducao,
        c.conclusao,
        u.nome AS usuario
    FROM execucoes_checklist e
    JOIN checklists c ON c.id = e.checklist_id
    JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$rel = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rel) {
    die('Relatório não encontrado');
}

// --- Respostas dos itens ---
// LEFT JOIN mantém respostas cujo item foi removido do modelo; r.id desempata a ordem.
$stmt = $pdo->prepare("
    SELECT 
        r.*,
        i.ordem,
        i.titulo AS item_titulo,
        i.setor_local AS item_setor
    FROM respostas_checklist r
    LEFT JOIN checklist_itens i ON i.id = r.item_id
    WHERE r.execucao_id = ?
    ORDER BY i.ordem, r.id
");
$stmt->execute([$id]);
$resps = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Extrai o valor de um campo gravado como "Campo: valor" em texto livre
 * (formato usado por inspeções antigas em observacao_geral).
 *
 * @param string|null $texto Texto multilinha de origem.
 * @param string      $campo Nome do campo, sem os dois-pontos.
 * @return string Valor encontrado ou string vazia.
 */
function extrairInfoObservacao($texto, $campo)
{
    $linhas = explode("\n", (string)$texto);

    foreach ($linhas as $linha) {
        if (stripos($linha, $campo . ':') === 0) {
            return trim(substr($linha, strlen($campo . ':')));
        }
    }

    return '';
}

/**
 * Símbolo da coluna C/NC/NA: círculo cheio na coluna marcada, vazio nas demais.
 *
 * @param string|null $resultado Resultado gravado para o item.
 * @param string      $tipo      Coluna sendo renderizada (C, NC ou NA).
 * @return string
 */
function marcarResultado($resultado, $tipo)
{
    return $resultado === $tipo ? '●' : '○';
}

/**
 * Define a classe CSS (cor da faixa) da seção a partir do nome do setor.
 * A comparação por palavra-chave aceita variações com e sem acento.
 *
 * @param string|null $setor Nome da seção/setor do checklist.
 * @return string Classe CSS (sec-org, sec-estrutura, sec-refeitorio ou sec-padrao).
 */
function classeSecao($setor)
{
    $setor = (string)$setor;

    if (stripos($setor, 'Organização') !== false || stripos($setor, 'Limpeza') !== false) {
        return 'sec-org';
    }

    if (
        stripos($setor, 'Estrutura') !== false ||
        stripos($setor, 'Iluminação') !== false ||
        stripos($setor, 'Elétricas') !== false ||
        stripos($setor, 'Eletricas') !== false
    ) {
        return 'sec-estrutura';
    }

    if (
        stripos($setor, 'Bebedouros') !== false ||
        stripos($setor, 'Refeitórios') !== false ||
        stripos($setor, 'Refeitorios') !== false
    ) {
        return 'sec-refeitorio';
    }

    return 'sec-padrao';
}

/**
 * Separa um título no padrão "1.2 - Descrição" em número e texto.
 * Sem o separador, usa a ordem do item como número.
 *
 * @param string|null    $titulo
 * @param int|string     $ordem
 * @return array{0: string, 1: string} [numero, texto]
 */
function separarItem($titulo, $ordem)
{
    $partes = explode(' - ', (string)$titulo, 2);

    if (count($partes) === 2) {
        return [$partes[0], $partes[1]];
    }

    return [$ordem, $titulo];
}

// --- Campos do cabeçalho ---
// Leitura com fallback para inspeções criadas antes da mudança de banco de dados:
// nesses registros os campos estavam como linhas "Campo: valor" em observacao_geral.
$semanaReferencia = trim($rel['semana_referencia'] ?? '');
if ($semanaReferencia === '') {
    $semanaReferencia = extrairInfoObservacao($rel['observacao_geral'] ?? '', 'Semana de referência');
}

$areaInspecionada = trim($rel['area_inspecionada'] ?? '');
if ($areaInspecionada === '') {
    $areaInspecionada = extrairInfoObservacao($rel['observacao_geral'] ?? '', 'Área inspecionada');
}

$responsavelInspecao = trim($rel['responsavel_inspecao'] ?? '');
if ($responsavelInspecao === '') {
    $responsavelInspecao = extrairInfoObservacao($rel['observacao_geral'] ?? '', 'Responsável pela inspeção');
}

// No formato legado, exibe apenas a linha "Observação geral:", descartando
// os metadados que já foram lidos acima.
$observacaoGeral = trim($rel['observacao_geral'] ?? '');
if (stripos($observacaoGeral, 'Observação geral:') !== false) {
    $observacaoGeral = extrairInfoObservacao($observacaoGeral, 'Observação geral');
}

// Sem responsável informado, assume o usuário que registrou a inspeção.
if ($responsavelInspecao === '') {
    $responsavelInspecao = $rel['usuario'];
}

$dataFormatada = '';
if (!empty($rel['data_inspecao'])) {
    $dataFormatada = date('d/m/Y', strtotime($rel['data_inspecao']));
}

$dataAprovacaoDoc = !empty($rel['data_aprovacao']) ? date('d/m/Y', strtotime($rel['data_aprovacao'])) : 'Não informada';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório de Inspeção - SST #<?= $rel['id'] ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
*{
    box-sizing:border-box;
}

body{
    margin:0;
    background:#f1f3f6;
    color:#1f2933;
    font-family:Arial, Helvetica, sans-serif;
}

.barra-acoes{
    max-width:1100px;
    margin:20px auto 0;
    display:flex;
    gap:10px;
    padding:0 18px;
}

.btn-relatorio{
    background:#0b5cab;
    color:#fff;
    border:0;
    padding:10px 18px;
    border-radius:6px;
    cursor:pointer;
    font-weight:bold;
    text-decoration:none;
    display:inline-block;
}

.btn-relatorio:hover{
    background:#084985;
}

.btn-voltar{
    background:#64748b;
}

.relatorio-wrap{
    max-width:1100px;
    margin:20px auto 50px;
    background:#fff;
    border:1px solid #cbd5e1;
    padding:40px;
    box-shadow:0 5px 18px rgba(0,0,0,.08);
}

/* Grid do Topo com 3 colunas idêntico ao Plano de Ação */
.topo-relatorio{ 
    display:grid; 
    grid-template-columns:160px 1fr 160px; 
    border:1px solid #222; 
    align-items:center; 
    margin-bottom:20px; 
}

/* Caixa das Logos (Esquerda e Direita) */
.logo-box{ 
    height:90px; 
    display:flex; 
    align-items:center; 
    justify-content:center; 
    padding:6px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.logo-left {
    background:#102744 !important; 
    border-right:1px solid #222; 
}

.logo-right {
    background:#f8fafc !important; 
    border-left:1px solid #222; 
}

.img-logo-topo{
    max-height:75px;
    max-width:140px;
    width:auto;
    height:auto;
    object-fit:contain;
}

.titulo-relatorio{ text-align:center; padding:10px; }
.titulo-relatorio h1{ margin:0; font-size:20px; color:#102744; }
.titulo-relatorio h2{ margin:4px 0 0; font-size:14px; color:#475569; font-weight:normal; }

.info-relatorio{
    border:1px solid #333;
    margin-bottom:25px;
    font-size:13px;
}

.linha-info{
    display:grid;
    grid-template-columns:repeat(3, 1fr);
    border-bottom:1px solid #333;
}

.linha-info:last-child{
    border-bottom:0;
}

.campo-info{
    padding:8px 10px;
    border-right:1px solid #333;
}

.campo-info:last-child{
    border-right:0;
}

.campo-info strong{
    display:block;
    color:#1e293b;
    margin-bottom:2px;
}

.campo-vazio{
    color:#777;
}

.area-box{
    display:flex;
    gap:12px;
    flex-wrap:wrap;
    align-items:center;
}

.area-opcao{
    display:inline-flex;
    align-items:center;
    gap:4px;
}

.caixa{
    width:13px;
    height:13px;
    border:1px solid #333;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    line-height:1;
}

.legenda{
    margin-bottom:20px;
    border:1px solid #444;
    padding:8px 10px;
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
    font-size:12px;
    color:#222;
}

.tag-legenda{
    padding:4px 10px;
    font-weight:bold;
    color:#000;
    border-radius:3px;
}

.tag-c{
    background:#00bf63 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.tag-nc{
    background:#ff3131 !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.tag-na{
    background:#bfbfbf !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.tabela-relatorio{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
    color:#222;
    margin-bottom:25px;
}

.tabela-relatorio th,
.tabela-relatorio td{
    border:1px solid #444;
    padding:8px;
}

.tabela-relatorio th{
    background:#e2e8f0 !important;
    color:#0f172a;
    font-weight:bold;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.th-item{
    width:65px;
}

.th-c{
    background:#00bf63 !important;
    color:#000 !important;
    width:50px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.th-nc{
    background:#ff6969 !important;
    color:#000 !important;
    width:50px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.th-na{
    background:#8d8d8d !important;
    color:#000 !important;
    width:50px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.sec-org td{
    background:#2e471f !important;
    color:#fff !important;
    font-weight:bold;
    text-align:center;
    padding:10px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.sec-estrutura td{
    background:#344958 !important;
    color:#fff !important;
    font-weight:bold;
    text-align:center;
    padding:10px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.sec-refeitorio td{
    background:#6b4f2a !important;
    color:#fff !important;
    font-weight:bold;
    text-align:center;
    padding:10px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.sec-padrao td{
    background:#394150 !important;
    color:#fff !important;
    font-weight:bold;
    text-align:center;
    padding:10px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.item-num{
    width:65px;
    text-align:center;
    font-weight:bold;
}

.item-texto{
    line-height:1.35;
}

.resultado-cell{
    text-align:center;
    font-size:20px;
    font-weight:bold;
}

.resultado-c{ color:#009b5a; }
.resultado-nc{ color:#dc2626; }
.resultado-na{ color:#737373; }

.linha-observacao td{
    background:#f8fafc;
    color:#334155;
    font-size:13px;
    padding:10px 12px;
}

.linha-observacao strong{
    color:#991b1b;
}

/* Estilo para Foto no Relatório */
.img-evidencia-relatorio {
    max-width: 220px;
    max-height: 150px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    margin-top: 8px;
    display: block;
    object-fit: cover;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.resumo{
    margin-top:20px;
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:12px;
}

.card-resumo{
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:12px;
    background:#f8fafc;
    text-align:center;
}

.card-resumo strong{
    display:block;
    font-size:24px;
    margin-bottom:2px;
}

.card-resumo span{
    font-size:12px;
    color:#475569;
}

.obs-geral{
    margin-top:20px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    padding:12px;
    background:#f8fafc;
}

.obs-geral h3{
    margin:0 0 6px;
    font-size:15px;
}

/* Assinaturas Centralizadas e limpas */
.assinaturas{
    margin-top:50px;
    display:flex;
    justify-content:space-around;
    text-align:center;
}

.assinatura{
    border-top:1px solid #333;
    padding-top:8px;
    font-size:13px;
    font-weight:bold;
    width:280px;
}

/* Rodapé Padrão do Documento */
.rodape-documento-padrao {
    margin-top: 50px;
    padding-top: 14px;
    border-top: 1px solid #cbd5e1;
    display: grid;
    grid-template-columns: 1fr 2fr 1fr;
    align-items: center;
    font-size: 11px;
    color: #64748b;
    line-height: 1.4;
}

.rodape-col-esq { text-align: left; }
.rodape-col-centro { text-align: center; }
.rodape-col-dir { text-align: right; font-weight: bold; }

/* REGRA DE IMPRESSÃO */
@page {
    margin: 0;
}

@media print{
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    body{
        background:#fff;
        padding: 12mm;
    }

    .topo,
    .topo-smart-hub,
    .no-print,
    .barra-acoes,
    nav,
    header{
        display:none !important;
    }

    .relatorio-wrap{
        max-width:100%;
        margin:0;
        padding:0;
        border:0;
        box-shadow:none;
    }

    .topo-relatorio {
        display: grid !important;
        grid-template-columns: 160px 1fr 160px !important;
    }

    .tabela-relatorio{
        font-size:11px;
    }

    .resultado-cell{
        font-size:16px;
    }

    .linha-observacao{
        page-break-inside:avoid;
    }

    .img-evidencia-relatorio {
        max-width: 180px;
        max-height: 130px;
        page-break-inside: avoid;
    }

    .sec-org,
    .sec-estrutura,
    .sec-refeitorio,
    .sec-padrao{
        page-break-after:avoid;
    }

    .rodape-documento-padrao {
        page-break-inside: avoid;
    }
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="barra-acoes no-print">
    <a href="relatorios.php" class="btn-relatorio btn-voltar">Voltar</a>
    <button class="btn-relatorio" onclick="window.print()">🖨️ Imprimir / Salvar em PDF</button>
</div>

<?php
$totalItens = count($resps);
// --- Totais do resumo: contagem por resultado (C, NC, NA e itens sem marcação) ---
$totalC = 0;
$totalNC = 0;
$totalNA = 0;
$totalNaoMarcados = 0;

foreach ($resps as $r) {
    $resultado = $r['resultado'] ?? null;

    if ($resultado === 'C') {
        $totalC++;
    } elseif ($resultado === 'NC') {
        $totalNC++;
    } elseif ($resultado === 'NA') {
        $totalNA++;
    } else {
        $totalNaoMarcados++;
    }
}
?>

<div class="relatorio-wrap">

    <div class="topo-relatorio">
        <div class="logo-box logo-left">
            <img src="logo_jb.png" alt="Logo Grupo JB" class="img-logo-topo">
        </div>

        <div class="titulo-relatorio">
            <h1>INSPEÇÃO SEMANAL</h1>
            <h2>SAÚDE E SEGURANÇA DO TRABALHO</h2>
        </div>

        <div class="logo-box logo-right">
            <img src="logo_st.png" alt="Logo Saúde e Segurança do Trabalho" class="img-logo-topo">
        </div>
    </div>

    <div class="info-relatorio">
        <div class="linha-info">
            <div class="campo-info">
                <strong>DATA DE INSPEÇÃO:</strong>
                <?= e($dataFormatada) ?>
            </div>

            <div class="campo-info">
                <strong>SEMANA DE REFERÊNCIA:</strong>
                <?= $semanaReferencia !== '' ? e($semanaReferencia) : '<span class="campo-vazio">Não informado</span>' ?>
            </div>

            <div class="campo-info">
                <strong>UNIDADE:</strong>
                <?= e($rel['unidade']) ?>
            </div>
        </div>

        <div class="linha-info">
            <div class="campo-info" style="grid-column:1 / -1;">
                <strong>ÁREA INSPECIONADA:</strong>

                <div class="area-box">
                    <?php
                    // Reproduz as caixas de seleção do formulário impresso; áreas fora
                    // da lista são gravadas como "OUTRO: descrição".
                    $areas = ['RH', 'RECEPÇÃO', 'ADM', 'TI', 'TORRE'];
                    foreach ($areas as $area):
                        $marcado = strtoupper($areaInspecionada) === strtoupper($area);
                    ?>
                        <span class="area-opcao">
                            <span class="caixa"><?= $marcado ? 'X' : '' ?></span>
                            <?= e($area) ?>
                        </span>
                    <?php endforeach; ?>

                    <span class="area-opcao">
                        <span class="caixa"><?= stripos($areaInspecionada, 'OUTRO') === 0 ? 'X' : '' ?></span>
                        OUTRO:
                        <?= stripos($areaInspecionada, 'OUTRO') === 0 ? e(str_replace('OUTRO:', '', $areaInspecionada)) : '____________________' ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="linha-info">
            <div class="campo-info" style="grid-column:1 / -1;">
                <strong>RESPONSÁVEL PELA INSPEÇÃO:</strong>
                <?= e($responsavelInspecao) ?>
            </div>
        </div>
    </div>

    <div class="legenda">
        <strong>LEGENDA:</strong>
        <span class="tag-legenda tag-c">C</span> CONFORME
        <span class="tag-legenda tag-nc">NC</span> NÃO CONFORME
        <span class="tag-legenda tag-na">NA</span> NÃO SE APLICA
    </div>

    <table class="tabela-relatorio">
        <thead>
            <tr>
                <th class="th-item">ITEM</th>
                <th>QUESITOS A SEREM OBSERVADOS</th>
                <th class="th-c">C</th>
                <th class="th-nc">NC</th>
                <th class="th-na">NA</th>
            </tr>
        </thead>

        <tbody>
        <?php
        // --- Itens agrupados por seção ---
        // As respostas já vêm ordenadas; uma faixa de seção é emitida sempre que o setor muda.
        $secaoAtual = '';

        foreach ($resps as $r):
            $setor = trim($r['setor_local'] ?? '');

            if ($setor === '') {
                $setor = trim($r['item_setor'] ?? '');
            }

            if ($setor === '') {
                $setor = 'Itens do checklist';
            }

            if ($setor !== $secaoAtual):
                $secaoAtual = $setor;
                $classe = classeSecao($secaoAtual);
        ?>
            <tr class="<?= $classe ?>">
                <td colspan="5"><?= e($secaoAtual) ?></td>
            </tr>
        <?php endif; ?>

        <?php
            // O título salvo na resposta prevalece sobre o do modelo (preserva o texto da época).
            // Observação e foto só são exibidas para itens NC.
            $titulo = $r['titulo_nao_conformidade'] ?: ($r['item_titulo'] ?? '');
            [$numeroItem, $textoItem] = separarItem($titulo, $r['ordem'] ?? '');

            $resultado = $r['resultado'] ?? null;
            $temObs = trim($r['detalhamento'] ?? '') !== '';
            $temFoto = !empty($r['foto']);
        ?>

            <tr>
                <td class="item-num"><?= e($numeroItem) ?></td>

                <td class="item-texto">
                    <?= e($textoItem) ?>
                </td>

                <td class="resultado-cell resultado-c">
                    <?= marcarResultado($resultado, 'C') ?>
                </td>

                <td class="resultado-cell resultado-nc">
                    <?= marcarResultado($resultado, 'NC') ?>
                </td>

                <td class="resultado-cell resultado-na">
                    <?= marcarResultado($resultado, 'NA') ?>
                </td>
            </tr>

            <?php if ($resultado === 'NC' && ($temObs || $temFoto)): ?>
                <tr class="linha-observacao">
                    <td colspan="5">
                        <?php if ($temObs): ?>
                            <strong>Observação da não conformidade:</strong>
                            <?= nl2br(e($r['detalhamento'])) ?>
                        <?php endif; ?>

                        <?php if ($temFoto): ?>
                            <div style="<?= $temObs ? 'margin-top: 10px;' : '' ?>">
                                <strong style="color: #334155; font-size: 13px;">📷 Evidência Fotográfica:</strong><br>
                                <a href="<?= e($r['foto']) ?>" target="_blank" title="Clique para ampliar">
                                    <img src="<?= e($r['foto']) ?>" class="img-evidencia-relatorio" alt="Evidência NC" onerror="this.parentNode.style.display='none';">
                                </a>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>

        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="resumo">
        <div class="card-resumo">
            <strong><?= $totalItens ?></strong>
            <span>Total de itens</span>
        </div>

        <div class="card-resumo">
            <strong style="color:#009b5a;"><?= $totalC ?></strong>
            <span>Conformes</span>
        </div>

        <div class="card-resumo">
            <strong style="color:#dc2626;"><?= $totalNC ?></strong>
            <span>Não conformes</span>
        </div>

        <div class="card-resumo">
            <strong style="color:#737373;"><?= $totalNA ?></strong>
            <span>Não se aplica</span>
        </div>
    </div>

    <?php if ($totalNaoMarcados > 0): ?>
        <div class="obs-geral">
            <h3>Atenção</h3>
            <p>
                Existem <strong><?= $totalNaoMarcados ?></strong> item(ns) sem marcação de C, NC ou NA.
            </p>
        </div>
    <?php endif; ?>

    <?php if (trim($observacaoGeral) !== ''): ?>
        <div class="obs-geral">
            <h3>Observação Geral</h3>
            <p><?= nl2br(e($observacaoGeral)) ?></p>
        </div>
    <?php endif; ?>

    <div class="assinaturas">
        <div class="assinatura">
            Responsável pela Inspeção
        </div>

        <div class="assinatura">
            Responsável pela Área
        </div>
    </div>

    <!-- Rodapé Padronizado -->
    <div class="rodape-documento-padrao">
        <div class="rodape-col-esq">
            <div><?= e($rel['codigo_documento'] ?: 'FRPR01-00') ?></div>
            <div>Aprovação: <?= e($dataAprovacaoDoc) ?></div>
        </div>
        <div class="rodape-col-centro">
            <div>Grupo JB Participações LTDA | CNPJ 48.015.520/0001-02</div>
            <div>Avenida Santos Dumont 2828 1808, Aldeota</div>
            <div>Fortaleza/CE, 60150-162</div>
        </div>
        <div class="rodape-col-dir">
            1
        </div>
    </div>

</div>

</body>
</html>