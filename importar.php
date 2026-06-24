<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

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
// Convierte a UTF-8 si hace falta (ARCA suele venir en Windows-1252). Funciona con o sin mbstring/iconv.
function aUtf8(string $s): string {
    if (preg_match('//u', $s) === 1) return $s;                                  // ya es UTF-8 válido
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    if (function_exists('iconv')) return (string)iconv('Windows-1252', 'UTF-8//TRANSLIT', $s);
    $out = '';                                                                   // fallback manual byte a byte
    for ($i = 0, $len = strlen($s); $i < $len; $i++) {
        $c = ord($s[$i]);
        $out .= $c < 0x80 ? $s[$i] : (chr(0xC0 | ($c >> 6)) . chr(0x80 | ($c & 0x3F)));
    }
    return $out;
}
// Índice de columna por substring (cualquier candidato).
function col(array $cab, array $candidatos): ?int {
    foreach ($cab as $i => $h) {
        foreach ($candidatos as $c) { if (strpos(norm($h), norm($c)) !== false) return $i; }
    }
    return null;
}
// Índice de columna por nombre EXACTO (para no confundir "IVA 21%" con "Neto Grav. IVA 21%").
function colExact(array $cab, string $target): ?int {
    $t = norm($target);
    foreach ($cab as $i => $h) { if (norm($h) === $t) return $i; }
    return null;
}

// Tabla oficial de tipos de comprobante de ARCA (los 76 códigos). Ver config/comprobantes.php.
$codigos = require __DIR__ . '/config/comprobantes.php';

