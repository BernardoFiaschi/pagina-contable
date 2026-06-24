<?php
/*
 * importar_retenciones.php
 * ------------------------
 * Importa percepciones y retenciones desde archivos de ARBA (IIBB) y ARCA (IVA / Ganancias).
 *
 * Tipos de archivo soportados:
 *   ARBA -P.txt  → Percepciones IIBB (matchea con compras por PV+Nro)
 *   ARBA -B.txt  → Retenciones bancarias IIBB
 *   ARBA -T.txt  → Retenciones IIBB (certificados)
 *   ARCA .xls    → Retenciones/Percepciones de IVA y Ganancias (matchea por Nro Comprobante)
 */
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requerirLogin();
$uid = usuarioId();

function num_ret(string $s): float {
    $s = trim($s);
    $neg = (strpos($s, '-') === 0);
    $s   = str_replace(['-', '.'], '', $s);
    $s   = str_replace(',', '.', $s);
    return $neg ? -(float)$s : (float)$s;
}
function fecha_ret(string $s): string {
    $s = trim($s);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) return "$m[3]-$m[2]-$m[1]";
    if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $s)) return $s;
    return $s;
}

$empresas = $pdo->prepare("SELECT id, nombre FROM empresas WHERE usuario_id=:uid ORDER BY nombre");
$empresas->execute([':uid'=>$uid]); $empresas = $empresas->fetchAll();

