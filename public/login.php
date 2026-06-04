<?php
require_once __DIR__ . '/../includes/login_manager.php';
require_once __DIR__ . '/../includes/security.php';
start_secure_session();
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Login DU</title>
<link rel="stylesheet" href="estilos.css">
<style>
  body.login-page {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    margin: 0;
    background: #f0f2f5;
    padding: 16px;
    box-sizing: border-box;
  }
  .login-container {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    min-width: 320px;
    width: min(700px, calc(100vw - 40px));
  }
  .mensaje-login {
    background: #fff4f4;
    color: #b00000;
    border: 1px solid #e0a0a0;
    padding: 10px;
    border-radius: 4px;
    text-align: center;
    margin-bottom: 15px;
    font-size: 0.95rem;
  }
</style>
</head>
<body class="login-page">

<div class="login-container">

  <form method="POST" action="../datoswebDU.php">
    <input type="hidden" name="accion" value="login">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

    <h2 style="text-align:center;margin-top:0;margin-bottom:1rem;">Acceso al sistema</h2>

    <label>Usuario</label>
    <input type="text" name="usuario" required style="width:100%;padding:8px;margin-bottom:10px;" autocomplete="username">

    <label>Contraseña</label>
    <input type="password" name="password_hash" required style="width:100%;padding:8px;margin-bottom:15px;" autocomplete="current-password">

    <button type="submit" style="width:100%;">Entrar</button>

    <?php if (!empty($_GET['msg'])): ?>
      <div class="mensaje-login">
        <?php echo htmlspecialchars($_GET['msg']); ?>
      </div>
    <?php endif; ?>
  </form>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
