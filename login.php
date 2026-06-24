<?php
/*
 * login.php
 * ---------
 * Inicio de sesión. Si todavía no existe ningún usuario, muestra el alta del
 * primer usuario (bootstrap inicial). Las contraseñas se guardan hasheadas con
 * password_hash() (bcrypt); nunca en texto plano.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

// Si ya está logueado, no tiene sentido ver el login.
if (!empty($_SESSION['uid'])) { header('Location: /index.php'); exit; }

$hayUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn() > 0;
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $clave   = (string)($_POST['clave'] ?? '');
    $accion  = $_POST['accion'] ?? 'login';

    if ($usuario === '' || $clave === '') {
        $errores[] = 'Completá usuario y contraseña.';
    } elseif ($accion === 'crear' && !$hayUsuarios) {
        // Alta del primer usuario.
        if (strlen($clave) < 4) {
            $errores[] = 'La contraseña debe tener al menos 4 caracteres.';
        } else {
            $pdo->prepare("INSERT INTO usuarios (usuario, password_hash) VALUES (:u, :h)")
                ->execute([':u' => $usuario, ':h' => password_hash($clave, PASSWORD_DEFAULT)]);
            $_SESSION['uid']     = (int)$pdo->lastInsertId();
            $_SESSION['usuario'] = $usuario;
            header('Location: /index.php'); exit;
        }
    } else {
        // Login normal: buscar usuario y verificar el hash.
        $st = $pdo->prepare("SELECT id, password_hash FROM usuarios WHERE usuario = :u");
        $st->execute([':u' => $usuario]);
        $row = $st->fetch();
        if ($row && password_verify($clave, $row['password_hash'])) {
            session_regenerate_id(true);            // evita fijación de sesión
            $_SESSION['uid']     = (int)$row['id'];
            $_SESSION['usuario'] = $usuario;
            header('Location: /index.php'); exit;
        }
        $errores[] = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $hayUsuarios ? 'Ingresar' : 'Crear usuario' ?> - Sistema Contable</title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
    <main class="contenedor" style="max-width:420px;">
        <h2 style="margin-top:2rem;"><?= $hayUsuarios ? 'Ingresar' : 'Crear el primer usuario' ?></h2>
        <?php if (!$hayUsuarios): ?>
            <p>No hay usuarios todavía. Creá el primero para empezar.</p>
        <?php endif; ?>
        <?php if ($errores): ?>
            <ul class="errores"><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <form method="post" class="formulario">
            <input type="hidden" name="accion" value="<?= $hayUsuarios ? 'login' : 'crear' ?>">
            <label>Usuario
                <input type="text" name="usuario" autocomplete="username" autofocus>
            </label>
            <label>Contraseña
                <input type="password" name="clave" autocomplete="<?= $hayUsuarios ? 'current-password' : 'new-password' ?>">
            </label>
            <button type="submit" class="btn btn-guardar"><?= $hayUsuarios ? 'Ingresar' : 'Crear usuario' ?></button>
        </form>
    </main>
</body>
</html>