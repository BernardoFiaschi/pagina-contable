<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
requerirLogin();
$uid = usuarioId();

/* ---- helpers de parseo ---- */
function norm(string $s): string {
    $s = strtolower(trim($s));
    return strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);
}
function num($s): float {
    $s = trim((string)$s);
    if ($s === '') return 0.0;
    if (strpos($s, ',') !== false) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
    return (float)$s;
}
function fecha($s): string {
    $s = trim((string)$s);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $s, $m)) return "$m[3]-$m[2]-$m[1]";
    if (preg_match('#^\d{4}-\d{2}-\d{2}#', $s)) return substr($s, 0, 10);
    return $s;
}
function aUtf8(string $s): string {
    if (preg_match('//u', $s) === 1) return $s;
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    if (function_exists('iconv')) return (string)iconv('Windows-1252', 'UTF-8//TRANSLIT', $s);
    $out = '';
    for ($i = 0, $len = strlen($s); $i < $len; $i++) {
        $c = ord($s[$i]); $out .= $c < 0x80 ? $s[$i] : (chr(0xC0|($c>>6)).chr(0x80|($c&0x3F)));
    }
    return $out;
}
// Busca columna cuyo nombre (normalizado) contiene todos los items de $must
// y al menos uno de $pcts, y ninguno de $not.
function colFlex(array $cab, array $must, array $pcts = [], array $not = []): ?int {
    foreach ($cab as $i => $h) {
        $hn = norm($h);
        foreach ($not  as $n) { if (strpos($hn, $n) !== false) continue 2; }
        foreach ($must as $m) { if (strpos($hn, $m) === false) continue 2; }
        if ($pcts) {
            $ok = false;
            foreach ($pcts as $p) { if (strpos($hn, $p) !== false) { $ok = true; break; } }
            if (!$ok) continue;
        }
        return $i;
    }
    return null;
}
function col(array $cab, array $cands): ?int {
    foreach ($cab as $i => $h) {
        foreach ($cands as $c) { if (strpos(norm($h), norm($c)) !== false) return $i; }
    }
    return null;
}
function colExact(array $cab, string $t): ?int {
    $t = norm($t);
    foreach ($cab as $i => $h) { if (norm($h) === $t) return $i; }
    return null;
}

$codigos  = require __DIR__ . '/config/comprobantes.php';

$stEmp = $pdo->prepare("SELECT id, nombre FROM empresas WHERE usuario_id=:uid ORDER BY nombre");
$stEmp->execute([':uid'=>$uid]); $empresas = $stEmp->fetchAll();

$todasActividades = [];
if ($empresas) {
    $ids = implode(',', array_column($empresas,'id'));
    foreach ($pdo->query("SELECT empresa_id,codigo,descripcion FROM empresa_actividades WHERE empresa_id IN ($ids) ORDER BY empresa_id,id")->fetchAll() as $a) {
        $todasActividades[$a['empresa_id']][] = $a;
    }
}

$resultado = null; $errores = [];
$paso = $_POST['paso'] ?? '1';
$proveedoresSinClas = []; $csvBase64 = ''; $paso1EmpId = 0; $paso1ActCod = ''; $paso1ActDesc = '';

/* ---- Función de parseo del CSV ---- */
function parsearCSV(string $tmpPath): array {
    $c = file_get_contents($tmpPath);
    $c = aUtf8($c);
    $c = str_replace(["\r\n","\r"], ["\n","\n"], $c);
    $primera = strtok($c, "\n");
    $sep = (substr_count($primera, ';') >= substr_count($primera, ',')) ? ';' : ',';
    $filas = [];
    foreach (explode("\n", $c) as $ln) {
        if (trim($ln) === '') continue;
        $filas[] = str_getcsv($ln, $sep, '"', '');
    }
    $cab = null; $inicio = 0;
    foreach ($filas as $i => $f) {
        if (col($f, ['punto de venta']) !== null) { $cab = $f; $inicio = $i + 1; break; }
    }
    return ['cab'=>$cab, 'filas'=>$filas, 'inicio'=>$inicio];
}

