<?php

function ensure_platform_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return;
    }

    $employerColumns = array_column($pdo->query('SHOW COLUMNS FROM employer_profiles')->fetchAll(), 'Field');
    $employerAdds = [
        'nik' => 'VARCHAR(30) NULL',
        'whatsapp' => 'VARCHAR(30) NULL',
        'linkedin' => 'VARCHAR(255) NULL',
        'facebook' => 'VARCHAR(255) NULL',
        'instagram' => 'VARCHAR(255) NULL',
        'tiktok' => 'VARCHAR(255) NULL',
        'npwp' => 'VARCHAR(40) NULL',
        'latitude' => 'VARCHAR(40) NULL',
        'longitude' => 'VARCHAR(40) NULL',
        'permit_document' => 'VARCHAR(255) NULL',
        'workplace_photo' => 'VARCHAR(255) NULL',
        'brand_name' => 'VARCHAR(255) NULL',
        'business_logo' => 'VARCHAR(255) NULL',
        'consent_accepted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'active_until' => 'DATETIME NULL',
        'last_activated_at' => 'DATETIME NULL',
        'extension_requested' => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach ($employerAdds as $name => $definition) {
        if (!in_array($name, $employerColumns, true)) {
            $pdo->exec('ALTER TABLE employer_profiles ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    $jobColumns = array_column($pdo->query('SHOW COLUMNS FROM job_posts')->fetchAll(), 'Field');
    $jobAdds = [
        'kbji_code' => 'VARCHAR(20) NULL',
        'details' => 'TEXT NULL',
        'parent_job_id' => 'INT NULL',
        'unfulfilled_reason' => 'TEXT NULL',
        'admin_notes' => 'TEXT NULL',
        'revision_opened_at' => 'DATETIME NULL',
    ];
    foreach ($jobAdds as $name => $definition) {
        if (!in_array($name, $jobColumns, true)) {
            $pdo->exec('ALTER TABLE job_posts ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    try {
        $pdo->exec("ALTER TABLE job_posts MODIFY status VARCHAR(60) NOT NULL DEFAULT 'Draft'");
    } catch (Throwable $ignored) {
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(40) NOT NULL,
        job_id INT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_user (user_id, is_read)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS job_applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        seeker_id INT NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT "Lamaran Masuk",
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_job_seeker (job_id, seeker_id)
    )');

    $appColumns = array_column($pdo->query('SHOW COLUMNS FROM job_applications')->fetchAll(), 'Field');
    if (!in_array('accepted_at', $appColumns, true)) {
        $pdo->exec('ALTER TABLE job_applications ADD COLUMN accepted_at DATETIME NULL AFTER status');
    }
    if (!in_array('updated_at', $appColumns, true)) {
        $pdo->exec('ALTER TABLE job_applications ADD COLUMN updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    }
    $pdo->exec("UPDATE job_applications SET status = 'Lamaran Masuk' WHERE status IN ('Dilamar', 'Applied', '')");
    $pdo->exec("UPDATE job_applications SET accepted_at = COALESCE(updated_at, created_at) WHERE status = 'Diterima' AND accepted_at IS NULL");
}

function notify_user(int $userId, string $title, string $message, string $type = 'info', ?int $jobId = null): void
{
    $statement = db()->prepare('INSERT INTO notifications (user_id, title, message, type, job_id) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$userId, $title, $message, $type, $jobId]);
}

function user_notifications(int $userId, int $limit = 12): array
{
    $statement = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit);
    $statement->execute([$userId]);
    return $statement->fetchAll() ?: [];
}

function unread_notification_count(int $userId): int
{
    $statement = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $statement->execute([$userId]);
    return (int) $statement->fetchColumn();
}

function mark_notifications_read(int $userId): void
{
    $statement = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $statement->execute([$userId]);
}

function store_upload(string $field, string $subdir, array $allowedExt): ?string
{
    if (empty($_FILES[$field]['name']) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $original = (string) $_FILES[$field]['name'];
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }

    $dir = __DIR__ . '/../uploads/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $filename = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $dir . '/' . $filename;
    if (!@move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
        if (!@copy($_FILES[$field]['tmp_name'], $target)) {
            return null;
        }
    }

    return 'uploads/' . trim($subdir, '/') . '/' . $filename;
}

function canonical_job_statuses(): array
{
    return [
        'Draft',
        'Menunggu Verifikasi',
        'ADDITIONAL_DOCUMENT_PENDING',
        'Perlu Direvisi',
        'Ditolak',
        'CANCELED',
        'Terjadwal Tayang',
        'Tayang',
        'Ditangguhkan',
        'Ditutup',
        'Kedaluwarsa',
        'Diblokir',
    ];
}

function normalize_job_status(string $status): string
{
    $trimmed = trim($status);
    return match ($trimmed) {
        'Dikirim/Menunggu Verifikasi', 'Dikirim', 'Menunggu Persetujuan', 'PENDING_JOB_VERIFICATION' => 'Menunggu Verifikasi',
        'ADDITIONAL_DOCUMENT_PENDING', 'Menunggu Dokumen Tambahan', 'Dokumen Tambahan Diperlukan', 'Dokumen Tambahan' => 'ADDITIONAL_DOCUMENT_PENDING',
        'Perlu Revisi', 'Revisi' => 'Perlu Direvisi',
        'Lowongan Aktif', 'Disetujui', 'Aktif' => 'Tayang',
        'Tutup' => 'Ditutup',
        'Expired' => 'Kedaluwarsa',
        'Suspended' => 'Ditangguhkan',
        'Blocked' => 'Diblokir',
        'CANCELED', 'Dibatalkan', 'Batal' => 'CANCELED',
        'Draft' => 'Draft',
        'Menunggu Verifikasi' => 'Menunggu Verifikasi',
        'Perlu Direvisi' => 'Perlu Direvisi',
        'Ditolak' => 'Ditolak',
        'Terjadwal Tayang' => 'Terjadwal Tayang',
        'Tayang' => 'Tayang',
        'Ditangguhkan' => 'Ditangguhkan',
        'Ditutup' => 'Ditutup',
        'Kedaluwarsa' => 'Kedaluwarsa',
        'Diblokir' => 'Diblokir',
        default => in_array($trimmed, canonical_job_statuses(), true) ? $trimmed : 'Draft',
    };
}

function job_status_meta(string $status): array
{
    $canonical = normalize_job_status($status);
    return match ($canonical) {
        'Draft' => ['label' => 'Draft', 'class' => 'draft'],
        'Menunggu Verifikasi' => ['label' => 'Menunggu Verifikasi', 'class' => 'pending'],
        'ADDITIONAL_DOCUMENT_PENDING' => ['label' => 'Dokumen Tambahan Diperlukan', 'class' => 'warning'],
        'Perlu Direvisi' => ['label' => 'Perlu Direvisi', 'class' => 'revision'],
        'Ditolak' => ['label' => 'Ditolak', 'class' => 'rejected'],
        'CANCELED' => ['label' => 'Dibatalkan (CANCELED)', 'class' => 'rejected'],
        'Terjadwal Tayang' => ['label' => 'Terjadwal Tayang', 'class' => 'scheduled'],
        'Tayang' => ['label' => 'Tayang', 'class' => 'live'],
        'Ditangguhkan' => ['label' => 'Ditangguhkan', 'class' => 'suspended'],
        'Ditutup' => ['label' => 'Ditutup', 'class' => 'closed'],
        'Kedaluwarsa' => ['label' => 'Kedaluwarsa', 'class' => 'expired'],
        'Diblokir' => ['label' => 'Diblokir', 'class' => 'blocked'],
        default => ['label' => $status, 'class' => 'draft'],
    };
}

function job_default_approve_note(): string
{
    return 'Lowongan telah memenuhi syarat dan disetujui untuk ditayangkan';
}

function job_revision_quick_tags(): array
{
    return [
        'Deskripsi pekerjaan kurang lengkap atau tidak jelas (rincian tugas/tanggung jawab belum dijabarkan spesifik).',
        'Kode KBJI tidak sesuai dengan posisi jabatan (tidak sinkron dengan judul/uraian pekerjaan).',
        'Persyaratan atau kualifikasi tidak wajar / diskriminatif (memuat kriteria fisik/syarat yang melanggar norma).',
        'Informasi gaji atau kompensasi tidak jelas (tidak dicantumkan atau berpotensi menyesatkan).',
        'Penulisan lowongan tidak profesional (huruf kapital berlebihan, banyak typo, atau tata bahasa tidak baku).',
        'Jenis pekerjaan atau status kontrak tidak sesuai (kategori magang/kontrak/penuh waktu tidak sinkron dengan isi teks).',
    ];
}

function job_rich_html(?string $html): string
{
    $clean = strip_tags((string) $html, '<p><br><b><strong><i><em><u><s><ul><ol><li><h3><h4><a><span>');
    return trim($clean);
}

function format_rupiah(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Tidak dicantumkan';
    }

    return 'Rp ' . number_format((int) $value, 0, ',', '.');
}

function job_yes_no(bool $value): string
{
    return $value ? 'Ya' : 'Tidak';
}

function time_ago_id(?string $datetime): string
{
    $timestamp = strtotime((string) $datetime);
    if (!$timestamp) {
        return '-';
    }

    $diff = max(0, time() - $timestamp);
    if ($diff < 60) {
        return 'baru saja';
    }
    if ($diff < 3600) {
        return 'sekitar ' . (int) floor($diff / 60) . ' menit yang lalu';
    }
    if ($diff < 86400) {
        return 'sekitar ' . (int) floor($diff / 3600) . ' jam yang lalu';
    }
    if ($diff < 2592000) {
        $days = (int) floor($diff / 86400);
        return $days . ' hari yang lalu';
    }

    return date('d M Y', $timestamp);
}

function job_public_salary(array $job): string
{
    $data = job_to_form_data($job);
    if (empty($data['show_salary']) || ($data['salary_min'] === null && $data['salary_max'] === null)) {
        return 'Dirahasiakan';
    }

    $min = $data['salary_min'] !== null ? format_rupiah($data['salary_min']) : '';
    $max = $data['salary_max'] !== null ? format_rupiah($data['salary_max']) : '';
    if ($min && $max) {
        return $min . ' - ' . $max;
    }

    return $min ?: $max;
}

function format_salary_range($min, $max): string
{
    if (!empty($min) && !empty($max)) {
        return format_rupiah($min) . ' – ' . format_rupiah($max);
    } elseif (!empty($min)) {
        return 'Mulai ' . format_rupiah($min);
    } elseif (!empty($max)) {
        return 'Hingga ' . format_rupiah($max);
    }
    return 'Negosiasi';
}

function job_apply_deadline(array $job): string
{
    $days = (int) (job_to_form_data($job)['expiry_days'] ?: 30);
    $created = strtotime((string) ($job['created_at'] ?? 'now')) ?: time();
    return date('d M Y', strtotime('+' . $days . ' days', $created));
}

function job_employer_display_name(array $job): string
{
    $owner = trim((string) ($job['owner_name'] ?? ''));
    return $owner !== '' ? $owner : (string) ($job['employer_name'] ?? 'Pemberi kerja individu');
}

function sanitize_job_location(string $loc, ?array $profile = null): string
{
    $loc = trim($loc);
    if ($loc === '') return '';

    if ($profile) {
        $pParts = array_filter([
            $profile['village'] ?? '',
            $profile['district'] ?? '',
            $profile['city'] ?? '',
            $profile['province'] ?? ''
        ]);
        if (!empty($pParts)) {
            $pLoc = implode(', ', $pParts);
            $street = trim((string)($profile['address'] ?? ''));
            if ($street !== '' && (str_contains(mb_strtolower($loc), mb_strtolower($street)) || str_starts_with(mb_strtolower($loc), mb_strtolower($street)))) {
                return $pLoc;
            }
        }
    }

    $parts = array_map('trim', explode(',', $loc));
    while (count($parts) > 4) {
        array_shift($parts);
    }
    if (count($parts) > 0 && preg_match('/^(jl|jalan|gg|gang|rt|rw|no|kav|komplek|blok)/i', $parts[0])) {
        array_shift($parts);
    }

    return !empty($parts) ? implode(', ', $parts) : $loc;
}

function job_placement_address(array $job): string
{
    $rawLoc = trim((string)($job['location'] ?? ''));
    if ($rawLoc !== '') {
        return sanitize_job_location($rawLoc, $job);
    }

    $parts = array_values(array_filter([
        trim((string) ($job['village'] ?? '')),
        trim((string) ($job['district'] ?? '')),
        trim((string) ($job['city'] ?? '')),
        trim((string) ($job['province'] ?? '')),
    ], static fn($value) => $value !== ''));

    return $parts ? implode(', ', $parts) : '-';
}

function job_employer_contact(array $job): string
{
    return trim((string) (($job['whatsapp'] ?? '') ?: ($job['phone'] ?? ''))) ?: '-';
}

function seeker_city_options(): array
{
    return [
        'Kota Bekasi',
        'Kabupaten Bekasi',
        'Kota Jakarta Pusat',
        'Kota Jakarta Selatan',
        'Kota Jakarta Timur',
        'Kota Jakarta Barat',
        'Kota Jakarta Utara',
        'Kota Bandung',
        'Kota Surabaya',
        'Kota Semarang',
        'Kota Yogyakarta',
        'Kota Depok',
        'Kota Tangerang',
        'Kota Tangerang Selatan',
        'Kota Medan',
        'Kota Makassar',
        'Kota Denpasar',
    ];
}

function seeker_job_type_options(): array
{
    return ['Penuh Waktu', 'Paruh Waktu', 'Kontrak', 'Magang', 'Freelance', 'Harian'];
}

function individual_business_types(): array
{
    return [
        'Kesehatan & Terapi' => [
            'Praktik Dokter Umum',
            'Praktik Dokter Gigi',
            'Praktik Bidan',
            'Praktik Keperawatan Mandiri',
            'Praktik Psikolog',
            'Layanan Fisioterapi',
            'Praktik Terapis Wicara',
            'Layanan Akupunktur',
            'Layanan Terapi Tumbuh Kembang Anak',
            'Jasa Pijat dan Refleksi',
            'Layanan Terapi Okupasi',
            'Layanan Konsultasi Gizi/Nutrisionis',
        ],
        'Hukum, Keuangan & Jasa Profesional' => [
            'Praktik Notaris',
            'Praktik PPAT',
            'Praktik Advokat/Pengacara',
            'Konsultan Hukum',
            'Konsultan Pajak',
            'Jasa Akuntansi',
            'Jasa Pembukuan',
            'Konsultan Manajemen',
            'Jasa Administrasi dan Pengurusan Dokumen',
            'Jasa Penerjemah',
            'Konsultan SDM',
            'Jasa Sekretarial/Administrasi Perkantoran',
        ],
        'Teknik, Arsitektur & Desain' => [
            'Praktik Arsitek',
            'Konsultan Teknik Sipil',
            'Konsultan Mekanikal dan Elektrikal',
            'Jasa Desain Interior',
            'Jasa Desain Lanskap',
            'Jasa Drafter',
            'Jasa Survei dan Pemetaan',
            'Jasa Desain Produk',
            'Jasa Pembuatan Maket dan Prototipe',
            'Jasa Perbaikan/Bengkel Teknik',
            'Jasa Instalasi Listrik',
            'Jasa Teknisi Elektronik',
        ],
        'Pendidikan & Pelatihan' => [
            'Bimbingan Belajar',
            'Guru Privat',
            'Kursus Bahasa',
            'Kursus Musik',
            'Kursus Tari',
            'Kursus Seni Rupa',
            'Pelatihan Public Speaking',
            'Pelatihan Komputer dan Digital',
            'Pelatihan Keterampilan Vokasional',
            'Pelatih Olahraga/Kebugaran',
            'Pelatihan Kewirausahaan',
            'Pelatihan Kerja/Keterampilan Profesi',
        ],
        'Jasa Acara, Kreatif & Hewan Peliharaan' => [
            'Studio Foto dan Video',
            'Jasa Fotografi/Videografi',
            'Jasa Dekorasi Acara',
            'Wedding Organizer',
            'Event Organizer',
            'Jasa Penjahit/Atelier',
            'Jasa Grooming Hewan',
            'Jasa Penitipan Hewan',
            'Jasa Percetakan',
            'Jasa Desain Grafis',
            'Jasa Pembuatan Suvenir/Handicraft',
            'Jasa Florist/Dekorasi Bunga',
        ],
        'Digital, Media & Konten' => [
            'Konten Kreator/Digital Creator',
            'Jasa Pengelolaan Media Sosial',
            'Jasa Pembuatan Konten Digital',
            'Jasa Editing Video',
            'Jasa Copywriting/Content Writing',
            'Jasa Desain Konten Media Sosial',
            'Jasa Live Streaming',
            'Studio Podcast',
            'Jasa Produksi Audio/Video',
            'Jasa Digital Marketing',
        ],
        'Kuliner & Industri Pangan Rumah Tangga' => [
            'Usaha Makanan Rumahan',
            'Jasa Katering',
            'Bakery dan Pastry Rumahan',
            'Usaha Kue dan Makanan Ringan',
            'Produksi Minuman Rumahan',
            'Produksi Makanan Olahan',
            'Produksi Makanan Beku/Frozen Food',
            'Produksi Sambal/Bumbu Olahan',
            'Produksi Camilan/Keripik',
            'Usaha Jajanan/Pangan Siap Saji',
        ],
        'Kecantikan & Perawatan Pribadi' => [
            'Salon Kecantikan',
            'Barbershop/Pangkas Rambut',
            'Makeup Artist',
            'Jasa Tata Rambut',
            'Nail Art',
            'Jasa Perawatan Kecantikan Nonmedis',
            'Jasa Perawatan Pengantin',
        ],
        'Jasa Rumah Tangga, Pemeliharaan & Perbaikan' => [
            'Jasa Kebersihan Rumah',
            'Jasa Laundry',
            'Jasa Servis AC',
            'Jasa Perbaikan Peralatan Rumah Tangga',
            'Jasa Instalasi/Perbaikan Plumbing',
            'Jasa Perawatan Taman',
            'Jasa Pengecatan',
            'Jasa Pertukangan',
        ],
    ];
}

function public_job_select_sql(): string
{
    return 'SELECT j.*, u.name AS employer_name, u.email AS employer_email,
            ep.owner_name, ep.profession, ep.address, ep.city, ep.province, ep.phone, ep.whatsapp,
            ep.verified, ep.description AS employer_bio, ep.workplace_photo, ep.latitude, ep.longitude
        FROM job_posts j
        JOIN users u ON u.id = j.user_id
        LEFT JOIN employer_profiles ep ON ep.user_id = j.user_id
        WHERE j.status = "Tayang"';
}

function render_seeker_job_card(array $job, array $appliedIds): string
{
    $applied = in_array((int) $job['id'], $appliedIds, true);
    $name = job_employer_display_name($job);
    $initial = strtoupper(mb_substr($name, 0, 1));
    $photo = trim((string) ($job['workplace_photo'] ?? ''));
    $avatar = $photo !== ''
        ? '<img src="' . e($photo) . '" alt="' . e($name) . '">'
        : e($initial);
    $titleUrl = 'seeker.php?page=job&id=' . (int) $job['id'];
    $quota = max(1, (int) ($job['quota'] ?? 1));

    $html = '<article class="vacancy-card">';
    $html .= '<div class="vacancy-card-top">';
    $html .= '<div class="vacancy-logo">' . $avatar . '</div>';
    $html .= '<span class="vacancy-time">' . e(time_ago_id($job['created_at'] ?? null)) . '</span>';
    $html .= '</div>';
    $html .= '<h3><a href="' . e($titleUrl) . '">' . e($job['title']) . '</a></h3>';
    $html .= '<div class="vacancy-employer">' . e($name) . ' <span class="perorangan-badge">Perorangan</span></div>';
    $html .= '<div class="vacancy-location"><i class="fa-solid fa-location-dot"></i> ' . e($job['location'] ?: '-') . '</div>';
    $html .= '<div class="vacancy-salary"><span>Kisaran Gaji</span><strong>' . e(job_public_salary($job)) . '</strong></div>';
    $html .= '<div class="vacancy-deadline">Lamar sebelum ' . e(job_apply_deadline($job)) . '</div>';
    $html .= '<div class="vacancy-badge">Tersisa ' . $quota . ' lowongan</div>';
    if ($applied) {
        $html .= '<button class="vacancy-apply is-disabled" type="button" disabled>Sudah Dilamar</button>';
    } else {
        $html .= '<form method="post" class="vacancy-apply-form"><input type="hidden" name="apply_job_id" value="' . (int) $job['id'] . '"><button class="vacancy-apply" type="submit">Lamar Sekarang</button></form>';
    }
    $html .= '<div class="vacancy-card-foot"><span>Lowongan dari <strong>Karirhub Perorangan</strong></span><span class="vacancy-source-mark"><i class="fa-solid fa-star"></i> Karirhub</span></div>';
    $html .= '</article>';

    return $html;
}

function kbji_name_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    try {
        foreach (db()->query('SELECT kode_kbji, nama_jabatan FROM kbji_data') as $row) {
            $map[(string) $row['kode_kbji']] = (string) $row['nama_jabatan'];
        }
    } catch (Throwable $ignored) {
        $map = [];
    }

    return $map;
}

function render_job_review_details(array $job): string
{
    $data = job_to_form_data($job);
    $kbjiName = kbji_name_map()[$data['kbji_code']] ?? '';
    $kbjiText = trim($data['kbji_code'] . ($kbjiName !== '' ? ' — ' . $kbjiName : ''));
    $salary = format_rupiah($data['salary_min']) . ' – ' . format_rupiah($data['salary_max']);
    if (!empty($data['show_salary'])) {
        $salary .= ' (ditampilkan pada lowongan)';
    } else {
        $salary .= ' (disembunyikan dari pelamar)';
    }

    $row = static function (string $label, string $value) {
        return '<div class="job-review-row"><span>' . e($label) . '</span><strong>' . $value . '</strong></div>';
    };

    $text = static function (mixed $value, string $fallback = '-') {
        $value = trim((string) $value);
        return e($value !== '' ? $value : $fallback);
    };

    $list = static function (array $items, string $fallback = '-') {
        $items = array_values(array_filter(array_map(static fn($item) => trim((string) $item), $items)));
        return e($items ? implode(', ', $items) : $fallback);
    };

    $description = job_rich_html($data['description']);
    $special = job_rich_html($data['special_requirements']);
    $employerPhone = trim((string) (($job['employer_whatsapp'] ?? '') ?: ($job['employer_phone'] ?? '')));

    $html = '<section class="job-review-section"><h3>Informasi Lowongan</h3>';
    $html .= $row('Judul jabatan', $text($data['title']));
    $html .= $row('Jenis pekerjaan', $text($data['job_type']));
    $html .= $row('Bidang pekerjaan', $text($data['job_field']));
    $html .= $row('Jenis Usaha Individu', $text($data['industry']));
    $html .= $row('Kode KBJI', $text($kbjiText));
    $html .= $row('Lokasi', $text($data['location']));
    $html .= $row('Remote working', e(job_yes_no(!empty($data['is_remote']))));
    $html .= $row('Terbatas', e(job_yes_no(!empty($data['is_limited']))));
    $html .= $row('Durasi tayang', $data['expiry_days'] !== '' ? e($data['expiry_days'] . ' hari') : e('-'));
    $html .= $row('Kuota', e((string) $data['quota'] . ' orang'));
    $html .= $row('Gaji', e($salary));
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Deskripsi Pekerjaan</h3>';
    $html .= $description !== '' ? '<div class="job-review-prose">' . $description . '</div>' : '<p class="job-review-empty">Tidak ada deskripsi.</p>';
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Kualifikasi &amp; Persyaratan</h3>';
    $html .= $row('Pendidikan minimal', $text($data['education_required']));
    $html .= $row('Pengalaman', $text($data['experience_required']));
    $html .= $row('Status pernikahan', $list($data['marital_statuses']));
    $html .= $row('Usia', e(trim(($data['age_min'] !== '' ? $data['age_min'] . ' th' : '-') . ' – ' . ($data['age_max'] !== '' ? $data['age_max'] . ' th' : '-'))));
    $html .= $row('Kondisi fisik', $list($data['physical_conditions']));
    $html .= $row('Jenis kelamin', $list($data['genders']));
    $html .= $row('Disabilitas tidak diperbolehkan', $text($data['disability_excluded'], 'Tidak ada batasan'));
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Persyaratan Khusus</h3>';
    $html .= $special !== '' ? '<div class="job-review-prose">' . $special . '</div>' : '<p class="job-review-empty">Tidak ada persyaratan khusus.</p>';
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Keahlian &amp; Kontak</h3>';
    $html .= $row('Skill / keahlian', $list($data['skills']));
    $html .= $row('Kontak lamaran', $list($data['contacts']));
    $html .= '</section>';

    $html .= '<section class="job-review-section"><h3>Pemberi Kerja</h3>';
    $html .= $row('Nama', $text($job['employer_name'] ?? '-'));
    $html .= $row('Email', $text($job['employer_email'] ?? '-'));
    $html .= $row('Telepon / WA', $text($employerPhone, '-'));
    $html .= $row('Profesi', $text($job['employer_profession'] ?? '-'));
    $html .= $row('Dibuat', e(date('d M Y H:i', strtotime((string) ($job['created_at'] ?? 'now')))));
    $html .= '</section>';

    return $html;
}

function parse_job_details(?string $json): array
{
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : [];
}

function job_decision_editable(array $job): bool
{
    $status = normalize_job_status((string) ($job['status'] ?? ''));
    if (!in_array($status, ['Menunggu Verifikasi', 'Perlu Direvisi', 'Tayang', 'Ditolak'], true)) {
        return false;
    }

    // After the employer opens and works on the revision form, the saved
    // decision can no longer be changed until they resubmit.
    if ($status === 'Perlu Direvisi' && !empty($job['revision_opened_at'])) {
        return false;
    }

    return true;
}

function job_to_form_data(array $job): array
{
    $details = parse_job_details($job['details'] ?? null);

    return [
        'id' => (int) ($job['id'] ?? 0),
        'title' => (string) ($job['title'] ?? ''),
        'description' => (string) ($job['description'] ?? ''),
        'location' => (string) ($job['location'] ?? ''),
        'job_type' => (string) ($job['job_type'] ?? ''),
        'industry' => (string) ($job['industry'] ?? ''),
        'salary_min' => $job['salary_min'] ?? null,
        'salary_max' => $job['salary_max'] ?? null,
        'quota' => (int) ($job['quota'] ?? 1),
        'kbji_code' => (string) ($job['kbji_code'] ?? ''),
        'admin_notes' => (string) ($job['admin_notes'] ?? ''),
        'job_field' => (string) ($details['job_field'] ?? ''),
        'physical_conditions' => array_values((array) ($details['physical_conditions'] ?? ['Disabilitas', 'Non Disabilitas'])),
        'genders' => array_values((array) ($details['genders'] ?? ['Laki-laki', 'Perempuan'])),
        'disability_excluded' => (string) ($details['disability_excluded'] ?? ''),
        'show_salary' => !empty($details['show_salary']),
        'is_remote' => !empty($details['is_remote']),
        'is_limited' => !empty($details['is_limited']),
        'expiry_days' => (function($val) {
            $e = (int)$val;
            if ($e === 1) return 30;
            if ($e === 2) return 60;
            if ($e === 3) return 90;
            return $e > 0 ? $e : 30;
        })($details['expiry_days'] ?? 30),
        'education_required' => (string) ($details['education_required'] ?? ''),
        'experience_required' => (string) ($details['experience_required'] ?? ''),
        'marital_statuses' => array_values((array) ($details['marital_statuses'] ?? ['Telah Menikah', 'Lajang / Belum Menikah'])),
        'age_min' => $details['age_min'] ?? '',
        'age_max' => $details['age_max'] ?? '',
        'special_requirements' => (string) ($details['special_requirements'] ?? ''),
        'skills' => array_values((array) ($details['skills'] ?? [])),
        'contacts' => array_values((array) ($details['contacts'] ?? [])),
    ];
}

function render_notif_dropdown(array $notifications, int $unread, string $verStatus = '', string $manualStatus = '', ?array $activePcr = null, ?int $userId = null): string
{
    if ($activePcr === null && $userId !== null && function_exists('get_active_profile_change_request')) {
        $activePcr = get_active_profile_change_request($userId);
    }

    $items = '';

    // If there is an active profile change request pending verification, show prominent notification item
    if ($activePcr && ($activePcr['status'] ?? '') === 'PENDING') {
        $pcrDate = !empty($activePcr['created_at']) ? date('d M Y H:i', strtotime($activePcr['created_at'])) . ' WIB' : 'Baru saja';
        $items .= '<div class="notif-item unread" style="background:#f0f9ff; border-left:3px solid #0284c7; cursor:pointer;" data-open-modal="modal-profile-change-request">';
        $items .= '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">';
        $items .= '<strong style="color:#0284c7; font-size:13px; display:inline-flex; align-items:center; gap:6px;"><i class="fa-solid fa-clock-rotate-left"></i> Permintaan Perubahan Profil</strong>';
        $items .= '<span style="font-size:10px; font-weight:700; background:#e0f2fe; color:#0369a1; padding:2px 7px; border-radius:999px;">Menunggu Keputusan</span>';
        $items .= '</div>';
        $items .= '<p style="color:#334155; font-size:12px; line-height:1.4; margin:0 0 4px 0;">Pengajuan perubahan data profil sedang diperiksa oleh Admin/Petugas Dinas. Klik di sini untuk melihat detail permohonan Anda.</p>';
        $items .= '<span style="font-size:11px; color:#64748b;">Diajukan: ' . e($pcrDate) . '</span>';
        $items .= '</div>';
    }

    if (!$notifications && !$activePcr) {
        $items = '<div class="notif-empty" style="padding:20px; text-align:center; color:#94a3b8; font-size:13px;">Belum ada notifikasi.</div>';
    } else {
        $isVerified = in_array($verStatus, ['APPROVED', 'ACTIVE_VERIFIED'], true);
        foreach ($notifications as $row) {
            $unreadClass = empty($row['is_read']) ? ' unread' : '';
            $title = (string)($row['title'] ?? '');
            $isConsentTitle = (stripos($title, 'Persetujuan') !== false || stripos($title, 'Consent') !== false);
            // Hanya izinkan modal-user-consent jika manualStatus === 'CONSENT_PENDING' dan belum terverifikasi
            $canOpenConsent = ($isConsentTitle && $manualStatus === 'CONSENT_PENDING' && !$isVerified);
            $isProfileChangeTitle = (stripos($title, 'Perubahan Profil') !== false || ($row['type'] ?? '') === 'PROFILE_CHANGE_SUBMITTED');
            $isProfileTitle = (stripos($title, 'Verifikasi Ulang') !== false || stripos($title, 'Perbaikan Profil') !== false || stripos($title, 'Profil Ditolak') !== false || stripos($title, 'Hak Akses Tidak Aktif') !== false);

            if ($canOpenConsent) {
                $modalAttr = ' data-open-modal="modal-user-consent" style="cursor:pointer;"';
            } elseif ($isProfileChangeTitle) {
                $modalAttr = ' data-open-modal="modal-profile-change-request" style="cursor:pointer;"';
            } elseif ($isProfileTitle || in_array($verStatus, ['INACTIVE_REVERIFICATION_REQUIRED', 'NEEDS_REVISION', 'REJECTED'], true)) {
                $modalAttr = ' data-open-modal="modal-employer-profile" style="cursor:pointer;"';
            } else {
                $modalAttr = '';
            }

            // Custom icon and styling based on notification type / title
            $iconHtml = '';
            $extraStyle = '';
            if (stripos($title, 'Disetujui') !== false) {
                $iconHtml = '<i class="fa-solid fa-circle-check" style="color:#10b981; font-size:12px; margin-right:4px;"></i> ';
                $extraStyle = 'border-left:3px solid #10b981; background:#f0fdf4;';
            } elseif (stripos($title, 'Ditolak') !== false) {
                $iconHtml = '<i class="fa-solid fa-circle-xmark" style="color:#ef4444; font-size:12px; margin-right:4px;"></i> ';
                $extraStyle = 'border-left:3px solid #ef4444; background:#fef2f2;';
            } elseif ($isProfileChangeTitle) {
                $iconHtml = '<i class="fa-solid fa-clock-rotate-left" style="color:#0284c7; font-size:12px; margin-right:4px;"></i> ';
                $extraStyle = 'border-left:3px solid #0284c7;';
            }

            $items .= '<div class="notif-item' . $unreadClass . '"' . $modalAttr . ($extraStyle ? ' style="' . $extraStyle . '"' : '') . '>';
            $items .= '<strong>' . $iconHtml . e($row['title']) . '</strong>';
            $items .= '<p>' . e($row['message']) . '</p>';
            $items .= '<span>' . e(date('d M Y H:i', strtotime((string) $row['created_at']))) . '</span>';
            $items .= '</div>';
        }
    }

    $hasActivePcr = ($activePcr && ($activePcr['status'] ?? '') === 'PENDING');
    $dot = ($unread > 0 || $hasActivePcr) ? ' has-unread' : '';
    return '<div class="notif-wrap">'
        . '<button type="button" class="notif' . $dot . '" data-notif-toggle aria-label="Notifikasi"><i class="fa-regular fa-bell"></i></button>'
        . '<div class="notif-panel" hidden><div class="notif-head" style="font-weight:700; padding:12px 16px; border-bottom:1px solid #f1f5f9; font-size:14px; color:#0f172a; display:flex; justify-content:space-between; align-items:center;"><span>Notifikasi</span>' . ($hasActivePcr ? '<span style="font-size:11px; font-weight:600; color:#0284c7;">Ada pengajuan aktif</span>' : '') . '</div>' . $items . '</div>'
        . '</div>';
}

function application_statuses(): array
{
    return [
        'Lamaran Masuk',
        'Sedang Dipelajari',
        'Wawancara',
        'Diterima',
        'Ditolak',
    ];
}

function normalize_application_status(?string $status): string
{
    $status = trim((string) $status);
    return match ($status) {
        'Dilamar', 'Applied', '' => 'Lamaran Masuk',
        'Dipelajari' => 'Sedang Dipelajari',
        default => in_array($status, application_statuses(), true) ? $status : 'Lamaran Masuk',
    };
}

function application_status_meta(string $status): array
{
    $status = normalize_application_status($status);
    return match ($status) {
        'Lamaran Masuk' => ['label' => $status, 'class' => 'in'],
        'Sedang Dipelajari' => ['label' => $status, 'class' => 'review'],
        'Wawancara' => ['label' => $status, 'class' => 'interview'],
        'Diterima' => ['label' => $status, 'class' => 'hired'],
        'Ditolak' => ['label' => $status, 'class' => 'rejected'],
        default => ['label' => $status, 'class' => 'in'],
    };
}

function employer_applicants(int $employerId): array
{
    $statement = db()->prepare('SELECT a.*, j.title AS job_title, u.name AS seeker_name, u.email AS seeker_email,
            sp.nik, sp.phone, sp.gender, sp.marital_status, sp.birth_place, sp.birth_date, sp.ktp_address, sp.domicile_address
        FROM job_applications a
        JOIN job_posts j ON j.id = a.job_id
        JOIN users u ON u.id = a.seeker_id
        LEFT JOIN seeker_profiles sp ON sp.user_id = a.seeker_id
        WHERE j.user_id = ?
        ORDER BY a.created_at DESC');
    $statement->execute([$employerId]);
    $rows = $statement->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['status'] = normalize_application_status($row['status'] ?? '');
    }
    return $rows;
}

function seeker_profile_bundle(int $seekerId): array
{
    $tables = [
        'education' => 'seeker_educations',
        'experience' => 'seeker_experiences',
        'skills' => 'seeker_skills',
        'languages' => 'seeker_languages',
        'trainings' => 'seeker_trainings',
    ];
    $bundle = [];
    foreach ($tables as $key => $table) {
        $statement = db()->prepare('SELECT * FROM ' . $table . ' WHERE user_id = ? ORDER BY id DESC');
        $statement->execute([$seekerId]);
        $bundle[$key] = $statement->fetchAll() ?: [];
    }
    return $bundle;
}

function profession_options(): array
{
    return [
        'Kuliner',
        'Jasa',
        'Teknologi Informasi',
        'Perdagangan',
        'Pertanian',
        'Konstruksi',
        'Kesehatan',
        'Pendidikan',
        'Transportasi & Logistik',
        'Hiburan & Kreatif',
        'Keuangan',
        'Lainnya',
    ];
}

function pki_close_reasons(): array
{
    return [
        'Belum menemukan kandidat yang sesuai dengan kebutuhan',
        'Jumlah pelamar yang memenuhi kualifikasi masih terbatas',
        'Kandidat yang sesuai mengundurkan diri atau membatalkan proses',
        'Kebutuhan tenaga kerja berubah atau berkurang',
        'Proses seleksi dan rekrutmen masih berlangsung',
        'Jumlah pelamar belum mencukupi',
        'Pelamar belum sesuai kompetensi/kualifikasi',
        'Pelamar mengundurkan diri',
        'Kandidat tidak hadir/tidak melanjutkan proses seleksi',
        'Kesepakatan kerja tidak tercapai',
        'Lainnya',
    ];
}

function check_pki_job_rules_engine(PDO $pdo, int $userId, string $kbjiCode, int $requestedQuota, ?int $jobId = null, bool $isRepost = false): array
{
    // LAYER 1: Active Duplicate KBJI Check (status IN ('Tayang', 'Terjadwal Tayang'))
    $stmtL1 = $pdo->prepare('SELECT id, title, status FROM job_posts WHERE user_id = ? AND kbji_code = ? AND status IN ("Tayang", "Terjadwal Tayang", "Lowongan Aktif") AND id != ? LIMIT 1');
    $stmtL1->execute([$userId, $kbjiCode, $jobId ?? 0]);
    $activeSameKbji = $stmtL1->fetch();

    if ($activeSameKbji) {
        return [
            'allowed' => false,
            'layer' => 1,
            'error_code' => 'ACTIVE_KBJI_DUPLICATE',
            'error_message' => 'Anda masih memiliki lowongan aktif yang sedang Tayang dengan kode KBJI yang sama (' . $kbjiCode . ': "' . $activeSameKbji['title'] . '"). Selesaikan atau tutup lowongan tersebut sebelum mengajukan lowongan baru dengan KBJI yang sama.',
            'additional_doc_required' => false,
            'conflict_job' => $activeSameKbji,
        ];
    }

    $startOfMonth = date('Y-m-01 00:00:00');
    $endOfMonth = date('Y-m-t 23:59:59');

    // LAYER 2: Monthly publication frequency of same-KBJI (1-3: normal, 4+: Dokumen/Keterangan Tambahan)
    // Count ALL PUBLISHED jobs this month for this KBJI (including posting ulang sisa kuota).
    // STRICTLY use published_at — no created_at fallback.
    $stmtL2 = $pdo->prepare('SELECT COUNT(*) FROM job_posts WHERE user_id = ? AND kbji_code = ? AND published_at IS NOT NULL AND published_at BETWEEN ? AND ? AND status NOT IN ("Draft", "Menunggu Verifikasi", "Dikirim/Menunggu Verifikasi", "Pending", "Perlu Direvisi", "Perlu Revisi", "Ditolak", "Dibatalkan", "CANCELED") AND id != ?');
    $stmtL2->execute([$userId, $kbjiCode, $startOfMonth, $endOfMonth, $jobId ?? 0]);
    $publishedSameKbjiCount = (int)$stmtL2->fetchColumn();

    $additionalDocRequired = ($publishedSameKbjiCount >= 3);

    // LAYER 3: Monthly total requested quota limit (max 10)
    // Sum quota of original PUBLISHED jobs in current month + new requested quota.
    // Continuation reposts do not add to monthly quota counter.
    // For 1st-3rd submissions, Layer 3 is evaluated immediately upon clicking Kirim Lowongan.
    // For 4th+ submissions, Layer 3 is evaluated when Admin clicks "Setujui & Lanjutkan".
    $stmtL3 = $pdo->prepare('SELECT COALESCE(SUM(quota), 0) FROM job_posts WHERE user_id = ? AND parent_job_id IS NULL AND published_at IS NOT NULL AND published_at BETWEEN ? AND ? AND status NOT IN ("Draft", "Menunggu Verifikasi", "Dikirim/Menunggu Verifikasi", "Pending", "Perlu Direvisi", "Perlu Revisi", "Ditolak", "Dibatalkan", "CANCELED") AND id != ?');
    $stmtL3->execute([$userId, $startOfMonth, $endOfMonth, $jobId ?? 0]);
    $currentMonthlyPublishedQuota = (int)$stmtL3->fetchColumn();

    $totalQuota = $isRepost ? $currentMonthlyPublishedQuota : ($currentMonthlyPublishedQuota + $requestedQuota);
    if (!$additionalDocRequired && !$isRepost && $totalQuota > 10) {
        return [
            'allowed' => false,
            'layer' => 3,
            'error_code' => 'MONTHLY_QUOTA_EXCEEDED',
            'error_message' => 'Batas maksimal kebutuhan tenaga kerja Pemberi Kerja Individu adalah 10 orang dalam satu bulan kalender. Total kuota yang diajukan (' . $totalQuota . ' orang) melebihi batas.',
            'additional_doc_required' => false,
            'current_monthly_quota' => $currentMonthlyPublishedQuota,
            'requested_quota' => $requestedQuota,
            'total_quota' => $totalQuota,
            'published_same_kbji_count' => $publishedSameKbjiCount,
        ];
    }

    return [
        'allowed' => true,
        'layer' => $additionalDocRequired ? 2 : 0,
        'additional_doc_required' => $additionalDocRequired,
        'published_same_kbji_count' => $publishedSameKbjiCount,
        'current_monthly_quota' => $currentMonthlyPublishedQuota,
        'requested_quota' => $requestedQuota,
        'total_quota' => $totalQuota,
        'is_repost' => $isRepost,
    ];
}

function record_audit_log(string $entityType, int $entityId, string $action, ?string $details = null, string $actorName = 'Pandu Isdiyanto, S.T., M.M', string $actorRole = 'admin', bool $strict = false): void
{
    try {
        $pdo = db();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare('INSERT INTO audit_logs (entity_type, entity_id, actor_name, actor_role, action, details, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime("now"))');
        } else {
            $stmt = $pdo->prepare('INSERT INTO audit_logs (entity_type, entity_id, actor_name, actor_role, action, details, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        }
        $stmt->execute([$entityType, $entityId, $actorName, $actorRole, $action, $details]);
    } catch (Throwable $e) {
        if ($strict) {
            throw $e;
        }
    }
}

function fetch_audit_logs(string $entityType, int $entityId): array
{
    try {
        $stmt = db()->prepare('SELECT * FROM audit_logs WHERE entity_type = ? AND entity_id = ? ORDER BY id ASC');
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function calculate_employer_consent_hash(array $p): string
{
    $fields = [
        $p['owner_name'] ?? '',
        $p['nik'] ?? '',
        $p['profession'] ?? '',
        $p['phone'] ?? '',
        $p['whatsapp'] ?? '',
        $p['npwp'] ?? '',
        $p['province'] ?? '',
        $p['city'] ?? '',
        $p['district'] ?? '',
        $p['village'] ?? '',
        $p['postal_code'] ?? '',
        $p['address'] ?? '',
        $p['address_detail'] ?? '',
        $p['description'] ?? '',
    ];
    return hash('sha256', implode('|#|', $fields));
}


function compliance_categories(): array
{
    return [
        'Data tidak lengkap',
        'Tidak sesuai substansi',
        'Tidak sesuai dengan aturan',
        'Tidak sesuai dengan aturan anti diskriminasi',
    ];
}

/**
 * Suspend an Individual Employer's access rights.
 * Allowed ONLY if profile is verified (verified = 1), active_until is present, and current lifecycle is ACTIVE (APPROVED) or TRANSITION_LIMITED.
 * Mandatory reason required; scope checked for Admin Dinas (exact domicile_city_id match without city fallback) while Admin Pusat is national.
 * Atomic transaction with SELECT FOR UPDATE and strict audit logging.
 */
function suspend_employer_access(PDO $pdo, int $targetUserId, string $reason, array $actorUser): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['success' => false, 'error' => 'Alasan Penangguhan Hak Akses Pemberi Kerja Individu WAJIB diisi.'];
    }

    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';
        $empStmt = $pdo->prepare('SELECT * FROM employer_profiles WHERE user_id = ?' . $forUpdate);
        $empStmt->execute([$targetUserId]);
        $targetEmp = $empStmt->fetch();

        if (!$targetEmp) {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Hak Akses Pemberi Kerja Individu tidak ditemukan.'];
        }

        // Scope check: Admin Dinas matches exact domicile_city_id (no fallback to city)
        $adminDomicileCity = (string)($actorUser['domicile_city_id'] ?? '');
        $role = $actorUser['role'] ?? 'admin';
        if ($role === 'admin_dinas' || ($adminDomicileCity !== '' && $role !== 'admin' && $role !== 'admin_pusat')) {
            $empDomicileCity = (string)($targetEmp['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                if (!$inTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'error' => 'Akses ditolak: Hak Akses Pemberi Kerja Individu ini di luar wilayah kewenangan Dinas Anda (' . $adminDomicileCity . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.'
                ];
            }
        }

        $currentStatus = $targetEmp['verification_status'] ?? 'NOT_SUBMITTED';
        $activeUntilRaw = $targetEmp['active_until'] ?? null;

        // Lifecycle evaluation: Check if profile is active or in transition period based on active_until
        $now = new DateTime();
        $isWithinTimeWindow = false;

        if ($activeUntilRaw) {
            $activeUntil = new DateTime($activeUntilRaw);
            if ($now <= $activeUntil) {
                $isWithinTimeWindow = true;
            } else {
                $diffSec = $now->getTimestamp() - $activeUntil->getTimestamp();
                if ($diffSec <= (7 * 86400)) {
                    $isWithinTimeWindow = true;
                }
            }
        }

        // Strict SIMULTANEOUS Eligibility:
        // 1. verified == 1
        // 2. active_until is present
        // 3. verification_status is explicitly APPROVED, ACTIVE_VERIFIED, or TRANSITION_LIMITED
        // 4. time evaluation is active or within 7-day Transition Period
        $isExplicitStatusAllowed = in_array($currentStatus, ['APPROVED', 'ACTIVE_VERIFIED', 'TRANSITION_LIMITED'], true);
        $isEligibleForSuspend = !empty($targetEmp['verified'])
            && !empty($activeUntilRaw)
            && $isExplicitStatusAllowed
            && $isWithinTimeWindow;

        if (!$isEligibleForSuspend || $currentStatus === 'SUSPENDED') {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($currentStatus === 'SUSPENDED') {
                return ['success' => false, 'error' => 'Hak Akses Pemberi Kerja Individu ini sudah dalam status Ditangguhkan (SUSPENDED).'];
            }
            return [
                'success' => false,
                'error' => 'Penangguhan Hak Akses Pemberi Kerja Individu hanya dapat dilakukan pada Hak Akses Pemberi Kerja Individu yang telah terverifikasi dengan masa aktif yang valid (berstatus Aktif atau Masa Transisi).'
            ];
        }

        $stmt = $pdo->prepare('UPDATE employer_profiles SET verification_status = "SUSPENDED", suspension_reason = ? WHERE user_id = ?');
        $stmt->execute([$reason, $targetUserId]);

        record_audit_log(
            'employer',
            $targetUserId,
            'SUSPENDED',
            "Hak Akses Pemberi Kerja Individu ditangguhkan. Alasan: {$reason}",
            $actorUser['name'] ?? 'Admin',
            $actorUser['role'] ?? 'admin',
            true // strict mode: throws exception if audit log insert fails, triggering transaction rollback
        );

        if (!$inTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return ['success' => true, 'message' => 'Hak Akses Pemberi Kerja Individu berhasil ditangguhkan.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Gagal menangguhkan Hak Akses Pemberi Kerja Individu: ' . $e->getMessage()];
    }
}

/**
 * Unsuspend an Individual Employer's access rights with active_until recalculation.
 * Scope checked for Admin Dinas (exact domicile_city_id match without city fallback) while Admin Pusat is national.
 * Atomic transaction with SELECT FOR UPDATE and strict audit logging.
 * Status recalculated based on active_until:
 * - active_until valid (now <= active_until) -> APPROVED
 * - active_until passed, but <= 7 days ago (Masa Transisi) -> TRANSITION_LIMITED
 * - active_until passed > 7 days ago -> FULL_DISABLED
 * - active_until empty/null -> REJECTED with safe error message (never grant active access without active_until)
 */
function unsuspend_employer_access(PDO $pdo, int $targetUserId, array $actorUser, ?string $refTime = null): array
{
    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';
        $empStmt = $pdo->prepare('SELECT * FROM employer_profiles WHERE user_id = ?' . $forUpdate);
        $empStmt->execute([$targetUserId]);
        $targetEmp = $empStmt->fetch();

        if (!$targetEmp) {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Hak Akses Pemberi Kerja Individu tidak ditemukan.'];
        }

        // State Check: Must currently be SUSPENDED
        if (($targetEmp['verification_status'] ?? '') !== 'SUSPENDED') {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Pembatalan penangguhan hanya dapat dilakukan jika Hak Akses Pemberi Kerja Individu berstatus SUSPENDED.'];
        }

        // Scope check: Admin Dinas matches exact domicile_city_id (no fallback to city)
        $adminDomicileCity = (string)($actorUser['domicile_city_id'] ?? '');
        $role = $actorUser['role'] ?? 'admin';
        if ($role === 'admin_dinas' || ($adminDomicileCity !== '' && $role !== 'admin' && $role !== 'admin_pusat')) {
            $empDomicileCity = (string)($targetEmp['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                if (!$inTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'error' => 'Akses ditolak: Hak Akses Pemberi Kerja Individu ini di luar wilayah kewenangan Dinas Anda (' . $adminDomicileCity . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.'
                ];
            }
        }

        $activeUntilRaw = $targetEmp['active_until'] ?? null;

        // If active_until is empty/null, reject unsuspend safely
        if (empty($activeUntilRaw)) {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Pembatalan penangguhan tidak dapat dilakukan karena masa berlaku Hak Akses Pemberi Kerja Individu (active_until) tidak dapat ditentukan.'
            ];
        }

        $now = $refTime ? new DateTime($refTime) : new DateTime();
        $activeUntil = new DateTime($activeUntilRaw);
        if ($now <= $activeUntil) {
            $newStatus = 'APPROVED';
        } else {
            $nowTs = $now->getTimestamp();
            $actUntilTs = $activeUntil->getTimestamp();
            $diffSec = $nowTs - $actUntilTs;
            if ($diffSec <= (7 * 86400)) {
                $newStatus = 'TRANSITION_LIMITED';
            } else {
                $newStatus = 'FULL_DISABLED';
            }
        }

        $stmt = $pdo->prepare('UPDATE employer_profiles SET verification_status = ?, suspension_reason = NULL WHERE user_id = ?');
        $stmt->execute([$newStatus, $targetUserId]);

        record_audit_log(
            'employer',
            $targetUserId,
            'UNSUSPENDED',
            "Penangguhan Hak Akses Pemberi Kerja Individu dibatalkan. Status dikembalikan ke {$newStatus} berdasarkan masa aktif (active_until: {$activeUntilRaw}).",
            $actorUser['name'] ?? 'Admin',
            $actorUser['role'] ?? 'admin',
            true // strict mode: throws exception if audit log insert fails, triggering transaction rollback
        );

        if (!$inTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'new_status' => $newStatus,
            'message' => "Penangguhan Hak Akses Pemberi Kerja Individu berhasil dibatalkan. Status dikembalikan ke {$newStatus}."
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Gagal membatalkan penangguhan Hak Akses Pemberi Kerja Individu: ' . $e->getMessage()];
    }
}

/**
 * Format date string into Indonesian formatted date.
 * E.g. '2026-09-22' -> '22 September 2026'
 */
function format_indo_date($dateRaw, string $format = 'long'): string
{
    if (empty($dateRaw)) {
        return '-';
    }
    try {
        if ($dateRaw instanceof DateTimeInterface) {
            $d = $dateRaw;
        } else {
            $ts = is_numeric($dateRaw) ? (int)$dateRaw : strtotime((string)$dateRaw);
            if ($ts !== false && $ts > 0) {
                $d = (new DateTime())->setTimestamp($ts);
            } else {
                $d = new DateTime((string)$dateRaw);
            }
        }
        $monthsLong = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $monthsShort = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];
        $monthNum = (int)$d->format('n');
        if ($format === 'short') {
            return $d->format('j') . ' ' . ($monthsShort[$monthNum] ?? '') . ' ' . $d->format('Y');
        }
        if ($format === 'day_month') {
            return $d->format('j') . ' ' . ($monthsShort[$monthNum] ?? '');
        }
        return $d->format('j') . ' ' . ($monthsLong[$monthNum] ?? '') . ' ' . $d->format('Y');
    } catch (Throwable $e) {
        return '-';
    }
}

/**
 * Format date range into Indonesian cycle format.
 * E.g. ('2026-09-21', '2026-12-21') -> '21 Sep – 21 Des 2026'
 */
function format_cycle_range($startDateRaw, $endDateRaw): string
{
    if (empty($startDateRaw) || empty($endDateRaw)) {
        return '-';
    }
    try {
        $start = ($startDateRaw instanceof DateTimeInterface) ? $startDateRaw : new DateTime((string)$startDateRaw);
        $end = ($endDateRaw instanceof DateTimeInterface) ? $endDateRaw : new DateTime((string)$endDateRaw);
        $months = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];
        $sDay = $start->format('j');
        $sMonth = $months[(int)$start->format('n')];
        $sYear = $start->format('Y');

        $eDay = $end->format('j');
        $eMonth = $months[(int)$end->format('n')];
        $eYear = $end->format('Y');

        if ($sYear === $eYear) {
            return "{$sDay} {$sMonth} – {$eDay} {$eMonth} {$eYear}";
        }
        return "{$sDay} {$sMonth} {$sYear} – {$eDay} {$eMonth} {$eYear}";
    } catch (Exception $e) {
        return '-';
    }
}

/**
 * Resolve effective lifecycle status for an individual employer.
 */
function get_employer_access_status(array $profile, ?string $refTime = null): array
{
    $verStatus = $profile['verification_status'] ?? 'NOT_SUBMITTED';
    $activeUntilRaw = $profile['active_until'] ?? null;
    $hasLastActivated = !empty($profile['last_activated_at']);
    $now = $refTime ? new DateTime($refTime) : new DateTime();

    if ($verStatus === 'SUSPENDED') {
        return [
            'status' => 'SUSPENDED',
            'label' => 'Ditangguhkan',
            'badge_class' => 'suspended',
            'is_active' => false,
            'is_disabled' => false,
            'is_transition' => false,
            'is_pending' => false,
            'is_online_reactivation_pending' => false,
            'can_direct_reactivate' => false,
        ];
    }

    if ($verStatus === 'PENDING') {
        $isOnlinePending = $hasLastActivated;
        return [
            'status' => 'PENDING',
            'label' => $isOnlinePending ? 'Menunggu Verifikasi (Reaktivasi Online)' : 'Menunggu Verifikasi',
            'badge_class' => 'pending',
            'is_active' => false,
            'is_disabled' => false,
            'is_transition' => false,
            'is_pending' => true,
            'is_online_reactivation_pending' => $isOnlinePending,
            'can_direct_reactivate' => false,
        ];
    }

    if ($verStatus === 'NEEDS_REVISION') {
        return [
            'status' => 'NEEDS_REVISION',
            'label' => 'Perlu Diperbaiki',
            'badge_class' => 'revision',
            'is_active' => false,
            'is_disabled' => false,
            'is_transition' => false,
            'is_pending' => false,
            'is_online_reactivation_pending' => false,
            'can_direct_reactivate' => false,
        ];
    }

    if (in_array($verStatus, ['APPROVED', 'ACTIVE_VERIFIED', 'TRANSITION_LIMITED', 'FULL_DISABLED'], true)) {
        if ($activeUntilRaw) {
            $actUntil = new DateTime($activeUntilRaw);
            if ($now <= $actUntil) {
                return [
                    'status' => 'ACTIVE',
                    'label' => 'Aktif',
                    'badge_class' => 'verified',
                    'is_active' => true,
                    'is_disabled' => false,
                    'is_transition' => false,
                    'is_pending' => false,
                    'is_online_reactivation_pending' => false,
                    'can_direct_reactivate' => false,
                ];
            }
            $diffSec = $now->getTimestamp() - $actUntil->getTimestamp();
            if ($diffSec <= (7 * 86400)) {
                return [
                    'status' => 'TRANSITION_LIMITED',
                    'label' => 'Masa Transisi',
                    'badge_class' => 'warning',
                    'is_active' => false,
                    'is_disabled' => false,
                    'is_transition' => true,
                    'is_pending' => false,
                    'is_online_reactivation_pending' => false,
                    'can_direct_reactivate' => false,
                ];
            }
            // Expired past 7 days -> FULL_DISABLED (Tidak Aktif)
            return [
                'status' => 'FULL_DISABLED',
                'label' => 'Tidak Aktif',
                'badge_class' => 'disabled',
                'is_active' => false,
                'is_disabled' => true,
                'is_transition' => false,
                'is_pending' => false,
                'is_online_reactivation_pending' => false,
                'can_direct_reactivate' => true,
            ];
        }
        return [
            'status' => 'ACTIVE',
            'label' => 'Aktif',
            'badge_class' => 'verified',
            'is_active' => true,
            'is_disabled' => false,
            'is_transition' => false,
            'is_pending' => false,
            'is_online_reactivation_pending' => false,
            'can_direct_reactivate' => false,
        ];
    }

    return [
        'status' => 'NOT_SUBMITTED',
        'label' => 'Belum Mengajukan',
        'badge_class' => 'neutral',
        'is_active' => false,
        'is_disabled' => false,
        'is_transition' => false,
        'is_pending' => false,
        'is_online_reactivation_pending' => false,
        'can_direct_reactivate' => false,
    ];
}

/**
 * Direct Reactivation of Individual Employer Access by Admin Dinas.
 * - Atomic transaction with SELECT FOR UPDATE
 * - Scope check: admin_dinas.domicile_city_id === employer.domicile_city_id exact (no city string fallback)
 * - State check: Must be FULL_DISABLED (Tidak Aktif). Not allowed if ACTIVE, TRANSITION_LIMITED, SUSPENDED, or PENDING.
 * - Concurrency protection: If status is already ACTIVE, rejects second request.
 * - Collision protection: If online reactivation is PENDING, rejects with clear error message.
 * - Directly transitions to ACTIVE / APPROVED with new 6-month cycle (no secondary verification case or approval).
 * - Sets last_activated_at = now, active_until = now + 6 months.
 * - Strict audit logging: action = 'REACTIVATE_EMPLOYER_ACCESS', source = 'ADMIN_DINAS'.
 */
function reactivate_employer_access_by_admin_dinas(PDO $pdo, int $targetUserId, array $actorUser, ?string $refTime = null): array
{
    $inTx = $pdo->inTransaction();
    if (!$inTx) {
        $pdo->beginTransaction();
    }

    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockSql = $driver === 'sqlite' ? 'SELECT * FROM employer_profiles WHERE user_id = ?' : 'SELECT * FROM employer_profiles WHERE user_id = ? FOR UPDATE';
        $empStmt = $pdo->prepare($lockSql);
        $empStmt->execute([$targetUserId]);
        $targetEmp = $empStmt->fetch();

        if (!$targetEmp) {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => 'Hak Akses Pemberi Kerja Individu tidak ditemukan.'];
        }

        // Scope check: Admin Dinas matches exact domicile_city_id (no fallback to city string)
        $adminDomicileCity = (string)($actorUser['domicile_city_id'] ?? '');
        $role = $actorUser['role'] ?? 'admin';
        if ($role === 'admin_dinas' || ($adminDomicileCity !== '' && $role !== 'admin' && $role !== 'admin_pusat')) {
            $empDomicileCity = (string)($targetEmp['domicile_city_id'] ?? '');
            if ($empDomicileCity === '' || $empDomicileCity !== $adminDomicileCity) {
                if (!$inTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return [
                    'success' => false,
                    'error' => 'Akses ditolak: Hak Akses Pemberi Kerja Individu ini di luar wilayah kewenangan Dinas Anda (' . $adminDomicileCity . '). Scope Admin Dinas mengikuti domicile_city_id Pemberi Kerja secara persis.'
                ];
            }
        }

        // State check: Resolve current effective status
        $statusInfo = get_employer_access_status($targetEmp, $refTime);
        $currentStatus = $statusInfo['status'];

        // Collision Protection: If online reactivation is PENDING
        if ($statusInfo['is_pending']) {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Reaktivasi Hak Akses ditolak: Permohonan reaktivasi online sedang dalam proses verifikasi.'
            ];
        }

        // Concurrency / State Protection: If already ACTIVE
        if ($statusInfo['is_active']) {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Reaktivasi Hak Akses gagal: Hak Akses Pemberi Kerja Individu sudah dalam status Aktif.'
            ];
        }

        // Suspended or Transition Protection
        if ($statusInfo['status'] === 'SUSPENDED') {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Reaktivasi Hak Akses gagal: Hak Akses Pemberi Kerja Individu sedang dalam status Ditangguhkan (SUSPENDED).'
            ];
        }
        if ($statusInfo['is_transition']) {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Reaktivasi Hak Akses gagal: Hak Akses Pemberi Kerja Individu masih berada dalam Masa Transisi.'
            ];
        }

        if ($statusInfo['status'] !== 'FULL_DISABLED') {
            if (!$inTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'error' => 'Reaktivasi Hak Akses hanya dapat dilakukan untuk Hak Akses yang telah berakhir (FULL_DISABLED).'
            ];
        }

        // Apply mutation: directly active with new 6-month cycle
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = datetime("now", "+6 months"), last_activated_at = datetime("now"), extension_requested = 0, extension_status = "NONE", manual_review_status = NULL, suspension_reason = NULL, assigned_to = NULL, assigned_at = NULL WHERE user_id = ?');
        } else {
            $stmt = $pdo->prepare('UPDATE employer_profiles SET verified = 1, verification_status = "APPROVED", active_until = DATE_ADD(NOW(), INTERVAL 6 MONTH), last_activated_at = NOW(), extension_requested = 0, extension_status = "NONE", manual_review_status = NULL, suspension_reason = NULL, assigned_to = NULL, assigned_at = NULL WHERE user_id = ?');
        }
        $stmt->execute([$targetUserId]);
        $pdo->prepare('UPDATE users SET profile_complete = 1 WHERE id = ?')->execute([$targetUserId]);

        $nowFormatted = format_indo_date(date('Y-m-d'));
        $newActiveUntilFormatted = format_indo_date(date('Y-m-d', strtotime('+6 months')));
        $prevActiveUntil = $targetEmp['active_until'] ? date('d M Y', strtotime($targetEmp['active_until'])) : '-';

        $auditDetails = "Hak Akses Pemberi Kerja Individu direaktivasi langsung oleh Admin Dinas. Previous Status: {$currentStatus}, New Status: ACTIVE, Previous Active Until: {$prevActiveUntil}, New Activated At: " . date('Y-m-d H:i:s') . ", New Active Until: " . date('Y-m-d H:i:s', strtotime('+6 months')) . " | Source: ADMIN_DINAS";
        record_audit_log('employer', $targetUserId, 'REACTIVATE_EMPLOYER_ACCESS', $auditDetails, $actorUser['name'] ?? 'Admin Dinas', $actorUser['role'] ?? 'admin_dinas', true);

        notify_user($targetUserId, 'Hak Akses Diaktifkan Kembali', 'Hak Akses Pemberi Kerja Individu Anda telah diaktifkan kembali oleh Dinas Tenaga Kerja selama 6 bulan.', 'success');

        if (!$inTx && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'success' => true,
            'activated_at' => $nowFormatted,
            'active_until' => $newActiveUntilFormatted,
            'employer_name' => $targetEmp['owner_name'] ?: 'Pemberi Kerja',
            'message' => 'Hak Akses berhasil direaktivasi. Hak Akses Pemberi Kerja Individu telah langsung aktif kembali.'
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Gagal mereaktivasi Hak Akses: ' . $e->getMessage()];
    }
}

