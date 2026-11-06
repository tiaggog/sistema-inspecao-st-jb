<?php
require_once 'config.php';
/**
 * Inspeções de SST: histórico, criação e edição de inspeções.
 *
 * Acesso: requer login (qualquer perfil).
 * GET:  id (edita uma inspeção "em_andamento"), checklist_id (inicia nova inspeção),
 *       data_inicio, data_fim, unidade, area, status (filtros do histórico), salvo (alerta).
 * POST: salvar_execucao, acao_status (em_andamento|concluido), campos do cabeçalho,
 *       resultado[item_id] (C/NC/NA), observacao[item_id], foto[item_id] (evidência).
 * Fluxo: sem checklist selecionado, a página exibe o histórico com filtros e
 * impressão em lote; com checklist, exibe o formulário de preenchimento. Ao salvar,
 * grava cabeçalho e respostas em transação. Inspeções concluídas ficam somente
 * leitura e são redirecionadas para o relatório.
 */
exigirLogin();

$u = usuarioAtual();
$execId = (int)($_GET['id'] ?? 0);

$execucaoEdicao = null;
$respostasSalvas = [];
$erro = '';
$hoje = date('Y-m-d');

// 1. Se informou ID, carrega os dados para EDIÇÃO
if ($execId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM execucoes_checklist WHERE id = ?");
    $stmt->execute([$execId]);
    $execucaoEdicao = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($execucaoEdicao) {
        // SE A INSPEÇÃO JÁ FOI CONCLUÍDA, BLOQUEIA A EDIÇÃO E VAI DIRETO PRO RELATÓRIO
        if (($execucaoEdicao['status'] ?? '') === 'concluido') {
            header("Location: visualizar_relatorio.php?id=" . $execId);
            exit;
        }

        // Se está "em_andamento", permite abrir e carregar as respostas existentes
        $cid = (int)$execucaoEdicao['checklist_id'];

        $stmtResp = $pdo->prepare("SELECT * FROM respostas_checklist WHERE execucao_id = ?");
        $stmtResp->execute([$execId]);
        $resps = $stmtResp->fetchAll(PDO::FETCH_ASSOC);

        foreach ($resps as $r) {
            $respostasSalvas[$r['item_id']] = $r;
        }
    }
}

// 2. Se não estiver editando, busca o checklist_id selecionado
if (!$execucaoEdicao) {
    $cid = (int)($_GET['checklist_id'] ?? 0);
}

// Modelos ativos para o modal "Nova Inspeção"
$checklists = $pdo->query("SELECT * FROM checklists WHERE ativo=1 ORDER BY nome")->fetchAll();

// Carrega o modelo e seus itens ativos; $check preenchido ativa o modo formulário
// na view (caso contrário, a página exibe o histórico).

$check = null;
$itens = [];

if ($cid > 0) {
    $s = $pdo->prepare("SELECT * FROM checklists WHERE id=?");
    $s->execute([$cid]);
    $check = $s->fetch();

    $s = $pdo->prepare("SELECT * FROM checklist_itens WHERE checklist_id=? AND ativo=1 ORDER BY ordem,id");
    $s->execute([$cid]);
    $itens = $s->fetchAll();
}

