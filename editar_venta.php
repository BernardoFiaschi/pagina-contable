<?php
/*
 * editar_venta.php
 * ----------------
 * Edición de una venta existente. Reusa la validación (leerComprobante) y el
 * formulario (comprobante_form.php) compartidos. Lo propio de "editar" es:
 *   - en GET: cargar la venta y sus líneas para precargar el formulario;
 *   - en POST: actualizar la cabecera y REEMPLAZAR las líneas (borrar e insertar),
 *     todo dentro de una transacción.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/comprobante_funciones.php';

// Sin un id válido no hay nada para editar.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: /ventas.php'); exit; }

$errores = [];

// Datos para los <select> del formulario.
$empresas = $pdo->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll();
$clientes = $pdo->query("SELECT id, nombre FROM contactos ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Misma validación que el alta.
    $r = leerComprobante($_POST, 'cliente_id');
    $errores = $r['errores'];

    if (count($errores) === 0) {
        try {
            $val = $r['valores'];
            $pdo->beginTransaction();
            // 1) Actualizar la cabecera.
            $pdo->prepare("UPDATE ventas SET
                empresa_id=:emp, cliente_id=:cli, comprobante_tipo=:ct, comprobante_letra=:cl,
                punto_venta=:pv, numero=:num, fecha=:f, actividad=:act,
                monto_no_gravado=:ng, monto_exento=:ex, otros_tributos=:ot, cae=:cae, moneda=:mon, tipo_cambio=:tc
                WHERE id=:id")
                ->execute([
                    ':emp'=>$val['empresa_id'], ':cli'=>$val['contacto_id'], ':ct'=>$val['comprobante_tipo'],
                    ':cl'=>$val['comprobante_letra'], ':pv'=>$val['punto_venta'], ':num'=>$val['numero'],
                    ':f'=>$val['fecha'], ':act'=>$val['actividad'], ':ng'=>$val['monto_no_gravado'],
                    ':ex'=>$val['monto_exento'], ':ot'=>$val['otros_tributos'], ':cae'=>$val['cae'],
                    ':mon'=>$val['moneda'], ':tc'=>$val['tipo_cambio'], ':id'=>$id,
                ]);
            // 2) Reemplazar las líneas: borrar las viejas e insertar las nuevas.
            $pdo->prepare("DELETE FROM venta_alicuotas WHERE venta_id = :id")->execute([':id'=>$id]);
            $stmtL = $pdo->prepare("INSERT INTO venta_alicuotas (venta_id, alicuota, monto_gravado, iva) VALUES (:v,:a,:g,:iva)");
            foreach ($r['lineas'] as $l) {
                $stmtL->execute([':v'=>$id, ':a'=>$l['alicuota'], ':g'=>$l['monto_gravado'], ':iva'=>$l['iva']]);
            }
            $pdo->commit();
            header('Location: /ventas.php?msg=' . urlencode('Venta actualizada.')); exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errores[] = 'Error al actualizar: ' . $e->getMessage();
        }
    }
    // Si hubo errores, repoblamos el form con lo que el usuario mandó (no se pierde lo cargado).
    $v = [
        'empresa_id'=>$_POST['empresa_id'] ?? '', 'contacto_id'=>$_POST['cliente_id'] ?? '',
        'comprobante_tipo'=>$_POST['comprobante_tipo'] ?? '', 'comprobante_letra'=>$_POST['comprobante_letra'] ?? '',
        'punto_venta'=>$_POST['punto_venta'] ?? '', 'numero'=>$_POST['numero'] ?? '',
        'fecha'=>$_POST['fecha'] ?? '', 'actividad'=>$_POST['actividad'] ?? '',
        'monto_no_gravado'=>$_POST['monto_no_gravado'] ?? 0, 'monto_exento'=>$_POST['monto_exento'] ?? 0,
        'otros_tributos'=>$_POST['otros_tributos'] ?? 0, 'cae'=>$_POST['cae'] ?? '',
        'moneda'=>$_POST['moneda'] ?? 'ARS', 'tipo_cambio'=>$_POST['tipo_cambio'] ?? 1,
    ];
    $filas = [];
    foreach (($_POST['alicuota'] ?? []) as $i => $a) {
        $filas[] = ['alicuota'=>$a, 'monto_gravado'=>$_POST['monto_gravado'][$i] ?? ''];
    }
} else {
    // GET: cargar la venta y sus líneas desde la base para precargar el form.
    $st = $pdo->prepare("SELECT * FROM ventas WHERE id = :id");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) { header('Location: /ventas.php'); exit; }

    // Pasamos los nombres de columna a las claves que espera el form compartido.
    $v = $row;
    $v['contacto_id'] = $row['cliente_id'];

    $stL = $pdo->prepare("SELECT alicuota, monto_gravado FROM venta_alicuotas WHERE venta_id = :id ORDER BY alicuota DESC");
    $stL->execute([':id' => $id]);
    $filas = $stL->fetchAll();
}

// Variables para el formulario compartido.
$accion       = '/editar_venta.php?id=' . $id;
$etiquetaCont = 'Cliente';
$campoCont    = 'cliente_id';
$contactos    = $clientes;
$textoBoton   = 'Guardar cambios';

$titulo = 'Editar venta';
require __DIR__ . '/includes/header.php';
?>

<h2>Editar venta #<?= (int)$id ?></h2>
<?php if ($errores): ?><ul class="errores"><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul><?php endif; ?>

<?php require __DIR__ . '/includes/comprobante_form.php'; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>