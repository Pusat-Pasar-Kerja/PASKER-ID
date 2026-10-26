<?php
/**
 * Modal Permintaan Perubahan Profil Pemberi Kerja Individu
 * Menampilkan struktur field yang identik dengan Form Profil Pemberi Kerja Individu (Langkah 1 - 4).
 * Seluruh data profil yang tersimpan sebelumnya diisi secara otomatis (pre-filled).
 */

$showPcrModal = isset($_GET['open_pcr']) && $_GET['open_pcr'] == '1';
$pcrData = $profile ?? [];
$sData = $seekerProfile ?? [];

// Helper values dari data profil yang tersimpan sebelumnya
$vOwnerName = e($pcrData['owner_name'] ?? ($user['name'] ?? ''));
$vNik = e($pcrData['nik'] ?? ($sData['nik'] ?? ''));
$vPhone = e($pcrData['phone'] ?? ($sData['phone'] ?? ''));
$vWhatsapp = e($pcrData['whatsapp'] ?? ($pcrData['phone'] ?? ($sData['phone'] ?? '')));
$vProfession = $pcrData['profession'] ?? '';
$vNpwp = e($pcrData['npwp'] ?? '');

$vSameLoc = !empty($pcrData['same_location_siapkerja']) || !empty($pcrData['same_address_siapkerja']) || !empty($pcrData['workplace_same_as_domicile']);
$vProvince = e($pcrData['province'] ?? ($sData['province'] ?? ''));
$vCity = e($pcrData['city'] ?? ($sData['city'] ?? ($user['city'] ?? '')));
$vDistrict = e($pcrData['district'] ?? ($sData['district'] ?? ''));
$vVillage = e($pcrData['village'] ?? ($sData['village'] ?? ''));
$vPostalCode = e($pcrData['postal_code'] ?? ($sData['postal_code'] ?? ''));
$vAddress = e($pcrData['address'] ?? ($sData['address'] ?? ''));
$vAddressDetail = e($pcrData['address_detail'] ?? ($pcrData['address_notes'] ?? ($sData['address_detail'] ?? '')));
$vLat = e($pcrData['latitude'] ?? '-6.887844');
$vLng = e($pcrData['longitude'] ?? '107.613038');

$vBrandName = e($pcrData['brand_name'] ?? '');
$vLogo = $pcrData['business_logo'] ?? ($pcrData['brand_logo'] ?? '');
$vLogoSizeText = '';
if (!empty($vLogo)) {
    $baseDir = realpath(__DIR__ . '/..');
    $logoRelative = ltrim(str_replace('\\', '/', (string) $vLogo), '/');
    $logoAbsolute = realpath(__DIR__ . '/../' . $logoRelative);
    if ($baseDir && $logoAbsolute && str_starts_with($logoAbsolute, $baseDir) && is_file($logoAbsolute)) {
        $bytes = (int) filesize($logoAbsolute);
        if ($bytes > 0) {
            $vLogoSizeText = $bytes >= 1024 * 1024 ? number_format($bytes / (1024 * 1024), 2) . ' MB' : number_format($bytes / 1024, 2) . ' KB';
        }
    }
}

$vDescription = e($pcrData['description'] ?? '');

$vPermitDoc = $pcrData['permit_document'] ?? ($pcrData['doc_permission'] ?? '');
$vPermitDocSizeText = '';
if (!empty($vPermitDoc)) {
    $baseDir = realpath(__DIR__ . '/..');
    $docRelative = ltrim(str_replace('\\', '/', (string) $vPermitDoc), '/');
    $docAbsolute = realpath(__DIR__ . '/../' . $docRelative);
    if ($baseDir && $docAbsolute && str_starts_with($docAbsolute, $baseDir) && is_file($docAbsolute)) {
        $bytes = (int) filesize($docAbsolute);
        if ($bytes > 0) {
            $vPermitDocSizeText = $bytes >= 1024 * 1024 ? number_format($bytes / (1024 * 1024), 2) . ' MB' : number_format($bytes / 1024, 2) . ' KB';
        }
    }
}

$vPhoto = $pcrData['workplace_photo'] ?? ($pcrData['doc_location_photo'] ?? '');
$vPhotoSizeText = '';
if (!empty($vPhoto)) {
    $baseDir = realpath(__DIR__ . '/..');
    $photoRelative = ltrim(str_replace('\\', '/', (string) $vPhoto), '/');
    $photoAbsolute = realpath(__DIR__ . '/../' . $photoRelative);
    if ($baseDir && $photoAbsolute && str_starts_with($photoAbsolute, $baseDir) && is_file($photoAbsolute)) {
        $bytes = (int) filesize($photoAbsolute);
        if ($bytes > 0) {
            $vPhotoSizeText = $bytes >= 1024 * 1024 ? number_format($bytes / (1024 * 1024), 2) . ' MB' : number_format($bytes / 1024, 2) . ' KB';
        }
    }
}

$vLinkedin = e($pcrData['linkedin'] ?? '');
$vFacebook = e($pcrData['facebook'] ?? '');
$vInstagram = e($pcrData['instagram'] ?? '');
$vTiktok = e($pcrData['tiktok'] ?? '');

$existingSocials = [];
if (!empty($vLinkedin)) $existingSocials[] = ['platform' => 'LinkedIn', 'handle' => $vLinkedin];
if (!empty($vFacebook)) $existingSocials[] = ['platform' => 'Facebook', 'handle' => $vFacebook];
if (!empty($vInstagram)) $existingSocials[] = ['platform' => 'Instagram', 'handle' => $vInstagram];
if (!empty($vTiktok)) $existingSocials[] = ['platform' => 'TikTok', 'handle' => $vTiktok];
if (empty($existingSocials)) {
    $existingSocials[] = ['platform' => 'LinkedIn', 'handle' => ''];
}

$locDisplayText = 'Pilih lokasi tempat usaha / kegiatan';
$hasLocSelected = false;
if (!empty($vVillage) && !empty($vDistrict) && !empty($vCity) && !empty($vProvince)) {
    $locDisplayText = $vVillage . ', ' . $vDistrict . ', ' . $vCity . ', ' . $vProvince;
    $hasLocSelected = true;
}

$hasAddress = !empty($vAddress);
$hasFullLocation = ($hasLocSelected && $hasAddress) || (!empty($vAddress) && (!empty($vCity) || !empty($vProvince)));
$initialMapQuery = '';
if ($hasFullLocation) {
    $initialMapQuery = implode(', ', array_filter([$vAddress, $vVillage, $vDistrict, $vCity, $vProvince, 'Indonesia']));
}

$infoIconSvg = '<svg class="tooltip-icon" width="13" height="13" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true" style="display:inline-block; vertical-align:-1px; cursor:pointer;"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>';

$professionGroups = function_exists('individual_business_types') ? individual_business_types() : [
    'Kesehatan & Terapi' => [
        'Praktik Dokter Umum', 'Praktik Dokter Gigi', 'Praktik Bidan', 'Praktik Keperawatan Mandiri',
        'Praktik Psikolog', 'Layanan Fisioterapi', 'Praktik Terapis Wicara', 'Layanan Akupunktur',
        'Layanan Terapi Tumbuh Kembang Anak', 'Layanan Konsultasi Gizi/Nutrisionis',
    ],
    'Hukum, Keuangan & Jasa Profesional' => [
        'Praktik Notaris / PPAT', 'Praktik Advokat/Pengacara / Konsultan Hukum', 'Konsultan Pajak',
        'Jasa Akuntansi', 'Konsultan Manajemen', 'Jasa Administrasi dan Pengurusan Dokumen', 'Jasa Penerjemah',
    ],
    'Teknik, Arsitektur & Desain' => [
        'Praktik Arsitek', 'Jasa Desain Interior', 'Jasa Drafter', 'Jasa Perbaikan/Bengkel Teknik (Instalasi Listrik & Elektronik)',
    ],
    'Pendidikan & Pelatihan' => [
        'Bimbingan Belajar', 'Kursus Bahasa', 'Kursus Musik', 'Kursus Tari',
    ],
    'Jasa Acara, Kreatif & Hewan Peliharaan' => [
        'Studio Foto dan Video', 'Jasa Fotografi/Videografi', 'Jasa Dekorasi Acara',
        'Jasa Penjahit/Atelier', 'Jasa Grooming Hewan', 'Jasa Desain Grafis', 'Jasa Florist/Dekorasi Bunga',
    ],
    'Digital, Media & Konten' => [
        'Konten Kreator/Digital Creator', 'Jasa Editing Video / Transfer Video',
        'Jasa Live Streaming / Host Live Streaming', 'Studio Podcast',
    ],
    'Kecantikan & Perawatan Pribadi' => [
        'Barbershop', 'Makeup Artist',
    ],
    'Jasa Rumah Tangga, Pemeliharaan & Perbaikan' => [
        'Jasa Laundry', 'Jasa Servis AC',
    ],
];
?>

