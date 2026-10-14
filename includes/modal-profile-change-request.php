<?php
/**
 * Modal Permintaan Perubahan Profil Pemberi Kerja Individu
 * Ditampilkan saat Profil Terverifikasi ingin mengajukan perubahan data.
 * Tampilan field mengikuti Form Daftar Sebagai Pemberi Kerja dengan data terisi otomatis.
 */
$showPcrModal = isset($_GET['open_pcr']) && $_GET['open_pcr'] == '1';
$pcrCurrentData = $profile ?? [];

// Helper values pre-filled dari data profil yang tersimpan
$pcrOwnerName = e($pcrCurrentData['owner_name'] ?? ($user['name'] ?? ''));
$pcrNik = e($pcrCurrentData['nik'] ?? '');
$pcrPhone = e($pcrCurrentData['phone'] ?? '');
$pcrWhatsapp = e($pcrCurrentData['whatsapp'] ?? '');
$pcrProfession = $pcrCurrentData['profession'] ?? '';
$pcrNpwp = e($pcrCurrentData['npwp'] ?? '');
$pcrNpwpStatus = !empty($pcrCurrentData['npwp_status']) || !empty($pcrCurrentData['npwp']);

$pcrBrandName = e($pcrCurrentData['brand_name'] ?? '');
$pcrLogo = $pcrCurrentData['business_logo'] ?? ($pcrCurrentData['brand_logo'] ?? '');
$pcrDescription = e($pcrCurrentData['description'] ?? '');

$pcrProvince = e($pcrCurrentData['province'] ?? '');
$pcrCity = e($pcrCurrentData['city'] ?? '');
$pcrDistrict = e($pcrCurrentData['district'] ?? '');
$pcrVillage = e($pcrCurrentData['village'] ?? '');
$pcrPostalCode = e($pcrCurrentData['postal_code'] ?? '');
$pcrAddress = e($pcrCurrentData['address'] ?? '');
$pcrAddressDetail = e($pcrCurrentData['address_detail'] ?? ($pcrCurrentData['address_notes'] ?? ''));

$pcrPermitDoc = $pcrCurrentData['permit_document'] ?? ($pcrCurrentData['doc_permission'] ?? '');
$pcrPhoto = $pcrCurrentData['workplace_photo'] ?? ($pcrCurrentData['doc_location_photo'] ?? '');

$pcrLinkedin = e($pcrCurrentData['linkedin'] ?? '');
$pcrFacebook = e($pcrCurrentData['facebook'] ?? '');
$pcrInstagram = e($pcrCurrentData['instagram'] ?? '');
$pcrTiktok = e($pcrCurrentData['tiktok'] ?? '');

// Helper hitung ukuran berkas
function pcr_format_filesize(?string $relativePath): string {
    if (empty($relativePath)) return '';
    $baseDir = realpath(__DIR__ . '/..');
    $cleanRel = ltrim(str_replace('\\', '/', (string)$relativePath), '/');
    $abs = realpath($baseDir . '/' . $cleanRel);
    if ($baseDir && $abs && str_starts_with($abs, $baseDir) && is_file($abs)) {
        $bytes = (int)filesize($abs);
        if ($bytes > 0) {
            return $bytes >= 1024 * 1024
                ? number_format($bytes / (1024 * 1024), 2) . ' MB'
                : number_format($bytes / 1024, 2) . ' KB';
        }
    }
    return '';
}

$pcrLogoSizeText = pcr_format_filesize($pcrLogo);
$pcrPermitDocSizeText = pcr_format_filesize($pcrPermitDoc);
$pcrPhotoSizeText = pcr_format_filesize($pcrPhoto);

