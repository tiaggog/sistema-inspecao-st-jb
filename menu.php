<?php 
/**
 * Menu de navegação superior (partial).
 *
 * Não é acessado diretamente: é incluído via `include 'menu.php'` nas páginas
 * internas, que já carregaram o config.php e validaram o login.
 * Fluxo: identifica o usuário logado e seu perfil, marca o link da página
 * atual como ativo e exibe o item "Usuários" somente para administradores.
 */

// Perfil do usuário: prioriza o campo 'perfil' retornado por usuarioAtual() e usa
// $_SESSION['perfil'] como fallback. Apenas 'admin' vê a gestão de usuários.
$u = usuarioAtual();
$isAdmin = ($u['perfil'] ?? $_SESSION['perfil'] ?? '') === 'admin';

// Identifica a página atual para aplicar o estilo ativo automaticamente
$paginaAtual = basename($_SERVER['PHP_SELF']);
?>
<style>
/* Reset básico do menu */
.topo-smart-hub {
    background-color: #031b4e; /* Azul escuro do Smart Hub */
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 28px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}

.box-logos-topo {
    display: flex;
    align-items: center;
    gap: 14px;
}

.img-logo-menu {
    height: 42px; /* Aumentado de 38px para acompanhar o tamanho da fonte */
    width: auto;
    object-fit: contain;
}

.nav-links-smart {
    display: flex;
    align-items: center;
    gap: 26px; /* Aumentado o espaço entre os links */
}

.nav-links-smart a {
    color: #9eb5e2; /* Cor padrão dos links inativos */
    text-decoration: none;
    font-family: 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif;
    font-size: 17px; /* Aumentado o tamanho da fonte */
    font-weight: 500;
    letter-spacing: 0.2px;
    transition: color 0.2s ease;
}

.nav-links-smart a:hover {
    color: #ffffff; /* Efeito ao passar o mouse */
}

/* Item ativo (página atual) estilo Smart Hub */
.nav-links-smart a.active {
    color: #ffffff !important;
    font-weight: 700 !important;
}

/* Estilo para o link de Sair */
.nav-links-smart a.btn-sair {
    color: #f87171;
    margin-left: 10px;
}

.nav-links-smart a.btn-sair:hover {
    color: #ef4444;
}
</style>

<div class="topo-smart-hub">
    <!-- LOGOS LADO A LADO -->
    <div class="box-logos-topo">
        <img src="logo_jb.png" alt="Logo Grupo JB" class="img-logo-menu">
        <img src="logo_st.png" alt="Logo SST" class="img-logo-menu">
    </div>
    
    <!-- LINKS NAVEGAÇÃO SMART HUB -->
    <div class="nav-links-smart">
        <a href="nova_execucao.php" class="<?= $paginaAtual === 'nova_execucao.php' ? 'active' : '' ?>">Inspeções</a>
        <a href="planos_acao.php" class="<?= $paginaAtual === 'planos_acao.php' ? 'active' : '' ?>">Planos de Ação</a>
        <a href="checklists.php" class="<?= $paginaAtual === 'checklists.php' ? 'active' : '' ?>">Checklists</a>
        <a href="relatorios.php" class="<?= $paginaAtual === 'relatorios.php' ? 'active' : '' ?>">Relatórios</a>
        
        <?php if ($isAdmin): ?>
            <a href="usuarios.php" class="<?= $paginaAtual === 'usuarios.php' ? 'active' : '' ?>">Usuários</a>
        <?php endif; ?>
        
        <a href="logout.php" class="btn-sair">Sair</a>
    </div>
</div>