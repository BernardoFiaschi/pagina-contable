<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id) {
    // ON DELETE CASCADE borra las compra_alicuotas asociadas automáticamente.
    $pdo->prepare("DELETE FROM compras WHERE id = :id")->execute([':id' => $id]);
    header('Location: /compras.php?msg=' . urlencode('Compra eliminada.'));
    exit;
}
header('Location: /compras.php');
exit;