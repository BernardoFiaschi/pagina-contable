<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/comprobante_funciones.php';
requerirLogin();
$uid = usuarioId();
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comprobante_tipo'])) {
    $r = leerComprobante($_POST, 'cliente_id');
    $errores = $r['errores'];
    if (!$errores) {
        try {
            $v = $r['valores']; $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO ventas (empresa_id,cliente_id,comprobante_tipo,comprobante_letra,punto_venta,numero,fecha,actividad,monto_no_gravado,monto_exento,otros_tributos,cae,moneda,tipo_cambio) VALUES (:emp,:cli,:ct,:cl,:pv,:num,:f,:act,:ng,:ex,:ot,:cae,:mon,:tc)")
                ->execute([':emp'=>$v['empresa_id'],':cli'=>$v['contacto_id'],':ct'=>$v['comprobante_tipo'],':cl'=>$v['comprobante_letra'],':pv'=>$v['punto_venta'],':num'=>$v['numero'],':f'=>$v['fecha'],':act'=>$v['actividad'],':ng'=>$v['monto_no_gravado'],':ex'=>$v['monto_exento'],':ot'=>$v['otros_tributos'],':cae'=>$v['cae'],':mon'=>$v['moneda'],':tc'=>$v['tipo_cambio']]);
            $vid = (int)$pdo->lastInsertId();
            $stL = $pdo->prepare("INSERT INTO venta_alicuotas (venta_id,alicuota,monto_gravado,iva) VALUES (:v,:a,:g,:iva)");
            foreach ($r['lineas'] as $l) $stL->execute([':v'=>$vid,':a'=>$l['alicuota'],':g'=>$l['monto_gravado'],':iva'=>$l['iva']]);
            $pdo->commit();
            header('Location: /ventas.php?msg='.urlencode('Venta registrada.')); exit;
        } catch (PDOException $e) { $pdo->rollBack(); $errores[]='Error: '.$e->getMessage(); }
    }
}

$empresas = $pdo->prepare("SELECT id,nombre FROM empresas WHERE usuario_id=:uid ORDER BY nombre");
$empresas->execute([':uid'=>$uid]); $empresas = $empresas->fetchAll();
$clientes = $pdo->query("SELECT id,nombre FROM contactos ORDER BY nombre")->fetchAll();

$fEmpresa = filter_input(INPUT_GET,'f_empresa',FILTER_VALIDATE_INT);
$fCliente = filter_input(INPUT_GET,'f_cliente',FILTER_VALIDATE_INT);
$fPeriodo = trim($_GET['f_periodo'] ?? '');
$fTipo    = trim($_GET['f_tipo']    ?? '');

$cond=[]; $par=[];
if ($fEmpresa)        { $cond[]='v.empresa_id=:emp';        $par[':emp']=$fEmpresa; }
if ($fCliente)        { $cond[]='v.cliente_id=:cli';        $par[':cli']=$fCliente; }
if ($fPeriodo!=='')   { $cond[]='v.fecha LIKE :per';        $par[':per']=$fPeriodo.'%'; }
if ($fTipo!=='')      { $cond[]='v.comprobante_tipo=:tipo'; $par[':tipo']=$fTipo; }
$where = $cond ? 'WHERE '.implode(' AND ',$cond) : '';