$pcrProfessionGroups = [
    'Kesehatan & Terapi' => [
        'Praktik Dokter Umum', 'Praktik Dokter Gigi', 'Praktik Bidan', 'Praktik Keperawatan Mandiri',
        'Praktik Psikolog', 'Layanan Fisioterapi', 'Praktik Terapis Wicara', 'Layanan Akupunktur',
        'Layanan Terapi Tumbuh Kembang Anak', 'Jasa Pijat dan Refleksi', 'Layanan Terapi Okupasi', 'Layanan Konsultasi Gizi/Nutrisionis',
    ],
    'Hukum, Keuangan & Jasa Profesional' => [
        'Praktik Notaris', 'Praktik PPAT', 'Praktik Advokat/Pengacara', 'Konsultan Hukum',
        'Konsultan Pajak', 'Jasa Akuntansi', 'Jasa Pembukuan', 'Konsultan Manajemen',
        'Jasa Administrasi dan Pengurusan Dokumen', 'Jasa Penerjemah', 'Konsultan SDM', 'Jasa Sekretarial/Administrasi Perkantoran',
    ],
    'Teknik, Arsitektur & Desain' => [
        'Praktik Arsitek', 'Konsultan Teknik Sipil', 'Konsultan Mekanikal dan Elektrikal',
        'Jasa Desain Interior', 'Jasa Desain Lanskap', 'Jasa Drafter', 'Jasa Survei dan Pemetaan',
        'Jasa Desain Produk', 'Jasa Pembuatan Maket dan Prototipe', 'Jasa Perbaikan/Bengkel Teknik',
        'Jasa Instalasi Listrik', 'Jasa Teknisi Elektronik',
    ],
    'Pendidikan & Pelatihan' => [
        'Bimbingan Belajar', 'Guru Privat', 'Kursus Bahasa', 'Kursus Musik', 'Kursus Tari',
        'Kursus Seni Rupa', 'Pelatihan Public Speaking', 'Pelatihan Komputer dan Digital',
        'Pelatihan Keterampilan Vokasional', 'Pelatih Olahraga/Kebugaran', 'Pelatihan Kewirausahaan', 'Pelatihan Kerja/Keterampilan Profesi',
    ],
    'Jasa Acara, Kreatif & Hewan Peliharaan' => [
        'Studio Foto dan Video', 'Jasa Fotografi/Videografi', 'Jasa Dekorasi Acara', 'Wedding Organizer',
        'Event Organizer', 'Jasa Penjahit/Atelier', 'Jasa Grooming Hewan', 'Jasa Penitipan Hewan',
        'Jasa Percetakan', 'Jasa Desain Grafis', 'Jasa Pembuatan Suvenir/Handicraft', 'Jasa Florist/Dekorasi Bunga',
    ],
    'Digital, Media & Konten' => [
        'Konten Kreator/Digital Creator', 'Jasa Pengelolaan Media Sosial', 'Jasa Pembuatan Konten Digital',
        'Jasa Editing Video', 'Jasa Copywriting/Content Writing', 'Jasa Desain Konten Media Sosial',
        'Jasa Live Streaming', 'Studio Podcast', 'Jasa Produksi Audio/Video', 'Jasa Digital Marketing',
    ],
    'Kuliner & Industri Pangan Rumah Tangga' => [
        'Usaha Makanan Rumahan', 'Jasa Katering', 'Bakery dan Pastry Rumahan', 'Usaha Kue dan Makanan Ringan',
        'Produksi Minuman Rumahan', 'Produksi Makanan Olahan', 'Produksi Makanan Beku/Frozen Food',
        'Produksi Sambal/Bumbu Olahan', 'Produksi Camilan/Keripik', 'Usaha Jajanan/Pangan Siap Saji',
    ],
    'Kecantikan & Perawatan Pribadi' => [
        'Salon Kecantikan', 'Barbershop/Pangkas Rambut', 'Makeup Artist', 'Jasa Tata Rambut',
        'Nail Art', 'Jasa Perawatan Kecantikan Nonmedis', 'Jasa Perawatan Pengantin',
    ],
    'Jasa Rumah Tangga, Pemeliharaan & Perbaikan' => [
        'Jasa Kebersihan Rumah', 'Jasa Laundry', 'Jasa Servis AC', 'Jasa Perbaikan Peralatan Rumah Tangga',
        'Jasa Instalasi/Perbaikan Plumbing', 'Jasa Perawatan Taman', 'Jasa Pengecatan', 'Jasa Pertukangan',
    ],
];

$infoIconSvg = '<svg class="tooltip-icon" width="13" height="13" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true" style="display:inline-block; vertical-align:-1px; cursor:pointer;"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>';
?>