<link rel="stylesheet" href="assets/pki-form.css?v=<?php echo time(); ?>">
<style>
<?php
$pkiCssPath = __DIR__ . '/../assets/pki-form.css';
if (file_exists($pkiCssPath)) {
    echo file_get_contents($pkiCssPath);
}
?>

/* ─── MODAL PROFILE CHANGE REQUEST RESPONSIVE DESIGN ─────────────────────────── */
#modal-profile-change-request {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    background: rgba(15, 23, 42, 0.6) !important;
    backdrop-filter: blur(4px) !important;
    -webkit-backdrop-filter: blur(4px) !important;
    display: none;
    align-items: center !important;
    justify-content: center !important;
    padding: 16px !important;
    box-sizing: border-box !important;
    z-index: 1050 !important;
}

#modal-profile-change-request.open {
    display: flex !important;
}

#modal-profile-change-request .modal-panel {
    width: 100% !important;
    max-width: 780px !important;
    max-height: 90vh !important;
    border-radius: 16px !important;
    background: #ffffff !important;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    margin: auto !important;
    position: relative !important;
    box-sizing: border-box !important;
}

#modal-profile-change-request .modal-header {
    background: #ffffff !important;
    padding: 20px 24px 16px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    flex-shrink: 0 !important;
}

#modal-profile-change-request .modal-body {
    padding: 20px 24px !important;
    flex: 1 !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch !important;
}

#modal-profile-change-request .modal-footer {
    padding: 16px 24px !important;
    background: #ffffff !important;
    border-top: 1px solid #f1f5f9 !important;
    display: flex !important;
    justify-content: flex-end !important;
    align-items: center !important;
    gap: 12px !important;
    flex-shrink: 0 !important;
}

/* Ensure dropdown popups sit properly inside the modal */
#modal-profile-change-request .loc-dropdown-popup {
    z-index: 1200 !important;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.1) !important;
}
#modal-profile-change-request .pki-custom-select-dropdown {
    z-index: 1200 !important;
}

/* Mobile & Tablet Responsiveness */
@media (max-width: 720px) {
    #modal-profile-change-request {
        padding: 0 !important;
    }
    #modal-profile-change-request .modal-panel {
        max-width: 100vw !important;
        width: 100vw !important;
        height: 100vh !important;
        max-height: 100vh !important;
        border-radius: 0 !important;
    }
    #modal-profile-change-request .modal-header {
        padding: 16px 18px 12px !important;
    }
    #modal-profile-change-request .modal-body {
        padding: 16px 18px !important;
    }
    #modal-profile-change-request .modal-footer {
        padding: 14px 18px !important;
        flex-direction: column-reverse !important;
        gap: 8px !important;
    }
    #modal-profile-change-request .modal-footer button {
        width: 100% !important;
    }
}
</style>

