<?php
session_start();
// Set the default timezone for PHP to match Niamey, Niger (UTC+1)
date_default_timezone_set('Africa/Niamey');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Query;

// Connexion à la base de données
$pdo = new PDO("pgsql:host=localhost;port=5432;dbname=mikrotik_tickets", "postgres", "4484");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// S'assurer que les colonnes existent et créer la table deleted_tickets si elle n'existe pas
$pdo->exec("
    ALTER TABLE tickets
        ADD COLUMN IF NOT EXISTS mac_autorisee VARCHAR(20),
        ADD COLUMN IF NOT EXISTS used_seconds INTEGER DEFAULT 0,
        ADD COLUMN IF NOT EXISTS last_active TIMESTAMP DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS activated_at TIMESTAMP DEFAULT NULL;

    CREATE TABLE IF NOT EXISTS deleted_tickets (
        id SERIAL PRIMARY KEY,
        username TEXT,
        mac_address TEXT,
        forfait TEXT,
        used_seconds INTEGER,
        expiration_reason TEXT,
        deleted_at TIMESTAMP DEFAULT now(),
        original_generated_at TIMESTAMP DEFAULT NULL,
        original_activated_at TIMESTAMP DEFAULT NULL
    );
");

// Message de débogage
$_SESSION['debug'] = [];

// Fonction de conversion de durée
function convert_duration_to_seconds($duree) {
    $duree = strtolower(trim($duree));
    if (str_contains($duree, 'j')) {
        return (int) filter_var($duree, FILTER_SANITIZE_NUMBER_INT) * 86400;
    } elseif (str_contains($duree, 'h')) {
        return (int) filter_var($duree, FILTER_SANITIZE_NUMBER_INT) * 3600;
    } elseif (str_contains($duree, 'm')) {
        return (int) filter_var($duree, FILTER_SANITIZE_NUMBER_INT) * 60;
    }
    return 0;
}

/**
 * Fonction pour gérer la suppression d'un ticket (unique ou en masse)
 * @param PDO $pdo Connexion PDO
 * @param Client $client Client RouterOS
 * @param int|array $ticket_ids ID(s) du ticket(s) à supprimer
 */
function deleteTicket($pdo, $client, $ticket_ids) {
    if (!is_array($ticket_ids)) {
        $ticket_ids = [$ticket_ids]; // Convertir en tableau si c'est un seul ID
    }

    foreach ($ticket_ids as $id) {
        $_SESSION['debug'][] = "Début suppression ticket ID: $id";

        // 1. Récupérer le code du ticket et les détails pour l'archivage
        $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $ticket_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ticket_to_delete) {
            $code = $ticket_to_delete['code'];
            $_SESSION['debug'][] = "Ticket trouvé - Code: $code";

            // 2a. Déconnecter toutes les sessions actives MikroTik
            $_SESSION['debug'][] = "Recherche sessions actives pour: $code";
            $activeQuery = new Query('/ip/hotspot/active/print');
            $activeQuery->where('user', $code);
            $activeSessions = $client->query($activeQuery)->read();

            if (!empty($activeSessions)) {
                $_SESSION['debug'][] = count($activeSessions) . " session(s) active(s) trouvée(s)";
                foreach ($activeSessions as $session) {
                    $sessionId = $session['.id'];
                    $_SESSION['debug'][] = "Déconnexion session ID: $sessionId";
                    try {
                        $removeSession = new Query('/ip/hotspot/active/remove');
                        $removeSession->equal('.id', $sessionId);
                        $client->query($removeSession)->read();
                        $_SESSION['debug'][] = "Session $sessionId déconnectée!";
                    } catch (Exception $e) {
                        $_SESSION['debug'][] = "❌ Erreur déconnexion session $sessionId: " . $e->getMessage();
                    }
                }
            } else {
                $_SESSION['debug'][] = "Aucune session active trouvée";
            }

            // 2b. Supprimer l'utilisateur Hotspot MikroTik
            $_SESSION['debug'][] = "Recherche utilisateur Hotspot: $code";
            $userQuery = new Query('/ip/hotspot/user/print');
            $userQuery->where('name', $code);
            $userData = $client->query($userQuery)->read();

            if (!empty($userData)) {
                $mikrotikId = $userData[0]['.id'];
                $_SESSION['debug'][] = "Utilisateur trouvé - ID MikroTik: $mikrotikId";
                try {
                    $removeUser = new Query('/ip/hotspot/user/remove');
                    $removeUser->equal('.id', $mikrotikId);
                    $client->query($removeUser)->read();
                    $_SESSION['debug'][] = "Utilisateur $code supprimé de MikroTik!";
                } catch (Exception $e) {
                    $_SESSION['debug'][] = "❌ Erreur suppression utilisateur MikroTik: " . $e->getMessage();
                }
            } else {
                $_SESSION['debug'][] = "Aucun utilisateur trouvé dans MikroTik";
            }

            // 3. Archiver dans deleted_tickets AVANT suppression de 'tickets'
            $archive = $pdo->prepare("
                INSERT INTO deleted_tickets
                    (username, mac_address, forfait, used_seconds, expiration_reason, deleted_at, original_generated_at, original_activated_at)
                VALUES
                    (:username, :mac, :forfait, :used, :reason, NOW(), :generated_at, :activated_at)
            ");
            $archive->execute([
                'username' => $ticket_to_delete['code'],
                'mac' => $ticket_to_delete['mac_autorisee'] ?? '',
                'forfait' => $ticket_to_delete['duree'],
                'used' => $ticket_to_delete['used_seconds'] ?? 0,
                'reason' => 'Suppression manuelle',
                'generated_at' => $ticket_to_delete['generated_at'],
                'activated_at' => $ticket_to_delete['activated_at']
            ]);
            $_SESSION['debug'][] = "Ticket archivé dans deleted_tickets";

        } else {
            $_SESSION['debug'][] = "Aucun ticket trouvé avec ID: $id";
        }

        // 4. Supprimer le ticket de la table 'tickets'
        $_SESSION['debug'][] = "Suppression du ticket en base de données 'tickets'";
        $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = :id");
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['debug'][] = "Ticket supprimé de la base de données 'tickets'!";
        } else {
            $_SESSION['debug'][] = "❌ Échec suppression base de données 'tickets'";
        }
    }
}


