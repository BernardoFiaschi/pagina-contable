<?php
/*
 * includes/comprobante_funciones.php
 * -----------------------------------
 * Lógica COMPARTIDA entre ventas y compras (alta y edición).
 * Antes este código estaba copiado en ventas.php, compras.php, editar_venta.php
 * y editar_compra.php. Al centralizarlo, una regla (por ejemplo, cómo se calcula
 * el IVA o qué alícuotas son válidas) se cambia en UN solo lugar.
 *
 * Contiene:
 *   - alicuotasValidas(): la lista de alícuotas permitidas.
 *   - tiposComprobante(): los tipos de comprobante permitidos en la carga manual.
 *   - leerComprobante(): toma los datos crudos del formulario ($_POST), los valida
 *     y devuelve [valores limpios, líneas de alícuota, errores].
 */

declare(strict_types=1);

/**
 * Alícuotas de IVA que aceptamos en la carga manual.
 * Son float a propósito (10.5 no es entero) y la comparación se hace con
 * in_array(..., true) para exigir que el tipo coincida (evita que "21" string pase como 21.0).
 */
function alicuotasValidas(): array {
    return [21.0, 27.0, 10.5, 0.0];
}

/**
 * Tipos de comprobante que se pueden elegir al cargar a mano.
 * (La importación reconoce muchos más, vía config/comprobantes.php; acá nos
 * quedamos con los tres que definen la lógica de IVA: Factura, NC y ND.)
 */
function tiposComprobante(): array {
    return ['Factura', 'Nota de Crédito', 'Nota de Débito', 'Certificado de Retención'];
}

/**
 * Lee y valida los datos de un comprobante enviados por POST.
 *
 * @param array  $post           Normalmente $_POST.
 * @param string $campoContacto  'cliente_id' en ventas, 'proveedor_id' en compras.
 *                               Permite reutilizar la misma función para ambos.
 *
 * @return array Con tres claves:
 *   'valores' => datos de la cabecera ya tipados/limpios, listos para guardar.
 *   'lineas'  => líneas de alícuota válidas, cada una con su IVA recalculado en el servidor.
 *   'errores' => lista de mensajes de error (vacía si todo está bien).
 */
function leerComprobante(array $post, string $campoContacto): array {
    $errores = [];

    // --- Cabecera ---
    // filter_var con FILTER_VALIDATE_INT devuelve false si no es un entero válido.
    $empresaId  = filter_var($post['empresa_id'] ?? null, FILTER_VALIDATE_INT);
    $contactoId = filter_var($post[$campoContacto] ?? null, FILTER_VALIDATE_INT);
    $compTipo   = trim($post['comprobante_tipo'] ?? '');
    $compLetra  = trim($post['comprobante_letra'] ?? '');
    $puntoVenta = trim($post['punto_venta'] ?? '');
    $numero     = trim($post['numero'] ?? '');
    $fecha      = trim($post['fecha'] ?? '');
    $actividad  = trim($post['actividad'] ?? '');
    $cae        = trim($post['cae'] ?? '');
    $moneda     = trim($post['moneda'] ?? 'ARS');

    // Montos: si no son numéricos, quedan en 0 (o 1 para el tipo de cambio).
    $noGravado  = is_numeric($post['monto_no_gravado'] ?? '') ? (float)$post['monto_no_gravado'] : 0.0;
    $exento     = is_numeric($post['monto_exento'] ?? '')     ? (float)$post['monto_exento']     : 0.0;
    $otros      = is_numeric($post['otros_tributos'] ?? '')   ? (float)$post['otros_tributos']   : 0.0;
    $tipoCambio = is_numeric($post['tipo_cambio'] ?? '')      ? (float)$post['tipo_cambio']      : 1.0;

    // --- Líneas de alícuota ---
    // Vienen como arrays paralelos: alicuota[] y monto_gravado[] (una posición por fila).
    $lineas = [];
    $alicuotas = $post['alicuota'] ?? [];
    $gravados  = $post['monto_gravado'] ?? [];
    foreach ($alicuotas as $i => $alic) {
        $g = trim((string)($gravados[$i] ?? ''));
        if ($g === '' || (float)$g <= 0) {
            continue; // fila vacía o sin importe: la ignoramos
        }
        $a = (float)$alic;
        if (!in_array($a, alicuotasValidas(), true)) {
            $errores[] = 'Hay una alícuota inválida en el detalle.';
            continue;
        }
        $g = (float)$g;
        // El IVA SIEMPRE se recalcula en el servidor: nunca confiamos en el del navegador.
        $lineas[] = ['alicuota' => $a, 'monto_gravado' => $g, 'iva' => round($g * $a / 100, 2)];
    }

    // --- Validaciones ---
    if (!$empresaId)  { $errores[] = 'Elegí la empresa (titular).'; }
    if (!$contactoId) { $errores[] = ($campoContacto === 'cliente_id') ? 'Elegí un cliente.' : 'Elegí un proveedor.'; }
    if (!in_array($compTipo, tiposComprobante(), true)) { $errores[] = 'Elegí el tipo de comprobante.'; }
    if ($fecha === '') { $errores[] = 'La fecha es obligatoria.'; }
    // Tiene que haber AL MENOS un importe: alguna línea, o monto no gravado, o exento.
    if (count($lineas) === 0 && $noGravado <= 0 && $exento <= 0) {
        $errores[] = 'Cargá al menos un importe (gravado, no gravado o exento).';
    }

    // Cabecera limpia y tipada, lista para el INSERT/UPDATE.
    // Los campos opcionales quedan en null cuando están vacíos (así la base guarda NULL).
    $valores = [
        'empresa_id'        => $empresaId ?: null,
        'contacto_id'       => $contactoId ?: null,
        'comprobante_tipo'  => $compTipo,
        'comprobante_letra' => $compLetra !== '' ? $compLetra : null,
        'punto_venta'       => $puntoVenta !== '' ? (int)$puntoVenta : null,
        'numero'            => $numero !== '' ? (int)$numero : null,
        'fecha'             => $fecha,
        'actividad'         => $actividad !== '' ? $actividad : null,
        'monto_no_gravado'  => $noGravado,
        'monto_exento'      => $exento,
        'otros_tributos'    => $otros,
        'cae'               => $cae !== '' ? $cae : null,
        'moneda'            => $moneda !== '' ? $moneda : 'ARS',
        'tipo_cambio'       => $tipoCambio,
    ];

    return ['valores' => $valores, 'lineas' => $lineas, 'errores' => $errores];
}