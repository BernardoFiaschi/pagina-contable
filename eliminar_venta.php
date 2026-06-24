<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id) {
    // ON DELETE CASCADE borra las venta_alicuotas asociadas automáticamente.
    $pdo->prepare("DELETE FROM ventas WHERE id = :id")->execute([':id' => $id]);
    header('Location: /ventas.php?msg=' . urlencode('Venta eliminada.'));
    exit;
}
header('Location: /ventas.php');
exit;