try {
    // Connexion à MikroTik
    $_SESSION['debug'][] = "Tentative de connexion à MikroTik...";
    $client = new Client([
        'host' => MIKROTIK_HOST,
        'user' => MIKROTIK_USERNAME,
        'pass' => MIKROTIK_PASSWORD,
        'port' => 8728,
    ]);
    $_SESSION['debug'][] = "Connexion MikroTik réussie!";

    // 🔥 GESTION DE LA SUPPRESSION (manuel via bouton unique ou en masse)
    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        // Suppression d'un seul ticket
        deleteTicket($pdo, $client, (int)$_GET['delete']);
        header("Location: tickets.php"); // Rediriger pour nettoyer l'URL
        exit;
    } elseif (isset($_POST['bulk_delete']) && isset($_POST['selected_tickets']) && is_array($_POST['selected_tickets'])) {
        // Suppression en masse
        if (!empty($_POST['selected_tickets'])) {
            deleteTicket($pdo, $client, array_map('intval', $_POST['selected_tickets']));
        }
        header("Location: tickets.php"); // Rediriger après suppression
        exit;
    }

    // 🔁 Lire les utilisateurs actifs du MikroTik (pour déterminer le statut de connexion)
    $_SESSION['debug'][] = "Lecture des utilisateurs actifs MikroTik...";
    $query = new Query('/ip/hotspot/active/print');
    $connectedUsers = $client->query($query)->read();
    $_SESSION['debug'][] = count($connectedUsers) . " utilisateur(s) actif(s) trouvé(s) sur MikroTik";

    // 🔐 Bloquer toute MAC non autorisée et gérer les sessions ACTUELLES (synchronisation MikroTik -> DB)
    foreach ($connectedUsers as $user) {
        $username = $user['user'] ?? null;
        $mac = $user['mac-address'] ?? null;
        $session_id = $user['.id'] ?? null;
        $uptime = $user['uptime'] ?? '0s';

        if ($username && $mac) {
            $_SESSION['debug'][] = "--- Traitement utilisateur MikroTik: $username (MAC: $mac, Uptime MT: $uptime) ---";
            $stmt = $pdo->prepare("SELECT * FROM tickets WHERE code = :code LIMIT 1");
            $stmt->execute(['code' => $username]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ticket) {
                $_SESSION['debug'][] = "Ticket DB trouvé pour $username (ID: {$ticket['id']})";
                $_SESSION['debug'][] = "DB: used_seconds = {$ticket['used_seconds']}, last_active = {$ticket['last_active']}";

                // Gestion MAC et activation de la date
                if (empty($ticket['mac_autorisee'])) {
                    $updateFields = ['mac_autorisee = :mac'];
                    $params = ['mac' => $mac, 'id' => $ticket['id']];

                    if (empty($ticket['activated_at'])) {
                        $updateFields[] = 'activated_at = NOW()';
                        $updateFields[] = 'last_active = NOW()';
                        $_SESSION['debug'][] = "Première activation: MAC autorisée et activated_at/last_active définis.";
                    } else {
                        $updateFields[] = 'last_active = NOW()'; // Always update last_active if already activated
                        $_SESSION['debug'][] = "MAC autorisée manquante, mais déjà activé: MAC autorisée et last_active définis.";
                    }

                    $update = $pdo->prepare("UPDATE tickets SET " . implode(', ', $updateFields) . " WHERE id = :id");
                    $update->execute($params);
                } elseif (strtolower($ticket['mac_autorisee']) !== strtolower($mac)) {
                    $_SESSION['debug'][] = "❌ MAC non autorisée ($mac vs {$ticket['mac_autorisee']}) pour $username - Déconnexion de MikroTik...";
                    try {
                        $removeQuery = new Query('/ip/hotspot/active/remove');
                        $removeQuery->equal('.id', $session_id);
                        $client->query($removeQuery)->read();
                        // Also remove the user from MikroTik's Hotspot User list to prevent re-connection
                        $userQuery = new Query('/ip/hotspot/user/print');
                        $userQuery->where('name', $username);
                        $userData = $client->query($userQuery)->read();
                        if (!empty($userData)) {
                             $client->query((new Query('/ip/hotspot/user/remove'))->equal('.id', $userData[0]['.id']))->read();
                        }
                        $_SESSION['debug'][] = "Utilisateur $username déconnecté et supprimé de MikroTik (MAC non autorisée).";
                    } catch (Exception $e) {
                        $_SESSION['debug'][] = "❌ Erreur déconnexion ou suppression MT user pour MAC non autorisée: " . $e->getMessage();
                    }
                    continue; // Skip processing this user further in this loop as they are disconnected
                }

                // Recalcul du temps utilisé pour cette session
                $uptime_sec_current_session = 0;
                if (preg_match('/(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/', $uptime, $matches)) {
                    $days = isset($matches[1]) ? (int)$matches[1] : 0;
                    $hours = isset($matches[2]) ? (int)$matches[2] : 0;
                    $minutes = isset($matches[3]) ? (int)$matches[3] : 0;
                    $seconds = isset($matches[4]) ? (int)$matches[4] : 0;
                    $uptime_sec_current_session = ($days * 86400) + ($hours * 3600) + ($minutes * 60) + $seconds;
                }
                $_SESSION['debug'][] = "Uptime MikroTik actuel (sec): $uptime_sec_current_session";

                $used_seconds_from_db = $ticket['used_seconds'] ?? 0;
                // Important: strtotime will now interpret the string based on 'Africa/Niamey' timezone
                $last_active_from_db = $ticket['last_active'] ? strtotime($ticket['last_active']) : null;
                $current_time_ts = time(); // This is UTC, but calculations will now be consistent
                $_SESSION['debug'][] = "DB used_seconds: $used_seconds_from_db";
                $_SESSION['debug'][] = "DB last_active (timestamp): " . ($last_active_from_db ? date('Y-m-d H:i:s', $last_active_from_db) : 'NULL');
                $_SESSION['debug'][] = "Current timestamp: " . date('Y-m-d H:i:s', $current_time_ts);


                $time_to_add = 0;
                if ($last_active_from_db !== null) {
                    $time_to_add = $current_time_ts - $last_active_from_db;
                    if ($time_to_add < 0) {
                        // This should now be rare if timezone is correctly set and clocks are synced
                        $_SESSION['debug'][] = "⚠️ time_to_add négatif, ajusté à 0. (Décalage horloge malgré le timezone?)";
                        $time_to_add = 0;
                    }
                    $_SESSION['debug'][] = "Calcul time_to_add (depuis last_active): $time_to_add secondes";
                } else {
                    $time_to_add = $uptime_sec_current_session;
                    $_SESSION['debug'][] = "Première activation pour ce cycle, time_to_add = Uptime MT: $time_to_add secondes";
                }

                $total_used_seconds_cumulative = $used_seconds_from_db + $time_to_add;
                $_SESSION['debug'][] = "total_used_seconds_cumulative (avant max): $total_used_seconds_cumulative";

                // Important : S'assurer que total_used_seconds n'est jamais inférieur au uptime actuel de MikroTik
                // pour une session active. Cela gère les cas où le script aurait pu manquer une mise à jour,
                // et MikroTik a accumulé plus de temps.
                $total_used_seconds_cumulative = max($total_used_seconds_cumulative, $uptime_sec_current_session);
                $_SESSION['debug'][] = "total_used_seconds_cumulative (après max, final): $total_used_seconds_cumulative";

                // Mise à jour du temps utilisé et de last_active en base de données
                $pdo->prepare("UPDATE tickets SET used_seconds = :used, last_active = NOW() WHERE id = :id")
                    ->execute(['used' => $total_used_seconds_cumulative, 'id' => $ticket['id']]);
                $_SESSION['debug'][] = "DB UPDATE: used_seconds = $total_used_seconds_cumulative, last_active = NOW()";

                // Vérification expiration session (basée sur la durée totale)
                $duree_sec = convert_duration_to_seconds($ticket['duree']);
                $is_expired_by_session_time = ($duree_sec > 0 && $total_used_seconds_cumulative >= $duree_sec);
                $_SESSION['debug'][] = "Durée forfait (sec): $duree_sec. Expired by session time: " . ($is_expired_by_session_time ? 'Oui' : 'Non');


                // Vérification expiration par période de validité (ex: 24h pour tickets horaires)
                $is_expired_by_validity_period = false;
                if (!empty($ticket['activated_at'])) {
                    $activated_at_ts = strtotime($ticket['activated_at']);
                    $valid_until_ts = $activated_at_ts;
                    if (str_contains($ticket['duree'], 'h')) {
                        $valid_until_ts += 86400; // 24 hours for hourly tickets
                        $_SESSION['debug'][] = "Type horaire: Validité jusqu'à " . date('Y-m-d H:i:s', $valid_until_ts);
                    } else {
                        $valid_until_ts += $duree_sec; // For other types, validity is equal to duration
                        $_SESSION['debug'][] = "Type non horaire: Validité jusqu'à " . date('Y-m-d H:i:s', $valid_until_ts);
                    }
                    if ($current_time_ts > $valid_until_ts) {
                        $is_expired_by_validity_period = true;
                    }
                }
                $_SESSION['debug'][] = "Expired by validity period: " . ($is_expired_by_validity_period ? 'Oui' : 'Non');


                if ($is_expired_by_session_time || $is_expired_by_validity_period) {
                    $_SESSION['debug'][] = "Ticket $username expiré détecté (session: " . ($is_expired_by_session_time ? 'Oui' : 'Non') . ", validité: " . ($is_expired_by_validity_period ? 'Oui' : 'Non') . ")";
                    $expiration_reason = '';
                    if ($is_expired_by_session_time) {
                        $expiration_reason = 'temps de session écoulé';
                    } elseif ($is_expired_by_validity_period) {
                        $expiration_reason = 'période de validité expirée';
                    }

                    // Archiver le ticket avant de le supprimer de la table active
                    $archive_expired = $pdo->prepare("
                        INSERT INTO deleted_tickets
                            (username, mac_address, forfait, used_seconds, expiration_reason, deleted_at, original_generated_at, original_activated_at)
                        VALUES
                            (:username, :mac, :forfait, :used, :reason, NOW(), :generated_at, :activated_at)
                    ");
                    $archive_expired->execute([
                        'username' => $ticket['code'],
                        'mac' => $ticket['mac_autorisee'] ?? '',
                        'forfait' => $ticket['duree'],
                        'used' => $total_used_seconds_cumulative,
                        'reason' => $expiration_reason,
                        'generated_at' => $ticket['generated_at'],
                        'activated_at' => $ticket['activated_at']
                    ]);
                    $_SESSION['debug'][] = "Ticket $username archivé suite à expiration.";

                    // Déconnecter l'utilisateur actif
                    try {
                        $removeQuery = new Query('/ip/hotspot/active/remove');
                        $removeQuery->equal('.id', $session_id);
                        $client->query($removeQuery)->read();
                        $_SESSION['debug'][] = "Session $session_id déconnectée pour $username.";
                    } catch (Exception $e) {
                        $_SESSION['debug'][] = "❌ Erreur déconnexion MikroTik pour $username: " . $e->getMessage();
                    }

                    // Supprimer l'utilisateur Hotspot de MikroTik
                    try {
                        $mikrotikUserQuery = new Query('/ip/hotspot/user/print');
                        $mikrotikUserQuery->where('name', $username);
                        $mikrotikUsers = $client->query($mikrotikUserQuery)->read();
                        if (!empty($mikrotikUsers)) {
                            $client->query((new Query('/ip/hotspot/user/remove'))->equal('.id', $mikrotikUsers[0]['.id']))->read();
                            $_SESSION['debug'][] = "Utilisateur MikroTik $username supprimé.";
                        }
                    } catch (Exception $e) {
                        $_SESSION['debug'][] = "❌ Erreur suppression utilisateur MikroTik $username: " . $e->getMessage();
                    }

                    // Supprimer le ticket de la table 'tickets'
                    $pdo->prepare("DELETE FROM tickets WHERE id = :id")->execute(['id' => $ticket['id']]);
                    $_SESSION['debug'][] = "Ticket $username supprimé de la table 'tickets'.";
                }
            } else {
                 $_SESSION['debug'][] = "Ticket DB NON trouvé pour utilisateur MikroTik: $username. (Peut-être un utilisateur temporaire MikroTik?)";
            }
        }
    }

} catch (Exception $e) {
    $_SESSION['debug'][] = "❌ ERREUR GLOBALE: " . $e->getMessage();
    $_SESSION['debug'][] = "Trace: " . $e->getTraceAsString();
}

