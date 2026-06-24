<?php
echo "PHP version: " . phpversion() . "<br>";
echo "SQLite: " . (extension_loaded('pdo_sqlite') ? 'SI' : 'NO') . "<br>";
echo "MySQL: " . (extension_loaded('pdo_mysql') ? 'SI' : 'NO') . "<br>";
echo "Sessions: " . (function_exists('session_start') ? 'SI' : 'NO') . "<br>";
phpinfo();