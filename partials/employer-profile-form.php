<?php
/**
 * Shared Form Profil Pemberi Kerja Individu
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
    if ($baseDir && $logoAbsolute && str_starts_with($logoAbsolute, $baseDir) && is_file($logoAbsolute)) {
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
<link rel="stylesheet" href="assets/pki-form.css?v=20260928_v1">
<style>
<?php
$pkiCssPath = __DIR__ . '/../assets/pki-form.css';
if (file_exists($pkiCssPath)) {
    echo file_get_contents($pkiCssPath);
}
?>
</style>

<!-- BANNER MODE DEMO -->
<div class="pki-demo-banner">
    <svg width="15" height="15" viewBox="0 0 512 512" fill="#0284c7" aria-hidden="true" style="display:inline-block; vertical-align:top; margin-top:2px; flex-shrink:0;"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>
    <div>
        <div class="pki-demo-banner-title">MODE DEMO</div>
        <div class="pki-demo-banner-text">Pada implementasi produksi, data identitas akan terisi otomatis dari SIAPKerja. Pada demo ini, data dapat diisi manual untuk kebutuhan pengujian.</div>
    </div>
</div>

<?php if (!empty($profileModalError)): ?>
<div class="pki-alert-error" style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:center; gap:10px; color:#991b1b; font-size:13.5px; font-weight:600;">
    <i class="fa-solid fa-circle-exclamation" style="font-size:16px; color:#dc2626; flex-shrink:0;"></i>
    <div style="flex:1;"><?php echo e($profileModalError); ?></div>
    <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:#991b1b; cursor:pointer; font-size:18px; padding:0 4px; line-height:1; opacity:0.7;">&times;</button>
</div>
<?php endif; ?>

<!-- 1. IDENTITAS PERORANGAN -->
<div class="modal-section" style="margin-bottom:20px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">1. IDENTITAS PERORANGAN</div>
    
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
    </div>

    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_profession" for="pki_profession">
                Jenis Usaha Individu <span class="req">*</span>
                <span class="pki-tooltip" tabindex="0" role="tooltip">
                    <?php echo $infoIconSvg; ?>
                    <span class="tooltip-popover">Pilih jenis usaha individu yang paling sesuai dengan kegiatan utama Anda.</span>
                </span>
            </label>
            <select name="profession" id="pki_profession" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;">
                <option value="">Pilih jenis usaha individu</option>
                <?php
                $professions = [
                    'Praktik Dokter Umum',
                    'Praktik Dokter Gigi',
                    'Praktik Bidan',
                    'Notaris',
                    'Konsultan Hukum',
                    'Psikolog',
                    'Arsitek',
                    'Konsultan Pajak',
                    'Guru Privat',
                    'Bimbingan Belajar (Bimbel)',
                ];
                foreach ($professions as $prof) {
                    $selected = ($vProfession === $prof) ? 'selected' : '';
                    echo "<option value=\"" . e($prof) . "\" $selected>" . e($prof) . "</option>";
                }
                if ($vProfession && !in_array($vProfession, $professions)) {
                    echo "<option value=\"" . e($vProfession) . "\" selected>" . e($vProfession) . "</option>";
                }
                ?>
            </select>
            <div class="pki-field-helper" id="helper_profession">Pilih jenis usaha individu yang paling sesuai dengan kegiatan utama Anda.</div>
        </div>
        <div class="pki-field">
            <label class="pki-field-label" id="label_npwp" for="pki_npwp">
                NPWP <span class="req">*</span>
            </label>
            <input type="text" name="npwp" id="pki_npwp" value="<?php echo $vNpwp; ?>" maxlength="16" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'')" placeholder="Masukkan 15 atau 16 digit NPWP">
            <div class="pki-field-helper" id="helper_npwp">NPWP terdiri dari 15 atau 16 digit angka.</div>
        </div>
    </div>
</div>

<hr class="modal-section-hr">

<!-- 2. PROFIL USAHA / KEGIATAN PEMBERI KERJA -->
<div class="modal-section" style="margin-bottom:20px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:14px;">2. PROFIL USAHA / KEGIATAN PEMBERI KERJA</div>

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
                        <span>Pilih foto logo untuk diunggah</span>
                    <?php endif; ?>
                </span>
                <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <?php if (!empty($vLogo)): ?>
                        <button type="button" class="pki-upload-clear-btn" title="Hapus logo" onclick="pkiClearUpload(event, 'business_logo')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pki-upload-btn" id="pki_btn_business_logo"><?php echo !empty($vLogo) ? 'Ganti' : 'Upload'; ?></button>
                </div>
            </div>
            <input type="file" name="business_logo" id="pki_input_business_logo" accept=".png,.jpg,.jpeg" style="display:none;" onchange="pkiHandleFileChange(this, 'business_logo')">
            <input type="hidden" name="existing_business_logo" id="pki_existing_business_logo" value="<?php echo e($vLogo); ?>">
            <div class="pki-field-helper" id="helper_business_logo">Format PNG, JPG. Ukuran Maksimal 2MB</div>
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
        <textarea name="description" id="pki_description" placeholder="Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen..." style="min-height:90px; padding:10px 14px; font-size:13.5px; border:1px solid #cbd5e1; border-radius:8px; width:100%; font-family:inherit; line-height:1.5; resize:vertical;"><?php echo $vDescription; ?></textarea>
        <div class="pki-field-helper" id="helper_description">Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.</div>
    </div>

    <div style="margin-bottom:16px;">
        <label style="font-size:13px; font-weight:600; color:#334155; display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
            <input type="checkbox" name="same_location_siapkerja" id="pki_cbSameLocation" value="1" <?php echo $vSameLoc ? 'checked' : ''; ?>>
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
        <select name="postal_code" id="pki_selectPostalCode" style="width:100%; height:42px; padding:0 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; color:#0f172a; background:#fff;">
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
        <label class="pki-field-label" style="margin-bottom:8px;">Peta Lokasi</label>
        <div id="pki_mapContainer" class="pki-map-container">
            <div id="pki_mapPlaceholder" class="pki-map-placeholder">
                <div style="font-size:28px; margin-bottom:6px;">🗺</div>
                <div style="font-size:13.5px; font-weight:700; color:#334155; margin-bottom:4px;">Pratinjau peta belum tersedia</div>
                <div style="font-size:12px; color:#64748b; max-width:360px; line-height:1.4;">Lengkapi lokasi dan alamat terlebih dahulu.</div>
            </div>
            <div id="pki_googleMapWrapper" class="pki-map-wrapper" style="display:none; width:100%; height:100%;">
                <iframe id="pki_gmapIframe" width="100%" height="100%" style="border:0;" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" src="about:blank"></iframe>
            </div>
        </div>
        <div class="pki-field-helper" id="helper_map" style="margin-top:8px;">Peta muncul otomatis setelah lokasi dan alamat lengkap tersedia.</div>
    </div>
</div>

<hr class="modal-section-hr">

<!-- 3. FILE & BUKTI TEMPAT USAHA -->
<div class="modal-section" style="margin-bottom:20px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:16px;">3. FILE & BUKTI TEMPAT USAHA</div>

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
                        <span>Pilih file untuk diunggah</span>
                    <?php endif; ?>
                </span>
                <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <?php if (!empty($vPermitDoc)): ?>
                        <button type="button" class="pki-upload-clear-btn" title="Hapus berkas" onclick="pkiClearUpload(event, 'permit_document')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pki-upload-btn" id="pki_btn_permit_document"><?php echo !empty($vPermitDoc) ? 'Ganti' : 'Upload'; ?></button>
                </div>
            </div>
            <input type="file" name="permit_document" id="pki_input_permit_document" accept=".pdf,.docx" style="display:none;" onchange="pkiHandleFileChange(this, 'permit_document')">
            <input type="hidden" name="existing_permit_document" id="pki_existing_permit_document" value="<?php echo e($vPermitDoc); ?>">
            <div class="pki-field-helper" id="helper_permit_document">Format PDF, Docx. Ukuran Maksimal 2MB</div>
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
                        <span>Pilih foto untuk diunggah</span>
                    <?php endif; ?>
                </span>
                <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                    <?php if (!empty($vPhoto)): ?>
                        <button type="button" class="pki-upload-clear-btn" title="Hapus foto" onclick="pkiClearUpload(event, 'workplace_photo')">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="pki-upload-btn" id="pki_btn_workplace_photo"><?php echo !empty($vPhoto) ? 'Ganti' : 'Upload'; ?></button>
                </div>
            </div>
            <input type="file" name="workplace_photo" id="pki_input_workplace_photo" accept=".png,.jpg,.jpeg" style="display:none;" onchange="pkiHandleFileChange(this, 'workplace_photo')">
            <input type="hidden" name="existing_workplace_photo" id="pki_existing_workplace_photo" value="<?php echo e($vPhoto); ?>">
            <div class="pki-field-helper" id="helper_workplace_photo">Format PNG, JPG. Ukuran Maksimal 2MB</div>
            <div class="pki-field-helper" id="helper_workplace_photo_size" style="<?php echo $vPhotoSizeText !== '' ? '' : 'display:none;'; ?>">
                <?php if ($vPhotoSizeText !== ''): ?>
                    Ukuran file yang di upload: <?php echo e($vPhotoSizeText); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<hr class="modal-section-hr">

<!-- 4. MEDIA SOSIAL & DESKRIPSI -->
<div class="modal-section" style="margin-bottom:20px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
        <span>4. MEDIA SOSIAL</span>
        <span class="req" style="color:#ef4444 !important; font-weight:700;">*</span>
    </div>
    <div style="font-size:12.5px; color:#64748b; margin-bottom:16px;">Wajib mengisi minimal salah satu akun media sosial (LinkedIn, Facebook, atau Instagram).</div>

    <div class="pki-grid-2">
        <div class="pki-field">
            <label class="pki-field-label" id="label_linkedin" for="pki_linkedin">LinkedIn</label>
            <input type="text" name="linkedin" id="pki_linkedin" value="<?php echo $vLinkedin; ?>" placeholder="username atau https://linkedin.com/in/...">
            <div class="pki-field-helper" id="helper_linkedin">Isi minimal salah satu media sosial.</div>
        </div>
        <div class="pki-field">
            <label class="pki-field-label" id="label_facebook" for="pki_facebook">Facebook</label>
            <input type="text" name="facebook" id="pki_facebook" value="<?php echo $vFacebook; ?>" placeholder="@username atau https://facebook.com/...">
            <div class="pki-field-helper" id="helper_facebook">Isi minimal salah satu media sosial.</div>
        </div>
    </div>

    <div class="pki-field">
        <label class="pki-field-label" id="label_instagram" for="pki_instagram">Instagram</label>
        <input type="text" name="instagram" id="pki_instagram" value="<?php echo $vInstagram; ?>" placeholder="@username atau https://instagram.com/...">
        <div class="pki-field-helper" id="helper_instagram">Isi minimal salah satu media sosial.</div>
    </div>
</div>

<hr class="modal-section-hr">

<!-- 5. PERNYATAAN -->
<div class="modal-section" style="margin-bottom:12px;">
    <div class="section-title" style="font-size:15px; font-weight:800; color:#0f172a; margin-bottom:12px;">5. PERNYATAAN <span class="req" style="color:#ef4444 !important; font-weight:700;">*</span></div>
    
    <div class="pki-consent-box" id="pki_consent_statement_box" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; font-size:12.5px; color:#334155; line-height:1.6; margin-bottom:12px;">
        Saya menyatakan bahwa seluruh informasi yang saya berikan benar, valid, dan dapat dipertanggungjawabkan. Saya berkomitmen untuk tidak melakukan penipuan, mempublikasikan lowongan palsu, atau tindakan lain yang merugikan pelamar/kandidat maupun melanggar hukum. Apabila terbukti melakukan pelanggaran, saya bersedia menerima sanksi sesuai ketentuan yang berlaku.
    </div>

    <div class="pki-field" style="margin-bottom:0;">
        <label style="display:inline-flex; align-items:flex-start; gap:9px; font-size:12.5px; color:#1e293b; font-weight:500; line-height:1.45; cursor:pointer; user-select:none;" id="label_user_consent_container">
            <input type="checkbox" name="user_consent" id="pki_user_consent" value="1" style="margin-top:2px; flex-shrink:0; cursor:pointer;">
            <span id="label_user_consent_text">Saya telah membaca dan menyetujui pernyataan di atas. <span class="req" style="color:#ef4444 !important; font-weight:700;">*</span></span>
        </label>
        <div class="pki-field-helper" id="helper_user_consent" style="display:none;"></div>
    </div>
</div>

<!-- MODAL / FORM FOOTER -->
<div class="modal-footer" style="padding:20px 0 0 0; display:flex; justify-content:flex-end; gap:12px; border-top:1px solid #e2e8f0; margin-top:24px;">
    <?php if (!empty($cancelButtonHtml)): ?>
        <?php echo $cancelButtonHtml; ?>
    <?php else: ?>
        <a href="employer-type.php" class="ghost-btn" style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center;">Batal</a>
    <?php endif; ?>
    <button type="submit" class="primary-btn" id="btnSubmitProfile">
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
