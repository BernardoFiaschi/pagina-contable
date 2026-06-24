<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/iibb_funciones.php';
requerirLogin();

$uid     = usuarioId();
$errores = [];
$mensaje = $_GET['msg'] ?? '';
$editId  = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
$elimId  = filter_input(INPUT_GET, 'eliminar', FILTER_VALIDATE_INT);

// --- ELIMINAR ---
if ($elimId) {
    $st = $pdo->prepare("SELECT id FROM empresas WHERE id=:id AND usuario_id=:uid");
    $st->execute([':id'=>$elimId,':uid'=>$uid]);
    if ($st->fetch()) {
        $pdo->prepare("DELETE FROM empresas WHERE id=:id")->execute([':id'=>$elimId]);
    }
    header('Location: /empresas.php?msg='.urlencode('Empresa eliminada.')); exit;
}

// Valores vacíos para el formulario
$v = ['nombre'=>'','documento'=>'','tipo_contribuyente'=>''];
$actividades_form = [['codigo'=>'','descripcion'=>'','iibb_alicuota'=>'']];
$modoEdicion = false;

// --- Cargar datos para editar ---
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM empresas WHERE id=:id AND usuario_id=:uid");
    $st->execute([':id'=>$editId,':uid'=>$uid]);
    $row = $st->fetch();
    if ($row) {
        $modoEdicion = true;
        $v = $row;
        $acts = $pdo->prepare("SELECT * FROM empresa_actividades WHERE empresa_id=:id ORDER BY id");
        $acts->execute([':id'=>$editId]);
        $actividades_form = $acts->fetchAll();
        if (!$actividades_form) $actividades_form = [['codigo'=>'','descripcion'=>'','iibb_alicuota'=>'']];
    }
}

// --- GUARDAR (alta o edición) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['nombre']             = trim($_POST['nombre'] ?? '');
    $v['documento']          = trim($_POST['documento'] ?? '');
    $v['tipo_contribuyente'] = trim($_POST['tipo_contribuyente'] ?? '');
    $postId                  = filter_input(INPUT_POST, 'empresa_id', FILTER_VALIDATE_INT);

    // Actividades: arrays paralelos del form
    $codigos   = $_POST['act_codigo']   ?? [];
    $descs     = $_POST['act_desc']     ?? [];
    $alicuotas = $_POST['act_alicuota'] ?? [];

    // Armar filas limpias (al menos descripción no vacía)
    $actividades_form = [];
    foreach ($descs as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') continue;
        $cod   = trim($codigos[$i]   ?? '');
        $alic  = $alicuotas[$i] !== '' ? (float)str_replace(',','.',$alicuotas[$i]) : null;
        // Si no hay alícuota manual, intentar deducirla del código
        if ($alic === null && $cod !== '') {
            $alic = alicuotaIIBB($cod);
        }
        $actividades_form[] = ['codigo'=>$cod,'descripcion'=>$desc,'iibb_alicuota'=>$alic];
    }
    if (!$actividades_form) {
        $actividades_form = [['codigo'=>'','descripcion'=>'','iibb_alicuota'=>'']];
    }

    if ($v['nombre'] === '') { $errores[] = 'La razón social es obligatoria.'; }
    if (!in_array($v['tipo_contribuyente'], ['Responsable Inscripto','Monotributista'], true)) {
        $errores[] = 'Elegí un tipo de contribuyente.';
    }

    if (!$errores) {
        try {
            $pdo->beginTransaction();
            if ($postId) {
                // Verificar que pertenece al usuario
                $chk = $pdo->prepare("SELECT id FROM empresas WHERE id=:id AND usuario_id=:uid");
                $chk->execute([':id'=>$postId,':uid'=>$uid]);
                if (!$chk->fetch()) throw new Exception('No autorizado.');
                $pdo->prepare("UPDATE empresas SET nombre=:n,documento=:d,tipo_contribuyente=:t WHERE id=:id")
                    ->execute([':n'=>$v['nombre'],':d'=>$v['documento']!==''?$v['documento']:null,
                               ':t'=>$v['tipo_contribuyente'],':id'=>$postId]);
                $pdo->prepare("DELETE FROM empresa_actividades WHERE empresa_id=:id")->execute([':id'=>$postId]);
                $empId = $postId;
            } else {
                $pdo->prepare("INSERT INTO empresas (nombre,documento,tipo_contribuyente,usuario_id) VALUES (:n,:d,:t,:uid)")
                    ->execute([':n'=>$v['nombre'],':d'=>$v['documento']!==''?$v['documento']:null,
                               ':t'=>$v['tipo_contribuyente'],':uid'=>$uid]);
                $empId = (int)$pdo->lastInsertId();
            }
            $stA = $pdo->prepare("INSERT INTO empresa_actividades (empresa_id,codigo,descripcion,iibb_alicuota) VALUES (:eid,:cod,:desc,:al)");
            foreach ($actividades_form as $a) {
                $stA->execute([':eid'=>$empId,':cod'=>$a['codigo']!==''?$a['codigo']:null,
                               ':desc'=>$a['descripcion'],':al'=>$a['iibb_alicuota']]);
            }
            $pdo->commit();
            header('Location: /empresas.php?msg='.urlencode($postId?'Empresa actualizada.':'Empresa guardada.')); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = str_contains($e->getMessage(),'UNIQUE') ? 'Ya existe una empresa con ese CUIT.' : 'Error: '.$e->getMessage();
        }
    }
    $modoEdicion = (bool)$postId;
    $editId      = $postId ?: null;
}