// 🔎 Lecture de TOUS les tickets (actifs et archivés) pour l'affichage
$allTickets = [];

// Récupérer les tickets actifs
$stmt_active = $pdo->query("SELECT *, 'active' AS ticket_type FROM tickets");
$active_tickets_from_db = $stmt_active->fetchAll(PDO::FETCH_ASSOC);
foreach($active_tickets_from_db as $t) {
    $allTickets[] = $t;
}
$_SESSION['debug'][] = count($active_tickets_from_db) . " ticket(s) actif(s) chargé(s) depuis la base";

// Récupérer les tickets archivés
$stmt_deleted = $pdo->query("SELECT *, 'deleted' AS ticket_type FROM deleted_tickets");
$deleted_tickets_from_db = $stmt_deleted->fetchAll(PDO::FETCH_ASSOC);
foreach($deleted_tickets_from_db as $t) {
    // Mapper les champs de deleted_tickets pour correspondre à tickets
    $allTickets[] = [
        'id' => $t['id'], // Use the id from deleted_tickets for consistency
        'code' => $t['username'],
        'duree' => $t['forfait'],
        'price' => 'N/A', // Price not stored in deleted_tickets, or fetch from tickets before deletion
        'generated_at' => $t['original_generated_at'] ?? $t['deleted_at'], // Prefer original, fallback to deleted_at
        'activated_at' => $t['original_activated_at'] ?? '-', // Prefer original, fallback to -
        'mac_autorisee' => $t['mac_address'] ?? '-', // Use mac_address from deleted_tickets
        'used_seconds' => $t['used_seconds'] ?? 0,
        'last_active' => $t['deleted_at'], // Last active is when it was deleted
        'ticket_type' => 'deleted',
        'computed_status' => 'Expiré et Archivé',
        'connected_mac' => $t['mac_address'] ?? '-',
        'expiration_reason' => $t['expiration_reason'] ?? 'N/A'
    ];
}
$_SESSION['debug'][] = count($deleted_tickets_from_db) . " ticket(s) archivé(s) chargé(s) depuis la base";

