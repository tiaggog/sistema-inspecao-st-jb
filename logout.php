<?php require_once 'config.php';
/**
 * Logout do sistema.
 *
 * Acesso: qualquer usuário (não exige login).
 * Fluxo: carrega a configuração (que inicia a sessão), destrói a sessão
 * atual e redireciona para a tela de login.
 */
session_destroy(); header('Location: login.php'); exit; ?>
