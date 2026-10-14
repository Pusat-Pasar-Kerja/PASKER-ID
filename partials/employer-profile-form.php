<?php
/**
 * Shared Form Profil Pemberi Kerja Individu (Multi-step 4 Langkah)
 * Variables available:
 * - $isRevision (bool): true if editing/revision, false if initial registration
 * - $formData (array): user profile data to pre-fill (empty for initial registration)
 * - $cancelButtonHtml (string): custom HTML for Batal button
 */
$isRevision = $isRevision ?? false;
$formData = $formData ?? [];

// Helper values
$vOwnerName = e($formData['owner_name'] ?? '');
$vNik = e($formData['nik'] ?? '');
$vPhone = e($formData['phone'] ?? '');
$vWhatsapp = e($formData['whatsapp'] ?? '');
$vProfession = $formData['profession'] ?? '';
$vNpwp = e($formData['npwp'] ?? '');

$vSameLoc = !empty($formData['same_location_siapkerja']);
$vProvince = e($formData['province'] ?? '');
$vCity = e($formData['city'] ?? '');
$vDistrict = e($formData['district'] ?? '');
$vVillage = e($formData['village'] ?? '');
$vPostalCode = e($formData['postal_code'] ?? '');
$vAddress = e($formData['address'] ?? '');
$vAddressDetail = e($formData['address_detail'] ?? ($formData['address_notes'] ?? ''));

$vPermitDoc = $formData['permit_document'] ?? ($formData['doc_permission'] ?? '');
$vPhoto = $formData['workplace_photo'] ?? ($formData['doc_location_photo'] ?? '');
$vPermitDocSizeText = '';
if (!empty($vPermitDoc)) {
    $baseDir = realpath(__DIR__ . '/..');
    $docRelative = ltrim(str_replace('\\', '/', (string) $vPermitDoc), '/');
    $docAbsolute = realpath(__DIR__ . '/../' . $docRelative);
    if ($baseDir && $docAbsolute && str_starts_with($docAbsolute, $baseDir) && is_file($docAbsolute)) {
        $bytes = (int) filesize($docAbsolute);
        if ($bytes > 0) {
            $vPermitDocSizeText = $bytes >= 1024 * 1024
                ? number_format($bytes / (1024 * 1024), 2) . ' MB'
                : number_format($bytes / 1024, 2) . ' KB';
        }
    }
}
$vPhotoSizeText = '';
if (!empty($vPhoto)) {
    $baseDir = realpath(__DIR__ . '/..');
    $photoRelative = ltrim(str_replace('\\', '/', (string) $vPhoto), '/');
    $photoAbsolute = realpath(__DIR__ . '/../' . $photoRelative);
    if ($baseDir && $photoAbsolute && str_starts_with($photoAbsolute, $baseDir) && is_file($photoAbsolute)) {
        $bytes = (int) filesize($photoAbsolute);
        if ($bytes > 0) {
            $vPhotoSizeText = $bytes >= 1024 * 1024
                ? number_format($bytes / (1024 * 1024), 2) . ' MB'
                : number_format($bytes / 1024, 2) . ' KB';
        }
    }
}
$vBrandName = e($formData['brand_name'] ?? '');
$vLogo = $formData['business_logo'] ?? ($formData['brand_logo'] ?? '');
$vLogoSizeText = '';
if (!empty($vLogo)) {
    $baseDir = realpath(__DIR__ . '/..');
    $logoRelative = ltrim(str_replace('\\', '/', (string) $vLogo), '/');
    $logoAbsolute = realpath(__DIR__ . '/../' . $logoRelative);
    if ($baseDir && $photoAbsolute && str_starts_with($logoAbsolute, $baseDir) && is_file($logoAbsolute)) {
        $bytes = (int) filesize($logoAbsolute);
        if ($bytes > 0) {
            $vLogoSizeText = $bytes >= 1024 * 1024
                ? number_format($bytes / (1024 * 1024), 2) . ' MB'
                : number_format($bytes / 1024, 2) . ' KB';
        }
    }
}

$vLinkedin = e($formData['linkedin'] ?? '');
$vFacebook = e($formData['facebook'] ?? '');
$vInstagram = e($formData['instagram'] ?? '');
$vTiktok = e($formData['tiktok'] ?? '');

