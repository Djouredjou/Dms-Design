<?php
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Query;

// Connexion à la base de données PostgreSQL
try {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=mikrotik_tickets", "postgres", "4484");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion à la base de données: " . $e->getMessage());
}

// Initialiser le tableau de tickets dans la session
if (!isset($_SESSION['tickets'])) {
    $_SESSION['tickets'] = [];
}

$message = '';
$tickets = &$_SESSION['tickets']; // Référence au tableau de tickets dans la session

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['duree'])) {
        // Génération de nouveaux tickets
        $duree = trim($_POST['duree']);
        $quantite = isset($_POST['quantite']) ? max(1, intval($_POST['quantite'])) : 1;
        $quantite = min(100, $quantite); // Limite à 100 tickets

        // Mapping des durées vers profils EXACTS dans MikroTik
        $profil_map = [
            '1h' => '1h',
            '5h' => '5h',
            '24h' => '24h',
            '3j' => '3j',
            '1s' => '1s',
            '1m' => '1m',
        ];

        // Mise à jour des prix (1s: 1000F, 1m: 3000F)
        $prix_map = [
            '1h' => 100,
            '5h' => 250,
            '24h' => 350,
            '3j' => 650,
            '1s' => 1000,
            '1m' => 3000
        ];

        // Mapping de couleurs basé sur login.html
        $color_map = [
            '1h' => ['#00bcd4', '#00bcd4'],
            '5h' => ['#ff9800', '#ff9800'],
            '24h' => ['#ff5722', '#ff5722'],
            '3j' => ['#673ab7', '#673ab7'],
            '1s' => ['#e91e63', '#e91e63'],
            '1m' => ['#0f9d58', '#0f9d58']
        ];

        if (!isset($profil_map[$duree])) {
            die('Erreur : Durée invalide.');
        }

        $profile = $profil_map[$duree];
        $prix = $prix_map[$duree];
        $couleurs = $color_map[$duree];

        // Connexion MikroTik
        try {
            $client = new Client([
                'host' => MIKROTIK_HOST,
                'user' => MIKROTIK_USERNAME,
                'pass' => MIKROTIK_PASSWORD,
                'port' => 8728,
            ]);
        } catch (Exception $e) {
            die('Erreur : Connexion au MikroTik échouée : ' . $e->getMessage());
        }

        for ($i = 0; $i < $quantite; $i++) {
            $code = 'MS-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));

            // Insertion dans la base
            try {
                $stmt = $pdo->prepare("INSERT INTO tickets (code, profile, status, duree, price) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$code, $profile, 'unused', $duree, $prix]);
            } catch (PDOException $e) {
                die("Erreur base de données: " . $e->getMessage());
            }

            // Création dans MikroTik
            try {
                $addQuery = (new Query('/ip/hotspot/user/add'))
                    ->equal('name', $code)
                    ->equal('password', $code)
                    ->equal('profile', $profile)
                    ->equal('comment', 'Ticket généré automatiquement');
                $client->query($addQuery);
            } catch (Exception $e) {
                echo "<pre>Erreur MikroTik : " . $e->getMessage() . "</pre>";
                continue;
            }

            // Ajouter le ticket à la session
            $tickets[] = [
                'code' => $code,
                'duree' => $duree,
                'prix' => $prix,
                'color1' => $couleurs[0],
                'color2' => $couleurs[1]
            ];
        }

        $message = "✅ $quantite ticket(s) généré(s) avec succès. Total: " . count($tickets) . " tickets.";
    } elseif (isset($_POST['clear_tickets'])) {
        $tickets = [];
        $message = "La liste des tickets a été vidée.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Génération de tickets WiFi | M.SOUNNA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
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

        /* Header */
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
        }

        .logo-title-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-left .logo {
            height: 50px;
            width: auto;
            border-radius: 8px;
        }

        .header-left h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            color: var(--gold-color);
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }

        .tagline {
            font-size: 1.1rem;
            color: #5f6368;
            font-style: italic;
            margin-top: 3px;
            line-height: 1.4;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .datetime-info {
            text-align: right;
            margin-right: 10px;
        }

        .datetime-info p {
            font-size: 1rem;
            color: var(--light-text-color);
            margin: 2px 0;
        }

        .current-time {
            font-weight: 600;
            color: var(--text-color);
        }

        .btn {
            padding: 12px 24px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition-speed);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            text-decoration: none;
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

        .btn-back {
            background: var(--light-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        .btn-back:hover {
            background: #f0f0f0;
            transform: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .page-title {
            font-size: 2rem;
            color: var(--text-color);
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-title i {
            color: var(--primary);
            font-size: 1.8rem;
        }

        .revenue-card {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            padding: 25px;
            margin-bottom: 30px;
            transition: var(--transition-speed);
            border: 1px solid var(--border-color);
        }

        .revenue-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .revenue-card h2 {
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .revenue-card h2 i {
            color: var(--primary);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 20px;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--light-text-color);
            font-size: 0.95rem;
        }

        select, input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 1rem;
            font-family: 'Poppins', sans-serif;
            background: white;
            transition: all 0.3s ease;
        }

        select:focus, input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(15, 157, 88, 0.15);
        }

        button {
            background: linear-gradient(90deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 14px 25px;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(15, 157, 88, 0.3);
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(15, 157, 88, 0.4);
        }

        button:active {
            transform: translateY(0);
        }

        .tickets-section {
            margin-top: 30px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.4rem;
            margin-bottom: 25px;
            color: var(--dark);
        }

        .section-title i {
            color: var(--primary);
        }

        .ticket-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .ticket {
            background: linear-gradient(145deg, #ffffff, #f5f7fa);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
            box-shadow: var(--shadow-light);
            transform-style: preserve-3d;
            transition: transform 0.3s ease;
            height: 240px;
        }

        .ticket:hover {
            transform: translateY(-5px) scale(1.02);
        }

        .ticket-header {
            padding: 15px 20px;
            text-align: center;
            position: relative;
        }

        .ticket-header::after {
            content: "";
            position: absolute;
            bottom: -10px;
            left: 0;
            right: 0;
            height: 20px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1440 320'%3E%3Cpath fill='%23ffffff' fill-opacity='1' d='M0,224L80,197.3C160,171,320,117,480,112C640,107,800,149,960,154.7C1120,160,1280,128,1360,112L1440,96L1440,320L1360,320C1280,320,1120,320,960,320C800,320,640,320,480,320C320,320,160,320,80,320L0,320Z'%3E%3C/path%3E%3C/svg%3E") center bottom/100% auto no-repeat;
        }

        .ticket-title {
            color: white;
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            position: relative;
            z-index: 2;
        }

        .ticket-body {
            padding: 25px 15px 15px;
            text-align: center;
        }

        .ticket-code {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--dark);
            margin: 10px 0;
            letter-spacing: 1px;
        }

        .ticket-info {
            display: flex;
            justify-content: space-around;
            margin: 20px 0 10px;
        }

        .info-item {
            text-align: center;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .info-label {
            font-size: 0.8rem;
            color: #5f6368;
        }

        .ticket-footer {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            border-top: 1px dashed #e0e0e0;
            color: #5f6368;
            font-size: 0.8rem;
        }

        .ticket-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 4rem;
            font-weight: 800;
            opacity: 0.03;
            color: var(--dark);
            pointer-events: none;
            z-index: 0;
        }

        .action-bar {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-top: 30px;
            gap: 15px;
        }

        .btn-secondary {
            background: linear-gradient(90deg, #5f6368, #3c4043);
            box-shadow: 0 4px 12px rgba(95, 99, 104, 0.3);
        }

        .btn-secondary:hover {
            box-shadow: 0 6px 15px rgba(95, 99, 104, 0.4);
        }

        .btn-dashboard {
            background: linear-gradient(90deg, #9c27b0, #673ab7);
            box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
        }

        .btn-dashboard:hover {
            box-shadow: 0 6px 15px rgba(156, 39, 176, 0.4);
        }

        .btn-clear {
            background: linear-gradient(90deg, #f44336, #d32f2f);
            box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
        }

        .btn-clear:hover {
            box-shadow: 0 6px 15px rgba(244, 67, 54, 0.4);
        }

        .notification {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 1200px) {
            .ticket-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 900px) {
            .ticket-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .ticket-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
                padding: 15px 20px;
            }
            
            .header-left h1 {
                font-size: 1.5rem;
            }
            
            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .tagline {
                font-size: 0.9rem;
            }
        }

        @media print {
            body, .container {
                padding: 0;
                background: white;
                width: 100%;
            }
            
            .card, .action-bar, .section-title, header {
                display: none;
            }
            
            .ticket-grid {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 3mm;
                padding: 5mm;
                margin: 0;
            }
            
            .ticket {
                width: 40mm !important;
                height: 25mm !important;
                min-height: 25mm !important;
                box-shadow: none;
                border: 0.5mm solid #ddd;
                border-radius: 2mm;
                overflow: hidden;
                position: relative;
                page-break-inside: avoid;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                transform: none !important;
                margin: 0 !important;
                padding: 0;
            }
            
            .ticket-header {
                padding: 1mm;
                height: 6mm;
                font-size: 6pt;
                text-align: center;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .ticket-header::after {
                height: 2mm;
                bottom: -1mm;
                background-size: 100% auto;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .ticket-title {
                font-size: 6pt;
                font-weight: 700;
                letter-spacing: 0.5px;
                margin: 0;
            }
            
            .ticket-body {
                padding: 1mm 2mm;
                position: relative;
            }
            
            .ticket-code {
                font-size: 8pt;
                font-weight: 700;
                margin: 1mm 0;
                letter-spacing: 0.5px;
                word-break: break-all;
                line-height: 1.1;
            }
            
            .ticket-info {
                display: flex;
                justify-content: space-around;
                margin: 1mm 0 0;
                font-size: 6pt;
            }
            
            .info-item {
                text-align: center;
                padding: 0;
            }
            
            .info-value {
                font-size: 7pt;
                font-weight: 700;
                margin-bottom: 0;
                line-height: 1.1;
            }
            
            .info-label {
                font-size: 6pt;
                color: #5f6368;
                line-height: 1.1;
            }
            
            .ticket-footer {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                padding: 1mm;
                font-size: 5pt;
                border-top: 0.3mm dashed #e0e0e0;
            }
            
            .ticket-watermark {
                font-size: 16pt;
                opacity: 0.05;
                z-index: -1;
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

        <a href="dashboard.php" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Retour au tableau de bord
        </a>

        <h1 class="page-title">
            <i class="fas fa-ticket-alt"></i> Générateur de Tickets WiFi
        </h1>

        <?php if (!empty($message)): ?>
            <div class="notification">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="revenue-card">
            <h2><i class="fas fa-plus-circle"></i> Créer de nouveaux tickets</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="duree"><i class="fas fa-clock"></i> Durée d'accès</label>
                        <select name="duree" id="duree" required>
                            <option value="">-- Sélectionner --</option>
                            <option value="1h">1 heure - 100 FCFA</option>
                            <option value="5h">5 heures - 250 FCFA</option>
                            <option value="24h">24 heures - 350 FCFA</option>
                            <option value="3j">3 jours - 650 FCFA</option>
                            <option value="1s">1 semaine - 1000 FCFA</option>
                            <option value="1m">1 mois - 3000 FCFA</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="quantite"><i class="fas fa-copy"></i> Quantité (max 100)</label>
                        <input type="number" name="quantite" id="quantite" min="1" max="100" value="1">
                    </div>
                    
                    <div class="form-group">
                        <button type="submit">
                            <i class="fas fa-plus-circle"></i> Générer
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <?php if (!empty($tickets)): ?>
            <div class="tickets-section">
                <div class="section-title">
                    <i class="fas fa-ticket-alt"></i>
                    <h2>Tickets générés (<?= count($tickets) ?>)</h2>
                </div>

                <div class="ticket-grid">
                    <?php foreach ($tickets as $t): ?>
                        <div class="ticket">
                            <div class="ticket-watermark">WIFI</div>
                            <div class="ticket-header" style="background: linear-gradient(90deg, <?= $t['color1'] ?>, <?= $t['color2'] ?>);">
                                <div class="ticket-title">M.SOUNNA WIFI-ZONE</div>
                            </div>

                            <div class="ticket-body">
                                <div class="ticket-code" style="color: <?= $t['color1'] ?>;"><?= htmlspecialchars($t['code']) ?></div>

                                <div class="ticket-info">
                                    <div class="info-item">
                                        <div class="info-value" style="color: <?= $t['color1'] ?>;"><?= htmlspecialchars($t['duree']) ?></div>
                                        <div class="info-label">Durée</div>
                                    </div>

                                    <div class="info-item">
                                        <div class="info-value" style="color: <?= $t['color1'] ?>;"><?= number_format($t['prix']) ?> FCFA</div>
                                        <div class="info-label">Prix</div>
                                    </div>
                                </div>
                            </div>

                            <div class="ticket-footer">
                                <i class="fas fa-info-circle"></i> Code valable pour une seule utilisation
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="action-bar">
                    <button class="btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimer les tickets
                    </button>
                    
                    <form method="POST" style="display:inline;">
                        <button type="submit" name="clear_tickets" class="btn-clear">
                            <i class="fas fa-trash"></i> Vider la liste
                        </button>
                    </form>
                    
                    <button onclick="location.reload()">
                        <i class="fas fa-redo"></i> Générer d'autres tickets
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <footer class="footer">
        <p>Système de gestion WiFi &copy; <?= date('Y') ?> - DMS-DESIGN</p>
        <p>Propriété exclusive de DMS-DESIGN - Tous droits réservés</p>
    </footer>
    
    <script>
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

        // Animation pour le survol des tickets
        document.addEventListener('DOMContentLoaded', function() {
            const tickets = document.querySelectorAll('.ticket');
            
            tickets.forEach(ticket => {
                ticket.addEventListener('mouseenter', function() {
                    this.style.transition = 'transform 0.3s ease';
                });
                
                ticket.addEventListener('mouseleave', function() {
                    this.style.transition = 'transform 0.4s ease';
                });
            });
        });
    </script>
</body>
</html>