// 3. Processamento do Salvamento (Novo ou Edição de "Em Andamento")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cid && isset($_POST['salvar_execucao'])) {

    // Regra da Data de Inspeção:
    // Se estiver editando uma inspeção existente, MANTÉM a data original do início.
    // Se for uma nova inspeção, pega a data de HOJE.
    if ($execId > 0 && !empty($execucaoEdicao['data_inspecao'])) {
        $dataInspecao = $execucaoEdicao['data_inspecao'];
    } else {
        $dataInspecao = $hoje;
    }
    
    $semanaReferencia = trim($_POST['semana_referencia'] ?? '');
    $unidade = trim($_POST['unidade'] ?? '');
    $areaInspecionada = $_POST['area_inspecionada'] ?? '';
    
    if ($areaInspecionada === 'OUTRO') {
        $areaOutro = trim($_POST['area_outro'] ?? '');
        if ($areaOutro !== '') {
            $areaInspecionada = 'OUTRO: ' . $areaOutro;
        }
    }

    $responsavelInspecao = trim($_POST['responsavel_inspecao'] ?? '');
    $observacaoGeral = trim($_POST['observacao_geral'] ?? '');
    // Whitelist do status: qualquer valor diferente de 'concluido' vira rascunho
    $statusExecucao = ($_POST['acao_status'] ?? '') === 'concluido' ? 'concluido' : 'em_andamento';

    // Validação PHP: Verifica se todos os campos do cabeçalho foram informados
    if (empty($semanaReferencia) || empty($unidade) || empty($areaInspecionada) || empty($responsavelInspecao)) {
        $erro = "Por favor, preencha todos os campos obrigatórios do cabeçalho antes de salvar.";
    }

    // Validação PHP: Verifica se TODOS os itens do checklist foram marcados (C, NC ou NA)
    if (empty($erro)) {
        foreach ($itens as $item) {
            $itemId = $item['id'];
            if (!isset($_POST['resultado'][$itemId]) || $_POST['resultado'][$itemId] === '') {
                $erro = "Todos os itens do checklist devem ser marcados (C, NC ou NA) antes de salvar.";
                break;
            }
        }
    }

    // Se passou na validação rígida, realiza o salvamento/atualização
    if (empty($erro)) {
        $diretorioUpload = 'uploads/';
        if (!is_dir($diretorioUpload)) {
            mkdir($diretorioUpload, 0755, true);
        }

        try {
            $pdo->beginTransaction();

            if ($execId > 0) {
                // Atualiza inspeção existente que estava "em_andamento"
                $s = $pdo->prepare("
                    UPDATE execucoes_checklist
                    SET unidade = ?, data_inspecao = ?, semana_referencia = ?, area_inspecionada = ?, responsavel_inspecao = ?, observacao_geral = ?, status = ?
                    WHERE id = ?
                ");
                $s->execute([
                    $unidade,
                    $dataInspecao,
                    $semanaReferencia,
                    $areaInspecionada,
                    $responsavelInspecao,
                    $observacaoGeral,
                    $statusExecucao,
                    $execId
                ]);

                $idAtual = $execId;

                // Limpa respostas anteriores para recriar com as edições.
                // Campos preenchidos fora desta tela (ação tomada, recomendação,
                // grau de risco, foto) foram carregados em $respostasSalvas e são
                // reaproveitados no INSERT abaixo para não serem perdidos.
                $sDel = $pdo->prepare("DELETE FROM respostas_checklist WHERE execucao_id = ?");
                $sDel->execute([$execId]);
            } else {
                // Insere nova inspeção
                $s = $pdo->prepare("
                    INSERT INTO execucoes_checklist
                    (checklist_id, usuario_id, unidade, data_inspecao, semana_referencia, area_inspecionada, responsavel_inspecao, observacao_geral, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $s->execute([
                    $cid,
                    $u['id'],
                    $unidade,
                    $dataInspecao,
                    $semanaReferencia,
                    $areaInspecionada,
                    $responsavelInspecao,
                    $observacaoGeral,
                    $statusExecucao
                ]);

                $idAtual = $pdo->lastInsertId();
            }

            // --- Gravação das respostas item a item ---
            foreach ($itens as $item) {
                $itemId = $item['id'];
                $resultado = $_POST['resultado'][$itemId] ?? null;
                $observacaoItem = $_POST['observacao'][$itemId] ?? '';
                
                // Recupera foto existente caso nenhuma nova seja enviada na edição
                $fotoCaminho = $respostasSalvas[$itemId]['foto'] ?? '';

                // Processa upload de nova foto se enviada. Aceita apenas extensões de
                // imagem e gera nome único (nc_<execução>_<item>_<uniqid>) para evitar
                // sobrescrita e não usar o nome original enviado pelo usuário.
                if (isset($_FILES['foto']['name'][$itemId]) && $_FILES['foto']['error'][$itemId] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto']['name'][$itemId], PATHINFO_EXTENSION));
                    $extPermitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (in_array($ext, $extPermitidas)) {
                        $novoNome = 'nc_' . $idAtual . '_' . $itemId . '_' . uniqid() . '.' . $ext;
                        $destino = $diretorioUpload . $novoNome;
                        if (move_uploaded_file($_FILES['foto']['tmp_name'][$itemId], $destino)) {
                            $fotoCaminho = $destino;
                        }
                    }
                }

                // Item NC fica "Pendente" e alimenta o Plano de Ação;
                // C e NA não exigem tratativa e já nascem "Concluído".
                if ($resultado === 'NC') {
                    $statusItem = 'Pendente';
                } elseif ($resultado === 'C' || $resultado === 'NA') {
                    $statusItem = 'Concluído';
                } else {
                    $statusItem = 'Pendente';
                }

                // Título, setor e base legal são copiados do item do modelo (snapshot),
                // preservando o histórico mesmo que o checklist seja alterado depois.
                $stmt = $pdo->prepare("
                    INSERT INTO respostas_checklist
                    (
                        execucao_id, item_id, rnc_numero, titulo_nao_conformidade,
                        setor_local, detalhamento, acao_tomada, recomendacao,
                        base_legal, resultado, grau_risco, status, foto
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $idAtual,
                    $itemId,
                    '',
                    $item['titulo'],
                    $item['setor_local'],
                    $observacaoItem,
                    $respostasSalvas[$itemId]['acao_tomada'] ?? '',
                    $respostasSalvas[$itemId]['recomendacao'] ?? '',
                    $item['base_legal'],
                    $resultado,
                    $respostasSalvas[$itemId]['grau_risco'] ?? 'Médio',
                    $statusItem,
                    $fotoCaminho
                ]);
            }

            $pdo->commit();

            // Se alterou o status para CONCLUÍDO, vai pro relatório (não edita mais)
            // Se salvou EM ANDAMENTO, permanece editável
            if ($statusExecucao === 'concluido') {
                header("Location: visualizar_relatorio.php?id=" . $idAtual);
            } else {
                header("Location: nova_execucao.php?id=" . $idAtual . "&salvo=1");
            }
            exit;

        } catch (Exception $e) {
            // Desfaz cabeçalho e respostas para não deixar a inspeção pela metade
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erro = "Erro ao salvar inspeção: " . $e->getMessage();
        }
    }
}

// 4. Filtros para o Histórico de Inspeções
$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim = $_GET['data_fim'] ?? '';
$filtroUnidade = $_GET['unidade'] ?? '';
$filtroArea = $_GET['area'] ?? '';
$filtroStatus = $_GET['status'] ?? '';

// WHERE montado dinamicamente apenas com os filtros informados; os valores
// seguem como parâmetros do prepared statement (sem concatenação de entrada).
$where = [];
$params = [];

if ($dataInicio !== '') { $where[] = "e.data_inspecao >= ?"; $params[] = $dataInicio; }
if ($dataFim !== '') { $where[] = "e.data_inspecao <= ?"; $params[] = $dataFim; }
if ($filtroUnidade !== '') { $where[] = "e.unidade = ?"; $params[] = $filtroUnidade; }
if ($filtroArea !== '') { $where[] = "e.area_inspecionada = ?"; $params[] = $filtroArea; }
if ($filtroStatus !== '') { $where[] = "e.status = ?"; $params[] = $filtroStatus; }

$whereSql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$sqlHistorico = "
    SELECT e.*, c.nome AS checklist_nome 
    FROM execucoes_checklist e
    JOIN checklists c ON c.id = e.checklist_id
    $whereSql
    ORDER BY e.data_inspecao DESC, e.id DESC
";

$stmtHist = $pdo->prepare($sqlHistorico);
$stmtHist->execute($params);
$historico = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

// Opções únicas para os selects de filtro, extraídas das inspeções já registradas
$unidades = $pdo->query("SELECT DISTINCT unidade FROM execucoes_checklist WHERE unidade IS NOT NULL AND unidade <> '' ORDER BY unidade")->fetchAll(PDO::FETCH_COLUMN);
$areas = $pdo->query("SELECT DISTINCT area_inspecionada FROM execucoes_checklist WHERE area_inspecionada IS NOT NULL AND area_inspecionada <> '' ORDER BY area_inspecionada")->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Inspeções - SST</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f8fafc; color: #1e293b; font-family: Arial, Helvetica, sans-serif; }

.container-pagina { max-width: 1180px; margin: 28px auto 60px; padding: 0 20px; }

/* Header Superior da Página */
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

.botoes-topo-flex {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
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

.btn-nova-inspecao {
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

.btn-nova-inspecao:hover {
    background: #15803d;
    transform: translateY(-1px);
}

/* Painel de Filtros */
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
    align-items: end; /* Alinha todos os itens pela base */
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
    height: 36px; /* Altura única padronizada */
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

/* Tabela de Histórico */
.card-historico { 
    background: #ffffff; 
    border: 1px solid #e2e8f0; 
    border-radius: 12px; 
    padding: 0; 
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); 
    overflow: hidden;
}

.tabela-historico { width: 100%; border-collapse: collapse; font-size: 14px; }
.tabela-historico th, .tabela-historico td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.tabela-historico th { background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }

.tabela-historico tr:last-child td { border-bottom: 0; }
.tabela-historico tr:hover td { background: #f8fafc; }

.checkbox-lote {
    width: 18px;
    height: 18px;
    cursor: pointer;
    accent-color: #0b5cab;
}

.badge-status { padding: 5px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; display: inline-block; }
.badge-andamento { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.badge-concluido { background: #dcfce7; color: #166534; border: 1px solid #86efac; }

.btn-acao { padding: 7px 14px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 12px; display: inline-block; transition: opacity 0.2s; }
.btn-acao:hover { opacity: 0.9; }
.btn-relatorio { background: #0b5cab; color: #ffffff; margin-right: 4px; }
.btn-editar { background: #d97706; color: #ffffff; }

/* Modal de Escolha do Checklist */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.modal-box {
    background: #ffffff;
    padding: 30px;
    border-radius: 14px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    position: relative;
}

.modal-box h3 { margin: 0 0 8px; color: #0f172a; font-size: 20px; }
.modal-box p { margin: 0 0 20px; color: #64748b; font-size: 14px; }
.modal-box label { display: block; font-size: 13px; font-weight: bold; color: #334155; margin-bottom: 8px; }
.modal-box select { width: 100%; height: 44px; padding: 0 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; margin-bottom: 24px; outline: none; }
.modal-close { position: absolute; top: 20px; right: 20px; background: none; border: 0; font-size: 24px; cursor: pointer; color: #94a3b8; }
.modal-close:hover { color: #0f172a; }

.btn-modal-iniciar {
    width: 100%;
    height: 44px;
    background: #0b5cab;
    color: #ffffff;
    border: 0;
    border-radius: 8px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-modal-iniciar:hover { background: #084985; }

/* Form de Inspeção */
.inspecao-wrap { border: 1px solid #cbd5e1; padding: 40px; background: #ffffff; color: #1e293b; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border-radius: 12px; }

/* Topo com Logos Imagens */
.topo-inspecao { 
    display: grid; 
    grid-template-columns: 180px 1fr 180px; 
    border: 1px solid #334155; 
    align-items: center; 
    min-height: 110px; 
}

.logo-box { 
    height: 110px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    border-right: 1px solid #334155; 
    background: #102744; 
    padding: 6px; 
    text-decoration: none; 
}

.selo-box { 
    height: 110px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    border-left: 1px solid #334155; 
    background: #f8fafc; 
    padding: 6px; 
}

.img-logo-topo {
    max-height: 90px;
    max-width: 160px;
    width: auto;
    height: auto;
    object-fit: contain;
}

.titulo-inspecao { text-align: center; padding: 10px; }
.titulo-inspecao h1 { margin: 0; font-size: 24px; color: #0f172a; }
.titulo-inspecao h2 { margin: 4px 0 0; font-size: 17px; font-weight: 400; color: #475569; }

.form-info { margin-top: 25px; border: 1px solid #334155; }
.linha-info { display: grid; grid-template-columns: 1fr 1fr 1fr; border-bottom: 1px solid #334155; }
.linha-info:last-child { border-bottom: 0; }
.campo-info { padding: 9px 12px; border-right: 1px solid #334155; min-height: 45px; }
.campo-info:last-child { border-right: 0; }
.campo-info label { display: block; font-size: 12px; margin-bottom: 4px; color: #0f172a; font-weight: bold; }
.campo-info input, .campo-info select { width: 100%; background: #ffffff; color: #0f172a; border: 0; border-bottom: 1px solid #94a3b8; outline: none; padding: 4px 0; font-size: 14px; }

/* Estilo para input bloqueado */
.input-data-fixa {
    background-color: #f1f5f9 !important;
    color: #475569 !important;
    font-weight: bold;
    cursor: not-allowed !important;
    pointer-events: none;
}

.area-checks { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; color: #0f172a; }
.area-checks label { display: flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer; }
.area-checks input[type="radio"] { width: auto; margin: 0; }
.area-checks input[type="text"] { max-width: 200px; }

.legenda { margin-top: 20px; border: 1px solid #334155; padding: 8px 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 12px; }
.tag-legenda { padding: 4px 10px; font-weight: bold; color: #000; border-radius: 3px; }
.tag-c { background: #00bf63; }
.tag-nc { background: #ff3131; }
.tag-na { background: #bfbfbf; }

.tabela-inspecao { width: 100%; margin-top: 25px; border-collapse: collapse; font-size: 14px; }
.tabela-inspecao th, .tabela-inspecao td { border: 1px solid #475569; padding: 8px 10px; }
.tabela-inspecao th { background: #cbd5e1; color: #0f172a; font-weight: bold; }
.th-item { width: 60px; }
.th-c { background: #00bf63 !important; color: #000 !important; width: 50px; }
.th-nc { background: #ff6969 !important; color: #000 !important; width: 50px; }
.th-na { background: #8d8d8d !important; color: #000 !important; width: 50px; }

.sec-padrao td, .sec-org td, .sec-estrutura td, .sec-refeitorio td { font-weight: bold; text-align: center; padding: 10px; color: #ffffff; }
.sec-org td { background: #2e471f; }
.sec-estrutura td { background: #344958; }
.sec-refeitorio td { background: #6b4f2a; }
.sec-padrao td { background: #394150; }

.item-num { text-align: center; font-weight: bold; }
.check-cell { text-align: center; vertical-align: middle; }
.check-cell input { width: 18px; height: 18px; cursor: pointer; accent-color: #00bf63; }
.check-cell.nc input { accent-color: #ff3131; }
.check-cell.na input { accent-color: #999; }

.linha-observacao { display: none; }
.linha-observacao td { background: #f8fafc; padding: 14px; }
.obs-item { width: 100%; padding: 8px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; min-height: 60px; font-size: 13px; outline: none; }

/* Seção da Foto com Badge */
.box-foto-upload {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px dashed #cbd5e1;
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.box-foto-upload label {
    font-weight: bold;
    font-size: 12px;
    color: #334155;
    display: flex;
    align-items: center;
    gap: 6px;
}

.input-foto { font-size: 12px; color: #475569; }

.badge-foto-sucesso {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: bold;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    animation: fadeIn 0.3s ease-in-out;
}

.miniatura-foto {
    max-height: 60px;
    max-width: 100px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    object-fit: cover;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.obs-geral-box { margin-top: 24px; }
.obs-geral-box label { display: block; margin-bottom: 6px; font-weight: bold; }
.obs-geral-box textarea { width: 100%; height: 90px; border: 1px solid #cbd5e1; padding: 10px; border-radius: 6px; font-family: Arial, sans-serif; outline: none; }

.acoes-container { display: flex; gap: 12px; margin-top: 28px; flex-wrap: wrap; align-items: center; }
.btn-andamento { background: #d97706; color: #ffffff; border: 0; padding: 12px 24px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; }
.btn-concluir { background: #16a34a; color: #ffffff; border: 0; padding: 12px 28px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 14px; }

.alerta-sucesso { background: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 14px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; }
.alerta-erro { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 14px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; }

/* Highlight para itens não marcados */
tr.item-pendente td { background-color: #fee2e2 !important; }

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-3px); }
    to { opacity: 1; transform: translateY(0); }
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

    <?php if (isset($_GET['salvo'])): ?>
        <div class="alerta-sucesso">
            Inspeção salva como "Em Andamento" com sucesso!
        </div>
    <?php endif; ?>

    <?php if (!empty($erro)): ?>
        <div class="alerta-erro">
            ⚠️ <?= e($erro) ?>
        </div>
    <?php endif; ?>

    <!-- MODO HISTÓRICO -->
    <?php if (!$check): ?>

        <!-- CABEÇALHO SUPERIOR DA PÁGINA -->
        <div class="top-header">
            <div>
                <h1>Inspeções de SST</h1>
                <p>Histórico de vistorias e controle de checklists realizados.</p>
            </div>

            <div class="botoes-topo-flex">
                <button type="button" id="btnImprimirLote" class="btn-imprimir-lote" onclick="imprimirSelecionados()">
                    📄 Imprimir Selecionados
                </button>

                <button type="button" class="btn-nova-inspecao" onclick="abrirModal()">
                    <span>+</span> Nova Inspeção
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
                        <label>Filial / Unidade</label>
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
                        <label>Status</label>
                        <select name="status">
                            <option value="">Todos</option>
                            <option value="em_andamento" <?= $filtroStatus === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                            <option value="concluido" <?= $filtroStatus === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                        </select>
                    </div>

                    <!-- Botões sem label interna para alinhamento perfeito na base -->
                    <div class="campo-filtro">
                        <button type="submit" class="btn-filtrar">Filtrar</button>
                    </div>

                    <div class="campo-filtro">
                        <a href="nova_execucao.php" class="btn-limpar">Limpar</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- TABELA DE RESULTADOS DO HISTÓRICO -->
        <div class="card-historico">
            <?php if (empty($historico)): ?>
                <p style="color:#64748b; margin:0; padding:24px; text-align:center;">Nenhuma inspeção encontrada para os filtros selecionados.</p>
            <?php else: ?>
                <form id="formImpressaoLote" method="get" action="imprimir_multiplos_relatorios.php" target="_blank">
                    <table class="tabela-historico">
                        <thead>
                            <tr>
                                <th style="width: 40px; text-align: center;">
                                    <input type="checkbox" id="selectAll" class="checkbox-lote" onclick="toggleSelecionarTodos(this)" title="Selecionar Todos">
                                </th>
                                <th>Data</th>
                                <th>Checklist</th>
                                <th>Filial</th>
                                <th>Área</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historico as $h): ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="ids[]" value="<?= $h['id'] ?>" class="check-inspecao checkbox-lote" onchange="atualizarBotaoImprimir()">
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($h['data_inspecao'])) ?></td>
                                    <td><?= e($h['checklist_nome']) ?></td>
                                    <td><?= e($h['unidade'] ?: '-') ?></td>
                                    <td><?= e($h['area_inspecionada'] ?: '-') ?></td>
                                    <td>
                                        <?php if (($h['status'] ?? '') === 'concluido'): ?>
                                            <span class="badge-status badge-concluido">Concluído</span>
                                        <?php else: ?>
                                            <span class="badge-status badge-andamento">Em Andamento</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- Ver Relatório primeiro -->
                                        <a href="visualizar_relatorio.php?id=<?= $h['id'] ?>" class="btn-acao btn-relatorio">📄 Ver Relatório</a>

                                        <?php if (($h['status'] ?? '') === 'em_andamento'): ?>
                                            <!-- Editar depois (apenas para Em Andamento) -->
                                            <a href="nova_execucao.php?id=<?= $h['id'] ?>" class="btn-acao btn-editar">✏️ Editar</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            <?php endif; ?>
        </div>

        <!-- MODAL FLUTUANTE PARA ESCOLHA DO CHECKLIST -->
        <div id="modalNovaInspecao" class="modal-overlay">
            <div class="modal-box">
                <button type="button" class="modal-close" onclick="fecharModal()">&times;</button>
                <h3>Nova Inspeção</h3>
                <p>Selecione o modelo para dar início ao preenchimento.</p>
                
                <form method="get" action="nova_execucao.php">
                    <label for="checklist_id">Modelo de Checklist</label>
                    <select name="checklist_id" id="checklist_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($checklists as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= e($c['nome']) ?> — <?= e($c['unidade']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn-modal-iniciar">Iniciar Preenchimento</button>
                </form>
            </div>
        </div>

    <?php endif; ?>

    <!-- MODO FORMULÁRIO DE PREENCHIMENTO OU EDIÇÃO DE RASCUNHO -->
    <?php if ($check): ?>
    <form method="post" enctype="multipart/form-data" onsubmit="return validarFormulario(this)">
        <input type="hidden" name="checklist_id" value="<?= $cid ?>">
        <input type="hidden" name="acao_status" id="acao_status" value="em_andamento">

        <div class="inspecao-wrap">

            <div class="topo-inspecao">
                <!-- Logo Grupo JB na esquerda -->
                <a href="nova_execucao.php" class="logo-box" title="Voltar para a lista">
                    <img src="logo_jb.png" alt="Logo Grupo JB" class="img-logo-topo">
                </a>

                <!-- Título centralizado -->
                <div class="titulo-inspecao">
                    <h1>INSPEÇÃO SEMANAL</h1>
                    <h2>SAÚDE E SEGURANÇA DO TRABALHO</h2>
                </div>

                <!-- Logo ST na direita -->
                <div class="selo-box">
                    <img src="logo_st.png" alt="Logo Saúde e Segurança do Trabalho" class="img-logo-topo">
                </div>
            </div>

            <?php
            // Valores iniciais do cabeçalho: na edição vêm da inspeção salva; numa nova
            // inspeção, unidade vem do modelo e responsável do usuário logado.
            // Se for edição, busca a data original do início; se for novo, pega HOJE
            $valData = !empty($execucaoEdicao['data_inspecao']) ? $execucaoEdicao['data_inspecao'] : $hoje;
            $valSemana = $execucaoEdicao['semana_referencia'] ?? '';
            $valUnidade = $execucaoEdicao['unidade'] ?? ($check['unidade'] ?? '');
            $valArea = $execucaoEdicao['area_inspecionada'] ?? '';
            $valResp = $execucaoEdicao['responsavel_inspecao'] ?? ($u['nome'] ?? '');
            $valObsGeral = $execucaoEdicao['observacao_geral'] ?? '';
            ?>

            <div class="form-info">
                <div class="linha-info">
                    <div class="campo-info">
                        <label>DATA DE INSPEÇÃO <span style="color:#dc2626;">*</span>:</label>
                        <!-- Exibe e trava a data inicial do início da inspeção -->
                        <input type="date" name="data_inspecao" value="<?= e($valData) ?>" class="input-data-fixa" readonly required>
                    </div>

                    <div class="campo-info">
                        <label>SEMANA DE REFERÊNCIA <span style="color:#dc2626;">*</span>:</label>
                        <input type="text" name="semana_referencia" value="<?= e($valSemana) ?>" placeholder="Ex.: 24/06 a 30/06" required>
                    </div>

                    <div class="campo-info">
                        <label>UNIDADE <span style="color:#dc2626;">*</span>:</label>
                        <input type="text" name="unidade" value="<?= e($valUnidade) ?>" required>
                    </div>
                </div>

                <div class="linha-info">
                    <div class="campo-info" style="grid-column:1 / -1;">
                        <label>ÁREA INSPECIONADA <span style="color:#dc2626;">*</span>:</label>

                        <div class="area-checks">
                            <?php 
                            $opcoesArea = ['RH', 'RECEPÇÃO', 'ADM', 'TI', 'TORRE']; 
                            // Área fora da lista fixa foi salva como "OUTRO: <texto>"; marca o
                            // rádio OUTRO e devolve só o texto livre para o campo.
                            $eOutro = $valArea !== '' && !in_array($valArea, $opcoesArea);
                            $valOutroTexto = $eOutro ? str_replace('OUTRO: ', '', $valArea) : '';
                            ?>

                            <?php foreach ($opcoesArea as $opArea): ?>
                                <label>
                                    <input type="radio" name="area_inspecionada" value="<?= $opArea ?>" <?= strtoupper($valArea) === $opArea ? 'checked' : '' ?> required>
                                    <?= $opArea ?>
                                </label>
                            <?php endforeach; ?>

                            <label>
                                <input type="radio" name="area_inspecionada" value="OUTRO" <?= $eOutro ? 'checked' : '' ?> required>
                                OUTRO:
                            </label>

                            <input type="text" name="area_outro" value="<?= e($valOutroTexto) ?>">
                        </div>
                    </div>
                </div>

                <div class="linha-info">
                    <div class="campo-info" style="grid-column:1 / -1;">
                        <label>RESPONSÁVEL PELA INSPEÇÃO <span style="color:#dc2626;">*</span>:</label>
                        <input type="text" name="responsavel_inspecao" value="<?= e($valResp) ?>" required>
                    </div>
                </div>
            </div>

            <div class="legenda">
                <strong>LEGENDA:</strong>
                <span class="tag-legenda tag-c">C</span> CONFORME
                <span class="tag-legenda tag-nc">NC</span> NÃO CONFORME
                <span class="tag-legenda tag-na">NA</span> NÃO SE APLICA
            </div>

            <table class="tabela-inspecao">
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
                // Itens vêm ordenados; uma linha de seção é emitida sempre que o
                // setor_local muda, com cor definida por palavras-chave do nome.
                $secaoAtual = '';

                foreach ($itens as $i):
                    $iid = $i['id'];
                    $setor = trim($i['setor_local']) ?: 'Itens do checklist';

                    $resSalva = $respostasSalvas[$iid] ?? null;
                    $valResultado = $resSalva['resultado'] ?? null;
                    $valObsItem = $resSalva['detalhamento'] ?? '';
                    $valFotoItem = $resSalva['foto'] ?? '';

                    if ($setor !== $secaoAtual):
                        $secaoAtual = $setor;
                        $classeSecao = 'sec-padrao';

                        if (stripos($setor, 'Organização') !== false || stripos($setor, 'Limpeza') !== false) $classeSecao = 'sec-org';
                        if (stripos($setor, 'Estrutura') !== false || stripos($setor, 'Iluminação') !== false) $classeSecao = 'sec-estrutura';
                        if (stripos($setor, 'Bebedouros') !== false || stripos($setor, 'Refeitórios') !== false) $classeSecao = 'sec-refeitorio';
                ?>
                    <tr class="<?= $classeSecao ?>">
                        <td colspan="5"><?= e($secaoAtual) ?></td>
                    </tr>
                <?php endif; ?>

                <?php
                    // Títulos no formato "1.2 - Texto" exibem o número próprio;
                    // sem esse padrão, usa a ordem do item como numeração.
                    $partes = explode(' - ', $i['titulo'], 2);
                    $numeroItem = count($partes) === 2 ? $partes[0] : $i['ordem'];
                    $textoItem = count($partes) === 2 ? $partes[1] : $i['titulo'];
                ?>

                    <tr class="linha-item" data-item="<?= $iid ?>">
                        <td class="item-num"><?= e($numeroItem) ?></td>
                        <td class="item-texto"><?= e($textoItem) ?></td>

                        <td class="check-cell">
                            <input type="radio" name="resultado[<?= $iid ?>]" value="C" data-item="<?= $iid ?>" <?= $valResultado === 'C' ? 'checked' : '' ?> required>
                        </td>

                        <td class="check-cell nc">
                            <input type="radio" name="resultado[<?= $iid ?>]" value="NC" data-item="<?= $iid ?>" <?= $valResultado === 'NC' ? 'checked' : '' ?> required>
                        </td>

                        <td class="check-cell na">
                            <input type="radio" name="resultado[<?= $iid ?>]" value="NA" data-item="<?= $iid ?>" <?= $valResultado === 'NA' ? 'checked' : '' ?> required>
                        </td>
                    </tr>

                    <tr class="linha-observacao" id="obs-linha-<?= $iid ?>" style="<?= $valResultado === 'NC' ? 'display:table-row;' : '' ?>">
                        <td colspan="5">
                            <label style="font-weight:bold; font-size:12px;">Observação da não conformidade:</label>
                            <textarea class="obs-item" name="observacao[<?= $iid ?>]" placeholder="Descreva aqui o problema encontrado..."><?= e($valObsItem) ?></textarea>
                            
                            <!-- Campo para Upload de Foto de Evidência -->
                            <div class="box-foto-upload">
                                <div>
                                    <label for="foto_<?= $iid ?>">📷 Anexar Foto / Evidência:</label>
                                    <input type="file" id="foto_<?= $iid ?>" name="foto[<?= $iid ?>]" accept="image/*" capture="environment" class="input-foto" onchange="previewFoto(this, '<?= $iid ?>')">
                                </div>

                                <!-- Mensagem de Confirmação de Foto Anexada -->
                                <div id="msg-foto-<?= $iid ?>" class="badge-foto-sucesso" style="display: <?= !empty($valFotoItem) ? 'inline-flex' : 'none' ?>;">
                                    ✅ Foto anexada com sucesso!
                                </div>

                                <!-- Box de pré-visualização da foto -->
                                <div id="preview-box-<?= $iid ?>" style="display: <?= !empty($valFotoItem) ? 'flex' : 'none' ?>; align-items:center; gap:8px;">
                                    <img id="preview-img-<?= $iid ?>" src="<?= e($valFotoItem) ?>" class="miniatura-foto" alt="Evidência Anexada">
                                </div>
                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="obs-geral-box">
                <label>OBSERVAÇÃO GERAL:</label>
                <textarea name="observacao_geral" placeholder="Observações gerais da inspeção..."><?= e($valObsGeral) ?></textarea>
            </div>

            <div class="acoes-container">
                <button type="submit" name="salvar_execucao" value="1" onclick="document.getElementById('acao_status').value='em_andamento';" class="btn-andamento">
                    💾 Salvar Em Andamento
                </button>

                <button type="submit" name="salvar_execucao" value="1" onclick="document.getElementById('acao_status').value='concluido';" class="btn-concluir">
                    ✅ Concluir Inspeção
                </button>

                <a href="nova_execucao.php" style="background:#64748b; color:#fff; padding:12px 20px; font-size:14px; text-decoration:none; border-radius:8px; font-weight:bold;">
                    Cancelar / Voltar
                </a>
            </div>

        </div>
    </form>
    <?php endif; ?>

</div>

<script>
function abrirModal() {
    document.getElementById('modalNovaInspecao').style.display = 'flex';
}

function fecharModal() {
    document.getElementById('modalNovaInspecao').style.display = 'none';
}

window.onclick = function(event) {
    var modal = document.getElementById('modalNovaInspecao');
    if (event.target == modal) {
        fecharModal();
    }
}

// Atualiza a visibilidade do botão de impressão dinamicamente
function atualizarBotaoImprimir() {
    var marcados = document.querySelectorAll('.check-inspecao:checked').length;
    var btn = document.getElementById('btnImprimirLote');
    if (btn) {
        btn.style.display = marcados > 0 ? 'inline-flex' : 'none';
    }
}

// Selecionar / Deselecionar todos os checkboxes de impressão
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
        alert('⚠️ Selecione pelo menos uma inspeção para imprimir.');
        return;
    }
    document.getElementById('formImpressaoLote').submit();
}

// Validação rigorosa em JS antes de submeter
function validarFormulario(form) {
    // Valida marcação dos itens do checklist
    var linhasItens = form.querySelectorAll('tr.linha-item');
    var faltam = 0;

    linhasItens.forEach(function(tr) {
        var itemId = tr.getAttribute('data-item');
        var radios = form.querySelectorAll('input[name="resultado[' + itemId + ']"]');
        var checado = false;

        radios.forEach(function(r) {
            if (r.checked) checado = true;
        });

        if (!checado) {
            faltam++;
            tr.classList.add('item-pendente');
        } else {
            tr.classList.remove('item-pendente');
        }
    });

    if (faltam > 0) {
        alert('⚠️ ATENÇÃO:\n\nExistem ' + faltam + ' item(ns) do checklist que não foram marcados (C, NC ou NA).\n\nTodos os itens do checklist e todos os campos do cabeçalho são OBRIGATÓRIOS!');
        return false;
    }

    return true;
}

// Exibe a pré-visualização imediata e a MENSAGEM DE CONFIRMAÇÃO
function previewFoto(input, itemId) {
    var box = document.getElementById('preview-box-' + itemId);
    var img = document.getElementById('preview-img-' + itemId);
    var msg = document.getElementById('msg-foto-' + itemId);
    
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            img.src = e.target.result;
            box.style.display = 'flex';
            if (msg) msg.style.display = 'inline-flex';
        }
        reader.readAsDataURL(input.files[0]);
    }
}

document.querySelectorAll('input[type="radio"][name^="resultado"]').forEach(function(radio){
    radio.addEventListener('change', function(){
        var itemId = this.getAttribute('data-item');
        var linhaObs = document.getElementById('obs-linha-' + itemId);
        var tr = this.closest('tr');
        if (tr) tr.classList.remove('item-pendente');

        if (!linhaObs) return;

        var textarea = linhaObs.querySelector('textarea');

        if (this.value === 'NC') {
            linhaObs.style.display = 'table-row';
        } else {
            linhaObs.style.display = 'none';
            if (textarea) textarea.value = '';
        }
    });
});
</script>

</body>
</html>