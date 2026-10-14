<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user() && !isset($_GET['success'])) {
    redirect('index.php');
}

$showSuccessPopup = isset($_GET['success']) && $_GET['success'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['register_action'] ?? '') === 'simulate_employer_session') {
    try {
        $ownerName = trim($_POST['owner_name'] ?? '');
        if ($ownerName === '') {
            $ownerName = 'Pemberi Kerja Individu';
        }

        $cleanSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', trim($ownerName)));
        $cleanSlug = trim($cleanSlug, '.');
        if ($cleanSlug === '') {
            $cleanSlug = 'employer';
        }
        $uniqueEmail = $cleanSlug . '@paskerid.test';
        if (find_user_by_email($uniqueEmail)) {
            $uniqueEmail = $cleanSlug . '.' . date('YmdHis') . '@paskerid.test';
        }
        $tempPassword = 'password';

        $nik = trim($_POST['nik'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $profession = trim($_POST['profession'] ?? '');
        $npwp = trim($_POST['npwp'] ?? '');
        $npwpStatus = !empty($_POST['npwp_status']);
        if ($npwp === '' && $npwpStatus) {
            $npwp = $nik;
        }
        $linkedin = trim($_POST['linkedin'] ?? '');
        $facebook = trim($_POST['facebook'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');
        $tiktok = trim($_POST['tiktok'] ?? '');

        if (!empty($_POST['social_platform']) && is_array($_POST['social_platform'])) {
            foreach ($_POST['social_platform'] as $idx => $plat) {
                $h = trim($_POST['social_handle'][$idx] ?? '');
                if ($h === '') continue;
                $p = strtolower($plat);
                if ($p === 'linkedin' && $linkedin === '') $linkedin = $h;
                if ($p === 'facebook' && $facebook === '') $facebook = $h;
                if ($p === 'instagram' && $instagram === '') $instagram = $h;
                if ($p === 'tiktok' && $tiktok === '') $tiktok = $h;
            }
        }

        $description = trim($_POST['description'] ?? '');
        $brandName = trim($_POST['brand_name'] ?? '');

        if ($linkedin === '' && $facebook === '' && $instagram === '' && $tiktok === '') {
            flash('error', 'Wajib mengisi minimal salah satu media sosial (LinkedIn, Facebook, Instagram, atau TikTok).');
            redirect('register.php');
            exit;
        }

        $sameLoc = isset($_POST['same_location_siapkerja']) ? 1 : 0;
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $village = trim($_POST['village'] ?? '');
        $domicileCityId = trim($_POST['domicile_city_id'] ?? $city);
        $postalCode = trim($_POST['postal_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $addressNotes = trim($_POST['address_detail'] ?? ($_POST['address_notes'] ?? ''));
        $userConsent = !empty($_POST['user_consent']) ? 1 : 0;

        create_user($ownerName, $uniqueEmail, $tempPassword, 'employer');
        $newUser = find_user_by_email($uniqueEmail);
        if ($newUser) {
            $userId = (int)$newUser['id'];
            
            // Store uploaded documents
            if (!empty($_FILES['permit_document']['name'])) {
                $permitExt = strtolower(pathinfo((string) $_FILES['permit_document']['name'], PATHINFO_EXTENSION));
                $permitSize = (int) ($_FILES['permit_document']['size'] ?? 0);
                $permitErr = (int) ($_FILES['permit_document']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($permitErr !== UPLOAD_ERR_OK || !in_array($permitExt, ['pdf', 'docx'], true) || $permitSize > 2 * 1024 * 1024) {
                    flash('error', 'Dokumen Perizinan Usaha harus berformat PDF atau Docx dengan ukuran maksimal 2MB.');
                    redirect('register.php');
                    exit;
                }
            }
            $permitDoc = store_upload('permit_document', 'employer/' . $userId, ['pdf', 'docx'])
                ?: store_upload('supporting_doc', 'employer/' . $userId, ['pdf', 'docx']);
            if (!empty($_FILES['workplace_photo']['name'])) {
                $photoExt = strtolower(pathinfo((string) $_FILES['workplace_photo']['name'], PATHINFO_EXTENSION));
                $photoSize = (int) ($_FILES['workplace_photo']['size'] ?? 0);
                $photoErr = (int) ($_FILES['workplace_photo']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($photoErr !== UPLOAD_ERR_OK || !in_array($photoExt, ['png', 'jpg', 'jpeg'], true) || $photoSize > 2 * 1024 * 1024) {
                    flash('error', 'Foto bukti tempat usaha harus berformat PNG atau JPG dengan ukuran maksimal 2MB.');
                    redirect('register.php');
                    exit;
                }
            }
            $workplacePhoto = store_upload('workplace_photo', 'employer/' . $userId, ['png', 'jpg', 'jpeg']);

            if (!empty($_FILES['business_logo']['name'])) {
                $logoExt = strtolower(pathinfo((string) $_FILES['business_logo']['name'], PATHINFO_EXTENSION));
                $logoSize = (int) ($_FILES['business_logo']['size'] ?? 0);
                $logoErr = (int) ($_FILES['business_logo']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($logoErr !== UPLOAD_ERR_OK || !in_array($logoExt, ['png', 'jpg', 'jpeg'], true) || $logoSize > 2 * 1024 * 1024) {
                    flash('error', 'Logo Usaha harus berformat PNG atau JPG dengan ukuran maksimal 2MB.');
                    redirect('register.php');
                    exit;
                }
            }
            $businessLogo = store_upload('business_logo', 'employer/' . $userId, ['png', 'jpg', 'jpeg']);

            // Update user profile complete and domicile
            db()->prepare('UPDATE users SET profile_complete = 1, domicile_city_id = ?, city = ? WHERE id = ?')->execute([$domicileCityId, $city, $userId]);
            
            $socialSummary = implode(', ', array_filter([
                $instagram ? "Instagram: {$instagram}" : null,
                $linkedin ? "LinkedIn: {$linkedin}" : null,
                $facebook ? "Facebook: {$facebook}" : null,
                $tiktok ? "TikTok: {$tiktok}" : null,
            ]));
            $pdo = db();
            $hasSocialMediaColumn = false;
            $hasBrandColumns = false;
            try {
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    $colCheck = $pdo->query("SHOW COLUMNS FROM employer_profiles LIKE 'social_media'");
                    $hasSocialMediaColumn = $colCheck && (bool) $colCheck->fetch();
                    $colCheckBrand = $pdo->query("SHOW COLUMNS FROM employer_profiles LIKE 'brand_name'");
                    $hasBrandColumns = $colCheckBrand && (bool) $colCheckBrand->fetch();
                } else {
                    $cols = array_column($pdo->query('PRAGMA table_info(employer_profiles)')->fetchAll(), 'name');
                    $hasSocialMediaColumn = in_array('social_media', $cols, true);
                    $hasBrandColumns = in_array('brand_name', $cols, true);
                }
            } catch (Throwable $ignored) {}

            if ($hasBrandColumns) {
                if ($hasSocialMediaColumn) {
                    $stmtEp = $pdo->prepare('INSERT INTO employer_profiles (
                        user_id, owner_name, nik, profession, phone, whatsapp, npwp,
                        brand_name, business_logo,
                        same_location_siapkerja, province, city, district, village, postal_code, address, address_detail,
                        latitude, longitude, description, linkedin, instagram, facebook, social_media,
                        permit_document, doc_permission, workplace_photo, doc_location_photo,
                        entity_type, verification_status, verified, domicile_city_id, user_consent, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        "Individu", "PENDING", 0, ?, ?, CURRENT_TIMESTAMP
                    )');

                    $stmtEp->execute([
                        $userId, $ownerName, $nik, $profession, $phone, $whatsapp, $npwp,
                        $brandName, $businessLogo,
                        $sameLoc, $province, $city, $district, $village, $postalCode, $address, $addressNotes,
                        '-6.887844', '107.613038', $description, $linkedin, $instagram, $facebook, $socialSummary,
                        $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
                        $domicileCityId, $userConsent
                    ]);
                } else {
                    $stmtEp = $pdo->prepare('INSERT INTO employer_profiles (
                        user_id, owner_name, nik, profession, phone, whatsapp, npwp,
                        brand_name, business_logo,
                        same_location_siapkerja, province, city, district, village, postal_code, address, address_detail,
                        latitude, longitude, description, linkedin, instagram, facebook,
                        permit_document, doc_permission, workplace_photo, doc_location_photo,
                        entity_type, verification_status, verified, domicile_city_id, user_consent, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        "Individu", "PENDING", 0, ?, ?, CURRENT_TIMESTAMP
                    )');

                    $stmtEp->execute([
                        $userId, $ownerName, $nik, $profession, $phone, $whatsapp, $npwp,
                        $brandName, $businessLogo,
                        $sameLoc, $province, $city, $district, $village, $postalCode, $address, $addressNotes,
                        '-6.887844', '107.613038', $description, $linkedin, $instagram, $facebook,
                        $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
                        $domicileCityId, $userConsent
                    ]);
                }
            } else {
                if ($hasSocialMediaColumn) {
                    $stmtEp = $pdo->prepare('INSERT INTO employer_profiles (
                        user_id, owner_name, nik, profession, phone, whatsapp, npwp,
                        same_location_siapkerja, province, city, district, village, postal_code, address, address_detail,
                        latitude, longitude, description, linkedin, instagram, facebook, social_media,
                        permit_document, doc_permission, workplace_photo, doc_location_photo,
                        entity_type, verification_status, verified, domicile_city_id, user_consent, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        "Individu", "PENDING", 0, ?, ?, CURRENT_TIMESTAMP
                    )');

                    $stmtEp->execute([
                        $userId, $ownerName, $nik, $profession, $phone, $whatsapp, $npwp,
                        $sameLoc, $province, $city, $district, $village, $postalCode, $address, $addressNotes,
                        '-6.887844', '107.613038', $description, $linkedin, $instagram, $facebook, $socialSummary,
                        $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
                        $domicileCityId, $userConsent
                    ]);
                } else {
                    $stmtEp = $pdo->prepare('INSERT INTO employer_profiles (
                        user_id, owner_name, nik, profession, phone, whatsapp, npwp,
                        same_location_siapkerja, province, city, district, village, postal_code, address, address_detail,
                        latitude, longitude, description, linkedin, instagram, facebook,
                        permit_document, doc_permission, workplace_photo, doc_location_photo,
                        entity_type, verification_status, verified, domicile_city_id, user_consent, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        "Individu", "PENDING", 0, ?, ?, CURRENT_TIMESTAMP
                    )');

                    $stmtEp->execute([
                        $userId, $ownerName, $nik, $profession, $phone, $whatsapp, $npwp,
                        $sameLoc, $province, $city, $district, $village, $postalCode, $address, $addressNotes,
                        '-6.887844', '107.613038', $description, $linkedin, $instagram, $facebook,
                        $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
                        $domicileCityId, $userConsent
                    ]);
                }
            }

            try {
                $stmtLog = db()->prepare("INSERT INTO audit_logs (entity_type, entity_id, actor_name, actor_role, action, details, created_at) VALUES ('employer', ?, ?, 'employer', 'Profil dikirim untuk verifikasi.', 'Pengajuan profil pemberi kerja baru.', CURRENT_TIMESTAMP)");
                $stmtLog->execute([$userId, $ownerName]);
            } catch (Throwable $ignored) {}

            $newUser['profile_complete'] = 1;
            $newUser['domicile_city_id'] = $domicileCityId;
            $newUser['city'] = $city;

            login_user($newUser);
            redirect('register.php?success=1');
        }
    } catch (Throwable $e) {
        error_log('[Karirhub Register Error] ' . $e->getMessage());
        flash('error', 'Pendaftaran gagal: ' . $e->getMessage());
        redirect('register.php');
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simulasi Onboarding - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/pki-form.css?v=<?php echo time(); ?>">
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

        /* Container Shell */
        .registration-shell {
            background: #f8fafc;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 36px 20px 36px;
            min-height: calc(100vh - 68px);
        }

        .registration-card {
            width: 100%;
            max-width: 580px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 36px 36px 28px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.03);
        }

        .registration-title {
            margin: 0 0 18px 0;
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }

        /* Bottom Help Footer */
        .page-help-footer {
            margin-top: 36px;
            text-align: center;
            color: #64748b;
            font-size: 13px;
        }

        .page-help-footer p {
            margin: 0 0 8px;
        }

        .page-help-footer a {
            color: #0284c7;
            text-decoration: none;
            font-weight: 600;
        }

        .page-help-footer a:hover {
            text-decoration: underline;
        }

        .help-contact-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            font-size: 13px;
            color: #475467;
        }

        .help-contact-row i {
            margin-right: 4px;
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
            <a href="employer-type.php" class="nav-back-link">
                Kembali ke Halaman Utama
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
            <h1 class="registration-title">Daftar sebagai pemberi kerja</h1>

            <?php $flash = get_flash(); if ($flash): ?>
                <div style="margin:0 0 20px; padding:12px 16px; border-radius:8px; font-size:13.5px; font-weight:600; background:<?php echo $flash['type'] === 'error' ? '#fef2f2' : '#f0fdf4'; ?>; color:<?php echo $flash['type'] === 'error' ? '#991b1b' : '#166534'; ?>; border:1px solid <?php echo $flash['type'] === 'error' ? '#fecaca' : '#bbf7d0'; ?>; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <div style="display:flex; align-items:center; gap:6px; flex:1;">
                        <i class="fa-solid <?php echo $flash['type'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                        <span><?php echo e($flash['message']); ?></span>
                    </div>
                    <button type="button" aria-label="Tutup Notifikasi" onclick="this.parentElement.remove()" style="background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer; padding:0 4px; line-height:1; opacity:0.6; transition:opacity 0.15s ease;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            <?php endif; ?>

            <form method="post" id="registrationForm" enctype="multipart/form-data">
                <input type="hidden" name="register_action" value="simulate_employer_session">
                <?php
                $isRevision = false;
                $formData = [];
                $cancelButtonHtml = '';
                include __DIR__ . '/includes/employer-profile-form.php';
                ?>
            </form>
        </div>

        <!-- Bottom Help Footer -->
        <footer class="page-help-footer">
            <p>Butuh bantuan? <a href="#">Kunjungi Pusat Bantuan</a> atau hubungi kami</p>
            <div class="help-contact-row">
                <span><i class="fa-solid fa-phone" style="font-size:12px;"></i> 0811-871-2018</span>
                <span class="contact-sep">|</span>
                <span><i class="fa-regular fa-envelope"></i> pusatpasarkerja@kemnaker.go.id</span>
            </div>
        </footer>
    </div>
    <div class="modal-backdrop <?php echo $showSuccessPopup ? 'open' : ''; ?>" id="modalPendaftaranBerhasil">
        <div class="popup-dialog-card" style="padding: 32px 28px; border-radius: 16px; max-width: 440px;">
            <div class="popup-dialog-icon success" style="width: 56px; height: 56px; font-size: 26px; margin: 0 auto 16px; background: #ecfdf5; color: #10b981;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h3 style="font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 16px; text-align: center;">Pendaftaran Berhasil</h3>
            
            <!-- Information Bar: Menunggu Verifikasi (Warna biru dengan ikon jam, tanpa button x) -->
            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: center; gap: 8px; color: #1d4ed8; font-size: 13px; font-weight: 700; margin-bottom: 18px;">
                <i class="fa-regular fa-clock" style="font-size: 15px; color: #2563eb;"></i>
                <span>Menunggu Verifikasi</span>
            </div>

            <!-- Detail Pesan (Di bawah Menunggu Verifikasi, dipisah/tidak digabung, text-align left) -->
            <div style="text-align: left; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 10px; padding: 14px 16px; margin-bottom: 24px;">
                <p style="font-size: 13.5px; color: #334155; line-height: 1.55; margin-bottom: 10px; font-weight: 500;">
                    Pengajuan Profil Pemberi Kerja Individu sedang dalam proses verifikasi.
                </p>
                <p style="font-size: 13px; color: #64748b; line-height: 1.55; margin: 0; font-weight: 400;">
                    Hak Akses akan mulai berlaku setelah pengajuan disetujui Admin.
                </p>
            </div>

            <a class="primary-btn" href="dashboard.php#dashboard" style="width: 100%; text-align: center; justify-content: center; height: 42px; font-size: 13.5px; font-weight: 600; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center;">Menuju Dashboard Pemberi Kerja</a>
        </div>
    </div>
    <script src="assets/pki-form.js?v=<?php echo time(); ?>"></script>
    <script>
    (function () {
        var modal = document.getElementById('modalPendaftaranBerhasil');
        if (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    modal.classList.remove('open');
                }
            });
        }
    })();
    </script>
</body>
</html>
