<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';   // trae $pdo

$errores = [];
// Guardamos lo que el usuario escribió, para repintarlo si hay error.
$valores = ['nombre' => '', 'documento' => '', 'tipo_contribuyente' => '', 'actividad' => ''];

// ¿El formulario se envió? Solo procesamos si vino por POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valores['nombre']             = trim($_POST['nombre'] ?? '');
    $valores['documento']          = trim($_POST['documento'] ?? '');
    $valores['tipo_contribuyente'] = trim($_POST['tipo_contribuyente'] ?? '');
    $valores['actividad']          = trim($_POST['actividad'] ?? '');

    // --- Validación ---
    if ($valores['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    $tiposValidos = ['Responsable Inscripto', 'Monotributista', 'Exento', 'Consumidor Final'];
    // in_array con true = comparación estricta (mismo valor Y mismo tipo).
    if (!in_array($valores['tipo_contribuyente'], $tiposValidos, true)) {
        $errores[] = 'Elegí un tipo de contribuyente.';
    }

    // --- Guardar (solo si no hubo errores) ---
    if (count($errores) === 0) {
        try {
            $sql = "INSERT INTO contactos (nombre, documento, tipo_contribuyente, actividad)
                    VALUES (:nombre, :documento, :tipo, :actividad)";
            // Consulta preparada: los datos van aparte del SQL. Evita inyección SQL.
            $pdo->prepare($sql)->execute([
                ':nombre'    => $valores['nombre'],
                // Documento vacío lo guardamos como NULL, no como "". Así varios
                // contactos sin documento no chocan con la regla UNIQUE.
                ':documento' => $valores['documento'] !== '' ? $valores['documento'] : null,
                ':tipo'      => $valores['tipo_contribuyente'],
                ':actividad' => $valores['actividad'] !== '' ? $valores['actividad'] : null,
            ]);
            // PRG: redirigir tras guardar evita que F5 cargue el contacto dos veces.
            header('Location: /contactos.php?msg=' . urlencode('Contacto guardado.'));
            exit;
        } catch (PDOException $e) {
            // 23000 = violación de UNIQUE (documento repetido).
            if ($e->getCode() === '23000') {
                $errores[] = 'Ya existe un contacto con ese documento.';
            } else {
                $errores[] = 'Error al guardar: ' . $e->getMessage();
            }
        }
    }
}

// Traemos todos los contactos para el listado de abajo.
$contactos = $pdo->query("SELECT * FROM contactos ORDER BY nombre")->fetchAll();
$mensaje = $_GET['msg'] ?? '';     // cartel verde de éxito
$error   = $_GET['error'] ?? '';   // cartel rojo (ej: borrado bloqueado)

$titulo = 'Contactos';
require __DIR__ . '/includes/header.php';
?>

<h2>Contactos</h2>

<?php if ($mensaje): ?>
    <p class="ok"><?= htmlspecialchars($mensaje) ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <ul class="errores"><li><?= htmlspecialchars($error) ?></li></ul>
<?php endif; ?>

<?php if ($errores): ?>
    <ul class="errores">
        <?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" class="formulario">
    <label>Nombre / Razón social
        <input type="text" name="nombre" value="<?= htmlspecialchars($valores['nombre']) ?>">
    </label>
    <label>Documento (CUIT/DNI)
        <input type="text" name="documento" value="<?= htmlspecialchars($valores['documento']) ?>">
    </label>
    <label>Tipo de contribuyente
        <select name="tipo_contribuyente">
            <option value="">Elegí...</option>
            <option value="Responsable Inscripto" <?= $valores['tipo_contribuyente'] === 'Responsable Inscripto' ? 'selected' : '' ?>>Responsable Inscripto</option>
            <option value="Monotributista" <?= $valores['tipo_contribuyente'] === 'Monotributista' ? 'selected' : '' ?>>Monotributista</option>
            <option value="Exento" <?= $valores['tipo_contribuyente'] === 'Exento' ? 'selected' : '' ?>>Exento</option>
            <option value="Consumidor Final" <?= $valores['tipo_contribuyente'] === 'Consumidor Final' ? 'selected' : '' ?>>Consumidor Final</option>
        </select>
    </label>
    <label>Actividad
        <input type="text" name="actividad" value="<?= htmlspecialchars($valores['actividad']) ?>">
    </label>
    <button type="submit" class="btn btn-guardar">Guardar contacto</button>
</form>

<?php if (!$contactos): ?>
    <p>Todavía no hay contactos cargados.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>Nombre</th><th>Documento</th><th>Tipo</th><th>Actividad</th><th>Acciones</th></tr>
        </thead>
        <tbody>
            <?php foreach ($contactos as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['nombre']) ?></td>
                    <td><?= htmlspecialchars($c['documento'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($c['tipo_contribuyente']) ?></td>
                    <td><?= htmlspecialchars($c['actividad'] ?? '-') ?></td>
                    <td>
                        <a class="btn" href="/editar.php?id=<?= (int)$c['id'] ?>">Editar</a>
                        <a class="btn btn-borrar" href="/eliminar.php?id=<?= (int)$c['id'] ?>"
                           onclick="return confirm('¿Eliminar este contacto?')">Eliminar</a>
                    </td>
                </tr>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>