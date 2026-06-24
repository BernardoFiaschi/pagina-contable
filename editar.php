<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

// 1) Tomamos el id de la URL y validamos que sea entero.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: /contactos.php'); exit; }

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2) Envío del formulario: validar y actualizar.
    $nombre    = trim($_POST['nombre'] ?? '');
    $documento = trim($_POST['documento'] ?? '');
    $tipo      = trim($_POST['tipo_contribuyente'] ?? '');
    $actividad = trim($_POST['actividad'] ?? '');

    if ($nombre === '') { $errores[] = 'El nombre es obligatorio.'; }
    if (!in_array($tipo, ['Responsable Inscripto', 'Monotributista', 'Exento', 'Consumidor Final'], true)) {
        $errores[] = 'Elegí un tipo de contribuyente.';
    }

    if (count($errores) === 0) {
        try {
            $sql = "UPDATE contactos
                       SET nombre = :nombre, documento = :documento,
                           tipo_contribuyente = :tipo, actividad = :actividad
                     WHERE id = :id";
            $pdo->prepare($sql)->execute([
                ':nombre'    => $nombre,
                ':documento' => $documento !== '' ? $documento : null,
                ':tipo'      => $tipo,
                ':actividad' => $actividad !== '' ? $actividad : null,
                ':id'        => $id,
            ]);
            header('Location: /contactos.php?msg=' . urlencode('Contacto actualizado.'));
            exit;
        } catch (PDOException $e) {
            $errores[] = $e->getCode() === '23000'
                ? 'Ya existe otro contacto con ese documento.'
                : 'Error al actualizar: ' . $e->getMessage();
        }
    }
    // Si hubo error, conservamos lo tipeado para repintar el formulario.
    $contacto = ['nombre'=>$nombre, 'documento'=>$documento, 'tipo_contribuyente'=>$tipo, 'actividad'=>$actividad];
} else {
    // 3) Primera carga (GET): traemos el contacto de la base para precargar.
    $st = $pdo->prepare("SELECT * FROM contactos WHERE id = :id");
    $st->execute([':id' => $id]);
    $contacto = $st->fetch();
    if (!$contacto) { header('Location: /contactos.php'); exit; }
}

$titulo = 'Editar contacto';
require __DIR__ . '/includes/header.php';
?>

<h2>Editar contacto</h2>

<?php if ($errores): ?>
    <ul class="errores">
        <?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" class="formulario">
    <label>Nombre / Razón social
        <input type="text" name="nombre" value="<?= htmlspecialchars($contacto['nombre']) ?>">
    </label>
    <label>Documento (CUIT/DNI)
        <input type="text" name="documento" value="<?= htmlspecialchars($contacto['documento'] ?? '') ?>">
    </label>
    <label>Tipo de contribuyente
        <select name="tipo_contribuyente">
            <option value="">Elegí...</option>
            <option value="Responsable Inscripto" <?= $contacto['tipo_contribuyente'] === 'Responsable Inscripto' ? 'selected' : '' ?>>Responsable Inscripto</option>
            <option value="Monotributista" <?= $contacto['tipo_contribuyente'] === 'Monotributista' ? 'selected' : '' ?>>Monotributista</option>
            <option value="Exento" <?= $contacto['tipo_contribuyente'] === 'Exento' ? 'selected' : '' ?>>Exento</option>
            <option value="Consumidor Final" <?= $contacto['tipo_contribuyente'] === 'Consumidor Final' ? 'selected' : '' ?>>Consumidor Final</option>
        </select>
    </label>
    <label>Actividad
        <input type="text" name="actividad" value="<?= htmlspecialchars($contacto['actividad'] ?? '') ?>">
    </label>
    <button type="submit" class="btn btn-guardar">Guardar cambios</button>
    <a href="/contactos.php" class="btn">Cancelar</a>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>