$stV = $pdo->prepare("
    SELECT v.id,v.fecha,v.comprobante_tipo,v.comprobante_letra,v.punto_venta,v.numero,v.moneda,
           e.nombre AS empresa, c.nombre AS cliente,
           v.monto_no_gravado, v.monto_exento, v.otros_tributos,
           COALESCE(SUM(a.monto_gravado),0) AS neto_gravado,
           COALESCE(SUM(a.iva),0)           AS total_iva,
           v.monto_no_gravado + v.monto_exento + v.otros_tributos + COALESCE(SUM(a.monto_gravado+a.iva),0) AS total
    FROM ventas v
    JOIN empresas e  ON e.id=v.empresa_id
    JOIN contactos c ON c.id=v.cliente_id
    LEFT JOIN venta_alicuotas a ON a.venta_id=v.id
    $where
    GROUP BY v.id ORDER BY v.fecha DESC, v.id DESC");
$stV->execute($par); $ventas = $stV->fetchAll();

$mensaje = $_GET['msg'] ?? '';
$accion='/ventas.php'; $etiquetaCont='Cliente'; $campoCont='cliente_id';
$contactos=$clientes; $textoBoton='Registrar venta'; $v=[]; $filas=[];
$titulo='Ventas';
require __DIR__ . '/includes/header.php';

function pesos(float $n): string { return '$'.number_format($n,2,',','.'); }
?>
<h2>Registrar venta</h2>
<?php if($mensaje): ?><p class="ok"><?= htmlspecialchars($mensaje) ?></p><?php endif; ?>
<?php if($errores): ?><ul class="errores"><?php foreach($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul><?php endif; ?>

<?php if(!$empresas): ?>
    <p>Primero cargá una empresa en <a href="/empresas.php">Empresas</a>.</p>
<?php elseif(!$clientes): ?>
    <p>Primero cargá un contacto en <a href="/contactos.php">Contactos</a>.</p>
<?php else: ?>
    <details class="bloque-alta">
        <summary>+ Registrar una venta</summary>
        <?php require __DIR__ . '/includes/comprobante_form.php'; ?>
    </details>
<?php endif; ?>

<h2 style="margin-top:2rem;">Ventas registradas</h2>

<form method="get" class="filtros">
    <label>Empresa<select name="f_empresa"><option value="">Todas</option><?php foreach($empresas as $em): ?><option value="<?= $em['id'] ?>" <?= $fEmpresa===(int)$em['id']?'selected':'' ?>><?= htmlspecialchars($em['nombre']) ?></option><?php endforeach; ?></select></label>
    <label>Cliente<select name="f_cliente"><option value="">Todos</option><?php foreach($clientes as $cl): ?><option value="<?= $cl['id'] ?>" <?= $fCliente===(int)$cl['id']?'selected':'' ?>><?= htmlspecialchars($cl['nombre']) ?></option><?php endforeach; ?></select></label>
    <label>Tipo<select name="f_tipo"><option value="">Todos</option><?php foreach(['Factura','Nota de Crédito','Nota de Débito'] as $t): ?><option value="<?= $t ?>" <?= $fTipo===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Período<input type="month" name="f_periodo" value="<?= htmlspecialchars($fPeriodo) ?>"></label>
    <button type="submit" class="btn btn-guardar">Filtrar</button>
    <a href="/ventas.php" class="btn">Limpiar</a>
</form>

<?php if(!$ventas): ?>
    <p>No hay ventas para ese filtro.</p>
<?php else: ?>
<form method="post" action="/eliminar_lote.php" onsubmit="return confirm('¿Eliminar las ventas seleccionadas?')">
    <input type="hidden" name="tipo" value="ventas">
    <div class="tabla-acciones">
        <button type="submit" class="btn btn-borrar">Eliminar seleccionadas</button>
        <span class="conteo"><?= count($ventas) ?> comprobante<?= count($ventas)===1?'':'s' ?></span>
    </div>
    <div style="overflow-x:auto;">
    <table class="tabla-lista">
        <thead>
            <tr>
                <th><input type="checkbox" id="check-todos" title="Todos"></th>
                <th>Fecha</th>
                <th>Comprobante</th>
                <th>Cliente</th>
                <th class="num">Neto Grav.</th>
                <th class="num">IVA</th>
                <th class="num">No Grav.</th>
                <th class="num">Exento</th>
                <th class="num">Otros</th>
                <th class="num">Total</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($ventas as $vta): ?>
            <tr>
                <td><input type="checkbox" name="ids[]" value="<?= $vta['id'] ?>" class="check-fila"></td>
                <td><?= htmlspecialchars($vta['fecha']) ?></td>
                <td>
                    <strong><?= htmlspecialchars($vta['comprobante_tipo']) ?> <?= htmlspecialchars($vta['comprobante_letra'] ?? '') ?></strong>
                    <span class="muted"><?= $vta['punto_venta'] !== null ? str_pad((string)$vta['punto_venta'],5,'0',STR_PAD_LEFT).'-'.str_pad((string)$vta['numero'],8,'0',STR_PAD_LEFT) : '-' ?></span>
                </td>
                <td><?= htmlspecialchars($vta['cliente']) ?></td>
                <td class="num"><?= pesos((float)$vta['neto_gravado']) ?></td>
                <td class="num"><?= pesos((float)$vta['total_iva']) ?></td>
                <td class="num"><?= pesos((float)$vta['monto_no_gravado']) ?></td>
                <td class="num"><?= pesos((float)$vta['monto_exento']) ?></td>
                <td class="num"><?= pesos((float)$vta['otros_tributos']) ?></td>
                <td class="num"><strong><?= pesos((float)$vta['total']) ?></strong></td>
                <td class="acciones-fila">
                    <a href="/editar_venta.php?id=<?= $vta['id'] ?>" title="Editar">✏️</a>
                    <a href="/eliminar_venta.php?id=<?= $vta['id'] ?>" onclick="return confirm('¿Eliminar?')" title="Eliminar">🗑️</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</form>
<?php endif; ?>

<script>
document.addEventListener('change',function(ev){
    if(ev.target.id==='check-todos') document.querySelectorAll('.check-fila').forEach(function(c){c.checked=ev.target.checked;});
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>