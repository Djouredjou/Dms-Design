<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Query;

// Définir le fuseau horaire
date_default_timezone_set('Africa/Niamey');

// Convertir des durées lisibles en secondes
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

// Convertir un uptime de MikroTik en secondes
function uptime_to_seconds($uptime) {
    $pattern = '/(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/';
    preg_match($pattern, $uptime, $matches);
    $days = isset($matches[1]) ? (int)$matches[1] : 0;
    $hours = isset($matches[2]) ? (int)$matches[2] : 0;
    $minutes = isset($matches[3]) ? (int)$matches[3] : 0;
    $seconds = isset($matches[4]) ? (int)$matches[4] : 0;
    return ($days * 86400) + ($hours * 3600) + ($minutes * 60) + $seconds;
}

// Afficher une durée formatée en texte complet
function format_seconds_full($seconds) {
    if ($seconds <= 0) return 'Expiré';
    
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf("%dd %02dh %02dm %02ds", $days, $hours, $minutes, $secs);
}

// Convertir des bytes en unité lisible (KB, MB, GB...)
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

try {
    // Connexion au MikroTik
    $client = new Client([
        'host' => MIKROTIK_HOST,
        'user' => MIKROTIK_USERNAME,
        'pass' => MIKROTIK_PASSWORD,
        'port' => 8728,
    ]);

    // Requête des utilisateurs actifs
    $query = new Query('/ip/hotspot/active/print');
    $users = $client->query($query)->read();

    // Calculs de bande passante
    $total_bandwidth = 0;
    $total_bandwidth_in = 0;
    $total_bandwidth_out = 0;

    foreach ($users as $user) {
        $total_bandwidth += ($user['bytes-in'] + $user['bytes-out']);
        $total_bandwidth_in += $user['bytes-in'];
        $total_bandwidth_out += $user['bytes-out'];
    }

    $bandwidth_mo = round($total_bandwidth / 1024 / 1024, 2);
    $bandwidth_in_mo = round($total_bandwidth_in / 1024 / 1024, 2);
    $bandwidth_out_mo = round($total_bandwidth_out / 1024 / 1024, 2);

    // Connexion à PostgreSQL
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=mikrotik_tickets", "postgres", "4484");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {
    die("Erreur de connexion : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utilisateurs Connectés - M.SOUNNA WIFI-ZONE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        :root {
            --primary: #0f9d58;
            --primary-dark: #0c7c45;
            --secondary: #4285f4;
            --secondary-light: #8ab4f8;
            --accent: #fbbc05;
            --danger: #ea4335;
            --info: #34a853;
            --dark-bg: #202124;
            --light-bg: #ffffff;
            --body-bg: #f5f7fa;
            --text-color: #3c4043;
            --light-text-color: #5f6368;
            --border-color: #dadce0;
            --shadow-light: 0 4px 15px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 25px rgba(0, 0, 0, 0.12);
            --border-radius: 12px;
            --transition-speed: 0.3s ease;
            --gold-color: #FFD700;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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
            width: 100%;
        }

        /* Header - Responsive */
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
            gap: 15px;
        }

        .header-left {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
            flex: 1;
            min-width: 250px;
        }

        .logo-title-container {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .header-left .logo {
            height: 50px;
            width: auto;
            border-radius: 8px;
            max-width: 100%;
        }

        .header-left h1 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(1.5rem, 2vw, 2.2rem);
            color: var(--gold-color);
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }

        .tagline {
            font-size: clamp(0.9rem, 1.1vw, 1.1rem);
            color: #5f6368;
            font-style: italic;
            margin-top: 3px;
            line-height: 1.4;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .datetime-info {
            text-align: right;
            margin-right: 10px;
            min-width: 120px;
        }

        .header-right p {
            font-size: clamp(0.85rem, 1vw, 1rem);
            color: var(--light-text-color);
            margin: 2px 0;
        }

        .header-right .current-time {
            font-weight: 600;
            color: var(--text-color);
        }

        .btn {
            padding: 10px 20px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            font-size: clamp(0.9rem, 1vw, 1rem);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition-speed);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            white-space: nowrap;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        .btn-secondary {
            background: var(--secondary);
        }
        .btn-secondary:hover {
            background: #357ae8;
        }

        .btn-danger {
            background: var(--danger);
        }
        .btn-danger:hover {
            background: #c9342a;
        }

        /* Summary Cards - Responsive */
        .summary-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            padding: 25px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: space-between;
            transition: var(--transition-speed);
            border: 1px solid var(--border-color);
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-medium);
        }

        .summary-card .icon {
            font-size: clamp(2rem, 3vw, 2.5rem);
            margin-bottom: 15px;
            color: var(--primary);
        }

        .summary-card .count {
            font-size: clamp(1.8rem, 2.5vw, 2.2rem);
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .summary-card .label {
            font-size: clamp(0.9rem, 1.1vw, 1rem);
            color: var(--light-text-color);
            font-weight: 500;
        }

        .summary-card .stat-subtext {
            font-size: clamp(0.8rem, 0.95vw, 0.9rem);
            color: var(--light-text-color);
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 3px;
        }

        .summary-card.users .icon { color: var(--secondary); }
        .summary-card.bandwidth .icon { color: var(--primary); }
        .summary-card.time .icon { color: var(--accent); }

        /* Navigation Buttons - Responsive */
        .navigation-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            margin-bottom: 30px;
        }

        /* Card */
        .card {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            overflow: hidden;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            width: 100%;
        }

        .card-header {
            padding: clamp(12px, 1.5vw, 20px) clamp(15px, 2vw, 30px);
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: white;
            font-size: clamp(1rem, 1.2vw, 1.2rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .card-header i {
            font-size: clamp(1.2rem, 1.5vw, 1.4rem);
        }

        /* Table - Responsive */
        .table-container {
            overflow-x: auto;
            padding: clamp(10px, 1.5vw, 20px);
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        th, td {
            padding: clamp(8px, 1vw, 16px) clamp(10px, 1.2vw, 20px);
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: clamp(0.8rem, 0.9vw, 0.9rem);
        }

        th {
            background-color: rgba(15, 157, 88, 0.1);
            color: var(--primary);
            font-weight: 600;
            text-transform: uppercase;
            font-size: clamp(0.75rem, 0.85vw, 0.85rem);
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        tr:not(:last-child) {
            border-bottom: 1px solid var(--border-color);
        }

        tr:hover {
            background-color: rgba(15, 157, 88, 0.03);
        }
        
        tr.expired-row {
            background-color: #ffe6e6;
            color: #c00;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: clamp(0.8rem, 0.85vw, 0.85rem);
            font-weight: 500;
            gap: 6px;
        }

        .active-badge {
            background-color: rgba(23, 162, 184, 0.15);
            color: #17a2b8;
        }

        .warning-badge {
            background-color: rgba(255, 193, 7, 0.15);
            color: #856404;
        }

        .danger-badge {
            background-color: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }

        /* Progress bar */
        .progress-container {
            height: 8px;
            background-color: #e9ecef;
            border-radius: 10px;
            margin-top: 8px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            border-radius: 10px;
        }

        .progress-normal {
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .progress-warning {
            background: linear-gradient(90deg, var(--warning), #ffb74d);
        }

        .progress-danger {
            background: linear-gradient(90deg, var(--danger), #e57373);
        }

        /* Status indicators */
        .status-indicator {
            display: inline-block;
            width: clamp(8px, 1vw, 12px);
            height: clamp(8px, 1vw, 12px);
            border-radius: 50%;
            margin-right: clamp(5px, 0.6vw, 8px);
        }

        .status-active {
            background-color: var(--success);
        }

        .status-warning {
            background-color: var(--warning);
        }

        .status-expired {
            background-color: var(--danger);
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: clamp(25px, 4vw, 40px) clamp(15px, 2vw, 20px);
        }

        .empty-state i {
            font-size: clamp(3rem, 6vw, 4rem);
            color: #e0e0e0;
            margin-bottom: clamp(15px, 2vw, 20px);
        }

        .empty-state p {
            font-size: clamp(1rem, 1.2vw, 1.2rem);
            color: var(--light-text-color);
            margin-bottom: clamp(20px, 2.5vw, 30px);
        }

        /* Footer - Responsive */
        .footer {
            text-align: center;
            padding: clamp(10px, 1.5vw, 20px);
            color: var(--light-text-color);
            font-size: clamp(0.8rem, 0.9vw, 0.9rem);
            margin-top: 30px;
            background: var(--light-bg);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .footer p {
            margin: 0;
        }

        .footer a {
            color: var(--primary);
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        /* ================= RESPONSIVE ADJUSTMENTS ================= */
        @media (max-width: 1200px) {
            .header {
                padding: 15px 20px;
            }
            
            .summary-card {
                padding: 20px;
            }
        }

        @media (max-width: 992px) {
            .header-right {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .datetime-info {
                text-align: left;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            
            .header-left, .header-right {
                width: 100%;
            }
            
            .header-right {
                justify-content: space-between;
                flex-direction: row;
                flex-wrap: nowrap;
            }
            
            .logo-title-container {
                justify-content: center;
                text-align: center;
            }
            
            .header-left {
                align-items: center;
                text-align: center;
            }
            
            .tagline {
                text-align: center;
                width: 100%;
            }
            
            .btn {
                padding: 8px 15px;
                font-size: 0.9rem;
            }
            
            .summary-cards-container {
                grid-template-columns: 1fr;
            }
            
            .navigation-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .card-header {
                padding: 12px 15px;
            }
            
            th, td {
                padding: 10px 12px;
            }
        }

        @media (max-width: 576px) {
            .container {
                padding: 0 10px;
                margin: 10px auto;
            }
            
            .header {
                padding: 12px 15px;
                margin-bottom: 20px;
            }
            
            .header-right {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            
            .datetime-info {
                margin-right: 0;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .summary-card {
                padding: 15px;
            }
            
            .summary-card .icon {
                font-size: 1.8rem;
            }
            
            .summary-card .count {
                font-size: 1.7rem;
            }
            
            .footer {
                padding: 10px;
            }
            
            .table-container {
                padding: 10px 5px;
            }
            
            th, td {
                padding: 8px 10px;
            }
        }

        @media (max-width: 480px) {
            .logo-title-container {
                flex-direction: column;
                gap: 5px;
            }
            
            .header-left h1 {
                font-size: 1.6rem;
            }
            
            .tagline {
                font-size: 0.9rem;
            }
            
            .summary-card .icon {
                font-size: 1.5rem;
            }
            
            .summary-card .count {
                font-size: 1.5rem;
            }
            
            .empty-state p {
                font-size: 1rem;
            }
        }

        /* Impression */
        @media print {
            .navigation-buttons, .btn {
                display: none;
            }
            
            .card {
                box-shadow: none;
                border: none;
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
            <a href="logout.php" class="btn btn-danger">
                <i class="fas fa-sign-out-alt"></i> Déconnexion
            </a>
        </div>
    </div>

    <div class="navigation-buttons">
        <a href="../public/dashboard.php" class="btn">
            <i class="fas fa-arrow-left"></i> Retour au tableau de bord
        </a>
    </div>

    <div class="summary-cards-container">
        <div class="summary-card users">
            <i class="fas fa-users icon"></i>
            <div class="count"><?= count($users) ?></div>
            <div class="label">Utilisateurs actifs</div>
        </div>
        <div class="summary-card bandwidth">
            <i class="fas fa-network-wired icon"></i>
            <div class="count"><?= $bandwidth_mo ?></div>
            <div class="label">Bande passante (Mo)</div>
            <div class="stat-subtext">
                <i class="fas fa-arrow-down text-info"></i> <?= $bandwidth_in_mo ?> Mo entrant
            </div>
            <div class="stat-subtext">
                <i class="fas fa-arrow-up text-success"></i> <?= $bandwidth_out_mo ?> Mo sortant
            </div>
        </div>
        <div class="summary-card time">
            <i class="fas fa-clock icon"></i>
            <div class="count"><?= date('H:i') ?></div>
            <div class="label">Heure actuelle</div>
            <div class="stat-subtext"><?= date('d/m/Y') ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <i class="fas fa-users"></i> Liste des connexions actives
        </div>
        <div class="table-container">
            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-slash"></i>
                    <p>Aucun utilisateur connecté actuellement</p>
                    <a href="../public/dashboard.php" class="btn">
                        <i class="fas fa-home"></i> Retour au tableau de bord
                    </a>
                </div>
            <?php else: ?>
                <table id="connectedUsers">
                    <thead>
                        <tr>
                            <th><i class="fas fa-ticket-alt"></i> Code de ticket</th>
                            <th>Durée choisie</th>
                            <th>Prix</th>
                            <th>Généré le</th>
                            <th>Activé le</th>
                            <th><i class="fas fa-network-wired"></i> IP</th>
                            <th><i class="fas fa-microchip"></i> MAC</th>
                            <th><i class="fas fa-clock"></i> Connecté depuis</th>
                            <th>Données utilisées</th>
                            <th><i class="fas fa-hourglass-half"></i> Temps restant</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total = 0;
                        foreach ($users as $user) {
                            $username = $user['user'];
                            $ip = $user['address'];
                            $mac = $user['mac-address'];
                            $uptime_hotspot = $user['uptime'];
                            $uptime_hotspot_sec = uptime_to_seconds($uptime_hotspot);
                            $bytes_used = $user['bytes-in'] + $user['bytes-out'];
                            $mo = round($bytes_used / 1024 / 1024, 2);
                            $formatted_bytes = format_bytes($bytes_used);

                            $stmt = $pdo->prepare("SELECT * FROM tickets WHERE code = :code ORDER BY generated_at DESC LIMIT 1");
                            $stmt->execute(['code' => $username]);
                            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

                            if (!$ticket) {
                                continue;
                            }

                            $duree = $ticket['duree'] ?? 'N/A';
                            $duree_sec = ($duree !== 'N/A') ? convert_duration_to_seconds($duree) : 0;
                            $prix = isset($ticket['price']) ? number_format($ticket['price'], 0, ',', ' ') . ' FCFA' : 'N/A';
                            $date_gen = $ticket['generated_at'] ?? 'N/A';
                            $date_act = $ticket['activated_at'] ?? 'N/A';
                            $mac_autorisee = $ticket['mac_autorisee'] ?? null;
                            $used_seconds_db = $ticket['used_seconds'] ?? 0;
                            $last_active_db = $ticket['last_active'] ?? null;

                            $current_time_ts = time();

                            if (empty($mac_autorisee)) {
                                $updateFields = ['mac_autorisee = :mac_address'];
                                $params = ['mac_address' => $mac, 'id' => $ticket['id']];
                                if (empty($date_act)) {
                                    $updateFields[] = 'activated_at = NOW()';
                                    $updateFields[] = 'last_active = NOW()';
                                }
                                $updateSql = "UPDATE tickets SET " . implode(', ', $updateFields) . " WHERE id = :id";
                                $updateStmt = $pdo->prepare($updateSql);
                                $updateStmt->execute($params);
                                $date_act = ($date_act === 'N/A') ? date("Y-m-d H:i:s") : $date_act;
                                $mac_autorisee = $mac;
                                $last_active_db = date("Y-m-d H:i:s");
                            } elseif (strtolower($mac_autorisee) !== strtolower($mac)) {
                                try {
                                    $client->query((new Query('/ip/hotspot/active/remove'))->equal('.id', $user['.id']))->read();
                                } catch (Exception $e) {
                                    error_log("connected_users: Erreur déconnexion MAC non autorisée: " . $e->getMessage());
                                }
                                continue;
                            }

                            $updated_used_seconds = $used_seconds_db;

                            if ($last_active_db && $last_active_db !== 'N/A') {
                                $last_active_ts_db = strtotime($last_active_db);
                                $time_elapsed_since_last_active = $current_time_ts - $last_active_ts_db;
                                $used_seconds_after_elapsed_calc = $used_seconds_db + max(0, $time_elapsed_since_last_active);
                                $updated_used_seconds = $used_seconds_after_elapsed_calc;
                            } else {
                                $updated_used_seconds = $uptime_hotspot_sec;
                            }

                            $updated_used_seconds = max($updated_used_seconds, $uptime_hotspot_sec);

                            $update_used_stmt = $pdo->prepare("UPDATE tickets SET used_seconds = :used_seconds, last_active = NOW() WHERE id = :id");
                            $update_used_stmt->execute([
                                'used_seconds' => $updated_used_seconds,
                                'id' => $ticket['id']
                            ]);
                            $ticket['used_seconds'] = $updated_used_seconds;

                            $is_expired = false;
                            $expiration_reason = '';
                            $temps_restant = 0;

                            $remaining_usage_time = ($duree_sec > 0) ? max($duree_sec - $ticket['used_seconds'], 0) : PHP_INT_MAX;
                            $remaining_validity_time = PHP_INT_MAX;
                            if (!empty($ticket['activated_at']) && $ticket['activated_at'] !== 'N/A') {
                                $activated_at_ts = strtotime($ticket['activated_at']);
                                $valid_until_ts = $activated_at_ts;
                                
                                if (str_contains($duree, 'h')) {
                                    $valid_until_ts += 86400;
                                } else {
                                    $valid_until_ts += $duree_sec;
                                }
                                $remaining_validity_time = max($valid_until_ts - $current_time_ts, 0);
                            }

                            if ($duree_sec > 0 && !empty($ticket['activated_at']) && $ticket['activated_at'] !== 'N/A') {
                                $temps_restant = min($remaining_usage_time, $remaining_validity_time);
                            } elseif ($duree_sec > 0) {
                                $temps_restant = $remaining_usage_time;
                            } elseif (!empty($ticket['activated_at']) && $ticket['activated_at'] !== 'N/A') {
                                $temps_restant = $remaining_validity_time;
                            } else {
                                $temps_restant = PHP_INT_MAX;
                            }
                            
                            if ($temps_restant <= 0 && ($duree_sec > 0 || (!empty($ticket['activated_at']) && $ticket['activated_at'] !== 'N/A'))) {
                                $is_expired = true;
                                if ($duree_sec > 0 && ($duree_sec - $ticket['used_seconds'] <= 0)) {
                                    $expiration_reason = 'temps de session écoulé';
                                } elseif (!empty($ticket['activated_at']) && $ticket['activated_at'] !== 'N/A' && ($remaining_validity_time <= 0)) {
                                    $expiration_reason = 'période de validité expirée';
                                } else {
                                    $expiration_reason = 'expiré';
                                }
                            }

                            if ($is_expired) {
                                $archive = $pdo->prepare("INSERT INTO deleted_tickets (username, mac_address, forfait, used_seconds, deleted_at, expiration_reason)
                                    VALUES (:username, :mac, :forfait, :used, NOW(), :reason)");
                                $archive->execute([
                                    'username' => $username,
                                    'mac' => $mac,
                                    'forfait' => $duree,
                                    'used' => $ticket['used_seconds'] ?? 0,
                                    'reason' => $expiration_reason
                                ]);

                                try {
                                    $client->query((new Query('/ip/hotspot/active/remove'))->equal('.id', $user['.id']))->read();
                                } catch (Exception $e) {
                                    error_log("connected_users: Erreur déconnexion session MikroTik pour $username: " . $e->getMessage());
                                }

                                try {
                                    $mikrotikUserQuery = new Query('/ip/hotspot/user/print');
                                    $mikrotikUserQuery->where('name', $username);
                                    $mikrotikUsers = $client->query($mikrotikUserQuery)->read();
                                    if (!empty($mikrotikUsers)) {
                                        $client->query((new Query('/ip/hotspot/user/remove'))->equal('.id', $mikrotikUsers[0]['.id']))->read();
                                    }
                                } catch (Exception $e) {
                                    error_log("connected_users: Erreur suppression utilisateur MikroTik pour $username: " . $e->getMessage());
                                }

                                $pdo->prepare("DELETE FROM tickets WHERE id = :id")->execute(['id' => $ticket['id']]);
                                continue;
                            }

                            $pourcentage = ($duree_sec > 0) ? min(100, ($ticket['used_seconds'] / $duree_sec) * 100) : 0;
                            
                            $progress_class = 'progress-normal';
                            $status_icon = '<span class="status-indicator status-active"></span>';
                            
                            if ($temps_restant <= 0) {
                                $progress_class = 'progress-danger';
                                $status_icon = '<span class="status-indicator status-expired"></span>';
                            } elseif ($temps_restant <= 600) {
                                $progress_class = 'progress-warning';
                                $status_icon = '<span class="status-indicator status-warning"></span>';
                            }
                            
                            $temps_restant_formatted = format_seconds_full($temps_restant);
                            
                            echo "<tr>
                                    <td><strong>{$username}</strong></td>
                                    <td>{$duree}</td>
                                    <td>{$prix}</td>
                                    <td>{$date_gen}</td>
                                    <td>{$date_act}</td>
                                    <td>{$ip}</td>
                                    <td><code>{$mac}</code></td>
                                    <td>{$uptime_hotspot}</td>
                                    <td>{$formatted_bytes}</td>
                                    <td>
                                        <div>{$status_icon}{$temps_restant_formatted}</div>
                                        <div class='progress-container'>
                                            <div class='progress-bar {$progress_class}' style='width: {$pourcentage}%'></div>
                                        </div>
                                    </td>
                                </tr>";
                            $total++;
                        }
                        ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="footer">
        <p>Système de gestion WiFi &copy; <?= date('Y') ?> - DMS-DESIGN</p>
        <p>Propriété exclusive de DMS-DESIGN - Tous droits réservés</p>
        <p>Actualisation automatique dans <span id="countdown">20</span> secondes</p>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
// Mettre à jour la date et l'heure en temps réel
function updateDateTime() {
    const now = new Date();
    const optionsDate = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    const optionsTime = { hour: '2-digit', minute: '2-digit', second: '2-digit' };

    document.getElementById('currentDate').textContent = now.toLocaleDateString('fr-FR', optionsDate);
    document.getElementById('currentTime').textContent = now.toLocaleTimeString('fr-FR', optionsTime);
}

updateDateTime();
setInterval(updateDateTime, 1000);

$(document).ready(function() {
    <?php if (!empty($users)): ?>
        $('#connectedUsers').DataTable({
            language: {
                search: "🔍 Rechercher :",
                lengthMenu: "Afficher _MENU_ lignes",
                info: "Affichage de _START_ à _END_ sur _TOTAL_ entrées",
                paginate: {
                    previous: "<i class='fas fa-chevron-left'></i> Précédent",
                    next: "Suivant <i class='fas fa-chevron-right'></i>"
                },
                emptyTable: "Aucun utilisateur connecté."
            },
            order: [[ 0, "asc" ]],
            responsive: true,
            columnDefs: [
                { orderable: false, targets: [1, 2, 3, 4, 5, 6, 7, 8, 9] }
            ]
        });
    <?php endif; ?>

    // Compte à rebours pour le rafraîchissement
    let countdown = 20;
    const countdownElement = document.getElementById('countdown');
    
    const countdownInterval = setInterval(() => {
        countdown--;
        countdownElement.textContent = countdown;
        
        if (countdown <= 0) {
            clearInterval(countdownInterval);
            location.reload();
        }
    }, 1000);
});
</script>
</body>
</html>