<div class="modal-backdrop <?php echo $showPcrModal ? 'open' : ''; ?>" data-modal="modal-profile-change-request" id="modal-profile-change-request">
    <div class="modal-panel">
        
        <!-- MODAL HEADER -->
        <div class="modal-header">
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
            
            <div class="modal-body">

                <!-- 1. BAGIAN PROFIL YANG DIUBAH (CHECKBOX) -->
                <div style="margin-bottom: 22px;">
                    <label style="display: block; font-size: 13.5px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">
                        Bagian Profil yang Diubah <span style="color: #ef4444;">*</span>
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
                <div style="margin-bottom: 22px;">
                    <label for="pcr_reason" style="display: block; font-size: 13.5px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                        Alasan Perubahan <span style="color: #ef4444;">*</span>
                    </label>
                    <div style="position: relative;">
                        <textarea name="pcr_reason" id="pcr_reason" maxlength="500" rows="3" required placeholder="Saya ingin memperbarui alamat tempat usaha karena sudah pindah lokasi, serta memperbarui informasi media sosial (Instagram dan LinkedIn) yang terbaru." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 14px; font-size: 13px; color: #1e293b; line-height: 1.5; resize: vertical; box-sizing: border-box; font-family: inherit; outline: none; transition: border-color 0.2s;"></textarea>
                        <div style="text-align: right; font-size: 11.5px; color: #94a3b8; margin-top: 4px;">
                            <span id="pcr_reason_counter">0</span>/500
                        </div>
                    </div>
                </div>

                <!-- 3. FIELD PROFIL TERPILIH CONTAINER -->
                <div style="margin-bottom: 22px;">
                    <label style="display: block; font-size: 13.5px; font-weight: 700; color: #0f172a; margin-bottom: 10px;">
                        Field Profil Terpilih
                    </label>

                    <!-- PLACEHOLDER IF NO SECTION CHECKED -->
                    <div id="pcr_fields_empty_placeholder" style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 24px; text-align: center; color: #64748b; font-size: 13px;">
                        <i class="fa-regular fa-hand-pointer" style="font-size: 22px; color: #94a3b8; margin-bottom: 8px; display: block;"></i>
                        Pilih minimal satu bagian profil di atas untuk menampilkan field yang ingin diubah.
                    </div>

                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <!-- SECTION 1: IDENTITAS PERORANGAN (SESUAI LANGKAH 1 FORM PROFIL)  -->
                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <div id="pcr_box_identity" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow: visible; margin-bottom: 18px; position: relative;">
                        <div style="background: #f1f5f9; padding: 10px 16px; font-weight: 700; font-size: 13.5px; color: #0369a1; display: flex; align-items: center; justify-content: space-between; border-top-left-radius: 9px; border-top-right-radius: 9px;">
                            <span>1. Identitas Perorangan</span>
                            <span style="font-size: 11px; font-weight: 600; color: #0369a1; background: #e0f2fe; padding: 2px 8px; border-radius: 999px; border: 1px solid #bae6fd;">Jenis Usaha &amp; WhatsApp dapat diedit</span>
                        </div>
                        <div style="padding: 16px 18px; background: #ffffff;">
                            
                            <!-- KETERANGAN FIELD READ ONLY -->
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; line-height: 1.45;">
                                <i class="fa-solid fa-lock" style="color: #94a3b8; margin-right: 5px;"></i> Field Nama Pemberi Kerja, NIK, Nomor Telepon Aktif, dan Status NPWP berstatus <strong>Read-Only</strong> dan terverifikasi dari akun SIAPKerja.
                            </div>

                            <!-- Baris 1: Nama & NIK (Read-Only) -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label">Nama Pemberi Kerja <span class="req">*</span></label>
                                    <input type="text" value="<?php echo $vOwnerName; ?>" readonly style="background: #f8fafc; color: #64748b; cursor: not-allowed;">
                                    <div class="pki-field-helper">Terisi otomatis dari akun SIAPKerja.</div>
                                </div>
                                <div class="pki-field">
                                    <label class="pki-field-label">NIK <span class="req">*</span></label>
                                    <input type="text" value="<?php echo $vNik; ?>" readonly style="background: #f8fafc; color: #64748b; cursor: not-allowed;">
                                    <div class="pki-field-helper">Terisi otomatis dari akun SIAPKerja.</div>
                                </div>
                            </div>

                            <!-- Baris 2: No Telp (Read-Only) & Jenis Usaha (EDITABLE) -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label">Nomor Telepon Aktif <span class="req">*</span></label>
                                    <input type="text" value="<?php echo $vPhone; ?>" readonly style="background: #f8fafc; color: #64748b; cursor: not-allowed;">
                                    <div class="pki-field-helper">Terisi otomatis dari akun SIAPKerja.</div>
                                </div>
                                
                                <!-- Jenis Usaha Individu (EDITABLE DENGAN CUSTOM SELECT SEARCHABLE IDENTIK) -->
                                <div class="pki-field" style="position:relative; z-index:30;">
                                    <label class="pki-field-label" for="pcr_profession_trigger">
                                        Jenis Usaha Individu <span class="req">*</span>
                                        <span class="pki-tooltip tooltip-right" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Pilih jenis usaha perorangan yang Anda jalankan.</span>
                                        </span>
                                    </label>

                                    <input type="hidden" name="pcr_profession" id="pcr_profession" value="<?php echo e($vProfession); ?>">

                                    <div style="position: relative;">
                                        <div class="pki-custom-select-trigger" id="pcr_profession_trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                                            <span class="pki-custom-select-text <?php echo empty($vProfession) ? 'is-placeholder' : ''; ?>" id="pcr_profession_display">
                                                <?php echo !empty($vProfession) ? e($vProfession) : 'Pilih Jenis Usaha Individu'; ?>
                                            </span>
                                            <span class="pki-custom-select-caret">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="m7 15 5 5 5-5"/>
                                                    <path d="m7 9 5-5 5 5"/>
                                                </svg>
                                            </span>
                                        </div>

                                        <div class="pki-custom-select-dropdown" id="pcr_profession_dropdown" style="display:none;" role="listbox">
                                            <div class="pki-custom-select-search-wrap">
                                                <input type="text" class="pki-custom-select-search" id="pcr_profession_search" placeholder="Cari jenis usaha..." autocomplete="off">
                                            </div>
                                            <div class="pki-custom-select-options" id="pcr_profession_options">
                                                <?php foreach ($professionGroups as $groupName => $items): ?>
                                                    <div class="pki-select-group" data-group="<?php echo e($groupName); ?>">
                                                        <div class="pki-select-group-header"><?php echo e($groupName); ?></div>
                                                        <?php foreach ($items as $item): ?>
                                                            <div class="pki-select-option <?php echo ($vProfession === $item) ? 'selected' : ''; ?>" data-value="<?php echo e($item); ?>" role="option">
                                                                <span><?php echo e($item); ?></span>
                                                                <?php if ($vProfession === $item): ?>
                                                                    <i class="fa-solid fa-check pki-select-check"></i>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="pki-select-no-results" id="pcr_profession_no_results" style="display:none;">
                                                    Tidak ada jenis usaha yang sesuai
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="pki-field-helper">Pilih jenis usaha perorangan yang Anda jalankan.</div>
                                </div>
                            </div>

                            <!-- Baris 3: Nomor WhatsApp (EDITABLE) & Status NPWP (Read-Only) -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_whatsapp">
                                        Nomor WhatsApp <span class="req">*</span>
                                    </label>
                                    <input type="text" name="pcr_whatsapp" id="pcr_whatsapp" value="<?php echo $vWhatsapp; ?>" maxlength="15" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="08xxxxxxxxxx">
                                    <div class="pki-field-helper">Gunakan nomor WhatsApp aktif.</div>
                                </div>
                                <div class="pki-field" style="position:relative;">
                                    <label class="pki-field-label">
                                        Status NPWP <span class="req">*</span>
                                        <span class="pki-tooltip tooltip-right" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Informasi: Untuk Wajib Pajak Orang Pribadi yang merupakan penduduk Indonesia, NIK digunakan sebagai NPWP.</span>
                                        </span>
                                    </label>
                                    <div class="pki-checkbox-field">
                                        <label class="pki-checkbox-label">
                                            <input type="checkbox" checked disabled>
                                            <span>Saya terdaftar sebagai Wajib Pajak Orang Pribadi dan menggunakan NIK sebagai NPWP</span>
                                        </label>
                                    </div>
                                    <div class="pki-field-helper">Status NPWP terverifikasi otomatis dari akun.</div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <!-- SECTION 2: TEMPAT USAHA & DESKRIPSI (IDENTIK LANGKAH 2)        -->
                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <div id="pcr_box_workplace" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow: visible; margin-bottom: 18px; position: relative;">
                        <div style="background: #f1f5f9; padding: 10px 16px; font-weight: 700; font-size: 13.5px; color: #0369a1; border-top-left-radius: 9px; border-top-right-radius: 9px;">
                            2. Tempat Usaha / Kegiatan Pemberi Kerja &amp; Deskripsi Usaha
                        </div>
                        <div style="padding: 16px 18px; background: #ffffff;">
                            
                            <!-- Baris 1: Nama Brand Usaha & Logo Usaha -->
                            <div class="pki-grid-2">
                                <div class="pki-field">
                                    <label class="pki-field-label" for="pcr_brand_name">
                                        Nama Brand Usaha
                                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Nama merek dagang, brand, atau nama komersial tempat usaha/kegiatan Anda.</span>
                                        </span>
                                    </label>
                                    <input type="text" name="pcr_brand_name" id="pcr_brand_name" value="<?php echo $vBrandName; ?>" placeholder="Masukkan nama brand usaha">
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
                                    <div class="pki-upload-box" id="pcr_box_business_logo" onclick="pcrTriggerUpload('pcr_business_logo')">
                                        <span class="pki-upload-text <?php echo !empty($vLogo) ? 'has-file' : ''; ?>" id="pcr_text_business_logo">
                                            <?php if (!empty($vLogo)): ?>
                                                <i class="fa-solid fa-image" style="color:#0284c7; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($vLogo)); ?></span>
                                            <?php else: ?>
                                                <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                                            <?php endif; ?>
                                        </span>
                                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                            <?php if (!empty($vLogo)): ?>
                                                <button type="button" class="pki-upload-clear-btn" title="Hapus logo" onclick="pcrClearUpload(event, 'pcr_business_logo')">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="pki-upload-btn" id="pcr_btn_business_logo"><?php echo !empty($vLogo) ? 'Ganti' : 'Pilih File'; ?></button>
                                        </div>
                                    </div>
                                    <input type="file" name="pcr_business_logo" id="pcr_input_business_logo" accept=".png,.jpg,.jpeg" style="display:none;" onchange="pcrHandleFileChange(this, 'pcr_business_logo')">
                                    <input type="hidden" name="pcr_existing_business_logo" id="pcr_existing_business_logo" value="<?php echo e($vLogo); ?>">
                                    <div class="pki-field-helper">Ukuran maksimum 2 MB • Format PNG, JPG</div>
                                    <div class="pki-field-helper" id="pcr_helper_business_logo_size" style="<?php echo $vLogoSizeText !== '' ? '' : 'display:none;'; ?>">
                                        <?php if ($vLogoSizeText !== ''): ?>
                                            Ukuran file saat ini: <?php echo e($vLogoSizeText); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Deskripsi Singkat Usaha -->
                            <div class="pki-field">
                                <label class="pki-field-label" for="pcr_description">
                                    Deskripsi Singkat Usaha / Rekrutmen
                                    <span class="pki-tooltip" tabindex="0" role="tooltip">
                                        <?php echo $infoIconSvg; ?>
                                        <span class="tooltip-popover">Jelaskan secara singkat usaha atau kegiatan yang dijalankan serta gambaran kebutuhan rekrutmen yang dilakukan.</span>
                                    </span>
                                </label>
                                <textarea name="pcr_description" id="pcr_description" placeholder="Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen..." style="min-height:80px;"><?php echo $vDescription; ?></textarea>
                                <div class="pki-field-helper">Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.</div>
                            </div>

                            <!-- Checkbox Gunakan Alamat Domisili SIAPKerja -->
                            <div style="margin-bottom:14px;">
                                <label style="font-size:12.5px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="pcr_same_location_siapkerja" id="pcr_cbSameLocation" value="1" <?php echo $vSameLoc ? 'checked' : ''; ?> style="accent-color:#0284c7; cursor:pointer;" onchange="pcrHandleSameLocationToggle(this)">
                                    <span>Gunakan alamat domisili di SIAPKerja sebagai lokasi tempat usaha/kegiatan</span>
                                </label>
                            </div>

                            <!-- Hidden Location Fields -->
                            <input type="hidden" name="pcr_province" id="pcr_hiddenProvince" value="<?php echo $vProvince; ?>">
                            <input type="hidden" name="pcr_city" id="pcr_hiddenCity" value="<?php echo $vCity; ?>">
                            <input type="hidden" name="pcr_district" id="pcr_hiddenDistrict" value="<?php echo $vDistrict; ?>">
                            <input type="hidden" name="pcr_village" id="pcr_hiddenVillage" value="<?php echo $vVillage; ?>">
                            <input type="hidden" name="pcr_domicile_city_id" id="pcr_hiddenDomicileCityId" value="<?php echo $vCity; ?>">
                            <input type="hidden" name="pcr_latitude" id="pcr_inputLat" value="<?php echo $vLat; ?>">
                            <input type="hidden" name="pcr_longitude" id="pcr_inputLng" value="<?php echo $vLng; ?>">

                            <!-- Lokasi Tempat Usaha / Kegiatan: HIERARCHICAL LOCATION SELECTOR BERJENJANG (IDENTIK FORM PENDAFTARAN) -->
                            <div class="pki-field" style="position:relative; z-index:25;">
                                <label class="pki-field-label" for="pcr_hierarchicalLocationInput">
                                    Lokasi Tempat Usaha / Kegiatan <span class="req">*</span>
                                    <span class="pki-tooltip" tabindex="0" role="tooltip">
                                        <?php echo $infoIconSvg; ?>
                                        <span class="tooltip-popover">Lokasi tempat Anda menjalankan usaha atau kegiatan sebagai Pemberi Kerja Individu.</span>
                                    </span>
                                </label>
                                <div style="position:relative;">
                                    <div id="pcr_hierarchicalLocationInput" class="hierarchical-loc-field" tabindex="0">
                                        <span id="pcr_locDisplayValue" class="<?php echo $hasLocSelected ? '' : 'placeholder'; ?>"><?php echo e($locDisplayText); ?></span>
                                        <i id="pcr_locCaret" class="fa-solid fa-chevron-down" style="color:#64748b; font-size:12px;"></i>
                                    </div>
                                    <div id="pcr_hierarchicalLocDropdown" class="loc-dropdown-popup" style="display:none;">
                                        <div id="pcr_locBreadcrumbs" class="loc-breadcrumbs">
                                            <span class="crumb-btn active" data-level="1">Pilih Provinsi</span>
                                        </div>
                                        <div id="pcr_locOptionsList" class="loc-options-list"></div>
                                    </div>
                                </div>
                                <div class="pki-field-helper">Pilih lokasi secara berjenjang sampai Kelurahan/Desa.</div>
                            </div>

                            <!-- Alamat Lengkap Tempat Usaha -->
                            <div class="pki-field">
                                <label class="pki-field-label" for="pcr_inputAddress">
                                    Alamat Lengkap Tempat Usaha <span class="req">*</span>
                                </label>
                                <input type="text" name="pcr_address" id="pcr_inputAddress" value="<?php echo $vAddress; ?>" placeholder="Masukkan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap...">
                                <div class="pki-field-helper">Tuliskan alamat lengkap tempat usaha/kegiatan.</div>
                            </div>

                            <!-- Kode Pos -->
                            <div class="pki-field">
                                <label class="pki-field-label" for="pcr_selectPostalCode">
                                    Kode Pos <span class="req">*</span>
                                </label>
                                <select name="pcr_postal_code" id="pcr_selectPostalCode">
                                    <option value="">Pilih kode pos</option>
                                    <?php if (!empty($vPostalCode)): ?>
                                        <option value="<?php echo $vPostalCode; ?>" selected><?php echo $vPostalCode; ?></option>
                                    <?php endif; ?>
                                </select>
                                <div class="pki-field-helper">Pilihan kode pos mengikuti lokasi yang dipilih.</div>
                            </div>

                            <!-- Detail Alamat / Patokan (Opsional) -->
                            <div class="pki-field">
                                <label class="pki-field-label" for="pcr_inputAddressDetail">
                                    Detail Alamat / Patokan (Opsional)
                                    <span class="pki-tooltip" tabindex="0" role="tooltip">
                                        <?php echo $infoIconSvg; ?>
                                        <span class="tooltip-popover">Tambahkan informasi yang membantu mengenali lokasi, seperti nomor bangunan, blok, lantai, atau patokan terdekat.</span>
                                    </span>
                                </label>
                                <input type="text" name="pcr_address_detail" id="pcr_inputAddressDetail" value="<?php echo $vAddressDetail; ?>" placeholder="Contoh: Ruko lantai 2, sebelah Kantor Kelurahan">
                                <div class="pki-field-helper">Opsional. Tambahkan informasi yang membantu mengenali lokasi.</div>
                            </div>

                            <!-- Peta Lokasi (Google Maps) -->
                            <div class="pki-field">
                                <label class="pki-field-label" style="margin-bottom:6px;">Peta Lokasi</label>
                                <div id="pcr_mapContainer" class="pki-map-container" style="height:190px;">
                                    <div id="pcr_mapPlaceholder" class="pki-map-placeholder <?php echo $hasFullLocation ? 'is-hidden' : ''; ?>" style="<?php echo $hasFullLocation ? 'display:none;' : 'display:flex;'; ?>">
                                        <div style="font-size:26px; margin-bottom:4px;">🗺</div>
                                        <div style="font-size:13px; font-weight:700; color:#334155; margin-bottom:2px;">Pratinjau peta belum tersedia</div>
                                        <div style="font-size:11.5px; color:#64748b; max-width:340px; line-height:1.4;">Lengkapi lokasi dan alamat terlebih dahulu.</div>
                                    </div>
                                    <div id="pcr_googleMapWrapper" class="pki-map-wrapper <?php echo $hasFullLocation ? 'is-visible' : ''; ?>" style="<?php echo $hasFullLocation ? 'display:block;' : 'display:none;'; ?> width:100%; height:100%;">
                                        <iframe id="pcr_gmapIframe" width="100%" height="100%" style="border:0;" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" data-last-query="<?php echo e($initialMapQuery); ?>" src="<?php echo $initialMapQuery ? 'https://maps.google.com/maps?q=' . urlencode($initialMapQuery) . '&output=embed' : 'about:blank'; ?>"></iframe>
                                    </div>
                                </div>
                                <div class="pki-field-helper" style="margin-top:6px;">Peta muncul otomatis setelah lokasi dan alamat lengkap tersedia.</div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <!-- SECTION 3: DOKUMEN & BUKTI TEMPAT USAHA (IDENTIK LANGKAH 3)     -->
                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <div id="pcr_box_documents" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow: visible; margin-bottom: 18px; position: relative;">
                        <div style="background: #f1f5f9; padding: 10px 16px; font-weight: 700; font-size: 13.5px; color: #0369a1; border-top-left-radius: 9px; border-top-right-radius: 9px;">
                            3. Dokumen &amp; Bukti Tempat Usaha
                        </div>
                        <div style="padding: 16px 18px; background: #ffffff;">
                            
                            <div class="pki-grid-2">
                                <!-- Dokumen Perizinan Usaha -->
                                <div class="pki-field">
                                    <label class="pki-field-label">
                                        Dokumen Perizinan Usaha <span class="req">*</span>
                                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Dokumen yang diunggah harus sesuai dengan Jenis Usaha Individu yang dipilih. Format PDF/JPG, maksimal 2 MB per file.</span>
                                        </span>
                                    </label>
                                    <div class="pki-upload-box" id="pcr_box_permit_document" onclick="pcrTriggerUpload('pcr_permit_document')">
                                        <span class="pki-upload-text <?php echo !empty($vPermitDoc) ? 'has-file' : ''; ?>" id="pcr_text_permit_document">
                                            <?php if (!empty($vPermitDoc)): ?>
                                                <i class="fa-solid fa-file-pdf" style="color:#ef4444; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($vPermitDoc)); ?></span>
                                            <?php else: ?>
                                                <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                                            <?php endif; ?>
                                        </span>
                                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                            <?php if (!empty($vPermitDoc)): ?>
                                                <button type="button" class="pki-upload-clear-btn" title="Hapus berkas" onclick="pcrClearUpload(event, 'pcr_permit_document')">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="pki-upload-btn" id="pcr_btn_permit_document"><?php echo !empty($vPermitDoc) ? 'Ganti' : 'Pilih File'; ?></button>
                                        </div>
                                    </div>
                                    <input type="file" name="pcr_permit_document" id="pcr_input_permit_document" accept=".pdf,.docx,.jpg,.jpeg" style="display:none;" onchange="pcrHandleFileChange(this, 'pcr_permit_document')">
                                    <input type="hidden" name="pcr_existing_permit_document" id="pcr_existing_permit_document" value="<?php echo e($vPermitDoc); ?>">
                                    <div class="pki-field-helper" id="pcr_helper_permit_document">
                                        <?php echo function_exists('get_permit_document_helper_html') ? get_permit_document_helper_html($vProfession) : '<div class="pki-permit-doc-helper-wrap"><div class="pki-permit-doc-prompt" style="color:#64748b; line-height:1.45; margin-bottom:3px;">Pilih Jenis Usaha Individu terlebih dahulu untuk melihat dokumen yang perlu diunggah.</div><div class="pki-permit-doc-format" style="color:#64748b; font-size:11px;">Format PDF/JPG, maksimal 2 MB per file.</div></div>'; ?>
                                    </div>
                                    <div class="pki-field-helper" id="pcr_helper_permit_document_size" style="<?php echo $vPermitDocSizeText !== '' ? '' : 'display:none;'; ?>">
                                        <?php if ($vPermitDocSizeText !== ''): ?>
                                            Ukuran file saat ini: <?php echo e($vPermitDocSizeText); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Foto Bukti Tempat Usaha / Lokasi -->
                                <div class="pki-field">
                                    <label class="pki-field-label">
                                        Foto Bukti Tempat Usaha / Lokasi <span class="req">*</span>
                                        <span class="pki-tooltip" tabindex="0" role="tooltip">
                                            <?php echo $infoIconSvg; ?>
                                            <span class="tooltip-popover">Unggah foto yang menunjukkan kondisi tempat usaha, lokasi kegiatan, dan/atau sarana pendukung usaha sebagai bukti aktivitas usaha.</span>
                                        </span>
                                    </label>
                                    <div class="pki-upload-box" id="pcr_box_workplace_photo" onclick="pcrTriggerUpload('pcr_workplace_photo')">
                                        <span class="pki-upload-text <?php echo !empty($vPhoto) ? 'has-file' : ''; ?>" id="pcr_text_workplace_photo">
                                            <?php if (!empty($vPhoto)): ?>
                                                <i class="fa-solid fa-image" style="color:#0284c7; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($vPhoto)); ?></span>
                                            <?php else: ?>
                                                <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                                            <?php endif; ?>
                                        </span>
                                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                            <?php if (!empty($vPhoto)): ?>
                                                <button type="button" class="pki-upload-clear-btn" title="Hapus foto" onclick="pcrClearUpload(event, 'pcr_workplace_photo')">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="pki-upload-btn" id="pcr_btn_workplace_photo"><?php echo !empty($vPhoto) ? 'Ganti' : 'Pilih File'; ?></button>
                                        </div>
                                    </div>
                                    <input type="file" name="pcr_workplace_photo" id="pcr_input_workplace_photo" accept=".png,.jpg,.jpeg" style="display:none;" onchange="pcrHandleFileChange(this, 'pcr_workplace_photo')">
                                    <input type="hidden" name="pcr_existing_workplace_photo" id="pcr_existing_workplace_photo" value="<?php echo e($vPhoto); ?>">
                                    <div class="pki-field-helper" id="pcr_helper_workplace_photo">
                                        <div class="pki-permit-doc-helper-wrap">
                                            <div class="pki-permit-doc-title" style="font-weight:600; color:#334155; margin-bottom:2px;">Foto yang perlu diunggah:</div>
                                            <div class="pki-permit-doc-requirement" style="color:#475569; margin-bottom:4px; line-height:1.45;">Foto tempat usaha, lokasi kegiatan, atau sarana pendukung usaha.</div>
                                            <div class="pki-permit-doc-format" style="color:#64748b; font-size:11px;">Format PNG/JPG, maksimal 2 MB per file.</div>
                                        </div>
                                    </div>
                                    <div class="pki-field-helper" id="pcr_helper_workplace_photo_size" style="<?php echo $vPhotoSizeText !== '' ? '' : 'display:none;'; ?>">
                                        <?php if ($vPhotoSizeText !== ''): ?>
                                            Ukuran file saat ini: <?php echo e($vPhotoSizeText); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <!-- SECTION 4: MEDIA SOSIAL (IDENTIK LANGKAH 4)                     -->
                    <!-- ═══════════════════════════════════════════════════════════════ -->
                    <div id="pcr_box_social" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow: visible; margin-bottom: 18px; position: relative;">
                        <div style="background: #f1f5f9; padding: 10px 16px; font-weight: 700; font-size: 13.5px; color: #0369a1; border-top-left-radius: 9px; border-top-right-radius: 9px;">
                            4. Media Sosial
                        </div>
                        <div style="padding: 16px 18px; background: #ffffff;">
                            
                            <div style="font-size:12px; color:#64748b; margin-bottom:14px;">Wajib mengisi minimal salah satu akun media sosial (LinkedIn, Facebook, Instagram, atau TikTok).</div>

                            <!-- Hidden compatibility fields synced by JS -->
                            <input type="hidden" name="pcr_linkedin" id="pcr_linkedin" value="<?php echo $vLinkedin; ?>">
                            <input type="hidden" name="pcr_facebook" id="pcr_facebook" value="<?php echo $vFacebook; ?>">
                            <input type="hidden" name="pcr_instagram" id="pcr_instagram" value="<?php echo $vInstagram; ?>">
                            <input type="hidden" name="pcr_tiktok" id="pcr_tiktok" value="<?php echo $vTiktok; ?>">

                            <div class="pki-social-container" id="pcr_social_container">
                                <?php 
                                $socialIcons = [
                                    'LinkedIn' => ['icon' => 'fa-brands fa-linkedin', 'color' => '#0a66c2', 'placeholder' => 'username atau https://linkedin.com/in/...'],
                                    'Facebook' => ['icon' => 'fa-brands fa-facebook', 'color' => '#1877f2', 'placeholder' => '@username atau https://facebook.com/...'],
                                    'Instagram' => ['icon' => 'fa-brands fa-instagram', 'color' => '#e1306c', 'placeholder' => '@username atau https://instagram.com/...'],
                                    'TikTok' => ['icon' => 'fa-brands fa-tiktok', 'color' => '#000000', 'placeholder' => '@username atau https://tiktok.com/@...'],
                                ];
                                $totalSoc = count($existingSocials);
                                foreach ($existingSocials as $sIdx => $socItem): 
                                    $curPlat = $socItem['platform'];
                                    $curHandle = $socItem['handle'];
                                    $platMeta = $socialIcons[$curPlat] ?? $socialIcons['LinkedIn'];
                                    $isLast = ($sIdx === $totalSoc - 1);
                                ?>
                                <div class="pki-social-row" data-index="<?php echo $sIdx; ?>">
                                    <div class="pki-social-select-wrap">
                                        <span class="pki-social-platform-icon">
                                            <i class="<?php echo $platMeta['icon']; ?>" style="color:<?php echo $platMeta['color']; ?>;"></i>
                                        </span>
                                        <select name="pcr_social_platform[]" class="pki-social-platform-select" onchange="pcrUpdateSocialIcon(this)">
                                            <option value="LinkedIn" <?php echo $curPlat === 'LinkedIn' ? 'selected' : ''; ?>>LinkedIn</option>
                                            <option value="Facebook" <?php echo $curPlat === 'Facebook' ? 'selected' : ''; ?>>Facebook</option>
                                            <option value="Instagram" <?php echo $curPlat === 'Instagram' ? 'selected' : ''; ?>>Instagram</option>
                                            <option value="TikTok" <?php echo $curPlat === 'TikTok' ? 'selected' : ''; ?>>TikTok</option>
                                        </select>
                                    </div>
                                    <div class="pki-social-input-wrap">
                                        <input type="text" name="pcr_social_handle[]" class="pki-social-handle-input" value="<?php echo $curHandle; ?>" placeholder="<?php echo $platMeta['placeholder']; ?>" autocomplete="off" oninput="pcrSyncSocialFields()">
                                    </div>
                                    <div class="pki-social-actions">
                                        <?php if ($totalSoc > 1): ?>
                                            <button type="button" class="pki-social-btn pki-social-btn-del" onclick="pcrRemoveSocialRow(this)" title="Hapus akun"><i class="fa-solid fa-trash-can"></i></button>
                                        <?php endif; ?>
                                        <?php if ($isLast && $totalSoc < 4): ?>
                                            <button type="button" class="pki-social-btn pki-social-btn-add" onclick="pcrAddSocialRow()" title="Tambah akun"><i class="fa-solid fa-plus"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                        </div>
                    </div>

                </div>

                <!-- 4. KONFIRMASI PERUBAHAN CALLOUT BOX -->
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; display: flex; align-items: flex-start; gap: 12px;">
                    <i class="fa-solid fa-circle-info" style="color: #0284c7; font-size: 18px; flex-shrink: 0; margin-top: 2px;"></i>
                    <div style="font-size: 12.5px; line-height: 1.5; color: #1e3a8a;">
                        <strong style="display: block; font-size: 13px; font-weight: 700; color: #1e40af; margin-bottom: 2px;">Konfirmasi Perubahan</strong>
                        <span id="pcr_confirmation_text">Anda akan mengajukan perubahan pada bagian: <strong id="pcr_confirm_sections_list">-</strong>.</span>
                    </div>
                </div>

                <!-- 5. USER CONSENT CHECKBOX -->
                <div style="margin-bottom: 6px;">
                    <label style="display: inline-flex; align-items: flex-start; gap: 10px; font-size: 12.5px; color: #1e293b; font-weight: 500; cursor: pointer; user-select: none;">
                        <input type="checkbox" name="pcr_consent" id="pcr_consent" value="1" required style="width: 16px; height: 16px; accent-color: #0284c7; cursor: pointer; margin-top: 2px; flex-shrink: 0;">
                        <span>Saya telah membaca dan menyetujui pengajuan perubahan profil untuk diperiksa oleh Admin. <span style="color: #ef4444;">*</span></span>
                    </label>
                </div>

            </div>

            <!-- MODAL FOOTER -->
            <div class="modal-footer">
                <button type="button" class="ghost-btn" data-close-modal="modal-profile-change-request" style="height: 38px; padding: 0 18px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #475569; background: #f8fafc; border: 1px solid #cbd5e1; cursor: pointer;">
                    Batal
                </button>
                <button type="submit" class="primary-btn" id="btnSubmitPCR" style="height: 38px; padding: 0 22px; border-radius: 8px; font-size: 13px; font-weight: 700; color: #ffffff; background: #0284c7; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.2);">
                    Ajukan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ─── PCR FILE UPLOAD HELPERS ──────────────────────────────────────────────────
