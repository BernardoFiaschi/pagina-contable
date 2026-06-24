<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requerirLogin();
$uid = usuarioId();

function pesos(float $n): string { return '$'.number_format($n,2,',','.'); }

/*
 * Calcula un "lado" (ventas o compras) para una empresa y rango de fechas.
 * Devuelve totales generales + desglose por alícuota IVA + compras por clasificación.
 */
function resumenLado(PDO $pdo, string $cab, string $det, string $fk, int $eid, string $desde, string $hasta): array {
    $base = "FROM $cab h JOIN $det d ON d.$fk=h.id WHERE h.empresa_id=:e AND h.fecha BETWEEN :d AND :h";
    $par  = [':e'=>$eid, ':d'=>$desde, ':h'=>$hasta];
    $signo= "CASE WHEN h.comprobante_tipo='Nota de Crédito' THEN -1 ELSE 1 END";

    // Totales cabecera
    $stC = $pdo->prepare("SELECT
        COALESCE(SUM($signo*h.monto_no_gravado),0) AS no_gravado,
        COALESCE(SUM($signo*h.monto_exento),0)     AS exento,
        COALESCE(SUM($signo*h.otros_tributos),0)   AS otros,
        COUNT(DISTINCT h.id)                        AS cantidad
        FROM $cab h WHERE h.empresa_id=:e AND h.fecha BETWEEN :d AND :h");
    $stC->execute($par); $c = $stC->fetch();

    // Desglose por alícuota
    $stA = $pdo->prepare("SELECT d.alicuota,
        COALESCE(SUM($signo*d.monto_gravado),0) AS gravado,
        COALESCE(SUM($signo*d.iva),0)           AS iva
        $base GROUP BY d.alicuota ORDER BY d.alicuota DESC");
    $stA->execute($par); $porAlic = $stA->fetchAll();

    $totalGravado = array_sum(array_column($porAlic,'gravado'));
    $totalIVA     = array_sum(array_column($porAlic,'iva'));

    // Percepciones/retenciones
    $percCols = ($cab === 'compras')
        ? ['percepcion_iva','percepcion_ganancias','percepcion_iibb']
        : ['retencion_iva','retencion_ganancias','retencion_iibb'];

    $percTotales = [];
    foreach ($percCols as $col) {
        $stP = $pdo->prepare("SELECT COALESCE(SUM($signo*h.$col),0) FROM $cab h WHERE h.empresa_id=:e AND h.fecha BETWEEN :d AND :h");
        $stP->execute($par);
        $percTotales[$col] = (float)$stP->fetchColumn();
    }

    return [
        'cantidad'    => (int)$c['cantidad'],
        'no_gravado'  => (float)$c['no_gravado'],
        'exento'      => (float)$c['exento'],
        'otros'       => (float)$c['otros'],
        'por_alicuota'=> $porAlic,
        'total_gravado'=> $totalGravado,
        'total_iva'   => $totalIVA,
        'total'       => $c['no_gravado'] + $c['exento'] + $c['otros'] + $totalGravado + $totalIVA,
        'percepciones'=> $percTotales,
    ];
}

// Filtros
$empresaId = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT);
$desde     = trim($_GET['desde'] ?? '');
$hasta     = trim($_GET['hasta'] ?? '');
if ($desde === '') $desde = date('Y-m-01');
if ($hasta === '') $hasta = date('Y-m-t');

$empresas = $pdo->prepare("SELECT id, nombre FROM empresas WHERE usuario_id=:uid ORDER BY nombre");
$empresas->execute([':uid'=>$uid]); $empresas = $empresas->fetchAll();

$ventasR = $comprasR = null; $posicion = 0.0;
$retenciones = [];

if ($empresaId) {
    $ventasR  = resumenLado($pdo,'ventas','venta_alicuotas','venta_id',$empresaId,$desde,$hasta);
    $comprasR = resumenLado($pdo,'compras','compra_alicuotas','compra_id',$empresaId,$desde,$hasta);
    $posicion = $ventasR['total_iva'] - $comprasR['total_iva'];

    // Retenciones importadas del período
    $stR = $pdo->prepare("SELECT tipo, SUM(importe) AS total FROM retenciones
        WHERE empresa_id=:e AND fecha BETWEEN :d AND :h GROUP BY tipo ORDER BY tipo");
    $stR->execute([':e'=>$empresaId,':d'=>$desde,':h'=>$hasta]);
    foreach ($stR->fetchAll() as $r) $retenciones[$r['tipo']] = (float)$r['total'];

    // Compras por clasificación
    $stClas = $pdo->prepare("
        SELECT c.clasificacion,
            COALESCE(SUM(CASE WHEN c.comprobante_tipo='Nota de Crédito' THEN -1 ELSE 1 END * a.monto_gravado),0) AS gravado,
            COALESCE(SUM(CASE WHEN c.comprobante_tipo='Nota de Crédito' THEN -1 ELSE 1 END * a.iva),0)           AS iva,
            c.monto_no_gravado + c.monto_exento + c.otros_tributos + COALESCE(SUM(a.monto_gravado+a.iva),0) AS subtotal,
            COUNT(DISTINCT c.id) AS cantidad
        FROM compras c
        LEFT JOIN compra_alicuotas a ON a.compra_id=c.id
        WHERE c.empresa_id=:e AND c.fecha BETWEEN :d AND :h
        GROUP BY c.clasificacion");
    $stClas->execute([':e'=>$empresaId,':d'=>$desde,':h'=>$hasta]);
    $comprasPorClas = $stClas->fetchAll();
}

$titulo = 'Resumen';
require __DIR__ . '/includes/header.php';
$clasLabels = ['servicio'=>'Prestación de servicio','bien_cambio'=>'Bienes de cambio','bien_uso'=>'Bienes de uso',''=>'Sin clasificar'];
$tiposLabel = [
    'percepcion_iibb'=>'Percepción IIBB','retencion_iibb'=>'Retención IIBB',
    'retencion_bancaria_iibb'=>'Ret. Bancaria IIBB',
    'percepcion_iva'=>'Percepción IVA','percepcion_ganancias'=>'Percepción Ganancias',
    'retencion_iva'=>'Retención IVA','retencion_ganancias'=>'Retención Ganancias',
];
?>
<h2>Resumen / posición de IVA</h2>

<form method="get" class="filtros">
    <label>Empresa
        <select name="empresa_id">
            <option value="">Elegí...</option>
            <?php foreach($empresas as $em): ?>
                <option value="<?= $em['id'] ?>" <?= $empresaId===(int)$em['id']?'selected':'' ?>><?= htmlspecialchars($em['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Desde <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"></label>
    <label>Hasta <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"></label>
    <button type="submit" class="btn btn-guardar">Ver resumen</button>
</form>

<?php if ($empresaId && $ventasR && $comprasR): ?>
<?php $empNom=''; foreach($empresas as $em){ if((int)$em['id']===$empresaId) $empNom=$em['nombre']; } ?>
<p style="margin-top:1rem;">
    <strong><?= htmlspecialchars($empNom) ?></strong> · <?= htmlspecialchars($desde) ?> al <?= htmlspecialchars($hasta) ?>
</p>

<!-- Ventas -->
<div class="venta-card" style="margin-top:1.25rem;">
    <h3>Ventas (débito fiscal)</h3>
    <div style="overflow-x:auto;">
    <table class="tabla-lista">
        <thead><tr><th>Concepto</th><th class="num">Importe</th></tr></thead>
        <tbody>
            <tr><td>Comprobantes</td><td class="num"><?= $ventasR['cantidad'] ?></td></tr>
            <?php foreach($ventasR['por_alicuota'] as $al): ?>
            <tr>
                <td style="padding-left:1.5rem;">Neto gravado <?= str_replace('.',',',(string)(float)$al['alicuota']) ?>%</td>
                <td class="num"><?= pesos((float)$al['gravado']) ?></td>
            </tr>
            <tr>
                <td style="padding-left:1.5rem;">IVA <?= str_replace('.',',',(string)(float)$al['alicuota']) ?>%</td>
                <td class="num"><?= pesos((float)$al['iva']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr><td>No gravado</td><td class="num"><?= pesos($ventasR['no_gravado']) ?></td></tr>
            <tr><td>Exento</td><td class="num"><?= pesos($ventasR['exento']) ?></td></tr>
            <tr><td>Otros tributos</td><td class="num"><?= pesos($ventasR['otros']) ?></td></tr>
            <?php if ($ventasR['percepciones']['retencion_iva'] ?? 0): ?><tr><td>Retención IVA sufrida</td><td class="num"><?= pesos($ventasR['percepciones']['retencion_iva']) ?></td></tr><?php endif; ?>
            <?php if ($ventasR['percepciones']['retencion_ganancias'] ?? 0): ?><tr><td>Retención Ganancias</td><td class="num"><?= pesos($ventasR['percepciones']['retencion_ganancias']) ?></td></tr><?php endif; ?>
            <?php if ($ventasR['percepciones']['retencion_iibb'] ?? 0): ?><tr><td>Retención IIBB</td><td class="num"><?= pesos($ventasR['percepciones']['retencion_iibb']) ?></td></tr><?php endif; ?>
        </tbody>
        <tfoot>
            <tr><td><strong>Total ventas</strong></td><td class="num"><strong><?= pesos($ventasR['total']) ?></strong></td></tr>
            <tr><td><strong>IVA débito</strong></td><td class="num"><strong><?= pesos($ventasR['total_iva']) ?></strong></td></tr>
        </tfoot>
    </table>
    </div>
</div>

<!-- Compras -->
<div class="venta-card" style="margin-top:1.25rem;">
    <h3>Compras (crédito fiscal)</h3>
    <div style="overflow-x:auto;">
    <table class="tabla-lista">
        <thead><tr><th>Concepto</th><th class="num">Importe</th></tr></thead>
        <tbody>
            <tr><td>Comprobantes</td><td class="num"><?= $comprasR['cantidad'] ?></td></tr>
            <?php foreach($comprasR['por_alicuota'] as $al): ?>
            <tr>
                <td style="padding-left:1.5rem;">Neto gravado <?= str_replace('.',',',(string)(float)$al['alicuota']) ?>%</td>
                <td class="num"><?= pesos((float)$al['gravado']) ?></td>
            </tr>
            <tr>
                <td style="padding-left:1.5rem;">IVA <?= str_replace('.',',',(string)(float)$al['alicuota']) ?>%</td>
                <td class="num"><?= pesos((float)$al['iva']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr><td>No gravado (Fact. B/C)</td><td class="num"><?= pesos($comprasR['no_gravado']) ?></td></tr>
            <tr><td>Exento</td><td class="num"><?= pesos($comprasR['exento']) ?></td></tr>
            <tr><td>Otros tributos</td><td class="num"><?= pesos($comprasR['otros']) ?></td></tr>
            <?php if ($comprasR['percepciones']['percepcion_iva'] ?? 0): ?><tr><td>Percepción IVA</td><td class="num"><?= pesos($comprasR['percepciones']['percepcion_iva']) ?></td></tr><?php endif; ?>
            <?php if ($comprasR['percepciones']['percepcion_ganancias'] ?? 0): ?><tr><td>Percepción Ganancias</td><td class="num"><?= pesos($comprasR['percepciones']['percepcion_ganancias']) ?></td></tr><?php endif; ?>
            <?php if ($comprasR['percepciones']['percepcion_iibb'] ?? 0): ?><tr><td>Percepción IIBB</td><td class="num"><?= pesos($comprasR['percepciones']['percepcion_iibb']) ?></td></tr><?php endif; ?>
        </tbody>
        <tfoot>
            <tr><td><strong>Total compras</strong></td><td class="num"><strong><?= pesos($comprasR['total']) ?></strong></td></tr>
            <tr><td><strong>IVA crédito</strong></td><td class="num"><strong><?= pesos($comprasR['total_iva']) ?></strong></td></tr>
        </tfoot>
    </table>
    </div>

    <?php if ($comprasPorClas): ?>
    <h4 style="margin-top:1rem;">Desglose por clasificación de compra</h4>
    <table class="tabla-lista">
        <thead><tr><th>Clasificación</th><th class="num">Comprobantes</th><th class="num">Neto grav.</th><th class="num">IVA</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach($comprasPorClas as $cl): ?>
            <tr>
                <td><?= htmlspecialchars($clasLabels[$cl['clasificacion'] ?? ''] ?? '-') ?></td>
                <td class="num"><?= $cl['cantidad'] ?></td>
                <td class="num"><?= pesos((float)$cl['gravado']) ?></td>
                <td class="num"><?= pesos((float)$cl['iva']) ?></td>
                <td class="num"><?= pesos((float)$cl['subtotal']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Retenciones importadas del período -->
<?php if ($retenciones): ?>
<div class="venta-card" style="margin-top:1.25rem;">
    <h3>Retenciones y percepciones del período</h3>
    <table class="tabla-lista">
        <thead><tr><th>Tipo</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach($retenciones as $tipo => $total): ?>
            <tr>
                <td><?= htmlspecialchars($tiposLabel[$tipo] ?? $tipo) ?></td>
                <td class="num"><?= pesos($total) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Posición IVA -->
<div class="venta-card" style="margin-top:1.25rem; max-width:560px;">
    <h3>Posición de IVA</h3>
    <table class="tabla-lista">
        <thead><tr><th>Concepto</th><th class="num">Importe</th></tr></thead>
        <tbody>
            <tr><td>IVA débito (ventas)</td><td class="num"><?= pesos($ventasR['total_iva']) ?></td></tr>
            <tr><td>IVA crédito (compras)</td><td class="num"><?= pesos($comprasR['total_iva']) ?></td></tr>
            <?php $percIVA = ($retenciones['percepcion_iva'] ?? 0) + ($retenciones['retencion_iva'] ?? 0);
            if ($percIVA): ?><tr><td>Percepciones/Ret. IVA (deducible)</td><td class="num" style="color:var(--verde)"><?= pesos($percIVA) ?></td></tr><?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td><strong><?= $posicion>=0?'Saldo a pagar':'Saldo a favor' ?></strong></td>
                <td class="num" style="color:<?= $posicion>=0?'var(--rojo)':'var(--verde)' ?>">
                    <strong><?= pesos(abs($posicion)) ?></strong>
                </td>
            </tr>
        </tfoot>
    </table>
    <p style="font-size:.8rem;color:var(--texto-suave);margin-top:.5rem;">Las NC ya están descontadas. Importes en pesos.</p>
</div>

<?php elseif ($empresaId): ?>
    <p style="margin-top:1rem;">No hay datos para esa empresa y período.</p>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>