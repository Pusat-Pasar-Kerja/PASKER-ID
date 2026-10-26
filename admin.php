<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/platform.php';

$user = require_role('admin');

// Active Section & Filters
$view = $_GET['view'] ?? 'directory_individual';
$entity = $_GET['entity'] ?? 'Semua';
$tab = $_GET['tab'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$cityFilter = trim($_GET['city_filter'] ?? '');
$verifierFilter = trim($_GET['verifier_filter'] ?? '');
$officerFilter = trim($_GET['officer_filter'] ?? '');
$unassignedFilter = isset($_GET['unassigned']) && $_GET['unassigned'] === '1' ? 1 : 0;
$detailId = isset($_GET['detail_id']) ? (int)$_GET['detail_id'] : 0;

// --- POST HANDLERS FOR ADMIN ACTIONS ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (isset($_POST['admin_action']) || isset($_POST['action']))) {
    $action = $_POST['admin_action'] ?? $_POST['action'];
    $redirectUrl = $_POST['redirect_url'] ?? "admin.php?view={$view}&entity={$entity}&tab={$tab}" . ($detailId ? "&detail_id={$detailId}" : "");

    // 1. AMBIL CASE / ASSIGN PEMERIKSA (EMPLOYER)
    if ($action === 'assign_employer_case') {
        $targetUserId = (int)$_POST['user_id'];
        $targetProfileId = (int)($_POST['profile_id'] ?? 0);
        $verifierName = trim($_POST['verifier_name'] ?? '');
        $reason = trim($_POST['assignment_reason'] ?? '');
        $isSelfAssign = !empty($_POST['self_assign']);
        if ($isSelfAssign && empty($verifierName)) {
            $verifierName = $user['name'] ?: 'Pandu Isdiyanto, S.T., M.M';
        }

        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
            $checkSql = ($targetProfileId > 0)
                ? 'SELECT domicile_city_id FROM employer_profiles WHERE id = ?'
                : 'SELECT domicile_city_id FROM employer_profiles WHERE user_id = ?';
            $stmtCheck = db()->prepare($checkSql);
            $stmtCheck->execute([$targetProfileId > 0 ? $targetProfileId : $targetUserId]);
            $empRow = $stmtCheck->fetch();
            $empDomicileCity = (string)($empRow['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                flash('error', 'Akses ditolak: Pemberi Kerja ini di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                redirect($redirectUrl);
                exit;
            }
        }

        if (!$isSelfAssign && empty($verifierName)) {
            flash('error', 'Pemeriksa wajib dipilih terlebih dahulu.');
        } elseif (!$isSelfAssign && strlen($reason) < 10) {
            flash('error', 'Alasan penugasan wajib diisi minimal 10 karakter.');
        } else {
            $reasonText = $isSelfAssign ? 'Pengambilan case mandiri oleh pemeriksa.' : $reason;
            $whereClause = ($targetProfileId > 0) ? 'WHERE id = ?' : 'WHERE user_id = ?';
            $targetParam = ($targetProfileId > 0) ? $targetProfileId : $targetUserId;
            $stmt = db()->prepare("UPDATE employer_profiles SET assigned_to = ?, assigned_at = datetime('now'), assignment_reason = ? {$whereClause}");
            try {
                $stmt->execute([$verifierName, $reasonText, $targetParam]);
            } catch (Throwable $e) {
                $stmt = db()->prepare("UPDATE employer_profiles SET assigned_to = ?, assigned_at = NOW(), assignment_reason = ? {$whereClause}");
                $stmt->execute([$verifierName, $reasonText, $targetParam]);
            }
            if ($isSelfAssign) {
                record_audit_log('employer', $targetUserId, 'CASE_ASSIGNED', "Ditugaskan ke {$verifierName}.", $user['name']);
                record_audit_log('employer', $targetUserId, 'CASE_TAKEN', "{$verifierName} mengambil pengajuan: Dikirim → Dalam Verifikasi.", $user['name']);
                flash('success', "Pengajuan verifikasi berhasil diambil oleh {$verifierName}.");
            } else {
                record_audit_log('employer', $targetUserId, 'CASE_ASSIGNED', "Case ditugaskan kepada: {$verifierName}. Alasan: {$reasonText}", $user['name']);
                flash('success', "Case verifikasi berhasil ditugaskan ke {$verifierName}.");
            }
        }
        redirect($redirectUrl);
        exit;
    }

    if ($action === 'unassign_employer_case') {
        $targetUserId = (int)$_POST['user_id'];
        $targetProfileId = (int)($_POST['profile_id'] ?? 0);
        $whereClause = ($targetProfileId > 0) ? 'WHERE id = ?' : 'WHERE user_id = ?';
        $targetParam = ($targetProfileId > 0) ? $targetProfileId : $targetUserId;
        $stmt = db()->prepare("UPDATE employer_profiles SET assigned_to = NULL, assigned_at = NULL, assignment_reason = NULL {$whereClause}");
        $stmt->execute([$targetParam]);
        record_audit_log('employer', $targetUserId, 'CASE_UNASSIGNED', "Penugasan dibatalkan.", $user['name']);
        flash('success', "Penugasan pemeriksa berhasil dibatalkan.");
        redirect($redirectUrl);
        exit;
    }

    // 2. KEPUTUSAN VERIFIKASI PEMBERI KERJA
    if ($action === 'verify_employer') {
        $targetUserId = (int)$_POST['user_id'];
        $targetProfileId = (int)($_POST['profile_id'] ?? 0);
        $decision = $_POST['decision']; // approve | revision | reject
        $notes = trim($_POST['verifier_notes'] ?? '');
        $checklist = isset($_POST['checklist']) ? implode(', ', $_POST['checklist']) : '';

        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Lock row with FOR UPDATE
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';
            $whereEmp = ($targetProfileId > 0) ? 'ep.id = ?' : 'ep.user_id = ?';
            $stmtEmp = $pdo->prepare('SELECT ep.*, u.name, u.email FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE ' . $whereEmp . $forUpdate);
            $stmtEmp->execute([$targetProfileId > 0 ? $targetProfileId : $targetUserId]);
            $targetEmp = $stmtEmp->fetch();

            if (!$targetEmp) {
                $pdo->rollBack();
                flash('error', 'Pemberi Kerja tidak ditemukan.');
                redirect($redirectUrl);
                exit;
            }

            $assignedTo = trim((string)($targetEmp['assigned_to'] ?? ''));
            if ($assignedTo === '') {
                $pdo->rollBack();
                flash('error', 'Pemberi kerja harus memiliki penugasan aktif terlebih dahulu sebelum keputusan dapat diambil.');
                redirect($redirectUrl);
                exit;
            }

            // Verify current admin IS the assigned verifier (compare by name or email)
            $currentAdminName  = trim((string)($user['name'] ?? ''));
            $currentAdminEmail = trim((string)($user['email'] ?? ''));
            if (strcasecmp($assignedTo, $currentAdminName) !== 0 && strcasecmp($assignedTo, $currentAdminEmail) !== 0) {
                $pdo->rollBack();
                flash('error', 'Akses ditolak: Anda bukan pemeriksa yang ditugaskan (assigned_to) untuk verifikasi profil ini. Hanya verifikator yang ditugaskan (' . e($assignedTo) . ') yang dapat mengambil keputusan. Silakan ambil alih penugasan case terlebih dahulu.');
                redirect($redirectUrl);
                exit;
            }

            // Ensure verification state is still open
            if (!in_array($targetEmp['verification_status'], ['PENDING', 'NEEDS_REVISION', 'NOT_SUBMITTED'], true)) {
                $pdo->rollBack();
                flash('error', 'Keputusan verifikasi tidak dapat diproses karena status profil sudah berubah (' . e($targetEmp['verification_status']) . ').');
                redirect($redirectUrl);
                exit;
            }

            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($targetEmp['domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $pdo->rollBack();
                    flash('error', 'Akses ditolak: Pemberi Kerja ini di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                    redirect($redirectUrl);
                    exit;
                }
            }

            $verifMethod = strtoupper(trim((string)($_POST['verification_method'] ?? 'ONLINE')));
            if ($verifMethod !== 'OFFLINE') {
                $verifMethod = 'ONLINE';
            }
            $onlineConsent = !empty($_POST['online_consent']);
            $fieldConsent = !empty($_POST['field_inspection_consent']);
            $locationResult = strtoupper(trim((string)($_POST['location_check_result'] ?? '')));
            if ($verifMethod === 'OFFLINE' && $locationResult !== 'TIDAK_SESUAI') {
                $locationResult = 'SESUAI';
            }

            // Behavior: Tombol keputusan Setujui / Revisi / Tolak hanya dapat diproses setelah consent sesuai metode verifikasi telah dicentang.
            if ($verifMethod === 'ONLINE' && !$onlineConsent) {
                $pdo->rollBack();
                flash('error', 'Consent Verifikasi Online wajib dicentang sebelum keputusan dapat diproses.');
                redirect($redirectUrl);
                exit;
            }

            if ($verifMethod === 'OFFLINE' && !$fieldConsent) {
                $pdo->rollBack();
                flash('error', 'Pernyataan Pemeriksaan Lapangan wajib dicentang sebelum keputusan dapat diproses.');
                redirect($redirectUrl);
                exit;
            }

            // Behavior: Field Alasan/Temuan wajib ketika Admin memilih Revisi atau Tolak.
            if (($decision === 'revision' || $decision === 'reject') && $notes === '') {
                $pdo->rollBack();
                flash('error', 'Field Alasan/Temuan wajib diisi ketika Admin memilih Revisi atau Tolak.');
                redirect($redirectUrl);
                exit;
            }

            $savedLocationResult = ($verifMethod === 'OFFLINE') ? $locationResult : null;
            $methodLog = "Metode: " . ($verifMethod === 'OFFLINE' ? "Offline (Lokasi: {$locationResult})" : "Online");

            $profileIdToUpdate = (int)($targetEmp['id'] ?? 0);
            if ($decision === 'approve') {
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = datetime("now", "+6 months"), last_activated_at = datetime("now"), extension_requested = 0, extension_status = "NONE", manual_review_status = NULL, verifier_notes = ?, verification_checklist = ?, verification_method = ?, location_check_result = ? WHERE id = ?');
                } else {
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = DATE_ADD(NOW(), INTERVAL 6 MONTH), last_activated_at = NOW(), extension_requested = 0, extension_status = "NONE", manual_review_status = NULL, verifier_notes = ?, verification_checklist = ?, verification_method = ?, location_check_result = ? WHERE id = ?');
                }
                $stmt->execute([$notes, $checklist, $verifMethod, $savedLocationResult, $profileIdToUpdate]);
                $pdo->prepare('UPDATE users SET profile_complete = 1 WHERE id = ?')->execute([$targetUserId]);

                $isReactivationApproval = !empty($targetEmp['last_activated_at']) || ($targetEmp['extension_status'] ?? '') === 'REQUESTED' || ($targetEmp['extension_requested'] ?? 0) == 1;
                $auditAction = $isReactivationApproval ? 'REVERIFICATION_APPROVED' : 'APPROVED';
                $notesStr = !empty($notes) ? " Catatan: {$notes}" : "";
                $auditText = $isReactivationApproval
                    ? "Verifikasi Ulang Profil disetujui ({$methodLog}). Hak Akses aktif kembali selama 6 bulan.{$notesStr}"
                    : "Profil disetujui ({$methodLog}). Masa aktif berlaku 6 bulan.{$notesStr}";

                record_audit_log('employer', $targetUserId, $auditAction, $auditText, $user['name'], $user['role'] ?? 'admin', true);
                if ($isReactivationApproval) {
                    notify_user($targetUserId, 'Verifikasi Ulang Profil Disetujui', 'Selamat! Verifikasi Ulang Profil Anda telah disetujui Admin dan Hak Akses aktif kembali selama 6 bulan.', 'success');
                } else {
                    notify_user($targetUserId, 'Profil Disetujui', 'Selamat! Hak Akses Pemberi Kerja Individu Anda telah disetujui dan aktif selama 6 bulan.', 'success');
                }
                $pdo->commit();
                flash('success', $isReactivationApproval ? 'Verifikasi Ulang Profil berhasil Disetujui. Hak Akses aktif kembali selama 6 Bulan.' : 'Hak Akses Pemberi Kerja Individu berhasil Disetujui (Masa Aktif 6 Bulan).');
            } elseif ($decision === 'revision') {
                $currentRev = (int)($targetEmp['revision_count'] ?? ($targetEmp['rejection_count'] ?? 0));
                $newRevisionCount = min(3, $currentRev + 1);
                $manualStatus = ($newRevisionCount >= 3) ? 'MANUAL_DINAS_REVIEW' : 'NONE';

                try {
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", revision_count = ?, rejection_count = ?, manual_review_status = ?, verifier_notes = ?, verification_checklist = ?, verification_method = ?, location_check_result = ? WHERE id = ?');
                    $stmt->execute([$newRevisionCount, $newRevisionCount, $manualStatus, $notes, $checklist, $verifMethod, $savedLocationResult, $profileIdToUpdate]);
                } catch (Throwable $ignore) {
                    $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "NEEDS_REVISION", rejection_count = ?, verifier_notes = ?, verification_checklist = ?, verification_method = ?, location_check_result = ? WHERE id = ?');
                    $stmt->execute([$newRevisionCount, $notes, $checklist, $verifMethod, $savedLocationResult, $profileIdToUpdate]);
                }
                record_audit_log('employer', $targetUserId, 'REVISION_REQUESTED', "Permintaan perbaikan data (Revisi ke-{$newRevisionCount}) dikirim ke pemohon. {$methodLog}. Catatan: {$notes}", $user['name'], $user['role'] ?? 'admin', true);
                notify_user($targetUserId, "Perbaikan Profil Diperlukan (Revisi ke-{$newRevisionCount})", 'Verifikator meminta perbaikan profil: ' . $notes, 'warning');
                $pdo->commit();
                flash('success', "Profil dikembalikan ke pemohon untuk diperbaiki (Revisi Diminta ke-{$newRevisionCount}).");
            } elseif ($decision === 'reject') {
                $newRejectionCount = (int)($targetEmp['rejection_count'] ?? 0) + 1;
                $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 0, verification_status = "REJECTED", rejection_count = ?, verifier_notes = ?, verification_checklist = ?, verification_method = ?, location_check_result = ? WHERE id = ?');
                $stmt->execute([$newRejectionCount, $notes, $checklist, $verifMethod, $savedLocationResult, $profileIdToUpdate]);
                record_audit_log('employer', $targetUserId, 'REJECTED', "Profil ditolak. {$methodLog}. Catatan: {$notes}", $user['name'], $user['role'] ?? 'admin', true);
                notify_user($targetUserId, 'Profil Ditolak', 'Verifikator menolak profil Anda: ' . $notes, 'error');
                $pdo->commit();
                flash('success', 'Profil Pemberi Kerja telah Ditolak.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Gagal memproses keputusan verifikasi: ' . $e->getMessage());
        }
        redirect($redirectUrl);
        exit;
    }

    // 2.1 VERIFIKASI PERMINTAAN PERUBAHAN PROFIL (PROFILE CHANGE REQUEST)
    if ($action === 'approve_profile_change') {
        $requestId = (int)$_POST['request_id'];
        $notes = trim($_POST['admin_notes'] ?? '');
        if ($notes === '') {
            flash('error', 'Catatan Admin / Verifikator wajib diisi.');
            redirect($redirectUrl);
            exit;
        }
        $result = approve_profile_change_request($requestId, $user['name'] ?? 'Admin', $notes);
        if ($result['success']) {
            flash('success', "Permintaan perubahan profil berhasil disetujui. Versi profil ke-{$result['version']} telah disimpan.");
        } else {
            flash('error', $result['error'] ?? 'Gagal menyetujui perubahan profil.');
        }
        redirect($redirectUrl);
        exit;
    }

    if ($action === 'reject_profile_change') {
        $requestId = (int)$_POST['request_id'];
        $notes = trim($_POST['admin_notes'] ?? '');
        if ($notes === '') {
            flash('error', 'Catatan Admin / Verifikator wajib diisi.');
            redirect($redirectUrl);
            exit;
        }
        $result = reject_profile_change_request($requestId, $user['name'] ?? 'Admin', $notes);
        if ($result['success']) {
            flash('success', 'Permintaan perubahan profil telah ditolak. Data aktif profil terverifikasi tetap dipertahankan.');
        } else {
            flash('error', $result['error'] ?? 'Gagal menolak perubahan profil.');
        }
        redirect($redirectUrl);
        exit;
    }

    // 3. JALUR MANUAL DINAS & VERIFIKASI ULANG OFFLINE: CONTROLLED EDIT & SIMPAN DATA
    if ($action === 'manual_dinas_edit') {
        $targetUserId = (int)$_POST['user_id'];

        // Fetch existing profile first for complete fallback protection
        $stmtOld = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtOld->execute([$targetUserId]);
        $oldProfile = $stmtOld->fetch() ?: [];

        $ownerName = trim($_POST['owner_name'] ?? ($oldProfile['owner_name'] ?? ''));
        $nik = trim($_POST['nik'] ?? ($oldProfile['nik'] ?? ''));
        $profession = trim($_POST['profession'] ?? ($oldProfile['profession'] ?? ''));
        $phone = trim($_POST['phone'] ?? ($oldProfile['phone'] ?? ''));
        $whatsapp = trim($_POST['whatsapp'] ?? ($oldProfile['whatsapp'] ?? ''));
        $npwp = trim($_POST['npwp'] ?? ($oldProfile['npwp'] ?? ''));
        $instagram = trim($_POST['instagram'] ?? ($oldProfile['instagram'] ?? ''));
        $facebook = trim($_POST['facebook'] ?? ($oldProfile['facebook'] ?? ''));
        $linkedin = trim($_POST['linkedin'] ?? ($oldProfile['linkedin'] ?? ''));
        $tiktok = trim($_POST['tiktok'] ?? ($oldProfile['tiktok'] ?? ''));
        $description = trim($_POST['description'] ?? ($oldProfile['description'] ?? ''));
        $brandName = trim($_POST['brand_name'] ?? ($oldProfile['brand_name'] ?? ''));

        // Primary location inputs from form
        $province = trim($_POST['province'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $village = trim($_POST['village'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $addressDetail = trim($_POST['address_detail'] ?? ($_POST['workplace_detail'] ?? ''));

        // Fallback from workplace or domicile fields if primary was omitted
        if ($province === '') $province = trim($_POST['workplace_province'] ?? ($_POST['domicile_province'] ?? ''));
        if ($city === '') $city = trim($_POST['workplace_city'] ?? ($_POST['domicile_city'] ?? ''));
        if ($district === '') $district = trim($_POST['workplace_district'] ?? ($_POST['domicile_district'] ?? ''));
        if ($village === '') $village = trim($_POST['workplace_village'] ?? ($_POST['domicile_village'] ?? ''));
        if ($postalCode === '') $postalCode = trim($_POST['workplace_postal_code'] ?? ($_POST['domicile_postal_code'] ?? ''));
        if ($address === '') $address = trim($_POST['workplace_address'] ?? ($_POST['domicile_address'] ?? ''));

        // Fallbacks from existing profile database so data is NEVER wiped out
        if ($province === '' && !empty($oldProfile['province'])) $province = $oldProfile['province'];
        if ($city === '' && !empty($oldProfile['city'])) $city = $oldProfile['city'];
        if ($district === '' && !empty($oldProfile['district'])) $district = $oldProfile['district'];
        if ($village === '' && !empty($oldProfile['village'])) $village = $oldProfile['village'];
        if ($postalCode === '' && !empty($oldProfile['postal_code'])) $postalCode = $oldProfile['postal_code'];
        if ($address === '' && !empty($oldProfile['address'])) $address = $oldProfile['address'];
        if ($addressDetail === '' && !empty($oldProfile['address_detail'])) $addressDetail = $oldProfile['address_detail'];

        // Workplace & Domicile synchronization
        $sameLoc = !empty($_POST['same_location_siapkerja']) || !empty($_POST['workplace_same_as_domicile']) ? 1 : 0;
        if ($sameLoc) {
            $domicileAddress = $address;
            $domicileProvince = $province;
            $domicileCity = $city;
            $domicileDistrict = $district;
            $domicileVillage = $village;
            $domicilePostalCode = $postalCode;

            $workplaceAddress = $address;
            $workplaceProvince = $province;
            $workplaceCity = $city;
            $workplaceDistrict = $district;
            $workplaceVillage = $village;
            $workplacePostalCode = $postalCode;
            $workplaceDetail = $addressDetail;
        } else {
            $workplaceAddress = $address;
            $workplaceProvince = $province;
            $workplaceCity = $city;
            $workplaceDistrict = $district;
            $workplaceVillage = $village;
            $workplacePostalCode = $postalCode;
            $workplaceDetail = $addressDetail;

            $domicileAddress = trim($_POST['domicile_address'] ?? '') ?: ($oldProfile['domicile_address'] ?? $address);
            $domicileProvince = trim($_POST['domicile_province'] ?? '') ?: ($oldProfile['domicile_province'] ?? $province);
            $domicileCity = trim($_POST['domicile_city'] ?? '') ?: ($oldProfile['domicile_city'] ?? $city);
            $domicileDistrict = trim($_POST['domicile_district'] ?? '') ?: ($oldProfile['domicile_district'] ?? $district);
            $domicileVillage = trim($_POST['domicile_village'] ?? '') ?: ($oldProfile['domicile_village'] ?? $village);
            $domicilePostalCode = trim($_POST['domicile_postal_code'] ?? '') ?: ($oldProfile['domicile_postal_code'] ?? $postalCode);
        }

        // Uploads
        $permitDoc = store_upload('permit_document', 'employer/' . $targetUserId, ['pdf', 'docx', 'jpg', 'jpeg']);
        $workplacePhoto = store_upload('workplace_photo', 'employer/' . $targetUserId, ['png', 'jpg', 'jpeg']);
        $businessLogo = store_upload('business_logo', 'employer/' . $targetUserId, ['png', 'jpg', 'jpeg']);

        $permitDoc = $permitDoc ?: (!empty($_POST['existing_permit_document']) ? trim($_POST['existing_permit_document']) : ($oldProfile['permit_document'] ?? $oldProfile['doc_permission'] ?? null));
        $workplacePhoto = $workplacePhoto ?: (!empty($_POST['existing_workplace_photo']) ? trim($_POST['existing_workplace_photo']) : ($oldProfile['workplace_photo'] ?? $oldProfile['doc_location_photo'] ?? null));
        $businessLogo = $businessLogo ?: (!empty($_POST['existing_business_logo']) ? trim($_POST['existing_business_logo']) : ($oldProfile['business_logo'] ?? null));

        $socList = [];
        if ($instagram !== '') $socList[] = "Instagram: {$instagram}";
        if ($facebook !== '') $socList[] = "Facebook: {$facebook}";
        if ($linkedin !== '') $socList[] = "LinkedIn: {$linkedin}";
        if ($tiktok !== '') $socList[] = "TikTok: {$tiktok}";
        $socialMediaStr = implode(', ', $socList);

        $updateSql = <<<SQL
            UPDATE employer_profiles SET
                owner_name = ?, nik = ?, profession = ?, phone = ?, whatsapp = ?, npwp = ?,
                brand_name = ?, business_logo = ?,
                instagram = ?, facebook = ?, linkedin = ?, tiktok = ?, social_media = ?,
                province = ?, city = ?, district = ?, village = ?, postal_code = ?,
                address = ?, address_detail = ?, description = ?,
                domicile_address = ?, domicile_province = ?, domicile_city = ?, domicile_district = ?, domicile_village = ?, domicile_postal_code = ?, domicile_city_id = ?,
                same_location_siapkerja = ?, workplace_same_as_domicile = ?,
                workplace_address = ?, workplace_province = ?, workplace_city = ?, workplace_district = ?, workplace_village = ?, workplace_postal_code = ?, workplace_detail = ?,
                permit_document = ?, doc_permission = ?, workplace_photo = ?, doc_location_photo = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        SQL;
        db()->prepare($updateSql)->execute([
            $ownerName, $nik, $profession, $phone, $whatsapp, $npwp,
            $brandName, $businessLogo,
            $instagram, $facebook, $linkedin, $tiktok, $socialMediaStr,
            $province, $city, $district, $village, $postalCode,
            $address, $addressDetail, $description,
            $domicileAddress, $domicileProvince, $domicileCity, $domicileDistrict, $domicileVillage, $domicilePostalCode, $domicileCity ?: $city,
            $sameLoc, $sameLoc,
            $workplaceAddress, $workplaceProvince, $workplaceCity, $workplaceDistrict, $workplaceVillage, $workplacePostalCode, $workplaceDetail,
            $permitDoc, $permitDoc, $workplacePhoto, $workplacePhoto,
            $targetUserId
        ]);

        $newProfileData = [
            'owner_name' => $ownerName, 'nik' => $nik, 'profession' => $profession,
            'phone' => $phone, 'whatsapp' => $whatsapp, 'npwp' => $npwp, 'province' => $province,
            'city' => $city, 'district' => $district, 'village' => $village, 'postal_code' => $postalCode,
            'address' => $address, 'address_detail' => $addressDetail, 'description' => $description,
            'brand_name' => $brandName, 'tiktok' => $tiktok
        ];
        $newHash = calculate_employer_consent_hash($newProfileData);

        if (!empty($_POST['send_consent'])) {
            db()->prepare('UPDATE employer_profiles SET manual_review_status = "CONSENT_PENDING", consent_data_hash = ?, consent_agreed = 0 WHERE user_id = ?')->execute([$newHash, $targetUserId]);
            record_audit_log('employer', $targetUserId, 'ADMIN_PROFILE_EDIT', "Petugas Dinas memperbarui seluruh data profil pemohon pada permohonan ulang.", $user['name'], $user['role'] ?? 'admin', true);
            record_audit_log('employer', $targetUserId, 'CONSENT_REQUESTED', "Petugas Dinas mengirimkan permintaan persetujuan (Consent) ke pemohon.", $user['name'], $user['role'] ?? 'admin', true);
            notify_user($targetUserId, 'Persetujuan Data Diperlukan (Jalur Dinas)', 'Petugas Dinas telah menyiapkan data perbaikan profil Anda. Silakan tinjau dan berikan persetujuan (Consent) di Dashboard Anda.', 'warning');
            flash('success', 'Data profil berhasil diperbarui dan Permintaan Persetujuan (Consent) telah dikirim ke akun pemohon.');
        } else {
            if (!empty($oldProfile['consent_data_hash']) && $oldProfile['consent_data_hash'] !== $newHash && ($oldProfile['manual_review_status'] ?? '') === 'CONSENT_GIVEN') {
                db()->prepare('UPDATE employer_profiles SET manual_review_status = "INVALID", consent_agreed = 0, consent_data_hash = NULL WHERE user_id = ?')->execute([$targetUserId]);
                record_audit_log('employer', $targetUserId, 'CONSENT_INVALIDATED', "Data profil diubah oleh Petugas Dinas setelah persetujuan pemohon. Consent sebelumnya otomatis INVALID.", $user['name'], $user['role'] ?? 'admin', true);
                flash('warning', 'Data profil berhasil diperbarui. PERINGATAN: Karena data berubah, persetujuan (consent) pemohon sebelumnya menjadi INVALID. Silakan klik Kirim Permintaan Consent ulang.');
            } else {
                record_audit_log('employer', $targetUserId, 'ADMIN_PROFILE_EDIT', "Petugas Dinas memperbarui data profil pemohon.", $user['name'], $user['role'] ?? 'admin', true);
                flash('success', 'Perubahan data profil berhasil disimpan.');
            }
        }

        if (!empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Perubahan data profil berhasil disimpan.',
                'data' => [
                    'province' => $province,
                    'city' => $city,
                    'district' => $district,
                    'village' => $village,
                    'postal_code' => $postalCode,
                    'address' => $address,
                    'address_detail' => $addressDetail,
                    'brand_name' => $brandName
                ]
            ]);
            exit;
        }

        redirect($redirectUrl);
        exit;
    }

    // 4. JALUR MANUAL DINAS: AJUKAN CONSENT KE USER
    if ($action === 'manual_dinas_request_consent') {
        $targetUserId = (int)$_POST['user_id'];
        $stmtEmp = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtEmp->execute([$targetUserId]);
        $emp = $stmtEmp->fetch();

        if ($emp) {
            $hash = calculate_employer_consent_hash($emp);
            $stmt = db()->prepare('UPDATE employer_profiles SET manual_review_status = "CONSENT_PENDING", consent_data_hash = ?, consent_agreed = 0 WHERE user_id = ?');
            $stmt->execute([$hash, $targetUserId]);
            record_audit_log('employer', $targetUserId, 'CONSENT_REQUESTED', "Petugas Dinas mengirimkan permintaan persetujuan (Consent) ke pemohon.", $user['name'], $user['role'] ?? 'admin', true);
            notify_user($targetUserId, 'Persetujuan Data Diperlukan (Jalur Dinas)', 'Petugas Dinas telah menyiapkan data perbaikan profil Anda. Silakan tinjau dan berikan persetujuan (Consent) di Dashboard Anda.', 'warning');
            flash('success', 'Permintaan persetujuan (Consent) berhasil diajukan ke pemohon.');
        }
        redirect($redirectUrl);
        exit;
    }

    // 5. JALUR MANUAL DINAS: SETUJUI & AKTIFKAN (PERNYATAAN PETUGAS)
    if ($action === 'manual_dinas_approve_activate') {
        $targetUserId = (int)$_POST['user_id'];
        $officerName = trim($_POST['officer_name'] ?? $user['name']);
        $officerStatement = trim($_POST['officer_statement'] ?? '');
        $statementCheck = !empty($_POST['statement_confirmed']);

        if (!$statementCheck || $officerStatement === '') {
            flash('error', 'Pernyataan Petugas dan konfirmasi checklist wajib dicentang.');
            redirect($redirectUrl);
            exit;
        }

        // Verify consent is valid
        $stmtEmp = db()->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtEmp->execute([$targetUserId]);
        $emp = $stmtEmp->fetch();

        $currentHash = calculate_employer_consent_hash($emp);
        if ($emp['manual_review_status'] !== 'CONSENT_GIVEN' || empty($emp['consent_data_hash']) || $emp['consent_data_hash'] !== $currentHash) {
            flash('error', 'Persetujuan pemohon belum disetujui atau data telah berubah setelah consent. Setujui & Aktifkan dibatalkan.');
            redirect($redirectUrl);
            exit;
        }

        $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = datetime("now", "+6 months"), last_activated_at = datetime("now"), extension_requested = 0, extension_status = "NONE", manual_review_status = "APPROVED_DINAS", officer_name = ?, officer_statement = ? WHERE user_id = ?');
        } else {
            $stmt = db()->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = DATE_ADD(NOW(), INTERVAL 6 MONTH), last_activated_at = NOW(), extension_requested = 0, extension_status = "NONE", manual_review_status = "APPROVED_DINAS", officer_name = ?, officer_statement = ? WHERE user_id = ?');
        }
        $stmt->execute([$officerName, $officerStatement, $targetUserId]);
        db()->prepare('UPDATE users SET profile_complete = 1 WHERE id = ?')->execute([$targetUserId]);
        record_audit_log('employer', $targetUserId, 'APPROVED_MANUAL_DINAS', "Profil disetujui & diaktifkan melalui Jalur Manual Dinas oleh petugas: {$officerName}. Pernyataan: {$officerStatement}", $user['name'], $user['role'] ?? 'admin', true);
        notify_user($targetUserId, 'Profil Aktif (Jalur Dinas)', 'Selamat! Akun Pemberi Kerja Individu Anda telah disetujui dan diaktifkan oleh Dinas Tenaga Kerja selama 6 bulan.', 'success');
        flash('success', 'Akun Pemberi Kerja Individu berhasil Disetujui & Diaktifkan melalui Jalur Manual Dinas.');
        redirect($redirectUrl);
        exit;
    }

    // 6. TANGGUHKAN / BATALKAN PENANGGUHAN (SUSPENSION)
    if ($action === 'suspend_employer') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['suspension_reason'] ?? '');
        $res = suspend_employer_access(db(), $targetUserId, $reason, $user);
        if (!$res['success']) {
            flash('error', $res['error']);
        } else {
            flash('success', $res['message']);
        }
        redirect($redirectUrl);
        exit;
    }

    if ($action === 'unsuspend_employer') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $res = unsuspend_employer_access(db(), $targetUserId, $user);
        if (!$res['success']) {
            flash('error', $res['error']);
        } else {
            flash('success', $res['message']);
        }
        redirect($redirectUrl);
        exit;
    }


    // 8. AMBIL CASE / ASSIGN PEMERIKSA LOWONGAN
    if ($action === 'assign_job_case') {
        $jobId = (int)$_POST['job_id'];
        $verifierName = trim($_POST['verifier_name'] ?? 'Pandu Isdiyanto, S.T., M.M');
        $reason = trim($_POST['assignment_reason'] ?? '');
        $isSelfAssign = !empty($_POST['self_assign']);

        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
            $stmtCheck = db()->prepare('SELECT ep.domicile_city_id FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.user_id WHERE j.id = ?');
            $stmtCheck->execute([$jobId]);
            $jobEmpRow = $stmtCheck->fetch();
            $empDomicileCity = (string)($jobEmpRow['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                flash('error', 'Akses ditolak: Lowongan ini milik Pemberi Kerja di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                redirect($redirectUrl);
                exit;
            }
        }

        if (!$isSelfAssign && strlen($reason) < 10) {
            flash('error', 'Alasan penugasan lowongan wajib diisi minimal 10 karakter.');
        } else {
            $reasonText = $isSelfAssign ? 'Pengambilan case lowongan mandiri oleh pemeriksa.' : $reason;
            $stmt = db()->prepare('UPDATE job_posts SET assigned_to = ?, assigned_at = datetime("now"), assignment_reason = ? WHERE id = ?');
            try {
                $stmt->execute([$verifierName, $reasonText, $jobId]);
            } catch (Throwable $e) {
                $stmt = db()->prepare('UPDATE job_posts SET assigned_to = ?, assigned_at = NOW(), assignment_reason = ? WHERE id = ?');
                $stmt->execute([$verifierName, $reasonText, $jobId]);
            }
            record_audit_log('job', $jobId, 'CASE_ASSIGNED', "Case lowongan ditugaskan kepada: {$verifierName}. Alasan: {$reasonText}", $user['name']);
            flash('success', "Case verifikasi lowongan berhasil ditugaskan ke {$verifierName}.");
        }
        redirect($redirectUrl);
        exit;
    }

    // 9. KEPUTUSAN VERIFIKASI LOWONGAN (CHECKLIST 4 KATEGORI)
    if ($action === 'verify_job') {
        $jobId = (int)$_POST['job_id'];
        $decision = $_POST['decision']; // approve | revision | reject
        $notes = trim($_POST['verifier_notes'] ?? '');

        $stmtStatusCheck = db()->prepare('SELECT status FROM job_posts WHERE id = ?');
        $stmtStatusCheck->execute([$jobId]);
        $currStatus = (string)($stmtStatusCheck->fetchColumn() ?? '');
        if ($currStatus === 'Ditutup' || strcasecmp($currStatus, 'Draft') === 0) {
            flash('error', 'Lowongan ini berstatus Draft / Ditutup dan tidak dapat diverifikasi.');
            redirect($redirectUrl);
            exit;
        }

        $childCheck = db()->prepare('SELECT COUNT(*) FROM job_posts WHERE parent_job_id = ?');
        $childCheck->execute([$jobId]);
        if ((int)$childCheck->fetchColumn() > 0) {
            flash('error', 'Lowongan sumber ini sudah memiliki posting turunan sisa kuota dan tidak dapat diubah statusnya.');
            redirect($redirectUrl);
            exit;
        }

        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
            $stmtCheck = db()->prepare('SELECT ep.domicile_city_id FROM job_posts j JOIN employer_profiles ep ON ep.user_id = j.user_id WHERE j.id = ?');
            $stmtCheck->execute([$jobId]);
            $jobEmpRow = $stmtCheck->fetch();
            $empDomicileCity = (string)($jobEmpRow['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                flash('error', 'Akses ditolak: Lowongan ini milik Pemberi Kerja di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.');
                redirect($redirectUrl);
                exit;
            }
        }

        // Parse 4 Compliance Categories
        $categories = compliance_categories();
        $checklistData = [];
        $hasViolation = false;
        $missingViolationNote = false;

        foreach ($categories as $cat) {
            $slug = 'cat_' . md5($cat);
            $status = $_POST[$slug . '_status'] ?? 'Patuh';
            $catNote = trim($_POST[$slug . '_note'] ?? '');

            if ($status === 'Tidak Patuh') {
                $hasViolation = true;
                if ($catNote === '') {
                    $missingViolationNote = true;
                }
            }
            $checklistData[$cat] = [
                'status' => $status,
                'note' => $catNote,
            ];
        }

        if ($missingViolationNote) {
            flash('error', 'Catatan item wajib diisi untuk setiap kategori yang dinyatakan "Tidak Patuh".');
            redirect($redirectUrl);
            exit;
        }

        if ($decision === 'approve' && $hasViolation) {
            flash('error', 'Keputusan "Setujui" TIDAK VALID karena masih terdapat kategori checklist yang "Tidak Patuh". Hanya keputusan Revisi atau Tolak yang diperbolehkan.');
            redirect($redirectUrl);
            exit;
        }

        if (($decision === 'reject' || $decision === 'revision') && $notes === '') {
            flash('error', 'Catatan wajib diisi untuk keputusan ' . ($decision === 'reject' ? 'Tolak' : 'Revisi') . '.');
            redirect($redirectUrl);
            exit;
        }

        $checklistJson = json_encode($checklistData, JSON_UNESCAPED_UNICODE);

            if ($decision === 'approve') {
            $stmt = db()->prepare('UPDATE job_posts SET status = "Tayang", published_at = CURRENT_TIMESTAMP, verifier_notes = ?, compliance_checklist = ? WHERE id = ?');
            $stmt->execute([$notes, $checklistJson, $jobId]);
            try {
                db()->prepare('UPDATE job_verifications SET status = "APPROVED", verifier_notes = ? WHERE job_id = ?')->execute([$notes, $jobId]);
            } catch (Throwable $ignored) {}
            record_audit_log('job', $jobId, 'APPROVED', "Lowongan disetujui dan Tayang. Semua kategori patuh. Catatan: {$notes}", $user['name']);

            // Lapisan 2: Notifikasi batas publikasi ke-3 jika pada bulan berjalan publikasi KBJI sama mencapai 3
            try {
                $jobInfoStmt = db()->prepare('SELECT user_id, kbji_code, title FROM job_posts WHERE id = ?');
                $jobInfoStmt->execute([$jobId]);
                $jobInfo = $jobInfoStmt->fetch();
                if ($jobInfo) {
                    $empUserId = (int)$jobInfo['user_id'];
                    $kbjiCode = (string)$jobInfo['kbji_code'];
                    $startOfMonth = date('Y-m-01 00:00:00');
                    $endOfMonth   = date('Y-m-t 23:59:59');
                    $cntStmt = db()->prepare('
                        SELECT COUNT(*) FROM job_posts
                        WHERE user_id = ?
                          AND kbji_code = ?
                          AND parent_job_id IS NULL
                          AND status = "Tayang"
                          AND published_at IS NOT NULL
                          AND published_at BETWEEN ? AND ?
                    ');
                    $cntStmt->execute([$empUserId, $kbjiCode, $startOfMonth, $endOfMonth]);
                    $publishedSameKbjiCount = (int)$cntStmt->fetchColumn();

                    if ($publishedSameKbjiCount === 3) {
                        $kbjiMap = kbji_name_map();
                        $kbjiName = $kbjiMap[$kbjiCode] ?? '';
                        $notifTitle = $kbjiName !== ''
                            ? 'Informasi Publikasi Jabatan: ' . $kbjiName . ' (KBJI: ' . $kbjiCode . ')'
                            : 'Informasi Publikasi Jabatan (KBJI: ' . $kbjiCode . ')';
                        $msgNotice = 'Anda telah mempublikasikan 3 lowongan dengan kode KBJI yang sama pada bulan ini. Mulai pengajuan keempat dan seterusnya dengan kode KBJI yang sama, Anda wajib melengkapi Alasan/Keterangan Pengulangan Jabatan dan Dokumen Pendukung Kebutuhan Tenaga Kerja untuk diperiksa oleh Admin.';
                        notify_user($empUserId, $notifTitle, $msgNotice, 'info', $jobId);
                    }
                }
            } catch (Throwable $ignored) {}
                flash('success', 'Lowongan berhasil disetujui dan Tayang.');
            } elseif ($decision === 'revision') {
            $stmt = db()->prepare('UPDATE job_posts SET status = "Perlu Direvisi", admin_notes = ?, verifier_notes = ?, compliance_checklist = ? WHERE id = ?');
            $stmt->execute([$notes, $notes, $checklistJson, $jobId]);
            try {
                db()->prepare('UPDATE job_verifications SET status = "NEEDS_REVISION", verifier_notes = ? WHERE job_id = ?')->execute([$notes, $jobId]);
            } catch (Throwable $ignored) {}
            record_audit_log('job', $jobId, 'REVISION_REQUESTED', "Lowongan dikembalikan ke pemohon untuk diperbaiki (Perlu Direvisi). Catatan: {$notes}", $user['name']);
                flash('success', 'Lowongan dikembalikan ke pemberi kerja (Perlu Direvisi).');
            } elseif ($decision === 'reject') {
            $stmt = db()->prepare('UPDATE job_posts SET status = "Ditolak", admin_notes = ?, verifier_notes = ?, compliance_checklist = ? WHERE id = ?');
            $stmt->execute([$notes, $notes, $checklistJson, $jobId]);
            try {
                db()->prepare('UPDATE job_verifications SET status = "REJECTED", verifier_notes = ? WHERE job_id = ?')->execute([$notes, $jobId]);
            } catch (Throwable $ignored) {}
            record_audit_log('job', $jobId, 'REJECTED', "Lowongan Ditolak secara permanen. Catatan: {$notes}", $user['name']);
                flash('success', 'Lowongan Ditolak.');
            }

        redirect($redirectUrl);
        exit;
    }

    // 10. KEPUTUSAN VERIFIKASI DOKUMEN TAMBAHAN LOWONGAN KE-4+ KBJI SAMA
    if ($action === 'verify_additional_doc') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $decision = trim($_POST['decision'] ?? ''); // approve | reject
        $docReviewed = trim($_POST['doc_reviewed'] ?? '');
        $fieldVisit = trim($_POST['field_visit'] ?? '');
        $notes = trim($_POST['verifier_notes'] ?? '');
        $redirectUrl = 'admin.php?view=verifikasi_job&entity=' . urlencode($entity) . '&tab=' . urlencode($tab);

        // Fetch job + employer domicile / location
        $jobStmt = db()->prepare('SELECT j.*, ep.city as emp_city, ep.domicile_city_id as emp_domicile_city_id, u.name as user_name, u.email as user_email FROM job_posts j JOIN users u ON u.id = j.user_id LEFT JOIN employer_profiles ep ON ep.user_id = u.id WHERE j.id = ?');
        $jobStmt->execute([$jobId]);
        $targetJob = $jobStmt->fetch();

        if (!$targetJob) {
            flash('error', 'Lowongan tidak ditemukan.');
            redirect($redirectUrl);
            exit;
        }

        // 1. Check Scope: Admin Kab./Kota sesuai wilayah lokasi kerja lowongan atau domisili (case-insensitive)
        $adminDomicileCity = trim((string)($user['domicile_city_id'] ?? ''));
        $role = $user['role'] ?? 'admin';
        if ($role === 'admin_dinas' || ($adminDomicileCity !== '' && $role !== 'admin' && $role !== 'admin_pusat')) {
            $employerDomicileCity = trim((string)($targetJob['emp_domicile_city_id'] ?? ''));
            $empCity = trim((string)($targetJob['emp_city'] ?? ''));
            $jobLocation = trim((string)($targetJob['location'] ?? ''));
            $inScope = ($adminDomicileCity !== '') && (
                strcasecmp($employerDomicileCity, $adminDomicileCity) === 0 ||
                ($empCity !== '' && stripos($empCity, $adminDomicileCity) !== false) ||
                ($jobLocation !== '' && stripos($jobLocation, $adminDomicileCity) !== false)
            );
            if (!$inScope) {
                flash('error', 'Akses ditolak: Lowongan ini berada di luar wilayah kewenangan Dinas Anda (' . e($adminDomicileCity) . ').');
                redirect($redirectUrl . '&detail_id=' . $jobId);
                exit;
            }
        }

        // 2. Assignment: auto-assign to current admin if empty
        $assignedTo = (string)($targetJob['assigned_to'] ?? '');
        if ($assignedTo === '') {
            $assignedTo = (string)($user['name'] ?? 'Admin');
            try {
                db()->prepare('UPDATE job_posts SET assigned_to = ? WHERE id = ?')->execute([$assignedTo, $jobId]);
            } catch (Throwable $ignored) {}
        }

        // 3. Single-Final-Decision Locking Check
        $docStmt = db()->prepare('SELECT * FROM job_additional_documents WHERE job_id = ? ORDER BY id DESC LIMIT 1');
        $docStmt->execute([$jobId]);
        $currentDoc = $docStmt->fetch();

        if ($currentDoc && in_array($currentDoc['status'], ['APPROVED', 'REJECTED'], true)) {
            flash('error', 'Keputusan untuk Dokumen Tambahan lowongan ini sudah final dan terkunci. Perubahan keputusan tidak diizinkan.');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        // 4. Validate Checklist Inputs: Berkas telah ditinjau = Ya wajib
        if ($docReviewed !== 'Ya') {
            flash('error', 'Keputusan hanya dapat diberikan setelah opsi "Berkas telah ditinjau" dipilih "Ya".');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        if (!in_array($fieldVisit, ['Ya', 'Tidak'], true)) {
            flash('error', 'Pilihan Kunjungan Lapangan wajib diisi.');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        if ($decision === 'reject' && $notes === '') {
            flash('error', 'Catatan Admin wajib diisi jika menolak pengajuan.');
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowSql = $driver === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';

        try {
            db()->beginTransaction();

            if ($decision === 'reject') {
                // Tolak → Pengajuan dihentikan, status = Ditolak
                $stmtLock = db()->prepare('UPDATE job_posts SET status = "Ditolak", additional_doc_status = "REJECTED", admin_notes = ?, verifier_notes = ?, updated_at = ' . $nowSql . ' WHERE id = ?');
                $stmtLock->execute([$notes, $notes, $jobId]);

                $docUp = db()->prepare('UPDATE job_additional_documents SET status = "REJECTED", doc_reviewed = ?, field_visit = ?, admin_notes = ?, reviewed_at = ' . $nowSql . ' WHERE job_id = ?');
                $docUp->execute([$docReviewed, $fieldVisit, $notes, $jobId]);
                if ($docUp->rowCount() === 0) {
                    if ($driver === 'sqlite') {
                        $insertDoc = db()->prepare('INSERT OR REPLACE INTO job_additional_documents (job_id, user_id, kbji_code, document_file, description, status, doc_reviewed, field_visit, admin_notes, created_at, reviewed_at) VALUES (?, ?, ?, ?, ?, "REJECTED", ?, ?, ?, ' . $nowSql . ', ' . $nowSql . ')');
                    } else {
                        $insertDoc = db()->prepare('REPLACE INTO job_additional_documents (job_id, user_id, kbji_code, document_file, description, status, doc_reviewed, field_visit, admin_notes, created_at, reviewed_at) VALUES (?, ?, ?, ?, ?, "REJECTED", ?, ?, ?, ' . $nowSql . ', ' . $nowSql . ')');
                    }
                    $insertDoc->execute([$jobId, (int)$targetJob['user_id'], (string)$targetJob['kbji_code'], $targetJob['additional_doc_file'] ?? null, $targetJob['additional_doc_notes'] ?? '', $docReviewed, $fieldVisit, $notes]);
                }

                try {
                    db()->prepare('UPDATE job_verifications SET status = "REJECTED", verifier_notes = ? WHERE job_id = ?')
                        ->execute([$notes, $jobId]);
                } catch (Throwable $ignored) {}

                db()->commit();

                record_audit_log('job', $jobId, 'ADDITIONAL_DOC_REJECTED', "Dokumen/Keterangan Tambahan ditolak oleh Admin (Berkas ditinjau: {$docReviewed}, Kunjungan lapangan: {$fieldVisit}). Pengajuan dihentikan (Status: Ditolak). Catatan: {$notes}", $user['name']);
                notify_user((int)$targetJob['user_id'], 'Pengajuan Lowongan Ditolak', "Pengajuan lowongan '{$targetJob['title']}' ditolak pada tahap pemeriksaan Dokumen/Keterangan Tambahan. Alasan: {$notes}", 'danger', $jobId);
                flash('success', 'Dokumen/Keterangan Tambahan telah ditolak. Pengajuan lowongan dihentikan (Status: Ditolak).');

            } elseif ($decision === 'approve') {
                // Setujui & Lanjutkan: Jangan langsung mempublikasikan lowongan. Lanjut ke Lapisan 3.
                $startOfMonth = date('Y-m-01 00:00:00');
                $endOfMonth   = date('Y-m-t 23:59:59');

                $stmtL3 = db()->prepare('
                    SELECT COALESCE(SUM(quota), 0) FROM job_posts
                    WHERE user_id = ?
                      AND parent_job_id IS NULL
                      AND published_at IS NOT NULL
                      AND published_at BETWEEN ? AND ?
                      AND status NOT IN ("Draft", "Menunggu Verifikasi", "Dikirim/Menunggu Verifikasi", "Pending", "Perlu Direvisi", "Perlu Revisi", "Ditolak", "Dibatalkan", "CANCELED")
                      AND id != ?
                ');
                $stmtL3->execute([(int)$targetJob['user_id'], $startOfMonth, $endOfMonth, $jobId]);
                $currentMonthlyPublishedQuota = (int)$stmtL3->fetchColumn();
                $requestedQuota = max(1, (int)($targetJob['quota'] ?? 1));
                $totalMonthlyQuota = $currentMonthlyPublishedQuota + $requestedQuota;

                if ($totalMonthlyQuota > 10) {
                    // Lapisan 3 gagal: kuota bulanan > 10 orang → sistem menolak pengajuan
                    $rejectReason = "Batas maksimal kebutuhan tenaga kerja Pemberi Kerja Individu adalah 10 orang dalam satu bulan kalender. Total kuota pengajuan bulan ini: {$totalMonthlyQuota} orang.";
                    $fullNotes = $notes !== '' ? ($notes . ' | ' . $rejectReason) : $rejectReason;

                    $stmtLock = db()->prepare('UPDATE job_posts SET status = "Ditolak", additional_doc_status = "REJECTED_QUOTA", admin_notes = ?, verifier_notes = ?, updated_at = ' . $nowSql . ' WHERE id = ?');
                    $stmtLock->execute([$fullNotes, $fullNotes, $jobId]);

                    $docUp = db()->prepare('UPDATE job_additional_documents SET status = "REJECTED", doc_reviewed = ?, field_visit = ?, admin_notes = ?, reviewed_at = ' . $nowSql . ' WHERE job_id = ?');
                    $docUp->execute([$docReviewed, $fieldVisit, $fullNotes, $jobId]);
                    if ($docUp->rowCount() === 0) {
                        if ($driver === 'sqlite') {
                            $insertDoc = db()->prepare('INSERT OR REPLACE INTO job_additional_documents (job_id, user_id, kbji_code, document_file, description, status, doc_reviewed, field_visit, admin_notes, created_at, reviewed_at) VALUES (?, ?, ?, ?, ?, "REJECTED", ?, ?, ?, ' . $nowSql . ', ' . $nowSql . ')');
                        } else {
                            $insertDoc = db()->prepare('REPLACE INTO job_additional_documents (job_id, user_id, kbji_code, document_file, description, status, doc_reviewed, field_visit, admin_notes, created_at, reviewed_at) VALUES (?, ?, ?, ?, ?, "REJECTED", ?, ?, ?, ' . $nowSql . ', ' . $nowSql . ')');
                        }
                        $insertDoc->execute([$jobId, (int)$targetJob['user_id'], (string)$targetJob['kbji_code'], $targetJob['additional_doc_file'] ?? null, $targetJob['additional_doc_notes'] ?? '', $docReviewed, $fieldVisit, $fullNotes]);
                    }

                    try {
                        db()->prepare('UPDATE job_verifications SET status = "REJECTED", verifier_notes = ? WHERE job_id = ?')
                            ->execute([$fullNotes, $jobId]);
                    } catch (Throwable $ignored) {}

                    db()->commit();

                    record_audit_log('job', $jobId, 'ADDITIONAL_DOC_REJECTED_LAYER3', "Dokumen Tambahan ditinjau, namun evaluasi Rules Engine Lapisan 3 melebihi batas kuota bulanan ({$totalMonthlyQuota}/10 orang). Status lowongan diubah menjadi Ditolak.", $user['name']);
                    notify_user((int)$targetJob['user_id'], 'Pengajuan Lowongan Ditolak (Batas Kuota Bulanan)', "Lowongan '{$targetJob['title']}' ditolak karena batas maksimal kebutuhan tenaga kerja Pemberi Kerja Individu adalah 10 orang dalam satu bulan kalender (Total kuota diajukan: {$totalMonthlyQuota} orang).", 'danger', $jobId);
                    flash('warning', "Dokumen/Keterangan Tambahan ditinjau, namun total kuota lowongan bulan berjalan mencapai {$totalMonthlyQuota} orang (melebihi batas maksimal 10 orang per bulan kalender). Pengajuan lowongan diubah menjadi Ditolak.");

                } else {
                    // Lapisan 3 lolos: lowongan dilanjutkan ke proses Verifikasi Lowongan normal oleh Admin
                    $stmtLock = db()->prepare('UPDATE job_posts SET status = "Menunggu Verifikasi", additional_doc_status = "APPROVED", verifier_notes = ?, updated_at = ' . $nowSql . ' WHERE id = ?');
                    $stmtLock->execute([$notes, $jobId]);

                    $docUp = db()->prepare('UPDATE job_additional_documents SET status = "APPROVED", doc_reviewed = ?, field_visit = ?, admin_notes = ?, reviewed_at = ' . $nowSql . ' WHERE job_id = ?');
                    $docUp->execute([$docReviewed, $fieldVisit, $notes, $jobId]);
                    if ($docUp->rowCount() === 0) {
                        if ($driver === 'sqlite') {
                            $insertDoc = db()->prepare('INSERT OR REPLACE INTO job_additional_documents (job_id, user_id, kbji_code, document_file, description, status, doc_reviewed, field_visit, admin_notes, created_at, reviewed_at) VALUES (?, ?, ?, ?, ?, "APPROVED", ?, ?, ?, ' . $nowSql . ', ' . $nowSql . ')');
                        } else {
                            $insertDoc = db()->prepare('REPLACE INTO job_additional_documents (job_id, user_id, kbji_code, document_file, description, status, doc_reviewed, field_visit, admin_notes, created_at, reviewed_at) VALUES (?, ?, ?, ?, ?, "APPROVED", ?, ?, ?, ' . $nowSql . ', ' . $nowSql . ')');
                        }
                        $insertDoc->execute([$jobId, (int)$targetJob['user_id'], (string)$targetJob['kbji_code'], $targetJob['additional_doc_file'] ?? null, $targetJob['additional_doc_notes'] ?? '', $docReviewed, $fieldVisit, $notes]);
                    }

                    try {
                        db()->prepare('UPDATE job_verifications SET status = "PENDING", additional_doc_required = 1, layer_flags = "LAYER2_APPROVED_LAYER3_PASSED" WHERE job_id = ?')
                            ->execute([$jobId]);
                    } catch (Throwable $ignored) {}

                    db()->commit();

                    record_audit_log('job', $jobId, 'ADDITIONAL_DOC_APPROVED', "Dokumen/Keterangan Tambahan disetujui (Berkas ditinjau: {$docReviewed}, Kunjungan lapangan: {$fieldVisit}). Evaluasi Lapisan 3 lolos (total kuota: {$totalMonthlyQuota}/10 orang). Pengajuan dilanjutkan ke proses Verifikasi Lowongan normal.", $user['name']);
                    notify_user((int)$targetJob['user_id'], 'Dokumen Tambahan Disetujui', "Dokumen tambahan untuk lowongan '{$targetJob['title']}' telah disetujui Admin dan lolos evaluasi kuota bulanan. Lowongan kini berada dalam proses Verifikasi Lowongan normal.", 'success', $jobId);
                    flash('success', "Dokumen/Keterangan Tambahan berhasil disetujui dan lolos Lapisan 3 (Total kuota: {$totalMonthlyQuota}/10 orang). Silakan lanjutkan ke Verifikasi Lowongan normal menggunakan tombol Ambil Keputusan.");
                }

            } else {
                db()->rollBack();
                flash('error', 'Keputusan tidak valid. Gunakan tombol Setujui & Lanjutkan atau Tolak.');
                redirect($redirectUrl . '&detail_id=' . $jobId);
                exit;
            }

        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('[verify_additional_doc] Transaction failed: ' . $e->getMessage());
            flash('error', 'Terjadi kesalahan sistem saat memproses keputusan: ' . $e->getMessage());
            redirect($redirectUrl . '&detail_id=' . $jobId);
            exit;
        }

        redirect($redirectUrl . '&detail_id=' . $jobId);
        exit;
    }

    // 14. REAKTIVASI HAK AKSES PEMBERI KERJA INDIVIDU (ADMIN DINAS / CENTRAL ADMIN)
    if ($action === 'reactivate_employer_access') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $res = reactivate_employer_access_by_admin_dinas(db(), $targetUserId, $user);
        if (!$res['success']) {
            flash('error', $res['error']);
        } else {
            $_SESSION['reactivation_success_banner'] = [
                'activated_at' => $res['activated_at'],
                'active_until' => $res['active_until'],
                'name' => $res['employer_name'],
            ];
            flash('success', $res['message']);
        }
        redirect($redirectUrl);
        exit;
    }
}

// --- FETCH DATA FOR DIRECTORY INDIVIDUAL ---
if ($view === 'directory_individual') {
    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.phone, ep.whatsapp, ep.npwp, ep.profession, ep.address, ep.address_detail,
               ep.city, ep.province, ep.district, ep.village, ep.postal_code, ep.latitude, ep.longitude, ep.description,
               ep.brand_name, ep.business_logo,
               ep.verified, ep.verification_status, ep.suspension_reason, ep.extension_status, ep.verifier_notes, ep.verification_checklist,
               ep.manual_review_status, ep.assigned_to, ep.assigned_at, ep.rejection_count, ep.entity_type,
               ep.active_until, ep.last_activated_at, ep.domicile_city_id,
               ep.doc_permission, ep.permit_document, ep.doc_location_photo, ep.workplace_photo, ep.social_media, ep.instagram, ep.linkedin, ep.facebook
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.role = 'employer'
    SQL;
    $params = [];

    // Filter for Individual entity type
    $query .= ' AND (ep.entity_type = "Individu" OR ep.entity_type = "Individual" OR ep.entity_type IS NULL)';

    if ($search !== '') {
        $query .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ? OR ep.owner_name LIKE ? OR ep.brand_name LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like]);
    }

    if ($startDate !== '') {
        $query .= ' AND DATE(u.created_at) >= ?';
        $params[] = $startDate;
    }
    if ($endDate !== '') {
        $query .= ' AND DATE(u.created_at) <= ?';
        $params[] = $endDate;
    }
    if ($cityFilter !== '') {
        $query .= ' AND (ep.city LIKE ? OR ep.domicile_city_id LIKE ? OR ep.province LIKE ?)';
        $cityLike = '%' . $cityFilter . '%';
        $params = array_merge($params, [$cityLike, $cityLike, $cityLike]);
    }

    if ($tab === 'verified') {
        $query .= ' AND ((ep.verification_status IN ("APPROVED", "ACTIVE_VERIFIED") OR ep.verified = 1) AND COALESCE(ep.extension_status, "") != "REQUESTED")';
    } elseif ($tab === 'process') {
        $query .= ' AND (ep.extension_status = "REQUESTED" OR ep.extension_requested = 1 OR ep.verification_status = "REVERIFICATION_PENDING" OR (((ep.verification_status IN ("PENDING", "NOT_SUBMITTED", "SUBMITTED", "IN_PROCESS", "NEEDS_REVISION") OR ep.verification_status IS NULL OR ep.verified = 0)) AND ep.verification_status NOT IN ("APPROVED", "ACTIVE_VERIFIED", "REJECTED", "FULL_DISABLED")))';
    } elseif ($tab === 'rejected') {
        $query .= ' AND (ep.verification_status IN ("REJECTED", "FULL_DISABLED"))';
    }

    // Scope Admin Dinas Filter
    if ($user['role'] === 'admin_dinas' && !empty($user['domicile_city_id'])) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $query .= ' AND (ep.domicile_city_id = ? OR ep.city LIKE ? OR ep.domicile_city_id IS NULL OR ep.city IS NULL)';
            $params[] = $adminDomicileCity;
            $params[] = '%' . $adminDomicileCity . '%';
        }
    }

    $sort = $_GET['sort'] ?? 'date_desc';
    if ($sort === 'name_asc') {
        $query .= ' ORDER BY COALESCE(NULLIF(ep.brand_name, ""), NULLIF(ep.owner_name, ""), u.name) ASC';
    } elseif ($sort === 'name_desc') {
        $query .= ' ORDER BY COALESCE(NULLIF(ep.brand_name, ""), NULLIF(ep.owner_name, ""), u.name) DESC';
    } elseif ($sort === 'date_asc') {
        $query .= ' ORDER BY u.created_at ASC';
    } else {
        $query .= ' ORDER BY u.created_at DESC';
    }

    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $individualList = $stmt->fetchAll() ?: [];

    $perPage = 20;
    $totalData = count($individualList);
    $totalPages = max(1, (int)ceil($totalData / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $showingList = array_slice($individualList, ($page - 1) * $perPage, $perPage);
    $showingCount = count($showingList);

    // If detail_id is requested, find that employer
    $selectedEmployer = null;
    $auditLogs = [];
    if ($detailId > 0) {
        $stmtSel = db()->prepare('SELECT u.id as user_id, u.name, u.email, u.created_at, u.profile_complete, u.city as u_city, ep.* FROM users u LEFT JOIN employer_profiles ep ON ep.user_id = u.id WHERE u.id = ? LIMIT 1');
        $stmtSel->execute([$detailId]);
        $selectedEmployer = $stmtSel->fetch();
        if ($selectedEmployer) {
            $auditLogs = fetch_audit_logs('employer', $detailId);
        }
    }
}

// --- FETCH DATA FOR VERIFIKASI PEMBERI KERJA ---
if ($view === 'verifikasi_employer') {
    ensure_profile_change_schema();
    $condOnlineReverifSql = '(ep.extension_status = "REQUESTED" OR ep.extension_requested = 1 OR ep.verification_status = "REVERIFICATION_PENDING" OR (ep.last_activated_at IS NOT NULL AND ep.verification_status = "PENDING"))';
    $condOfflineReverifSql = '(ep.last_activated_at IS NOT NULL AND (ep.verification_status IN ("TRANSITION_LIMITED", "FULL_DISABLED") OR (ep.active_until IS NOT NULL AND ep.active_until <= DATE_ADD(NOW(), INTERVAL 7 DAY))) AND COALESCE(ep.extension_status, "") != "REQUESTED")';
    $condPcrPendingSql = '((SELECT COUNT(*) FROM profile_change_requests pcr WHERE pcr.user_id = u.id AND pcr.status = "PENDING") > 0)';

    $query = <<<SQL
        SELECT u.id as user_id, u.name, u.email, u.created_at,
               ep.id as profile_id, ep.owner_name, ep.nik, ep.profession, ep.phone, ep.whatsapp, ep.npwp,
               ep.province, ep.city, ep.district, ep.village, ep.postal_code, ep.address, ep.address_detail,
               ep.latitude, ep.longitude, ep.description, ep.brand_name, ep.business_logo, ep.verified, ep.verification_status, ep.verifier_notes,
               ep.verification_checklist, ep.assigned_to, ep.assigned_at, ep.assignment_reason, ep.rejection_count, ep.rejection_count AS revision_count,
               ep.manual_review_status, ep.consent_data_hash, ep.consent_given_at, ep.officer_statement, ep.officer_name, ep.entity_type,
               ep.doc_permission, ep.permit_document, ep.doc_location_photo, ep.workplace_photo, ep.social_media, ep.instagram, ep.linkedin, ep.facebook, ep.tiktok, ep.domicile_city_id,
               ep.verification_method, ep.location_check_result, ep.updated_at,
               ep.extension_status, ep.extension_requested, ep.last_activated_at, ep.active_until,
               ep.same_location_siapkerja, ep.domicile_address, ep.domicile_province, ep.domicile_city, ep.domicile_district, ep.domicile_village, ep.domicile_postal_code,
               ep.workplace_same_as_domicile, ep.workplace_address, ep.workplace_province, ep.workplace_city, ep.workplace_district, ep.workplace_village, ep.workplace_postal_code, ep.workplace_detail,
               (SELECT COUNT(*) FROM profile_change_requests pcr WHERE pcr.user_id = u.id AND pcr.status = 'PENDING') AS pending_profile_changes,
               (SELECT id FROM profile_change_requests pcr WHERE pcr.user_id = u.id AND pcr.status = 'PENDING' ORDER BY id DESC LIMIT 1) AS pending_pcr_id
    SQL;
    $baseFilter = <<<SQL
        FROM users u
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE u.role = 'employer'
    SQL;
    $paramsCommon = [];

    $entityFilter = '';
    $entityParams = [];
    if ($entity === 'Individu' || $entity === 'Individual') {
        $entityFilter = ' AND (ep.entity_type = "Individu" OR ep.entity_type = "Individual" OR ep.entity_type IS NULL)';
    } elseif ($entity === 'Perusahaan') {
        $entityFilter = ' AND ep.entity_type = "Perusahaan"';
    }

    if ($search !== '') {
        $baseFilter .= ' AND (u.name LIKE ? OR u.email LIKE ? OR ep.owner_name LIKE ? OR ep.brand_name LIKE ? OR ep.phone LIKE ? OR ep.city LIKE ? OR ep.address LIKE ? OR ep.npwp LIKE ?)';
        $like = '%' . $search . '%';
        $paramsCommon = array_merge($paramsCommon, [$like, $like, $like, $like, $like, $like, $like, $like]);
    }

    if ($startDate !== '') {
        $baseFilter .= ' AND DATE(u.created_at) >= ?';
        $paramsCommon[] = $startDate;
    }
    if ($endDate !== '') {
        $baseFilter .= ' AND DATE(u.created_at) <= ?';
        $paramsCommon[] = $endDate;
    }
    if ($cityFilter !== '') {
        $baseFilter .= ' AND (ep.city LIKE ? OR ep.province LIKE ? OR ep.district LIKE ? OR ep.address LIKE ?)';
        $cityLike = '%' . $cityFilter . '%';
        $paramsCommon = array_merge($paramsCommon, [$cityLike, $cityLike, $cityLike, $cityLike]);
    }
    if ($verifierFilter !== '') {
        $baseFilter .= ' AND (ep.assigned_to LIKE ? OR ep.verifier_notes LIKE ?)';
        $vLike = '%' . $verifierFilter . '%';
        $paramsCommon = array_merge($paramsCommon, [$vLike, $vLike]);
    }
    if ($officerFilter !== '') {
        $baseFilter .= ' AND ep.officer_name LIKE ?';
        $paramsCommon[] = '%' . $officerFilter . '%';
    }
    if ($unassignedFilter === 1) {
        $baseFilter .= ' AND (ep.assigned_to IS NULL OR ep.assigned_to = "")';
    }

    $tabFilter = '';
    $tabParams = [];
    if ($tab === 'process') {
        $tabFilter = " AND (ep.verification_status = 'PENDING' OR ep.verification_status = 'NOT_SUBMITTED' OR ep.verification_status IS NULL OR {$condOnlineReverifSql} OR {$condOfflineReverifSql} OR {$condPcrPendingSql})";
    } elseif ($tab === 'approved') {
        $tabFilter = " AND (ep.verification_status = 'APPROVED' OR ep.verification_status = 'ACTIVE_VERIFIED') AND NOT {$condOnlineReverifSql} AND NOT {$condPcrPendingSql}";
    } elseif ($tab === 'revision') {
        $tabFilter = " AND ep.verification_status = 'NEEDS_REVISION'";
    } elseif ($tab === 'rejected') {
        $tabFilter = " AND ep.verification_status = 'REJECTED'";
    }

    // Scope Admin Dinas Filter (exact domicile_city_id match)
    if ($user['role'] === 'admin_dinas' || (!empty($user['domicile_city_id']) && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $baseFilter .= ' AND (ep.city LIKE ? OR ep.domicile_city_id = ? OR ep.domicile_city_id IS NULL OR ep.city IS NULL OR ep.city = "")';
            $paramsCommon[] = '%' . $adminDomicileCity . '%';
            $paramsCommon[] = $adminDomicileCity;
        }
    }

    $sort = $_GET['sort'] ?? 'date_desc';
    $orderBy = ' ORDER BY u.created_at DESC';
    if ($sort === 'name_asc') {
        $orderBy = ' ORDER BY COALESCE(NULLIF(ep.brand_name, ""), NULLIF(ep.owner_name, ""), u.name) ASC';
    } elseif ($sort === 'name_desc') {
        $orderBy = ' ORDER BY COALESCE(NULLIF(ep.brand_name, ""), NULLIF(ep.owner_name, ""), u.name) DESC';
    } elseif ($sort === 'date_asc') {
        $orderBy = ' ORDER BY u.created_at ASC';
    }

    $query .= $baseFilter . $entityFilter . $tabFilter . $orderBy;
    $params = array_merge($paramsCommon, $entityParams, $tabParams);
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationEmployers = $stmt->fetchAll() ?: [];

    // Counts for status tabs, preserving entity and active filter/search scope.
    $statusCountSql = <<<SQL
        SELECT
            COUNT(*) AS all_count,
            SUM(CASE WHEN (ep.verification_status = "PENDING" OR ep.verification_status = "NOT_SUBMITTED" OR ep.verification_status IS NULL OR {$condOnlineReverifSql} OR {$condOfflineReverifSql} OR {$condPcrPendingSql}) THEN 1 ELSE 0 END) AS process_count,
            SUM(CASE WHEN ep.verification_status = "NEEDS_REVISION" THEN 1 ELSE 0 END) AS revision_count,
            SUM(CASE WHEN (ep.verification_status = "APPROVED" OR ep.verification_status = "ACTIVE_VERIFIED") AND NOT {$condOnlineReverifSql} AND NOT {$condPcrPendingSql} THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN ep.verification_status = "REJECTED" THEN 1 ELSE 0 END) AS rejected_count
        {$baseFilter}
        {$entityFilter}
    SQL;
    $stmtStatusCounts = db()->prepare($statusCountSql);
    $stmtStatusCounts->execute(array_merge($paramsCommon, $entityParams));
    $statusCountsRow = $stmtStatusCounts->fetch() ?: [];
    $verificationStatusCounts = [
        'all' => (int)($statusCountsRow['all_count'] ?? 0),
        'process' => (int)($statusCountsRow['process_count'] ?? 0),
        'revision' => (int)($statusCountsRow['revision_count'] ?? 0),
        'approved' => (int)($statusCountsRow['approved_count'] ?? 0),
        'rejected' => (int)($statusCountsRow['rejected_count'] ?? 0),
    ];

    // Counts for entity pills, preserving active tab and active filter/search scope.
    $entityCountSql = <<<SQL
        SELECT
            COUNT(*) AS all_entities_count,
            SUM(CASE WHEN ep.entity_type = "Perusahaan" THEN 1 ELSE 0 END) AS perusahaan_count,
            SUM(CASE WHEN (ep.entity_type = "Individu" OR ep.entity_type = "Individual" OR ep.entity_type IS NULL) THEN 1 ELSE 0 END) AS individual_count
        {$baseFilter}
        {$tabFilter}
    SQL;
    $stmtEntityCounts = db()->prepare($entityCountSql);
    $stmtEntityCounts->execute(array_merge($paramsCommon, $tabParams));
    $entityCountsRow = $stmtEntityCounts->fetch() ?: [];
    $verificationEntityCounts = [
        'Semua' => (int)($entityCountsRow['all_entities_count'] ?? 0),
        'Perusahaan' => (int)($entityCountsRow['perusahaan_count'] ?? 0),
        'Individu' => (int)($entityCountsRow['individual_count'] ?? 0),
    ];

    $perPage = 20;
    $totalData = count($verificationEmployers);
    $totalPages = max(1, (int)ceil($totalData / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;
    $showingEmployers = array_slice($verificationEmployers, $offset, $perPage);

    // If detail_id is requested
    $selectedEmployer = null;
    $auditLogs = [];
    $activeProfileChange = null;
    $profileVersions = [];
    if ($detailId > 0) {
        $reqProfileId = (int)($_GET['profile_id'] ?? 0);
        if ($reqProfileId > 0) {
            $stmtSel = db()->prepare('SELECT u.id as user_id, u.name, u.email, u.created_at, ep.*,
                (SELECT COUNT(*) FROM profile_change_requests pcr WHERE pcr.user_id = u.id AND pcr.status = "PENDING") AS pending_profile_changes
                FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE ep.id = ? LIMIT 1');
            $stmtSel->execute([$reqProfileId]);
        } else {
            $stmtSel = db()->prepare('SELECT u.id as user_id, u.name, u.email, u.created_at, ep.*,
                (SELECT COUNT(*) FROM profile_change_requests pcr WHERE pcr.user_id = u.id AND pcr.status = "PENDING") AS pending_profile_changes
                FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE ep.id = ? OR ep.user_id = ? ORDER BY (CASE WHEN ep.id = ? THEN 1 ELSE 2 END) LIMIT 1');
            $stmtSel->execute([$detailId, $detailId, $detailId]);
        }
        $selectedEmployer = $stmtSel->fetch();
        if ($selectedEmployer) {
            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($selectedEmployer['domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $selectedEmployer = null;
                }
            }
            if ($selectedEmployer) {
                $auditLogs = fetch_audit_logs('employer', $detailId);
                $activeProfileChange = function_exists('get_active_profile_change_request') ? get_active_profile_change_request((int)$selectedEmployer['user_id']) : null;
                $profileVersions = function_exists('get_profile_versions') ? get_profile_versions((int)$selectedEmployer['user_id']) : [];
            }
        }
    }
}

// --- FETCH DATA FOR VERIFIKASI LOWONGAN ---
if ($view === 'verifikasi_job') {
    $query = <<<SQL
        SELECT j.*, ep.owner_name, ep.brand_name, ep.profession, ep.city as emp_city, ep.province as emp_province, u.name as user_name, u.email as user_email
    SQL;
    $baseFilter = <<<SQL
        FROM job_posts j
        JOIN users u ON u.id = j.user_id
        LEFT JOIN employer_profiles ep ON ep.user_id = u.id
        WHERE j.status NOT IN ("Draft", "draft", "Ditutup", "ditutup", "Closed", "closed")
    SQL;
    $paramsCommon = [];

    $entityFilter = '';
    $entityParams = [];
    if ($entity === 'Individu' || $entity === 'Individual') {
        $entityFilter = ' AND (j.entity_type = "Individu" OR j.entity_type = "Individual" OR j.entity_type IS NULL)';
    } elseif ($entity === 'Perusahaan') {
        $entityFilter = ' AND j.entity_type = "Perusahaan"';
    }

    if ($search !== '') {
        $baseFilter .= ' AND (j.title LIKE ? OR j.location LIKE ? OR j.kbji_code LIKE ? OR u.name LIKE ? OR ep.owner_name LIKE ? OR ep.brand_name LIKE ?)';
        $like = '%' . $search . '%';
        $paramsCommon = array_merge($paramsCommon, [$like, $like, $like, $like, $like, $like]);
    }

    if ($startDate !== '') {
        $baseFilter .= ' AND DATE(j.created_at) >= ?';
        $paramsCommon[] = $startDate;
    }
    if ($endDate !== '') {
        $baseFilter .= ' AND DATE(j.created_at) <= ?';
        $paramsCommon[] = $endDate;
    }
    if ($cityFilter !== '') {
        $baseFilter .= ' AND (j.location LIKE ? OR ep.city LIKE ? OR ep.domicile_city_id LIKE ? OR ep.province LIKE ?)';
        $cityLike = '%' . $cityFilter . '%';
        $paramsCommon = array_merge($paramsCommon, [$cityLike, $cityLike, $cityLike, $cityLike]);
    }

    $tabFilter = '';
    $tabParams = [];
    if ($tab === 'process') {
        $tabFilter = ' AND j.status = "Menunggu Verifikasi"';
    } elseif ($tab === 'revision') {
        $tabFilter = ' AND j.status = "Perlu Direvisi"';
    } elseif ($tab === 'approved') {
        $tabFilter = ' AND j.status = "Tayang"';
    } elseif ($tab === 'rejected') {
        $tabFilter = ' AND (j.status = "Ditolak" OR j.status = "CANCELED")';
    } elseif ($tab === 'additional_doc') {
        $tabFilter = ' AND j.status = "ADDITIONAL_DOCUMENT_PENDING"';
    }

    // Admin Dinas Scope Filter (exact domicile_city_id match)
    if ($user['role'] === 'admin_dinas' || (!empty($user['domicile_city_id']) && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $baseFilter .= ' AND ep.domicile_city_id = ?';
            $paramsCommon[] = $adminDomicileCity;
        }
    }

    $sort = $_GET['sort'] ?? 'date_desc';
    $orderBy = ' ORDER BY j.created_at DESC';
    if ($sort === 'name_asc') {
        $orderBy = ' ORDER BY j.title ASC';
    } elseif ($sort === 'name_desc') {
        $orderBy = ' ORDER BY j.title DESC';
    } elseif ($sort === 'date_asc') {
        $orderBy = ' ORDER BY j.created_at ASC';
    }

    $query .= $baseFilter . $entityFilter . $tabFilter . $orderBy;
    $params = array_merge($paramsCommon, $entityParams, $tabParams);
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    $verificationJobs = $stmt->fetchAll() ?: [];

    // Counts for status tabs, preserving entity and active filter/search scope.
    $statusCountSql = <<<SQL
        SELECT
            COUNT(*) AS all_count,
            SUM(CASE WHEN j.status = "Menunggu Verifikasi" THEN 1 ELSE 0 END) AS process_count,
            SUM(CASE WHEN j.status = "Perlu Direvisi" THEN 1 ELSE 0 END) AS revision_count,
            SUM(CASE WHEN j.status = "Tayang" THEN 1 ELSE 0 END) AS approved_count,
            SUM(CASE WHEN (j.status = "Ditolak" OR j.status = "CANCELED") THEN 1 ELSE 0 END) AS rejected_count
        {$baseFilter}
        {$entityFilter}
    SQL;
    $stmtStatusCounts = db()->prepare($statusCountSql);
    $stmtStatusCounts->execute(array_merge($paramsCommon, $entityParams));
    $statusCountsRow = $stmtStatusCounts->fetch() ?: [];
    $jobStatusCounts = [
        'all' => (int)($statusCountsRow['all_count'] ?? 0),
        'process' => (int)($statusCountsRow['process_count'] ?? 0),
        'revision' => (int)($statusCountsRow['revision_count'] ?? 0),
        'approved' => (int)($statusCountsRow['approved_count'] ?? 0),
        'rejected' => (int)($statusCountsRow['rejected_count'] ?? 0),
    ];

    // Counts for entity pills, preserving active tab and active filter/search scope.
    $entityCountSql = <<<SQL
        SELECT
            COUNT(*) AS all_entities_count,
            SUM(CASE WHEN j.entity_type = "Perusahaan" THEN 1 ELSE 0 END) AS perusahaan_count,
            SUM(CASE WHEN (j.entity_type = "Individu" OR j.entity_type = "Individual" OR j.entity_type IS NULL) THEN 1 ELSE 0 END) AS individual_count
        {$baseFilter}
        {$tabFilter}
    SQL;
    $stmtEntityCounts = db()->prepare($entityCountSql);
    $stmtEntityCounts->execute(array_merge($paramsCommon, $tabParams));
    $entityCountsRow = $stmtEntityCounts->fetch() ?: [];
    $jobEntityCounts = [
        'Semua' => (int)($entityCountsRow['all_entities_count'] ?? 0),
        'Perusahaan' => (int)($entityCountsRow['perusahaan_count'] ?? 0),
        'Individu' => (int)($entityCountsRow['individual_count'] ?? 0),
    ];

    $perPage = 20;
    $totalData = count($verificationJobs);
    $totalPages = max(1, (int)ceil($totalData / $perPage));
    $page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
    $showingJobs = array_slice($verificationJobs, ($page - 1) * $perPage, $perPage);
    $showingCount = count($showingJobs);

    // If detail_id is requested for job
    $selectedJob = null;
    $auditLogs = [];
    $additionalDocCase = null;
    if ($detailId > 0) {
        $stmtSel = db()->prepare('SELECT j.*, ep.owner_name, ep.brand_name, ep.profession, ep.city as emp_city, ep.domicile_city_id as emp_domicile_city_id, ep.phone, ep.whatsapp, ep.social_media, ep.instagram, ep.address, u.name as user_name, u.email as user_email FROM job_posts j JOIN users u ON u.id = j.user_id LEFT JOIN employer_profiles ep ON ep.user_id = u.id WHERE j.id = ? LIMIT 1');
        $stmtSel->execute([$detailId]);
        $selectedJob = $stmtSel->fetch();
        if ($selectedJob) {
            $stCheck = strtolower((string)($selectedJob['status'] ?? ''));
            if ($stCheck === 'draft' || $stCheck === 'ditutup' || $stCheck === 'closed') {
                $selectedJob = null;
            }
        }
        if ($selectedJob) {
            $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
            if ($user['role'] === 'admin_dinas' || ($adminDomicileCity !== '' && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
                $empDomicileCity = (string)($selectedJob['emp_domicile_city_id'] ?? '');
                if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                    $selectedJob = null;
                }
            }
            if ($selectedJob) {
                $auditLogs = fetch_audit_logs('job', $detailId);
                $docStmt = db()->prepare('SELECT * FROM job_additional_documents WHERE job_id = ? ORDER BY id DESC LIMIT 1');
                $docStmt->execute([$detailId]);
                $additionalDocCase = $docStmt->fetch();
            }
        }
    }
}

$unread = unread_notification_count((int) $user['id']);
$notifications = user_notifications((int) $user['id']);

// Trigger notifikasi bell jika ada pengajuan pemberi kerja (baru maupun verifikasi ulang) yang belum diverifikasi dan melewati batas SLA 3 hari
$totalOverdueCount = 0;
$overdueInitialCount = 0;
$overdueReverifCount = 0;
try {
    $slaLimitDate = date('Y-m-d H:i:s', time() - (3 * 86400));
    
    $dinasFilterSql = '';
    $dinasFilterParams = [];
    if ($user['role'] === 'admin_dinas' || (!empty($user['domicile_city_id']) && $user['role'] !== 'admin' && $user['role'] !== 'admin_pusat')) {
        $adminDomicileCity = (string)($user['domicile_city_id'] ?? '');
        if ($adminDomicileCity !== '') {
            $dinasFilterSql = ' AND (ep.city LIKE ? OR ep.domicile_city_id = ? OR ep.domicile_city_id IS NULL OR ep.city IS NULL OR ep.city = "")';
            $dinasFilterParams = ['%' . $adminDomicileCity . '%', $adminDomicileCity];
        }
    }

    // 1. Pengajuan pertama kali yang overdue
    $stmtOverdueInitial = db()->prepare('
        SELECT COUNT(*) 
        FROM employer_profiles ep 
        JOIN users u ON u.id = ep.user_id 
        WHERE u.role = "employer"
          AND (ep.extension_status IS NULL OR ep.extension_status != "REQUESTED")
          AND (ep.extension_requested IS NULL OR ep.extension_requested = 0)
          AND (ep.last_activated_at IS NULL OR ep.last_activated_at = "")
          AND (ep.verification_status NOT IN ("APPROVED", "REJECTED", "ACTIVE_VERIFIED", "FULL_DISABLED", "NEEDS_REVISION", "REVISION") OR ep.verification_status IS NULL)
          AND (
              (ep.updated_at IS NOT NULL AND ep.updated_at != "" AND ep.updated_at <= ?)
              OR ((ep.updated_at IS NULL OR ep.updated_at = "") AND u.created_at <= ?)
          )
          ' . $dinasFilterSql . '
    ');
    $stmtOverdueInitial->execute(array_merge([$slaLimitDate, $slaLimitDate], $dinasFilterParams));
    $overdueInitialCount = (int)$stmtOverdueInitial->fetchColumn();

    // 2. Pengajuan Verifikasi Ulang Profil yang overdue (SLA 3 hari sejak diajukan verifikasi ulang / updated_at)
    $stmtOverdueReverif = db()->prepare('
        SELECT COUNT(*) 
        FROM employer_profiles ep 
        JOIN users u ON u.id = ep.user_id 
        WHERE u.role = "employer"
          AND (
              ep.extension_status = "REQUESTED" 
              OR ep.extension_requested = 1 
              OR ep.verification_status = "REVERIFICATION_PENDING"
              OR (ep.last_activated_at IS NOT NULL AND ep.verification_status = "PENDING")
          )
          AND (ep.verification_status NOT IN ("REJECTED") OR ep.verification_status IS NULL)
          AND (
              (ep.updated_at IS NOT NULL AND ep.updated_at != "" AND ep.updated_at <= ?)
              OR ((ep.updated_at IS NULL OR ep.updated_at = "") AND u.created_at <= ?)
          )
          ' . $dinasFilterSql . '
    ');
    $stmtOverdueReverif->execute(array_merge([$slaLimitDate, $slaLimitDate], $dinasFilterParams));
    $overdueReverifCount = (int)$stmtOverdueReverif->fetchColumn();

    $totalOverdueCount = $overdueInitialCount + $overdueReverifCount;

    if ($totalOverdueCount > 0) {
        $unread += 1;
        
        if ($overdueInitialCount > 0 && $overdueReverifCount > 0) {
            $alertTitle = 'Peringatan SLA Verifikasi (' . $totalOverdueCount . ' Pengajuan)';
            $alertMessage = 'Terdapat ' . $overdueInitialCount . ' verifikasi baru dan ' . $overdueReverifCount . ' verifikasi ulang profil yang belum diproses dan telah melewati batas SLA 3 hari. Segera lakukan verifikasi.';
        } elseif ($overdueReverifCount > 0) {
            $alertTitle = 'Peringatan SLA Verifikasi Ulang (' . $overdueReverifCount . ' Pengajuan)';
            $alertMessage = 'Terdapat ' . $overdueReverifCount . ' pengajuan verifikasi ulang profil yang belum diproses dan telah melewati batas SLA 3 hari. Segera lakukan verifikasi.';
        } else {
            $alertTitle = 'Peringatan SLA Verifikasi (' . $overdueInitialCount . ' Pengajuan)';
            $alertMessage = 'Terdapat ' . $overdueInitialCount . ' pengajuan verifikasi pemberi kerja baru yang belum diproses dan telah melewati batas SLA 3 hari. Segera lakukan verifikasi.';
        }

        array_unshift($notifications, [
            'id' => 'sla_overdue_alert',
            'user_id' => $user['id'],
            'title' => $alertTitle,
            'message' => $alertMessage,
            'type' => 'SLA_OVERDUE_ALERT',
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
} catch (Throwable $e) {
    // Graceful fallback
}
$adminInitial = strtoupper(mb_substr($user['name'], 0, 1));

// Overall KPIs for Admin Overview
$statTotalEmployers = (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "employer"')->fetchColumn();
$statPendingEmployers = (int) db()->query('SELECT COUNT(*) FROM employer_profiles WHERE verification_status = "PENDING"')->fetchColumn();
$statPendingJobs = (int) db()->query('SELECT COUNT(*) FROM job_posts WHERE status = "Menunggu Disetujui"')->fetchColumn();
$statTotalSeekers = (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "seeker"')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Karirhub - Admin Pusat</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css?v=admin-std-1">
    <link rel="stylesheet" href="assets/pki-form.css?v=admin-std-1">
    <style>
        .detail-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: nowrap;
            gap: 16px;
        }
        .detail-grid-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .detail-grid-container { grid-template-columns: 1fr; }
        }
        .key-val-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 20px;
            font-size: 13px;
        }
        .key-val-item .label { font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 2px; text-transform: uppercase; }
        .key-val-item .value { font-weight: 600; color: #0f172a; }
        .timeline-list { position: relative; padding-left: 20px; margin-top: 10px; }
        .timeline-list::before {
            content: '';
            position: absolute;
            left: 5px;
            top: 6px;
            bottom: 6px;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-item { position: relative; margin-bottom: 18px; }
        .timeline-dot {
            position: absolute;
            left: -19px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #30aed8;
            border: 2px solid #ffffff;
            box-shadow: 0 0 0 2px #bae6fd;
        }
        .timeline-time { font-size: 11px; color: #94a3b8; margin-bottom: 2px; }
        .timeline-title { font-size: 12px; font-weight: 700; color: #1e293b; }
        .timeline-desc { font-size: 12px; color: #475569; margin-top: 2px; line-height: 1.4; }
        .compare-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .compare-table th { background: #f8fafc; padding: 10px 12px; color: #64748b; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
        .compare-table td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; color: #334155; }
        .map-box-placeholder {
            height: 160px;
            background: #f1f5f9;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 13px;
            margin-top: 12px;
            border: 1px dashed #cbd5e1;
        }
        .tab-filter-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 14px; }
        .status-tab-list { display: flex; gap: 20px; }
        .status-tab-item {
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            padding-bottom: 8px;
            position: relative;
            transition: all 0.2s;
        }
        .status-tab-item:hover { color: var(--primary-strong); }
        .status-tab-item.active { color: var(--primary-strong); font-weight: 700; }
        .status-tab-item.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
            border-radius: 2px;
        }
        .filter-controls { display: flex; align-items: center; gap: 10px; }
        .filter-search-box {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0 12px;
            height: 38px;
            width: 240px;
        }
        .filter-search-box input { border: none; outline: none; width: 100%; font-size: 13px; margin-left: 8px; background: transparent; }
        .entity-selector-pill {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 10px;
            padding: 3px;
            gap: 3px;
        }
        .entity-selector-btn {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-decoration: none;
            transition: all 0.15s;
        }
        .entity-selector-btn.active {
            background: #ffffff;
            color: var(--primary-strong);
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        }
        .pill-badge.disabled {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }
        .pill-badge.warning {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }
        .pill-badge.neutral {
            background: #f8fafc;
            color: #94a3b8;
            border: 1px solid #e2e8f0;
        }
        .action-dropdown { position: relative; display: inline-block; }
        .action-menu-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 4px);
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            min-width: 195px;
            z-index: 50;
            padding: 6px 0;
            text-align: left;
        }
        .action-menu-dropdown.show { display: block; }
    </style>
</head>
<body>
<div class="app-layout-wrapper">
    <!-- NARROW SIDEBAR RAIL (60px) -->
    <aside class="sidebar-rail">
        <!-- Top Logo Icon -->
        <a href="admin.php" class="sidebar-rail-logo" title="Karirhub - Admin Pusat">
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
            <a href="admin.php?view=directory_individual" class="rail-btn <?php echo str_starts_with($view, 'directory') ? 'active' : ''; ?>" title="Individual" style="text-decoration:none;">
                <i class="fa-solid fa-user"></i>
            </a>
            <a href="admin.php?view=verifikasi_employer&entity=Individu" class="rail-btn <?php echo str_starts_with($view, 'verifikasi_employer') ? 'active' : ''; ?>" title="Verifikasi Pemberi Kerja" style="text-decoration:none;">
                <i class="fa-solid fa-user-check"></i>
            </a>
            <a href="admin.php?view=verifikasi_job&entity=Individu" class="rail-btn <?php echo str_starts_with($view, 'verifikasi_job') ? 'active' : ''; ?>" title="Verifikasi Lowongan" style="text-decoration:none;">
                <i class="fa-solid fa-briefcase"></i>
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
                <button type="button" class="rail-avatar-btn" id="sidebarAvatar" title="Akun Pengguna"><?php echo e($adminInitial ?: 'AD'); ?></button>
                <div class="rail-popover rail-account-popover" id="railAccountPopover">
                    <div class="rail-account-info">
                        <div class="rail-account-name"><?php echo e($user['name']); ?></div>
                        <div class="rail-account-email"><?php echo e($user['email']); ?></div>
                    </div>
                    <div class="rail-popover-divider"></div>
                    <div class="rail-popover-list">
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
                <p>Admin Pusat</p>
            </div>
            <button type="button" class="drawer-close-btn" id="drawerCloseBtn" title="Tutup">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>

        <div class="drawer-menu-list">
            <a href="admin.php?view=directory_individual" class="drawer-menu-item <?php echo str_starts_with($view, 'directory') ? 'active' : ''; ?>" style="text-decoration:none;">
                <i class="fa-solid fa-user"></i>
                <span style="text-decoration:none;">Individual</span>
            </a>
            <a href="admin.php?view=verifikasi_employer&entity=Individu" class="drawer-menu-item <?php echo str_starts_with($view, 'verifikasi_employer') ? 'active' : ''; ?>" style="text-decoration:none;">
                <i class="fa-solid fa-user-check"></i>
                <span style="text-decoration:none;">Verifikasi Pemberi Kerja</span>
            </a>
            <a href="admin.php?view=verifikasi_job&entity=Individu" class="drawer-menu-item <?php echo str_starts_with($view, 'verifikasi_job') ? 'active' : ''; ?>" style="text-decoration:none;">
                <i class="fa-solid fa-briefcase"></i>
                <span style="text-decoration:none;">Verifikasi Lowongan</span>
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
                <?php if ($view === 'directory_individual'): ?>
                    <a href="admin.php?view=directory_individual" style="color:inherit;text-decoration:none;">Individual</a>
                    <?php if ($selectedEmployer): ?>
                        <span class="sep">&gt;</span>
                        <strong id="crumbCurrent">#<?php echo substr(md5($selectedEmployer['user_id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php elseif ($view === 'verifikasi_employer'): ?>
                    <a href="admin.php?view=verifikasi_employer" style="color:inherit;text-decoration:none;">Verifikasi Pemberi Kerja</a>
                    <?php if ($selectedEmployer): ?>
                        <span class="sep">&gt;</span>
                        <strong id="crumbCurrent">#<?php echo substr(md5($selectedEmployer['user_id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="admin.php?view=verifikasi_job" style="color:inherit;text-decoration:none;">Verifikasi Lowongan</a>
                    <?php if ($selectedJob): ?>
                        <span class="sep">&gt;</span>
                        <strong id="crumbCurrent">#<?php echo substr(md5($selectedJob['id']), 0, 8); ?></strong>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="topbar-search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <form method="get" action="admin.php" style="width:100%;margin:0;display:flex;">
                    <input type="hidden" name="view" value="<?php echo e($view); ?>">
                    <input type="hidden" name="entity" value="<?php echo e($entity); ?>">
                    <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan, pemberi kerja, pencari kerja, atau..." style="border:none;outline:none;width:100%;background:transparent;font-size:13px;color:var(--text-dark, #0f172a);">
                </form>
            </div>

            <div class="topbar-right-actions">
                <?php echo render_notif_dropdown($notifications, $unread); ?>

                <div class="company-profile-pill" onclick="toggleAccountMenu(event)" title="Pengaturan Akun Admin">
                    <div class="company-pill-avatar" style="background:#0284c7;color:#ffffff;"><?php echo e($adminInitial ?: 'AD'); ?></div>
                    <div class="company-pill-text">
                        <strong><?php echo e($user['name']); ?></strong>
                        <span>Admin Pusat</span>
                    </div>
                    <i class="fa-solid fa-chevron-right company-pill-arrow"></i>
                </div>
            </div>
        </header>

        <!-- CONTENT AREA -->
        <div class="content" style="flex:1; overflow-y:auto; background:#f8fafc;">
            <div class="page active">
                <?php if (!empty($_SESSION['reactivation_success_banner'])):
                    $rBanner = $_SESSION['reactivation_success_banner'];
                    unset($_SESSION['reactivation_success_banner']);
                ?>
                    <div style="background:#ecfdf5; border:1px solid #6ee7b7; border-radius:12px; padding:20px; margin-bottom:20px; color:#065f46; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <div style="display:flex; align-items:center; gap:10px; font-weight:700; font-size:16px; margin-bottom:6px;">
                            <i class="fa-solid fa-circle-check" style="color:#059669; font-size:20px;"></i>
                            Hak Akses berhasil direaktivasi.
                        </div>
                        <p style="margin:0 0 14px 0; font-size:13.5px; color:#047857;">Hak Akses Pemberi Kerja Individu telah langsung aktif kembali.</p>
                        <div style="background:#ffffff; border:1px solid #a7f3d0; border-radius:8px; padding:12px 18px; font-size:13px; display:inline-grid; grid-template-columns:auto auto; column-gap:20px; row-gap:8px;">
                            <span style="color:#64748b; font-weight:500;">Tanggal Aktivasi :</span>
                            <strong style="color:#0f172a;"><?php echo e($rBanner['activated_at']); ?></strong>
                            <span style="color:#64748b; font-weight:500;">Berlaku Sampai :</span>
                            <strong style="color:#0f172a;"><?php echo e($rBanner['active_until']); ?></strong>
                            <span style="color:#64748b; font-weight:500;">Status :</span>
                            <strong style="color:#059669;"><span class="pill-badge verified" style="font-size:11px; padding:2px 8px;">● Aktif</span></strong>
                        </div>
                    </div>
                <?php endif; ?>

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

<script>
window.MONTH_NAMES = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agt", "Sep", "Okt", "Nov", "Des"];
window.CITY_MASTER = [
    "Kab. Timor Tengah Selatan, Nusa Tenggara Timur",
    "Kab. Aceh Barat, Aceh",
    "Kab. Aceh Barat Daya, Aceh",
    "Kab. Aceh Besar, Aceh",
    "Kab. Aceh Jaya, Aceh",
    "Kab. Aceh Selatan, Aceh",
    "Kab. Aceh Singkil, Aceh",
    "Kab. Aceh Tamiang, Aceh",
    "Kab. Aceh Tengah, Aceh",
    "Kab. Aceh Tenggara, Aceh",
    "Kab. Aceh Timur, Aceh",
    "Kab. Aceh Utara, Aceh",
    "Kab. Agam, Sumatera Barat",
    "Kab. Alor, Nusa Tenggara Timur",
    "Kab. Asahan, Sumatera Utara",
    "Kab. Badung, Bali",
    "Kab. Bangka, Bangka Belitung",
    "Kab. Banggai, Sulawesi Tengah",
    "Kab. Bandung, Jawa Barat",
    "Kab. Bandung Barat, Jawa Barat",
    "Kab. Bangkalan, Jawa Timur",
    "Kab. Bangli, Bali",
    "Kab. Banjar, Kalimantan Selatan",
    "Kab. Banjarnegara, Jawa Tengah",
    "Kab. Bantaeng, Sulawesi Selatan",
    "Kab. Bantul, DI Yogyakarta",
    "Kab. Banyumas, Jawa Tengah",
    "Kab. Banyuwangi, Jawa Timur",
    "Kab. Batang, Jawa Tengah",
    "Kab. Bekasi, Jawa Barat",
    "Kab. Belitung, Bangka Belitung",
    "Kab. Blora, Jawa Tengah",
    "Kab. Bogor, Jawa Barat",
    "Kab. Bojonegoro, Jawa Timur",
    "Kab. Bondowoso, Jawa Timur",
    "Kab. Boyolali, Jawa Tengah",
    "Kab. Brebes, Jawa Tengah",
    "Kab. Ciamis, Jawa Barat",
    "Kab. Cianjur, Jawa Barat",
    "Kab. Cilacap, Jawa Tengah",
    "Kab. Cirebon, Jawa Barat",
    "Kab. Demak, Jawa Tengah",
    "Kab. Garut, Jawa Barat",
    "Kab. Gianyar, Bali",
    "Kab. Gresik, Jawa Timur",
    "Kab. Grobogan, Jawa Tengah",
    "Kab. Gunungkidul, DI Yogyakarta",
    "Kab. Indramayu, Jawa Barat",
    "Kab. Jember, Jawa Timur",
    "Kab. Jepara, Jawa Tengah",
    "Kab. Jombang, Jawa Timur",
    "Kab. Karanganyar, Jawa Tengah",
    "Kab. Karawang, Jawa Barat",
    "Kab. Kebumen, Jawa Tengah",
    "Kab. Kediri, Jawa Timur",
    "Kab. Kendal, Jawa Tengah",
    "Kab. Klaten, Jawa Tengah",
    "Kab. Klungkung, Bali",
    "Kab. Kudus, Jawa Tengah",
    "Kab. Kulon Progo, DI Yogyakarta",
    "Kab. Kuningan, Jawa Barat",
    "Kab. Lamongan, Jawa Timur",
    "Kab. Lumajang, Jawa Timur",
    "Kab. Madiun, Jawa Timur",
    "Kab. Magelang, Jawa Tengah",
    "Kab. Magetan, Jawa Timur",
    "Kab. Majalengka, Jawa Barat",
    "Kab. Malang, Jawa Timur",
    "Kab. Mojokerto, Jawa Timur",
    "Kab. Nganjuk, Jawa Timur",
    "Kab. Ngawi, Jawa Timur",
    "Kab. Pacitan, Jawa Timur",
    "Kab. Pamekasan, Jawa Timur",
    "Kab. Pandeglang, Banten",
    "Kab. Pasuruan, Jawa Timur",
    "Kab. Pati, Jawa Tengah",
    "Kab. Pekalongan, Jawa Tengah",
    "Kab. Pemalang, Jawa Tengah",
    "Kab. Ponorogo, Jawa Timur",
    "Kab. Probolinggo, Jawa Timur",
    "Kab. Purbalingga, Jawa Tengah",
    "Kab. Purworejo, Jawa Tengah",
    "Kab. Rembang, Jawa Tengah",
    "Kab. Sampang, Jawa Timur",
    "Kab. Semarang, Jawa Tengah",
    "Kab. Serang, Banten",
    "Kab. Sidoarjo, Jawa Timur",
    "Kab. Sleman, DI Yogyakarta",
    "Kab. Sragen, Jawa Tengah",
    "Kab. Subang, Jawa Barat",
    "Kab. Sukabumi, Jawa Barat",
    "Kab. Sukoharjo, Jawa Tengah",
    "Kab. Sumedang, Jawa Barat",
    "Kab. Sumenep, Jawa Timur",
    "Kab. Tabalong, Kalimantan Selatan",
    "Kab. Tabanan, Bali",
    "Kab. Tangerang, Banten",
    "Kab. Tanggamus, Lampung",
    "Kab. Tasikmalaya, Jawa Barat",
    "Kab. Temanggung, Jawa Tengah",
    "Kab. Tuban, Jawa Timur",
    "Kab. Tulungagung, Jawa Timur",
    "Kab. Wonogiri, Jawa Tengah",
    "Kab. Wonosobo, Jawa Tengah",
    "Kota Bandung, Jawa Barat",
    "Kota Banjar, Jawa Barat",
    "Kota Batu, Jawa Timur",
    "Kota Bekasi, Jawa Barat",
    "Kota Blitar, Jawa Timur",
    "Kota Bogor, Jawa Barat",
    "Kota Cirebon, Jawa Barat",
    "Kota Denpasar, Bali",
    "Kota Depok, Jawa Barat",
    "Kota Jakarta Barat, DKI Jakarta",
    "Kota Jakarta Pusat, DKI Jakarta",
    "Kota Jakarta Selatan, DKI Jakarta",
    "Kota Jakarta Timur, DKI Jakarta",
    "Kota Jakarta Utara, DKI Jakarta",
    "Kota Kediri, Jawa Timur",
    "Kota Madiun, Jawa Timur",
    "Kota Magelang, Jawa Tengah",
    "Kota Malang, Jawa Timur",
    "Kota Mojokerto, Jawa Timur",
    "Kota Padang, Sumatera Barat",
    "Kota Pasuruan, Jawa Timur",
    "Kota Pekalongan, Jawa Tengah",
    "Kota Probolinggo, Jawa Timur",
    "Kota Salatiga, Jawa Tengah",
    "Kota Semarang, Jawa Tengah",
    "Kota Surakarta, Jawa Tengah",
    "Kota Surabaya, Jawa Timur",
    "Kota Tangerang, Banten",
    "Kota Tangerang Selatan, Banten",
    "Kota Tasikmalaya, Jawa Barat",
    "Kota Yogyakarta, DI Yogyakarta"
];
</script>

            <!-- ========================================== -->
            <!-- 1. DIREKTORI INDIVIDUAL (READ-ONLY DIRECTORY) -->
            <!-- ========================================== -->
            <?php if ($view === 'directory_individual'): ?>
                <?php if ($selectedEmployer):
                    $selectedEmpStatus = get_employer_access_status($selectedEmployer);
                    $selectedSiklusTerakhir = format_cycle_range($selectedEmployer['last_activated_at'] ?? null, $selectedEmployer['active_until'] ?? null);
                    $adminCity = (string)($user['domicile_city_id'] ?? '');
                    $isScopeMatchSelected = ($user['role'] === 'admin' || $user['role'] === 'admin_pusat' || empty($adminCity) || ($selectedEmployer['domicile_city_id'] ?? '') === $adminCity);
                    $canReactivateSelected = ($selectedEmpStatus['can_direct_reactivate'] && $isScopeMatchSelected);
                    $displayName = !empty($selectedEmployer['brand_name']) ? $selectedEmployer['brand_name'] : ($selectedEmployer['owner_name'] ?: $selectedEmployer['name']);
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $displayName)) . '-x7k2p';

                    $vStatus = $selectedEmployer['verification_status'] ?? '';
                    if ($vStatus === 'APPROVED' || !empty($selectedEmployer['verified'])) {
                        $headerBadgeClass = 'verified';
                        $headerBadgeText = 'Terverifikasi';
                        $headerStatusBadge = '<span class="pill-badge verified" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:700; font-size:12px; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:6px;"><span style="font-size:8px;">●</span> Terverifikasi</span>';
                    } elseif ($vStatus === 'NEEDS_REVISION') {
                        $headerStatusBadge = '<span class="pill-badge revision" style="background:#fff7ed; color:#ea580c; border:1px solid #ffedd5; font-weight:700; font-size:12px; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:6px;"><span style="font-size:8px;">●</span> Revisi Diminta</span>';
                    } elseif ($vStatus === 'REJECTED' || $vStatus === 'FULL_DISABLED') {
                        $headerStatusBadge = '<span class="pill-badge rejected" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-weight:700; font-size:12px; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:6px;"><span style="font-size:8px;">●</span> Ditolak</span>';
                    } else {
                        $headerStatusBadge = '<span class="pill-badge pending" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:700; font-size:12px; padding:4px 12px; border-radius:999px; display:inline-flex; align-items:center; gap:6px;"><span style="font-size:8px;">●</span> Dikirim</span>';
                    }

                    $locParts = array_filter([$selectedEmployer['village'], $selectedEmployer['district'], $selectedEmployer['city'], $selectedEmployer['province']]);
                    $headerLocation = !empty($locParts) ? implode(', ', $locParts) : ($selectedEmployer['domicile_city_id'] ?: ($selectedEmployer['city'] ?: 'Kota Bandung, Jawa Barat'));
                    $fullAdminRegion = !empty($locParts) ? implode(', ', $locParts) : 'Dago, Coblong, Kota Bandung, Jawa Barat';

                    $rawNik = (string)($selectedEmployer['nik'] ?? '');
                    if (strlen($rawNik) >= 10) {
                        $maskedNik = substr($rawNik, 0, 4) . 'xxxxxxxx' . substr($rawNik, -4);
                    } else {
                        $maskedNik = '3273xxxxxxxxxxxx';
                    }

                    $latitude = $selectedEmployer['latitude'] ?: '-6.887844';
                    $longitude = $selectedEmployer['longitude'] ?: '107.613038';

                    $instagramHandle = $selectedEmployer['instagram'] ?: '@' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $displayName));
                    $linkedinHandle = $selectedEmployer['linkedin'] ?: 'linkedin.com/in/' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $displayName));
                    $docPermissionName = $selectedEmployer['permit_document'] ?: ($selectedEmployer['doc_permission'] ?: '');
                    if (empty($docPermissionName) || (!file_exists(__DIR__ . '/' . ltrim($docPermissionName, '/')) && !file_exists($docPermissionName))) {
                        if (file_exists(__DIR__ . '/public/images/surat-pernyataan-usaha.pdf')) {
                            $docPermissionName = 'public/images/surat-pernyataan-usaha.pdf';
                        }
                    }
                    $hasDoc = !empty($docPermissionName);
                    $docDisplayName = $hasDoc ? basename($docPermissionName) : 'Tidak ada dokumen';
                    $photoLocationName = $selectedEmployer['workplace_photo'] ?: ($selectedEmployer['doc_location_photo'] ?: '');
                    if (empty($photoLocationName) || (!file_exists(__DIR__ . '/' . ltrim($photoLocationName, '/')) && !file_exists($photoLocationName))) {
                        if (file_exists(__DIR__ . '/public/images/foto-lokasi.png')) {
                            $photoLocationName = 'public/images/foto-lokasi.png';
                        } elseif (file_exists(__DIR__ . '/public/images/jobi-mascot.png')) {
                            $photoLocationName = 'public/images/jobi-mascot.png';
                        }
                    }
                    $hasPhoto = !empty($photoLocationName);
                    $photoDisplayName = $hasPhoto ? basename($photoLocationName) : 'Tidak ada foto';
                    $photoCountLabel = $hasPhoto ? '1 Foto Terlampir' : 'Tidak ada foto';
                ?>
                    <!-- DETAIL VIEW FOR DIRECTORY INDIVIDUAL (MATCHING KEMNAKER KARIRHUB REFERENCE) -->
                    <div style="margin-bottom:14px;">
                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>" style="display:inline-flex; align-items:center; gap:8px; color:#475569; text-decoration:none; font-size:13.5px; font-weight:600; transition:color 0.2s;">
                            <i class="fa-solid fa-arrow-left"></i> Kembali
                        </a>
                    </div>

                    <div style="font-size:11.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:6px;">
                        DETAIL PEMBERI KERJA INDIVIDU
                    </div>

                    <div class="detail-header-bar" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:18px 24px;">
                        <div style="display:flex; align-items:flex-start; gap:16px;">
                            <div class="item-avatar-box" style="width:60px; height:60px; border-radius:12px; background:linear-gradient(135deg, #0284c7 0%, #0369a1 100%); color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:800; box-shadow:0 4px 10px rgba(2, 132, 199, 0.2); flex-shrink:0;">
                                <?php echo strtoupper(substr($displayName, 0, 2)); ?>
                            </div>
                            <div>
                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                    <h1 style="font-size:20px; font-weight:800; color:#0f172a; margin:0;"><?php echo e($displayName); ?></h1>
                                    <?php echo $headerStatusBadge; ?>
                                </div>
                                <div style="font-size:12.5px; color:#64748b; margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <?php if (!empty($selectedEmployer['brand_name']) && ($selectedEmployer['owner_name'] || $selectedEmployer['name'])): ?>
                                        <span>Pemilik: <strong style="color:#334155;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></strong></span>
                                        <span>|</span>
                                    <?php endif; ?>
                                    <span>Slug: <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:12px; color:#334155;"><?php echo e($slug); ?></code></span>
                                    <span>|</span>
                                    <span style="font-weight:600; color:#475569;">Individual</span>
                                    <span>—</span>
                                    <span>Didaftarkan: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></span>
                                    <span>—</span>
                                    <span><?php echo e($headerLocation); ?></span>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                            <a href="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>" class="btn-lihat-detail" style="display:inline-flex; align-items:center; gap:8px; padding:9px 18px; font-size:13px; font-weight:600; border-radius:8px; border:1px solid #cbd5e1; background:#ffffff; color:#334155; text-decoration:none; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                                <i class="fa-regular fa-circle-dot" style="color:#0284c7;"></i> Lihat Rincian Verifikasi
                            </a>
                            <?php if ($canReactivateSelected): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#0284c7; border-color:#93c5fd; background:#eff6ff; font-weight:700; padding:9px 18px;" data-open-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">
                                    <i class="fa-solid fa-arrows-rotate"></i> Verifikasi Ulang (Disnaker)
                                </button>
                            <?php endif; ?>
                            <?php if ($selectedEmpStatus['is_active']): ?>
                                <button type="button" class="btn-lihat-detail" style="color:#dc2626; border-color:#fca5a5; padding:9px 18px;" data-open-modal="modal-suspend">
                                    <i class="fa-solid fa-ban"></i> Tangguhkan
                                </button>
                            <?php elseif ($selectedEmpStatus['status'] === 'SUSPENDED'): ?>
                                <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>" style="margin:0;">
                                    <input type="hidden" name="admin_action" value="unsuspend_employer">
                                    <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                    <button type="submit" class="btn-lihat-detail" style="color:#059669; border-color:#a7f3d0; padding:9px 18px;">
                                        <i class="fa-solid fa-rotate-left"></i> Batalkan Penangguhan
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- TABS (Profil | Lowongan | Lamaran) -->
                    <div style="border-bottom:1px solid #e2e8f0; margin-bottom:24px;">
                        <div style="display:flex; gap:32px;">
                            <button type="button" onclick="switchIndividuTab('profil')" id="tab-btn-profil" style="padding:10px 0; border:none; background:transparent; font-size:14.5px; font-weight:700; color:#0284c7; border-bottom:2px solid #0284c7; cursor:pointer;">
                                Profil
                            </button>
                            <button type="button" onclick="switchIndividuTab('lowongan')" id="tab-btn-lowongan" style="padding:10px 0; border:none; background:transparent; font-size:14.5px; font-weight:600; color:#64748b; border-bottom:2px solid transparent; cursor:pointer;">
                                Lowongan
                            </button>
                            <button type="button" onclick="switchIndividuTab('lamaran')" id="tab-btn-lamaran" style="padding:10px 0; border:none; background:transparent; font-size:14.5px; font-weight:600; color:#64748b; border-bottom:2px solid transparent; cursor:pointer;">
                                Lamaran
                            </button>
                        </div>
                    </div>

                    <!-- TAB CONTENT: PROFIL -->
                    <div id="tab-content-profil" style="display:grid; grid-template-columns:1fr 360px; gap:24px; align-items:start;">
                        <!-- LEFT COLUMN -->
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <!-- 1. INFORMASI UMUM -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Informasi Umum</div>
                                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:18px;">
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-user" style="color:#94a3b8;"></i> Nama
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($displayName); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-envelope" style="color:#94a3b8;"></i> Email
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0284c7;"><?php echo e($selectedEmployer['email']); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-phone" style="color:#94a3b8;"></i> Telepon
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['phone'] ?: '0812-3456-7890'); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-brands fa-whatsapp" style="color:#94a3b8;"></i> WhatsApp
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['whatsapp'] ?: ($selectedEmployer['phone'] ?: '0812-3456-7890')); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-briefcase" style="color:#94a3b8;"></i> Jenis Profesi / Usaha Individu
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['profession'] ?: 'Jasa Desain Grafis'); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-calendar" style="color:#94a3b8;"></i> Tanggal Daftar
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. LOKASI -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Lokasi</div>
                                <div style="display:grid; grid-template-columns:1fr 280px; gap:20px; align-items:start;">
                                    <div style="display:flex; flex-direction:column; gap:14px;">
                                        <div>
                                            <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                                <i class="fa-solid fa-location-dot" style="color:#0284c7;"></i> Alamat
                                            </div>
                                            <div style="font-size:13.5px; font-weight:700; color:#0f172a; text-transform:uppercase; line-height:1.4;">
                                                <?php echo e($selectedEmployer['address'] ?: 'Jl. Ir. H. Juanda No. 25'); ?>
                                            </div>
                                            <div style="font-size:12.5px; color:#475569; margin-top:2px;">
                                                <?php echo e($selectedEmployer['address_detail'] ?: ($selectedEmployer['village'] . ', ' . $selectedEmployer['district'])); ?>
                                            </div>
                                            <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                                Kode Pos: <?php echo e($selectedEmployer['postal_code'] ?: '40135'); ?>
                                            </div>
                                        </div>

                                        <div>
                                            <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                                <i class="fa-regular fa-circle-dot" style="color:#10b981;"></i> Wilayah Administratif
                                            </div>
                                            <div style="font-size:13px; font-weight:600; color:#0f172a;">
                                                <?php echo e($fullAdminRegion); ?>
                                            </div>
                                        </div>

                                        <div>
                                            <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                                <i class="fa-regular fa-compass" style="color:#8b5cf6;"></i> Koordinat
                                            </div>
                                            <div style="font-size:13px; font-family:monospace; color:#334155;">
                                                <?php echo e($latitude); ?>, <?php echo e($longitude); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Map Preview Box -->
                                    <div style="border-radius:10px; overflow:hidden; border:1px solid #e2e8f0; height:180px; position:relative; background:#f8fafc;">
                                        <iframe width="100%" height="100%" frameborder="0" style="border:0;" src="https://maps.google.com/maps?q=<?php echo urlencode($latitude . ',' . $longitude); ?>&hl=id&z=15&output=embed" loading="lazy"></iframe>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. INFORMASI PEMBERI KERJA INDIVIDU -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Informasi Pemberi Kerja Individu</div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-id-card" style="color:#94a3b8;"></i> NIK
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a; font-family:monospace;"><?php echo e($maskedNik); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-file-invoice" style="color:#94a3b8;"></i> NPWP
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a; font-family:monospace;"><?php echo e($selectedEmployer['npwp'] ?: '12.345.678.9-123.000'); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-brands fa-whatsapp" style="color:#94a3b8;"></i> WhatsApp
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['whatsapp'] ?: ($selectedEmployer['phone'] ?: '0812-3456-7890')); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-briefcase" style="color:#94a3b8;"></i> Jenis Profesi / Usaha
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['profession'] ?: 'Jasa Desain Grafis'); ?></div>
                                    </div>
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-tag" style="color:#94a3b8;"></i> Nama Brand Usaha
                                        </div>
                                        <div style="font-size:13.5px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['brand_name'] ?: '-'); ?></div>
                                    </div>
                                </div>

                                <!-- Sosial Media -->
                                <div style="border-top:1px solid #f1f5f9; padding-top:14px; margin-bottom:14px;">
                                    <div style="font-size:12px; color:#64748b; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                        <i class="fa-solid fa-share-nodes" style="color:#94a3b8;"></i> Sosial Media
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:6px; font-size:13px;">
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <i class="fa-brands fa-instagram" style="color:#e1306c; width:16px;"></i>
                                            <span style="color:#64748b;">Instagram :</span>
                                            <strong style="color:#0f172a;"><?php echo e($instagramHandle); ?></strong>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <i class="fa-brands fa-linkedin" style="color:#0a66c2; width:16px;"></i>
                                            <span style="color:#64748b;">LinkedIn :</span>
                                            <strong style="color:#0f172a;"><?php echo e($linkedinHandle); ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <!-- File Pendukung & Foto Bukti -->
                                <div style="border-top:1px solid #f1f5f9; padding-top:16px; margin-top:14px;">
                                    <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                                        <i class="fa-regular fa-folder-closed" style="color:#0284c7;"></i> Dokumen Perizinan Usaha & Foto Bukti
                                    </div>
                                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
                                        <!-- File Pendukung Card -->
                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                            <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                                                <div style="width:36px; height:36px; border-radius:8px; background:#fee2e2; color:#ef4444; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                    <i class="fa-solid fa-file-pdf" style="font-size:16px;"></i>
                                                </div>
                                                <div style="min-width:0;">
                                                    <div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Dokumen Perizinan Usaha</div>
                                                    <div style="font-size:12.5px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo e($docDisplayName); ?>"><?php echo e($docDisplayName); ?></div>
                                                </div>
                                            </div>
                                            <?php if ($hasDoc): ?>
                                                <button type="button" class="btn-lihat-detail" data-open-modal="modal-doc-permission" style="padding:6px 14px; font-size:12px; font-weight:600; white-space:nowrap; border-radius:6px; flex-shrink:0; background:#ffffff; border:1px solid #0284c7; color:#0284c7; cursor:pointer;">
                                                    <i class="fa-regular fa-eye"></i> Lihat File
                                                </button>
                                            <?php else: ?>
                                                <span style="font-size:12px; color:#94a3b8; font-style:italic;">Belum diunggah</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Foto Bukti Card -->
                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                            <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                                                <div style="width:36px; height:36px; border-radius:8px; background:#f0fdf4; color:#16a34a; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                    <i class="fa-regular fa-image" style="font-size:16px;"></i>
                                                </div>
                                                <div style="min-width:0;">
                                                    <div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Foto Lokasi / Tempat Usaha</div>
                                                    <div style="font-size:12.5px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo e($photoDisplayName); ?></div>
                                                </div>
                                            </div>
                                            <?php if ($hasPhoto): ?>
                                                <button type="button" class="btn-lihat-detail" data-open-modal="modal-doc-photos" style="padding:6px 14px; font-size:12px; font-weight:600; white-space:nowrap; border-radius:6px; flex-shrink:0; background:#ffffff; border:1px solid #16a34a; color:#16a34a; cursor:pointer;">
                                                    <i class="fa-regular fa-eye"></i> Lihat Foto
                                                </button>
                                            <?php else: ?>
                                                <span style="font-size:12px; color:#94a3b8; font-style:italic;">Belum diunggah</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Logo Usaha Card -->
                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; gap:10px;">
                                            <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                                                <div style="width:36px; height:36px; border-radius:8px; background:#eff6ff; color:#0284c7; display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden;">
                                                    <img src="public/images/jobii.png" alt="Logo Usaha" style="width:100%; height:100%; object-fit:contain;">
                                                </div>
                                                <div style="min-width:0;">
                                                    <div style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Logo Usaha</div>
                                                    <div style="font-size:12.5px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo e(!empty($selectedEmployer['business_logo']) ? basename($selectedEmployer['business_logo']) : 'jobii.png'); ?></div>
                                                </div>
                                            </div>
                                            <button type="button" class="btn-lihat-detail" data-open-modal="modal-doc-logo" style="padding:6px 14px; font-size:12px; font-weight:600; white-space:nowrap; border-radius:6px; flex-shrink:0; background:#ffffff; border:1px solid #0284c7; color:#0284c7; cursor:pointer;">
                                                <i class="fa-regular fa-eye"></i> Lihat Foto
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. DESKRIPSI -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:12px;">Deskripsi</div>
                                <div style="font-size:13.5px; color:#334155; line-height:1.6;">
                                    <?php echo nl2br(e($selectedEmployer['description'] ?: 'Menjalankan usaha jasa desain grafis dan layanan digital secara mandiri di wilayah Kota Bandung.')); ?>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT COLUMN -->
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <!-- AKUN PEMBERI KERJA -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Akun Pemberi Kerja</div>
                                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #f1f5f9;">
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <div style="width:44px; height:44px; border-radius:50%; background:#ef4444; color:#ffffff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:16px;">
                                            <?php echo strtoupper(substr($displayName, 0, 1)); ?>
                                        </div>
                                        <div>
                                            <div style="font-size:13.5px; font-weight:800; color:#0f172a; text-transform:uppercase;"><?php echo e($displayName); ?></div>
                                        </div>
                                    </div>
                                    <span style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:11.5px; font-weight:700; padding:3px 10px; border-radius:999px;">
                                        Aktif
                                    </span>
                                </div>

                                <div style="display:flex; flex-direction:column; gap:14px; font-size:13px;">
                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:3px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-envelope" style="color:#94a3b8;"></i> Email Akun
                                        </div>
                                        <div style="font-weight:600; color:#0284c7;"><?php echo e($selectedEmployer['email']); ?></div>
                                    </div>

                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:3px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-circle-check" style="color:#10b981;"></i> Status Akun
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;">Aktif</div>
                                    </div>

                                    <div>
                                        <div style="font-size:12px; color:#64748b; margin-bottom:3px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-user-shield" style="color:#0284c7;"></i> Hak Akses
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;">Pemberi Kerja Individu</div>
                                        <div style="font-size:12px; color:#16a34a; font-weight:600; margin-top:2px;">Aktif</div>
                                    </div>
                                </div>
                            </div>

                            <!-- AKTIVITAS & AUDIT LOG -->
                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                <div style="font-size:15px; font-weight:700; color:#0f172a; margin-bottom:16px;">Aktivitas & Audit Log</div>
                                <div class="timeline-list" style="position:relative; padding-left:18px;">
                                    <!-- Vertical connecting line -->
                                    <div style="position:absolute; left:5px; top:8px; bottom:12px; width:2px; background:#e2e8f0;"></div>

                                    <?php if (!empty($auditLogs)): ?>
                                        <?php foreach ($auditLogs as $log): ?>
                                            <?php $formattedLog = format_audit_log_item($log); ?>
                                            <div style="position:relative; margin-bottom:18px;">
                                                <div style="position:absolute; left:-18px; top:4px; width:10px; height:10px; border-radius:50%; <?php echo $formattedLog['dotStyle']; ?> border:2px solid #ffffff;"></div>
                                                <div style="font-size:11.5px; color:#64748b; font-weight:600;"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></div>
                                                <div style="font-size:12.5px; color:#0f172a; font-weight:700; margin-top:2px; line-height:1.4;">
                                                    <?php echo e($formattedLog['title']); ?>
                                                    <?php if (!empty($formattedLog['actor'])): ?>
                                                        <small style="color:#64748b; font-weight:500;">(oleh <?php echo e($formattedLog['actor']); ?>)</small>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($formattedLog['details'])): ?>
                                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px;"><?php echo e($formattedLog['details']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="position:relative; margin-bottom:18px;">
                                            <div style="position:absolute; left:-18px; top:4px; width:10px; height:10px; border-radius:50%; background:#94a3b8; border:2px solid #ffffff;"></div>
                                            <div style="font-size:11.5px; color:#64748b; font-weight:600;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                            <div style="font-size:12.5px; color:#0f172a; font-weight:600; margin-top:2px;">Profil dikirim untuk verifikasi.</div>
                                        </div>
                                        <div style="position:relative;">
                                            <div style="position:absolute; left:-18px; top:4px; width:10px; height:10px; border-radius:50%; background:#94a3b8; border:2px solid #ffffff;"></div>
                                            <div style="font-size:11.5px; color:#64748b; font-weight:600;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'] . ' -7 minutes')); ?></div>
                                            <div style="font-size:12.5px; color:#0f172a; font-weight:600; margin-top:2px;">Pemberi kerja mengajukan profil.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB CONTENT: LOWONGAN -->
                    <div id="tab-content-lowongan" style="display:none; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:60px 24px; text-align:center;">
                        <div style="width:64px; height:64px; border-radius:50%; background:#f1f5f9; color:#94a3b8; display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 16px auto;">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>
                        <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin:0 0 6px 0;">Belum Ada Lowongan Pekerjaan</h3>
                        <p style="font-size:13px; color:#64748b; max-width:400px; margin:0 auto;">Pemberi kerja individu ini belum mempublikasikan lowongan pekerjaan aktif.</p>
                    </div>

                    <!-- TAB CONTENT: LAMARAN -->
                    <div id="tab-content-lamaran" style="display:none; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:60px 24px; text-align:center;">
                        <div style="width:64px; height:64px; border-radius:50%; background:#f1f5f9; color:#94a3b8; display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 16px auto;">
                            <i class="fa-regular fa-folder-open"></i>
                        </div>
                        <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin:0 0 6px 0;">Belum Ada Lamaran Masuk</h3>
                        <p style="font-size:13px; color:#64748b; max-width:400px; margin:0 auto;">Belum ada riwayat lamaran dari pencari kerja untuk akun pemberi kerja ini.</p>
                    </div>

                    <!-- MODAL RINCIAN VERIFIKASI -->
                    <div class="modal-backdrop" data-modal="modal-ver-info">
                        <div class="modal-panel" style="width:min(540px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title">Rincian Verifikasi Pemberi Kerja</div>
                                <div class="modal-subtitle"><?php echo e($displayName); ?></div>
                            </div>
                            <div class="modal-body">
                                <div style="background:#f8fafc; padding:16px; border-radius:10px; margin-bottom:14px; font-size:13px; border:1px solid #e2e8f0;">
                                    <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                                        <strong>Status Verifikasi:</strong>
                                        <span><?php echo $headerStatusBadge; ?></span>
                                    </div>
                                    <div style="margin-top:8px;"><strong>Pemeriksa:</strong> <?php echo e($selectedEmployer['assigned_to'] ?: 'Belum ditugaskan'); ?></div>
                                    <div style="margin-top:8px;"><strong>Catatan Verifikator:</strong> <?php echo e($selectedEmployer['verifier_notes'] ?: 'Belum ada catatan verifikasi khusus.'); ?></div>
                                    <?php if (!empty($selectedEmployer['verification_checklist'])): ?>
                                        <div style="margin-top:8px;"><strong>Hasil Checklist:</strong> <?php echo e($selectedEmployer['verification_checklist']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
                                <a href="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>" class="btn-lihat-detail" style="background:#0284c7; color:#ffffff; border-color:#0284c7; font-weight:600;">
                                    Buka Halaman Verifikasi
                                </a>
                                <button type="button" class="ghost-btn" data-close-modal="modal-ver-info">Tutup</button>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT DOKUMEN PENDUKUNG -->
                    <div class="modal-backdrop" data-modal="modal-doc-permission">
                        <div class="modal-panel" style="width:min(760px, 94vw); max-height:90vh; display:flex; flex-direction:column;">
                            <div class="modal-header">
                                <div>
                                    <div class="modal-title">Dokumen Pendukung Usaha</div>
                                    <div class="modal-subtitle"><?php echo e($docDisplayName); ?> &bull; <?php echo e($displayName); ?></div>
                                </div>
                                <button type="button" data-close-modal="modal-doc-permission" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px; text-align:center;">
                                <?php if ($hasDoc): ?>
                                    <?php $docExt = strtolower(pathinfo($docPermissionName, PATHINFO_EXTENSION)); ?>
                                    <?php if ($docExt === 'pdf'): ?>
                                        <div style="width:100%; height:500px; border:1px solid #cbd5e1; border-radius:10px; overflow:hidden; background:#ffffff; margin-bottom:14px;">
                                            <iframe src="<?php echo e($docPermissionName); ?>" style="width:100%; height:100%; border:none;"></iframe>
                                        </div>
                                    <?php else: ?>
                                        <div style="max-width:600px; margin:0 auto 14px auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:12px;">
                                            <img src="<?php echo e($docPermissionName); ?>" alt="Dokumen Pendukung" style="max-width:100%; max-height:460px; border-radius:8px; object-fit:contain;">
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div style="padding:40px; color:#64748b;">Tidak ada dokumen pendukung yang diunggah.</div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="ghost-btn" data-close-modal="modal-doc-permission">Tutup</button>
                                <?php if ($hasDoc): ?>
                                    <a href="<?php echo e($docPermissionName); ?>" target="_blank" download class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:8px; text-decoration:none; padding:8px 16px; border-radius:8px; font-size:13px;">
                                        <i class="fa-solid fa-download"></i> Unduh Dokumen
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT FOTO BUKTI TEMPAT USAHA -->
                    <div class="modal-backdrop" data-modal="modal-doc-photos">
                        <div class="modal-panel" style="width:min(760px, 94vw); max-height:90vh; display:flex; flex-direction:column;">
                            <div class="modal-header">
                                <div>
                                    <div class="modal-title">Foto Bukti Tempat Usaha / Lokasi</div>
                                    <div class="modal-subtitle"><?php echo e($photoDisplayName); ?> &bull; <?php echo e($displayName); ?> — <?php echo e($headerLocation); ?></div>
                                </div>
                                <button type="button" data-close-modal="modal-doc-photos" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px; text-align:center;">
                                <?php if ($hasPhoto): ?>
                                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; max-width:640px; margin:0 auto;">
                                        <div style="border-radius:8px; overflow:hidden; background:#0f172a; margin-bottom:12px; max-height:460px; display:flex; align-items:center; justify-content:center;">
                                            <img src="<?php echo e($photoLocationName); ?>" alt="Foto Bukti Tempat Usaha" style="max-width:100%; max-height:460px; object-fit:contain;">
                                        </div>
                                        <div style="font-size:12.5px; color:#475569; text-align:left;">
                                            <strong>Alamat:</strong> <?php echo e($headerLocation); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="padding:40px; color:#64748b;">Tidak ada foto tempat usaha yang diunggah.</div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="ghost-btn" data-close-modal="modal-doc-photos">Tutup</button>
                                <?php if ($hasPhoto): ?>
                                    <a href="<?php echo e($photoLocationName); ?>" target="_blank" class="primary-btn" style="background:#16a34a; display:inline-flex; align-items:center; gap:8px; text-decoration:none; padding:8px 16px; border-radius:8px; font-size:13px;">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Foto Asli
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT LOGO USAHA -->
                    <div class="modal-backdrop" data-modal="modal-doc-logo">
                        <div class="modal-panel" style="width:min(640px, 94vw); max-height:90vh; display:flex; flex-direction:column;">
                            <div class="modal-header">
                                <div>
                                    <div class="modal-title">Logo Usaha</div>
                                    <div class="modal-subtitle"><?php echo e(!empty($selectedEmployer['business_logo']) ? basename($selectedEmployer['business_logo']) : 'jobii.png'); ?> &bull; <?php echo e($displayName); ?></div>
                                </div>
                                <button type="button" data-close-modal="modal-doc-logo" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px; text-align:center;">
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; max-width:480px; margin:0 auto;">
                                    <div style="border-radius:8px; overflow:hidden; background:#f8fafc; border:1px solid #e2e8f0; margin-bottom:12px; max-height:360px; display:flex; align-items:center; justify-content:center; padding:16px;">
                                        <img src="public/images/jobii.png" alt="Logo Usaha" style="max-width:100%; max-height:340px; object-fit:contain;">
                                    </div>
                                    <div style="font-size:12.5px; color:#475569; text-align:left;">
                                        <strong>Brand:</strong> <?php echo e($selectedEmployer['brand_name'] ?: $displayName); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="ghost-btn" data-close-modal="modal-doc-logo">Tutup</button>
                                <a href="public/images/jobii.png" target="_blank" class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:8px; text-decoration:none; padding:8px 16px; border-radius:8px; font-size:13px;">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Foto Asli
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL SUSPEND -->
                    <div class="modal-backdrop" data-modal="modal-suspend">
                        <div class="modal-panel" style="width:min(500px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title" style="color:#dc2626;">Tangguhkan Pemberi Kerja</div>
                                <div class="modal-subtitle"><?php echo e($displayName); ?></div>
                            </div>
                            <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="suspend_employer">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <div class="modal-body">
                                    <div style="font-size:13px; color:#475569; margin-bottom:12px;">
                                        Penangguhan Hak Akses Pemberi Kerja Individu akan membatasi aksi operasional pemohon tanpa menonaktifkan akun SIAPkerja. Masukkan alasan penangguhan:
                                    </div>
                                    <textarea name="suspension_reason" required placeholder="Alasan penangguhan wajib diisi..." style="width:100%; min-height:80px; padding:10px; border-radius:8px; border:1px solid #fca5a5; font-size:13px;"></textarea>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-suspend">Batal</button>
                                    <button type="submit" class="primary-btn" style="background:#dc2626;">Tangguhkan Sekarang</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL REACTIVATE FOR DETAIL VIEW -->
                    <?php if ($selectedEmployer && $canReactivateSelected): ?>
                        <div class="modal-backdrop" data-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">
                            <div class="modal-panel" style="width:min(540px, 92vw);">
                                <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
                                                             <div class="modal-title" style="color:#0284c7; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> PERSETUJUAN VERIFIKASI ULANG PROFIL (DISNAKER)
                                        </div>
                                    </div>
                                    <button type="button" class="close-btn" data-close-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>" style="background:none; border:none; font-size:18px; color:#94a3b8; cursor:pointer;">&times;</button>
                                 </div>
                                 <form method="post" action="admin.php?view=directory_individual&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                     <input type="hidden" name="admin_action" value="reactivate_employer_access">
                                     <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                     <div class="modal-body" style="padding:16px 20px;">
                                         <p style="margin:0 0 14px 0; color:#475569; font-size:13px; line-height:1.5;">
                                             Anda akan menyetujui Verifikasi Ulang Profil offline dan mengaktifkan kembali <strong>Hak Akses Pemberi Kerja Individu</strong> berikut:
                                         </p>

                                         <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:14px; font-size:13px; display:grid; grid-template-columns:130px 1fr; row-gap:8px;">
                                             <span style="color:#64748b;">Nama</span>
                                             <strong style="color:#0f172a;"><?php echo e($displayName); ?></strong>

                                             <span style="color:#64748b;">NIK</span>
                                             <code style="color:#0f172a; font-weight:600;"><?php echo e($maskedNik); ?></code>

                                             <span style="color:#64748b;">Lokasi Domisili</span>
                                             <span style="color:#0f172a;"><?php echo e($headerLocation); ?></span>

                                             <span style="color:#64748b;">Status Saat Ini</span>
                                             <span style="color:#dc2626; font-weight:600;">Tidak Aktif</span>
                                         </div>

                                         <div style="background:#f1f5f9; border-left:4px solid #0284c7; padding:10px 14px; border-radius:0 6px 6px 0; margin-bottom:14px;">
                                             <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:#64748b; margin-bottom:2px;">Siklus Terakhir</div>
                                             <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo e($selectedSiklusTerakhir); ?></div>
                                         </div>

                                         <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 14px; font-size:12.5px; color:#1e40af; line-height:1.5;">
                                             <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
                                             Setelah Verifikasi Ulang Profil disetujui, Hak Akses akan langsung aktif kembali selama <strong>6 bulan</strong>.<br>
                                             Verifikasi langsung melalui Admin Dinas Tenaga Kerja telah final dan tidak memerlukan verifikasi lanjutan.
                                         </div>
                                     </div>
                                     <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                         <button type="button" class="ghost-btn" data-close-modal="modal-reactivate-<?php echo $selectedEmployer['user_id']; ?>">Batal</button>
                                         <button type="submit" class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:6px;">
                                             <i class="fa-solid fa-check"></i> Setujui & Aktifkan Kembali
                                         </button>
                                     </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- DIRECTORY TABLE VIEW (MATCHING VISUAL SCREENSHOT BASELINE) -->
                    <?php
                    $filterParams = ($startDate ? '&start_date=' . urlencode($startDate) : '') . ($endDate ? '&end_date=' . urlencode($endDate) : '') . ($cityFilter ? '&city_filter=' . urlencode($cityFilter) : '');
                    ?>
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0; color:#0f172a;">Individual</h1>

                        <div style="border-bottom:1px solid #e2e8f0; margin-bottom:16px;">
                            <div class="status-tab-list" style="gap:24px;">
                                <a href="admin.php?view=directory_individual&tab=all&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParams; ?>" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=directory_individual&tab=verified&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParams; ?>" class="status-tab-item <?php echo $tab === 'verified' ? 'active' : ''; ?>">Terverifikasi</a>
                                <a href="admin.php?view=directory_individual&tab=process&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParams; ?>" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>">Dalam Proses</a>
                                <a href="admin.php?view=directory_individual&tab=rejected&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParams; ?>" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                            </div>
                        </div>

                        <form method="get" action="admin.php" id="filterMainForm" style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; width:100%; position:relative;">
                            <input type="hidden" name="view" value="directory_individual">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                            <?php if ($sort): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                            <input type="hidden" name="start_date" id="inputStartDate" value="<?php echo e($startDate); ?>">
                            <input type="hidden" name="end_date" id="inputEndDate" value="<?php echo e($endDate); ?>">
                            <input type="hidden" name="city_filter" id="inputCityFilter" value="<?php echo e($cityFilter); ?>">

                            <div class="filter-search-box" style="width:280px; border-radius:999px; height:38px;">
                                <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8; font-size:13px;"></i>
                                <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari individual...">
                            </div>

                            <div style="position:relative;">
                                <button type="button" class="filter-btn" id="filterToggleBtn" onclick="toggleFilterPopover(event)" style="display:inline-flex; align-items:center; gap:6px; background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:7px 16px; font-size:13px; font-weight:600; color:#334155; cursor:pointer;">
                                    <i class="fa-solid fa-sliders" style="font-size:12px;"></i> Filter
                                    <?php if ($startDate || $endDate || $cityFilter): ?>
                                        <span style="background:#0284c7; color:#fff; font-size:10px; border-radius:999px; padding:1px 6px; margin-left:2px;">●</span>
                                    <?php endif; ?>
                                </button>

                                <!-- FILTER POPOVER CARD -->
                                <div id="filterPopover" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:280px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05); z-index:1000; overflow:visible;">

                                    <!-- ACCORDION 1: TANGGAL PENDAFTARAN -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordion('date')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#0f172a;">Tanggal Pendaftaran</span>
                                            <i class="fa-solid fa-chevron-down" id="dateChevron" style="font-size:11px; color:#64748b; transition:transform 0.2s; <?php echo ($startDate || $endDate) ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="dateAccordionBody" style="display:<?php echo ($startDate || $endDate) ? 'block' : 'none'; ?>; padding:0 18px 14px 18px;">
                                            <div id="dateRangeTrigger" onclick="toggleDatePickerPopover(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <div style="display:flex; align-items:center; gap:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <i class="fa-regular fa-calendar" style="color:#94a3b8; font-size:14px;"></i>
                                                    <span id="dateRangeLabel"><?php echo ($startDate && $endDate) ? e($startDate . ' - ' . $endDate) : ($startDate ? e($startDate) : 'Pilih rentang tanggal'); ?></span>
                                                </div>
                                                <i class="fa-regular fa-circle-xmark" id="clearDateBtn" style="color:#cbd5e1; font-size:14px; cursor:pointer; <?php echo ($startDate || $endDate) ? 'display:inline;' : 'display:none;'; ?>" onclick="event.stopPropagation(); clearDateRange();"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 2: WILAYAH / KOTA -->
                                    <div>
                                        <div onclick="toggleAccordion('city')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#0f172a;">Wilayah / Kota</span>
                                            <i class="fa-solid fa-chevron-down" id="cityChevron" style="font-size:11px; color:#64748b; transition:transform 0.2s; <?php echo $cityFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="cityAccordionBody" style="display:<?php echo $cityFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="citySelectTrigger" onclick="toggleCityDropdown(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="citySelectLabel" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $cityFilter ? e($cityFilter) : 'Pilih kota...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- CITY SEARCHABLE DROPDOWN -->
                                            <div id="cityDropdownListCard" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="citySearchInput" onkeyup="filterCityOptions()" placeholder="Cari kota..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="cityOptionsContainer" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <!-- DUAL MONTH DATE RANGE PICKER POPOVER (SIBLING ANCHORED TO RIGHT) -->
                                <div id="datePickerPopover" onclick="event.stopPropagation();" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:540px; max-width:90vw; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 15px 35px -5px rgba(0,0,0,0.15); z-index:1010; padding:18px; box-sizing:border-box;">
                                    <!-- Header row with month/year navigation -->
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                                        <button type="button" onclick="prevMonthCluster()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-left"></i></button>

                                        <div style="display:flex; gap:24px; align-items:center;">
                                            <div style="display:flex; gap:6px;">
                                                <select id="m1Select" onchange="renderCalendar()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y1Select" onchange="renderCalendar()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                            <div style="display:flex; gap:6px;">
                                                <select id="m2Select" onchange="renderCalendar()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y2Select" onchange="renderCalendar()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                        </div>

                                        <button type="button" onclick="nextMonthCluster()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-right"></i></button>
                                    </div>

                                    <!-- Dual Month Grids -->
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:16px;">
                                        <!-- Month 1 Grid -->
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m1DaysGrid" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                        <!-- Month 2 Grid -->
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m2DaysGrid" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                    </div>

                                    <!-- Footer Buttons -->
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <button type="button" onclick="applyDatePickerSelection()" style="width:100%; background:#00a8e8; border:none; border-radius:10px; padding:10px; color:#ffffff; font-size:13.5px; font-weight:700; cursor:pointer;">Simpan</button>
                                        <button type="button" onclick="resetDatePickerSelection()" style="width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; color:#0f172a; font-size:13.5px; font-weight:700; cursor:pointer;">Reset</button>
                                    </div>
                                </div>
                            </div>
                        </form>

<script>
const CITY_MASTER = window.CITY_MASTER || [];
const MONTH_NAMES = window.MONTH_NAMES || ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agt", "Sep", "Okt", "Nov", "Des"];

let selectedStartDate = "<?php echo e($startDate); ?>";
let selectedEndDate = "<?php echo e($endDate); ?>";
let tempStartDate = selectedStartDate;
let tempEndDate = selectedEndDate;
let currentYear1 = 2026, currentMonth1 = 8;
let currentYear2 = 2026, currentMonth2 = 9;

if (selectedStartDate) {
    const parts = selectedStartDate.split('-');
    if (parts.length === 3) {
        currentYear1 = parseInt(parts[0]);
        currentMonth1 = parseInt(parts[1]) - 1;
        currentMonth2 = (currentMonth1 + 1) % 12;
        currentYear2 = currentMonth1 === 11 ? currentYear1 + 1 : currentYear1;
    }
}

function toggleFilterPopover(e) {
    if (e) e.stopPropagation();
    const popover = document.getElementById('filterPopover');
    if (popover.style.display === 'none' || !popover.style.display) {
        popover.style.display = 'block';
    } else {
        popover.style.display = 'none';
        document.getElementById('datePickerPopover').style.display = 'none';
        document.getElementById('cityDropdownListCard').style.display = 'none';
    }
}

function toggleAccordion(type) {
    if (type === 'date') {
        const body = document.getElementById('dateAccordionBody');
        const chevron = document.getElementById('dateChevron');
        if (body.style.display === 'none' || !body.style.display) {
            body.style.display = 'block';
            chevron.style.transform = 'rotate(180deg)';
        } else {
            body.style.display = 'none';
            chevron.style.transform = 'rotate(0deg)';
            document.getElementById('datePickerPopover').style.display = 'none';
        }
    } else if (type === 'city') {
        const body = document.getElementById('cityAccordionBody');
        const chevron = document.getElementById('cityChevron');
        if (body.style.display === 'none' || !body.style.display) {
            body.style.display = 'block';
            chevron.style.transform = 'rotate(180deg)';
            populateCityOptions();
        } else {
            body.style.display = 'none';
            chevron.style.transform = 'rotate(0deg)';
            document.getElementById('cityDropdownListCard').style.display = 'none';
        }
    }
}

function toggleDatePickerPopover(e) {
    if (e) e.stopPropagation();
    const picker = document.getElementById('datePickerPopover');
    if (picker.style.display === 'none' || !picker.style.display) {
        picker.style.display = 'block';
        initMonthYearSelects();
        renderCalendar();
    } else {
        picker.style.display = 'none';
    }
}

function toggleCityDropdown(e) {
    if (e) e.stopPropagation();
    const card = document.getElementById('cityDropdownListCard');
    if (card.style.display === 'none' || !card.style.display) {
        card.style.display = 'block';
        populateCityOptions();
        setTimeout(() => {
            const input = document.getElementById('citySearchInput');
            if (input) input.focus();
        }, 50);
    } else {
        card.style.display = 'none';
    }
}

function populateCityOptions(filter = '') {
    const container = document.getElementById('cityOptionsContainer');
    if (!container) return;
    container.innerHTML = '';

    const filterLower = filter.toLowerCase();
    const filtered = CITY_MASTER.filter(c => c.toLowerCase().includes(filterLower));

    if (filtered.length === 0) {
        container.innerHTML = '<div style="padding:10px; font-size:12.5px; color:#94a3b8; text-align:center;">Kota tidak ditemukan</div>';
        return;
    }

    filtered.forEach(city => {
        const item = document.createElement('div');
        item.style.cssText = 'padding:8px 12px; font-size:13px; color:#334155; cursor:pointer; border-radius:8px; font-weight:500;';
        item.textContent = city;
        item.onmouseover = () => item.style.background = '#f1f5f9';
        item.onmouseout = () => item.style.background = 'transparent';
        item.onclick = (e) => {
            e.stopPropagation();
            selectCity(city);
        };
        container.appendChild(item);
    });
}

function filterCityOptions() {
    const val = document.getElementById('citySearchInput').value;
    populateCityOptions(val);
}

function selectCity(cityName) {
    document.getElementById('inputCityFilter').value = cityName;
    document.getElementById('citySelectLabel').textContent = cityName;
    document.getElementById('cityDropdownListCard').style.display = 'none';
    document.getElementById('filterMainForm').submit();
}

function initMonthYearSelects() {
    const m1Sel = document.getElementById('m1Select');
    const m2Sel = document.getElementById('m2Select');
    const y1Sel = document.getElementById('y1Select');
    const y2Sel = document.getElementById('y2Select');

    if (!m1Sel || !m2Sel || !y1Sel || !y2Sel) return;

    m1Sel.innerHTML = MONTH_NAMES.map((m, i) => `<option value="${i}" ${i === currentMonth1 ? 'selected' : ''}>${m}</option>`).join('');
    m2Sel.innerHTML = MONTH_NAMES.map((m, i) => `<option value="${i}" ${i === currentMonth2 ? 'selected' : ''}>${m}</option>`).join('');

    const years = [2024, 2025, 2026, 2027];
    y1Sel.innerHTML = years.map(y => `<option value="${y}" ${y === currentYear1 ? 'selected' : ''}>${y}</option>`).join('');
    y2Sel.innerHTML = years.map(y => `<option value="${y}" ${y === currentYear2 ? 'selected' : ''}>${y}</option>`).join('');
}

function prevMonthCluster() {
    currentMonth1--;
    if (currentMonth1 < 0) { currentMonth1 = 11; currentYear1--; }
    currentMonth2 = (currentMonth1 + 1) % 12;
    currentYear2 = currentMonth1 === 11 ? currentYear1 + 1 : currentYear1;
    initMonthYearSelects();
    renderCalendar();
}

function nextMonthCluster() {
    currentMonth1++;
    if (currentMonth1 > 11) { currentMonth1 = 0; currentYear1++; }
    currentMonth2 = (currentMonth1 + 1) % 12;
    currentYear2 = currentMonth1 === 11 ? currentYear1 + 1 : currentYear1;
    initMonthYearSelects();
    renderCalendar();
}

function renderMonthGrid(gridId, year, month) {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    grid.innerHTML = '';

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const prevMonthDays = new Date(year, month, 0).getDate();

    const offset = (firstDay + 6) % 7;

    for (let i = offset - 1; i >= 0; i--) {
        const dayNum = prevMonthDays - i;
        const cell = document.createElement('div');
        cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
        cell.textContent = dayNum;
        grid.appendChild(cell);
    }

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const cell = document.createElement('div');

        let isSelected = false;
        let isInRange = false;

        if (tempStartDate && dateStr === tempStartDate) isSelected = true;
        if (tempEndDate && dateStr === tempEndDate) isSelected = true;
        if (tempStartDate && tempEndDate && dateStr > tempStartDate && dateStr < tempEndDate) isInRange = true;

        let bg = 'transparent';
        let color = '#334155';
        let borderRadius = '50%';
        let fontWeight = '500';

        if (isSelected) {
            bg = '#00a8e8';
            color = '#ffffff';
            fontWeight = '700';
        } else if (isInRange) {
            bg = '#e0f2fe';
            color = '#0284c7';
            borderRadius = '0';
        }

        cell.style.cssText = `padding:6px 0; background:${bg}; color:${color}; border-radius:${borderRadius}; font-weight:${fontWeight}; cursor:pointer; font-size:12.5px; transition:all 0.15s;`;
        cell.textContent = d;
        cell.onclick = (e) => {
            if (e) e.stopPropagation();
            selectDate(dateStr);
        };
        grid.appendChild(cell);
    }

    const totalCells = offset + daysInMonth;
    const remaining = (7 - (totalCells % 7)) % 7;
    for (let n = 1; n <= remaining; n++) {
        const cell = document.createElement('div');
        cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
        cell.textContent = n;
        grid.appendChild(cell);
    }
}

function renderCalendar() {
    const m1Sel = document.getElementById('m1Select');
    const y1Sel = document.getElementById('y1Select');
    const m2Sel = document.getElementById('m2Select');
    const y2Sel = document.getElementById('y2Select');

    if (m1Sel && y1Sel && m2Sel && y2Sel) {
        currentMonth1 = parseInt(m1Sel.value);
        currentYear1 = parseInt(y1Sel.value);
        currentMonth2 = parseInt(m2Sel.value);
        currentYear2 = parseInt(y2Sel.value);
    }

    renderMonthGrid('m1DaysGrid', currentYear1, currentMonth1);
    renderMonthGrid('m2DaysGrid', currentYear2, currentMonth2);
}

function selectDate(dateStr) {
    if (!tempStartDate || (tempStartDate && tempEndDate)) {
        tempStartDate = dateStr;
        tempEndDate = '';
    } else if (tempStartDate && !tempEndDate) {
        if (dateStr >= tempStartDate) {
            tempEndDate = dateStr;
        } else {
            tempEndDate = tempStartDate;
            tempStartDate = dateStr;
        }
    }
    renderCalendar();
}

function applyDatePickerSelection() {
    selectedStartDate = tempStartDate;
    selectedEndDate = tempEndDate;
    document.getElementById('inputStartDate').value = selectedStartDate;
    document.getElementById('inputEndDate').value = selectedEndDate;

    if (selectedStartDate && selectedEndDate) {
        document.getElementById('dateRangeLabel').textContent = `${selectedStartDate} - ${selectedEndDate}`;
        document.getElementById('clearDateBtn').style.display = 'inline';
    } else if (selectedStartDate) {
        document.getElementById('dateRangeLabel').textContent = selectedStartDate;
        document.getElementById('clearDateBtn').style.display = 'inline';
    } else {
        document.getElementById('dateRangeLabel').textContent = 'Pilih rentang tanggal';
        document.getElementById('clearDateBtn').style.display = 'none';
    }

    document.getElementById('datePickerPopover').style.display = 'none';
    document.getElementById('filterMainForm').submit();
}

function resetDatePickerSelection() {
    tempStartDate = '';
    tempEndDate = '';
    selectedStartDate = '';
    selectedEndDate = '';
    document.getElementById('inputStartDate').value = '';
    document.getElementById('inputEndDate').value = '';
    document.getElementById('dateRangeLabel').textContent = 'Pilih rentang tanggal';
    document.getElementById('clearDateBtn').style.display = 'none';
    renderCalendar();
    document.getElementById('filterMainForm').submit();
}

function clearDateRange() {
    resetDatePickerSelection();
}

document.addEventListener('click', function(e) {
    // Individual Filter Popover
    const popover = document.getElementById('filterPopover');
    const filterBtn = document.getElementById('filterToggleBtn');
    const picker = document.getElementById('datePickerPopover');
    const cityCard = document.getElementById('cityDropdownListCard');

    const isInsidePopover = popover && popover.contains(e.target);
    const isInsideFilterBtn = filterBtn && filterBtn.contains(e.target);
    const isInsidePicker = picker && picker.contains(e.target);

    if (!isInsidePopover && !isInsideFilterBtn && !isInsidePicker) {
        if (popover) popover.style.display = 'none';
        if (picker) picker.style.display = 'none';
        if (cityCard) cityCard.style.display = 'none';
    }

    // Job Filter Popover
    const popoverJob = document.getElementById('filterPopoverJob');
    const filterBtnJob = document.getElementById('filterToggleBtnJob');
    const pickerJob = document.getElementById('datePickerPopoverJob');
    const cityCardJob = document.getElementById('cityDropdownListCardJob');

    const isInsidePopoverJob = popoverJob && popoverJob.contains(e.target);
    const isInsideFilterBtnJob = filterBtnJob && filterBtnJob.contains(e.target);
    const isInsidePickerJob = pickerJob && pickerJob.contains(e.target);

    if (!isInsidePopoverJob && !isInsideFilterBtnJob && !isInsidePickerJob) {
        if (popoverJob) popoverJob.style.display = 'none';
        if (pickerJob) pickerJob.style.display = 'none';
        if (cityCardJob) cityCardJob.style.display = 'none';
    }

    // Employer Filter Popover
    const popoverEmp = document.getElementById('filterPopoverEmp');
    const filterBtnEmp = document.getElementById('filterToggleBtnEmp');
    const pickerEmp = document.getElementById('datePickerPopoverEmp');
    const cityCardEmp = document.getElementById('cityDropdownListCardEmp');
    const verifierCardEmp = document.getElementById('verifierDropdownListCardEmp');
    const officerCardEmp = document.getElementById('officerDropdownListCardEmp');

    const isInsidePopoverEmp = popoverEmp && popoverEmp.contains(e.target);
    const isInsideFilterBtnEmp = filterBtnEmp && filterBtnEmp.contains(e.target);
    const isInsidePickerEmp = pickerEmp && pickerEmp.contains(e.target);

    if (!isInsidePopoverEmp && !isInsideFilterBtnEmp && !isInsidePickerEmp) {
        if (popoverEmp) popoverEmp.style.display = 'none';
        if (pickerEmp) pickerEmp.style.display = 'none';
        if (cityCardEmp) cityCardEmp.style.display = 'none';
        if (verifierCardEmp) verifierCardEmp.style.display = 'none';
        if (officerCardEmp) officerCardEmp.style.display = 'none';
    }
});
</script>
                    <div class="console-table-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow-x:auto;">
                        <table class="console-table" style="width:100%; border-collapse:collapse; min-width:1000px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left;">
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:180px;">
                                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'name_asc' ? 'name_desc' : 'name_asc'; ?><?php echo $filterParams; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Nama Brand Usaha <i class="fa-solid fa-arrows-up-down" style="font-size:11px; color:#94a3b8;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:180px;">Email</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:130px;">Telepon</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:180px;">Alamat</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:160px;">Lokasi</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:130px;">Status</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:150px;">
                                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'date_desc' ? 'date_asc' : 'date_desc'; ?><?php echo $filterParams; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Tanggal Daftar <i class="fa-solid fa-arrow-down" style="font-size:11px; color:#64748b;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; width:130px; text-align:right;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$showingList): ?>
                                    <tr>
                                        <td colspan="8" style="text-align:center; padding:60px 20px; color:#64748b; font-size:13.5px;">
                                            Tidak ada data individual yang tersedia.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($showingList as $emp):
                                        $brandStr = !empty($emp['brand_name']) ? $emp['brand_name'] : ($emp['owner_name'] ?: $emp['name']);
                                        $ownerStr = !empty($emp['brand_name']) ? ($emp['owner_name'] ?: $emp['name']) : '';
                                        $emailStr = $emp['email'];
                                        $phoneStr = $emp['phone'] ?: ($emp['whatsapp'] ?: '-');
                                        $addressStr = $emp['address'] ?: ($emp['address_detail'] ?: '-');

                                        $locArr = array_filter([$emp['village'] ?? null, $emp['district'] ?? null, $emp['city'] ?? null, $emp['province'] ?? null]);
                                        $locationStr = !empty($locArr) ? implode(', ', $locArr) : ($emp['domicile_city_id'] ?: ($emp['city'] ?: '-'));

                                        $vStatus = $emp['verification_status'] ?? '';
                                        $isReverifCase = ($vStatus === 'REVERIFICATION_PENDING') || (!empty($emp['last_activated_at']) && in_array($vStatus, ['PENDING', 'REVERIFICATION_PENDING'], true));
                                        if ($vStatus === 'APPROVED' || (!empty($emp['verified']) && (int)$emp['verified'] === 1 && !$isReverifCase && !in_array($vStatus, ['NEEDS_REVISION', 'REJECTED'], true))) {
                                            $badgeHtml = '<span class="pill-badge verified" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Terverifikasi</span>';
                                        } elseif ($isReverifCase) {
                                            $badgeHtml = '<span class="pill-badge reverification" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Verifikasi Ulang</span>';
                                        } elseif ($vStatus === 'NEEDS_REVISION') {
                                            $revNum = (int)($emp['revision_count'] ?? ($emp['rejection_count'] ?? 1));
                                            $revNum = max(1, min(3, $revNum));
                                            $badgeHtml = '<span class="pill-badge revision" style="background:#fff7ed; color:#ea580c; border:1px solid #ffedd5; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Revisi Diminta (ke-' . $revNum . ')</span>';
                                        } elseif ($vStatus === 'REJECTED' || $vStatus === 'FULL_DISABLED') {
                                            $badgeHtml = '<span class="pill-badge rejected" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Ditolak</span>';
                                        } else {
                                            $badgeHtml = '<span class="pill-badge pending" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:600; padding:4px 10px; border-radius:999px; font-size:12px; display:inline-flex; align-items:center; gap:5px;"><span style="font-size:8px;">●</span> Dikirim</span>';
                                        }

                                        $dateStr = date('d M Y, H:i', strtotime($emp['created_at']));
                                    ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <td style="padding:14px 16px;">
                                                <div style="font-weight:700; color:#0f172a; font-size:13px;"><?php echo e($brandStr); ?></div>
                                                <?php if ($ownerStr && strcasecmp($ownerStr, $brandStr) !== 0): ?>
                                                    <div style="font-size:11.5px; color:#64748b; margin-top:2px; display:flex; align-items:center; gap:4px;">
                                                        <i class="fa-solid fa-user" style="font-size:10px; color:#94a3b8;"></i>
                                                        <span><?php echo e($ownerStr); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($emailStr); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px; white-space:nowrap;"><?php echo e($phoneStr); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo e($addressStr); ?>"><?php echo e($addressStr); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($locationStr); ?></td>
                                            <td style="padding:14px 16px; font-size:13px; white-space:nowrap;"><?php echo $badgeHtml; ?></td>
                                            <td style="padding:14px 16px; color:#64748b; font-size:12.5px; white-space:nowrap;"><?php echo e($dateStr); ?></td>
                                            <td style="padding:14px 16px; text-align:right; white-space:nowrap;">
                                                <a href="admin.php?view=directory_individual&detail_id=<?php echo $emp['user_id']; ?>" class="btn-lihat-detail" style="white-space:nowrap;">Lihat Detail</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($showingList): ?>
                            <div class="console-table-footer" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; font-size:13px; color:#64748b; border-top:1px solid #e2e8f0;">
                                <div>
                                    Menampilkan <?php echo $showingCount; ?> dari <?php echo $totalData; ?> total data.
                                </div>
                                <div style="display:flex; align-items:center; gap:4px;">
                                    <?php if ($page > 1): ?>
                                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page - 1; ?><?php echo $filterParams; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">‹</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">‹</span>
                                    <?php endif; ?>

                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <?php if ($p == $page): ?>
                                            <span style="padding:6px 12px; border-radius:6px; background:#0284c7; color:#fff; font-weight:700; border:1px solid #0284c7;"><?php echo $p; ?></span>
                                        <?php else: ?>
                                            <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $p; ?><?php echo $filterParams; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;"><?php echo $p; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="admin.php?view=directory_individual&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page + 1; ?><?php echo $filterParams; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">›</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">›</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>              </div>

                    <!-- MODALS FOR TABLE VIEW REACTIVATION -->
                    <?php foreach ($individualList as $emp):
                        $empStatus = get_employer_access_status($emp);
                        $adminCity = (string)($user['domicile_city_id'] ?? '');
                        $isScopeMatch = ($user['role'] === 'admin' || $user['role'] === 'admin_pusat' || empty($adminCity) || ($emp['domicile_city_id'] ?? '') === $adminCity);
                        $canReactivate = ($empStatus['can_direct_reactivate'] && $isScopeMatch);
                        if ($canReactivate):
                            $siklusTerakhir = format_cycle_range($emp['last_activated_at'] ?? null, $emp['active_until'] ?? null);
                    ?>
                        <div class="modal-backdrop" data-modal="modal-reactivate-<?php echo $emp['user_id']; ?>">
                            <div class="modal-panel" style="width:min(540px, 92vw);">
                                <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <div class="modal-title" style="color:#0284c7; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> PERSETUJUAN VERIFIKASI ULANG PROFIL (DISNAKER)
                                        </div>
                                    </div>
                                    <button type="button" class="close-btn" data-close-modal="modal-reactivate-<?php echo $emp['user_id']; ?>" style="background:none; border:none; font-size:18px; color:#94a3b8; cursor:pointer;">&times;</button>
                                </div>
                                <form method="post" action="admin.php?view=directory_individual&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>">
                                    <input type="hidden" name="admin_action" value="reactivate_employer_access">
                                    <input type="hidden" name="user_id" value="<?php echo $emp['user_id']; ?>">
                                    <div class="modal-body" style="padding:16px 20px;">
                                        <p style="margin:0 0 14px 0; color:#475569; font-size:13px; line-height:1.5;">
                                            Anda akan menyetujui Verifikasi Ulang Profil offline dan mengaktifkan kembali <strong>Hak Akses Pemberi Kerja Individu</strong> berikut:
                                        </p>

                                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:14px; font-size:13px; display:grid; grid-template-columns:130px 1fr; row-gap:8px;">
                                            <span style="color:#64748b;">Nama</span>
                                            <strong style="color:#0f172a;"><?php echo e($emp['owner_name'] ?: $emp['name']); ?></strong>

                                            <span style="color:#64748b;">NIK</span>
                                            <code style="color:#0f172a; font-weight:600;"><?php echo e($emp['nik'] ?: '-'); ?></code>

                                            <span style="color:#64748b;">Lokasi Domisili</span>
                                            <span style="color:#0f172a;"><?php echo e($emp['domicile_city_id'] ?: $emp['city'] ?: '-'); ?></span>

                                            <span style="color:#64748b;">Status Saat Ini</span>
                                            <span style="color:#dc2626; font-weight:600;">Tidak Aktif</span>
                                        </div>

                                        <div style="background:#f1f5f9; border-left:4px solid #0284c7; padding:10px 14px; border-radius:0 6px 6px 0; margin-bottom:14px;">
                                            <div style="font-size:11px; text-transform:uppercase; font-weight:700; color:#64748b; margin-bottom:2px;">Siklus Terakhir</div>
                                            <div style="font-size:13px; font-weight:600; color:#0f172a;"><?php echo e($siklusTerakhir); ?></div>
                                        </div>

                                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px 14px; font-size:12.5px; color:#1e40af; line-height:1.5;">
                                            <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
                                            Setelah Verifikasi Ulang Profil disetujui, Hak Akses akan langsung aktif kembali selama <strong>6 bulan</strong>.<br>
                                            Verifikasi langsung melalui Admin Dinas Tenaga Kerja telah final dan tidak memerlukan verifikasi lanjutan.
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                        <button type="button" class="ghost-btn" data-close-modal="modal-reactivate-<?php echo $emp['user_id']; ?>">Batal</button>
                                        <button type="submit" class="primary-btn" style="background:#0284c7; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-check"></i> Setujui & Aktifkan Kembali
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- 2. VERIFIKASI PEMBERI KERJA (VERIFICATION WORKFLOW) -->
            <!-- ========================================== -->
            <?php if ($view === 'verifikasi_employer'): ?>
                <?php
                $pemeriksaMasterList = [
                    "A. Dimas, Se", "A. Fajar Wahyu", "A. RAHMAT FAJAR", "A.a. Putra Wirasanjaya", "ABD Halim", "ABD. WAHAB, S.Pd", "ABDUL BASYIR", "ABDUL HAMID TUASALAMONY", "ABDUL SALAM LAUMA, S.Sos", "ACHMAD RAJA NASUTION",
                    "ADE KURNIA GUNAWAN", "ADE SUDARSO, ST", "ADMARINA YESTI, SE", "ADRI RANTUNG", "ADY RAHMAT", "AFDHAL NAUFAL SOFYAN", "AFRIZAL, SE", "AGUNG BACHRI, S.H", "AGUNG PRIONO", "AGUNG SURYO NUGROHO",
                    "AGUS SALIM", "AGUS ZULIARDI, S.E", "AGUSMAN ZEBUA", "AHMAD GALIH DWICAHYA", "AHMAD IQBAL, S. Sos., M.M", "AHMAD JANI, ST", "AISAH DWI ANJANI", "AISYAH DARWIS", "AJI UTAMA, S.Psi.", "AKBAR, S.Sos, MM",
                    "ALFITRA AJI NUGROHO", "ALFRED J WORABAI", "AMALIA KHAIRIDA", "AMALYA RAMADINI SAFITRI", "AMIN SUSANTO, S.E., M.M.", "AMINAH", "AMIR SAMSURIJAL", "ANDI BATARI.S.Sos.MM", "ANDI LINDA", "ANDI SULHA RAHMAN",
                    "ANDIK TAMA", "ANDRI PRATAMA", "ANDY HAMDALILAH", "ANEKA SUPIAWATI, SE", "ANGGA DARA NUGRAHA, S.STP, M.Si", "ANGGA KUSUMA WARDANI", "ANGGER GEBYARING WASKITO, S.E.", "ANNISA", "ANNISA ULHIDAYAH DWIYANTI", "ANTOK DEKI TRIANTO",
                    "ANY NOORSIAH", "APRILIANA RAHMAWATI KURDANI", "ARDIANSYAH PUTRA HALOMOAN HARAHAP", "ARGHA YONATHAN SETYAWAN KUNCORO", "ARI SAPUTRA, SE, M.Si", "ARIEF RACHMAN", "ARIES DIAN KRISTANTO, S. Sos", "ARIF SUPRAPTO", "ASDHIN PAMA", "ASMURI.S.IP",
                    "ASTI MANAO", "ASTRID YUNIAR NURBAITY", "Aa Ahmad Riswandi", "Abdi Sasra Patriyandi", "Abdul Karim", "Abdul Aziz Halim, S.IP", "Abdul Bar Zimam Rajabi", "Abdul Halim Mantau", "Abdul Rohmat", "Achmad Amrullah Yoga Priyo Darmawan",
                    "Achmad Eko Prabowo", "Achmad Rizky Mauludi", "Ade Hendri", "Ade Masynta", "Ade Riswanto", "Ade Sudrajat, S.IP", "Adelia Sekar Apsari", "Adhi Nugroho", "Adi Hendarto", "Adi Supriadi",
                    "Adib Bahari S.H.,M.E.", "Adison Sebayang", "Aditya Aprian Suari", "Aditya Irza Pahany, SE", "Aditya Nugraha Putra", "Adriana Arrung", "Adriansyah, S.hi", "Adrianto Wibowo", "Afriani Pramawati", "Afrida Susanti",
                    "Afriyani, S.Sos", "Agata Hartati", "Agoestin Faridijani, Sh.", "Agung Setiawan", "Agus", "Agus Dyanto", "Agus Firmansyah", "Agus Hadi Saputra", "Agus Haerul Rizal", "Agus Isnanto",
                    "Agus Junaidi", "Agus Suherman, S.IP", "Agus Tomi, SE", "Agustina", "Agustini", "Agustiyardi, ST. M. Si", "Agustya Dewi Kharisma", "Ahadrin", "Ahmad Fatihin", "Ahmad Hafizy Anshari",
                    "Ahmad Irfanza", "Ahmad Rifani", "Ahmad Sadili", "Ahmad Taufan Taufani, S.H.", "Aji Mohd. Afriansyah", "Ajis Suleman", "Al Muttaqin, S.T.", "Alan Maulana", "Aldestia", "Aldhila Mahati",
                    "Aldo Weldia, SH", "Alen Pandeiroth", "Alfian Agusqurrohman, SE", "Alfius Kambu", "Alfridha Nahwiyar Azis", "Ali Hasan, S.Sos", "Alif Munandar", "Alimuddin", "Alka Christo Posawa, S.Sos", "Alpian Indra Gumilar, S. Psi",
                    "Alung Juanda, SE.", "Alvin Vigo Pratama", "Amanda Yulina Putri Rahayu", "Aminuddin", "Amrah Sakti", "Amrina Rosyida", "Anak Agung Eka Dharma Kusumawati", "Andi Dian Nata", "Andi Nurdiana", "Andi Setiandy",
                    "Andi mappasukki.S.sos", "Andini andantia sonya", "Andreas Satria Wicaksana", "Andrie Lesmana", "Andry Martin", "Anestesia", "Angga Lesmana", "Anggar Wahyu Hadiyatullah, S.Kom", "Anggi Novriadi", "Anih Purwanti",
                    "Anik Estiningsih, S.I.Kom.", "Anin Khoirunnisa'", "Anitaningsi. S.Sos., M.AP", "Anna Kurnianingsih,Stp,Mm", "Annisa Dewi Hajar Satiti", "Annisa Irdhania", "Annisa Nururrohmah", "Annisa Salsabila Aulia", "Anom Yusuf", "Antares Gita Kencana, S.Pd",
                    "Anton Amau Zegar", "Antonius Syaffriel L", "Anwar Laide", "Apit Yuri Pramono", "Apri Melda, S.E", "Apriawan", "Apridayani", "April Kusuma Dwi Riwayati", "Aprillia Ayu Restiani", "Ardhi Wardhani",
                    "Ardian Muhjid Permana", "Ardiansyah. R", "Arief Wana Subagja, S.I.P.", "Arif Susanto", "Arifa Kartikasari", "Arip Rohman", "Aris Budi Setiarso", "Aristrina Sugiyanti", "Arjani Kurniansyah", "Armegi Sidik, SE",
                    "Arne Saputra BBPVP Semarang", "Arofah Kurniawan", "Artaty Pasande Runtuk, S.T.", "Arum Dwi Rahayu, Sh", "Aryanti Dwiastuti", "Aryo Brotoseno N", "Asep Kurniawan, M.PD", "Asep Saepudin", "Asep Sukandar", "Asep juhara",
                    "Ashdiqo", "Asih Setiyorini", "Asnah Kristiani Utami", "Asnawi,SE.ME", "Asniwati Br Sembiring", "Aspriani Tinambunan", "Asrianti", "Astiti Lasalutu", "Astri Liswanti", "Atika Sangadji, SE",
                    "Atirah", "Aulia Oktaviani", "Aulia ikram pulungan", "Aulifah Rachmawati", "Ayu Anisah Jayanti, S.Pd.", "Ayu Dwi Putri", "Azis Fitrianto, S.Psi.", "Azizah, S.E", "BAKTIAR,S.Sos", "BAMBANG SUMIANTO",
                    "BARLI SAPUTRA UTAMA, SH", "BARNABAS AGUS HARSOWIDIGDDO.S.E,", "BATARA MANGGALA SIANTURI", "BENYAMIN BENY NUBA", "BETTI YUDHASTUTI RETNANINGRUM", "BISMAN", "BONI GILANG KHARISMA", "BRESMAN ANDEL SARAGIH", "BUDI SETIAWAN", "Bahri",
                    "Baidhawi", "Baikuni W A Pasaribu", "Balgis Alkatiri", "Bararatul Hasanah", "Basaria Silitonga", "Baskoro Putra Aditya", "Beata Vilkanova Seraphin Waja", "Bela Merdianingsih", "Beni Yuli santoso", "Benih Subagio",
                    "Berlian", "Berton Pasaribu", "Bety Ayu Juprianti", "Bezanolo Harefa", "Bhi Anggoro Anunghadi", "Bob Santoso Abadi", "Bobby Eka Syafutra", "Bonardo Amudinta Parluhutan Marpaung", "Bram Darussalam", "Brian Fatchur Rochman",
                    "Budi Hartono", "Budi Santoso", "Budi Syafputra", "Bukhari", "Bukhori, Sh", "Bulman Muda Sidi, ST", "CASWIN.S.AN", "CAYARANI SYARIF", "Cahya Yuly Artika", "Cahyadi Maulana S.M",
                    "Cahyaning Widhi", "Calvaryneke Hanna Wantania, SH., M.Si", "Candra Dewi Hasibuan", "Carlena, S.E", "Chaeri Razikin", "Chairuddin O Mona", "Chandra Joenoes", "Chandra Setia Eswanto", "Chandra Sihotang", "Chandra Sugara",
                    "Chelsia Rorintulus", "Chintya Chandra Adella", "Chris Yudho Sih Kurniawan", "Christian Reddy Wibisono", "Christianus Kia Da Gomes", "Christy Windy Lapod", "Cicilia Srihartanti,Se", "Cipto Saputra N", "Citra Anggraini", "Citra Anggriyani",
                    "Clara Theresia Sonia Seran", "Cokorda Gede Surya Putra Trisnu, SE", "Cris Kuntadi", "Cyntia Puspita Sari, S. IP", "D. Jumiati. A.s", "DADANG KOMARA", "DANANG DWI HANANTO", "DARWIS SIREGAR, S.E", "DAVID SETIAWAN, SE., M.Si", "DEBY TWARI JASSICA TANDY",
                    "DESI AYU NINGSIH", "DESI MARLINDA SARI, S.IP", "DEWI FITRIYANA, S.Sos.", "DEWI NOFIANI, S.Sos", "DEWI PARAS UTAMI", "DIAH RIZKY PARDANI JUNAEDI", "DIANA SAKURA, S.A.P", "DIANA SUSILOWATI", "DIDIT HARTOMO", "DODI",
                    "DONY TANJUNG MUTIARA", "DRA.HENY RULIANTI MASNAWI", "DUMARIA EVI MAWARTIKU PALAMARTA BR GULTOM", "DUS DUS", "DWI BUDI SETIONO", "DWI HARTATIK", "DWI HENDARTO", "DWI PUSPA AGUSTINA, SE.MM.", "DWI UTARTI", "Dadun Kohar",
                    "Dahliana Harahap", "Dandon Anggono Putro", "Danial El Amin, S.Hut., M.E.", "Daniel Seru, SE", "Dantia Mahanani", "Darmanto,", "Darsani Sahalem", "De Safari Natadikarya, S.Kom.,M.Si.", "Dea roza ayuningtias", "Deddy Agus Pranata Harefa",
                    "Deddy Danga Rantelino", "Deddy Wilistyan, SE, MAP", "Dede Andreas, S.IP", "Dedi Candra", "Dedy Cahyadi", "Dedy Harianto", "Dedy Kurniawan", "Dedy Maryadi", "Delina Asriyani", "Delisa",
                    "Dendy Indrawan Karno Putranto", "Deni Kustiawan", "Denny Agustiansyah", "Denok Utari", "Desi Cahyandari", "Desi Liyani", "Desi Nofiyati", "Desi Rano Sulle", "Desi pangalinan", "Desniati",
                    "Desnita Thamrin S. Sos", "Dessi Dewi Yani", "Desta Trinata Amalo", "Deta Pratiwi", "Devi Nurrahayu", "Devi virayati malangkase", "Dewi Andalusia", "Dewi Asdar Purwaningsih", "Dewi Eva Kiranti", "Dewi Patriasari Sutarya, SH",
                    "Dewi Puspasari", "Dewi Sartika", "Dhanu Indra Bhaswara", "Dhian Eka Sulistiawati", "Dhina Novita Rahmaulfa", "Dhiyah Moerdhaniyati", "Dhony Suherman Putra", "Diah Ayu Novitasari,S.Pd", "Dian Dewinta", "Dian Islamiyati",
                    "Dian Mardianah", "Dian Novi Yanti", "Dian Retnowati", "Diana Eka Damayanti", "Diana Puspita Dewi", "Diana Reni Ambarwati", "Diane Prisillya Thenu", "Dianita", "Dicky Surya Pradana", "Didi Musriadi",
                    "Dies Ekaprasetya Putra", "Diky Mochamad Ramdan", "Dina Hadiani Sadarwati", "Dina Nuraeni", "Dini Munawwaroh,S.tp", "Dinul Mu'arif", "Diogenes Gedion Thonak", "Dion Ruben Timotius", "Dita Fatmawati", "Djandjang Purwanti",
                    "Doddy Danan Jaya", "Dominggus Umbu Deta", "Donna Yurika Nasution", "Dra CATUR PANGESTUNINGSIH, M.Si", "Dra. Heni Maesaroh", "Dra. Hj. Nining Herlina, M.Si", "Drs Aris Wahyudi, M.si", "Drs. Edia, M.SI", "Drs. HERMANSYAH", "Drs. MUSTAFA",
                    "Dussel Sodup Pangon Banjar Nahor", "Dwi Bambang Susanto", "Dwi Bekti Faizal", "Dwi Dis Setiyawati", "Dwi Puspa Rini", "Dwi Septina Rahayu", "Dwi Setyo Aribowo", "Dwi Susanti", "EDI SUSANTO, S.E.", "EDI WINARKO",
                    "EDINA FITRIA RAHMAN, S.STP.MM", "EDY SUYONO", "EGA EDGARDA USMAN, SE", "EKA PRASETIA ZEBUA", "ELIS MULIA SUNDAWATI, S.Pd, SH", "ELLEN CRISTIE PATTINAMA, S.Pt", "ENDAH NOVITASARI, S.Sos", "ENDAH RUHANA", "ENDANG TRI HASTOTI", "ERVAN",
                    "ERWAN S.A.P", "ESNI YULITA", "EVENTIUS PATERNUS", "EVI SUSANTI, S.Pd, M.Si", "Edhie Catur Prayitno", "Edisson Cornelis Bali", "Edwin Edzuardy", "Edwin Nugraha", "Effendi", "Efriyeni",
                    "Eka Andri Yaksa", "Eka Angelieva S", "Eka Elvira", "Eka Fajar Juniar", "Eka Permatasari", "Eka Rosmiyati, SP", "Eka Yudha Sudrajad,", "Eka michelina", "Eki Kusumadewi", "Eko Darmanto",
                    "Eko Hardiyanto", "Eko Wijayatno", "Eko bayu nugraha A Tarsi", "Eko budi setyono", "Elena Fitriyani Tjaya", "Elfried Harteguh", "Elia Susanti Titin", "Eliosa br Pinem", "Ellanda Ollivia Lesa", "Elly Safitri",
                    "Elsa Rochito Subara", "Elvi Diana Putri, S.Psi", "Elviarita Yenti", "Elviyani", "Ema Prihatini", "Emildu Azhari", "Emilia El Yunusiyah", "Emma Yunita, S.Pd.", "Endah Juli Wulandari, S.AB.", "Endah Setiawati, S.sos",
                    "Endi Mardiansyah", "Endrawati", "Erma Yustiyah", "Ermalinda Lodja", "Ernawan", "Erni Haerani", "Ernij Christin Lase", "Erniza Puspita Ningsih", "Ervin Jongguran Marajohan", "Erwin Dodengo",
                    "Erwinda Nora", "Esmet Vahlevi Cantiago", "Essie Wineri", "Esti Rohana, S.Si", "Estie Susanti", "Etik Hendarti, S.p", "Etika alistyaningsih", "Eurica Firdha Ramandita", "Eva Celia Alberthina Homer", "Eva novalinda",
                    "Everlince Yarisetouw", "Eydet Rientje Siwabessy", "FACHRUL ROSYID", "FAHMEL TRIADI", "FAHMI SAPUTRA", "FAISAL AMRI TAMPUBOLON", "FANDIAJI", "FARIDA FARADIBA", "FARIDA HOTMA,SH,.M.Si", "FARIDHA",
                    "FAUZI RAHMAN HUTABARAT", "FEKOLIMA LASE", "FERAWATY A.K DUNGGA,SS.MH", "FERRI ANDRIADI", "FIAN ISMAYADI SUSILA,SE", "FIRDAUS", "FRAN DAROMES ALIDA", "Fadilah", "Fadli Hidayat Septriana", "Fadly Syahrial",
                    "Fahruddin", "Fahrur Rozi", "Faisal Amir", "Faisal Firman", "Faisarni Namudat", "Faizal Singgih, S.I.Kom.", "Fajar Alamsyah", "Fajar Prambudi Setyagraha", "Fajrin Amin", "Farida Yulika Artati",
                    "Fariskianto Hakiki", "Fariz Bagus Pradana", "Farizal Arif Prajanto", "Farningotan siahaan", "Fatma Ramadhini, SE, MSi.", "Fatmawati", "Fatmawati", "Fatmawaty Ahmad", "Fauzan Indra Kusumah", "Fazlurrahman, S.STP",
                    "Febriza Ihsan", "Feibry Timbowo", "Felix Faro Kameubun", "Fenty Usman", "Fernanda Yogaswara Tegar Wibowo", "Ferry Gunawan M Tampubolon", "Ferry Hamonangan", "Fetriana Lestary, ST", "Fida Suherna", "Fifi Zuniarti",
                    "Finsen Demianus Furay", "Finsensius Fererius Due", "Fiora. SH", "Firdaus Gulo", "Firdaus, S.Sos, M.M", "Firdausi Nuzula", "Firman Rengga Adi Nugroho", "Firmansyah", "Firmansyah", "Fitra Rizky Yosa",
                    "Fitrah aidin", "Fitri Astuti, S.psi", "Fitri Efendi", "Fitria Ratna Sari", "Fitria Sedjati", "Fitriani", "Fitriansyah Kurniawan", "Fitrya Faradevi", "Florensa Yenialiska Ndonalia", "Foresta Siswoharsono, S.H",
                    "Frans Laurensus Sinaga", "Fredy Harry Marthonis, S.Pt., M.Si", "Frendy Nurhadi Saputra", "Frengki Tiboyong", "Fresdia Febri Yenita", "Frisca Putri Prihandini", "Fritson Patty Damo, S.I.Kom", "Fuad Kurniawan, S.H",
                    "Fuad Ramadan, S.IP", "GITA INDAH PERMATA SARI", "GUSTI ZAINAL HASAN", "GUSTIAH, S.Sos", "Galih Agan Pambayun", "Galih Pratama, S.Psi", "Gangan Ganda Somantri M.pd", "Gede Wira Pradnya", "Gemal Pramana", "Gerson Yakob Warisal",
                    "Gesta Diniarti", "Gian Jauhari Ghofiqi", "Gilang Anggi Puspita Sari", "Gilang Ikhsanul Amri", "Ginanjar Budhi Utomo", "Gita Mahartini", "Gita Rahmatillah Apsari", "Glen Pietersz, SE", "Gogo Kurnia Butar Butar", "Gracia Yanida Rachmawati",
                    "Guldhian Syahputra", "Gun Gun Agung Gumilar", "Gus Gus Taofik Hidayat", "Gusti Ayu Komang Indrayanti,S. KOM", "H. EDDY IRSON, S.T, M.Si", "HADRAYANTI", "HAIRUL AZHARI", "HAJOPAN IRIANTO ARITONANG", "HAMDAN WIDAKDO", "HANDAYANI EXTANTA RIAWATI NINGSIH",
                    "HAPPY FANTRISLA LIOW", "HARMI, SH", "HARYANTI TANAI, S.IP", "HASNI B. IBRAHIM", "HASRIADY FAMSA", "HELPINA HT. S.Sos., M.Si", "HENDRA DARMAWAN,ST.,MT", "HENDRICA MATRONA UN", "HENDRIK LOKOLLO", "HENINGSIH, S.Sos.",
                    "HENY DIANE YUSNITA, S.T., M.M.", "HEPI RAHMAWATI", "HERLINA, SE", "HERONIMUS RUMYARU", "HERRY SUSANTO", "HETTI SETIAWATI MALAKA", "Hadida Samanery, SE", "Hafiz Ansyari, S.Psi", "Haikal", "Halimatun Sa'diah",
                    "Hamidun", "Hamsiah Yahya", "Hanna Noveria Lumban Batu", "Hardi Suprapto", "Hari Fitriana, SE", "Hari Setiawan, S. Stp", "Harimukti Surya Wirawan", "Hariyani Fitrianingsih", "Hariyanto, S AP", "Hariyati",
                    "Harmawansyah", "Harmono Nugroho", "Harry Haijiwada", "Hartanto,SE", "Hartantyo Wahyu Sardono, SH", "Harun Al Rasyid, S.Si.", "Hasia Paputungan", "Hasna Rusydiani", "Hasniati", "Hatimulhusna",
                    "Hedyana Mardatina", "Hendra Buranna", "Hendra Djuanda", "Hendri", "Hendri Febrian S.Kom", "Hendriana.B,SE", "Hendrianto", "Heni Iryaningsih", "Heni Mariati", "Heni Septinawati",
                    "Heni Susilowati", "Henni Fariha", "Henny Fadilla", "Henri permana", "Herawati", "Heria, S.Pd.I", "Herlan Santoso", "Herman Rubiyansah", "Hermon Iswandi", "Herry Supriatna",
                    "Heru Setiyanto", "Heruwibowo", "Herwin Jabir", "Herwin Setiawan, S.Sos", "Hesni yuningsih, S.Kom", "Hesti Agustini", "Hestin atas asih", "Hesty Wirayati Turan", "Hidayah Fiqih Utama", "Hidayat,S.I.P",
                    "Hisyam", "Hj. ALUSMAWATI, SE", "Hj. Daryati Ratna", "Hj. Hasnun Akmal", "Hj. Jumriati.S,SE,MM", "Hj. Mukarromah", "Husran", "I Gede Agus Sudaneyasa", "I Gede Ekananda Hartika", "I Gusti Ayu Diah Kurniasari",
                    "I Gusti Ayu Made Oktavia Utami Dewi", "I KETUT ADI NATHA, S.E, M.A.P", "I Ketut Ardana", "I Ketut Suartika", "I Komang Suardana, SH", "I Made Agus Wira Wijaya", "I Made Bambang Suliastono, SE.,MAP.", "I Made Dwi Etmo Cahyono Supraptha, S.H.", "I Made Ngurah Bangun Jayadi Putra", "I Made Reta",
                    "I PUTU SUBRATA", "I Putu Sumardika", "I gede kartanayasa", "I. Syafii", "ICHWAN HAFNI, ST, MM", "IKA SETIANINGRUM", "IKHWANTI ABDUL GANI", "ILDA SUTOPO,SP", "INA WIDIAWATI, S.H.", "INSYIRA SUBAGIA",
                    "INTAN KUSUMA WARDANI", "IRAWATI,SE", "IRENE SETYANINGRUM, S.IP., M.Si.", "IRSAD ADI LAZUARDI", "IRWAN ,SE", "IRWAN KURNIAWAN", "IRWAN PRIMA HARTAWAN", "IRWANDHANI", "IRWANTI, SH", "IRWIN SETIAWAN,SH.MM",
                    "ISHAK MAULANA", "ISKANDAR, S.Kom.,MM", "ISNAENI DE ANDREOTTI", "ISWADI", "ISWANDI,SKM", "ISWARADJATI", "IVAN SEPTIANTO. S.E.,M.M", "Ibnu Aulia Hanif", "Ibnu khaldun Sahabuddin, S.Sos", "Ichsan Singi, S.Sos",
                    "Ida Ayu Mirah Setiawati", "Ida Ayu Ratih Mayuni", "Ida Bagus Agung Andi Bhisma M.", "Ida Bagus Pidada Adi Putra", "Ida Sanjaya", "Idrus, S.Sos", "Idul Aguscik, S.H, M.M", "Iehsan tri kurnia", "Ihpan Siregar", "Ika Ardiyanto",
                    "Ikhrawan", "Ikmal Hananto", "Ilham Hadikusuma", "Ilham Ramadhan", "Ilham iskandar", "Imam Mu'aziz", "Imam Robani", "Iman Rajiman", "Imanuel Yohanes Lande", "Imas Masitoh",
                    "Imawan Sujianto", "Ina Rhomy", "Inas Azzahra", "Indah Ernawati", "Indah Kurnia Lestari", "Indah Sri Wahyuni", "Indah Tri Rahayu", "Indah joelianti", "Indarti", "Indra Sahputra",
                    "Indri Chevalia", "Intan Maria Rumantir Sinambela", "Intan Priyandini", "Ir. ARIF SOEDJANARTA, MM", "Ir. I Gusti Ayu Yuliari Ratrini", "Ira Ramadhani", "Irdha Yanti Musyawarah", "Irene Kusuma Palmarani", "Irfan Risnandi", "Irma Hendriyanti",
                    "Irma Widiastuti", "Irna asih astuti", "Isal Firdaus", "Iskandar", "Islahun Nihayah", "Ismartini", "Ismi Putri", "Isti Wasono S.Pt", "Iswady", "Ivan Valentino",
                    "Iwan Hendrawan", "Iwan Widiantoro", "Izza Islamiyah Putri Fayaliq", "JANIA Hi. UMAR", "JASRIL HAKIM, S,Pd", "JEANETTE PRICHILIA SEMEN, SE", "JIMMIE MANOVO", "JONI AFRIZAL, S.E", "JOSE SOARES REGO, S.Sos", "JUARIAH, S.IP",
                    "JUMADIN", "JUMIASTI RASMAN", "JUNAIDI", "JUNIAR TIGVA BORU", "JUNIATININGSIH S.A.P", "JUNITA FLORIN BUKIT, S.E", "JUNITA JETTY HANNA KUMOWAL", "JUWITA AMELIA DAULAY", "Jahrudin", "Jamila Wael",
                    "Jandra Jessy Pangemanan", "Janu Didik Santoso", "Jatu Aji Legowo", "Jean Rizal Wijanto", "Jefri Polembi", "Jens Rere", "Jerry Diwitau", "Jetro", "Johana C. Matau", "Joko Prihharjanto, S.Sos",
                    "Joni Malau", "Joni Palentek", "Jonisten Rajagukguk", "Jordanatha", "Jovan Ferdianto", "Juima Marthen", "Jujun Hidayat", "Jules Jaurat Sibarani", "Julius Andrea Juspongo", "Junaidi Amanda Pasaribu",
                    "Junaidin", "Juni Aryanto", "Junion Mirasoni Robinson Taga", "Jusman", "KASITA PUTRI YENITA", "KENDRA YUNIAWAN", "KURNIAWAN A. NURZAL, S.Sos", "KUSNANTO", "Kadek Puspita Ratnadewi", "Kadir,S.E",
                    "Kamalia Sutra Dewi,S.Psi.,M.A.P", "Kariza Dyah Yasmin", "Karjuna", "Karmila", "Karmila Ndajakapraingu", "Kartika Sari", "Kasman Karama", "Kencana Sari", "Ketut Wiratni", "Kevin Tovani",
                    "Khairina Syafitri", "Khairudin", "Khairunnisah, S.Psi", "Khamsiardi", "Khoirurijaluddin,S.E.", "Khony Wibowo", "Khorina Noviyanti", "Khosim Wongso Suratna", "Khusni", "Komang Eli Susanti",
                    "Komarudin", "Kris Wibowo", "Kristianus Sugandi Tampar", "Kumala Nindya Pramono", "Kumaya, SE", "Kurniasari", "Kurniati", "Kurrotul aini", "LAILA RAHMI, S.Pi", "LAILATUL MARHAMAH, A.Md.",
                    "LISTYO RAHAYU", "Lagowe", "Laila Dona Apriani, S.IP.", "Lalu Satria Utama", "Laode Rekesi", "Larasati Azizah Rahimi", "Lastri, S.I.Kom", "Lati Jannani", "Laviena Octora", "Lazuardi Okva Harindra",
                    "Lenggana Dewi", "Leni Muliana", "Leny Wulandari", "Leviana Agustin", "Liesna Prasetyorini", "Linda Manurung", "Linda Rosida", "Lindasari,Se", "Lindawati", "Lineke Kaeng",
                    "Lisa", "Lisa Erma Sumarni", "Lisbeth Limbong, SE", "Lisda Dhyniarti Bachtiar", "Lita Mariyani", "Louis Stefani Sriratu, SE, M.Si", "Lubis Polo", "Ludfi", "Luki Rani Ervita, S.S.", "Lukito",
                    "Lukman", "Luthfi Adi Setiawan", "Luthfi Hariyanto", "M Mustafa Sarinanto", "M ROSIHAN NUR ANWAR, S.E", "M RUSWIYANTO", "M Zaki Dzulfiqar Rosyadi", "M. FADLI SJAH", "M. Geraldi Prihandana", "M. Ichwan",
                    "M. Jaini", "M. Rizki Ramadhan", "M.SUBHAN, SE", "M.TAHARUDDIN", "M.satrio Pratomo", "MAEMANA, SE.", "MAHENDRI ARIMURTY", "MAIDAH .S.Sos", "MAR'ATUS SOLIKHAH", "MARAGANTI HASIBUAN",
                    "MARDIYANI", "MARETA FIFIAN DWI ROSANTI", "MARGARETHA, SE", "MARIA SARIYANTI HERAWATI TARMIN", "MARIA SOFI ARDINI", "MARISTHANI", "MARLEN AGUSTIN TAMPI", "MARMIN", "MARSIA INA RAWI, S.E", "MARWIYAH, S.AP",
                    "MASRITA J. DJ. MOHI", "MAT Shonif", "MATLA'UL ANWAR", "MAYA NURPAICA, SE", "MAYSKER WILIAMSON", "MELFIDYAN GENAKAMA", "MINA BOUTY", "MINARMI", "MIRANTI, ST", "MIRNA NUR ISTIQOMAH",
                    "MOEHAMMAD ZOECHRI TOBAMBA,ST", "MOH ZAINODDIN", "MOHAMMAD FAIDIL ANWARIE", "MONALISA", "MUHAMAD WIDHIARTO, S.Si., M.Hum", "MUHAMAD YUSUF", "MUHAMMAD FIKRUL ILMI, S.Pd.", "MUHAMMAD GAZALI SYAIDAR", "MUHAMMAD HAMKA, S.A.B", "MUHAMMAD NASIR, S.Sos",
                    "MUHAMMAD SHOBIRUR RIZQI", "MUHAMMAD SYAUFI IHZA", "MUHRODHI, S.Sos", "MULIADI, Sos., M.Si.", "MUSTAFA KAMAL, SE, M. Si", "Ma'sum Makkawaru", "Madien Hilalah", "Maghfirotun Nisa", "Mahlidar", "Maifendri",
                    "Maizar, Sh, Mh", "Majarani,SE.,MM", "Maman Fadhilah, SH", "Maman Lukman", "Manake Bambang Triawan", "Mardhatillah H. Polinelo", "Mardiana", "Mardiani SE", "Mardika Belapati", "Marhaeni",
                    "Marhawia", "Maria Angganitha De Lima", "Maria Efanggelina Fahik", "Maria Legiani", "Marianti Makalalag", "Mariedi Manto", "Marina Putri", "Marjuni", "Marlen Nirahua,SE", "Marliana Agus Mante",
                    "Marliana Wale Waton, S.Sos", "Marlina", "Marlinah", "Marni Hartati", "Maroeto Yoeli Setiawan", "Marselina Gadu", "Marta Meiliana Tiurmaida Patricia", "Martania Rizki Permatasari", "Martina Amarairu", "Martini Yahya",
                    "Maryam Karepesina", "Maryati", "Mashudi", "Masianna Pasaribu", "Mateos Maleta", "Maulidar", "Maulidma Muhammad", "Maulina Adelia Pratiwi", "Mawadah Dewi Apriyani", "Maya Nursanti , SE",
                    "Maya utari", "Medianto", "Meidi H.gunawan S.sos", "Meilanny Margaretha Sondakh", "Melani Fitra Rizkianty, SP, M.Si.", "Melati Putri Mose", "Melly Pebrianti", "Melrytio Junita Sitio", "Merliani", "Merry Wadu",
                    "Mersi Tangdilassu'", "Mery Yosepha Manik", "Meta Lara Pandini", "Mexon Maiman Purba S.Sos", "Meyrina Pronityastuti", "Mia Aulia", "Miafitri Damanik", "Mikhael Nikodemus Awi", "Mirayulita", "Mirda Datuela",
                    "Miriansyah", "Mirlie Lenggo Genie", "Mirnawaty Moo", "Mirra Desthari Thiodorra", "Misgianto", "Miss Herlina", "Mita Chairunnisa", "Moch. Yusuf Efendi", "Moch.Royyanudin Mafitri", "Mohamad Ridwan",
                    "Mohamad Syaiful Amin", "Mohammad Axel Runako", "Mohammad Ido Hendra Wijaya, S.Tr.P", "Mohammad Soko Marhendi", "Mohammad Toha Putra", "Mohammad fadly", "Mokhtar Kusuma Atmaja,Se", "Mona Kiranasih", "Mona Lisa Oktavia", "Muchsin Habib",
                    "Mugiyani", "Muh Auliyah Nur Yaqin", "Muh Bahri Ikbal", "Muh. Hariadi, S.S.T", "Muhamad Aliudin Rumra", "Muhamad Najmul Fikri", "Muhamad Rifqi Robbani", "Muhamad Said", "Muhamad Taufik", "Muhamad Yani",
                    "Muhamad Yasil Farabi", "Muhammad Adenin,St", "Muhammad Agus Ilmiawan", "Muhammad Ali Akbar", "Muhammad Dikhatama Yudha", "Muhammad Eka Darmawan, SE", "Muhammad Eric Cahyadi", "Muhammad Faizal", "Muhammad Farid Ardiansyah", "Muhammad Faridhon Sy. ST.,MT",
                    "Muhammad Faried Risky", "Muhammad Hafidz Alfikri", "Muhammad Hidayatullah", "Muhammad Iksan", "Muhammad Ivandry", "Muhammad Izhar", "Muhammad Kabul, S.Sos", "Muhammad Miftahul Khoir Rahmatullah, S.E", "Muhammad Muajib Ardiansah", "Muhammad Nafarin",
                    "Muhammad Nur Ihsan", "Muhammad Rinaldy Arif", "Muhammad Rizky Sembiring", "Muhammad Rofiq Kurnia", "Muhammad Rusydi, SH., MM", "Muhammad Sofian Husein", "Muhammad Taswin", "Muhammad Tio Fadillah", "Muhammad Toni Afriady", "Muhammad Wendy Danendra Pohan",
                    "Muhammad Zuhdi Kurniawan", "Muharyadi , S. Sos", "Muhmmed Khoreiza Qodliya", "Muhtadin Mustafa", "Mujiburahman Saputra", "Munawar", "Muri Kusmahana, S.Kom", "Murni Susianti,SE", "Mursalim S", "Murseto",
                    "Murwani F", "Muryati, S.Sos.", "Mus Saputra", "Musinah, SH", "Musliono", "Musni Bakar SH", "Mustafa Kamal", "Mustain, SE", "Mutia Astar", "Mutiara Tio Nora Simarmata",
                    "N Juliawati", "NAJIH NUR FAUZI", "NAPOLEON ENA, S.Sos", "NASIRUDDIN", "NELLIZA", "NI GUSTI AYU OKA PURNAWATI, SE", "NINDING KOSMANA", "NOORHAYANI", "NUNIK INDRAWATI", "NUNIK SUPARTINI",
                    "NUR ALIYAH", "NUR LAELA PATRIANI", "NUR'AINI,S.Sos", "NURAINY BARDJA", "NURDIN ANWAR, SE", "NURFADHILLAH ARDIYANI NASARU", "NURHEMI RITONGA", "NURMANDIKA BAYU IRAWAN", "NURUL HASANAH", "Nabella Intan Pertiwi",
                    "Nabilla Mei Larasati", "Nadian Tanora Mirzani", "Nadiatul Humairoh", "Nadya Noor Oktavia", "Nafisa Aulia Fahmi", "Nailul faroh", "Nani Aprizha", "Naomi Fitria Arja", "Naomi Sa'bi", "Nasrullah",
                    "Nasrullah, SE", "Natalia Ogolmagai", "Natalia isa", "Natya Adi Nugroho", "Nazaria Febiani", "Nazarudin Arif", "Neflianty Birlian", "Nelika", "Ni Kadek Ristawati", "Ni Luh Sudiani,SH",
                    "Ni Made Dwi Ari Susilawati", "Ni Made Sri Malini,SE", "Ni Wayan Surasmini", "Nia Kurnia", "Niken Candraningrum", "Nikhen Pratiwi Sekar Tanjung", "Nikira Desti Dewati", "Nindya Rachmayanti", "Nisa Arifiana", "Niswatul Rokhma",
                    "Nita Budi Astuti", "Nitya Dimas Anggara", "Nona Monika Sombolayuk", "Noor Aida Choirunnisa", "Noor Heldayanti, SE", "Nor Aisya Mahdha Heldina", "Novia Dwi Wanti", "Novia Elisabeth Putri Permatasari", "Novia Rosvita Sari", "Novinaristanty Zega",
                    "Novistasary", "Novrita Karo Karo, SH", "Nugraha Muharafandy", "Nugroho Wijoyo Kusumo", "Nungky Puri Astuti", "Nuni Rahayu, S.Sos", "Nunik Wahyu Rahmawati", "Nunuy Nursamsiah", "Nur Alvi", "Nur Andini, SE",
                    "Nur Fitri Anasari", "Nur Lailiah", "Nur Wahid Syafarli", "Nur Wahyuni", "Nur Widiastuti", "Nur Widiyaningsih", "Nuranti Eka Oktaviana", "Nurdi Arie wibowo", "Nurfatin Fiqgiya", "Nurhadi",
                    "Nurkasanah", "Nurkholis", "Nurmina manik", "Nurnismah", "Nursamsi", "Nurul Febiyanti", "Nurul Hasanah Tul Zannah", "Nurullaily", "Nurwiranto, SH", "Nuurin Izzati,S.Hum.", "Nyoman Arsiani",
                    "OLSJE JANS PIRING", "Octa Prindani", "Okfita Linda Anjasari", "Oki Nugraha", "Oki Oktafri Yatno", "Okta ariyani", "Oliva Yohana Weridity", "Orie Secunda Ayunitantry", "Ovi Utami", "PARIAMAN DAELI",
                    "PATRICK SERVANDA GROTIUS PARADIK", "PEDI", "PENITAWATI", "PONCO PRANOTO", "PRAWITA DEWI RIANINGRUM", "Pamelia Rahayu S.", "Pamungkas Setyo Utomo", "Panca Retnawati", "Pandu Isdiyanto, S.T., M.M.", "Patmi Sahroni, SP",
                    "Paulina Maria Songkares", "Pipin Sulistiyaningsih", "Pitriani", "Poni Eka Putra", "Praba Pancala Radya", "Pradina Fitri Maniku,A.Md.Kom", "Pramudhita Ayu Amalia", "Pramudianto, S.H.", "Prasetiyaning Tyas Ari Safitri", "Prasetya Wijayanto",
                    "Pratiwi Nurdiana", "Prince Alvin Yusuf", "Putri Anggraeni", "Putu Cindy Candra Dewi", "QUDRATULLAH AGAM, S.E", "R DEDY SANTOSO SULUS SE", "R Dani Guntara", "R Nurhidayat", "R. Bambang Dwi Minardi", "R. Deddy Dwiyudha Bakti",
                    "R. Elly Widianingsih,SE.,M.AP", "RAHDAT HARI", "RAHMAD DILAGA, SH", "RAHMAD HIDAYAT", "RAHMAD MULYADI", "RAHMAD SANDI,S.T", "RAHMAH,S.Pd", "RAHMAT AULIA", "RAMLAH S. LASORE", "RASYIDAN,S.Hut",
                    "RATNA PRATMAWATI", "REBY RAMBU RITI ROBU", "RENDRA PUTRA DINATA", "RENI KLEMENTIA LASE, SE", "RENIATI, S. Sos", "RENO SAHALA JHON PAULUS PURBA", "RESTU NORO", "RESTUTY", "RETNO ANDAYANI LESTARI", "RETNO WIDHI ASTUTI",
                    "REZA AFRIANSYAH", "REZA ARIFIN", "RIANA LYZA", "RIBKA MASIE NELCE MELLES", "RICKY IDAMAN SYARFI,SH.MH", "RIDHA ANSHARI", "RIDWAN ILHAMI", "RINATIN", "RIRI FERDINA", "RISNA ARIANI",
                    "RITA AGUSTINA", "RITA DAMAYANTI", "RITA FADHILAH", "RIZA PAHLEVI", "RIZALUDIN", "RIZKA MAHARDIKA", "ROBERT FRANKY ROOROH", "ROCKY PUTRA ANTAJA GULO, SM", "ROJALI, SE", "ROMDIYAH",
                    "RONI EKA PRASETIAWAN, S.E.", "RUDIANSYAH", "RUSLAN TARFIN, S.STP", "RUSMIATI", "Rachmat ginanjar", "Raden Roro Mur Oktaviani Swieta Wijayanti, Se", "Rafi'ah Defretes, SH", "Rafki Hadinarto", "Raga Sugih Pangestu", "Rahardian Aditya Maulana",
                    "Rahel", "Rahenda Ahmad Sanusi", "Rahma Dinda Valentine", "Rahma Fitriati", "Rahma Nurlita", "Rahmani", "Rahmat Juang Kusyari", "Rahmat Waluyo", "Rahmat Widodo", "Rahmayati Br Karo",
                    "Rahmi Fitria Asril", "Raisha pulukadang", "Raka Dwiman Hudiya", "Rama", "Rama Trijaya Kusuma", "Ramadanni", "Ramadhani", "Ramal Agus Risal, S.E.", "Ramos P. Siagian", "Rangga Yudistia,S.E.,M.Si.",
                    "Ranti Roezalia Sekti", "Rantini, S.E", "Rasniati", "Rasul", "Ratama Arifin Wibowo", "Ratih Indradiyati", "Ratri Nurinda Kusumawati", "Ratri Wiryani", "Redemta Krisanti Sumiati Laka", "Redie Sumantri, S.Kom",
                    "Refi Aprisanti", "Refti Betriesva", "Renata Agustina.,S.E", "Rencana Tarigan", "Renna Aprina", "Renny Wahyuni", "Restu Sucipa", "Retno Pangestuti Widianti", "Revita Permana", "Reynhard Hutapea",
                    "Reza Diki Nisyadin", "Rezky Maharani", "Ria Aggriani Dedtiama", "Rian Rizky", "Rianti Djafar,S.Sos", "Ribka Ambarwati, S.Pd.", "Richat simangunsong", "Richi Agung Ervanto", "Rici Ronaldo", "Ridhayani Aniray",
                    "Riduansyah", "Ridwan Arif Budiman", "Rijkaard Lasol", "Rika Kurniati", "Rika Nidiya Sari", "Rika Yustika", "Rikahasnita", "Riki Chosyikin", "Riko Ekaputra, S.E, MM", "Riko Tandean",
                    "Rina", "Rina Endra Astutik", "Rini Martiana", "Riny Karpanisa", "Rio Valentino", "Riri Melanie Rahayu", "Riri Widyawati", "Ririn Martini Rezki, ME", "Rivai Sindring", "Riyanto",
                    "Rizal Ardi", "Rizka Izzati", "Rizki Al Fahri", "Rizki Putri Anuari, S.I.Kom", "Rizki Rezza Fahlevi", "Rizkia Ramadhan, S.T", "Rizky Adhia Esprila", "Rizky Amalia Ulfa", "Rizqi Meiana Putri", "Rizqi Mutahara",
                    "Robertius", "Rochmad Effendi", "Rohendi", "Rohiyah", "Rohmah Ahdiyati", "Rohmat Syaefulloh", "Roi Munazir", "Roiyah Arakhman", "Romadi", "Ronald Rotu Ludji",
                    "Ronaldus Cika Perkasa", "Roose Spiegel Nebore, S.Psi", "Root User", "Rosda, SE., M.A.P", "Rosdiani", "Rosinta Girsang", "Rr. Dhiasty Mahayanti, ST", "Rr. KURNIASIH WILUJENG", "Ruben Calvin Wattimena, SH", "Rudi Hartono, S.IP",
                    "Rudolfus Kaliang Dendimara", "Ruly Amri", "Rumiyati", "Rusdianto", "Ruslim", "Rustam", "Rustanto", "Ruth Sherina Dama Yanti", "Rutiani Umar", "Ryan Surya Nadapdap",
                    "Ryas Cahya Annisa", "Rychad Luluk Kuncahyo", "SABAARO MENDROFA, S.Sos", "SAFRANS SABLIMAN ZEGA", "SALMAWATI", "SALWA NOOR AZIZAH", "SAMUEL TANDUNGAN", "SAMURNI", "SANG AYU KETUT SRI ARMONI", "SANUSI BARDENA SEMBIRING",
                    "SAPTARINI, SE", "SARI SARLITA, S.IP", "SAYFUL ALIWU,ST", "SEBASTIANUS FANULEN,SH", "SEFTI FITRIANI", "SELVARIUS RUDI CAHYANTO, S.E", "SELVINA", "SETIAMAN LASE", "SHIFA SALASIA AGUSTIANA", "SINTYA RATIKA SARI",
                    "SISKA NURDIN MOHI", "SITI AMINAH", "SITI HARMILLA, S.IP, M.Si", "SITI KHADIJAH KITTA, S.Psi., M.Hum", "SITI SYARIAH", "SOPAN", "SRI ISYAWATI", "SRI RAHAYU", "SRI WIDANARTI PAMUJI RAHAYU", "ST.SYAMSINAR.S.IP,.M.IP.",
                    "SUBRAN KARNI, S.SY", "SUGIYANTO", "SUHARDI, SE,MM", "SUKADI, SE", "SUKIRAH, SH., M.EC.DEV", "SUMARDAN, SE.", "SUNARTO", "SUPRIYONO, S.Sos, M.M", "SUSALMAWATI, WN", "SUSY ARMAYA TANJUNG",
                    "SUYANI", "SUZZANA MARIANA DURAND", "SYAHIDAH ASMA AMANINA", "SYANTY", "SYARIFAH SALMA", "Saepul Rohmat", "Safi'i", "Sagita Kusumawardani", "Sahna Putri Aselira", "Sakban simarmata",
                    "Sakinah, SE", "Sakri Warastrotomo", "Salman Abdurakhman", "Samsir firdaus", "Samsuardi", "Samsul", "Samsul Arief", "Samsyu", "Sandi Tampubolon", "Sandy Florantine Titis Martosudarmo, S.Psi",
                    "Sapani", "Saprianto", "Sapta Wulandari, Se", "Sarah Reza Maharani", "Sari Dhewi Saraswati", "Sarjono", "Savira Amanda", "Savitria Winariah", "Sawalman", "Sayani",
                    "Selly lintari", "Selma Almakiya", "Selva Mardinawaty, ST., M.M.", "Sentia Rapika, S.E", "Sentot Tasgunarto", "Senyorita Rosaliana Aronggear", "Septi Rahayu", "Septi Wulandari", "Septian Thahir", "Septiana Vista Dewi",
                    "Septiani Khaerunnisa", "Septiarni", "Serlina,SE,MM", "Seruni Adhinta Perdana", "Seta Satria Utama", "Setiawan", "Setiono Budi Sopiy", "Shadri saputra", "Shellomita Kusumawardhani", "Shelvy Susanti",
                    "Shely Marfuah", "Sheylla Aprisca Windiyani", "Shindi Istia Ratyadi", "Shofi Fajriah Ilmi", "Sie Gerrenil Yukgikhanta,", "Sigit Ary Prasetyo", "Simon", "Siti Arfah Husen", "Siti Chumakyah", "Siti Jubaidah",
                    "Siti Mutoharoh", "Siti Ratna", "Siti Yoanita Adrina", "Siti latifatul mahmudah", "Sitti Zarfina", "Slamet Budiono", "Slamet Riyadi", "Sofia Deslinda", "Sofia Ndao", "Sofiyanti Al Hasanah",
                    "Sofyan", "Sony maulana", "Sophia Adella", "Sri hartini", "Sri Anitia Fournia", "Sri Handayani", "Sri Hartanti", "Sri Juminarsih", "Sri Oktavianti Porosi, SH.MH", "Sri Rahayu",
                    "Sri Restanti", "Sri Wahyuni", "Srisiana bunsal", "Stefanie Cicilia zarosa", "Stefanus Richard Marcus", "Stenly Rambitan", "Subhan Syukri Daulay", "Suci Anggraeny Pasaribu, SST", "Suci Islamiya", "Suci Puji Lestari",
                    "Sudaryatno", "Sudiarto", "Sudiono", "Sudiryo", "Sugeng wiyono", "Sugiman, S.IP", "Sugiyanto", "Suhaimar Alfiandri", "Suharni Bondang", "Suhartoyo", "Suheri",
                    "Sujarwati", "Sukaria Tarigan", "Sukma dryandi", "Sukmawati Syafwa", "Sulaeman", "Sulistiowati", "Suliyanti", "Sumiati Njau", "Supiandi", "Supriyati",
                    "Suratimahda", "Suriani", "Surya Lukita Warman, M.Sc.", "Suryani Ningsi Bawiling", "Susanah", "Susantie", "Susiani, S.E", "Susilawati", "Sutri Dahlena, SKM.MSi", "Swari Hadiningsih",
                    "Syafri agus zulbahri", "Syafruddin, Se", "Syahril N. Hilumalo", "Syahrizal, SE,MM", "Syahromadoni", "Syamsir", "Syamsun Nur Syamsuddin", "Syaqmal Raditya Latarang", "Syarifuddin,S.E.", "Syifa Nurul Azizah",
                    "Syofik Maizola", "Syukriani, ST", "Syukrizal", "TITIK PURWANI, S.Sos.", "TRI HASTUTI HANDAYANI", "TRIO DORA WANDA MANANEKE", "TURWAPIT, S.IP", "Tamrin. T", "Tantriati", "Tatik Ika Mustika, S.IP, M.Si",
                    "Tedi Suryo Wibowo", "Teguh Eka Saputro", "Teja dahliawati", "Teni Tanzilal", "Tetty Sinambela", "Theressa Zaratrusha", "Thomas Andrean", "Thomas Bagas Wisnu Putra", "Tiara Ghaitsha Handayani", "Tiara Ramadhani",
                    "Timbul Tua Panggabean", "Tita herita", "Titan Pancawati", "Titi Nurul Hopipah", "Titiek nansriaty", "Titin Maryati", "Titis Sri Hartopo", "Toto Supriyanto", "Tresy Trinita", "Tri Darmawan Sambodho",
                    "Tri Martuti Rini Susanti", "Tri Wahyuning Hastuti", "Tri Windari", "Tumpak Boangmanalu", "Tuti Ismawati", "UNTARI MULYANINGSIH,S.E.", "USMADI", "USMAN, SH, M.Si", "Umar Abdul Syukur", "Umi Kadar Utami",
                    "Uriantono Triwibowo", "Uswah Delsia", "Uun Nurfitriana", "Uyun Fanny Fahraeni, ST.,M.M", "VIRGO ANGELA MARICE LAKE", "VIVI SULVIANTI.SE", "Veni Nur Agustin", "Verawaty Sambine", "Verdi J Pangaribuan", "Veronika susane paula wondal",
                    "Victoryado Shandez Joseph", "Vini Juliarini Putri", "Viona Azzahra", "Vivi Manisha", "WAHIDAH, A.Md", "WAHYU HARSIKIN", "WAHYUNI", "WAHYUNI J. L. PANGARIBUAN, S.E", "WALFRIK ZEBUA", "WIDARTY",
                    "WIDHI LESTARI OKTHARINA", "WINDY HIJJRIANTO ILHAMSYAH, S.Stat.", "WISDA NINGSIH SAFITRI", "Wa Ode Hesti Zuhaliman, S.M", "Wa Ode Sumiati Rusli", "Wahab Sugiarto", "Wahyu Efendy", "Wahyu Panca Pamungkas",
                    "Wahyu Sakti Tri Atmojo", "Wahyu Widiyanto", "Wahyu Yudowibisono", "Wahyuni Lasabuda", "Wahyuniati, S.Sos. M.Si", "Wardaniah Andi Paelori", "Wawan gunawan", "Wayan Ari Sude", "Wayan Sulatri", "Wega Nurhidayah",
                    "Weli Gustia Putri, SEI", "Wendy Burhannurdin", "Wenny Yuliang Prihatin,S Psi.", "Widiar Wahyudi", "Widiawati, Sh", "Widiyo Handono", "Widya Agsari Rallang", "Wijaya Kesuma", "Wijayanti Purnasari", "Wildani Syifaa",
                    "Windi Ahmad Hasyimi, S.Sos", "Windi Astuti", "Windi Triana Sari", "Windy Pradita Harma", "Winna Algustin", "Wiryawan", "Witri amelia", "Wiwin Asmianti", "Wiyono", "YAN PIETER RAUBABA",
                    "YENDRA YADI, S.T.,(ARCH). M.T", "YEYEN TAHIR PALLATJE", "YOESMARFIQ", "YOSI IRAWATI", "YUDHISTARI, SH", "YULIANA TITIARI", "YULISWAN ZN", "YULIUS KARETH", "YURNALIS TITRAWATI TORE, SE", "YUSRIWANTI",
                    "YUSTARI YUSUF", "Yahya Imansyah Girsang", "Yani Fitriyani, S.sos", "Yanita Uly Br Tarigan", "Yannear Al Reza", "Yanni Maria Cristianti Nahas", "Yanti febrini", "Yarmadanis", "Yasin R., S.E.", "Yen Zubriyani",
                    "Yenni Narulitha Anggraeni", "Yeny Rudianto", "Yeremias Andreas Amoye", "Yesi Elvi Cahyanti", "Yogautomo Budinugroho", "Yogi Wibowo", "Yogie Noor Hidayat", "Yohana Agustin Wijayantie, S.E.", "Yon Ersa Rewa", "Yonathan Tanna",
                    "Yoni Oktavia", "Yonita", "Yopi Saproni, SH", "Yori Dharta Wijaya", "Yoseph Moris Magang Sau", "Yova Krisma Hara", "Yovi Yuliana", "Yuda Hardika ,S.sos.", "Yudha Prasetya Maha Putra., SE", "Yudha. S",
                    "Yudik Hendri Hananto", "Yugita Putra Distriawan", "Yuli Mulyasari", "Yuli Sri Wardani", "Yulia Rani", "Yuliana Elu Nino", "Yuliana Ningsih, S. Pd", "Yuliani Safitri", "Yulianis", "Yulianti",
                    "Yulita Andiani", "Yuliyadi Christal Leo Taga Lele", "Yumna Basir", "Yungki Kantiana Taqwa", "Yuni Kurnia Putri", "Yuni Kurniawati", "Yuni Wulandari", "Yunian Prihatini", "Yuniarti, Se", "Yuningsih",
                    "Yunita Dwi Nuraeni, S.Pd", "Yunita Rahmawaty Utami", "Yurida Noerhania", "Yusep Hendarsyah", "Yussiwendi", "Yustina Diana Gama Putri", "Yusuf Ardabili", "Yusuf Fajra Maulia, S.H", "Yusuf Kurniawan", "Yusuf Mochamad",
                    "Yuyum Puspitaningrum", "ZAILLA NURFAZRIANI PONTO", "Zainah Afrianti", "Zainal Abidin", "Zamzam", "Zul Faizah", "Zulfian Hafni Nazar", "Zulfirayanti Abas", "Zulhendri", "afrizal",
                    "akhmad gaos", "akhmad gunawan", "aldi", "andiro Maleani", "arie widyotomo", "asrian darma saputra", "aw. budiansyah", "chairul saleh,SE,M.Si", "citro joyo trisno", "dani setiawan",
    "daniel s dethan", "de viviant", "dian kurniawati subardi, s.ip", "eli fitriani", "esy novialtri", "eta lestari tambunan", "eviyanti", "febrianto", "fitria rozalina, S.Sos", "fitriana wibawanti",
    "hariyono", "i dewa gede juniartana", "indra", "jana silniodi", "kristina royan", "lilis darojah", "luther ta'dung", "maha rani putri", "meria sari umar", "mochamad fajar sigit rahmanto",
    "mokhammad farid maruf", "muh irwan hasib, se", "muhammad aris aprianoor", "muhammad farid", "muhammad taufik,S.Ip", "mumsita iryani", "ni luh putu widyantari", "novita sari", "nyoto budhi astoro", "ovi mawaddah",
    "rahmah gustiha", "rahmi fauziah", "rodianti, S.Sos", "romy andi manik", "salmidawati", "sartono", "saurma rumiris", "sigma kusuma wijaya", "sukardi", "suryani",
    "suryati", "suryono", "susana margretha thei", "susi marini", "syamsul bahri", "syofian", "titiek suyatni wantogia", "vernny moriane sjultje soputan", "victor yuditara", "waode rosliani",
    "warliah", "widia apriyanti.S.STP.MM", "yakobus alex yocom", "yudha setyo nugroho", "zainal guzali"
                ];

                if (!function_exists('render_online_reverification_comparison')) {
                    function render_online_reverification_comparison(array $selectedEmployer): void
                    {
                        $prevSnapshot = null;
                        if (!empty($selectedEmployer['user_id'])) {
                            $stmtPv = db()->prepare("SELECT snapshot_data FROM profile_versions WHERE user_id = ? AND change_summary LIKE '%Sebelum Verifikasi Ulang%' ORDER BY id DESC LIMIT 1");
                            $stmtPv->execute([$selectedEmployer['user_id']]);
                            $pvRow = $stmtPv->fetch();
                            if ($pvRow && !empty($pvRow['snapshot_data'])) {
                                $prevSnapshot = json_decode($pvRow['snapshot_data'], true);
                            }
                            if (!$prevSnapshot) {
                                $stmtPv2 = db()->prepare("SELECT snapshot_data FROM profile_versions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                                $stmtPv2->execute([$selectedEmployer['user_id']]);
                                $pvRow2 = $stmtPv2->fetch();
                                if ($pvRow2 && !empty($pvRow2['snapshot_data'])) {
                                    $prevSnapshot = json_decode($pvRow2['snapshot_data'], true);
                                }
                            }
                        }
                        if (!$prevSnapshot) {
                            $prevSnapshot = $selectedEmployer;
                        }

                        $fieldNamesMap = [
                            'brand_name' => 'Nama Brand Usaha',
                            'description' => 'Deskripsi Tempat Usaha',
                            'same_location_siapkerja' => 'Same location siapkerja',
                            'province' => 'Provinsi',
                            'city' => 'Kabupaten / Kota',
                            'domicile_city_id' => 'Domicile city id',
                            'district' => 'Kecamatan',
                            'village' => 'Kelurahan / Desa',
                            'postal_code' => 'Kode Pos',
                            'address' => 'Alamat Usaha',
                            'address_detail' => 'Detail Patokan Alamat',
                            'latitude' => 'Latitude',
                            'longitude' => 'Longitude',
                            'whatsapp' => 'Nomor WhatsApp',
                            'profession' => 'Jenis Profesi / Usaha Individu',
                            'npwp' => 'NPWP',
                            'permit_document' => 'Dokumen Perizinan Usaha',
                            'workplace_photo' => 'Foto Tempat Usaha / Lokasi',
                            'business_logo' => 'Logo Usaha',
                            'instagram' => 'Instagram',
                            'linkedin' => 'LinkedIn',
                            'facebook' => 'Facebook',
                            'tiktok' => 'TikTok'
                        ];
                        ?>
                        <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; margin-bottom:20px;">
                            <div style="font-size:14.5px; font-weight:800; color:#0f172a; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                <i class="fa-solid fa-table-columns" style="color:#0284c7;"></i> Perbandingan Data Profil (Saat Ini vs Verifikasi Ulang):
                            </div>
                            <div style="border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;">
                                <table style="width:100%; border-collapse:collapse; font-size:12.5px; text-align:left;">
                                    <thead>
                                        <tr style="background:#f1f5f9; color:#475569; font-weight:700; border-bottom:1px solid #cbd5e1;">
                                            <th style="padding:10px 14px; width:28%;">Field / Data</th>
                                            <th style="padding:10px 14px; width:36%;">Data Profil Saat Ini</th>
                                            <th style="padding:10px 14px; width:36%; background:#ecfdf5; color:#065f46;">Verifikasi Ulang Profil</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $renderedCount = 0;
                                        foreach ($fieldNamesMap as $fKey => $fLabel):
                                            $renderedCount++;
                                            $fOldVal = $prevSnapshot[$fKey] ?? ($selectedEmployer[$fKey] ?? '-');
                                            $fNewVal = $selectedEmployer[$fKey] ?? '-';

                                            if ($fKey === 'permit_document') {
                                                $fOldVal = $prevSnapshot['permit_document'] ?? ($prevSnapshot['doc_permission'] ?? '-');
                                                $fNewVal = $selectedEmployer['permit_document'] ?: ($selectedEmployer['doc_permission'] ?: '-');
                                            } elseif ($fKey === 'workplace_photo') {
                                                $fOldVal = $prevSnapshot['workplace_photo'] ?? ($prevSnapshot['doc_location_photo'] ?? '-');
                                                $fNewVal = $selectedEmployer['workplace_photo'] ?: ($selectedEmployer['doc_location_photo'] ?: '-');
                                            } elseif ($fKey === 'same_location_siapkerja') {
                                                $fOldVal = !empty($fOldVal) ? '1' : '0';
                                                $fNewVal = !empty($fNewVal) ? '1' : '0';
                                            }

                                            $isDocField = in_array($fKey, ['permit_document', 'workplace_photo', 'business_logo'], true);
                                        ?>
                                            <tr style="border-bottom:1px solid #f1f5f9; background:<?php echo $renderedCount % 2 === 0 ? '#ffffff' : '#fafafa'; ?>;">
                                                <td style="padding:10px 14px; font-weight:700; color:#334155; vertical-align:top;">
                                                    <?php echo e($fLabel); ?>
                                                </td>
                                                <td style="padding:10px 14px; color:#64748b; vertical-align:top;">
                                                    <?php if ($isDocField && !empty($fOldVal) && $fOldVal !== '-'): ?>
                                                        <a href="<?php echo e($fOldVal); ?>" target="_blank" style="color:#0284c7; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                            <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;"></i> Lihat Berkas Lama
                                                        </a>
                                                    <?php else: ?>
                                                        <?php echo e((string)($fOldVal !== '' ? $fOldVal : '-')); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding:10px 14px; background:#f0fdf4; color:#15803d; font-weight:700; vertical-align:top; border-left:1px solid #dcfce7;">
                                                    <?php if ($isDocField && !empty($fNewVal) && $fNewVal !== '-'): ?>
                                                        <div style="display:flex; align-items:center; gap:10px;">
                                                            <a href="<?php echo e($fNewVal); ?>" target="_blank" style="color:#15803d; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:6px; background:#dcfce7; padding:4px 10px; border-radius:6px; border:1px solid #bbf7d0;">
                                                                <i class="fa-solid fa-file-arrow-down"></i> Buka Berkas Baru
                                                            </a>
                                                            <?php if (in_array(strtolower(pathinfo((string)$fNewVal, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp'], true)): ?>
                                                                <img src="<?php echo e($fNewVal); ?>" alt="Preview" style="max-height:40px; border-radius:4px; border:1px solid #bbf7d0;">
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php echo e((string)($fNewVal !== '' ? $fNewVal : '-')); ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php
                    }
                }

                if (!function_exists('render_admin_employer_profile_form')) {
    function render_admin_employer_profile_form(array $selectedEmployer, string $mode = 'dinas_revision_3'): void
    {
        $isOfflineReverif = ($mode === 'offline_reverif');
        $ownerName = $selectedEmployer['owner_name'] ?: ($selectedEmployer['name'] ?? '');
        $brandName = $selectedEmployer['brand_name'] ?: ($ownerName ? 'Usaha ' . $ownerName : '');
        $nik = $selectedEmployer['nik'] ?? '';
        $phone = $selectedEmployer['phone'] ?? '';
        $whatsapp = $selectedEmployer['whatsapp'] ?: $phone;
        $profession = $selectedEmployer['profession'] ?? '';
        $npwp = $selectedEmployer['npwp'] ?? '';
        $desc = $selectedEmployer['description'] ?? '';

        $sameLoc = !empty($selectedEmployer['same_location_siapkerja']) || !empty($selectedEmployer['workplace_same_as_domicile']);
        $domicileData = [
            'province' => $selectedEmployer['domicile_province'] ?: ($selectedEmployer['province'] ?: 'Bali'),
            'city' => $selectedEmployer['domicile_city'] ?: ($selectedEmployer['city'] ?: ($selectedEmployer['domicile_city_id'] ?? 'Kabupaten Gianyar')),
            'district' => $selectedEmployer['domicile_district'] ?: ($selectedEmployer['district'] ?: 'Ubud'),
            'village' => $selectedEmployer['domicile_village'] ?: ($selectedEmployer['village'] ?: 'Petulu'),
            'postal_code' => $selectedEmployer['domicile_postal_code'] ?: ($selectedEmployer['postal_code'] ?: '80571'),
            'address' => $selectedEmployer['domicile_address'] ?: ($selectedEmployer['address'] ?: 'tes'),
        ];
        $infoIconSvg = '<svg class="tooltip-icon" width="13" height="13" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true" style="display:inline-block; vertical-align:-1px; cursor:pointer;"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>';

        // Unified single location
        $province = $selectedEmployer['province'] ?: ($selectedEmployer['workplace_province'] ?: ($selectedEmployer['domicile_province'] ?? ''));
        $city = $selectedEmployer['city'] ?: ($selectedEmployer['workplace_city'] ?: ($selectedEmployer['domicile_city'] ?? ''));
        $district = $selectedEmployer['district'] ?: ($selectedEmployer['workplace_district'] ?: ($selectedEmployer['domicile_district'] ?? ''));
        $village = $selectedEmployer['village'] ?: ($selectedEmployer['workplace_village'] ?: ($selectedEmployer['domicile_village'] ?? ''));
        $postalCode = $selectedEmployer['postal_code'] ?: ($selectedEmployer['workplace_postal_code'] ?: ($selectedEmployer['domicile_postal_code'] ?? ''));
        $address = $selectedEmployer['address'] ?: ($selectedEmployer['workplace_address'] ?: ($selectedEmployer['domicile_address'] ?? ''));
        $addressDetail = $selectedEmployer['address_detail'] ?: ($selectedEmployer['workplace_detail'] ?? '');

        // Computed display text for hierarchical location picker
        $locParts = array_filter([$village, $district, $city, $province]);
        $locDisplayText = !empty($locParts) ? implode(', ', $locParts) : '';

        // Documents
        $permitDoc = $selectedEmployer['permit_document'] ?: ($selectedEmployer['doc_permission'] ?: '');
        $workPhoto = $selectedEmployer['workplace_photo'] ?: ($selectedEmployer['doc_location_photo'] ?: '');
        $bizLogo = $selectedEmployer['business_logo'] ?: ($selectedEmployer['brand_logo'] ?? '');

        // Social Media
        $ig = $selectedEmployer['instagram'] ?? '';
        $li = $selectedEmployer['linkedin'] ?? '';
        $fb = $selectedEmployer['facebook'] ?? '';
        $tt = $selectedEmployer['tiktok'] ?? '';

        $existingSocials = [];
        if (!empty($li)) $existingSocials[] = ['platform' => 'LinkedIn', 'handle' => $li];
        if (!empty($fb)) $existingSocials[] = ['platform' => 'Facebook', 'handle' => $fb];
        if (!empty($ig)) $existingSocials[] = ['platform' => 'Instagram', 'handle' => $ig];
        if (!empty($tt)) $existingSocials[] = ['platform' => 'TikTok', 'handle' => $tt];
        if (empty($existingSocials)) {
            $existingSocials[] = ['platform' => 'LinkedIn', 'handle' => ''];
        }

        $professionGroups = function_exists('individual_business_types') ? individual_business_types() : [
            'Kesehatan & Terapi' => ['Praktik Dokter Umum', 'Praktik Dokter Gigi', 'Praktik Bidan', 'Praktik Keperawatan Mandiri', 'Praktik Psikolog', 'Layanan Fisioterapi', 'Praktik Terapis Wicara', 'Layanan Akupunktur', 'Layanan Terapi Tumbuh Kembang Anak', 'Layanan Konsultasi Gizi/Nutrisionis'],
            'Hukum, Keuangan & Jasa Profesional' => ['Praktik Notaris / PPAT', 'Praktik Advokat/Pengacara / Konsultan Hukum', 'Konsultan Pajak', 'Jasa Akuntansi', 'Konsultan Manajemen', 'Jasa Administrasi dan Pengurusan Dokumen', 'Jasa Penerjemah'],
            'Teknik, Arsitektur & Desain' => ['Praktik Arsitek', 'Jasa Desain Interior', 'Jasa Drafter', 'Jasa Perbaikan/Bengkel Teknik (Instalasi Listrik & Elektronik)'],
            'Pendidikan & Pelatihan' => ['Bimbingan Belajar', 'Kursus Bahasa', 'Kursus Musik', 'Kursus Tari'],
            'Jasa Acara, Kreatif & Hewan Peliharaan' => ['Studio Foto dan Video', 'Jasa Fotografi/Videografi', 'Jasa Dekorasi Acara', 'Jasa Penjahit/Atelier', 'Jasa Grooming Hewan', 'Jasa Desain Grafis', 'Jasa Florist/Dekorasi Bunga'],
            'Digital, Media & Konten' => ['Konten Kreator/Digital Creator', 'Jasa Editing Video / Transfer Video', 'Jasa Live Streaming / Host Live Streaming', 'Studio Podcast'],
            'Kecantikan & Perawatan Pribadi' => ['Barbershop', 'Makeup Artist'],
            'Jasa Rumah Tangga, Pemeliharaan & Perbaikan' => ['Jasa Laundry', 'Jasa Servis AC']
        ];
        $flatProfessions = [];
        foreach ($professionGroups as $groupItems) {
            foreach ($groupItems as $item) {
                $flatProfessions[] = $item;
            }
        }
        ?>
        <form id="adm_profile_form_<?php echo $mode; ?>" method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>&profile_id=<?php echo $selectedEmployer['id']; ?>" enctype="multipart/form-data" style="margin-top:16px;">
            <input type="hidden" name="admin_action" value="manual_dinas_edit">
            <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">

            <!-- Hidden duplicate fields for comprehensive backend compatibility -->
            <input type="hidden" name="workplace_province" id="adm_wp_prov_<?php echo $mode; ?>" value="<?php echo e($province); ?>">
            <input type="hidden" name="workplace_city" id="adm_wp_city_<?php echo $mode; ?>" value="<?php echo e($city); ?>">
            <input type="hidden" name="workplace_district" id="adm_wp_dist_<?php echo $mode; ?>" value="<?php echo e($district); ?>">
            <input type="hidden" name="workplace_village" id="adm_wp_vill_<?php echo $mode; ?>" value="<?php echo e($village); ?>">
            <input type="hidden" name="workplace_postal_code" id="adm_wp_post_<?php echo $mode; ?>" value="<?php echo e($postalCode); ?>">
            <input type="hidden" name="workplace_address" id="adm_wp_addr_<?php echo $mode; ?>" value="<?php echo e($address); ?>">
            <input type="hidden" name="workplace_detail" id="adm_wp_detail_<?php echo $mode; ?>" value="<?php echo e($addressDetail); ?>">
            <input type="hidden" name="domicile_province" id="adm_dp_prov_<?php echo $mode; ?>" value="<?php echo e($province); ?>">
            <input type="hidden" name="domicile_city" id="adm_dp_city_<?php echo $mode; ?>" value="<?php echo e($city); ?>">
            <input type="hidden" name="domicile_district" id="adm_dp_dist_<?php echo $mode; ?>" value="<?php echo e($district); ?>">
            <input type="hidden" name="domicile_village" id="adm_dp_vill_<?php echo $mode; ?>" value="<?php echo e($village); ?>">
            <input type="hidden" name="domicile_postal_code" id="adm_dp_post_<?php echo $mode; ?>" value="<?php echo e($postalCode); ?>">
            <input type="hidden" name="domicile_address" id="adm_dp_addr_<?php echo $mode; ?>" value="<?php echo e($address); ?>">

            <!-- 1. IDENTITAS PEMBERI KERJA INDIVIDU (LANGKAH 1) -->
            <div style="font-size:12.5px; font-weight:800; color:#0369a1; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:14px; padding-bottom:6px; border-bottom:1.5px solid #e0f2fe; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-user"></i> 1. Identitas Pemberi Kerja Individu
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                <div>
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Nama Pemberi Kerja <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" name="owner_name" value="<?php echo e($ownerName); ?>" required placeholder="Masukkan nama lengkap" style="width:100%; height:40px; padding:8px 12px; font-size:13.5px; border:1.5px solid #cbd5e1; border-radius:8px; color:#0f172a; box-sizing:border-box; background:#ffffff;">
                    <div style="font-size:11px; color:#64748b; margin-top:4px;">Terisi otomatis dari akun SIAPKerja.</div>
                </div>
                <div>
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        NIK (16 Digit) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" name="nik" id="admin_form_nik_<?php echo $mode; ?>" value="<?php echo e($nik); ?>" required maxlength="16" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="Masukkan 16 digit NIK" style="width:100%; height:40px; padding:8px 12px; font-size:13.5px; border:1.5px solid #cbd5e1; border-radius:8px; color:#0f172a; box-sizing:border-box; background:#ffffff;">
                    <div style="font-size:11px; color:#64748b; margin-top:4px;">Terisi otomatis dari akun SIAPKerja.</div>
                </div>
                <div>
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Nomor Telepon Aktif <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" name="phone" id="admin_form_phone_<?php echo $mode; ?>" value="<?php echo e($phone); ?>" required maxlength="15" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="08xxxxxxxxxx" style="width:100%; height:40px; padding:8px 12px; font-size:13.5px; border:1.5px solid #cbd5e1; border-radius:8px; color:#0f172a; box-sizing:border-box; background:#ffffff;">
                    <div style="font-size:11px; color:#64748b; margin-top:4px;">Terisi otomatis dari akun SIAPKerja.</div>
                </div>
                <div>
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Jenis Usaha Individu <span style="color:#ef4444;">*</span>
                        <span class="admin-tooltip" tabindex="0">
                            <i class="fa-solid fa-circle-info" style="font-size:12px; color:#0284c7; cursor:pointer;"></i>
                            <span class="admin-tooltip-text">Pilih jenis usaha perorangan yang dijalankan pemohon.</span>
                        </span>
                    </label>
                    <select name="profession" id="adm_select_profession_<?php echo $mode; ?>" onchange="adminUpdatePermitDocHelper(this.value, '<?php echo $mode; ?>')" required style="width:100%; height:40px; padding:8px 12px; font-size:13px; border:1.5px solid #cbd5e1; border-radius:8px; color:#0f172a; box-sizing:border-box; background:#ffffff;">
                        <option value="">Pilih Jenis Usaha Individu</option>
                        <?php foreach ($professionGroups as $grp => $items): ?>
                            <optgroup label="<?php echo e($grp); ?>">
                                <?php foreach ($items as $item): ?>
                                    <option value="<?php echo e($item); ?>" <?php echo ($profession === $item) ? 'selected' : ''; ?>><?php echo e($item); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div style="font-size:11px; color:#64748b; margin-top:4px;">Pilih jenis usaha perorangan yang Anda jalankan.</div>
                </div>
                <div>
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Nomor WhatsApp <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" name="whatsapp" id="admin_form_wa_<?php echo $mode; ?>" value="<?php echo e($whatsapp); ?>" required maxlength="15" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="08xxxxxxxxxx" style="width:100%; height:40px; padding:8px 12px; font-size:13.5px; border:1.5px solid #cbd5e1; border-radius:8px; color:#0f172a; box-sizing:border-box; background:#ffffff;">
                    <div style="font-size:11px; color:#64748b; margin-top:4px;">Gunakan nomor WhatsApp aktif.</div>
                    <div style="margin-top:6px;">
                        <label style="font-size:12px; font-weight:500; color:#334155; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" onchange="if(this.checked){ const p=document.getElementById('admin_form_phone_<?php echo $mode; ?>'); const w=document.getElementById('admin_form_wa_<?php echo $mode; ?>'); if(p&&w) w.value=p.value; }" style="width:14px; height:14px; accent-color:#0284c7; cursor:pointer;" <?php echo (!empty($phone) && $phone === $whatsapp) ? 'checked' : ''; ?>>
                            <span>Samakan dengan Nomor Telepon Aktif</span>
                        </label>
                    </div>
                </div>
                <div>
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Status &amp; Nomor NPWP <span style="color:#ef4444;">*</span>
                        <span class="admin-tooltip" tabindex="0">
                            <i class="fa-solid fa-circle-info" style="font-size:12px; color:#0284c7; cursor:pointer;"></i>
                            <span class="admin-tooltip-text">Informasi: Untuk Wajib Pajak Orang Pribadi yang merupakan penduduk Indonesia, NIK digunakan sebagai NPWP.</span>
                        </span>
                    </label>
                    <input type="text" name="npwp" id="admin_form_npwp_<?php echo $mode; ?>" value="<?php echo e($npwp ?: $nik); ?>" placeholder="Masukkan NPWP atau 16 digit NIK" style="width:100%; height:40px; padding:8px 12px; font-size:13.5px; border:1.5px solid #cbd5e1; border-radius:8px; color:#0f172a; box-sizing:border-box; background:#ffffff;">
                    <div style="margin-top:6px;">
                        <label style="font-size:12px; font-weight:500; color:#334155; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                            <input type="checkbox" name="npwp_status" value="1" onchange="if(this.checked){ const n=document.getElementById('admin_form_nik_<?php echo $mode; ?>'); const p=document.getElementById('admin_form_npwp_<?php echo $mode; ?>'); if(n&&p&&n.value) p.value=n.value; }" style="width:14px; height:14px; accent-color:#0284c7; cursor:pointer;" <?php echo (!empty($npwp) || !empty($selectedEmployer['npwp_status'])) ? 'checked' : ''; ?>>
                            <span>Saya terdaftar sebagai Wajib Pajak Orang Pribadi dan menggunakan NIK sebagai NPWP</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- 2. PROFIL USAHA / KEGIATAN & LOKASI (LANGKAH 2 - UNIFIED MATCHING FORM) -->
            <div style="font-size:12.5px; font-weight:800; color:#0369a1; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:14px; padding-bottom:6px; border-bottom:1.5px solid #e0f2fe; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-store"></i> 2. Profil Usaha / Kegiatan &amp; Lokasi
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                <div>
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Nama Brand Usaha <span style="color:#ef4444;">*</span>
                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                            <?php echo $infoIconSvg; ?>
                            <span class="tooltip-popover">Nama merek dagang, brand, atau nama komersial tempat usaha/kegiatan Anda.</span>
                        </span>
                    </label>
                    <input type="text" name="brand_name" value="<?php echo e($brandName); ?>" required placeholder="Masukkan nama brand usaha" style="width:100%; height:40px; padding:8px 12px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px; color:#0f172a; box-sizing:border-box; background:#ffffff;">
                    <div style="font-size:11.5px; color:#64748b; margin-top:4px;">Nama brand atau merek usaha/kegiatan yang dijalankan.</div>
                </div>
                <div>
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Logo Usaha
                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                            <?php echo $infoIconSvg; ?>
                            <span class="tooltip-popover">Unggah logo resmi atau foto identitas visual usaha/kegiatan Anda.</span>
                        </span>
                    </label>
                    <div class="pki-upload-box" id="adm_box_business_logo_<?php echo $mode; ?>" onclick="adminTriggerUpload('business_logo_<?php echo $mode; ?>')">
                        <span class="pki-upload-text <?php echo !empty($bizLogo) ? 'has-file' : ''; ?>" id="adm_text_business_logo_<?php echo $mode; ?>">
                            <?php if (!empty($bizLogo)): ?>
                                <i class="fa-solid fa-image" style="color:#0284c7; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($bizLogo)); ?></span>
                            <?php else: ?>
                                <span>Klik dan letakkan fil...</span>
                            <?php endif; ?>
                        </span>
                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                            <?php if (!empty($bizLogo)): ?>
                                <a href="<?php echo e($bizLogo); ?>" target="_blank" class="pki-upload-clear-btn" title="Lihat Logo" onclick="event.stopPropagation();" style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:4px; background:#f1f5f9; color:#0284c7; text-decoration:none;">
                                    <i class="fa-solid fa-eye" style="font-size:11px;"></i>
                                </a>
                                <button type="button" class="pki-upload-clear-btn" title="Hapus logo" onclick="adminClearUpload(event, 'business_logo_<?php echo $mode; ?>')">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="pki-upload-btn" id="adm_btn_business_logo_<?php echo $mode; ?>"><?php echo !empty($bizLogo) ? 'Ganti' : 'Pilih File'; ?></button>
                        </div>
                    </div>
                    <input type="file" name="business_logo" id="adm_input_business_logo_<?php echo $mode; ?>" accept=".png,.jpg,.jpeg" style="display:none;" onchange="adminHandleFileChange(this, 'business_logo_<?php echo $mode; ?>')">
                    <input type="hidden" name="existing_business_logo" id="adm_existing_business_logo_<?php echo $mode; ?>" value="<?php echo e($bizLogo); ?>">
                    <div style="font-size:11.5px; color:#64748b; margin-top:4px;">Ukuran maksimum 2 MB • Format PNG, JPG</div>
                </div>
                <div style="grid-column: span 2;">
                    <label style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Deskripsi Singkat Usaha / Rekrutmen <span style="color:#ef4444;">*</span>
                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                            <?php echo $infoIconSvg; ?>
                            <span class="tooltip-popover">Jelaskan secara singkat usaha atau kegiatan yang dijalankan serta gambaran kebutuhan rekrutmen yang dilakukan.</span>
                        </span>
                    </label>
                    <textarea name="description" required placeholder="Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; min-height:75px; color:#0f172a; box-sizing:border-box; background:#ffffff; font-family:inherit; resize:vertical;"><?php echo e($desc); ?></textarea>
                    <div style="font-size:11.5px; color:#64748b; margin-top:4px;">Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.</div>
                </div>
            </div>

            <!-- CHECKBOX SAMA DENGAN ALAMAT DOMISILI SIAPKERJA (IMAGE 2) -->
            <div style="margin-bottom:14px;">
                <label style="font-size:12.5px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="same_location_siapkerja" id="admin_same_location_<?php echo $mode; ?>" value="1" <?php echo $sameLoc ? 'checked' : ''; ?> style="accent-color:#0284c7; cursor:pointer;" onchange="adminHandleSameLocationToggle(this, '<?php echo $mode; ?>')">
                    <span>Gunakan alamat domisili di SIAPKerja sebagai lokasi tempat usaha/kegiatan</span>
                </label>
            </div>

            <!-- Hidden Location Fields -->
            <input type="hidden" name="province" id="adm_hiddenProvince_<?php echo $mode; ?>" value="<?php echo e($province); ?>">
            <input type="hidden" name="city" id="adm_hiddenCity_<?php echo $mode; ?>" value="<?php echo e($city); ?>">
            <input type="hidden" name="district" id="adm_hiddenDistrict_<?php echo $mode; ?>" value="<?php echo e($district); ?>">
            <input type="hidden" name="village" id="adm_hiddenVillage_<?php echo $mode; ?>" value="<?php echo e($village); ?>">
            <input type="hidden" name="domicile_city_id" id="adm_hiddenDomicileCityId_<?php echo $mode; ?>" value="<?php echo e($city); ?>">
            <input type="hidden" name="latitude" id="adm_inputLat_<?php echo $mode; ?>" value="<?php echo e($selectedEmployer['latitude'] ?? '-6.887844'); ?>">
            <input type="hidden" name="longitude" id="adm_inputLng_<?php echo $mode; ?>" value="<?php echo e($selectedEmployer['longitude'] ?? '107.613038'); ?>">

            <!-- Lokasi Tempat Usaha / Kegiatan (IMAGE 2) -->
            <div class="pki-field" style="position:relative; margin-bottom:14px;">
                <label class="pki-field-label" id="label_adm_location_<?php echo $mode; ?>" for="adm_hierarchicalLocationInput_<?php echo $mode; ?>" style="font-size:12.5px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                    Lokasi Tempat Usaha / Kegiatan <span class="req" style="color:#ef4444;">*</span>
                    <span class="pki-tooltip" tabindex="0" role="tooltip">
                        <?php echo $infoIconSvg; ?>
                        <span class="tooltip-popover">Lokasi tempat Anda menjalankan usaha atau kegiatan sebagai Pemberi Kerja Individu.</span>
                    </span>
                </label>
                <div id="adm_hierarchicalLocationInput_<?php echo $mode; ?>" class="hierarchical-loc-field" tabindex="0" onclick="adminToggleLocDropdown('<?php echo $mode; ?>')">
                    <span id="adm_locDisplayValue_<?php echo $mode; ?>" class="<?php echo !empty($locDisplayText) ? '' : 'placeholder'; ?>"><?php echo !empty($locDisplayText) ? e($locDisplayText) : 'Pilih lokasi tempat usaha / kegiatan'; ?></span>
                    <i id="adm_locCaret_<?php echo $mode; ?>" class="fa-solid fa-chevron-down" style="color:#64748b; font-size:12px;"></i>
                </div>
                <div id="adm_hierarchicalLocDropdown_<?php echo $mode; ?>" class="loc-dropdown-popup" style="display:none;">
                    <div id="adm_locBreadcrumbs_<?php echo $mode; ?>" class="loc-breadcrumbs">
                        <span class="crumb-btn active" data-level="1">Pilih Provinsi</span>
                    </div>
                    <div id="adm_locOptionsList_<?php echo $mode; ?>" class="loc-options-list"></div>
                </div>
                <div class="pki-field-helper" style="font-size:11.5px; color:#64748b; margin-top:4px;">Pilih lokasi secara berjenjang sampai Kelurahan/Desa.</div>
            </div>

            <!-- Alamat Lengkap Tempat Usaha (IMAGE 2) -->
            <div class="pki-field" style="margin-bottom:14px;">
                <label class="pki-field-label" id="label_adm_address_<?php echo $mode; ?>" for="adm_inputAddress_<?php echo $mode; ?>" style="font-size:12.5px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                    Alamat Lengkap Tempat Usaha <span class="req" style="color:#ef4444;">*</span>
                </label>
                <input type="text" name="address" id="adm_inputAddress_<?php echo $mode; ?>" value="<?php echo e($address); ?>" placeholder="Masukkan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap..." oninput="adminUpdateMapPreview('<?php echo $mode; ?>')" style="width:100%; height:38px; padding:6px 12px; font-size:12.5px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box; color:#0f172a; background:#ffffff;">
                <div class="pki-field-helper" style="font-size:11.5px; color:#64748b; margin-top:4px;">Tuliskan alamat lengkap tempat usaha/kegiatan.</div>
            </div>

            <!-- Kode Pos (IMAGE 2) -->
            <div class="pki-field" style="margin-bottom:14px;">
                <label class="pki-field-label" id="label_adm_postal_code_<?php echo $mode; ?>" for="adm_selectPostalCode_<?php echo $mode; ?>" style="font-size:12.5px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                    Kode Pos <span class="req" style="color:#ef4444;">*</span>
                </label>
                <select name="postal_code" id="adm_selectPostalCode_<?php echo $mode; ?>" style="width:100%; height:38px; padding:6px 12px; font-size:12.5px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box; color:#0f172a; background:#ffffff;">
                    <option value="">Pilih kode pos</option>
                    <?php if (!empty($postalCode)): ?>
                        <option value="<?php echo e($postalCode); ?>" selected><?php echo e($postalCode); ?></option>
                    <?php endif; ?>
                </select>
                <div class="pki-field-helper" style="font-size:11.5px; color:#64748b; margin-top:4px;">Pilihan kode pos mengikuti lokasi yang dipilih.</div>
            </div>

            <!-- Detail Alamat / Patokan (Opsional) (IMAGE 2) -->
            <div class="pki-field" style="margin-bottom:14px;">
                <label class="pki-field-label" id="label_adm_address_detail_<?php echo $mode; ?>" for="adm_inputAddressDetail_<?php echo $mode; ?>" style="font-size:12.5px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                    Detail Alamat / Patokan (Opsional)
                    <span class="pki-tooltip" tabindex="0" role="tooltip">
                        <?php echo $infoIconSvg; ?>
                        <span class="tooltip-popover">Tambahkan informasi yang membantu mengenali lokasi, seperti nomor bangunan, blok, lantai, atau patokan terdekat.</span>
                    </span>
                </label>
                <input type="text" name="address_detail" id="adm_inputAddressDetail_<?php echo $mode; ?>" value="<?php echo e($addressDetail); ?>" placeholder="Contoh: Ruko lantai 2, sebelah Kantor Kelurahan" style="width:100%; height:38px; padding:6px 12px; font-size:12.5px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box; color:#0f172a; background:#ffffff;">
                <div class="pki-field-helper" style="font-size:11.5px; color:#64748b; margin-top:4px;">Opsional. Tambahkan informasi yang membantu mengenali lokasi.</div>
            </div>

            <!-- Peta Lokasi (IMAGE 2) -->
            <div class="pki-field" style="margin-bottom:20px;">
                <label class="pki-field-label" style="font-size:12.5px; font-weight:700; color:#1e293b; display:block; margin-bottom:6px;">Peta Lokasi</label>
                <div id="adm_mapContainer_<?php echo $mode; ?>" class="pki-map-container" style="height:190px; border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; position:relative; background:#f8fafc;">
                    <div id="adm_mapPlaceholder_<?php echo $mode; ?>" class="pki-map-placeholder <?php echo (!empty($address) || !empty($city)) ? 'is-hidden' : ''; ?>" style="<?php echo (!empty($address) || !empty($city)) ? 'display:none;' : 'display:flex;'; ?> flex-direction:column; align-items:center; justify-content:center; height:100%; text-align:center; padding:20px;">
                        <div style="font-size:26px; margin-bottom:4px;">🗺</div>
                        <div style="font-size:13px; font-weight:700; color:#334155; margin-bottom:2px;">Pratinjau peta belum tersedia</div>
                        <div style="font-size:11.5px; color:#64748b; max-width:340px; line-height:1.4;">Lengkapi lokasi dan alamat terlebih dahulu.</div>
                    </div>
                    <div id="adm_googleMapWrapper_<?php echo $mode; ?>" class="pki-map-wrapper <?php echo (!empty($address) || !empty($city)) ? 'is-visible' : ''; ?>" style="<?php echo (!empty($address) || !empty($city)) ? 'display:block;' : 'display:none;'; ?> width:100%; height:100%;">
                        <iframe id="adm_gmapIframe_<?php echo $mode; ?>" width="100%" height="100%" style="border:0;" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" src="<?php echo (!empty($address) || !empty($city)) ? 'https://maps.google.com/maps?q=' . urlencode(trim(($address ? $address . ', ' : '') . ($village ? $village . ', ' : '') . ($district ? $district . ', ' : '') . ($city ? $city . ', ' : '') . $province)) . '&output=embed' : 'about:blank'; ?>"></iframe>
                    </div>
                </div>
                <div class="pki-field-helper" style="font-size:11.5px; color:#64748b; margin-top:6px;">Peta muncul otomatis setelah lokasi dan alamat lengkap tersedia.</div>
            </div>

            <!-- 3. DOKUMEN & BUKTI TEMPAT USAHA (IMAGE 1) -->
            <div class="section-title" style="font-size:14px; font-weight:800; color:#0f172a; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                3. DOKUMEN &amp; BUKTI TEMPAT USAHA
            </div>
            <div class="pki-grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
                <!-- Dokumen Perizinan Usaha (IMAGE 1) -->
                <div class="pki-field">
                    <label class="pki-field-label" style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Dokumen Perizinan Usaha <span class="req" style="color:#ef4444;">*</span>
                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                            <?php echo $infoIconSvg; ?>
                            <span class="tooltip-popover">Dokumen yang diunggah harus sesuai dengan Jenis Usaha Individu yang dipilih. Format PDF/JPG, maksimal 2 MB per file.</span>
                        </span>
                    </label>
                    <div class="pki-upload-box" id="adm_box_permit_document_<?php echo $mode; ?>" onclick="adminTriggerUpload('permit_document_<?php echo $mode; ?>')">
                        <span class="pki-upload-text <?php echo !empty($permitDoc) ? 'has-file' : ''; ?>" id="adm_text_permit_document_<?php echo $mode; ?>">
                            <?php if (!empty($permitDoc)): ?>
                                <i class="fa-solid fa-file-pdf" style="color:#ef4444; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($permitDoc)); ?></span>
                            <?php else: ?>
                                <span>Klik dan letakkan fil...</span>
                            <?php endif; ?>
                        </span>
                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                            <?php if (!empty($permitDoc)): ?>
                                <a href="<?php echo e($permitDoc); ?>" target="_blank" class="pki-upload-clear-btn" title="Lihat Berkas" onclick="event.stopPropagation();" style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:4px; background:#f1f5f9; color:#0284c7; text-decoration:none;">
                                    <i class="fa-solid fa-eye" style="font-size:11px;"></i>
                                </a>
                                <button type="button" class="pki-upload-clear-btn" title="Hapus berkas" onclick="adminClearUpload(event, 'permit_document_<?php echo $mode; ?>')">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="pki-upload-btn" id="adm_btn_permit_document_<?php echo $mode; ?>"><?php echo !empty($permitDoc) ? 'Ganti' : 'Pilih File'; ?></button>
                        </div>
                    </div>
                    <input type="file" name="permit_document" id="adm_input_permit_document_<?php echo $mode; ?>" accept=".pdf,.docx,.jpg,.jpeg" style="display:none;" onchange="adminHandleFileChange(this, 'permit_document_<?php echo $mode; ?>')">
                    <input type="hidden" name="existing_permit_document" id="adm_existing_permit_document_<?php echo $mode; ?>" value="<?php echo e($permitDoc); ?>">
                    <div class="pki-field-helper" id="adm_helper_permit_document_<?php echo $mode; ?>" style="margin-top:6px;">
                        <?php echo function_exists('get_permit_document_helper_html') ? get_permit_document_helper_html($profession) : ''; ?>
                    </div>
                </div>

                <!-- Foto Bukti Tempat Usaha / Lokasi (IMAGE 1) -->
                <div class="pki-field">
                    <label class="pki-field-label" style="font-size:13px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:4px; margin-bottom:6px;">
                        Foto Bukti Tempat Usaha / Lokasi <span class="req" style="color:#ef4444;">*</span>
                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                            <?php echo $infoIconSvg; ?>
                            <span class="tooltip-popover">Unggah foto yang menunjukkan kondisi tempat usaha, lokasi kegiatan, dan/atau sarana pendukung usaha sebagai bukti aktivitas usaha.</span>
                        </span>
                    </label>
                    <div class="pki-upload-box" id="adm_box_workplace_photo_<?php echo $mode; ?>" onclick="adminTriggerUpload('workplace_photo_<?php echo $mode; ?>')">
                        <span class="pki-upload-text <?php echo !empty($workPhoto) ? 'has-file' : ''; ?>" id="adm_text_workplace_photo_<?php echo $mode; ?>">
                            <?php if (!empty($workPhoto)): ?>
                                <i class="fa-solid fa-image" style="color:#0284c7; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($workPhoto)); ?></span>
                            <?php else: ?>
                                <span>Klik dan letakkan fil...</span>
                            <?php endif; ?>
                        </span>
                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                            <?php if (!empty($workPhoto)): ?>
                                <a href="<?php echo e($workPhoto); ?>" target="_blank" class="pki-upload-clear-btn" title="Lihat Foto" onclick="event.stopPropagation();" style="display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:4px; background:#f1f5f9; color:#0284c7; text-decoration:none;">
                                    <i class="fa-solid fa-eye" style="font-size:11px;"></i>
                                </a>
                                <button type="button" class="pki-upload-clear-btn" title="Hapus foto" onclick="adminClearUpload(event, 'workplace_photo_<?php echo $mode; ?>')">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="pki-upload-btn" id="adm_btn_workplace_photo_<?php echo $mode; ?>"><?php echo !empty($workPhoto) ? 'Ganti' : 'Pilih File'; ?></button>
                        </div>
                    </div>
                    <input type="file" name="workplace_photo" id="adm_input_workplace_photo_<?php echo $mode; ?>" accept=".png,.jpg,.jpeg" style="display:none;" onchange="adminHandleFileChange(this, 'workplace_photo_<?php echo $mode; ?>')">
                    <input type="hidden" name="existing_workplace_photo" id="adm_existing_workplace_photo_<?php echo $mode; ?>" value="<?php echo e($workPhoto); ?>">
                    <div class="pki-field-helper" id="adm_helper_workplace_photo_<?php echo $mode; ?>" style="margin-top:6px;">
                        <div class="pki-permit-doc-helper-wrap">
                            <div class="pki-permit-doc-title" style="font-weight:600; color:#334155; margin-bottom:2px; font-size:12px;">Foto yang perlu diunggah:</div>
                            <div class="pki-permit-doc-requirement" style="color:#475569; margin-bottom:4px; line-height:1.45; font-size:12px;">Foto tempat usaha, lokasi kegiatan, atau sarana pendukung usaha.</div>
                            <div class="pki-permit-doc-format" style="color:#64748b; font-size:11px;">Format PNG/JPG, maksimal 2 MB per file.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. MEDIA SOSIAL (LANGKAH 3 - UNIFIED SOCIAL CONTAINER) -->
            <div style="font-size:12.5px; font-weight:800; color:#0369a1; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; padding-bottom:6px; border-bottom:1.5px solid #e0f2fe; display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-share-nodes"></i> 4. Media Sosial <span style="color:#ef4444;">*</span>
            </div>
            <div style="font-size:12px; color:#64748b; margin-bottom:12px;">
                Wajib mengisi minimal salah satu akun media sosial (LinkedIn, Facebook, Instagram, atau TikTok).
            </div>

            <!-- Hidden compatibility inputs synced automatically -->
            <input type="hidden" name="linkedin" id="adm_soc_linkedin_<?php echo $mode; ?>" value="<?php echo e($li); ?>">
            <input type="hidden" name="facebook" id="adm_soc_facebook_<?php echo $mode; ?>" value="<?php echo e($fb); ?>">
            <input type="hidden" name="instagram" id="adm_soc_instagram_<?php echo $mode; ?>" value="<?php echo e($ig); ?>">
            <input type="hidden" name="tiktok" id="adm_soc_tiktok_<?php echo $mode; ?>" value="<?php echo e($tt); ?>">

            <div class="pki-social-container" id="adm_social_container_<?php echo $mode; ?>" style="display:flex; flex-direction:column; gap:10px; margin-bottom:20px;">
                <?php 
                $socialMetaMap = [
                    'LinkedIn'  => ['icon' => 'fa-brands fa-linkedin', 'color' => '#0a66c2', 'placeholder' => 'username atau https://linkedin.com/in/...'],
                    'Facebook'  => ['icon' => 'fa-brands fa-facebook', 'color' => '#1877f2', 'placeholder' => '@username atau https://facebook.com/...'],
                    'Instagram' => ['icon' => 'fa-brands fa-instagram', 'color' => '#e1306c', 'placeholder' => '@username atau https://instagram.com/...'],
                    'TikTok'    => ['icon' => 'fa-brands fa-tiktok', 'color' => '#000000', 'placeholder' => '@username atau https://tiktok.com/@...'],
                ];
                $totalSoc = count($existingSocials);
                foreach ($existingSocials as $sIdx => $socItem): 
                    $curPlat = $socItem['platform'];
                    $curHandle = $socItem['handle'];
                    $platMeta = $socialMetaMap[$curPlat] ?? $socialMetaMap['LinkedIn'];
                    $isLast = ($sIdx === $totalSoc - 1);
                ?>
                <div class="pki-social-row" data-index="<?php echo $sIdx; ?>" style="display:flex; align-items:center; gap:8px;">
                    <div class="pki-social-select-wrap" style="position:relative; width:170px; flex-shrink:0;">
                        <span class="pki-social-platform-icon" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:16px;">
                            <i class="<?php echo $platMeta['icon']; ?>" style="color:<?php echo $platMeta['color']; ?>;"></i>
                        </span>
                        <select name="social_platform[]" class="pki-social-platform-select" style="width:100%; height:40px; padding:6px 10px 6px 36px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; color:#0f172a; background:#ffffff; box-sizing:border-box;">
                            <option value="LinkedIn" <?php echo $curPlat === 'LinkedIn' ? 'selected' : ''; ?>>LinkedIn</option>
                            <option value="Facebook" <?php echo $curPlat === 'Facebook' ? 'selected' : ''; ?>>Facebook</option>
                            <option value="Instagram" <?php echo $curPlat === 'Instagram' ? 'selected' : ''; ?>>Instagram</option>
                            <option value="TikTok" <?php echo $curPlat === 'TikTok' ? 'selected' : ''; ?>>TikTok</option>
                        </select>
                    </div>
                    <div class="pki-social-input-wrap" style="flex:1;">
                        <input type="text" name="social_handle[]" class="pki-social-handle-input" value="<?php echo e($curHandle); ?>" placeholder="<?php echo $platMeta['placeholder']; ?>" style="width:100%; height:40px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; color:#0f172a; box-sizing:border-box;">
                    </div>
                    <div class="pki-social-actions" style="display:flex; gap:6px;">
                        <?php if ($totalSoc > 1): ?>
                            <button type="button" class="pki-social-btn pki-social-btn-del" title="Hapus akun" style="width:36px; height:36px; border:1px solid #fee2e2; background:#fef2f2; color:#ef4444; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        <?php endif; ?>
                        <?php if ($isLast && $totalSoc < 4): ?>
                            <button type="button" class="pki-social-btn pki-social-btn-add" title="Tambah akun" style="width:36px; height:36px; border:1px solid #bae6fd; background:#f0f9ff; color:#0284c7; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 5. PERNYATAAN (LANGKAH 4) -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; font-size:12.5px; color:#334155; line-height:1.6; margin-bottom:16px;">
                Saya menyatakan bahwa seluruh informasi yang diisikan benar, valid, dan dapat dipertanggungjawabkan sesuai data faktual pemberi kerja.
            </div>

            <!-- SAVE FEEDBACK NOTIFICATION CONTAINER -->
            <div id="adm_save_feedback_<?php echo $mode; ?>" style="display:none; margin-top:14px; padding:12px 16px; border-radius:8px; font-size:13px; font-weight:600; transition:all 0.3s ease;"></div>

            <!-- FORM ACTION BUTTONS -->
            <div style="display:flex; justify-content:flex-end; gap:12px; border-top:1px solid #e2e8f0; padding-top:16px; margin-top:12px;">
                <?php if ($isOfflineReverif): ?>
                    <button type="button" id="adm_btn_save_only_<?php echo $mode; ?>" name="save_only" value="1" class="primary-btn" style="height:40px; padding:0 22px; font-size:13.5px; font-weight:700; background:#0284c7; color:#ffffff; border:none; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 2px 6px rgba(2,132,199,0.25);">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan Data
                    </button>
                <?php else: ?>
                    <button type="button" id="adm_btn_save_only_<?php echo $mode; ?>" name="save_only" value="1" class="secondary-btn" style="height:38px; padding:0 18px; font-size:13px; font-weight:600; border:1.5px solid #cbd5e1; border-radius:8px; cursor:pointer; background:#ffffff; color:#334155; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan Data
                    </button>
                    <button type="submit" name="send_consent" value="1" class="primary-btn" style="height:38px; padding:0 18px; font-size:13px; font-weight:700; background:#0284c7; color:#ffffff; border:none; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-paper-plane"></i> Kirim Permintaan Consent
                    </button>
                <?php endif; ?>
            </div>
        </form>
        <script>
        (function(){
            const mode = '<?php echo $mode; ?>';
            const siapkerjaDom = <?php echo json_encode($domicileData); ?>;
            const adminPermitDocRequirements = <?php echo json_encode(function_exists('individual_business_permit_requirements') ? individual_business_permit_requirements() : []); ?>;

            window.adminUpdatePermitDocHelper = function(profession, m) {
                const helper = document.getElementById('adm_helper_permit_document_' + m);
                if (!helper) return;
                const p = (profession || '').trim();
                let reqText = (p && adminPermitDocRequirements[p]) ? adminPermitDocRequirements[p] : '';

                if (reqText) {
                    helper.innerHTML = '<div class="pki-permit-doc-helper-wrap">' +
                        '<div class="pki-permit-doc-title" style="font-weight:600; color:#334155; margin-bottom:2px; font-size:12px;">Dokumen yang perlu diunggah:</div>' +
                        '<div class="pki-permit-doc-requirement" style="color:#475569; margin-bottom:4px; line-height:1.45; font-size:12px;">' + reqText + '</div>' +
                        '<div class="pki-permit-doc-format" style="color:#64748b; font-size:11px;">Format PDF/JPG, maksimal 2 MB per file.</div>' +
                        '</div>';
                } else {
                    helper.innerHTML = '<div class="pki-permit-doc-helper-wrap">' +
                        '<div class="pki-permit-doc-prompt" style="color:#64748b; line-height:1.45; margin-bottom:3px; font-size:12px;">Pilih Jenis Usaha Individu terlebih dahulu untuk melihat dokumen yang perlu diunggah.</div>' +
                        '<div class="pki-permit-doc-format" style="color:#64748b; font-size:11px;">Format PDF/JPG, maksimal 2 MB per file.</div>' +
                        '</div>';
                }
            };

            // --- 1. UPLOAD BOX TRIGGERS & HANDLERS ---
            window.adminTriggerUpload = function(fieldId) {
                const inp = document.getElementById('adm_input_' + fieldId);
                if (inp) inp.click();
            };

            window.adminHandleFileChange = function(inp, fieldId) {
                if (!inp.files || inp.files.length === 0) return;
                const file = inp.files[0];
                const textBox = document.getElementById('adm_text_' + fieldId);
                const btn = document.getElementById('adm_btn_' + fieldId);
                const box = document.getElementById('adm_box_' + fieldId);

                if (textBox) {
                    const ext = (file.name.split('.').pop() || '').toLowerCase();
                    const iconClass = (fieldId.indexOf('permit_document') !== -1)
                        ? (ext === 'docx' ? 'fa-file-word' : 'fa-file-pdf')
                        : 'fa-image';
                    const iconColor = (fieldId.indexOf('permit_document') !== -1)
                        ? (ext === 'docx' ? '#2563eb' : '#ef4444')
                        : '#0284c7';
                    textBox.innerHTML = '<i class="fa-solid ' + iconClass + '" style="color:' + iconColor + '; flex-shrink:0;"></i> <span class="pki-upload-filename">' + file.name + '</span>';
                    textBox.classList.add('has-file');
                }
                if (btn) btn.textContent = 'Ganti';

                if (box) {
                    let clearBtn = box.querySelector('.pki-upload-clear-btn:not(a)');
                    if (!clearBtn) {
                        const btnContainer = box.querySelector('div');
                        if (btnContainer) {
                            clearBtn = document.createElement('button');
                            clearBtn.type = 'button';
                            clearBtn.className = 'pki-upload-clear-btn';
                            clearBtn.title = 'Hapus';
                            clearBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                            clearBtn.onclick = function(e) { adminClearUpload(e, fieldId); };
                            btnContainer.insertBefore(clearBtn, btnContainer.firstChild);
                        }
                    }
                }
            };

            window.adminClearUpload = function(e, fieldId) {
                if (e) e.stopPropagation();
                const inp = document.getElementById('adm_input_' + fieldId);
                const exist = document.getElementById('adm_existing_' + fieldId);
                const textBox = document.getElementById('adm_text_' + fieldId);
                const btn = document.getElementById('adm_btn_' + fieldId);
                const box = document.getElementById('adm_box_' + fieldId);

                if (inp) inp.value = '';
                if (exist) exist.value = '';
                if (textBox) {
                    textBox.classList.remove('has-file');
                    textBox.innerHTML = '<span>Klik dan letakkan fil...</span>';
                }
                if (btn) btn.textContent = 'Pilih File';
                if (box) {
                    const viewLink = box.querySelector('a.pki-upload-clear-btn');
                    if (viewLink) viewLink.remove();
                    const clearBtn = box.querySelector('button.pki-upload-clear-btn');
                    if (clearBtn) clearBtn.remove();
                }
            };

            // Drag and drop for upload boxes
            ['business_logo_' + mode, 'permit_document_' + mode, 'workplace_photo_' + mode].forEach(fid => {
                const b = document.getElementById('adm_box_' + fid);
                const i = document.getElementById('adm_input_' + fid);
                if (b && i) {
                    ['dragenter', 'dragover'].forEach(evt => {
                        b.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); b.style.borderColor = '#0284c7'; b.style.background = '#f0f9ff'; });
                    });
                    ['dragleave', 'drop'].forEach(evt => {
                        b.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); b.style.borderColor = ''; b.style.background = ''; });
                    });
                    b.addEventListener('drop', e => {
                        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                            i.files = e.dataTransfer.files;
                            adminHandleFileChange(i, fid);
                        }
                    });
                }
            });

            // --- 2. HIERARCHICAL LOCATION SELECTOR (IDENTICAL TO PKI-FORM) ---
            const locDb = window.PKI_LOCATION_DATABASE || {
                'Bali': {
                    'Kota Denpasar': {
                        'Denpasar Selatan': { 'Sanur': '80228', 'Panjer': '80225', 'Sesetan': '80223', 'Sidakarya': '80224', 'Renon': '80226' },
                        'Denpasar Barat': { 'Pemecutan': '80112', 'Padangsambian': '80118', 'Dauh Puri': '80113' },
                        'Denpasar Utara': { 'Peguyangan': '80115', 'Tonja': '80235', 'Ubung': '80116' },
                        'Denpasar Timur': { 'Kesiman': '80237', 'Sumerta': '80236', 'Penatih': '80238' }
                    },
                    'Kabupaten Gianyar': {
                        'Ubud': { 'Petulu': '80571', 'Kedewatan': '80571', 'Sayan': '80571', 'Ubud': '80571' },
                        'Gianyar': { 'Gianyar': '80511', 'Beng': '80513', 'Samplangan': '80512' },
                        'Sukawati': { 'Batubulan': '80582', 'Celuk': '80582', 'Singapadu': '80582' }
                    },
                    'Kabupaten Badung': {
                        'Kuta': { 'Kuta': '80361', 'Legian': '80361', 'Seminyak': '80361', 'Tuban': '80361' },
                        'Kuta Selatan': { 'Jimbaran': '80361', 'Benoa': '80361', 'Ungasan': '80361' },
                        'Kuta Utara': { 'Canggu': '80361', 'Kerobokan': '80361', 'Tibubeneng': '80361' }
                    }
                },
                'DKI Jakarta': {
                    'Kota Jakarta Pusat': {
                        'Gambir': { 'Gambir': '10110', 'Kebon Kelapa': '10120', 'Petojo Selatan': '10160' },
                        'Menteng': { 'Menteng': '10310', 'Pegangsaan': '10320', 'Cikini': '10330' }
                    },
                    'Kota Jakarta Selatan': {
                        'Kebayoran Baru': { 'Melawai': '12160', 'Senayan': '12190', 'Gandaria Utara': '12140' },
                        'Setiabudi': { 'Karet': '12920', 'Kuningan Timur': '12950', 'Menteng Atas': '12960' }
                    }
                },
                'Jawa Barat': {
                    'Kota Bandung': {
                        'Coblong': { 'Dago': '40135', 'Lebak Siliwangi': '40132', 'Sadang Serang': '40133' },
                        'Sumur Bandung': { 'Braga': '40111', 'Kebon Pisang': '40112' }
                    }
                }
            };
            window.PKI_LOCATION_DATABASE = locDb;

            const locInput = document.getElementById('adm_hierarchicalLocationInput_' + mode);
            const locDropdown = document.getElementById('adm_hierarchicalLocDropdown_' + mode);
            const locDisplay = document.getElementById('adm_locDisplayValue_' + mode);
            const locCaret = document.getElementById('adm_locCaret_' + mode);
            const breadcrumbs = document.getElementById('adm_locBreadcrumbs_' + mode);
            const optionsList = document.getElementById('adm_locOptionsList_' + mode);

            let locLevel = 1;
            let selProv = document.getElementById('adm_hiddenProvince_' + mode)?.value || '';
            let selCity = document.getElementById('adm_hiddenCity_' + mode)?.value || '';
            let selDistrict = document.getElementById('adm_hiddenDistrict_' + mode)?.value || '';
            let selVillage = document.getElementById('adm_hiddenVillage_' + mode)?.value || '';

            window.adminToggleLocDropdown = function(m) {
                if (!locDropdown) return;
                if (locDropdown.style.display === 'block') {
                    closeLocDropdown();
                } else {
                    openLocDropdown();
                }
            };

            function openLocDropdown() {
                if (!locDropdown) return;
                locDropdown.style.display = 'block';
                if (locCaret) {
                    locCaret.classList.remove('fa-chevron-down');
                    locCaret.classList.add('fa-chevron-up');
                }
                if (selProv && selCity && selDistrict && locDb[selProv]?.[selCity]?.[selDistrict]) {
                    locLevel = 4;
                } else if (selProv && selCity && locDb[selProv]?.[selCity]) {
                    locLevel = 3;
                } else if (selProv && locDb[selProv]) {
                    locLevel = 2;
                } else {
                    locLevel = 1;
                }
                renderLocView();
            }

            function closeLocDropdown() {
                if (!locDropdown) return;
                locDropdown.style.display = 'none';
                if (locCaret) {
                    locCaret.classList.remove('fa-chevron-up');
                    locCaret.classList.add('fa-chevron-down');
                }
            }

            document.addEventListener('click', function(e) {
                if (locInput && locDropdown && !locInput.contains(e.target) && !locDropdown.contains(e.target)) {
                    closeLocDropdown();
                }
            });

            function renderLocView() {
                renderBreadcrumbs();
                renderOptions();
            }

            function renderBreadcrumbs() {
                if (!breadcrumbs) return;
                let html = '';
                if (locLevel === 1) {
                    html += '<span class="crumb-btn active" onclick="adminSwitchLevel(1)">Pilih Provinsi</span>';
                } else if (locLevel === 2) {
                    html += '<span class="crumb-btn" onclick="adminSwitchLevel(1)">' + selProv + '</span> <span class="crumb-sep">›</span> ';
                    html += '<span class="crumb-btn active" onclick="adminSwitchLevel(2)">Pilih Kabupaten / Kota</span>';
                } else if (locLevel === 3) {
                    html += '<span class="crumb-btn" onclick="adminSwitchLevel(1)">' + selProv + '</span> <span class="crumb-sep">›</span> ';
                    html += '<span class="crumb-btn" onclick="adminSwitchLevel(2)">' + selCity + '</span> <span class="crumb-sep">›</span> ';
                    html += '<span class="crumb-btn active" onclick="adminSwitchLevel(3)">Pilih Kecamatan</span>';
                } else if (locLevel === 4) {
                    html += '<span class="crumb-btn" onclick="adminSwitchLevel(1)">' + selProv + '</span> <span class="crumb-sep">›</span> ';
                    html += '<span class="crumb-btn" onclick="adminSwitchLevel(2)">' + selCity + '</span> <span class="crumb-sep">›</span> ';
                    html += '<span class="crumb-btn" onclick="adminSwitchLevel(3)">' + selDistrict + '</span> <span class="crumb-sep">›</span> ';
                    html += '<span class="crumb-btn active" onclick="adminSwitchLevel(4)">Pilih Kelurahan / Desa</span>';
                }
                breadcrumbs.innerHTML = html;
            }

            window.adminSwitchLevel = function(lvl) {
                locLevel = lvl;
                renderLocView();
            };

            function renderOptions() {
                if (!optionsList) return;
                optionsList.innerHTML = '';

                if (locLevel === 1) {
                    const provinces = Object.keys(locDb);
                    provinces.forEach(prov => {
                        const isSel = prov === selProv;
                        const row = document.createElement('div');
                        row.className = 'loc-opt-row' + (isSel ? ' selected' : '');
                        row.innerHTML = '<div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>' + prov + '</span></div>' + (isSel ? '<span class="sel-tag">✓ Dipilih</span>' : '');
                        row.onclick = () => selectProv(prov);
                        optionsList.appendChild(row);
                    });
                } else if (locLevel === 2) {
                    const cities = Object.keys(locDb[selProv] || {});
                    cities.forEach(city => {
                        const isSel = city === selCity;
                        const row = document.createElement('div');
                        row.className = 'loc-opt-row' + (isSel ? ' selected' : '');
                        row.innerHTML = '<div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>' + city + '</span></div>' + (isSel ? '<span class="sel-tag">✓ Dipilih</span>' : '');
                        row.onclick = () => selectCity(city);
                        optionsList.appendChild(row);
                    });
                } else if (locLevel === 3) {
                    const districts = Object.keys(locDb[selProv]?.[selCity] || {});
                    districts.forEach(dist => {
                        const isSel = dist === selDistrict;
                        const row = document.createElement('div');
                        row.className = 'loc-opt-row' + (isSel ? ' selected' : '');
                        row.innerHTML = '<div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>' + dist + '</span></div>' + (isSel ? '<span class="sel-tag">✓ Dipilih</span>' : '');
                        row.onclick = () => selectDistrict(dist);
                        optionsList.appendChild(row);
                    });
                } else if (locLevel === 4) {
                    const villagesObj = locDb[selProv]?.[selCity]?.[selDistrict] || {};
                    const villages = Object.keys(villagesObj);
                    villages.forEach(vill => {
                        const postal = villagesObj[vill] || '';
                        const isSel = vill === selVillage;
                        const row = document.createElement('div');
                        row.className = 'loc-opt-row' + (isSel ? ' selected' : '');
                        row.innerHTML = '<div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>' + vill + (postal ? ' (' + postal + ')' : '') + '</span></div>' + (isSel ? '<span class="sel-tag">✓ Dipilih</span>' : '');
                        row.onclick = () => selectVillage(vill, postal);
                        optionsList.appendChild(row);
                    });
                }
            }

            function selectProv(prov) {
                selProv = prov;
                selCity = '';
                selDistrict = '';
                selVillage = '';
                syncLocFields();
                locLevel = 2;
                renderLocView();
            }

            function selectCity(city) {
                selCity = city;
                selDistrict = '';
                selVillage = '';
                syncLocFields();
                locLevel = 3;
                renderLocView();
            }

            function selectDistrict(dist) {
                selDistrict = dist;
                selVillage = '';
                syncLocFields();
                locLevel = 4;
                renderLocView();
            }

            function selectVillage(vill, postal) {
                selVillage = vill;
                syncLocFields();
                if (locDisplay) {
                    locDisplay.textContent = selVillage + ', ' + selDistrict + ', ' + selCity + ', ' + selProv;
                    locDisplay.classList.remove('placeholder');
                }
                const postSelect = document.getElementById('adm_selectPostalCode_' + mode);
                if (postSelect && postal) {
                    postSelect.innerHTML = '<option value="' + postal + '" selected>' + postal + '</option>';
                }
                closeLocDropdown();
                adminUpdateMapPreview(mode);
            }

            function syncLocFields() {
                const elP = document.getElementById('adm_hiddenProvince_' + mode);
                const elC = document.getElementById('adm_hiddenCity_' + mode);
                const elD = document.getElementById('adm_hiddenDistrict_' + mode);
                const elV = document.getElementById('adm_hiddenVillage_' + mode);
                const elDomId = document.getElementById('adm_hiddenDomicileCityId_' + mode);

                if (elP) elP.value = selProv;
                if (elC) elC.value = selCity;
                if (elD) elD.value = selDistrict;
                if (elV) elV.value = selVillage;
                if (elDomId) elDomId.value = selCity;

                // Sync workplace duplicates
                const wpP = document.getElementById('adm_wp_prov_' + mode);
                const wpC = document.getElementById('adm_wp_city_' + mode);
                const wpD = document.getElementById('adm_wp_dist_' + mode);
                const wpV = document.getElementById('adm_wp_vill_' + mode);
                if (wpP) wpP.value = selProv;
                if (wpC) wpC.value = selCity;
                if (wpD) wpD.value = selDistrict;
                if (wpV) wpV.value = selVillage;

                // Sync domicile duplicates
                const dpP = document.getElementById('adm_dp_prov_' + mode);
                const dpC = document.getElementById('adm_dp_city_' + mode);
                const dpD = document.getElementById('adm_dp_dist_' + mode);
                const dpV = document.getElementById('adm_dp_vill_' + mode);
                if (dpP) dpP.value = selProv;
                if (dpC) dpC.value = selCity;
                if (dpD) dpD.value = selDistrict;
                if (dpV) dpV.value = selVillage;
            }

            // --- 3. SAME AS SIAPKERJA CHECKBOX ---
            window.adminHandleSameLocationToggle = function(cb, m) {
                if (!cb.checked) return;
                if (!siapkerjaDom) return;

                selProv = siapkerjaDom.province || selProv || 'Bali';
                selCity = siapkerjaDom.city || selCity || 'Kabupaten Gianyar';
                selDistrict = siapkerjaDom.district || selDistrict || 'Ubud';
                selVillage = siapkerjaDom.village || selVillage || 'Petulu';
                syncLocFields();

                if (locDisplay) {
                    const parts = [selVillage, selDistrict, selCity, selProv].filter(Boolean);
                    if (parts.length > 0) {
                        locDisplay.textContent = parts.join(', ');
                        locDisplay.classList.remove('placeholder');
                    }
                }

                const postSelect = document.getElementById('adm_selectPostalCode_' + mode);
                if (postSelect && siapkerjaDom.postal_code) {
                    postSelect.innerHTML = '<option value="' + siapkerjaDom.postal_code + '" selected>' + siapkerjaDom.postal_code + '</option>';
                    postSelect.value = siapkerjaDom.postal_code;
                }

                const addrInput = document.getElementById('adm_inputAddress_' + mode);
                if (addrInput && siapkerjaDom.address) {
                    addrInput.value = siapkerjaDom.address;
                }

                adminUpdateMapPreview(mode);
            };

            // --- 4. LIVE GOOGLE MAPS PREVIEW ---
            window.adminUpdateMapPreview = function(m) {
                const addr = document.getElementById('adm_inputAddress_' + mode)?.value?.trim() || '';
                const vill = document.getElementById('adm_hiddenVillage_' + mode)?.value?.trim() || selVillage;
                const dist = document.getElementById('adm_hiddenDistrict_' + mode)?.value?.trim() || selDistrict;
                const city = document.getElementById('adm_hiddenCity_' + mode)?.value?.trim() || selCity;
                const prov = document.getElementById('adm_hiddenProvince_' + mode)?.value?.trim() || selProv;

                const iframe = document.getElementById('adm_gmapIframe_' + mode);
                const placeholder = document.getElementById('adm_mapPlaceholder_' + mode);
                const wrapper = document.getElementById('adm_googleMapWrapper_' + mode);

                const parts = [addr, vill, dist, city, prov].filter(p => p && p.length > 0);
                if (parts.length > 0 && iframe) {
                    const q = parts.join(', ');
                    iframe.src = 'https://maps.google.com/maps?q=' + encodeURIComponent(q) + '&t=&z=14&ie=UTF8&iwloc=&output=embed';
                    if (placeholder) {
                        placeholder.style.display = 'none';
                        placeholder.classList.add('is-hidden');
                    }
                    if (wrapper) {
                        wrapper.style.display = 'block';
                        wrapper.classList.add('is-visible');
                    }
                } else {
                    if (placeholder) {
                        placeholder.style.display = 'flex';
                        placeholder.classList.remove('is-hidden');
                    }
                    if (wrapper) {
                        wrapper.style.display = 'none';
                        wrapper.classList.remove('is-visible');
                    }
                }
            };

            // --- 5. SOCIAL MEDIA REPEATER ---
            const container = document.getElementById('adm_social_container_' + mode);
            const hiddenLi = document.getElementById('adm_soc_linkedin_' + mode);
            const hiddenFb = document.getElementById('adm_soc_facebook_' + mode);
            const hiddenIg = document.getElementById('adm_soc_instagram_' + mode);
            const hiddenTt = document.getElementById('adm_soc_tiktok_' + mode);

            const PLATFORMS = {
                'LinkedIn':  { icon: 'fa-brands fa-linkedin', color: '#0a66c2', placeholder: 'username atau https://linkedin.com/in/...' },
                'Facebook':  { icon: 'fa-brands fa-facebook', color: '#1877f2', placeholder: '@username atau https://facebook.com/...' },
                'Instagram': { icon: 'fa-brands fa-instagram', color: '#e1306c', placeholder: '@username atau https://instagram.com/...' },
                'TikTok':    { icon: 'fa-brands fa-tiktok', color: '#000000', placeholder: '@username atau https://tiktok.com/@...' }
            };

            function syncHidden() {
                if (!container) return;
                let li = '', fb = '', ig = '', tt = '';
                container.querySelectorAll('.pki-social-row').forEach(row => {
                    const sel = row.querySelector('.pki-social-platform-select');
                    const inp = row.querySelector('.pki-social-handle-input');
                    if (sel && inp) {
                        const plat = sel.value;
                        const val = inp.value.trim();
                        if (plat === 'LinkedIn') li = val;
                        else if (plat === 'Facebook') fb = val;
                        else if (plat === 'Instagram') ig = val;
                        else if (plat === 'TikTok') tt = val;
                    }
                });
                if (hiddenLi) hiddenLi.value = li;
                if (hiddenFb) hiddenFb.value = fb;
                if (hiddenIg) hiddenIg.value = ig;
                if (hiddenTt) hiddenTt.value = tt;
            }

            function refreshButtons() {
                if (!container) return;
                const rows = container.querySelectorAll('.pki-social-row');
                const total = rows.length;
                rows.forEach((row, idx) => {
                    row.dataset.index = idx;
                    const acts = row.querySelector('.pki-social-actions');
                    if (!acts) return;
                    const isLast = (idx === total - 1);
                    let html = '';
                    if (total > 1) {
                        html += '<button type="button" class="pki-social-btn pki-social-btn-del" title="Hapus akun" style="width:36px; height:36px; border:1px solid #fee2e2; background:#fef2f2; color:#ef4444; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center;"><i class="fa-solid fa-trash-can"></i></button>';
                    }
                    if (isLast && total < 4) {
                        html += '<button type="button" class="pki-social-btn pki-social-btn-add" title="Tambah akun" style="width:36px; height:36px; border:1px solid #bae6fd; background:#f0f9ff; color:#0284c7; border-radius:8px; cursor:pointer; display:flex; align-items:center; justify-content:center;"><i class="fa-solid fa-plus"></i></button>';
                    }
                    acts.innerHTML = html;
                });
            }

            if (container) {
                container.addEventListener('change', function(e) {
                    const sel = e.target.closest('.pki-social-platform-select');
                    if (sel) {
                        const row = sel.closest('.pki-social-row');
                        const iconSpan = row ? row.querySelector('.pki-social-platform-icon') : null;
                        const input = row ? row.querySelector('.pki-social-handle-input') : null;
                        const meta = PLATFORMS[sel.value] || PLATFORMS['LinkedIn'];
                        if (iconSpan) iconSpan.innerHTML = '<i class="' + meta.icon + '" style="color:' + meta.color + ';"></i>';
                        if (input) input.placeholder = meta.placeholder;
                        syncHidden();
                    }
                });

                container.addEventListener('input', function(e) {
                    if (e.target.closest('.pki-social-handle-input')) {
                        syncHidden();
                    }
                });

                container.addEventListener('click', function(e) {
                    const addBtn = e.target.closest('.pki-social-btn-add');
                    const delBtn = e.target.closest('.pki-social-btn-del');
                    if (addBtn) {
                        e.preventDefault();
                        const rows = container.querySelectorAll('.pki-social-row');
                        if (rows.length >= 4) return;
                        const used = Array.from(rows).map(r => r.querySelector('.pki-social-platform-select')?.value);
                        const nextPlat = Object.keys(PLATFORMS).find(p => !used.includes(p)) || 'LinkedIn';
                        const meta = PLATFORMS[nextPlat];
                        const div = document.createElement('div');
                        div.className = 'pki-social-row';
                        div.style.cssText = 'display:flex; align-items:center; gap:8px;';
                        let opts = '';
                        Object.keys(PLATFORMS).forEach(p => {
                            opts += '<option value="' + p + '"' + (p === nextPlat ? ' selected' : '') + '>' + p + '</option>';
                        });
                        div.innerHTML = '<div class="pki-social-select-wrap" style="position:relative; width:170px; flex-shrink:0;"><span class="pki-social-platform-icon" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:16px;"><i class="' + meta.icon + '" style="color:' + meta.color + ';"></i></span><select name="social_platform[]" class="pki-social-platform-select" style="width:100%; height:40px; padding:6px 10px 6px 36px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-weight:600; color:#0f172a; background:#ffffff; box-sizing:border-box;">' + opts + '</select></div><div class="pki-social-input-wrap" style="flex:1;"><input type="text" name="social_handle[]" class="pki-social-handle-input" value="" placeholder="' + meta.placeholder + '" style="width:100%; height:40px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; color:#0f172a; box-sizing:border-box;"></div><div class="pki-social-actions" style="display:flex; gap:6px;"></div>';
                        container.appendChild(div);
                        refreshButtons();
                        syncHidden();
                    }
                    if (delBtn) {
                        e.preventDefault();
                        const row = delBtn.closest('.pki-social-row');
                        if (row && container.querySelectorAll('.pki-social-row').length > 1) {
                            row.remove();
                            refreshButtons();
                            syncHidden();
                        }
                    }
                });

                syncHidden();
            }

            // Auto-display initial location text if missing or if checkbox is checked
            const cbInitial = document.getElementById('admin_same_location_' + mode);
            if (cbInitial && cbInitial.checked && siapkerjaDom) {
                if (!selProv && siapkerjaDom.province) selProv = siapkerjaDom.province;
                if (!selCity && siapkerjaDom.city) selCity = siapkerjaDom.city;
                if (!selDistrict && siapkerjaDom.district) selDistrict = siapkerjaDom.district;
                if (!selVillage && siapkerjaDom.village) selVillage = siapkerjaDom.village;
                syncLocFields();

                if (locDisplay) {
                    const initParts = [selVillage, selDistrict, selCity, selProv].filter(Boolean);
                    if (initParts.length > 0) {
                        locDisplay.textContent = initParts.join(', ');
                        locDisplay.classList.remove('placeholder');
                    }
                }
            } else if (locDisplay && (!locDisplay.textContent.trim() || locDisplay.classList.contains('placeholder')) && (selVillage || selDistrict || selCity || selProv)) {
                const initParts = [selVillage, selDistrict, selCity, selProv].filter(Boolean);
                if (initParts.length > 0) {
                    locDisplay.textContent = initParts.join(', ');
                    locDisplay.classList.remove('placeholder');
                }
            }

            // Interactive save handling (AJAX save without page refresh)
            const profileForm = document.getElementById('adm_profile_form_' + mode);
            const saveOnlyBtn = document.getElementById('adm_btn_save_only_' + mode);
            const feedbackBox = document.getElementById('adm_save_feedback_' + mode);

            function showSaveFeedback(msg, type) {
                if (!feedbackBox) return;
                feedbackBox.style.display = 'block';
                if (type === 'success') {
                    feedbackBox.style.background = '#ecfdf5';
                    feedbackBox.style.color = '#065f46';
                    feedbackBox.style.border = '1px solid #a7f3d0';
                    feedbackBox.innerHTML = '<i class="fa-solid fa-circle-check" style="margin-right:6px; color:#10b981;"></i> ' + msg;
                } else {
                    feedbackBox.style.background = '#fef2f2';
                    feedbackBox.style.color = '#991b1b';
                    feedbackBox.style.border = '1px solid #fecaca';
                    feedbackBox.innerHTML = '<i class="fa-solid fa-circle-exclamation" style="margin-right:6px; color:#ef4444;"></i> ' + msg;
                }
                setTimeout(() => {
                    if (feedbackBox) {
                        feedbackBox.style.transition = 'opacity 0.5s';
                        feedbackBox.style.opacity = '0';
                        setTimeout(() => {
                            feedbackBox.style.display = 'none';
                            feedbackBox.style.opacity = '1';
                        }, 500);
                    }
                }, 4000);
            }

            function syncAllFieldsBeforeSave() {
                syncLocFields();
                if (typeof syncHidden === 'function') syncHidden();
                const curAddr = document.getElementById('adm_inputAddress_' + mode)?.value?.trim() || '';
                const curPost = document.getElementById('adm_selectPostalCode_' + mode)?.value?.trim() || '';
                const curDetail = document.getElementById('adm_inputAddressDetail_' + mode)?.value?.trim() || '';

                const wpA = document.getElementById('adm_wp_addr_' + mode);
                const dpA = document.getElementById('adm_dp_addr_' + mode);
                if (wpA) wpA.value = curAddr;
                if (dpA) dpA.value = curAddr;

                const wpP = document.getElementById('adm_wp_post_' + mode);
                const dpP = document.getElementById('adm_dp_post_' + mode);
                if (wpP) wpP.value = curPost;
                if (dpP) dpP.value = curPost;

                const wpDet = document.getElementById('adm_wp_detail_' + mode);
                if (wpDet) wpDet.value = curDetail;
            }

            if (saveOnlyBtn && profileForm) {
                saveOnlyBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    syncAllFieldsBeforeSave();

                    if (!profileForm.checkValidity()) {
                        profileForm.reportValidity();
                        return;
                    }

                    const originalBtnHtml = saveOnlyBtn.innerHTML;
                    saveOnlyBtn.disabled = true;
                    saveOnlyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan Perubahan...';

                    const formData = new FormData(profileForm);
                    formData.append('save_only', '1');
                    formData.append('ajax', '1');

                    fetch(profileForm.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(response => {
                        const contentType = response.headers.get('content-type') || '';
                        if (contentType.includes('application/json')) {
                            return response.json();
                        }
                        return { success: true, message: 'Perubahan data profil berhasil disimpan.' };
                    })
                    .then(data => {
                        saveOnlyBtn.disabled = false;
                        if (data.success) {
                            saveOnlyBtn.innerHTML = '<i class="fa-solid fa-check"></i> Data Tersimpan!';
                            showSaveFeedback(data.message || 'Perubahan data profil berhasil disimpan ke database.', 'success');
                            setTimeout(() => {
                                saveOnlyBtn.innerHTML = originalBtnHtml;
                            }, 2500);
                        } else {
                            saveOnlyBtn.innerHTML = originalBtnHtml;
                            showSaveFeedback(data.error || 'Gagal menyimpan perubahan.', 'error');
                        }
                    })
                    .catch(err => {
                        console.error('Fetch save error:', err);
                        saveOnlyBtn.disabled = false;
                        saveOnlyBtn.innerHTML = originalBtnHtml;
                        profileForm.submit();
                    });
                });

                profileForm.addEventListener('submit', function() {
                    syncAllFieldsBeforeSave();
                });
            }

            // Initialize live map preview & permit doc helper
            adminUpdateMapPreview(mode);
            const profSelect = document.getElementById('adm_select_profession_' + mode);
            if (profSelect) {
                adminUpdatePermitDocHelper(profSelect.value, mode);
            }
        })();
        </script>
        <?php
    }
}
                ?>
                <style>
                .admin-tooltip {
                    position: relative;
                    display: inline-flex;
                    align-items: center;
                    cursor: pointer;
                    color: #0284c7;
                    margin-left: 4px;
                }
                .admin-tooltip .admin-tooltip-text {
                    visibility: hidden;
                    opacity: 0;
                    width: 250px;
                    background-color: #0f172a;
                    color: #ffffff;
                    text-align: left;
                    border-radius: 8px;
                    padding: 8px 12px;
                    font-size: 11.5px;
                    font-weight: 500;
                    line-height: 1.4;
                    position: absolute;
                    z-index: 1000;
                    bottom: 125%;
                    left: 50%;
                    transform: translateX(-50%);
                    box-shadow: 0 4px 12px rgba(0,0,0,0.18);
                    transition: opacity 0.2s;
                    pointer-events: none;
                    text-transform: none;
                    letter-spacing: normal;
                }
                .admin-tooltip .admin-tooltip-text::after {
                    content: "";
                    position: absolute;
                    top: 100%;
                    left: 50%;
                    margin-left: -5px;
                    border-width: 5px;
                    border-style: solid;
                    border-color: #0f172a transparent transparent transparent;
                }
                .admin-tooltip:hover .admin-tooltip-text,
                .admin-tooltip:focus .admin-tooltip-text {
                    visibility: visible;
                    opacity: 1;
                }
                </style>
                <?php if ($selectedEmployer): ?>
                    <!-- DETAIL VIEW FOR VERIFIKASI PEMBERI KERJA -->
                    <div class="detail-page-wrapper" style="max-width: 1200px; margin: 0 auto; width: 100%;">
                        <div style="margin-bottom:16px;">
                            <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>" style="display:inline-flex; align-items:center; gap:8px; color:#475569; font-weight:600; font-size:13px; text-decoration:none; padding:7px 14px; background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(0,0,0,0.04); transition:all 0.15s;">
                                <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Verifikasi
                            </a>
                        </div>

                    <!-- TOP HEADER BAR -->
                    <div class="detail-header-bar" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 24px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:nowrap; gap:16px;">
                        <div style="display:flex; align-items:center; gap:16px; min-width:0; flex:1 1 auto;">
                            <?php
                                $empBrandTitle = !empty($selectedEmployer['brand_name']) ? $selectedEmployer['brand_name'] : ($selectedEmployer['owner_name'] ?: $selectedEmployer['name']);
                                $empOwnerSubtitle = !empty($selectedEmployer['brand_name']) ? ($selectedEmployer['owner_name'] ?: $selectedEmployer['name']) : '';
                            ?>
                            <div class="item-avatar-box" style="width:52px; height:52px; font-size:18px; border-radius:12px; background:#f1f5f9; color:#0f172a; display:flex; align-items:center; justify-content:center; font-weight:700;">
                                <?php echo strtoupper(substr($empBrandTitle, 0, 2)); ?>
                            </div>
                            <div>
                                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#64748b; margin-bottom:4px;">
                                    DETAIL PENGAJUAN VERIFIKASI
                                </div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <h1 style="font-size:20px; font-weight:800; margin:0; color:#0f172a;"><?php echo e($empBrandTitle); ?></h1>
                                    <?php
                                        $status = $selectedEmployer['verification_status'] ?? 'PENDING';
                                        $isOnlineReverif = ($selectedEmployer['extension_status'] === 'REQUESTED' 
                                            || ($selectedEmployer['extension_requested'] ?? 0) == 1 
                                            || ($selectedEmployer['verification_status'] ?? '') === 'REVERIFICATION_PENDING'
                                            || (!empty($selectedEmployer['last_activated_at']) && ($selectedEmployer['verification_status'] ?? '') === 'PENDING')
                                            || (($_GET['reverif_type'] ?? '') === 'online'));

                                        $empActiveUntilTime = !empty($selectedEmployer['active_until']) ? strtotime($selectedEmployer['active_until']) : null;
                                        $isOfflineReverif = !$isOnlineReverif && (
                                            (!empty($selectedEmployer['last_activated_at']) && (
                                                in_array($selectedEmployer['verification_status'] ?? '', ['TRANSITION_LIMITED', 'FULL_DISABLED'], true)
                                                || ($empActiveUntilTime !== null && $empActiveUntilTime <= (time() + 7 * 86400))
                                            ))
                                            || (($_GET['reverif_type'] ?? '') === 'offline')
                                        );

                                        $isRevisionStatus = in_array(strtoupper($status), ['REVISION', 'NEEDS_REVISION']) || ($tab === 'revision');
                                        $isIndividual = strcasecmp((string)($selectedEmployer['entity_type'] ?? 'Individual'), 'Individual') === 0;
                                        $hasAssignment = !empty($selectedEmployer['assigned_to']);
                                        $statusClass = 'pending';
                                        $statusLabel = 'Dikirim';
                                        $revNum = 1;
                                        if ($isRevisionStatus) {
                                            $statusClass = 'revision';
                                            $revNum = (int)($selectedEmployer['revision_count'] ?? ($selectedEmployer['rejection_count'] ?? 1));
                                            $revNum = max(1, min(3, $revNum));
                                            $statusLabel = "Revisi Diminta (ke-{$revNum})";
                                        } elseif ($isOnlineReverif) {
                                            $statusClass = 'pending';
                                            $statusLabel = 'Verifikasi Ulang Profil';
                                        } elseif ($isOfflineReverif) {
                                            $statusClass = 'process';
                                            $statusLabel = 'Verifikasi Ulang Profil';
                                        } elseif ($status === 'APPROVED') {
                                            $statusClass = 'verified';
                                            $statusLabel = 'Terverifikasi';
                                        } elseif ($status === 'REJECTED') {
                                            $statusClass = 'danger';
                                            $statusLabel = 'Ditolak';
                                        } elseif ($hasAssignment) {
                                            $statusClass = 'process';
                                            $statusLabel = 'Dalam Verifikasi';
                                        } elseif ($status === 'PENDING') {
                                            $statusClass = 'pending';
                                            $statusLabel = 'Dikirim';
                                        }
                                        $isDinasFlow = ($isRevisionStatus && ($revNum >= 3 || in_array($selectedEmployer['manual_review_status'] ?? '', ['MANUAL_DINAS_REVIEW', 'CONSENT_PENDING', 'CONSENT_GIVEN', 'INVALID'])));
                                        $isAdminPusat = ($user['role'] === 'admin' || $user['role'] === 'admin_pusat' || strcasecmp((string)$user['name'], 'Admin Pusat') === 0);
                                    ?>
                                    <span class="pill-badge <?php echo $statusClass; ?>" style="<?php echo $isRevisionStatus ? 'background:#fff7ed; color:#ea580c; border:1px solid #ffedd5;' : ($statusClass === 'pending' ? 'background:#e0f2fe; color:#0284c7; border:1px solid #bae6fd;' : ''); ?>">
                                        ● <?php echo e($statusLabel); ?>
                                    </span>
                                    <?php if ($isOnlineReverif): ?>
                                        <span class="pill-badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-weight:700; display:inline-flex; align-items:center; gap:5px; padding:4px 10px;">
                                            <i class="fa-solid fa-arrows-rotate"></i> Verifikasi Ulang Profil (Online)
                                        </span>
                                    <?php elseif ($isOfflineReverif): ?>
                                        <span class="pill-badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-weight:700; display:inline-flex; align-items:center; gap:5px; padding:4px 10px;">
                                            <i class="fa-solid fa-building-user"></i> Verifikasi Ulang Profil (Offline)
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($activeProfileChange): ?>
                                        <span class="pill-badge" style="background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; font-weight:700; display:inline-flex; align-items:center; gap:5px; padding:4px 10px;">
                                            <i class="fa-solid fa-clock-rotate-left"></i> Ada Pengajuan Perubahan Profil
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:12px; color:#64748b; margin-top:4px;">
                                    <?php if ($empOwnerSubtitle && strcasecmp($empOwnerSubtitle, $empBrandTitle) !== 0): ?>
                                        <span>Pemilik: <strong style="color:#334155;"><?php echo e($empOwnerSubtitle); ?></strong></span> &nbsp;|&nbsp;
                                    <?php endif; ?>
                                    Slug: <code><?php echo strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $empBrandTitle)); ?></code> &nbsp;|&nbsp;
                                    <strong><?php echo e($selectedEmployer['entity_type'] ?? 'Individual'); ?></strong> &nbsp;|&nbsp;
                                    Didaftarkan: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?>
                                    <?php if ($isOnlineReverif && !empty($selectedEmployer['updated_at'])): ?>
                                        &nbsp;|&nbsp; <span style="color:#0284c7; font-weight:600;"><i class="fa-solid fa-arrows-rotate"></i> Diajukan Verifikasi Ulang: <?php echo date('d M Y, H:i', strtotime($selectedEmployer['updated_at'])); ?></span>
                                    <?php endif; ?>
                                    &nbsp;|&nbsp;
                                    <?php echo e($selectedEmployer['city'] ?: 'Dalung, Kuta Utara, Kab. Badung, Bali'); ?>
                                </div>
                            </div>
                        </div>

                        <div style="flex:0 0 auto; margin-left:16px;">
                            <?php if ($isDinasFlow): ?>
                                <button type="button" onclick="const sec = document.getElementById('sectionManualDinasEdit'); if (sec) { sec.scrollIntoView({behavior:'smooth'}); const dt = sec.querySelector('details'); if (dt) dt.open = true; }" style="display:inline-flex; align-items:center; gap:8px; background:#0284c7; border:none; border-radius:999px; padding:9px 20px; font-size:13px; font-weight:700; color:#ffffff; cursor:pointer; box-shadow:0 2px 6px rgba(2,132,199,0.25);">
                                    <i class="fa-solid fa-pen-to-square"></i> Ajukan Permohonan Ulang
                                </button>
                            <?php elseif ($hasAssignment && !$isRevisionStatus && ($status !== 'APPROVED' || $isOnlineReverif || $isOfflineReverif) && $status !== 'REJECTED'): ?>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <button type="button" data-open-modal="modal-confirm-unassign" style="background:#ffffff; border:1px solid #cbd5e1; border-radius:999px; padding:8px 16px; font-size:13px; font-weight:700; color:#475569; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                        Batalkan Penugasan
                                    </button>
                                    <button type="button" id="headerBtnRevisi" onclick="handleHeaderDecisionClick('revision')" disabled style="background:#fffbeb; border:1px solid #fde68a; border-radius:999px; padding:8px 18px; font-size:13px; font-weight:700; color:#b45309; cursor:not-allowed; opacity:0.5; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:all 0.2s;">
                                        Revisi
                                    </button>
                                    <button type="button" id="headerBtnTolak" onclick="handleHeaderDecisionClick('reject')" disabled style="background:#fef2f2; border:1px solid #fecaca; border-radius:999px; padding:8px 18px; font-size:13px; font-weight:700; color:#b91c1c; cursor:not-allowed; opacity:0.5; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:all 0.2s;">
                                        Tolak
                                    </button>
                                    <button type="button" id="headerBtnSetujui" onclick="handleHeaderDecisionClick('approve')" disabled style="background:#0284c7; border:none; border-radius:999px; padding:8px 20px; font-size:13px; font-weight:700; color:#ffffff; cursor:not-allowed; opacity:0.5; box-shadow:0 2px 6px rgba(2,132,199,0.3); transition:all 0.2s;">
                                        Setujui
                                    </button>
                                </div>
                            <?php elseif (!$hasAssignment && !$isRevisionStatus && ($status !== 'APPROVED' || $isOnlineReverif || $isOfflineReverif) && $status !== 'REJECTED'): ?>
                                <button type="button" data-open-modal="modal-confirm-ambil-pengajuan" style="display:inline-flex; align-items:center; gap:8px; background:#ffffff; border:1px solid #00a8e8; border-radius:999px; padding:8px 18px; font-size:13px; font-weight:700; color:#0284c7; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                                    <i class="fa-solid fa-arrows-rotate" style="color:#00a8e8;"></i> Ambil Pengajuan
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="detail-grid-container" style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
                        <!-- LEFT COLUMN: MAIN VERIFICATION CONTENT -->
                        <div style="display:flex; flex-direction:column; gap:20px;">

                            <!-- CARD PENGAJUAN PERUBAHAN PROFIL (JIKA ADA ACTIVE PCR) -->
                            <?php if ($activeProfileChange): ?>
                                <?php
                                $pcrProposed = json_decode($activeProfileChange['proposed_data'] ?? '{}', true) ?: [];
                                $pcrCurrent = json_decode($activeProfileChange['current_data'] ?? '{}', true) ?: [];
                                $pcrSections = json_decode($activeProfileChange['sections'] ?? '[]', true) ?: [];
                                $secLabels = [
                                    'identity' => 'Identitas (WhatsApp)',
                                    'workplace' => 'Tempat & Deskripsi Usaha',
                                    'documents' => 'Dokumen & Bukti Usaha',
                                    'social' => 'Media Sosial'
                                ];
                                ?>
                                <div class="section-card" style="background:#ffffff; border:2px solid #0284c7; border-radius:14px; padding:22px; box-shadow:0 4px 14px rgba(2,132,199,0.12);">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <div style="width:36px; height:36px; border-radius:8px; background:#e0f2fe; color:#0284c7; display:flex; align-items:center; justify-content:center; font-size:16px;">
                                                <i class="fa-solid fa-clock-rotate-left"></i>
                                            </div>
                                            <div>
                                                <h2 style="font-size:16px; font-weight:800; color:#0f172a; margin:0;">Pengajuan Perubahan Profil Pemberi Kerja</h2>
                                                <div style="font-size:12px; color:#64748b;">Diajukan pada: <strong><?php echo date('d M Y, H:i', strtotime($activeProfileChange['created_at'])); ?> WIB</strong></div>
                                            </div>
                                        </div>
                                        <span class="pill-badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-weight:700;">
                                            ● Menunggu Keputusan Admin
                                        </span>
                                    </div>

                                    <!-- ALASAN PENGAJUAN & BAGIAN YANG DIUBAH -->
                                    <div style="margin-bottom:16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                                        <div style="font-size:12.5px; font-weight:700; color:#475569; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-circle-info" style="color:#0284c7;"></i> Alasan Perubahan Data Profil:
                                        </div>
                                        <div style="font-size:13.5px; color:#0f172a; font-weight:600; line-height:1.5;">
                                            "<?php echo nl2br(e($activeProfileChange['reason'])); ?>"
                                        </div>
                                        <div style="margin-top:10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                            <span style="font-size:11.5px; color:#64748b; font-weight:600;">Bagian yang Diajukan:</span>
                                            <?php foreach ($pcrSections as $secKey): ?>
                                                <span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px;">
                                                    <?php echo e($secLabels[$secKey] ?? $secKey); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- TABEL KOMPARASI PERUBAHAN DATA (DIFF TABLE) -->
                                    <div style="margin-bottom:20px;">
                                        <div style="font-size:13px; font-weight:800; color:#0f172a; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-table-columns" style="color:#0284c7;"></i> Perbandingan Data Profil (Saat Ini vs Usulan Baru):
                                        </div>
                                        <div style="border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;">
                                            <table style="width:100%; border-collapse:collapse; font-size:12.5px; text-align:left;">
                                                <thead>
                                                    <tr style="background:#f1f5f9; color:#475569; font-weight:700; border-bottom:1px solid #cbd5e1;">
                                                        <th style="padding:10px 14px; width:28%;">Field / Data</th>
                                                        <th style="padding:10px 14px; width:36%;">Data Profil Saat Ini</th>
                                                        <th style="padding:10px 14px; width:36%; background:#ecfdf5; color:#065f46;">Usulan Perubahan Baru</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $fieldNamesMap = [
                                                        'whatsapp' => 'Nomor WhatsApp',
                                                        'profession' => 'Profesi / Jenis Usaha',
                                                        'brand_name' => 'Nama Brand Usaha',
                                                        'business_logo' => 'Logo Usaha',
                                                        'description' => 'Deskripsi Tempat Usaha',
                                                        'province' => 'Provinsi',
                                                        'city' => 'Kabupaten / Kota',
                                                        'district' => 'Kecamatan',
                                                        'village' => 'Kelurahan / Desa',
                                                        'postal_code' => 'Kode Pos',
                                                        'address' => 'Alamat Usaha',
                                                        'address_detail' => 'Detail Patokan Alamat',
                                                        'permit_document' => 'Dokumen Perizinan Usaha',
                                                        'doc_permission' => 'Dokumen Perizinan Usaha (File)',
                                                        'workplace_photo' => 'Foto Tempat Usaha',
                                                        'doc_location_photo' => 'Foto Lokasi Tempat Usaha',
                                                        'instagram' => 'Instagram',
                                                        'facebook' => 'Facebook',
                                                        'linkedin' => 'LinkedIn',
                                                        'tiktok' => 'TikTok'
                                                    ];

                                                    $renderedCount = 0;
                                                    foreach ($pcrProposed as $fKey => $fNewVal):
                                                        $fOldVal = $pcrCurrent[$fKey] ?? ($selectedEmployer[$fKey] ?? '-');
                                                        $fLabel = $fieldNamesMap[$fKey] ?? ucfirst(str_replace('_', ' ', $fKey));
                                                        $renderedCount++;
                                                        $isDocField = in_array($fKey, ['permit_document', 'doc_permission', 'workplace_photo', 'doc_location_photo', 'business_logo'], true);
                                                    ?>
                                                        <tr style="border-bottom:1px solid #f1f5f9; background:<?php echo $renderedCount % 2 === 0 ? '#ffffff' : '#fafafa'; ?>;">
                                                            <td style="padding:10px 14px; font-weight:700; color:#334155; vertical-align:top;">
                                                                <?php echo e($fLabel); ?>
                                                            </td>
                                                            <td style="padding:10px 14px; color:#64748b; vertical-align:top;">
                                                                <?php if ($isDocField && !empty($fOldVal) && $fOldVal !== '-'): ?>
                                                                    <a href="<?php echo e($fOldVal); ?>" target="_blank" style="color:#0284c7; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:5px;">
                                                                        <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;"></i> Lihat Berkas Lama
                                                                    </a>
                                                                <?php else: ?>
                                                                    <?php echo e($fOldVal ?: '-'); ?>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td style="padding:10px 14px; background:#f0fdf4; color:#15803d; font-weight:700; vertical-align:top; border-left:1px solid #dcfce7;">
                                                                <?php if ($isDocField && !empty($fNewVal)): ?>
                                                                    <div style="display:flex; align-items:center; gap:10px;">
                                                                        <a href="<?php echo e($fNewVal); ?>" target="_blank" style="color:#15803d; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:6px; background:#dcfce7; padding:4px 10px; border-radius:6px; border:1px solid #bbf7d0;">
                                                                            <i class="fa-solid fa-file-arrow-down"></i> Buka Berkas Baru
                                                                        </a>
                                                                        <?php if (in_array(strtolower(pathinfo((string)$fNewVal, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp'], true)): ?>
                                                                            <img src="<?php echo e($fNewVal); ?>" alt="Preview" style="max-height:40px; border-radius:4px; border:1px solid #bbf7d0;">
                                                                        <?php endif; ?>
                                                                    </div>
                                                                <?php else: ?>
                                                                    <?php echo e($fNewVal ?: '-'); ?>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>

                                                    <?php if ($renderedCount === 0): ?>
                                                        <tr>
                                                            <td colspan="3" style="text-align:center; padding:16px; color:#94a3b8;">Tidak ada data komparasi usulan spesifik.</td>
                                                        </tr>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- FORM AKSI KEPUTUSAN ADMIN PCR LANGSUNG DI CARD (SAMA SEPERTI VERIFIKASI LOWONGAN) -->
                                    <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>" id="adminPcrDecisionForm" style="border-top:1px solid #e2e8f0; padding-top:18px; margin-top:14px;">
                                        <input type="hidden" name="admin_action" id="pcr_admin_action_input" value="approve_profile_change">
                                        <input type="hidden" name="request_id" value="<?php echo (int)$activeProfileChange['id']; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$selectedEmployer['user_id']; ?>">

                                        <div style="margin-bottom:14px;">
                                            <label style="display:block; font-size:13px; font-weight:700; color:#0f172a; margin-bottom:6px;">
                                                Usulan data profil telah ditinjau <span style="color:#ef4444;">*</span>
                                            </label>
                                            <div style="display:flex; align-items:center; gap:24px; font-size:13px; color:#334155;">
                                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                                    <input type="radio" name="pcr_reviewed" value="Ya" id="pcr_reviewed_ya" checked required>
                                                    <span>Ya, sudah ditinjau</span>
                                                </label>
                                                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                                    <input type="radio" name="pcr_reviewed" value="Tidak" id="pcr_reviewed_tidak">
                                                    <span>Belum</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div style="margin-bottom:16px;">
                                            <label for="pcr_admin_notes" style="display:block; font-size:13px; font-weight:700; color:#0f172a; margin-bottom:6px;">
                                                Catatan Admin / Verifikator: <span style="color:#ef4444;">*</span>
                                            </label>
                                            <textarea
                                                name="admin_notes"
                                                id="pcr_admin_notes"
                                                rows="3"
                                                required
                                                placeholder="Tuliskan catatan persetujuan atau alasan penolakan jika ditolak..."
                                                oninput="if(this.value.trim()){ const err=document.getElementById('pcrAdminNotesError'); if(err) err.style.display='none'; this.style.borderColor='#cbd5e1'; }"
                                                style="width:100%; box-sizing:border-box; font-family:inherit; font-size:13px; color:#0f172a; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; resize:vertical; min-height:80px; outline:none;"
                                            ></textarea>
                                            <div id="pcrAdminNotesError" style="display:none; color:#ef4444; font-size:12px; margin-top:5px; font-weight:600;">
                                                Catatan Admin / Verifikator wajib diisi.
                                            </div>
                                        </div>

                                        <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 18px; gap:16px; flex-wrap:wrap;">
                                            <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:8px; flex:1 1 260px;">
                                                <i class="fa-solid fa-shield-halved" style="color:#0284c7; font-size:15px; flex-shrink:0;"></i>
                                                <span>Keputusan Admin akan langsung memperbarui profil aktif dan mencatat snapshot versi baru ke riwayat versi.</span>
                                            </div>
                                            <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
                                                <button type="button" onclick="submitPcrDecision('reject_profile_change')" style="background:#fef2f2; border:1px solid #fecaca; border-radius:999px; padding:9px 20px; font-size:13px; font-weight:700; color:#b91c1c; cursor:pointer; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:inline-flex; align-items:center; gap:7px; white-space:nowrap; transition:all 0.15s ease;">
                                                    <i class="fa-solid fa-xmark"></i> Tolak
                                                </button>
                                                <button type="button" onclick="submitPcrDecision('approve_profile_change')" style="background:#0284c7; border:none; border-radius:999px; padding:9px 22px; font-size:13px; font-weight:700; color:#ffffff; cursor:pointer; box-shadow:0 2px 6px rgba(2,132,199,0.3); display:inline-flex; align-items:center; gap:7px; white-space:nowrap; transition:all 0.15s ease;">
                                                    <i class="fa-solid fa-check"></i> Setujui
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                    <script>
                                    function submitPcrDecision(action) {
                                        const form = document.getElementById('adminPcrDecisionForm');
                                        const actionInput = document.getElementById('pcr_admin_action_input');
                                        const notesInput = document.getElementById('pcr_admin_notes');
                                        const errEl = document.getElementById('pcrAdminNotesError');
                                        const reviewedRadio = document.querySelector('input[name="pcr_reviewed"]:checked');

                                        if (reviewedRadio && reviewedRadio.value === 'Tidak') {
                                            alert('Usulan data profil harus ditinjau terlebih dahulu sebelum mengambil keputusan.');
                                            return false;
                                        }

                                        if (!notesInput || !notesInput.value.trim()) {
                                            if (errEl) {
                                                errEl.style.display = 'block';
                                                errEl.textContent = 'Catatan Admin / Verifikator wajib diisi.';
                                            }
                                            if (notesInput) {
                                                notesInput.style.borderColor = '#ef4444';
                                                notesInput.focus();
                                            }
                                            return false;
                                        }

                                        if (errEl) errEl.style.display = 'none';
                                        if (notesInput) notesInput.style.borderColor = '#cbd5e1';
                                        if (actionInput) actionInput.value = action;
                                        if (form) form.submit();
                                        return true;
                                    }
                                    </script>
                                </div>
                            <?php endif; ?>

                            <!-- CARD RIWAYAT VERSI PROFIL (JIKA ADA RIWAYAT VERSI) -->
                            <?php if (!empty($profileVersions)): ?>
                                <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-timeline" style="color:#0284c7;"></i> Riwayat Versi Profil (Audit Trail)
                                    </div>
                                    <div style="display:flex; flex-direction:column; gap:10px;">
                                        <?php foreach ($profileVersions as $pVer): ?>
                                            <div style="display:flex; align-items:flex-start; justify-content:space-between; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                                                <div>
                                                    <div style="display:flex; align-items:center; gap:8px;">
                                                        <span style="background:#0284c7; color:#fff; font-size:11px; font-weight:800; padding:2px 8px; border-radius:999px;">
                                                            Versi <?php echo (int)$pVer['version_num']; ?>
                                                        </span>
                                                        <strong style="font-size:13px; color:#0f172a;"><?php echo e($pVer['change_summary'] ?: 'Pembaruan profil'); ?></strong>
                                                    </div>
                                                    <div style="font-size:11.5px; color:#64748b; margin-top:4px;">
                                                        Disimpan pada: <?php echo date('d M Y, H:i', strtotime($pVer['created_at'])); ?> WIB
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- CARD 1: RINGKASAN PENGAJUAN -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Ringkasan Pengajuan</div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; font-size:13px;">
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-database" style="color:#00a8e8; font-size:12px;"></i> Sumber Data
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;">Registrasi Platform</div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-calendar" style="color:#d97706; font-size:12px;"></i> Tanggal Pengajuan
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-shapes" style="color:#e11d48; font-size:12px;"></i> Tipe
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['entity_type'] ?? 'Individual'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-clock" style="color:#e11d48; font-size:12px;"></i> SLA Verifikasi
                                        </div>
                                        <div style="font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                            <?php
                                            $selDeadlineInfo = get_verification_deadline_info($selectedEmployer['created_at'] ?? null, $selectedEmployer['verification_status'] ?? 'PENDING', null, $selectedEmployer['updated_at'] ?? null, $isOnlineReverif);
                                            echo $selDeadlineInfo['badge_html'];
                                            ?>
                                            <?php if (!empty($selDeadlineInfo['deadline_time'])): ?>
                                                <span style="font-size:11.5px; font-weight:500; color:#64748b;">(s/d <?php echo date('d M Y, H:i', $selDeadlineInfo['deadline_time']); ?> WIB)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="grid-column: span 2;">
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-location-dot" style="color:#00a8e8; font-size:12px;"></i> Wilayah
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['city'] ?: 'Dalung, Kuta Utara, Kab. Badung, Bali'); ?><?php echo !empty($selectedEmployer['province']) ? ', ' . e($selectedEmployer['province']) : ''; ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- CARD 2: INFORMASI PENUGASAN DAN VERIFIKATOR -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Informasi Penugasan dan Verifikator</div>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; font-size:13px;">
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-user-check" style="color:#00a8e8; font-size:12px;"></i> Pemeriksa
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['assigned_to'] ?: 'Belum ditugaskan'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-shield-halved" style="color:#10b981; font-size:12px;"></i> Status Penugasan
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;">
                                            <?php if (!empty($selectedEmployer['assigned_to'])): ?>
                                                <span class="pill-badge assigned">● Ditugaskan</span>
                                            <?php else: ?>
                                                <span style="color:#94a3b8;">-</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="grid-column: span 2;">
                                        <div style="color:#64748b; margin-bottom:4px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-calendar-check" style="color:#d97706; font-size:12px;"></i> Ditugaskan Pada
                                        </div>
                                        <div style="font-weight:700; color:#0f172a;"><?php echo !empty($selectedEmployer['assigned_at']) ? date('d M Y, H:i', strtotime($selectedEmployer['assigned_at'])) : '-'; ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- SECTION METODE VERIFIKASI (DIATAS DATA PROFIL PEMBERI KERJA INDIVIDU) -->
                            <?php
                            $savedMethod = !empty($selectedEmployer['verification_method']) ? strtoupper($selectedEmployer['verification_method']) : 'ONLINE';
                            $savedLocResult = !empty($selectedEmployer['location_check_result']) ? strtoupper($selectedEmployer['location_check_result']) : 'SESUAI';
                            $isDecided = !$isOnlineReverif && !$isOfflineReverif && (in_array($status, ['APPROVED', 'REJECTED', 'NEEDS_REVISION', 'REVISION'], true) || $isRevisionStatus);
                            ?>
                            <div id="sectionMetodeVerifikasi" class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between;">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div style="width:28px; height:28px; border-radius:6px; background:#e0f2fe; color:#0284c7; display:flex; align-items:center; justify-content:center; font-size:14px;">
                                            <i class="fa-solid fa-clipboard-check"></i>
                                        </div>
                                        <span>Metode Verifikasi</span>
                                    </div>
                                    <?php if ($isDecided): ?>
                                        <?php if ($status === 'APPROVED'): ?>
                                            <span class="pill-badge" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0; font-size:12px; font-weight:700;">
                                                <i class="fa-solid fa-check"></i> Selesai Diverifikasi (Disetujui)
                                            </span>
                                        <?php elseif ($status === 'REJECTED'): ?>
                                            <span class="pill-badge" style="background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; font-size:12px; font-weight:700;">
                                                <i class="fa-solid fa-xmark"></i> Selesai Diverifikasi (Ditolak)
                                            </span>
                                        <?php else: ?>
                                            <span class="pill-badge" style="background:#fff7ed; color:#ea580c; border:1px solid #ffedd5; font-size:12px; font-weight:700;">
                                                <i class="fa-solid fa-clock-rotate-left"></i> Revisi Diminta (Menunggu Pemohon)
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <?php if ($isDecided): ?>
                                    <!-- DISPLAY HASIL METODE VERIFIKASI SEBELUMNYA (READ-ONLY) -->
                                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:14px;">
                                            <div>
                                                <div style="font-size:12px; color:#64748b; font-weight:600; margin-bottom:4px;">Metode Digunakan</div>
                                                <div style="font-size:14px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:6px;">
                                                    <?php if ($savedMethod === 'OFFLINE'): ?>
                                                        <i class="fa-solid fa-map-location-dot" style="color:#d97706;"></i> Offline (Pemeriksaan Lapangan)
                                                    <?php else: ?>
                                                        <i class="fa-solid fa-globe" style="color:#0284c7;"></i> Online (Pemeriksaan Sistem)
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($savedMethod === 'OFFLINE'): ?>
                                                <div>
                                                    <div style="font-size:12px; color:#64748b; font-weight:600; margin-bottom:4px;">Hasil Pemeriksaan Lokasi</div>
                                                    <div style="font-size:14px; font-weight:700; color:<?php echo $savedLocResult === 'SESUAI' ? '#15803d' : '#b45309'; ?>; display:flex; align-items:center; gap:6px;">
                                                        <i class="fa-solid <?php echo $savedLocResult === 'SESUAI' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
                                                        <?php echo $savedLocResult === 'SESUAI' ? 'Sesuai' : 'Tidak Sesuai'; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div style="font-size:12px; color:#64748b; font-weight:600; margin-bottom:4px;">Pemeriksa</div>
                                                <div style="font-size:13.5px; font-weight:700; color:#0f172a;">
                                                    <?php echo e($selectedEmployer['assigned_to'] ?: '-'); ?>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if ($savedMethod === 'OFFLINE'): ?>
                                            <div style="background:#ffffff; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                                                <span style="font-size:12.5px; color:#475569;">Alamat yang dilakukan pemeriksaan: <strong><?php echo e($selectedEmployer['address'] ?: '-'); ?></strong></span>
                                                <span class="badge-field-inspected" style="display:inline-flex; align-items:center; gap:5px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px;">
                                                    <i class="fa-solid fa-star" style="color:#eab308; font-size:11px;"></i> Sudah Dilakukan Pemeriksaan Lapangan
                                                </span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($selectedEmployer['verifier_notes'])): ?>
                                            <div style="border-top:1px solid #e2e8f0; padding-top:12px; margin-top:8px;">
                                                <div style="font-size:12px; color:#64748b; font-weight:600; margin-bottom:2px;">
                                                    <?php echo ($status === 'APPROVED') ? 'Catatan / Temuan Verifikator:' : (($status === 'REJECTED') ? 'Alasan Penolakan:' : 'Catatan Perbaikan (Dikirim ke Pemohon):'); ?>
                                                </div>
                                                <div style="font-size:13px; color:#1e293b; line-height:1.5;"><?php echo nl2br(e($selectedEmployer['verifier_notes'])); ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($status === 'NEEDS_REVISION' || $isRevisionStatus): ?>
                                            <div style="background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:12px 14px; margin-top:12px; display:flex; align-items:flex-start; gap:10px;">
                                                <i class="fa-solid fa-clock-rotate-left" style="color:#ea580c; margin-top:2px;"></i>
                                                <div style="font-size:12.5px; color:#9a3412; line-height:1.5;">
                                                    <strong>Menunggu Perbaikan Profil oleh Pemohon.</strong> Keputusan verifikasi telah dikirimkan dengan permintaan perbaikan data. Pilihan metode verifikasi dan keputusan dikunci sampai pemberi kerja mengirimkan perbaikan data profil.
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <!-- FORM INTERAKTIF METODE VERIFIKASI -->
                                    <?php if (!$hasAssignment): ?>
                                        <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px 14px; margin-bottom:16px; display:flex; align-items:flex-start; gap:10px;">
                                            <i class="fa-solid fa-circle-info" style="color:#b45309; margin-top:2px;"></i>
                                            <div style="font-size:12.5px; color:#92400e; line-height:1.4;">
                                                Pemberi kerja belum memiliki penugasan aktif. Silakan klik tombol <strong>"Ambil Pengajuan"</strong> pada bagian header terlebih dahulu untuk mengaktifkan proses verifikasi dan keputusan.
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- 1. RADIO BUTTON PILIHAN METODE -->
                                    <div style="margin-bottom:18px;">
                                        <label style="font-size:13px; font-weight:700; color:#0f172a; display:block; margin-bottom:8px;">
                                            Pilih Metode Verifikasi <span style="color:#ef4444;">*</span>
                                        </label>
                                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px;">
                                            <!-- Pilihan Online -->
                                            <label id="optVerifOnlineCard" style="display:flex; align-items:flex-start; gap:12px; padding:14px; border:2px solid <?php echo ($savedMethod === 'ONLINE') ? '#0284c7' : '#e2e8f0'; ?>; background:<?php echo ($savedMethod === 'ONLINE') ? '#f0f9ff' : '#ffffff'; ?>; border-radius:12px; <?php echo !$hasAssignment ? 'opacity:0.65; cursor:not-allowed;' : 'cursor:pointer;'; ?> transition:all 0.2s;">
                                                <input type="radio" name="verification_method_radio" value="ONLINE" <?php echo ($savedMethod === 'ONLINE') ? 'checked' : ''; ?> <?php echo !$hasAssignment ? 'disabled' : ''; ?> onchange="onMetodeVerifikasiChange('ONLINE')" style="margin-top:2px; width:17px; height:17px; accent-color:#0284c7; cursor:pointer;">
                                                <div>
                                                    <div style="font-size:13.5px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:6px;">
                                                        <i class="fa-solid fa-globe" style="color:#0284c7;"></i> Online
                                                    </div>
                                                    <div style="font-size:12px; color:#64748b; margin-top:2px; line-height:1.4;">
                                                        Pemeriksaan dokumen & kesesuaian data pengajuan secara digital pada sistem.
                                                    </div>
                                                </div>
                                            </label>

                                            <!-- Pilihan Offline -->
                                            <label id="optVerifOfflineCard" style="display:flex; align-items:flex-start; gap:12px; padding:14px; border:2px solid <?php echo ($savedMethod === 'OFFLINE') ? '#0284c7' : '#e2e8f0'; ?>; background:<?php echo ($savedMethod === 'OFFLINE') ? '#f0f9ff' : '#ffffff'; ?>; border-radius:12px; <?php echo !$hasAssignment ? 'opacity:0.65; cursor:not-allowed;' : 'cursor:pointer;'; ?> transition:all 0.2s;">
                                                <input type="radio" name="verification_method_radio" value="OFFLINE" <?php echo ($savedMethod === 'OFFLINE') ? 'checked' : ''; ?> <?php echo !$hasAssignment ? 'disabled' : ''; ?> onchange="onMetodeVerifikasiChange('OFFLINE')" style="margin-top:2px; width:17px; height:17px; accent-color:#0284c7; cursor:pointer;">
                                                <div>
                                                    <div style="font-size:13.5px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:6px;">
                                                        <i class="fa-solid fa-map-location-dot" style="color:#d97706;"></i> Offline
                                                    </div>
                                                    <div style="font-size:12px; color:#64748b; margin-top:2px; line-height:1.4;">
                                                        Pemeriksaan / verifikasi lapangan langsung ke alamat lokasi tempat usaha.
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- 2. PANEL ONLINE -->
                                    <div id="panelMetodeOnline" style="display:<?php echo ($savedMethod === 'ONLINE') ? 'block' : 'none'; ?>; background:#f0f9ff; border:1px solid #bae6fd; border-radius:12px; padding:16px; margin-bottom:18px;">
                                        <div style="font-size:13px; font-weight:800; color:#0369a1; margin-bottom:10px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-shield-check"></i> Consent Verifikasi Online <span style="color:#ef4444; font-weight:700;">*</span>
                                        </div>
                                        <label id="labelConsentOnline" style="display:flex; align-items:flex-start; gap:12px; <?php echo !$hasAssignment ? 'opacity:0.65; cursor:not-allowed;' : 'cursor:pointer;'; ?> background:#ffffff; border:1.5px solid #cbd5e1; border-radius:8px; padding:14px; transition:border-color 0.2s;">
                                            <input type="checkbox" id="checkConsentOnline" name="consent_online" value="1" <?php echo !$hasAssignment ? 'disabled' : ''; ?> onchange="onConsentChange()" style="margin-top:2px; width:18px; height:18px; accent-color:#0284c7; cursor:pointer; flex-shrink:0;">
                                            <span style="font-size:13px; color:#0f172a; line-height:1.5; font-weight:500;">
                                                “Saya telah melakukan pemeriksaan/verifikasi secara online terhadap data dan dokumen pengajuan Pemberi Kerja Individu serta memberikan hasil pemeriksaan berdasarkan informasi yang tersedia pada sistem.”
                                            </span>
                                        </label>
                                    </div>

                                    <!-- 3. PANEL OFFLINE -->
                                    <div id="panelMetodeOffline" style="display:<?php echo ($savedMethod === 'OFFLINE') ? 'block' : 'none'; ?>; background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:16px; margin-bottom:18px;">
                                        <div style="font-size:13px; font-weight:800; color:#92400e; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-person-walking-luggage"></i> Pemeriksaan Lapangan (Offline)
                                        </div>

                                        <!-- Alamat dengan badge/bintang -->
                                        <div style="background:#ffffff; border:1px solid #fcd34d; border-radius:10px; padding:12px 14px; margin-bottom:14px;">
                                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:4px;">
                                                <span style="font-size:12px; color:#64748b; font-weight:600;">Alamat Pemeriksaan Lapangan:</span>
                                                <span id="badgeAlamatSection" class="badge-field-inspected" style="display:<?php echo ($savedMethod === 'OFFLINE') ? 'inline-flex' : 'none'; ?>; align-items:center; gap:5px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px;">
                                                    <i class="fa-solid fa-star" style="color:#eab308; font-size:11px;"></i> Sudah Dilakukan Pemeriksaan Lapangan
                                                </span>
                                            </div>
                                            <div style="font-size:13.5px; font-weight:700; color:#0f172a; line-height:1.4;">
                                                <?php echo e($selectedEmployer['address'] ?: 'Jl. Ir. H. Juanda No. 25'); ?><?php echo !empty($selectedEmployer['city']) ? ', ' . e($selectedEmployer['city']) : ''; ?>
                                            </div>
                                            <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                                Patokan / Detail: <?php echo e($selectedEmployer['address_detail'] ?: '-'); ?> | Kode Pos: <?php echo e($selectedEmployer['postal_code'] ?: '-'); ?>
                                            </div>
                                        </div>

                                        <!-- Radio button Hasil Pemeriksaan Lokasi/Alamat: Sesuai / Tidak Sesuai -->
                                        <div style="margin-bottom:14px;">
                                            <label style="font-size:13px; font-weight:700; color:#0f172a; display:block; margin-bottom:8px;">
                                                Hasil Pemeriksaan Lokasi/Alamat <span style="color:#ef4444;">*</span>
                                            </label>
                                            <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                                                <label id="labelLocSesuai" style="display:inline-flex; align-items:center; gap:8px; background:#ffffff; border:1.5px solid <?php echo ($savedLocResult !== 'TIDAK_SESUAI') ? '#10b981' : '#cbd5e1'; ?>; padding:8px 16px; border-radius:8px; <?php echo !$hasAssignment ? 'opacity:0.65; cursor:not-allowed;' : 'cursor:pointer;'; ?> transition:all 0.2s;">
                                                    <input type="radio" name="location_check_result_radio" value="SESUAI" <?php echo ($savedLocResult !== 'TIDAK_SESUAI') ? 'checked' : ''; ?> <?php echo !$hasAssignment ? 'disabled' : ''; ?> onchange="onLocationResultChange('SESUAI')" style="accent-color:#10b981; width:16px; height:16px; cursor:pointer;">
                                                    <span style="font-size:13px; font-weight:700; color:#065f46; display:flex; align-items:center; gap:6px;">
                                                        <i class="fa-solid fa-circle-check" style="color:#10b981;"></i> Sesuai
                                                    </span>
                                                </label>
                                                <label id="labelLocTidakSesuai" style="display:inline-flex; align-items:center; gap:8px; background:#ffffff; border:1.5px solid <?php echo ($savedLocResult === 'TIDAK_SESUAI') ? '#f59e0b' : '#cbd5e1'; ?>; padding:8px 16px; border-radius:8px; <?php echo !$hasAssignment ? 'opacity:0.65; cursor:not-allowed;' : 'cursor:pointer;'; ?> transition:all 0.2s;">
                                                    <input type="radio" name="location_check_result_radio" value="TIDAK_SESUAI" <?php echo ($savedLocResult === 'TIDAK_SESUAI') ? 'checked' : ''; ?> <?php echo !$hasAssignment ? 'disabled' : ''; ?> onchange="onLocationResultChange('TIDAK_SESUAI')" style="accent-color:#f59e0b; width:16px; height:16px; cursor:pointer;">
                                                    <span style="font-size:13px; font-weight:700; color:#92400e; display:flex; align-items:center; gap:6px;">
                                                        <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i> Tidak Sesuai
                                                    </span>
                                                </label>
                                            </div>
                                            <div id="offlineResultNotice" style="font-size:12px; color:#92400e; margin-top:6px; display:<?php echo ($savedLocResult === 'TIDAK_SESUAI') ? 'block' : 'none'; ?>;">
                                                <i class="fa-solid fa-circle-info"></i> Catatan: Pada metode Offline, hasil alamat <strong>Tidak Sesuai</strong> tidak otomatis menonaktifkan tombol Setujui jika verifikator memiliki pertimbangan khusus.
                                            </div>
                                        </div>

                                        <!-- Checkbox wajib Pernyataan Pemeriksaan Lapangan -->
                                        <div>
                                            <div style="font-size:13px; font-weight:800; color:#92400e; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                                <i class="fa-solid fa-clipboard-check"></i> Pernyataan Pemeriksaan Lapangan <span style="color:#ef4444; font-weight:700;">*</span>
                                            </div>
                                            <label id="labelConsentField" style="display:flex; align-items:flex-start; gap:12px; <?php echo !$hasAssignment ? 'opacity:0.65; cursor:not-allowed;' : 'cursor:pointer;'; ?> background:#ffffff; border:1.5px solid #fcd34d; border-radius:8px; padding:14px; transition:border-color 0.2s;">
                                                <input type="checkbox" id="checkConsentField" name="consent_field" value="1" <?php echo !$hasAssignment ? 'disabled' : ''; ?> onchange="onConsentChange()" style="margin-top:2px; width:18px; height:18px; accent-color:#d97706; cursor:pointer; flex-shrink:0;">
                                                <span style="font-size:13px; color:#0f172a; line-height:1.5; font-weight:500;">
                                                    “Saya telah melakukan pemeriksaan/verifikasi lapangan terhadap pengajuan Pemberi Kerja Individu dan memberikan hasil pemeriksaan berdasarkan kondisi yang saya temukan.”
                                                </span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- 5. PETUNJUK KEPUTUSAN VERIFIKASI -->
                                    <div id="consentStatusNotice" style="font-size:12.5px; color:#64748b; display:flex; align-items:center; gap:8px; border-top:1px solid #e2e8f0; padding-top:14px; margin-top:4px;">
                                        <?php if (!$hasAssignment): ?>
                                            <i class="fa-solid fa-lock" style="color:#94a3b8;"></i>
                                            <span>Pemberi kerja belum memiliki penugasan aktif. Silakan klik tombol <strong>"Ambil Pengajuan"</strong> pada bagian header terlebih dahulu untuk mengaktifkan pilihan metode dan verifikasi.</span>
                                        <?php else: ?>
                                            <i class="fa-solid fa-lock" style="color:#94a3b8;"></i>
                                            <span>Tombol keputusan (<strong>Setujui</strong> / <strong>Revisi</strong> / <strong>Tolak</strong>) akan aktif setelah consent verifikasi di atas dicentang.</span>
                                        <?php endif; ?>
                                    </div>

                                    
                                <?php endif; ?>
                            </div>

                            <?php
                            $vDocPath = $selectedEmployer['permit_document'] ?: ($selectedEmployer['doc_permission'] ?: '');
                            if (empty($vDocPath) || (!file_exists(__DIR__ . '/' . ltrim($vDocPath, '/')) && !file_exists($vDocPath))) {
                                if (file_exists(__DIR__ . '/public/images/surat-pernyataan-usaha.pdf')) {
                                    $vDocPath = 'public/images/surat-pernyataan-usaha.pdf';
                                }
                            }
                            $vHasDoc = !empty($vDocPath);
                            $vDocName = $vHasDoc ? basename($vDocPath) : 'Tidak ada dokumen';

                            $vPhotoPath = $selectedEmployer['workplace_photo'] ?: ($selectedEmployer['doc_location_photo'] ?: '');
                            if (empty($vPhotoPath) || (!file_exists(__DIR__ . '/' . ltrim($vPhotoPath, '/')) && !file_exists($vPhotoPath))) {
                                if (file_exists(__DIR__ . '/public/images/foto-lokasi.png')) {
                                    $vPhotoPath = 'public/images/foto-lokasi.png';
                                } elseif (file_exists(__DIR__ . '/public/images/jobi-mascot.png')) {
                                    $vPhotoPath = 'public/images/jobi-mascot.png';
                                }
                            }
                            $vHasPhoto = !empty($vPhotoPath);
                            $vPhotoName = $vHasPhoto ? basename($vPhotoPath) : 'Tidak ada foto';
                            ?>

                            <!-- CARD 3: DATA PROFIL TABLE (INDIVIDUAL VS PERUSAHAAN/OSS) -->
                            <?php if (strcasecmp((string)($selectedEmployer['entity_type'] ?? 'Individual'), 'Perusahaan') === 0): ?>
                                <!-- TABLE FOR PERUSAHAAN (PERBANDINGAN DATA OSS) -->
                                <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:4px;">Perbandingan Data Pemberi Kerja dan OSS</div>
                                    <p style="font-size:12px; color:#64748b; margin:0 0 14px 0;">Data OSS diambil otomatis berdasarkan NIB perusahaan.</p>
                                    <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:10px 14px; font-size:12.5px; color:#991b1b; margin-bottom:14px;">
                                        Data pemberi kerja ini tidak dapat ditemukan karena NIB tidak terdaftar di WUP.
                                    </div>
                                    <div style="overflow-x:auto;">
                                        <table class="compare-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                                            <thead>
                                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left; color:#475569;">
                                                    <th style="padding:10px 14px; font-weight:700;">Variabel</th>
                                                    <th style="padding:10px 14px; font-weight:700;">Data Pemberi Kerja</th>
                                                    <th style="padding:10px 14px; font-weight:700;">Data OSS</th>
                                                    <th style="padding:10px 14px; font-weight:700;">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Nama Pemberi Kerja</td><td style="padding:10px 14px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">NIB</td><td style="padding:10px 14px; color:#0f172a;">-</td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Email</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['email']); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Telepon</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['phone']); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Alamat</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['address'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Provinsi</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['province'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Kota/Kabupaten</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['city'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600;">Kode Pos</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['postal_code'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                                <tr><td style="padding:10px 14px; font-weight:600;">Deskripsi</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['description'] ?: '-'); ?></td><td style="padding:10px 14px; color:#94a3b8;">-</td><td><span class="pill-badge danger" style="font-size:11px; padding:2px 8px;">Tidak Ditemukan</span></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php if ($isOnlineReverif): ?>
                                    <?php render_online_reverification_comparison($selectedEmployer); ?>
                                <?php else: ?>
                                    <!-- TABLE FOR INDIVIDUAL (DATA PROFIL PEMBERI KERJA INDIVIDU) -->
                                    <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                        <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Data Profil Pemberi Kerja Individu</div>
                                    <div style="overflow-x:auto;">
                                        <table class="compare-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                                            <thead>
                                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left; color:#475569;">
                                                    <th style="padding:10px 14px; font-weight:700; width:260px;">Variabel</th>
                                                    <th style="padding:10px 14px; font-weight:700;">Data Pemberi Kerja Individu</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Nama Pemberi Kerja</td><td style="padding:10px 14px; font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">NIK</td><td style="padding:10px 14px; color:#0f172a;"><code><?php echo e($selectedEmployer['nik'] ?: '3273xxxxxxxxxxxx'); ?></code></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Email</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['email'] ?: 'ahmad@email.com'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Telepon</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['phone'] ?: '0812xxxxxxxx'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">WhatsApp</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['whatsapp'] ?: '0812xxxxxxxx'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Jenis Profesi / Usaha Individu</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['profession'] ?: ($selectedEmployer['description'] ?: 'Jasa Desain Grafis')); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">NPWP</td><td style="padding:10px 14px; color:#0f172a;"><code><?php echo e($selectedEmployer['npwp'] ?: '12.345.678.9-123.000'); ?></code></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Sosial Media</td><td style="padding:10px 14px; color:#0f172a;">Instagram: @<?php echo e(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $selectedEmployer['owner_name'] ?: $selectedEmployer['name']))); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Lokasi Domisili</td><td style="padding:10px 14px; color:#0f172a;"><?php 
                                                     $empLocParts = array_filter([
                                                         $selectedEmployer['village'] ?? '',
                                                         $selectedEmployer['district'] ?? '',
                                                         $selectedEmployer['city'] ?? '',
                                                         $selectedEmployer['province'] ?? ''
                                                     ]);
                                                     echo e(!empty($empLocParts) ? implode(', ', $empLocParts) : ($selectedEmployer['city'] ?: 'Dago, Coblong, Kota Bandung, Jawa Barat'));
                                                 ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;">
                                                    <td style="padding:10px 14px; font-weight:600; color:#334155;">Alamat Lengkap</td>
                                                    <td style="padding:10px 14px; color:#0f172a;">
                                                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                                            <span><?php echo e($selectedEmployer['address'] ?: 'Jl. Ir. H. Juanda No. 25'); ?></span>
                                                            <span id="badgeAlamatTable" class="badge-field-inspected" style="display:<?php echo (!empty($selectedEmployer['verification_method']) && strtoupper($selectedEmployer['verification_method']) === 'OFFLINE') ? 'inline-flex' : 'none'; ?>; align-items:center; gap:5px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px;">
                                                                <i class="fa-solid fa-star" style="color:#eab308; font-size:11px;"></i> Sudah Dilakukan Pemeriksaan Lapangan
                                                            </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Detail Alamat / Patokan</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['address_detail'] ?: 'Dekat persimpangan utama'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Kode Pos</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['postal_code'] ?: '40135'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Nama Brand Usaha</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['brand_name'] ?: '-'); ?></td></tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:10px 14px; font-weight:600; color:#334155;">Deskripsi Singkat Usaha / Rekrutmen</td><td style="padding:10px 14px; color:#0f172a;"><?php echo e($selectedEmployer['description'] ?: 'Usaha jasa desain grafis dan digital'); ?></td></tr>
                                                <?php
                                                    $vDocPath = $selectedEmployer['permit_document'] ?: ($selectedEmployer['doc_permission'] ?: '');
                                    if (empty($vDocPath) || (!file_exists(__DIR__ . '/' . ltrim($vDocPath, '/')) && !file_exists($vDocPath))) {
                                        if (file_exists(__DIR__ . '/public/images/surat-pernyataan-usaha.pdf')) {
                                            $vDocPath = 'public/images/surat-pernyataan-usaha.pdf';
                                        }
                                    }
                                                    $vHasDoc = !empty($vDocPath);
                                                    $vDocName = $vHasDoc ? basename($vDocPath) : 'Tidak ada dokumen';

                                                    $vPhotoPath = $selectedEmployer['workplace_photo'] ?: ($selectedEmployer['doc_location_photo'] ?: '');
                    if (empty($vPhotoPath) || (!file_exists(__DIR__ . '/' . ltrim($vPhotoPath, '/')) && !file_exists($vPhotoPath))) {
                        if (file_exists(__DIR__ . '/public/images/foto-lokasi.png')) {
                            $vPhotoPath = 'public/images/foto-lokasi.png';
                        } elseif (file_exists(__DIR__ . '/public/images/jobi-mascot.png')) {
                            $vPhotoPath = 'public/images/jobi-mascot.png';
                        }
                    }
                                                    $vHasPhoto = !empty($vPhotoPath);
                                                    $vPhotoName = $vHasPhoto ? basename($vPhotoPath) : 'Tidak ada foto';
                                                ?>
                                                <tr style="border-bottom:1px solid #f1f5f9;">
                                                    <td style="padding:10px 14px; font-weight:600; color:#334155;">Dokumen Perizinan Usaha</td>
                                                    <td style="padding:10px 14px; color:#0f172a;">
                                                        <?php if ($vHasDoc): ?>
                                                            <div style="display:flex; align-items:center; gap:10px;">
                                                                <span style="font-weight:600; color:#0f172a;"><i class="fa-solid fa-file-pdf" style="color:#ef4444; margin-right:4px;"></i> <?php echo e($vDocName); ?></span>
                                                                <button type="button" data-open-modal="modal-view-doc" style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; padding:4px 10px; color:#0284c7; font-weight:700; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;"></i> Lihat File
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <span style="color:#94a3b8; font-style:italic;">Belum ada file diunggah</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <tr style="border-bottom:1px solid #f1f5f9;">
                                                    <td style="padding:10px 14px; font-weight:600; color:#334155;">Foto Bukti Tempat Usaha / Lokasi</td>
                                                    <td style="padding:10px 14px; color:#0f172a;">
                                                        <?php if ($vHasPhoto): ?>
                                                            <div style="display:flex; align-items:center; gap:10px;">
                                                                <span style="font-weight:600; color:#0f172a;"><i class="fa-solid fa-image" style="color:#0284c7; margin-right:4px;"></i> <?php echo e($vPhotoName); ?></span>
                                                                <button type="button" data-open-modal="modal-view-photos" style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; padding:4px 10px; color:#0284c7; font-weight:700; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                                                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;"></i> Lihat Foto
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <span style="color:#94a3b8; font-style:italic;">Belum ada foto diunggah</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="padding:10px 14px; font-weight:600; color:#334155;">Logo Usaha</td>
                                                    <td style="padding:10px 14px; color:#0f172a;">
                                                        <div style="display:flex; align-items:center; gap:10px;">
                                                            <div style="width:28px; height:28px; border-radius:6px; overflow:hidden; border:1px solid #e2e8f0; background:#f8fafc; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                                <img src="public/images/jobii.png" alt="Logo Usaha" style="width:100%; height:100%; object-fit:contain;">
                                                            </div>
                                                            <span style="font-weight:600; color:#0f172a;"><i class="fa-solid fa-image" style="color:#0284c7; margin-right:4px;"></i> <?php echo e(!empty($selectedEmployer['business_logo']) ? basename($selectedEmployer['business_logo']) : 'jobii.png'); ?></span>
                                                            <button type="button" data-open-modal="modal-view-logo" style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; padding:4px 10px; color:#0284c7; font-weight:700; font-size:12px; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                                                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;"></i> Lihat Foto
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <?php if ($isOfflineReverif): ?>
                                    <!-- FORM EDITABLE KHUSUS VERIFIKASI ULANG PROFIL OFFLINE -->
                                    <details id="detailsAdminProfileFormOffline" class="section-card" style="background:#ffffff; border:1.5px solid #cbd5e1; border-radius:14px; padding:20px; margin-bottom:20px;" open>
                                        <summary style="font-weight:800; color:#0f172a; cursor:pointer; font-size:14.5px; display:flex; align-items:center; justify-content:space-between; user-select:none; margin-bottom:12px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <span style="display:flex; align-items:center; gap:8px;">
                                                    <i class="fa-solid fa-pen-to-square" style="color:#0284c7;"></i> Form Profil Pemberi Kerja Individu (Versi Admin - Prefilled &amp; Editable)
                                                </span>
                                                <span class="pill-badge" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:5px; padding:2px 8px;">
                                                    <i class="fa-solid fa-building-user"></i> Mode Offline
                                                </span>
                                            </div>
                                            <span style="font-size:11.5px; font-weight:600; color:#0284c7; background:#e0f2fe; padding:3px 10px; border-radius:999px;">
                                                Buka / Tutup
                                            </span>
                                        </summary>
                                        <div style="font-size:12px; color:#64748b; margin-top:4px; margin-bottom:16px;">
                                            Verifikasi Ulang Profil Offline: Petugas dapat memperbarui data profil pemohon secara langsung berdasarkan hasil pemeriksaan lapangan sebelum menetapkan keputusan.
                                        </div>
                                        <?php render_admin_employer_profile_form($selectedEmployer, 'offline_reverif'); ?>
                                    </details>
                                <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>

                            <!-- CARD: HASIL VERIFIKASI (IF REVISI DIMINTA / PROCESSED) -->
                            <?php if ($isRevisionStatus): ?>
                                <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Hasil Verifikasi</div>
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px 20px; font-size:13px; margin-bottom:16px;">
                                        <div>
                                            <div style="color:#64748b; margin-bottom:4px; font-weight:500;">Status Keputusan</div>
                                            <div>
                                                <span style="background:#fffbeb; color:#b45309; border:1px solid #fde68a; font-weight:700; font-size:12px; padding:4px 10px; border-radius:12px; display:inline-flex; align-items:center; gap:6px;">
                                                    <span style="width:7px; height:7px; border-radius:50%; background:#f59e0b;"></span> Revisi Diminta (Revisi ke-<?php echo e($selectedEmployer['revision_count'] ?? 1); ?>)
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <div style="color:#64748b; margin-bottom:4px; font-weight:500;">Tanggal Keputusan</div>
                                            <div style="font-weight:600; color:#0f172a;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['updated_at'] ?? '2026-09-24 09:18')); ?></div>
                                        </div>
                                        <div style="grid-column: span 2;">
                                            <div style="color:#64748b; margin-bottom:4px; font-weight:500;">Nama Pemeriksa</div>
                                            <div style="font-weight:700; color:#0f172a;"><?php echo e($selectedEmployer['assigned_to'] ?: 'Budi Santoso'); ?></div>
                                        </div>
                                    </div>
                                    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px;">
                                        <div style="font-size:12.5px; font-weight:700; color:#92400e; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-circle-exclamation"></i> Catatan Perbaikan
                                        </div>
                                        <div style="font-size:13px; color:#78350f; line-height:1.5;">
                                            <?php echo e($selectedEmployer['verifier_notes'] ?: 'Mohon perbaiki dan lengkapi foto tempat usaha serta sesuaikan dokumen identitas pendukung dengan alamat domisili terbaru.'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- CARD 4: INFORMASI PEMBERI KERJA DETAIL & MAP -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                    <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin:0;">Informasi Pemberi Kerja Individu</div>
                                    <a href="#" onclick="event.preventDefault();" style="color:#00a8e8; text-decoration:none; font-size:12.5px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Lihat Detail
                                    </a>
                                </div>

                                <div style="margin-bottom:14px;">
                                    <div style="font-size:16px; font-weight:800; color:#0f172a;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                    <div style="font-size:12.5px; color:#64748b; margin-top:2px; font-weight:500;"><?php echo e($isIndividual ? 'Individual' : ($selectedEmployer['entity_type'] ?? 'Perusahaan')); ?></div>
                                </div>

                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px 20px; font-size:13px; margin-bottom:16px;">
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-location-dot" style="color:#94a3b8;"></i> Wilayah
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['city'] ?: 'Dago, Coblong, Kota Bandung, Jawa Barat'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-calendar" style="color:#94a3b8;"></i> Tanggal Daftar
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-regular fa-envelope" style="color:#94a3b8;"></i> Email
                                        </div>
                                        <div style="font-weight:600; color:#00a8e8;"><?php echo e($selectedEmployer['email'] ?: 'ahmad@email.com'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-phone" style="color:#94a3b8;"></i> Telepon
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['phone'] ?: '0812xxxxxxxx'); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-brands fa-instagram" style="color:#94a3b8;"></i> Sosial Media
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;">Instagram: @<?php echo e(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $selectedEmployer['owner_name'] ?: $selectedEmployer['name']))); ?></div>
                                    </div>
                                    <div>
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-brands fa-whatsapp" style="color:#94a3b8;"></i> WhatsApp
                                        </div>
                                        <div style="font-weight:600; color:#0f172a;"><?php echo e($selectedEmployer['whatsapp'] ?: '0812xxxxxxxx'); ?></div>
                                    </div>
                                    <div style="grid-column: span 2;">
                                        <div style="color:#64748b; margin-bottom:2px; font-weight:500; display:flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-house" style="color:#94a3b8;"></i> Alamat Lengkap
                                        </div>
                                        <div style="font-weight:600; color:#0f172a; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                            <span><?php echo e($selectedEmployer['address'] ?: 'Jl. Ir. H. Juanda No. 25'); ?></span>
                                            <span id="badgeAlamatDetail" class="badge-field-inspected" style="display:<?php echo (!empty($selectedEmployer['verification_method']) && strtoupper($selectedEmployer['verification_method']) === 'OFFLINE') ? 'inline-flex' : 'none'; ?>; align-items:center; gap:5px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px;">
                                                <i class="fa-solid fa-star" style="color:#eab308; font-size:11px;"></i> Sudah Dilakukan Pemeriksaan Lapangan
                                            </span>
                                        </div>
                                        <div style="font-size:12px; color:#64748b; margin-top:2px;">Kode Pos: <?php echo e($selectedEmployer['postal_code'] ?: '40135'); ?></div>
                                    </div>
                                </div>

                                <!-- MAP PREVIEW BOX -->
                                <div style="border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:16px; background:#e0f2fe; height:180px; position:relative; display:flex; align-items:center; justify-content:center;">
                                    <iframe width="100%" height="180" frameborder="0" style="border:0;" src="https://maps.google.com/maps?q=<?php echo urlencode($selectedEmployer['address'] ?: ($selectedEmployer['city'] ?: 'Kota Bandung Jawa Barat')); ?>&t=&z=13&ie=UTF8&iwloc=&output=embed" allowfullscreen></iframe>
                                </div>

                                <div>
                                    <div style="color:#64748b; font-size:12.5px; font-weight:600; margin-bottom:4px;">Deskripsi Singkat Usaha / Rekrutmen</div>
                                    <div style="font-size:13px; color:#334155; line-height:1.5;"><?php echo e($selectedEmployer['description'] ?: 'Usaha jasa desain grafis dan digital'); ?></div>
                                </div>
                            </div>

                            <!-- CARD 5: ASSIGN / REASSIGN PEMERIKSA -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">
                                    Assign Pemeriksa
                                </div>

                                <?php if (!$hasAssignment): ?>
                                    <!-- NOTICE BOX WHEN UNASSIGNED -->
                                    <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px; margin-bottom:16px;">
                                        <div style="font-size:13px; font-weight:700; color:#92400e; margin-bottom:4px;">Perhatian</div>
                                        <div style="font-size:12.5px; color:#b45309; line-height:1.4;">
                                            Untuk mengubah pemeriksa, pemberi kerja harus memiliki penugasan aktif terlebih dahulu. Silakan ambil case terlebih dahulu melalui aksi di header.
                                        </div>
                                    </div>

                                    <div style="margin-bottom:14px;">
                                        <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Pemeriksa <span style="color:#ef4444;">*</span></label>
                                        <div style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; background:#f8fafc; color:#94a3b8; font-size:13px; cursor:not-allowed;">
                                            <span>Pilih pemeriksa...</span>
                                            <i class="fa-solid fa-chevron-down" style="color:#cbd5e1; font-size:11px;"></i>
                                        </div>
                                    </div>

                                    <div style="margin-bottom:16px;">
                                        <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Alasan <span style="color:#ef4444;">*</span></label>
                                        <textarea disabled placeholder="Masukkan alasan penugasan (minimal 10 karakter)..." style="width:100%; min-height:80px; padding:10px 12px; border-radius:8px; border:1px solid #e2e8f0; background:#f8fafc; font-size:13px; color:#94a3b8; outline:none; font-family:inherit; cursor:not-allowed; resize:none;"></textarea>
                                    </div>

                                    <button type="button" disabled style="width:100%; height:42px; background:#7dd3fc; opacity:0.6; border:none; border-radius:10px; color:#0369a1; font-weight:700; font-size:13.5px; cursor:not-allowed;">
                                        Assign Pemeriksa
                                    </button>
                                <?php else: ?>
                                    <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                        <input type="hidden" name="admin_action" value="assign_employer_case">
                                        <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">

                                        <div style="margin-bottom:14px;">
                                            <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Pemeriksa saat ini</label>
                                            <div style="border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; background:#f8fafc; color:#0f172a; font-weight:700; font-size:13px;">
                                                <?php echo e($selectedEmployer['assigned_to']); ?>
                                            </div>
                                        </div>

                                        <div style="margin-bottom:14px; position:relative;">
                                            <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Pemeriksa <span style="color:#ef4444;">*</span></label>
                                            <input type="hidden" name="verifier_name" id="inputAssignPemeriksa" required value="">
                                            <div id="assignPemeriksaTrigger" onclick="toggleAssignPemeriksaDropdown(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #cbd5e1; border-radius:8px; padding:10px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#0f172a;">
                                                <span id="assignPemeriksaLabel" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#64748b;">Pilih pemeriksa...</span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- SEARCHABLE DROPDOWN CARD -->
                                            <div id="assignPemeriksaDropdownCard" style="display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); z-index:1005; padding:8px;">
                                                <input type="text" id="assignPemeriksaSearchInput" onkeyup="filterAssignPemeriksaOptions()" placeholder="Cari Pemeriksa..." style="width:100%; border:1px solid #00a8e8; border-radius:8px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="assignPemeriksaOptionsContainer" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>

                                        <div style="margin-bottom:16px;">
                                            <label style="font-size:13px; font-weight:600; color:#0f172a; display:block; margin-bottom:6px;">Alasan <span style="color:#ef4444;">*</span></label>
                                            <textarea name="assignment_reason" required minlength="10" placeholder="Masukkan alasan penugasan (minimal 10 karakter)..." style="width:100%; min-height:80px; padding:10px 12px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; color:#0f172a; outline:none; font-family:inherit;"></textarea>
                                        </div>

                                        <button type="submit" id="btnSubmitAssignPemeriksa" disabled style="width:100%; height:42px; background:#7dd3fc; opacity:0.6; border:none; border-radius:10px; color:#0369a1; font-weight:700; font-size:13.5px; cursor:not-allowed; transition:all 0.2s;">
                                            Assign Pemeriksa
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <!-- ========================================== -->
                            <!-- JALUR MANUAL DINAS SECTION (REVISI DIMINTA KE-3) -->
                            <!-- ========================================== -->
                            <?php if ($isDinasFlow): ?>
                                <div id="sectionManualDinasEdit" class="section-card" style="border:2px solid #0284c7; background:#f0f9ff; border-radius:14px; padding:20px;">
                                    <div class="section-card-title" style="color:#0369a1; font-size:15px; font-weight:800; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-hands-holding-child"></i> Pendampingan & Permohonan Ulang Bersama Petugas Dinas (Revisi Diminta ke-3)
                                    </div>
                                    <p style="font-size:13px; color:#0c4a6e; line-height:1.5; margin-bottom:16px;">
                                        Pengajuan ini telah mencapai batas maksimal revisi mandiri (<strong>Revisi Diminta ke-3</strong>). Petugas Dinas sesuai domisili dapat membantu memperbaiki data profil pemohon, mengirimkan permohonan persetujuan (Consent), memverifikasi secara manual, dan menyetujui serta mengaktifkan hak akses.
                                    </p>

                                    <!-- STEP 1: STATUS CONSENT BOX -->
                                    <div style="background:#ffffff; border:1px solid #bae6fd; border-radius:10px; padding:14px; margin-bottom:16px; font-size:13px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                        <div>
                                            <strong>Status Consent Pemberi Kerja:</strong>
                                            <?php if ($selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN'): ?>
                                                <span class="pill-badge verified" style="margin-left:8px; font-weight:700; background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;">
                                                    <i class="fa-solid fa-check-circle"></i> Sudah Disetujui
                                                </span>
                                            <?php elseif ($selectedEmployer['manual_review_status'] === 'CONSENT_PENDING'): ?>
                                                <span class="pill-badge pending" style="margin-left:8px; background:#fef3c7; color:#b45309; border:1px solid #fde68a;">
                                                    <i class="fa-solid fa-clock"></i> Menunggu Persetujuan Pemohon (Consent Terkirim)
                                                </span>
                                            <?php elseif ($selectedEmployer['manual_review_status'] === 'INVALID'): ?>
                                                <span class="pill-badge danger" style="margin-left:8px; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5;">
                                                    <i class="fa-solid fa-triangle-exclamation"></i> Consent INVALID (Data Berubah Setelah Persetujuan)
                                                </span>
                                            <?php else: ?>
                                                <span class="pill-badge process" style="margin-left:8px; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">
                                                    Belum Dikirimkan Consent
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($selectedEmployer['consent_given_at']) && $selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN'): ?>
                                            <div style="font-size:12px; color:#059669; font-weight:600;">
                                                <i class="fa-regular fa-calendar-check"></i> <?php echo date('d M Y, H:i', strtotime($selectedEmployer['consent_given_at'])); ?> WIB
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- STEP 2: FORM PROFIL PEMBERI KERJA INDIVIDU (VERSI ADMIN) -->
                                    <details id="detailsAdminProfileForm" style="background:#ffffff; border:1.5px solid #cbd5e1; border-radius:12px; padding:20px; margin-bottom:16px;" <?php echo ($selectedEmployer['manual_review_status'] !== 'CONSENT_GIVEN') ? 'open' : ''; ?>>
                                        <summary style="font-weight:800; color:#0f172a; cursor:pointer; font-size:14.5px; display:flex; align-items:center; justify-content:space-between; user-select:none;">
                                            <span style="display:flex; align-items:center; gap:8px;">
                                                <i class="fa-solid fa-pen-to-square" style="color:#0284c7;"></i> Form Profil Pemberi Kerja Individu (Versi Admin - Prefilled & Editable)
                                            </span>
                                            <span style="font-size:11.5px; font-weight:600; color:#0284c7; background:#e0f2fe; padding:3px 10px; border-radius:999px;">
                                                Buka / Tutup
                                            </span>
                                        </summary>
                                        <?php render_admin_employer_profile_form($selectedEmployer, 'dinas_revision_3'); ?>
                                    </details>

                                    <!-- STEP 3: PERNYATAAN VERIFIKASI MANUAL PETUGAS DINAS -->
                                    <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:12px; padding:18px;">
                                        <div style="font-weight:700; color:#0f172a; font-size:14px; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                            <i class="fa-solid fa-certificate" style="color:#059669;"></i> Pernyataan Verifikasi Manual Petugas Dinas
                                        </div>

                                        <div style="background:#f8fafc; border-left:4px solid #0284c7; padding:12px 14px; border-radius:6px; font-size:12.5px; color:#334155; line-height:1.6; margin-bottom:14px;">
                                            “Saya sebagai Petugas Dinas yang berwenang menyatakan telah melakukan pemeriksaan dan verifikasi manual terhadap identitas, bukti tempat pemberi kerja, serta data pendukung Pemberi Kerja Individu yang bersangkutan. Saya memastikan hasil pemeriksaan ini dapat dipertanggungjawabkan secara kedinasan dan hukum.”
                                        </div>

                                        <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                            <input type="hidden" name="admin_action" value="manual_dinas_approve_activate">
                                            <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                            <input type="hidden" name="officer_name" value="<?php echo e($user['name']); ?>">
                                            <input type="hidden" name="officer_statement" value="Saya sebagai Petugas Dinas yang berwenang menyatakan telah melakukan pemeriksaan dan verifikasi manual terhadap identitas, bukti tempat pemberi kerja, serta data pendukung Pemberi Kerja Individu yang bersangkutan. Saya memastikan hasil pemeriksaan ini dapat dipertanggungjawabkan secara kedinasan dan hukum.">

                                            <div style="margin-bottom:16px;">
                                                <label style="font-size:13px; font-weight:600; color:#0f172a; display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                                                    <input type="checkbox" id="officerStatementCheck" name="statement_confirmed" value="1" onchange="toggleOfficerApproveButton()" <?php echo ($selectedEmployer['manual_review_status'] !== 'CONSENT_GIVEN') ? 'disabled' : ''; ?> style="margin-top:2px; width:16px; height:16px; accent-color:#059669; cursor:pointer;">
                                                    <span>Saya menyatakan telah melakukan verifikasi manual dan bertanggung jawab atas hasil pemeriksaan ini.</span>
                                                </label>
                                            </div>

                                            <button type="submit" id="btnOfficerApproveActivate" class="primary-btn" style="background:#059669; width:100%; height:44px; font-size:13.5px; font-weight:700; border-radius:10px; border:none; color:#ffffff; display:flex; align-items:center; justify-content:center; gap:8px; opacity:0.5; cursor:not-allowed;" disabled>
                                                <i class="fa-solid fa-check-double"></i> Setujui & Aktifkan Akses
                                            </button>

                                            <div id="officerApproveNotice" style="font-size:12px; color:#64748b; margin-top:10px; text-align:center;">
                                                <?php if ($selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN'): ?>
                                                    <span style="color:#b45309;"><i class="fa-solid fa-circle-info"></i> Centang pernyataan verifikasi manual di atas untuk mengaktifkan tombol Setujui & Aktifkan Akses.</span>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-lock"></i> Tombol <strong>Setujui & Aktifkan Akses</strong> dinonaktifkan sampai User Consent Pemberi Kerja = <strong>Sudah Disetujui</strong> dan Pernyataan Petugas Dinas dicentang.
                                                <?php endif; ?>
                                            </div>
                                        </form>

                                        <script>
                                        function toggleOfficerApproveButton() {
                                            const isConsentGiven = <?php echo ($selectedEmployer['manual_review_status'] === 'CONSENT_GIVEN') ? 'true' : 'false'; ?>;
                                            const check = document.getElementById('officerStatementCheck');
                                            const btn = document.getElementById('btnOfficerApproveActivate');
                                            const notice = document.getElementById('officerApproveNotice');
                                            if (isConsentGiven && check && check.checked) {
                                                btn.disabled = false;
                                                btn.style.opacity = '1';
                                                btn.style.cursor = 'pointer';
                                                if (notice) notice.innerHTML = '<span style="color:#059669; font-weight:600;"><i class="fa-solid fa-circle-check"></i> Seluruh syarat terpenuhi. Anda dapat menyetujui dan mengaktifkan akses pemberi kerja.</span>';
                                            } else {
                                                btn.disabled = true;
                                                btn.style.opacity = '0.5';
                                                btn.style.cursor = 'not-allowed';
                                                if (notice && isConsentGiven) {
                                                    notice.innerHTML = '<span style="color:#b45309;"><i class="fa-solid fa-circle-info"></i> Centang pernyataan verifikasi manual di atas untuk mengaktifkan tombol.</span>';
                                                }
                                            }
                                        }
                                        </script>
                                    </div>
                                </div>
                            <?php endif; ?>                        </div>

                        <!-- RIGHT COLUMN: AKUN & AUDIT LOG -->
                        <div style="display:flex; flex-direction:column; gap:20px;">
                            <!-- CARD: AKUN PEMBERI KERJA -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Akun Pemberi Kerja</div>

                                <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                                    <div style="width:42px; height:42px; border-radius:50%; background:#ef4444; color:#ffffff; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; overflow:hidden;">
                                        <?php if (!empty($selectedEmployer['avatar'])): ?>
                                            <img src="<?php echo e($selectedEmployer['avatar']); ?>" style="width:100%; height:100%; object-fit:cover;">
                                        <?php else: ?>
                                            <?php echo strtoupper(substr($selectedEmployer['owner_name'] ?: $selectedEmployer['name'], 0, 2)); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex:1; min-width:0;">
                                        <div style="font-size:14px; font-weight:800; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-transform:uppercase;"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                    </div>
                                    <span style="background:#dcfce7; color:#15803d; font-size:11px; font-weight:700; padding:3px 10px; border-radius:12px;">Aktif</span>
                                </div>

                                <div style="margin-bottom:14px;">
                                    <div style="font-size:12px; color:#64748b; font-weight:500; margin-bottom:2px;">Email Akun</div>
                                    <div style="font-size:13px; font-weight:600; color:#00a8e8; word-break:break-all;"><?php echo e($selectedEmployer['email'] ?: 'ahmad@email.com'); ?></div>
                                </div>

                                <div>
                                    <div style="font-size:12px; color:#64748b; font-weight:500; margin-bottom:2px;">Status Akun</div>
                                    <div style="font-size:13px; font-weight:600; color:#0f172a;">Aktif</div>
                                </div>
                            </div>

                            <!-- CARD: AKTIVITAS & AUDIT LOG -->
                            <div class="section-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; padding:20px;">
                                <div class="section-card-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">Aktivitas & Audit Log</div>

                                <div class="timeline-list">
                                    <?php if ($isRevisionStatus && empty($auditLogs)): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot" style="background:#f59e0b;"></div>
                                            <div class="timeline-time">24 Sep 2026, 09:20</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Revisi Diminta kepada Pemberi Kerja Individu.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time">24 Sep 2026, 09:18</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;"><?php echo strtoupper(e($selectedEmployer['assigned_to'] ?: 'BUDI SANTOSO')); ?> - Memberikan keputusan Revisi Diminta.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time">22 Sep 2026, 11:50</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Ditugaskan ke <?php echo e($selectedEmployer['assigned_to'] ?: 'Budi Santoso'); ?>.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time">22 Sep 2026, 11:50</div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;"><?php echo strtoupper(e($selectedEmployer['assigned_to'] ?: 'BUDI SANTOSO')); ?> - Mengambil Pengajuan: Dikirim → Dalam Verifikasi</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Data profil dikirim untuk verifikasi.</div>
                                        </div>
                                        <div class="timeline-item">
                                            <div class="timeline-dot"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'] . ' -2 minutes')); ?></div>
                                            <div class="timeline-title" style="font-weight:600; color:#0f172a;">Pemberi kerja mengajukan profil.</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="timeline-item">
                                            <div class="timeline-dot" style="background:#0284c7; box-shadow:0 0 0 2px #bae6fd;"></div>
                                            <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                            <div class="timeline-title" style="font-weight:700; color:#0f172a; font-size:12.5px;">
                                                Pengajuan Profil Awal
                                                <small style="color:#64748b; font-weight:500;">(oleh <?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?>)</small>
                                            </div>
                                            <div class="timeline-desc" style="color:#475569; font-size:12px; margin-top:3px; line-height:1.45;">
                                                Data profil pemberi kerja dikirim untuk verifikasi.
                                            </div>
                                        </div>
                                        <?php if (!empty($auditLogs)): ?>
                                            <?php foreach ($auditLogs as $log): ?>
                                                <?php $formattedLog = format_audit_log_item($log); ?>
                                                <div class="timeline-item">
                                                    <div class="timeline-dot" style="<?php echo $formattedLog['dotStyle']; ?>"></div>
                                                    <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($log['created_at'])); ?></div>
                                                    <div class="timeline-title" style="font-weight:700; color:#0f172a; font-size:12.5px;">
                                                        <?php echo e($formattedLog['title']); ?>
                                                        <?php if (!empty($formattedLog['actor'])): ?>
                                                            <small style="color:#64748b; font-weight:500;">(oleh <?php echo e($formattedLog['actor']); ?>)</small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($formattedLog['details'])): ?>
                                                        <div class="timeline-desc" style="color:#475569; font-size:12px; margin-top:3px; line-height:1.45;"><?php echo e($formattedLog['details']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="timeline-item">
                                                <div class="timeline-dot"></div>
                                                <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                                <div class="timeline-title"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?> - Mengirim Data: Membuat → Dikirim</div>
                                            </div>
                                            <div class="timeline-item">
                                                <div class="timeline-dot"></div>
                                                <div class="timeline-time"><?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                                <div class="timeline-title">Pemberi kerja mendaftar di platform.</div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODAL LIHAT DOKUMEN PERIZINAN USAHA -->
                <div class="modal-backdrop" data-modal="modal-view-doc">
                    <div class="modal-panel" style="width:min(800px, 94vw); max-height:90vh; display:flex; flex-direction:column;">
                        <div class="modal-header">
                            <div>
                                <div class="modal-title">Dokumen Perizinan Usaha</div>
                                <div class="modal-subtitle"><?php echo e($vDocName); ?> &bull; <?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                            </div>
                                <button type="button" data-close-modal="modal-view-doc" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px; background:#f8fafc; text-align:center;">
                                <?php if ($vHasDoc): ?>
                                    <?php $docExt = strtolower(pathinfo($vDocPath, PATHINFO_EXTENSION)); ?>
                                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:16px; text-align:left; display:flex; justify-content:space-between; align-items:center;">
                                        <div style="display:flex; align-items:center; gap:12px;">
                                            <div style="width:40px; height:40px; border-radius:8px; background:#fee2e2; color:#ef4444; display:flex; align-items:center; justify-content:center; font-size:20px;">
                                                <i class="fa-solid fa-file-pdf"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight:700; color:#0f172a; font-size:14px;"><?php echo e($vDocName); ?></div>
                                                <div style="font-size:12px; color:#64748b;">Diunggah oleh <?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?> &bull; <?php echo date('d M Y, H:i', strtotime($selectedEmployer['created_at'])); ?></div>
                                            </div>
                                        </div>
                                        <a href="<?php echo e($vDocPath); ?>" target="_blank" download class="primary-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; height:34px; padding:0 14px; font-size:12.5px;">
                                            <i class="fa-solid fa-download"></i> Unduh File
                                        </a>
                                    </div>
                                    <?php if ($docExt === 'pdf'): ?>
                                        <div style="width:100%; height:500px; border:1px solid #cbd5e1; border-radius:10px; overflow:hidden; background:#ffffff;">
                                            <iframe src="<?php echo e($vDocPath); ?>" style="width:100%; height:100%; border:none;"></iframe>
                                        </div>
                                    <?php else: ?>
                                        <div style="max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                                            <img src="<?php echo e($vDocPath); ?>" alt="Dokumen Pendukung" style="max-width:100%; max-height:500px; border-radius:8px; object-fit:contain;">
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div style="padding:40px; color:#64748b;">Tidak ada dokumen pendukung yang diunggah.</div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="ghost-btn" data-close-modal="modal-view-doc">Tutup</button>
                                <?php if ($vHasDoc): ?>
                                    <a href="<?php echo e($vDocPath); ?>" target="_blank" class="primary-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; height:38px; padding:0 16px; font-size:13px;">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka di Tab Baru
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT FOTO TEMPAT USAHA -->
                    <div class="modal-backdrop" data-modal="modal-view-photos">
                        <div class="modal-panel" style="width:min(800px, 94vw); max-height:90vh; display:flex; flex-direction:column;">
                            <div class="modal-header">
                                <div>
                                    <div class="modal-title">Foto Bukti Tempat Usaha / Lokasi</div>
                                    <div class="modal-subtitle"><?php echo e($vPhotoName); ?> &bull; <?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                                </div>
                                <button type="button" data-close-modal="modal-view-photos" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px; background:#f8fafc; text-align:center;">
                                <?php if ($vHasPhoto): ?>
                                    <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; max-width:680px; margin:0 auto; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
                                        <div style="border-radius:8px; overflow:hidden; background:#0f172a; margin-bottom:12px; max-height:480px; display:flex; align-items:center; justify-content:center;">
                                            <img src="<?php echo e($vPhotoPath); ?>" alt="Foto Bukti Tempat Usaha" style="max-width:100%; max-height:480px; object-fit:contain;">
                                        </div>
                                        <div style="text-align:left; font-size:13px; color:#475569;">
                                            <strong>Alamat:</strong> <?php echo e($selectedEmployer['address'] ?: '-'); ?><?php echo !empty($selectedEmployer['city']) ? ', ' . e($selectedEmployer['city']) : ''; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="padding:40px; color:#64748b;">Tidak ada foto tempat usaha yang diunggah.</div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="ghost-btn" data-close-modal="modal-view-photos">Tutup</button>
                                <?php if ($vHasPhoto): ?>
                                    <a href="<?php echo e($vPhotoPath); ?>" target="_blank" class="primary-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; height:38px; padding:0 16px; font-size:13px;">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Foto Asli
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL LIHAT FOTO LOGO USAHA -->
                    <div class="modal-backdrop" data-modal="modal-view-logo">
                        <div class="modal-panel" style="width:min(640px, 94vw); max-height:90vh; display:flex; flex-direction:column;">
                            <div class="modal-header">
                                <div>
                                    <div class="modal-title">Logo Usaha</div>
                                    <div class="modal-subtitle"><?php echo e(!empty($selectedEmployer['business_logo']) ? basename($selectedEmployer['business_logo']) : 'jobii.png'); ?> &bull; <?php echo e($selectedEmployer['brand_name'] ?: ($selectedEmployer['owner_name'] ?: $selectedEmployer['name'])); ?></div>
                                </div>
                                <button type="button" data-close-modal="modal-view-logo" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                            <div class="modal-body" style="flex:1; overflow-y:auto; padding:20px; background:#f8fafc; text-align:center;">
                                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; max-width:480px; margin:0 auto; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
                                    <div style="border-radius:10px; overflow:hidden; background:#f8fafc; border:1px solid #e2e8f0; margin-bottom:14px; min-height:240px; display:flex; align-items:center; justify-content:center; padding:16px;">
                                        <img src="public/images/jobii.png" alt="Logo Usaha Jobii" style="max-width:100%; max-height:360px; object-fit:contain;">
                                    </div>
                                    <div style="text-align:left; font-size:13px; color:#475569;">
                                        <strong>Nama Brand:</strong> <?php echo e($selectedEmployer['brand_name'] ?: ($selectedEmployer['owner_name'] ?: $selectedEmployer['name'])); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px;">
                                <button type="button" class="ghost-btn" data-close-modal="modal-view-logo">Tutup</button>
                                <a href="public/images/jobii.png" target="_blank" class="primary-btn" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; height:38px; padding:0 16px; font-size:13px;">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Foto Asli
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL KONFIRMASI AMBIL PENGAJUAN -->
                    <div class="modal-backdrop" data-modal="modal-confirm-ambil-pengajuan">
                        <div class="modal-panel" style="width:min(460px, 92vw); border-radius:16px; padding:24px; background:#ffffff;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                <h3 style="font-size:17px; font-weight:800; color:#0f172a; margin:0;">Konfirmasi</h3>
                                <button type="button" data-close-modal="modal-confirm-ambil-pengajuan" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                            <p style="font-size:13.5px; color:#475569; line-height:1.5; margin-bottom:24px;">
                                Apakah Anda yakin ingin mengambil pengajuan verifikasi ini? Pengajuan ini akan langsung ditugaskan kepada Anda sebagai pemeriksa.
                            </p>
                            <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="assign_employer_case">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="self_assign" value="1">
                                <input type="hidden" name="verifier_name" value="<?php echo e($user['name'] ?: 'Pandu Isdiyanto, S.T., M.M'); ?>">
                                <div style="display:flex; justify-content:flex-end; gap:12px;">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-confirm-ambil-pengajuan" style="border:1px solid #cbd5e1; border-radius:999px; padding:8px 20px; font-size:13px; font-weight:700; color:#475569; background:#ffffff; cursor:pointer;">
                                        Batalkan
                                    </button>
                                    <button type="submit" style="background:#0284c7; border:none; border-radius:999px; padding:8px 22px; font-size:13px; font-weight:700; color:#ffffff; cursor:pointer; box-shadow:0 2px 6px rgba(2,132,199,0.3);">
                                        Lanjutkan
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL BATALKAN PENUGASAN -->
                    <div class="modal-backdrop" data-modal="modal-confirm-unassign">
                        <div class="modal-panel" style="width:min(440px, 90vw); border-radius:16px; padding:24px; background:#ffffff;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                <h3 style="font-size:17px; font-weight:800; color:#0f172a; margin:0;">Batalkan Penugasan</h3>
                                <button type="button" data-close-modal="modal-confirm-unassign" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                            <p style="font-size:13.5px; color:#475569; line-height:1.5; margin-bottom:24px;">
                                Apakah Anda yakin ingin membatalkan penugasan pemeriksa pada pengajuan verifikasi ini?
                            </p>
                            <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="unassign_employer_case">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <div style="display:flex; justify-content:flex-end; gap:12px;">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-confirm-unassign" style="border:1px solid #cbd5e1; border-radius:999px; padding:8px 20px; font-size:13px; font-weight:700; color:#475569; background:#ffffff; cursor:pointer;">
                                        Batal
                                    </button>
                                    <button type="submit" style="background:#ef4444; border:none; border-radius:999px; padding:8px 22px; font-size:13px; font-weight:700; color:#ffffff; cursor:pointer;">
                                        Ya, Batalkan Penugasan
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL KEPUTUSAN REVISI -->
                    <div class="modal-backdrop" data-modal="modal-header-revisi">
                        <div class="modal-panel" style="width:min(500px, 90vw); border-radius:16px; padding:24px; background:#ffffff;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                <h3 style="font-size:17px; font-weight:800; color:#0f172a; margin:0;">Minta Revisi Verifikasi</h3>
                                <button type="button" data-close-modal="modal-header-revisi" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                            <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="verify_employer">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="decision" value="revision">
                                <input type="hidden" name="verification_method" class="modal-sync-method" value="ONLINE">
                                <input type="hidden" name="online_consent" class="modal-sync-online-consent" value="0">
                                <input type="hidden" name="location_check_result" class="modal-sync-location-result" value="">
                                <input type="hidden" name="field_inspection_consent" class="modal-sync-field-consent" value="0">
                                <div style="margin-bottom:16px;">
                                    <label style="font-size:13px; font-weight:700; color:#0f172a; display:block; margin-bottom:6px;">Catatan Perbaikan <span style="color:#ef4444;">*</span></label>
                                    <textarea name="verifier_notes" required placeholder="Tuliskan catatan perbaikan atau dokumen yang perlu dilengkapi..." style="width:100%; min-height:90px; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; color:#0f172a; outline:none; font-family:inherit;"></textarea>
                                </div>
                                <div style="display:flex; justify-content:flex-end; gap:12px;">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-header-revisi" style="border:1px solid #cbd5e1; border-radius:999px; padding:8px 20px; font-size:13px; font-weight:700; color:#475569; background:#ffffff; cursor:pointer;">
                                        Batal
                                    </button>
                                    <button type="submit" style="background:#f59e0b; border:none; border-radius:999px; padding:8px 22px; font-size:13px; font-weight:700; color:#ffffff; cursor:pointer;">
                                        Kirim Revisi
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL KEPUTUSAN TOLAK -->
                    <div class="modal-backdrop" data-modal="modal-header-tolak">
                        <div class="modal-panel" style="width:min(500px, 90vw); border-radius:16px; padding:24px; background:#ffffff;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                <h3 style="font-size:17px; font-weight:800; color:#0f172a; margin:0;">Tolak Verifikasi Pemberi Kerja</h3>
                                <button type="button" data-close-modal="modal-header-tolak" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                            <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="verify_employer">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="decision" value="reject">
                                <input type="hidden" name="verification_method" class="modal-sync-method" value="ONLINE">
                                <input type="hidden" name="online_consent" class="modal-sync-online-consent" value="0">
                                <input type="hidden" name="location_check_result" class="modal-sync-location-result" value="">
                                <input type="hidden" name="field_inspection_consent" class="modal-sync-field-consent" value="0">
                                <div style="margin-bottom:16px;">
                                    <label style="font-size:13px; font-weight:700; color:#0f172a; display:block; margin-bottom:6px;">Alasan Penolakan <span style="color:#ef4444;">*</span></label>
                                    <textarea name="verifier_notes" required placeholder="Tuliskan alasan penolakan..." style="width:100%; min-height:90px; padding:10px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px; color:#0f172a; outline:none; font-family:inherit;"></textarea>
                                </div>
                                <div style="display:flex; justify-content:flex-end; gap:12px;">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-header-tolak" style="border:1px solid #cbd5e1; border-radius:999px; padding:8px 20px; font-size:13px; font-weight:700; color:#475569; background:#ffffff; cursor:pointer;">
                                        Batal
                                    </button>
                                    <button type="submit" style="background:#ef4444; border:none; border-radius:999px; padding:8px 22px; font-size:13px; font-weight:700; color:#ffffff; cursor:pointer;">
                                        Tolak Pengajuan
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL KEPUTUSAN SETUJUI -->
                    <div class="modal-backdrop" data-modal="modal-header-setujui">
                        <div class="modal-panel" style="width:min(440px, 90vw); border-radius:16px; padding:24px; background:#ffffff;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                                <h3 style="font-size:17px; font-weight:800; color:#0f172a; margin:0;">Setujui Verifikasi</h3>
                                <button type="button" data-close-modal="modal-header-setujui" style="background:none; border:none; color:#64748b; font-size:18px; cursor:pointer;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                            <p style="font-size:13.5px; color:#475569; line-height:1.5; margin-bottom:24px;">
                                Apakah Anda yakin ingin menyetujui verifikasi profil pemberi kerja ini? Profil akan aktif terverifikasi selama 6 bulan.
                            </p>
                            <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="verify_employer">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="decision" value="approve">
                                <input type="hidden" name="verification_method" class="modal-sync-method" value="ONLINE">
                                <input type="hidden" name="online_consent" class="modal-sync-online-consent" value="0">
                                <input type="hidden" name="location_check_result" class="modal-sync-location-result" value="">
                                <input type="hidden" name="field_inspection_consent" class="modal-sync-field-consent" value="0">
                                <input type="hidden" name="verifier_notes" value="">
                                <div style="display:flex; justify-content:flex-end; gap:12px;">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-header-setujui" style="border:1px solid #cbd5e1; border-radius:999px; padding:8px 20px; font-size:13px; font-weight:700; color:#475569; background:#ffffff; cursor:pointer;">
                                        Batal
                                    </button>
                                    <button type="submit" style="background:#0284c7; border:none; border-radius:999px; padding:8px 22px; font-size:13px; font-weight:700; color:#ffffff; cursor:pointer; box-shadow:0 2px 6px rgba(2,132,199,0.3);">
                                        Setujui & Verifikasi
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL KEPUTUSAN SETUJUI PERUBAHAN PROFIL -->


                    <!-- MODAL ASSIGN PEMERIKSA -->
                    <div class="modal-backdrop" data-modal="modal-assign-pemeriksa">
                        <div class="modal-panel" style="width:min(500px, 90vw);">
                            <div class="modal-header">
                                <div class="modal-title">Assign Pemeriksa Verifikasi</div>
                                <div class="modal-subtitle"><?php echo e($selectedEmployer['owner_name'] ?: $selectedEmployer['name']); ?></div>
                            </div>
                            <form method="post" action="admin.php?view=verifikasi_employer&detail_id=<?php echo $selectedEmployer['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="assign_employer_case">
                                <input type="hidden" name="user_id" value="<?php echo $selectedEmployer['user_id']; ?>">
                                <div class="modal-body">
                                    <div style="margin-bottom:14px; position:relative;">
                                        <label style="font-size:13px; font-weight:700; display:block; margin-bottom:4px;">Pemeriksa:</label>
                                        <input type="hidden" name="verifier_name" id="inputAssignPemeriksaModal" required value="<?php echo e($user['name']); ?>">
                                        <div id="assignPemeriksaModalTrigger" onclick="toggleAssignPemeriksaModalDropdown(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #cbd5e1; border-radius:8px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#0f172a;">
                                            <span id="assignPemeriksaModalLabel" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#0f172a; font-weight:600;"><?php echo e($user['name']); ?> (Saya)</span>
                                            <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                        </div>

                                        <!-- SEARCHABLE DROPDOWN CARD -->
                                        <div id="assignPemeriksaModalDropdownCard" style="display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:12px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); z-index:1005; padding:8px;">
                                            <input type="text" id="assignPemeriksaModalSearchInput" onkeyup="filterAssignPemeriksaModalOptions()" placeholder="Cari Pemeriksa..." style="width:100%; border:1px solid #00a8e8; border-radius:8px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                            <div id="assignPemeriksaModalOptionsContainer" style="max-height:220px; overflow-y:auto;"></div>
                                        </div>
                                    </div>
                                    <div style="margin-bottom:12px;">
                                        <label style="font-size:13px; font-weight:700; display:block; margin-bottom:4px;">Alasan Penugasan (Minimal 10 karakter):</label>
                                        <textarea name="assignment_reason" required minlength="10" placeholder="Contoh: Penugasan verifikasi berkas permohonan baru wilayah Kota Bekasi..." style="width:100%; min-height:80px; padding:8px; border-radius:8px; border:1px solid #cbd5e1; font-size:13px;"></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="ghost-btn" data-close-modal="modal-assign-pemeriksa">Batal</button>
                                    <button type="submit" class="primary-btn">Simpan Penugasan</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <script>
                    const ALL_PEMERIKSA_MASTER = <?php echo json_encode(array_merge([$user['name'] . ' (Saya)'], $pemeriksaMasterList)); ?>;

                    function toggleAssignPemeriksaDropdown(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('assignPemeriksaDropdownCard');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateAssignPemeriksaOptions();
                            setTimeout(() => {
                                const input = document.getElementById('assignPemeriksaSearchInput');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateAssignPemeriksaOptions() {
                        const container = document.getElementById('assignPemeriksaOptionsContainer');
                        if (!container || container.children.length > 0) return;
                        renderAssignPemeriksaList(ALL_PEMERIKSA_MASTER);
                    }

                    function renderAssignPemeriksaList(list) {
                        const container = document.getElementById('assignPemeriksaOptionsContainer');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputAssignPemeriksa') ? document.getElementById('inputAssignPemeriksa').value : '';

                        list.forEach(itemText => {
                            const item = document.createElement('div');
                            const val = itemText.replace(' (Saya)', '');
                            const isSelected = val === currentVal || itemText === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = itemText;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectAssignPemeriksa(val, itemText);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterAssignPemeriksaOptions() {
                        const input = document.getElementById('assignPemeriksaSearchInput');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const filtered = ALL_PEMERIKSA_MASTER.filter(n => n.toLowerCase().includes(query));
                        renderAssignPemeriksaList(filtered);
                    }

                    function selectAssignPemeriksa(val, labelText) {
                        const input = document.getElementById('inputAssignPemeriksa');
                        if (input) input.value = val;
                        const label = document.getElementById('assignPemeriksaLabel');
                        if (label) {
                            label.textContent = labelText;
                            label.style.color = '#0f172a';
                        }
                        const card = document.getElementById('assignPemeriksaDropdownCard');
                        if (card) card.style.display = 'none';

                        updateAssignPemeriksaButtonState();
                    }

                    function updateAssignPemeriksaButtonState() {
                        const input = document.getElementById('inputAssignPemeriksa');
                        const btn = document.getElementById('btnSubmitAssignPemeriksa');
                        if (!btn) return;

                        const hasVerifier = input && input.value && input.value.trim() !== '';

                        if (hasVerifier) {
                            btn.disabled = false;
                            btn.style.background = '#0284c7';
                            btn.style.opacity = '1';
                            btn.style.color = '#ffffff';
                            btn.style.cursor = 'pointer';
                        } else {
                            btn.disabled = true;
                            btn.style.background = '#7dd3fc';
                            btn.style.opacity = '0.6';
                            btn.style.color = '#0369a1';
                            btn.style.cursor = 'not-allowed';
                        }
                    }

                    function toggleAssignPemeriksaModalDropdown(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('assignPemeriksaModalDropdownCard');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateAssignPemeriksaModalOptions();
                            setTimeout(() => {
                                const input = document.getElementById('assignPemeriksaModalSearchInput');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateAssignPemeriksaModalOptions() {
                        const container = document.getElementById('assignPemeriksaModalOptionsContainer');
                        if (!container || container.children.length > 0) return;
                        renderAssignPemeriksaModalList(ALL_PEMERIKSA_MASTER);
                    }

                    function renderAssignPemeriksaModalList(list) {
                        const container = document.getElementById('assignPemeriksaModalOptionsContainer');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputAssignPemeriksaModal') ? document.getElementById('inputAssignPemeriksaModal').value : '';

                        list.forEach(itemText => {
                            const item = document.createElement('div');
                            const val = itemText.replace(' (Saya)', '');
                            const isSelected = val === currentVal || itemText === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = itemText;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectAssignPemeriksaModal(val, itemText);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterAssignPemeriksaModalOptions() {
                        const input = document.getElementById('assignPemeriksaModalSearchInput');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const filtered = ALL_PEMERIKSA_MASTER.filter(n => n.toLowerCase().includes(query));
                        renderAssignPemeriksaModalList(filtered);
                    }

                    function selectAssignPemeriksaModal(val, labelText) {
                        const input = document.getElementById('inputAssignPemeriksaModal');
                        if (input) input.value = val;
                        const label = document.getElementById('assignPemeriksaModalLabel');
                        if (label) {
                            label.textContent = labelText;
                            label.style.color = '#0f172a';
                        }
                        const card = document.getElementById('assignPemeriksaModalDropdownCard');
                        if (card) card.style.display = 'none';
                    }

                    document.addEventListener('click', function(e) {
                        const card = document.getElementById('assignPemeriksaDropdownCard');
                        const trigger = document.getElementById('assignPemeriksaTrigger');
                        if (card && card.style.display !== 'none' && !card.contains(e.target) && (!trigger || !trigger.contains(e.target))) {
                            card.style.display = 'none';
                        }

                        const modalCard = document.getElementById('assignPemeriksaModalDropdownCard');
                        const modalTrigger = document.getElementById('assignPemeriksaModalTrigger');
                        if (modalCard && modalCard.style.display !== 'none' && !modalCard.contains(e.target) && (!modalTrigger || !modalTrigger.contains(e.target))) {
                            modalCard.style.display = 'none';
                        }
                    });

                    document.addEventListener('DOMContentLoaded', function() {
                        updateAssignPemeriksaButtonState();
                        const btnAssignSubmit = document.getElementById('btnSubmitAssignPemeriksa');
                        if (btnAssignSubmit) {
                            btnAssignSubmit.addEventListener('mouseenter', function() {
                                if (!this.disabled) this.style.background = '#0369a1';
                            });
                            btnAssignSubmit.addEventListener('mouseleave', function() {
                                if (!this.disabled) this.style.background = '#0284c7';
                            });
                        }
                    });
                    updateAssignPemeriksaButtonState();

                    // --- METODE VERIFIKASI INTERACTION LOGIC ---
                    let currentVerifMethod = '<?php echo $savedMethod ?? 'ONLINE'; ?>';
                    let currentLocationResult = '<?php echo $savedLocResult ?? 'SESUAI'; ?>';

                    function onMetodeVerifikasiChange(method) {
                        currentVerifMethod = method;
                        const isOnline = (method === 'ONLINE');

                        const optOnline = document.getElementById('optVerifOnlineCard');
                        const optOffline = document.getElementById('optVerifOfflineCard');
                        const panelOnline = document.getElementById('panelMetodeOnline');
                        const panelOffline = document.getElementById('panelMetodeOffline');

                        if (optOnline) {
                            optOnline.style.borderColor = isOnline ? '#0284c7' : '#e2e8f0';
                            optOnline.style.background = isOnline ? '#f0f9ff' : '#ffffff';
                        }
                        if (optOffline) {
                            optOffline.style.borderColor = !isOnline ? '#0284c7' : '#e2e8f0';
                            optOffline.style.background = !isOnline ? '#f0f9ff' : '#ffffff';
                        }
                        if (panelOnline) panelOnline.style.display = isOnline ? 'block' : 'none';
                        if (panelOffline) panelOffline.style.display = !isOnline ? 'block' : 'none';

                        // Tampilkan badge/bintang pada alamat ketika Offline dipilih
                        document.querySelectorAll('.badge-field-inspected').forEach(b => {
                            b.style.display = !isOnline ? 'inline-flex' : 'none';
                        });

                        syncToModals();
                        updateVerificationButtonsState();
                    }

                    function onLocationResultChange(result) {
                        currentLocationResult = result;
                        const labelSesuai = document.getElementById('labelLocSesuai');
                        const labelTidakSesuai = document.getElementById('labelLocTidakSesuai');
                        const notice = document.getElementById('offlineResultNotice');

                        if (labelSesuai) {
                            labelSesuai.style.borderColor = (result === 'SESUAI') ? '#10b981' : '#cbd5e1';
                        }
                        if (labelTidakSesuai) {
                            labelTidakSesuai.style.borderColor = (result === 'TIDAK_SESUAI') ? '#f59e0b' : '#cbd5e1';
                        }
                        if (notice) {
                            notice.style.display = (result === 'TIDAK_SESUAI') ? 'block' : 'none';
                        }

                        syncToModals();
                        // Pada metode Offline, hasil alamat Tidak Sesuai tidak otomatis menonaktifkan tombol Setujui
                        updateVerificationButtonsState();
                    }

                    function onConsentChange() {
                        syncToModals();
                        updateVerificationButtonsState();
                    }                    function isCurrentConsentChecked() {
                        if (currentVerifMethod === 'ONLINE') {
                            const c = document.getElementById('checkConsentOnline');
                            return c ? c.checked : false;
                        } else {
                            const c = document.getElementById('checkConsentField');
                            return c ? c.checked : false;
                        }
                    }

                    function syncToModals() {
                        const isOnline = (currentVerifMethod === 'ONLINE');
                        const onlineConsent = (document.getElementById('checkConsentOnline')?.checked) ? '1' : '0';
                        const fieldConsent = (document.getElementById('checkConsentField')?.checked) ? '1' : '0';

                        document.querySelectorAll('.modal-sync-method').forEach(el => el.value = currentVerifMethod);
                        document.querySelectorAll('.modal-sync-online-consent').forEach(el => el.value = onlineConsent);
                        document.querySelectorAll('.modal-sync-location-result').forEach(el => el.value = isOnline ? '' : currentLocationResult);
                        document.querySelectorAll('.modal-sync-field-consent').forEach(el => el.value = fieldConsent);
                    }

                    function updateVerificationButtonsState() {
                        const isConsentGiven = isCurrentConsentChecked();
                        const notice = document.getElementById('consentStatusNotice');

                        const btnHdrRevisi = document.getElementById('headerBtnRevisi');
                        const btnHdrTolak = document.getElementById('headerBtnTolak');
                        const btnHdrSetujui = document.getElementById('headerBtnSetujui');
                                                                        
                        const allButtons = [btnHdrRevisi, btnHdrTolak, btnHdrSetujui].filter(Boolean);

                        if (isConsentGiven) {
                            allButtons.forEach(btn => {
                                btn.disabled = false;
                                btn.style.opacity = '1';
                                btn.style.cursor = 'pointer';
                            });
                            if (notice) {
                                notice.innerHTML = '<span style="color:#059669; font-weight:700;"><i class="fa-solid fa-circle-check"></i> Consent telah dicentang. Anda dapat mengambil keputusan verifikasi sekarang melalui tombol di header bar atas.</span>';
                            }
                        } else {
                            allButtons.forEach(btn => {
                                btn.disabled = true;
                                btn.style.opacity = '0.5';
                                btn.style.cursor = 'not-allowed';
                            });
                            if (notice) {
                                notice.innerHTML = '<i class="fa-solid fa-lock" style="color:#94a3b8;"></i> <span>Tombol keputusan (<strong>Setujui</strong> / <strong>Revisi</strong> / <strong>Tolak</strong>) akan aktif setelah consent verifikasi di atas dicentang.</span>';
                            }
                        }
                    }

                    function handleHeaderDecisionClick(decision) {
                        if (!isCurrentConsentChecked()) {
                            highlightConsentRequired();
                            return false;
                        }

                        syncToModals();

                        if (decision === 'approve') {
                            openModalDirect('modal-header-setujui');
                        } else if (decision === 'revision') {
                            openModalDirect('modal-header-revisi');
                        } else if (decision === 'reject') {
                            openModalDirect('modal-header-tolak');
                        }
                        return true;
                    }

                    function highlightConsentRequired() {
                        alert('Consent Verifikasi wajib dicentang pada section Metode Verifikasi sebelum keputusan dapat diproses.');
                        const sec = document.getElementById('sectionMetodeVerifikasi');
                        if (sec) {
                            sec.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        const labelId = (currentVerifMethod === 'ONLINE') ? 'labelConsentOnline' : 'labelConsentField';
                        const label = document.getElementById(labelId);
                        if (label) {
                            label.style.borderColor = '#ef4444';
                            label.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.2)';
                            setTimeout(() => {
                                label.style.borderColor = (currentVerifMethod === 'ONLINE') ? '#cbd5e1' : '#fcd34d';
                                label.style.boxShadow = 'none';
                            }, 2500);
                        }
                        const checkId = (currentVerifMethod === 'ONLINE') ? 'checkConsentOnline' : 'checkConsentField';
                        const check = document.getElementById(checkId);
                        if (check) check.focus();
                    }

                    function openModalDirect(modalId) {
                        const modal = document.querySelector(`[data-modal="${modalId}"]`);
                        if (modal) {
                            modal.style.display = 'flex';
                            modal.classList.add('open');
                            modal.dispatchEvent(new CustomEvent('modal:open'));
                        }
                    }

                    // Initialize button states and syncing on page ready
                    document.addEventListener('DOMContentLoaded', () => {
                        syncToModals();
                        updateVerificationButtonsState();
                    });
                    // Also run immediately in case DOM is already parsed
                    syncToModals();
                    updateVerificationButtonsState();
                    </script>

                <?php else: ?>
                    <!-- VERIFIKASI PEMBERI KERJA TABLE VIEW -->
                    <?php
                    $filterParamsEmp = ($startDate ? '&start_date=' . urlencode($startDate) : '')
                        . ($endDate ? '&end_date=' . urlencode($endDate) : '')
                        . ($cityFilter ? '&city_filter=' . urlencode($cityFilter) : '')
                        . ($verifierFilter ? '&verifier_filter=' . urlencode($verifierFilter) : '')
                        . ($officerFilter ? '&officer_filter=' . urlencode($officerFilter) : '')
                        . ($unassignedFilter ? '&unassigned=1' : '');
                    ?>
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0; color:#0f172a;">Verifikasi Pemberi Kerja</h1>

                        <!-- STATUS TAB LIST -->
                        <div style="border-bottom:1px solid #e2e8f0; margin-bottom:16px;">
                            <div class="status-tab-list" style="gap:24px;">
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=all&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=process&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>" style="display:inline-flex; align-items:center; gap:8px;">Menunggu Verifikasi<?php if ((int)($verificationStatusCounts['process'] ?? 0) > 0): ?> <span style="display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 8px; border-radius:999px; border:1px solid #fecdd3; background:#fff1f2; color:#e11d48; font-size:12px; font-weight:700;"><?php echo (int)($verificationStatusCounts['process'] ?? 0); ?></span><?php endif; ?></a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=revision&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'revision' ? 'active' : ''; ?>">Revisi</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=approved&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'approved' ? 'active' : ''; ?>">Terverifikasi</a>
                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=rejected&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsEmp; ?>" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                            </div>
                        </div>

                        <?php if (!empty($totalOverdueCount) && (int)$totalOverdueCount > 0 && ($tab === 'process' || $tab === 'all')): ?>
                            <div style="background:#fff1f2; border:1px solid #fecdd3; border-left:4px solid #e11d48; border-radius:12px; padding:12px 18px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:12px; box-shadow:0 1px 3px rgba(225,29,72,0.06);">
                                <div style="display:flex; align-items:center; gap:10px; color:#9f1239; font-size:13px;">
                                    <i class="fa-solid fa-triangle-exclamation" style="font-size:16px; color:#e11d48;"></i>
                                    <span>
                                        <strong>Pemberitahuan SLA Verifikasi:</strong> Terdapat <strong><?php echo (int)$totalOverdueCount; ?></strong> pengajuan
                                        <?php if (!empty($overdueReverifCount) && (int)$overdueReverifCount > 0): ?>
                                            (termasuk <strong><?php echo (int)$overdueReverifCount; ?></strong> Verifikasi Ulang Profil)
                                        <?php endif; ?>
                                        yang telah melewati batas waktu SLA 3 hari kalender. Baris bertanda <span style="background:#fee2e2; color:#dc2626; border-radius:4px; padding:1px 6px; font-weight:700; font-size:11px;">Segera Verifikasi</span> memerlukan pemeriksaan segera.
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- FILTER MAIN FORM EMP -->
                        <form method="get" action="admin.php" id="filterMainFormEmp" style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; width:100%; position:relative;">
                            <input type="hidden" name="view" value="verifikasi_employer">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                            <input type="hidden" name="entity" id="inputEntityEmp" value="<?php echo e($entity); ?>">
                            <?php if ($sort): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                            <input type="hidden" name="start_date" id="inputStartDateEmp" value="<?php echo e($startDate); ?>">
                            <input type="hidden" name="end_date" id="inputEndDateEmp" value="<?php echo e($endDate); ?>">
                            <input type="hidden" name="city_filter" id="inputCityFilterEmp" value="<?php echo e($cityFilter); ?>">
                            <input type="hidden" name="verifier_filter" id="inputVerifierFilterEmp" value="<?php echo e($verifierFilter); ?>">
                            <input type="hidden" name="officer_filter" id="inputOfficerFilterEmp" value="<?php echo e($officerFilter); ?>">
                            <input type="hidden" name="unassigned" id="inputUnassignedEmp" value="<?php echo $unassignedFilter ? '1' : '0'; ?>">

                            <!-- LEFT CONTAINER: SEARCH BOX & SEGMENTED PILL FILTER -->
                            <div style="display:flex; align-items:center; gap:12px;">
                                <!-- SEARCH BOX -->
                                <div class="filter-search-box" style="width:280px; border-radius:999px; height:38px;">
                                    <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8; font-size:13px;"></i>
                                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari Pemberi Kerja...">
                                </div>

                                <!-- SEGMENTED PILL FILTER: Semua | Perusahaan | Individual -->
                                <div style="display:inline-flex; background:#f1f5f9; border-radius:999px; padding:3px; gap:2px;">
                                    <a href="admin.php?view=verifikasi_employer&entity=Semua&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsEmp; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; <?php echo ($entity === 'Semua' || !$entity) ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Semua<?php if ((int)($verificationEntityCounts['Semua'] ?? 0) > 0): ?> <span style="display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 7px; border-radius:999px; background:#e2e8f0; color:#334155; font-size:11px; font-weight:700;"><?php echo (int)($verificationEntityCounts['Semua'] ?? 0); ?></span><?php endif; ?></a>
                                    <a href="admin.php?view=verifikasi_employer&entity=Perusahaan&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsEmp; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; <?php echo $entity === 'Perusahaan' ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Perusahaan<?php if ((int)($verificationEntityCounts['Perusahaan'] ?? 0) > 0): ?> <span style="display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 7px; border-radius:999px; background:#e2e8f0; color:#334155; font-size:11px; font-weight:700;"><?php echo (int)($verificationEntityCounts['Perusahaan'] ?? 0); ?></span><?php endif; ?></a>
                                    <a href="admin.php?view=verifikasi_employer&entity=Individu&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsEmp; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; <?php echo ($entity === 'Individu' || $entity === 'Individual') ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Individual<?php if ((int)($verificationEntityCounts['Individu'] ?? 0) > 0): ?> <span style="display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 7px; border-radius:999px; background:#e2e8f0; color:#334155; font-size:11px; font-weight:700;"><?php echo (int)($verificationEntityCounts['Individu'] ?? 0); ?></span><?php endif; ?></a>
                                </div>
                            </div>

                            <!-- FILTER BUTTON & POPOVER -->
                            <div style="position:relative;">
                                <button type="button" class="filter-btn" id="filterToggleBtnEmp" onclick="toggleFilterPopoverEmp(event)" style="display:inline-flex; align-items:center; gap:6px; background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:7px 16px; font-size:13px; font-weight:600; color:#334155; cursor:pointer;">
                                    <i class="fa-solid fa-sliders" style="font-size:12px;"></i> Filter
                                    <?php if ($startDate || $endDate || $cityFilter || $verifierFilter || $officerFilter || $unassignedFilter): ?>
                                        <span style="background:#0284c7; color:#fff; font-size:10px; border-radius:999px; padding:1px 6px; margin-left:2px;">●</span>
                                    <?php endif; ?>
                                </button>

                                <!-- FILTER POPOVER CARD EMP -->
                                <div id="filterPopoverEmp" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:280px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05); z-index:1000; overflow:visible;">

                                    <!-- ACCORDION 1: TANGGAL PENGAJUAN -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionEmp('date')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Tanggal Pengajuan</span>
                                            <i class="fa-solid fa-chevron-down" id="dateChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo ($startDate || $endDate) ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="dateAccordionBodyEmp" style="display:<?php echo ($startDate || $endDate) ? 'block' : 'none'; ?>; padding:0 18px 14px 18px;">
                                            <div id="dateRangeTriggerEmp" onclick="toggleDatePickerPopoverEmp(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <div style="display:flex; align-items:center; gap:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <i class="fa-regular fa-calendar" style="color:#94a3b8; font-size:14px;"></i>
                                                    <span id="dateRangeLabelEmp"><?php echo ($startDate && $endDate) ? e($startDate . ' - ' . $endDate) : ($startDate ? e($startDate) : 'Pilih rentang tanggal'); ?></span>
                                                </div>
                                                <i class="fa-regular fa-circle-xmark" id="clearDateBtnEmp" style="color:#cbd5e1; font-size:14px; cursor:pointer; <?php echo ($startDate || $endDate) ? 'display:inline;' : 'display:none;'; ?>" onclick="event.stopPropagation(); clearDateRangeEmp();"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 2: WILAYAH / KOTA -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionEmp('city')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Wilayah / Kota</span>
                                            <i class="fa-solid fa-chevron-down" id="cityChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo $cityFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="cityAccordionBodyEmp" style="display:<?php echo $cityFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="citySelectTriggerEmp" onclick="toggleCityDropdownEmp(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="citySelectLabelEmp" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $cityFilter ? e($cityFilter) : 'Pilih kota...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- CITY SEARCHABLE DROPDOWN EMP -->
                                            <div id="cityDropdownListCardEmp" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="citySearchInputEmp" onkeyup="filterCityOptionsEmp()" placeholder="Cari kota..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="cityOptionsContainerEmp" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 3: VERIFIKATOR -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionEmp('verifier')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Verifikator</span>
                                            <i class="fa-solid fa-chevron-down" id="verifierChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo $verifierFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="verifierAccordionBodyEmp" style="display:<?php echo $verifierFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="verifierSelectTriggerEmp" onclick="toggleVerifierDropdownEmp(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="verifierSelectLabelEmp" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $verifierFilter ? e($verifierFilter) : 'Pilih verifikator...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- VERIFIER SEARCHABLE DROPDOWN EMP -->
                                            <div id="verifierDropdownListCardEmp" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="verifierSearchInputEmp" onkeyup="filterVerifierOptionsEmp()" placeholder="Cari verifikator..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="verifierOptionsContainerEmp" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 4: PETUGAS PEMERIKSA -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionEmp('officer')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Petugas Pemeriksa</span>
                                            <i class="fa-solid fa-chevron-down" id="officerChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo $officerFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="officerAccordionBodyEmp" style="display:<?php echo $officerFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="officerSelectTriggerEmp" onclick="toggleOfficerDropdownEmp(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="officerSelectLabelEmp" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $officerFilter ? e($officerFilter) : 'Pilih pemeriksa...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- OFFICER SEARCHABLE DROPDOWN EMP -->
                                            <div id="officerDropdownListCardEmp" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="officerSearchInputEmp" onkeyup="filterOfficerOptionsEmp()" placeholder="Cari pemeriksa..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="officerOptionsContainerEmp" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 5: STATUS PENUGASAN -->
                                    <div>
                                        <div onclick="toggleAccordionEmp('unassigned')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#334155;">Status Penugasan</span>
                                            <i class="fa-solid fa-chevron-down" id="unassignedChevronEmp" style="font-size:11px; color:#94a3b8; transition:transform 0.2s; <?php echo $unassignedFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="unassignedAccordionBodyEmp" style="display:<?php echo $unassignedFilter ? 'block' : 'none'; ?>; padding:0 18px 18px 18px;">
                                            <label onclick="toggleUnassignedOptionEmp()" style="display:flex; align-items:center; gap:10px; cursor:pointer; user-select:none; font-size:13px; color:#1e293b; font-weight:500;">
                                                <div id="unassignedRadioCircleEmp" style="width:18px; height:18px; border-radius:50%; border:2px solid <?php echo $unassignedFilter ? '#00a8e8' : '#cbd5e1'; ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                    <div style="width:8px; height:8px; border-radius:50%; background:#00a8e8; display:<?php echo $unassignedFilter ? 'block' : 'none'; ?>;"></div>
                                                </div>
                                                <span>Tampilkan yang belum ada pemeriksa</span>
                                            </label>
                                        </div>
                                    </div>

                                </div>

                                <!-- DUAL MONTH DATE RANGE PICKER POPOVER EMP -->
                                <div id="datePickerPopoverEmp" onclick="event.stopPropagation();" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:540px; max-width:90vw; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 15px 35px -5px rgba(0,0,0,0.15); z-index:1010; padding:18px; box-sizing:border-box;">
                                    <!-- Header row with month/year navigation -->
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                                        <button type="button" onclick="event.stopPropagation(); prevMonthClusterEmp()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-left"></i></button>

                                        <div style="display:flex; gap:24px; align-items:center;">
                                            <div style="display:flex; gap:6px;">
                                                <select id="m1SelectEmp" onchange="renderCalendarEmp()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y1SelectEmp" onchange="renderCalendarEmp()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                            <div style="display:flex; gap:6px;">
                                                <select id="m2SelectEmp" onchange="renderCalendarEmp()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y2SelectEmp" onchange="renderCalendarEmp()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                        </div>

                                        <button type="button" onclick="event.stopPropagation(); nextMonthClusterEmp()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-right"></i></button>
                                    </div>

                                    <!-- Dual Month Grids -->
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:16px;">
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m1DaysGridEmp" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m2DaysGridEmp" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                    </div>

                                    <!-- Footer Buttons -->
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <button type="button" onclick="applyDatePickerSelectionEmp()" style="width:100%; background:#00a8e8; border:none; border-radius:10px; padding:10px; color:#ffffff; font-size:13.5px; font-weight:700; cursor:pointer;">Simpan</button>
                                        <button type="button" onclick="resetDatePickerSelectionEmp()" style="width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; color:#0f172a; font-size:13.5px; font-weight:700; cursor:pointer;">Reset</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="console-table-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow-x:auto;">
                        <table class="console-table" style="width:100%; border-collapse:collapse; min-width:1200px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left;">
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:200px;">
                                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'name_asc' ? 'name_desc' : 'name_asc'; ?><?php echo $filterParamsEmp; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Nama Brand Usaha <i class="fa-solid fa-arrows-up-down" style="font-size:11px; color:#94a3b8;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; white-space:nowrap;">Jenis Entitas</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:160px;">Lokasi</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; white-space:nowrap;">Telepon</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; white-space:nowrap;">Status</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; white-space:nowrap;">Deadline Verifikasi</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:150px;">Pemeriksa</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; white-space:nowrap;">
                                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'date_desc' ? 'date_asc' : 'date_desc'; ?><?php echo $filterParamsEmp; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Tanggal Daftar <i class="fa-solid fa-arrow-down" style="font-size:11px; color:#64748b;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; text-align:right; white-space:nowrap;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$showingEmployers): ?>
                                    <tr><td colspan="9" style="text-align:center; padding:60px 20px; color:#64748b; font-size:13.5px;">Tidak ada data pemberi kerja.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($showingEmployers as $vEmp):
                                        $vBrand = !empty($vEmp['brand_name']) ? $vEmp['brand_name'] : ($vEmp['owner_name'] ?: $vEmp['name']);
                                        $vOwner = !empty($vEmp['brand_name']) ? ($vEmp['owner_name'] ?: $vEmp['name']) : '';
                                        $isRowOnlineReverif = ($vEmp['extension_status'] === 'REQUESTED' 
                                            || ($vEmp['extension_requested'] ?? 0) == 1 
                                            || ($vEmp['verification_status'] ?? '') === 'REVERIFICATION_PENDING'
                                            || (!empty($vEmp['last_activated_at']) && ($vEmp['verification_status'] ?? '') === 'PENDING'));

                                        $rowActiveUntilTime = !empty($vEmp['active_until']) ? strtotime($vEmp['active_until']) : null;
                                        $isRowOfflineReverif = !$isRowOnlineReverif && !empty($vEmp['last_activated_at']) && (
                                            in_array($vEmp['verification_status'] ?? '', ['TRANSITION_LIMITED', 'FULL_DISABLED'], true)
                                            || ($rowActiveUntilTime !== null && $rowActiveUntilTime <= (time() + 7 * 86400))
                                        );
                                        $vDeadline = get_verification_deadline_info(
                                            $vEmp['created_at'] ?? null, 
                                            $vEmp['verification_status'] ?? 'PENDING', 
                                            null, 
                                            $vEmp['updated_at'] ?? null, 
                                            $isRowOnlineReverif
                                        );
                                    ?>
                                        <tr style="<?php echo $vDeadline['row_style']; ?>">
                                            <td style="padding:14px 16px;">
                                                <div style="display:flex; align-items:center; gap:12px;">
                                                    <div class="item-avatar-box">
                                                        <?php echo strtoupper(substr($vBrand, 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <strong style="color:#0f172a; font-size:13.5px;"><?php echo e($vBrand); ?></strong>
                                                        <?php if ($vOwner && strcasecmp($vOwner, $vBrand) !== 0): ?>
                                                            <div style="color:#64748b; font-size:11.5px; margin-top:1px; display:flex; align-items:center; gap:4px;">
                                                                <i class="fa-solid fa-user" style="font-size:10px; color:#94a3b8;"></i>
                                                                <span><?php echo e($vOwner); ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <small style="color:#94a3b8; font-size:11px;"><?php echo e($vEmp['email']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="padding:14px 16px; white-space:nowrap;"><span class="pill-badge verified"><?php echo e($vEmp['entity_type'] ?? 'Individu'); ?></span></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px;"><?php echo e($vEmp['city'] ?: '-'); ?></td>
                                            <td style="padding:14px 16px; color:#334155; font-size:13px; white-space:nowrap;"><?php echo e($vEmp['phone'] ?: '0'); ?></td>
                                            <td style="padding:14px 16px; font-size:13px; white-space:nowrap;">
                                                <?php if ($vEmp['verification_status'] === 'APPROVED'): ?>
                                                    <span class="pill-badge verified" style="background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;">● Terverifikasi</span>
                                                <?php elseif ($vEmp['verification_status'] === 'PENDING'): ?>
                                                    <span class="pill-badge pending" style="background:#e0f2fe; color:#0284c7; border:1px solid #bae6fd;">● Dikirim</span>
                                                <?php elseif (in_array($vEmp['verification_status'], ['NEEDS_REVISION', 'REVISION'])): ?>
                                                    <?php $revCount = max(1, min(3, (int)($vEmp['revision_count'] ?? $vEmp['rejection_count'] ?? 1))); ?>
                                                    <span class="pill-badge revision" style="background:#fff7ed; color:#ea580c; border:1px solid #ffedd5;">● Revisi Diminta (ke-<?php echo $revCount; ?>)</span>
                                                <?php elseif ($vEmp['verification_status'] === 'REJECTED'): ?>
                                                    <span class="pill-badge danger" style="background:#fef2f2; color:#dc2626; border:1px solid #fee2e2;">● Ditolak</span>
                                                <?php else: ?>
                                                    <span class="pill-badge revision" style="background:#fff7ed; color:#ea580c; border:1px solid #ffedd5;">● <?php echo e($vEmp['verification_status']); ?></span>
                                                <?php endif; ?>
                                                <?php if ($isRowOnlineReverif): ?>
                                                    <div style="margin-top:5px;">
                                                        <span class="pill-badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; padding:3px 8px;">
                                                            <i class="fa-solid fa-arrows-rotate"></i> Verifikasi Ulang Profil (Online)
                                                        </span>
                                                    </div>
                                                <?php elseif ($isRowOfflineReverif): ?>
                                                    <div style="margin-top:5px;">
                                                        <span class="pill-badge" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; padding:3px 8px;">
                                                            <i class="fa-solid fa-building-user"></i> Verifikasi Ulang Profil (Offline)
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($vEmp['pending_profile_changes']) && (int)$vEmp['pending_profile_changes'] > 0): ?>
                                                    <div style="margin-top:5px;">
                                                        <span class="pill-badge" style="background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:5px; padding:3px 8px;">
                                                            <i class="fa-solid fa-clock-rotate-left"></i> Ada Perubahan Profil
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 16px; font-size:13px; white-space:nowrap;">
                                                <?php echo $vDeadline['badge_html']; ?>
                                                <?php if (!empty($vDeadline['is_overdue'])): ?>
                                                    <div style="font-size:10.5px; color:#dc2626; font-weight:700; margin-top:2px;">
                                                        Lewat batas SLA
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 16px; font-size:13px;">
                                                <?php if (!empty($vEmp['assigned_to'])): ?>
                                                    <span style="font-weight:600; color:#0284c7;"><?php echo e($vEmp['assigned_to']); ?></span>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:14px 16px; color:#64748b; font-size:12.5px; white-space:nowrap;"><?php echo date('d M Y, H:i', strtotime($vEmp['created_at'])); ?></td>
                                            <td style="padding:14px 16px; text-align:right; white-space:nowrap;">
                                                <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $vEmp['profile_id'] ?: $vEmp['user_id']; ?>&profile_id=<?php echo $vEmp['profile_id']; ?><?php echo $isRowOnlineReverif ? '&reverif_type=online' : ($isRowOfflineReverif ? '&reverif_type=offline' : ''); ?>" class="btn-lihat-detail">
                                                    Lihat Detail
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($showingEmployers): ?>
                            <div class="console-table-footer" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; font-size:13px; color:#64748b; border-top:1px solid #e2e8f0;">
                                <div>
                                    Menampilkan <?php echo count($showingEmployers); ?> dari <?php echo $totalData; ?> total data.
                                </div>
                                <div style="display:flex; align-items:center; gap:4px;">
                                    <?php if ($page > 1): ?>
                                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page - 1; ?><?php echo $filterParamsEmp; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">‹</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">‹</span>
                                    <?php endif; ?>

                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <?php if ($p == $page): ?>
                                            <span style="padding:6px 12px; border-radius:6px; background:#0284c7; color:#fff; font-weight:700; border:1px solid #0284c7;"><?php echo $p; ?></span>
                                        <?php else: ?>
                                            <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $p; ?><?php echo $filterParamsEmp; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;"><?php echo $p; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="admin.php?view=verifikasi_employer&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page + 1; ?><?php echo $filterParamsEmp; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">›</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">›</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <script>
                    const VERIFIER_LIST_EMP = [
                        "A. Dimas, Se",
                        "A. Fajar Wahyu",
                        "A. RAHMAT FAJAR",
                        "A.a. Putra Wirasanjaya",
                        "ABD Halim",
                        "ABD. WAHAB, S.Pd",
                        "ABDUL BASYIR",
                        "ABDUL HAMID TUASALAMONY",
                        "ABDUL SALAM LAUMA, S.Sos",
                        "ACHMAD RAJA NASUTION"
                    ];


                    function toggleFilterPopoverEmp(e) {
                        if (e) e.stopPropagation();
                        const popover = document.getElementById('filterPopoverEmp');
                        if (!popover) return;
                        const isVisible = popover.style.display === 'block';
                        popover.style.display = isVisible ? 'none' : 'block';
                        if (!isVisible) {
                            populateCityOptionsEmp();
                            populateVerifierOptionsEmp();
                            populateOfficerOptionsEmp();
                        }
                    }

                    function toggleAccordionEmp(type) {
                        const bodyMap = {
                            'date': 'dateAccordionBodyEmp',
                            'city': 'cityAccordionBodyEmp',
                            'verifier': 'verifierAccordionBodyEmp',
                            'officer': 'officerAccordionBodyEmp',
                            'unassigned': 'unassignedAccordionBodyEmp'
                        };
                        const chevMap = {
                            'date': 'dateChevronEmp',
                            'city': 'cityChevronEmp',
                            'verifier': 'verifierChevronEmp',
                            'officer': 'officerChevronEmp',
                            'unassigned': 'unassignedChevronEmp'
                        };

                        const targetId = bodyMap[type];
                        const targetChevId = chevMap[type];
                        if (!targetId) return;

                        const targetBody = document.getElementById(targetId);
                        const targetChev = document.getElementById(targetChevId);
                        if (!targetBody) return;

                        const isHidden = targetBody.style.display === 'none';
                        targetBody.style.display = isHidden ? 'block' : 'none';
                        if (targetChev) {
                            targetChev.style.transform = isHidden ? 'rotate(180deg)' : 'rotate(0deg)';
                        }

                        if (type === 'city' && isHidden) {
                            populateCityOptionsEmp();
                        } else if (type === 'verifier' && isHidden) {
                            populateVerifierOptionsEmp();
                        } else if (type === 'officer' && isHidden) {
                            populateOfficerOptionsEmp();
                        }
                    }

                    function toggleCityDropdownEmp(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('cityDropdownListCardEmp');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateCityOptionsEmp();
                            setTimeout(() => {
                                const input = document.getElementById('citySearchInputEmp');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateCityOptionsEmp() {
                        const container = document.getElementById('cityOptionsContainerEmp');
                        if (!container || container.children.length > 0) return;
                        renderCityListEmp(typeof CITY_MASTER !== 'undefined' ? CITY_MASTER : []);
                    }

                    function renderCityListEmp(list) {
                        const container = document.getElementById('cityOptionsContainerEmp');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputCityFilterEmp') ? document.getElementById('inputCityFilterEmp').value : '';

                        list.forEach(city => {
                            const item = document.createElement('div');
                            const isSelected = city === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = city;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectCityEmp(city);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterCityOptionsEmp() {
                        const input = document.getElementById('citySearchInputEmp');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const masterList = typeof CITY_MASTER !== 'undefined' ? CITY_MASTER : [];
                        const filtered = masterList.filter(c => c.toLowerCase().includes(query));
                        renderCityListEmp(filtered);
                    }

                    function selectCityEmp(city) {
                        const input = document.getElementById('inputCityFilterEmp');
                        if (input) input.value = city;
                        const label = document.getElementById('citySelectLabelEmp');
                        if (label) label.textContent = city;
                        const card = document.getElementById('cityDropdownListCardEmp');
                        if (card) card.style.display = 'none';
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function toggleVerifierDropdownEmp(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('verifierDropdownListCardEmp');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateVerifierOptionsEmp();
                            setTimeout(() => {
                                const input = document.getElementById('verifierSearchInputEmp');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateVerifierOptionsEmp() {
                        const container = document.getElementById('verifierOptionsContainerEmp');
                        if (!container || container.children.length > 0) return;
                        renderVerifierListEmp(VERIFIER_LIST_EMP);
                    }

                    function renderVerifierListEmp(list) {
                        const container = document.getElementById('verifierOptionsContainerEmp');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputVerifierFilterEmp') ? document.getElementById('inputVerifierFilterEmp').value : '';

                        list.forEach(name => {
                            const item = document.createElement('div');
                            const isSelected = name === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = name;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectVerifierEmp(name);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterVerifierOptionsEmp() {
                        const input = document.getElementById('verifierSearchInputEmp');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const filtered = VERIFIER_LIST_EMP.filter(n => n.toLowerCase().includes(query));
                        renderVerifierListEmp(filtered);
                    }

                    function selectVerifierEmp(name) {
                        const input = document.getElementById('inputVerifierFilterEmp');
                        if (input) input.value = name;
                        const label = document.getElementById('verifierSelectLabelEmp');
                        if (label) label.textContent = name;
                        const card = document.getElementById('verifierDropdownListCardEmp');
                        if (card) card.style.display = 'none';
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function toggleOfficerDropdownEmp(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('officerDropdownListCardEmp');
                        if (!card) return;
                        const isHidden = card.style.display === 'none';
                        card.style.display = isHidden ? 'block' : 'none';
                        if (isHidden) {
                            populateOfficerOptionsEmp();
                            setTimeout(() => {
                                const input = document.getElementById('officerSearchInputEmp');
                                if (input) input.focus();
                            }, 50);
                        }
                    }

                    function populateOfficerOptionsEmp() {
                        const container = document.getElementById('officerOptionsContainerEmp');
                        if (!container || container.children.length > 0) return;
                        renderOfficerListEmp(VERIFIER_LIST_EMP);
                    }

                    function renderOfficerListEmp(list) {
                        const container = document.getElementById('officerOptionsContainerEmp');
                        if (!container) return;
                        container.innerHTML = '';
                        const currentVal = document.getElementById('inputOfficerFilterEmp') ? document.getElementById('inputOfficerFilterEmp').value : '';

                        list.forEach(name => {
                            const item = document.createElement('div');
                            const isSelected = name === currentVal;
                            item.style.cssText = `padding:8px 12px; font-size:13px; color:#1e293b; border-radius:8px; cursor:pointer; background:${isSelected ? '#f0f9ff' : 'transparent'}; font-weight:${isSelected ? '700' : 'normal'}; transition:background 0.15s;`;
                            item.textContent = name;
                            item.onmouseover = () => { if (!isSelected) item.style.background = '#f8fafc'; };
                            item.onmouseout = () => { if (!isSelected) item.style.background = 'transparent'; };
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectOfficerEmp(name);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterOfficerOptionsEmp() {
                        const input = document.getElementById('officerSearchInputEmp');
                        const query = (input ? input.value : '').toLowerCase().trim();
                        const filtered = VERIFIER_LIST_EMP.filter(n => n.toLowerCase().includes(query));
                        renderOfficerListEmp(filtered);
                    }

                    function selectOfficerEmp(name) {
                        const input = document.getElementById('inputOfficerFilterEmp');
                        if (input) input.value = name;
                        const label = document.getElementById('officerSelectLabelEmp');
                        if (label) label.textContent = name;
                        const card = document.getElementById('officerDropdownListCardEmp');
                        if (card) card.style.display = 'none';
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function toggleUnassignedOptionEmp() {
                        const input = document.getElementById('inputUnassignedEmp');
                        const circle = document.getElementById('unassignedRadioCircleEmp');
                        if (!input || !circle) return;

                        const isCurrentlyChecked = input.value === '1';
                        if (isCurrentlyChecked) {
                            input.value = '0';
                            circle.style.borderColor = '#cbd5e1';
                            if (circle.firstElementChild) circle.firstElementChild.style.display = 'none';
                        } else {
                            input.value = '1';
                            circle.style.borderColor = '#00a8e8';
                            if (circle.firstElementChild) circle.firstElementChild.style.display = 'block';
                        }
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    /* DUAL MONTH DATE PICKER FUNCTIONS FOR EMP */
                    let selectedStartDateEmp = "<?php echo e($startDate); ?>";
                    let selectedEndDateEmp = "<?php echo e($endDate); ?>";
                    let tempStartDateEmp = selectedStartDateEmp;
                    let tempEndDateEmp = selectedEndDateEmp;
                    let currentYear1Emp = 2026, currentMonth1Emp = 8;
                    let currentYear2Emp = 2026, currentMonth2Emp = 9;

                    function toggleDatePickerPopoverEmp(e) {
                        if (e) e.stopPropagation();
                        const popover = document.getElementById('datePickerPopoverEmp');
                        if (!popover) return;
                        const isVisible = popover.style.display === 'block';
                        popover.style.display = isVisible ? 'none' : 'block';
                        if (!isVisible) {
                            initYearSelectsEmp();
                            renderCalendarEmp();
                        }
                    }

                    function initYearSelectsEmp() {
                        const y1 = document.getElementById('y1SelectEmp');
                        const y2 = document.getElementById('y2SelectEmp');
                        const m1 = document.getElementById('m1SelectEmp');
                        const m2 = document.getElementById('m2SelectEmp');
                        if (!y1 || y1.children.length > 0) return;

                        const monthList = window.MONTH_NAMES || ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agt", "Sep", "Okt", "Nov", "Des"];
                        const startY = 2020, endY = 2030;
                        for (let y = startY; y <= endY; y++) {
                            y1.add(new Option(y, y, false, y === currentYear1Emp));
                            y2.add(new Option(y, y, false, y === currentYear2Emp));
                        }
                        monthList.forEach((m, idx) => {
                            m1.add(new Option(m, idx, false, idx === currentMonth1Emp));
                            m2.add(new Option(m, idx, false, idx === currentMonth2Emp));
                        });
                    }

                    function prevMonthClusterEmp() {
                        if (currentMonth1Emp === 0) {
                            currentMonth1Emp = 11; currentYear1Emp--;
                        } else {
                            currentMonth1Emp--;
                        }
                        if (currentMonth2Emp === 0) {
                            currentMonth2Emp = 11; currentYear2Emp--;
                        } else {
                            currentMonth2Emp--;
                        }
                        updateSelectValsEmp();
                        renderCalendarEmp();
                    }

                    function nextMonthClusterEmp() {
                        if (currentMonth1Emp === 11) {
                            currentMonth1Emp = 0; currentYear1Emp++;
                        } else {
                            currentMonth1Emp++;
                        }
                        if (currentMonth2Emp === 11) {
                            currentMonth2Emp = 0; currentYear2Emp++;
                        } else {
                            currentMonth2Emp++;
                        }
                        updateSelectValsEmp();
                        renderCalendarEmp();
                    }

                    function updateSelectValsEmp() {
                        const m1 = document.getElementById('m1SelectEmp');
                        const y1 = document.getElementById('y1SelectEmp');
                        const m2 = document.getElementById('m2SelectEmp');
                        const y2 = document.getElementById('y2SelectEmp');
                        if (m1) m1.value = currentMonth1Emp;
                        if (y1) y1.value = currentYear1Emp;
                        if (m2) m2.value = currentMonth2Emp;
                        if (y2) y2.value = currentYear2Emp;
                    }

                    function renderMonthGridEmp(gridId, year, month) {
                        const grid = document.getElementById(gridId);
                        if (!grid) return;
                        grid.innerHTML = '';

                        const firstDay = new Date(year, month, 1).getDay();
                        const offset = (firstDay + 6) % 7;
                        const daysInMonth = new Date(year, month + 1, 0).getDate();
                        const prevMonthDays = new Date(year, month, 0).getDate();

                        for (let i = offset - 1; i >= 0; i--) {
                            const cell = document.createElement('div');
                            cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
                            cell.textContent = prevMonthDays - i;
                            grid.appendChild(cell);
                        }

                        for (let d = 1; d <= daysInMonth; d++) {
                            const cell = document.createElement('div');
                            const mStr = String(month + 1).padStart(2, '0');
                            const dStr = String(d).padStart(2, '0');
                            const dateStr = `${year}-${mStr}-${dStr}`;

                            let bg = 'transparent';
                            let color = '#1e293b';
                            let fontWeight = '500';
                            let borderRadius = '0';

                            if (tempStartDateEmp && tempEndDateEmp) {
                                if (dateStr === tempStartDateEmp) {
                                    bg = '#00a8e8'; color = '#ffffff'; fontWeight = '700'; borderRadius = '8px 0 0 8px';
                                } else if (dateStr === tempEndDateEmp) {
                                    bg = '#00a8e8'; color = '#ffffff'; fontWeight = '700'; borderRadius = '0 8px 8px 0';
                                } else if (dateStr > tempStartDateEmp && dateStr < tempEndDateEmp) {
                                    bg = '#e0f2fe'; color = '#0284c7'; fontWeight = '600';
                                }
                            } else if (tempStartDateEmp && dateStr === tempStartDateEmp) {
                                bg = '#00a8e8'; color = '#ffffff'; fontWeight = '700'; borderRadius = '8px';
                            }

                            cell.style.cssText = `padding:6px 0; background:${bg}; color:${color}; border-radius:${borderRadius}; font-weight:${fontWeight}; cursor:pointer; font-size:12.5px; transition:all 0.15s;`;
                            cell.textContent = d;
                            cell.onclick = (e) => {
                                if (e) e.stopPropagation();
                                selectDateEmp(dateStr);
                            };
                            grid.appendChild(cell);
                        }

                        const totalCells = offset + daysInMonth;
                        const remaining = (7 - (totalCells % 7)) % 7;
                        for (let n = 1; n <= remaining; n++) {
                            const cell = document.createElement('div');
                            cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
                            cell.textContent = n;
                            grid.appendChild(cell);
                        }
                    }

                    function renderCalendarEmp() {
                        const m1Sel = document.getElementById('m1SelectEmp');
                        const y1Sel = document.getElementById('y1SelectEmp');
                        const m2Sel = document.getElementById('m2SelectEmp');
                        const y2Sel = document.getElementById('y2SelectEmp');

                        if (m1Sel && y1Sel && m2Sel && y2Sel) {
                            currentMonth1Emp = parseInt(m1Sel.value);
                            currentYear1Emp = parseInt(y1Sel.value);
                            currentMonth2Emp = parseInt(m2Sel.value);
                            currentYear2Emp = parseInt(y2Sel.value);
                        }

                        renderMonthGridEmp('m1DaysGridEmp', currentYear1Emp, currentMonth1Emp);
                        renderMonthGridEmp('m2DaysGridEmp', currentYear2Emp, currentMonth2Emp);
                    }

                    function selectDateEmp(dateStr) {
                        if (!tempStartDateEmp || (tempStartDateEmp && tempEndDateEmp)) {
                            tempStartDateEmp = dateStr;
                            tempEndDateEmp = '';
                        } else if (tempStartDateEmp && !tempEndDateEmp) {
                            if (dateStr >= tempStartDateEmp) {
                                tempEndDateEmp = dateStr;
                            } else {
                                tempEndDateEmp = tempStartDateEmp;
                                tempStartDateEmp = dateStr;
                            }
                        }
                        renderCalendarEmp();
                    }

                    function applyDatePickerSelectionEmp() {
                        selectedStartDateEmp = tempStartDateEmp;
                        selectedEndDateEmp = tempEndDateEmp;
                        const inputStart = document.getElementById('inputStartDateEmp');
                        const inputEnd = document.getElementById('inputEndDateEmp');
                        if (inputStart) inputStart.value = selectedStartDateEmp;
                        if (inputEnd) inputEnd.value = selectedEndDateEmp;

                        const label = document.getElementById('dateRangeLabelEmp');
                        const clearBtn = document.getElementById('clearDateBtnEmp');

                        if (selectedStartDateEmp && selectedEndDateEmp) {
                            if (label) label.textContent = `${selectedStartDateEmp} - ${selectedEndDateEmp}`;
                            if (clearBtn) clearBtn.style.display = 'inline';
                        } else if (selectedStartDateEmp) {
                            if (label) label.textContent = selectedStartDateEmp;
                            if (clearBtn) clearBtn.style.display = 'inline';
                        } else {
                            if (label) label.textContent = 'Pilih rentang tanggal';
                            if (clearBtn) clearBtn.style.display = 'none';
                        }

                        const picker = document.getElementById('datePickerPopoverEmp');
                        if (picker) picker.style.display = 'none';
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function resetDatePickerSelectionEmp() {
                        tempStartDateEmp = '';
                        tempEndDateEmp = '';
                        selectedStartDateEmp = '';
                        selectedEndDateEmp = '';
                        const inputStart = document.getElementById('inputStartDateEmp');
                        const inputEnd = document.getElementById('inputEndDateEmp');
                        if (inputStart) inputStart.value = '';
                        if (inputEnd) inputEnd.value = '';
                        const label = document.getElementById('dateRangeLabelEmp');
                        const clearBtn = document.getElementById('clearDateBtnEmp');
                        if (label) label.textContent = 'Pilih rentang tanggal';
                        if (clearBtn) clearBtn.style.display = 'none';
                        renderCalendarEmp();
                        const form = document.getElementById('filterMainFormEmp');
                        if (form) form.submit();
                    }

                    function clearDateRangeEmp() {
                        resetDatePickerSelectionEmp();
                    }
                    </script>
                <?php endif; ?>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- 3. VERIFIKASI LOWONGAN (COMPLIANCE MATRIX WORKFLOW) -->
            <!-- ========================================== -->
            <?php if ($view === 'verifikasi_job'): ?>
                <?php if ($selectedJob): ?>
                    <?php
                        $jobFormData = job_to_form_data($selectedJob);
                        $isJobIndividual = (strcasecmp($selectedJob['entity_type'] ?? '', 'Perusahaan') !== 0);
                        $empBrandName = trim((string)($selectedJob['brand_name'] ?? ''));
                        $empOwnerName = $selectedJob['owner_name'] ?: $selectedJob['user_name'];
                        // Untuk Individu, gunakan Nama Brand Usaha sebagai sublabel Lowongan
                        $empDisplayOnJob = ($isJobIndividual && $empBrandName !== '') ? $empBrandName : $empOwnerName;
                        $empName = $empOwnerName;
                        $empEmail = $selectedJob['user_email'];
                        $empPhone = $selectedJob['phone'] ?: '-';
                        $empWhatsapp = $selectedJob['whatsapp'] ?: $empPhone;
                        $empSocialMedia = $selectedJob['social_media'] ?: ($selectedJob['instagram'] ?: '-');
                        $empAddress = $selectedJob['address'] ?: '-';
                        $jobExpiryDate = !empty($jobFormData['expiry_days']) ? date('d M Y', strtotime('+' . (int)$jobFormData['expiry_days'] . ' days', strtotime($selectedJob['created_at']))) : date('d M Y', strtotime('+30 days', strtotime($selectedJob['created_at'])));
                        $skillsList = !empty($jobFormData['skills']) ? $jobFormData['skills'] : [];
                        // KBJI name lookup
                        $kbjiCode = trim((string)($selectedJob['kbji_code'] ?? ''));
                        $kbjiMap = kbji_name_map();
                        $kbjiName = $kbjiMap[$kbjiCode] ?? '';
                        $kbjiDisplay = $kbjiCode !== '' ? ($kbjiCode . ($kbjiName !== '' ? ' — ' . $kbjiName : '')) : '-';
                        // Job status for conditional rendering
                        $detailStatus = $selectedJob['status'] ?? '';
                        $isJobFinalized = in_array($detailStatus, ['Tayang', 'Ditolak', 'CANCELED', 'Perlu Direvisi'], true);
                        // Verifier info from job_posts
                        $verifierName = $selectedJob['assigned_to'] ?? '';
                        // Entity type label
                        $entityTypeRaw = $selectedJob['entity_type'] ?? '';
                        $entityTypeLabel = (strcasecmp($entityTypeRaw, 'Individu') === 0 || strcasecmp($entityTypeRaw, 'Individual') === 0) ? 'Individual' : ($entityTypeRaw ?: 'Individual');
                        // Keputusan verifikasi label
                        $keputusanLabel = '';
                        if ($detailStatus === 'Tayang') $keputusanLabel = 'Disetujui';
                        elseif ($detailStatus === 'Perlu Direvisi') $keputusanLabel = 'Revisi';
                        elseif ($detailStatus === 'Ditolak' || $detailStatus === 'CANCELED') $keputusanLabel = 'Ditolak';
                        // Pemeriksaan pada: use updated_at if finalized, else created_at + 1min
                        $pemeriksaanPada = $isJobFinalized && !empty($selectedJob['updated_at']) ? date('d M Y, H:i', strtotime($selectedJob['updated_at'])) : date('d M Y, H:i', strtotime($selectedJob['created_at'] . ' + 1 minute'));
                        // Compliance checklist
                        $savedChecklist = json_decode($selectedJob['compliance_checklist'] ?? '{}', true) ?: [];
                        $hasChecklistData = !empty($savedChecklist);
                        $checklistCategories = compliance_categories();
                    ?>

                                        <!-- FLASH MESSAGES BANNER -->
                    <?php
                    $flashMsg = get_flash();
                    if ($flashMsg):
                        $bgColor = $flashMsg['type'] === 'success' ? '#dcfce7' : ($flashMsg['type'] === 'warning' ? '#fef3c7' : '#fee2e2');
                        $borderColor = $flashMsg['type'] === 'success' ? '#bbf7d0' : ($flashMsg['type'] === 'warning' ? '#fde68a' : '#fecaca');
                        $textColor = $flashMsg['type'] === 'success' ? '#15803d' : ($flashMsg['type'] === 'warning' ? '#92400e' : '#991b1b');
                        $icon = $flashMsg['type'] === 'success' ? 'fa-circle-check' : ($flashMsg['type'] === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-xmark');
                    ?>
                    <div style="background:<?php echo $bgColor; ?>; border:1px solid <?php echo $borderColor; ?>; color:<?php echo $textColor; ?>; padding:14px 18px; border-radius:10px; margin-bottom:20px; font-size:13.5px; font-weight:600; display:flex; align-items:center; gap:10px; box-shadow:0 2px 4px rgba(0,0,0,0.03);">
                        <i class="fa-solid <?php echo $icon; ?>" style="font-size:18px;"></i>
                        <div><?php echo e($flashMsg['message']); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- DETAIL VIEW FOR VERIFIKASI LOWONGAN -->
                    <div style="margin-bottom:16px;">
                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>" class="btn-lihat-detail" style="display:inline-flex; align-items:center; gap:8px; color:#64748b; text-decoration:none; font-weight:600; font-size:13px;">
                            <i class="fa-solid fa-arrow-left"></i> Kembali
                        </a>
                    </div>

                    <div style="margin-bottom:20px;">
                        <div style="font-size:11px; font-weight:700; color:#64748b; letter-spacing:0.5px; text-transform:uppercase; margin-bottom:6px;">
                            DETAIL PENGAJUAN VERIFIKASI LOWONGAN
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
                            <div>
                                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <h1 style="font-size:24px; font-weight:800; color:#0f172a; margin:0; line-height:1.2;"><?php echo e($selectedJob['title']); ?></h1>
                                    <?php
                                    $detailStatus = $selectedJob['status'] ?? '';
                                    if ($detailStatus === 'Tayang') {
                                        echo '<span class="pill-badge verified" style="background:#dcfce7; color:#15803d; font-size:12px; padding:4px 12px; border-radius:999px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">● Disetujui</span>';
                                    } elseif ($detailStatus === 'Perlu Direvisi') {
                                        echo '<span class="pill-badge revision" style="background:#fef3c7; color:#b45309; font-size:12px; padding:4px 12px; border-radius:999px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">● Revisi</span>';
                                    } elseif ($detailStatus === 'Ditolak' || $detailStatus === 'CANCELED') {
                                        echo '<span class="pill-badge danger" style="background:#fee2e2; color:#b91c1c; font-size:12px; padding:4px 12px; border-radius:999px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">● Ditolak</span>';
                                    } else {
                                        echo '<span class="pill-badge assigned" style="background:#e0f2fe; color:#0369a1; font-size:12px; padding:4px 12px; border-radius:999px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">● Ditugaskan</span>';
                                    }
                                    ?>
                                </div>
                                <div style="font-size:13px; color:#64748b; margin-top:6px; display:flex; align-items:center; gap:8px;">
                                    <strong style="color:#334155;"><?php echo e($empDisplayOnJob); ?></strong>
                                    <span style="color:#cbd5e1;">|</span>
                                    <span><?php echo e($entityTypeLabel); ?></span>
                                    <span style="color:#cbd5e1;">|</span>
                                    <span>Diajukan: <?php echo date('d M Y, H:i', strtotime($selectedJob['created_at'])); ?></span>
                                </div>
                            </div>
                            <?php
                            $needAdditionalDocReview = !empty($selectedJob['additional_doc_required']) && ($selectedJob['additional_doc_status'] ?? '') !== 'APPROVED';
                            ?>
                            <?php if (!$isJobFinalized && !$needAdditionalDocReview): ?>
                            <div>
                                <button type="button" onclick="openDecisionModal()" class="primary-btn" style="height:40px; padding:0 20px; font-size:13px; background:#0284c7; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; box-shadow:0 2px 4px rgba(2,132,199,0.2);">
                                    <i class="fa-solid fa-gavel"></i> Ambil Keputusan
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- SECTION KONDISIONAL: INFORMASI EVALUASI OTOMATIS (LAPISAN 2) -->
                    <?php if (!empty($selectedJob['additional_doc_required'])): ?>
                        <?php
                            $startOfMonth = date('Y-m-01 00:00:00');
                            $endOfMonth   = date('Y-m-t 23:59:59');
                            $cntKbjiStmt = db()->prepare('
                                SELECT COUNT(*) FROM job_posts
                                WHERE user_id = ?
                                  AND kbji_code = ?
                                  AND published_at IS NOT NULL
                                  AND published_at BETWEEN ? AND ?
                                  AND status NOT IN ("Draft", "Menunggu Verifikasi", "Dikirim/Menunggu Verifikasi", "Pending", "Perlu Direvisi", "Perlu Revisi", "Ditolak", "Dibatalkan", "CANCELED")
                                  AND id != ?
                            ');
                            $cntKbjiStmt->execute([(int)$selectedJob['user_id'], (string)$selectedJob['kbji_code'], $startOfMonth, $endOfMonth, $selectedJob['id']]);
                            $pubKbjiMonth = (int)$cntKbjiStmt->fetchColumn();
                            $currentSubmissionOrder = $pubKbjiMonth + 1;

                            $docStatus = $selectedJob['additional_doc_status'] ?? 'SUBMITTED';
                            if ($docStatus === 'APPROVED') {
                                $evalStatusText = 'Disetujui (Lolos Lapisan 3)';
                                $evalStatusColor = '#059669';
                            } elseif ($docStatus === 'REJECTED' || $docStatus === 'REJECTED_QUOTA') {
                                $evalStatusText = 'Ditolak';
                                $evalStatusColor = '#dc2626';
                            } else {
                                $evalStatusText = 'Memerlukan Pemeriksaan Tambahan';
                                $evalStatusColor = '#d97706';
                            }

                            $alasanText = $selectedJob['additional_doc_notes'] ?: ($additionalDocCase['description'] ?? '-');
                            $filePendukung = $selectedJob['additional_doc_file'] ?: ($additionalDocCase['document_file'] ?? '');
                        ?>
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px; padding-bottom:12px; border-bottom:1px solid #f1f5f9;">
                                <div style="width:36px; height:36px; border-radius:8px; background:#eff6ff; color:#0284c7; display:flex; align-items:center; justify-content:center; font-size:16px;">
                                    <i class="fa-solid fa-shield-halved"></i>
                                </div>
                                <div>
                                    <h3 style="font-size:16px; font-weight:800; color:#0f172a; margin:0;">Informasi Evaluasi Otomatis</h3>
                                    <p style="font-size:12px; color:#64748b; margin:2px 0 0 0;">Evaluasi Lapisan 2: Frekuensi Publikasi KBJI yang Sama</p>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:230px 1fr; row-gap:10px; column-gap:12px; font-size:13px; margin-bottom:20px; background:#f8fafc; padding:16px 20px; border-radius:10px; border:1px solid #f1f5f9;">
                                <span style="color:#64748b; font-weight:500;">Publikasi KBJI bulan berjalan</span>
                                <strong style="color:#0f172a;">: <?php echo $pubKbjiMonth; ?></strong>

                                <span style="color:#64748b; font-weight:500;">Pengajuan saat ini</span>
                                <strong style="color:#0f172a;">: Ke-<?php echo $currentSubmissionOrder; ?></strong>

                                <span style="color:#64748b; font-weight:500;">Status</span>
                                <strong style="color:<?php echo $evalStatusColor; ?>;">: <?php echo $evalStatusText; ?></strong>
                            </div>

                            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; margin-bottom:<?php echo ($docStatus !== 'APPROVED' && $selectedJob['status'] === 'Menunggu Verifikasi') ? '20px' : '0'; ?>;">
                                <h4 style="font-size:14px; font-weight:700; color:#0f172a; margin:0 0 14px 0;">Dokumen/Keterangan Tambahan</h4>
                                <div style="display:grid; grid-template-columns:130px 1fr; row-gap:12px; column-gap:12px; font-size:13px;">
                                    <span style="color:#64748b; font-weight:500;">Alasan</span>
                                    <div style="color:#1e293b; line-height:1.5;">: <?php echo nl2br(e($alasanText)); ?></div>

                                    <span style="color:#64748b; font-weight:500;">Dokumen Pendukung Kebutuhan Tenaga Kerja</span>
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            :
                                            <?php
                                                // Resolve file from DB, uploads, or public/images
                                                $resolvedDoc = !empty($filePendukung) ? $filePendukung : '';
                                                $resolvedName = !empty($additionalDocCase['document_filename']) ? $additionalDocCase['document_filename'] : (!empty($selectedJob['additional_doc_filename']) ? $selectedJob['additional_doc_filename'] : '');

                                                if (empty($resolvedDoc) || (!file_exists(__DIR__ . '/' . ltrim($resolvedDoc, '/')) && empty($selectedJob['additional_doc_blob']) && empty($additionalDocCase['document_blob']))) {
                                                    if (file_exists(__DIR__ . '/public/images/Surat_Pernyataan_Usaha_Andi_Pratama_Dummy.pdf')) {
                                                        $resolvedDoc = 'public/images/Surat_Pernyataan_Usaha_Andi_Pratama_Dummy.pdf';
                                                        $resolvedName = 'Surat_Pernyataan_Usaha_Andi_Pratama_Dummy.pdf';
                                                    } elseif (file_exists(__DIR__ . '/public/images/surat-pernyataan-usaha.pdf')) {
                                                        $resolvedDoc = 'public/images/surat-pernyataan-usaha.pdf';
                                                        $resolvedName = 'surat-pernyataan-usaha.pdf';
                                                    }
                                                }

                                                $hasBlobData = !empty($selectedJob['additional_doc_blob']) || !empty($additionalDocCase['document_blob']);
                                                $docLink = $hasBlobData ? ('view_doc.php?job_id=' . (int)$selectedJob['id']) : $resolvedDoc;
                                                $displayDocName = $resolvedName ?: basename($resolvedDoc);
                                            ?>
                                            <?php if (!empty($resolvedDoc)): ?>
                                                <span style="color:#1e293b; font-weight:600;"><?php echo e($displayDocName); ?></span>
                                                <a href="<?php echo e($docLink); ?>" target="_blank" class="btn-secondary-custom" style="padding:4px 10px; font-size:12px; font-weight:600; text-decoration:none; background:#f0f9ff; color:#0284c7; border:1px solid #bae6fd; border-radius:6px; display:inline-flex; align-items:center; gap:4px;">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Lihat File
                                                </a>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-style:italic;">-</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($resolvedDoc)): ?>
                                            <?php
                                                $fExt = strtolower(pathinfo($resolvedDoc, PATHINFO_EXTENSION));
                                                $isImgFile = in_array($fExt, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true);
                                            ?>
                                            <?php if ($isImgFile): ?>
                                                <div style="margin-top:4px; margin-left:10px;">
                                                    <a href="<?php echo e($docLink); ?>" target="_blank">
                                                        <img src="<?php echo e($docLink); ?>" alt="Preview Dokumen Pendukung Kebutuhan Tenaga Kerja" style="max-width:260px; max-height:180px; border-radius:8px; border:1px solid #cbd5e1; object-fit:cover; box-shadow:0 2px 4px rgba(0,0,0,0.08);">
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($docStatus !== 'APPROVED' && $selectedJob['status'] === 'Menunggu Verifikasi'): ?>
                                <form method="post" action="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $selectedJob['id']; ?>" id="adminAdditionalDocForm" onsubmit="return validateAdditionalDocReview(event)" style="border-top:1px solid #f1f5f9; padding-top:20px;">
                                    <input type="hidden" name="action" value="verify_additional_doc">
                                    <input type="hidden" name="admin_action" value="verify_additional_doc">
                                    <input type="hidden" name="job_id" value="<?php echo $selectedJob['id']; ?>">
                                    <input type="hidden" name="decision" id="admin_decision_input" value="">

                                    <div style="margin-bottom:16px;">
                                        <label style="display:block; font-size:13px; font-weight:700; color:#0f172a; margin-bottom:8px;">
                                            Berkas telah ditinjau <span style="color:#ef4444;">*</span>
                                        </label>
                                        <div style="display:flex; align-items:center; gap:24px; font-size:13px; color:#334155;">
                                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                                <input type="radio" name="doc_reviewed" value="Ya" id="doc_reviewed_ya" required>
                                                <span>Ya</span>
                                            </label>
                                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                                <input type="radio" name="doc_reviewed" value="Tidak" id="doc_reviewed_tidak" checked>
                                                <span>Tidak</span>
                                            </label>
                                        </div>
                                        <div style="font-size:11px; color:#64748b; margin-top:4px;">
                                            * Keputusan hanya dapat diberikan setelah opsi "Berkas telah ditinjau" dipilih "Ya".
                                        </div>
                                        <div id="docReviewedError" style="display:none; color:#ef4444; font-size:12px; margin-top:4px;">
                                            Keputusan hanya dapat diberikan setelah opsi "Berkas telah ditinjau" dipilih "Ya".
                                        </div>
                                    </div>

                                    <div style="margin-bottom:16px;">
                                        <label style="display:block; font-size:13px; font-weight:700; color:#0f172a; margin-bottom:8px;">
                                            Kunjungan Lapangan <span style="color:#ef4444;">*</span>
                                        </label>
                                        <div style="display:flex; align-items:center; gap:24px; font-size:13px; color:#334155;">
                                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                                <input type="radio" name="field_visit" value="Ya" required>
                                                <span>Ya</span>
                                            </label>
                                            <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                                <input type="radio" name="field_visit" value="Tidak" checked required>
                                                <span>Tidak</span>
                                            </label>
                                        </div>
                                        <div style="font-size:11px; color:#64748b; margin-top:4px;">
                                            * Kunjungan Lapangan = Tidak, tidak otomatis berarti dokumen ditolak; hanya merupakan informasi apakah kunjungan dilakukan atau tidak.
                                        </div>
                                    </div>

                                    <div style="margin-bottom:20px;">
                                        <label for="admin_notes_eval" style="display:block; font-size:13px; font-weight:700; color:#0f172a; margin-bottom:6px;">
                                            Catatan Admin <span style="color:#ef4444;">*</span>
                                        </label>
                                        <textarea
                                            name="verifier_notes"
                                            id="admin_notes_eval"
                                            rows="3"
                                            placeholder="Tuliskan catatan pemeriksaan..."
                                            required
                                            oninput="const err=document.getElementById('adminNotesEvalError'); if(err && this.value.trim() !== ''){ err.style.display='none'; this.style.borderColor='#cbd5e1'; }"
                                            style="width:100%; box-sizing:border-box; font-family:inherit; font-size:13px; color:#0f172a; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; resize:vertical; min-height:80px; outline:none;"
                                        ></textarea>
                                        <div id="adminNotesEvalError" style="display:none; color:#ef4444; font-size:12px; margin-top:4px; font-weight:500;">Catatan Admin wajib diisi.</div>
                                    </div>

                                    <div style="display:flex; justify-content:flex-end; align-items:center; gap:12px; flex-wrap:wrap;">
                                        <button type="submit" name="decision" value="reject" onclick="document.getElementById('admin_decision_input').value='reject';" class="btn-secondary-custom" style="padding:9px 20px; font-size:13px; font-weight:700; border:1px solid #fecaca; background:#fff; color:#dc2626; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-xmark"></i> Tolak
                                        </button>
                                        <button type="submit" name="decision" value="approve" onclick="document.getElementById('admin_decision_input').value='approve';" class="btn-primary-custom" style="padding:9px 22px; font-size:13px; font-weight:700; border:none; background:#0284c7; color:#fff; border-radius:8px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 1px 3px rgba(2,132,199,0.3);">
                                            <i class="fa-solid fa-check"></i> Setujui & Lanjutkan
                                        </button>
                                    </div>
                                </form>
                                                        <?php elseif ($docStatus === 'REJECTED' || $docStatus === 'REJECTED_QUOTA'): ?>
                                <div style="margin-top:16px; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:14px 18px; font-size:13px; color:#991b1b; display:flex; align-items:flex-start; gap:12px;">
                                    <i class="fa-solid fa-circle-xmark" style="font-size:18px; color:#dc2626; margin-top:2px;"></i>
                                    <div>
                                        <strong style="font-size:14px;">Pemeriksaan Dokumen/Keterangan Tambahan telah Ditolak.</strong>
                                        <p style="margin:4px 0 0 0; color:#7f1d1d;">Pengajuan lowongan ini telah dihentikan oleh Admin dan status lowongan diubah menjadi <strong>Ditolak</strong>.</p>
                                        <?php if (!empty($selectedJob['admin_notes']) || !empty($selectedJob['verifier_notes'])): ?>
                                            <div style="margin-top:8px; padding-top:8px; border-top:1px solid #fca5a5; font-size:12.5px; color:#991b1b;">
                                                <strong>Catatan Admin:</strong> <?php echo nl2br(e($selectedJob['admin_notes'] ?: $selectedJob['verifier_notes'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php elseif ($docStatus === 'APPROVED'): ?>
                                <div style="margin-top:16px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:12px 16px; font-size:13px; color:#065f46; display:flex; align-items:center; gap:10px;">
                                    <i class="fa-solid fa-circle-check" style="font-size:16px; color:#059669;"></i>
                                    <div>
                                        <strong>Pemeriksaan Dokumen/Keterangan Tambahan telah disetujui & Lolos Lapisan 3.</strong><br>
                                        Silakan lanjutkan proses verifikasi lowongan normal melalui tombol <strong>Ambil Keputusan</strong>.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div style="display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:20px;">
                        <!-- LEFT CARD: RINGKASAN PENGAJUAN -->
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                            <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin:0 0 16px 0;">Ringkasan Pengajuan</h3>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                                <div>
                                    <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                        <i class="fa-solid fa-briefcase" style="color:#0284c7; width:14px;"></i> Jenis Lowongan
                                    </div>
                                    <div style="font-size:13px; font-weight:600; color:#1e293b;">Lowongan Kerja</div>
                                </div>
                                <div>
                                    <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                        <i class="fa-solid fa-calendar-days" style="color:#0284c7; width:14px;"></i> Tanggal Pengajuan
                                    </div>
                                    <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo date('d M Y, H:i', strtotime($selectedJob['created_at'])); ?></div>
                                </div>
                                <div>
                                    <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                        <i class="fa-solid fa-location-dot" style="color:#0284c7; width:14px;"></i> Wilayah
                                    </div>
                                    <div style="font-size:13px; font-weight:600; color:#1e293b; line-height:1.4;"><?php echo e($selectedJob['location'] ?: 'Dago, Coblong, Kota Bandung, Jawa Barat'); ?></div>
                                </div>
                                <div>
                                    <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                        <i class="fa-solid fa-shield-halved" style="color:#0284c7; width:14px;"></i> Blacklist
                                    </div>
                                    <div style="font-size:13px; font-weight:600; color:#059669; display:flex; align-items:center; gap:6px;">
                                        <span style="font-size:8px;">●</span> Tidak terdeteksi
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT CARD: AKTIVITAS & AUDIT LOG -->
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                            <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin:0 0 16px 0;">Aktivitas & Audit Log</h3>
                            <div class="timeline-list" style="display:flex; flex-direction:column; gap:14px;">
                                <div style="display:flex; gap:12px;">
                                    <div style="width:8px; height:8px; border-radius:50%; background:#0284c7; margin-top:5px; flex-shrink:0;"></div>
                                    <div>
                                        <div style="font-size:11px; color:#64748b; margin-bottom:2px;"><?php echo date('d M Y, H:i', strtotime($selectedJob['created_at'] . ' + 1 minute')); ?></div>
                                        <div style="font-size:12px; font-weight:700; color:#0f172a;">Ditugaskan ke verifikator.</div>
                                        <div style="font-size:11px; color:#64748b;">Auto-booked to verifier</div>
                                        <div style="font-size:11px; color:#475569; font-weight:600; margin-top:2px;">Aktor: <?php echo e($user['name'] ?: 'Pandu Isdiyanto, S.T., M.M'); ?></div>
                                    </div>
                                </div>
                                <div style="display:flex; gap:12px;">
                                    <div style="width:8px; height:8px; border-radius:50%; background:#cbd5e1; margin-top:5px; flex-shrink:0;"></div>
                                    <div>
                                        <div style="font-size:11px; color:#64748b; margin-bottom:2px;"><?php echo date('d M Y, H:i', strtotime($selectedJob['created_at'])); ?></div>
                                        <div style="font-size:12px; font-weight:700; color:#0f172a;">Lowongan dikirim untuk verifikasi.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- MIDDLE CARD: INFORMASI KEPUTUSAN DAN VERIFIKASI -->
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                        <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin:0 0 20px 0;">Informasi Keputusan dan Verifikasi</h3>

                        <!-- ROW 1: Deadline (full width) -->
                        <div style="margin-bottom:16px;">
                            <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                <i class="fa-solid fa-clock" style="color:#d97706; width:14px;"></i> Deadline
                            </div>
                            <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo date('d M Y, H:i', strtotime('+3 days', strtotime($selectedJob['created_at']))); ?></div>
                        </div>

                        <!-- ROW 2: Pemeriksaan Pada (full width) -->
                        <div style="margin-bottom:16px;">
                            <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                <i class="fa-solid fa-calendar-check" style="color:#0284c7; width:14px;"></i> Pemeriksaan Pada
                            </div>
                            <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo $pemeriksaanPada; ?></div>
                        </div>

                        <!-- ROW 3+: 2-column grid -->
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0 24px;">
                            <div style="margin-bottom:16px;">
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-user" style="color:#0284c7; width:14px;"></i> Nama Petugas yang Ditugaskan
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($verifierName ?: 'Pandu Isdiyanto, S.T., M.M'); ?></div>
                            </div>
                            <div style="margin-bottom:16px;">
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-envelope" style="color:#0284c7; width:14px;"></i> Email Petugas yang Ditugaskan
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#0284c7;"><?php echo e($user['email'] ?? 'admin@paskerid.test'); ?></div>
                            </div>
                            <?php if ($isJobFinalized && $keputusanLabel !== ''): ?>
                            <div style="margin-bottom:16px;">
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-gavel" style="color:#0284c7; width:14px;"></i> Keputusan Verifikasi
                                </div>
                                <div>
                                    <?php
                                    if ($detailStatus === 'Tayang') {
                                        echo '<span style="display:inline-flex; align-items:center; gap:6px; background:#dcfce7; color:#15803d; font-size:12px; font-weight:700; padding:3px 12px; border-radius:999px;">● Disetujui</span>';
                                    } elseif ($detailStatus === 'Perlu Direvisi') {
                                        echo '<span style="display:inline-flex; align-items:center; gap:6px; background:#fef3c7; color:#b45309; font-size:12px; font-weight:700; padding:3px 12px; border-radius:999px;">● Revisi</span>';
                                    } elseif ($detailStatus === 'Ditolak' || $detailStatus === 'CANCELED') {
                                        echo '<span style="display:inline-flex; align-items:center; gap:6px; background:#fee2e2; color:#b91c1c; font-size:12px; font-weight:700; padding:3px 12px; border-radius:999px;">● Ditolak</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div style="margin-bottom:16px;">
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-calendar-day" style="color:#0284c7; width:14px;"></i> Tanggal Keputusan
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;">
                                    <?php echo !empty($selectedJob['updated_at']) ? date('d M Y, H:i', strtotime($selectedJob['updated_at'])) : '-'; ?>
                                </div>
                            </div>
                            <div style="margin-bottom:16px;">
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-id-badge" style="color:#0284c7; width:14px;"></i> Nama Verifikator
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($verifierName ?: ($user['name'] ?? 'Pandu Isdiyanto, S.T., M.M')); ?></div>
                            </div>
                            <div style="margin-bottom:16px;">
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-envelope" style="color:#0284c7; width:14px;"></i> Email Verifikator
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#0284c7;"><?php echo e($user['email'] ?? 'admin@paskerid.test'); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($isJobFinalized && !empty($selectedJob['verifier_notes'])): ?>
                        <div style="margin-top:20px;">
                            <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:6px;">
                                <i class="fa-solid fa-note-sticky" style="color:#0284c7; width:14px;"></i> Catatan Keputusan
                            </div>
                            <div style="font-size:13px; color:#334155; line-height:1.6; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px;"><?php echo nl2br(e($selectedJob['verifier_notes'])); ?></div>
                        </div>
                        <?php endif; ?>

                        <!-- CHECKLIST VERIFIKASI -->
                        <?php if ($hasChecklistData): ?>
                        <div style="margin-top:24px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <div style="font-size:13px; font-weight:700; color:#0f172a;">Checklist Verifikasi</div>
                                <?php
                                $allPatuh = true;
                                foreach ($checklistCategories as $cat) {
                                    if (($savedChecklist[$cat]['status'] ?? 'Patuh') !== 'Patuh') { $allPatuh = false; break; }
                                }
                                ?>
                                <span style="background:<?php echo $allPatuh ? '#dcfce7' : '#fee2e2'; ?>; color:<?php echo $allPatuh ? '#15803d' : '#b91c1c'; ?>; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px;">
                                    <?php echo $allPatuh ? 'Semua Patuh' : 'Ada Pelanggaran'; ?>
                                </span>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:8px;">
                                <?php foreach ($checklistCategories as $cat):
                                    $catData = $savedChecklist[$cat] ?? ['status' => 'Patuh', 'note' => ''];
                                    $isNc = $catData['status'] === 'Tidak Patuh';
                                ?>
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:10px 14px; background:<?php echo $isNc ? '#fff7ed' : '#f8fafc'; ?>; border:1px solid <?php echo $isNc ? '#fed7aa' : '#e2e8f0'; ?>; border-radius:10px; gap:12px;">
                                    <div style="flex:1;">
                                        <div style="font-size:13px; font-weight:<?php echo $isNc ? '700' : '500'; ?>; color:<?php echo $isNc ? '#9a3412' : '#334155'; ?>; margin-bottom:<?php echo ($isNc && !empty($catData['note'])) ? '6px' : '0'; ?>;"><?php echo e($cat); ?></div>
                                        <?php if ($isNc && !empty($catData['note'])): ?>
                                        <div style="font-size:12px; color:#64748b; line-height:1.5;"><?php echo nl2br(e($catData['note'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <span style="background:<?php echo $isNc ? '#fee2e2' : '#dcfce7'; ?>; color:<?php echo $isNc ? '#b91c1c' : '#15803d'; ?>; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; flex-shrink:0; display:inline-flex; align-items:center; gap:4px;">
                                        <?php echo $isNc ? '✕ Tidak Patuh' : '✓ Patuh'; ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div id="checklistPlaceholderBox" style="background:#f8fafc; border:1px dashed #cbd5e1; border-radius:12px; padding:36px 20px; text-align:center;">
                            <div style="width:44px; height:44px; background:#e2e8f0; color:#64748b; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 12px auto; font-size:18px;">
                                <i class="fa-solid fa-list-check"></i>
                            </div>
                            <div style="font-weight:700; font-size:14px; color:#0f172a; margin-bottom:4px;">Checklist verifikasi tidak tersedia</div>
                            <div style="font-size:12px; color:#64748b;">Checklist hanya akan muncul setelah diberikan keputusan oleh verifikator.</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- MODAL POPUP: AMBIL KEPUTUSAN VERIFIKASI (RIGHT SIDE DRAWER) -->
                    <div id="decisionModalOverlay" style="display:none; position:fixed; inset:0; z-index:999999; background:rgba(15, 23, 42, 0.6); backdrop-filter:blur(2px); justify-content:flex-end;">
                        <div class="side-panel-content" style="background:#ffffff; width:500px; max-width:92vw; height:100vh; border-top-left-radius:20px; border-bottom-left-radius:20px; box-shadow:-10px 0 35px rgba(0,0,0,0.15); display:flex; flex-direction:column; overflow:hidden;">
                            <!-- MODAL HEADER -->
                            <div style="padding:22px 26px 16px 26px; border-bottom:1px solid #f1f5f9; position:relative;">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                    <h2 style="font-size:17px; font-weight:800; color:#0f172a; margin:0 0 6px 0;">Ambil Keputusan Verifikasi</h2>
                                    <button type="button" onclick="requestCloseDecisionModal()" style="background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; padding:2px; line-height:1;" title="Tutup">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                                <p style="font-size:12.5px; color:#64748b; margin:0; line-height:1.45; padding-right:20px;">Pilih keputusan untuk lowongan ini. Pastikan Anda telah memeriksa seluruh data lowongan dengan seksama.</p>
                            </div>

                            <!-- MODAL BODY FORM -->
                            <form method="post" action="admin.php?view=verifikasi_job&detail_id=<?php echo $selectedJob['id']; ?>" id="jobVerificationForm" onsubmit="return handleFormSubmit(event)" style="display:flex; flex-direction:column; flex:1; overflow:hidden; margin:0;">
                                <input type="hidden" name="admin_action" value="verify_job">
                                <input type="hidden" name="job_id" value="<?php echo $selectedJob['id']; ?>">
                                <input type="hidden" name="decision" id="inputDecision" value="">
                                <textarea name="verifier_notes" id="finalVerifierNotes" style="display:none;"></textarea>

                                <?php
                                    $savedChecklist = json_decode($selectedJob['compliance_checklist'] ?? '{}', true) ?: [];
                                    $categories = compliance_categories();
                                ?>

                                <div style="padding:20px 26px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:26px;">
                                    <!-- SECTION 1: KETIDAKPATUHAN -->
                                    <div>
                                        <div style="display:flex; gap:12px; align-items:flex-start; margin-bottom:14px;">
                                            <div style="width:34px; height:34px; border-radius:8px; background:#e0f2fe; color:#00a8e8; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;">
                                                <i class="fa-solid fa-bars-staggered"></i>
                                            </div>
                                            <div>
                                                <h3 style="font-size:13.5px; font-weight:800; color:#0f172a; margin:0 0 2px 0;">Ketidakpatuhan</h3>
                                                <p style="font-size:11.5px; color:#64748b; margin:0; line-height:1.35;">Aktifkan salah satu item di bawah ini apabila terdapat ketidakpatuhan pada lowongan ini</p>
                                            </div>
                                        </div>

                                        <!-- 4 ITEM TOGGLE SWITCHES -->
                                        <div style="display:flex; flex-direction:column; gap:10px;">
                                            <?php foreach ($categories as $cat):
                                                $slug = 'cat_' . md5($cat);
                                                $catData = $savedChecklist[$cat] ?? ['status' => 'Patuh', 'note' => ''];
                                                $isNonCompliant = $catData['status'] === 'Tidak Patuh';
                                            ?>
                                                <div id="<?php echo $slug; ?>_card" style="border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; background:#ffffff; transition:all 0.2s ease;">
                                                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                                                        <span id="<?php echo $slug; ?>_label" data-cat-name="<?php echo e($cat); ?>" style="font-size:13px; font-weight:<?php echo $isNonCompliant ? '700' : '500'; ?>; color:#1e293b;">
                                                            <?php echo e($cat); ?><?php if ($isNonCompliant): ?> <span style="color:#ef4444;">*</span><?php endif; ?>
                                                        </span>
                                                        <label class="switch-toggle" style="position:relative; display:inline-block; width:44px; height:24px; margin:0; cursor:pointer; flex-shrink:0;">
                                                            <input type="checkbox" id="<?php echo $slug; ?>_toggle" onchange="handleComplianceToggle('<?php echo $slug; ?>', '<?php echo addslashes($cat); ?>')" <?php echo $isNonCompliant ? 'checked' : ''; ?> style="opacity:0; width:0; height:0;">
                                                            <span class="custom-toggle-slider" style="position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; transition:.25s; border-radius:24px;"></span>
                                                        </label>
                                                        <input type="hidden" name="<?php echo $slug; ?>_status" id="<?php echo $slug; ?>_status" value="<?php echo $isNonCompliant ? 'Tidak Patuh' : 'Patuh'; ?>">
                                                    </div>

                                                    <!-- ITEM CATATAN TEXTAREA (APPEARS WHEN TOGGLE IS ON) -->
                                                    <div id="<?php echo $slug; ?>_note_wrapper" style="display:<?php echo $isNonCompliant ? 'block' : 'none'; ?>; margin-top:10px;">
                                                        <textarea id="<?php echo $slug; ?>_note" name="<?php echo $slug; ?>_note" placeholder="Masukkan catatan..." style="width:100%; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; font-size:13px; color:#1e293b; outline:none; min-height:75px; resize:vertical; box-sizing:border-box; font-family:inherit; transition:border-color 0.2s;" oninput="updateButtonAndNotesState()"><?php echo e($catData['note']); ?></textarea>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- SECTION 2: KEPUTUSAN -->
                                    <div>
                                        <div style="display:flex; gap:12px; align-items:flex-start; margin-bottom:14px;">
                                            <div style="width:34px; height:34px; border-radius:8px; background:#e0f2fe; color:#00a8e8; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;">
                                                <i class="fa-solid fa-gavel"></i>
                                            </div>
                                            <div>
                                                <h3 style="font-size:13.5px; font-weight:800; color:#0f172a; margin:0 0 2px 0;">Keputusan</h3>
                                                <p style="font-size:11.5px; color:#64748b; margin:0; line-height:1.35;">Tentukan keputusan sebelum memverifikasi lowongan ini</p>
                                            </div>
                                        </div>

                                        <!-- KEPUTUSAN SELECTABLE RADIO CARDS -->
                                        <div style="display:flex; flex-direction:column; gap:10px;">
                                            <!-- SETUJUI -->
                                            <div id="cardSetujui" onclick="selectDecision('approve')" style="border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; display:flex; align-items:center; gap:12px; cursor:pointer; transition:all 0.15s ease; background:#ffffff;">
                                                <div class="radio-indicator" id="radioDotSetujui" style="width:18px; height:18px; border-radius:50%; border:1.5px solid #cbd5e1; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                    <div class="inner-dot" style="width:9px; height:9px; border-radius:50%; background:#00a8e8; display:none;"></div>
                                                </div>
                                                <div style="display:flex; align-items:center; gap:8px; font-size:13.5px; font-weight:600; color:#0f172a;">
                                                    <i class="fa-regular fa-circle-check" style="font-size:15px; color:#0f172a;"></i>
                                                    <span>Setujui</span>
                                                </div>
                                            </div>

                                            <!-- TOLAK -->
                                            <div id="cardTolak" onclick="selectDecision('reject')" style="border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; display:flex; align-items:center; gap:12px; cursor:pointer; transition:all 0.15s ease; background:#ffffff;">
                                                <div class="radio-indicator" id="radioDotTolak" style="width:18px; height:18px; border-radius:50%; border:1.5px solid #cbd5e1; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                    <div class="inner-dot" style="width:9px; height:9px; border-radius:50%; background:#00a8e8; display:none;"></div>
                                                </div>
                                                <div style="display:flex; align-items:center; gap:8px; font-size:13.5px; font-weight:600; color:#0f172a;">
                                                    <i class="fa-regular fa-circle-xmark" style="font-size:15px; color:#0f172a;"></i>
                                                    <span>Tolak</span>
                                                </div>
                                            </div>

                                            <!-- REVISI -->
                                            <div id="cardRevisi" onclick="selectDecision('revision')" style="border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; display:flex; align-items:center; gap:12px; cursor:pointer; transition:all 0.15s ease; background:#ffffff;">
                                                <div class="radio-indicator" id="radioDotRevisi" style="width:18px; height:18px; border-radius:50%; border:1.5px solid #cbd5e1; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                    <div class="inner-dot" style="width:9px; height:9px; border-radius:50%; background:#00a8e8; display:none;"></div>
                                                </div>
                                                <div style="display:flex; align-items:center; gap:8px; font-size:13.5px; font-weight:600; color:#0f172a;">
                                                    <i class="fa-solid fa-pen" style="font-size:13.5px; color:#0f172a;"></i>
                                                    <span>Revisi</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- DYNAMIC DECISION FEEDBACK BOXES -->
                                        <!-- 1. APPROVE FEEDBACK -->
                                        <div id="decisionFeedbackApprove" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:14px 16px; margin-top:14px; align-items:center; gap:12px;">
                                            <span style="background:#ffffff; border:1px solid #86efac; color:#16a34a; font-weight:600; font-size:12px; padding:3px 12px; border-radius:999px; white-space:nowrap;">Persetujuan</span>
                                            <span style="color:#166534; font-size:13px; line-height:1.4;">Dengan menyetujui, lowongan akan langsung dipublikasikan dan dapat dilihat oleh pencari kerja.</span>
                                        </div>

                                        <!-- 2. REJECT FEEDBACK -->
                                        <div id="decisionFeedbackReject" style="display:none; margin-top:14px;">
                                            <label style="display:block; font-size:13px; font-weight:700; color:#0f172a; margin-bottom:6px;">Catatan <span style="color:#ef4444;">*</span></label>
                                            <textarea id="noteReject" placeholder="Masukkan alasan penolakan lowongan..." style="width:100%; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; font-size:13px; color:#1e293b; outline:none; min-height:85px; resize:vertical; box-sizing:border-box; font-family:inherit;" oninput="syncNotes()"></textarea>

                                            <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:14px 16px; margin-top:14px; display:flex; align-items:center; gap:12px;">
                                                <span style="background:#ffffff; border:1px solid #fca5a5; color:#dc2626; font-weight:600; font-size:12px; padding:3px 12px; border-radius:999px; white-space:nowrap;">Peringatan</span>
                                                <span style="color:#991b1b; font-size:13px; line-height:1.4;">Tindakan ini akan menolak lowongan secara permanen. Pemberi kerja harus mengajukan ulang lowongan baru.</span>
                                            </div>
                                        </div>

                                        <!-- 3. REVISION FEEDBACK -->
                                        <div id="decisionFeedbackRevision" style="display:none; margin-top:14px;">
                                            <label style="display:block; font-size:13px; font-weight:700; color:#0f172a; margin-bottom:6px;">Catatan <span style="color:#ef4444;">*</span></label>
                                            <textarea id="noteRevision" placeholder="Masukkan catatan revisi untuk pemberi kerja..." style="width:100%; border:1px solid #e2e8f0; border-radius:10px; padding:10px 12px; font-size:13px; color:#1e293b; outline:none; min-height:85px; resize:vertical; box-sizing:border-box; font-family:inherit;" oninput="syncNotes()"></textarea>
                                        </div>
                                    </div>
                                </div>

                                <!-- MODAL FOOTER BUTTONS -->
                                <div style="padding:16px 26px; border-top:1px solid #f1f5f9; display:flex; justify-content:flex-end; align-items:center; gap:12px; background:#ffffff;">
                                    <button type="button" onclick="requestCloseDecisionModal()" style="height:42px; padding:0 22px; font-size:13.5px; background:#ffffff; color:#0f172a; border:1px solid #e2e8f0; border-radius:10px; font-weight:600; cursor:pointer;">
                                        Batalkan
                                    </button>
                                    <button type="submit" id="btnSubmitJobDecision" style="height:42px; padding:0 24px; font-size:13.5px; background:#00a8e8; color:#ffffff; border:none; border-radius:10px; font-weight:700; cursor:not-allowed; opacity:0.6;" disabled>
                                        Konfirmasi
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL POPUP KONFIRMASI: BATALKAN / TUTUP -->
                    <div id="cancelConfirmModalOverlay" style="display:none; position:fixed; inset:0; z-index:1000000; background:rgba(15, 23, 42, 0.6); backdrop-filter:blur(2px); align-items:center; justify-content:center; font-family:'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
                        <div style="background:#ffffff; border-radius:18px; width:330px; max-width:88vw; padding:24px 22px 20px 24px; box-shadow:0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 10px 10px -5px rgba(0, 0, 0, 0.1); position:relative; animation:popupScale 0.18s cubic-bezier(0.16, 1, 0.3, 1); box-sizing:border-box;">
                            <!-- HEADER -->
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <h3 style="font-size:16px; font-weight:800; color:#0f172a; margin:0; line-height:1.2;">Konfirmasi</h3>
                                <button type="button" onclick="closeCancelConfirmModal()" style="background:none; border:none; color:#94a3b8; font-size:16px; cursor:pointer; padding:2px; display:inline-flex; align-items:center; justify-content:center; line-height:1;" title="Tutup">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                            <!-- BODY TEXT -->
                            <p style="font-size:13px; color:#475569; margin:0 0 20px 0; line-height:1.45;">
                                Data yang sudah diisi pada form ini akan hilang. Yakin ingin menutup?
                            </p>
                            <!-- ACTIONS -->
                            <div style="display:flex; justify-content:flex-end; align-items:center; gap:10px;">
                                <button type="button" onclick="closeCancelConfirmModal()" style="padding:7px 16px; font-size:13px; font-weight:600; color:#334155; background:#ffffff; border:1px solid #cbd5e1; border-radius:9px; cursor:pointer; transition:all 0.15s ease;">
                                    Batalkan
                                </button>
                                <button type="button" onclick="confirmCancelAndCloseDrawer()" style="padding:7px 18px; font-size:13px; font-weight:600; color:#ffffff; background:#e11d48; border:none; border-radius:9px; cursor:pointer; transition:all 0.15s ease; box-shadow:0 2px 4px rgba(225, 29, 72, 0.25);">
                                    Lanjutkan
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- TOGGLE SWITCH & SIDE PANEL CUSTOM STYLES -->
                    <style>
                    @keyframes slideInRight {
                        from { transform: translateX(100%); }
                        to { transform: translateX(0); }
                    }
                    @keyframes popupScale {
                        from { opacity: 0; transform: scale(0.95); }
                        to { opacity: 1; transform: scale(1); }
                    }
                    .side-panel-content {
                        animation: slideInRight 0.25s cubic-bezier(0.16, 1, 0.3, 1);
                    }
                    .custom-toggle-slider {
                        background-color: #cbd5e1;
                    }
                    .custom-toggle-slider:before {
                        position: absolute;
                        content: "";
                        height: 18px;
                        width: 18px;
                        left: 3px;
                        bottom: 3px;
                        background-color: white;
                        transition: .25s;
                        border-radius: 50%;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.18);
                    }
                    input:checked + .custom-toggle-slider {
                        background-color: #00a8e8 !important;
                    }
                    input:checked + .custom-toggle-slider:before {
                        transform: translateX(20px) !important;
                    }
                    </style>

                    <!-- MAIN CARD: INFORMASI LOWONGAN -->
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:12px; border-bottom:1px solid #f1f5f9;">
                            <h3 style="font-size:16px; font-weight:800; color:#0f172a; margin:0;">Informasi Lowongan</h3>
                            <a href="dashboard.php?job_preview=<?php echo $selectedJob['id']; ?>" target="_blank" style="font-size:12px; color:#0284c7; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:4px;">
                                Lihat Detail Lowongan <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </div>

                        <!-- JOB & EMPLOYER TITLE HEADER -->
                        <div style="margin-bottom:20px;">
                            <h2 style="font-size:20px; font-weight:800; color:#0f172a; margin:0 0 4px 0;"><?php echo e($selectedJob['title']); ?></h2>
                            <div style="font-size:13px; color:#64748b; font-weight:500;"><?php echo e($empDisplayOnJob); ?></div>
                        </div>

                        <!-- KEY METADATA GRID -->
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; background:#f8fafc; border:1px solid #f1f5f9; border-radius:10px; padding:16px;">
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-user-tie" style="color:#0284c7; width:14px;"></i> Jenis Entitas
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;">Individual</div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-location-dot" style="color:#0284c7; width:14px;"></i> Wilayah
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($selectedJob['location'] ?: 'Kota Bandung, Jawa Barat'); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-calendar-xmark" style="color:#0284c7; width:14px;"></i> Berlaku Hingga
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo $jobExpiryDate; ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                                    <i class="fa-solid fa-circle-info" style="color:#0284c7; width:14px;"></i> Status Lowongan
                                </div>
                                <div>
                                    <?php
                                    if ($detailStatus === 'Tayang') {
                                        echo '<span style="background:#dcfce7; color:#15803d; font-size:12px; padding:3px 10px; border-radius:999px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">● Tayang</span>';
                                    } elseif ($detailStatus === 'Perlu Direvisi') {
                                        echo '<span style="background:#fef3c7; color:#b45309; font-size:12px; padding:3px 10px; border-radius:999px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">● Perlu Direvisi</span>';
                                    } elseif ($detailStatus === 'Ditolak' || $detailStatus === 'CANCELED') {
                                        echo '<span style="background:#fee2e2; color:#b91c1c; font-size:12px; padding:3px 10px; border-radius:999px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">● Ditolak</span>';
                                    } else {
                                        echo '<span style="background:#fef3c7; color:#d97706; font-size:12px; padding:3px 10px; border-radius:999px; font-weight:600; display:inline-flex; align-items:center; gap:4px;">● ' . e($detailStatus ?: 'Menunggu Verifikasi') . '</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- DESKRIPSI -->
                        <div style="margin-bottom:20px;">
                            <div style="font-size:13px; font-weight:700; color:#0f172a; margin-bottom:6px;">Deskripsi</div>
                            <div style="font-size:13px; color:#334155; line-height:1.6;"><?php echo nl2br(e($selectedJob['description'])); ?></div>
                        </div>

                        <!-- PERSYARATAN KHUSUS -->
                        <?php
                            $reqText = trim((string)($jobFormData['special_requirements'] ?? ''));
                        ?>
                        <?php if ($reqText !== ''): ?>
                        <div style="margin-bottom:20px;">
                            <div style="font-size:13px; font-weight:700; color:#0f172a; margin-bottom:6px;">Persyaratan Khusus</div>
                            <div style="font-size:13px; color:#334155; line-height:1.6;"><?php echo nl2br(e($reqText)); ?></div>
                        </div>
                        <?php endif; ?>

                        <!-- SKILLS BADGES -->
                        <div style="margin-bottom:24px;">
                            <div style="font-size:13px; font-weight:700; color:#0f172a; margin-bottom:8px;">Skills</div>
                            <?php if (!empty($skillsList)): ?>
                            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                <?php foreach ($skillsList as $sk): ?>
                                    <span style="background:#f1f5f9; color:#475569; font-size:12px; font-weight:600; padding:4px 12px; border-radius:6px; border:1px solid #e2e8f0;">
                                        [ <?php echo e($sk); ?> ]
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div style="font-size:13px; color:#94a3b8; font-style:italic;">Tidak ada skills yang dicantumkan.</div>
                            <?php endif; ?>
                        </div>

                        <!-- DIVIDER -->
                        <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0;">

                        <!-- DETAIL LOWONGAN SUBSECTION -->
                        <h4 style="font-size:15px; font-weight:800; color:#0f172a; margin:0 0 16px 0;">Detail Lowongan</h4>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; margin-bottom:24px;">
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-briefcase" style="color:#94a3b8; width:14px;"></i> Jabatan (KBJI)
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($kbjiDisplay); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-clock" style="color:#94a3b8; width:14px;"></i> Jenis Pekerjaan
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($selectedJob['job_type'] ?: 'Full time'); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-layer-group" style="color:#94a3b8; width:14px;"></i> Bidang Pekerjaan
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($jobFormData['job_field'] ?: 'Media dan Komunikasi'); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-building" style="color:#94a3b8; width:14px;"></i> Industri / Sektor
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($selectedJob['industry'] ?: 'Jasa Kreatif'); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-graduation-cap" style="color:#94a3b8; width:14px;"></i> Pendidikan Minimal
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($jobFormData['education_required'] ?: ($selectedJob['min_education'] ?: 'Sarjana')); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-user-clock" style="color:#94a3b8; width:14px;"></i> Pengalaman
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e($jobFormData['experience_required'] ?: ($selectedJob['min_experience'] ?: '1–2 tahun')); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-venus-mars" style="color:#94a3b8; width:14px;"></i> Jenis Kelamin
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e(!empty($jobFormData['genders']) ? implode(', ', $jobFormData['genders']) : 'Semua'); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-ring" style="color:#94a3b8; width:14px;"></i> Status Pernikahan
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e(!empty($jobFormData['marital_statuses']) ? implode(', ', $jobFormData['marital_statuses']) : 'Tidak ada preferensi'); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-user-tag" style="color:#94a3b8; width:14px;"></i> Rentang Usia
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo ($jobFormData['age_min'] ?? 20) . '–' . ($jobFormData['age_max'] ?? 35) . ' tahun'; ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-person-walking" style="color:#94a3b8; width:14px;"></i> Kondisi Fisik
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo e(!empty($jobFormData['physical_conditions']) ? implode(', ', $jobFormData['physical_conditions']) : 'Semua'); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-money-bill-wave" style="color:#94a3b8; width:14px;"></i> Rentang Gaji
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo format_salary_range($selectedJob['salary_min'], $selectedJob['salary_max']); ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-users" style="color:#94a3b8; width:14px;"></i> Kuota
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo (int)($selectedJob['quota'] ?: 1); ?> orang</div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-laptop-house" style="color:#94a3b8; width:14px;"></i> Boleh Remote
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo !empty($jobFormData['is_remote']) ? 'Ya' : 'Tidak'; ?></div>
                            </div>
                            <div>
                                <div style="font-size:12px; color:#64748b; display:flex; align-items:center; gap:6px; margin-bottom:2px;">
                                    <i class="fa-solid fa-lock" style="color:#94a3b8; width:14px;"></i> Terbatas
                                </div>
                                <div style="font-size:13px; font-weight:600; color:#1e293b;"><?php echo !empty($jobFormData['is_limited']) ? 'Ya' : 'Tidak'; ?></div>
                            </div>
                        </div>

                        <div style="margin-bottom:20px;">
                            <div style="font-size:12px; color:#64748b; margin-bottom:6px;">Keahlian yang Dibutuhkan</div>
                            <?php if (!empty($skillsList)): ?>
                            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                <?php foreach ($skillsList as $sk): ?>
                                    <span style="background:#e2e8f0; color:#334155; font-size:12px; font-weight:600; padding:4px 12px; border-radius:6px;">
                                        [ <?php echo e($sk); ?> ]
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div style="font-size:13px; color:#94a3b8; font-style:italic;">Tidak ada keahlian yang dicantumkan.</div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-bottom:24px;">
                            <div style="font-size:12px; color:#64748b; margin-bottom:6px;">Kontak Email</div>
                            <div>
                                <span style="background:#e0f2fe; color:#0284c7; padding:6px 14px; border-radius:6px; font-size:13px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fa-regular fa-envelope"></i> <?php echo e($empEmail); ?>
                                </span>
                            </div>
                        </div>

                        <!-- DIVIDER -->
                        <hr style="border:0; border-top:1px solid #e2e8f0; margin:24px 0;">

                        <!-- INFORMASI PEMBERI KERJA SUBSECTION -->
                        <h4 style="font-size:15px; font-weight:800; color:#0f172a; margin:0 0 16px 0;">Informasi Pemberi Kerja</h4>

                        <div style="display:grid; grid-template-columns:max-content 12px 1fr; row-gap:10px; column-gap:6px; font-size:13px; align-items:start; line-height:1.5;">
                            <?php if ($isJobIndividual && $empBrandName !== ''): ?>
                            <div style="color:#64748b; font-weight:500; white-space:nowrap;">Nama Brand Usaha</div>
                            <div style="color:#0f172a; font-weight:600; text-align:center;">:</div>
                            <div style="color:#0f172a; font-weight:600;"><?php echo e($empBrandName); ?></div>

                            <div style="color:#64748b; font-weight:500; white-space:nowrap;">Nama Pemilik (Owner)</div>
                            <div style="color:#0f172a; font-weight:600; text-align:center;">:</div>
                            <div style="color:#0f172a; font-weight:600;"><?php echo e($empOwnerName); ?></div>
                            <?php else: ?>
                            <div style="color:#64748b; font-weight:500; white-space:nowrap;">Nama</div>
                            <div style="color:#0f172a; font-weight:600; text-align:center;">:</div>
                            <div style="color:#0f172a; font-weight:600;"><?php echo e($empOwnerName); ?></div>
                            <?php endif; ?>

                            <div style="color:#64748b; font-weight:500; white-space:nowrap;">Email</div>
                            <div style="color:#0f172a; font-weight:600; text-align:center;">:</div>
                            <div style="color:#0f172a; font-weight:600;"><?php echo e($empEmail); ?></div>

                            <div style="color:#64748b; font-weight:500; white-space:nowrap;">Telepon</div>
                            <div style="color:#0f172a; font-weight:600; text-align:center;">:</div>
                            <div style="color:#0f172a; font-weight:600;"><?php echo e($empPhone); ?></div>

                            <div style="color:#64748b; font-weight:500; white-space:nowrap;">WhatsApp</div>
                            <div style="color:#0f172a; font-weight:600; text-align:center;">:</div>
                            <div style="color:#0f172a; font-weight:600;"><?php echo e($empWhatsapp); ?></div>

                            <div style="color:#64748b; font-weight:500; white-space:nowrap;">Sosial Media</div>
                            <div style="color:#0f172a; font-weight:600; text-align:center;">:</div>
                            <div style="color:#0f172a; font-weight:600;"><?php echo e($empSocialMedia); ?></div>

                            <div style="color:#64748b; font-weight:500; white-space:nowrap;">Alamat</div>
                            <div style="color:#0f172a; font-weight:600; text-align:center;">:</div>
                            <div style="color:#0f172a; font-weight:600;"><?php echo e($empAddress); ?></div>
                        </div>
                    </div>

                    <script>
                    function validateAdditionalDocReview(event) {
    const yaRadio = document.getElementById('doc_reviewed_ya');
    const errBox = document.getElementById('docReviewedError');
    if (!yaRadio || !yaRadio.checked) {
        event.preventDefault();
        if (errBox) errBox.style.display = 'block';
        alert('Keputusan hanya dapat diberikan setelah opsi "Berkas telah ditinjau" dipilih "Ya".');
        return false;
    }
    if (errBox) errBox.style.display = 'none';

    const fieldVisitChecked = document.querySelector('input[name="field_visit"]:checked');
    if (!fieldVisitChecked) {
        event.preventDefault();
        alert('Pilihan Kunjungan Lapangan wajib diisi.');
        return false;
    }

    const submitter = event.submitter;
    if (submitter && submitter.value === 'reject') {
        const notes = document.getElementById('admin_notes_eval');
        if (!notes || notes.value.trim() === '') {
            event.preventDefault();
            alert('Catatan Admin wajib diisi jika menolak pengajuan.');
            if (notes) notes.focus();
            return false;
        }
        if (!confirm('Apakah Anda yakin ingin menolak pengajuan lowongan ini?')) {
            event.preventDefault();
            return false;
        }
    }
    return true;
}

function openDecisionModal() {
                        const modal = document.getElementById('decisionModalOverlay');
                        if (modal) {
                            modal.style.display = 'flex';
                            document.body.style.overflow = 'hidden';
                        }
                        validateDecisionState();
                    }

                    function closeDecisionModal() {
                        const modal = document.getElementById('decisionModalOverlay');
                        if (modal) {
                            modal.style.display = 'none';
                            document.body.style.overflow = '';
                        }
                    }

                    function requestCloseDecisionModal() {
                        const confirmModal = document.getElementById('cancelConfirmModalOverlay');
                        if (confirmModal) {
                            confirmModal.style.display = 'flex';
                        }
                    }

                    function closeCancelConfirmModal() {
                        const confirmModal = document.getElementById('cancelConfirmModalOverlay');
                        if (confirmModal) {
                            confirmModal.style.display = 'none';
                        }
                    }

                    function confirmCancelAndCloseDrawer() {
                        closeCancelConfirmModal();
                        closeDecisionModal();
                        resetDecisionForm();
                    }

                    function resetDecisionForm() {
                        const form = document.getElementById('jobVerificationForm');
                        if (!form) return;

                        const toggles = form.querySelectorAll('input[type="checkbox"]');
                        toggles.forEach(toggle => {
                            toggle.checked = false;
                            const slug = toggle.id.replace('_toggle', '');
                            const statusInput = document.getElementById(slug + '_status');
                            const noteWrapper = document.getElementById(slug + '_note_wrapper');
                            const noteArea = document.getElementById(slug + '_note');
                            const label = document.getElementById(slug + '_label');
                            if (statusInput) statusInput.value = 'Patuh';
                            if (noteWrapper) noteWrapper.style.display = 'none';
                            if (noteArea) noteArea.value = '';
                            if (label) {
                                const catName = label.getAttribute('data-cat-name') || label.textContent.replace('*', '').trim();
                                label.innerHTML = catName;
                                label.style.fontWeight = '500';
                            }
                        });

                        const inputDecision = document.getElementById('inputDecision');
                        if (inputDecision) inputDecision.value = '';

                        const cards = [
                            document.getElementById('cardSetujui'),
                            document.getElementById('cardTolak'),
                            document.getElementById('cardRevisi')
                        ];
                        cards.forEach(card => {
                            if (card) {
                                card.style.borderColor = '#e2e8f0';
                                card.style.display = 'flex';
                            }
                        });

                        const dots = [
                            document.getElementById('radioDotSetujui'),
                            document.getElementById('radioDotTolak'),
                            document.getElementById('radioDotRevisi')
                        ];
                        dots.forEach(dot => {
                            if (dot) {
                                dot.style.borderColor = '#cbd5e1';
                                const inner = dot.querySelector('.inner-dot');
                                if (inner) inner.style.display = 'none';
                            }
                        });

                        const noteReject = document.getElementById('noteReject');
                        if (noteReject) noteReject.value = '';
                        const noteRevision = document.getElementById('noteRevision');
                        if (noteRevision) noteRevision.value = '';
                        const finalNotes = document.getElementById('finalVerifierNotes');
                        if (finalNotes) finalNotes.value = '';

                        const fbApprove = document.getElementById('decisionFeedbackApprove');
                        const fbReject = document.getElementById('decisionFeedbackReject');
                        const fbRevision = document.getElementById('decisionFeedbackRevision');
                        if (fbApprove) fbApprove.style.display = 'none';
                        if (fbReject) fbReject.style.display = 'none';
                        if (fbRevision) fbRevision.style.display = 'none';

                        updateButtonAndNotesState();
                    }

                    function handleComplianceToggle(slug, catNameParam) {
                        const toggle = document.getElementById(slug + '_toggle');
                        const isChecked = toggle ? toggle.checked : false;
                        const statusInput = document.getElementById(slug + '_status');
                        const noteWrapper = document.getElementById(slug + '_note_wrapper');
                        const label = document.getElementById(slug + '_label');
                        const noteTextarea = document.getElementById(slug + '_note');

                        const catName = (label && label.getAttribute('data-cat-name')) ? label.getAttribute('data-cat-name') : catNameParam;

                        if (statusInput) {
                            statusInput.value = isChecked ? 'Tidak Patuh' : 'Patuh';
                        }

                        if (label) {
                            label.innerHTML = catName + (isChecked ? ' <span style="color:#ef4444;">*</span>' : '');
                            label.style.fontWeight = isChecked ? '700' : '500';
                        }

                        if (noteWrapper) {
                            noteWrapper.style.display = isChecked ? 'block' : 'none';
                            if (isChecked && noteTextarea) {
                                setTimeout(() => noteTextarea.focus(), 50);
                            }
                        }

                        validateDecisionState();
                    }

                    function selectDecision(type) {
                        const inputDecision = document.getElementById('inputDecision');
                        if (inputDecision) inputDecision.value = type;

                        const cards = {
                            'approve': document.getElementById('cardSetujui'),
                            'reject': document.getElementById('cardTolak'),
                            'revision': document.getElementById('cardRevisi')
                        };

                        const dots = {
                            'approve': document.getElementById('radioDotSetujui'),
                            'reject': document.getElementById('radioDotTolak'),
                            'revision': document.getElementById('radioDotRevisi')
                        };

                        Object.keys(cards).forEach(key => {
                            const card = cards[key];
                            const dot = dots[key];
                            if (!card) return;
                            const inner = dot ? dot.querySelector('.inner-dot') : null;

                            if (key === type) {
                                card.style.borderColor = '#00a8e8';
                                if (dot) dot.style.borderColor = '#00a8e8';
                                if (inner) inner.style.display = 'block';
                            } else {
                                card.style.borderColor = '#e2e8f0';
                                if (dot) dot.style.borderColor = '#cbd5e1';
                                if (inner) inner.style.display = 'none';
                            }
                        });

                        const fbApprove = document.getElementById('decisionFeedbackApprove');
                        const fbReject = document.getElementById('decisionFeedbackReject');
                        const fbRevision = document.getElementById('decisionFeedbackRevision');

                        if (fbApprove) fbApprove.style.display = (type === 'approve') ? 'flex' : 'none';
                        if (fbReject) fbReject.style.display = (type === 'reject') ? 'block' : 'none';
                        if (fbRevision) fbRevision.style.display = (type === 'revision') ? 'block' : 'none';

                        syncNotes();
                        updateButtonAndNotesState();
                    }

                    function syncNotes() {
                        const inputDecision = document.getElementById('inputDecision');
                        const currentDecision = inputDecision ? inputDecision.value : '';
                        const finalNotes = document.getElementById('finalVerifierNotes');
                        if (!finalNotes) return;

                        if (currentDecision === 'reject') {
                            const noteReject = document.getElementById('noteReject');
                            finalNotes.value = noteReject ? noteReject.value.trim() : '';
                        } else if (currentDecision === 'revision') {
                            const noteRevision = document.getElementById('noteRevision');
                            finalNotes.value = noteRevision ? noteRevision.value.trim() : '';
                        } else {
                            finalNotes.value = '';
                        }

                        updateButtonAndNotesState();
                    }

                    function validateDecisionState() {
                        const form = document.getElementById('jobVerificationForm');
                        if (!form) return;

                        const statusInputs = form.querySelectorAll('input[name$="_status"]');
                        let hasViolation = false;
                        statusInputs.forEach(inp => {
                            if (inp.value === 'Tidak Patuh') {
                                hasViolation = true;
                            }
                        });

                        const cardSetujui = document.getElementById('cardSetujui');
                        const inputDecision = document.getElementById('inputDecision');

                        if (hasViolation) {
                            if (cardSetujui) cardSetujui.style.display = 'none';
                            if (inputDecision && inputDecision.value === 'approve') {
                                selectDecision('revision');
                            }
                        } else {
                            if (cardSetujui) cardSetujui.style.display = 'flex';
                        }

                        updateButtonAndNotesState();
                    }

                    function updateButtonAndNotesState() {
                        const submitBtn = document.getElementById('btnSubmitJobDecision');
                        const inputDecision = document.getElementById('inputDecision');
                        if (!submitBtn || !inputDecision) return;

                        const currentDecision = inputDecision.value;

                        if (currentDecision === 'approve') {
                            submitBtn.textContent = 'Setujui Lowongan';
                            submitBtn.style.background = '#00875a';
                            submitBtn.style.color = '#ffffff';
                            submitBtn.style.opacity = '1';
                            submitBtn.style.cursor = 'pointer';
                            submitBtn.disabled = false;
                        } else if (currentDecision === 'reject') {
                            submitBtn.textContent = 'Tolak Lowongan';
                            submitBtn.style.background = '#e11d48';
                            submitBtn.style.color = '#ffffff';
                            submitBtn.style.opacity = '1';
                            submitBtn.style.cursor = 'pointer';
                            submitBtn.disabled = false;
                        } else if (currentDecision === 'revision') {
                            submitBtn.textContent = 'Kirim Revisi';
                            submitBtn.style.background = '#f59e0b';
                            submitBtn.style.color = '#ffffff';
                            submitBtn.style.opacity = '1';
                            submitBtn.style.cursor = 'pointer';
                            submitBtn.disabled = false;
                        } else {
                            submitBtn.textContent = 'Konfirmasi';
                            submitBtn.style.background = '#00a8e8';
                            submitBtn.style.color = '#ffffff';
                            submitBtn.style.opacity = '0.6';
                            submitBtn.style.cursor = 'not-allowed';
                            submitBtn.disabled = true;
                        }
                    }

                    function handleFormSubmit(e) {
                        const inputDecision = document.getElementById('inputDecision');
                        const decision = inputDecision ? inputDecision.value : '';

                        if (!decision) {
                            e.preventDefault();
                            alert('Silakan pilih salah satu keputusan terlebih dahulu.');
                            return false;
                        }

                        // Check active compliance items for non-empty note
                        const form = document.getElementById('jobVerificationForm');
                        const statusInputs = form.querySelectorAll('input[name$="_status"]');
                        for (let inp of statusInputs) {
                            if (inp.value === 'Tidak Patuh') {
                                const slug = inp.id.replace('_status', '');
                                const noteArea = document.getElementById(slug + '_note');
                                if (!noteArea || !noteArea.value.trim()) {
                                    e.preventDefault();
                                    alert('Catatan pada item ketidakpatuhan yang aktif wajib diisi.');
                                    if (noteArea) noteArea.focus();
                                    return false;
                                }
                            }
                        }

                        // Check decision notes if reject or revision
                        if (decision === 'reject') {
                            const noteReject = document.getElementById('noteReject');
                            if (!noteReject || !noteReject.value.trim()) {
                                e.preventDefault();
                                alert('Catatan alasan penolakan lowongan wajib diisi.');
                                if (noteReject) noteReject.focus();
                                return false;
                            }
                            document.getElementById('finalVerifierNotes').value = noteReject.value.trim();
                        } else if (decision === 'revision') {
                            const noteRevision = document.getElementById('noteRevision');
                            if (!noteRevision || !noteRevision.value.trim()) {
                                e.preventDefault();
                                alert('Catatan revisi untuk pemberi kerja wajib diisi.');
                                if (noteRevision) noteRevision.focus();
                                return false;
                            }
                            document.getElementById('finalVerifierNotes').value = noteRevision.value.trim();
                        }

                        return true;
                    }

                    document.addEventListener('DOMContentLoaded', () => {
                        const modal = document.getElementById('decisionModalOverlay');
                        if (modal) {
                            modal.addEventListener('click', (e) => {
                                if (e.target === modal) requestCloseDecisionModal();
                            });
                        }

                        const confirmModal = document.getElementById('cancelConfirmModalOverlay');
                        if (confirmModal) {
                            confirmModal.addEventListener('click', (e) => {
                                if (e.target === confirmModal) closeCancelConfirmModal();
                            });
                        }
                    });
                    </script>

                <?php else: ?>
                    <!-- VERIFIKASI LOWONGAN TABLE VIEW (MATCHING EXISTING KARIRHUB CONSOLE) -->
                    <?php
                    $filterParamsJob = ($startDate ? '&start_date=' . urlencode($startDate) : '') . ($endDate ? '&end_date=' . urlencode($endDate) : '') . ($cityFilter ? '&city_filter=' . urlencode($cityFilter) : '');
                    ?>
                    <div style="margin-bottom:20px;">
                        <h1 style="font-size:24px; font-weight:800; margin:0 0 16px 0; color:#0f172a;">Verifikasi Lowongan</h1>

                        <!-- STATUS TAB LIST -->
                        <div style="border-bottom:1px solid #e2e8f0; margin-bottom:16px;">
                            <div class="status-tab-list" style="gap:24px;">
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=all&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'all' ? 'active' : ''; ?>">Semua</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=process&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'process' ? 'active' : ''; ?>" style="display:inline-flex; align-items:center; gap:8px;">Menunggu Verifikasi<?php if ((int)($jobStatusCounts['process'] ?? 0) > 0): ?> <span style="display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 8px; border-radius:999px; border:1px solid #fecdd3; background:#fff1f2; color:#e11d48; font-size:12px; font-weight:700;"><?php echo (int)($jobStatusCounts['process'] ?? 0); ?></span><?php endif; ?></a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=revision&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'revision' ? 'active' : ''; ?>">Revisi</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=approved&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'approved' ? 'active' : ''; ?>">Disetujui</a>
                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=rejected&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?><?php echo $filterParamsJob; ?>" class="status-tab-item <?php echo $tab === 'rejected' ? 'active' : ''; ?>">Ditolak</a>
                            </div>
                        </div>

                        <!-- FILTER MAIN FORM JOB -->
                        <form method="get" action="admin.php" id="filterMainFormJob" style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; width:100%; position:relative;">
                            <input type="hidden" name="view" value="verifikasi_job">
                            <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
                            <input type="hidden" name="entity" id="inputEntityJob" value="<?php echo e($entity); ?>">
                            <?php if ($sort): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                            <input type="hidden" name="start_date" id="inputStartDateJob" value="<?php echo e($startDate); ?>">
                            <input type="hidden" name="end_date" id="inputEndDateJob" value="<?php echo e($endDate); ?>">
                            <input type="hidden" name="city_filter" id="inputCityFilterJob" value="<?php echo e($cityFilter); ?>">

                            <!-- LEFT CONTAINER: SEARCH BOX & SEGMENTED PILL FILTER -->
                            <div style="display:flex; align-items:center; gap:12px;">
                                <!-- SEARCH BOX -->
                                <div class="filter-search-box" style="width:280px; border-radius:999px; height:38px;">
                                    <i class="fa-solid fa-magnifying-glass" style="color:#94a3b8; font-size:13px;"></i>
                                    <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Cari lowongan...">
                                </div>

                                <!-- SEGMENTED PILL FILTER: Semua | Perusahaan | Individual -->
                                <div style="display:inline-flex; background:#f1f5f9; border-radius:999px; padding:3px; gap:2px;">
                                    <a href="admin.php?view=verifikasi_job&entity=Semua&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsJob; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; <?php echo ($entity === 'Semua' || !$entity) ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Semua<?php if ((int)($jobEntityCounts['Semua'] ?? 0) > 0): ?> <span style="display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 7px; border-radius:999px; background:#e2e8f0; color:#334155; font-size:11px; font-weight:700;"><?php echo (int)($jobEntityCounts['Semua'] ?? 0); ?></span><?php endif; ?></a>
                                    <a href="admin.php?view=verifikasi_job&entity=Perusahaan&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsJob; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; <?php echo $entity === 'Perusahaan' ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Perusahaan<?php if ((int)($jobEntityCounts['Perusahaan'] ?? 0) > 0): ?> <span style="display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 7px; border-radius:999px; background:#e2e8f0; color:#334155; font-size:11px; font-weight:700;"><?php echo (int)($jobEntityCounts['Perusahaan'] ?? 0); ?></span><?php endif; ?></a>
                                    <a href="admin.php?view=verifikasi_job&entity=Individu&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?><?php echo $filterParamsJob; ?>" style="padding:6px 16px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:7px; <?php echo ($entity === 'Individu' || $entity === 'Individual') ? 'background:#ffffff; color:#0f172a; box-shadow:0 1px 3px rgba(0,0,0,0.1);' : 'color:#64748b;'; ?>">Individual<?php if ((int)($jobEntityCounts['Individu'] ?? 0) > 0): ?> <span style="display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 7px; border-radius:999px; background:#e2e8f0; color:#334155; font-size:11px; font-weight:700;"><?php echo (int)($jobEntityCounts['Individu'] ?? 0); ?></span><?php endif; ?></a>
                                </div>
                            </div>

                            <!-- FILTER BUTTON & POPOVER -->
                            <div style="position:relative;">
                                <button type="button" class="filter-btn" id="filterToggleBtnJob" onclick="toggleFilterPopoverJob(event)" style="display:inline-flex; align-items:center; gap:6px; background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:7px 16px; font-size:13px; font-weight:600; color:#334155; cursor:pointer;">
                                    <i class="fa-solid fa-sliders" style="font-size:12px;"></i> Filter
                                    <?php if ($startDate || $endDate || $cityFilter): ?>
                                        <span style="background:#0284c7; color:#fff; font-size:10px; border-radius:999px; padding:1px 6px; margin-left:2px;">●</span>
                                    <?php endif; ?>
                                </button>

                                <!-- FILTER POPOVER CARD JOB -->
                                <div id="filterPopoverJob" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:280px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.05); z-index:1000; overflow:visible;">

                                    <!-- ACCORDION 1: TANGGAL PENGAJUAN -->
                                    <div style="border-bottom:1px solid #f1f5f9;">
                                        <div onclick="toggleAccordionJob('date')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#0f172a;">Tanggal Pengajuan</span>
                                            <i class="fa-solid fa-chevron-down" id="dateChevronJob" style="font-size:11px; color:#64748b; transition:transform 0.2s; <?php echo ($startDate || $endDate) ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="dateAccordionBodyJob" style="display:<?php echo ($startDate || $endDate) ? 'block' : 'none'; ?>; padding:0 18px 14px 18px;">
                                            <div id="dateRangeTriggerJob" onclick="toggleDatePickerPopoverJob(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <div style="display:flex; align-items:center; gap:8px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                                    <i class="fa-regular fa-calendar" style="color:#94a3b8; font-size:14px;"></i>
                                                    <span id="dateRangeLabelJob"><?php echo ($startDate && $endDate) ? e($startDate . ' - ' . $endDate) : ($startDate ? e($startDate) : 'Pilih rentang tanggal'); ?></span>
                                                </div>
                                                <i class="fa-regular fa-circle-xmark" id="clearDateBtnJob" style="color:#cbd5e1; font-size:14px; cursor:pointer; <?php echo ($startDate || $endDate) ? 'display:inline;' : 'display:none;'; ?>" onclick="event.stopPropagation(); clearDateRangeJob();"></i>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ACCORDION 2: WILAYAH / KOTA -->
                                    <div>
                                        <div onclick="toggleAccordionJob('city')" style="display:flex; justify-content:space-between; align-items:center; padding:14px 18px; cursor:pointer; user-select:none;">
                                            <span style="font-size:13.5px; font-weight:700; color:#0f172a;">Wilayah / Kota</span>
                                            <i class="fa-solid fa-chevron-down" id="cityChevronJob" style="font-size:11px; color:#64748b; transition:transform 0.2s; <?php echo $cityFilter ? 'transform:rotate(180deg);' : ''; ?>"></i>
                                        </div>
                                        <div id="cityAccordionBodyJob" style="display:<?php echo $cityFilter ? 'block' : 'none'; ?>; padding:0 18px 14px 18px; position:relative;">
                                            <div id="citySelectTriggerJob" onclick="toggleCityDropdownJob(event)" style="display:flex; align-items:center; justify-content:space-between; border:1px solid #e2e8f0; border-radius:12px; padding:9px 12px; background:#ffffff; cursor:pointer; font-size:13px; color:#475569;">
                                                <span id="citySelectLabelJob" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo $cityFilter ? e($cityFilter) : 'Pilih kota...'; ?></span>
                                                <i class="fa-solid fa-chevron-down" style="color:#94a3b8; font-size:11px;"></i>
                                            </div>

                                            <!-- CITY SEARCHABLE DROPDOWN JOB -->
                                            <div id="cityDropdownListCardJob" style="display:none; position:absolute; left:18px; right:18px; top:calc(100% + 4px); background:#ffffff; border:1px solid #00a8e8; border-radius:14px; box-shadow:0 10px 25px -5px rgba(0,0,0,0.12); z-index:1005; padding:8px;">
                                                <input type="text" id="citySearchInputJob" onkeyup="filterCityOptionsJob()" placeholder="Cari kota..." style="width:100%; border:1px solid #00a8e8; border-radius:10px; padding:8px 12px; font-size:13px; outline:none; margin-bottom:6px; box-sizing:border-box;">
                                                <div id="cityOptionsContainerJob" style="max-height:220px; overflow-y:auto;"></div>
                                            </div>
                                        </div>
                                    </div>

                                </div>

                                <!-- DUAL MONTH DATE RANGE PICKER POPOVER JOB -->
                                <div id="datePickerPopoverJob" onclick="event.stopPropagation();" style="display:none; position:absolute; right:0; top:calc(100% + 8px); width:540px; max-width:90vw; background:#ffffff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 15px 35px -5px rgba(0,0,0,0.15); z-index:1010; padding:18px; box-sizing:border-box;">
                                    <!-- Header row with month/year navigation -->
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                                        <button type="button" onclick="event.stopPropagation(); prevMonthClusterJob()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-left"></i></button>

                                        <div style="display:flex; gap:24px; align-items:center;">
                                            <div style="display:flex; gap:6px;">
                                                <select id="m1SelectJob" onchange="renderCalendarJob()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y1SelectJob" onchange="renderCalendarJob()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                            <div style="display:flex; gap:6px;">
                                                <select id="m2SelectJob" onchange="renderCalendarJob()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                                <select id="y2SelectJob" onchange="renderCalendarJob()" onclick="event.stopPropagation()" style="border:1px solid #e2e8f0; border-radius:8px; padding:4px 8px; font-size:13px; font-weight:600; color:#0f172a; cursor:pointer; outline:none;"></select>
                                            </div>
                                        </div>

                                        <button type="button" onclick="event.stopPropagation(); nextMonthClusterJob()" style="background:none; border:none; cursor:pointer; padding:6px 10px; color:#475569; font-size:14px;"><i class="fa-solid fa-chevron-right"></i></button>
                                    </div>

                                    <!-- Dual Month Grids -->
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:16px;">
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m1DaysGridJob" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                        <div>
                                            <div style="display:grid; grid-template-columns:repeat(7, 1fr); text-align:center; font-size:12px; font-weight:600; color:#64748b; margin-bottom:8px;">
                                                <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum</span><span>Sab</span><span>Min</span>
                                            </div>
                                            <div id="m2DaysGridJob" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; font-size:12.5px;"></div>
                                        </div>
                                    </div>

                                    <!-- Footer Buttons -->
                                    <div style="display:flex; flex-direction:column; gap:8px;">
                                        <button type="button" onclick="applyDatePickerSelectionJob()" style="width:100%; background:#00a8e8; border:none; border-radius:10px; padding:10px; color:#ffffff; font-size:13.5px; font-weight:700; cursor:pointer;">Simpan</button>
                                        <button type="button" onclick="resetDatePickerSelectionJob()" style="width:100%; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; color:#0f172a; font-size:13.5px; font-weight:700; cursor:pointer;">Reset</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <script>
                    const CITY_MASTER = window.CITY_MASTER || [];
                    const MONTH_NAMES = window.MONTH_NAMES || ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agt", "Sep", "Okt", "Nov", "Des"];

                    let selectedStartDateJob = "<?php echo e($startDate); ?>";
                    let selectedEndDateJob = "<?php echo e($endDate); ?>";
                    let tempStartDateJob = selectedStartDateJob;
                    let tempEndDateJob = selectedEndDateJob;
                    let currentYear1Job = 2026, currentMonth1Job = 8;
                    let currentYear2Job = 2026, currentMonth2Job = 9;

                    if (selectedStartDateJob) {
                        const parts = selectedStartDateJob.split('-');
                        if (parts.length === 3) {
                            currentYear1Job = parseInt(parts[0]);
                            currentMonth1Job = parseInt(parts[1]) - 1;
                            currentMonth2Job = (currentMonth1Job + 1) % 12;
                            currentYear2Job = currentMonth1Job === 11 ? currentYear1Job + 1 : currentYear1Job;
                        }
                    }

                    function toggleFilterPopoverJob(e) {
                        if (e) e.stopPropagation();
                        const popover = document.getElementById('filterPopoverJob');
                        if (!popover) return;
                        if (popover.style.display === 'none' || !popover.style.display) {
                            popover.style.display = 'block';
                        } else {
                            popover.style.display = 'none';
                            if (document.getElementById('datePickerPopoverJob')) document.getElementById('datePickerPopoverJob').style.display = 'none';
                            if (document.getElementById('cityDropdownListCardJob')) document.getElementById('cityDropdownListCardJob').style.display = 'none';
                        }
                    }

                    function toggleAccordionJob(type) {
                        if (type === 'date') {
                            const body = document.getElementById('dateAccordionBodyJob');
                            const chevron = document.getElementById('dateChevronJob');
                            if (body.style.display === 'none' || !body.style.display) {
                                body.style.display = 'block';
                                chevron.style.transform = 'rotate(180deg)';
                            } else {
                                body.style.display = 'none';
                                chevron.style.transform = 'rotate(0deg)';
                                if (document.getElementById('datePickerPopoverJob')) document.getElementById('datePickerPopoverJob').style.display = 'none';
                            }
                        } else if (type === 'city') {
                            const body = document.getElementById('cityAccordionBodyJob');
                            const chevron = document.getElementById('cityChevronJob');
                            if (body.style.display === 'none' || !body.style.display) {
                                body.style.display = 'block';
                                chevron.style.transform = 'rotate(180deg)';
                                populateCityOptionsJob();
                            } else {
                                body.style.display = 'none';
                                chevron.style.transform = 'rotate(0deg)';
                                if (document.getElementById('cityDropdownListCardJob')) document.getElementById('cityDropdownListCardJob').style.display = 'none';
                            }
                        }
                    }

                    function toggleDatePickerPopoverJob(e) {
                        if (e) e.stopPropagation();
                        const picker = document.getElementById('datePickerPopoverJob');
                        if (!picker) return;
                        if (picker.style.display === 'none' || !picker.style.display) {
                            picker.style.display = 'block';
                            initMonthYearSelectsJob();
                            renderCalendarJob();
                        } else {
                            picker.style.display = 'none';
                        }
                    }

                    function toggleCityDropdownJob(e) {
                        if (e) e.stopPropagation();
                        const card = document.getElementById('cityDropdownListCardJob');
                        if (!card) return;
                        if (card.style.display === 'none' || !card.style.display) {
                            card.style.display = 'block';
                            populateCityOptionsJob();
                            setTimeout(() => {
                                const input = document.getElementById('citySearchInputJob');
                                if (input) input.focus();
                            }, 50);
                        } else {
                            card.style.display = 'none';
                        }
                    }

                    function populateCityOptionsJob(filter = '') {
                        const container = document.getElementById('cityOptionsContainerJob');
                        if (!container) return;
                        container.innerHTML = '';

                        const filterLower = filter.toLowerCase();
                        const masterList = (typeof CITY_MASTER !== 'undefined') ? CITY_MASTER : [];
                        const filtered = masterList.filter(c => c.toLowerCase().includes(filterLower));

                        if (filtered.length === 0) {
                            container.innerHTML = '<div style="padding:10px; font-size:12.5px; color:#94a3b8; text-align:center;">Kota tidak ditemukan</div>';
                            return;
                        }

                        filtered.forEach(city => {
                            const item = document.createElement('div');
                            item.style.cssText = 'padding:8px 12px; font-size:13px; color:#334155; cursor:pointer; border-radius:8px; font-weight:500;';
                            item.textContent = city;
                            item.onmouseover = () => item.style.background = '#f1f5f9';
                            item.onmouseout = () => item.style.background = 'transparent';
                            item.onclick = (e) => {
                                e.stopPropagation();
                                selectCityJob(city);
                            };
                            container.appendChild(item);
                        });
                    }

                    function filterCityOptionsJob() {
                        const val = document.getElementById('citySearchInputJob').value;
                        populateCityOptionsJob(val);
                    }

                    function selectCityJob(cityName) {
                        document.getElementById('inputCityFilterJob').value = cityName;
                        document.getElementById('citySelectLabelJob').textContent = cityName;
                        document.getElementById('cityDropdownListCardJob').style.display = 'none';
                        document.getElementById('filterMainFormJob').submit();
                    }

                    function initMonthYearSelectsJob() {
                        const m1Sel = document.getElementById('m1SelectJob');
                        const m2Sel = document.getElementById('m2SelectJob');
                        const y1Sel = document.getElementById('y1SelectJob');
                        const y2Sel = document.getElementById('y2SelectJob');

                        if (!m1Sel || !m2Sel || !y1Sel || !y2Sel) return;

                        m1Sel.innerHTML = MONTH_NAMES.map((m, i) => `<option value="${i}" ${i === currentMonth1Job ? 'selected' : ''}>${m}</option>`).join('');
                        m2Sel.innerHTML = MONTH_NAMES.map((m, i) => `<option value="${i}" ${i === currentMonth2Job ? 'selected' : ''}>${m}</option>`).join('');

                        const years = [2024, 2025, 2026, 2027];
                        y1Sel.innerHTML = years.map(y => `<option value="${y}" ${y === currentYear1Job ? 'selected' : ''}>${y}</option>`).join('');
                        y2Sel.innerHTML = years.map(y => `<option value="${y}" ${y === currentYear2Job ? 'selected' : ''}>${y}</option>`).join('');
                    }

                    function prevMonthClusterJob() {
                        currentMonth1Job--;
                        if (currentMonth1Job < 0) { currentMonth1Job = 11; currentYear1Job--; }
                        currentMonth2Job = (currentMonth1Job + 1) % 12;
                        currentYear2Job = currentMonth1Job === 11 ? currentYear1Job + 1 : currentYear1Job;
                        initMonthYearSelectsJob();
                        renderCalendarJob();
                    }

                    function nextMonthClusterJob() {
                        currentMonth1Job++;
                        if (currentMonth1Job > 11) { currentMonth1Job = 0; currentYear1Job++; }
                        currentMonth2Job = (currentMonth1Job + 1) % 12;
                        currentYear2Job = currentMonth1Job === 11 ? currentYear1Job + 1 : currentYear1Job;
                        initMonthYearSelectsJob();
                        renderCalendarJob();
                    }

                    function renderMonthGridJob(gridId, year, month) {
                        const grid = document.getElementById(gridId);
                        if (!grid) return;
                        grid.innerHTML = '';

                        const firstDay = new Date(year, month, 1).getDay();
                        const daysInMonth = new Date(year, month + 1, 0).getDate();
                        const prevMonthDays = new Date(year, month, 0).getDate();

                        const offset = (firstDay + 6) % 7;

                        for (let i = offset - 1; i >= 0; i--) {
                            const dayNum = prevMonthDays - i;
                            const cell = document.createElement('div');
                            cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
                            cell.textContent = dayNum;
                            grid.appendChild(cell);
                        }

                        for (let d = 1; d <= daysInMonth; d++) {
                            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                            const cell = document.createElement('div');

                            let isSelected = false;
                            let isInRange = false;

                            if (tempStartDateJob && dateStr === tempStartDateJob) isSelected = true;
                            if (tempEndDateJob && dateStr === tempEndDateJob) isSelected = true;
                            if (tempStartDateJob && tempEndDateJob && dateStr > tempStartDateJob && dateStr < tempEndDateJob) isInRange = true;

                            let bg = 'transparent';
                            let color = '#334155';
                            let borderRadius = '50%';
                            let fontWeight = '500';

                            if (isSelected) {
                                bg = '#00a8e8';
                                color = '#ffffff';
                                fontWeight = '700';
                            } else if (isInRange) {
                                bg = '#e0f2fe';
                                color = '#0284c7';
                                borderRadius = '0';
                            }

                            cell.style.cssText = `padding:6px 0; background:${bg}; color:${color}; border-radius:${borderRadius}; font-weight:${fontWeight}; cursor:pointer; font-size:12.5px; transition:all 0.15s;`;
                            cell.textContent = d;
                            cell.onclick = (e) => {
                                if (e) e.stopPropagation();
                                selectDateJob(dateStr);
                            };
                            grid.appendChild(cell);
                        }

                        const totalCells = offset + daysInMonth;
                        const remaining = (7 - (totalCells % 7)) % 7;
                        for (let n = 1; n <= remaining; n++) {
                            const cell = document.createElement('div');
                            cell.style.cssText = 'padding:6px 0; color:#cbd5e1; font-weight:500;';
                            cell.textContent = n;
                            grid.appendChild(cell);
                        }
                    }

                    function renderCalendarJob() {
                        const m1Sel = document.getElementById('m1SelectJob');
                        const y1Sel = document.getElementById('y1SelectJob');
                        const m2Sel = document.getElementById('m2SelectJob');
                        const y2Sel = document.getElementById('y2SelectJob');

                        if (m1Sel && y1Sel && m2Sel && y2Sel) {
                            currentMonth1Job = parseInt(m1Sel.value);
                            currentYear1Job = parseInt(y1Sel.value);
                            currentMonth2Job = parseInt(m2Sel.value);
                            currentYear2Job = parseInt(y2Sel.value);
                        }

                        renderMonthGridJob('m1DaysGridJob', currentYear1Job, currentMonth1Job);
                        renderMonthGridJob('m2DaysGridJob', currentYear2Job, currentMonth2Job);
                    }

                    function selectDateJob(dateStr) {
                        if (!tempStartDateJob || (tempStartDateJob && tempEndDateJob)) {
                            tempStartDateJob = dateStr;
                            tempEndDateJob = '';
                        } else if (tempStartDateJob && !tempEndDateJob) {
                            if (dateStr >= tempStartDateJob) {
                                tempEndDateJob = dateStr;
                            } else {
                                tempEndDateJob = tempStartDateJob;
                                tempStartDateJob = dateStr;
                            }
                        }
                        renderCalendarJob();
                    }

                    function applyDatePickerSelectionJob() {
                        selectedStartDateJob = tempStartDateJob;
                        selectedEndDateJob = tempEndDateJob;
                        document.getElementById('inputStartDateJob').value = selectedStartDateJob;
                        document.getElementById('inputEndDateJob').value = selectedEndDateJob;

                        if (selectedStartDateJob && selectedEndDateJob) {
                            document.getElementById('dateRangeLabelJob').textContent = `${selectedStartDateJob} - ${selectedEndDateJob}`;
                            document.getElementById('clearDateBtnJob').style.display = 'inline';
                        } else if (selectedStartDateJob) {
                            document.getElementById('dateRangeLabelJob').textContent = selectedStartDateJob;
                            document.getElementById('clearDateBtnJob').style.display = 'inline';
                        } else {
                            document.getElementById('dateRangeLabelJob').textContent = 'Pilih rentang tanggal';
                            document.getElementById('clearDateBtnJob').style.display = 'none';
                        }

                        document.getElementById('datePickerPopoverJob').style.display = 'none';
                        document.getElementById('filterMainFormJob').submit();
                    }

                    function resetDatePickerSelectionJob() {
                        tempStartDateJob = '';
                        tempEndDateJob = '';
                        selectedStartDateJob = '';
                        selectedEndDateJob = '';
                        document.getElementById('inputStartDateJob').value = '';
                        document.getElementById('inputEndDateJob').value = '';
                        document.getElementById('dateRangeLabelJob').textContent = 'Pilih rentang tanggal';
                        document.getElementById('clearDateBtnJob').style.display = 'none';
                        renderCalendarJob();
                        document.getElementById('filterMainFormJob').submit();
                    }

                    function clearDateRangeJob() {
                        resetDatePickerSelectionJob();
                    }
                    </script>

                    <div class="console-table-card" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; overflow-x:auto;">
                        <table class="console-table" style="width:100%; border-collapse:collapse; min-width:1000px;">
                            <thead>
                                <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:left;">
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:240px;">
                                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'name_asc' ? 'name_desc' : 'name_asc'; ?><?php echo $filterParamsJob; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Judul Lowongan <i class="fa-solid fa-arrows-up-down" style="font-size:11px; color:#94a3b8;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:130px;">Jenis Entitas</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:140px;">Status</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:120px;">Blacklist</th>
                                    <th style="padding:14px 16px; font-size:12.5px; font-weight:600; color:#475569; min-width:160px;">
                                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo $sort === 'date_desc' ? 'date_asc' : 'date_desc'; ?><?php echo $filterParamsJob; ?>" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            Tanggal Pengajuan <i class="fa-solid fa-arrow-down" style="font-size:11px; color:#64748b;"></i>
                                        </a>
                                    </th>
                                    <th style="padding:14px 16px; width:130px; text-align:right;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$showingJobs): ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center; padding:60px 20px; color:#64748b; font-size:13.5px;">
                                            <?php echo $entity === 'Perusahaan' ? 'Tidak ada data lowongan perusahaan.' : 'Tidak ada data lowongan yang tersedia.'; ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($showingJobs as $vJob):
                                        $jobTitle = $vJob['title'];
                                        $isIndividual = ($vJob['entity_type'] === 'Individu' || $vJob['entity_type'] === 'Individual' || empty($vJob['entity_type']));
                                        $brandName = trim((string)($vJob['brand_name'] ?? ''));
                                        $ownerName = $vJob['owner_name'] ?: $vJob['user_name'];
                                        // Untuk Jenis Entitas = Individu, tampilkan Nama Brand Usaha sebagai sublabel/nama Pemberi Kerja pada Lowongan.
                                        $employerSublabel = ($isIndividual && $brandName !== '') ? $brandName : $ownerName;
                                        $entityTypeLabel = ($vJob['entity_type'] === 'Perusahaan') ? 'Perusahaan' : 'Individual';

                                        $jStatus = $vJob['status'] ?? '';
                                        if ($jStatus === 'Tayang') {
                                            $badgeHtml = '<span class="pill-badge verified">● Disetujui</span>';
                                        } elseif ($jStatus === 'Menunggu Verifikasi') {
                                            $badgeHtml = '<span class="pill-badge assigned">● Ditugaskan</span>';
                                        } elseif ($jStatus === 'Perlu Direvisi') {
                                            $badgeHtml = '<span class="pill-badge revision">● Revisi</span>';
                                        } elseif ($jStatus === 'Ditolak' || $jStatus === 'CANCELED') {
                                            $badgeHtml = '<span class="pill-badge danger">● Ditolak</span>';
                                        } else {
                                            $badgeHtml = '<span class="pill-badge pending">● ' . e($jStatus) . '</span>';
                                        }

                                        // Blacklist Status Indicator
                                        $isBlacklisted = !empty($vJob['is_blacklisted']) || ($vJob['verification_status'] ?? '') === 'REJECTED';
                                        $blacklistHtml = $isBlacklisted
                                            ? '<span style="color:#dc2626; font-weight:600; font-size:12.5px;">Terdeteksi</span>'
                                            : '<span style="color:#059669; font-weight:600; font-size:12.5px;">Aman</span>';

                                        $dateStr = date('d M Y, H:i', strtotime($vJob['created_at']));
                                        $initialChar = mb_strtoupper(mb_substr($jobTitle, 0, 1));
                                    ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <!-- Column 1: Judul Lowongan + Nama Pemberi Kerja Individu -->
                                            <td style="padding:14px 16px;">
                                                <div style="display:flex; align-items:center; gap:10px;">
                                                    <div style="width:30px; height:30px; border-radius:50%; background:#e0f2fe; color:#0284c7; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:12.5px; flex-shrink:0;">
                                                        <?php echo e($initialChar); ?>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight:700; color:#0f172a; font-size:13.5px; line-height:1.3;"><?php echo e($jobTitle); ?></div>
                                                        <div style="font-size:12px; color:#64748b; margin-top:2px;"><?php echo e($employerSublabel); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <!-- Column 2: Jenis Entitas -->
                                            <td style="padding:14px 16px; color:#334155; font-size:13px; font-weight:500;"><?php echo e($entityTypeLabel); ?></td>
                                            <!-- Column 3: Status -->
                                            <td style="padding:14px 16px; font-size:13px; white-space:nowrap;"><?php echo $badgeHtml; ?></td>
                                            <!-- Column 4: Blacklist -->
                                            <td style="padding:14px 16px; font-size:13px; white-space:nowrap;"><?php echo $blacklistHtml; ?></td>
                                            <!-- Column 5: Tanggal Pengajuan -->
                                            <td style="padding:14px 16px; color:#64748b; font-size:12.5px; white-space:nowrap;"><?php echo e($dateStr); ?></td>
                                            <!-- Column 6: Action Button -->
                                            <td style="padding:14px 16px; text-align:right; white-space:nowrap;">
                                                <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&detail_id=<?php echo $vJob['id']; ?>" class="btn-lihat-detail" style="white-space:nowrap;">Lihat Detail</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($showingJobs): ?>
                            <div class="console-table-footer" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; font-size:13px; color:#64748b; border-top:1px solid #e2e8f0;">
                                <div>
                                    Menampilkan <?php echo $showingCount; ?> dari <?php echo $totalData; ?> total data.
                                </div>
                                <div style="display:flex; align-items:center; gap:4px;">
                                    <?php if ($page > 1): ?>
                                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page - 1; ?><?php echo $filterParamsJob; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">‹</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">‹</span>
                                    <?php endif; ?>

                                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                        <?php if ($p == $page): ?>
                                            <span style="padding:6px 12px; border-radius:6px; background:#0284c7; color:#fff; font-weight:700; border:1px solid #0284c7;"><?php echo $p; ?></span>
                                        <?php else: ?>
                                            <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $p; ?><?php echo $filterParamsJob; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;"><?php echo $p; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="admin.php?view=verifikasi_job&entity=<?php echo e($entity); ?>&tab=<?php echo e($tab); ?>&q=<?php echo urlencode($search); ?>&sort=<?php echo e($sort); ?>&page=<?php echo $page + 1; ?><?php echo $filterParamsJob; ?>" style="padding:6px 12px; border-radius:6px; text-decoration:none; color:#475569; border:1px solid #cbd5e1; font-weight:600;">›</a>
                                    <?php else: ?>
                                        <span style="padding:6px 12px; border-radius:6px; color:#cbd5e1; border:1px solid #e2e8f0; font-weight:600; cursor:not-allowed;">›</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
</div>
    </div>
<script src="assets/app.js?v=admin-std-1"></script>
<script>
function toggleActionMenu(btn, e) {
    e.stopPropagation();
    const menu = btn.nextElementSibling;
    const isShown = menu.classList.contains('show');
    document.querySelectorAll('.action-menu-dropdown').forEach(el => el.classList.remove('show'));
    if (!isShown) {
        menu.classList.add('show');
    }
}
document.addEventListener('click', (e) => {
    document.querySelectorAll('.action-menu-dropdown').forEach(el => el.classList.remove('show'));

    // Employer Filter Popover outside click close
    const popoverEmp = document.getElementById('filterPopoverEmp');
    const filterBtnEmp = document.getElementById('filterToggleBtnEmp');
    const pickerEmp = document.getElementById('datePickerPopoverEmp');
    const cityCardEmp = document.getElementById('cityDropdownListCardEmp');
    const verifierCardEmp = document.getElementById('verifierDropdownListCardEmp');
    const officerCardEmp = document.getElementById('officerDropdownListCardEmp');

    const isInsidePopoverEmp = popoverEmp && popoverEmp.contains(e.target);
    const isInsideFilterBtnEmp = filterBtnEmp && filterBtnEmp.contains(e.target);
    const isInsidePickerEmp = pickerEmp && pickerEmp.contains(e.target);

    if (!isInsidePopoverEmp && !isInsideFilterBtnEmp && !isInsidePickerEmp) {
        if (popoverEmp) popoverEmp.style.display = 'none';
        if (pickerEmp) pickerEmp.style.display = 'none';
        if (cityCardEmp) cityCardEmp.style.display = 'none';
        if (verifierCardEmp) verifierCardEmp.style.display = 'none';
        if (officerCardEmp) officerCardEmp.style.display = 'none';
    }
});
</script>
<script>
function switchIndividuTab(tabName) {
    const tabs = ['profil', 'lowongan', 'lamaran'];
    tabs.forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        const content = document.getElementById('tab-content-' + t);
        if (btn) {
            if (t === tabName) {
                btn.style.color = '#0284c7';
                btn.style.fontWeight = '700';
                btn.style.borderBottom = '2px solid #0284c7';
            } else {
                btn.style.color = '#64748b';
                btn.style.fontWeight = '600';
                btn.style.borderBottom = '2px solid transparent';
            }
        }
        if (content) {
            if (t === tabName) {
                content.style.display = (t === 'profil') ? 'grid' : 'block';
            } else {
                content.style.display = 'none';
            }
        }
    });
}
</script>
</body>
</html>
