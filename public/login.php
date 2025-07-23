<?php
session_start();

// Rediriger si déjà connecté
if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

// Traitement du formulaire
$error = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Identifiants fixés pour l'instant
    if ($username === "admin" && $password === "M.sounna2025") {
        $_SESSION['admin_logged_in'] = true;
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Identifiant ou mot de passe incorrect.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion Admin - DMS-DESIGN</title>
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
            color: var(--text-color);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 450px;
            animation: fadeIn 0.8s ease-out;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo-title-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin-bottom: 15px;
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
            color: white;
            font-weight: 700;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.2);
        }

        .tagline {
            font-size: 1.2rem;
            color: white;
            font-style: italic;
            text-align: center;
            margin-bottom: 5px;
        }

        .login-box {
            background: var(--light-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-medium);
            padding: 40px 35px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        .login-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .login-box h2 {
            text-align: center;
            margin-bottom: 30px;
            color: var(--primary);
            font-size: 1.8rem;
            position: relative;
        }

        .login-box h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 3px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
            padding-left: 5px;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 40px;
            color: var(--primary);
            font-size: 1.1rem;
        }

        .form-group input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .form-group input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(15, 157, 88, 0.2);
        }

        .btn {
            padding: 14px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            display: block;
            width: 100%;
            text-align: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -60%;
            width: 50%;
            height: 200%;
            background: rgba(255,255,255,0.2);
            transform: rotate(30deg);
            transition: all 0.6s;
        }

        .btn:hover::after {
            left: 110%;
        }

        .error {
            color: var(--danger);
            margin-bottom: 20px;
            text-align: center;
            padding: 12px;
            background: rgba(234, 67, 53, 0.1);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--danger);
            animation: shake 0.5s;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
            margin-top: 30px;
        }

        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 500;
        }

        .footer a:hover {
            text-decoration: underline;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
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

            .tagline {
                font-size: 1rem;
            }

            .login-box {
                padding: 30px 25px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo-title-container">
                <img src="dms-design.png" alt="DMS-DESIGN Logo" class="logo">
                <h1>DMS-DESIGN</h1>
            </div>
            <p class="tagline">Optimisez votre réseau WiFi avec notre solution intelligente</p>
        </div>

        <div class="login-box">
            <h2>Connexion Admin</h2>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="username">Utilisateur</label>
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" id="username" name="username" required placeholder="admin">
                </div>
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" required placeholder="••••••••••">
                </div>
                <button type="submit" class="btn">Se connecter</button>
            </form>
        </div>

        <div class="footer">
            <p>Système de gestion WiFi &copy; <?= date('Y') ?> - DMS-DESIGN</p>
            <p>Propriété exclusive de DMS-DESIGN - Tous droits réservés</p>
        </div>
    </div>
</body>
</html>