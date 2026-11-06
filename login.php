<?php 
require_once 'config.php';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u && password_verify($senha, $u['senha'])) {
        $_SESSION['usuario'] = $u;
        header('Location: nova_execucao.php'); 
        exit;
    }

    $erro = 'E-mail ou senha inválidos.';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Login - Segurança do Trabalho</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">

    <style>
    * {
        box-sizing: border-box;
    }

    body {
        margin: 0;
        padding: 0;
        font-family: Arial, Helvetica, sans-serif;
        /* Fundo Azul Médio Corporativo */
        background: radial-gradient(circle at center, #2b5288 0%, #122b4f 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    /* Card Principal de Login Compacto */
    .login-container {
        width: 100%;
        max-width: 680px; /* Reduzido para ficar mais compacto */
        background: #ffffff;
        border-radius: 16px;
        display: flex;
        overflow: hidden;
        box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.35);
    }

    /* Coluna Esquerda Escura (Logos + Mensagem) */
    .banner-lado-esquerdo {
        flex: 1;
        background: #111928;
        padding: 30px 25px; /* Padding menor */
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
    }

    /* Container para posicionar as duas logos lado a lado */
    .container-logos {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin-bottom: 16px;
        width: 100%;
        flex-wrap: wrap;
    }

    .logo-img {
        max-width: 95px; /* Logos ajustadas */
        max-height: 50px;
        width: auto;
        height: auto;
        object-fit: contain;
    }

    .banner-lado-esquerdo p {
        color: #94a3b8;
        font-size: 13px;
        margin: 0;
        line-height: 1.4;
    }

    /* Coluna Direita Branca (Formulário) */
    .form-lado-direito {
        flex: 1.1;
        padding: 32px 28px; /* Padding menor */
        background: #ffffff;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .form-lado-direito h2 {
        margin: 0 0 4px 0;
        font-size: 20px;
        color: #0f172a;
        font-weight: 700;
    }

    .subtitulo {
        margin: 0 0 20px 0;
        color: #64748b;
        font-size: 12px;
    }

    /* Alerta de Erro */
    .alerta-erro {
        background: #fef2f2;
        border: 1px solid #fca5a5;
        color: #991b1b;
        padding: 8px 12px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: bold;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Campos de Entrada e Ícones */
    .campo-grupo {
        margin-bottom: 14px;
        position: relative;
    }

    .campo-com-icone {
        position: relative;
        display: flex;
        align-items: center;
    }

    .icone-input {
        position: absolute;
        left: 14px;
        width: 16px;
        height: 16px;
        fill: #94a3b8;
        pointer-events: none;
    }

    .campo-com-icone input {
        width: 100%;
        height: 40px; /* Altura do input mais compacta */
        padding: 0 38px 0 40px;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        font-size: 13px;
        color: #0f172a;
        background: #f8fafc;
        outline: none;
        transition: all 0.2s ease;
    }

    .campo-com-icone input::placeholder {
        color: #94a3b8;
    }

    .campo-com-icone input:focus {
        border-color: #0b5cab;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(11, 92, 171, 0.1);
    }

    /* Botão Ocultar/Ver Senha */
    .btn-toggle-senha {
        position: absolute;
        right: 12px;
        background: none;
        border: none;
        cursor: pointer;
        padding: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .btn-toggle-senha svg {
        width: 16px;
        height: 16px;
        fill: #94a3b8;
        transition: fill 0.2s;
    }

    .btn-toggle-senha:hover svg {
        fill: #0f172a;
    }

    /* Checkbox Lembrar-me */
    .lembrar-wrapper {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 18px;
        margin-left: 4px;
    }

    .lembrar-wrapper input[type="checkbox"] {
        accent-color: #ff6b00;
        width: 15px;
        height: 15px;
        cursor: pointer;
    }

    .lembrar-wrapper label {
        font-size: 12px;
        color: #64748b;
        cursor: pointer;
        user-select: none;
    }

    /* Botão Laranja Compacto */
    .btn-entrar {
        width: 100%;
        height: 42px; /* Botão mais baixo */
        background: linear-gradient(135deg, #ff6b00 0%, #ff8800 100%);
        color: #ffffff;
        border: 0;
        border-radius: 21px;
        font-weight: 700;
        font-size: 12px;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 6px 16px rgba(255, 107, 0, 0.3);
    }

    .btn-entrar:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 20px rgba(255, 107, 0, 0.4);
        background: linear-gradient(135deg, #ff751a 0%, #ff941a 100%);
    }

    /* Responsividade */
    @media (max-width: 680px) {
        .login-container {
            flex-direction: column;
            max-width: 380px;
        }

        .banner-lado-esquerdo {
            padding: 25px 20px;
        }

        .form-lado-direito {
            padding: 28px 20px;
        }
    }
    </style>
</head>
<body>

<div class="login-container">
    
    <!-- LADO ESQUERDO (DARK) -->
    <div class="banner-lado-esquerdo">
        <div class="container-logos">
            <img src="logo_jb.png" alt="Logo Grupo JB" class="logo-img">
            <img src="logo_st.png" alt="Logo Saúde e Segurança do Trabalho" class="logo-img">
        </div>
        <p>Faça o login para acessar o sistema.</p>
    </div>

    <!-- LADO DIREITO (FORMULÁRIO) -->
    <div class="form-lado-direito">
        <h2>Bem-vindo(a)</h2>
        <p class="subtitulo">Acesse o sistema de Segurança do Trabalho.</p>

        <?php if ($erro): ?>
            <div class="alerta-erro">
                ⚠️ <?= e($erro) ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            
            <!-- Campo Usuário / E-mail -->
            <div class="campo-grupo">
                <div class="campo-com-icone">
                    <svg class="icone-input" viewBox="0 0 24 24">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <input 
                        type="email" 
                        id="email"
                        name="email" 
                        placeholder="Usuário ou E-mail" 
                        value="<?= e($_POST['email'] ?? '') ?>" 
                        required 
                        autofocus>
                </div>
            </div>

            <!-- Campo Senha -->
            <div class="campo-grupo">
                <div class="campo-com-icone">
                    <svg class="icone-input" viewBox="0 0 24 24">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                    </svg>
                    <input 
                        type="password" 
                        id="senha"
                        name="senha" 
                        placeholder="Senha" 
                        required>
                    <button type="button" class="btn-toggle-senha" onclick="toggleSenha()" title="Alternar visibilidade">
                        <svg id="icone-olho" viewBox="0 0 24 24">
                            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Lembrar-me -->
            <div class="lembrar-wrapper">
                <input type="checkbox" id="lembrar" name="lembrar">
                <label for="lembrar">Lembrar-me</label>
            </div>

            <!-- Botão Entrar -->
            <button type="submit" class="btn-entrar">ENTRAR NO SISTEMA</button>
        </form>

    </div>
</div>

<script>
function toggleSenha() {
    var input = document.getElementById('senha');
    var icone = document.getElementById('icone-olho');
    
    if (input.type === 'password') {
        input.type = 'text';
        icone.innerHTML = '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.17c0-1.66-1.34-3-3-3l-.17.02z"/>';
    } else {
        input.type = 'password';
        icone.innerHTML = '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
    }
}
</script>

</body>
</html>