<?php
session_start();
if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Set timezone
date_default_timezone_set('Africa/Niamey');

try {
    $pdo = new PDO("pgsql:host=localhost;port=5432;dbname=mikrotik_tickets", "postgres", "4484");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Statistiques par forfait
    $stmt = $pdo->query("
        SELECT duree, price,
            COUNT(*) AS total_tickets,
            COUNT(activated_at) AS tickets_utilises,
            SUM(price) AS total_revenu
        FROM tickets
        GROUP BY duree, price
        ORDER BY price ASC
    ");
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total général (tous tickets générés)
    $totalGenere = $pdo->query("SELECT COALESCE(SUM(price), 0) FROM tickets")->fetchColumn();

    // Total consommé (tickets activés)
    $totalConsomme = $pdo->query("SELECT COALESCE(SUM(price), 0) FROM tickets WHERE activated_at IS NOT NULL")->fetchColumn();

} catch (PDOException $e) {
    die("Erreur DB : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenus - M.SOUNNA WIFI-ZONE</title>
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

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 16px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        thead th {
            background-color: var(--primary);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }

        tbody tr {
            transition: background-color 0.2s;
        }

        tbody tr:hover {
            background-color: rgba(66, 133, 244, 0.05);
        }

        tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }

        .highlight {
            font-weight: 700;
            color: var(--primary-dark);
        }

        .revenue-total {
            background-color: rgba(15, 157, 88, 0.1);
            border-left: 4px solid var(--primary);
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .summary-card {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-light);
            padding: 25px;
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
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: var(--primary);
            background: rgba(15, 157, 88, 0.1);
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .summary-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }

        .summary-card .label {
            font-size: 1.1rem;
            color: var(--light-text-color);
            font-weight: 500;
        }

        .summary-card.revenue-total .icon { 
            color: var(--primary); 
        }

        .summary-card.consumed .icon { 
            color: var(--secondary); 
            background: rgba(66, 133, 244, 0.1);
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: var(--light-text-color);
            font-size: 0.9rem;
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
                justify-content: space-between;
            }

            .header-left h1 {
                font-size: 1.5rem;
            }

            .tagline {
                font-size: 0.9rem;
                margin-top: 2px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .page-title {
                font-size: 1.5rem;
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            table, thead, tbody, th, td, tr {
                display: block;
            }
            
            thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            
            tr {
                border: 1px solid var(--border-color);
                border-radius: var(--border-radius);
                margin-bottom: 15px;
                box-shadow: var(--shadow-light);
            }
            
            td {
                border: none;
                border-bottom: 1px solid var(--border-color);
                position: relative;
                padding-left: 50%;
                text-align: right;
            }
            
            td:before {
                position: absolute;
                left: 10px;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
                content: attr(data-label);
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
            <i class="fas fa-chart-line"></i> Rapport Financier des Tickets
        </h1>

        <div class="revenue-card">
            <h2><i class="fas fa-table"></i> Statistiques par Forfait</h2>
            <table>
                <thead>
                    <tr>
                        <th>Durée</th>
                        <th>Prix</th>
                        <th>Tickets générés</th>
                        <th>Tickets activés</th>
                        <th>Revenus</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats as $row): ?>
                        <tr>
                            <td data-label="Durée"><?= htmlspecialchars($row['duree']) ?></td>
                            <td data-label="Prix"><?= number_format($row['price'], 0, ',', ' ') ?> FCFA</td>
                            <td data-label="Tickets générés"><?= $row['total_tickets'] ?></td>
                            <td data-label="Tickets activés"><?= $row['tickets_utilises'] ?></td>
                            <td data-label="Revenus" class="highlight"><?= number_format($row['total_revenu'], 0, ',', ' ') ?> FCFA</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="summary-cards">
            <div class="summary-card revenue-total">
                <div class="icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="value"><?= number_format($totalGenere, 0, ',', ' ') ?> FCFA</div>
                <div class="label">Revenus Totaux Générés</div>
            </div>
            
            <div class="summary-card consumed">
                <div class="icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="value"><?= number_format($totalConsomme, 0, ',', ' ') ?> FCFA</div>
                <div class="label">Revenus Consommés</div>
            </div>
        </div>
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

        // Make table rows responsive
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth <= 768) {
                const headers = document.querySelectorAll('th');
                const rows = document.querySelectorAll('tbody tr');
                
                headers.forEach((header, index) => {
                    const label = header.textContent;
                    rows.forEach(row => {
                        const cell = row.querySelectorAll('td')[index];
                        if (cell) {
                            cell.setAttribute('data-label', label);
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>