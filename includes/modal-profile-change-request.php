<?php
/**
 * Modal Permintaan Perubahan Profil Pemberi Kerja Individu
 * Ditampilkan saat Profil Terverifikasi ingin mengajukan perubahan data.
 * Perubahan membentuk Profile Change Request dan masuk verifikasi Admin.
 */
$showPcrModal = isset($_GET['open_pcr']) && $_GET['open_pcr'] == '1';
$pcrCurrentData = $profile ?? [];
?>

<div class="modal-backdrop <?php echo $showPcrModal ? 'open' : ''; ?>" data-modal="modal-profile-change-request" id="modal-profile-change-request" style="z-index: 1050;">
    <div class="modal-panel" style="width: min(760px, 95vw); max-height: 90vh; border-radius: 16px; overflow: hidden; display: flex; flex-direction: column; background: #ffffff; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        
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

                <!-- 1. BAGIAN PROFIL YANG DIUBAH (CHECKBOX) -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 4px;">
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
                <div style="margin-bottom: 24px;">
                    <label for="pcr_reason" style="display: block; font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 6px;">
                        Alasan Perubahan <span style="color: #ef4444;">*</span>
                    </label>
                    <div style="position: relative;">
                        <textarea name="pcr_reason" id="pcr_reason" maxlength="500" rows="3" required placeholder="Saya ingin memperbarui alamat tempat usaha karena sudah pindah lokasi, serta memperbarui informasi media sosial (Instagram dan LinkedIn) yang terbaru." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px 14px; font-size: 13.5px; color: #1e293b; line-height: 1.5; resize: vertical; box-sizing: border-box; font-family: inherit; outline: none; transition: border-color 0.2s;"></textarea>
                        <div style="text-align: right; font-size: 12px; color: #94a3b8; margin-top: 4px;">
                            <span id="pcr_reason_counter">0</span>/500
                        </div>
                    </div>
                </div>

                <!-- 3. FIELD PROFIL TERPILIH CONTAINER -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 10px;">
                        Field Profil Terpilih
                    </label>

                    <!-- PLACEHOLDER IF NO SECTION CHECKED -->
                    <div id="pcr_fields_empty_placeholder" style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 24px; text-align: center; color: #64748b; font-size: 13.5px;">
                        <i class="fa-regular fa-hand-pointer" style="font-size: 22px; color: #94a3b8; margin-bottom: 8px; display: block;"></i>
                        Pilih minimal satu bagian profil di atas untuk menampilkan field yang ingin diubah.
                    </div>

                    <!-- ─── SECTION 1: IDENTITAS PERORANGAN ─── -->
                    <?php
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
                    $pcrCurrentProf = $pcrCurrentData['profession'] ?? '';
                    ?>
                    <div id="pcr_box_identity" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 16px;">
                        <div style="background: #f1f5f9; padding: 10px 16px; font-weight: 700; font-size: 13.5px; color: #0369a1; display: flex; align-items: center; justify-content: space-between;">
                            <span>Identitas Perorangan</span>
                            <span style="font-size: 11.5px; font-weight: 500; color: #0369a1; background: #e0f2fe; padding: 2px 8px; border-radius: 999px; border: 1px solid #bae6fd;">Nomor WhatsApp &amp; Jenis Usaha dapat diedit</span>
                        </div>
                        <div style="padding: 16px; background: #ffffff;">
                            
                            <!-- KETERANGAN FIELD READ ONLY -->
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; line-height: 1.4;">
                                <i class="fa-solid fa-lock" style="color: #94a3b8; margin-right: 4px;"></i> Field Nama Pemberi Kerja, NIK, Nomor Telepon Aktif, dan Status NPWP berstatus <strong>Read-Only</strong> dan terverifikasi secara resmi dari akun SIAPKerja.
                            </div>

                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-bottom: 14px;">
                                <!-- Nama Lengkap (Read-only) -->
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Nama Pemberi Kerja</label>
                                    <input type="text" value="<?php echo e($pcrCurrentData['owner_name'] ?? ($user['name'] ?? '')); ?>" readonly disabled style="width: 100%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #64748b; cursor: not-allowed; box-sizing: border-box;">
                                </div>
                                <!-- NIK (Read-only) -->
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">NIK</label>
                                    <input type="text" value="<?php echo e($pcrCurrentData['nik'] ?? ''); ?>" readonly disabled style="width: 100%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #64748b; cursor: not-allowed; box-sizing: border-box;">
                                </div>
                                <!-- Nomor Telepon Aktif (Read-only) -->
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Nomor Telepon Aktif</label>
                                    <input type="text" value="<?php echo e($pcrCurrentData['phone'] ?? ''); ?>" readonly disabled style="width: 100%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #64748b; cursor: not-allowed; box-sizing: border-box;">
                                </div>
                                <!-- Status NPWP (Read-only) -->
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Status NPWP</label>
                                    <input type="text" value="Wajib Pajak Orang Pribadi (NIK sebagai NPWP)" readonly disabled style="width: 100%; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #64748b; cursor: not-allowed; box-sizing: border-box;">
                                </div>
                            </div>

                            <!-- FIELD AKTIF DAPAT DIEDIT (JENIS USAHA & WHATSAPP) -->
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 16px; display: flex; flex-direction: column; gap: 12px;">
                                <div style="font-size: 12.5px; font-weight: 700; color: #166534; display: flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-pen-to-square"></i> Field yang Dapat Diperbarui:
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
                                    <!-- Jenis Usaha Individu (EDITABLE) -->
                                    <div>
                                        <label for="pcr_profession" style="display: block; font-size: 12px; font-weight: 700; color: #166534; margin-bottom: 4px;">
                                            Jenis Usaha Individu <span style="color: #ef4444;">*</span>
                                        </label>
                                        <select name="pcr_profession" id="pcr_profession" style="width: 100%; background: #ffffff; border: 1px solid #86efac; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; font-weight: 600; box-sizing: border-box; outline: none; cursor: pointer;">
                                            <option value="">-- Pilih Jenis Usaha Individu --</option>
                                            <?php foreach ($pcrProfessionGroups as $groupName => $items): ?>
                                                <optgroup label="<?php echo e($groupName); ?>">
                                                    <?php foreach ($items as $item): ?>
                                                        <option value="<?php echo e($item); ?>" <?php echo ($pcrCurrentProf === $item) ? 'selected' : ''; ?>>
                                                            <?php echo e($item); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                        <div style="font-size: 11.5px; color: #15803d; margin-top: 3px;">Pilih jenis usaha perorangan yang Anda jalankan.</div>
                                    </div>

                                    <!-- Nomor WhatsApp (EDITABLE) -->
                                    <div>
                                        <label for="pcr_whatsapp" style="display: block; font-size: 12px; font-weight: 700; color: #166534; margin-bottom: 4px;">
                                            Nomor WhatsApp Baru <span style="color: #ef4444;">*</span>
                                        </label>
                                        <input type="text" name="pcr_whatsapp" id="pcr_whatsapp" value="<?php echo e($pcrCurrentData['whatsapp'] ?? ''); ?>" maxlength="15" inputmode="numeric" placeholder="08xxxxxxxxxx" oninput="this.value=this.value.replace(/\D/g,'')" style="width: 100%; background: #ffffff; border: 1px solid #86efac; border-radius: 6px; padding: 8px 12px; font-size: 13.5px; color: #1e293b; font-weight: 600; box-sizing: border-box; outline: none;">
                                        <div style="font-size: 11.5px; color: #15803d; margin-top: 3px;">Gunakan nomor WhatsApp aktif yang dapat dihubungi.</div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- ─── SECTION 2: TEMPAT USAHA / KEGIATAN & DESKRIPSI USAHA ─── -->
                    <div id="pcr_box_workplace" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 16px;">
                        <div style="background: #f1f5f9; padding: 10px 16px; font-weight: 700; font-size: 13.5px; color: #0369a1;">
                            Tempat Usaha / Kegiatan Pemberi Kerja &amp; Deskripsi Usaha
                        </div>
                        <div style="padding: 16px; background: #ffffff; display: flex; flex-direction: column; gap: 14px;">
                            <!-- Nama Brand Usaha -->
                            <div>
                                <label for="pcr_brand_name" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Nama Brand Usaha</label>
                                <input type="text" name="pcr_brand_name" id="pcr_brand_name" value="<?php echo e($pcrCurrentData['brand_name'] ?? ''); ?>" placeholder="Masukkan nama brand/merek usaha" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                            </div>

                            <!-- Alamat Lengkap Tempat Usaha -->
                            <div>
                                <label for="pcr_address" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Alamat Tempat Usaha</label>
                                <textarea name="pcr_address" id="pcr_address" rows="2" placeholder="Nama jalan, nomor bangunan, RT/RW, dan alamat tempat usaha..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box; resize: vertical;"><?php echo e($pcrCurrentData['address'] ?? ''); ?></textarea>
                            </div>

                            <!-- Wilayah: Provinsi, Kota, Kecamatan, Kelurahan -->
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                                <div>
                                    <label for="pcr_province" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Provinsi</label>
                                    <input type="text" name="pcr_province" id="pcr_province" value="<?php echo e($pcrCurrentData['province'] ?? ''); ?>" placeholder="Provinsi" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                                <div>
                                    <label for="pcr_city" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Kota / Kabupaten</label>
                                    <input type="text" name="pcr_city" id="pcr_city" value="<?php echo e($pcrCurrentData['city'] ?? ''); ?>" placeholder="Kota/Kabupaten" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                                <div>
                                    <label for="pcr_district" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Kecamatan</label>
                                    <input type="text" name="pcr_district" id="pcr_district" value="<?php echo e($pcrCurrentData['district'] ?? ''); ?>" placeholder="Kecamatan" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                                <div>
                                    <label for="pcr_village" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Kelurahan / Desa</label>
                                    <input type="text" name="pcr_village" id="pcr_village" value="<?php echo e($pcrCurrentData['village'] ?? ''); ?>" placeholder="Kelurahan/Desa" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                            </div>

                            <!-- Kode Pos & Detail Patokan -->
                            <div style="display: grid; grid-template-columns: 180px 1fr; gap: 10px;">
                                <div>
                                    <label for="pcr_postal_code" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Kode Pos</label>
                                    <input type="text" name="pcr_postal_code" id="pcr_postal_code" value="<?php echo e($pcrCurrentData['postal_code'] ?? ''); ?>" placeholder="Kode Pos" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                                <div>
                                    <label for="pcr_address_detail" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Detail Patokan (Opsional)</label>
                                    <input type="text" name="pcr_address_detail" id="pcr_address_detail" value="<?php echo e($pcrCurrentData['address_detail'] ?? ''); ?>" placeholder="Contoh: Ruko sebelah kantor pos, lantai 2" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                            </div>

                            <!-- Deskripsi Usaha -->
                            <div>
                                <label for="pcr_description" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Deskripsi Usaha</label>
                                <textarea name="pcr_description" id="pcr_description" rows="3" placeholder="Deskripsikan secara singkat profil usaha atau kegiatan..." style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box; resize: vertical;"><?php echo e($pcrCurrentData['description'] ?? ''); ?></textarea>
                            </div>

                            <!-- Logo Usaha Upload (Opsional) -->
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Perbarui Logo Usaha (Opsional)</label>
                                <input type="file" name="pcr_business_logo" accept=".png,.jpg,.jpeg" style="font-size: 12px; color: #475569;">
                                <?php if (!empty($pcrCurrentData['business_logo'])): ?>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 3px;">Logo aktif saat ini: <code><?php echo e(basename($pcrCurrentData['business_logo'])); ?></code></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ─── SECTION 3: DOKUMEN & BUKTI TEMPAT USAHA ─── -->
                    <div id="pcr_box_documents" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 16px;">
                        <div style="background: #f1f5f9; padding: 10px 16px; font-weight: 700; font-size: 13.5px; color: #0369a1;">
                            Dokumen &amp; Bukti Tempat Usaha
                        </div>
                        <div style="padding: 16px; background: #ffffff; display: flex; flex-direction: column; gap: 14px;">
                            <!-- Dokumen Perizinan Usaha -->
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                                    Unggah Dokumen Perizinan Usaha Baru (PDF / Docx, Maks 2MB)
                                </label>
                                <input type="file" name="pcr_permit_document" accept=".pdf,.docx" style="font-size: 12px; color: #475569;">
                                <?php $curDoc = $pcrCurrentData['permit_document'] ?? ($pcrCurrentData['doc_permission'] ?? ''); ?>
                                <?php if (!empty($curDoc)): ?>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 3px;">Dokumen aktif saat ini: <code><?php echo e(basename($curDoc)); ?></code></div>
                                <?php endif; ?>
                            </div>

                            <!-- Foto Bukti Tempat Usaha / Lokasi -->
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                                    Unggah Foto Bukti Tempat Usaha / Lokasi Baru (JPG / PNG, Maks 2MB)
                                </label>
                                <input type="file" name="pcr_workplace_photo" accept=".png,.jpg,.jpeg" style="font-size: 12px; color: #475569;">
                                <?php $curPhoto = $pcrCurrentData['workplace_photo'] ?? ($pcrCurrentData['doc_location_photo'] ?? ''); ?>
                                <?php if (!empty($curPhoto)): ?>
                                    <div style="font-size: 11.5px; color: #64748b; margin-top: 3px;">Foto aktif saat ini: <code><?php echo e(basename($curPhoto)); ?></code></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ─── SECTION 4: MEDIA SOSIAL ─── -->
                    <div id="pcr_box_social" style="display: none; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; margin-bottom: 16px;">
                        <div style="background: #f1f5f9; padding: 10px 16px; font-weight: 700; font-size: 13.5px; color: #0369a1;">
                            Media Sosial
                        </div>
                        <div style="padding: 16px; background: #ffffff;">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px;">
                                <!-- Instagram -->
                                <div>
                                    <label for="pcr_instagram" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                                        <i class="fa-brands fa-instagram" style="color:#e1306c; margin-right:4px;"></i> Instagram
                                    </label>
                                    <input type="text" name="pcr_instagram" id="pcr_instagram" value="<?php echo e($pcrCurrentData['instagram'] ?? ''); ?>" placeholder="@username atau tautan profil" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                                <!-- LinkedIn -->
                                <div>
                                    <label for="pcr_linkedin" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                                        <i class="fa-brands fa-linkedin" style="color:#0a66c2; margin-right:4px;"></i> LinkedIn
                                    </label>
                                    <input type="text" name="pcr_linkedin" id="pcr_linkedin" value="<?php echo e($pcrCurrentData['linkedin'] ?? ''); ?>" placeholder="https://linkedin.com/in/... atau username" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                                <!-- Facebook -->
                                <div>
                                    <label for="pcr_facebook" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                                        <i class="fa-brands fa-facebook" style="color:#1877f2; margin-right:4px;"></i> Facebook
                                    </label>
                                    <input type="text" name="pcr_facebook" id="pcr_facebook" value="<?php echo e($pcrCurrentData['facebook'] ?? ''); ?>" placeholder="@username atau tautan profil" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                                <!-- TikTok -->
                                <div>
                                    <label for="pcr_tiktok" style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">
                                        <i class="fa-brands fa-tiktok" style="color:#000000; margin-right:4px;"></i> TikTok
                                    </label>
                                    <input type="text" name="pcr_tiktok" id="pcr_tiktok" value="<?php echo e($pcrCurrentData['tiktok'] ?? ''); ?>" placeholder="@username atau tautan profil" style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 12px; font-size: 13px; color: #1e293b; box-sizing: border-box;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. KONFIRMASI PERUBAHAN CALLOUT BOX -->
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
                        <span>Saya telah membaca dan menyetujui pengajuan perubahan profil untuk diperiksa oleh Admin. <span style="color: #ef4444;">*</span></span>
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

    // Default trigger on init
    updatePcrSections();
})();
</script>