function pcrTriggerUpload(fieldId) {
    const input = document.getElementById('pcr_input_' + fieldId.replace('pcr_', ''));
    if (input) input.click();
}

function pcrHandleFileChange(input, fieldId) {
    const rawId = fieldId.replace('pcr_', '');
    const textBox = document.getElementById('pcr_text_' + rawId);
    const btn = document.getElementById('pcr_btn_' + rawId);

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const isPdf = file.name.endsWith('.pdf') || file.name.endsWith('.docx');
        const iconClass = isPdf ? 'fa-solid fa-file-pdf' : 'fa-solid fa-image';
        const iconColor = isPdf ? '#ef4444' : '#0284c7';

        if (textBox) {
            textBox.className = 'pki-upload-text has-file';
            textBox.innerHTML = `<i class="${iconClass}" style="color:${iconColor}; flex-shrink:0;"></i> <span class="pki-upload-filename">${file.name}</span>`;
        }
        if (btn) btn.textContent = 'Ganti';
    }
}

function pcrClearUpload(event, fieldId) {
    if (event) event.stopPropagation();
    const rawId = fieldId.replace('pcr_', '');
    const input = document.getElementById('pcr_input_' + rawId);
    const existing = document.getElementById('pcr_existing_' + rawId);
    const textBox = document.getElementById('pcr_text_' + rawId);
    const btn = document.getElementById('pcr_btn_' + rawId);

    if (input) input.value = '';
    if (existing) existing.value = '';
    if (textBox) {
        textBox.className = 'pki-upload-text';
        textBox.innerHTML = '<span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>';
    }
    if (btn) btn.textContent = 'Pilih File';
    if (event && event.target) {
        const clearBtn = event.target.closest('.pki-upload-clear-btn');
        if (clearBtn) clearBtn.remove();
    }
}

