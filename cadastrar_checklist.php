<?php
/**
 * Cadastro de um novo modelo de checklist de inspeção.
 *
 * Acesso: requer login (qualquer perfil).
 *
 * POST: nome, unidade, codigo_documento, data_aprovacao (obrigatórios) e
 *       introducao (opcional, texto de apresentação exibido no relatório).
 *
 * Fluxo: exibe o formulário; no envio, valida os campos obrigatórios, grava o
 * modelo em `checklists` e redireciona para checklists.php. Os itens do modelo
 * são cadastrados depois, em itens_checklist.php.
 */
require_once 'config.php';
exigirLogin();

// Lista fixa de filiais do Grupo JB (mesma usada em usuarios.php)
$filiaisDisponiveis = [
    'FORTALEZA',
    'IMPERATRIZ',
    'JUAZEIRO',
    'SÃO LUIS',
    'SOBRAL',
    'TERESINA'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Padroniza o nome em CAIXA ALTA no backend (o CSS só altera a exibição no campo).
    // mb_strtoupper com UTF-8 garante a conversão correta de acentos (ex.: "inspeção" -> "INSPEÇÃO")
    $nome = mb_strtoupper(trim($_POST['nome'] ?? ''), 'UTF-8');
    $unidade = trim($_POST['unidade'] ?? '');
    $codigoDocumento = trim($_POST['codigo_documento'] ?? '');
    $dataAprovacao = trim($_POST['data_aprovacao'] ?? '');
    $introducao = trim($_POST['introducao'] ?? '');

    // Código do documento e data de aprovação vêm do Setor de Projetos (controle documental).
    // Se faltar algum obrigatório, o formulário é apenas exibido novamente.
    if (!empty($nome) && !empty($unidade) && !empty($codigoDocumento) && !empty($dataAprovacao)) {
        $stmt = $pdo->prepare('INSERT INTO checklists (nome, unidade, codigo_documento, data_aprovacao, introducao) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            $nome,
            $unidade,
            $codigoDocumento,
            $dataAprovacao,
            $introducao
        ]);
        
        // Padrão Post/Redirect/Get: evita reenvio do formulário ao atualizar a página
        header('Location: checklists.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Novo Checklist - SST</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="style.css">

<style>
* { box-sizing: border-box; }
body { margin: 0; background: #f8fafc; color: #1e293b; font-family: Arial, Helvetica, sans-serif; }

.container-pagina { max-width: 800px; margin: 28px auto 60px; padding: 0 20px; }

/* Cabeçalho Topo */
.top-header { margin-bottom: 24px; }
.top-header h1 { margin: 0; font-size: 24px; color: #0f172a; font-weight: 700; }
.top-header p { margin: 4px 0 0; color: #64748b; font-size: 14px; }

/* Card do Formulário */
.card-form { 
    background: #ffffff; 
    border: 1px solid #e2e8f0; 
    border-radius: 12px; 
    padding: 28px; 
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03); 
}

.campo-form { margin-bottom: 20px; }
.campo-form label { 
    display: block; 
    font-size: 12px; 
    font-weight: 700; 
    color: #475569; 
    margin-bottom: 6px; 
    text-transform: uppercase; 
    letter-spacing: 0.5px; 
}

.campo-form input, 
.campo-form select,
.campo-form textarea { 
    width: 100%; 
    padding: 10px 14px; 
    border: 1px solid #cbd5e1; 
    border-radius: 8px; 
    font-size: 14px; 
    outline: none; 
    background: #ffffff;
    color: #0f172a;
    transition: border-color 0.2s; 
}

/* Layout em duas colunas */
.linha-dupla {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}

.linha-dupla .campo-form {
    margin-bottom: 0;
}

@media (max-width: 600px) {
    .linha-dupla {
        grid-template-columns: 1fr;
        gap: 16px;
    }
}

/* Caixinha de Aviso em Destaque */
.caixa-aviso {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-left: 4px solid #ef4444;
    border-radius: 6px;
    padding: 8px 10px;
    margin-top: 8px;
    color: #991b1b;
    font-size: 11.5px;
    line-height: 1.4;
}

.caixa-aviso span.icone {
    font-size: 13px;
    line-height: 1;
}

.caixa-aviso strong {
    color: #dc2626;
}

/* Força exibição em caixa alta no input de nome */
.campo-form input.caixa-alta {
    text-transform: uppercase;
}

.campo-form input:focus, 
.campo-form select:focus,
.campo-form textarea:focus { 
    border-color: #0b5cab; 
}

.campo-form textarea { 
    height: 100px; 
    resize: vertical; 
    font-family: Arial, sans-serif; 
}

.acoes-form { 
    display: flex; 
    gap: 12px; 
    margin-top: 28px; 
    align-items: center;
}

.btn-salvar { 
    background: #16a34a; 
    color: #ffffff; 
    border: 0; 
    padding: 12px 24px; 
    border-radius: 8px; 
    font-weight: bold; 
    cursor: pointer; 
    font-size: 14px; 
    transition: background 0.2s; 
}

.btn-salvar:hover { 
    background: #15803d; 
}

.btn-voltar { 
    background: #64748b; 
    color: #ffffff; 
    padding: 12px 20px; 
    border-radius: 8px; 
    font-weight: bold; 
    text-decoration: none; 
    font-size: 14px; 
    display: inline-block; 
    transition: background 0.2s;
}

.btn-voltar:hover {
    background: #475569;
}
</style>
</head>

<body>
<?php include 'menu.php'; ?>

<div class="container-pagina">
    <div class="top-header">
        <h1>Novo Modelo de Checklist</h1>
        <p>Cadastre um novo modelo de inspeção para SST.</p>
    </div>

    <div class="card-form">
        <form method="post">
            <div class="campo-form">
                <label>Nome <span style="color:#dc2626;">*</span></label>
                <input name="nome" class="caixa-alta" required placeholder="EX.: INSPEÇÃO SEMANAL - SST">
            </div>

            <div class="campo-form">
                <label>Unidade / Filial <span style="color:#dc2626;">*</span></label>
                <select name="unidade" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($filiaisDisponiveis as $f): ?>
                        <option value="<?= $f ?>"><?= $f ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="linha-dupla">
                <div class="campo-form">
                    <label>Código do Documento <span style="color:#dc2626;">*</span></label>
                    <input name="codigo_documento" required placeholder="Ex.: FRPR01-00">
                    <div class="caixa-aviso">
                        <span class="icone">⚠️</span>
                        <span><strong>Atenção:</strong> solicitar o código ao Setor de Projetos antes de realizar o preenchimento.</span>
                    </div>
                </div>

                <div class="campo-form">
                    <label>Data da Aprovação <span style="color:#dc2626;">*</span></label>
                    <input type="date" name="data_aprovacao" required>
                    <div class="caixa-aviso">
                        <span class="icone">⚠️</span>
                        <span><strong>Atenção:</strong> solicitar a data de aprovação ao Setor de Projetos antes de realizar o preenchimento.</span>
                    </div>
                </div>
            </div>

            <div class="campo-form">
                <label>Introdução</label>
                <textarea name="introducao" placeholder="Instruções ou resumo inicial sobre a realização deste checklist..."></textarea>
            </div>

            <div class="acoes-form">
                <button type="submit" class="btn-salvar">💾 Salvar Modelo</button>
                <a href="checklists.php" class="btn-voltar">Cancelar / Voltar</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>