<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

/*
 * Calcula los totales de un "lado" (ventas o compras) para una empresa y período.
 * Aplica el SIGNO según el tipo de comprobante: las Notas de Crédito restan.
 * Recibe los nombres de tabla (controlados por nosotros, no por el usuario).
 */
function resumenLado(PDO $pdo, string $cab, string $det, string $fk, int $empresaId, string $periodoLike): array {
    // Cabeceras: no gravado, exento y otros tributos (con signo) + cantidad de comprobantes.
    $sqlCab = "SELECT
        COALESCE(SUM(CASE WHEN comprobante_tipo='Nota de Crédito' THEN -1 ELSE 1 END * monto_no_gravado),0) AS no_gravado,
        COALESCE(SUM(CASE WHEN comprobante_tipo='Nota de Crédito' THEN -1 ELSE 1 END * monto_exento),0)     AS exento,
        COALESCE(SUM(CASE WHEN comprobante_tipo='Nota de Crédito' THEN -1 ELSE 1 END * otros_tributos),0)   AS otros,
        COUNT(*) AS cantidad
      FROM $cab WHERE empresa_id = :emp AND fecha LIKE :per";
    $stC = $pdo->prepare($sqlCab);
    $stC->execute([':emp' => $empresaId, ':per' => $periodoLike]);
    $c = $stC->fetch();

    // Detalle: gravado e IVA (con signo según la cabecera a la que pertenece la línea).
    $sqlDet = "SELECT
        COALESCE(SUM(CASE WHEN h.comprobante_tipo='Nota de Crédito' THEN -1 ELSE 1 END * d.monto_gravado),0) AS gravado,
        COALESCE(SUM(CASE WHEN h.comprobante_tipo='Nota de Crédito' THEN -1 ELSE 1 END * d.iva),0)           AS iva
      FROM $cab h JOIN $det d ON d.$fk = h.id
      WHERE h.empresa_id = :emp AND h.fecha LIKE :per";
    $stD = $pdo->prepare($sqlDet);
    $stD->execute([':emp' => $empresaId, ':per' => $periodoLike]);
    $d = $stD->fetch();

    $total = $c['no_gravado'] + $c['exento'] + $c['otros'] + $d['gravado'] + $d['iva'];
    return [
        'cantidad'   => (int)$c['cantidad'],
        'no_gravado' => (float)$c['no_gravado'],
        'exento'     => (float)$c['exento'],
        'otros'      => (float)$c['otros'],
        'gravado'    => (float)$d['gravado'],
        'iva'        => (float)$d['iva'],
        'total'      => (float)$total,
    ];
}

// --- Filtros (vienen por GET, así el reporte es enlazable) ---
$empresaId = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT);
$periodo   = trim($_GET['periodo'] ?? '');   // 'AAAA-MM' o vacío = todos

$empresas = $pdo->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll();

$ventasR = $comprasR = null;
$posicion = 0.0;
if ($empresaId) {
    // Para el LIKE: 'AAAA-MM%' filtra ese mes; '%' (vacío) trae todo.
    $periodoLike = $periodo !== '' ? $periodo . '%' : '%';
    $ventasR  = resumenLado($pdo, 'ventas', 'venta_alicuotas', 'venta_id', $empresaId, $periodoLike);
    $comprasR = resumenLado($pdo, 'compras', 'compra_alicuotas', 'compra_id', $empresaId, $periodoLike);
    $posicion = $ventasR['iva'] - $comprasR['iva'];   // Débito (ventas) - Crédito (compras)
}

$titulo = 'Resumen';
require __DIR__ . '/includes/header.php';

// Formatea un número como pesos: 1234.5 -> $1.234,50
function pesos(float $n): string { return '$' . number_format($n, 2, ',', '.'); }
// Convierte 'AAAA-MM' a 'Mes AAAA' (ej. '2026-06' -> 'Junio 2026'); vacío => 'todos los períodos'.
function periodoLindo(string $p): string {
    if ($p === '') return 'todos los períodos';
    $meses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    [$a, $m] = array_pad(explode('-', $p), 2, '');
    return ($meses[(int)$m] ?? $p) . ' ' . $a;
}
?>

