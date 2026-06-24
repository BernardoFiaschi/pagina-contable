<?php
/**
 * includes/comprobante_form.php
 * -----------------------------
 * Formulario HTML COMPARTIDO para cargar/editar un comprobante (venta o compra).
 * Antes este bloque estaba copiado en los cuatro archivos; ahora se escribe una vez.
 * No accede a la base de datos: solo dibuja. Toda la logica vive en otro lado.
 *
 * Variables que llegan desde el archivo que incluye este form:
 */
/** @var string $accion       URL a la que postea el form (ej: '/ventas.php' o '/editar_venta.php?id=5'). */
/** @var string $etiquetaCont Texto del contacto en pantalla: 'Cliente' o 'Proveedor'. */
/** @var string $campoCont    Nombre del campo del contacto: 'cliente_id' o 'proveedor_id'. */
/** @var array  $empresas     Lista de empresas  [ ['id'=>, 'nombre'=>], ... ]. */
/** @var array  $contactos    Lista de contactos [ ['id'=>, 'nombre'=>], ... ]. */
/** @var string $textoBoton   Texto del boton de submit (ej: 'Registrar venta'). */
/** @var array  $v            Valores a mostrar en los campos. [] al crear; precargado al editar. */
/** @var array  $filas        Lineas de alicuota [ ['alicuota'=>, 'monto_gravado'=>], ... ]. */

// sel(): devuelve 'selected' si los dos valores coinciden (comparando como texto).
// Sirve para marcar la opcion elegida en los <select> al precargar en edicion.
if (!function_exists('sel')) {
    function sel(string|int|float|null $a, string|int|float|null $b): string {
        return (string)$a === (string)$b ? 'selected' : '';
    }
}

// vget(): lee un valor de $v de forma segura (si no existe, devuelve el default).
// Evita "undefined array key" cuando el form se usa en modo alta (con $v vacio).
if (!function_exists('vget')) {
    function vget(array $v, string $k, string|int|float|null $def = ''): string|int|float|null {
        return $v[$k] ?? $def;
    }
}

// Si no hay lineas (alta), arrancamos con una fila vacia al 21%.
if (empty($filas)) { $filas = [['alicuota' => '21', 'monto_gravado' => '']]; }
?>

<form method="post" action="<?= htmlspecialchars($accion) ?>" class="formulario" style="max-width:760px;">

    <!-- Empresa titular y contacto (cliente o proveedor segun la pantalla) -->
    <label>Empresa (titular)
        <select name="empresa_id">
            <option value="">Elegí...</option>
            <?php foreach ($empresas as $em): ?>
                <option value="<?= (int)$em['id'] ?>" <?= sel($em['id'], vget($v, 'empresa_id')) ?>><?= htmlspecialchars($em['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><?= htmlspecialchars($etiquetaCont) ?>
        <select name="<?= htmlspecialchars($campoCont) ?>">
            <option value="">Elegí...</option>
            <?php foreach ($contactos as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= sel($c['id'], vget($v, 'contacto_id')) ?>><?= htmlspecialchars($c['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <!-- Identificacion del comprobante -->
    <label>Tipo de comprobante
        <select name="comprobante_tipo">
            <option value="">Elegí...</option>
            <option value="Factura"          <?= sel('Factura', vget($v, 'comprobante_tipo')) ?>>Factura</option>
            <option value="Nota de Crédito"  <?= sel('Nota de Crédito', vget($v, 'comprobante_tipo')) ?>>Nota de Crédito</option>
            <option value="Nota de Débito"   <?= sel('Nota de Débito', vget($v, 'comprobante_tipo')) ?>>Nota de Débito</option>
        </select>
    </label>
    <label>Letra
        <select name="comprobante_letra">
            <option value="">-</option>
            <option value="A" <?= sel('A', vget($v, 'comprobante_letra')) ?>>A</option>
            <option value="B" <?= sel('B', vget($v, 'comprobante_letra')) ?>>B</option>
            <option value="C" <?= sel('C', vget($v, 'comprobante_letra')) ?>>C</option>
        </select>
    </label>
    <label>Punto de venta <input type="number" name="punto_venta" min="1" value="<?= htmlspecialchars((string)vget($v, 'punto_venta')) ?>"></label>
    <label>Número <input type="number" name="numero" min="1" value="<?= htmlspecialchars((string)vget($v, 'numero')) ?>"></label>
    <label>Fecha <input type="date" name="fecha" value="<?= htmlspecialchars((string)vget($v, 'fecha')) ?>"></label>
    <label>Actividad <input type="text" name="actividad" value="<?= htmlspecialchars((string)vget($v, 'actividad')) ?>"></label>

    <!-- Detalle de IVA: una fila por alicuota. El IVA se calcula solo (JS) y se recalcula en el servidor. -->
    <h3>Detalle de IVA</h3>
    <table id="detalle">
        <thead><tr><th>Alícuota</th><th>Gravado</th><th>IVA</th><th></th></tr></thead>
        <tbody id="filas">
            <?php foreach ($filas as $f): ?>
            <tr>
                <td>
                    <select name="alicuota[]" class="alic">
                        <option value="21"   <?= sel('21', (float)$f['alicuota']) ?>>21%</option>
                        <option value="10.5" <?= sel('10.5', (float)$f['alicuota']) ?>>10,5%</option>
                        <option value="27"   <?= sel('27', (float)$f['alicuota']) ?>>27%</option>
                        <option value="0"    <?= sel('0', (float)$f['alicuota']) ?>>0%</option>
                    </select>
                </td>
                <td><input type="number" step="0.01" name="monto_gravado[]" class="grav" value="<?= htmlspecialchars((string)$f['monto_gravado']) ?>"></td>
                <td><input type="number" step="0.01" name="iva[]" class="iva" readonly></td>
                <td><button type="button" class="btn quitar">x</button></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <button type="button" id="agregar" class="btn">+ Agregar alícuota</button>

    <!-- Otros importes de la cabecera -->
    <label style="margin-top:1rem;">Monto no gravado <input type="number" step="0.01" name="monto_no_gravado" id="nograv" value="<?= htmlspecialchars((string)vget($v, 'monto_no_gravado', 0)) ?>"></label>
    <label>Monto exento <input type="number" step="0.01" name="monto_exento" id="exento" value="<?= htmlspecialchars((string)vget($v, 'monto_exento', 0)) ?>"></label>
    <label>Otros tributos (percepciones, etc.) <input type="number" step="0.01" name="otros_tributos" id="otros" value="<?= htmlspecialchars((string)vget($v, 'otros_tributos', 0)) ?>"></label>
    <label>CAE <input type="text" name="cae" value="<?= htmlspecialchars((string)vget($v, 'cae')) ?>"></label>
    <label>Moneda
        <select name="moneda">
            <option value="ARS" <?= sel('ARS', vget($v, 'moneda', 'ARS')) ?>>ARS (pesos)</option>
            <option value="USD" <?= sel('USD', vget($v, 'moneda')) ?>>USD</option>
        </select>
    </label>
    <label>Tipo de cambio <input type="number" step="0.0001" name="tipo_cambio" value="<?= htmlspecialchars((string)vget($v, 'tipo_cambio', 1)) ?>"></label>

    <p><strong>Total: $<span id="total">0,00</span></strong></p>
    <button type="submit" class="btn btn-guardar"><?= htmlspecialchars($textoBoton) ?></button>
    <a href="<?= htmlspecialchars(strtok($accion, '?')) ?>" class="btn">Cancelar</a>
</form>

<!-- Calculo en vivo del IVA y el total (archivo compartido). -->
<script src="/js/comprobante.js"></script>