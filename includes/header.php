<?php /* includes/header.php - cabecera común con pestañas. $actual = archivo en curso, para resaltar la pestaña activa. */ ?>
<?php $actual = basename($_SERVER['SCRIPT_NAME'] ?? ''); ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($titulo ?? 'Sistema contable') ?></title>
    <link rel="stylesheet" href="/css/estilos.css">
</head>
<body>
    <header class="barra">
        <a class="marca" href="/index.php">Sistema Contable</a>
        <nav class="tabs">
            <a href="/index.php"     class="<?= $actual === 'index.php' ? 'activo' : '' ?>">Inicio</a>
            <a href="/empresas.php"  class="<?= $actual === 'empresas.php' ? 'activo' : '' ?>">Empresas</a>
            <a href="/contactos.php" class="<?= ($actual === 'contactos.php' || $actual === 'editar.php') ? 'activo' : '' ?>">Contactos</a>
            <a href="/ventas.php"    class="<?= $actual === 'ventas.php' ? 'activo' : '' ?>">Ventas</a>
            <a href="/compras.php"   class="<?= $actual === 'compras.php' ? 'activo' : '' ?>">Compras</a>
            <a href="/resumen.php"      class="<?= $actual === 'resumen.php' ? 'activo' : '' ?>">Resumen</a>
            <a href="/liquidacion.php"          class="<?= $actual === 'liquidacion.php' ? 'activo' : '' ?>">Liquidación</a>
            <a href="/importar_retenciones.php" class="<?= $actual === 'importar_retenciones.php' ? 'activo' : '' ?>">Retenciones</a>
            <a href="/importar.php"             class="<?= $actual === 'importar.php' ? 'activo' : '' ?>">Importar</a>
        </nav>
        <button id="toggle-tema" class="toggle-tema" type="button" aria-label="Cambiar tema">🌙</button>
        <?php if (function_exists('usuarioNombre') && usuarioNombre() !== ''): ?>
            <span class="usuario-barra"><?= htmlspecialchars(usuarioNombre()) ?></span>
            <a href="/logout.php" class="btn" style="padding:.4rem .7rem;">Salir</a>
        <?php endif; ?>
    </header>
    <main class="contenedor">