<h2>Resumen / posición de IVA</h2>

<!-- Filtro: empresa + período. Va por GET para que el resultado quede en la URL. -->
<form method="get" class="formulario" style="max-width:520px;">
    <label>Empresa
        <select name="empresa_id">
            <option value="">Elegí...</option>
            <?php foreach ($empresas as $em): ?>
                <option value="<?= (int)$em['id'] ?>" <?= $empresaId === (int)$em['id'] ? 'selected' : '' ?>><?= htmlspecialchars($em['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Período
        <input type="month" name="periodo" value="<?= htmlspecialchars($periodo) ?>">
        <small style="display:block; font-weight:400; color:#7b8794; margin-top:.25rem;">Elegí <strong>mes y año</strong> (con el siguiente formato de ej: 2026-05). Dejalo vacío para incluir todos los períodos.</small>
    </label>
    <button type="submit" class="btn btn-guardar">Ver resumen</button>
</form>

<?php if ($empresaId && $ventasR && $comprasR): ?>

    <!-- Confirma en texto qué empresa y período se está mostrando. -->
    <?php $empNom = ''; foreach ($empresas as $em) { if ((int)$em['id'] === $empresaId) { $empNom = $em['nombre']; } } ?>
    <p style="margin-top:1.5rem; color:#3e4c59;">Mostrando: <strong><?= htmlspecialchars($empNom) ?></strong> · <strong><?= htmlspecialchars(periodoLindo($periodo)) ?></strong></p>

    <table style="margin-top:1.5rem;">
        <thead><tr><th>Concepto</th><th>Ventas (débito)</th><th>Compras (crédito)</th></tr></thead>
        <tbody>
            <tr><td>Comprobantes</td><td><?= $ventasR['cantidad'] ?></td><td><?= $comprasR['cantidad'] ?></td></tr>
            <tr><td>Neto gravado</td><td><?= pesos($ventasR['gravado']) ?></td><td><?= pesos($comprasR['gravado']) ?></td></tr>
            <tr><td>No gravado</td><td><?= pesos($ventasR['no_gravado']) ?></td><td><?= pesos($comprasR['no_gravado']) ?></td></tr>
            <tr><td>Exento</td><td><?= pesos($ventasR['exento']) ?></td><td><?= pesos($comprasR['exento']) ?></td></tr>
            <tr><td>Otros tributos</td><td><?= pesos($ventasR['otros']) ?></td><td><?= pesos($comprasR['otros']) ?></td></tr>
            <tr><td><strong>IVA</strong></td><td><strong><?= pesos($ventasR['iva']) ?></strong></td><td><strong><?= pesos($comprasR['iva']) ?></strong></td></tr>
            <tr><td>Total</td><td><?= pesos($ventasR['total']) ?></td><td><?= pesos($comprasR['total']) ?></td></tr>
        </tbody>
    </table>

    <!-- Caja con la posición de IVA: débito - crédito. -->
    <div class="venta-card" style="margin-top:1.5rem; max-width:520px;">
        <div class="venta-pie" style="justify-content:space-between;">
            <span>IVA Débito (ventas): <strong><?= pesos($ventasR['iva']) ?></strong></span>
            <span>IVA Crédito (compras): <strong><?= pesos($comprasR['iva']) ?></strong></span>
        </div>
        <hr style="margin:.6rem 0; border:none; border-top:1px solid #e4e7eb;">
        <p style="font-size:1.1rem;">
            <?php if ($posicion > 0): ?>
                <strong>Posición de IVA: saldo a pagar <?= pesos($posicion) ?></strong>
            <?php elseif ($posicion < 0): ?>
                <strong>Posición de IVA: saldo a favor <?= pesos(abs($posicion)) ?></strong>
            <?php else: ?>
                <strong>Posición de IVA: equilibrada ($0,00)</strong>
            <?php endif; ?>
        </p>
        <p style="font-size:.8rem; color:#7b8794;">Débito − Crédito. Las notas de crédito ya están restadas. Importes en pesos.</p>
    </div>

<?php elseif ($empresaId): ?>
    <p style="margin-top:1rem;">No hay datos para esa empresa y período.</p>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>