// ─── PCR SOCIAL MEDIA ROW HELPERS ─────────────────────────────────────────────
const PCR_SOCIAL_ICONS = {
    'LinkedIn': { icon: 'fa-brands fa-linkedin', color: '#0a66c2', placeholder: 'username atau https://linkedin.com/in/...' },
    'Facebook': { icon: 'fa-brands fa-facebook', color: '#1877f2', placeholder: '@username atau https://facebook.com/...' },
    'Instagram': { icon: 'fa-brands fa-instagram', color: '#e1306c', placeholder: '@username atau https://instagram.com/...' },
    'TikTok': { icon: 'fa-brands fa-tiktok', color: '#000000', placeholder: '@username atau https://tiktok.com/@...' }
};

function pcrUpdateSocialIcon(select) {
    const row = select.closest('.pki-social-row');
    if (!row) return;
    const plat = select.value;
    const meta = PCR_SOCIAL_ICONS[plat] || PCR_SOCIAL_ICONS['LinkedIn'];
    const icon = row.querySelector('.pki-social-platform-icon i');
    if (icon) {
        icon.className = meta.icon;
        icon.style.color = meta.color;
    }
    const input = row.querySelector('.pki-social-handle-input');
    if (input) {
        input.placeholder = meta.placeholder;
    }
    pcrSyncSocialFields();
}