/* ---- Función de importación ---- */
function importarFilas(PDO $pdo, array $cab, array $filas, int $inicio, array $codigos,
                       int $empresaId, bool $esVentas, string $actDesc, string $actCodigo): array
{
    // Columnas fijas
    $ix = [
        'fecha'   => col($cab, ['fecha de emision','fecha']),
        'tipo'    => col($cab, ['tipo de comprobante','tipo']),
        'pv'      => col($cab, ['punto de venta']),
        'nro'     => col($cab, ['numero desde','numero']),
        'cae'     => col($cab, ['autorizacion']),
        'doc'     => col($cab, ['nro. doc']),
        'nombre'  => col($cab, ['denominacion']),
        'tc'      => col($cab, ['tipo cambio']),
        'moneda'  => col($cab, ['moneda']),
        'nograv'  => col($cab, ['neto no gravado','no gravado']),
        'exento'  => col($cab, ['op. exentas','exenta','exento']),
        'otros'   => col($cab, ['otros tributos','percepciones']),
        'netotot' => col($cab, ['neto gravado total','neto gravado']),
        'ivatot'  => col($cab, ['total iva','imp. iva','iva total']),
        'imptot'  => col($cab, ['imp. total','importe total']),
    ];

    // Columnas por alícuota: busca tanto "Neto Grav. IVA X%" como "IVA X%"
    // Usamos colFlex para ser robustos ante variantes de nombre
    $rates = [
        ['pct'=>'27',   'pct2'=>'27.0', 'val'=>27.0],
        ['pct'=>'21',   'pct2'=>'21.0', 'val'=>21.0],
        ['pct'=>'10,5', 'pct2'=>'10.5', 'val'=>10.5],
        ['pct'=>'5',    'pct2'=>'5.0',  'val'=>5.0 ],
        ['pct'=>'2,5',  'pct2'=>'2.5',  'val'=>2.5 ],
    ];
    $ratesCols = []; $seen = [];
    foreach ($rates as $r) {
        if (isset($seen[$r['val']])) continue;
        // Columna IVA X% (sin "neto")
        $ivaC = colFlex($cab, ['iva'], [$r['pct'].'%', $r['pct2'].'%'], ['neto','gravado']);
        // Columna Neto Grav. IVA X% (con "neto", "grav" e "iva")
        $netC = colFlex($cab, ['neto','grav','iva'], [$r['pct'].'%', $r['pct2'].'%']);
        if ($ivaC !== null || $netC !== null) {
            $seen[$r['val']] = true;
            $ratesCols[] = ['val'=>$r['val'], 'iva'=>$ivaC, 'neto'=>$netC];
        }
    }
    // Columna neto 0%
    $neto0Col = colFlex($cab, ['neto','grav'], ['0%'], ['iva']); // "Neto Grav. IVA 0%" o "Neto Grav. 0%"
    if ($neto0Col === null) $neto0Col = colFlex($cab, ['neto','0%'], [], ['iva']); // fallback

    // ¿Tiene columnas por alícuota detalladas?
    $detallado = count($ratesCols) > 0;

    $fkContacto = $esVentas ? 'cliente_id' : 'proveedor_id';
    $tablaCab   = $esVentas ? 'ventas' : 'compras';
    $tablaDet   = $esVentas ? 'venta_alicuotas' : 'compra_alicuotas';
    $fkDet      = $esVentas ? 'venta_id' : 'compra_id';

    if ($esVentas) {
        $insCab = $pdo->prepare("INSERT INTO $tablaCab
            (empresa_id,$fkContacto,comprobante_tipo,comprobante_letra,punto_venta,numero,fecha,
             monto_no_gravado,monto_exento,otros_tributos,cae,moneda,tipo_cambio,actividad,actividad_codigo)
            VALUES (:e,:c,:ct,:cl,:pv,:num,:f,:ng,:ex,:ot,:cae,:mon,:tc,:act,:actcod)");
    } else {
        $insCab = $pdo->prepare("INSERT INTO $tablaCab
            (empresa_id,$fkContacto,comprobante_tipo,comprobante_letra,punto_venta,numero,fecha,
             monto_no_gravado,monto_exento,otros_tributos,cae,moneda,tipo_cambio)
            VALUES (:e,:c,:ct,:cl,:pv,:num,:f,:ng,:ex,:ot,:cae,:mon,:tc)");
    }
    $insDet = $pdo->prepare("INSERT INTO $tablaDet ($fkDet,alicuota,monto_gravado,iva) VALUES (:id,:a,:g,:iva)");

    $importados=0; $duplicados=0; $omitidos=0; $contactosNuevos=0; $detalleErrores=[];

    for ($r = $inicio; $r < count($filas); $r++) {
        $f   = $filas[$r];
        $get = fn($k) => ($ix[$k] !== null && isset($f[$ix[$k]])) ? trim((string)$f[$ix[$k]]) : '';
        if ($get('pv') === '' && $get('fecha') === '') continue;

        // Determinar tipo y letra del comprobante
        $codStr = trim($get('tipo'));
        if (preg_match('/^\s*(\d+)/', $codStr, $m)) $codStr = $m[1];
        if (!isset($codigos[$codStr])) {
            $omitidos++;
            $detalleErrores[] = "Fila ".($r+1).": tipo '".trim($get('tipo'))."' no reconocido.";
            continue;
        }
        $compTipo  = $codigos[$codStr]['tipo'];
        $compLetra = $codigos[$codStr]['letra'];

        $pv  = (int)preg_replace('/\D/', '', $get('pv'));
        $num = (int)preg_replace('/\D/', '', $get('nro'));

        $chk = $pdo->prepare("SELECT 1 FROM $tablaCab WHERE empresa_id=:e AND comprobante_tipo=:t AND COALESCE(punto_venta,0)=CAST(:pv AS INTEGER) AND COALESCE(numero,0)=CAST(:n AS INTEGER)");
        $chk->execute([':e'=>$empresaId,':t'=>$compTipo,':pv'=>$pv,':n'=>$num]);
        if ($chk->fetchColumn()) { $duplicados++; continue; }

        // Contacto
        $doc    = $get('doc'); if ($doc === '0' || $doc === '') $doc = '';
        $nombre = $get('nombre'); if ($nombre === '') $nombre = 'Consumidor Final';
        if ($doc !== '') {
            $st = $pdo->prepare("SELECT id FROM contactos WHERE documento=:d"); $st->execute([':d'=>$doc]);
            $cid = $st->fetchColumn();
            if (!$cid) {
                $pdo->prepare("INSERT INTO contactos (nombre,documento,tipo_contribuyente) VALUES (:n,:d,'Responsable Inscripto')")->execute([':n'=>$nombre,':d'=>$doc]);
                $cid = $pdo->lastInsertId(); $contactosNuevos++;
            }
        } else {
            $st = $pdo->prepare("SELECT id FROM contactos WHERE nombre=:n AND documento IS NULL"); $st->execute([':n'=>$nombre]);
            $cid = $st->fetchColumn();
            if (!$cid) {
                $pdo->prepare("INSERT INTO contactos (nombre,tipo_contribuyente) VALUES (:n,'Consumidor Final')")->execute([':n'=>$nombre]);
                $cid = $pdo->lastInsertId(); $contactosNuevos++;
            }
        }

        // Importes
        $ngRaw  = num($get('nograv'));
        $exRaw  = num($get('exento'));
        $otRaw  = num($get('otros'));
        $moneda = strtoupper($get('moneda'));
        if (in_array($moneda, ['PES','$','PESOS',''], true)) $moneda = 'ARS';
        $tc = num($get('tc')) ?: 1.0;
        $caeVal = $get('cae') ?: null;

        $monto_no_gravado = 0.0;
        $monto_exento     = 0.0;
        $otros_tributos   = $otRaw;
        $lineas           = [];

        if (!$esVentas && $compLetra === 'B') {
            /*
             * Compras Factura B: el emisor (proveedor RI) cobra IVA dentro del precio total.
             * Desde el punto de vista del comprador NO se puede tomar crédito fiscal.
             * Registramos todo en "no gravado".
             * El total viene en: "Imp. Total" o suma de todo lo que hay en la fila.
             */
            $total = num($get('imptot'));
            if ($total == 0) {
                // Fallback: sumar todo lo que haya en la fila
                $total = $ngRaw + $exRaw + $otRaw;
                if ($detallado) {
                    foreach ($ratesCols as $rc) {
                        $total += ($rc['neto'] !== null ? num($f[$rc['neto']] ?? '') : 0.0);
                        $total += ($rc['iva']  !== null ? num($f[$rc['iva']]  ?? '') : 0.0);
                    }
                } else {
                    $total += num($get('netotot')) + num($get('ivatot'));
                }
            }
            $monto_no_gravado = $total;
            $otros_tributos   = 0.0; // ya está incluido en el total

        } elseif (!$esVentas && $compLetra === 'C') {
            /*
             * Compras Factura C:
             * - Proveedor Monotributista → no alcanzado → monto_no_gravado
             * - Proveedor RI Exento      → exento       → monto_exento
             */
            $total = num($get('imptot'));
            if ($total == 0) {
                $total = $ngRaw + $exRaw + $otRaw + num($get('netotot')) + num($get('ivatot'));
            }
            $stTipo = $pdo->prepare("SELECT tipo_contribuyente FROM contactos WHERE id=:id");
            $stTipo->execute([':id'=>$cid]);
            $tipoCtrib = strtolower((string)$stTipo->fetchColumn());
            if (str_contains($tipoCtrib, 'exento')) {
                $monto_exento = $total;
            } else {
                $monto_no_gravado = $total;
            }
            $otros_tributos = 0.0;

        } else {
            /*
             * Facturas A (ventas y compras), Tickets Factura A, y todas las ventas:
             * Registramos con el detalle de alícuotas.
             */
            $monto_no_gravado = $ngRaw;
            $monto_exento     = $exRaw;

            if ($detallado) {
                foreach ($ratesCols as $rc) {
                    $neto = $rc['neto'] !== null ? num($f[$rc['neto']] ?? '') : 0.0;
                    $iva  = $rc['iva']  !== null ? num($f[$rc['iva']]  ?? '') : 0.0;
                    // Si no encontramos neto por columna de alícuota, intentar con neto total
                    if ($neto == 0 && $iva > 0 && $ix['netotot'] !== null) {
                        // Calcular neto desde IVA usando la tasa conocida
                        $neto = round($iva / ($rc['val'] / 100), 2);
                    }
                    if ($neto > 0 || $iva > 0) $lineas[] = ['a'=>$rc['val'],'g'=>$neto,'iva'=>$iva];
                }
                // Neto al 0%
                if ($neto0Col !== null) {
                    $n0 = num($f[$neto0Col] ?? '');
                    if ($n0 > 0) $lineas[] = ['a'=>0.0,'g'=>$n0,'iva'=>0.0];
                }
                // Si no hubo ninguna línea, usar neto total con alícuota inferida
                if (!$lineas && $ix['netotot'] !== null) {
                    $netoT = num($f[$ix['netotot']] ?? '');
                    $ivaT  = $ix['ivatot'] !== null ? num($f[$ix['ivatot']] ?? '') : 0.0;
                    if ($netoT > 0) {
                        $alic = 0.0;
                        if ($ivaT > 0 && $netoT > 0) {
                            $rate = $ivaT / $netoT * 100;
                            $alic = 21.0; $bd = PHP_FLOAT_MAX;
                            foreach ([21.0,27.0,10.5,5.0,2.5] as $c) { $d=abs($c-$rate); if($d<$bd){$bd=$d;$alic=$c;} }
                        }
                        $lineas[] = ['a'=>$alic,'g'=>$netoT,'iva'=>$ivaT];
                    }
                }
            } else {
                // Modo simple: un solo neto total
                $netoT = $ix['netotot'] !== null ? num($f[$ix['netotot']] ?? '') : 0.0;
                $ivaT  = $ix['ivatot']  !== null ? num($f[$ix['ivatot']]  ?? '') : 0.0;
                if ($netoT > 0) {
                    $alic = 0.0;
                    if ($ivaT > 0) {
                        $rate = $ivaT / $netoT * 100; $alic = 21.0; $bd = PHP_FLOAT_MAX;
                        foreach ([21.0,27.0,10.5,5.0,2.5] as $c) { $d=abs($c-$rate); if($d<$bd){$bd=$d;$alic=$c;} }
                    }
                    $lineas[] = ['a'=>$alic,'g'=>$netoT,'iva'=>$ivaT];
                }
            }
        }

        // INSERT cabecera
        $params = [':e'=>$empresaId,':c'=>$cid,':ct'=>$compTipo,':cl'=>$compLetra,
            ':pv'=>$pv?:null,':num'=>$num?:null,':f'=>fecha($get('fecha')),
            ':ng'=>$monto_no_gravado,':ex'=>$monto_exento,':ot'=>$otros_tributos,
            ':cae'=>$caeVal,':mon'=>$moneda,':tc'=>$tc];
        if ($esVentas) { $params[':act'] = $actDesc ?: null; $params[':actcod'] = $actCodigo ?: null; }
        $insCab->execute($params);
        $nuevoId = (int)$pdo->lastInsertId();
        foreach ($lineas as $l) $insDet->execute([':id'=>$nuevoId,':a'=>$l['a'],':g'=>$l['g'],':iva'=>$l['iva']]);
        $importados++;
    }
    return compact('importados','duplicados','omitidos','contactosNuevos','detalleErrores');
}

/* ---- PASO 2: guardar clasificaciones e importar ---- */
if ($paso === '2' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresaId  = (int)($_POST['empresa_id'] ?? 0);
    $actCodigo  = $_POST['actividad_codigo'] ?? '';
    $actDesc    = $_POST['actividad_desc']   ?? '';
    $csvData    = $_POST['csv_data']         ?? '';
    $nuevasClas = $_POST['clas']             ?? [];

    $stUpsert = $pdo->prepare("INSERT INTO empresa_proveedor (empresa_id,contacto_id,clasificacion)
        VALUES (:eid,:cid,:clas)
        ON CONFLICT(empresa_id,contacto_id) DO UPDATE SET clasificacion=excluded.clasificacion");
    foreach ($nuevasClas as $cid => $clas) {
        if (in_array($clas, ['servicio','bien_cambio','bien_uso'], true)) {
            $stUpsert->execute([':eid'=>$empresaId,':cid'=>(int)$cid,':clas'=>$clas]);
        }
    }
    $tmpPath = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($tmpPath, base64_decode($csvData));
    $parsed = parsearCSV($tmpPath);
    unlink($tmpPath);
    if (!$parsed['cab']) {
        $errores[] = 'Error al releer el CSV.';
    } else {
        try {
            $pdo->beginTransaction();
            $resultado = importarFilas($pdo,$parsed['cab'],$parsed['filas'],$parsed['inicio'],$codigos,$empresaId,false,'','');
            $pdo->commit();
        } catch (PDOException $e) { $pdo->rollBack(); $errores[] = 'Error: '.$e->getMessage(); }
    }
}

/* ---- PASO 1: recibir form inicial ---- */
if ($paso === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresaId  = filter_input(INPUT_POST,'empresa_id',FILTER_VALIDATE_INT);
    $tipoImport = $_POST['tipo'] ?? '';
    $esVentas   = ($tipoImport === 'ventas');
    $actCodigo  = trim($_POST['actividad_codigo'] ?? '');
    $actDesc    = trim($_POST['actividad_desc']   ?? '');

    if (!$empresaId)                                       $errores[] = 'Elegí la empresa.';
    if (!in_array($tipoImport,['ventas','compras'],true))  $errores[] = 'Elegí el tipo.';
    if ($esVentas && $actDesc === '')                      $errores[] = 'Elegí la actividad de estas ventas.';
    if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) $errores[] = 'Subí un archivo CSV.';
    if ($empresaId) {
        $chkEmp = $pdo->prepare("SELECT id FROM empresas WHERE id=:id AND usuario_id=:uid");
        $chkEmp->execute([':id'=>$empresaId,':uid'=>$uid]);
        if (!$chkEmp->fetch()) $errores[] = 'Empresa no válida.';
    }
    if (!$errores) {
        $parsed = parsearCSV($_FILES['archivo']['tmp_name']);
        if (!$parsed['cab']) {
            $errores[] = 'No encontré los encabezados de ARCA (falta "Punto de Venta").';
        } elseif ($esVentas) {
            try {
                $pdo->beginTransaction();
                $resultado = importarFilas($pdo,$parsed['cab'],$parsed['filas'],$parsed['inicio'],$codigos,$empresaId,true,$actDesc,$actCodigo);
                $pdo->commit();
            } catch (PDOException $e) { $pdo->rollBack(); $errores[] = 'Error: '.$e->getMessage(); }
        } else {
            // Compras: detectar proveedores sin clasificar
            $ixDoc    = col($parsed['cab'], ['nro. doc']);
            $ixNombre = col($parsed['cab'], ['denominacion']);
            $ixPv     = col($parsed['cab'], ['punto de venta']);
            $proveedoresEnCSV = [];
            for ($r = $parsed['inicio']; $r < count($parsed['filas']); $r++) {
                $f = $parsed['filas'][$r];
                $doc    = $ixDoc    !== null ? trim((string)($f[$ixDoc]    ?? '')) : '';
                $nombre = $ixNombre !== null ? trim((string)($f[$ixNombre] ?? '')) : 'Consumidor Final';
                $pv     = $ixPv     !== null ? trim((string)($f[$ixPv]     ?? '')) : '';
                if ($pv === '' && $doc === '' && $nombre === '') continue;
                if ($doc === '0') $doc = '';
                if ($nombre === '') $nombre = 'Consumidor Final';
                $key = $doc !== '' ? "doc:$doc" : "nom:$nombre";
                $proveedoresEnCSV[$key] = ['doc'=>$doc,'nombre'=>$nombre];
            }
            foreach ($proveedoresEnCSV as $key => $prov) {
                if ($prov['doc'] !== '') {
                    $st = $pdo->prepare("SELECT id FROM contactos WHERE documento=:d"); $st->execute([':d'=>$prov['doc']]);
                } else {
                    $st = $pdo->prepare("SELECT id FROM contactos WHERE nombre=:n AND documento IS NULL"); $st->execute([':n'=>$prov['nombre']]);
                }
                $cid = $st->fetchColumn();
                if ($cid) {
                    $stClas = $pdo->prepare("SELECT clasificacion FROM empresa_proveedor WHERE empresa_id=:eid AND contacto_id=:cid");
                    $stClas->execute([':eid'=>$empresaId,':cid'=>$cid]);
                    if (!$stClas->fetchColumn()) $proveedoresSinClas[] = ['id'=>$cid,'nombre'=>$prov['nombre'],'doc'=>$prov['doc']];
                } else {
                    $proveedoresSinClas[] = ['id'=>null,'nombre'=>$prov['nombre'],'doc'=>$prov['doc']];
                }
            }
            if ($proveedoresSinClas) {
                $csvBase64   = base64_encode(file_get_contents($_FILES['archivo']['tmp_name']));
                $paso1EmpId  = $empresaId; $paso1ActCod = $actCodigo; $paso1ActDesc = $actDesc;
            } else {
                try {
                    $pdo->beginTransaction();
                    $resultado = importarFilas($pdo,$parsed['cab'],$parsed['filas'],$parsed['inicio'],$codigos,$empresaId,false,'','');
                    $pdo->commit();
                } catch (PDOException $e) { $pdo->rollBack(); $errores[] = 'Error: '.$e->getMessage(); }
            }
        }
    }
}

$titulo = 'Importar';
require __DIR__ . '/includes/header.php';
?>
<h2>Importar comprobantes de ARCA</h2>
<p>Subí el archivo de <strong>Mis Comprobantes</strong> (Emitidos o Recibidos) de ARCA, guardado como <strong>CSV</strong>.</p>

<?php if ($errores): ?><ul class="errores"><?php foreach($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($resultado): ?>
    <p class="ok">Importados: <strong><?= $resultado['importados'] ?></strong> · Duplicados omitidos: <?= $resultado['duplicados'] ?> · No reconocidos: <?= $resultado['omitidos'] ?> · Contactos creados: <?= $resultado['contactosNuevos'] ?></p>
    <?php if ($resultado['detalleErrores']): ?><ul class="errores"><?php foreach(array_slice($resultado['detalleErrores'],0,10) as $d): ?><li><?= htmlspecialchars($d) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>

<?php if ($proveedoresSinClas): ?>
<div class="formulario" style="max-width:700px;">
    <h3>Clasificar proveedores</h3>
    <p>Asigná una clasificación a cada proveedor antes de importar. La próxima vez que importes compras de los mismos proveedores no te lo vuelve a pedir.</p>
    <form method="post">
        <input type="hidden" name="paso"             value="2">
        <input type="hidden" name="empresa_id"       value="<?= (int)$paso1EmpId ?>">
        <input type="hidden" name="actividad_codigo" value="<?= htmlspecialchars($paso1ActCod) ?>">
        <input type="hidden" name="actividad_desc"   value="<?= htmlspecialchars($paso1ActDesc) ?>">
        <input type="hidden" name="csv_data"         value="<?= htmlspecialchars($csvBase64) ?>">
        <table>
            <thead><tr><th>Proveedor</th><th>CUIT</th><th>Clasificación</th></tr></thead>
            <tbody>
            <?php foreach ($proveedoresSinClas as $pv):
                $cid = $pv['id'];
                if (!$cid) {
                    if ($pv['doc'] !== '') {
                        $pdo->prepare("INSERT OR IGNORE INTO contactos (nombre,documento,tipo_contribuyente) VALUES (:n,:d,'Responsable Inscripto')")->execute([':n'=>$pv['nombre'],':d'=>$pv['doc']]);
                        $st = $pdo->prepare("SELECT id FROM contactos WHERE documento=:d"); $st->execute([':d'=>$pv['doc']]);
                    } else {
                        $pdo->prepare("INSERT OR IGNORE INTO contactos (nombre,tipo_contribuyente) VALUES (:n,'Consumidor Final')")->execute([':n'=>$pv['nombre']]);
                        $st = $pdo->prepare("SELECT id FROM contactos WHERE nombre=:n AND documento IS NULL"); $st->execute([':n'=>$pv['nombre']]);
                    }
                    $cid = $st->fetchColumn();
                }
            ?>
                <tr>
                    <td><?= htmlspecialchars($pv['nombre']) ?></td>
                    <td><?= htmlspecialchars($pv['doc'] ?: '-') ?></td>
                    <td>
                        <select name="clas[<?= (int)$cid ?>]" required>
                            <option value="">Elegí...</option>
                            <option value="servicio">Prestación de servicio</option>
                            <option value="bien_cambio">Bienes de cambio</option>
                            <option value="bien_uso">Bienes de uso</option>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <br>
        <button type="submit" class="btn btn-guardar">Guardar y continuar importación</button>
        <a href="/importar.php" class="btn">Cancelar</a>
    </form>
</div>
<?php elseif (!$resultado): ?>

<?php if (!$empresas): ?>
    <p>Primero cargá una empresa en <a href="/empresas.php">Empresas</a>.</p>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="formulario">
    <input type="hidden" name="paso" value="1">
    <label>Empresa
        <select name="empresa_id" id="sel-empresa" required>
            <option value="">Elegí...</option>
            <?php foreach($empresas as $em): ?><option value="<?= $em['id'] ?>"><?= htmlspecialchars($em['nombre']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label>Tipo
        <select name="tipo" id="sel-tipo">
            <option value="ventas">Ventas (Emitidos)</option>
            <option value="compras">Compras (Recibidos)</option>
        </select>
    </label>
    <div id="bloque-actividad" style="grid-column:1/-1;">
        <label>Actividad de estas ventas
            <select name="actividad_codigo" id="sel-actividad"><option value="">Elegí empresa primero...</option></select>
            <input type="hidden" name="actividad_desc" id="hid-actdesc">
        </label>
    </div>
    <label>Archivo CSV <input type="file" name="archivo" accept=".csv" required></label>
    <button type="submit" class="btn btn-guardar">Importar</button>
</form>
<script>
(function(){
    var actsPorEmp=<?= json_encode($todasActividades) ?>;
    var selEmp=document.getElementById('sel-empresa'),selTipo=document.getElementById('sel-tipo');
    var selAct=document.getElementById('sel-actividad'),hidDesc=document.getElementById('hid-actdesc');
    var bloqAct=document.getElementById('bloque-actividad');
    function updActs(){ var a=actsPorEmp[selEmp.value]||[]; selAct.innerHTML=''; if(!a.length){selAct.innerHTML='<option value="">Sin actividades cargadas</option>';}else{a.forEach(function(x){var o=document.createElement('option');o.value=x.codigo||'';o.textContent=(x.codigo?x.codigo+' - ':'')+x.descripcion;o.dataset.desc=x.descripcion;selAct.appendChild(o);});} updDesc(); }
    function updDesc(){ var o=selAct.options[selAct.selectedIndex]; hidDesc.value=o?(o.dataset.desc||o.textContent):''; }
    function updBloques(){ bloqAct.style.display=selTipo.value==='ventas'?'':'none'; }
    selEmp.addEventListener('change',updActs); selAct.addEventListener('change',updDesc); selTipo.addEventListener('change',updBloques);
    updBloques(); updActs();
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>