<div class="modal-backdrop <?php echo $showPcrModal ? 'open' : ''; ?>" data-modal="modal-profile-change-request" id="modal-profile-change-request" style="z-index: 1050;">
    <div class="modal-panel" style="width: min(780px, 95vw); max-height: 90vh; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; background: #ffffff; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        
        <!-- MODAL HEADER -->
        <div class="modal-header" style="background:#ffffff; padding: 22px 28px 18px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <h2 style="font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0; line-height: 1.3;">Permintaan Perubahan Profil</h2>
                <div style="font-size: 13.5px; color: #64748b; line-height: 1.45;">Ajukan perubahan data profil yang telah terverifikasi. Perubahan akan diperiksa oleh Admin sebelum diterapkan.</div>
            </div>
            <button type="button" class="ghost-btn" data-close-modal="modal-profile-change-request" style="width: 34px; height: 34px; border-radius: 50%; background: #f8fafc; border: 1px solid #e2e8f0; color: #64748b; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 15px; padding: 0; margin-left: 12px; transition: all 0.2s;" aria-label="Tutup Modal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <!-- FORM BODY -->
        <form method="post" action="dashboard.php" enctype="multipart/form-data" id="formProfileChangeRequest" style="display:flex; flex-direction:column; flex:1; min-height:0; overflow:hidden; margin:0;">
            <input type="hidden" name="submit_profile_change_request" value="1">
            
            <div class="modal-body" style="padding: 24px 28px; flex: 1; overflow-y: auto; -webkit-overflow-scrolling: touch;">

                <!-- 1. BAGIAN PROFIL YANG DIUBAH (CHECKBOX SESUAI GAMBAR) -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">
                        Bagian Profil yang Diubah <span class="req" style="color: #ef4444;">*</span>
                    </label>
                    <div style="font-size: 12.5px; color: #64748b; margin-bottom: 12px;">Pilih bagian profil yang ingin Anda ubah.</div>

                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <!-- Option 1: Identitas Perorangan -->
                        <label style="display: inline-flex; align-items: center; gap: 10px; font-size: 13.5px; font-weight: 600; color: #1e293b; cursor: pointer; user-select: none;">
                            <input type="checkbox" name="pcr_sections[]" value="identity" class="pcr-section-checkbox" id="pcr_cb_identity" style="width: 17px; height: 17px; accent-color: #0284c7; cursor: pointer;">
                            <span>Identitas Perorangan</span>
                        </label>

                        <!-- Option 2: Tempat Usaha / Kegiatan & Deskripsi Usaha -->
                        <label style="display: inline-flex; align-items: center; gap: 10px; font-size: 13.5px; font-weight: 600; color: #1e293b; cursor: pointer; user-select: none;">
                            <input type="checkbox" name="pcr_sections[]" value="workplace" class="pcr-section-checkbox" id="pcr_cb_workplace" style="width: 17px; height: 17px; accent-color: #0284c7; cursor: pointer;">
                            <span>Tempat Usaha / Kegiatan Pemberi Kerja &amp; Deskripsi Usaha</span>
                        </label>

                        <!-- Option 3: Dokumen & Bukti Tempat Usaha -->
                        <label style="display: inline-flex; align-items: center; gap: 10px; font-size: 13.5px; font-weight: 600; color: #1e293b; cursor: pointer; user-select: none;">
                            <input type="checkbox" name="pcr_sections[]" value="documents" class="pcr-section-checkbox" id="pcr_cb_documents" style="width: 17px; height: 17px; accent-color: #0284c7; cursor: pointer;">
                            <span>Dokumen &amp; Bukti Tempat Usaha</span>
                        </label>

                        <!-- Option 4: Media Sosial -->
                        <label style="display: inline-flex; align-items: center; gap: 10px; font-size: 13.5px; font-weight: 600; color: #1e293b; cursor: pointer; user-select: none;">
                            <input type="checkbox" name="pcr_sections[]" value="social" class="pcr-section-checkbox" id="pcr_cb_social" style="width: 17px; height: 17px; accent-color: #0284c7; cursor: pointer;">
                            <span>Media Sosial</span>
                        </label>
                    </div>
                </div>

                <!-- 2. ALASAN PERUBAHAN -->
                <div style="margin-bottom: 24px;">
                    <label for="pcr_reason" style="display: block; font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                        Alasan Perubahan <span class="req" style="color: #ef4444;">*</span>
                    </label>
                    <div style="position: relative;">
                        <textarea name="pcr_reason" id="pcr_reason" maxlength="500" rows="3" required placeholder="Saya ingin memperbarui alamat tempat usaha karena sudah pindah lokasi, serta memperbarui informasi media sosial (Instagram dan LinkedIn) yang terbaru." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 14px; font-size: 13.5px; color: #1e293b; line-height: 1.5; resize: vertical; box-sizing: border-box; font-family: inherit; outline: none; transition: border-color 0.2s;"></textarea>
                        <div style="text-align: right; font-size: 12px; color: #94a3b8; margin-top: 4px;">
                            <span id="pcr_reason_counter">0</span>/500
                        </div>
                    </div>
                </div>

                <!-- 3. FIELD PROFIL TERPILIH (SESUAI FORM DAFTAR PEMBERI KERJA DENGAN DATA TERISI) -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 10px;">
                        Field Profil Terpilih
                    </label>

                    <!-- PLACEHOLDER JIKA BELUM ADA CHECKBOX YANG DIPILIH -->
                    <div id="pcr_fields_empty_placeholder" style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 24px; text-align: center; color: #64748b; font-size: 13.5px;">
                        <i class="fa-regular fa-hand-pointer" style="font-size: 22px; color: #94a3b8; margin-bottom: 8px; display: block;"></i>
                        Pilih minimal satu bagian profil di atas untuk menampilkan field yang ingin diubah.
                    </div>

                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <!-- SECTION 1: IDENTITAS PERORANGAN                                 -->
                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <div id="pcr_box_identity" style="display: none; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                        <div style="background: #f1f5f9; padding: 12px 18px; font-weight: 700; font-size: 13.5px; color: #0369a1; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0;">
                            <span>Identitas Perorangan</span>
                            <span style="font-size: 11.5px; font-weight: 600; color: #0369a1; background: #e0f2fe; padding: 3px 10px; border-radius: 999px; border: 1px solid #bae6fd;">Jenis Usaha &amp; WhatsApp dapat diedit</span>
                        </div>
                        <div style="padding: 20px; background: #ffffff;">

                            <!-- Helper Info Read-only -->
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; line-height: 1.45; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-lock" style="color: #94a3b8; font-size: 13px;"></i>
                                <span>Field Nama Pemberi Kerja, NIK, Nomor Telepon Aktif, dan Status NPWP berstatus <strong>Read-Only (Disabled)</strong> karena terverifikasi secara resmi dari akun SIAPKerja.</span>
                            </div>

                            <!-- Baris 1: Nama Lengkap & NIK (Read-Only) -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label">
                                        Nama Pemberi Kerja <span class="req">*</span>
                                    </label>
                                    <input type="text" value="<?php echo $pcrOwnerName; ?>" readonly disabled style="background: #f8fafc; color: #64748b; cursor: not-allowed;">
                                    <div class="pki-field-helper">Terisi otomatis dari akun SIAPKerja (Read-Only).</div>
                                </div>
                                <div class="pki-field">
                                    <label class="pki-field-label">
                                        NIK <span class="req">*</span>
                                    </label>
                                    <input type="text" value="<?php echo $pcrNik; ?>" readonly disabled style="background: #f8fafc; color: #64748b; cursor: not-allowed;">
                                    <div class="pki-field-helper">Terisi otomatis dari akun SIAPKerja (Read-Only).</div>
                                </div>
                            </div>

                            <!-- Baris 2: Nomor Telepon Aktif & Jenis Usaha Individu -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label">
                                        Nomor Telepon Aktif <span class="req">*</span>
                                    </label>
                                    <input type="text" value="<?php echo $pcrPhone; ?>" readonly disabled style="background: #f8fafc; color: #64748b; cursor: not-allowed;">
                                    <div class="pki-field-helper">Terisi otomatis dari akun SIAPKerja (Read-Only).</div>
                                </div>

                                <!-- Jenis Usaha Individu (BISA DIEDIT) -->
                                <div class="pki-field" style="position:relative;">
                                    <label class="pki-field-label" for="pcr_select_profession">
                                        Jenis Usaha Individu <span class="req">*</span>
                                        <span class="pki-tooltip tooltip-right" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Pilih jenis usaha perorangan yang Anda jalankan saat ini.</span>
                                        </span>
                                    </label>
                                    <select name="pcr_profession" id="pcr_select_profession" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 13.5px; color: #0f172a; font-weight: 600; background: #ffffff; outline: none; cursor: pointer; transition: border-color 0.2s;">
                                        <option value="">Pilih Jenis Usaha Individu</option>
                                        <?php foreach ($pcrProfessionGroups as $groupName => $items): ?>
                                            <optgroup label="<?php echo e($groupName); ?>" style="font-weight: 700; color: #0284c7;">
                                                <?php foreach ($items as $item): ?>
                                                    <option value="<?php echo e($item); ?>" <?php echo ($pcrProfession === $item) ? 'selected' : ''; ?> style="color: #1e293b; font-weight: normal;">
                                                        <?php echo e($item); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="pki-field-helper" style="color: #0284c7; font-weight: 500;">Dapat diedit: pilih jenis usaha perorangan yang Anda jalankan.</div>
                                </div>
                            </div>

                            <!-- Baris 3: Nomor WhatsApp (BISA DIEDIT) & Status NPWP (Read-Only) -->
                            <div class="pki-grid-2">
                                <!-- Nomor WhatsApp -->
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_whatsapp">
                                        Nomor WhatsApp <span class="req">*</span>
                                    </label>
                                    <input type="text" name="pcr_whatsapp" id="pcr_whatsapp" value="<?php echo $pcrWhatsapp; ?>" maxlength="15" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="08xxxxxxxxxx" style="border: 1px solid #0284c7; background: #f0fdf4; font-weight: 600;">
                                    <div class="pki-field-helper" style="color: #166534; font-weight: 500;">Dapat diedit: gunakan nomor WhatsApp aktif yang dapat dihubungi.</div>
                                </div>

                                <!-- Status NPWP (Read-Only) -->
                                <div class="pki-field" style="position:relative;">
                                    <label class="pki-field-label">
                                        Status NPWP <span class="req">*</span>
                                        <span class="pki-tooltip tooltip-right" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Informasi: Untuk Wajib Pajak Orang Pribadi yang merupakan penduduk Indonesia, NIK digunakan sebagai NPWP.</span>
                                        </span>
                                    </label>
                                    <div class="pki-checkbox-field" style="opacity: 0.85; background: #f8fafc; border-color: #e2e8f0; cursor: not-allowed;">
                                        <label class="pki-checkbox-label" style="cursor: not-allowed;">
                                            <input type="checkbox" checked disabled style="cursor: not-allowed; accent-color: #0284c7;">
                                            <span>Saya terdaftar sebagai Wajib Pajak Orang Pribadi dan menggunakan NIK sebagai NPWP</span>
                                        </label>
                                    </div>
                                    <div class="pki-field-helper">Status NPWP terverifikasi mengikuti akun SIAPKerja (Read-Only).</div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <!-- SECTION 2: TEMPAT USAHA / KEGIATAN & DESKRIPSI USAHA            -->
                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <div id="pcr_box_workplace" style="display: none; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                        <div style="background: #f1f5f9; padding: 12px 18px; font-weight: 700; font-size: 13.5px; color: #0369a1; border-bottom: 1px solid #e2e8f0;">
                            Tempat Usaha / Kegiatan Pemberi Kerja &amp; Deskripsi Usaha
                        </div>
                        <div style="padding: 20px; background: #ffffff;">

                            <!-- Baris 1: Nama Brand & Logo Usaha -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_brand_name">
                                        Nama Brand Usaha
                                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Nama merek dagang, brand, atau nama komersial tempat usaha/kegiatan Anda.</span>
                                        </span>
                                    </label>
                                    <input type="text" name="pcr_brand_name" id="pcr_brand_name" value="<?php echo $pcrBrandName; ?>" placeholder="Masukkan nama brand usaha">
                                    <div class="pki-field-helper">Nama brand atau merek usaha/kegiatan yang dijalankan.</div>
                                </div>

                                <div class="pki-field">
                                    <label class="pki-field-label">
                                        Logo Usaha
                                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Unggah logo resmi atau foto identitas visual usaha/kegiatan Anda.</span>
                                        </span>
                                    </label>
                                    <div class="pki-upload-box" id="pcr_box_business_logo" onclick="pcrTriggerUpload('business_logo')">
                                        <span class="pki-upload-text <?php echo !empty($pcrLogo) ? 'has-file' : ''; ?>" id="pcr_text_business_logo">
                                            <?php if (!empty($pcrLogo)): ?>
                                                <i class="fa-solid fa-image" style="color:#0284c7; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($pcrLogo)); ?></span>
                                            <?php else: ?>
                                                <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                                            <?php endif; ?>
                                        </span>
                                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                            <button type="button" class="pki-upload-btn" id="pcr_btn_business_logo"><?php echo !empty($pcrLogo) ? 'Ganti' : 'Pilih File'; ?></button>
                                        </div>
                                    </div>
                                    <input type="file" name="pcr_business_logo" id="pcr_input_business_logo" accept=".png,.jpg,.jpeg" style="display:none;" onchange="pcrHandleFileChange(this, 'business_logo')">
                                    <div class="pki-field-helper">Ukuran maksimum 2 MB • Format PNG, JPG</div>
                                    <?php if ($pcrLogoSizeText !== ''): ?>
                                        <div class="pki-field-helper" style="color: #64748b;">Ukuran file saat ini: <?php echo e($pcrLogoSizeText); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Deskripsi Singkat Usaha -->
                            <div class="pki-field">
                                <label class="pki-field-label" for="pcr_description">
                                    Deskripsi Singkat Usaha / Rekrutmen
                                    <span class="pki-tooltip" tabindex="0" role="tooltip">
                                        <?php echo $infoIconSvg; ?>
                                        <span class="tooltip-popover">Jelaskan secara singkat usaha atau kegiatan yang dijalankan serta gambaran kebutuhan rekrutmen.</span>
                                    </span>
                                </label>
                                <textarea name="pcr_description" id="pcr_description" placeholder="Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen..." style="min-height:85px;"><?php echo $pcrDescription; ?></textarea>
                                <div class="pki-field-helper">Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.</div>
                            </div>

                            <!-- Alamat Lengkap Tempat Usaha -->
                            <div class="pki-field">
                                <label class="pki-field-label" for="pcr_address">
                                    Alamat Lengkap Tempat Usaha <span class="req">*</span>
                                </label>
                                <input type="text" name="pcr_address" id="pcr_address" value="<?php echo $pcrAddress; ?>" placeholder="Masukkan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap...">
                                <div class="pki-field-helper">Tuliskan alamat lengkap tempat usaha/kegiatan.</div>
                            </div>

                            <!-- Wilayah: Provinsi & Kota/Kabupaten -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_province">
                                        Provinsi <span class="req">*</span>
                                    </label>
                                    <input type="text" name="pcr_province" id="pcr_province" value="<?php echo $pcrProvince; ?>" placeholder="Masukkan provinsi">
                                    <div class="pki-field-helper">Provinsi lokasi usaha / kegiatan.</div>
                                </div>
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_city">
                                        Kota / Kabupaten <span class="req">*</span>
                                    </label>
                                    <input type="text" name="pcr_city" id="pcr_city" value="<?php echo $pcrCity; ?>" placeholder="Masukkan kota/kabupaten">
                                    <div class="pki-field-helper">Kota/Kabupaten lokasi usaha / kegiatan.</div>
                                </div>
                            </div>

                            <!-- Wilayah: Kecamatan & Kelurahan -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_district">
                                        Kecamatan <span class="req">*</span>
                                    </label>
                                    <input type="text" name="pcr_district" id="pcr_district" value="<?php echo $pcrDistrict; ?>" placeholder="Masukkan kecamatan">
                                    <div class="pki-field-helper">Kecamatan lokasi usaha / kegiatan.</div>
                                </div>
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_village">
                                        Kelurahan / Desa <span class="req">*</span>
                                    </label>
                                    <input type="text" name="pcr_village" id="pcr_village" value="<?php echo $pcrVillage; ?>" placeholder="Masukkan kelurahan/desa">
                                    <div class="pki-field-helper">Kelurahan/Desa lokasi usaha / kegiatan.</div>
                                </div>
                            </div>

                            <!-- Kode Pos & Detail Patokan -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_postal_code">
                                        Kode Pos <span class="req">*</span>
                                    </label>
                                    <input type="text" name="pcr_postal_code" id="pcr_postal_code" value="<?php echo $pcrPostalCode; ?>" placeholder="Kode Pos">
                                    <div class="pki-field-helper">Kode pos mengikuti lokasi usaha.</div>
                                </div>
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_address_detail">
                                        Detail Alamat / Patokan (Opsional)
                                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Informasi tambahan seperti nomor gedung, blok, lantai, atau patokan terdekat.</span>
                                        </span>
                                    </label>
                                    <input type="text" name="pcr_address_detail" id="pcr_address_detail" value="<?php echo $pcrAddressDetail; ?>" placeholder="Contoh: Ruko lantai 2, sebelah Kantor Pos">
                                    <div class="pki-field-helper">Opsional. Informasi pengenal lokasi.</div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <!-- SECTION 3: DOKUMEN & BUKTI TEMPAT USAHA                         -->
                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <div id="pcr_box_documents" style="display: none; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                        <div style="background: #f1f5f9; padding: 12px 18px; font-weight: 700; font-size: 13.5px; color: #0369a1; border-bottom: 1px solid #e2e8f0;">
                            Dokumen &amp; Bukti Tempat Usaha
                        </div>
                        <div style="padding: 20px; background: #ffffff;">

                            <div class="pki-grid-2">
                                <!-- Dokumen Perizinan Usaha -->
                                <div class="pki-field">
                                    <label class="pki-field-label">
                                        Dokumen Perizinan Usaha <span class="req">*</span>
                                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Unggah file pendukung identitas atau kegiatan usaha (NIB, SIUP, SKDU, atau surat keterangan terkait).</span>
                                        </span>
                                    </label>
                                    <div class="pki-upload-box" id="pcr_box_permit_document" onclick="pcrTriggerUpload('permit_document')">
                                        <span class="pki-upload-text <?php echo !empty($pcrPermitDoc) ? 'has-file' : ''; ?>" id="pcr_text_permit_document">
                                            <?php if (!empty($pcrPermitDoc)): ?>
                                                <i class="fa-solid fa-file-pdf" style="color:#ef4444; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($pcrPermitDoc)); ?></span>
                                            <?php else: ?>
                                                <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                                            <?php endif; ?>
                                        </span>
                                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                            <button type="button" class="pki-upload-btn" id="pcr_btn_permit_document"><?php echo !empty($pcrPermitDoc) ? 'Ganti' : 'Pilih File'; ?></button>
                                        </div>
                                    </div>
                                    <input type="file" name="pcr_permit_document" id="pcr_input_permit_document" accept=".pdf,.docx" style="display:none;" onchange="pcrHandleFileChange(this, 'permit_document')">
                                    <div class="pki-field-helper">Ukuran maksimum 2 MB • Format PDF, Docx</div>
                                    <?php if ($pcrPermitDocSizeText !== ''): ?>
                                        <div class="pki-field-helper" style="color: #64748b;">Ukuran file saat ini: <?php echo e($pcrPermitDocSizeText); ?></div>
                                    <?php endif; ?>
                                </div>

                                <!-- Foto Bukti Tempat Usaha / Lokasi -->
                                <div class="pki-field">
                                    <label class="pki-field-label">
                                        Foto Bukti Tempat Usaha / Lokasi <span class="req">*</span>
                                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Unggah foto bagian depan tempat usaha atau lokasi kegiatan yang sesuai dengan alamat tempat usaha.</span>
                                        </span>
                                    </label>
                                    <div class="pki-upload-box" id="pcr_box_workplace_photo" onclick="pcrTriggerUpload('workplace_photo')">
                                        <span class="pki-upload-text <?php echo !empty($pcrPhoto) ? 'has-file' : ''; ?>" id="pcr_text_workplace_photo">
                                            <?php if (!empty($pcrPhoto)): ?>
                                                <i class="fa-solid fa-image" style="color:#0284c7; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($pcrPhoto)); ?></span>
                                            <?php else: ?>
                                                <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                                            <?php endif; ?>
                                        </span>
                                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                            <button type="button" class="pki-upload-btn" id="pcr_btn_workplace_photo"><?php echo !empty($pcrPhoto) ? 'Ganti' : 'Pilih File'; ?></button>
                                        </div>
                                    </div>
                                    <input type="file" name="pcr_workplace_photo" id="pcr_input_workplace_photo" accept=".png,.jpg,.jpeg" style="display:none;" onchange="pcrHandleFileChange(this, 'workplace_photo')">
                                    <div class="pki-field-helper">Ukuran maksimum 2 MB • Format PNG, JPG</div>
                                    <?php if ($pcrPhotoSizeText !== ''): ?>
                                        <div class="pki-field-helper" style="color: #64748b;">Ukuran file saat ini: <?php echo e($pcrPhotoSizeText); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <!-- SECTION 4: MEDIA SOSIAL                                         -->
                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <div id="pcr_box_social" style="display: none; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                        <div style="background: #f1f5f9; padding: 12px 18px; font-weight: 700; font-size: 13.5px; color: #0369a1; border-bottom: 1px solid #e2e8f0;">
                            Media Sosial
                        </div>
                        <div style="padding: 20px; background: #ffffff;">

                            <div style="font-size: 12.5px; color: #64748b; margin-bottom: 16px;">
                                Perbarui akun media sosial resmi yang digunakan untuk tempat usaha / kegiatan Anda:
                            </div>

                            <div class="pki-grid-2">
                                <!-- Instagram -->
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_instagram">
                                        <i class="fa-brands fa-instagram" style="color:#e1306c; margin-right:4px;"></i> Instagram
                                    </label>
                                    <input type="text" name="pcr_instagram" id="pcr_instagram" value="<?php echo $pcrInstagram; ?>" placeholder="@username atau https://instagram.com/...">
                                    <div class="pki-field-helper">Akun profil Instagram resmi usaha Anda.</div>
                                </div>

                                <!-- LinkedIn -->
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_linkedin">
                                        <i class="fa-brands fa-linkedin" style="color:#0a66c2; margin-right:4px;"></i> LinkedIn
                                    </label>
                                    <input type="text" name="pcr_linkedin" id="pcr_linkedin" value="<?php echo $pcrLinkedin; ?>" placeholder="https://linkedin.com/in/... atau username">
                                    <div class="pki-field-helper">Tautan akun atau profil LinkedIn usaha Anda.</div>
                                </div>
                            </div>

                            <div class="pki-grid-2" style="margin-top: 6px;">
                                <!-- Facebook -->
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_facebook">
                                        <i class="fa-brands fa-facebook" style="color:#1877f2; margin-right:4px;"></i> Facebook
                                    </label>
                                    <input type="text" name="pcr_facebook" id="pcr_facebook" value="<?php echo $pcrFacebook; ?>" placeholder="@username atau https://facebook.com/...">
                                    <div class="pki-field-helper">Halaman atau profil Facebook resmi usaha Anda.</div>
                                </div>

                                <!-- TikTok -->
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_tiktok">
                                        <i class="fa-brands fa-tiktok" style="color:#000000; margin-right:4px;"></i> TikTok
                                    </label>
                                    <input type="text" name="pcr_tiktok" id="pcr_tiktok" value="<?php echo $pcrTiktok; ?>" placeholder="@username atau https://tiktok.com/@...">
                                    <div class="pki-field-helper">Akun TikTok resmi tempat usaha Anda.</div>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>

                <!-- 4. KONFIRMASI PERUBAHAN CALLOUT BOX (SESUAI GAMBAR) -->
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 14px;">
                    <i class="fa-solid fa-circle-info" style="color: #0284c7; font-size: 20px; flex-shrink: 0; margin-top: 2px;"></i>
                    <div style="font-size: 13px; line-height: 1.5; color: #1e3a8a;">
                        <strong style="display: block; font-size: 13.5px; font-weight: 700; color: #1e40af; margin-bottom: 2px;">Konfirmasi Perubahan</strong>
                        <span id="pcr_confirmation_text">Anda akan mengajukan perubahan pada bagian: <strong id="pcr_confirm_sections_list">-</strong>.</span>
                    </div>
                </div>

                <!-- 5. USER CONSENT CHECKBOX -->
                <div style="margin-bottom: 8px;">
                    <label style="display: inline-flex; align-items: flex-start; gap: 10px; font-size: 13px; color: #1e293b; font-weight: 500; cursor: pointer; user-select: none;">
                        <input type="checkbox" name="pcr_consent" id="pcr_consent" value="1" required style="width: 17px; height: 17px; accent-color: #0284c7; cursor: pointer; margin-top: 2px; flex-shrink: 0;">
                        <span>Saya telah membaca dan menyetujui pengajuan perubahan profil untuk diperiksa oleh Admin. <span class="req" style="color: #ef4444;">*</span></span>
                    </label>
                </div>

            </div>

            <!-- MODAL FOOTER -->
            <div class="modal-footer" style="padding: 16px 28px; background: #ffffff; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; align-items: center; gap: 12px;">
                <button type="button" class="ghost-btn" data-close-modal="modal-profile-change-request" style="height: 40px; padding: 0 20px; border-radius: 8px; font-size: 13.5px; font-weight: 600; color: #475569; background: #f8fafc; border: 1px solid #cbd5e1; cursor: pointer;">
                    Batal
                </button>
                <button type="submit" class="primary-btn" id="btnSubmitPCR" style="height: 40px; padding: 0 24px; border-radius: 8px; font-size: 13.5px; font-weight: 700; color: #ffffff; background: #0284c7; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.2);">
                    Ajukan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    // Alasan counter
    const reasonTextarea = document.getElementById('pcr_reason');
    const reasonCounter = document.getElementById('pcr_reason_counter');
    if (reasonTextarea && reasonCounter) {
        reasonTextarea.addEventListener('input', function() {
            reasonCounter.textContent = this.value.length;
        });
        reasonCounter.textContent = reasonTextarea.value.length;
    }

    // Checkboxes toggle
    const sectionCheckboxes = document.querySelectorAll('.pcr-section-checkbox');
    const placeholderEmpty = document.getElementById('pcr_fields_empty_placeholder');
    const boxIdentity = document.getElementById('pcr_box_identity');
    const boxWorkplace = document.getElementById('pcr_box_workplace');
    const boxDocuments = document.getElementById('pcr_box_documents');
    const boxSocial = document.getElementById('pcr_box_social');
    const confirmSectionsList = document.getElementById('pcr_confirm_sections_list');

    const sectionLabels = {
        'identity': 'Identitas Perorangan',
        'workplace': 'Tempat Usaha / Kegiatan Pemberi Kerja & Deskripsi Usaha',
        'documents': 'Dokumen & Bukti Tempat Usaha',
        'social': 'Media Sosial'
    };

    function updatePcrSections() {
        const checkedValues = [];
        sectionCheckboxes.forEach(cb => {
            if (cb.checked) {
                checkedValues.push(cb.value);
            }
        });

        // Toggle boxes
        if (boxIdentity) boxIdentity.style.display = checkedValues.includes('identity') ? 'block' : 'none';
        if (boxWorkplace) boxWorkplace.style.display = checkedValues.includes('workplace') ? 'block' : 'none';
        if (boxDocuments) boxDocuments.style.display = checkedValues.includes('documents') ? 'block' : 'none';
        if (boxSocial) boxSocial.style.display = checkedValues.includes('social') ? 'block' : 'none';

        if (placeholderEmpty) {
            placeholderEmpty.style.display = checkedValues.length === 0 ? 'block' : 'none';
        }

        // Update confirmation text
        if (confirmSectionsList) {
            if (checkedValues.length === 0) {
                confirmSectionsList.textContent = 'Pilih minimal satu bagian di atas';
            } else {
                const names = checkedValues.map(v => sectionLabels[v] || v);
                if (names.length === 1) {
                    confirmSectionsList.textContent = names[0];
                } else if (names.length === 2) {
                    confirmSectionsList.textContent = names.join(' dan ');
                } else {
                    const last = names.pop();
                    confirmSectionsList.textContent = names.join(', ') + ', dan ' + last;
                }
            }
        }
    }

    sectionCheckboxes.forEach(cb => {
        cb.addEventListener('change', updatePcrSections);
    });

    // Form submit validation
    const form = document.getElementById('formProfileChangeRequest');
    if (form) {
        form.addEventListener('submit', function(e) {
            const checkedAny = Array.from(sectionCheckboxes).some(cb => cb.checked);
            if (!checkedAny) {
                e.preventDefault();
                alert('Pilih minimal satu bagian profil yang ingin Anda ubah.');
                return false;
            }
            const consent = document.getElementById('pcr_consent');
            if (consent && !consent.checked) {
                e.preventDefault();
                alert('Anda wajib menyetujui pernyataan pengajuan perubahan profil.');
                return false;
            }
        });
    }

    // Custom upload trigger & handle
    window.pcrTriggerUpload = function(fieldKey) {
        const fileInput = document.getElementById(`pcr_input_${fieldKey}`);
        if (fileInput) fileInput.click();
    };

    window.pcrHandleFileChange = function(input, fieldKey) {
        if (!input.files || input.files.length === 0) return;
        const file = input.files[0];
        const textBox = document.getElementById(`pcr_text_${fieldKey}`);
        const btn = document.getElementById(`pcr_btn_${fieldKey}`);
        if (!textBox) return;

        const ext = (file.name.split('.').pop() || '').toLowerCase();
        let iconClass = 'fa-file';
        let iconColor = '#0284c7';

        if (fieldKey === 'permit_document') {
            if (ext === 'pdf') {
                iconClass = 'fa-file-pdf';
                iconColor = '#ef4444';
            } else if (ext === 'docx' || ext === 'doc') {
                iconClass = 'fa-file-word';
                iconColor = '#2563eb';
            }
        } else {
            iconClass = 'fa-image';
            iconColor = '#0284c7';
        }

        textBox.classList.add('has-file');
        textBox.innerHTML = `<i class="fa-solid ${iconClass}" style="color:${iconColor}; flex-shrink:0;"></i> <span class="pki-upload-filename">${file.name}</span>`;
        if (btn) btn.textContent = 'Ganti';
    };

    // Default trigger on init
    updatePcrSections();
})();
</script>