function pcrSyncSocialFields() {
    let li = '', fb = '', ig = '', tt = '';
    const rows = document.querySelectorAll('#pcr_social_container .pki-social-row');
    rows.forEach(r => {
        const plat = r.querySelector('.pki-social-platform-select')?.value?.toLowerCase();
        const handle = r.querySelector('.pki-social-handle-input')?.value?.trim();
        if (!handle) return;
        if (plat === 'linkedin' && !li) li = handle;
        if (plat === 'facebook' && !fb) fb = handle;
        if (plat === 'instagram' && !ig) ig = handle;
        if (plat === 'tiktok' && !tt) tt = handle;
    });
    const elLi = document.getElementById('pcr_linkedin');
    const elFb = document.getElementById('pcr_facebook');
    const elIg = document.getElementById('pcr_instagram');
    const elTt = document.getElementById('pcr_tiktok');
    if (elLi) elLi.value = li;
    if (elFb) elFb.value = fb;
    if (elIg) elIg.value = ig;
    if (elTt) elTt.value = tt;
}

function pcrAddSocialRow() {
    const container = document.getElementById('pcr_social_container');
    if (!container) return;
    const rows = container.querySelectorAll('.pki-social-row');
    if (rows.length >= 4) return;

    const used = Array.from(rows).map(r => r.querySelector('.pki-social-platform-select')?.value);
    const available = ['LinkedIn', 'Facebook', 'Instagram', 'TikTok'].filter(p => !used.includes(p));
    const nextPlat = available[0] || 'Instagram';
    const meta = PCR_SOCIAL_ICONS[nextPlat] || PCR_SOCIAL_ICONS['Instagram'];
    const newIdx = rows.length;

    const row = document.createElement('div');
    row.className = 'pki-social-row';
    row.dataset.index = newIdx;
    row.innerHTML = `
        <div class="pki-social-select-wrap">
            <span class="pki-social-platform-icon">
                <i class="${meta.icon}" style="color:${meta.color};"></i>
            </span>
            <select name="pcr_social_platform[]" class="pki-social-platform-select" onchange="pcrUpdateSocialIcon(this)">
                <option value="LinkedIn" ${nextPlat === 'LinkedIn' ? 'selected' : ''}>LinkedIn</option>
                <option value="Facebook" ${nextPlat === 'Facebook' ? 'selected' : ''}>Facebook</option>
                <option value="Instagram" ${nextPlat === 'Instagram' ? 'selected' : ''}>Instagram</option>
                <option value="TikTok" ${nextPlat === 'TikTok' ? 'selected' : ''}>TikTok</option>
            </select>
        </div>
        <div class="pki-social-input-wrap">
            <input type="text" name="pcr_social_handle[]" class="pki-social-handle-input" placeholder="${meta.placeholder}" autocomplete="off" oninput="pcrSyncSocialFields()">
        </div>
        <div class="pki-social-actions">
            <button type="button" class="pki-social-btn pki-social-btn-del" onclick="pcrRemoveSocialRow(this)" title="Hapus akun"><i class="fa-solid fa-trash-can"></i></button>
            <button type="button" class="pki-social-btn pki-social-btn-add" onclick="pcrAddSocialRow()" title="Tambah akun"><i class="fa-solid fa-plus"></i></button>
        </div>
    `;
    container.appendChild(row);
    pcrRefreshSocialActionButtons();
}

function pcrRemoveSocialRow(btn) {
    const row = btn.closest('.pki-social-row');
    if (!row) return;
    row.remove();
    pcrRefreshSocialActionButtons();
    pcrSyncSocialFields();
}

function pcrRefreshSocialActionButtons() {
    const container = document.getElementById('pcr_social_container');
    if (!container) return;
    const rows = container.querySelectorAll('.pki-social-row');
    rows.forEach((r, idx) => {
        const actions = r.querySelector('.pki-social-actions');
        if (!actions) return;
        const isLast = (idx === rows.length - 1);
        let html = '';
        if (rows.length > 1) {
            html += `<button type="button" class="pki-social-btn pki-social-btn-del" onclick="pcrRemoveSocialRow(this)" title="Hapus akun"><i class="fa-solid fa-trash-can"></i></button>`;
        }
        if (isLast && rows.length < 4) {
            html += `<button type="button" class="pki-social-btn pki-social-btn-add" onclick="pcrAddSocialRow()" title="Tambah akun"><i class="fa-solid fa-plus"></i></button>`;
        }
        actions.innerHTML = html;
    });
}

