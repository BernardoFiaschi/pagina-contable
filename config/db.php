<?php
declare(strict_types=1);
$pdo = new PDO('sqlite:' . __DIR__ . '/contable.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec("CREATE TABLE IF NOT EXISTS empresas (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, documento TEXT UNIQUE, tipo_contribuyente TEXT NOT NULL, actividad TEXT, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS contactos (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, documento TEXT UNIQUE, tipo_contribuyente TEXT NOT NULL, actividad TEXT, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS ventas (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL, cliente_id INTEGER NOT NULL, comprobante_tipo TEXT NOT NULL, comprobante_letra TEXT, punto_venta INTEGER, numero INTEGER, fecha TEXT NOT NULL, actividad TEXT, monto_no_gravado REAL NOT NULL DEFAULT 0, monto_exento REAL NOT NULL DEFAULT 0, otros_tributos REAL NOT NULL DEFAULT 0, cae TEXT, moneda TEXT NOT NULL DEFAULT 'ARS', tipo_cambio REAL NOT NULL DEFAULT 1, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (empresa_id) REFERENCES empresas(id), FOREIGN KEY (cliente_id) REFERENCES contactos(id))");
$pdo->exec("CREATE TABLE IF NOT EXISTS venta_alicuotas (id INTEGER PRIMARY KEY AUTOINCREMENT, venta_id INTEGER NOT NULL, alicuota REAL NOT NULL, monto_gravado REAL NOT NULL DEFAULT 0, iva REAL NOT NULL DEFAULT 0, FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE)");
$pdo->exec("CREATE TABLE IF NOT EXISTS compras (id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL, proveedor_id INTEGER NOT NULL, comprobante_tipo TEXT NOT NULL, comprobante_letra TEXT, punto_venta INTEGER, numero INTEGER, fecha TEXT NOT NULL, actividad TEXT, monto_no_gravado REAL NOT NULL DEFAULT 0, monto_exento REAL NOT NULL DEFAULT 0, otros_tributos REAL NOT NULL DEFAULT 0, cae TEXT, moneda TEXT NOT NULL DEFAULT 'ARS', tipo_cambio REAL NOT NULL DEFAULT 1, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (empresa_id) REFERENCES empresas(id), FOREIGN KEY (proveedor_id) REFERENCES contactos(id))");
$pdo->exec("CREATE TABLE IF NOT EXISTS compra_alicuotas (id INTEGER PRIMARY KEY AUTOINCREMENT, compra_id INTEGER NOT NULL, alicuota REAL NOT NULL, monto_gravado REAL NOT NULL DEFAULT 0, iva REAL NOT NULL DEFAULT 0, FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE)");

/*
 * Migración liviana: agrega columnas nuevas a 'empresas' si todavía no existen.
 * CREATE TABLE IF NOT EXISTS no agrega columnas a una tabla ya creada, así que
 * para bases viejas usamos ALTER TABLE ADD COLUMN (solo si falta la columna).
 */
function columnaExiste(PDO $pdo, string $tabla, string $col): bool {
    foreach ($pdo->query("PRAGMA table_info($tabla)")->fetchAll() as $c) {
        if ($c['name'] === $col) { return true; }
    }
    return false;
}
if (!columnaExiste($pdo, 'empresas', 'actividad_codigo')) {
    $pdo->exec("ALTER TABLE empresas ADD COLUMN actividad_codigo TEXT");
}
if (!columnaExiste($pdo, 'empresas', 'iibb_alicuota')) {
    $pdo->exec("ALTER TABLE empresas ADD COLUMN iibb_alicuota REAL");
}

/*
 * --- Migraciones para multiusuario, actividades múltiples y clasificación ---
 */
// Tabla de usuarios (login).
$pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// Actividades de cada empresa (una empresa puede tener varias).
$pdo->exec("CREATE TABLE IF NOT EXISTS empresa_actividades (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    empresa_id INTEGER NOT NULL,
    codigo TEXT,
    descripcion TEXT NOT NULL,
    iibb_alicuota REAL,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
)");

// usuario_id en empresas y contactos: separa los datos por usuario.
if (!columnaExiste($pdo, 'empresas',  'usuario_id'))      { $pdo->exec("ALTER TABLE empresas  ADD COLUMN usuario_id INTEGER"); }
if (!columnaExiste($pdo, 'contactos', 'usuario_id'))      { $pdo->exec("ALTER TABLE contactos ADD COLUMN usuario_id INTEGER"); }
// código de actividad por venta (para IIBB por actividad).
if (!columnaExiste($pdo, 'ventas',    'actividad_codigo')){ $pdo->exec("ALTER TABLE ventas   ADD COLUMN actividad_codigo TEXT"); }
// clasificación de la compra: servicio / bien_cambio / bien_uso.
if (!columnaExiste($pdo, 'compras',   'clasificacion'))   { $pdo->exec("ALTER TABLE compras  ADD COLUMN clasificacion TEXT"); }

// Clasificacion de un proveedor para una empresa especifica.
$pdo->exec("CREATE TABLE IF NOT EXISTS empresa_proveedor (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    empresa_id INTEGER NOT NULL,
    contacto_id INTEGER NOT NULL,
    clasificacion TEXT NOT NULL,
    UNIQUE(empresa_id, contacto_id),
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE CASCADE
)");

// Tabla de percepciones y retenciones (IVA, Ganancias, IIBB)
$pdo->exec("CREATE TABLE IF NOT EXISTS retenciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    empresa_id INTEGER NOT NULL,
    tipo TEXT NOT NULL,       -- 'percepcion_iibb' | 'retencion_iibb' | 'retencion_bancaria_iibb'
                              -- | 'percepcion_iva' | 'percepcion_ganancias' | 'retencion_ganancias'
    subtipo TEXT,             -- 'percepcion' o 'retencion' (de la descripcion del XLS)
    cuit_agente TEXT,
    nombre_agente TEXT,
    fecha TEXT NOT NULL,
    nro_certificado TEXT,
    comprobante_tipo TEXT,    -- FA, CA, etc (para P.txt)
    punto_venta INTEGER,
    numero INTEGER,
    importe REAL NOT NULL,
    compra_id INTEGER,        -- FK si se linkeo a una compra
    venta_id INTEGER,         -- FK si se linkeo a una venta
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE SET NULL,
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE SET NULL
)");

// Columna percepciones/retenciones en compras y ventas (totales del comprobante)
if (!columnaExiste($pdo,'compras','percepcion_iva'))      { $pdo->exec("ALTER TABLE compras ADD COLUMN percepcion_iva REAL DEFAULT 0"); }
if (!columnaExiste($pdo,'compras','percepcion_ganancias')){ $pdo->exec("ALTER TABLE compras ADD COLUMN percepcion_ganancias REAL DEFAULT 0"); }
if (!columnaExiste($pdo,'compras','percepcion_iibb'))     { $pdo->exec("ALTER TABLE compras ADD COLUMN percepcion_iibb REAL DEFAULT 0"); }
if (!columnaExiste($pdo,'ventas','retencion_iva'))        { $pdo->exec("ALTER TABLE ventas ADD COLUMN retencion_iva REAL DEFAULT 0"); }
if (!columnaExiste($pdo,'ventas','retencion_ganancias'))  { $pdo->exec("ALTER TABLE ventas ADD COLUMN retencion_ganancias REAL DEFAULT 0"); }
if (!columnaExiste($pdo,'ventas','retencion_iibb'))       { $pdo->exec("ALTER TABLE ventas ADD COLUMN retencion_iibb REAL DEFAULT 0"); }