<?php
// logout.php
session_start();

// Détruire la session
$_SESSION = [];
session_destroy();

// Message de déconnexion
$message = "Vous avez été déconnecté avec succès. Redirection en cours...";

// Redirection après 5 secondes
$redirect_url = "login.php";
$redirect_delay = 5; // en secondes
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déconnexion - DMS-DESIGN</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0f9d58; /* Google Green */
            --primary-dark: #0c7c45;
            --secondary: #4285f4; /* Google Blue */
            --accent: #fbbc05; /* Google Yellow */
            --danger: #ea4335; /* Google Red */
            --info: #34a853; /* Another Green shade */
            --light-bg: #ffffff; /* Card background */
            --body-bg: #f5f7fa; /* Light grey background */
            --text-color: #3c4043; /* Dark grey text */
            --light-text-color: #5f6368;
            --border-color: #dadce0;
            --shadow-light: 0 4px 15px rgba(0, 0, 0, 0.08);
            --shadow-medium: 0 8px 25px rgba(0, 0, 0, 0.12);
            --border-radius: 12px;
            --gold-color: #FFD700; /* Gold for DMS-DESIGN text */
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #0f9d58, #4285f4);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .logout-container {
            width: 100%;
            max-width: 500px;
            text-align: center;
            animation: fadeIn 0.8s ease-out;
        }

        .logout-box {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            padding: 40px;
            position: relative;
            overflow: hidden;
        }

        .logout-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .logo-title-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 30px;
        }

        .logo-title-container img {
            height: 60px;
            width: auto;
            border-radius: 8px;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }

        .logo-title-container h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem;
            color: var(--primary);
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.1);
        }

        .logout-icon {
            font-size: 5rem;
            color: var(--primary);
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }

        .logout-message {
            color: var(--text-color);
            font-size: 1.5rem;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .countdown {
            display: inline-block;
            font-weight: bold;
            color: var(--secondary);
            font-size: 1.6rem;
            min-width: 30px;
        }

        .redirect-info {
            color: var(--light-text-color);
            margin-bottom: 30px;
            font-size: 1.1rem;
        }

        .btn {
            padding: 14px 30px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            display: inline-block;
            text-decoration: none;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
            margin-top: 10px;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
            margin-top: 30px;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .logo-title-container {
                flex-direction: column;
                text-align: center;
            }

            .logo-title-container h1 {
                font-size: 2rem;
            }

            .logout-box {
                padding: 30px 20px;
            }

            .logout-message {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <div class="logout-box">
            <div class="logo-title-container">
                <img src="dms-design.png" alt="DMS-DESIGN Logo" class="logo">
                <h1>DMS-DESIGN</h1>
            </div>
            
            <div class="logout-icon">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            
            <div class="logout-message">
                Vous avez été déconnecté avec succès
            </div>
            
            <div class="redirect-info">
                Redirection vers la page de connexion dans 
                <span id="countdown" class="countdown"><?= $redirect_delay ?></span> 
                secondes...
            </div>
            
            <a href="login.php" class="btn">
                <i class="fas fa-sign-in-alt"></i> Se connecter maintenant
            </a>
        </div>
        
        <div class="footer">
            <p>Système de gestion WiFi &copy; <?= date('Y') ?> - DMS-DESIGN</p>
            <p>Propriété exclusive de DMS-DESIGN - Tous droits réservés</p>
        </div>
    </div>

    <script>
        // Compte à rebours pour la redirection
        let countdown = <?= $redirect_delay ?>;
        const countdownElement = document.getElementById('countdown');
        
        const countdownInterval = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(countdownInterval);
                window.location.href = "<?= $redirect_url ?>";
            }
        }, 1000);
    </script>
</body>
</html>