$existingSocials = [];
if (!empty($vLinkedin)) $existingSocials[] = ['platform' => 'LinkedIn', 'handle' => $vLinkedin];
if (!empty($vFacebook)) $existingSocials[] = ['platform' => 'Facebook', 'handle' => $vFacebook];
if (!empty($vInstagram)) $existingSocials[] = ['platform' => 'Instagram', 'handle' => $vInstagram];
if (!empty($vTiktok)) $existingSocials[] = ['platform' => 'TikTok', 'handle' => $vTiktok];
if (empty($existingSocials)) {
    $existingSocials[] = ['platform' => 'LinkedIn', 'handle' => ''];
}

$vDescription = e($formData['description'] ?? '');
$vConsent = !empty($formData['user_consent']);

// Location display text
$locDisplayText = 'Pilih lokasi tempat usaha / kegiatan';
$hasLocSelected = false;
if (!empty($vVillage) && !empty($vDistrict) && !empty($vCity) && !empty($vProvince)) {
    $locDisplayText = $vVillage . ', ' . $vDistrict . ', ' . $vCity . ', ' . $vProvince;
    $hasLocSelected = true;
}

$googleMapsApiKey = getenv('GOOGLE_MAPS_API_KEY') ?: ($_ENV['GOOGLE_MAPS_API_KEY'] ?? '');
$infoIconSvg = '<svg class="tooltip-icon" width="13" height="13" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true" style="display:inline-block; vertical-align:-1px; cursor:pointer;"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>';
?>
<link rel="stylesheet" href="assets/pki-form.css?v=20261013_v4">
<style>
<?php
$pkiCssPath = __DIR__ . '/../assets/pki-form.css';
if (file_exists($pkiCssPath)) {
    echo file_get_contents($pkiCssPath);
}
?>
</style>

<?php if (!empty($profileModalError)): ?>
<div class="pki-alert-error" style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:center; gap:10px; color:#991b1b; font-size:13.5px; font-weight:600;">
    <i class="fa-solid fa-circle-exclamation" style="font-size:16px; color:#dc2626; flex-shrink:0;"></i>
    <div style="flex:1;"><?php echo e($profileModalError); ?></div>
    <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:#991b1b; cursor:pointer; font-size:18px; padding:0 4px; line-height:1; opacity:0.7;">&times;</button>
</div>
<?php endif; ?>

<!-- CALLOUT BANNER: INSTANSI ANDA -->
<div class="pki-entity-badge">
    <div class="pki-entity-badge-label">Instansi Anda</div>
    <div class="pki-entity-badge-val">
        <i class="fa-solid fa-user" style="color:#0284c7; font-size:15px;"></i>
        <span>Pemberi Kerja Individu</span>
    </div>
</div>