$empresas = $pdo->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll();
$resultado = null;
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresaId = filter_input(INPUT_POST, 'empresa_id', FILTER_VALIDATE_INT);
    $tipoImport = $_POST['tipo'] ?? '';
    $esVentas = ($tipoImport === 'ventas');

    if (!$empresaId) { $errores[] = 'Elegí la empresa.'; }
    if (!in_array($tipoImport, ['ventas', 'compras'], true)) { $errores[] = 'Elegí si son ventas o compras.'; }
    if (empty($_FILES['archivo']['tmp_name']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) { $errores[] = 'Subí un archivo CSV.'; }

    if (count($errores) === 0) {
        $contenido = file_get_contents($_FILES['archivo']['tmp_name']);
        // ARCA suele venir en Windows-1252: lo pasamos a UTF-8 para que los acentos coincidan.
        $contenido = aUtf8($contenido);
        $contenido = str_replace("\r\n", "\n", $contenido);
        $primera = strtok($contenido, "\n");
        $sep = (substr_count($primera, ';') >= substr_count($primera, ',')) ? ';' : ',';

        $filas = [];
        foreach (explode("\n", $contenido) as $ln) {
            if (trim($ln) === '') continue;
            $filas[] = str_getcsv($ln, $sep, '"', '');
        }

        $cab = null; $inicio = 0;
        foreach ($filas as $i => $f) {
            if (col($f, ['punto de venta']) !== null) { $cab = $f; $inicio = $i + 1; break; }
        }
        if (!$cab) {
            $errores[] = 'No encontré los encabezados de ARCA (falta "Punto de Venta").';
        } else {
            $ix = [
                'fecha'  => col($cab, ['fecha de emision', 'fecha']),
                'tipo'   => col($cab, ['tipo de comprobante', 'tipo']),
                'pv'     => col($cab, ['punto de venta']),
                'nro'    => col($cab, ['numero desde', 'numero']),
                'cae'    => col($cab, ['autorizacion']),
                'doc'    => col($cab, ['nro. doc']),
                'nombre' => col($cab, ['denominacion']),
                'tc'     => col($cab, ['tipo cambio']),
                'moneda' => col($cab, ['moneda']),
                'nograv' => colExact($cab, 'neto no gravado'),
                'exento' => col($cab, ['op. exentas', 'exenta', 'exento']),
                'otros'  => col($cab, ['otros tributos']),
            ];
            // Columnas por alícuota (formato detallado de ARCA).
            $ratesLabels = ['27'=>27.0, '21'=>21.0, '10,5'=>10.5, '5'=>5.0, '2,5'=>2.5];
            $ratesCols = [];
            foreach ($ratesLabels as $lab => $val) {
                $ivaC = colExact($cab, "iva $lab%");
                $netC = colExact($cab, "neto grav. iva $lab%");
                if ($ivaC !== null || $netC !== null) { $ratesCols[] = ['val'=>$val, 'iva'=>$ivaC, 'neto'=>$netC]; }
            }
            $neto0Col = colExact($cab, 'neto grav. iva 0%');
            $detallado = count($ratesCols) > 0;
            // Fallback simple (export sin desglose por alícuota)
            $netoSimple = col($cab, ['neto gravado total', 'neto gravado']);
            $ivaSimple  = colExact($cab, 'total iva') ?? colExact($cab, 'iva');

            $fkContacto = $esVentas ? 'cliente_id' : 'proveedor_id';
            $tablaCab = $esVentas ? 'ventas' : 'compras';
            $tablaDet = $esVentas ? 'venta_alicuotas' : 'compra_alicuotas';
            $fkDet    = $esVentas ? 'venta_id' : 'compra_id';

            $importados=0; $duplicados=0; $omitidos=0; $contactosNuevos=0; $detalleErrores=[];

            try {
                $pdo->beginTransaction();
                $insCab = $pdo->prepare("INSERT INTO $tablaCab (empresa_id, $fkContacto, comprobante_tipo, comprobante_letra, punto_venta, numero, fecha, monto_no_gravado, monto_exento, otros_tributos, cae, moneda, tipo_cambio)
                    VALUES (:e,:c,:ct,:cl,:pv,:num,:f,:ng,:ex,:ot,:cae,:mon,:tc)");
                $insDet = $pdo->prepare("INSERT INTO $tablaDet ($fkDet, alicuota, monto_gravado, iva) VALUES (:id,:a,:g,:iva)");

                for ($r = $inicio; $r < count($filas); $r++) {
                    $f = $filas[$r];
                    $get = fn($k) => ($ix[$k] !== null && isset($f[$ix[$k]])) ? $f[$ix[$k]] : '';
                    if (trim($get('pv')) === '' && trim($get('fecha')) === '') { continue; }

                    $cod = trim($get('tipo'));
                    if (preg_match('/^\s*(\d+)/', $cod, $m)) { $cod = $m[1]; }
                    if (!isset($codigos[$cod])) { $omitidos++; $detalleErrores[] = "Fila ".($r+1).": tipo ".trim($get('tipo'))." no reconocido."; continue; }
                        $compTipo  = $codigos[$cod]['tipo'];
                        $compLetra = $codigos[$cod]['letra'];

                    $pv  = (int)preg_replace('/\D/', '', $get('pv'));
                    $num = (int)preg_replace('/\D/', '', $get('nro'));

                    // Anti-duplicados. CAST a INTEGER: COALESCE le saca la afinidad de tipo a la columna en SQLite.
                    $chk = $pdo->prepare("SELECT 1 FROM $tablaCab WHERE empresa_id=:e AND comprobante_tipo=:t AND COALESCE(punto_venta,0)=CAST(:pv AS INTEGER) AND COALESCE(numero,0)=CAST(:n AS INTEGER)");
                    $chk->execute([':e'=>$empresaId, ':t'=>$compTipo, ':pv'=>$pv, ':n'=>$num]);
                    if ($chk->fetchColumn()) { $duplicados++; continue; }

                    // Contacto. Doc "0" o vacío => consumidor final.
                    $doc = trim($get('doc')); if ($doc === '0') { $doc = ''; }
                    $nombre = trim($get('nombre')); if ($nombre === '') { $nombre = 'Consumidor Final'; }
                    if ($doc !== '') {
                        $st = $pdo->prepare("SELECT id FROM contactos WHERE documento=:d"); $st->execute([':d'=>$doc]);
                        $cid = $st->fetchColumn();
                        if (!$cid) { $pdo->prepare("INSERT INTO contactos (nombre,documento,tipo_contribuyente) VALUES (:n,:d,'Responsable Inscripto')")->execute([':n'=>$nombre,':d'=>$doc]); $cid=$pdo->lastInsertId(); $contactosNuevos++; }
                    } else {
                        $st = $pdo->prepare("SELECT id FROM contactos WHERE nombre=:n AND documento IS NULL"); $st->execute([':n'=>$nombre]);
                        $cid = $st->fetchColumn();
                        if (!$cid) { $pdo->prepare("INSERT INTO contactos (nombre,tipo_contribuyente) VALUES (:n,'Consumidor Final')")->execute([':n'=>$nombre]); $cid=$pdo->lastInsertId(); $contactosNuevos++; }
                    }

                    // Líneas de alícuota
                    $lineas = [];
                    if ($detallado) {
                        foreach ($ratesCols as $rc) {
                            $neto = $rc['neto'] !== null ? num($f[$rc['neto']] ?? '') : 0.0;
                            $iva  = $rc['iva']  !== null ? num($f[$rc['iva']]  ?? '') : 0.0;
                            if ($neto > 0 || $iva > 0) { $lineas[] = ['a'=>$rc['val'], 'g'=>$neto, 'iva'=>$iva]; }
                        }
                        if ($neto0Col !== null) { $n0 = num($f[$neto0Col] ?? ''); if ($n0 > 0) { $lineas[] = ['a'=>0.0, 'g'=>$n0, 'iva'=>0.0]; } }
                    } else {
                        $neto = $netoSimple !== null ? num($f[$netoSimple] ?? '') : 0.0;
                        $iva  = $ivaSimple  !== null ? num($f[$ivaSimple]  ?? '') : 0.0;
                        if ($neto > 0) {
                            $alic = 0.0;
                            if ($iva > 0) { $rate=$iva/$neto*100; $best=21.0;$bd=INF; foreach([21.0,27.0,10.5] as $c){$d=abs($c-$rate);if($d<$bd){$bd=$d;$best=$c;}} $alic=$best; }
                            $lineas[] = ['a'=>$alic, 'g'=>$neto, 'iva'=>$iva];
                        }
                    }

                    $monedaRaw = strtoupper(trim($get('moneda')));
                    $moneda = in_array($monedaRaw, ['PES','$','PESOS',''], true) ? 'ARS' : $monedaRaw;
                    $tc = num($get('tc')) ?: 1.0;

                    $insCab->execute([
                        ':e'=>$empresaId, ':c'=>$cid, ':ct'=>$compTipo, ':cl'=>$compLetra,
                        ':pv'=>$pv ?: null, ':num'=>$num ?: null, ':f'=>fecha($get('fecha')),
                        ':ng'=>num($get('nograv')), ':ex'=>num($get('exento')), ':ot'=>num($get('otros')),
                        ':cae'=>trim($get('cae')) ?: null, ':mon'=>$moneda, ':tc'=>$tc,
                    ]);
                    $nuevoId = (int)$pdo->lastInsertId();
                    foreach ($lineas as $l) { $insDet->execute([':id'=>$nuevoId, ':a'=>$l['a'], ':g'=>$l['g'], ':iva'=>$l['iva']]); }
                    $importados++;
                }
                $pdo->commit();
                $resultado = compact('importados','duplicados','omitidos','contactosNuevos','detalleErrores');
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errores[] = 'Error durante la importación: ' . $e->getMessage();
            }
        }
    }
}

$titulo = 'Importar';
require __DIR__ . '/includes/header.php';
?>
<h2>Importar comprobantes de ARCA</h2>
<p>Subí el archivo de <strong>Mis Comprobantes</strong> (Emitidos o Recibidos) de ARCA, antes tenes que guardarlo como <strong>CSV</strong>.</p>
<?php if ($errores): ?><ul class="errores"><?php foreach ($errores as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($resultado): ?>
    <p class="ok">Importados: <strong><?= $resultado['importados'] ?></strong> · Duplicados omitidos: <?= $resultado['duplicados'] ?> · No reconocidos: <?= $resultado['omitidos'] ?> · Contactos creados: <?= $resultado['contactosNuevos'] ?></p>
    <?php if ($resultado['detalleErrores']): ?><ul class="errores"><?php foreach (array_slice($resultado['detalleErrores'],0,10) as $d): ?><li><?= htmlspecialchars($d) ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?>
<?php if (!$empresas): ?>
    <p>Primero cargá una empresa en <a href="/empresas.php">Empresas</a>.</p>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="formulario">
    <label>Empresa (titular)
        <select name="empresa_id"><option value="">Elegí...</option>
            <?php foreach ($empresas as $em): ?><option value="<?= (int)$em['id'] ?>"><?= htmlspecialchars($em['nombre']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label>Tipo
        <select name="tipo"><option value="ventas">Ventas (Emitidos)</option><option value="compras">Compras (Recibidos)</option></select>
    </label>
    <label>Archivo CSV <input type="file" name="archivo" accept=".csv"></label>
    <button type="submit" class="btn btn-guardar">Importar</button>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>