<?php
require_once 'config.php';
exigirLogin();

$execId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT 
        e.*, 
        c.nome AS checklist_nome,
        c.codigo_documento,
        c.data_aprovacao
    FROM execucoes_checklist e 
    JOIN checklists c ON c.id = e.checklist_id 
    WHERE e.id = ?
");
$stmt->execute([$execId]);
$inspecao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$inspecao) {
    die('Inspeção não encontrada.');
}

$stmtNC = $pdo->prepare("
    SELECT r.*, i.ordem, i.titulo AS item_titulo, i.setor_local AS item_setor
    FROM respostas_checklist r
    LEFT JOIN checklist_itens i ON i.id = r.item_id
    WHERE r.execucao_id = ? AND r.resultado = 'NC'
    ORDER BY r.id
");
$stmtNC->execute([$execId]);
$itensNC = $stmtNC->fetchAll(PDO::FETCH_ASSOC);

$dataAprovacaoDoc = !empty($inspecao['data_aprovacao']) ? date('d/m/Y', strtotime($inspecao['data_aprovacao'])) : 'Não informada';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Plano de Ação - SST #<?= $inspecao['id'] ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
*{ box-sizing:border-box; }
body{ margin:0; background:#f1f3f6; color:#1f2933; font-family:Arial, Helvetica, sans-serif; }

.barra-acoes{ max-width:1100px; margin:20px auto 0; display:flex; gap:10px; padding:0 18px; }
.btn-acao{ background:#0b5cab; color:#fff; border:0; padding:10px 18px; border-radius:6px; font-weight:bold; cursor:pointer; text-decoration:none; }
.btn-voltar{ background:#64748b; }

.relatorio-pdf{ max-width:1100px; margin:20px auto 50px; background:#fff; border:1px solid #cbd5e1; padding:40px; box-shadow:0 5px 18px rgba(0,0,0,.08); }

/* Grid do Topo com 3 colunas para as duas logos */
.topo-pdf{ 
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

.titulo-box{ text-align:center; padding:10px; }
.titulo-box h1{ margin:0; font-size:20px; color:#102744; }
.titulo-box h2{ margin:4px 0 0; font-size:14px; color:#475569; font-weight:normal; }

.info-grid{ display:grid; grid-template-columns:repeat(3, 1fr); border:1px solid #333; margin-bottom:25px; font-size:13px; }
.info-item{ padding:8px 10px; border-right:1px solid #333; }
.info-item:last-child{ border-right:0; }
.info-item strong{ display:block; color:#1e293b; margin-bottom:2px; }

.tabela-pdf{ width:100%; border-collapse:collapse; font-size:13px; margin-bottom:30px; }
.tabela-pdf th, .tabela-pdf td{ border:1px solid #444; padding:8px; text-align:left; vertical-align:top; }
.tabela-pdf th{ 
    background:#e2e8f0 !important; 
    color:#0f172a; 
    font-weight:bold; 
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.badge-risco{ 
    padding:3px 6px; 
    border-radius:4px; 
    font-weight:bold; 
    font-size:11px; 
    display:inline-block; 
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}
.risco-Alto, .risco-Crítico{ background:#fee2e2 !important; color:#991b1b !important; }
.risco-Médio{ background:#fef3c7 !important; color:#92400e !important; }
.risco-Baixo, .risco-Aceitável{ background:#dcfce7 !important; color:#166534 !important; }

/* Estilo para a Foto Ampliada */
.img-evidencia-coluna {
    max-width: 180px;
    max-height: 140px;
    width: auto;
    height: auto;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    display: block;
    margin: 0 auto;
    object-fit: cover;
    box-shadow: 0 2px 6px rgba(0,0,0,0.12);
}

/* Assinatura Única Centralizada */
.assinaturas{ 
    margin-top:60px; 
    display:flex; 
    justify-content:center; 
    text-align:center; 
}
.assinatura-linha{ 
    border-top:1px solid #333; 
    padding-top:8px; 
    font-size:13px; 
    font-weight:bold; 
    width:360px;
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
    .topo, .topo-smart-hub, .barra-acoes, nav, header{ display:none !important; }
    .relatorio-pdf{ border:0; box-shadow:none; padding:0; margin:0; max-width:100%; }
    .tabela-pdf{ font-size:11px; }
    .img-evidencia-coluna { max-width: 150px; max-height: 110px; page-break-inside: avoid; }
    .rodape-documento-padrao { page-break-inside: avoid; }
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="barra-acoes">
    <a href="planos_acao.php" class="btn-acao btn-voltar">Voltar</a>
    <button onclick="window.print()" class="btn-acao">🖨️ Imprimir / Salvar em PDF</button>
</div>

<div class="relatorio-pdf">
    <div class="topo-pdf">
        <div class="logo-box logo-left">
            <img src="logo_jb.png" alt="Logo Grupo JB" class="img-logo-topo">
        </div>

        <div class="titulo-box">
            <h1>PLANO DE AÇÃO DE SEGURANÇA DO TRABALHO</h1>
            <h2>Tratativa de Não Conformidades (NC)</h2>
        </div>

        <div class="logo-box logo-right">
            <img src="logo_st.png" alt="Logo Saúde e Segurança do Trabalho" class="img-logo-topo">
        </div>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <strong>CHECKLIST:</strong>
            <?= e($inspecao['checklist_nome']) ?>
        </div>
        <div class="info-item">
            <strong>UNIDADE:</strong>
            <?= e($inspecao['unidade']) ?>
        </div>
        <div class="info-item">
            <strong>DATA DE INSPEÇÃO:</strong>
            <?= date('d/m/Y', strtotime($inspecao['data_inspecao'])) ?>
        </div>
        <div class="info-item" style="grid-column: 1 / -1; border-top:1px solid #333;">
            <strong>RESPONSÁVEL PELA INSPEÇÃO:</strong>
            <?= e($inspecao['responsavel_inspecao'] ?: 'Não informado') ?>
        </div>
    </div>

    <table class="tabela-pdf">
        <thead>
            <tr>
                <th style="width:25%;">Item / Problema</th>
                <th style="width:25%; text-align:center;">Evidência Fotográfica</th>
                <th style="width:28%;">Ação Corretiva</th>
                <th style="width:8%;">Risco</th>
                <th style="width:8%;">Responsável</th>
                <th style="width:6%;">Prazo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($itensNC as $nc): ?>
                <?php $titulo = $nc['titulo_nao_conformidade'] ?: $nc['item_titulo']; ?>
                <tr>
                    <td>
                        <strong><?= e($titulo) ?></strong>
                        <?php if ($nc['detalhamento']): ?>
                            <br><small style="color:#64748b;"><?= e($nc['detalhamento']) ?></small>
                        <?php endif; ?>
                    </td>

                    <td style="text-align:center; vertical-align:middle;">
                        <?php if (!empty($nc['foto'])): ?>
                            <a href="<?= e($nc['foto']) ?>" target="_blank" title="Clique para ver em tamanho real">
                                <img src="<?= e($nc['foto']) ?>" class="img-evidencia-coluna" alt="Evidência NC" onerror="this.parentNode.innerHTML='<span style=\'color:#94a3b8; font-size:11px;\'>Imagem indisponível</span>';">
                            </a>
                        <?php else: ?>
                            <span style="color:#94a3b8; font-size:11px;">Sem foto</span>
                        <?php endif; ?>
                    </td>

                    <td><?= nl2br(e($nc['acao_tomada'] ?: '-')) ?></td>
                    <td>
                        <span class="badge-risco risco-<?= e($nc['grau_risco']) ?>">
                            <?= e($nc['grau_risco']) ?>
                        </span>
                    </td>
                    <td><?= e($nc['responsavel_acao'] ?: '-') ?></td>
                    <td>
                        <?= $nc['prazo_conclusao'] ? date('d/m/Y', strtotime($nc['prazo_conclusao'])) : '-' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="assinaturas">
        <div class="assinatura-linha">
            Técnico / Resp. Segurança do Trabalho
        </div>
    </div>

    <!-- Rodapé Padronizado -->
    <div class="rodape-documento-padrao">
        <div class="rodape-col-esq">
            <div><?= e($inspecao['codigo_documento'] ?: 'FRPR01-00') ?></div>
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