$errores = []; $resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresaId = filter_input(INPUT_POST, 'empresa_id', FILTER_VALIDATE_INT);
    $tipoArch  = $_POST['tipo_archivo'] ?? '';

    if (!$empresaId) { $errores[] = 'Elegí la empresa.'; }
    if (!$tipoArch)  { $errores[] = 'Elegí el tipo de archivo.'; }
    if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        $errores[] = 'Subí un archivo.';
    }

    if ($empresaId) {
        $chk = $pdo->prepare("SELECT id FROM empresas WHERE id=:id AND usuario_id=:uid");
        $chk->execute([':id'=>$empresaId, ':uid'=>$uid]);
        if (!$chk->fetch()) $errores[] = 'Empresa no válida.';
    }

    if (!$errores) {
        $importados = 0; $ignorados = 0; $linkeados = 0;
        $tmpPath = $_FILES['archivo']['tmp_name'];

        // ---- Stmt de inserción
        $ins = $pdo->prepare("INSERT INTO retenciones
            (empresa_id,tipo,subtipo,cuit_agente,nombre_agente,fecha,nro_certificado,
             comprobante_tipo,punto_venta,numero,importe,compra_id,venta_id)
            VALUES (:eid,:tipo,:sub,:cuit,:nom,:fec,:cert,:ct,:pv,:num,:imp,:cid,:vid)");

        // ---- Función de linkeo: busca compra por PV+Nro
        $linkearCompra = function(int $eid, int $pv, int $nro) use ($pdo): ?int {
            $st = $pdo->prepare("SELECT id FROM compras WHERE empresa_id=:e AND COALESCE(punto_venta,0)=CAST(:pv AS INTEGER) AND COALESCE(numero,0)=CAST(:n AS INTEGER) LIMIT 1");
            $st->execute([':e'=>$eid, ':pv'=>$pv, ':n'=>$nro]);
            return ($r = $st->fetchColumn()) ? (int)$r : null;
        };
        $linkearVenta = function(int $eid, int $pv, int $nro) use ($pdo): ?int {
            $st = $pdo->prepare("SELECT id FROM ventas WHERE empresa_id=:e AND COALESCE(punto_venta,0)=CAST(:pv AS INTEGER) AND COALESCE(numero,0)=CAST(:n AS INTEGER) LIMIT 1");
            $st->execute([':e'=>$eid, ':pv'=>$pv, ':n'=>$nro]);
            return ($r = $st->fetchColumn()) ? (int)$r : null;
        };

        try {
            $pdo->beginTransaction();

            if ($tipoArch === 'arba_p') {
                // ---- ARBA Percepciones IIBB (-P.txt)
                // Formato: CUIT(13) + fecha(10) + tipo_comp(2) + PV(5) + Nro(8) + importe(variable)
                $lineas = explode("\n", str_replace("\r", "", file_get_contents($tmpPath)));
                foreach ($lineas as $ln) {
                    $ln = rtrim($ln);
                    if (strlen($ln) < 30) continue;
                    $cuit  = substr($ln, 0, 13);
                    $fecha = fecha_ret(substr($ln, 13, 10));
                    $tipo  = trim(substr($ln, 23, 2));
                    $pv    = (int)substr($ln, 25, 5);
                    $nro   = (int)substr($ln, 30, 8);
                    $imp   = num_ret(substr($ln, 38));
                    if ($imp == 0.0) { $ignorados++; continue; }

                    $cid = ($pv > 0 || $nro > 0) ? $linkearCompra($empresaId, $pv, $nro) : null;
                    // Actualizar compra si se linkeó
                    if ($cid) {
                        $pdo->prepare("UPDATE compras SET percepcion_iibb=COALESCE(percepcion_iibb,0)+:imp WHERE id=:id")
                            ->execute([':imp'=>$imp, ':id'=>$cid]);
                        $linkeados++;
                    }
                    $ins->execute([':eid'=>$empresaId, ':tipo'=>'percepcion_iibb', ':sub'=>'percepcion',
                        ':cuit'=>$cuit, ':nom'=>null, ':fec'=>$fecha, ':cert'=>null,
                        ':ct'=>$tipo ?: null, ':pv'=>$pv ?: null, ':num'=>$nro ?: null,
                        ':imp'=>$imp, ':cid'=>$cid, ':vid'=>null]);
                    $importados++;
                }

            } elseif ($tipoArch === 'arba_b') {
                // ---- ARBA Retenciones Bancarias (-B.txt)
                // Formato: banco(9) + nroOp(13) + CUIT(13) + fecha(10) + cod(2) + importe(14)
                $lineas = explode("\n", str_replace("\r", "", file_get_contents($tmpPath)));
                foreach ($lineas as $ln) {
                    $ln = rtrim($ln);
                    if (strlen($ln) < 47) continue;
                    $banco = substr($ln, 0, 9);
                    $nrop  = substr($ln, 9, 13);
                    $cuit  = substr($ln, 22, 13);
                    $fecha = fecha_ret(substr($ln, 35, 10));
                    $imp   = num_ret(substr($ln, 47));
                    if ($imp == 0.0) { $ignorados++; continue; }

                    $ins->execute([':eid'=>$empresaId, ':tipo'=>'retencion_bancaria_iibb', ':sub'=>'retencion',
                        ':cuit'=>$cuit, ':nom'=>"Banco $banco", ':fec'=>$fecha, ':cert'=>$nrop,
                        ':ct'=>null, ':pv'=>null, ':num'=>null,
                        ':imp'=>$imp, ':cid'=>null, ':vid'=>null]);
                    $importados++;
                }

            } elseif ($tipoArch === 'arba_t') {
                // ---- ARBA Retenciones IIBB (-T.txt)
                // Formato: CUIT(13) + periodo(6) + fecha(10) + nroCert(20) + importe(20)
                $lineas = explode("\n", str_replace("\r", "", file_get_contents($tmpPath)));
                foreach ($lineas as $ln) {
                    $ln = rtrim($ln);
                    if (strlen($ln) < 49) continue;
                    $cuit   = substr($ln, 0, 13);
                    $fecha  = fecha_ret(substr($ln, 19, 10));
                    $nroC   = ltrim(substr($ln, 29, 20), '0') ?: '0';
                    $imp    = num_ret(substr($ln, 49));
                    if ($imp == 0.0) { $ignorados++; continue; }

                    $ins->execute([':eid'=>$empresaId, ':tipo'=>'retencion_iibb', ':sub'=>'retencion',
                        ':cuit'=>$cuit, ':nom'=>null, ':fec'=>$fecha, ':cert'=>$nroC,
                        ':ct'=>null, ':pv'=>null, ':num'=>null,
                        ':imp'=>$imp, ':cid'=>null, ':vid'=>null]);
                    $importados++;
                }

            } elseif (in_array($tipoArch, ['arca_iva', 'arca_ganancias'], true)) {
                // ---- ARCA XLS (IVA o Ganancias)
                // Columnas: CUIT|Nombre|Impuesto|Desc|Regimen|DescReg|Fecha|NroCert|Tipo|Importe|NroComp|FechaComp
                $tipoRet = ($tipoArch === 'arca_iva') ? 'percepcion_iva' : 'percepcion_ganancias';

                // Usar xlrd via python para parsear el XLS
                $jsonTmp = tempnam(sys_get_temp_dir(), 'xls');
                $cmd = "python3 -c \"
import xlrd, json, sys
wb = xlrd.open_workbook(sys.argv[1])
sh = wb.sheets()[0]
rows = []
for r in range(1, sh.nrows):
    row = [str(sh.cell(r,c).value).strip() for c in range(sh.ncols)]
    rows.append(row)
print(json.dumps(rows))
\" " . escapeshellarg($tmpPath) . " 2>/dev/null";
                $json = shell_exec($cmd);
                $rows = $json ? json_decode($json, true) : [];

                foreach (($rows ?: []) as $row) {
                    if (count($row) < 10) continue;
                    $cuit  = preg_replace('/[^0-9]/', '', $row[0]);
                    $nom   = $row[1];
                    $fecha = fecha_ret($row[6]);
                    $cert  = $row[7];
                    $subTipo = strtolower(trim($row[8])); // 'percepcion' o 'retencion'
                    $impStr= str_replace('.', ',', $row[9]); // puede venir con . decimal
                    $impStr= str_replace(',', '.', $impStr);
                    $imp   = (float)$impStr;
                    if ($imp == 0.0) { $ignorados++; continue; }

                    // Tipo real: percepcion_iva, retencion_iva, percepcion_ganancias, retencion_ganancias
                    $tipoBD = (str_contains($subTipo,'percep') ? 'percepcion' : 'retencion') . '_' . ($tipoArch === 'arca_iva' ? 'iva' : 'ganancias');

                    // Linkear a venta por nro comprobante (ARCA pone el nro de mov bancario, no el de factura)
                    // Por ahora almacenamos sin linkear; el usuario puede editar si quiere
                    $ins->execute([':eid'=>$empresaId, ':tipo'=>$tipoBD, ':sub'=>str_contains($subTipo,'percep')?'percepcion':'retencion',
                        ':cuit'=>$cuit, ':nom'=>$nom, ':fec'=>$fecha, ':cert'=>$cert,
                        ':ct'=>null, ':pv'=>null, ':num'=>null,
                        ':imp'=>$imp, ':cid'=>null, ':vid'=>null]);
                    $importados++;
                }
            }

            $pdo->commit();
            $resultado = compact('importados', 'ignorados', 'linkeados');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = 'Error: ' . $e->getMessage();
        }
    }
}

