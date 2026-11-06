<?php
require_once 'config.php';
/**
 * Impressão em lote dos planos de ação: reúne as não conformidades (NC) de
 * várias inspeções em um único documento, pronto para impressão / PDF.
 *
 * Acesso: requer usuário autenticado (qualquer perfil). Chamado a partir
 * da listagem de planos de ação (planos_acao.php).
 *
 * Parâmetros GET:
 *   - ids : IDs das execuções de checklist, como array (ids[]=1&ids[]=2)
 *           ou string separada por vírgula (ids=1,2)
 *
 * Fluxo: valida os IDs; carrega as inspeções selecionadas; para cada uma,
 * busca os itens NC e renderiza uma tabela por área (ação corretiva, risco,
 * responsável, prazo e foto). Cabeçalho e dados gerais aparecem só uma vez,
 * no início, e assinatura/rodapé só ao final do documento.
 */
exigirLogin();

$ids = $_GET['ids'] ?? [];

// Converte string para array caso venha separado por vírgula
if (!is_array($ids)) {
    $ids = explode(',', (string)$ids);
}

// Converte para inteiros, descarta valores inválidos (que viram 0) e reindexa,
// pois o execute() com placeholders posicionais exige índices sequenciais
$idsLimpos = array_values(array_filter(array_map('intval', $ids)));

if (empty($idsLimpos)) {
    die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#dc2626;"><h2>Nenhum plano de ação selecionado.</h2><a href="planos_acao.php">Voltar</a></div>');
}

// Um placeholder "?" por ID para montar o IN (...) de forma segura
$inQuery = implode(',', array_fill(0, count($idsLimpos), '?'));

// Busca todas as inspeções selecionadas. LEFT JOIN para não perder inspeções
// cujo modelo de checklist ou usuário tenha sido excluído.
$stmt = $pdo->prepare("
    SELECT 
        e.*, 
        COALESCE(c.nome, 'Modelo não encontrado') AS checklist_nome,
        c.codigo_documento,
        c.data_aprovacao,
        u.nome AS usuario_nome
    FROM execucoes_checklist e
    LEFT JOIN checklists c ON c.id = e.checklist_id
    LEFT JOIN usuarios u ON u.id = e.usuario_id
    WHERE e.id IN ($inQuery)
    ORDER BY e.data_inspecao DESC, e.id DESC
");
$stmt->execute($idsLimpos);
$inspecoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($inspecoes)) {
    die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#dc2626;"><h2>Registros não encontrados.</h2><a href="planos_acao.php">Voltar</a></div>');
}

