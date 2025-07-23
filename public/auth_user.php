<?php
require_once __DIR__ . '/../config.php';

$code = $_POST['username'] ?? '';
$mac = $_POST['mac'] ?? '';

if (!$code || !$mac) {
    die("Erreur : Données manquantes");
}

$stmt = $pdo->prepare("SELECT * FROM tickets WHERE code = ?");
$stmt->execute([$code]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    die("Ticket invalide");
}

$duree_map = [
    '1h' => 3600,
    '5h' => 18000,
    '24h' => 86400,
    '3j' => 259200,
    '7j' => 604800,    // Correction: 7 jours (1 semaine)
    '30j' => 2592000   // 30 jours (1 mois)
];

// Vérifier si déjà utilisé sur autre appareil
if ($ticket['mac_adresse'] && $ticket['mac_adresse'] !== $mac) {
    die("Ticket déjà utilisé sur un autre appareil (MAC: {$ticket['mac_adresse']})");
}

// Première activation
if (!$ticket['date_activation']) {
    $expires_at = date('Y-m-d H:i:s', time() + 86400); // Expire dans 24h
    
    $stmt = $pdo->prepare("UPDATE tickets SET 
        utilise = 1, 
        date_activation = NOW(), 
        expires_at = ?,
        mac_adresse = ?,
        temps_restant = ? 
        WHERE code = ?");
    
    $duree_secondes = $duree_map[$ticket['duree']];
    $stmt->execute([
        $expires_at,
        $mac,
        $duree_secondes,
        $code
    ]);
}

// Vérifier expiration (24h après activation)
if (strtotime($ticket['expires_at']) < time()) {
    die("Ticket expiré");
}

// Vérifier temps restant
if ($ticket['temps_restant'] <= 0) {
    die("Temps de connexion épuisé");
}

// Authentification MikroTik
// ... code pour autoriser la connexion dans le hotspot ...

echo "Connexion réussie!";