// ─── PCR DOMISILI AUTOFIL CHECKBOX ───────────────────────────────────────────
function pcrHandleSameLocationToggle(cb) {
    if (!cb.checked) return;
    const dom = window.PKI_SIAPKERJA_DOMICILE;
    if (!dom) return;

    const elProv = document.getElementById('pcr_hiddenProvince');
    const elCity = document.getElementById('pcr_hiddenCity');
    const elDist = document.getElementById('pcr_hiddenDistrict');
    const elVill = document.getElementById('pcr_hiddenVillage');
    const elDom = document.getElementById('pcr_hiddenDomicileCityId');
    const elAddr = document.getElementById('pcr_inputAddress');
    const elDisp = document.getElementById('pcr_locDisplayValue');
    const elPost = document.getElementById('pcr_selectPostalCode');

    if (elProv) elProv.value = dom.province;
    if (elCity) elCity.value = dom.city;
    if (elDom) elDom.value = dom.city;
    if (elDist) elDist.value = dom.district;
    if (elVill) elVill.value = dom.village;
    if (elAddr && !elAddr.value.trim()) elAddr.value = dom.address;

    if (elDisp) {
        elDisp.textContent = `${dom.village}, ${dom.district}, ${dom.city}, ${dom.province}`;
        elDisp.classList.remove('placeholder');
    }

    if (elPost && dom.postal_code) {
        elPost.innerHTML = `<option value="${dom.postal_code}" selected>${dom.postal_code}</option>`;
    }

    // Refresh map
    pcrUpdateMapPreview();
}

function pcrUpdateMapPreview() {
    const addr = document.getElementById('pcr_inputAddress')?.value?.trim() || '';
    const vill = document.getElementById('pcr_hiddenVillage')?.value?.trim() || '';
    const dist = document.getElementById('pcr_hiddenDistrict')?.value?.trim() || '';
    const city = document.getElementById('pcr_hiddenCity')?.value?.trim() || '';
    const prov = document.getElementById('pcr_hiddenProvince')?.value?.trim() || '';
    const iframe = document.getElementById('pcr_gmapIframe');
    const wrapper = document.getElementById('pcr_googleMapWrapper');
    const placeholder = document.getElementById('pcr_mapPlaceholder');

    if (!iframe || !wrapper || !placeholder) return;

    if (addr && (vill || city || prov)) {
        const parts = [addr, vill, dist, city, prov, 'Indonesia'].filter(Boolean);
        const query = parts.join(', ');
        const embedUrl = 'https://maps.google.com/maps?q=' + encodeURIComponent(query) + '&output=embed';
        if (iframe.getAttribute('data-last-query') !== query) {
            iframe.setAttribute('data-last-query', query);
            iframe.src = embedUrl;
        }
        placeholder.classList.add('is-hidden');
        placeholder.style.display = 'none';
        wrapper.classList.add('is-visible');
        wrapper.style.display = 'block';
    } else {
        placeholder.classList.remove('is-hidden');
        placeholder.style.display = 'flex';
        wrapper.classList.remove('is-visible');
        wrapper.style.display = 'none';
        iframe.src = 'about:blank';
        iframe.removeAttribute('data-last-query');
    }
}