// Cabeçalho e rodapé do documento usam os dados da primeira inspeção
// (a mais recente, pela ordenação da consulta).
$totalInspecoes = count($inspecoes);
$primeiraInspecao = $inspecoes[0];
$dataAprovacaoDoc = !empty($primeiraInspecao['data_aprovacao']) ? date('d/m/Y', strtotime($primeiraInspecao['data_aprovacao'])) : 'Não informada';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Planos de Ação em Lote - SST</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
* { box-sizing: border-box; }
body { margin: 0; padding: 20px; background: #e2e8f0; font-family: Arial, Helvetica, sans-serif; color: #1e293b; }

.btn-print-topo {
    position: fixed;
    top: 20px;
    right: 20px;
    background: #0b5cab;
    color: #ffffff;
    border: 0;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: bold;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    z-index: 9999;
}
.btn-print-topo:hover { background: #084985; }

.container-impressao { max-width: 1050px; margin: 0 auto; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 35px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }

/* Topo / Cabeçalho Principal */
.topo-pdf { 
    display: grid; 
    grid-template-columns: 160px 1fr 160px; 
    border: 1px solid #222; 
    align-items: center; 
    margin-bottom: 20px; 
}

.logo-box { 
    height: 90px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    padding: 6px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.logo-left { background: #102744 !important; border-right: 1px solid #222; }
.logo-right { background: #f8fafc !important; border-left: 1px solid #222; }
.img-logo-topo { max-height: 75px; max-width: 140px; width: auto; height: auto; object-fit: contain; }

.titulo-box { text-align: center; padding: 10px; }
.titulo-box h1 { margin: 0; font-size: 19px; color: #102744; }
.titulo-box h2 { margin: 4px 0 0; font-size: 13px; color: #475569; font-weight: normal; }

/* Grid de Informações */
.info-grid { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid #333; margin-bottom: 20px; font-size: 12.5px; }
.info-item { padding: 8px 10px; border-right: 1px solid #333; }
.info-item:last-child { border-right: 0; }
.info-item strong { display: block; color: #1e293b; margin-bottom: 2px; }

/* Título de Destaque da Área (Primeiro Relatório) */
.titulo-area-primeiro {
    text-align: center;
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 8px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    margin-bottom: 12px;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

/* Separador de Área (Do segundo relatório em diante) */
.separador-area {
    text-align: center;
    font-size: 15px;
    font-weight: 800;
    color: #0f172a;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 35px 0 14px 0;
    padding: 10px;
    background: #f1f5f9;
    border-top: 2px solid #0f172a;
    border-bottom: 2px solid #0f172a;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    page-break-inside: avoid;
}

/* Tabela de Não Conformidades */
.tabela-pdf { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 20px; page-break-inside: avoid; }
.tabela-pdf th, .tabela-pdf td { border: 1px solid #444; padding: 8px; text-align: left; vertical-align: top; }
.tabela-pdf th { 
    background: #e2e8f0 !important; 
    color: #0f172a; 
    font-weight: bold; 
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}

.badge-risco { 
    padding: 3px 6px; 
    border-radius: 4px; 
    font-weight: bold; 
    font-size: 11px; 
    display: inline-block; 
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}
.risco-Alto, .risco-Crítico { background: #fee2e2 !important; color: #991b1b !important; }
.risco-Médio { background: #fef3c7 !important; color: #92400e !important; }
.risco-Baixo, .risco-Aceitável { background: #dcfce7 !important; color: #166534 !important; }

.img-evidencia-coluna {
    max-width: 170px;
    max-height: 125px;
    width: auto;
    height: auto;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    display: block;
    margin: 0 auto;
    object-fit: cover;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

/* Linha de Assinatura */
.assinaturas { 
    margin-top: 40px; 
    display: flex; 
    justify-content: center; 
    text-align: center; 
    page-break-inside: avoid;
}
.assinatura-linha { 
    border-top: 1px solid #333; 
    padding-top: 8px; 
    font-size: 12px; 
    font-weight: bold; 
    width: 340px; 
}

/* Rodapé Padrão do Documento */
.rodape-documento-padrao {
    margin-top: 40px;
    padding-top: 12px;
    border-top: 1px solid #cbd5e1;
    display: grid;
    grid-template-columns: 1fr 2fr 1fr;
    align-items: center;
    font-size: 11px;
    color: #64748b;
    line-height: 1.4;
    page-break-inside: avoid;
}

.rodape-col-esq { text-align: left; }
.rodape-col-centro { text-align: center; }
.rodape-col-dir { text-align: right; font-weight: bold; }

/* Regras de Impressão */
@page {
    margin: 10mm;
}

@media print {
    html, body { 
        background: #ffffff !important; 
        margin: 0 !important; 
        padding: 0 !important; 
        width: 100% !important;
    }

    .btn-print-topo { 
        display: none !important; 
    }

    .container-impressao { 
        max-width: 100% !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }

    .topo-pdf, 
    .info-grid, 
    .separador-area,
    .tabela-pdf,
    .assinaturas, 
    .rodape-documento-padrao {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
}
</style>
</head>
<body>

<button type="button" class="btn-print-topo" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>

<div class="container-impressao">

    <?php foreach ($inspecoes as $index => $inspecao): ?>
        <?php
            $execId = $inspecao['id'];
            
            // Busca os itens com Não Conformidade (NC) desta inspeção.
            // Uma consulta por inspeção é aceitável aqui: o lote é escolhido manualmente.
            $stmtNC = $pdo->prepare("
                SELECT r.*, i.ordem, i.titulo AS item_titulo, i.setor_local AS item_setor
                FROM respostas_checklist r
                LEFT JOIN checklist_itens i ON i.id = r.item_id
                WHERE r.execucao_id = ? AND r.resultado = 'NC'
                ORDER BY r.id
            ");
            $stmtNC->execute([$execId]);
            $itensNC = $stmtNC->fetchAll(PDO::FETCH_ASSOC);

            // Sem responsável informado, assume o usuário que registrou a inspeção.
            $responsavelNome = $inspecao['responsavel_inspecao'] ?: ($inspecao['usuario_nome'] ?? 'Não informado');
            $nomeArea = $inspecao['area_inspecionada'] ?: 'ÁREA NÃO INFORMADA';
        ?>

        <!-- 1. Cabeçalho e Informações: apenas no PRIMEIRO relatório -->
        <?php if ($index === 0): ?>
            <div class="topo-pdf">
                <div class="logo-box logo-left">
                    <img src="logo_jb.png" alt="Logo Grupo JB" class="img-logo-topo">
                </div>

                <div class="titulo-box">
                    <h1>PLANO DE AÇÃO DE SEGURANÇA DO TRABALHO</h1>
                    <h2>Tratativa de Não Conformidades (NC)</h2>
                </div>

                <div class="logo-box logo-right">
                    <img src="logo_st.png" alt="Logo ST" class="img-logo-topo">
                </div>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <strong>CHECKLIST:</strong>
                    <?= e($inspecao['checklist_nome']) ?>
                </div>
                <div class="info-item">
                    <strong>UNIDADE:</strong>
                    <?= e($inspecao['unidade'] ?: '-') ?>
                </div>
                <div class="info-item">
                    <strong>DATA DE INSPEÇÃO:</strong>
                    <?= date('d/m/Y', strtotime($inspecao['data_inspecao'])) ?>
                </div>
                <div class="info-item" style="grid-column: 1 / -1; border-top: 1px solid #333;">
                    <strong>RESPONSÁVEL PELA INSPEÇÃO:</strong>
                    <?= e($responsavelNome) ?>
                </div>
            </div>

            <div class="titulo-area-primeiro">
                ÁREA: <?= e($nomeArea) ?>
            </div>

        <!-- 2. A partir do SEGUNDO relatório: apenas o separador com a área centralizada -->
        <?php else: ?>
            <div class="separador-area">
                ÁREA: <?= e($nomeArea) ?>
            </div>
        <?php endif; ?>

        <!-- Tabela com os Itens da Área -->
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
                <?php if (empty($itensNC)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:#64748b; padding:16px;">Nenhuma Não Conformidade registrada nesta área.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($itensNC as $nc): ?>
                        <?php $titulo = $nc['titulo_nao_conformidade'] ?: $nc['item_titulo']; // título salvo na resposta prevalece sobre o do modelo ?>
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
                <?php endif; ?>
            </tbody>
        </table>

    <?php endforeach; ?>

    <!-- Assinatura e Rodapé exibidos apenas uma vez ao final de todos os relatórios -->
    <div class="assinaturas">
        <div class="assinatura-linha">
            Técnico / Resp. Segurança do Trabalho
        </div>
    </div>

    <div class="rodape-documento-padrao">
        <div class="rodape-col-esq">
            <div><?= e($primeiraInspecao['codigo_documento'] ?: 'FRPR01-00') ?></div>
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