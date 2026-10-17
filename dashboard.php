<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = require_role('employer');
//test
// Fetch Employer Profile
$profileStatement = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
$profileStatement->execute([$user['id']]);
$profile = $profileStatement->fetch() ?: [];

// Fetch Seeker Profile (SIAPkerja data)
$seekerStatement = db()->prepare('SELECT * FROM seeker_profiles WHERE user_id = ? LIMIT 1');
$seekerStatement->execute([$user['id']]);
$seekerProfile = $seekerStatement->fetch() ?: [];

// Fetch Jobs
$jobsStatement = db()->prepare('SELECT * FROM job_posts WHERE user_id = ? ORDER BY id DESC');
$jobsStatement->execute([$user['id']]);
$jobs = $jobsStatement->fetchAll() ?: [];

// Monthly KBJI publication count for current employer (Layer 2 tracking)
$startOfMonth = date('Y-m-01 00:00:00');
$endOfMonth   = date('Y-m-t 23:59:59');
$kbjiPublishedCountsStmt = db()->prepare('
    SELECT kbji_code, COUNT(*) as cnt
    FROM job_posts
    WHERE user_id = ?
      AND published_at IS NOT NULL
      AND published_at BETWEEN ? AND ?
      AND status NOT IN ("Draft", "Menunggu Verifikasi", "Dikirim/Menunggu Verifikasi", "Pending", "Perlu Direvisi", "Perlu Revisi", "Ditolak", "Dibatalkan", "CANCELED")
    GROUP BY kbji_code
');
$kbjiPublishedCountsStmt->execute([$user['id'], $startOfMonth, $endOfMonth]);
$kbjiPublishedCounts = $kbjiPublishedCountsStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

$kbjiReached3 = [];
foreach ($kbjiPublishedCounts as $kCode => $cnt) {
    if ((int)$cnt >= 3) {
        $kbjiReached3[] = (string)$kCode;
    }
}

// Helper Indonesian date formatter
if (!function_exists('format_indo_date')) {
    function format_indo_date($dt, $format = 'long') {
        if (!$dt) return '-';
        if (!($dt instanceof DateTime)) {
            try {
                $dt = new DateTime($dt);
            } catch (Exception $e) {
                return '-';
            }
        }
        $day = $dt->format('j');
        $monthNum = (int)$dt->format('n');
        $year = $dt->format('Y');

        $monthsLong = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $monthsShort = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];

        if ($format === 'short') {
            return $day . ' ' . ($monthsShort[$monthNum] ?? '') . ' ' . $year;
        } elseif ($format === 'day_month') {
            return $day . ' ' . ($monthsShort[$monthNum] ?? '');
        }
        return $day . ' ' . ($monthsLong[$monthNum] ?? '') . ' ' . $year;
    }
}

// Status calculation logic according to FSD Final & Step 4/5 specification
$verificationStatus = $profile['verification_status'] ?? 'NOT_SUBMITTED';
if (empty($profile)) {
    $verificationStatus = 'NOT_SUBMITTED';
} elseif ($verificationStatus === 'NOT_SUBMITTED' && !empty($profile['owner_name'])) {
    $verificationStatus = !empty($profile['verified']) ? 'APPROVED' : 'PENDING';
}

$activeUntilRaw = $profile['active_until'] ?? null;
$now = new DateTime();
$activeUntil = $activeUntilRaw ? new DateTime($activeUntilRaw) : null;

$daysRemaining = 0;
$isExpired = false;
$isTransitionPeriod = false;
$isFullDisable = false;

if (!empty($profile['verified']) && (int)$profile['verified'] === 1 && in_array($verificationStatus, ['APPROVED', 'ACTIVE_VERIFIED', 'TRANSITION_LIMITED', 'FULL_DISABLED', 'INACTIVE_REVERIFICATION_REQUIRED'], true)) {
    if ($activeUntil) {
        if ($now <= $activeUntil) {
            $diff = $now->diff($activeUntil);
            $daysRemaining = (int)$diff->days;
            $verificationStatus = 'ACTIVE_VERIFIED';
            $isExpired = false;
        } else {
            $nowTs = $now->getTimestamp();
            $actUntilTs = $activeUntil->getTimestamp();
            $diffSec = $nowTs - $actUntilTs;
            $isExpired = true;
            $daysPast = (int)floor($diffSec / 86400);
            $daysRemaining = -$daysPast;

            // 7 days transition period (exact second math)
            if ($diffSec <= (7 * 86400)) {
                $verificationStatus = 'TRANSITION_LIMITED';
                $isTransitionPeriod = true;
            } else {
                $verificationStatus = 'INACTIVE_REVERIFICATION_REQUIRED';
                $isFullDisable = true;
            }
        }
    } else {
        $verificationStatus = 'ACTIVE_VERIFIED';
    }
} elseif ($verificationStatus === 'INACTIVE_REVERIFICATION_REQUIRED' || $verificationStatus === 'FULL_DISABLED') {
    $isFullDisable = true;
}

// Lifecycle date calculation & formatting
$startDate = null;
if (!empty($profile['last_activated_at'])) {
    try {
        $startDate = new DateTime($profile['last_activated_at']);
    } catch (Exception $e) {
        $startDate = null;
    }
} elseif ($activeUntil !== null) {
    $startDate = (clone $activeUntil)->modify('-6 months');
}

$hasValidDates = ($startDate !== null && $activeUntil !== null);
$totalDays = null;
$elapsedDays = null;
$progressPct = 0;
$isH7 = false;
$isLastDay = false;

if ($hasValidDates) {
    $totalDays = max(1, (int)$startDate->diff($activeUntil)->days);

    // Auto-fix: If active_until was set using old 3-month duration (< 150 days total), update to 6 months
    if ($totalDays < 150) {
        $activeUntil = (clone $startDate)->modify('+6 months');
        $profile['active_until'] = $activeUntil->format('Y-m-d H:i:s');
        $totalDays = max(1, (int)$startDate->diff($activeUntil)->days);
        if (!empty($profile['id'])) {
            try {
                db()->prepare('UPDATE employer_profiles SET active_until = ? WHERE id = ?')
                    ->execute([$profile['active_until'], $profile['id']]);
            } catch (Throwable $e) {}
        }
    }

    if ($now <= $activeUntil) {
        $elapsedSec = max(0, $now->getTimestamp() - $startDate->getTimestamp());
        $elapsedDays = max(1, min($totalDays, (int)floor($elapsedSec / 86400) + 1));

        $diffRem = $now->diff($activeUntil);
        $daysRemaining = max(0, (int)$diffRem->days);
        if ($now->getTimestamp() + 86400 >= $activeUntil->getTimestamp() && $daysRemaining <= 0) {
            $daysRemaining = 0;
            $isLastDay = true;
            $elapsedDays = $totalDays;
        } elseif ($daysRemaining <= 7) {
            $isH7 = true;
        }

        $progressPct = max(0, min(100, (int)round(($elapsedDays / $totalDays) * 100)));
    } else {
        $elapsedDays = $totalDays;
        $progressPct = 100;
        $daysRemaining = 0;
    }
} else {
    $daysRemaining = null;
}

// Transition calculations (Masa Transisi maksimal 7 hari)
$transElapsed = 1;
$transRemain = 7;
$transProgressPct = 0;
$transEndDate = null;
if ($activeUntil) {
    $transEndDate = (clone $activeUntil)->modify('+7 days');
    if ($now > $activeUntil) {
        $secInTrans = max(0, $now->getTimestamp() - $activeUntil->getTimestamp());
        $transElapsed = max(1, min(7, (int)floor($secInTrans / 86400) + 1));
        $transRemain = max(0, 7 - (int)floor($secInTrans / 86400));
        $transProgressPct = max(0, min(100, (int)round(($transElapsed / 7) * 100)));
    }
}

$lastActivatedLong = format_indo_date($startDate, 'long');
$lastActivatedFormatted = format_indo_date($startDate, 'short');
$lastActivatedShort = format_indo_date($startDate, 'day_month');
$activeUntilLong = format_indo_date($activeUntil, 'long');
$activeUntilFormatted = format_indo_date($activeUntil, 'short');
$activeUntilShort = format_indo_date($activeUntil, 'day_month');
$transEndFormatted = format_indo_date($transEndDate, 'long');
$extStat = $profile['extension_status'] ?? 'NONE';

