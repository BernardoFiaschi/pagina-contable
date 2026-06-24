<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$errores = [];
$valores = ['nombre' => '', 'documento' => '', 'tipo_contribuyente' => '', 'actividad' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valores['nombre']             = trim($_POST['nombre'] ?? '');
    $valores['documento']          = trim($_POST['documento'] ?? '');
    $valores['tipo_contribuyente'] = trim($_POST['tipo_contribuyente'] ?? '');
    $valores['actividad']          = trim($_POST['actividad'] ?? '');

    if ($valores['nombre'] === '') { $errores[] = 'La razón social es obligatoria.'; }
    if (!in_array($valores['tipo_contribuyente'], ['Responsable Inscripto', 'Monotributista', 'Exento'], true)) {
        $errores[] = 'Elegí un tipo de contribuyente.';
    }

    if (count($errores) === 0) {
        try {
            $pdo->prepare("INSERT INTO empresas (nombre, documento, tipo_contribuyente, actividad)
                           VALUES (:n, :d, :t, :a)")->execute([
                ':n' => $valores['nombre'],
                ':d' => $valores['documento'] !== '' ? $valores['documento'] : null,
                ':t' => $valores['tipo_contribuyente'],
                ':a' => $valores['actividad'] !== '' ? $valores['actividad'] : null,
            ]);
            header('Location: /empresas.php?msg=' . urlencode('Empresa guardada.'));
            exit;
        } catch (PDOException $e) {
            $errores[] = $e->getCode() === '23000'
                ? 'Ya existe una empresa con ese documento.'
                : 'Error al guardar: ' . $e->getMessage();
        }
    }
}

$empresas = $pdo->query("SELECT * FROM empresas ORDER BY nombre")->fetchAll();
$mensaje = $_GET['msg'] ?? '';

$titulo = 'Empresas';
require __DIR__ . '/includes/header.php';
?>

<h2>Empresas (titulares)</h2>
<p>Empresas de las que llevamos la contabilidad. Cada venta y compra pertenece a una empresa.</p>

<?php if ($mensaje): ?><p class="ok"><?= htmlspecialchars($mensaje) ?></p><?php endif; ?>
<?php if ($errores): ?>
    <ul class="errores">
        <?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" class="formulario">
    <label>Razón social
        <input type="text" name="nombre" value="<?= htmlspecialchars($valores['nombre']) ?>">
    </label>
    <label>CUIT
        <input type="text" name="documento" value="<?= htmlspecialchars($valores['documento']) ?>">
    </label>
    <label>Tipo de contribuyente
        <select name="tipo_contribuyente">
            <option value="">Elegí...</option>
            <option value="Responsable Inscripto" <?= $valores['tipo_contribuyente'] === 'Responsable Inscripto' ? 'selected' : '' ?>>Responsable Inscripto</option>
            <option value="Monotributista" <?= $valores['tipo_contribuyente'] === 'Monotributista' ? 'selected' : '' ?>>Monotributista</option>
            <option value="Exento" <?= $valores['tipo_contribuyente'] === 'Exento' ? 'selected' : '' ?>>Exento</option>
        </select>
    </label>
    <label>Actividad
        <input type="text" name="actividad" value="<?= htmlspecialchars($valores['actividad']) ?>">
    </label>
    <button type="submit" class="btn btn-guardar">Guardar empresa</button>
</form>

<?php if (!$empresas): ?>
    <p>Todavía no hay empresas cargadas.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Razón social</th><th>CUIT</th><th>Tipo</th><th>Actividad</th></tr></thead>
        <tbody>
            <?php foreach ($empresas as $em): ?>
                <tr>
                    <td><?= htmlspecialchars($em['nombre']) ?></td>
                    <td><?= htmlspecialchars($em['documento'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($em['tipo_contribuyente']) ?></td>
                    <td><?= htmlspecialchars($em['actividad'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>