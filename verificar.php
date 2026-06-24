<?php
require __DIR__ . '/config/db.php';
$tablas = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll();
echo "<h3>Tablas creadas:</h3><ul>";
foreach ($tablas as $t) { echo "<li>" . $t['name'] . "</li>"; }
echo "</ul>FK activas: " . $pdo->query("PRAGMA foreign_keys")->fetchColumn();