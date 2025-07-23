<?php
session_start();
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Set the default timezone for PHP (important for date-based queries)
date_default_timezone_set('Africa/Niamey');

// Connexion à la base de données
try {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=mikrotik_tickets", "postgres", "4484");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $_SESSION['debug_dashboard'][] = "Connexion à la base de données établie.";
} catch (PDOException $e) {
    $_SESSION['debug_dashboard'][] = "Erreur de connexion à la base de données: " . $e->getMessage();
    // It's crucial to stop execution if DB connection fails, as no data can be retrieved.
    die("Impossible de se connecter à la base de données: " . $e->getMessage());
}

// Message de débogage (initialisation si non déjà fait)
if (!isset($_SESSION['debug_dashboard'])) {
    $_SESSION['debug_dashboard'] = [];
}

// --- Database Data Retrieval ---

// Nombre total de tickets générés
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets");
    $total_tickets_generated = $stmt->fetchColumn();
    $total_tickets_generated = $total_tickets_generated ?? 0;
    $_SESSION['debug_dashboard'][] = "Nombre total de tickets générés récupéré: " . $total_tickets_generated;
} catch (PDOException $e) {
    $total_tickets_generated = 0;
    $_SESSION['debug_dashboard'][] = "Erreur de récupération du total des tickets générés: " . $e->getMessage();
}

// Nombre de tickets actifs (simulé par 'activated_at IS NOT NULL')
// IMPORTANT: For a real system, 'Tickets Actifs' should come from MikroTik's active users.
// This query only counts tickets that have been marked as 'activated' in the DB.
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE activated_at IS NOT NULL");
    $total_active_tickets_mikrotik = $stmt->fetchColumn();
    $total_active_tickets_mikrotik = $total_active_tickets_mikrotik ?? 0;
    $_SESSION['debug_dashboard'][] = "Nombre de tickets actifs (activés en DB) récupéré: " . $total_active_tickets_mikrotik;
} catch (PDOException $e) {
    $total_active_tickets_mikrotik = 0;
    $_SESSION['debug_dashboard'][] = "Erreur de récupération des tickets actifs (DB): " . $e->getMessage();
}

// Nombre de tickets expirés (archivés dans la table deleted_tickets)
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM deleted_tickets");
    $total_expired_tickets = $stmt->fetchColumn();
    $total_expired_tickets = $total_expired_tickets ?? 0;
    $_SESSION['debug_dashboard'][] = "Nombre total de tickets expirés (archivés) récupéré: " . $total_expired_tickets;
} catch (PDOException $e) {
    $total_expired_tickets = 0;
    $_SESSION['debug_dashboard'][] = "Erreur de récupération du total des tickets expirés: " . $e->getMessage();
}

// Revenu total estimé (somme des prix de TOUS les tickets)
try {
    $stmt = $pdo->query("SELECT COALESCE(SUM(price), 0) FROM tickets");
    $total_revenue = $stmt->fetchColumn();
    $_SESSION['debug_dashboard'][] = "Revenu total estimé récupéré: " . $total_revenue;
} catch (PDOException $e) {
    $total_revenue = 0;
    $_SESSION['debug_dashboard'][] = "Erreur de récupération du revenu total: " . $e->getMessage();
}

// Nombre de tickets générés aujourd'hui
try {
    $today_date = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(generated_at) = :today_date");
    $stmt->execute(['today_date' => $today_date]);
    $tickets_generated_today = $stmt->fetchColumn();
    $tickets_generated_today = $tickets_generated_today ?? 0;
    $_SESSION['debug_dashboard'][] = "Tickets générés aujourd'hui: " . $tickets_generated_today;
} catch (PDOException $e) {
    $tickets_generated_today = 0;
    $_SESSION['debug_dashboard'][] = "Erreur de récupération des tickets générés aujourd'hui: " . $e->getMessage();
}

