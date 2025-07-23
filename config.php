<?php
// Démarre la session si ce n'est pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Connexion à PostgreSQL (Render)
$dbHost = 'dpg-d1sdpmfdiees73fg8q3g-a.oregon-postgres.render.com';
$dbPort = '5432';
$dbName = 'mikrotik_tickets';
$dbUser = 'mikrotik_tickets_user';
$dbPass = '4KPIHjGm30GwgSIIVFHVcBvwveVMyGLj';

try {
    $pdo = new PDO("pgsql:host=$dbHost;port=$dbPort;dbname=$dbName", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("❌ Erreur de connexion à la base Render : " . $e->getMessage());
}

// Protection : redirige vers login.html si l'admin n'est pas connecté
$nomFichier = basename($_SERVER['PHP_SELF']);
if (!isset($_SESSION['admin_logged_in']) && !in_array($nomFichier, ['login.php', 'login.html'])) {
    header("Location: login.html");
    exit();
}

// Connexion à MikroTik via API et Zerotier
define('MIKROTIK_HOST', '10.144.152.59'); // IP Zerotier du MikroTik
define('MIKROTIK_USERNAME', 'admin');
define('MIKROTIK_PASSWORD', 'M.sounna2025');
?>
