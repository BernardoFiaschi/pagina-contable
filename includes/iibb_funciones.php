<?php
/*
 * includes/iibb_funciones.php
 * ---------------------------
 * Funciones para resolver la alícuota de Ingresos Brutos (ARBA) de una actividad.
 * La tabla oficial (config/iibb_alicuotas.php) tiene códigos generales de 4 dígitos
 * y algunos especiales de 6. Una actividad de AFIP es de 6 dígitos, así que:
 *   1) probamos match exacto de 6 dígitos (código especial);
 *   2) si no, probamos los primeros 4 dígitos (código general).
 */
declare(strict_types=1);

/** Devuelve la alícuota IIBB (float, en %) para un código de actividad, o null si no hay. */
function alicuotaIIBB(?string $codigo): ?float {
    static $tabla = null;
    if ($tabla === null) { $tabla = require __DIR__ . '/../config/iibb_alicuotas.php'; }
    if (!$codigo) { return null; }
    $codigo = preg_replace('/\D/', '', $codigo);          // dejar solo dígitos
    if (isset($tabla[$codigo]))               { return (float)$tabla[$codigo]; }      // especial 6 díg
    $g = substr($codigo, 0, 4);
    if ($g !== '' && isset($tabla[$g]))       { return (float)$tabla[$g]; }           // general 4 díg
    return null;
}