// Préparation des données pour l'affichage (statut, MAC connectée etc.)
foreach ($allTickets as &$ticket) {
    $status = 'Non utilisé';
    $macAddress = '-';
    $isConnected = false;

    // If it's an active ticket, check its current connection and status
    if ($ticket['ticket_type'] === 'active') {
        foreach ($connectedUsers as $user) {
            if (!empty($user['user']) && $user['user'] === $ticket['code']) {
                $isConnected = true;
                $macAddress = $user['mac-address'] ?? '-';
                break;
            }
        }

        if (!empty($ticket['activated_at'])) {
            if ($isConnected) {
                $status = 'En cours';
            } else {
                $status = 'Activé';
            }
        }
        $ticket['computed_status'] = $status;
        $ticket['connected_mac'] = $macAddress;
    }
    // If it's a deleted ticket, its status is already "Expiré et Archivé"

    // Assign sort priority based on computed_status
    $priority = 99; // Default low priority for unknown or unexpected statuses
    if ($ticket['computed_status'] === 'En cours') {
        $priority = 1;
    } elseif ($ticket['computed_status'] === 'Activé') {
        $priority = 2;
    } elseif ($ticket['computed_status'] === 'Non utilisé') {
        $priority = 3;
    } elseif ($ticket['computed_status'] === 'Expiré et Archivé') {
        $priority = 4;
    }
    $ticket['sort_status_priority'] = $priority;
}
unset($ticket); // Rompre la référence sur le dernier élément