// Retenciones ya importadas para esta empresa
$retExistentes = null;
$fEmpresa = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT);
if ($fEmpresa) {
    $fPer = trim($_GET['periodo'] ?? '');
    $condR = "WHERE r.empresa_id=:eid";
    $parR  = [':eid'=>$fEmpresa];
    if ($fPer) { $condR .= " AND r.fecha LIKE :per"; $parR[':per']=$fPer.'%'; }
    $retExistentes = $pdo->prepare("SELECT r.*,
        COALESCE(c.punto_venta||'-'||c.numero,'') AS comp_compra,
        COALESCE(v.punto_venta||'-'||v.numero,'') AS comp_venta
        FROM retenciones r
        LEFT JOIN compras c ON c.id=r.compra_id
        LEFT JOIN ventas  v ON v.id=r.venta_id
        $condR ORDER BY r.fecha DESC, r.id DESC");
    $retExistentes->execute($parR);
    $retExistentes = $retExistentes->fetchAll();
}

$titulo = 'Importar retenciones';
require __DIR__ . '/includes/header.php';

$tiposLabel = [
    'percepcion_iibb'         => 'Percepción IIBB',
    'retencion_iibb'          => 'Retención IIBB',
    'retencion_bancaria_iibb' => 'Ret. Bancaria IIBB',
    'percepcion_iva'          => 'Percepción IVA',
    'percepcion_ganancias'    => 'Percepción Ganancias',
    'retencion_iva'           => 'Retención IVA',
    'retencion_ganancias'     => 'Retención Ganancias',
];
?>
<h2>Importar retenciones y percepciones</h2>

<?php if ($errores): ?><ul class="errores"><?php foreach($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($resultado): ?>
    <p class="ok">Importados: <strong><?= $resultado['importados'] ?></strong>
        · Ignorados (importe $0): <?= $resultado['ignorados'] ?>
        · Linkeados a compras: <?= $resultado['linkeados'] ?></p>
<?php endif; ?>

<?php if (!$empresas): ?>
    <p>Primero cargá una empresa en <a href="/empresas.php">Empresas</a>.</p>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="formulario">
    <label>Empresa
        <select name="empresa_id" required>
            <option value="">Elegí...</option>
            <?php foreach($empresas as $em): ?>
                <option value="<?= $em['id'] ?>"><?= htmlspecialchars($em['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Tipo de archivo
        <select name="tipo_archivo" required>
            <option value="">Elegí...</option>
            <optgroup label="ARBA — Ingresos Brutos (.txt)">
                <option value="arba_p">-P.txt → Percepciones IIBB</option>
                <option value="arba_b">-B.txt → Retenciones Bancarias IIBB</option>
                <option value="arba_t">-T.txt → Retenciones IIBB</option>
            </optgroup>
            <optgroup label="ARCA — SICORE (.xls)">
                <option value="arca_iva">XLS → IVA (percepciones/retenciones)</option>
                <option value="arca_ganancias">XLS → Ganancias (percepciones/retenciones)</option>
            </optgroup>
        </select>
    </label>
    <label>Archivo
        <input type="file" name="archivo" accept=".txt,.xls,.xlsx" required>
    </label>
    <button type="submit" class="btn btn-guardar">Importar</button>
</form>

<h2 style="margin-top:2rem;">Ver retenciones importadas</h2>
<form method="get" class="filtros">
    <label>Empresa
        <select name="empresa_id">
            <option value="">Elegí...</option>
            <?php foreach($empresas as $em): ?>
                <option value="<?= $em['id'] ?>" <?= $fEmpresa===$em['id']?'selected':'' ?>><?= htmlspecialchars($em['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Período <input type="month" name="periodo" value="<?= htmlspecialchars($_GET['periodo']??'') ?>"></label>
    <button type="submit" class="btn btn-guardar">Filtrar</button>
</form>

<?php if ($retExistentes !== null): ?>
<?php if (!$retExistentes): ?>
    <p>No hay retenciones para ese filtro.</p>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="tabla-lista">
    <thead><tr>
        <th>Fecha</th><th>Tipo</th><th>Agente</th><th>CUIT</th>
        <th>Cert./Nro</th><th class="num">Importe</th><th>Linkeo</th>
    </tr></thead>
    <tbody>
    <?php foreach($retExistentes as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['fecha']) ?></td>
            <td><span style="font-size:.8rem;"><?= htmlspecialchars($tiposLabel[$r['tipo']] ?? $r['tipo']) ?></span></td>
            <td><?= htmlspecialchars($r['nombre_agente'] ?? '-') ?></td>
            <td class="muted" style="font-size:.8rem;"><?= htmlspecialchars($r['cuit_agente'] ?? '') ?></td>
            <td class="muted" style="font-size:.8rem;"><?= htmlspecialchars($r['nro_certificado'] ?? '') ?></td>
            <td class="num"><?= ($r['importe']<0?'<span style="color:var(--verde)">':'').'$'.number_format(abs((float)$r['importe']),2,',','.').(($r['importe']<0)?'</span>':'') ?></td>
            <td style="font-size:.8rem;">
                <?php if ($r['compra_id']): ?>🔗 Compra <?= htmlspecialchars($r['comp_compra']) ?>
                <?php elseif ($r['venta_id']): ?>🔗 Venta <?= htmlspecialchars($r['comp_venta']) ?>
                <?php else: ?>—<?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>