// ─── INITIALIZATION ON MODAL LOAD ─────────────────────────────────────────────
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
        if (boxWorkplace) {
            const isWp = checkedValues.includes('workplace');
            boxWorkplace.style.display = isWp ? 'block' : 'none';
            if (isWp) {
                setTimeout(pcrUpdateMapPreview, 50);
            }
        }
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

    // ─── PCR PROFESSION CUSTOM DROPDOWN (SEARCHABLE IDENTIK) ───
    const profTrigger = document.getElementById('pcr_profession_trigger');
    const profDropdown = document.getElementById('pcr_profession_dropdown');
    const profSearch = document.getElementById('pcr_profession_search');
    const profOptions = document.getElementById('pcr_profession_options');
    const profDisplay = document.getElementById('pcr_profession_display');
    const profHidden = document.getElementById('pcr_profession');

    function updatePcrPermitDocHelper(profession) {
        const helper = document.getElementById('pcr_helper_permit_document');
        if (!helper) return;
        if (typeof window.getPermitDocHelperHtml === 'function') {
            helper.innerHTML = window.getPermitDocHelperHtml(profession);
        } else if (window.PERMIT_DOC_REQUIREMENTS) {
            const p = (profession || '').trim();
            if (p && window.PERMIT_DOC_REQUIREMENTS[p]) {
                helper.innerHTML = '<div class="pki-permit-doc-helper-wrap">' +
                    '<div style="font-weight:600; color:#334155; margin-bottom:2px;">Dokumen yang perlu diunggah:</div>' +
                    '<div style="color:#475569; margin-bottom:4px; line-height:1.45;">' + window.PERMIT_DOC_REQUIREMENTS[p] + '</div>' +
                    '<div style="color:#64748b; font-size:11px;">Format PDF/JPG, maksimal 2 MB per file.</div>' +
                    '</div>';
            } else {
                helper.innerHTML = '<div class="pki-permit-doc-helper-wrap">' +
                    '<div style="color:#64748b; line-height:1.45; margin-bottom:3px;">Pilih Jenis Usaha Individu terlebih dahulu untuk melihat dokumen yang perlu diunggah.</div>' +
                    '<div style="color:#64748b; font-size:11px;">Format PDF/JPG, maksimal 2 MB per file.</div>' +
                    '</div>';
            }
        }
    }

    if (profHidden && profHidden.value) {
        updatePcrPermitDocHelper(profHidden.value);
    }

    if (profTrigger && profDropdown) {
        profTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = profDropdown.style.display === 'block';
            profDropdown.style.display = isOpen ? 'none' : 'block';
            profTrigger.setAttribute('aria-expanded', !isOpen);
            if (!isOpen && profSearch) {
                setTimeout(() => profSearch.focus(), 50);
            }
        });

        document.addEventListener('click', function(e) {
            if (!profTrigger.contains(e.target) && !profDropdown.contains(e.target)) {
                profDropdown.style.display = 'none';
                profTrigger.setAttribute('aria-expanded', 'false');
            }
        });

        if (profSearch) {
            profSearch.addEventListener('input', function() {
                const q = this.value.toLowerCase().trim();
                const groups = profOptions.querySelectorAll('.pki-select-group');
                let totalVis = 0;
                groups.forEach(g => {
                    let groupVis = 0;
                    g.querySelectorAll('.pki-select-option').forEach(opt => {
                        const val = (opt.getAttribute('data-value') || opt.textContent).toLowerCase();
                        if (!q || val.includes(q)) {
                            opt.style.display = 'flex';
                            groupVis++;
                            totalVis++;
                        } else {
                            opt.style.display = 'none';
                        }
                    });
                    g.style.display = groupVis > 0 ? 'block' : 'none';
                });
                const noRes = document.getElementById('pcr_profession_no_results');
                if (noRes) noRes.style.display = totalVis === 0 ? 'block' : 'none';
            });
        }

        if (profOptions) {
            profOptions.addEventListener('click', function(e) {
                const opt = e.target.closest('.pki-select-option');
                if (!opt) return;
                const val = opt.getAttribute('data-value') || opt.textContent.trim();
                if (profHidden) profHidden.value = val;
                if (profDisplay) {
                    profDisplay.textContent = val;
                    profDisplay.classList.remove('is-placeholder');
                }
                profOptions.querySelectorAll('.pki-select-option').forEach(o => {
                    o.classList.remove('selected');
                    const c = o.querySelector('.pki-select-check');
                    if (c) c.remove();
                });
                opt.classList.add('selected');
                if (!opt.querySelector('.pki-select-check')) {
                    const check = document.createElement('i');
                    check.className = 'fa-solid fa-check pki-select-check';
                    opt.appendChild(check);
                }
                updatePcrPermitDocHelper(val);
                profDropdown.style.display = 'none';
                profTrigger.setAttribute('aria-expanded', 'false');
                profTrigger.focus();
            });
        }
    }

    // ─── PCR HIERARCHICAL LOCATION SELECTOR (IDENTIK PKI-FORM) ───
    const locInput = document.getElementById('pcr_hierarchicalLocationInput');
    const locDropdown = document.getElementById('pcr_hierarchicalLocDropdown');
    const locDisplay = document.getElementById('pcr_locDisplayValue');
    const locCaret = document.getElementById('pcr_locCaret');
    const breadcrumbs = document.getElementById('pcr_locBreadcrumbs');
    const optionsList = document.getElementById('pcr_locOptionsList');

    let pcrLocLevel = 1;
    let pcrSelProv = document.getElementById('pcr_hiddenProvince')?.value || '';
    let pcrSelCity = document.getElementById('pcr_hiddenCity')?.value || '';
    let pcrSelDistrict = document.getElementById('pcr_hiddenDistrict')?.value || '';
    let pcrSelVillage = document.getElementById('pcr_hiddenVillage')?.value || '';

    function getDb() {
        return window.PKI_LOCATION_DATABASE || {};
    }

    if (locInput && locDropdown) {
        locInput.addEventListener('click', function(e) {
            e.stopPropagation();
            if (locDropdown.style.display === 'block') {
                closeLocDropdown();
            } else {
                openLocDropdown();
            }
        });

        locDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        document.addEventListener('click', function(e) {
            if (!locInput.contains(e.target) && !locDropdown.contains(e.target)) {
                closeLocDropdown();
            }
        });

        function openLocDropdown() {
            locDropdown.style.display = 'block';
            if (locCaret) {
                locCaret.classList.remove('fa-chevron-down');
                locCaret.classList.add('fa-chevron-up');
            }
            const db = getDb();
            if (pcrSelProv && pcrSelCity && pcrSelDistrict && db[pcrSelProv]?.[pcrSelCity]?.[pcrSelDistrict]) {
                pcrLocLevel = 4;
            } else if (pcrSelProv && pcrSelCity && db[pcrSelProv]?.[pcrSelCity]) {
                pcrLocLevel = 3;
            } else if (pcrSelProv && db[pcrSelProv]) {
                pcrLocLevel = 2;
            } else {
                pcrLocLevel = 1;
            }
            renderLocView();
        }

        function closeLocDropdown() {
            locDropdown.style.display = 'none';
            if (locCaret) {
                locCaret.classList.remove('fa-chevron-up');
                locCaret.classList.add('fa-chevron-down');
            }
        }

        function renderLocView() {
            renderBreadcrumbs();
            renderOptions();
        }

        function renderBreadcrumbs() {
            if (!breadcrumbs) return;
            let html = '';
            if (pcrLocLevel === 1) {
                html += `<span class="crumb-btn active" onclick="pcrSwitchLevel(1)">Pilih Provinsi</span>`;
            } else if (pcrLocLevel === 2) {
                html += `<span class="crumb-btn" onclick="pcrSwitchLevel(1)">${pcrSelProv}</span> <span class="crumb-sep">›</span> `;
                html += `<span class="crumb-btn active" onclick="pcrSwitchLevel(2)">Pilih Kabupaten / Kota</span>`;
            } else if (pcrLocLevel === 3) {
                html += `<span class="crumb-btn" onclick="pcrSwitchLevel(1)">${pcrSelProv}</span> <span class="crumb-sep">›</span> `;
                html += `<span class="crumb-btn" onclick="pcrSwitchLevel(2)">${pcrSelCity}</span> <span class="crumb-sep">›</span> `;
                html += `<span class="crumb-btn active" onclick="pcrSwitchLevel(3)">Pilih Kecamatan</span>`;
            } else if (pcrLocLevel === 4) {
                html += `<span class="crumb-btn" onclick="pcrSwitchLevel(1)">${pcrSelProv}</span> <span class="crumb-sep">›</span> `;
                html += `<span class="crumb-btn" onclick="pcrSwitchLevel(2)">${pcrSelCity}</span> <span class="crumb-sep">›</span> `;
                html += `<span class="crumb-btn" onclick="pcrSwitchLevel(3)">${pcrSelDistrict}</span> <span class="crumb-sep">›</span> `;
                html += `<span class="crumb-btn active" onclick="pcrSwitchLevel(4)">Pilih Kelurahan / Desa</span>`;
            }
            breadcrumbs.innerHTML = html;
        }

        function renderOptions() {
            if (!optionsList) return;
            optionsList.innerHTML = '';
            const db = getDb();

            if (pcrLocLevel === 1) {
                const provinces = Object.keys(db);
                provinces.forEach(prov => {
                    const isSel = prov === pcrSelProv;
                    const row = document.createElement('div');
                    row.className = 'loc-opt-row' + (isSel ? ' selected' : '');
                    row.innerHTML = `
                        <div style="display:flex; align-items:center;">
                            <span class="radio-bullet"></span>
                            <span>${prov}</span>
                        </div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    `;
                    row.onclick = () => window.pcrSelectProv(prov);
                    optionsList.appendChild(row);
                });
            } else if (pcrLocLevel === 2) {
                const cities = Object.keys(db[pcrSelProv] || {});
                cities.forEach(city => {
                    const isSel = city === pcrSelCity;
                    const row = document.createElement('div');
                    row.className = 'loc-opt-row' + (isSel ? ' selected' : '');
                    row.innerHTML = `
                        <div style="display:flex; align-items:center;">
                            <span class="radio-bullet"></span>
                            <span>${city}</span>
                        </div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    `;
                    row.onclick = () => window.pcrSelectCity(city);
                    optionsList.appendChild(row);
                });
            } else if (pcrLocLevel === 3) {
                const districts = Object.keys(db[pcrSelProv]?.[pcrSelCity] || {});
                districts.forEach(dist => {
                    const isSel = dist === pcrSelDistrict;
                    const row = document.createElement('div');
                    row.className = 'loc-opt-row' + (isSel ? ' selected' : '');
                    row.innerHTML = `
                        <div style="display:flex; align-items:center;">
                            <span class="radio-bullet"></span>
                            <span>${dist}</span>
                        </div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    `;
                    row.onclick = () => window.pcrSelectDistrict(dist);
                    optionsList.appendChild(row);
                });
            } else if (pcrLocLevel === 4) {
                const villagesObj = db[pcrSelProv]?.[pcrSelCity]?.[pcrSelDistrict] || {};
                const villages = Object.keys(villagesObj);
                villages.forEach(vill => {
                    const isSel = vill === pcrSelVillage;
                    const postal = villagesObj[vill];
                    const row = document.createElement('div');
                    row.className = 'loc-opt-row' + (isSel ? ' selected' : '');
                    row.innerHTML = `
                        <div style="display:flex; align-items:center;">
                            <span class="radio-bullet"></span>
                            <span>${vill}</span>
                        </div>
                        <span style="font-size:12px; color:#64748b;">${postal}</span>
                    `;
                    row.onclick = () => window.pcrSelectVillage(vill, postal);
                    optionsList.appendChild(row);
                });
            }
        }

        window.pcrSwitchLevel = function(lvl) {
            pcrLocLevel = lvl;
            renderLocView();
        };

        window.pcrSelectProv = function(prov) {
            pcrSelProv = prov;
            pcrSelCity = '';
            pcrSelDistrict = '';
            pcrSelVillage = '';
            document.getElementById('pcr_hiddenProvince').value = prov;
            document.getElementById('pcr_hiddenCity').value = '';
            document.getElementById('pcr_hiddenDistrict').value = '';
            document.getElementById('pcr_hiddenVillage').value = '';
            pcrLocLevel = 2;
            renderLocView();
        };

        window.pcrSelectCity = function(city) {
            pcrSelCity = city;
            pcrSelDistrict = '';
            pcrSelVillage = '';
            document.getElementById('pcr_hiddenCity').value = city;
            document.getElementById('pcr_hiddenDomicileCityId').value = city;
            document.getElementById('pcr_hiddenDistrict').value = '';
            document.getElementById('pcr_hiddenVillage').value = '';
            pcrLocLevel = 3;
            renderLocView();
        };

        window.pcrSelectDistrict = function(dist) {
            pcrSelDistrict = dist;
            pcrSelVillage = '';
            document.getElementById('pcr_hiddenDistrict').value = dist;
            document.getElementById('pcr_hiddenVillage').value = '';
            pcrLocLevel = 4;
            renderLocView();
        };

        window.pcrSelectVillage = function(vill, postal) {
            pcrSelVillage = vill;
            document.getElementById('pcr_hiddenVillage').value = vill;
            if (locDisplay) {
                locDisplay.textContent = `${vill}, ${pcrSelDistrict}, ${pcrSelCity}, ${pcrSelProv}`;
                locDisplay.classList.remove('placeholder');
            }
            const postSelect = document.getElementById('pcr_selectPostalCode');
            if (postSelect && postal) {
                postSelect.innerHTML = `<option value="${postal}" selected>${postal}</option>`;
            }
            closeLocDropdown();
            pcrUpdateMapPreview();
        };
    }

    // Address input triggers map update
    const addrInput = document.getElementById('pcr_inputAddress');
    if (addrInput) {
        addrInput.addEventListener('input', pcrUpdateMapPreview);
    }

    // Listen for modal open event
    const modalEl = document.getElementById('modal-profile-change-request');
    if (modalEl) {
        modalEl.addEventListener('modal:open', function() {
            updatePcrSections();
            pcrUpdateMapPreview();
        });
    }

    // Default trigger on init
    updatePcrSections();
    pcrUpdateMapPreview();
})();
</script>