// Trier tous les tickets pour l'affichage (par status_priority puis par date de génération/suppression)
usort($allTickets, function($a, $b) {
    // Primary sort: by status priority (ascending)
    if ($a['sort_status_priority'] !== $b['sort_status_priority']) {
        return $a['sort_status_priority'] <=> $b['sort_status_priority'];
    }
    // Secondary sort: by generated/deleted at (descending - most recent first)
    $timeA = strtotime($a['generated_at'] ?? $a['deleted_at']);
    $timeB = strtotime($b['generated_at'] ?? $b['deleted_at']);
    return $timeB <=> $timeA;
});

// Separate tickets into active and archived arrays AFTER sorting
$activeTickets = array_filter($allTickets, fn($t) => $t['ticket_type'] === 'active');
$archivedTickets = array_filter($allTickets, fn($t) => $t['ticket_type'] === 'deleted');

// Calculate summary counts
$count_active = count($activeTickets);
$count_archived = count($archivedTickets);
$count_en_cours = 0;
$count_active_only = 0; // 'Activé' but not 'En cours'
$count_non_utilise = 0;

foreach ($activeTickets as $ticket) {
    if ($ticket['computed_status'] === 'En cours') {
        $count_en_cours++;
    } elseif ($ticket['computed_status'] === 'Activé') {
        $count_active_only++;
    } elseif ($ticket['computed_status'] === 'Non utilisé') {
        $count_non_utilise++;
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billets - M.SOUNNA WIFI-ZONE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.0/css/responsive.dataTables.min.css">
    <style>
        :root {
            --primary: #0f9d58; /* Google Green */
            --primary-dark: #0c7c45;
            --secondary: #4285f4; /* Google Blue */
            --secondary-light: #8ab4f8;
            --accent: #fbbc05; /* Google Yellow */
            --danger: #ea4335; /* Google Red */
            --info: #34a853; /* Another Green shade */
            --dark-bg: #202124; /* Dark for cards/text if dark mode */
            --light-bg: #ffffff; /* Card background */
            --body-bg: #f5f7fa; /* Light grey background */
            --text-color: #3c4043; /* Dark grey text */
            --light-text-color: #5f6368;
            --border-color: #dadce0;
            --shadow-light: 0 4px 15px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 25px rgba(0, 0, 0, 0.12);
            --border-radius: 12px;
            --transition-speed: 0.3s ease;
            --gold-color: #FFD700; /* Gold for DMS-DESIGN text */
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .tagline {
            font-size: 1.1rem;
            color: #5f6368;
            font-style: italic;
            margin-top: 3px;
            line-height: 1.4;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--body-bg);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
            flex-grow: 1;
        }

        /* Header responsive */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--light-bg);
            border-radius: var(--border-radius);
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            flex-wrap: wrap;
        }

        .header-left {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
            margin-bottom: 15px;
            width: 100%;
        }

        .logo-title-container {
            display: flex;
            align-items: center;
            gap: 15px;
            width: 100%;
        }

        .header-left .logo {
            height: 50px;
            width: auto;
            border-radius: 8px;
        }

        .header-left h1 {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            color: var(--gold-color);
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            width: 100%;
            justify-content: space-between;
        }

        .header-right .datetime-info {
            text-align: left;
        }

        .header-right p {
            font-size: 0.9rem;
            color: var(--light-text-color);
            margin: 2px 0;
        }

        .header-right .current-time {
            font-weight: 600;
            color: var(--text-color);
            font-size: 1rem;
        }

        .btn {
            padding: 12px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition-speed);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            width: 100%;
            justify-content: center;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        /* Summary Cards - responsive grid */
        .summary-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: var(--transition-speed);
            border: 1px solid var(--border-color);
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .summary-card .icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--primary);
        }

        .summary-card .count {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .summary-card .label {
            font-size: 0.9rem;
            color: var(--light-text-color);
            font-weight: 500;
        }

        .summary-card.active .icon { color: var(--primary); }
        .summary-card.inprogress .icon { color: var(--secondary); }
        .summary-card.activated .icon { color: var(--accent); }
        .summary-card.notused .icon { color: var(--light-text-color); }
        .summary-card.archived .icon { color: var(--dark-bg); }

        /* Card */
        .card {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            overflow: hidden;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .card-header {
            padding: 15px 20px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: white;
            font-size: 1.1rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header i {
            font-size: 1.2rem;
        }

        /* Table container */
        .table-container {
            overflow-x: auto;
            padding: 0 10px 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px; /* Minimum width for tables */
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background-color: rgba(15, 157, 88, 0.1);
            color: var(--primary);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        tr:not(:last-child) {
            border-bottom: 1px solid var(--border-color);
        }

        tr:hover {
            background-color: rgba(15, 157, 88, 0.03);
        }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            gap: 6px;
            white-space: nowrap;
        }

        .non { background-color: rgba(108, 117, 125, 0.15); color: #6c757d; }
        .en { background-color: rgba(23, 162, 184, 0.15); color: #17a2b8; }
        .activé { background-color: rgba(255, 193, 7, 0.15); color: #856404; }
        .expiré { background-color: rgba(220, 53, 69, 0.25); color: #dc3545; border: 1px solid #dc3545; }
        .expiré-archived { background-color: rgba(108, 117, 125, 0.1); color: #6c757d; border: 1px solid #6c757d; }

        /* Buttons */
        .action-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition-speed);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .action-btn:hover {
            background: #c62828;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(229, 57, 53, 0.3);
        }
        
        .bulk-actions {
            margin: 10px 0 15px;
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 0 10px;
        }

        .bulk-actions button {
            background-color: var(--danger);
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition-speed);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .bulk-actions button:hover {
            background-color: #c62828;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(229, 57, 53, 0.3);
        }

        .bulk-actions button:disabled {
            background-color: var(--border-color);
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        /* Progress bar */
        .progress-container {
            height: 6px;
            background-color: #e9ecef;
            border-radius: 10px;
            margin-top: 6px;
            overflow: hidden;
            width: 80px;
        }

        .progress-bar {
            height: 100%;
            border-radius: 10px;
        }

        .progress-normal { background: linear-gradient(90deg, var(--primary), var(--secondary)); }
        .progress-warning { background: linear-gradient(90deg, var(--accent), #ffb74d); }
        .progress-danger { background: linear-gradient(90deg, var(--danger), #e57373); }

        .usage-info {
            font-size: 0.75rem;
            color: var(--light-text-color);
            margin-top: 5px;
            display: block;
        }

        /* Debug */
        .debug-container {
            margin-top: 20px;
        }

        .debug {
            background: var(--dark-bg);
            color: #e0e0e0;
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--shadow-light);
        }

        .debug-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            margin-bottom: 10px;
        }

        .debug-header h3 {
            color: var(--secondary-light);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            font-size: 1.1rem;
        }

        .debug-header .toggle-icon {
            color: var(--secondary-light);
            font-size: 1.3rem;
            transition: transform 0.3s ease;
        }

        .debug-header .toggle-icon.rotated {
            transform: rotate(90deg);
        }

        .debug-content {
            display: none;
        }

        .debug-content ul {
            list-style-type: none;
            padding-left: 0;
        }

        .debug-content li {
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-family: monospace;
            font-size: 0.85rem;
            white-space: pre-wrap;
            word-break: break-all;
            color: #bdbdbd;
        }

        .debug-content li:last-child {
            border-bottom: none;
        }

        .debug-content li::before {
            content: ">> ";
            color: var(--accent);
        }

        /* Footer */
        .footer {
            text-align: center;
            padding: 15px;
            color: var(--light-text-color);
            font-size: 0.85rem;
            margin-top: 20px;
            background: var(--light-bg);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .footer p {
            margin: 0;
        }

        /* Responsive adjustments */
        @media (min-width: 576px) {
            .header-left {
                width: auto;
                margin-bottom: 0;
            }
            
            .header-right {
                width: auto;
                justify-content: flex-end;
            }
            
            .btn {
                width: auto;
            }
            
            .summary-cards-container {
                gap: 20px;
            }
        }

        @media (min-width: 768px) {
            .header {
                flex-wrap: nowrap;
            }
            
            .header-left h1 {
                font-size: 2rem;
            }
            
            .header-right .datetime-info {
                text-align: right;
            }
            
            .card-header {
                padding: 20px 30px;
                font-size: 1.2rem;
            }
            
            .card-header i {
                font-size: 1.4rem;
            }
            
            th, td {
                padding: 16px 20px;
            }
            
            .progress-container {
                width: 100px;
            }
        }

        @media (max-width: 767px) {
            .table-container {
                padding: 0;
            }
            
            .bulk-actions {
                padding: 0 10px;
            }
            
            .logo-title-container {
                flex-direction: column;
                text-align: center;
            }
            
            .header-left .logo {
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-left">
                <div class="logo-title-container">
                    <img src="dms-design.png" alt="DMS-DESIGN Logo" class="logo">
                    <h1>DMS-DESIGN</h1>
                </div>
                <p class="tagline">Optimisez votre réseau WiFi avec notre solution intelligente</p>
            </div>
            <div class="header-right">
                <div class="datetime-info">
                    <p>Date: <span id="currentDate"></span></p>
                    <p>Heure: <span id="currentTime" class="current-time"></span></p>
                </div>
                <a href="../public/dashboard.php" class="btn">
                    <i class="fas fa-arrow-left"></i> Tableau de bord
                </a>
            </div>
        </div>

        <div class="summary-cards-container">
            <div class="summary-card active">
                <i class="fas fa-wifi icon"></i>
                <div class="count"><?= $count_active ?></div>
                <div class="label">Billets Actifs</div>
            </div>
            <div class="summary-card inprogress">
                <i class="fas fa-arrow-alt-circle-right icon"></i>
                <div class="count"><?= $count_en_cours ?></div>
                <div class="label">En Cours</div>
            </div>
            <div class="summary-card activated">
                <i class="fas fa-check-circle icon"></i>
                <div class="count"><?= $count_active_only ?></div>
                <div class="label">Activés (non connectés)</div>
            </div>
            <div class="summary-card notused">
                <i class="fas fa-hourglass-start icon"></i>
                <div class="count"><?= $count_non_utilise ?></div>
                <div class="label">Non Utilisés</div>
            </div>
            <div class="summary-card archived">
                <i class="fas fa-archive icon"></i>
                <div class="count"><?= $count_archived ?></div>
                <div class="label">Archivés</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Liste des billets actifs
            </div>
            
            <form id="bulkDeleteForm" method="POST" action="tickets.php" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer les tickets sélectionnés ? Cette action est irréversible.');">
                <div class="bulk-actions">
                    <button type="submit" name="bulk_delete" id="bulkDeleteBtn" disabled>
                        <i class="fas fa-trash-alt"></i> Supprimer la sélection
                    </button>
                </div>
                <div class="table-container">
                    <table id="ticketsTable" class="display">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllTickets"></th>
                                <th>Code</th>
                                <th>Durée</th>
                                <th>Prix</th>
                                <th>Généré</th>
                                <th>Activé</th>
                                <th>MAC autorisée</th>
                                <th>Statut</th>
                                <th>Temps utilisé</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeTickets as $ticket):
                            $duree_sec = convert_duration_to_seconds($ticket['duree']);
                            $pourcentage = ($duree_sec > 0) ? min(100, (($ticket['used_seconds'] ?? 0) / $duree_sec) * 100) : 0;
                            $progress_class = ($pourcentage < 80) ? 'progress-normal' : (($pourcentage < 95) ? 'progress-warning' : 'progress-danger');
                        ?>
                            <tr>
                                <td><input type="checkbox" name="selected_tickets[]" value="<?= $ticket['id'] ?>" class="ticket-checkbox"></td>
                                <td><strong><?= htmlspecialchars($ticket['code']) ?></strong></td>
                                <td><?= htmlspecialchars($ticket['duree']) ?></td>
                                <td><?= htmlspecialchars($ticket['price'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($ticket['generated_at'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($ticket['activated_at'] ?? '-') ?></td>
                                <td><code><?= htmlspecialchars($ticket['mac_autorisee'] ?? '-') ?></code></td>
                                <td>
                                    <?php
                                        $display_status = $ticket['computed_status'];
                                        $statusClass = '';
                                        $statusIcon = '';

                                        if ($display_status === 'Non utilisé') {
                                            $statusClass = 'non';
                                            $statusIcon = '<i class="fas fa-circle status-icon"></i>';
                                        } elseif ($display_status === 'En cours') {
                                            $statusClass = 'en';
                                            $statusIcon = '<i class="fas fa-wifi status-icon"></i>';
                                        } elseif ($display_status === 'Activé') {
                                            $statusClass = 'activé';
                                            $statusIcon = '<i class="fas fa-check-circle status-icon"></i>';
                                        }
                                    ?>
                                    <span class="badge <?= $statusClass ?>">
                                        <?= $statusIcon ?>
                                        <?= $display_status ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($ticket['activated_at'])): ?>
                                        <div class="progress-container">
                                            <div class="progress-bar <?= $progress_class ?>" style="width: <?= $pourcentage ?>%"></div>
                                        </div>
                                        <span class="usage-info">
                                            <?= floor($pourcentage) ?>% utilisé
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="get" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce ticket ?');">
                                        <input type="hidden" name="delete" value="<?= $ticket['id'] ?>">
                                        <button type="submit" class="action-btn">
                                            <i class="fas fa-trash-alt"></i> Supprimer
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>

        <?php if (!empty($archivedTickets)): ?>
        <div class="card">
            <div class="card-header" style="background: linear-gradient(90deg, #6c757d, #495057);">
                <i class="fas fa-archive"></i> Billets Archivés
            </div>
            <div class="table-container">
                <table id="archivedTicketsTable" class="display">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Durée</th>
                            <th>MAC Autorisé/Connecté</th>
                            <th>Temps Utilisé</th>
                            <th>Raison d'expiration</th>
                            <th>Archivé le</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archivedTickets as $ticket): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($ticket['code']) ?></strong></td>
                                <td><?= htmlspecialchars($ticket['duree']) ?></td>
                                <td><code><?= htmlspecialchars($ticket['mac_autorisee'] ?? '-') ?></code></td>
                                <td>
                                    <span class="usage-info">
                                        Utilisé: <?= round(($ticket['used_seconds'] ?? 0) / 3600, 2) ?> heures
                                    </span>
                                </td>
                                <td><span class="badge expiré-archived"><?= htmlspecialchars($ticket['expiration_reason'] ?? 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($ticket['deleted_at'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['debug'])): ?>
            <div class="debug-container">
                <div class="debug">
                    <div class="debug-header" id="debugToggle">
                        <h3><i class="fas fa-bug"></i> Journal de débogage</h3>
                        <i class="fas fa-chevron-right toggle-icon"></i>
                    </div>
                    <div class="debug-content" id="debugContent">
                        <ul>
                            <?php foreach ($_SESSION['debug'] as $log): ?>
                                <li><?= htmlspecialchars($log) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['debug']); ?>
        <?php endif; ?>
    </div>

    <footer class="footer">
        <p>Système de gestion WiFi &copy; <?= date('Y') ?> - DMS-DESIGN</p>
        <p>Propriété exclusive de DMS-DESIGN - Tous droits réservés</p>
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.4.0/js/dataTables.responsive.min.js"></script>
    <script>
    $(document).ready(function() {
        // Mettre à jour la date et l'heure en temps réel
        function updateDateTime() {
            const now = new Date();
            const optionsDate = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const optionsTime = { hour: '2-digit', minute: '2-digit', second: '2-digit' };

            document.getElementById('currentDate').textContent = now.toLocaleDateString('fr-FR', optionsDate);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('fr-FR', optionsTime);
        }

        // Appeler la fonction au chargement et toutes les secondes
        updateDateTime();
        setInterval(updateDateTime, 1000);

        // Initialize active tickets table with responsive
        $('#ticketsTable').DataTable({
            responsive: true,
            language: {
                search: "🔍 Rechercher :",
                lengthMenu: "Afficher _MENU_ lignes",
                info: "Affichage de _START_ à _END_ sur _TOTAL_ entrées",
                paginate: {
                    previous: "<i class='fas fa-chevron-left'></i> Précédent",
                    next: "Suivant <i class='fas fa-chevron-right'></i>"
                },
                emptyTable: "Aucun ticket actif disponible."
            },
            order: [], // No initial JS sorting, rely on PHP pre-sort
            columnDefs: [
                { orderable: false, targets: [0, 9] } // Disable ordering for checkbox and action columns
            ]
        });

        // Initialize archived tickets table with responsive
        $('#archivedTicketsTable').DataTable({
            responsive: true,
            language: {
                search: "🔍 Rechercher :",
                lengthMenu: "Afficher _MENU_ lignes",
                info: "Affichage de _START_ à _END_ sur _TOTAL_ entrées",
                paginate: {
                    previous: "<i class='fas fa-chevron-left'></i> Précédent",
                    next: "Suivant <i class='fas fa-chevron-right'></i>"
                },
                emptyTable: "Aucun ticket archivé disponible."
            },
            order: [[ 5, "desc" ]] // Order by 'Archivé le' column (index 5) in descending order
        });

        // Handle select all checkbox for active tickets
        $('#selectAllTickets').on('change', function() {
            $('.ticket-checkbox').prop('checked', $(this).prop('checked'));
            toggleBulkDeleteButton();
        });

        // Handle individual checkbox change for active tickets
        $('#ticketsTable').on('change', '.ticket-checkbox', function() {
            if (!$(this).prop('checked')) {
                $('#selectAllTickets').prop('checked', false);
            } else {
                // Check if all checkboxes are checked
                var allChecked = true;
                $('.ticket-checkbox').each(function() {
                    if (!$(this).prop('checked')) {
                        allChecked = false;
                        return false; // Break loop
                    }
                });
                $('#selectAllTickets').prop('checked', allChecked);
            }
            toggleBulkDeleteButton();
        });

        // Function to enable/disable bulk delete button
        function toggleBulkDeleteButton() {
            if ($('.ticket-checkbox:checked').length > 0) {
                $('#bulkDeleteBtn').prop('disabled', false);
            } else {
                $('#bulkDeleteBtn').prop('disabled', true);
            }
        }

        // Initial check for button state on page load
        toggleBulkDeleteButton();

        // Toggle debug content
        $('#debugToggle').on('click', function() {
            $('#debugContent').slideToggle(300);
            $(this).find('.toggle-icon').toggleClass('rotated');
        });
    });
    </script>
</body>
</html>