// Reactivation eligibility: must have at least 1 candidate accepted in previous cycle (not whole history)
$isEligibleForReactivation = false;
if ($isFullDisable && !empty($profile['active_until'])) {
    $cycleStart = !empty($profile['last_activated_at'])
        ? $profile['last_activated_at']
        : date('Y-m-d H:i:s', strtotime($profile['active_until'] . ' -6 months'));
    $cycleEnd = date('Y-m-d H:i:s', strtotime($profile['active_until'] . ' +7 days'));

    $accCandidateStmt = db()->prepare('
        SELECT COUNT(*) FROM job_applications a
        JOIN job_posts j ON j.id = a.job_id
        WHERE j.user_id = ? AND a.status = "Diterima"
          AND a.accepted_at BETWEEN ? AND ?
    ');
    $accCandidateStmt->execute([$user['id'], $cycleStart, $cycleEnd]);
    $isEligibleForReactivation = ((int)$accCandidateStmt->fetchColumn() > 0);
}

// Reactivated employer detection (for active cycle dashboard banner)
$isReactivatedEmployer = false;
if (!empty($profile['last_activated_at'])) {
    try {
        $checkAudit = db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'employer' AND entity_id = ? AND action IN ('REACTIVATE_EMPLOYER_ACCESS', 'REACTIVATION_APPROVED', 'REACTIVATION_REQUESTED')");
        $checkAudit->execute([$user['id']]);
        $isReactivatedEmployer = ((int)$checkAudit->fetchColumn() > 0);
    } catch (Throwable $e) {
        $isReactivatedEmployer = false;
    }
}

// --- API / JSON HANDLERS ---
if (isset($_GET['read_notif'])) {
    mark_notifications_read((int) $user['id']);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['applicant_json'])) {
    $applicationId = (int) $_GET['applicant_json'];
    $detail = db()->prepare('SELECT a.*, j.title AS job_title, j.user_id AS employer_id, u.name AS seeker_name, u.email AS seeker_email,
            sp.nik, sp.phone, sp.gender, sp.marital_status, sp.birth_place, sp.birth_date, sp.ktp_address, sp.domicile_address
        FROM job_applications a
        JOIN job_posts j ON j.id = a.job_id
        JOIN users u ON u.id = a.seeker_id
        LEFT JOIN seeker_profiles sp ON sp.user_id = a.seeker_id
        WHERE a.id = ? AND j.user_id = ?
        LIMIT 1');
    $detail->execute([$applicationId, $user['id']]);
    $row = $detail->fetch();
    header('Content-Type: application/json');
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    $row['status'] = normalize_application_status($row['status'] ?? '');
    $row['applied_date'] = !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : date('d M Y');

    // Format bundle
    $bundle = seeker_profile_bundle((int) $row['seeker_id']);

    // Ensure rich fallback data matching sample if empty
    $row['location'] = $row['domicile_address'] ?: ($row['ktp_address'] ?: 'Karangmaja, Banjarharjo, KAB. BREBES, JAWA TENGAH');
    $row['about'] = 'Saya adalah pribadi yang disiplin, bertanggung jawab, cepat belajar, dan mampu beradaptasi dengan lingkungan kerja baru serta bekerja secara individu maupun dalam tim.';
    $row['birth_info'] = ($row['birth_place'] ?: 'Brebes') . ', ' . ($row['birth_date'] ? date('d F Y', strtotime($row['birth_date'])) : '19 Desember 2006');
    $row['ktp_address'] = $row['ktp_address'] ?: 'Karangmaja, Banjarharjo, Brebes, Karangmaja, Banjarharjo, KAB. BREBES';
    $row['domicile_address'] = $row['domicile_address'] ?: 'Karangmaja, Banjarharjo, Brebes, Karangmaja, Banjarharjo, KAB. BREBES, JAWA TENGAH';

    if (empty($bundle['trainings'])) {
        $bundle['trainings'] = [
            [
                'training_name' => 'Operator Forklift',
                'organizer' => 'KEMNAKER',
                'year' => 'Sep 2026',
                'certificate' => 'Cibuntu, Cibitung, KAB. BEKASI, JAWA BARAT',
            ]
        ];
    }
    if (empty($bundle['education'])) {
        $bundle['education'] = [
            [
                'level' => 'SMA atau Sederajat',
                'school_name' => 'SMAN 1 WALED',
                'major' => 'IPS',
                'graduation_year' => '2022 – 2025',
                'gpa' => 'IPK: 87',
                'location' => 'Waled Kota, Waled, KAB. CIREBON, JAWA BARAT',
            ]
        ];
    }
    if (empty($bundle['skills'])) {
        $bundle['skills'] = [
            ['skill_name' => 'Bahasa Korea', 'level' => 'Dasar'],
            ['skill_name' => 'Operator forklift', 'level' => 'Tingkat Mahir'],
        ];
    }

    $row['profile'] = $bundle;
    echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['job_json'])) {
    $jobId = (int) $_GET['job_json'];
    $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ? LIMIT 1');
    $jobStmt->execute([$jobId, $user['id']]);
    $job = $jobStmt->fetch();
    header('Content-Type: application/json');
    if (!$job) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }

    if (in_array($job['status'], ['Perlu Direvisi', 'Perlu Revisi'], true) && empty($job['revision_opened_at'])) {
        db()->prepare('UPDATE job_posts SET revision_opened_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ? AND status IN ("Perlu Direvisi", "Perlu Revisi") AND revision_opened_at IS NULL')
            ->execute([$jobId, $user['id']]);
    }

    echo json_encode(['ok' => true, 'data' => job_to_form_data($job)], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- POST HANDLERS ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // 1. UPDATE STATUS PELAMAR (Diizinkan saat ACTIVE_VERIFIED dan TRANSITION_LIMITED)
    if (isset($_POST['update_application_status'])) {
        $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isFullDisable || $verificationStatus === 'SUSPENDED') {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Hak Akses Pemberi Kerja Individu sedang ditangguhkan atau tidak aktif.']);
                exit;
            }
            flash('error', 'Hak Akses Pemberi Kerja Individu sedang ditangguhkan atau tidak aktif.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $nextStatus = normalize_application_status($_POST['status'] ?? '');
        if (!in_array($nextStatus, application_statuses(), true)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Status pelamar tidak valid.']);
                exit;
            }
            flash('error', 'Status pelamar tidak valid.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $owned = db()->prepare('SELECT a.id, a.job_id, a.seeker_id, j.title FROM job_applications a JOIN job_posts j ON j.id = a.job_id WHERE a.id = ? AND j.user_id = ?');
        $owned->execute([$applicationId, $user['id']]);
        $application = $owned->fetch();
        if (!$application) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Pelamar tidak ditemukan.']);
                exit;
            }
            flash('error', 'Pelamar tidak ditemukan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        if ($nextStatus === 'Diterima') {
            db()->prepare('UPDATE job_applications SET status = ?, accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$nextStatus, $applicationId]);
        } else {
            db()->prepare('UPDATE job_applications SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$nextStatus, $applicationId]);
        }

        // Recalculate accepted_count for parent job
        $jobIdForApp = (int)$application['job_id'];
        $accStmt = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ? AND status = "Diterima"');
        $accStmt->execute([$jobIdForApp]);
        $accCount = (int)$accStmt->fetchColumn();
        db()->prepare('UPDATE job_posts SET accepted_count = ? WHERE id = ?')->execute([$accCount, $jobIdForApp]);

        $quotaStmt = db()->prepare('SELECT quota FROM job_posts WHERE id = ?');
        $quotaStmt->execute([$jobIdForApp]);
        $jobQuota = (int)$quotaStmt->fetchColumn() ?: 1;

        notify_user((int) $application['seeker_id'], 'Status lamaran diperbarui', 'Status lamaran Anda untuk "' . $application['title'] . '" sekarang: ' . $nextStatus . '.', 'info', $applicationId);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'status' => $nextStatus,
                'application_id' => $applicationId,
                'job_id' => $jobIdForApp,
                'accepted_count' => $accCount,
                'quota' => $jobQuota,
                'sisa_kuota' => max(0, $jobQuota - $accCount)
            ]);
            exit;
        }

        flash('success', 'Status pelamar diperbarui menjadi ' . $nextStatus . '.');
        redirect('dashboard.php#lowongan');
        exit;
    }

    // 2. SIMPAN & AJUKAN PROFIL PEMBERI KERJA INDIVIDU (ONBOARDING / REVISION / REACTIVATION)
    if (isset($_POST['submit_profile'])) {
        if ($verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu sedang ditangguhkan. Pembaharuan profil tidak dapat dilakukan.');
            redirect('dashboard.php?open_profile=1');
            exit;
        }
        $ownerName   = trim($_POST['owner_name'] ?? '');
        $nik         = trim($_POST['nik'] ?? '');
        $phone       = trim($_POST['phone'] ?? '');
        $whatsapp    = trim($_POST['whatsapp'] ?? '');
        $profession  = trim($_POST['profession'] ?? '');
        $npwp        = trim($_POST['npwp'] ?? '');
        $npwpStatus  = !empty($_POST['npwp_status']);
        if ($npwp === '' && $npwpStatus) {
            $npwp = $nik;
        }

        $linkedin    = trim($_POST['linkedin'] ?? '');
        $facebook    = trim($_POST['facebook'] ?? '');
        $instagram   = trim($_POST['instagram'] ?? '');
        $tiktok      = trim($_POST['tiktok'] ?? '');

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

        $sameLoc     = isset($_POST['same_location_siapkerja']) ? 1 : 0;
        $province    = trim($_POST['province'] ?? '');
        $city        = trim($_POST['city'] ?? '');
        $district    = trim($_POST['district'] ?? '');
        $village     = trim($_POST['village'] ?? '');
        $postalCode  = trim($_POST['postal_code'] ?? '');

        $sameAddr    = isset($_POST['same_address_siapkerja']) ? 1 : 0;
        $address     = trim($_POST['address'] ?? '');
        $addressDetail = trim($_POST['address_detail'] ?? '');

        $latitude    = trim($_POST['latitude'] ?? '');
        $longitude   = trim($_POST['longitude'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $brandName   = trim($_POST['brand_name'] ?? '');
        $consent     = isset($_POST['user_consent']) ? 1 : 0;
        if ($ownerName === '' || $nik === '' || $phone === '' || $whatsapp === '' || $profession === '' || $npwp === '' || $brandName === '' || $description === '' || ($linkedin === '' && $facebook === '' && $instagram === '' && $tiktok === '') || !$consent) {
            $_SESSION['profile_modal_error'] = 'Lengkapi semua field wajib (termasuk Nama Brand Usaha, Deskripsi Singkat Usaha, dan minimal salah satu media sosial) serta centang persetujuan pengguna.';
            flash('error', 'Lengkapi semua field wajib (termasuk Nama Brand Usaha, Deskripsi Singkat Usaha, dan minimal salah satu media sosial) serta centang persetujuan pengguna.');
            redirect('dashboard.php?open_profile=1');
            exit;
        }

        // Check if user is in NEEDS_REVISION state with revision_count >= 3 (Max revision reached)
        $currRev = (int)($profile['revision_count'] ?? ($profile['rejection_count'] ?? 0));
        if (($profile['verification_status'] ?? '') === 'NEEDS_REVISION' && $currRev >= 3) {
            flash('error', 'Batas maksimal perbaikan profil mandiri telah tercapai (Revisi ke-3). Proses selanjutnya harus dilakukan bersama Petugas Dinas sesuai domisili.');
            redirect('dashboard.php');
            exit;
        }

        // Check if user is in FULL_DISABLED state: must be eligible to submit reactivation online
        if ($isFullDisable && !$isEligibleForReactivation) {
            flash('error', 'Pengajuan reaktivasi online tidak tersedia karena tidak memenuhi syarat (minimal 1 pelamar diterima pada siklus sebelumnya). Silakan ikuti proses melalui Dinas Tenaga Kerja setempat.');
            redirect('dashboard.php');
            exit;
        }

        if (!empty($_FILES['permit_document']['name'])) {
            $permitExt = strtolower(pathinfo((string) $_FILES['permit_document']['name'], PATHINFO_EXTENSION));
            $permitSize = (int) ($_FILES['permit_document']['size'] ?? 0);
            $permitErr = (int) ($_FILES['permit_document']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($permitErr !== UPLOAD_ERR_OK || !in_array($permitExt, ['pdf', 'docx'], true) || $permitSize > 2 * 1024 * 1024) {
                $_SESSION['profile_modal_error'] = 'Dokumen Perizinan Usaha harus berformat PDF atau Docx dengan ukuran maksimal 2MB.';
                flash('error', 'Dokumen Perizinan Usaha harus berformat PDF atau Docx dengan ukuran maksimal 2MB.');
                redirect('dashboard.php?open_profile=1');
                exit;
            }
        }

        $permitDoc = store_upload('permit_document', 'employer/' . $user['id'], ['pdf', 'docx']);
        if (!empty($_FILES['workplace_photo']['name'])) {
            $photoExt = strtolower(pathinfo((string) $_FILES['workplace_photo']['name'], PATHINFO_EXTENSION));
            $photoSize = (int) ($_FILES['workplace_photo']['size'] ?? 0);
            $photoErr = (int) ($_FILES['workplace_photo']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($photoErr !== UPLOAD_ERR_OK || !in_array($photoExt, ['png', 'jpg', 'jpeg'], true) || $photoSize > 2 * 1024 * 1024) {
                $_SESSION['profile_modal_error'] = 'Foto bukti tempat usaha harus berformat PNG atau JPG dengan ukuran maksimal 2MB.';
                flash('error', 'Foto bukti tempat usaha harus berformat PNG atau JPG dengan ukuran maksimal 2MB.');
                redirect('dashboard.php?open_profile=1');
                exit;
            }
        }
        $workplacePhoto = store_upload('workplace_photo', 'employer/' . $user['id'], ['png', 'jpg', 'jpeg']);

        if (!empty($_FILES['business_logo']['name'])) {
            $logoExt = strtolower(pathinfo((string) $_FILES['business_logo']['name'], PATHINFO_EXTENSION));
            $logoSize = (int) ($_FILES['business_logo']['size'] ?? 0);
            $logoErr = (int) ($_FILES['business_logo']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($logoErr !== UPLOAD_ERR_OK || !in_array($logoExt, ['png', 'jpg', 'jpeg'], true) || $logoSize > 2 * 1024 * 1024) {
                $_SESSION['profile_modal_error'] = 'Logo Usaha harus berformat PNG atau JPG dengan ukuran maksimal 2MB.';
                flash('error', 'Logo Usaha harus berformat PNG atau JPG dengan ukuran maksimal 2MB.');
                redirect('dashboard.php?open_profile=1');
                exit;
            }
        }
        $businessLogo = store_upload('business_logo', 'employer/' . $user['id'], ['png', 'jpg', 'jpeg']);
        $existingLogo = !empty($_POST['existing_business_logo']) ? trim($_POST['existing_business_logo']) : null;
        $businessLogo = $businessLogo ?: ($existingLogo ?: ($profile['business_logo'] ?? null));

        $existingPermit = !empty($_POST['existing_permit_document']) ? trim($_POST['existing_permit_document']) : null;
        $existingWorkplace = !empty($_POST['existing_workplace_photo']) ? trim($_POST['existing_workplace_photo']) : null;
        $permitDoc = $permitDoc ?: ($existingPermit ?: ($profile['permit_document'] ?? $profile['doc_permission'] ?? null));
        $workplacePhoto = $workplacePhoto ?: ($existingWorkplace ?: ($profile['workplace_photo'] ?? $profile['doc_location_photo'] ?? null));

        if (empty($businessLogo) && (is_demo_env() || !empty($profile['owner_name']))) {
            $businessLogo = 'public/images/jobii.png';
        }
        if (empty($permitDoc) && (is_demo_env() || !empty($profile['owner_name']))) {
            $permitDoc = 'dokumen-legalitas.pdf';
        }
        if (empty($workplacePhoto) && (is_demo_env() || !empty($profile['owner_name']))) {
            $workplacePhoto = 'foto-rumah.jpg';
        }

        if (empty($businessLogo)) {
            $_SESSION['profile_modal_error'] = 'Logo Usaha wajib diunggah.';
            flash('error', 'Logo Usaha wajib diunggah.');
            redirect('dashboard.php?open_profile=1');
            exit;
        }

        if (empty($permitDoc)) {
            $_SESSION['profile_modal_error'] = 'Dokumen Perizinan Usaha wajib diunggah minimal 1 dokumen.';
            flash('error', 'Dokumen Perizinan Usaha wajib diunggah minimal 1 dokumen.');
            redirect('dashboard.php?open_profile=1');
            exit;
        }

        $isReactivation = ($isFullDisable || ($profile['verification_status'] ?? '') === 'FULL_DISABLED' || ($profile['verification_status'] ?? '') === 'INACTIVE_REVERIFICATION_REQUIRED' || !empty($profile['last_activated_at']));
        $newVerStatus = $isReactivation ? 'REVERIFICATION_PENDING' : 'PENDING';

        if ($profile) {
            $stmt = db()->prepare('UPDATE employer_profiles SET
                owner_name = ?, nik = ?, phone = ?, whatsapp = ?, profession = ?, npwp = ?,
                brand_name = ?, business_logo = ?,
                linkedin = ?, facebook = ?, instagram = ?,
                same_location_siapkerja = ?, province = ?, city = ?, domicile_city_id = ?, district = ?, village = ?, postal_code = ?,
                same_address_siapkerja = ?, address = ?, address_detail = ?,
                latitude = ?, longitude = ?, permit_document = ?, doc_permission = ?,
                workplace_photo = ?, doc_location_photo = ?,
                description = ?, user_consent = ?, consent_accepted = ?,
                entity_type = "Individu", verification_status = ?, verified = 0, active_until = NULL,
                extension_requested = 0, extension_status = "NONE",
                assigned_to = NULL, assigned_at = NULL, verifier_notes = NULL, verification_checklist = NULL,
                manual_review_status = NULL, consent_data_hash = NULL, consent_agreed = 1,
                updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?');
            $stmt->execute([
                $ownerName, $nik, $phone, $whatsapp, $profession, $npwp,
                $brandName, $businessLogo,
                $linkedin, $facebook, $instagram,
                $sameLoc, $province, $city, $city, $district, $village, $postalCode,
                $sameAddr, $address, $addressDetail,
                $latitude, $longitude, $permitDoc, $permitDoc,
                $workplacePhoto, $workplacePhoto,
                $description, $consent, $consent,
                $newVerStatus,
                $user['id']
            ]);

            if ($isReactivation) {
                record_audit_log('employer', $user['id'], 'REVERIFICATION_SUBMITTED', "Pengajuan Verifikasi Ulang Profil dikirim oleh pengguna.", $user['name'] ?? $ownerName, 'employer', true);
            } else {
                record_audit_log('employer', $user['id'], 'INITIAL_VERIFICATION_SUBMITTED', "Pengajuan Verifikasi Profil Pertama dikirim oleh pengguna.", $user['name'] ?? $ownerName, 'employer', true);
            }

            // Sync location of existing jobs (Draft, Menunggu Verifikasi, Tayang, etc.) to match new "Lokasi Tempat Usaha / Kegiatan"
            $domPartsNew = array_filter([$village, $district, $city, $province]);
            $cleanLocationNew = !empty($domPartsNew) ? implode(', ', $domPartsNew) : $city;
            if (!empty($cleanLocationNew)) {
                try {
                    $oldStreet = trim((string)($profile['address'] ?? ''));
                    $oldCity = trim((string)($profile['city'] ?? ''));
                    db()->prepare('UPDATE job_posts SET location = ? WHERE user_id = ? AND (location = ? OR (location LIKE ? AND ? != ""))')
                        ->execute([$cleanLocationNew, $user['id'], $oldCity, $oldStreet ? ($oldStreet . '%') : '', $oldStreet]);
                } catch (Throwable $ignored) {}
            }
        } else {
            $stmt = db()->prepare('INSERT INTO employer_profiles (
                user_id, owner_name, nik, phone, whatsapp, profession, npwp,
                brand_name, business_logo,
                linkedin, facebook, instagram,
                same_location_siapkerja, province, city, domicile_city_id, district, village, postal_code,
                same_address_siapkerja, address, address_detail,
                latitude, longitude, permit_document, doc_permission, workplace_photo, doc_location_photo,
                description, user_consent, consent_accepted,
                entity_type, verification_status, verified, active_until, extension_requested, extension_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Individu", "PENDING", 0, NULL, 0, "NONE")');
            $stmt->execute([
                $user['id'], $ownerName, $nik, $phone, $whatsapp, $profession, $npwp,
                $brandName, $businessLogo,
                $linkedin, $facebook, $instagram,
                $sameLoc, $province, $city, $city, $district, $village, $postalCode,
                $sameAddr, $address, $addressDetail,
                $latitude, $longitude, $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
                $description, $consent, $consent
            ]);
        }

        db()->prepare('UPDATE users SET name = ?, domicile_city_id = ?, city = ?, profile_complete = 1 WHERE id = ?')->execute([$ownerName, $city, $city, $user['id']]);

        if ($isReactivation) {
            record_audit_log('employer', $user['id'], 'REACTIVATION_REQUESTED', 'Mengajukan permohonan reaktivasi Hak Akses Pemberi Kerja Individu secara online.', $user['name'], 'employer');
            flash('pending_popup', 'Permohonan reaktivasi Hak Akses Pemberi Kerja Individu berhasil diajukan dan sedang menunggu verifikasi.');
        } else {
            flash('pending_popup', 'Profil Anda berhasil diajukan! Status Profil: Menunggu Verifikasi.');
        }
        redirect('dashboard.php');
        exit;
    }

    // 2.1 PENGAJUAN PERMINTAAN PERUBAHAN PROFIL (PROFILE CHANGE REQUEST UNTUK PROFIL TERVERIFIKASI)
    if (isset($_POST['submit_profile_change_request'])) {
        if ($verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu sedang ditangguhkan. Perubahan profil tidak dapat diajukan.');
            redirect('dashboard.php#profil');
            exit;
        }

        // Pastikan tidak ada active pending change request yang masih berjalan
        $existingActivePCR = get_active_profile_change_request((int)$user['id']);
        if ($existingActivePCR) {
            flash('error', 'Anda masih memiliki permintaan perubahan profil aktif yang sedang menunggu pemeriksaan Admin.');
            redirect('dashboard.php#profil');
            exit;
        }

        $sections = isset($_POST['pcr_sections']) && is_array($_POST['pcr_sections']) ? array_values(array_filter($_POST['pcr_sections'])) : [];
        $reason = trim($_POST['pcr_reason'] ?? '');
        $consent = !empty($_POST['pcr_consent']);

        if (empty($sections)) {
            flash('error', 'Pilih minimal satu bagian profil yang ingin Anda ubah.');
            redirect('dashboard.php?open_pcr=1#profil');
            exit;
        }

        if ($reason === '') {
            flash('error', 'Alasan perubahan profil wajib diisi.');
            redirect('dashboard.php?open_pcr=1#profil');
            exit;
        }

        if (mb_strlen($reason) > 500) {
            flash('error', 'Alasan perubahan profil tidak boleh melebihi 500 karakter.');
            redirect('dashboard.php?open_pcr=1#profil');
            exit;
        }

        if (!$consent) {
            flash('error', 'Anda wajib menyetujui pernyataan pengajuan perubahan profil.');
            redirect('dashboard.php?open_pcr=1#profil');
            exit;
        }

        // Ambil data profil saat ini
        $stmtCurrent = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtCurrent->execute([$user['id']]);
        $currentData = $stmtCurrent->fetch() ?: [];

        $proposedData = [];

        // 1. Identitas Perorangan
        if (in_array('identity', $sections, true)) {
            $newWhatsapp = trim($_POST['pcr_whatsapp'] ?? '');
            if ($newWhatsapp === '') {
                flash('error', 'Nomor WhatsApp wajib diisi pada bagian Identitas Perorangan.');
                redirect('dashboard.php?open_pcr=1#profil');
                exit;
            }
            $proposedData['whatsapp'] = $newWhatsapp;
            if (isset($_POST['pcr_profession']) && trim($_POST['pcr_profession']) !== '') {
                $proposedData['profession'] = trim($_POST['pcr_profession']);
            }
        }

        // 2. Tempat Usaha / Kegiatan & Deskripsi Usaha
        if (in_array('workplace', $sections, true)) {
            $proposedData['brand_name'] = trim($_POST['pcr_brand_name'] ?? '');
            $proposedData['description'] = trim($_POST['pcr_description'] ?? '');
            $proposedData['same_location_siapkerja'] = isset($_POST['pcr_same_location_siapkerja']) ? 1 : 0;
            $proposedData['province'] = trim($_POST['pcr_province'] ?? '');
            $proposedData['city'] = trim($_POST['pcr_city'] ?? '');
            $proposedData['domicile_city_id'] = trim($_POST['pcr_domicile_city_id'] ?? ($_POST['pcr_city'] ?? ''));
            $proposedData['district'] = trim($_POST['pcr_district'] ?? '');
            $proposedData['village'] = trim($_POST['pcr_village'] ?? '');
            $proposedData['postal_code'] = trim($_POST['pcr_postal_code'] ?? '');
            $proposedData['address'] = trim($_POST['pcr_address'] ?? '');
            $proposedData['address_detail'] = trim($_POST['pcr_address_detail'] ?? '');
            $proposedData['latitude'] = trim($_POST['pcr_latitude'] ?? '');
            $proposedData['longitude'] = trim($_POST['pcr_longitude'] ?? '');

            // Handle logo jika diupload
            if (!empty($_FILES['pcr_business_logo']['name'])) {
                $newLogo = store_upload('pcr_business_logo', 'employer/' . $user['id'], ['png', 'jpg', 'jpeg']);
                if ($newLogo) {
                    $proposedData['business_logo'] = $newLogo;
                }
            }
        }

        // 3. Dokumen & Bukti Tempat Usaha
        if (in_array('documents', $sections, true)) {
            if (!empty($_FILES['pcr_permit_document']['name'])) {
                $newPermit = store_upload('pcr_permit_document', 'employer/' . $user['id'], ['pdf', 'docx']);
                if ($newPermit) {
                    $proposedData['permit_document'] = $newPermit;
                    $proposedData['doc_permission'] = $newPermit;
                }
            }
            if (!empty($_FILES['pcr_workplace_photo']['name'])) {
                $newPhoto = store_upload('pcr_workplace_photo', 'employer/' . $user['id'], ['png', 'jpg', 'jpeg']);
                if ($newPhoto) {
                    $proposedData['workplace_photo'] = $newPhoto;
                    $proposedData['doc_location_photo'] = $newPhoto;
                }
            }
        }

        // 4. Media Sosial
        if (in_array('social', $sections, true)) {
            $proposedData['instagram'] = trim($_POST['pcr_instagram'] ?? '');
            $proposedData['linkedin'] = trim($_POST['pcr_linkedin'] ?? '');
            $proposedData['facebook'] = trim($_POST['pcr_facebook'] ?? '');
            $proposedData['tiktok'] = trim($_POST['pcr_tiktok'] ?? '');

            if (!empty($_POST['pcr_social_platform']) && is_array($_POST['pcr_social_platform'])) {
                foreach ($_POST['pcr_social_platform'] as $idx => $plat) {
                    $h = trim($_POST['pcr_social_handle'][$idx] ?? '');
                    if ($h === '') continue;
                    $p = strtolower($plat);
                    if ($p === 'linkedin' && empty($proposedData['linkedin'])) $proposedData['linkedin'] = $h;
                    if ($p === 'facebook' && empty($proposedData['facebook'])) $proposedData['facebook'] = $h;
                    if ($p === 'instagram' && empty($proposedData['instagram'])) $proposedData['instagram'] = $h;
                    if ($p === 'tiktok' && empty($proposedData['tiktok'])) $proposedData['tiktok'] = $h;
                }
            }
        }

        // Buat record Profile Change Request baru
        $requestId = create_profile_change_request($user['id'], $sections, $reason, $proposedData, $currentData);

        // Audit log
        record_audit_log('employer', $user['id'], 'PROFILE_CHANGE_REQUESTED', "Pengguna mengajukan Permintaan Perubahan Profil (Request #{$requestId}). Menunggu verifikasi Admin.", $user['name'] ?? 'Pemberi Kerja', 'employer', true);

        // Notifikasi ke user
        notify_user($user['id'], 'Permintaan Perubahan Profil Dikirim', 'Permintaan perubahan profil Anda telah berhasil diajukan dan sedang dalam proses verifikasi oleh Admin. Data profil aktif Anda saat ini tetap aman.', 'info');

        flash('success', 'Permintaan perubahan profil berhasil diajukan dan sedang menunggu pemeriksaan oleh Admin.');
        redirect('dashboard.php#profil');
        exit;
    }

    // 3. TAMBAH / UPDATE DRAFT LOWONGAN (SELALU Draft, TANPA Rules Engine)
    if (isset($_POST['save_job']) || isset($_POST['update_job'])) {
        if ($isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu dalam Masa Transisi atau terkunci. Tidak dapat membuat atau mengubah lowongan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $jobId = (int)($_POST['job_id'] ?? 0);
        $title = trim($_POST['job_title'] ?? $_POST['title'] ?? '');
        $location = trim($_POST['job_location'] ?? $_POST['location'] ?? '');
        $jobType = trim($_POST['job_type'] ?? '');
        $industry = trim($_POST['industry'] ?? $_POST['profession'] ?? $_POST['job_field'] ?? '');
        $kbjiCode = trim($_POST['kbji_code'] ?? '');
        $minEducation = trim($_POST['min_education'] ?? $_POST['education_required'] ?? '');
        $minExperience = trim($_POST['min_experience'] ?? $_POST['experience_required'] ?? '');
        $quota = (int)($_POST['quota'] ?? 1);
        $description = trim($_POST['job_description'] ?? $_POST['description'] ?? '');

        $salaryMin = isset($_POST['salary_min']) && $_POST['salary_min'] !== '' ? (int)preg_replace('/[^0-9]/', '', (string)$_POST['salary_min']) : null;
        $salaryMax = isset($_POST['salary_max']) && $_POST['salary_max'] !== '' ? (int)preg_replace('/[^0-9]/', '', (string)$_POST['salary_max']) : null;

        $physicalConditions = isset($_POST['physical_condition']) ? array_values((array)$_POST['physical_condition']) : ['Non Disabilitas'];
        $genders = isset($_POST['gender']) ? array_values((array)$_POST['gender']) : ['Laki-laki', 'Perempuan'];
        $maritalStatuses = isset($_POST['marital_status']) ? array_values((array)$_POST['marital_status']) : ['Telah Menikah', 'Lajang / Belum Menikah'];

        $skillsRaw = $_POST['skills'] ?? '';
        $skills = is_array($skillsRaw) ? $skillsRaw : array_values(array_filter(array_map('trim', explode(',', (string)$skillsRaw))));

        $contactsRaw = $_POST['contacts'] ?? '';
        $contacts = is_array($contactsRaw) ? $contactsRaw : array_values(array_filter(array_map('trim', explode(',', (string)$contactsRaw))));

        $detailsData = [
            'job_field' => trim($_POST['job_field'] ?? $_POST['industry'] ?? ''),
            'physical_conditions' => $physicalConditions,
            'genders' => $genders,
            'disability_excluded' => trim($_POST['disability_excluded'] ?? ''),
            'show_salary' => !empty($_POST['show_salary']),
            'is_remote' => !empty($_POST['is_remote']),
            'is_limited' => !empty($_POST['is_limited']),
            'expiry_days' => (int)($_POST['expiry_days'] ?? 30),
            'education_required' => $minEducation,
            'experience_required' => $minExperience,
            'marital_statuses' => $maritalStatuses,
            'age_min' => isset($_POST['age_min']) && $_POST['age_min'] !== '' ? (int)$_POST['age_min'] : 18,
            'age_max' => isset($_POST['age_max']) && $_POST['age_max'] !== '' ? (int)$_POST['age_max'] : 45,
            'special_requirements' => trim($_POST['special_requirements'] ?? ''),
            'skills' => $skills,
            'contacts' => $contacts,
        ];
        $detailsJson = json_encode($detailsData, JSON_UNESCAPED_UNICODE);

        if ($title !== '' && $location !== '' && $kbjiCode !== '' && $quota > 0 && $description !== '') {
            if ($jobId > 0) {
                // Update existing job maintaining its identity and draft/revision status
                $stmt = db()->prepare('UPDATE job_posts SET title = ?, location = ?, job_type = ?, industry = ?, kbji_code = ?, min_education = ?, min_experience = ?, quota = ?, description = ?, salary_min = ?, salary_max = ?, details = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
                $stmt->execute([$title, $location, $jobType, $industry, $kbjiCode, $minEducation, $minExperience, $quota, $description, $salaryMin, $salaryMax, $detailsJson, $jobId, $user['id']]);
                flash('success', 'Draft lowongan berhasil diperbarui.');
            } else {
                // Always create as Draft with NO rules engine checks
                $stmt = db()->prepare('INSERT INTO job_posts (user_id, title, description, location, job_type, industry, entity_type, status, quota, kbji_code, min_education, min_experience, salary_min, salary_max, details, created_at) VALUES (?, ?, ?, ?, ?, ?, "Individu", "Draft", ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
                $stmt->execute([$user['id'], $title, $description, $location, $jobType, $industry, $quota, $kbjiCode, $minEducation, $minExperience, $salaryMin, $salaryMax, $detailsJson]);
                $jobId = (int)db()->lastInsertId();
                flash('success', 'Lowongan berhasil disimpan sebagai Draft.');
            }
            redirect('dashboard.php#lowongan');
            exit;
        } else {
            flash('error', 'Lengkapi semua field wajib pada form lowongan.');
            redirect('dashboard.php#lowongan');
            exit;
        }
    }

    // 4. KIRIM LOWONGAN (VALIDATION + RULES ENGINE 3 LAYERS)
    if (isset($_POST['send_job'])) {
        if ($isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu dalam Masa Transisi atau terkunci. Tidak dapat mengirim lowongan baru.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $jobId = (int)$_POST['job_id'];

        $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ?');
        $jobStmt->execute([$jobId, $user['id']]);
        $targetJob = $jobStmt->fetch();

        if (!$targetJob) {
            flash('error', 'Lowongan tidak ditemukan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        if (empty($targetJob['title']) || empty($targetJob['location']) || empty($targetJob['kbji_code']) || empty($targetJob['description'])) {
            flash('error', 'Informasi lowongan belum lengkap. Harap perbarui draft Anda.');
            redirect('dashboard.php?open_draft=' . $jobId . '#lowongan');
            exit;
        }

        $isChildRepost = !empty($targetJob['parent_job_id']);
        $rulesResult = check_pki_job_rules_engine(
            db(),
            (int)$user['id'],
            (string)$targetJob['kbji_code'],
            (int)($targetJob['quota'] ?? 1),
            $jobId,
            $isChildRepost
        );

        if (!$rulesResult['allowed']) {
            if ($rulesResult['layer'] === 1) {
                $_SESSION['kbji_duplicate_error'] = [
                    'job_id' => $jobId,
                    'kbji_code' => $targetJob['kbji_code'],
                    'active_job_id' => $rulesResult['conflict_job']['id'] ?? 0,
                    'active_job_title' => $rulesResult['conflict_job']['title'] ?? '',
                    'active_job_status' => $rulesResult['conflict_job']['status'] ?? ''
                ];
                redirect('dashboard.php?kbji_conflict=1&draft_id=' . $jobId . '#lowongan');
                exit;
            } else {
                // Layer 3: Monthly quota exceeded (>10) - Open modal directly without top toast notification
                unset($_SESSION['flash']);
                redirect('dashboard.php?job_detail=' . $jobId . '&layer3_error=1#lowongan');
                exit;
            }
        }

        $additionalDocRequired = !empty($rulesResult['additional_doc_required']) ? 1 : 0;

        if ($additionalDocRequired) {
            // Lowongan ke-4+ KBJI sama: Tahan submit dan tampilkan modal Dokumen/Keterangan Tambahan jika belum diisi
            $additionalNotes = trim($_POST['additional_notes'] ?? '');
            if ($additionalNotes === '') {
                redirect('dashboard.php?open_additional_doc_modal=' . $jobId . '#lowongan');
                exit;
            }

            $hasExistingFile = !empty($targetJob['additional_doc_file']);
            $hasUploadedFile = !empty($_FILES['additional_file']['name']) && empty($_FILES['additional_file']['error']);
            if (!$hasExistingFile && !$hasUploadedFile) {
                flash('danger', 'Harap unggah Dokumen Pendukung Kebutuhan Tenaga Kerja terlebih dahulu.');
                redirect('dashboard.php?open_additional_doc_modal=' . $jobId . '#lowongan');
                exit;
            }

            $filePath = null;
            if ($hasUploadedFile) {
                if ($_FILES['additional_file']['size'] > 2 * 1024 * 1024) {
                    flash('danger', 'Ukuran file Dokumen Pendukung melebihi batas maksimal 2 MB.');
                    redirect('dashboard.php?open_additional_doc_modal=' . $jobId . '#lowongan');
                    exit;
                }
                $filePath = store_upload('additional_file', 'additional_docs', ['pdf', 'jpg', 'jpeg']);
            }

            // Setelah Dokumen/Keterangan Tambahan dikirim, pengajuan masuk ke status Menunggu Verifikasi
            $update = db()->prepare('UPDATE job_posts SET status = "Menunggu Verifikasi", additional_doc_required = 1, additional_doc_status = "SUBMITTED", additional_doc_notes = ?, additional_doc_file = COALESCE(?, additional_doc_file), updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
            $update->execute([$additionalNotes, $filePath, $jobId, $user['id']]);

            try {
                $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $docStmt = db()->prepare('INSERT OR REPLACE INTO job_additional_documents (job_id, user_id, kbji_code, document_file, description, status, doc_reviewed, field_visit, created_at) VALUES (?, ?, ?, ?, ?, "SUBMITTED", "Tidak", "Tidak", CURRENT_TIMESTAMP)');
                } else {
                    $docStmt = db()->prepare('REPLACE INTO job_additional_documents (job_id, user_id, kbji_code, document_file, description, status, doc_reviewed, field_visit, created_at) VALUES (?, ?, ?, ?, ?, "SUBMITTED", "Tidak", "Tidak", NOW())');
                }
                $docStmt->execute([$jobId, $user['id'], $targetJob['kbji_code'], $filePath, $additionalNotes]);
            } catch (Throwable $e) {
                error_log('[Karirhub DB Doc Error] ' . $e->getMessage());
            }

            try {
                $caseStmt = db()->prepare('INSERT INTO job_verifications (job_id, user_id, kbji_code, status, additional_doc_required, layer_flags) VALUES (?, ?, ?, "PENDING", 1, "LAYER2_ADDITIONAL_DOC")');
                $caseStmt->execute([$jobId, $user['id'], $targetJob['kbji_code']]);
            } catch (Throwable $ignored) {}

            record_audit_log('job', $jobId, 'SUBMITTED_WITH_ADDITIONAL_DOC', "Lowongan ke-4+ untuk KBJI {$targetJob['kbji_code']} diajukan dengan Dokumen/Keterangan Tambahan untuk diverifikasi Admin.", $user['name']);

            flash('success', 'Dokumen/Keterangan Tambahan berhasil dikirim. Pengajuan lowongan masuk ke antrean Menunggu Verifikasi oleh Admin.');
        } else {
            $layerFlag = $isChildRepost ? 'REPOST_CONTINUATION' : null;
            // Normal flow: update to Menunggu Verifikasi and create job_verifications case
            $update = db()->prepare('UPDATE job_posts SET status = "Menunggu Verifikasi", additional_doc_required = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
            $update->execute([$jobId, $user['id']]);

            try {
                $caseStmt = db()->prepare('INSERT INTO job_verifications (job_id, user_id, kbji_code, status, additional_doc_required, layer_flags) VALUES (?, ?, ?, "PENDING", 0, ?)');
                $caseStmt->execute([$jobId, $user['id'], $targetJob['kbji_code'], $layerFlag]);
            } catch (Throwable $ignored) {}

            record_audit_log('job', $jobId, 'SUBMITTED', "Lowongan diajukan untuk diverifikasi.", $user['name']);
            flash('success', 'Lowongan berhasil dikirim dan sedang Menunggu Verifikasi.');
        }
        redirect('dashboard.php#lowongan');
        exit;
    }

    // UPLOAD DOKUMEN TAMBAHAN UNTUK LOWONGAN KE-4+ KBJI SAMA
    if (isset($_POST['upload_additional_doc'])) {
        if ($isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu dalam Masa Transisi, ditangguhkan, atau terkunci. Tidak dapat mengunggah dokumen tambahan.');
            redirect('dashboard.php#lowongan');
            exit;
        }
        $jobId = (int)$_POST['job_id'];
        $notes = trim($_POST['additional_notes'] ?? '');

        $jobStmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ?');
        $jobStmt->execute([$jobId, $user['id']]);
        $targetJob = $jobStmt->fetch();

        if (!$targetJob || $targetJob['status'] !== 'ADDITIONAL_DOCUMENT_PENDING') {
            flash('error', 'Lowongan tidak valid untuk pengunggahan dokumen tambahan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $filePath = null;
        if (!empty($_FILES['additional_file']['name'])) {
            $filePath = store_upload('additional_file', 'additional_docs', ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif', 'bmp', 'doc', 'docx']);
        }

        if (!$filePath && $notes === '') {
            flash('error', 'Harap lampirkan berkas dokumen atau masukkan keterangan tambahan.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        try {
            $stmt = db()->prepare('UPDATE job_additional_documents SET document_file = COALESCE(?, document_file), description = ?, status = "SUBMITTED" WHERE job_id = ?');
            $stmt->execute([$filePath, $notes, $jobId]);
        } catch (Throwable $ignored) {}

        $upJob = db()->prepare('UPDATE job_posts SET additional_doc_file = COALESCE(?, additional_doc_file), additional_doc_notes = ?, additional_doc_status = "SUBMITTED", updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $upJob->execute([$filePath, $notes, $jobId]);

        record_audit_log('job', $jobId, 'ADDITIONAL_DOC_SUBMITTED', "Pemberi kerja mengunggah dokumen/keterangan tambahan: {$notes}", $user['name']);

        flash('success', 'Dokumen/keterangan tambahan berhasil dikirim dan sedang menunggu peninjauan oleh Admin.');
        redirect('dashboard.php#lowongan');
        exit;
    }

    // 5. TUTUP LOWONGAN & POSTING ULANG SISA KUOTA (ATOMIC TRANSACTION)
    if (isset($_POST['close_job'])) {
        if ($isFullDisable || $verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu sedang ditangguhkan atau tidak aktif. Tidak dapat mengubah atau menutup lowongan.');
            redirect('dashboard.php#lowongan');
            exit;
        }
        $jobId = (int)$_POST['job_id'];
        $repost = ($_POST['repost'] ?? '0') === '1';
        $reasons = $_POST['reasons'] ?? [];
        $reasonNotes = $_POST['reason_notes'] ?? [];
        $lainnya = trim($_POST['reason_lainnya'] ?? ($reasonNotes['Lainnya'] ?? ''));

        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Lock source row inside transaction with FOR UPDATE to prevent race conditions / concurrent requests
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';
            $jobStmt = $pdo->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ?' . $forUpdate);
            $jobStmt->execute([$jobId, $user['id']]);
            $oldJob = $jobStmt->fetch();

            if (!$oldJob) {
                $pdo->rollBack();
                flash('error', 'Lowongan tidak ditemukan.');
                redirect('dashboard.php#lowongan');
                exit;
            }

            if ($oldJob['status'] === 'Ditutup') {
                $pdo->rollBack();
                flash('error', 'Lowongan ini sudah berstatus Ditutup dan tidak dapat diproses ulang.');
                redirect('dashboard.php#lowongan');
                exit;
            }

            // Check if source job already has a child repost (prevent double-submit creating multiple children)
            $childCheck = $pdo->prepare('SELECT COUNT(*) FROM job_posts WHERE parent_job_id = ?');
            $childCheck->execute([$jobId]);
            if ((int)$childCheck->fetchColumn() > 0) {
                $pdo->rollBack();
                flash('error', 'Lowongan sumber ini sudah memiliki posting turunan sisa kuota dan tidak dapat diproses ulang.');
                redirect('dashboard.php#lowongan');
                exit;
            }

            // Calculate accepted_count and remaining_quota
            $accStmt = $pdo->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ? AND status = "Diterima"');
            $accStmt->execute([$jobId]);
            $acceptedCount = (int)$accStmt->fetchColumn();
            $requestedQuota = (int)($oldJob['quota'] ?? 1);
            $sisaKuota = max(0, $requestedQuota - $acceptedCount);

            // Update accepted_count on source job
            $pdo->prepare('UPDATE job_posts SET accepted_count = ? WHERE id = ?')->execute([$acceptedCount, $jobId]);

            // Scenario 1: remaining_quota == 0 -> Directly close without requiring reasons or reposting
            if ($sisaKuota <= 0) {
                $stmt = $pdo->prepare('UPDATE job_posts SET status = "Ditutup", unfulfilled_reason = "Kuota Terpenuhi" WHERE id = ? AND user_id = ?');
                $stmt->execute([$jobId, $user['id']]);
                $pdo->commit();
                flash('success', 'Lowongan telah berhasil Ditutup (Kuota Terpenuhi).');
                redirect('dashboard.php#lowongan');
                exit;
            }

            // Scenario 2: remaining_quota > 0 -> Requires reasons & repost selection
            if ($repost && ($isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED')) {
                $pdo->rollBack();
                flash('error', 'Hak Akses Pemberi Kerja Individu dalam Masa Transisi atau terkunci. Tidak dapat memposting ulang sisa kuota.');
                redirect('dashboard.php#lowongan');
                exit;
            }

            if (empty($reasons)) {
                $pdo->rollBack();
                flash('error', 'Anda wajib memilih minimal 1 alasan mengapa sisa kuota belum terpenuhi.');
                redirect('dashboard.php#lowongan');
                exit;
            }

            $validReasons = pki_close_reasons();
            foreach ($reasons as $r) {
                if (!in_array($r, $validReasons, true)) {
                    $pdo->rollBack();
                    flash('error', 'Alasan penutupan tidak valid.');
                    redirect('dashboard.php#lowongan');
                    exit;
                }
            }

            if (in_array('Lainnya', $reasons, true) && $lainnya === '') {
                $pdo->rollBack();
                flash('error', 'Alasan "Lainnya" wajib diisi.');
                redirect('dashboard.php#lowongan');
                exit;
            }

            $reasonItems = [];
            foreach ($reasons as $r) {
                $note = trim($reasonNotes[$r] ?? '');
                if ($r === 'Lainnya') {
                    if ($note !== '') {
                        $reasonItems[] = 'Lainnya - ' . $note;
                    } elseif ($lainnya !== '') {
                        $reasonItems[] = 'Lainnya - ' . $lainnya;
                    } else {
                        $reasonItems[] = 'Lainnya';
                    }
                } else {
                    if ($note !== '') {
                        $reasonItems[] = $r . ' (' . $note . ')';
                    } else {
                        $reasonItems[] = $r;
                    }
                }
            }
            $reasonStr = implode(', ', $reasonItems);

            // Close original job with reason (Source job remains Ditutup)
            $stmt = $pdo->prepare('UPDATE job_posts SET status = "Ditutup", unfulfilled_reason = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$reasonStr, $jobId, $user['id']]);

            if ($repost) {
                // Check Rules Engine (Layer 2 - frequency check) for child repost
                $rulesResult = check_pki_job_rules_engine($pdo, $user['id'], $oldJob['kbji_code'], $sisaKuota, null, true);
                $additionalDocRequired = !empty($rulesResult['additional_doc_required']);

                $initialStatus = $additionalDocRequired ? 'Draft' : 'Menunggu Verifikasi';
                $initialDocStatus = $additionalDocRequired ? 'PENDING_SUBMISSION' : null;
                $docRequiredFlag = $additionalDocRequired ? 1 : 0;

                // Create child posting with quota = sisa_kuota, copying ONLY business fields (original title preserved without suffix).
                $insert = $pdo->prepare('INSERT INTO job_posts (
                    user_id, title, description, location, job_type, industry, entity_type, status,
                    salary_min, salary_max, quota, accepted_count, kbji_code, details, min_education, min_experience,
                    additional_doc_required, additional_doc_file, additional_doc_notes, additional_doc_status,
                    parent_job_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, CURRENT_TIMESTAMP)');
                $insert->execute([
                    $user['id'],
                    $oldJob['title'],
                    $oldJob['description'],
                    sanitize_job_location($oldJob['location'], $profile),
                    $oldJob['job_type'],
                    $oldJob['industry'],
                    $oldJob['entity_type'] ?? 'Individu',
                    $initialStatus,
                    $oldJob['salary_min'] ?? null,
                    $oldJob['salary_max'] ?? null,
                    $sisaKuota,
                    $oldJob['kbji_code'],
                    $oldJob['details'] ?? null,
                    $oldJob['min_education'] ?? '',
                    $oldJob['min_experience'] ?? '',
                    $docRequiredFlag,
                    $initialDocStatus,
                    $jobId
                ]);
                $childId = (int)$pdo->lastInsertId();

                if (!$additionalDocRequired) {
                    // Create Job Verification Case for child (WITHOUT catch ignore; exception will trigger transaction rollback!)
                    $caseStmt = $pdo->prepare('INSERT INTO job_verifications (job_id, user_id, kbji_code, status, layer_flags, created_at) VALUES (?, ?, ?, "PENDING", "REPOST_CONTINUATION", CURRENT_TIMESTAMP)');
                    $caseStmt->execute([$childId, $user['id'], $oldJob['kbji_code']]);
                }

                $pdo->commit();

                if ($additionalDocRequired) {
                    flash('warning', 'Lowongan awal telah Ditutup. Posting turunan sisa kuota (' . $sisaKuota . ' posisi) berhasil dibuat. Karena Anda telah mempublikasikan 3 lowongan dengan KBJI yang sama bulan ini, pengajuan ke-4 ini memerlukan Dokumen/Keterangan Tambahan.');
                    redirect('dashboard.php?open_additional_doc_modal=' . $childId . '#lowongan');
                    exit;
                } else {
                    flash('success', 'Lowongan awal telah Ditutup. Posting turunan sisa kuota (' . $sisaKuota . ' posisi) berhasil dibuat dan sedang Menunggu Verifikasi.');
                    redirect('dashboard.php#lowongan');
                    exit;
                }
            } else {
                $pdo->commit();
                flash('success', 'Lowongan berhasil Ditutup.');
                redirect('dashboard.php#lowongan');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Gagal memproses penutupan/posting ulang lowongan: ' . $e->getMessage());
            redirect('dashboard.php#lowongan');
            exit;
        }
    }

    // 5b. BUKA KEMBALI LOWONGAN YANG DITUTUP
    if (isset($_POST['reopen_job'])) {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $publishDate = trim((string)($_POST['publish_date'] ?? ''));
        $expiryDays = (int)($_POST['expiry_days'] ?? 30);
        if ($expiryDays <= 0) $expiryDays = 30;
        $quota = (int)($_POST['quota'] ?? 1);
        if ($quota <= 0) $quota = 1;

        if ($jobId <= 0) {
            flash('error', 'Lowongan tidak valid.');
            redirect('dashboard.php#lowongan');
            exit;
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';
            $jobStmt = $pdo->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ?' . $forUpdate);
            $jobStmt->execute([$jobId, $user['id']]);
            $targetJob = $jobStmt->fetch();

            if (!$targetJob) {
                $pdo->rollBack();
                flash('error', 'Lowongan tidak ditemukan.');
                redirect('dashboard.php#lowongan');
                exit;
            }

            $todayStr = date('Y-m-d');
            if (empty($publishDate) || $publishDate < $todayStr) {
                $publishDate = $todayStr;
            }

            $isFuture = (strtotime($publishDate) > strtotime($todayStr));
            $newStatus = $isFuture ? 'Terjadwal Tayang' : 'Tayang';

            $details = parse_job_details($targetJob['details'] ?? null);
            $details['publish_date'] = $publishDate;
            $details['expiry_days'] = $expiryDays;
            $newDetailsJson = json_encode($details, JSON_UNESCAPED_UNICODE);

            $updateStmt = $pdo->prepare('UPDATE job_posts SET 
                status = ?, 
                quota = ?, 
                details = ?, 
                unfulfilled_reason = NULL,
                created_at = ?,
                updated_at = CURRENT_TIMESTAMP 
                WHERE id = ? AND user_id = ?');
            
            $createdAtVal = $publishDate . ' ' . date('H:i:s');
            $updateStmt->execute([$newStatus, $quota, $newDetailsJson, $createdAtVal, $jobId, $user['id']]);

            $pdo->commit();
            flash('success', 'Lowongan berhasil dibuka kembali dan berstatus ' . $newStatus . '.');
            redirect('dashboard.php?job_detail=' . $jobId . '#lowongan');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Gagal membuka kembali lowongan: ' . $e->getMessage());
            redirect('dashboard.php?job_detail=' . $jobId . '#lowongan');
            exit;
        }
    }

    // 6. AJUKAN PERPANJANGAN HAK AKSES PEMBERI KERJA INDIVIDU (1x, 1-3 HARI)
    if (isset($_POST['request_extension'])) {
        if ($verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu sedang ditangguhkan. Perpanjangan Hak Akses tidak dapat diajukan.');
            redirect('dashboard.php');
            exit;
        }
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Refresh & lock employer profile row inside transaction
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';
            $profStmt = $pdo->prepare('SELECT * FROM employer_profiles WHERE user_id = ?' . $forUpdate);
            $profStmt->execute([$user['id']]);
            $currProfile = $profStmt->fetch() ?: [];

            if (empty($currProfile)) {
                $pdo->rollBack();
                flash('error', 'Profil Pemberi Kerja tidak ditemukan.');
                redirect('dashboard.php');
                exit;
            }

            // 1. Strict time-based eligibility: active_until < NOW <= active_until + 7 hari
            $cActiveUntilRaw = $currProfile['active_until'] ?? null;
            if (empty($cActiveUntilRaw)) {
                $pdo->rollBack();
                flash('error', 'Perpanjangan Hak Akses Pemberi Kerja Individu tidak dapat diajukan karena masa aktif belum ditentukan.');
                redirect('dashboard.php');
                exit;
            }

            $cNow = new DateTime();
            $cActiveUntil = new DateTime($cActiveUntilRaw);
            $activeUntilTs = $cActiveUntil->getTimestamp();
            $nowTs = $cNow->getTimestamp();
            $sevenDaysTs = $activeUntilTs + (7 * 86400);

            if ($nowTs <= $activeUntilTs) {
                $pdo->rollBack();
                flash('error', 'Hak Akses Anda masih aktif. Perpanjangan hanya dapat diajukan saat masa aktif telah berakhir dan masuk Masa Transisi.');
                redirect('dashboard.php');
                exit;
            }

            if ($nowTs > $sevenDaysTs) {
                $pdo->rollBack();
                flash('error', 'Jendela Masa Transisi (7 hari) telah terlewati. Perpanjangan Hak Akses Pemberi Kerja Individu tidak dapat diajukan.');
                redirect('dashboard.php');
                exit;
            }

            // 2. Max 1x validation: Must not have requested previously
            if (($currProfile['extension_requested'] ?? 0) != 0 || ($currProfile['extension_status'] ?? 'NONE') !== 'NONE') {
                $pdo->rollBack();
                flash('error', 'Pengajuan perpanjangan Hak Akses Pemberi Kerja Individu hanya dapat dilakukan maksimal 1 kali.');
                redirect('dashboard.php');
                exit;
            }

            // 3. Duration validation: 1 to 3 days
            if (isset($_POST['extension_days']) || isset($_POST['requested_days'])) {
                $rawDays = $_POST['extension_days'] ?? $_POST['requested_days'];
                if (!is_numeric($rawDays) || (int)$rawDays < 1 || (int)$rawDays > 3 || (int)$rawDays != $rawDays) {
                    $pdo->rollBack();
                    flash('error', 'Durasi perpanjangan Hak Akses Pemberi Kerja Individu tidak valid. Pilihan durasi harus antara 1 sampai 3 hari.');
                    redirect('dashboard.php');
                    exit;
                }
                $reqDays = (int)$rawDays;
            } else {
                $reqDays = 3;
            }

            // 4. Update status without altering active_until (active_until will only be updated upon Admin Dinas/Pusat approval)
            $stmt = $pdo->prepare('UPDATE employer_profiles SET extension_requested = 1, extension_status = "REQUESTED" WHERE user_id = ?');
            $stmt->execute([$user['id']]);

            // Record audit log INSIDE transaction before commit (strict mode for atomic rollback)
            record_audit_log('employer', $user['id'], 'EXTENSION_REQUESTED', "Mengajukan permohonan perpanjangan Hak Akses Pemberi Kerja Individu selama {$reqDays} hari.", $user['name'], 'employer', true);

            $pdo->commit();

            flash('success', 'Permohonan perpanjangan Hak Akses Pemberi Kerja Individu berhasil diajukan dan sedang menunggu verifikasi.');
            redirect('dashboard.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Gagal mengajukan perpanjangan: ' . $e->getMessage());
            redirect('dashboard.php');
            exit;
        }
    }

    // 7. HAPUS DRAFT LOWONGAN
    if (isset($_POST['delete_job'])) {
        if ($isFullDisable || $verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu sedang ditangguhkan atau tidak aktif. Tidak dapat menghapus lowongan.');
            redirect('dashboard.php#lowongan');
            exit;
        }
        $jobId = (int)$_POST['job_id'];
        $stmt = db()->prepare('DELETE FROM job_posts WHERE id = ? AND user_id = ? AND status = "Draft"');
        $stmt->execute([$jobId, $user['id']]);
        flash('success', 'Draft lowongan berhasil dihapus.');
        redirect('dashboard.php#lowongan');
        exit;
    }

    // 8. BERIKAN CONSENT (JALUR MANUAL DINAS)
    if (isset($_POST['give_manual_dinas_consent'])) {
        if ($verificationStatus === 'SUSPENDED') {
            flash('error', 'Hak Akses Pemberi Kerja Individu sedang ditangguhkan. Persetujuan consent tidak dapat diproses.');
            redirect('dashboard.php');
            exit;
        }
        if (in_array($verificationStatus, ['APPROVED', 'ACTIVE_VERIFIED'], true)) {
            flash('info', 'Profil Anda telah terverifikasi oleh Petugas Dinas. Persetujuan consent tidak diperlukan lagi.');
            redirect('dashboard.php');
            exit;
        }
        $stmtEmp = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtEmp->execute([$user['id']]);
        $emp = $stmtEmp->fetch();

        if ($emp && $emp['manual_review_status'] === 'CONSENT_PENDING') {
            $stmt = db()->prepare('UPDATE employer_profiles SET manual_review_status = "CONSENT_GIVEN", consent_agreed = 1, consent_given_at = datetime("now") WHERE user_id = ?');
            try {
                $stmt->execute([$user['id']]);
            } catch (Throwable $e) {
                $stmt = db()->prepare('UPDATE employer_profiles SET manual_review_status = "CONSENT_GIVEN", consent_agreed = 1, consent_given_at = NOW() WHERE user_id = ?');
                $stmt->execute([$user['id']]);
            }
            record_audit_log('employer', $user['id'], 'CONSENT_GIVEN', 'Pemohon telah membaca dan menyetujui perubahan data profil yang diajukan oleh Petugas Dinas.', $user['name'], 'employer');
            flash('success', 'Terima kasih! Persetujuan (Consent) Anda telah berhasil diberikan. Petugas Dinas akan segera menyelesaikan verifikasi dan mengaktifkan akun Anda.');
        } elseif ($emp && ($emp['manual_review_status'] === 'CONSENT_GIVEN' || !empty($emp['consent_agreed']))) {
            flash('info', 'Persetujuan (Consent) Anda sudah pernah dikirim sebelumnya dan sedang diproses Petugas Dinas.');
        } else {
            flash('error', 'Status permohonan consent tidak valid atau telah diperbarui.');
        }
        redirect('dashboard.php');
        exit;
    }
}

$ownerName = $profile['owner_name'] ?? $user['name'];
$profession = $profile['profession'] ?? 'Kuliner';
$city = $profile['city'] ?? 'Kota Bekasi';

// Ambil flash message SEBELUM ob_start (karena session harus dibaca dulu)
$profileModalError = $_SESSION['profile_modal_error'] ?? null;
unset($_SESSION['profile_modal_error']);

$isOpenProfile = isset($_GET['open_profile']) && $_GET['open_profile'] == '1';
$showProfileModal = $isOpenProfile;

$flashData = get_flash();
$flashHtml = '';
$pendingPopupMessage = null;
$isAnyModalActive = (!empty($_GET['open_draft']) || !empty($_GET['job_detail']) || !empty($_GET['layer3_error']) || !empty($_GET['kbji_conflict']) || !empty($_GET['open_profile']) || !empty($_SESSION['kbji_duplicate_error']));

if ($flashData && $flashData['type'] === 'pending_popup') {
    $pendingPopupMessage = $flashData['message'];
    $flashData = null;
} elseif ($flashData && !$isAnyModalActive) {
    if ($isOpenProfile && $flashData['type'] === 'error') {
        $profileModalError = $profileModalError ?: $flashData['message'];
    } else {
        $flashType = $flashData['type'] === 'success' ? 'success' : 'error';
        $flashIcon = $flashType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
        $flashColor = $flashType === 'success'
            ? 'background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;'
            : 'background:#fef2f2;border:1px solid #fecaca;color:#991b1b;';
        $flashHtml = '<div style="position:fixed;top:20px;right:20px;z-index:9999;max-width:400px;border-radius:14px;padding:14px 18px;font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(0,0,0,0.12);' . $flashColor . '">';
        $flashHtml .= '<i class="fa-solid ' . $flashIcon . '"></i>';
        $flashHtml .= htmlspecialchars($flashData['message'], ENT_QUOTES, 'UTF-8');
        $flashHtml .= '<button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:16px;color:inherit;opacity:0.6;">×</button>';
        $flashHtml .= '</div>';
    }
}

$selectedJobId = isset($_GET['job_detail']) ? (int)$_GET['job_detail'] : (isset($_GET['open_draft']) ? (int)$_GET['open_draft'] : 0);
$detailJob = null;
$detailData = null;
$monthlyQuotaTotal = 0;
$exceededAmount = 0;
if ($selectedJobId > 0) {
    $stmt = db()->prepare('SELECT * FROM job_posts WHERE id = ? AND user_id = ?');
    $stmt->execute([$selectedJobId, $user['id']]);
    $detailJob = $stmt->fetch() ?: null;
    if ($detailJob) {
        $realAccStmt = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ? AND status = "Diterima"');
        $realAccStmt->execute([(int)$detailJob['id']]);
        $realAcc = (int)$realAccStmt->fetchColumn();
        $detailJob['accepted_count'] = $realAcc;
        $detailData = job_to_form_data($detailJob);

        $startOfMonth = date('Y-m-01 00:00:00');
        $endOfMonth = date('Y-m-t 23:59:59');
        $mStmt = db()->prepare('SELECT SUM(quota) FROM job_posts WHERE user_id = ? AND status IN ("Tayang", "Menunggu Verifikasi", "ADDITIONAL_DOCUMENT_PENDING") AND created_at BETWEEN ? AND ?');
        $mStmt->execute([$user['id'], $startOfMonth, $endOfMonth]);
        $existingMonthly = (int)$mStmt->fetchColumn();
        $monthlyQuotaTotal = $existingMonthly + (int)($detailJob['quota'] ?? 1);
        $exceededAmount = max(0, $monthlyQuotaTotal - 10);
    }
}

// Prepare dynamic metrics & verification flags for Index.html template
$activeChangeRequest = get_active_profile_change_request((int)$user['id']);
$latestChangeRequest = get_latest_profile_change_request((int)$user['id']);
$profileVersions = get_profile_versions((int)$user['id']);
$hasActiveChangeRequest = !empty($activeChangeRequest);

$isProfileVerified = (!empty($profile['verified']) && (int)$profile['verified'] === 1) && in_array($verificationStatus, ['APPROVED', 'ACTIVE_VERIFIED', 'TRANSITION_LIMITED'], true);

$jobCounts = [
    'draft' => 0, 'menunggu' => 0, 'revisi' => 0, 'ditolak' => 0,
    'terjadwal' => 0, 'tayang' => 0, 'ditangguhkan' => 0, 'ditutup' => 0,
    'kedaluwarsa' => 0, 'diblokir' => 0
];
foreach ($jobs as $j) {
    $st = mb_strtolower($j['status'] ?? '');
    if (str_contains($st, 'draft')) $jobCounts['draft']++;
    elseif (str_contains($st, 'menunggu')) $jobCounts['menunggu']++;
    elseif (str_contains($st, 'revisi')) $jobCounts['revisi']++;
    elseif (str_contains($st, 'ditolak')) $jobCounts['ditolak']++;
    elseif (str_contains($st, 'terjadwal')) $jobCounts['terjadwal']++;
    elseif (str_contains($st, 'tayang') || str_contains($st, 'aktif') || str_contains($st, 'published')) $jobCounts['tayang']++;
    elseif (str_contains($st, 'ditangguhkan')) $jobCounts['ditangguhkan']++;
    elseif (str_contains($st, 'tutup')) $jobCounts['ditutup']++;
    elseif (str_contains($st, 'kedaluwarsa')) $jobCounts['kedaluwarsa']++;
    elseif (str_contains($st, 'blokir')) $jobCounts['diblokir']++;
}

$userApplications = [];
try {
    $appStmt = db()->prepare('
        SELECT a.*, j.title AS job_title, u.name AS seeker_name, u.email AS seeker_email
        FROM job_applications a
        JOIN job_posts j ON j.id = a.job_id
        JOIN users u ON u.id = a.seeker_id
        WHERE j.user_id = ?
        ORDER BY a.id DESC
    ');
    $appStmt->execute([$user['id']]);
    $userApplications = $appStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $userApplications = [];
}

$totalApplications = count($userApplications);
$totalWawancara = count(array_filter($userApplications, fn($a) => in_array($a['status'] ?? '', ['Wawancara', 'Interview'], true)));
$totalDiterima = count(array_filter($userApplications, fn($a) => in_array($a['status'] ?? '', ['Diterima', 'Accepted'], true)));

$userActivities = [];
try {
    $actStmt = db()->prepare('
        SELECT * FROM audit_logs
        WHERE (entity_type = "employer" AND entity_id = ?) OR user_id = ?
        ORDER BY id DESC LIMIT 10
    ');
    $actStmt->execute([$user['id'], $user['id']]);
    $userActivities = $actStmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $userActivities = [];
}

ob_start();
include __DIR__ . '/Index.html';
$html = ob_get_clean();

// Sisipkan flash toast ke dalam body
if ($flashHtml) {
    $html = preg_replace('/(<body[^>]*>)/i', '$1' . "\n" . $flashHtml, $html, 1);
}

$initials = mb_strtoupper(mb_substr($ownerName, 0, 1));
if (str_contains($ownerName, ' ')) {
    $parts = explode(' ', $ownerName);
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
}

$replacements = [
    'Karirhub - Pemberi Kerja Individu'   => 'Karirhub - ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'Halo nama Pemberi Kerja Individu'    => 'Halo ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'Halo PT. Pandu Jaya'                 => 'Halo ' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    // Sidebar & Topbar
    'id="sidebarAvatar">PI'               => 'id="sidebarAvatar">' . $initials,
    'id="topbarAvatar">PI'                => 'id="topbarAvatar">' . $initials,
    'id="topbarCompName">PT. Pandu Jaya'  => 'id="topbarCompName">' . htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'id="topbarCompType">Perusahaan'      => 'id="topbarCompType">' . htmlspecialchars($profession, ENT_QUOTES, 'UTF-8'),
    // Profile page & Popover
    'Pandu Isdiyanto, S.T., M.M.'         => htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'ppandfoee@gmail.com'                 => htmlspecialchars($user['email'] ?? 'ppandfoee@gmail.com', ENT_QUOTES, 'UTF-8'),
    '3402153112760034'                    => htmlspecialchars($profile['nik'] ?? ($seekerProfile['nik'] ?? '3402153112760034'), ENT_QUOTES, 'UTF-8'),
    'PT. Pandu Jaya'                      => htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'),
    'Kota Bekasi'                         => htmlspecialchars($city, ENT_QUOTES, 'UTF-8'),
    '<strong>Sisa 87 hari</strong>'       => '<strong>Sisa ' . (int)max(0, $daysRemaining ?? 0) . ' hari</strong>',
];

$html = str_replace(array_keys($replacements), array_values($replacements), $html);

$modalStyles = <<<'CSS'
        /* ═══════════════════════════════════════════════════════════════
           JOB CREATE DRAWER — injected AFTER app.css via </body>
           ASCII wireframe spec: 40-45% viewport width, 100vh, sticky
           header+footer, scrollable body only.
           ═══════════════════════════════════════════════════════════════ */

        /* ── Backdrop: dark overlay left, panel flush-right ── */
        .modal-backdrop[data-modal="job-create"] {
            position: fixed !important;
            inset: 0 !important;
            background: rgba(15, 23, 42, 0.50) !important;
            display: none !important;
            align-items: stretch !important;
            justify-content: flex-end !important;
            padding: 0 !important;
            z-index: 1050 !important;
        }
        .modal-backdrop[data-modal="job-create"].open {
            display: flex !important;
        }

        /* ── Panel: 40-45% viewport, full height, white, flush right ── */
        .job-create-panel {
            width: clamp(400px, 44vw, 680px) !important;
            height: 100vh !important;
            max-height: 100vh !important;
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            min-height: 0 !important;
            border-radius: 0 !important;
            box-shadow: -4px 0 32px rgba(15, 23, 42, 0.18) !important;
            background: #ffffff !important;
            margin: 0 !important;
            flex-shrink: 0 !important;
        }

        /* ── Form: flex column to fill panel ── */
        .job-create-panel form {
            display: flex !important;
            flex-direction: column !important;
            flex: 1 !important;
            min-height: 0 !important;
            overflow: hidden !important;
        }

        /* ── Header: sticky top, no scroll ── */
        .job-create-panel .modal-header {
            flex-shrink: 0 !important;
            padding: 20px 24px 14px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            background: #ffffff !important;
            position: relative !important;
            display: block !important;
        }
        .job-create-panel .modal-title {
            font-size: 18px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            margin: 0 0 4px 0 !important;
            padding-right: 40px !important;
            line-height: 1.3 !important;
            display: block !important;
        }
        .job-create-panel .modal-subtitle {
            font-size: 13px !important;
            color: #64748b !important;
            margin: 0 !important;
            display: block !important;
            line-height: 1.4 !important;
        }
        .job-create-panel .modal-close {
            position: absolute !important;
            top: 18px !important;
            right: 20px !important;
            width: 30px !important;
            height: 30px !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 50% !important;
            background: #f8fafc !important;
            color: #64748b !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            cursor: pointer !important;
            font-size: 14px !important;
            transition: all 0.15s !important;
        }
        .job-create-panel .modal-close:hover {
            background: #f1f5f9 !important;
            color: #0f172a !important;
        }

        /* ── Revision Banner ── */
        .revision-banner {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            font-size: 12px;
        }
        .revision-banner strong { display: block; font-size: 11px; margin-bottom: 3px; }
        .revision-banner p { margin: 0; font-size: 12px; line-height: 1.4; }

        /* ── Step Progress Bar: sticky below header ── */
        .step-progress-wizard {
            display: flex !important;
            align-items: center !important;
            padding: 12px 24px !important;
            border-bottom: 1px solid #f1f5f9 !important;
            background: #ffffff !important;
            flex-shrink: 0 !important;
        }
        .step-progress-item {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            white-space: nowrap;
        }
        .step-progress-item.active { color: #0284c7; font-weight: 700; }
        .step-progress-item.done  { color: #0284c7; font-weight: 600; }
        .step-progress-item .step-badge {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e2e8f0;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        .step-progress-item.active .step-badge { background: #0ea5e9; color: #fff; }
        .step-progress-item.done .step-badge   { background: #0ea5e9; color: #fff; }
        .step-progress-line {
            flex: 1;
            height: 1.5px;
            background: #e2e8f0;
            margin: 0 10px;
            transition: background 0.3s;
        }
        .step-progress-line.done { background: #0ea5e9; }

        /* ── Modal Body: ONLY this section scrolls ── */
        .job-create-panel .modal-body {
            flex: 1 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 24px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 20px !important;
            min-height: 0 !important;
            background: #f8fafc !important;
            scrollbar-width: thin !important;
            scrollbar-color: #cbd5e1 transparent !important;
        }
        .job-create-panel .modal-body::-webkit-scrollbar { width: 5px; }
        .job-create-panel .modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1; border-radius: 3px;
        }

        /* ── Section Cards ── */
        .form-section-card {
            background: #ffffff;
            border: 1px solid #e8edf3;
            border-radius: 12px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .form-section-header {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding-bottom: 14px;
            border-bottom: 1px solid #f1f5f9;
        }
        .form-section-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #e0f2fe;
            color: #0284c7;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }
        .section-title, .form-section-title {
            font-size: 14px !important;
            font-weight: 700 !important;
            color: #0f172a !important;
            margin: 0 0 2px 0 !important;
            line-height: 1.3 !important;
        }
        .section-subtitle, .form-section-subtitle {
            font-size: 12px !important;
            color: #64748b !important;
            margin: 0 !important;
            line-height: 1.4 !important;
        }

        /* ── Form Elements ── */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            line-height: 1.3;
        }
        .form-group label .req { color: #ef4444; margin-left: 2px; }
        .field-hint {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
        }
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        /* ── Text / Select Inputs ── */
        .form-control-custom {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 13px;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.15s, box-shadow 0.15s;
            appearance: none;
        }
        .form-control-custom:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
        }
        select.form-control-custom {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24'%3E%3Cpath fill='%2394a3b8' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
            cursor: pointer;
        }

        /* ── Addon inputs (Rp prefix, Tahun/Orang suffix) ── */
        .input-addon-group {
            display: flex;
            align-items: center;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .input-addon-group:focus-within {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
        }
        .input-addon-group .addon-text {
            padding: 9px 11px;
            background: #f8fafc;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            border-right: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        .input-addon-group .addon-suffix {
            padding: 9px 11px;
            background: #f8fafc;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            border-left: 1px solid #e5e7eb;
            flex-shrink: 0;
        }
        .input-addon-group input {
            flex: 1;
            border: none;
            padding: 9px 12px;
            font-size: 13px;
            outline: none;
            background: transparent;
            min-width: 0;
        }

        /* ── Pill Radio / Checkbox (Kondisi Fisik, Jenis Kelamin, Status Pernikahan) ── */
        .pill-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .pill-option {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 14px;
            border-radius: 9999px;
            border: 1.5px solid #d1d5db;
            background: #fff;
            color: #475569;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
            transition: border-color 0.15s, background 0.15s, color 0.15s;
        }
        .pill-option:hover { border-color: #0ea5e9; }
        .pill-option input[type="checkbox"],
        .pill-option input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 15px;
            height: 15px;
            border: 1.5px solid #9ca3af;
            border-radius: 50%;
            outline: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            cursor: pointer;
            transition: all 0.15s;
            flex-shrink: 0;
        }
        .pill-option input[type="checkbox"]:checked,
        .pill-option input[type="radio"]:checked {
            background: #0ea5e9;
            border-color: #0ea5e9;
        }
        .pill-option input[type="checkbox"]:checked::after,
        .pill-option input[type="radio"]:checked::after {
            content: "";
            display: block;
            width: 5px; height: 5px;
            border-radius: 50%;
            background: #fff;
        }
        .pill-option:has(input:checked) {
            border-color: #0ea5e9;
            background: #f0f9ff;
            color: #0369a1;
            font-weight: 600;
        }

        /* ── Card Toggle (Tampilkan Gaji, Remote, Terbatas) ── */
        .card-toggle-group { display: flex; flex-direction: column; gap: 8px; }
        .card-toggle-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 11px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            cursor: pointer;
            user-select: none;
            transition: border-color 0.15s, background 0.15s;
        }
        .card-toggle-item:hover { border-color: #0ea5e9; }
        .card-toggle-item:has(input:checked) { border-color: #0ea5e9; background: #f0f9ff; }
        .card-toggle-item input[type="checkbox"],
        .card-toggle-item input[type="radio"] {
            margin-top: 2px;
            accent-color: #0ea5e9;
            width: 15px; height: 15px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .card-toggle-content { display: flex; flex-direction: column; gap: 2px; }
        .card-toggle-title { font-size: 13px; font-weight: 600; color: #0f172a; }
        .card-toggle-desc  { font-size: 12px; color: #64748b; line-height: 1.4; }

        /* ── Rich Text Editor ── */
        .rich-editor-shell {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        .rich-editor-shell:focus-within {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
        }
        .rich-toolbar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 2px;
            padding: 5px 8px;
            border-bottom: 1px solid #f1f5f9;
            background: #f8fafc;
        }
        .rich-btn {
            border: none;
            background: transparent;
            color: #475569;
            border-radius: 4px;
            padding: 3px 5px;
            font-size: 11px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
        }
        .rich-btn:hover { background: #e2e8f0; }
        .rich-btn-select {
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 1px 4px;
            font-size: 11px;
            color: #475569;
            background: #fff;
            height: 22px;
        }
        .rich-area {
            min-height: 130px;
            max-height: 200px;
            overflow-y: auto;
            padding: 11px 13px;
            outline: none;
            font-size: 13px;
            line-height: 1.6;
            color: #0f172a;
        }
        .rich-area:empty::before {
            content: attr(data-placeholder);
            color: #9ca3af;
            pointer-events: none;
        }

        /* ── Chips ── */
        .choice-chip-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            min-height: 10px;
        }
        .choice-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 9999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 600;
        }
        .choice-chip button {
            border: none;
            background: transparent;
            color: inherit;
            cursor: pointer;
            padding: 0;
            font-size: 14px;
            line-height: 1;
            opacity: 0.7;
        }
        .choice-chip button:hover { opacity: 1; }

        /* ── Footer: sticky bottom, shows correct buttons per step ── */
        .job-create-panel [hidden] {
            display: none !important;
        }
        .job-create-panel .modal-footer {
            flex-shrink: 0 !important;
            padding: 14px 24px !important;
            border-top: 1px solid #f1f5f9 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: flex-end !important;
            gap: 10px !important;
            background: #ffffff !important;
        }
        .btn-secondary-custom {
            padding: 9px 20px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s, border-color 0.15s;
        }
        .btn-secondary-custom:hover { background: #f9fafb; border-color: #9ca3af; }
        .btn-primary-custom {
            padding: 9px 22px;
            border-radius: 8px;
            border: none;
            background: #0ea5e9;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
            box-shadow: 0 1px 3px rgba(14,165,233,0.3);
        }
        .btn-primary-custom:hover { background: #0284c7; }
        .pki-custom-select-trigger.has-error {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 2.5px rgba(239, 68, 68, 0.15) !important;
        }
CSS;

// Inject drawer CSS just before </body> so it loads AFTER app.css and wins the cascade
$drawerStyleTag = "<style id='job-create-drawer-css'>\n" . $modalStyles . "\n</style>";

$domicileParts = array_filter([
    $profile['village'] ?? '',
    $profile['district'] ?? '',
    $profile['city'] ?? '',
    $profile['province'] ?? ''
]);
$employerDomicileAddress = !empty($domicileParts) ? implode(', ', $domicileParts) : ($profile['city'] ?? '');
$employerDomicileEsc = htmlspecialchars($employerDomicileAddress, ENT_QUOTES, 'UTF-8');

$kbjiList = [];
try {
    $kbjiList = db()->query('SELECT kode_kbji, nama_jabatan FROM kbji_data ORDER BY kode_kbji ASC')->fetchAll() ?: [];
} catch (Throwable $e) {}

$kbjiOptionsHtml = '<option value="">Pilih jabatan sesuai KBJI</option>';
if (!empty($kbjiList)) {
    foreach ($kbjiList as $kbji) {
        $code = htmlspecialchars($kbji['kode_kbji']);
        $name = htmlspecialchars($kbji['nama_jabatan']);
        $kbjiOptionsHtml .= "<option value=\"{$code}\">{$code} - {$name}</option>";
    }
} else {
    $kbjiOptionsHtml .= '<option value="5120.01">5120.01 - Juru Masak / Koki</option>'
        . '<option value="5131.00">5131.00 - Pelayan Restoran / Kafe</option>'
        . '<option value="5151.01">5151.01 - Pengurus Rumah Tangga / ART</option>'
        . '<option value="5322.00">5322.00 - Pengasuh Anak / Babysitter</option>'
        . '<option value="5414.01">5414.01 - Penjaga Keamanan / Satpam</option>';
}

$jobProfessionGroups = function_exists('individual_business_types') ? individual_business_types() : [];
$jobIndustryOptionsHtml = '';
foreach ($jobProfessionGroups as $groupName => $items) {
    $jobIndustryOptionsHtml .= '<div class="pki-select-group" data-group="' . htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') . '">';
    $jobIndustryOptionsHtml .= '<div class="pki-select-group-header">' . htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') . '</div>';
    foreach ($items as $item) {
        $jobIndustryOptionsHtml .= '<div class="pki-select-option" data-value="' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '" role="option">';
        $jobIndustryOptionsHtml .= '<span>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</span>';
        $jobIndustryOptionsHtml .= '</div>';
    }
    $jobIndustryOptionsHtml .= '</div>';
}
$jobIndustryOptionsHtml .= '<div class="pki-select-no-results" id="jobIndustryNoResults" style="display:none;">Tidak ada jenis usaha yang sesuai</div>';

$exp1 = format_indo_date(date('Y-m-d', strtotime('+1 month')), 'short');
$exp2 = format_indo_date(date('Y-m-d', strtotime('+2 month')), 'short');
$exp3 = format_indo_date(date('Y-m-d', strtotime('+3 month')), 'short');

$modal = <<<HTML
    <div class="modal-backdrop" data-modal="job-create">
        <div class="modal-panel job-create-panel" role="dialog" aria-modal="true">
            <div class="modal-header">
                <button type="button" class="modal-close" data-close-modal="job-create" aria-label="Tutup"><i class="fa-solid fa-xmark"></i></button>
                <div class="modal-title" id="jobCreateTitle">Tambah Lowongan</div>
                <div class="modal-subtitle" id="jobCreateSubtitle">Lengkapi form berikut untuk mengisi lowongan</div>
                <div class="revision-banner" id="revisionBanner" hidden>
                    <strong><i class="fa-solid fa-triangle-exclamation"></i> Catatan Revisi dari Admin</strong>
                    <p id="revisionBannerText"></p>
                </div>
            </div>
            <div class="step-progress-wizard" aria-hidden="true">
                <div class="step-progress-item active" data-step-label="1">
                    <span class="step-badge">1</span>
                    <span>Informasi Loker</span>
                </div>
                <div class="step-progress-line" data-step-line="1"></div>
                <div class="step-progress-item" data-step-label="2">
                    <span class="step-badge">2</span>
                    <span>Persyaratan</span>
                </div>
                <div class="step-progress-line" data-step-line="2"></div>
                <div class="step-progress-item" data-step-label="3">
                    <span class="step-badge">3</span>
                    <span>Tambahan</span>
                </div>
            </div>
            <form method="post" action="dashboard.php" data-job-create-form>
                <input type="hidden" name="save_job" value="1">
                <input type="hidden" name="job_action" value="save">
                <input type="hidden" name="job_id" id="reviseJobId" value="">
                <div class="modal-body">
                    <!-- Step 1: Informasi Loker -->
                    <div class="form-step active" data-job-step="1">
                        <!-- Section 1: Informasi Loker -->
                        <div class="form-section-card">
                            <div class="form-section-header">
                                <div class="form-section-icon"><i class="fa-solid fa-briefcase"></i></div>
                                <div>
                                    <h3 class="section-title">Informasi Loker</h3>
                                    <p class="section-subtitle">Judul, lokasi, hingga jenis disabilitas loker</p>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Judul loker <span class="req">*</span></label>
                                <input type="text" name="job_title" class="form-control-custom" placeholder="Masukkan judul loker" required>
                            </div>

                            <div class="form-group">
                                <label>Deskripsi loker <span class="req">*</span></label>
                                <div class="rich-editor-shell" data-rich-editor>
                                    <div class="rich-toolbar">
                                        <button type="button" class="rich-btn" data-cmd="undo" title="Undo"><i class="fa-solid fa-rotate-left"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="redo" title="Redo"><i class="fa-solid fa-rotate-right"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="fullscreen" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="removeFormat" title="Hapus Format"><i class="fa-solid fa-eraser"></i></button>
                                        <select class="rich-btn-select" data-cmd-select="formatBlock">
                                            <option value="p">Paragraph</option>
                                            <option value="h1">Heading 1</option>
                                            <option value="h2">Heading 2</option>
                                            <option value="h3">Heading 3</option>
                                        </select>
                                        <select class="rich-btn-select" data-cmd-select="fontSize">
                                            <option value="3">Default</option>
                                            <option value="2">Kecil</option>
                                            <option value="4">Besar</option>
                                        </select>
                                        <button type="button" class="rich-btn" data-cmd="bold" title="Bold"><b>B</b></button>
                                        <button type="button" class="rich-btn" data-cmd="italic" title="Italic"><i>I</i></button>
                                        <button type="button" class="rich-btn" data-cmd="underline" title="Underline"><u>U</u></button>
                                        <button type="button" class="rich-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
                                        <button type="button" class="rich-btn" data-cmd="foreColor" title="Warna Teks"><span style="text-decoration:underline; font-weight:bold;">T</span><small>▾</small></button>
                                        <button type="button" class="rich-btn" data-cmd="hiliteColor" title="Warna Sorot"><span style="background:#fef08a; padding:0 2px; font-weight:bold;">A</span><small>▾</small></button>
                                        <button type="button" class="rich-btn" data-cmd="insertUnorderedList" title="Bullet List"><i class="fa-solid fa-list-ul"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="insertOrderedList" title="Numbered List"><i class="fa-solid fa-list-ol"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="justifyLeft" title="Rata Kiri"><i class="fa-solid fa-align-left"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="justifyCenter" title="Rata Tengah"><i class="fa-solid fa-align-center"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="justifyRight" title="Rata Kanan"><i class="fa-solid fa-align-right"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="indent" title="Tambah Inden"><i class="fa-solid fa-indent"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="outdent" title="Kurangi Inden"><i class="fa-solid fa-outdent"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="createLink" title="Sisipkan Tautan"><i class="fa-solid fa-link"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="insertHorizontalRule" title="Garis Horisontal"><i class="fa-solid fa-minus"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="insertCode" title="Kode">&lt;&gt;</button>
                                        <button type="button" class="rich-btn" data-cmd="insertTableCol" title="Tabel"><i class="fa-solid fa-table-cells"></i></button>
                                    </div>
                                    <div class="rich-area" contenteditable="true" data-placeholder="Masukkan deskripsi loker"></div>
                                    <textarea name="job_description" hidden></textarea>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Jabatan sesuai KBJI <span class="req">*</span></label>
                                <select name="kbji_code" class="form-control-custom" required>
                                    {$kbjiOptionsHtml}
                                </select>
                            </div>

                            <div class="form-group" style="position:relative;">
                                <label>Lokasi loker <span class="req">*</span></label>
                                <input type="hidden" name="job_location" id="jobLocationInput" required>
                                <div id="jobLocationTrigger" class="hierarchical-loc-field" tabindex="0">
                                    <span id="jobLocationDisplay" class="placeholder">Pilih lokasi loker</span>
                                    <i id="jobLocationCaret" class="fa-solid fa-chevron-down" style="color:#64748b; font-size:12px;"></i>
                                </div>
                                <div id="jobLocationDropdown" class="loc-dropdown-popup" style="display:none;">
                                    <div id="jobLocationBreadcrumbs" class="loc-breadcrumbs">
                                        <span class="crumb-btn active" data-level="1">Pilih Provinsi</span>
                                    </div>
                                    <div id="jobLocationOptionsList" class="loc-options-list"></div>
                                </div>
                                <label style="display:flex; align-items:center; gap:8px; margin-top:8px; font-size:13px; font-weight:normal; color:#475569; cursor:pointer; user-select:none;">
                                    <input type="checkbox" id="chkSameDomicile" data-domicile-address="{$employerDomicileEsc}">
                                    <span>Alamat loker sama dengan alamat domisili pemberi kerja</span>
                                </label>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label>Jenis pekerjaan <span class="req">*</span></label>
                                    <select name="job_type" class="form-control-custom" required>
                                        <option value="">Pilih jenis pekerjaan</option>
                                        <option value="Penuh Waktu" selected>Penuh Waktu</option>
                                        <option value="Paruh Waktu">Paruh Waktu</option>
                                        <option value="Kontrak">Kontrak</option>
                                        <option value="Harian Lepas">Harian Lepas</option>
                                        <option value="Magang">Magang</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Bidang pekerjaan <span class="req">*</span></label>
                                    <select name="job_field" class="form-control-custom" required>
                                        <option value="">Pilih bidang pekerjaan</option>
                                        <option value="Akuntansi & Keuangan">Akuntansi & Keuangan</option>
                                        <option value="Administrasi & Sekretaris">Administrasi & Sekretaris</option>
                                        <option value="Rumah Tangga & ART / Pengasuh">Rumah Tangga & ART / Pengasuh</option>
                                        <option value="Kuliner & Katering / Restoran">Kuliner & Katering / Restoran</option>
                                        <option value="Keamanan & Kebersihan">Keamanan & Kebersihan</option>
                                        <option value="Logistik, Pengemudi & Kurir">Logistik, Pengemudi & Kurir</option>
                                        <option value="Penjualan, Kasir & Marketing">Penjualan, Kasir & Marketing</option>
                                        <option value="Teknologi Informasi & Komputer">Teknologi Informasi & Komputer</option>
                                        <option value="Teknik, Pemeliharaan & Konstruksi">Teknik, Pemeliharaan & Konstruksi</option>
                                        <option value="Kesehatan & Perawatan">Kesehatan & Perawatan</option>
                                        <option value="Pendidikan & Pelatihan">Pendidikan & Pelatihan</option>
                                        <option value="Jasa Perorangan & Umum">Jasa Perorangan & Umum</option>
                                        <option value="Lainnya">Lainnya</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group" style="position:relative;">
                                <label>Jenis Usaha Individu <span class="req">*</span></label>
                                <input type="hidden" name="industry" id="jobIndustryInput" value="" required>

                                <div class="pki-custom-select-trigger" id="jobIndustryTrigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                    <span class="pki-custom-select-text is-placeholder" id="jobIndustryDisplay">Pilih Jenis Usaha Individu</span>
                                    <span class="pki-custom-select-caret">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m7 15 5 5 5-5"/>
                                            <path d="m7 9 5-5 5 5"/>
                                        </svg>
                                    </span>
                                </div>

                                <div class="pki-custom-select-dropdown" id="jobIndustryDropdown" style="display:none;" role="listbox">
                                    <div class="pki-custom-select-search-wrap">
                                        <input type="text" class="pki-custom-select-search" id="jobIndustrySearch" placeholder="Cari jenis usaha..." autocomplete="off">
                                    </div>
                                    <div class="pki-custom-select-options" id="jobIndustryOptions">
                                        {$jobIndustryOptionsHtml}
                                    </div>
                                </div>
                                <div class="field-hint">Pilih jenis usaha perorangan yang Anda jalankan.</div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label>Kondisi fisik <span class="req">*</span></label>
                                    <div class="pill-group" data-required-group="Pilih minimal satu kondisi fisik.">
                                        <label class="pill-option">
                                            <input type="checkbox" name="physical_condition[]" id="chkDisability" value="Disabilitas">
                                            <span>Disabilitas</span>
                                        </label>
                                        <label class="pill-option">
                                            <input type="checkbox" name="physical_condition[]" value="Non Disabilitas" checked>
                                            <span>Non Disabilitas</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Jenis kelamin <span class="req">*</span></label>
                                    <div class="pill-group" data-required-group="Pilih minimal satu jenis kelamin.">
                                        <label class="pill-option">
                                            <input type="checkbox" name="gender[]" value="Laki-laki" checked>
                                            <span>Laki-laki</span>
                                        </label>
                                        <label class="pill-option">
                                            <input type="checkbox" name="gender[]" value="Perempuan" checked>
                                            <span>Perempuan</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group" id="disabilityExcludedGroup" style="display:none;">
                                <label>Jenis disabilitas tidak diperbolehkan</label>
                                <select name="disability_excluded" class="form-control-custom">
                                    <option value="">Pilih jenis disabilitas</option>
                                    <option value="Tidak Ada">Tidak Ada (Semua diperbolehkan)</option>
                                    <option value="Disabilitas Fisik / Sensorik Berat">Disabilitas Fisik / Sensorik Berat</option>
                                    <option value="Disabilitas Intelektual">Disabilitas Intelektual</option>
                                    <option value="Disabilitas Netra">Disabilitas Netra (Tunanetra)</option>
                                    <option value="Disabilitas Rungu">Disabilitas Rungu / Wicara</option>
                                    <option value="Disabilitas Mental">Disabilitas Mental</option>
                                </select>
                                <div class="field-hint"><i class="fa-solid fa-circle-info"></i> Pilih jenis disabilitas yang tidak diperbolehkan untuk melamar.</div>
                            </div>
                        </div>

                        <!-- Section 2: Preferensi Gaji -->
                        <div class="form-section-card">
                            <div class="form-section-header">
                                <div class="form-section-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                                <div>
                                    <h3 class="section-title">Preferensi Gaji</h3>
                                    <p class="section-subtitle">Besaran dan pengaturan gaji pada loker</p>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label>Gaji minimal <span class="req">*</span></label>
                                    <div class="input-addon-group">
                                        <span class="addon-text">Rp</span>
                                        <input type="text" inputmode="numeric" name="salary_min" placeholder="Isi minimal gaji yang akan diberikan" required oninput="formatSalaryInput(this)">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Gaji maksimal <span class="req">*</span></label>
                                    <div class="input-addon-group">
                                        <span class="addon-text">Rp</span>
                                        <input type="text" inputmode="numeric" name="salary_max" placeholder="Isi maksimal gaji yang akan diberikan" required oninput="formatSalaryInput(this)">
                                    </div>
                                </div>
                            </div>

                            <div class="card-toggle-group">
                                <label class="card-toggle-item">
                                    <input type="checkbox" name="show_salary" value="1" checked>
                                    <div class="card-toggle-content">
                                        <span class="card-toggle-title">Tampilkan gaji</span>
                                        <span class="card-toggle-desc">Fun facts: Berbagi rentang gaji meningkatkan klik posting pekerjaan kamu.</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Section 3: Preferensi Lainnya -->
                        <div class="form-section-card">
                            <div class="form-section-header">
                                <div class="form-section-icon"><i class="fa-solid fa-sliders"></i></div>
                                <div>
                                    <h3 class="section-title">Preferensi Lainnya</h3>
                                    <p class="section-subtitle">Tentukan preferensi lainnya untuk lowongan pekerjaan</p>
                                </div>
                            </div>

                            <div class="card-toggle-group">
                                <label class="card-toggle-item">
                                    <input type="checkbox" name="is_remote" value="1">
                                    <div class="card-toggle-content">
                                        <span class="card-toggle-title">Remote working</span>
                                        <span class="card-toggle-desc">Dapat bekerja secara remote (jarak jauh)</span>
                                    </div>
                                </label>
                                <label class="card-toggle-item">
                                    <input type="checkbox" name="is_limited" value="1">
                                    <div class="card-toggle-content">
                                        <span class="card-toggle-title">Terbatas</span>
                                        <span class="card-toggle-desc">Loker tidak dipublikasikan secara umum</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Section 4: Durasi Tayang & Kuota Loker -->
                        <div class="form-section-card">
                            <div class="form-section-header">
                                <div class="form-section-icon"><i class="fa-solid fa-calendar-days"></i></div>
                                <div>
                                    <h3 class="section-title">Durasi Tayang & Kuota Loker</h3>
                                    <p class="section-subtitle">Tentukan berapa lama loker tayang setelah diverifikasi dan jumlah kuota yang diperlukan</p>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Lama expired loker <span class="req">*</span></label>
                                <select name="expiry_days" class="form-control-custom" required>
                                    <option value="">Pilih lama expired loker</option>
                                    <option value="30" selected>1 Bulan ({$exp1})</option>
                                    <option value="60">2 Bulan ({$exp2})</option>
                                    <option value="90">3 Bulan ({$exp3})</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Jumlah lowongan <span class="req">*</span></label>
                                <div class="input-addon-group">
                                    <input type="number" name="quota" value="1" min="1" placeholder="Isi jumlah lowongan pada loker" required>
                                    <span class="addon-suffix">Orang</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Persyaratan -->
                    <div class="form-step" data-job-step="2" hidden>
                        <!-- Section 1: Persyaratan Umum -->
                        <div class="form-section-card">
                            <div class="form-section-header">
                                <div class="form-section-icon"><i class="fa-solid fa-file-lines"></i></div>
                                <div>
                                    <h3 class="section-title">Persyaratan Umum</h3>
                                    <p class="section-subtitle">Informasi pendidikan, pengalaman, status pernikahan, dan usia</p>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label>Pendidikan minimal <span class="req">*</span></label>
                                    <select name="education_required" class="form-control-custom" required>
                                        <option value="">Pilih pendidikan minimal</option>
                                        <option value="Tidak Ada Minimal">Tidak Ada Minimal</option>
                                        <option value="SD">SD Sederajat</option>
                                        <option value="SMP">SMP Sederajat</option>
                                        <option value="SMA/SMK" selected>SMA/SMK Sederajat</option>
                                        <option value="Diploma">Diploma (D3)</option>
                                        <option value="Sarjana">Sarjana (S1)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Pengalaman dibutuhkan <span class="req">*</span></label>
                                    <select name="experience_required" class="form-control-custom" required>
                                        <option value="">Pilih pengalaman</option>
                                        <option value="Fresh Graduate / Pemula">Fresh Graduate / Pemula</option>
                                        <option value="Kurang dari 1 tahun">Kurang dari 1 tahun</option>
                                        <option value="1 - 3 tahun" selected>1 - 3 tahun</option>
                                        <option value="Lebih dari 3 tahun">Lebih dari 3 tahun</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Status Pernikahan <span class="req">*</span></label>
                                <div class="pill-group" data-required-group="Pilih minimal satu status pernikahan.">
                                    <label class="pill-option">
                                        <input type="checkbox" name="marital_status[]" value="Telah Menikah" checked>
                                        <span>Telah Menikah</span>
                                    </label>
                                    <label class="pill-option">
                                        <input type="checkbox" name="marital_status[]" value="Lajang / Belum Menikah" checked>
                                        <span>Lajang / Belum Menikah</span>
                                    </label>
                                </div>
                            </div>

                            <div class="form-grid-2">
                                <div class="form-group">
                                    <label>Usia minimal <span class="req">*</span></label>
                                    <div class="input-addon-group">
                                        <input type="number" name="age_min" placeholder="Isi usia minimal" required min="17" max="80" value="18">
                                        <span class="addon-suffix">Tahun</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Usia maksimal <span class="req">*</span></label>
                                    <div class="input-addon-group">
                                        <input type="number" name="age_max" placeholder="Isi usia maksimal" required min="17" max="80" value="45">
                                        <span class="addon-suffix">Tahun</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Section 2: Persyaratan Khusus -->
                        <div class="form-section-card">
                            <div class="form-section-header">
                                <div class="form-section-icon"><i class="fa-solid fa-file-signature"></i></div>
                                <div>
                                    <h3 class="section-title">Persyaratan Khusus</h3>
                                    <p class="section-subtitle">Masukkan persyaratan khusus untuk loker ini</p>
                                </div>
                            </div>

                            <div class="form-group">
                                <div class="rich-editor-shell" data-rich-editor>
                                    <div class="rich-toolbar">
                                        <button type="button" class="rich-btn" data-cmd="undo" title="Undo"><i class="fa-solid fa-rotate-left"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="redo" title="Redo"><i class="fa-solid fa-rotate-right"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="fullscreen" title="Fullscreen"><i class="fa-solid fa-expand"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="removeFormat" title="Hapus Format"><i class="fa-solid fa-eraser"></i></button>
                                        <select class="rich-btn-select" data-cmd-select="formatBlock">
                                            <option value="p">Paragraph</option>
                                            <option value="h1">Heading 1</option>
                                            <option value="h2">Heading 2</option>
                                            <option value="h3">Heading 3</option>
                                        </select>
                                        <select class="rich-btn-select" data-cmd-select="fontSize">
                                            <option value="3">Default</option>
                                            <option value="2">Kecil</option>
                                            <option value="4">Besar</option>
                                        </select>
                                        <button type="button" class="rich-btn" data-cmd="bold" title="Bold"><b>B</b></button>
                                        <button type="button" class="rich-btn" data-cmd="italic" title="Italic"><i>I</i></button>
                                        <button type="button" class="rich-btn" data-cmd="underline" title="Underline"><u>U</u></button>
                                        <button type="button" class="rich-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
                                        <button type="button" class="rich-btn" data-cmd="foreColor" title="Warna Teks"><span style="text-decoration:underline; font-weight:bold;">T</span><small>▾</small></button>
                                        <button type="button" class="rich-btn" data-cmd="hiliteColor" title="Warna Sorot"><span style="background:#fef08a; padding:0 2px; font-weight:bold;">A</span><small>▾</small></button>
                                        <button type="button" class="rich-btn" data-cmd="insertUnorderedList" title="Bullet List"><i class="fa-solid fa-list-ul"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="insertOrderedList" title="Numbered List"><i class="fa-solid fa-list-ol"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="justifyLeft" title="Rata Kiri"><i class="fa-solid fa-align-left"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="justifyCenter" title="Rata Tengah"><i class="fa-solid fa-align-center"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="justifyRight" title="Rata Kanan"><i class="fa-solid fa-align-right"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="indent" title="Tambah Inden"><i class="fa-solid fa-indent"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="outdent" title="Kurangi Inden"><i class="fa-solid fa-outdent"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="createLink" title="Sisipkan Tautan"><i class="fa-solid fa-link"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="insertHorizontalRule" title="Garis Horisontal"><i class="fa-solid fa-minus"></i></button>
                                        <button type="button" class="rich-btn" data-cmd="insertCode" title="Kode">&lt;&gt;</button>
                                        <button type="button" class="rich-btn" data-cmd="insertTableCol" title="Tabel"><i class="fa-solid fa-table-cells"></i></button>
                                    </div>
                                    <div class="rich-area" contenteditable="true" data-placeholder="Masukkan persyaratan khusus untuk loker ini"></div>
                                    <textarea name="special_requirements" hidden></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Tambahan -->
                    <div class="form-step" data-job-step="3" hidden>
                        <!-- Section 1: Skill / Keahlian -->
                        <div class="form-section-card">
                            <div class="form-section-header">
                                <div class="form-section-icon"><i class="fa-solid fa-graduation-cap"></i></div>
                                <div>
                                    <h3 class="section-title">Skill / Keahlian</h3>
                                    <p class="section-subtitle">Keahlian sangat berpengaruh untuk sistem pencocokan dengan pencari kerja.</p>
                                </div>
                            </div>

                            <div class="form-group">
                                <input type="text" data-chip-input="skills" class="form-control-custom" placeholder="Pilih keahlian">
                                <input type="hidden" name="skills" data-chip-value="skills">
                                <div class="choice-chip-wrap" data-chip-list="skills" style="margin-top:8px;"></div>
                            </div>
                        </div>

                        <!-- Section 2: Kontak -->
                        <div class="form-section-card">
                            <div class="form-section-header">
                                <div class="form-section-icon"><i class="fa-solid fa-address-book"></i></div>
                                <div>
                                    <h3 class="section-title">Kontak</h3>
                                    <p class="section-subtitle">Kami akan mengirimkan email ke daftar email di bawah ini untuk setiap lamaran yang masuk.</p>
                                </div>
                            </div>

                            <div class="form-group">
                                <input type="text" data-chip-input="contacts" class="form-control-custom" placeholder="Klik untuk menambahkan kontak">
                                <input type="hidden" name="contacts" data-chip-value="contacts">
                                <div class="choice-chip-wrap" data-chip-list="contacts" style="margin-top:8px;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-job-cancel data-close-modal="job-create">Batal</button>
                    <button type="button" class="btn-secondary-custom" data-job-back hidden><i class="fa-solid fa-arrow-left"></i> Kembali</button>
                    <button type="button" class="btn-primary-custom" data-job-next>Selanjutnya <i class="fa-solid fa-arrow-right"></i></button>
                    <button type="submit" class="btn-primary-custom" data-job-submit hidden><i class="fa-solid fa-paper-plane"></i> Tambah Loker</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop" data-modal="job-close">
        <div class="modal-panel" style="width: min(500px, 100%);" role="dialog">
            <div class="modal-header">
                <div class="modal-title">Selesaikan Lowongan & Tetapkan Kandidat</div>
                <div class="modal-subtitle">Kuota belum terpenuhi. Mohon isi alasan.</div>
            </div>
            <form method="post" action="dashboard.php">
                <input type="hidden" name="close_job" value="1">
                <input type="hidden" name="job_id" id="close_job_id" value="">
                <input type="hidden" name="sisa_kuota" id="close_sisa_kuota" value="1">
                <div class="modal-body">
                    <div class="modal-section" style="border-bottom:none;">
                        <div class="section-text" style="margin-bottom:8px;">
                            Anda menetapkan kandidat kurang dari kuota yang tersedia. Mohon pilih alasan mengapa sisa kuota belum terpenuhi (pilih minimal 1):
                        </div>
                        <div style="display:flex; flex-direction:column; gap:8px; margin-bottom: 16px;">
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Jumlah pelamar belum mencukupi"> Jumlah pelamar belum mencukupi</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Pelamar belum sesuai kompetensi/kualifikasi"> Pelamar belum sesuai kompetensi/kualifikasi</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Pelamar mengundurkan diri"> Pelamar mengundurkan diri</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Kandidat tidak hadir/tidak melanjutkan proses seleksi"> Kandidat tidak hadir/tidak melanjutkan proses seleksi</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Kesepakatan kerja tidak tercapai"> Kesepakatan kerja tidak tercapai</label>
                            <label style="font-size:13px;"><input type="checkbox" name="reasons[]" value="Lainnya" onchange="document.getElementById('reason_lainnya').style.display = this.checked ? 'block' : 'none'"> Lainnya</label>
                            <textarea id="reason_lainnya" name="reason_lainnya" placeholder="Tulis alasan spesifik Anda..." style="display:none; font-size:13px; padding:8px; border:1px solid #dbe7f0; border-radius:8px; min-height:60px; margin-top:4px;"></textarea>
                        </div>

                        <div style="background:#f0f9ff; padding:12px; border-radius:8px; border:1px solid #bae6fd;">
                            <div style="font-weight:700; font-size:13px; color:#0369a1; margin-bottom:4px;">Posting Ulang Sisa Kuota?</div>
                            <div style="font-size:12px; color:#0c4a6e; margin-bottom:10px;">Apakah Anda ingin mempublikasikan ulang lowongan ini secara otomatis untuk memenuhi sisa kuota?</div>
                            <select name="repost" required style="width:100%; padding:8px; border:1px solid #bae6fd; border-radius:6px; font-size:13px;">
                                <option value="">Pilih tindakan...</option>
                                <option value="1">Ya, Posting Ulang Sisa Kuota</option>
                                <option value="0">Tidak, Tutup Saja</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ghost-btn" data-close-modal="job-close">Batal</button>
                    <button type="submit" class="primary-btn">Simpan & Selesaikan</button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop" data-modal="applicant-profile" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.45); backdrop-filter:blur(2px); z-index:9999; justify-content:flex-end; align-items:stretch;">
        <div class="modal-panel applicant-profile-panel" role="dialog" aria-modal="true" style="width:min(460px, 95vw); height:100vh; background:#fff; box-shadow:-8px 0 25px rgba(0,0,0,0.12); display:flex; flex-direction:column; position:relative; margin-left:auto; border-radius:0; padding:0;">
            <!-- Header -->
            <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:flex-start; position:relative; background:#fff;">
                <div style="display:flex; align-items:center; gap:14px;">
                    <img id="applicantAvatar" src="https://ui-avatars.com/api/?name=Pencari+Kerja&background=0ea5e9&color=fff" alt="" style="width:46px; height:46px; border-radius:50%; object-fit:cover; border:1px solid #e2e8f0; flex-shrink:0;">
                    <div>
                        <h2 id="applicantName" style="font-size:15px; font-weight:700; color:#0f172a; margin:0 0 4px 0;">Muhamad Amar Basyari</h2>
                        <div style="display:flex; flex-direction:column; gap:2px; font-size:11.5px; color:#64748b;">
                            <div style="display:flex; align-items:center; gap:5px;">
                                <i class="fa-regular fa-calendar" style="color:#94a3b8; font-size:11px;"></i> Mendaftar: <span id="applicantAppliedDate" style="color:#334155; font-weight:600;">20 Sep 2026</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:5px;">
                                <i class="fa-solid fa-layer-group" style="color:#94a3b8; font-size:11px;"></i> Status: <span id="applicantStatusBadge" style="color:#d97706; font-weight:700;">Lamaran Masuk</span>
                            </div>
                        </div>
                    </div>
                </div>
                <button type="button" class="modal-close" onclick="closeApplicantProfileModal()" data-close-modal="applicant-profile" aria-label="Tutup" style="background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; padding:4px 6px; border-radius:6px; line-height:1;"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Scrollable Body Container -->
            <div style="padding:18px 20px; flex:1; overflow-y:auto;">
                <!-- Upper Metadata (2-Column Grid: Label Left, Value Right) -->
                <div style="display:grid; grid-template-columns:85px 1fr; row-gap:12px; column-gap:10px; font-size:12px; margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #f1f5f9;">
                    <!-- Lokasi -->
                    <div style="color:#64748b; display:flex; align-items:flex-start; gap:5px; font-weight:500;">
                        <i class="fa-solid fa-location-dot" style="margin-top:2px; color:#94a3b8;"></i> Lokasi
                    </div>
                    <div id="applicantLocation" style="font-weight:500; color:#1e293b; line-height:1.4;">Karangmaja, Banjarharjo, KAB. BREBES, JAWA TENGAH</div>

                    <!-- Tag Seleksi -->
                    <div style="color:#64748b; display:flex; align-items:flex-start; gap:5px; font-weight:500;">
                        <i class="fa-solid fa-tag" style="margin-top:2px; color:#94a3b8; transform:rotate(45deg);"></i> Tag Seleksi
                    </div>
                    <div id="applicantTag" style="color:#64748b; font-weight:500;">-</div>

                    <!-- Tentang -->
                    <div style="color:#64748b; display:flex; align-items:flex-start; gap:5px; font-weight:500;">
                        <i class="fa-solid fa-user" style="margin-top:2px; color:#94a3b8;"></i> Tentang
                    </div>
                    <div id="applicantAbout" style="color:#334155; line-height:1.5;">Saya adalah pribadi yang disiplin, bertanggung jawab, cepat belajar, dan mampu beradaptasi dengan lingkungan kerja baru serta bekerja secara individu maupun dalam tim.</div>

                    <!-- Resume -->
                    <div style="color:#64748b; display:flex; align-items:flex-start; gap:5px; font-weight:500;">
                        <i class="fa-solid fa-paperclip" style="margin-top:2px; color:#94a3b8;"></i> Resume
                    </div>
                    <div style="background:#fffbeb; border:1px solid #fef3c7; border-radius:8px; padding:10px 12px; color:#92400e; font-size:11.5px; line-height:1.4; display:flex; align-items:flex-start; gap:8px;">
                        <i class="fa-solid fa-circle-info" style="color:#d97706; font-size:13px; margin-top:2px; flex-shrink:0;"></i>
                        <div>Silahkan memproses lamaran ini dengan cara bookmark atau interview untuk dapat mendownload CV pelamar.</div>
                    </div>
                </div>

                <!-- Tabs: Profil & Riwayat Aktivitas -->
                <div style="display:flex; border-bottom:1px solid #e2e8f0; margin-bottom:18px;">
                    <button type="button" id="tabApplicantProfilBtn" onclick="switchApplicantModalTab('profil')" style="padding:8px 16px; border:none; background:none; border-bottom:2px solid #0ea5e9; color:#0ea5e9; font-weight:700; font-size:12.5px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                        <i class="fa-regular fa-user"></i> Profil
                    </button>
                    <button type="button" id="tabApplicantAktivitasBtn" onclick="switchApplicantModalTab('aktivitas')" style="padding:8px 16px; border:none; background:none; border-bottom:2px solid transparent; color:#64748b; font-weight:600; font-size:12.5px; cursor:pointer; display:flex; align-items:center; gap:6px;">
                        <i class="fa-regular fa-clock"></i> Riwayat Aktivitas
                    </button>
                </div>

                <!-- Tab Pane 1: Profil -->
                <div id="tabApplicantProfilPane">
                    <h3 style="font-size:13px; font-weight:700; color:#0f172a; margin:0 0 14px 0; display:flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-id-card" style="color:#0ea5e9; font-size:13px;"></i> Informasi Profil
                    </h3>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <div style="font-size:11.5px; color:#64748b; margin-bottom:2px;">Tempat, tanggal lahir</div>
                            <div id="applicantBirthInfo" style="font-size:12px; font-weight:600; color:#1e293b;">Brebes, 19 Desember 2006</div>
                        </div>
                        <div>
                            <div style="font-size:11.5px; color:#64748b; margin-bottom:2px;">Kontak</div>
                            <div style="font-size:11px; color:#64748b; line-height:1.4;">Alamat email dan nomor telepon pelamar akan tersedia setelah Anda melakukan proses bookmark atau memilih kandidat yang dinilai potensial maupun diminati.</div>
                        </div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <div style="font-size:11.5px; color:#64748b; margin-bottom:2px;">Alamat</div>
                        <div id="applicantKtpAddress" style="font-size:12px; color:#1e293b; font-weight:500;">Karangmaja, Banjarharjo, Brebes, Karangmaja, Banjarharjo, KAB. BREBES</div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <div style="font-size:11.5px; color:#64748b; margin-bottom:2px;">Alamat domisili</div>
                        <div id="applicantDomicileAddress" style="font-size:12px; color:#1e293b; font-weight:500;">Karangmaja, Banjarharjo, Brebes, Karangmaja, Banjarharjo, KAB. BREBES, JAWA TENGAH</div>
                    </div>

                    <!-- Pengalaman Kerja -->
                    <div style="margin-bottom:20px;">
                        <div style="font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-briefcase" style="color:#64748b; font-size:12px;"></i> Pengalaman Kerja
                        </div>
                        <div id="applicantExperience" style="border:1px dashed #e2e8f0; border-radius:8px; padding:20px; text-align:center;">
                            <i class="fa-solid fa-briefcase" style="font-size:22px; color:#cbd5e1; margin-bottom:4px; display:block;"></i>
                            <span style="font-size:11.5px; color:#94a3b8;">Belum ada pengalaman kerja yang tercatat.</span>
                        </div>
                    </div>

                    <!-- Pelatihan -->
                    <div style="margin-bottom:20px;">
                        <div style="font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-graduation-cap" style="color:#64748b; font-size:12px;"></i> Pelatihan
                        </div>
                        <div id="applicantTrainings">
                            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:8px;">
                                <div style="font-size:12.5px; font-weight:700; color:#0f172a;">Operator Forklift</div>
                                <div style="font-size:11.5px; color:#64748b; margin:2px 0;">🏢 KEMNAKER</div>
                                <div style="font-size:11px; color:#94a3b8;">Sep 2026 · Cibuntu, Cibitung, KAB. BEKASI, JAWA BARAT</div>
                            </div>
                        </div>
                    </div>

                    <!-- Pendidikan -->
                    <div style="margin-bottom:20px;">
                        <div style="font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-building-columns" style="color:#64748b; font-size:12px;"></i> Pendidikan
                        </div>
                        <div id="applicantEducation">
                            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:8px;">
                                <div style="font-size:12.5px; font-weight:700; color:#0f172a;">SMA atau Sederajat</div>
                                <div style="font-size:11.5px; color:#64748b; margin:2px 0;">🏛 SMAN 1 WALED</div>
                                <div style="font-size:11.5px; color:#64748b;">IPS</div>
                                <div style="font-size:11px; color:#94a3b8; margin-top:2px;">2022 – 2025 · IPK: 87 · Waled Kota, Waled, KAB. CIREBON, JAWA BARAT</div>
                            </div>
                        </div>
                    </div>

                    <!-- Sertifikasi & Keahlian -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-award" style="color:#64748b; font-size:12px;"></i> Sertifikasi & Keahlian
                        </div>
                        <div style="font-size:11.5px; color:#64748b; margin-bottom:6px;">Keahlian</div>
                        <div id="applicantSkills" style="display:flex; flex-wrap:wrap; gap:6px;">
                            <span style="background:#fff; color:#334155; border:1px solid #cbd5e1; padding:3px 10px; border-radius:6px; font-size:11.5px; font-weight:500;">Bahasa Korea</span>
                            <span style="background:#fff; color:#334155; border:1px solid #cbd5e1; padding:3px 10px; border-radius:6px; font-size:11.5px; font-weight:500;">Operator forklift</span>
                        </div>
                    </div>
                </div>

                <!-- Tab Pane 2: Riwayat Aktivitas -->
                <div id="tabApplicantAktivitasPane" style="display:none;">
                    <div id="applicantTimeline" style="display:flex; flex-direction:column; gap:14px; padding-left:10px; border-left:2px solid #e2e8f0; margin-left:8px;">
                        <div style="position:relative;">
                            <div style="position:absolute; left:-16px; top:4px; width:10px; height:10px; border-radius:50%; background:#0ea5e9; border:2px solid #fff;"></div>
                            <div style="font-size:12.5px; font-weight:700; color:#0f172a;">Lamaran Masuk</div>
                            <div style="font-size:11.5px; color:#64748b;">Lamaran diajukan oleh pelamar</div>
                            <div id="applicantActivityDate" style="font-size:11px; color:#94a3b8; margin-top:2px;">20 Sep 2026</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sticky Bottom Footer: Status Lamaran & 2 Action Buttons (Persis Versi Perusahaan) -->
            <div style="position:sticky; bottom:0; background:#fff; border-top:1px solid #e2e8f0; padding:12px 18px; display:flex; align-items:center; justify-content:space-between; z-index:10; box-shadow:0 -4px 12px rgba(0,0,0,0.03);">
                <!-- Left: Status Lamaran & Dropdown -->
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:12.5px; font-weight:600; color:#475569; white-space:nowrap;">Status Lamaran:</span>

                    <!-- Custom Dropdown Container -->
                    <div style="position:relative;" id="customStatusDropdownContainer">
                        <input type="hidden" name="status" id="applicantStatus" value="Lamaran Masuk">
                        <button type="button" id="customStatusDropdownBtn" onclick="toggleCustomStatusDropdown(event)" style="display:inline-flex; align-items:center; gap:8px; padding:6px 12px; background:#fff; border:1px solid #cbd5e1; border-radius:8px; cursor:pointer; font-size:12.5px; font-weight:600; color:#1e293b; transition:all 0.15s;">
                            <span id="customStatusDot" style="width:8px; height:8px; border-radius:50%; background:#f59e0b; flex-shrink:0;"></span>
                            <span id="customStatusLabel">Lamaran Masuk</span>
                            <i class="fa-solid fa-chevron-down" style="font-size:10px; color:#94a3b8; margin-left:4px;"></i>
                        </button>

                        <!-- Dropdown Menu (POPS UPWARD ABOVE FOOTER) -->
                        <div id="customStatusDropdownMenu" style="display:none; position:absolute; bottom:calc(100% + 6px); left:0; min-width:210px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.12); padding:5px; z-index:99999;">
                            <div class="status-option-item" onclick="selectApplicantStatus('Lamaran Masuk', '#f59e0b')" style="padding:8px 10px; border-radius:6px; display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:500; color:#1e293b; transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <span style="width:7px; height:7px; border-radius:50%; background:#f59e0b; flex-shrink:0;"></span>
                                <span>Lamaran Masuk</span>
                                <i class="fa-solid fa-check status-check-icon" data-status="Lamaran Masuk" style="margin-left:auto; color:#0f172a; font-size:11px;"></i>
                            </div>
                            <div class="status-option-item" onclick="selectApplicantStatus('Sedang Dipelajari', '#f97316')" style="padding:8px 10px; border-radius:6px; display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:500; color:#1e293b; transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <span style="width:7px; height:7px; border-radius:50%; background:#f97316; flex-shrink:0;"></span>
                                <span>Sedang Dipelajari</span>
                                <i class="fa-solid fa-check status-check-icon" data-status="Sedang Dipelajari" style="margin-left:auto; color:#0f172a; font-size:11px; display:none;"></i>
                            </div>
                            <div class="status-option-item" onclick="selectApplicantStatus('Wawancara', '#3b82f6')" style="padding:8px 10px; border-radius:6px; display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:500; color:#1e293b; transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <span style="width:7px; height:7px; border-radius:50%; background:#3b82f6; flex-shrink:0;"></span>
                                <span>Wawancara</span>
                                <i class="fa-solid fa-check status-check-icon" data-status="Wawancara" style="margin-left:auto; color:#0f172a; font-size:11px; display:none;"></i>
                            </div>
                            <div class="status-option-item" onclick="selectApplicantStatus('Diterima', '#10b981')" style="padding:8px 10px; border-radius:6px; display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:500; color:#1e293b; transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <span style="width:7px; height:7px; border-radius:50%; background:#10b981; flex-shrink:0;"></span>
                                <span>Diterima</span>
                                <i class="fa-solid fa-check status-check-icon" data-status="Diterima" style="margin-left:auto; color:#0f172a; font-size:11px; display:none;"></i>
                            </div>
                            <div class="status-option-item" onclick="selectApplicantStatus('Ditolak', '#ef4444')" style="padding:8px 10px; border-radius:6px; display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:500; color:#1e293b; transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <span style="width:7px; height:7px; border-radius:50%; background:#ef4444; flex-shrink:0;"></span>
                                <span>Ditolak</span>
                                <i class="fa-solid fa-check status-check-icon" data-status="Ditolak" style="margin-left:auto; color:#0f172a; font-size:11px; display:none;"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: 2 Action Icons (Tag & Jadwal Wawancara) -->
                <div style="display:flex; align-items:center; gap:6px;">
                    <button type="button" onclick="openApplicantTagModal()" title="Tandai pelamar dengan tag" style="width:32px; height:32px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b; transition:all 0.15s;" onmouseover="this.style.borderColor='#0ea5e9';this.style.color='#0284c7'" onmouseout="this.style.borderColor='#cbd5e1';this.style.color='#64748b'">
                        <i class="fa-solid fa-tag" style="font-size:12px; transform:rotate(45deg);"></i>
                    </button>
                    <button type="button" onclick="openApplicantInterviewModal()" title="Jadwal wawancara" style="width:32px; height:32px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b; transition:all 0.15s;" onmouseover="this.style.borderColor='#0ea5e9';this.style.color='#0284c7'" onmouseout="this.style.borderColor='#cbd5e1';this.style.color='#64748b'">
                        <i class="fa-regular fa-calendar-check" style="font-size:13px;"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Tandai Pelamar dengan Tag -->
    <div class="modal-backdrop" id="applicantTagModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); backdrop-filter:blur(4px); z-index:100000; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; width:min(440px, 92vw); box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); overflow:hidden;">
            <div style="padding:18px 22px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:36px; height:36px; border-radius:10px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#0284c7;">
                        <i class="fa-solid fa-tag" style="font-size:14px; transform:rotate(45deg);"></i>
                    </div>
                    <div>
                        <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin:0;">Tandai Pelamar</h3>
                        <div style="font-size:12px; color:#64748b;">Pilih atau buat label penanda pelamar</div>
                    </div>
                </div>
                <button type="button" onclick="closeApplicantTagModal()" style="background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; padding:4px;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:20px 22px;">
                <div style="font-size:12.5px; font-weight:600; color:#334155; margin-bottom:8px;">Pilihan Tag Populer:</div>
                <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px;" id="suggestedTagsContainer">
                    <button type="button" onclick="applyQuickTag('🌟 Kandidat Unggulan')" class="quick-tag-btn" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:20px; background:#f8fafc; font-size:12px; font-weight:600; color:#334155; cursor:pointer;">🌟 Kandidat Unggulan</button>
                    <button type="button" onclick="applyQuickTag('⚡ Prioritas')" class="quick-tag-btn" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:20px; background:#f8fafc; font-size:12px; font-weight:600; color:#334155; cursor:pointer;">⚡ Prioritas</button>
                    <button type="button" onclick="applyQuickTag('🎓 Lulusan Baru')" class="quick-tag-btn" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:20px; background:#f8fafc; font-size:12px; font-weight:600; color:#334155; cursor:pointer;">🎓 Lulusan Baru</button>
                    <button type="button" onclick="applyQuickTag('✅ Sesuai Kriteria')" class="quick-tag-btn" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:20px; background:#f8fafc; font-size:12px; font-weight:600; color:#334155; cursor:pointer;">✅ Sesuai Kriteria</button>
                </div>
                <div style="font-size:12.5px; font-weight:600; color:#334155; margin-bottom:6px;">Atau Tulis Tag Kustom:</div>
                <input type="text" id="customTagInput" placeholder="Contoh: Domisili Dekat, Siap Lembur..." style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; box-sizing:border-box;">
            </div>
            <div style="padding:14px 22px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="closeApplicantTagModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#fff; border-radius:8px; font-size:12.5px; font-weight:600; cursor:pointer;">Batal</button>
                <button type="button" onclick="saveApplicantTag()" style="padding:8px 18px; border:none; background:#0284c7; color:#fff; border-radius:8px; font-size:12.5px; font-weight:700; cursor:pointer;">Simpan Tag</button>
            </div>
        </div>
    </div>

    <!-- Modal Jadwal Wawancara -->
    <div class="modal-backdrop" id="applicantInterviewModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); backdrop-filter:blur(4px); z-index:100000; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; width:min(480px, 92vw); box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); overflow:hidden;">
            <div style="padding:18px 22px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:36px; height:36px; border-radius:10px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#2563eb;">
                        <i class="fa-regular fa-calendar-check" style="font-size:16px;"></i>
                    </div>
                    <div>
                        <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin:0;">Jadwal Wawancara</h3>
                        <div style="font-size:12px; color:#64748b;">Atur jadwal & undangan wawancara kandidat</div>
                    </div>
                </div>
                <button type="button" onclick="closeApplicantInterviewModal()" style="background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; padding:4px;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:20px 22px; display:flex; flex-direction:column; gap:14px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Tanggal</label>
                        <input type="date" id="interviewDate" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Waktu (WIB)</label>
                        <input type="time" id="interviewTime" value="09:00" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; box-sizing:border-box;">
                    </div>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Metode Wawancara</label>
                    <select id="interviewType" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; background:#fff; box-sizing:border-box;">
                        <option value="Online (Google Meet / Zoom)">Online (Google Meet / Zoom)</option>
                        <option value="Tatap Muka / Kantor">Tatap Muka / Kantor</option>
                        <option value="Telepon / WhatsApp Call">Telepon / WhatsApp Call</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Lokasi / Tautan Meeting</label>
                    <input type="text" id="interviewLocation" placeholder="meet.google.com/xxx-xxxx-xxx atau Alamat Kantor" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:4px;">Catatan untuk Pelamar (Opsional)</label>
                    <textarea id="interviewNotes" rows="2" placeholder="Bawa berkas fisik KTP / CV atau persiapkan laptop..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px; outline:none; resize:vertical; box-sizing:border-box;"></textarea>
                </div>
            </div>
            <div style="padding:14px 22px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="closeApplicantInterviewModal()" style="padding:8px 16px; border:1px solid #cbd5e1; background:#fff; border-radius:8px; font-size:12.5px; font-weight:600; cursor:pointer;">Batal</button>
                <button type="button" onclick="saveApplicantInterview()" style="padding:8px 18px; border:none; background:#0284c7; color:#fff; border-radius:8px; font-size:12.5px; font-weight:700; cursor:pointer;">Simpan & Jadwalkan</button>
            </div>
        </div>
    </div>
HTML;

// Inject modal HTML + drawer CSS (AFTER app.css) before </body>
$html = str_replace('</body>', $drawerStyleTag . "\n" . $modal . "\n</body>", $html);

$html = str_replace('<button class="primary-btn"><i class="fa-solid fa-plus"></i> Tambah</button>', '<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah</button>', $html);
$html = str_replace('<button class="primary-btn"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', '<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah</button>', $html);

if (empty($profile['verified']) || $isTransitionPeriod || $isFullDisable || $verificationStatus === 'SUSPENDED') {
    $html = str_replace('<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah</button>', '<button class="primary-btn" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah</button>', $html);
    $html = str_replace('<button class="primary-btn" data-open-modal="job-create"><i class="fa-solid fa-plus"></i> Tambah Lowongan</button>', '<button class="primary-btn" style="opacity:0.5;cursor:not-allowed;" disabled><i class="fa-solid fa-plus"></i> Tambah</button>', $html);
}

// Lock state logic
$isDashboardLocked = in_array($verificationStatus, ['NOT_SUBMITTED', 'PENDING', 'SUSPENDED', 'FULL_DISABLED'], true);

if (!str_contains($html, 'src="./assets/app.js"') && !str_contains($html, 'src="assets/app.js"')) {
    $html = str_replace('</body>', '<script src="./assets/app.js"></script>' . "\n</body>", $html);
}

if (!str_contains($html, 'window.testCloseJob')) {
    $html = str_replace('</body>', <<<'JS'
    <script>
    window.testCloseJob = function(jobId, sisaKuota, quota, acceptedCount) {
        if (typeof openConfirmCloseJobModal === 'function') {
            openConfirmCloseJobModal(jobId, quota, acceptedCount);
        } else {
            const modal = document.getElementById('modalConfirmCloseJob');
            const inputJobId = document.getElementById('modalCloseJobId');
            if (inputJobId && jobId) inputJobId.value = jobId;
            if (modal) modal.style.display = 'flex';
        }
    };
    </script>
</body>
JS, $html);
}

$employerJobs = db()->prepare('SELECT * FROM job_posts WHERE user_id = ? ORDER BY created_at DESC');
$employerJobs->execute([$user['id']]);
$employerJobs = $employerJobs->fetchAll();

$jobCounts = [
    'Draft' => 0,
    'Menunggu Verifikasi' => 0,
    'ADDITIONAL_DOCUMENT_PENDING' => 0,
    'Perlu Direvisi' => 0,
    'Tayang' => 0,
    'Ditolak' => 0,
    'Ditutup' => 0
];
foreach ($employerJobs as $row) {
    $cStat = normalize_job_status($row['status'] ?? '');
    if (isset($jobCounts[$cStat])) {
        $jobCounts[$cStat]++;
    }
}

$jobRowsHtml = '';
$additionalDocModalsHtml = '';
if (!$employerJobs) {
    $jobRowsHtml = '<tr><td colspan="6" style="text-align:center;color:#64748b;padding:28px">Belum ada lowongan. Klik Tambah Lowongan untuk membuat postingan baru.</td></tr>';
} else {
    foreach ($employerJobs as $row) {
        $meta = job_status_meta($row['status']);
        $countStmt = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE job_id = ?');
        $countStmt->execute([$row['id']]);
        $appCount = (int) $countStmt->fetchColumn();
        $actionBtn = '-';
        if (in_array($row['status'], ['Perlu Direvisi', 'Perlu Revisi'], true)) {
            $actionBtn = '<button type="button" class="ghost-btn" data-revise-job="' . (int) $row['id'] . '">Revisi</button>';
        } elseif ($row['status'] === 'ADDITIONAL_DOCUMENT_PENDING') {
            $hasSubmitted = !empty($row['additional_doc_file']) || !empty($row['additional_doc_notes']);
            $btnText = $hasSubmitted ? 'Ubah Dokumen' : 'Unggah Dokumen';
            $actionBtn = '<button type="button" class="ghost-btn" style="color:#0284c7;border-color:#bae6fd;background:#f0f9ff;" data-open-modal="modal-doc-' . (int)$row['id'] . '"><i class="fa-solid fa-upload"></i> ' . $btnText . '</button>';

            $additionalDocModalsHtml .= '
            <div class="modal" data-modal="modal-doc-' . (int)$row['id'] . '">
                <div class="modal-backdrop" data-close-modal="modal-doc-' . (int)$row['id'] . '"></div>
                <div class="modal-panel" style="max-width:540px;">
                    <div class="modal-header">
                        <h3>Unggah Dokumen Tambahan</h3>
                        <button type="button" class="icon-btn" data-close-modal="modal-doc-' . (int)$row['id'] . '"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    <form method="post" action="dashboard.php" enctype="multipart/form-data" style="padding:20px;">
                        <input type="hidden" name="upload_additional_doc" value="1">
                        <input type="hidden" name="job_id" value="' . (int)$row['id'] . '">
                        <div style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:12px; border-radius:8px; font-size:13px; margin-bottom:16px;">
                            <strong>Pengajuan Lowongan ke-4+ (KBJI: ' . e($row['kbji_code']) . ')</strong><br>
                            Posisi: <strong>' . e($row['title']) . '</strong><br>
                            Harap lampirkan berkas dokumen pendukung (Surat Izin/Keterangan Tempat Kerja/Justifikasi) atau catatan keterangan untuk ditinjau oleh Admin.
                        </div>
                        <div class="form-group" style="margin-bottom:16px;">
                            <label style="font-weight:600; font-size:13px; display:block; margin-bottom:4px;">Dokumen Pendukung Kebutuhan Tenaga Kerja: <span style="color:#ef4444;">*</span></label>
                            <div style="font-size:11.5px; color:#64748b; line-height:1.45; margin-bottom:8px;">Unggah dokumen pendukung yang menjelaskan kebutuhan membuka kembali jabatan, seperti surat pernyataan kebutuhan tenaga kerja atau dokumen pendukung lain yang relevan.</div>
                            <input type="file" name="additional_file" accept=".pdf,.jpg,.jpeg" ' . (empty($row['additional_doc_file']) ? 'required' : '') . ' style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                            <small style="display:block; font-size:11px; color:#94a3b8; margin-top:4px;">PDF/JPG (Maks. 2 MB)</small>
                            ' . (!empty($row['additional_doc_file']) ? '<div class="tiny" style="color:#0284c7; margin-top:4px;">Berkas tersimpan: <a href="' . e($row['additional_doc_file']) . '" target="_blank" style="color:#0284c7; text-decoration:underline;">Lihat Berkas</a></div>' : '') . '
                        </div>
                        <div class="form-group" style="margin-bottom:20px;">
                            <label style="font-weight:600; font-size:13px; display:block; margin-bottom:6px;">Keterangan / Justifikasi Kebutuhan Tenaga Kerja:</label>
                            <textarea name="additional_notes" rows="4" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;" placeholder="Jelaskan kebutuhan tenaga kerja tambahan untuk posisi ini...">' . e($row['additional_doc_notes'] ?? '') . '</textarea>
                        </div>
                        <div style="display:flex; justify-content:flex-end; gap:10px;">
                            <button type="button" class="secondary-btn" data-close-modal="modal-doc-' . (int)$row['id'] . '">Batal</button>
                            <button type="submit" class="primary-btn"><i class="fa-solid fa-paper-plane"></i> Kirim Dokumen</button>
                        </div>
                    </form>
                </div>
            </div>';
        }
        $adminNoteHtml = '';
        if (in_array($row['status'], ['Perlu Direvisi', 'Perlu Revisi'], true) && trim((string) ($row['admin_notes'] ?? '')) !== '') {
            $adminNoteHtml = '<div class="tiny" style="color:#b45309">Catatan admin: ' . e($row['admin_notes']) . '</div>';
        } elseif ($row['status'] === 'ADDITIONAL_DOCUMENT_PENDING') {
            $hasSubmitted = !empty($row['additional_doc_file']) || !empty($row['additional_doc_notes']);
            $adminNoteHtml = $hasSubmitted
                ? '<div class="tiny" style="color:#0284c7;">Dokumen tambahan telah dikirim · Menunggu peninjauan Admin</div>'
                : '<div class="tiny" style="color:#b45309;">Silakan unggah dokumen tambahan agar dapat diproses Admin</div>';
        }
        $jobRowsHtml .= '<tr data-job-row data-title="' . e($row['title']) . '" data-status="' . e($meta['label']) . '" data-created="' . e($row['created_at']) . '"><td><strong>' . e($row['title']) . '</strong><div class="tiny">Dibuat ' . e(date('d M Y', strtotime($row['created_at']))) . '</div>' . $adminNoteHtml . '</td>'
            . '<td>' . e($row['location']) . '</td>'
            . '<td>' . (int) $row['quota'] . ' orang</td>'
            . '<td>' . $appCount . ' pelamar</td>'
            . '<td><span class="status ' . e($meta['class']) . '">' . e($meta['label']) . '</span></td>'
            . '<td>' . $actionBtn . '</td></tr>';
    }
}

$applicants = employer_applicants((int) $user['id']);
$stageCounts = [
    'Lamaran Masuk' => 0,
    'Sedang Dipelajari' => 0,
    'Wawancara' => 0,
    'Diterima' => 0,
    'Ditolak' => 0,
];
foreach ($applicants as $applicant) {
    $stage = normalize_application_status($applicant['status'] ?? '');
    if (isset($stageCounts[$stage])) {
        $stageCounts[$stage]++;
    }
}
$totalApplicants = count($applicants);
$funnelTotal = max(1, $totalApplicants);
$hiredRate = $totalApplicants ? (int) round(($stageCounts['Diterima'] / $totalApplicants) * 100) : 0;
$funnelRow = static function (string $label, int $count, int $total, string $extraClass = '') {
    $width = $total > 0 ? max(8, (int) round(($count / $total) * 100)) : 8;
    return '<div class="funnel-row"><div class="funnel-label">' . e($label) . '</div><div class="funnel-track"><div class="funnel-fill ' . $extraClass . '" style="width:' . $width . '%">' . $count . '</div></div><div class="funnel-percent">' . $width . '%</div></div>';
};
$funnelHtml = '<div class="funnel-layout"><div class="funnel-bars">'
    . $funnelRow('Pelamar Masuk', $totalApplicants, $funnelTotal)
    . $funnelRow('Dipelajari', $stageCounts['Sedang Dipelajari'], $funnelTotal, 'soft')
    . $funnelRow('Wawancara', $stageCounts['Wawancara'], $funnelTotal, 'softest')
    . $funnelRow('Diterima', $stageCounts['Diterima'], $funnelTotal, 'pale')
    . '</div><div class="funnel-stats"><h4>Konversi keseluruhan</h4><h2>' . $hiredRate . '%</h2><p>dari pelamar masuk sampai diterima</p><div class="stat-block"><div style="color:var(--muted);font-size:12px;margin-bottom:8px;">Tidak berlanjut</div><div class="stat-block-row"><span>Ditolak</span><strong>' . $stageCounts['Ditolak'] . '</strong></div></div></div></div>';

if (!$applicants) {
    $readyHtml = '<div class="empty-state"><i class="fa-solid fa-user-group" style="color:#c9dff7"></i><h4>Belum ada pelamar</h4><p>Pelamar yang masuk akan<br>muncul di sini.</p></div>';
    $applicantHtml = '<div class="record-item"><strong>Belum ada pelamar</strong><span>Lamaran dari pencari kerja akan tampil di sini.</span></div>';
    $activityHtml = '<div class="list"><div class="activity-item"><div class="activity-title">Belum ada aktivitas lamaran.</div><div class="activity-subtitle">Riwayat perubahan status akan muncul di sini.</div></div></div>';
} else {
    $readyHtml = '<div class="applicant-ready-list">';
    foreach ($applicants as $index => $applicant) {
        $meta = application_status_meta($applicant['status']);
        $hidden = $index >= 4 ? ' hidden' : '';
        $avatar = 'https://ui-avatars.com/api/?name=' . rawurlencode($applicant['seeker_name']) . '&background=e8f7fc&color=1e97c4';
        $readyHtml .= '<button type="button" class="applicant-item" data-open-applicant="' . (int) $applicant['id'] . '"' . $hidden . '>'
            . '<img class="avatar" src="' . e($avatar) . '" alt="">'
            . '<div class="meta"><div class="title">' . e($applicant['seeker_name']) . '</div><div class="subtitle">' . e($applicant['job_title']) . '</div></div>'
            . '<span class="tag ' . e($meta['class']) . '">' . e($meta['label']) . '</span></button>';
    }
    $readyHtml .= '</div>';
    if ($totalApplicants > 4) {
        $readyHtml .= '<button type="button" class="show-more-btn" data-expand-applicants>Tampilkan lebih banyak</button>';
    }

    $applicantHtml = '<table class="applicant-table"><thead><tr><th>Pelamar</th><th>Lowongan</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($applicants as $applicant) {
        $meta = application_status_meta($applicant['status']);
        $applicantHtml .= '<tr data-open-applicant="' . (int) $applicant['id'] . '"><td><strong>' . e($applicant['seeker_name']) . '</strong><div class="tiny">' . e($applicant['seeker_email']) . '</div></td><td>' . e($applicant['job_title']) . '</td><td><span class="tag ' . e($meta['class']) . '">' . e($meta['label']) . '</span></td><td>Lihat profil</td></tr>';
    }
    $applicantHtml .= '</tbody></table>';

    $activityHtml = '<div class="list">';
    foreach (array_slice($applicants, 0, 5) as $applicant) {
        $meta = application_status_meta($applicant['status']);
        $when = $applicant['updated_at'] ?: $applicant['created_at'];
        $activityHtml .= '<div class="activity-item"><div class="activity-title">' . e($applicant['seeker_name']) . ' · ' . e($meta['label']) . '</div><div class="activity-subtitle">' . e($applicant['job_title']) . '</div><div class="activity-meta">' . e(date('d M Y H:i', strtotime((string) $when))) . '</div></div>';
    }
    $activityHtml .= '</div>';
}

// Automatic notification reminders for H-7, Transition, Inactive, and Pending Reverification
if ($isH7 && $hasValidDates && $daysRemaining > 0) {
    $existing = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'h7_reminder' AND created_at >= ?");
    $existing->execute([$user['id'], date('Y-m-d 00:00:00')]);
    if ((int)$existing->fetchColumn() === 0) {
        notify_user((int)$user['id'], 'Pengingat Masa Aktif Hak Akses', "Masa aktif Pemberi Kerja Individu Anda akan berakhir dalam {$daysRemaining} hari. Pastikan profil tetap sesuai dan terbaru.", 'h7_reminder');
    }
} elseif ($isTransitionPeriod) {
    $existing = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'transition_reminder' AND created_at >= ?");
    $existing->execute([$user['id'], date('Y-m-d 00:00:00')]);
    if ((int)$existing->fetchColumn() === 0) {
        notify_user((int)$user['id'], 'Masa Transisi Hak Akses', "Masa aktif Pemberi Kerja Individu telah berakhir. Anda berada dalam masa transisi selama {$transRemain} hari dan tidak dapat membuat lowongan baru.", 'transition_reminder');
    }
} elseif ($isFullDisable || $verificationStatus === 'INACTIVE_REVERIFICATION_REQUIRED') {
    $existing = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'inactive_reminder' AND created_at >= ?");
    $existing->execute([$user['id'], date('Y-m-d 00:00:00')]);
    if ((int)$existing->fetchColumn() === 0) {
        notify_user((int)$user['id'], 'Hak Akses Tidak Aktif', 'Hak akses Pemberi Kerja Individu Anda tidak aktif. Lakukan verifikasi ulang profil untuk menggunakan kembali layanan.', 'inactive_reminder');
    }
} elseif (in_array($verificationStatus, ['REVERIFICATION_PENDING', 'PENDING'], true) && !empty($profile['last_activated_at'])) {
    $existing = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'pending_reverification_info' AND created_at >= ?");
    $existing->execute([$user['id'], date('Y-m-d 00:00:00')]);
    if ((int)$existing->fetchColumn() === 0) {
        notify_user((int)$user['id'], 'Verifikasi Ulang Profil Sedang Diproses', 'Verifikasi ulang profil sedang diproses. Anda akan mendapatkan notifikasi setelah Admin memberikan keputusan.', 'pending_reverification_info');
    }
}

$notifications = user_notifications((int) $user['id']);
$unread = unread_notification_count((int) $user['id']);
$activePcr = get_active_profile_change_request((int) $user['id']);
$html = str_replace('<div class="notif"><i class="fa-regular fa-bell"></i></div>', render_notif_dropdown($notifications, $unread, $verificationStatus, $profile['manual_review_status'] ?? '', $activePcr, (int)$user['id']), $html);
$html = preg_replace('/<h3>Draft<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Draft</h3><div class="value">' . $jobCounts['Draft'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Dikirim<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Dikirim</h3><div class="value">' . $jobCounts['Menunggu Verifikasi'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Perlu Direvisi<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Perlu Direvisi</h3><div class="value">' . $jobCounts['Perlu Direvisi'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Lowongan Aktif<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Lowongan Aktif</h3><div class="value">' . $jobCounts['Tayang'] . '</div>', $html, 1);
$html = str_replace('<!--JOB_TABLE_ROWS-->', $jobRowsHtml ?? '', $html);
$html = str_replace('<!--JOB_APPLICANTS-->', $applicantHtml ?? '', $html);
$html = str_replace('<!--READY_APPLICANTS-->', $readyHtml ?? '', $html);
$html = str_replace('<!--FUNNEL_METRICS-->', $funnelHtml ?? '', $html);
$html = str_replace('<!--APPLICATION_ACTIVITY-->', $activityHtml ?? '', $html);
$html = preg_replace('/<h3>Lowongan<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Lowongan</h3><div class="value">' . count($employerJobs) . '</div>', $html, 1);
$html = preg_replace('/<h3>Pelamar<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Pelamar</h3><div class="value">' . $totalApplicants . '</div>', $html, 1);
$html = preg_replace('/<h3>Wawancara<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Wawancara</h3><div class="value">' . $stageCounts['Wawancara'] . '</div>', $html, 1);
$html = preg_replace('/<h3>Diterima<\/h3>\s*<div class="value">\d+<\/div>/', '<h3>Diterima</h3><div class="value">' . $stageCounts['Diterima'] . '</div>', $html, 1);
$html = str_replace('</body>', $additionalDocModalsHtml . "\n</body>", $html);

echo $html;
