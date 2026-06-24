<?php
declare(strict_types=1);
$pdo = new PDO('sqlite:' . __DIR__ . '/contable.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec("CREATE TABLE IF NOT EXISTS empresas (
    id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, documento TEXT UNIQUE,
    tipo_contribuyente TEXT NOT NULL, actividad TEXT, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");

$pdo->exec("CREATE TABLE IF NOT EXISTS contactos (
    id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, documento TEXT UNIQUE,
    tipo_contribuyente TEXT NOT NULL, actividad TEXT, creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");

$pdo->exec("CREATE TABLE IF NOT EXISTS ventas (
    id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL, cliente_id INTEGER NOT NULL,
    comprobante_tipo TEXT NOT NULL, comprobante_letra TEXT, punto_venta INTEGER, numero INTEGER,
    fecha TEXT NOT NULL, actividad TEXT,
    monto_no_gravado REAL NOT NULL DEFAULT 0, monto_exento REAL NOT NULL DEFAULT 0, otros_tributos REAL NOT NULL DEFAULT 0,
    cae TEXT, moneda TEXT NOT NULL DEFAULT 'ARS', tipo_cambio REAL NOT NULL DEFAULT 1,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id), FOREIGN KEY (cliente_id) REFERENCES contactos(id))");

$pdo->exec("CREATE TABLE IF NOT EXISTS venta_alicuotas (
    id INTEGER PRIMARY KEY AUTOINCREMENT, venta_id INTEGER NOT NULL, alicuota REAL NOT NULL,
    monto_gravado REAL NOT NULL DEFAULT 0, iva REAL NOT NULL DEFAULT 0,
    FOREIGN KEY (venta_id) REFERENCES ventas(id) ON DELETE CASCADE)");

$pdo->exec("CREATE TABLE IF NOT EXISTS compras (
    id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER NOT NULL, proveedor_id INTEGER NOT NULL,
    comprobante_tipo TEXT NOT NULL, comprobante_letra TEXT, punto_venta INTEGER, numero INTEGER,
    fecha TEXT NOT NULL, actividad TEXT,
    monto_no_gravado REAL NOT NULL DEFAULT 0, monto_exento REAL NOT NULL DEFAULT 0, otros_tributos REAL NOT NULL DEFAULT 0,
    cae TEXT, moneda TEXT NOT NULL DEFAULT 'ARS', tipo_cambio REAL NOT NULL DEFAULT 1,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id), FOREIGN KEY (proveedor_id) REFERENCES contactos(id))");

$pdo->exec("CREATE TABLE IF NOT EXISTS compra_alicuotas (
    id INTEGER PRIMARY KEY AUTOINCREMENT, compra_id INTEGER NOT NULL, alicuota REAL NOT NULL,
    monto_gravado REAL NOT NULL DEFAULT 0, iva REAL NOT NULL DEFAULT 0,
    FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE)");