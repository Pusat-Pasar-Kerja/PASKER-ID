<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect(role_home(current_user()['role'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Pendaftaran - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #101828;
            -webkit-font-smoothing: antialiased;
        }

        /* Top Header Navbar */
        .top-nav-bar {
            height: 68px;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .top-nav-left {
            display: flex;
            align-items: center;
            flex: 1;
        }

        .nav-back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .nav-back-link:hover {
            color: #0284c7;
        }

        .top-nav-center {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-logo-link {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .brand-logo-icon {
            width: 34px;
            height: 34px;
        }

        .brand-logo-text {
            display: flex;
            flex-direction: column;
        }

        .brand-name {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            line-height: 1.05;
        }

        .brand-sub {
            font-size: 10px;
            font-weight: 600;
            color: #64748b;
            margin-top: 1px;
        }

        .top-nav-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex: 1;
        }

        .nav-admin-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #1e293b;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }

        .admin-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #475467;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            overflow: hidden;
            border: 1px solid #cbd5e1;
        }

        .admin-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Container Shell */
        .registration-shell {
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 36px 20px 24px;
        }

        .registration-card {
            width: 100%;
            max-width: 540px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 36px 36px 28px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.03);
        }

        .registration-title {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: -0.3px;
        }

        .registration-subtitle {
            margin: 8px 0 28px;
            font-size: 13.5px;
            color: #64748b;
            line-height: 1.5;
        }

        /* Option Wrapper (Gray Container) */
        .option-wrapper {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .registration-option {
            display: block;
            text-decoration: none;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            color: inherit;
            position: relative;
            transition: all 0.2s ease;
        }

        .registration-option:hover:not(.disabled) {
            border-color: #2590F9;
            box-shadow: 0 4px 16px rgba(37, 144, 249, 0.1);
        }

        .registration-option.disabled {
            background: #ffffff;
            border-color: #e2e8f0;
            cursor: not-allowed;
            opacity: 0.95;
        }

        .registration-option-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .registration-option-left {
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }

        .registration-option-left i {
            font-size: 20px;
            color: #2590F9;
            width: 24px;
            text-align: center;
        }

        .registration-option h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }

        .registration-option-arrow {
            color: #94a3b8;
            font-size: 14px;
        }

        .registration-option p {
            margin: 0;
            font-size: 13px;
            color: #94a3b8;
            line-height: 1.55;
        }

        .registration-option:not(.disabled) p {
            color: #64748b;
        }

        .coming-soon-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            background: #fde047;
            color: #854d0e;
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
        }

        .option-help-link {
            margin-top: 12px;
            padding-left: 2px;
        }

        .option-help-link a {
            color: #64748b;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            font-weight: 400;
            transition: color 0.15s ease;
        }

        .option-help-link a:hover {
            color: #2590F9;
        }

        .registration-footer {
            margin-top: 24px;
            text-align: center;
        }

        .registration-footer a {
            color: #475467;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }

        .registration-footer a:hover {
            color: #0f172a;
        }

        /* Bottom Help Footer */
        .page-help-footer {
            margin-top: 28px;
            margin-bottom: 32px;
            text-align: center;
            font-size: 13.5px;
            color: #64748b;
        }

        .page-help-footer p {
            margin: 0 0 10px;
        }

        .page-help-footer a {
            color: #2590F9;
            font-weight: 600;
            text-decoration: none;
        }

        .help-contact-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            font-size: 13px;
            font-weight: 600;
            color: #475467;
        }

        .contact-sep {
            color: #cbd5e1;
        }

        @media (max-width: 640px) {
            .top-nav-bar { padding: 0 16px; }
            .nav-admin-link span { display: none; }
            .registration-card { padding: 24px 20px; }
            .help-contact-row { flex-direction: column; gap: 6px; }
            .contact-sep { display: none; }
        }
    </style>
</head>
<body>
    <!-- Top Header Navbar -->
    <header class="top-nav-bar">
        <div class="top-nav-left">
            <a href="index.php" class="nav-back-link">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Halaman Utama
            </a>
        </div>
        <div class="top-nav-center">
            <a href="index.php" class="brand-logo-link">
                <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" class="brand-logo-icon">
                    <circle cx="73" cy="22" r="13" fill="#2590F9" />
                    <path d="M22 32 C15.37 32 10 37.37 10 44 C10 50.63 15.37 56 22 56 H42 C44.2 56 46 57.8 46 60 V76 C46 82.63 51.37 88 58 88 C64.63 88 70 82.63 70 76 V50 C70 40.06 61.94 32 52 32 H22 Z" fill="#2590F9" />
                </svg>
                <div class="brand-logo-text">
                    <span class="brand-name">Karirhub</span>
                    <span class="brand-sub">oleh Kemnaker</span>
                </div>
            </a>
        </div>
        <div class="top-nav-right">
            <a href="admin.php" class="nav-admin-link" title="Dasbor Pengelola">
                <span>Dasbor Pengelola</span>
                <div class="admin-avatar">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
            </a>
        </div>
    </header>

    <!-- Main Container Shell -->
    <div class="registration-shell">
        <div class="registration-card">
            <h1 class="registration-title">Pilih pendaftaran</h1>
            <p class="registration-subtitle">Pilih pendaftaran yang Anda inginkan pada akun Anda.</p>

            <!-- Option 1: Pencari Kerja -->
            <div class="option-wrapper">
                <div class="registration-option disabled">
                    <span class="coming-soon-badge">Akan datang</span>
                    <div class="registration-option-head">
                        <div class="registration-option-left">
                            <i class="fa-solid fa-user"></i>
                            <h3>Pencari Kerja</h3>
                        </div>
                        <i class="fa-solid fa-chevron-right registration-option-arrow"></i>
                    </div>
                    <p>Perorangan yang membutuhkan pekerjaan, dan dapat melamar pada lowongan pekerjaan yang tersedia.</p>
                </div>
                <div class="option-help-link">
                    <a href="#" onclick="alert('Pencari Kerja adalah perorangan yang mencari dan melamar pekerjaan di platform Karirhub.'); return false;">
                        Apa itu Pencari Kerja <i class="fa-regular fa-circle-question"></i>
                    </a>
                </div>
            </div>

            <!-- Option 2: Pemberi Kerja -->
            <div class="option-wrapper">
                <a class="registration-option" href="employer-type.php">
                    <div class="registration-option-head">
                        <div class="registration-option-left">
                            <i class="fa-solid fa-building"></i>
                            <h3>Pemberi Kerja</h3>
                        </div>
                        <i class="fa-solid fa-chevron-right registration-option-arrow"></i>
                    </div>
                    <p>Perorangan atau instansi yang dapat mengelola lowongan pekerjaan yang tersedia.</p>
                </a>
                <div class="option-help-link">
                    <a href="#" onclick="alert('Pemberi Kerja adalah individu atau badan usaha/instansi yang membuka dan mengelola lowongan pekerjaan.'); return false;">
                        Apa itu Pemberi Kerja <i class="fa-regular fa-circle-question"></i>
                    </a>
                </div>
            </div>

            <div class="registration-footer">
                <a href="index.php">Kembali ke halaman awal</a>
            </div>
        </div>

        <!-- Bottom Help Footer -->
        <footer class="page-help-footer">
            <p>Butuh bantuan? <a href="#">Kunjungi Pusat Bantuan</a> atau hubungi kami</p>
            <div class="help-contact-row">
                <span><i class="fa-brands fa-whatsapp"></i> 0811-871-2018</span>
                <span class="contact-sep">|</span>
                <span><i class="fa-regular fa-envelope"></i> pusatpasarkerja@kemnaker.go.id</span>
            </div>
        </footer>
    </div>
</body>
</html>