// Nombre de tickets activés aujourd'hui
try {
    $today_date = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(activated_at) = :today_date");
    $stmt->execute(['today_date' => $today_date]);
    $tickets_activated_today = $stmt->fetchColumn();
    $tickets_activated_today = $tickets_activated_today ?? 0;
    $_SESSION['debug_dashboard'][] = "Tickets activés aujourd'hui: " . $tickets_activated_today;
} catch (PDOException $e) {
    $tickets_activated_today = 0;
    $_SESSION['debug_dashboard'][] = "Erreur de récupération des tickets activés aujourd'hui: " . $e->getMessage();
}

// Graphique: Distribution des forfaits
$forfait_distribution_data = [];
try {
    $stmt = $pdo->query("SELECT duree, COUNT(*) as count FROM tickets GROUP BY duree ORDER BY count DESC");
    $forfait_distribution_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $_SESSION['debug_dashboard'][] = "Données de distribution des forfaits récupérées.";
} catch (PDOException $e) {
    $_SESSION['debug_dashboard'][] = "Erreur de récupération de la distribution des forfaits: " . $e->getMessage();
}
$forfait_labels = json_encode(array_column($forfait_distribution_data, 'duree'));
$forfait_counts = json_encode(array_column($forfait_distribution_data, 'count'));


// Graphique: Revenu par forfait (estimation)
$revenue_by_forfait_data = [];
try {
    $stmt = $pdo->query("SELECT duree, COALESCE(SUM(price), 0) as total_price FROM tickets GROUP BY duree ORDER BY total_price DESC");
    $revenue_by_forfait_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $_SESSION['debug_dashboard'][] = "Données de revenu par forfait récupérées.";
} catch (PDOException $e) {
    $_SESSION['debug_dashboard'][] = "Erreur de récupération du revenu par forfait: " . $e->getMessage();
}
$revenue_forfait_labels = json_encode(array_column($revenue_by_forfait_data, 'duree'));
$revenue_forfait_amounts = json_encode(array_column($revenue_by_forfait_data, 'total_price'));


// Graphique: Tickets générés sur les 7 derniers jours
$tickets_last_7_days = [];
try {
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE DATE(generated_at) = :date");
        $stmt->execute(['date' => $date]);
        $tickets_last_7_days[date('D', strtotime($date))] = $stmt->fetchColumn() ?? 0; // 'D' for Mon, Tue etc.
    }
    $_SESSION['debug_dashboard'][] = "Tickets générés sur les 7 derniers jours récupérés.";
} catch (PDOException $e) {
    $_SESSION['debug_dashboard'][] = "Erreur de récupération des tickets générés sur les 7 derniers jours: " . $e->getMessage();
}

$last_7_days_labels = json_encode(array_keys($tickets_last_7_days));
$last_7_days_counts = json_encode(array_values($tickets_last_7_days));


