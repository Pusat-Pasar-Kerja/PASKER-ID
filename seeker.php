<?php
require_once __DIR__ . '/includes/bootstrap.php';


$user = require_role('seeker');

if (!is_profile_complete($user)) {
    redirect('profile-seeker.php');
}

$page = $_GET['page'] ?? 'dashboard';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['apply_job_id'])) {
    $jobId = (int) $_POST['apply_job_id'];
    $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND status = "Tayang" LIMIT 1');
    $jobStmt->execute([$jobId]);
    $job = $jobStmt->fetch();

    if (!$job) {
        flash('error', 'Lowongan tidak tersedia atau belum disetujui.');
        redirect('seeker.php?page=jobs');
    }

    try {
        db()->prepare('INSERT INTO job_applications (job_id, seeker_id, status) VALUES (?, ?, "Lamaran Masuk")')
            ->execute([$jobId, $user['id']]);
        notify_user((int) $job['user_id'], 'Pelamar baru', $user['name'] . ' melamar lowongan "' . $job['title'] . '". Profil pelamar sudah tersinkron ke menu lowongan Anda.', 'info', $jobId);
        flash('success', 'Lamaran berhasil dikirim. Data profil Anda dikirim ke pemberi kerja.');
    } catch (Throwable $error) {
        flash('error', 'Anda sudah melamar lowongan ini.');
    }

    $back = trim((string) ($_POST['return_to'] ?? ''));
    if ($back === 'job') {
        redirect('seeker.php?page=job&id=' . $jobId);
    }
    if ($back === 'employer') {
        redirect('seeker.php?page=employer&id=' . (int) $job['user_id']);
    }
    redirect('seeker.php?page=jobs');
}

$profileStatement = db()->prepare('SELECT * FROM seeker_profiles WHERE user_id = ? LIMIT 1');
$profileStatement->execute([$user['id']]);
$profile = $profileStatement->fetch() ?: [];

