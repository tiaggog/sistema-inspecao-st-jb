<?php
/**
 * Preenchimento do Plano de Ação de uma inspeção.
 *
 * Acesso: requer login. Administradores editam enquanto o plano não estiver
 * 100% concluído; demais perfis (ou plano concluído) abrem em modo somente leitura.
 *
 * Parâmetros:
 *   - GET  id                   : ID da execução (inspeção); inválido redireciona para planos_acao.php
 *   - POST salvar_plano         : dispara o salvamento
 *   - POST acao_tomada[id], status_nc[id], grau_risco[id],
 *          responsavel_acao[id], prazo_conclusao[id] : campos por resposta NC (indexados pelo ID da resposta)
 *
 * Fluxo: carrega a inspeção e suas NCs, calcula o progresso e a permissão de
 * edição; no POST atualiza cada NC e redireciona para a listagem com ?salvo=1.
 */
require_once 'config.php';
exigirLogin();

// --- Usuário e permissões ---
$usuarioLogado = usuarioAtual();
$isAdmin = ($usuarioLogado['perfil'] ?? $_SESSION['perfil'] ?? '') === 'admin';

$execId = (int)($_GET['id'] ?? 0);

// Se não foi passado um ID válido na URL, redireciona para a lista
if ($execId <= 0) {
    header('Location: planos_acao.php');
    exit;
}

// Busca dados da inspeção
$stmt = $pdo->prepare("
    SELECT e.*, COALESCE(c.nome, 'Modelo não encontrado') AS checklist_nome 
    FROM execucoes_checklist e 
    LEFT JOIN checklists c ON c.id = e.checklist_id 
    WHERE e.id = ?
");
$stmt->execute([$execId]);
$inspecao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$inspecao) {
    die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#dc2626;"><h2>Inspeção não encontrada.</h2><p>Verifique se o ID informado na URL é válido ou se a inspeção realmente existe no banco de dados.</p><a href="planos_acao.php" style="color:#0b5cab;">Voltar aos Planos de Ação</a></div>');
}

