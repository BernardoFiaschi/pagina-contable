<?php
try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/config/contable.sqlite');
    echo "SQLite OK<br>";
} catch (Throwable $e) {
    echo "ERROR SQLite: " . $e->getMessage();
    die();
}

echo "db sin require OK<br>";

try {
    require_once __DIR__ . '/config/db.php';
    echo "db.php OK<br>";
} catch (Throwable $e) {
    echo "ERROR en db.php: " . $e->getMessage() . " linea " . $e->getLine();
    die();
}

echo "Todo OK";