$experienceCount = (int) db()->query('SELECT COUNT(*) FROM seeker_experiences WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$trainingCount = (int) db()->query('SELECT COUNT(*) FROM seeker_trainings WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$educationCount = (int) db()->query('SELECT COUNT(*) FROM seeker_educations WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$skillCount = (int) db()->query('SELECT COUNT(*) FROM seeker_skills WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$languageCount = (int) db()->query('SELECT COUNT(*) FROM seeker_languages WHERE user_id = ' . (int) $user['id'])->fetchColumn();
$experienceRows = db()->query('SELECT * FROM seeker_experiences WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
$trainingRows = db()->query('SELECT * FROM seeker_trainings WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
$educationRows = db()->query('SELECT * FROM seeker_educations WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
$skillRows = db()->query('SELECT * FROM seeker_skills WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();
$languageRows = db()->query('SELECT * FROM seeker_languages WHERE user_id = ' . (int) $user['id'] . ' ORDER BY id DESC')->fetchAll();

$search = trim($_GET['q'] ?? '');
$filterLocations = array_values(array_filter(array_map('trim', (array) ($_GET['location'] ?? []))));
$filterJobType = trim((string) ($_GET['job_type'] ?? ''));
$filterEducation = trim((string) ($_GET['education'] ?? ''));
$filterField = trim((string) ($_GET['job_field'] ?? ''));

$jobSql = public_job_select_sql();
$jobParams = [];
if ($search !== '') {
    $jobSql .= ' AND (j.title LIKE ? OR j.location LIKE ? OR j.industry LIKE ? OR u.name LIKE ? OR ep.owner_name LIKE ?)';
    $like = '%' . $search . '%';
    $jobParams = [$like, $like, $like, $like, $like];
}
if ($filterLocations) {
    $placeholders = implode(',', array_fill(0, count($filterLocations), '?'));
    $jobSql .= ' AND j.location IN (' . $placeholders . ')';
    $jobParams = array_merge($jobParams, $filterLocations);
}
if ($filterJobType !== '' && in_array($filterJobType, seeker_job_type_options(), true)) {
    $jobSql .= ' AND j.job_type = ?';
    $jobParams[] = $filterJobType;
}
$jobSql .= ' ORDER BY j.created_at DESC';
$jobStmt = db()->prepare($jobSql);
$jobStmt->execute($jobParams);
$jobs = $jobStmt->fetchAll() ?: [];

if ($filterEducation !== '' || $filterField !== '') {
    $jobs = array_values(array_filter($jobs, static function (array $job) use ($filterEducation, $filterField) {
        $data = job_to_form_data($job);
        if ($filterEducation !== '' && $data['education_required'] !== $filterEducation) {
            return false;
        }
        if ($filterField !== '' && $data['job_field'] !== $filterField) {
            return false;
        }
        return true;
    }));
}

$locationCounts = [];
$typeCounts = [];
foreach (db()->query('SELECT location, job_type FROM job_posts WHERE status = "Tayang"') as $row) {
    $locationCounts[$row['location']] = ($locationCounts[$row['location']] ?? 0) + 1;
    $typeCounts[$row['job_type']] = ($typeCounts[$row['job_type']] ?? 0) + 1;
}
$filterCityOptions = seeker_city_options();
foreach (array_keys($locationCounts) as $cityName) {
    if (!in_array($cityName, $filterCityOptions, true)) {
        $filterCityOptions[] = $cityName;
    }
}

$detailJob = null;
$detailData = [];
$relatedJobs = [];
$employerProfile = null;
$employerJobs = [];

if ($page === 'job') {
    $jobId = (int) ($_GET['id'] ?? 0);
    $detailStmt = db()->prepare(public_job_select_sql() . ' AND j.id = ? LIMIT 1');
    $detailStmt->execute([$jobId]);
    $detailJob = $detailStmt->fetch() ?: null;
    if (!$detailJob) {
        flash('error', 'Lowongan tidak ditemukan atau belum disetujui.');
        redirect('seeker.php?page=jobs');
    }
    $detailData = job_to_form_data($detailJob);
    $relatedStmt = db()->prepare(public_job_select_sql() . ' AND j.user_id = ? AND j.id != ? ORDER BY j.created_at DESC LIMIT 4');
    $relatedStmt->execute([(int) $detailJob['user_id'], (int) $detailJob['id']]);
    $relatedJobs = $relatedStmt->fetchAll() ?: [];
}

if ($page === 'employer') {
    $employerId = (int) ($_GET['id'] ?? 0);
    $employerStmt = db()->prepare('SELECT u.id, u.name, u.email, ep.*
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.id = ? AND u.role = "employer"
        LIMIT 1');
    $employerStmt->execute([$employerId]);
    $employerProfile = $employerStmt->fetch() ?: null;
    if (!$employerProfile) {
        flash('error', 'Profil pemberi kerja tidak ditemukan.');
        redirect('seeker.php?page=jobs');
    }
    $empJobsStmt = db()->prepare(public_job_select_sql() . ' AND j.user_id = ? ORDER BY j.created_at DESC');
    $empJobsStmt->execute([$employerId]);
    $employerJobs = $empJobsStmt->fetchAll() ?: [];
}

$appliedStmt = db()->prepare('SELECT job_id FROM job_applications WHERE seeker_id = ?');
$appliedStmt->execute([$user['id']]);
$appliedIds = array_map('intval', array_column($appliedStmt->fetchAll(), 'job_id'));

$unread = unread_notification_count((int) $user['id']);
$notifications = user_notifications((int) $user['id']);
$initials = strtoupper(mb_substr($user['name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Karirhub - Pencari Kerja</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css?v=seeker-std-1">
</head>
<body>
<div class="app-layout-wrapper">
    <!-- NARROW SIDEBAR RAIL (60px) -->
    <aside class="sidebar-rail">
        <!-- Top Logo Icon -->
        <a href="seeker.php" class="sidebar-rail-logo" title="Karirhub">
            <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;">
                <circle cx="73" cy="22" r="13" fill="#2590F9" />
                <path d="M22 32 C15.37 32 10 37.37 10 44 C10 50.63 15.37 56 22 56 H42 C44.2 56 46 57.8 46 60 V76 C46 82.63 51.37 88 58 88 C64.63 88 70 82.63 70 76 V50 C70 40.06 61.94 32 52 32 H22 Z" fill="#2590F9" />
            </svg>
        </a>

        <!-- Hamburger Toggle Button -->
        <button type="button" class="sidebar-rail-toggle" id="railToggleBtn" title="Buka Menu Navigasi">
            <i class="fa-solid fa-bars"></i>
        </button>

        <!-- Navigation Icons -->
        <div class="sidebar-rail-nav">
            <a href="seeker.php?page=dashboard" class="rail-btn <?php echo $page === 'dashboard' ? 'active' : ''; ?>" title="Dasbor" style="text-decoration:none;">
                <i class="fa-solid fa-chart-line"></i>
            </a>
            <a href="seeker.php?page=jobs" class="rail-btn <?php echo in_array($page, ['jobs', 'job', 'employer'], true) ? 'active' : ''; ?>" title="Lowongan Kerja" style="text-decoration:none;">
                <i class="fa-solid fa-briefcase"></i>
            </a>
            <a href="profile-seeker.php" class="rail-btn <?php echo $page === 'profile' ? 'active' : ''; ?>" title="Profil Saya" style="text-decoration:none;">
                <i class="fa-regular fa-user"></i>
            </a>
        </div>

        <div class="rail-spacer"></div>

        <!-- Bottom Theme & Account Avatar -->
        <div class="rail-bottom">
            <div class="rail-popover-wrap">
                <button type="button" class="rail-btn" id="themeToggleBtn" title="Tema Tampilan">
                    <i class="fa-solid fa-display"></i>
                </button>
                <div class="rail-popover rail-theme-popover" id="railThemePopover">
                    <div class="rail-popover-header">TEMA TAMPILAN</div>
                    <div class="rail-popover-list">
                        <button type="button" class="rail-popover-item" data-theme-val="light">
                            <div class="rail-popover-item-left"><i class="fa-regular fa-sun"></i><span>Terang</span></div>
                            <i class="fa-solid fa-check rail-theme-check"></i>
                        </button>
                        <button type="button" class="rail-popover-item" data-theme-val="dark">
                            <div class="rail-popover-item-left"><i class="fa-regular fa-moon"></i><span>Gelap</span></div>
                            <i class="fa-solid fa-check rail-theme-check"></i>
                        </button>
                        <button type="button" class="rail-popover-item" data-theme-val="system">
                            <div class="rail-popover-item-left"><i class="fa-solid fa-display"></i><span>Sistem</span></div>
                            <i class="fa-solid fa-check rail-theme-check"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="rail-popover-wrap">
                <button type="button" class="rail-avatar-btn" id="sidebarAvatar" title="Akun Pengguna"><?php echo e($initials); ?></button>
                <div class="rail-popover rail-account-popover" id="railAccountPopover">
                    <div class="rail-account-info">
                        <div class="rail-account-name"><?php echo e($user['name']); ?></div>
                        <div class="rail-account-email"><?php echo e($user['email']); ?></div>
                    </div>
                    <div class="rail-popover-divider"></div>
                    <div class="rail-popover-list">
                        <a href="profile-seeker.php" class="rail-popover-item">
                            <div class="rail-popover-item-left"><i class="fa-solid fa-user-pen"></i><span>Profil &amp; Biodata</span></div>
                        </a>
                        <a href="settings.php" class="rail-popover-item">
                            <div class="rail-popover-item-left"><i class="fa-solid fa-gear"></i><span>Pengaturan</span></div>
                        </a>
                        <a href="logout.php" class="rail-popover-item item-logout">
                            <div class="rail-popover-item-left"><i class="fa-solid fa-arrow-right-from-bracket"></i><span>Keluar</span></div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- FLYOUT / EXPANDED NAVIGATION DRAWER -->
    <div class="nav-drawer-backdrop" id="drawerBackdrop"></div>
    <div class="nav-drawer" id="navDrawer">
        <div class="drawer-header">
            <div class="drawer-brand-logo">
                <svg viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:32px;height:32px;">
                    <circle cx="73" cy="22" r="13" fill="#2590F9" />
                    <path d="M22 32 C15.37 32 10 37.37 10 44 C10 50.63 15.37 56 22 56 H42 C44.2 56 46 57.8 46 60 V76 C46 82.63 51.37 88 58 88 C64.63 88 70 82.63 70 76 V50 C70 40.06 61.94 32 52 32 H22 Z" fill="#2590F9" />
                </svg>
            </div>
            <div class="drawer-brand-text">
                <h3>Karirhub</h3>
                <p>Pencari Kerja</p>
            </div>
            <button type="button" class="drawer-close-btn" id="drawerCloseBtn" title="Tutup">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <div class="drawer-menu-list">
            <a href="seeker.php?page=dashboard" class="drawer-menu-item <?php echo $page === 'dashboard' ? 'active' : ''; ?>" style="text-decoration:none;">
                <i class="fa-solid fa-chart-line"></i>
                <span style="text-decoration:none;">Dasbor</span>
            </a>
            <a href="seeker.php?page=jobs" class="drawer-menu-item <?php echo in_array($page, ['jobs', 'job', 'employer'], true) ? 'active' : ''; ?>" style="text-decoration:none;">
                <i class="fa-solid fa-briefcase"></i>
                <span style="text-decoration:none;">Lowongan</span>
            </a>
            <a href="profile-seeker.php" class="drawer-menu-item <?php echo $page === 'profile' ? 'active' : ''; ?>" style="text-decoration:none;">
                <i class="fa-regular fa-user"></i>
                <span style="text-decoration:none;">Profil Saya</span>
            </a>
        </div>
    </div>

    <!-- MAIN APP CONTENT -->
    <div class="main" style="flex:1; display:flex; flex-direction:column; min-width:0; overflow:hidden;">
        <!-- TOPBAR (MATCHING INDIVIDUAL EMPLOYER) -->
        <header class="new-topbar">
            <div class="topbar-nav-arrows">
                <button type="button" class="topbar-arrow-btn" onclick="history.back()" title="Kembali"><i class="fa-solid fa-chevron-left"></i></button>
                <button type="button" class="topbar-arrow-btn" onclick="history.forward()" title="Maju"><i class="fa-solid fa-chevron-right"></i></button>
            </div>

            <div class="topbar-crumbs" id="crumbs">
                <span>Beranda</span>
                <span class="sep">&gt;</span>
                <strong id="crumbCurrent"><?php echo $page === 'job' ? 'Detail Lowongan' : ($page === 'employer' ? 'Profil Pemberi Kerja' : ($page === 'jobs' ? 'Lowongan Kerja' : ($page === 'profile' ? 'Profil Pencari Kerja' : 'Dasbor'))); ?></strong>
            </div>

            <div class="topbar-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <form method="get" action="seeker.php" style="width:100%;margin:0;display:flex;">
                    <input type="hidden" name="page" value="jobs">
                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan pekerjaan, posisi, atau keahlian..." style="border:none;outline:none;width:100%;background:transparent;font-size:13px;color:var(--text-dark, #0f172a);">
                </form>
            </div>

            <div class="topbar-right-actions">
                <?php echo render_notif_dropdown($notifications, $unread); ?>

                <div class="company-profile-pill" onclick="toggleAccountMenu(event)" title="Pengaturan Akun / Profil">
                    <div class="company-pill-avatar"><?php echo e($initials); ?></div>
                    <div class="company-pill-text">
                        <strong><?php echo e($user['name']); ?></strong>
                        <span>Pencari Kerja</span>
                    </div>
                    <i class="fa-solid fa-chevron-right company-pill-arrow"></i>
                </div>
            </div>
        </header>

        <!-- CONTENT AREA -->
        <div class="content" style="flex:1; overflow-y:auto; background:#f8fafc;">
            <div class="page active">
                <?php if ($flash = get_flash()): ?>
                    <div class="alert-box <?php echo $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:12px; border-radius:10px; padding:12px 16px;">
                        <div style="display:flex; align-items:center; gap:10px; flex:1;">
                            <i class="fa-solid <?php echo $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                            <span><?php echo e($flash['message']); ?></span>
                        </div>
                        <button type="button" aria-label="Tutup Notifikasi" onclick="this.closest('.alert-box').remove()" style="background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer; padding:0 4px; line-height:1; opacity:0.6; transition:opacity 0.15s ease;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                    <!-- HERO HEADER -->
                    <div class="section-card" style="padding:22px 24px;margin-bottom:16px;background:linear-gradient(135deg, #ffffff 0%, #f4fbfe 100%);border-color:#d5edf6;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
                            <div>
                                <h1 style="font-size:26px;font-weight:800;color:#0f172a;margin-bottom:6px;">Halo, <?php echo e($user['name']); ?> 👋</h1>
                                <div class="hero-subrow">
                                    <span class="status-pill"><i class="fa-solid fa-circle-check"></i> Profil Siap Melamar</span>
                                    <span class="cycle-info">
                                        <strong>NIK: <?php echo e($profile['nik'] ?? '-'); ?></strong>
                                        <span><?php echo e($profile['domicile_address'] ?? 'Domisili belum diisi'); ?></span>
                                    </span>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                <a class="primary-btn" href="seeker.php?page=jobs" style="padding:10px 18px;font-size:13px;"><i class="fa-solid fa-magnifying-glass"></i> Cari Lowongan</a>
                                <a class="ghost-btn" href="profile-seeker.php" style="padding:10px 18px;font-size:13px;"><i class="fa-solid fa-pen-to-square"></i> Edit Profil</a>
                            </div>
                        </div>
                    </div>

                    <!-- 5 METRIC CARDS -->
                    <div class="cards5">
                        <div class="card">
                            <div class="mini-icon" style="background:#e0f2fe;color:#0284c7;"><i class="fa-solid fa-id-card"></i></div>
                            <h3>Biodata</h3>
                            <div class="value" style="font-size:20px;color:#059669;"><i class="fa-solid fa-circle-check" style="font-size:18px;"></i> Lengkap</div>
                            <div class="desc neutral">Terverifikasi sistem</div>
                        </div>
                        <div class="card">
                            <div class="mini-icon" style="background:#ecfdf5;color:#059669;"><i class="fa-solid fa-briefcase"></i></div>
                            <h3>Pengalaman</h3>
                            <div class="value"><?php echo $experienceCount; ?></div>
                            <div class="desc neutral">Riwayat kerja tercatat</div>
                        </div>
                        <div class="card">
                            <div class="mini-icon" style="background:#fef3c7;color:#d97706;"><i class="fa-solid fa-certificate"></i></div>
                            <h3>Pelatihan</h3>
                            <div class="value"><?php echo $trainingCount; ?></div>
                            <div class="desc neutral">Sertifikasi & kursus</div>
                        </div>
                        <div class="card">
                            <div class="mini-icon" style="background:#f3e8ff;color:#9333ea;"><i class="fa-solid fa-graduation-cap"></i></div>
                            <h3>Pendidikan</h3>
                            <div class="value"><?php echo $educationCount; ?></div>
                            <div class="desc neutral">Riwayat akademis</div>
                        </div>
                        <div class="card">
                            <div class="mini-icon" style="background:#ffe4e6;color:#e11d48;"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
                            <h3>Keahlian</h3>
                            <div class="value"><?php echo $skillCount + $languageCount; ?></div>
                            <div class="desc neutral">Skill & kemampuan bahasa</div>
                        </div>
                    </div>

                    <!-- PROFILE DETAILS TWO-COLUMN SECTION -->
                    <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px;margin-top:16px;">
                        <div class="section-card" style="padding:20px;">
                            <div class="section-header" style="margin-bottom:14px;">
                                <h3><i class="fa-solid fa-user"></i> Ringkasan Biodata Diri</h3>
                                <a href="profile-seeker.php" class="btn-link"><i class="fa-solid fa-pen"></i> Ubah</a>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:13px;">
                                <div>
                                    <span style="color:#64748b;display:block;font-size:11px;font-weight:600;text-transform:uppercase;">NIK</span>
                                    <strong style="color:#0f172a;"><?php echo e($profile['nik'] ?? '-'); ?></strong>
                                </div>
                                <div>
                                    <span style="color:#64748b;display:block;font-size:11px;font-weight:600;text-transform:uppercase;">Nomor Telepon</span>
                                    <strong style="color:#0f172a;"><?php echo e($profile['phone'] ?? '-'); ?></strong>
                                </div>
                                <div>
                                    <span style="color:#64748b;display:block;font-size:11px;font-weight:600;text-transform:uppercase;">Tempat, Tgl Lahir</span>
                                    <strong style="color:#0f172a;"><?php echo e(($profile['birth_place'] ?? '-') . ', ' . ($profile['birth_date'] ?? '-')); ?></strong>
                                </div>
                                <div>
                                    <span style="color:#64748b;display:block;font-size:11px;font-weight:600;text-transform:uppercase;">Jenis Kelamin & Status</span>
                                    <strong style="color:#0f172a;"><?php echo e(($profile['gender'] ?? '-') . ' / ' . ($profile['marital_status'] ?? '-')); ?></strong>
                                </div>
                                <div style="grid-column:1/-1;">
                                    <span style="color:#64748b;display:block;font-size:11px;font-weight:600;text-transform:uppercase;">Alamat Domisili</span>
                                    <p style="margin:4px 0 0;color:#334155;"><?php echo e($profile['domicile_address'] ?? '-'); ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="section-card" style="padding:20px;">
                            <div class="section-header" style="margin-bottom:14px;">
                                <h3><i class="fa-solid fa-bolt"></i> Keahlian & Kompetensi</h3>
                                <a href="profile-seeker.php" class="btn-link"><i class="fa-solid fa-pen"></i> Ubah</a>
                            </div>
                            <?php if ($skillRows): ?>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">
                                    <?php foreach ($skillRows as $row): ?>
                                        <span class="pill-badge process"><i class="fa-solid fa-check"></i> <?php echo e($row['skill_name']); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p style="color:#64748b;font-size:13px;margin:10px 0;">Belum ada keahlian yang ditambahkan. Silakan perbarui profil Anda.</p>
                            <?php endif; ?>
                            
                            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e8ebf2;">
                                <h4 style="font-size:13px;font-weight:700;color:#334155;margin-bottom:8px;"><i class="fa-solid fa-briefcase"></i> Cari Pekerjaan Terbaru</h4>
                                <p style="font-size:12px;color:#64748b;margin-bottom:12px;">Temukan ratusan lowongan kerja dari pemberi kerja perorangan terverifikasi.</p>
                                <a href="seeker.php?page=jobs" class="primary-btn" style="width:100%;height:38px;font-size:13px;">Jelajahi Lowongan Kerja</a>
                            </div>
                        </div>
                    </div>

                <?php elseif ($page === 'job' && $detailJob): ?>
                    <?php
                        $applied = in_array((int) $detailJob['id'], $appliedIds, true);
                        $employerName = job_employer_display_name($detailJob);
                        $employerInitial = strtoupper(mb_substr($employerName, 0, 1));
                        $photo = trim((string) ($detailJob['workplace_photo'] ?? ''));
                        $descriptionHtml = job_rich_html($detailData['description']);
                        $specialHtml = job_rich_html($detailData['special_requirements']);
                        $genderText = $detailData['genders'] ? implode(' / ', $detailData['genders']) : 'Tidak ada preferensi';
                        $maritalText = $detailData['marital_statuses'] ? implode(' / ', $detailData['marital_statuses']) : 'Tidak ada preferensi';
                        $physicalText = $detailData['physical_conditions'] ? implode(' & ', $detailData['physical_conditions']) : '-';
                    ?>
                    <div class="job-detail-page">
                        <a class="back-link" href="seeker.php?page=jobs"><i class="fa-solid fa-arrow-left"></i> Kembali ke daftar lowongan</a>
                        
                        <section class="job-detail-hero" style="border-radius:18px;border:1px solid #e8ebf2;background:#fff;box-shadow:var(--shadow-soft);">
                            <div class="job-detail-hero-main">
                                <div class="vacancy-logo lg"><?php echo $photo !== '' ? '<img src="' . e($photo) . '" alt="">' : e($employerInitial); ?></div>
                                <div>
                                    <h1 style="font-size:24px;font-weight:800;color:#0f172a;"><?php echo e($detailJob['title']); ?></h1>
                                    <p class="job-detail-meta"><i class="fa-solid fa-location-dot"></i> <?php echo e(job_placement_address($detailJob)); ?></p>
                                    <p class="job-detail-meta"><i class="fa-regular fa-clock"></i> Diposting <?php echo e(time_ago_id($detailJob['created_at'] ?? null)); ?> · Kuota: <?php echo (int) $detailJob['quota']; ?> orang</p>
                                    <p class="job-detail-meta"><i class="fa-regular fa-calendar"></i> Batas waktu lamaran <?php echo e(job_apply_deadline($detailJob)); ?></p>
                                </div>
                            </div>
                            <?php if ($applied): ?>
                                <button class="vacancy-apply is-disabled" type="button" disabled style="border-radius:12px;"><i class="fa-solid fa-check"></i> Sudah Dilamar</button>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="apply_job_id" value="<?php echo (int) $detailJob['id']; ?>">
                                    <input type="hidden" name="return_to" value="job">
                                    <button class="vacancy-apply" type="submit" style="border-radius:12px;"><i class="fa-solid fa-paper-plane"></i> Lamar Sekarang</button>
                                </form>
                            <?php endif; ?>
                        </section>

                        <div class="job-facts" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                            <div><span>Bidang pekerjaan</span><strong><?php echo e($detailData['job_field'] ?: $detailJob['industry'] ?: '-'); ?></strong></div>
                            <div><span>Jenis pekerjaan</span><strong><?php echo e($detailJob['job_type'] ?: '-'); ?></strong></div>
                            <div><span>Tipe lowongan</span><strong>Dalam negeri</strong></div>
                            <div><span>Jenis kelamin</span><strong><?php echo e($genderText); ?></strong></div>
                            <div><span>Rentang gaji</span><strong style="color:#059669;"><?php echo e(job_public_salary($detailJob)); ?></strong></div>
                            <div><span>Remote</span><strong><?php echo e(!empty($detailData['is_remote']) ? 'Ya' : 'Tidak'); ?></strong></div>
                        </div>

                        <div class="job-detail-layout">
                            <div class="job-detail-main">
                                <section class="job-detail-block" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                                    <h2>Deskripsi Pekerjaan</h2>
                                    <?php if ($descriptionHtml !== ''): ?>
                                        <div class="job-review-prose"><?php echo $descriptionHtml; ?></div>
                                    <?php else: ?>
                                        <p class="job-review-empty">Belum ada deskripsi spesifik.</p>
                                    <?php endif; ?>
                                </section>
                                
                                <section class="job-detail-block" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                                    <h2>Persyaratan Khusus</h2>
                                    <ul class="job-spec-list">
                                        <li>Pendidikan: <?php echo e($detailData['education_required'] ?: 'Tidak ditentukan'); ?></li>
                                        <li>Pengalaman: <?php echo e($detailData['experience_required'] ?: 'Tidak ditentukan'); ?></li>
                                        <?php if ($detailData['skills']): ?>
                                            <li>Keahlian: <?php echo e(implode(', ', $detailData['skills'])); ?></li>
                                        <?php endif; ?>
                                        <?php if ($detailData['age_min'] !== '' || $detailData['age_max'] !== ''): ?>
                                            <li>Usia: <?php echo e(trim(($detailData['age_min'] !== '' ? $detailData['age_min'] . ' th' : '') . ' – ' . ($detailData['age_max'] !== '' ? $detailData['age_max'] . ' th' : ''), ' –')); ?></li>
                                        <?php endif; ?>
                                    </ul>
                                    <?php if ($specialHtml !== ''): ?>
                                        <div class="job-review-prose" style="margin-top:12px"><?php echo $specialHtml; ?></div>
                                    <?php endif; ?>
                                </section>
                                
                                <section class="job-detail-block" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                                    <h2>Persyaratan Umum</h2>
                                    <div class="job-general-grid">
                                        <div><span>Minimal pendidikan</span><strong><?php echo e($detailData['education_required'] ?: 'Tidak ditentukan'); ?></strong></div>
                                        <div><span>Status pernikahan</span><strong><?php echo e($maritalText); ?></strong></div>
                                        <div><span>Minimal pengalaman</span><strong><?php echo e($detailData['experience_required'] ?: 'Tidak ditentukan'); ?></strong></div>
                                        <div><span>Kondisi fisik</span><strong><?php echo e($physicalText); ?></strong></div>
                                    </div>
                                </section>
                            </div>

                            <aside class="job-detail-side">
                                <div class="employer-side-card" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                                    <h3><?php echo e($employerName); ?></h3>
                                    <div class="employer-side-tags">
                                        <span class="perorangan-badge">Perorangan</span>
                                        <?php if (!empty($detailJob['verified'])): ?>
                                            <span class="verified-badge"><i class="fa-solid fa-circle-check"></i> Terverifikasi</span>
                                        <?php endif; ?>
                                        <?php if (!empty($detailJob['profession'])): ?>
                                            <span class="soft-pill"><?php echo e($detailJob['profession']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <a class="employer-profile-link" href="seeker.php?page=employer&id=<?php echo (int) $detailJob['user_id']; ?>">Lihat Profil Lengkap</a>
                                    <div class="employer-side-meta">
                                        <div><span>Alamat penempatan</span><strong><?php echo e(job_placement_address($detailJob)); ?></strong></div>
                                        <div><span>Email kontak</span><strong><?php echo e($detailJob['employer_email'] ?? '-'); ?></strong></div>
                                        <div><span>Kontak langsung</span><strong><?php echo e(job_employer_contact($detailJob)); ?></strong></div>
                                    </div>
                                </div>
                                
                                <?php if ($relatedJobs): ?>
                                <div class="employer-side-card" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                                    <h3>Lowongan lain dari pemberi kerja ini</h3>
                                    <div class="related-job-list">
                                        <?php foreach ($relatedJobs as $related): ?>
                                            <a class="related-job-item" href="seeker.php?page=job&id=<?php echo (int) $related['id']; ?>">
                                                <strong><?php echo e($related['title']); ?></strong>
                                                <span><?php echo e($related['location']); ?> · <?php echo e(job_public_salary($related)); ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </aside>
                        </div>
                    </div>

                <?php elseif ($page === 'employer' && $employerProfile): ?>
                    <?php
                        $empName = trim((string) ($employerProfile['owner_name'] ?? '')) ?: (string) $employerProfile['name'];
                        $empInitial = strtoupper(mb_substr($empName, 0, 1));
                        $empPhoto = trim((string) ($employerProfile['workplace_photo'] ?? ''));
                        $empAddress = implode(', ', array_filter([
                            $employerProfile['address'] ?? '',
                            $employerProfile['city'] ?? '',
                            $employerProfile['province'] ?? '',
                        ]));
                        $empPhone = trim((string) (($employerProfile['whatsapp'] ?? '') ?: ($employerProfile['phone'] ?? '')));
                    ?>
                    <div class="job-detail-page">
                        <a class="back-link" href="seeker.php?page=jobs"><i class="fa-solid fa-arrow-left"></i> Kembali ke daftar lowongan</a>
                        <section class="employer-profile-hero" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                            <div class="vacancy-logo lg"><?php echo $empPhoto !== '' ? '<img src="' . e($empPhoto) . '" alt="">' : e($empInitial); ?></div>
                            <div>
                                <h1 style="font-size:24px;font-weight:800;color:#0f172a;"><?php echo e($empName); ?></h1>
                                <div class="employer-side-tags">
                                    <span class="perorangan-badge">Perorangan</span>
                                    <?php if (!empty($employerProfile['verified'])): ?>
                                        <span class="verified-badge"><i class="fa-solid fa-circle-check"></i> Terverifikasi</span>
                                    <?php endif; ?>
                                    <?php if (!empty($employerProfile['profession'])): ?>
                                        <span class="soft-pill"><?php echo e($employerProfile['profession']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($employerProfile['employer_bio']) || !empty($employerProfile['description'])): ?>
                                    <p class="employer-about"><?php echo e($employerProfile['employer_bio'] ?? $employerProfile['description'] ?? ''); ?></p>
                                <?php endif; ?>
                            </div>
                        </section>
                        <div class="job-facts" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                            <div><span>Email</span><strong><?php echo e($employerProfile['email'] ?? '-'); ?></strong></div>
                            <div><span>Kontak langsung</span><strong><?php echo e($empPhone !== '' ? $empPhone : '-'); ?></strong></div>
                            <div><span>Alamat lengkap</span><strong><?php echo e($empAddress !== '' ? $empAddress : '-'); ?></strong></div>
                        </div>
                        <h2 class="jobs-section-title" style="margin-top:24px;font-size:18px;font-weight:800;">Lowongan Aktif Pemberi Kerja Ini</h2>
                        <div class="vacancy-grid">
                            <?php if (!$employerJobs): ?>
                                <div class="section-card" style="padding:24px">Belum ada lowongan tayang dari pemberi kerja ini.</div>
                            <?php else: foreach ($employerJobs as $job): ?>
                                <?php echo render_seeker_job_card($job, $appliedIds); ?>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                <?php elseif ($page === 'jobs'): ?>
                    <div class="jobs-page-head" style="margin-bottom:16px;">
                        <div>
                            <div class="jobs-kicker">Lowongan Dalam Negeri</div>
                            <h1 style="font-size:26px;font-weight:800;color:#0f172a;margin:2px 0;">Lowongan Kerja Perorangan</h1>
                            <p style="color:#64748b;"><?php echo count($jobs); ?> lowongan disetujui siap dilamar</p>
                        </div>
                        <form method="get" class="jobs-search">
                            <input type="hidden" name="page" value="jobs">
                            <?php if ($filterJobType !== ''): ?><input type="hidden" name="job_type" value="<?php echo e($filterJobType); ?>"><?php endif; ?>
                            <?php foreach ($filterLocations as $loc): ?>
                                <input type="hidden" name="location[]" value="<?php echo e($loc); ?>">
                            <?php endforeach; ?>
                            <div class="search-small"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan yang kamu inginkan"></div>
                            <button class="primary-btn" type="submit">Cari</button>
                        </form>
                    </div>
                    
                    <div class="jobs-layout">
                        <aside class="jobs-filter" style="border-radius:18px;border:1px solid #e8ebf2;box-shadow:var(--shadow-soft);background:#fff;">
                            <form method="get">
                                <input type="hidden" name="page" value="jobs">
                                <input type="hidden" name="q" value="<?php echo e($search); ?>">
                                <div class="filter-head">
                                    <strong>Filter</strong>
                                    <a href="seeker.php?page=jobs">Reset</a>
                                </div>
                                <details class="filter-group" open>
                                    <summary>Lokasi</summary>
                                    <div class="filter-search"><input type="search" data-filter-location placeholder="Cari lokasi"></div>
                                    <div class="filter-options" data-location-options>
                                        <?php foreach ($filterCityOptions as $index => $cityName): ?>
                                            <label class="filter-check" <?php echo $index > 7 ? 'data-extra-location hidden' : ''; ?>>
                                                <input type="checkbox" name="location[]" value="<?php echo e($cityName); ?>" <?php echo in_array($cityName, $filterLocations, true) ? 'checked' : ''; ?>>
                                                <span><?php echo e($cityName); ?></span>
                                                <?php if (!empty($locationCounts[$cityName])): ?>
                                                    <em><?php echo (int) $locationCounts[$cityName]; ?></em>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($filterCityOptions) > 8): ?>
                                        <button type="button" class="filter-more" data-toggle-locations>Lihat lebih banyak</button>
                                    <?php endif; ?>
                                </details>
                                <details class="filter-group" open>
                                    <summary>Tipe Pekerjaan</summary>
                                    <div class="filter-options">
                                        <label class="filter-check">
                                            <input type="radio" name="job_type" value="" <?php echo $filterJobType === '' ? 'checked' : ''; ?>>
                                            <span>Semua</span>
                                        </label>
                                        <?php foreach (seeker_job_type_options() as $typeName): ?>
                                            <label class="filter-check">
                                                <input type="radio" name="job_type" value="<?php echo e($typeName); ?>" <?php echo $filterJobType === $typeName ? 'checked' : ''; ?>>
                                                <span><?php echo e($typeName); ?></span>
                                                <?php if (!empty($typeCounts[$typeName])): ?>
                                                    <em><?php echo (int) $typeCounts[$typeName]; ?></em>
                                                <?php endif; ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                                <details class="filter-group">
                                    <summary>Pendidikan</summary>
                                    <div class="filter-options">
                                        <?php foreach (['SMA / SMK', 'D3', 'D4 / S1', 'S2'] as $edu): ?>
                                            <label class="filter-check">
                                                <input type="radio" name="education" value="<?php echo e($edu); ?>" <?php echo $filterEducation === $edu ? 'checked' : ''; ?>>
                                                <span><?php echo e($edu); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                                <details class="filter-group">
                                    <summary>Bidang Pekerjaan</summary>
                                    <div class="filter-options">
                                        <?php foreach (['Teknologi Informasi', 'Administrasi', 'Keuangan & Akuntansi', 'Penjualan & Marketing', 'Kuliner & Hospitality', 'Lainnya'] as $fieldName): ?>
                                            <label class="filter-check">
                                                <input type="radio" name="job_field" value="<?php echo e($fieldName); ?>" <?php echo $filterField === $fieldName ? 'checked' : ''; ?>>
                                                <span><?php echo e($fieldName); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                                <button class="primary-btn" type="submit" style="width:100%;margin-top:12px;height:40px;">Terapkan Filter</button>
                            </form>
                        </aside>
                        <div>
                            <div class="vacancy-grid">
                                <?php if (!$jobs): ?>
                                    <div class="section-card" style="padding:28px;grid-column:1/-1;text-align:center;color:#64748b;">
                                        <i class="fa-solid fa-folder-open" style="font-size:32px;margin-bottom:8px;display:block;color:#cbd5e1;"></i>
                                        Belum ada lowongan yang sesuai filter. Coba ubah kata kunci atau lokasi pencarian.
                                    </div>
                                <?php else: foreach ($jobs as $job): ?>
                                    <?php echo render_seeker_job_card($job, $appliedIds); ?>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($page === 'profile'): ?>
                    <!-- PROFILE HEADER & ACTION -->
                    <div class="section-card" style="padding:24px;margin-bottom:20px;background:linear-gradient(135deg, #ffffff 0%, #f4fbfe 100%);border-color:#d5edf6;">
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
                            <div style="display:flex;align-items:center;gap:18px;">
                                <div style="width:64px;height:64px;border-radius:18px;background:linear-gradient(135deg, #0284c7, #0369a1);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;box-shadow:0 8px 20px rgba(2,132,199,0.25);">
                                    <?php echo e($initials); ?>
                                </div>
                                <div>
                                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                        <h1 style="font-size:24px;font-weight:800;color:#0f172a;margin:0;"><?php echo e($user['name']); ?></h1>
                                        <span class="pill-badge verified" style="font-size:12px;"><i class="fa-solid fa-circle-check"></i> Profil Aktif &amp; Lengkap</span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:16px;margin-top:6px;font-size:13px;color:#64748b;flex-wrap:wrap;">
                                        <span><i class="fa-solid fa-envelope" style="color:#0284c7;margin-right:4px;"></i> <?php echo e($user['email']); ?></span>
                                        <span><i class="fa-solid fa-phone" style="color:#0284c7;margin-right:4px;"></i> <?php echo e($profile['phone'] ?? '-'); ?></span>
                                        <span><i class="fa-solid fa-location-dot" style="color:#0284c7;margin-right:4px;"></i> <?php echo e($profile['domicile_address'] ?? 'Domisili belum diisi'); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <a href="profile-seeker.php" class="primary-btn" style="padding:10px 20px;font-size:13px;display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:10px;box-shadow:0 4px 14px rgba(2,132,199,0.25);">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit Profil
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- 2-COLUMN PROFILE CONTENT -->
                    <div style="display:grid;grid-template-columns:1.1fr 1fr;gap:20px;align-items:start;">
                        <!-- LEFT COLUMN: BIODATA & PENDIDIKAN & PELATIHAN -->
                        <div style="display:flex;flex-direction:column;gap:20px;">
                            <!-- 1. BIODATA PRIBADI -->
                            <div class="section-card" style="padding:22px;">
                                <div class="section-header" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                                    <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;">
                                        <i class="fa-solid fa-id-card" style="color:#0284c7;"></i> Biodata Pribadi
                                    </h3>
                                    <a href="profile-seeker.php" class="btn-link" style="font-size:12px;"><i class="fa-solid fa-pen"></i> Ubah</a>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 20px;font-size:13px;">
                                    <div>
                                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:2px;">NIK</span>
                                        <strong style="color:#0f172a;"><?php echo e($profile['nik'] ?? '-'); ?></strong>
                                    </div>
                                    <div>
                                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:2px;">Nomor Telepon</span>
                                        <strong style="color:#0f172a;"><?php echo e($profile['phone'] ?? '-'); ?></strong>
                                    </div>
                                    <div>
                                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:2px;">Tempat, Tanggal Lahir</span>
                                        <strong style="color:#0f172a;"><?php echo e(($profile['birth_place'] ?? '-') . ', ' . ($profile['birth_date'] ?? '-')); ?></strong>
                                    </div>
                                    <div>
                                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:2px;">Jenis Kelamin</span>
                                        <strong style="color:#0f172a;"><?php echo e($profile['gender'] ?? '-'); ?></strong>
                                    </div>
                                    <div>
                                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:2px;">Status Pernikahan</span>
                                        <strong style="color:#0f172a;"><?php echo e($profile['marital_status'] ?? '-'); ?></strong>
                                    </div>
                                    <div>
                                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:2px;">Status Akun</span>
                                        <span class="pill-badge verified" style="font-size:11px;">Siap Kerja</span>
                                    </div>
                                    <div style="grid-column:1/-1;">
                                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:2px;">Alamat Sesuai KTP</span>
                                        <p style="margin:2px 0 0;color:#334155;line-height:1.4;"><?php echo e($profile['ktp_address'] ?? '-'); ?></p>
                                    </div>
                                    <div style="grid-column:1/-1;">
                                        <span style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;display:block;margin-bottom:2px;">Alamat Domisili</span>
                                        <p style="margin:2px 0 0;color:#334155;line-height:1.4;"><?php echo e($profile['domicile_address'] ?? '-'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. RIWAYAT PENDIDIKAN -->
                            <div class="section-card" style="padding:22px;">
                                <div class="section-header" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                                    <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;">
                                        <i class="fa-solid fa-graduation-cap" style="color:#0284c7;"></i> Riwayat Pendidikan (<?php echo count($educationRows); ?>)
                                    </h3>
                                    <a href="profile-seeker.php" class="btn-link" style="font-size:12px;"><i class="fa-solid fa-pen"></i> Ubah</a>
                                </div>
                                <?php if (!$educationRows): ?>
                                    <p style="color:#64748b;font-size:13px;margin:0;">Belum ada riwayat pendidikan yang dicantumkan.</p>
                                <?php else: ?>
                                    <div style="display:flex;flex-direction:column;gap:12px;">
                                        <?php foreach ($educationRows as $edu): ?>
                                            <div style="padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                                                <div>
                                                    <div style="font-weight:700;font-size:13px;color:#0f172a;"><?php echo e($edu['school_name']); ?></div>
                                                    <div style="font-size:12px;color:#64748b;margin-top:2px;">
                                                        <span class="pill-badge" style="background:#e0f2fe;color:#0284c7;font-size:11px;margin-right:6px;"><?php echo e($edu['level']); ?></span>
                                                        <?php echo e($edu['major'] ?: 'Semua Jurusan'); ?>
                                                    </div>
                                                </div>
                                                <div style="font-size:12px;font-weight:600;color:#64748b;">
                                                    Lulus <?php echo e($edu['graduation_year'] ?: '-'); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- 3. PELATIHAN & SERTIFIKASI -->
                            <div class="section-card" style="padding:22px;">
                                <div class="section-header" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                                    <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;">
                                        <i class="fa-solid fa-certificate" style="color:#0284c7;"></i> Pelatihan &amp; Sertifikasi (<?php echo count($trainingRows); ?>)
                                    </h3>
                                    <a href="profile-seeker.php" class="btn-link" style="font-size:12px;"><i class="fa-solid fa-pen"></i> Ubah</a>
                                </div>
                                <?php if (!$trainingRows): ?>
                                    <p style="color:#64748b;font-size:13px;margin:0;">Belum ada riwayat pelatihan &amp; sertifikasi.</p>
                                <?php else: ?>
                                    <div style="display:flex;flex-direction:column;gap:12px;">
                                        <?php foreach ($trainingRows as $trn): ?>
                                            <div style="padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                                                <div>
                                                    <div style="font-weight:700;font-size:13px;color:#0f172a;"><?php echo e($trn['training_name']); ?></div>
                                                    <div style="font-size:12px;color:#64748b;margin-top:2px;">
                                                        Penyelenggara: <strong><?php echo e($trn['organizer'] ?: '-'); ?></strong> · Tahun: <?php echo e($trn['year'] ?: '-'); ?>
                                                    </div>
                                                </div>
                                                <?php if (!empty($trn['certificate'])): ?>
                                                    <span class="pill-badge verified" style="font-size:11px;"><i class="fa-solid fa-certificate"></i> <?php echo e($trn['certificate']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- RIGHT COLUMN: PENGALAMAN KERJA, SKILLS, BAHASA -->
                        <div style="display:flex;flex-direction:column;gap:20px;">
                            <!-- 4. PENGALAMAN KERJA -->
                            <div class="section-card" style="padding:22px;">
                                <div class="section-header" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;">
                                    <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;">
                                        <i class="fa-solid fa-briefcase" style="color:#0284c7;"></i> Pengalaman Kerja (<?php echo count($experienceRows); ?>)
                                    </h3>
                                    <a href="profile-seeker.php" class="btn-link" style="font-size:12px;"><i class="fa-solid fa-pen"></i> Ubah</a>
                                </div>
                                <?php if (!$experienceRows): ?>
                                    <p style="color:#64748b;font-size:13px;margin:0;">Belum ada riwayat pengalaman kerja yang ditambahkan.</p>
                                <?php else: ?>
                                    <div style="display:flex;flex-direction:column;gap:12px;">
                                        <?php foreach ($experienceRows as $exp): ?>
                                            <div style="padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">
                                                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
                                                    <div>
                                                        <div style="font-weight:700;font-size:14px;color:#0f172a;"><?php echo e($exp['position']); ?></div>
                                                        <div style="font-size:13px;color:#0284c7;font-weight:600;margin-top:2px;"><?php echo e($exp['company_name']); ?></div>
                                                    </div>
                                                    <span class="pill-badge" style="background:#e2e8f0;color:#334155;font-size:11px;"><?php echo e($exp['duration']); ?></span>
                                                </div>
                                                <?php if (!empty($exp['notes'])): ?>
                                                    <div style="font-size:12px;color:#64748b;margin-top:8px;line-height:1.4;border-top:1px dashed #cbd5e1;padding-top:6px;">
                                                        <?php echo e($exp['notes']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- 5. KEAHLIAN & KOMPETENSI -->
                            <div class="section-card" style="padding:22px;">
                                <div class="section-header" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
                                    <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;">
                                        <i class="fa-solid fa-star" style="color:#0284c7;"></i> Keterampilan &amp; Keahlian
                                    </h3>
                                    <a href="profile-seeker.php" class="btn-link" style="font-size:12px;"><i class="fa-solid fa-pen"></i> Ubah</a>
                                </div>
                                <?php if (!$skillRows): ?>
                                    <p style="color:#64748b;font-size:13px;margin:0;">Belum ada keahlian yang tercantum.</p>
                                <?php else: ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                        <?php foreach ($skillRows as $sk): ?>
                                            <span class="pill-badge" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;font-size:12px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px;">
                                                <i class="fa-solid fa-check" style="color:#0284c7;"></i>
                                                <strong><?php echo e($sk['skill_name']); ?></strong>
                                                <?php if (!empty($sk['level'])): ?>
                                                    <span style="color:#64748b;font-weight:400;">(<?php echo e($sk['level']); ?>)</span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- 6. BAHASA -->
                            <div class="section-card" style="padding:22px;">
                                <div class="section-header" style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;">
                                    <h3 style="font-size:15px;font-weight:700;color:#0f172a;margin:0;display:flex;align-items:center;gap:8px;">
                                        <i class="fa-solid fa-language" style="color:#0284c7;"></i> Penguasaan Bahasa
                                    </h3>
                                    <a href="profile-seeker.php" class="btn-link" style="font-size:12px;"><i class="fa-solid fa-pen"></i> Ubah</a>
                                </div>
                                <?php if (!$languageRows): ?>
                                    <p style="color:#64748b;font-size:13px;margin:0;">Belum ada bahasa yang tercantum.</p>
                                <?php else: ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                        <?php foreach ($languageRows as $lang): ?>
                                            <span class="pill-badge" style="background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;font-size:12px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px;">
                                                <i class="fa-solid fa-comments"></i>
                                                <strong><?php echo e($lang['language_name']); ?></strong>
                                                <?php if (!empty($lang['proficiency'])): ?>
                                                    <span style="color:#047857;font-weight:400;">· <?php echo e($lang['proficiency']); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- CTA CARD TO EDIT -->
                            <div class="section-card" style="padding:20px;background:#f8fafc;border:1px dashed #cbd5e1;text-align:center;">
                                <div style="font-weight:700;font-size:14px;color:#0f172a;margin-bottom:4px;">Ingin memperbarui informasi profil Anda?</div>
                                <div style="font-size:12px;color:#64748b;margin-bottom:12px;">Pastikan data biodata dan riwayat pengalaman selalu terbaru untuk meningkatkan peluang diterima kerja.</div>
                                <a href="profile-seeker.php" class="primary-btn" style="height:36px;padding:0 16px;font-size:12px;display:inline-flex;align-items:center;gap:6px;">
                                    <i class="fa-solid fa-user-pen"></i> Buka Form Edit Profil
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="assets/app.js?v=seeker-std-1"></script>
</body>
</html>