?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - M.SOUNNA WIFI-ZONE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Nouveau style pour la phrase d'accroche */
        .tagline {
            font-size: 1.1rem;
            color: #5f6368; /* Gris doux */
            font-style: italic;
            margin-top: 3px; /* Réduction de la marge supérieure */
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
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
        }

        .header-left {
            display: flex;
            flex-direction: column; /* Changement pour aligner verticalement */
            align-items: flex-start;
            gap: 5px; /* Réduit l'espace entre les éléments */
        }

        /* Conteneur pour logo et titre */
        .logo-title-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-left .logo {
            height: 50px; /* Adjust as needed */
            width: auto;
            border-radius: 8px; /* Slightly rounded corners for logo */
        }

        .header-left h1 {
            font-family: 'Playfair Display', serif; /* A nice, elegant font */
            font-size: 2.2rem; /* Slightly larger */
            color: var(--gold-color); /* Gold color for DMS-DESIGN */
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2); /* Subtle shadow for depth */
        }

        .header-right {
            display: flex; /* Use flexbox for time and logout button */
            align-items: center;
            gap: 20px; /* Space between time and button */
        }

        .header-right .datetime-info {
            text-align: right;
            margin-right: 10px; /* Space before buttons if needed */
        }

        .header-right p {
            font-size: 1rem;
            color: var(--light-text-color);
            margin: 2px 0;
        }

        .header-right .current-time {
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
            text-decoration: none; /* For links styled as buttons */
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
            background: #357ae8; /* Darker blue on hover */
        }

        .btn-danger {
            background: var(--danger);
        }
        .btn-danger:hover {
            background: #c9342a; /* Darker red on hover */
        }


        /* Summary Cards */
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
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: var(--primary); /* Default icon color */
        }

        .summary-card .count {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .summary-card .label {
            font-size: 1rem;
            color: var(--light-text-color);
            font-weight: 500;
        }

        /* Specific icon colors for summary cards */
        .summary-card.total-tickets .icon { color: var(--secondary); }
        .summary-card.active-tickets .icon { color: var(--primary); }
        .summary-card.expired-tickets .icon { color: var(--danger); }
        .summary-card.revenue .icon { color: var(--info); }
        .summary-card.generated-today .icon { color: var(--accent); }
        .summary-card.activated-today .icon { color: var(--primary-dark); }

        /* Navigation Buttons below summary cards */
        .navigation-buttons {
            display: flex;
            flex-wrap: wrap; /* Allow wrapping */
            gap: 15px; /* Space between buttons */
            justify-content: center; /* Center buttons */
            margin-top: 20px;
            margin-bottom: 40px; /* More space before charts/debug */
        }

        .navigation-buttons .btn {
            min-width: 180px; /* Ensure buttons have a consistent minimum width */
            justify-content: flex-start; /* Align icon and text to start */
        }

        /* Chart Cards */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            padding: 25px;
            transition: var(--transition-speed);
            border: 1px solid var(--border-color);
        }

        .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-medium);
        }

        .chart-card h2 {
            font-size: 1.4rem;
            color: var(--text-color);
            margin-bottom: 20px;
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        /* Ensure canvas takes full width and has a base height */
        .chart-card canvas {
            width: 100% !important; /* Override Chart.js default inline style */
            height: 300px !important; /* Set a base height for responsiveness */
        }

        /* Debug Section */
        .debug-container {
            margin-top: 30px;
        }

        .debug {
            background: var(--dark-bg);
            color: #e0e0e0;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow-light);
        }

        .debug-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .debug-header h3 {
            color: var(--secondary-light);
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            font-size: 1.2rem;
        }

        .debug-header .toggle-icon {
            color: var(--secondary-light);
            font-size: 1.5rem;
            transition: transform 0.3s ease;
        }

        .debug-header .toggle-icon.rotated {
            transform: rotate(90deg);
        }

        .debug-content {
            display: none; /* Hidden by default */
        }

        .debug-content ul {
            list-style-type: none;
            padding-left: 0;
        }

        .debug-content li {
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-family: monospace;
            font-size: 0.9rem;
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

        /* Footer amélioré */
        .footer {
            text-align: center;
            padding: 20px; /* Ajustement du padding */
            color: var(--light-text-color);
            font-size: 0.9rem;
            margin-top: 30px;
            background: var(--light-bg);
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            gap: 5px; /* Espace entre les lignes */
        }

        .footer p {
            margin: 0; /* Suppression des marges par défaut */
        }

        .footer a {
            color: var(--primary);
            text-decoration: none;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
                padding: 15px 20px;
            }

            .header-right {
                width: 100%;
                text-align: left;
                margin-top: 10px;
                justify-content: space-between; /* Distribute time and logout button */
            }

            .header-left h1 {
                font-size: 1.5rem;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .summary-card, .chart-card {
                padding: 20px;
            }

            .summary-card .icon {
                font-size: 2rem;
            }

            .summary-card .count {
                font-size: 1.8rem;
            }
            .charts-grid {
                grid-template-columns: 1fr; /* Stack charts on small screens */
            }
            .navigation-buttons {
                justify-content: center; /* Center buttons on small screens */
            }
            
            /* Adaptation responsive pour la phrase d'accroche */
            .tagline {
                font-size: 0.9rem;
                margin-top: 2px;
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
                <!-- Phrase d'accroche ajoutée ici -->
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

        <div class="summary-cards-container">
            <div class="summary-card total-tickets">
                <i class="fas fa-chart-line icon"></i>
                <div class="count"><?= $total_tickets_generated ?></div>
                <div class="label">Tickets Générés</div>
            </div>
            <div class="summary-card active-tickets">
                <i class="fas fa-wifi icon"></i>
                <div class="count"><?= $total_active_tickets_mikrotik ?></div>
                <div class="label">Tickets Actifs (Activés)</div>
            </div>
            <div class="summary-card expired-tickets">
                <i class="fas fa-archive icon"></i>
                <div class="count"><?= $total_expired_tickets ?></div>
                <div class="label">Tickets Archivés</div>
            </div>
            <div class="summary-card revenue">
                <i class="fas fa-dollar-sign icon"></i>
                <div class="count"><?= number_format($total_revenue, 0, ',', '.') ?> F CFA</div>
                <div class="label">Revenu Estimé</div>
            </div>
            <div class="summary-card generated-today">
                <i class="fas fa-calendar-plus icon"></i>
                <div class="count"><?= $tickets_generated_today ?></div>
                <div class="label">Générés Aujourd'hui</div>
            </div>
            <div class="summary-card activated-today">
                <i class="fas fa-user-check icon"></i>
                <div class="count"><?= $tickets_activated_today ?></div>
                <div class="label">Activés Aujourd'hui</div>
            </div>
        </div>

       <div class="navigation-buttons">
    <a href="../public/generate_ticket.php" class="btn">
        <i class="fas fa-plus-circle"></i> Générer des tickets
    </a>
    <a href="../scripts/connected_users.php" class="btn btn-secondary">
        <i class="fas fa-users"></i> Utilisateurs connectés
    </a>
    <a href="../scripts/tickets.php" class="btn">
        <i class="fas fa-ticket-alt"></i> Gérer les tickets
    </a>
    <a href="../public/revenus.php" class="btn btn-secondary">
        <i class="fas fa-money-bill-wave"></i> Rapports de revenus
    </a>
</div>

        <div class="charts-grid">
            <div class="chart-card">
                <h2><i class="fas fa-chart-pie"></i> Distribution des Forfaits</h2>
                <canvas id="forfaitDistributionChart"></canvas>
            </div>
            <div class="chart-card">
                <h2><i class="fas fa-dollar-sign"></i> Revenu par Forfait</h2>
                <canvas id="revenueByForfaitChart"></canvas>
            </div>
            <div class="chart-card">
                <h2><i class="fas fa-chart-bar"></i> Tickets Générés (7 Derniers Jours)</h2>
                <canvas id="ticketsLast7DaysChart"></canvas>
            </div>
        </div>

        <?php if (!empty($_SESSION['debug_dashboard'])): ?>
            <div class="debug-container">
                <div class="debug">
                    <div class="debug-header" id="debugToggle">
                        <h3><i class="fas fa-bug"></i> Journal de débogage</h3>
                        <i class="fas fa-chevron-right toggle-icon"></i>
                    </div>
                    <div class="debug-content" id="debugContent">
                        <ul>
                            <?php foreach ($_SESSION['debug_dashboard'] as $log): ?>
                                <li><?= htmlspecialchars($log) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['debug_dashboard']); // Clear debug logs after display ?>
        <?php endif; ?>
    </div>

    <footer class="footer">
        <p>Système de gestion WiFi &copy; <?= date('Y') ?> - DMS-DESIGN</p>
        <!-- Seconde ligne ajoutée -->
        <p>Propriété exclusive de DMS-DESIGN - Tous droits réservés</p>
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        // Chart.js configurations
        document.addEventListener('DOMContentLoaded', function() {
            // Chart: Distribution des Forfaits
            new Chart(document.getElementById('forfaitDistributionChart'), {
                type: 'doughnut',
                data: {
                    labels: <?= $forfait_labels ?>,
                    datasets: [{
                        data: <?= $forfait_counts ?>,
                        backgroundColor: [
                            '#4285F4', // Blue
                            '#0F9D58', // Green
                            '#FBBC05', // Yellow
                            '#EA4335', // Red
                            '#34A853', // Another Green
                            '#A793FB', // Light Purple
                            '#4CAF50', // Material Green
                            '#FFC107', // Material Amber
                        ],
                        hoverOffset: 10,
                        borderWidth: 1,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // Crucial for independent sizing
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: 'var(--text-color)',
                                font: {
                                    size: 12,
                                    family: 'Poppins'
                                }
                            }
                        },
                        title: {
                            display: false,
                        },
                        tooltip: {
                            backgroundColor: 'var(--dark-bg)',
                            titleFont: { size: 14, family: 'Poppins' },
                            bodyFont: { size: 12, family: 'Poppins' },
                            padding: 10,
                            boxPadding: 5,
                            displayColors: true,
                            bodyColor: '#e0e0e0',
                            titleColor: '#fff',
                        }
                    }
                }
            });

            // Chart: Revenu par Forfait
            new Chart(document.getElementById('revenueByForfaitChart'), {
                type: 'bar',
                data: {
                    labels: <?= $revenue_forfait_labels ?>,
                    datasets: [{
                        label: 'Revenu (F CFA)',
                        data: <?= $revenue_forfait_amounts ?>,
                        backgroundColor: [
                            '#0F9D58', // Green
                            '#4285F4', // Blue
                            '#FBBC05', // Yellow
                            '#EA4335', // Red
                            '#34A853', // Another Green
                            '#A793FB', // Light Purple
                            '#4CAF50', // Material Green
                            '#FFC107', // Material Amber
                        ],
                        borderColor: 'var(--primary-dark)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // Crucial for independent sizing
                    plugins: {
                        legend: {
                            display: false,
                        },
                        title: {
                            display: false,
                        },
                        tooltip: {
                            backgroundColor: 'var(--dark-bg)',
                            titleFont: { size: 14, family: 'Poppins' },
                            bodyFont: { size: 12, family: 'Poppins' },
                            padding: 10,
                            boxPadding: 5,
                            displayColors: true,
                            bodyColor: '#e0e0e0',
                            titleColor: '#fff',
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'XOF' }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: 'var(--text-color)',
                                font: {
                                    family: 'Poppins'
                                }
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: 'var(--text-color)',
                                font: {
                                    family: 'Poppins'
                                },
                                callback: function(value) {
                                    return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'XOF', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value);
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        }
                    }
                }
            });

            // Chart: Tickets Générés (7 Derniers Jours)
            new Chart(document.getElementById('ticketsLast7DaysChart'), {
                type: 'line',
                data: {
                    labels: <?= $last_7_days_labels ?>,
                    datasets: [{
                        label: 'Tickets Générés',
                        data: <?= $last_7_days_counts ?>,
                        backgroundColor: 'rgba(66, 133, 244, 0.2)', // Google Blue with transparency
                        borderColor: 'var(--secondary)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: 'var(--secondary)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'var(--secondary)',
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // Crucial for independent sizing
                    plugins: {
                        legend: {
                            display: false,
                        },
                        title: {
                            display: false,
                        },
                        tooltip: {
                            backgroundColor: 'var(--dark-bg)',
                            titleFont: { size: 14, family: 'Poppins' },
                            bodyFont: { size: 12, family: 'Poppins' },
                            padding: 10,
                            boxPadding: 5,
                            displayColors: false,
                            bodyColor: '#e0e0e0',
                            titleColor: '#fff',
                        }
                    },
                    scales: {
                        x: {
                            ticks: {
                                color: 'var(--text-color)',
                                font: {
                                    family: 'Poppins'
                                }
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: 'var(--text-color)',
                                font: {
                                    family: 'Poppins'
                                },
                                precision: 0 // Ensure integer ticks for counts
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        }
                    }
                }
            });

            // Toggle debug content
            $('#debugToggle').on('click', function() {
                $('#debugContent').slideToggle(300);
                $(this).find('.toggle-icon').toggleClass('rotated');
            });
        });
    </script>
</body>
</html>