// Listado de empresas del usuario con sus actividades
$empresas = $pdo->prepare("SELECT * FROM empresas WHERE usuario_id=:uid ORDER BY nombre");
$empresas->execute([':uid'=>$uid]);
$empresas = $empresas->fetchAll();

$actsPorEmp = [];
if ($empresas) {
    $ids = implode(',', array_column($empresas,'id'));
    foreach ($pdo->query("SELECT * FROM empresa_actividades WHERE empresa_id IN ($ids) ORDER BY empresa_id,id")->fetchAll() as $a) {
        $actsPorEmp[$a['empresa_id']][] = $a;
    }
}

$titulo = 'Empresas';
require __DIR__ . '/includes/header.php';
?>

<h2>Empresas (titulares)</h2>
<p>Las empresas cuyos libros llevás. Cada una puede tener varias actividades para IIBB.</p>

<?php if ($mensaje): ?><p class="ok"><?= htmlspecialchars($mensaje) ?></p><?php endif; ?>
<?php if ($errores): ?><ul class="errores"><?php foreach($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul><?php endif; ?>

<!-- Formulario alta / edición -->
<details class="bloque-alta" <?= ($modoEdicion || $errores) ? 'open' : '' ?>>
    <summary><?= $modoEdicion ? '✏️ Editando empresa' : '+ Nueva empresa' ?></summary>
    <form method="post" class="formulario" style="max-width:760px;">
        <?php if ($modoEdicion): ?><input type="hidden" name="empresa_id" value="<?= (int)$editId ?>"><?php endif; ?>
        <label>Razón social
            <input type="text" name="nombre" value="<?= htmlspecialchars($v['nombre']) ?>" required>
        </label>
        <label>CUIT
            <input type="text" name="documento" value="<?= htmlspecialchars($v['documento'] ?? '') ?>">
        </label>
        <label>Tipo de contribuyente
            <select name="tipo_contribuyente">
                <option value="">Elegí...</option>
                <?php foreach (['Responsable Inscripto','Monotributista'] as $t): ?>
                    <option value="<?= $t ?>" <?= ($v['tipo_contribuyente']==$t)?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <!-- Actividades múltiples -->
        <div style="grid-column:1/-1;">
            <h3>Actividades económicas</h3>
            <p style="font-size:.85rem;color:var(--texto-suave);">Podés cargar una o más. La alícuota IIBB se completa sola si ingresás el código AFIP.</p>
            <div id="acti-filas">
            <?php foreach ($actividades_form as $i => $a): ?>
                <div class="acti-fila" style="display:grid;grid-template-columns:130px 1fr 100px auto;gap:.5rem;align-items:end;margin-bottom:.5rem;">
                    <label style="font-size:.78rem;">Código AFIP
                        <input type="text" name="act_codigo[]" class="act-cod" value="<?= htmlspecialchars($a['codigo']??'') ?>" placeholder="ej: 620100" style="width:100%;">
                    </label>
                    <label style="font-size:.78rem;">Descripción
                        <input type="text" name="act_desc[]" class="act-desc" value="<?= htmlspecialchars($a['descripcion']??'') ?>" placeholder="Actividad..." style="width:100%;" autocomplete="off">
                        <ul class="combo-lista" hidden style="position:absolute;z-index:20;"></ul>
                    </label>
                    <label style="font-size:.78rem;">IIBB %
                        <input type="number" step="0.01" name="act_alicuota[]" class="act-alic" value="<?= $a['iibb_alicuota']!==null&&$a['iibb_alicuota']!==''?htmlspecialchars((string)$a['iibb_alicuota']):'' ?>" placeholder="auto" style="width:100%;">
                    </label>
                    <button type="button" class="btn btn-borrar quitar-fila" style="margin-top:1.1rem;">✕</button>
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" id="agregar-actividad" class="btn">+ Agregar actividad</button>
        </div>

        <button type="submit" class="btn btn-guardar"><?= $modoEdicion ? 'Guardar cambios' : 'Guardar empresa' ?></button>
        <?php if ($modoEdicion): ?><a href="/empresas.php" class="btn">Cancelar</a><?php endif; ?>
    </form>
</details>

<!-- Listado -->
<?php if (!$empresas): ?>
    <p>Todavía no hay empresas cargadas.</p>
<?php else: ?>
<table>
    <thead><tr><th>Razón social</th><th>CUIT</th><th>Tipo</th><th>Actividades / IIBB</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($empresas as $em): ?>
        <tr>
            <td><?= htmlspecialchars($em['nombre']) ?></td>
            <td><?= htmlspecialchars($em['documento'] ?? '-') ?></td>
            <td><?= htmlspecialchars($em['tipo_contribuyente']) ?></td>
            <td>
                <?php foreach ($actsPorEmp[$em['id']] ?? [] as $a): ?>
                    <div style="font-size:.85rem;">
                        <?php if ($a['codigo']): ?><span class="muted"><?= htmlspecialchars($a['codigo']) ?></span> <?php endif; ?>
                        <?= htmlspecialchars($a['descripcion']) ?>
                        <?php if ($a['iibb_alicuota'] !== null): ?>
                            <span class="muted">(<?= str_replace('.',',',(string)(float)$a['iibb_alicuota']) ?>%)</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($actsPorEmp[$em['id']])): ?><span class="muted">-</span><?php endif; ?>
            </td>
            <td class="acciones-fila" style="white-space:nowrap;">
                <a href="/empresas.php?editar=<?= $em['id'] ?>" title="Editar">✏️</a>
                <a href="/empresas.php?eliminar=<?= $em['id'] ?>" title="Eliminar"
                   onclick="return confirm('¿Eliminar <?= htmlspecialchars(addslashes($em['nombre'])) ?>?')">🗑️</a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<script>
