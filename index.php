<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();

if ($user) {
    redirect(role_home($user['role'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulasi Pengguna - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f8fafc 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            box-sizing: border-box;
        }

        .portal-container {
            width: 100%;
            max-width: 1040px;
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(2, 132, 199, 0.18), 0 0 0 1px rgba(226, 232, 240, 0.8);
            display: grid;
            grid-template-columns: 1.1fr 1fr;
            overflow: hidden;
            min-height: 580px;
            position: relative;
        }

        .portal-visual-side {
            background: linear-gradient(145deg, #0284c7 0%, #0369a1 100%);
            padding: 44px;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .portal-visual-side::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            pointer-events: none;
        }

        .portal-visual-side::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.06);
            pointer-events: none;
        }

        .logo-wrapper {
            background: #ffffff;
            padding: 20px 32px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 14px 35px -8px rgba(0, 0, 0, 0.22);
            margin: 0 auto 24px auto;
            border: 1px solid rgba(255, 255, 255, 0.8);
            width: fit-content;
        }

        .logo-wrapper img {
            height: 76px;
            width: auto;
            max-width: 100%;
            display: block;
            object-fit: contain;
        }

        .visual-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(8px);
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 16px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .visual-title {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.35;
            margin: 0 0 14px 0;
            letter-spacing: -0.5px;
        }

        .visual-desc {
            font-size: 14px;
            line-height: 1.6;
            color: #e0f2fe;
            margin: 0 0 24px 0;
        }

        .portal-info-card {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 20px;
            font-size: 13px;
            text-align: left;
        }

        .portal-info-card h4 {
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 6px 0;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .portal-info-card p {
            margin: 0;
            color: #e0f2fe;
            line-height: 1.5;
        }

        .portal-content-side {
            padding: 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #ffffff;
            position: relative;
        }

        .quick-login-link {
            position: absolute;
            top: 24px;
            right: 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            color: #0284c7;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .quick-login-link:hover {
            background: #e0f2fe;
            color: #0369a1;
        }

        .portal-header h2 {
            font-size: 26px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 6px 0;
        }

        .portal-header p {
            font-size: 13.5px;
            color: #64748b;
            margin: 0 0 28px 0;
            line-height: 1.5;
        }

        .action-card-btn {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 18px 22px;
            border: 2px solid #0284c7;
            border-radius: 16px;
            text-decoration: none;
            color: #ffffff;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            box-shadow: 0 8px 20px -4px rgba(2, 132, 199, 0.3);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            margin-bottom: 20px;
        }

        .action-card-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 25px -4px rgba(2, 132, 199, 0.4);
        }

        .action-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }

        .action-text h3 {
            font-size: 16px;
            font-weight: 800;
            margin: 0 0 3px 0;
            color: #ffffff;
        }

        .action-text p {
            font-size: 12.5px;
            color: #e0f2fe;
            margin: 0;
        }

        .action-arrow {
            margin-left: auto;
            color: #ffffff;
            font-size: 16px;
            transition: transform 0.2s ease;
        }

        .action-card-btn:hover .action-arrow {
            transform: translateX(4px);
        }

        .login-note-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
        }

        @media (max-width: 860px) {
            .portal-container {
                grid-template-columns: 1fr;
            }
            .portal-visual-side, .portal-content-side {
                padding: 32px 24px;
            }
            .quick-login-link {
                top: 14px;
                right: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="portal-container">
        <!-- LEFT VISUAL SIDE (MODERN & CENTERED LOGO) -->
        <div class="portal-visual-side">
            <div>
                <div class="logo-wrapper">
                    <img src="assets/logo-karirhub.png" alt="Karirhub oleh Kemnaker">
                </div>

                <div class="visual-pill">
                    <i class="fa-solid fa-users"></i>
                    Pilih Mode Simulasi
                </div>

                <h1 class="visual-title">Simulasi Pengguna</h1>
                <p class="visual-desc">
                    Pilih jenis pengguna terlebih dahulu sebelum masuk ke halaman autentikasi SIAPkerja.
                </p>
            </div>

            <div class="portal-info-card">
                <h4><i class="fa-solid fa-circle-info"></i> Catatan Simulasi</h4>
                <p>Setiap pilihan pengguna akan diarahkan ke halaman <strong>login</strong> yang sama untuk simulasi alur akses.</p>
            </div>
        </div>

        <!-- RIGHT CONTENT SIDE (ORIGINAL EMPLOYER FLOW & LOGIN ACTION) -->
        <div class="portal-content-side">
            <a class="quick-login-link" href="login.php">
                <i class="fa-solid fa-right-to-bracket"></i>
                Login
            </a>

            <div class="portal-header">
                <h2>Daftar Sebagai</h2>
                <p>Pilih peran pengguna untuk mulai simulasi:</p>
            </div>

            <a href="employer-menu.php" class="action-card-btn">
                <div class="action-icon">
                    <i class="fa-solid fa-building-user"></i>
                </div>
                <div class="action-text">
                    <h3>Employer</h3>
                    <p>Pemberi Kerja Individu / Perusahaan</p>
                </div>
                <i class="fa-solid fa-chevron-right action-arrow"></i>
            </a>

            <div class="login-note-box">
                <i class="fa-solid fa-shield-halved" style="color:#0284c7; margin-right:6px;"></i>
                Sudah memiliki Akun SIAPkerja? Klik tombol <strong>Login</strong> di sudut kanan atas untuk langsung masuk.
            </div>
        </div>
    </div>
</body>
</html>
