<?php
/*
 * includes/auth.php
 * -----------------
 * Sesión y autenticación. Lo incluye toda página protegida DESPUÉS de db.php.
 *   requerirLogin() : si no hay sesión activa, manda a /login.php.
 *   usuarioId()     : id del usuario logueado (para filtrar/insertar sus datos).
 *   usuarioNombre() : nombre del usuario logueado (para mostrar en la barra).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requerirLogin(): void {
    if (empty($_SESSION['uid'])) {
        header('Location: /login.php');
        exit;
    }
}
function usuarioId(): int       { return (int)($_SESSION['uid'] ?? 0); }
function usuarioNombre(): string{ return (string)($_SESSION['usuario'] ?? ''); }