<!-- STEP HEADER WITH TITLE & PROGRESS INDICATOR (LANGKAH X/4) -->
<div class="pki-step-header">
    <div class="pki-step-title" id="pkiStepTitle">Identitas Perorangan</div>
    <div class="pki-step-indicator" id="pkiStepIndicator">
        <span class="pki-step-pie" id="pkiStepPie">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="9" stroke="#cbd5e1" stroke-width="2.5" fill="none"></circle>
                <circle cx="12" cy="12" r="9" stroke="#2563eb" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-dasharray="56.54" stroke-dashoffset="42.4" transform="rotate(-90 12 12)"></circle>
            </svg>
        </span>
        <span class="pki-step-counter" id="pkiStepCounter">Langkah 1/4</span>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- LANGKAH 1: SECTION 1 (IDENTITAS PERORANGAN)                     -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="pki-step-pane active" data-step="1">
    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_owner_name" for="pki_owner_name">
                Nama Pemberi Kerja <span class="req">*</span>
            </label>
            <input type="text" name="owner_name" id="pki_owner_name" value="<?php echo $vOwnerName; ?>" placeholder="Masukkan nama lengkap">
            <div class="pki-field-helper" id="helper_owner_name">Terisi otomatis dari akun SIAPKerja.</div>
        </div>
        <div class="pki-field">
            <label class="pki-field-label" id="label_nik" for="pki_nik">
                NIK <span class="req">*</span>
            </label>
            <input type="text" name="nik" id="pki_nik" value="<?php echo $vNik; ?>" maxlength="16" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="Masukkan 16 digit NIK">
            <div class="pki-field-helper" id="helper_nik">Terisi otomatis dari akun SIAPKerja.</div>
        </div>
    </div>

    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_phone" for="pki_phone">
                Nomor Telepon Aktif <span class="req">*</span>
            </label>
            <input type="text" name="phone" id="pki_phone" value="<?php echo $vPhone; ?>" maxlength="15" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="08xxxxxxxxxx">
            <div class="pki-field-helper" id="helper_phone">Terisi otomatis dari akun SIAPKerja.</div>
        </div>
        <div class="pki-field" style="position:relative;">
            <label class="pki-field-label" id="label_profession" for="pki_profession_trigger">
                Jenis Usaha Individu <span class="req">*</span>
                <span class="pki-tooltip tooltip-right" tabindex="0" role="tooltip">
                    <?php echo $infoIconSvg; ?>
                    <span class="tooltip-popover">Pilih jenis usaha perorangan yang Anda jalankan.</span>
                </span>
            </label>

            <input type="hidden" name="profession" id="pki_profession" value="<?php echo e($vProfession); ?>">

            <div class="pki-custom-select-trigger" id="pki_profession_trigger" tabindex="0" role="combobox" aria-expanded="false" aria-haspopup="listbox">
                <span class="pki-custom-select-text <?php echo empty($vProfession) ? 'is-placeholder' : ''; ?>" id="pki_profession_display">
                    <?php echo !empty($vProfession) ? e($vProfession) : 'Pilih Jenis Usaha Individu'; ?>
                </span>
                <span class="pki-custom-select-caret">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m7 15 5 5 5-5"/>
                        <path d="m7 9 5-5 5 5"/>
                    </svg>
                </span>
            </div>

            <div class="pki-custom-select-dropdown" id="pki_profession_dropdown" style="display:none;" role="listbox">
                <div class="pki-custom-select-search-wrap">
                    <input type="text" class="pki-custom-select-search" id="pki_profession_search" placeholder="Cari jenis usaha..." autocomplete="off">
                </div>
                <div class="pki-custom-select-options" id="pki_profession_options">
                    <?php
                    $professionGroups = function_exists('individual_business_types') ? individual_business_types() : [];
                    foreach ($professionGroups as $groupName => $items):
                    ?>
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
                    <div class="pki-select-no-results" id="pki_profession_no_results" style="display:none;">
                        Tidak ada jenis usaha yang sesuai
                    </div>
                </div>
            </div>
            <div class="pki-field-helper" id="helper_profession">Pilih jenis usaha perorangan yang Anda jalankan.</div>
        </div>
    </div>

    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_whatsapp" for="pki_whatsapp">
                Nomor WhatsApp <span class="req">*</span>
            </label>
            <input type="text" name="whatsapp" id="pki_whatsapp" value="<?php echo $vWhatsapp; ?>" maxlength="15" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="08xxxxxxxxxx">
            <div class="pki-field-helper" id="helper_whatsapp">Gunakan nomor WhatsApp aktif.</div>
            <div style="margin-top:6px; margin-bottom:2px;">
                <label style="font-size:12px; font-weight:500; color:#334155; display:inline-flex; align-items:center; gap:6px; cursor:pointer; user-select:none;">
                    <input type="checkbox" id="pki_same_as_phone" style="width:14px; height:14px; accent-color:#0284c7; cursor:pointer;" <?php echo (!empty($vPhone) && $vPhone === $vWhatsapp) ? 'checked' : ''; ?>>
                    <span>Samakan dengan Nomor Telepon Aktif</span>
                </label>
            </div>
        </div>
        <div class="pki-field" style="position:relative;">
            <label class="pki-field-label" id="label_npwp" for="pki_npwp_status">
                Status NPWP <span class="req">*</span>
                <span class="pki-tooltip tooltip-right" tabindex="0" role="tooltip">
                    <?php echo $infoIconSvg; ?>
                    <span class="tooltip-popover">Informasi: Untuk Wajib Pajak Orang Pribadi yang merupakan penduduk Indonesia, NIK digunakan sebagai NPWP.</span>
                </span>
            </label>
            <input type="hidden" name="npwp" id="pki_npwp" value="<?php echo $vNpwp ?: $vNik; ?>">
            <div class="pki-checkbox-field" id="pki_box_npwp">
                <label class="pki-checkbox-label" id="label_npwp_checkbox">
                    <input type="checkbox" name="npwp_status" id="pki_npwp_status" value="1" <?php echo (!empty($vNpwp) || !empty($formData['npwp_status'])) ? 'checked' : ''; ?>>
                    <span>Saya terdaftar sebagai Wajib Pajak Orang Pribadi dan menggunakan NIK sebagai NPWP</span>
                </label>
            </div>
            <div class="pki-field-helper" id="helper_npwp" style="display:none;">Status NPWP wajib dicentang untuk melanjutkan.</div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- LANGKAH 2: SECTION 2 (PROFIL USAHA / KEGIATAN PEMBERI KERJA)     -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="pki-step-pane" data-step="2" style="display:none;">
    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_brand_name" for="pki_brand_name">
                Nama Brand Usaha
                <span class="pki-tooltip" tabindex="0" role="tooltip">
                    <?php echo $infoIconSvg; ?>
                    <span class="tooltip-popover">Nama merek dagang, brand, atau nama komersial tempat usaha/kegiatan Anda.</span>
                </span>
            </label>
            <input type="text" name="brand_name" id="pki_brand_name" value="<?php echo $vBrandName; ?>" placeholder="Masukkan nama brand usaha">
            <div class="pki-field-helper" id="helper_brand_name">Nama brand atau merek usaha/kegiatan yang dijalankan.</div>
        </div>
        <div class="pki-field">
            <label class="pki-field-label" id="label_business_logo">
                Logo Usaha
                <span class="pki-tooltip" tabindex="0" role="tooltip">
                    <?php echo $infoIconSvg; ?>
                    <span class="tooltip-popover">Unggah logo resmi atau foto identitas visual usaha/kegiatan Anda.</span>
                </span>
            </label>
            <div class="pki-upload-box" id="pki_box_business_logo" onclick="pkiTriggerUpload('business_logo')">
                <span class="pki-upload-text <?php echo !empty($vLogo) ? 'has-file' : ''; ?>" id="pki_text_business_logo">
                    <?php if (!empty($vLogo)): ?>
                        <i class="fa-solid fa-image" style="color:#0284c7; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($vLogo)); ?></span>
                    <?php else: ?>
                        <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                    <?php endif; ?>
                </span>
                <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <?php if (!empty($vLogo)): ?>
                        <button type="button" class="pki-upload-clear-btn" title="Hapus logo" onclick="pkiClearUpload(event, 'business_logo')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pki-upload-btn" id="pki_btn_business_logo"><?php echo !empty($vLogo) ? 'Ganti' : 'Pilih File'; ?></button>
                </div>
            </div>
            <input type="file" name="business_logo" id="pki_input_business_logo" accept=".png,.jpg,.jpeg" style="display:none;" onchange="pkiHandleFileChange(this, 'business_logo')">
            <input type="hidden" name="existing_business_logo" id="pki_existing_business_logo" value="<?php echo e($vLogo); ?>">
            <div class="pki-field-helper" id="helper_business_logo">Ukuran maksimum 2 MB • Format PNG, JPG</div>
            <div class="pki-field-helper" id="helper_business_logo_size" style="<?php echo $vLogoSizeText !== '' ? '' : 'display:none;'; ?>">
                <?php if ($vLogoSizeText !== ''): ?>
                    Ukuran file yang di upload: <?php echo e($vLogoSizeText); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="pki-field">
        <label class="pki-field-label" id="label_description" for="pki_description">
            Deskripsi Singkat Usaha / Rekrutmen
            <span class="pki-tooltip" tabindex="0" role="tooltip">
                <?php echo $infoIconSvg; ?>
                <span class="tooltip-popover">Jelaskan secara singkat usaha atau kegiatan yang dijalankan serta gambaran kebutuhan rekrutmen yang dilakukan.</span>
            </span>
        </label>
        <textarea name="description" id="pki_description" placeholder="Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen..." style="min-height:85px;"><?php echo $vDescription; ?></textarea>
        <div class="pki-field-helper" id="helper_description">Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.</div>
    </div>

    <div style="margin-bottom:14px;">
        <label style="font-size:12.5px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
            <input type="checkbox" name="same_location_siapkerja" id="pki_cbSameLocation" value="1" <?php echo $vSameLoc ? 'checked' : ''; ?> style="accent-color:#0284c7; cursor:pointer;">
            <span>Gunakan alamat domisili di SIAPKerja sebagai lokasi tempat usaha/kegiatan</span>
        </label>
    </div>

    <!-- Hidden Location Fields -->
    <input type="hidden" name="province" id="pki_hiddenProvince" value="<?php echo $vProvince; ?>">
    <input type="hidden" name="city" id="pki_hiddenCity" value="<?php echo $vCity; ?>">
    <input type="hidden" name="district" id="pki_hiddenDistrict" value="<?php echo $vDistrict; ?>">
    <input type="hidden" name="village" id="pki_hiddenVillage" value="<?php echo $vVillage; ?>">
    <input type="hidden" name="domicile_city_id" id="pki_hiddenDomicileCityId" value="<?php echo $vCity; ?>">
    <input type="hidden" name="latitude" id="pki_inputLat" value="<?php echo e($formData['latitude'] ?? '-6.887844'); ?>">
    <input type="hidden" name="longitude" id="pki_inputLng" value="<?php echo e($formData['longitude'] ?? '107.613038'); ?>">

    <!-- Lokasi Tempat Usaha / Kegiatan -->
    <div class="pki-field" style="position:relative;">
        <label class="pki-field-label" id="label_location" for="pki_hierarchicalLocationInput">
            Lokasi Tempat Usaha / Kegiatan <span class="req">*</span>
            <span class="pki-tooltip" tabindex="0" role="tooltip">
                <?php echo $infoIconSvg; ?>
                <span class="tooltip-popover">Lokasi tempat Anda menjalankan usaha atau kegiatan sebagai Pemberi Kerja Individu.</span>
            </span>
        </label>
        <div id="pki_hierarchicalLocationInput" class="hierarchical-loc-field" tabindex="0">
            <span id="pki_locDisplayValue" class="<?php echo $hasLocSelected ? '' : 'placeholder'; ?>"><?php echo e($locDisplayText); ?></span>
            <i id="pki_locCaret" class="fa-solid fa-chevron-down" style="color:#64748b; font-size:12px;"></i>
        </div>
        <div id="pki_hierarchicalLocDropdown" class="loc-dropdown-popup" style="display:none;">
            <div id="pki_locBreadcrumbs" class="loc-breadcrumbs">
                <span class="crumb-btn active" data-level="1">Pilih Provinsi</span>
            </div>
            <div id="pki_locOptionsList" class="loc-options-list"></div>
        </div>
        <div class="pki-field-helper" id="helper_location">Pilih lokasi secara berjenjang sampai Kelurahan/Desa.</div>
    </div>

    <!-- Alamat Lengkap Tempat Usaha -->
    <div class="pki-field">
        <label class="pki-field-label" id="label_address" for="pki_inputAddress">
            Alamat Lengkap Tempat Usaha <span class="req">*</span>
        </label>
        <input type="text" name="address" id="pki_inputAddress" value="<?php echo $vAddress; ?>" placeholder="Masukkan nama jalan, nomor bangunan, RT/RW, dan alamat lengkap...">
        <div class="pki-field-helper" id="helper_address">Tuliskan alamat lengkap tempat usaha/kegiatan.</div>
    </div>

    <!-- Kode Pos -->
    <div class="pki-field">
        <label class="pki-field-label" id="label_postal_code" for="pki_selectPostalCode">
            Kode Pos <span class="req">*</span>
        </label>
        <select name="postal_code" id="pki_selectPostalCode">
            <option value="">Pilih kode pos</option>
            <?php if (!empty($vPostalCode)): ?>
                <option value="<?php echo $vPostalCode; ?>" selected><?php echo $vPostalCode; ?></option>
            <?php endif; ?>
        </select>
        <div class="pki-field-helper" id="helper_postal_code">Pilihan kode pos mengikuti lokasi yang dipilih.</div>
    </div>

    <!-- Detail Alamat / Patokan (Opsional) -->
    <div class="pki-field">
        <label class="pki-field-label" id="label_address_detail" for="pki_inputAddressDetail">
            Detail Alamat / Patokan (Opsional)
            <span class="pki-tooltip" tabindex="0" role="tooltip">
                <?php echo $infoIconSvg; ?>
                <span class="tooltip-popover">Tambahkan informasi yang membantu mengenali lokasi, seperti nomor bangunan, blok, lantai, atau patokan terdekat.</span>
            </span>
        </label>
        <input type="text" name="address_detail" id="pki_inputAddressDetail" value="<?php echo $vAddressDetail; ?>" placeholder="Contoh: Ruko lantai 2, sebelah Kantor Kelurahan">
        <div class="pki-field-helper" id="helper_address_detail">Opsional. Tambahkan informasi yang membantu mengenali lokasi.</div>
    </div>

    <!-- Peta Lokasi (Google Maps) -->
    <div class="pki-field">
        <label class="pki-field-label" style="margin-bottom:6px;">Peta Lokasi</label>
        <div id="pki_mapContainer" class="pki-map-container">
            <div id="pki_mapPlaceholder" class="pki-map-placeholder">
                <div style="font-size:26px; margin-bottom:4px;">🗺</div>
                <div style="font-size:13px; font-weight:700; color:#334155; margin-bottom:2px;">Pratinjau peta belum tersedia</div>
                <div style="font-size:11.5px; color:#64748b; max-width:340px; line-height:1.4;">Lengkapi lokasi dan alamat terlebih dahulu.</div>
            </div>
            <div id="pki_googleMapWrapper" class="pki-map-wrapper" style="display:none; width:100%; height:100%;">
                <iframe id="pki_gmapIframe" width="100%" height="100%" style="border:0;" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" src="about:blank"></iframe>
            </div>
        </div>
        <div class="pki-field-helper" id="helper_map" style="margin-top:6px;">Peta muncul otomatis setelah lokasi dan alamat lengkap tersedia.</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- LANGKAH 3: SECTION 3 (FILE BUKTI) & SECTION 4 (MEDIA SOSIAL)    -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="pki-step-pane" data-step="3" style="display:none;">
    <div class="section-title" style="font-size:14px; font-weight:800; color:#0f172a; margin-bottom:12px;">3. FILE & BUKTI TEMPAT USAHA</div>

    <div class="pki-grid-2">
        <!-- Dokumen Perizinan Usaha -->
        <div class="pki-field">
            <label class="pki-field-label" id="label_permit_document">
                Dokumen Perizinan Usaha <span class="req">*</span>
                <span class="pki-tooltip" tabindex="0" role="tooltip">
                    <?php echo $infoIconSvg; ?>
                    <span class="tooltip-popover">Unggah file yang mendukung validitas identitas atau kegiatan usaha Pemberi Kerja Individu.</span>
                </span>
            </label>
            <div class="pki-upload-box" id="pki_box_permit_document" onclick="pkiTriggerUpload('permit_document')">
                <span class="pki-upload-text <?php echo !empty($vPermitDoc) ? 'has-file' : ''; ?>" id="pki_text_permit_document">
                    <?php if (!empty($vPermitDoc)): ?>
                        <i class="fa-solid fa-file-pdf" style="color:#ef4444; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($vPermitDoc)); ?></span>
                    <?php else: ?>
                        <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                    <?php endif; ?>
                </span>
                <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <?php if (!empty($vPermitDoc)): ?>
                        <button type="button" class="pki-upload-clear-btn" title="Hapus berkas" onclick="pkiClearUpload(event, 'permit_document')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pki-upload-btn" id="pki_btn_permit_document"><?php echo !empty($vPermitDoc) ? 'Ganti' : 'Pilih File'; ?></button>
                </div>
            </div>
            <input type="file" name="permit_document" id="pki_input_permit_document" accept=".pdf,.docx" style="display:none;" onchange="pkiHandleFileChange(this, 'permit_document')">
            <input type="hidden" name="existing_permit_document" id="pki_existing_permit_document" value="<?php echo e($vPermitDoc); ?>">
            <div class="pki-field-helper" id="helper_permit_document">Ukuran maksimum 2 MB • Format PDF, Docx</div>
            <div class="pki-field-helper" id="helper_permit_document_size" style="<?php echo $vPermitDocSizeText !== '' ? '' : 'display:none;'; ?>">
                <?php if ($vPermitDocSizeText !== ''): ?>
                    Ukuran file yang di upload: <?php echo e($vPermitDocSizeText); ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Foto Bukti Tempat Usaha / Lokasi -->
        <div class="pki-field">
            <label class="pki-field-label" id="label_workplace_photo">
                Foto Bukti Tempat Usaha / Lokasi <span class="req">*</span>
                <span class="pki-tooltip" tabindex="0" role="tooltip">
                    <?php echo $infoIconSvg; ?>
                    <span class="tooltip-popover">Unggah foto bagian depan rumah, tempat usaha, atau lokasi kegiatan yang sesuai dengan alamat tempat usaha/kegiatan yang dicantumkan pada profil.</span>
                </span>
            </label>
            <div class="pki-upload-box" id="pki_box_workplace_photo" onclick="pkiTriggerUpload('workplace_photo')">
                <span class="pki-upload-text <?php echo !empty($vPhoto) ? 'has-file' : ''; ?>" id="pki_text_workplace_photo">
                    <?php if (!empty($vPhoto)): ?>
                        <i class="fa-solid fa-image" style="color:#0284c7; flex-shrink:0;"></i> <span class="pki-upload-filename"><?php echo e(basename($vPhoto)); ?></span>
                    <?php else: ?>
                        <span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>
                    <?php endif; ?>
                </span>
                <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <?php if (!empty($vPhoto)): ?>
                        <button type="button" class="pki-upload-clear-btn" title="Hapus foto" onclick="pkiClearUpload(event, 'workplace_photo')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pki-upload-btn" id="pki_btn_workplace_photo"><?php echo !empty($vPhoto) ? 'Ganti' : 'Pilih File'; ?></button>
                </div>
            </div>
            <input type="file" name="workplace_photo" id="pki_input_workplace_photo" accept=".png,.jpg,.jpeg" style="display:none;" onchange="pkiHandleFileChange(this, 'workplace_photo')">
            <input type="hidden" name="existing_workplace_photo" id="pki_existing_workplace_photo" value="<?php echo e($vPhoto); ?>">
            <div class="pki-field-helper" id="helper_workplace_photo">Ukuran maksimum 2 MB • Format PNG, JPG</div>
            <div class="pki-field-helper" id="helper_workplace_photo_size" style="<?php echo $vPhotoSizeText !== '' ? '' : 'display:none;'; ?>">
                <?php if ($vPhotoSizeText !== ''): ?>
                    Ukuran file yang di upload: <?php echo e($vPhotoSizeText); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <hr class="modal-section-hr" style="margin:16px 0;">

    <div class="section-title" style="font-size:14px; font-weight:800; color:#0f172a; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
        <span>4. MEDIA SOSIAL</span>
        <span class="req" style="color:#ef4444 !important; font-weight:700;">*</span>
    </div>
    <div style="font-size:12px; color:#64748b; margin-bottom:14px;">Wajib mengisi minimal salah satu akun media sosial (LinkedIn, Facebook, Instagram, atau TikTok).</div>

    <!-- Hidden compatibility fields synced by JS -->
    <input type="hidden" name="linkedin" id="pki_linkedin" value="<?php echo $vLinkedin; ?>">
    <input type="hidden" name="facebook" id="pki_facebook" value="<?php echo $vFacebook; ?>">
    <input type="hidden" name="instagram" id="pki_instagram" value="<?php echo $vInstagram; ?>">
    <input type="hidden" name="tiktok" id="pki_tiktok" value="<?php echo $vTiktok; ?>">

    <div class="pki-social-container" id="pki_social_container">
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
                <select name="social_platform[]" class="pki-social-platform-select" aria-label="Pilih platform media sosial">
                    <option value="LinkedIn" <?php echo $curPlat === 'LinkedIn' ? 'selected' : ''; ?>>LinkedIn</option>
                    <option value="Facebook" <?php echo $curPlat === 'Facebook' ? 'selected' : ''; ?>>Facebook</option>
                    <option value="Instagram" <?php echo $curPlat === 'Instagram' ? 'selected' : ''; ?>>Instagram</option>
                    <option value="TikTok" <?php echo $curPlat === 'TikTok' ? 'selected' : ''; ?>>TikTok</option>
                </select>
            </div>
            <div class="pki-social-input-wrap">
                <input type="text" name="social_handle[]" class="pki-social-handle-input" value="<?php echo $curHandle; ?>" placeholder="<?php echo $platMeta['placeholder']; ?>" autocomplete="off" aria-label="Username atau URL akun media sosial">
            </div>
            <div class="pki-social-actions">
                <?php if ($totalSoc > 1): ?>
                    <button type="button" class="pki-social-btn pki-social-btn-del" title="Hapus akun media sosial" aria-label="Hapus akun">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                <?php endif; ?>
                <?php if ($isLast && $totalSoc < 4): ?>
                    <button type="button" class="pki-social-btn pki-social-btn-add" title="Tambah akun media sosial" aria-label="Tambah akun">
                        <i class="fa-solid fa-plus"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="pki-field-helper" id="helper_social" style="display:none; color:#ef4444; font-size:12px; margin-top:6px; font-weight:500;">
        Wajib mengisi minimal salah satu akun media sosial (LinkedIn, Facebook, Instagram, atau TikTok).
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- LANGKAH 4: SECTION 5 (PERNYATAAN)                               -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="pki-step-pane" data-step="4" style="display:none;">
    <div class="section-title" style="font-size:14px; font-weight:800; color:#0f172a; margin-bottom:12px;">5. PERNYATAAN <span class="req" style="color:#ef4444 !important; font-weight:700;">*</span></div>
    
    <div class="pki-consent-box" id="pki_consent_statement_box" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; font-size:12.5px; color:#334155; line-height:1.65; margin-bottom:14px;">
        Saya menyatakan bahwa seluruh informasi yang saya berikan benar, valid, dan dapat dipertanggungjawabkan. Saya berkomitmen untuk tidak melakukan penipuan, mempublikasikan lowongan palsu, atau tindakan lain yang merugikan pelamar maupun melanggar hukum. Apabila terbukti melakukan pelanggaran, saya bersedia menerima sanksi hukum sesuai dengan ketentuan peraturan perundang-undangan yang berlaku.
    </div>

    <div class="pki-field" style="margin-bottom:0;">
        <label style="display:inline-flex; align-items:flex-start; gap:9px; font-size:12.5px; color:#1e293b; font-weight:500; line-height:1.45; cursor:pointer; user-select:none;" id="label_user_consent_container">
            <input type="checkbox" name="user_consent" id="pki_user_consent" value="1" style="margin-top:2px; flex-shrink:0; cursor:pointer;" <?php echo $vConsent ? 'checked' : ''; ?>>
            <span id="label_user_consent_text">Saya telah membaca dan menyetujui pernyataan di atas. <span class="req" style="color:#ef4444 !important; font-weight:700;">*</span></span>
        </label>
        <div class="pki-field-helper" id="helper_user_consent" style="display:none;"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- STEP NAVIGATION CONTROLS (SEBELUMNYA / SELANJUTNYA / AJUKAN)    -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="pki-step-footer" id="pkiStepFooter">
    <button type="button" class="pki-btn-prev" id="pkiBtnPrev" style="display:none;">Kembali</button>
    <button type="button" class="pki-btn-next" id="pkiBtnNext">Selanjutnya</button>
    <button type="submit" class="pki-btn-submit" id="btnSubmitProfile" style="display:none;">
        <i class="fa-solid fa-paper-plane" style="margin-right:6px;"></i>
        <?php echo (!empty($isReverification) || !empty($isFullDisable) || in_array($verificationStatus ?? "", ["FULL_DISABLED", "INACTIVE_REVERIFICATION_REQUIRED"], true) || (($verificationStatus ?? "") === "PENDING" && !empty($profile["last_activated_at"]))) ? "Ajukan Verifikasi Ulang" : "Ajukan Profil"; ?>
    </button>
</div>

<script>
window.PKI_CONFIG = {
    googleMapsApiKey: "<?php echo addslashes($googleMapsApiKey); ?>",
    isRevision: <?php echo $isRevision ? 'true' : 'false'; ?>
};
</script>