// Busca apenas os itens marcados como NC
// Usa o título do item do modelo como fallback quando a NC não tem título próprio.
$stmtNC = $pdo->prepare("
    SELECT r.*, i.ordem, i.titulo AS item_titulo, i.setor_local AS item_setor
    FROM respostas_checklist r
    LEFT JOIN checklist_itens i ON i.id = r.item_id
    WHERE r.execucao_id = ? AND r.resultado = 'NC'
    ORDER BY r.id
");
$stmtNC->execute([$execId]);
$itensNC = $stmtNC->fetchAll(PDO::FETCH_ASSOC);

// Cálculo de Progresso Geral do Plano (CONSIDERA APENAS STATUS CONCLUÍDO)
// Percentual = NCs concluídas / total de NCs, mesma regra da listagem em planos_acao.php.
// As duas grafias de "Concluído" cobrem registros gravados sem acento.
$totalNC = count($itensNC);
$concluidas = 0;
foreach ($itensNC as $nc) {
    $statusConcluido = in_array($nc['status'] ?? '', ['Concluído', 'Concluido']);
    if ($statusConcluido) {
        $concluidas++;
    }
}
$porcentagemGeral = $totalNC > 0 ? round(($concluidas / $totalNC) * 100) : 0;

// REGRA: Só permite editar se for ADMIN e se a tratativa ainda NÃO estiver 100% concluída
$podeEditar = $isAdmin && $porcentagemGeral < 100;

// --- Salvamento do plano (POST) ---
// Trava no servidor: impede salvar em modo de visualização ou caso o plano já esteja 100% tratado
// (os campos "disabled" no formulário não bastam, pois o POST pode ser forjado).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_plano'])) {
    if (!$podeEditar) {
        die('Acesso negado: este plano de ação está em modo de visualização ou já foi 100% concluído.');
    }

    // Itera sobre as NCs carregadas do banco (e não sobre os IDs do POST), garantindo
    // que só respostas desta inspeção sejam alteradas. Campo ausente no POST mantém
    // o valor atual; prazo vazio é gravado como NULL.
    foreach ($itensNC as $nc) {
        $respId = $nc['id'];

        $acao = isset($_POST['acao_tomada'][$respId]) ? trim($_POST['acao_tomada'][$respId]) : $nc['acao_tomada'];
        $statusNc = $_POST['status_nc'][$respId] ?? $nc['status'] ?? 'Pendente';
        $grauRisco = $_POST['grau_risco'][$respId] ?? $nc['grau_risco'] ?? 'Médio';
        $responsavel = isset($_POST['responsavel_acao'][$respId]) ? trim($_POST['responsavel_acao'][$respId]) : $nc['responsavel_acao'];
        $prazo = !empty($_POST['prazo_conclusao'][$respId]) ? $_POST['prazo_conclusao'][$respId] : null;

        // O filtro por execucao_id reforça que a resposta pertence à inspeção aberta.
        $uStmt = $pdo->prepare("
            UPDATE respostas_checklist 
            SET acao_tomada = ?, grau_risco = ?, responsavel_acao = ?, prazo_conclusao = ?, status = ?
            WHERE id = ? AND execucao_id = ?
        ");
        $uStmt->execute([
            $acao,
            $grauRisco,
            $responsavel,
            $prazo,
            $statusNc,
            $respId,
            $execId
        ]);
    }

    header("Location: planos_acao.php?salvo=1");
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Plano de Ação - SST</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f8fafc; color: #1e293b; font-family: Arial, Helvetica, sans-serif; }

.wrap-plano { max-width: 1100px; margin: 28px auto 60px; padding: 0 18px; }

/* Card Topo */
.card-topo {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
}

.card-topo h2 { margin: 0 0 8px; color: #0f172a; font-size: 20px; }
.card-topo p { margin: 0 0 16px; color: #64748b; font-size: 14px; }

.barra-progresso-geral {
    background: #e2e8f0;
    height: 12px;
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progresso-fill-geral {
    height: 100%;
    background: #16a34a;
    transition: width 0.4s ease;
}

.progresso-info {
    font-size: 13px;
    font-weight: bold;
    color: #334155;
    display: flex;
    justify-content: space-between;
}

/* Card de cada NC */
.card-nc {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 6px solid #dc2626;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
}

.card-nc h4 { margin: 0 0 8px; color: #991b1b; font-size: 16px; }
.card-nc p { margin: 0 0 16px; font-size: 14px; color: #334155; line-height: 1.4; }

.grid-campos {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}

.campo-full { grid-column: 1 / -1; }

.grid-campos label {
    font-size: 12px;
    font-weight: bold;
    color: #475569;
    display: block;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.grid-campos input, 
.grid-campos select, 
.grid-campos textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px;
    font-size: 13px;
    background: #ffffff;
    color: #0f172a;
    outline: none;
    transition: border-color 0.2s;
}

/* Estilo para campos desabilitados em modo visualização */
.grid-campos input:disabled, 
.grid-campos select:disabled, 
.grid-campos textarea:disabled {
    background-color: #f1f5f9 !important;
    color: #334155 !important;
    cursor: not-allowed;
    opacity: 1;
}

.grid-campos input:focus, 
.grid-campos select:focus, 
.grid-campos textarea:focus {
    border-color: #0b5cab;
}

.select-status { font-weight: bold; }

.btn-salvar {
    background: #16a34a;
    color: #ffffff;
    border: 0;
    padding: 12px 28px;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
    font-size: 15px;
    transition: background 0.2s;
}

.btn-salvar:hover { background: #15803d; }

.badge-somente-leitura {
    background: #f1f5f9;
    color: #64748b;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: bold;
    font-size: 13px;
    border: 1px solid #cbd5e1;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

@media (max-width: 900px) {
    .grid-campos { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 600px) {
    .grid-campos { grid-template-columns: 1fr; }
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="wrap-plano">

    <!-- CABEÇALHO DO PLANO -->
    <div class="card-topo">
        <h2>Plano de Ação — Inspeção #<?= $inspecao['id'] ?></h2>
        <p>
            <strong>Checklist:</strong> <?= e($inspecao['checklist_nome']) ?> | 
            <strong>Unidade:</strong> <?= e($inspecao['unidade']) ?> | 
            <strong>Data:</strong> <?= date('d/m/Y', strtotime($inspecao['data_inspecao'])) ?>
        </p>

        <!-- BARRA DE PROGRESSO DO PLANO -->
        <div class="barra-progresso-geral">
            <div class="progresso-fill-geral" style="width: <?= $porcentagemGeral ?>%;"></div>
        </div>
        <div class="progresso-info">
            <span>Progresso da Tratativa:</span>
            <span><?= $porcentagemGeral ?>% Concluído (<?= $concluidas ?> de <?= $totalNC ?> NCs resolvidas)</span>
        </div>
    </div>

    <form method="post">
        <?php foreach ($itensNC as $index => $nc): ?>
            <?php 
                // Campos são nomeados como array indexado pelo ID da resposta (ex.: acao_tomada[15]).
                $titulo = $nc['titulo_nao_conformidade'] ?: $nc['item_titulo'];
                $idNc = $nc['id'];
                $statusAtual = $nc['status'] ?? 'Pendente';
            ?>
            <div class="card-nc">
                <h4>Item #<?= $index + 1 ?>: <?= e($titulo) ?></h4>
                <p><strong>Observação da Inspeção:</strong> <?= e($nc['detalhamento'] ?: 'Nenhuma observação detalhada.') ?></p>

                <div class="grid-campos">
                    <div class="campo-full">
                        <label>Ação Corretiva / Proposta (O que fazer?):</label>
                        <textarea name="acao_tomada[<?= $idNc ?>]" rows="2" placeholder="Descreva a ação imediata ou corretiva..." <?= $podeEditar ? '' : 'disabled' ?>><?= e($nc['acao_tomada']) ?></textarea>
                    </div>

                    <div>
                        <label>Status da NC:</label>
                        <select name="status_nc[<?= $idNc ?>]" class="select-status" <?= $podeEditar ? '' : 'disabled' ?>>
                            <option value="Pendente" <?= $statusAtual === 'Pendente' ? 'selected' : '' ?>>🔴 Pendente</option>
                            <option value="Em andamento" <?= $statusAtual === 'Em andamento' ? 'selected' : '' ?>>🟠 Em andamento</option>
                            <option value="Aguardando aprovação da diretoria" <?= $statusAtual === 'Aguardando aprovação da diretoria' ? 'selected' : '' ?>>🟡 Aguardando aprovação da diretoria</option>
                            <option value="Concluído" <?= $statusAtual === 'Concluído' ? 'selected' : '' ?>>🟢 Concluído</option>
                        </select>
                    </div>

                    <div>
                        <label>Grau de Risco:</label>
                        <select name="grau_risco[<?= $idNc ?>]" <?= $podeEditar ? '' : 'disabled' ?>>
                            <option value="Baixo" <?= ($nc['grau_risco'] ?? '') === 'Baixo' ? 'selected' : '' ?>>Baixo</option>
                            <option value="Médio" <?= ($nc['grau_risco'] ?? '') === 'Médio' ? 'selected' : '' ?>>Médio</option>
                            <option value="Alto" <?= ($nc['grau_risco'] ?? '') === 'Alto' ? 'selected' : '' ?>>Alto</option>
                            <option value="Crítico" <?= ($nc['grau_risco'] ?? '') === 'Crítico' ? 'selected' : '' ?>>Crítico</option>
                        </select>
                    </div>

                    <div>
                        <label>Responsável pela Ação:</label>
                        <input type="text" name="responsavel_acao[<?= $idNc ?>]" value="<?= e($nc['responsavel_acao']) ?>" placeholder="Nome do responsável" <?= $podeEditar ? '' : 'disabled' ?>>
                    </div>

                    <div>
                        <label>Prazo de Conclusão:</label>
                        <input type="date" name="prazo_conclusao[<?= $idNc ?>]" value="<?= e($nc['prazo_conclusao']) ?>" <?= $podeEditar ? '' : 'disabled' ?>>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div style="margin-top: 24px; display: flex; align-items: center; gap: 14px;">
            <?php if ($podeEditar): ?>
                <button type="submit" name="salvar_plano" value="1" class="btn-salvar">💾 Salvar Alterações</button>
            <?php elseif ($porcentagemGeral >= 100): ?>
                <span class="badge-somente-leitura" style="background: #dcfce7; color: #166534; border-color: #86efac;">✅ Plano Concluído (100%) — Somente Leitura</span>
            <?php else: ?>
                <span class="badge-somente-leitura">🔒 Modo de Visualização (Apenas Leitura)</span>
            <?php endif; ?>
            
            <a href="planos_acao.php" style="color: #64748b; text-decoration: none; font-weight: bold;">Voltar</a>
        </div>
    </form>
</div>

</body>
</html>