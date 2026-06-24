<?php
/*
 * eliminar_lote.php
 * -----------------
 * Borra varias ventas o compras de una. Recibe por POST:
 *   tipo  = 'ventas' | 'compras'
 *   ids[] = lista de ids a borrar
 * El ON DELETE CASCADE elimina las líneas de alícuota asociadas.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$tipo = $_POST['tipo'] ?? '';
$ids  = $_POST['ids'] ?? [];

// Whitelist del nombre de tabla: NUNCA usar el valor crudo del POST en el SQL.
$tabla = $tipo === 'compras' ? 'compras' : ($tipo === 'ventas' ? 'ventas' : null);
$destino = '/' . ($tabla ?? 'ventas') . '.php';

if ($tabla && is_array($ids) && count($ids) > 0) {
    // Nos quedamos solo con enteros positivos.
    $ids = array_values(array_filter(array_map('intval', $ids), fn($n) => $n > 0));
    if ($ids) {
        // Placeholders ?,?,? — uno por id — para un IN seguro con parámetros.
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM $tabla WHERE id IN ($ph)")->execute($ids);
        $n = count($ids);
        header('Location: ' . $destino . '?msg=' . urlencode("$n comprobante(s) eliminado(s).")); exit;
    }
}
header('Location: ' . $destino); exit;