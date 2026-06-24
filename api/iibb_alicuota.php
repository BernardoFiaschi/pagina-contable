<?php
/*
 * api/iibb_alicuota.php
 * ---------------------
 * Mini-endpoint JSON: dado un código de actividad, devuelve la alícuota IIBB.
 * Llamado desde el JS del buscador de actividades en empresas.php.
 */
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/iibb_funciones.php';
$cod  = trim($_GET['cod'] ?? '');
$alic = $cod !== '' ? alicuotaIIBB($cod) : null;
echo json_encode(['alic' => $alic]);