/**
 * Ensure database tables for Profile Change Requests and Profile Versioning
 */
function ensure_profile_change_schema(?PDO $pdo = null): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    if ($pdo === null) {
        $pdo = db();
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS profile_change_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            sections TEXT NOT NULL,
            reason TEXT NOT NULL,
            proposed_data TEXT NOT NULL,
            current_data TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "PENDING",
            admin_notes TEXT,
            reviewed_by TEXT,
            reviewed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS profile_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            request_id INTEGER,
            version_num INTEGER NOT NULL DEFAULT 1,
            snapshot_data TEXT NOT NULL,
            change_summary TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS profile_change_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sections TEXT NOT NULL,
            reason TEXT NOT NULL,
            proposed_data TEXT NOT NULL,
            current_data TEXT NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT "PENDING",
            admin_notes TEXT NULL,
            reviewed_by VARCHAR(120) NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pcr_user (user_id),
            INDEX idx_pcr_status (status)
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS profile_versions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_id INT NULL,
            version_num INT NOT NULL DEFAULT 1,
            snapshot_data TEXT NOT NULL,
            change_summary TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pv_user (user_id)
        )');
    }
}

/**
 * Get active (PENDING) profile change request for a specific user
 */
function get_active_profile_change_request(int $userId): ?array
{
    ensure_profile_change_schema();
    $stmt = db()->prepare('SELECT * FROM profile_change_requests WHERE user_id = ? AND status = "PENDING" ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get latest profile change request (PENDING, APPROVED, or REJECTED)
 */
function get_latest_profile_change_request(int $userId): ?array
{
    ensure_profile_change_schema();
    $stmt = db()->prepare('SELECT * FROM profile_change_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Get all profile versions for audit trail
 */
function get_profile_versions(int $userId): array
{
    ensure_profile_change_schema();
    $stmt = db()->prepare('SELECT * FROM profile_versions WHERE user_id = ? ORDER BY version_num DESC, id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Create a new Profile Change Request
 */
function create_profile_change_request(int $userId, array $sections, string $reason, array $proposedData, array $currentData): int
{
    ensure_profile_change_schema();
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO profile_change_requests 
        (user_id, sections, reason, proposed_data, current_data, status, created_at)
        VALUES (?, ?, ?, ?, ?, "PENDING", ' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'datetime("now")' : 'NOW()') . ')');

    $stmt->execute([
        $userId,
        json_encode($sections, JSON_UNESCAPED_UNICODE),
        $reason,
        json_encode($proposedData, JSON_UNESCAPED_UNICODE),
        json_encode($currentData, JSON_UNESCAPED_UNICODE)
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Approve a Profile Change Request, apply changes to active profile, and record version snapshot
 */
function approve_profile_change_request(int $requestId, string $reviewerName, ?string $adminNotes = null): array
{
    ensure_profile_change_schema();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM profile_change_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();

    if (!$request) {
        return ['success' => false, 'error' => 'Permintaan perubahan profil tidak ditemukan.'];
    }
    if ($request['status'] !== 'PENDING') {
        return ['success' => false, 'error' => 'Permintaan perubahan ini sudah diproses sebelumnya (Status: ' . $request['status'] . ').'];
    }

    $userId = (int)$request['user_id'];
    $proposedData = json_decode($request['proposed_data'] ?? '{}', true) ?: [];
    $currentData = json_decode($request['current_data'] ?? '{}', true) ?: [];
    $sections = json_decode($request['sections'] ?? '[]', true) ?: [];

    // Map section IDs to human-readable names
    $sectionLabelsMap = [
        'identity' => 'Identitas Perorangan (Nomor WhatsApp)',
        'workplace' => 'Tempat Usaha / Kegiatan & Deskripsi Usaha',
        'documents' => 'Dokumen & Bukti Tempat Usaha',
        'social' => 'Media Sosial'
    ];
    $sectionNames = array_map(fn($s) => $sectionLabelsMap[$s] ?? $s, $sections);

    $pdo->beginTransaction();
    try {
        // 1. Ensure initial version (Version 1) is archived if no version exists yet
        $checkVerStmt = $pdo->prepare('SELECT COUNT(*) FROM profile_versions WHERE user_id = ?');
        $checkVerStmt->execute([$userId]);
        $existingVersionCount = (int)$checkVerStmt->fetchColumn();

        if ($existingVersionCount === 0) {
            $stmtInitVer = $pdo->prepare('INSERT INTO profile_versions (user_id, request_id, version_num, snapshot_data, change_summary, created_at) VALUES (?, NULL, 1, ?, "Profil Awal Terverifikasi", ' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'datetime("now")' : 'NOW()') . ')');
            $stmtInitVer->execute([$userId, json_encode($currentData, JSON_UNESCAPED_UNICODE)]);
            $nextVersionNum = 2;
        } else {
            $maxVerStmt = $pdo->prepare('SELECT MAX(version_num) FROM profile_versions WHERE user_id = ?');
            $maxVerStmt->execute([$userId]);
            $nextVersionNum = ((int)$maxVerStmt->fetchColumn()) + 1;
        }

        // 2. Build UPDATE statement dynamically for proposed fields
        $allowedFields = [
            'whatsapp', 'profession',
            'brand_name', 'business_logo', 'description',
            'province', 'city', 'district', 'village', 'postal_code',
            'address', 'address_detail', 'latitude', 'longitude', 'same_location_siapkerja',
            'permit_document', 'doc_permission', 'workplace_photo', 'doc_location_photo',
            'instagram', 'linkedin', 'facebook', 'tiktok'
        ];

        $updateParts = [];
        $updateVals = [];
        foreach ($proposedData as $k => $v) {
            if (in_array($k, $allowedFields, true)) {
                $updateParts[] = "{$k} = ?";
                $updateVals[] = $v;
            }
        }

        if (!empty($updateParts)) {
            $updateParts[] = "updated_at = " . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'datetime("now")' : 'NOW()');
            $updateVals[] = $userId;
            $sql = "UPDATE employer_profiles SET " . implode(', ', $updateParts) . " WHERE user_id = ?";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($updateVals);
        }

        // 3. Fetch newly updated profile for new version snapshot
        $stmtNewProf = $pdo->prepare('SELECT * FROM employer_profiles WHERE user_id = ? LIMIT 1');
        $stmtNewProf->execute([$userId]);
        $newProfileData = $stmtNewProf->fetch() ?: [];

        // 4. Record new version snapshot
        $changeSummary = 'Perubahan profil disetujui: ' . implode(', ', $sectionNames);
        if ($adminNotes) {
            $changeSummary .= ' | Catatan Admin: ' . $adminNotes;
        }
        $stmtNewVer = $pdo->prepare('INSERT INTO profile_versions (user_id, request_id, version_num, snapshot_data, change_summary, created_at) VALUES (?, ?, ?, ?, ?, ' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'datetime("now")' : 'NOW()') . ')');
        $stmtNewVer->execute([
            $userId,
            $requestId,
            $nextVersionNum,
            json_encode($newProfileData, JSON_UNESCAPED_UNICODE),
            $changeSummary
        ]);

        // 5. Update profile change request status
        $nowStr = date('Y-m-d H:i:s');
        $stmtReqUpdate = $pdo->prepare('UPDATE profile_change_requests SET status = "APPROVED", reviewed_by = ?, reviewed_at = ?, admin_notes = ?, updated_at = ? WHERE id = ?');
        $stmtReqUpdate->execute([$reviewerName, $nowStr, $adminNotes, $nowStr, $requestId]);

        // 6. Record audit log
        record_audit_log('employer', $userId, 'PROFILE_CHANGE_APPROVED', "Permintaan perubahan profil disetujui oleh {$reviewerName}. Bagian diubah: " . implode(', ', $sectionNames) . ". Riwayat versi profil tersimpan (Versi {$nextVersionNum}).", $reviewerName, 'admin', true);

        // 7. Notify employer user
        notify_user($userId, 'Perubahan Profil Disetujui', 'Permintaan perubahan data profil Anda telah disetujui oleh Admin dan data profil aktif telah diperbarui.', 'success');

        $pdo->commit();
        return ['success' => true, 'version' => $nextVersionNum];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Gagal menyetujui perubahan profil: ' . $e->getMessage()];
    }
}

/**
 * Reject a Profile Change Request, keeping old profile data intact
 */
function reject_profile_change_request(int $requestId, string $reviewerName, string $adminNotes): array
{
    ensure_profile_change_schema();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM profile_change_requests WHERE id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();

    if (!$request) {
        return ['success' => false, 'error' => 'Permintaan perubahan profil tidak ditemukan.'];
    }
    if ($request['status'] !== 'PENDING') {
        return ['success' => false, 'error' => 'Permintaan perubahan ini sudah diproses sebelumnya (Status: ' . $request['status'] . ').'];
    }

    $userId = (int)$request['user_id'];
    $sections = json_decode($request['sections'] ?? '[]', true) ?: [];
    $sectionLabelsMap = [
        'identity' => 'Identitas Perorangan (Nomor WhatsApp)',
        'workplace' => 'Tempat Usaha / Kegiatan & Deskripsi Usaha',
        'documents' => 'Dokumen & Bukti Tempat Usaha',
        'social' => 'Media Sosial'
    ];
    $sectionNames = array_map(fn($s) => $sectionLabelsMap[$s] ?? $s, $sections);

    $nowStr = date('Y-m-d H:i:s');
    $stmtReqUpdate = $pdo->prepare('UPDATE profile_change_requests SET status = "REJECTED", reviewed_by = ?, reviewed_at = ?, admin_notes = ?, updated_at = ? WHERE id = ?');
    $stmtReqUpdate->execute([$reviewerName, $nowStr, $adminNotes, $nowStr, $requestId]);

    // Record audit log
    record_audit_log('employer', $userId, 'PROFILE_CHANGE_REJECTED', "Permintaan perubahan profil ditolak oleh {$reviewerName}. Alasan: {$adminNotes}", $reviewerName, 'admin', true);

    // Notify user with rejection reason
    notify_user($userId, 'Perubahan Profil Ditolak', 'Permintaan perubahan profil Anda belum dapat disetujui oleh Admin. Alasan: ' . $adminNotes, 'error');

    return ['success' => true];
}

