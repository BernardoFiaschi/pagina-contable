<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id) {
    try {
        $pdo->prepare("DELETE FROM contactos WHERE id = :id")->execute([':id' => $id]);
        header('Location: /contactos.php?msg=' . urlencode('Contacto eliminado.'));
        exit;
    } catch (PDOException $e) {
        // Si el contacto tiene ventas/compras, la clave foránea impide borrarlo.
        header('Location: /contactos.php?error=' . urlencode('No se puede eliminar: el contacto tiene movimientos asociados.'));
        exit;
    }
}
header('Location: /contactos.php');
exit;