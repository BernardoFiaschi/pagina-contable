<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// Conteos rápidos para el panel.
$stats = [
    'Empresas'  => $pdo->query("SELECT COUNT(*) FROM empresas")->fetchColumn(),
    'Contactos' => $pdo->query("SELECT COUNT(*) FROM contactos")->fetchColumn(),
    'Ventas'    => $pdo->query("SELECT COUNT(*) FROM ventas")->fetchColumn(),
    'Compras'   => $pdo->query("SELECT COUNT(*) FROM compras")->fetchColumn(),
];

$titulo = 'Inicio';
require __DIR__ . '/includes/header.php';
?>

<h2>Inicio</h2>
<p>Gestión contable simple: cargá empresas y contactos, registrá ventas y compras con su detalle de IVA, y consultá la posición de IVA por período.</p>

<div class="stats">
    <?php foreach ($stats as $nombre => $n): ?>
        <div class="stat"><span class="stat-num"><?= (int)$n ?></span><span class="stat-lbl"><?= $nombre ?></span></div>
    <?php endforeach; ?>
</div>

<div class="menu-cards">
    <a class="card" href="/empresas.php"><h3>Empresas</h3><p>Los titulares cuyos libros llevás.</p></a>
    <a class="card" href="/contactos.php"><h3>Contactos</h3><p>Clientes y proveedores.</p></a>
    <a class="card" href="/ventas.php"><h3>Ventas</h3><p>Registrar y ver comprobantes emitidos.</p></a>
    <a class="card" href="/compras.php"><h3>Compras</h3><p>Registrar y ver comprobantes recibidos.</p></a>
    <a class="card" href="/resumen.php"><h3>Resumen</h3><p>Posición de IVA por empresa y período.</p></a>
    <a class="card" href="/importar.php"><h3>Importar</h3><p>Subir el CSV de ARCA (ventas o compras).</p></a>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>