(function(){
    // Buscador de actividades por descripcion o codigo
    var acts = null;
    function normalizar(s){ return (s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }

    function cargarActs(cb){
        if (acts){ cb(); return; }
        fetch('/data/actividades_afip.json').then(r=>r.json()).then(d=>{ acts=d; cb(); });
    }

    function bindFila(fila){
        var cod  = fila.querySelector('.act-cod');
        var desc = fila.querySelector('.act-desc');
        var alic = fila.querySelector('.act-alic');
        var lista= fila.querySelector('.combo-lista');

        // Al escribir en descripción: filtrar lista
        desc.addEventListener('input', function(){
            cargarActs(function(){
                var q = normalizar(desc.value).trim();
                lista.innerHTML='';
                if(q.length<2){ lista.hidden=true; return; }
                var res=[];
                for(var i=0;i<acts.length&&res.length<40;i++){
                    var a=acts[i];
                    if(a.cod.indexOf(q)===0||normalizar(a.desc).indexOf(q)!==-1) res.push(a);
                }
                if(!res.length){ lista.hidden=true; return; }
                res.forEach(function(a){
                    var li=document.createElement('li');
                    li.textContent=a.cod+' - '+a.desc;
                    li.addEventListener('mousedown',function(ev){
                        ev.preventDefault();
                        cod.value=a.cod;
                        desc.value=a.desc;
                        lista.hidden=true;
                        // Buscar alicuota automatica via fetch pequeño
                        if(!alic.value){
                            fetch('/api/iibb_alicuota.php?cod='+encodeURIComponent(a.cod))
                                .then(r=>r.json()).then(d=>{ if(d.alic!==null) alic.value=d.alic; });
                        }
                    });
                    lista.appendChild(li);
                });
                lista.hidden=false;
            });
        });
        desc.addEventListener('blur',function(){ setTimeout(function(){ lista.hidden=true; },150); });

        // Al ingresar código manualmente: buscar alícuota
        cod.addEventListener('change',function(){
            if(cod.value && !alic.value){
                fetch('/api/iibb_alicuota.php?cod='+encodeURIComponent(cod.value))
                    .then(r=>r.json()).then(d=>{ if(d.alic!==null) alic.value=d.alic; });
            }
        });
    }

    // Quitar fila
    document.addEventListener('click',function(ev){
        if(ev.target.classList.contains('quitar-fila')){
            var filas=document.querySelectorAll('.acti-fila');
            if(filas.length>1) ev.target.closest('.acti-fila').remove();
        }
    });

    // Agregar fila
    document.getElementById('agregar-actividad').addEventListener('click',function(){
        var modelo=document.querySelector('.acti-fila');
        var nueva=modelo.cloneNode(true);
        nueva.querySelectorAll('input').forEach(function(i){ i.value=''; });
        var l=nueva.querySelector('.combo-lista');
        if(l){ l.innerHTML=''; l.hidden=true; }
        document.getElementById('acti-filas').appendChild(nueva);
        bindFila(nueva);
    });

    // Bindear filas existentes
    document.querySelectorAll('.acti-fila').forEach(bindFila);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>