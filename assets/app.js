window.formatSalaryInput = function(input) {
    if (!input) return;
    let value = input.value;
    let cursorPosition = input.selectionStart;
    let origLen = value.length;

    let raw = value.replace(/\D/g, '');
    if (!raw) {
        input.value = '';
        return;
    }

    let formatted = new Intl.NumberFormat('id-ID').format(raw);
    input.value = formatted;

    let newLen = formatted.length;
    cursorPosition = cursorPosition + (newLen - origLen);
    if (cursorPosition < 0) cursorPosition = 0;
    try {
        input.setSelectionRange(cursorPosition, cursorPosition);
    } catch (e) {}
};

// Theme Management (Terang, Gelap, Sistem)
function setTheme(theme) {
    localStorage.setItem('karirhub_theme', theme);
    let isDark = false;
    if (theme === 'system') {
        isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    } else {
        isDark = (theme === 'dark');
    }
    document.documentElement.classList.toggle('dark-theme', isDark);
    if (document.body) {
        document.body.classList.toggle('dark-theme', isDark);
    }

    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) {
        toggleBtn.innerHTML = isDark ? '<i class="fa-solid fa-moon" style="color:#38bdf8;"></i>' : '<i class="fa-solid fa-display"></i>';
        toggleBtn.title = isDark ? 'Tema: Gelap (Klik untuk beralih ke Terang)' : 'Tema: Terang (Klik untuk beralih ke Gelap)';
    }

    document.querySelectorAll('[data-theme-option]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.themeOption === theme);
    });

    document.querySelectorAll('[data-theme-val]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.themeVal === theme);
    });
}

function initTheme() {
    const savedTheme = localStorage.getItem('karirhub_theme') || 'light';
    setTheme(savedTheme);

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (localStorage.getItem('karirhub_theme') === 'system') {
            const prefersDark = e.matches;
            document.documentElement.classList.toggle('dark-theme', prefersDark);
            if (document.body) document.body.classList.toggle('dark-theme', prefersDark);
            const toggleBtn = document.getElementById('themeToggleBtn');
            if (toggleBtn) {
                toggleBtn.innerHTML = prefersDark ? '<i class="fa-solid fa-moon" style="color:#38bdf8;"></i>' : '<i class="fa-solid fa-display"></i>';
            }
        }
    });
}

function setActivePage(pageName) {
    const dataPages = document.querySelectorAll('.page[data-page], .page[id^="page-"]');
    if (dataPages.length === 0) {
        return;
    }

    let targetPage = pageName || 'dashboard';

    const isLockedItem = document.querySelector(`.rail-btn[data-page="${targetPage}"][data-locked="true"], .drawer-menu-item[data-page="${targetPage}"][data-locked="true"]`);
    if (isLockedItem && targetPage !== 'dashboard' && targetPage !== 'profil') {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'warning',
                title: 'Akses Dikunci',
                text: 'Menu ini belum dapat diakses. Mohon selesaikan dan tunggu verifikasi profil Pemberi Kerja Anda terlebih dahulu.',
                confirmButtonText: 'Mengerti',
                confirmButtonColor: '#002B66'
            });
        } else {
            alert('Menu ini belum dapat diakses. Mohon selesaikan dan tunggu verifikasi profil Pemberi Kerja Anda terlebih dahulu.');
        }
        targetPage = 'dashboard';
    }

    dataPages.forEach((page) => {
        const matches = page.dataset.page === targetPage || page.id === `page-${targetPage}`;
        page.classList.toggle('active', matches);
    });

    document.querySelectorAll('[data-page]:not(.page):not(section), [data-nav]').forEach((item) => {
        const p = item.dataset.page || item.dataset.nav;
        item.classList.toggle('active', p === targetPage);
    });

    const navDrawer = document.getElementById('navDrawer');
    const drawerBackdrop = document.getElementById('drawerBackdrop');
    if (navDrawer) navDrawer.classList.remove('open');
    if (drawerBackdrop) drawerBackdrop.classList.remove('open');

    if (targetPage !== 'lowongan') {
        const kbjiModal = document.querySelector('[data-modal="kbji-conflict"]');
        if (kbjiModal && (kbjiModal.classList.contains('open') || kbjiModal.style.display !== 'none')) {
            kbjiModal.classList.remove('open');
            kbjiModal.style.display = 'none';
        }
    }
    if (typeof cleanKbjiConflictUrl === 'function') {
        cleanKbjiConflictUrl();
    }

    const currentCrumb = document.querySelector('[data-current-crumb]') || document.getElementById('crumbCurrent');
    const labelMap = {
        dashboard: 'Dasbor',
        lowongan: 'Lowongan',
        jadwal: 'Jadwal Wawancara',
        profil: 'Profil Pemberi kerja',
        jobs: 'Lowongan Kerja',
        profile: 'Profil Pencari Kerja',
        directory_individual: 'Individual',
        verifikasi_employer: 'Verifikasi Profil',
        verifikasi_job: 'Verifikasi Lowongan'
    };

    if (currentCrumb) {
        currentCrumb.textContent = labelMap[targetPage] || 'Dasbor';
    }

    if (window.location.hash !== `#${targetPage}`) {
        window.location.hash = targetPage;
    }
}
window.setActivePage = setActivePage;
window.showPage = setActivePage;

function bindPageSwitchers() {
    document.querySelectorAll('[data-page], [data-nav]').forEach((item) => {
        if (item.classList.contains('page') || item.tagName === 'SECTION') return;
        item.addEventListener('click', (e) => {
            if (item.tagName === 'A' && item.getAttribute('href') && !item.getAttribute('href').startsWith('#')) {
                return; // Let normal links work
            }
            if (item.getAttribute('data-locked') === 'true' || item.classList.contains('locked-nav-item')) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Akses Dikunci',
                        text: 'Menu ini belum dapat diakses. Mohon selesaikan dan tunggu verifikasi profil Pemberi Kerja Anda terlebih dahulu.',
                        confirmButtonText: 'Mengerti',
                        confirmButtonColor: '#002B66'
                    });
                } else {
                    alert('Menu ini belum dapat diakses. Mohon selesaikan dan tunggu verifikasi profil Pemberi Kerja Anda terlebih dahulu.');
                }
                return;
            }
            const target = item.dataset.page || item.dataset.nav;
            if (target) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof showPage === 'function') {
                    showPage(target);
                } else {
                    setActivePage(target);
                }
            }
        });
    });
}

function bindModalsAndDrawers() {
    document.querySelectorAll('[data-open-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = btn.dataset.openModal;
            const modal = document.querySelector(`[data-modal="${modalId}"]`);
            if (modal) {
                modal.style.display = 'flex';
                modal.classList.add('open');
                modal.dispatchEvent(new CustomEvent('modal:open'));
                if (modalId === 'modal-employer-profile' && typeof invalidatePkiMap === 'function') {
                    invalidatePkiMap();
                }
            }
        });
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-close-modal]');
        if (btn) {
            e.preventDefault();
            const modalId = btn.dataset.closeModal;
            const modal = document.querySelector(`[data-modal="${modalId}"]`) || document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('open');
                modal.dispatchEvent(new CustomEvent('modal:close'));
            }
            if (modalId === 'kbji-conflict' && typeof cleanKbjiConflictUrl === 'function') {
                cleanKbjiConflictUrl();
            }
        }
    });

    document.querySelectorAll('[data-open-drawer]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const drawerId = btn.dataset.openDrawer;
            const drawer = document.querySelector(`[data-drawer="${drawerId}"]`);
            if (drawer) drawer.classList.add('open');
        });
    });

    document.querySelectorAll('[data-close-drawer]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const drawerId = btn.dataset.closeDrawer;
            const drawer = document.querySelector(`[data-drawer="${drawerId}"]`);
            if (drawer) drawer.classList.remove('open');
        });
    });

    document.querySelectorAll('.modal-backdrop, .drawer-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                backdrop.classList.remove('open');
            }
        });
    });
}

// Helper: Smooth scroll and focus KBJI field
function scrollAndFocusKbji() {
    const popup = document.querySelector('[data-modal="kbji-duplicate-modal"]');
    if (popup) popup.classList.remove('open');

    const jobCreateModal = document.querySelector('[data-modal="job-create"]');
    if (jobCreateModal) jobCreateModal.classList.add('open');

    const kbjiSelect = document.querySelector('[name="kbji_code"]');
    if (kbjiSelect) {
        kbjiSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
        kbjiSelect.focus();
        kbjiSelect.style.borderColor = '#ef4444';
        kbjiSelect.style.boxShadow = '0 0 0 4px rgba(239, 68, 68, 0.2)';
        setTimeout(() => {
            kbjiSelect.style.borderColor = '';
            kbjiSelect.style.boxShadow = '';
        }, 4000);
    }
}

function bindModal(openSelector, closeSelector, modalSelector) {
    const openButtons = document.querySelectorAll(openSelector);
    const closeButtons = document.querySelectorAll(closeSelector);
    const modal = document.querySelector(modalSelector);

    if (!modal || !openButtons.length) {
        return;
    }

    const open = () => {
        modal.classList.add('open');
        modal.dispatchEvent(new CustomEvent('modal:open'));
    };

    const close = () => {
        modal.classList.remove('open');
        modal.dispatchEvent(new CustomEvent('modal:close'));
    };

    openButtons.forEach((button) => {
        button.addEventListener('click', open);
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', close);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });
}

// Comprehensive Cascading Locations Dictionary for Indonesia
const ID_LOCATIONS = {
    'Aceh': {
        'Kota Banda Aceh': {
            'Kuta Alam': { 'Beurawe': '23124', 'Bandar Baru': '23126', 'Kota Baru': '23125', 'Keuramat': '23123', 'Lambaro Skep': '23127' },
            'Baiturrahman': { 'Neusu Aceh': '23241', 'Peuniti': '23241', 'Seutui': '23243', 'Ateuk Pabuat': '23244' },
            'Banda Raya': { 'Geuceu Komplek': '23238', 'Laksana': '23239' },
            'Jaya Baru': { 'Lampoh Daya': '23231', 'Punge Blang Cut': '23232' }
        },
        'Kota Sabang': {
            'Sukakarya': { 'Kuta Ateuh': '23511', 'Kuta Barat': '23512' }
        },
        'Kab. Aceh Besar': {
            'Ingin Jaya': { 'Aneu Galang': '23371', 'Ateuk Angok': '23371' }
        },
        'Kab. Pidie': {
            'Kota Sigli': { 'Blang Paseh': '24112' }
        },
        'Kab. Pidie Jaya': {
            'Trienggadeng': { 'Rawasari': '24186' }
        }
    },
    'DKI Jakarta': {
        'Jakarta Pusat': {
            'Gambir': { 'Gambir': '10110', 'Kebon Kelapa': '10120', 'Petojo Selatan': '10160', 'Duri Pulo': '10140' },
            'Tanah Abang': { 'Bendungan Hilir': '10210', 'Karet Tengsin': '10220', 'Kebon Melati': '10230', 'Kebon Kacang': '10240', 'Kampung Bali': '10250', 'Petamburan': '10260', 'Gelora': '10270' },
            'Menteng': { 'Menteng': '10310', 'Pegangsaan': '10320', 'Cikini': '10330', 'Gondangdia': '10350', 'Kebon Sirih': '10340' },
            'Senen': { 'Senen': '10410', 'Kenari': '10430', 'Paseban': '10440', 'Kramat': '10450', 'Kwitang': '10420', 'Bungur': '10460' }
        },
        'Jakarta Selatan': {
            'Kebayoran Baru': { 'Selong': '12110', 'Gunung': '12120', 'Kramat Pela': '12130', 'Gandaria Utara': '12140', 'Cipete Utara': '12150', 'Melawai': '12160', 'Pulo': '12160', 'Petogogan': '12170', 'Rawa Barat': '12180', 'Senayan': '12190' },
            'Cilandak': { 'Cilandak Barat': '12430', 'Lebak Bulus': '12440', 'Pondok Labu': '12450', 'Gandaria Selatan': '12420', 'Cipete Selatan': '12410' },
            'Setiabudi': { 'Setiabudi': '12910', 'Karet': '12920', 'Karet Semanggi': '12930', 'Karet Kuningan': '12940', 'Kuningan Timur': '12950', 'Menteng Atas': '12960', 'Pasar Manggis': '12970', 'Guntur': '12980' },
            'Pasar Minggu': { 'Pejaten Barat': '12510', 'Pejaten Timur': '12510', 'Pasar Minggu': '12520', 'Kebagusan': '12520', 'Jati Padang': '12540', 'Ragunan': '12550', 'Cilandak Timur': '12560' },
            'Tebet': { 'Tebet Barat': '12810', 'Tebet Timur': '12820', 'Kebon Baru': '12830', 'Bukit Duri': '12840', 'Manggarai': '12850', 'Manggarai Selatan': '12860', 'Menteng Dalam': '12870' }
        },
        'Jakarta Barat': {
            'Grogol Petamburan': { 'Tanjung Duren Utara': '11470', 'Tanjung Duren Selatan': '11470', 'Tomang': '11440', 'Grogol': '11450', 'Jelambar': '11460', 'Wijaya Kusuma': '11460' },
            'Kebon Jeruk': { 'Kebon Jeruk': '11530', 'Sukabumi Utara': '11540', 'Sukabumi Selatan': '11560', 'Kelapa Dua': '11550', 'Duri Kepa': '11510', 'Kedoya Selatan': '11520', 'Kedoya Utara': '11520' },
            'Kembangan': { 'Kembangan Utara': '11610', 'Kembangan Selatan': '11610', 'Meruya Utara': '11620', 'Meruya Selatan': '11650', 'Srengseng': '11630', 'Joglo': '11640' }
        },
        'Jakarta Timur': {
            'Jatinegara': { 'Bali Mester': '13310', 'Kampung Melayu': '13320', 'Bidara Cina': '13330', 'Cipinang Cempedak': '13340', 'Rawa Bunga': '13350', 'Cipinang Besar Utara': '13410', 'Cipinang Besar Selatan': '13410', 'Cipinang Muara': '13420' },
            'Duren Sawit': { 'Pondok Bambu': '13430', 'Duren Sawit': '13440', 'Pondok Kelapa': '13450', 'Malaka Jaya': '13460', 'Malaka Sari': '13460', 'Pondok Kopi': '13460', 'Klender': '13470' },
            'Matraman': { 'Pisangan Baru': '13110', 'Utan Kayu Selatan': '13120', 'Utan Kayu Utara': '13120', 'Kayu Manis': '13130', 'Pal Matraman': '13140', 'Kebon Manggis': '13150' }
        },
        'Jakarta Utara': {
            'Penjaringan': { 'Pluit': '14450', 'Pejagalan': '14450', 'Kapuk Muara': '14460', 'Kamal Muara': '14470', 'Penjaringan': '14440' },
            'Kelapa Gading': { 'Kelapa Gading Barat': '14240', 'Kelapa Gading Timur': '14240', 'Pegangsaan Dua': '14250' }
        }
    },
    'Jawa Barat': {
        'Kota Bekasi': {
            'Bekasi Selatan': { 'Pekayon Jaya': '17148', 'Jaka Setia': '17147', 'Kayuringin Jaya': '17144', 'Marga Jaya': '17141' },
            'Bekasi Timur': { 'Aren Jaya': '17111', 'Bekasi Jaya': '17112', 'Duren Jaya': '17111', 'Margahayu': '17113' },
            'Bekasi Barat': { 'Bintara': '17134', 'Kranji': '17135', 'Kota Baru': '17133', 'Jaka Sampurna': '17145' },
            'Bekasi Utara': { 'Harapan Baru': '17123', 'Harapan Jaya': '17124', 'Kaliabang Tengah': '17125', 'Perwira': '17122', 'Teluk Pucung': '17121' },
            'Pondok Gede': { 'Jatibening': '17412', 'Jatibening Baru': '17412', 'Jaticempaka': '17411', 'Jatimakmur': '17413', 'Jatiwaringin': '17411' },
            'Jatiasih': { 'Jatiasih': '17423', 'Jatikramat': '17421', 'Jatimekar': '17422', 'Jatirasa': '17424', 'Jatisari': '17426', 'Jatiluhur': '17425' },
            'Medan Satria': { 'Medan Satria': '17132', 'Pejuang': '17131', 'Harapan Mulya': '17143', 'Kali Baru': '17133' },
            'Rawalumbu': { 'Bojong Rawalumbu': '17116', 'Bojong Menteng': '17117', 'Pengasinan': '17115', 'Sepanjang Jaya': '17114' }
        },
        'Kabupaten Bekasi': {
            'Cikarang Pusat': { 'Jayamukti': '17530', 'Pasirranji': '17530', 'Pasirtanjung': '17530', 'Sukamahi': '17530', 'Cicau': '17530', 'Hegarmanah': '17530' },
            'Cikarang Selatan': { 'Ciantra': '17530', 'Cibatu': '17530', 'Pasirsari': '17530', 'Serang': '17530', 'Sukadami': '17530', 'Sukaresmi': '17530', 'Sukasejati': '17530' },
            'Cikarang Barat': { 'Danau Indah': '17520', 'Gandamekar': '17520', 'Gandasari': '17520', 'Jatiwangi': '17520', 'Kalijaya': '17520', 'Telagamurni': '17520', 'Telajung': '17520' },
            'Cikarang Utara': { 'Cikarang Kota': '17530', 'Harjamekar': '17530', 'Karangasih': '17530', 'Karangbaru': '17530', 'Pasirgombong': '17530', 'Simpangan': '17530', 'Tanjungsari': '17530' },
            'Tambun Selatan': { 'Jatimulya': '17510', 'Lambangjaya': '17510', 'Lambangsari': '17510', 'Mangunjaya': '17510', 'Setiadarma': '17510', 'Setiamekar': '17510', 'Sumberjaya': '17510', 'Tambun': '17510', 'Tridaya Sakti': '17510' }
        },
        'Kota Bandung': {
            'Coblong': { 'Dago': '40135', 'Lebak Gede': '40132', 'Lebak Siliwangi': '40132', 'Sadang Serang': '40133', 'Sekeloa': '40134' },
            'Sukajadi': { 'Cipedes': '40162', 'Pasteur': '40161', 'Sukabungah': '40162', 'Sukagalih': '40163', 'Sukawarna': '40164' },
            'Cicendo': { 'Arjuna': '40172', 'Husen Sastranegara': '40174', 'Pajajaran': '40173', 'Pamoyanan': '40173', 'Pasirkaliki': '40171', 'Sukaraja': '40175' },
            'Sumur Bandung': { 'Braga': '40111', 'Kebon Pisang': '40112', 'Merdeka': '40113', 'Babakan Ciamis': '40117' },
            'Bandung Wetan': { 'Cihapit': '40114', 'Citarum': '40115', 'Tamansari': '40116' }
        },
        'Kota Bogor': {
            'Bogor Tengah': { 'Babakan': '16128', 'Babakan Pasar': '16126', 'Cibogor': '16124', 'Ciwaringin': '16124', 'Gudang': '16123', 'Kebon Kelapa': '16125', 'Pabaton': '16121', 'Paledang': '16122', 'Panaragan': '16125', 'Sempur': '16129', 'Tegallega': '16127' },
            'Bogor Selatan': { 'Batutulis': '16133', 'Bondongan': '16131', 'Empang': '16132', 'Genteng': '16137', 'Harjasari': '16138', 'Kertamaya': '16138', 'Lawanggintung': '16134', 'Muarasari': '16137', 'Mulyaharja': '16135', 'Pakuan': '16134', 'Pamoyanan': '16136', 'Rancamaya': '16139', 'Ranggamekar': '16136' }
        },
        'Kota Depok': {
            'Pancoran Mas': { 'Depok': '16431', 'Depok Jaya': '16432', 'Mampang': '16433', 'Pancoran Mas': '16436', 'Rangkapan Jaya': '16435', 'Rangkapan Jaya Baru': '16434' },
            'Beji': { 'Beji': '16421', 'Beji Timur': '16422', 'Kemiri Muka': '16423', 'Kukusan': '16425', 'Pondok Cina': '16424', 'Tanah Baru': '16426' }
        },
        'Kota Tangerang Selatan': {
            'Serpong': { 'Buaran': '15310', 'Ciater': '15310', 'Cilenggang': '15310', 'Lengkong Gudang': '15321', 'Lengkong Gudang Timur': '15321', 'Lengkong Wetan': '15322', 'Rawa Buntu': '15318', 'Rawa Mekar Jaya': '15310', 'Serpong': '15311' },
            'Pondok Aren': { 'Jurang Mangu Barat': '15223', 'Jurang Mangu Timur': '15222', 'Pondok Kacang Barat': '15226', 'Pondok Kacang Timur': '15226', 'Pondok Karya': '15225', 'Pondok Jaya': '15224', 'Pondok Betung': '15221', 'Pondok Pucung': '15229', 'Pondok Aren': '15224' }
        }
    },
    'Banten': {
        'Kota Tangerang': {
            'Tangerang': { 'Babakan': '15118', 'Buaran Indah': '15119', 'Cikokol': '15117', 'Kelapa Indah': '15117', 'Sukarasa': '15111', 'Sukasari': '15118', 'Tanah Tinggi': '15119' },
            'Cipondoh': { 'Cipondoh': '15148', 'Cipondoh Indah': '15148', 'Cipondoh Makmur': '15148', 'Gondrong': '15146', 'Kenanga': '15146', 'Ketapang': '15147', 'Petir': '15147', 'Poris Plawad': '15141', 'Poris Plawad Indah': '15141', 'Poris Plawad Utara': '15141' }
        },
        'Kota Serang': {
            'Serang': { 'Cipare': '42117', 'Kagungan': '42114', 'Kotabaru': '42112', 'Lontarbaru': '42115', 'Serang': '42116', 'Sukawana': '42116', 'Sumurpecung': '42118' }
        }
    },
    'Jawa Tengah': {
        'Kota Semarang': {
            'Semarang Tengah': { 'Bangunharjo': '50139', 'Brumbungan': '50135', 'Gabahan': '50135', 'Jagalan': '50136', 'Karangkidul': '50136', 'Kauman': '50138', 'Kembangsari': '50133', 'Kranggan': '50139', 'Miroto': '50134', 'Pandansari': '50139', 'Pekunden': '50134', 'Pendrikan Kidul': '50131', 'Pendrikan Lor': '50131', 'Purwodinatan': '50137', 'Sekayu': '50132' },
            'Semarang Barat': { 'Bojongsalaman': '50141', 'Bongsari': '50148', 'Cabean': '50141', 'Gisikdrono': '50149', 'Kalibanteng Kidul': '50145', 'Kalibanteng Kulon': '50145', 'Karangayu': '50149', 'Krobokan': '50141', 'Manyaran': '50147', 'Ngemplak Simongan': '50148', 'Salamanmloyo': '50149', 'Tambakharjo': '50149', 'Tawangmas': '50144', 'Tawangsari': '50144' }
        },
        'Kota Surakarta': {
            'Banjarsari': { 'Banyuanyar': '57137', 'Gilingan': '57134', 'Kadipiro': '57136', 'Keprabon': '57131', 'Kestalan': '57133', 'Ketelan': '57132', 'Manahan': '57139', 'Mangkubumen': '57139', 'Nusukan': '57135', 'Punggawan': '57132', 'Setabelan': '57133', 'Sumber': '57138', 'Timuran': '57131' }
        }
    },
    'DI Yogyakarta': {
        'Kota Yogyakarta': {
            'Danurejan': { 'Bausasran': '55211', 'Tegal Panggung': '55212', 'Suryatmajan': '55213' },
            'Gondomanan': { 'Ngupasan': '55122', 'Prawirodirjan': '55121' },
            'Kotagede': { 'Prenggan': '55172', 'Purbayan': '55173', 'Rejowinangun': '55171' }
        },
        'Kabupaten Sleman': {
            'Depok': { 'Caturtunggal': '55281', 'Condongcatur': '55283', 'Maguwoharjo': '55282' },
            'Mlati': { 'Sendangadi': '55285', 'Sinduadi': '55284', 'Sumberadi': '55288', 'Tirtoadi': '55287', 'Tlogoadi': '55286' }
        }
    },
    'Jawa Timur': {
        'Kota Surabaya': {
            'Genteng': { 'Embong Kaliasin': '60271', 'Genteng': '60272', 'Kapasari': '60273', 'Ketabang': '60272', 'Peneleh': '60274' },
            'Gubeng': { 'Airlangga': '60286', 'Barata Jaya': '60284', 'Gubeng': '60281', 'Kertajaya': '60282', 'Mojo': '60285', 'Pucang Sewu': '60283' },
            'Wonokromo': { 'Darmo': '60241', 'Jagir': '60244', 'Ngagel': '60246', 'Ngagelrejo': '60245', 'Sawunggaling': '60242', 'Wonokromo': '60243' }
        },
        'Kota Malang': {
            'Klojen': { 'Bareng': '65116', 'Gadingasri': '65115', 'Kasin': '65117', 'Kauman': '65119', 'Kiduldalem': '65119', 'Klojen': '65111', 'Oro-oro Dowo': '65112', 'Penanggungan': '65113', 'Rampal Celaket': '65111', 'Samaan': '65112', 'Sukoharjo': '65118' },
            'Lowokwaru': { 'Dinoyo': '65144', 'Jatimulyo': '65141', 'Ketawanggede': '65145', 'Lowokwaru': '65141', 'Merjosari': '65144', 'Mojolangu': '65142', 'Sumbersari': '65145', 'Tasikmadu': '65143', 'Tlogomas': '65144', 'Tulusrejo': '65143', 'Tunggulwulung': '65143' }
        }
    },
    'Bali': {
        'Kota Denpasar': {
            'Denpasar Barat': { 'Dauh Puri': '80113', 'Padangsambian': '80118', 'Pemecutan': '80111' },
            'Denpasar Selatan': { 'Panjer': '80225', 'Pedungan': '80222', 'Sanur': '80228', 'Renon': '80226' }
        },
        'Kabupaten Badung': {
            'Kuta': { 'Kedonganan': '80361', 'Tuban': '80361', 'Kuta': '80361', 'Legian': '80361', 'Seminyak': '80361' },
            'Kuta Selatan': { 'Jimbaran': '80361', 'Benoa': '80361', 'Pecatu': '80361', 'Ungasan': '80361' }
        }
    },
    'Sumatera Utara': {
        'Kota Medan': {
            'Medan Kota': { 'Kotamatsum III': '20215', 'Mesjid': '20212', 'Pasar Baru': '20212', 'Pasar Merah Barat': '20214', 'Pusat Pasar': '20211', 'Sitirejo II': '20216', 'Sudirejo I': '20218', 'Sudirejo II': '20218', 'Teladan Barat': '20217', 'Teladan Timur': '20217' },
            'Medan Petisah': { 'Petisah Tengah': '20112', 'Sekip': '20113', 'Sei Putih Barat': '20118', 'Sei Putih Timur I': '20118', 'Sei Putih Timur II': '20118', 'Sei Putih Tengah': '20118', 'Sikambing D': '20111' }
        }
    }
};

const ID_COORDINATES = {
    'DKI Jakarta': [-6.2088, 106.8456],
    'Jawa Barat': [-6.9175, 107.6191],
    'Banten': [-6.4058, 106.0640],
    'Jawa Tengah': [-7.1510, 110.1403],
    'DI Yogyakarta': [-7.7956, 110.3695],
    'Jawa Timur': [-7.5360, 112.2384],
    'Bali': [-8.4095, 115.1889],
    'Sumatera Utara': [2.1154, 99.5451],

    'Jakarta Pusat': [-6.1805, 106.8284],
    'Jakarta Selatan': [-6.2615, 106.8106],
    'Jakarta Barat': [-6.1683, 106.7588],
    'Jakarta Timur': [-6.2250, 106.9004],
    'Jakarta Utara': [-6.1384, 106.8640],
    'Kota Bekasi': [-6.2383, 106.9756],
    'Kabupaten Bekasi': [-6.3644, 107.1725],
    'Kota Bandung': [-6.9175, 107.6191],
    'Kota Bogor': [-6.5971, 106.8060],
    'Kota Depok': [-6.4025, 106.7942],
    'Kota Tangerang': [-6.1783, 106.6319],
    'Kota Tangerang Selatan': [-6.2887, 106.7179],
    'Kota Serang': [-6.1104, 106.1639],
    'Kota Semarang': [-6.9667, 110.4167],
    'Kota Surakarta': [-7.5755, 110.8243],
    'Kota Yogyakarta': [-7.7956, 110.3695],
    'Kabupaten Sleman': [-7.7156, 110.3556],
    'Kota Surabaya': [-7.2575, 112.7521],
    'Kota Malang': [-7.9797, 112.6304],
    'Kota Denpasar': [-8.6705, 115.2126],
    'Kabupaten Badung': [-8.5819, 115.1771],
    'Kota Medan': [3.5952, 98.6722],

    'Bekasi Selatan': [-6.2572, 106.9896],
    'Pekayon Jaya': [-6.2639, 106.9858],
    'Kebayoran Baru': [-6.2443, 106.7997],
    'Coblong': [-6.8833, 107.6167],
    'Dago': [-6.8770, 107.6186]
};

let pkiLeafletMap = null;
let pkiMapMarker = null;

function invalidatePkiMap() {
    setTimeout(() => {
        if (pkiLeafletMap) {
            pkiLeafletMap.invalidateSize();
        }
    }, 250);
}

function updatePkiMap(lat, lng) {
    const latInput = document.getElementById('inputLat');
    const lngInput = document.getElementById('inputLng');
    const mapDiv = document.getElementById('leafletMap');
    const emptyState = document.getElementById('mapEmptyState');
    const openBtn = document.getElementById('btnOpenMap');

    const latitude = parseFloat(lat);
    const longitude = parseFloat(lng);

    if (isNaN(latitude) || isNaN(longitude) || latitude === 0 || longitude === 0) {
        if (mapDiv) mapDiv.style.display = 'none';
        if (emptyState) emptyState.style.display = 'flex';
        if (openBtn) openBtn.style.display = 'none';
        if (latInput) latInput.value = '';
        if (lngInput) lngInput.value = '';
        return;
    }

    if (latInput) latInput.value = latitude.toFixed(6);
    if (lngInput) lngInput.value = longitude.toFixed(6);

    if (emptyState) emptyState.style.display = 'none';
    if (mapDiv) mapDiv.style.display = 'block';

    if (openBtn) {
        openBtn.style.display = 'inline-flex';
        openBtn.href = `https://www.google.com/maps?q=${latitude},${longitude}`;
    }

    if (typeof L === 'undefined') return;

    if (!pkiLeafletMap) {
        pkiLeafletMap = L.map('leafletMap', {
            center: [latitude, longitude],
            zoom: 15,
            dragging: true,
            touchZoom: true,
            scrollWheelZoom: true,
            doubleClickZoom: true,
            boxZoom: true
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(pkiLeafletMap);

        pkiMapMarker = L.marker([latitude, longitude], {
            draggable: false,
            interactive: false
        }).addTo(pkiLeafletMap);
    } else {
        pkiLeafletMap.setView([latitude, longitude], 15);
        if (pkiMapMarker) {
            pkiMapMarker.setLatLng([latitude, longitude]);
        } else {
            pkiMapMarker = L.marker([latitude, longitude], { draggable: false, interactive: false }).addTo(pkiLeafletMap);
        }
        invalidatePkiMap();
    }
}

function removeUploadedDoc(inputId, previewId) {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    if (input) input.value = '';
    if (preview) preview.style.display = 'none';
}

function autoGeocodeLocation() {
    const prov = document.getElementById('hiddenProvinceName')?.value || '';
    const city = document.getElementById('hiddenCityName')?.value || '';
    const dist = document.getElementById('hiddenDistrictName')?.value || '';
    const vill = document.getElementById('hiddenVillageName')?.value || '';
    const addr = document.getElementById('inputAddress')?.value.trim() || '';

    // Map ONLY displays if BOTH location (prov + city) AND address are provided!
    if (!addr || !prov || !city) {
        updatePkiMap(null, null);
        return;
    }

    let coords = null;
    if (vill && typeof ID_COORDINATES !== 'undefined' && ID_COORDINATES[vill]) {
        coords = ID_COORDINATES[vill];
    } else if (dist && typeof ID_COORDINATES !== 'undefined' && ID_COORDINATES[dist]) {
        coords = ID_COORDINATES[dist];
    } else if (city && typeof ID_COORDINATES !== 'undefined' && ID_COORDINATES[city]) {
        coords = ID_COORDINATES[city];
    } else if (prov && typeof ID_COORDINATES !== 'undefined' && ID_COORDINATES[prov]) {
        coords = ID_COORDINATES[prov];
    }

    if (coords) {
        updatePkiMap(coords[0], coords[1]);
    } else {
        const existingLat = document.getElementById('inputLat')?.value;
        const existingLng = document.getElementById('inputLng')?.value;
        if (existingLat && existingLng) {
            updatePkiMap(existingLat, existingLng);
        } else {
            updatePkiMap(null, null);
        }
    }
}

function initHierarchicalLocationSelector() {
    const trigger = document.getElementById('locationBoxTrigger');
    const display = document.getElementById('locationBoxDisplay');
    const caret = document.getElementById('locationBoxCaret');
    const panel = document.getElementById('locationDropdownPanel');
    const breadcrumb = document.getElementById('locationBreadcrumbHeader');
    const levelTitle = document.getElementById('locationLevelTitleBar');
    const optionsList = document.getElementById('locationOptionsContainer');

    const hidProv = document.getElementById('hiddenProvinceName');
    const hidCity = document.getElementById('hiddenCityName');
    const hidDomCity = document.getElementById('hiddenDomicileCityId');
    const hidDist = document.getElementById('hiddenDistrictName');
    const hidVill = document.getElementById('hiddenVillageName');
    const postalInput = document.getElementById('inputPostalCode');

    if (!trigger || !panel || !optionsList) return;

    let selectedProv = hidProv?.value || '';
    let selectedCity = hidCity?.value || '';
    let selectedDist = hidDist?.value || '';
    let selectedVill = hidVill?.value || '';
    let currentLevel = 1;

    function renderPanel() {
        if (currentLevel === 1) {
            if (breadcrumb) breadcrumb.style.display = 'none';
            if (levelTitle) levelTitle.textContent = 'Pilih Provinsi';
            const provinces = typeof ID_LOCATIONS !== 'undefined' ? Object.keys(ID_LOCATIONS) : [];
            optionsList.innerHTML = provinces.map(p => {
                const isSel = p === selectedProv;
                return `<div class="loc-opt-row ${isSel ? 'selected' : ''}" data-loc-val="${p}" data-loc-type="prov">
                    <span>${isSel ? '●' : '○'} ${p}</span>
                    ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                </div>`;
            }).join('');
        } else if (currentLevel === 2) {
            if (breadcrumb) {
                breadcrumb.style.display = 'flex';
                breadcrumb.innerHTML = `<span class="crumb-btn" data-crumb-lvl="1">${selectedProv}</span>`;
            }
            if (levelTitle) levelTitle.textContent = 'Pilih Kabupaten / Kota';
            const cities = (typeof ID_LOCATIONS !== 'undefined' && ID_LOCATIONS[selectedProv]) ? Object.keys(ID_LOCATIONS[selectedProv]) : [];
            optionsList.innerHTML = cities.map(c => {
                const isSel = c === selectedCity;
                return `<div class="loc-opt-row ${isSel ? 'selected' : ''}" data-loc-val="${c}" data-loc-type="city">
                    <span>${isSel ? '●' : '○'} ${c}</span>
                    ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                </div>`;
            }).join('');
        } else if (currentLevel === 3) {
            if (breadcrumb) {
                breadcrumb.style.display = 'flex';
                breadcrumb.innerHTML = `<span class="crumb-btn" data-crumb-lvl="1">${selectedProv}</span> › <span class="crumb-btn" data-crumb-lvl="2">${selectedCity}</span>`;
            }
            if (levelTitle) levelTitle.textContent = 'Pilih Kecamatan';
            const districts = (typeof ID_LOCATIONS !== 'undefined' && ID_LOCATIONS[selectedProv]?.[selectedCity]) ? Object.keys(ID_LOCATIONS[selectedProv][selectedCity]) : [];
            optionsList.innerHTML = districts.map(d => {
                const isSel = d === selectedDist;
                return `<div class="loc-opt-row ${isSel ? 'selected' : ''}" data-loc-val="${d}" data-loc-type="dist">
                    <span>${isSel ? '●' : '○'} ${d}</span>
                    ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                </div>`;
            }).join('');
        } else if (currentLevel === 4) {
            if (breadcrumb) {
                breadcrumb.style.display = 'flex';
                breadcrumb.innerHTML = `<span class="crumb-btn" data-crumb-lvl="1">${selectedProv}</span> › <span class="crumb-btn" data-crumb-lvl="2">${selectedCity}</span> › <span class="crumb-btn" data-crumb-lvl="3">${selectedDist}</span>`;
            }
            if (levelTitle) levelTitle.textContent = 'Pilih Kelurahan / Desa';
            const villages = (typeof ID_LOCATIONS !== 'undefined' && ID_LOCATIONS[selectedProv]?.[selectedCity]?.[selectedDist]) ? Object.keys(ID_LOCATIONS[selectedProv][selectedCity][selectedDist]) : [];
            optionsList.innerHTML = villages.map(v => {
                const isSel = v === selectedVill;
                return `<div class="loc-opt-row ${isSel ? 'selected' : ''}" data-loc-val="${v}" data-loc-type="vill">
                    <span>${isSel ? '●' : '○'} ${v}</span>
                    ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                </div>`;
            }).join('');
        }
    }

    function togglePanel() {
        const isOpen = panel.style.display === 'block';
        if (isOpen) {
            panel.style.display = 'none';
            if (caret) caret.style.transform = 'rotate(0deg)';
        } else {
            // Determine level to open
            if (selectedProv && selectedCity && selectedDist && selectedVill) {
                currentLevel = 4; // Open directly at Kelurahan / Desa!
            } else if (selectedProv && selectedCity && selectedDist) {
                currentLevel = 4;
            } else if (selectedProv && selectedCity) {
                currentLevel = 3;
            } else if (selectedProv) {
                currentLevel = 2;
            } else {
                currentLevel = 1;
            }
            renderPanel();
            panel.style.display = 'block';
            if (caret) caret.style.transform = 'rotate(180deg)';
        }
    }

    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        togglePanel();
    });

    panel.addEventListener('click', (e) => {
        e.stopPropagation();
        const crumb = e.target.closest('[data-crumb-lvl]');
        if (crumb) {
            currentLevel = parseInt(crumb.dataset.crumbLvl, 10);
            renderPanel();
            return;
        }

        const opt = e.target.closest('[data-loc-val]');
        if (!opt) return;

        const val = opt.dataset.locVal;
        const type = opt.dataset.locType;

        if (type === 'prov') {
            if (selectedProv !== val) {
                selectedProv = val;
                selectedCity = '';
                selectedDist = '';
                selectedVill = '';
            }
            currentLevel = 2;
            renderPanel();
        } else if (type === 'city') {
            if (selectedCity !== val) {
                selectedCity = val;
                selectedDist = '';
                selectedVill = '';
            }
            if (hidCity) hidCity.value = val;
            if (hidDomCity) hidDomCity.value = val;
            currentLevel = 3;
            renderPanel();
        } else if (type === 'dist') {
            if (selectedDist !== val) {
                selectedDist = val;
                selectedVill = '';
            }
            if (hidDist) hidDist.value = val;
            currentLevel = 4;
            renderPanel();
        } else if (type === 'vill') {
            selectedVill = val;
            if (hidProv) hidProv.value = selectedProv;
            if (hidCity) hidCity.value = selectedCity;
            if (hidDomCity) hidDomCity.value = selectedCity;
            if (hidDist) hidDist.value = selectedDist;
            if (hidVill) hidVill.value = selectedVill;

            if (typeof ID_LOCATIONS !== 'undefined' && ID_LOCATIONS[selectedProv]?.[selectedCity]?.[selectedDist]?.[selectedVill]) {
                const pCode = ID_LOCATIONS[selectedProv][selectedCity][selectedDist][selectedVill];
                if (postalInput && pCode) postalInput.value = pCode;
            }

            if (display) {
                display.innerHTML = `${selectedVill}, ${selectedDist}, ${selectedCity}, ${selectedProv}`;
                display.style.color = '#0f172a';
                display.style.fontWeight = '600';
            }

            panel.style.display = 'none';
            if (caret) caret.style.transform = 'rotate(0deg)';

            autoGeocodeLocation();
        }
    });

    document.addEventListener('click', (e) => {
        if (panel.style.display === 'block' && !panel.contains(e.target) && !trigger.contains(e.target)) {
            panel.style.display = 'none';
            if (caret) caret.style.transform = 'rotate(0deg)';
        }
    });
}

function initEmployerProfileForm() {
    const nikInput = document.getElementById('inputNik');
    const nikError = document.getElementById('nikErrorMsg');
    const addrInput = document.getElementById('inputAddress');
    const cbSameLoc = document.getElementById('cbSameLocation');
    const noticeLoc = document.getElementById('siapkerjaLocNotice');
    const docInput = document.getElementById('inputPermitDoc');
    const photoInput = document.getElementById('inputWorkplacePhoto');
    const form = document.getElementById('formEmployerProfile');

    if (nikInput) {
        nikInput.addEventListener('input', () => {
            nikInput.value = nikInput.value.replace(/\D/g, '').slice(0, 16);
            if (nikInput.value.length === 16) {
                if (nikError) nikError.style.display = 'none';
                nikInput.setCustomValidity('');
            }
        });
        nikInput.addEventListener('blur', () => {
            if (nikInput.value.length !== 16 && nikInput.value.length > 0) {
                if (nikError) nikError.style.display = 'block';
                nikInput.setCustomValidity('NIK harus terdiri dari 16 digit angka.');
            } else {
                if (nikError) nikError.style.display = 'none';
                nikInput.setCustomValidity('');
            }
        });
    }

    if (addrInput) {
        addrInput.addEventListener('input', () => {
            autoGeocodeLocation();
        });
    }

    if (cbSameLoc) {
        cbSameLoc.addEventListener('change', () => {
            if (cbSameLoc.checked) {
                if (noticeLoc) noticeLoc.style.display = 'block';
            } else {
                if (noticeLoc) noticeLoc.style.display = 'none';
            }
        });
    }

    if (docInput) {
        docInput.addEventListener('change', () => {
            const preview = document.getElementById('permitDocPreview');
            const nameEl = document.getElementById('permitDocName');
            if (docInput.files && docInput.files[0]) {
                if (nameEl) nameEl.textContent = '📄 ' + docInput.files[0].name;
                if (preview) preview.style.display = 'flex';
            }
        });
    }

    if (photoInput) {
        photoInput.addEventListener('change', () => {
            const preview = document.getElementById('workplacePhotoPreview');
            const nameEl = document.getElementById('workplacePhotoName');
            if (photoInput.files && photoInput.files[0]) {
                if (nameEl) nameEl.textContent = '📷 ' + photoInput.files[0].name;
                if (preview) preview.style.display = 'flex';
            }
        });
    }

    if (form) {
        form.addEventListener('submit', (e) => {
            if (nikInput && nikInput.value.length !== 16) {
                e.preventDefault();
                if (nikError) nikError.style.display = 'block';
                nikInput.setCustomValidity('NIK harus terdiri dari 16 digit angka.');
                nikInput.reportValidity();
                return false;
            }
        });
    }

    // Initial map check
    const initialLat = document.getElementById('inputLat')?.value;
    const initialLng = document.getElementById('inputLng')?.value;
    if (initialLat && initialLng) {
        updatePkiMap(initialLat, initialLng);
    } else {
        autoGeocodeLocation();
    }
}

function initRichEditors(root) {
    root.querySelectorAll('[data-rich-editor]').forEach((editor) => {
        if (editor.dataset.bound === 'true') {
            return;
        }
        editor.dataset.bound = 'true';

        const area = editor.querySelector('.rich-area');
        const input = editor.querySelector('textarea');
        if (!area || !input) {
            return;
        }

        const sync = () => {
            const text = (area.innerText || '').replace(/\u00a0/g, ' ').trim();
            input.value = text ? area.innerHTML.trim() : '';
        };

        editor.querySelectorAll('[data-cmd]').forEach((button) => {
            button.addEventListener('click', () => {
                area.focus();
                const command = button.dataset.cmd;
                if (command === 'createLink') {
                    const url = window.prompt('Masukkan tautan');
                    if (url) {
                        document.execCommand(command, false, url);
                    }
                } else {
                    document.execCommand(command, false, null);
                }
                sync();
            });
        });

        editor.querySelectorAll('[data-cmd-select]').forEach((select) => {
            select.addEventListener('change', () => {
                area.focus();
                document.execCommand(select.dataset.cmdSelect, false, select.value);
                sync();
            });
        });



        area.addEventListener('input', sync);
        area.addEventListener('blur', sync);
    });
}

function initChipField(root, key, options = {}) {
    const select = root.querySelector(`[data-chip-select="${key}"]`);
    const textInput = root.querySelector(`[data-chip-input="${key}"]`);
    const list = root.querySelector(`[data-chip-list="${key}"]`);
    const hidden = root.querySelector(`[data-chip-value="${key}"]`);
    if (!list || !hidden) {
        return;
    }

    const values = () => hidden.value.split(',').map((item) => item.trim()).filter(Boolean);

    const render = () => {
        list.replaceChildren();
        values().forEach((item) => {
            const chip = document.createElement('span');
            chip.className = 'choice-chip';
            chip.append(item);
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.removeChip = item;
            button.setAttribute('aria-label', 'Hapus');
            button.innerHTML = '&times;';
            chip.append(button);
            list.append(chip);
        });
    };
    hidden.refreshChips = render;

    const setValues = (next) => {
        hidden.value = [...new Set(next.map((item) => item.trim()).filter(Boolean))].join(',');
        hidden.setCustomValidity(hidden.value ? '' : (hidden.required ? 'Wajib diisi' : ''));
        render();
    };

    const addValue = (value) => {
        if (!value) {
            return;
        }
        setValues([...values(), value]);
    };

    if (select) {
        select.addEventListener('change', () => {
            addValue(select.value);
            select.value = '';
        });
    }

    if (textInput) {
        const commit = () => {
            addValue(textInput.value);
            textInput.value = '';
        };
        textInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                commit();
            }
        });
        textInput.addEventListener('blur', commit);
    }

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-chip]');
        if (!button) {
            return;
        }
        setValues(values().filter((item) => item !== button.dataset.removeChip));
    });

    if (options.initial && !hidden.value) {
        hidden.value = options.initial;
    }
    render();
}

function initJobCreateWizard() {
    const modal = document.querySelector('[data-modal="job-create"]');
    if (!modal) {
        return;
    }

    const form = modal.querySelector('[data-job-create-form]');
    const steps = [...modal.querySelectorAll('[data-job-step]')];
    const labels = [...modal.querySelectorAll('[data-step-label]')];
    const lines = [...modal.querySelectorAll('[data-step-line]')];
    const btnCancel = modal.querySelector('[data-job-cancel]');
    const btnBack = modal.querySelector('[data-job-back]');
    const btnNext = modal.querySelector('[data-job-next]');
    const btnSubmit = modal.querySelector('[data-job-submit]');
    const titleEl = document.getElementById('jobCreateTitle');
    const subtitleEl = document.getElementById('jobCreateSubtitle');
    const banner = document.getElementById('revisionBanner');
    const bannerText = document.getElementById('revisionBannerText');
    let current = 1;

    initRichEditors(modal);
    initChipField(modal, 'skills');
    initChipField(modal, 'contacts');

    const initJobLocationPicker = (modalEl) => {
        const jobLocationInput = modalEl.querySelector('#jobLocationInput');
        const trigger = modalEl.querySelector('#jobLocationTrigger');
        const display = modalEl.querySelector('#jobLocationDisplay');
        const caret = modalEl.querySelector('#jobLocationCaret');
        const dropdown = modalEl.querySelector('#jobLocationDropdown');
        const breadcrumbs = modalEl.querySelector('#jobLocationBreadcrumbs');
        const optionsList = modalEl.querySelector('#jobLocationOptionsList');
        const chkSameDomicile = modalEl.querySelector('#chkSameDomicile');

        if (!jobLocationInput || !trigger || !dropdown) return;

        let currentLvl = 1;
        let sProv = '', sCity = '', sDistrict = '', sVillage = '';

        const parseLocationString = (str) => {
            if (!str) {
                sProv = ''; sCity = ''; sDistrict = ''; sVillage = '';
                return;
            }
            const parts = str.split(',').map(s => s.trim());
            if (parts.length >= 4) {
                sVillage = parts[0];
                sDistrict = parts[1];
                sCity = parts[2];
                sProv = parts[3];
            } else if (parts.length === 3) {
                sVillage = '';
                sDistrict = parts[0];
                sCity = parts[1];
                sProv = parts[2];
            } else if (parts.length === 2) {
                sVillage = ''; sDistrict = '';
                sCity = parts[0];
                sProv = parts[1];
            } else if (parts.length === 1) {
                sVillage = ''; sDistrict = ''; sCity = '';
                sProv = parts[0];
            }
        };

        const updateDisplay = () => {
            if (jobLocationInput.value) {
                display.textContent = jobLocationInput.value;
                display.classList.remove('placeholder');
            } else {
                display.textContent = 'Pilih lokasi loker';
                display.classList.add('placeholder');
            }
        };

        const renderBreadcrumbs = () => {
            if (!breadcrumbs) return;
            let html = '';
            if (currentLvl === 1) {
                html = '<span class="crumb-btn active" data-lvl="1">Pilih Provinsi</span>';
            } else if (currentLvl === 2) {
                html = `<span class="crumb-btn" data-lvl="1">${sProv || 'Provinsi'}</span> › <span class="crumb-btn active" data-lvl="2">Pilih Kab/Kota</span>`;
            } else if (currentLvl === 3) {
                html = `<span class="crumb-btn" data-lvl="1">${sProv || 'Provinsi'}</span> › <span class="crumb-btn" data-lvl="2">${sCity || 'Kab/Kota'}</span> › <span class="crumb-btn active" data-lvl="3">Pilih Kecamatan</span>`;
            } else if (currentLvl === 4) {
                html = `<span class="crumb-btn" data-lvl="1">${sProv || 'Provinsi'}</span> › <span class="crumb-btn" data-lvl="2">${sCity || 'Kab/Kota'}</span> › <span class="crumb-btn" data-lvl="3">${sDistrict || 'Kecamatan'}</span> › <span class="crumb-btn active" data-lvl="4">Pilih Kel/Desa</span>`;
            }
            breadcrumbs.innerHTML = html;

            breadcrumbs.querySelectorAll('.crumb-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const lvl = parseInt(btn.getAttribute('data-lvl'), 10);
                    if (lvl) {
                        currentLvl = lvl;
                        renderView();
                    }
                });
            });
        };

        const renderOptions = () => {
            if (!optionsList) return;
            let html = '';
            const locDb = (typeof PKI_FORM_LOCATIONS !== 'undefined') ? PKI_FORM_LOCATIONS : {};

            if (currentLvl === 1) {
                const provs = Object.keys(locDb);
                provs.forEach(p => {
                    const isSel = p === sProv;
                    html += `<div class="loc-opt-row ${isSel ? 'selected' : ''}" data-val="${p}">
                        <div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>${p}</span></div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    </div>`;
                });
            } else if (currentLvl === 2) {
                const cities = Object.keys(locDb[sProv] || {});
                cities.forEach(c => {
                    const isSel = c === sCity;
                    html += `<div class="loc-opt-row ${isSel ? 'selected' : ''}" data-val="${c}">
                        <div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>${c}</span></div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    </div>`;
                });
            } else if (currentLvl === 3) {
                const dists = Object.keys(locDb[sProv]?.[sCity] || {});
                dists.forEach(d => {
                    const isSel = d === sDistrict;
                    html += `<div class="loc-opt-row ${isSel ? 'selected' : ''}" data-val="${d}">
                        <div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>${d}</span></div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    </div>`;
                });
            } else if (currentLvl === 4) {
                const vills = locDb[sProv]?.[sCity]?.[sDistrict] || [];
                vills.forEach(v => {
                    const isSel = v === sVillage;
                    html += `<div class="loc-opt-row ${isSel ? 'selected' : ''}" data-val="${v}">
                        <div style="display:flex; align-items:center;"><span class="radio-bullet"></span><span>${v}</span></div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    </div>`;
                });
            }
            optionsList.innerHTML = html;

            optionsList.querySelectorAll('.loc-opt-row').forEach(row => {
                row.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const val = row.getAttribute('data-val');
                    if (currentLvl === 1) {
                        sProv = val; sCity = ''; sDistrict = ''; sVillage = '';
                        currentLvl = 2;
                        renderView();
                    } else if (currentLvl === 2) {
                        sCity = val; sDistrict = ''; sVillage = '';
                        currentLvl = 3;
                        renderView();
                    } else if (currentLvl === 3) {
                        sDistrict = val; sVillage = '';
                        currentLvl = 4;
                        renderView();
                    } else if (currentLvl === 4) {
                        sVillage = val;
                        const fullLoc = `${sVillage}, ${sDistrict}, ${sCity}, ${sProv}`;
                        jobLocationInput.value = fullLoc;
                        if (chkSameDomicile) chkSameDomicile.checked = false;
                        updateDisplay();
                        closeDropdown();
                    }
                });
            });
        };

        const renderView = () => {
            renderBreadcrumbs();
            renderOptions();
        };

        const openDropdown = () => {
            dropdown.style.display = 'block';
            if (caret) {
                caret.classList.remove('fa-chevron-down');
                caret.classList.add('fa-chevron-up');
            }
            parseLocationString(jobLocationInput.value);
            const locDb = (typeof PKI_FORM_LOCATIONS !== 'undefined') ? PKI_FORM_LOCATIONS : {};
            if (sProv && sCity && sDistrict && sVillage && locDb[sProv]?.[sCity]?.[sDistrict]) {
                currentLvl = 4;
            } else if (sProv && sCity && sDistrict && locDb[sProv]?.[sCity]?.[sDistrict]) {
                currentLvl = 4;
            } else if (sProv && sCity && locDb[sProv]?.[sCity]) {
                currentLvl = 3;
            } else if (sProv && locDb[sProv]) {
                currentLvl = 2;
            } else {
                currentLvl = 1;
            }
            renderView();
        };

        const closeDropdown = () => {
            dropdown.style.display = 'none';
            if (caret) {
                caret.classList.remove('fa-chevron-up');
                caret.classList.add('fa-chevron-down');
            }
        };

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (dropdown.style.display === 'block') {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        document.addEventListener('click', (e) => {
            if (!trigger.contains(e.target) && !dropdown.contains(e.target)) {
                closeDropdown();
            }
        });

        if (chkSameDomicile) {
            let savedManualLocation = '';
            chkSameDomicile.addEventListener('change', () => {
                if (chkSameDomicile.checked) {
                    savedManualLocation = jobLocationInput.value;
                    const domAddr = chkSameDomicile.getAttribute('data-domicile-address') || '';
                    jobLocationInput.value = domAddr;
                } else {
                    jobLocationInput.value = savedManualLocation;
                }
                updateDisplay();
            });
        }

        jobLocationInput.updateDisplay = updateDisplay;
        updateDisplay();
    };

    initJobLocationPicker(modal);

    const initJobIndustryPicker = (modalEl) => {
        const hiddenInput = modalEl.querySelector('#jobIndustryInput');
        const trigger = modalEl.querySelector('#jobIndustryTrigger');
        const display = modalEl.querySelector('#jobIndustryDisplay');
        const dropdown = modalEl.querySelector('#jobIndustryDropdown');
        const searchInput = modalEl.querySelector('#jobIndustrySearch');
        const optionsList = modalEl.querySelector('#jobIndustryOptions');
        const noResults = modalEl.querySelector('#jobIndustryNoResults');

        if (!hiddenInput || !trigger || !dropdown) return;

        const openDropdown = () => {
            dropdown.style.display = 'block';
            trigger.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            if (searchInput) {
                searchInput.value = '';
                filterOptions('');
                setTimeout(() => searchInput.focus(), 40);
            }
        };

        const closeDropdown = () => {
            dropdown.style.display = 'none';
            trigger.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        };

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            if (dropdown.style.display === 'block') {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        trigger.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                e.preventDefault();
                openDropdown();
            }
        });

        dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        document.addEventListener('click', (e) => {
            if (!trigger.contains(e.target) && !dropdown.contains(e.target)) {
                closeDropdown();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && dropdown.style.display === 'block') {
                closeDropdown();
                trigger.focus();
            }
        });

        const filterOptions = (query) => {
            const q = query.toLowerCase().trim();
            if (!optionsList) return;
            const groups = optionsList.querySelectorAll('.pki-select-group');
            let totalVisible = 0;

            groups.forEach((group) => {
                const options = group.querySelectorAll('.pki-select-option');
                let groupVisible = 0;

                options.forEach((opt) => {
                    const val = (opt.getAttribute('data-value') || opt.textContent).toLowerCase();
                    if (!q || val.includes(q)) {
                        opt.style.display = 'flex';
                        groupVisible++;
                        totalVisible++;
                    } else {
                        opt.style.display = 'none';
                    }
                });

                group.style.display = groupVisible > 0 ? 'block' : 'none';
            });

            if (noResults) {
                noResults.style.display = totalVisible === 0 ? 'block' : 'none';
            }
        };

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                filterOptions(this.value);
            });
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const firstOpt = optionsList?.querySelector('.pki-select-option:not([style*="display: none"])');
                    if (firstOpt) {
                        firstOpt.click();
                    }
                }
            });
        }

        const setVal = (val) => {
            const v = val ? String(val).trim() : '';
            hiddenInput.value = v;
            if (display) {
                if (v) {
                    display.textContent = v;
                    display.classList.remove('is-placeholder');
                } else {
                    display.textContent = 'Pilih Jenis Usaha Individu';
                    display.classList.add('is-placeholder');
                }
            }
            if (optionsList) {
                optionsList.querySelectorAll('.pki-select-option').forEach((o) => {
                    const optVal = o.getAttribute('data-value') || o.textContent.trim();
                    const isSelected = optVal === v;
                    o.classList.toggle('selected', isSelected);
                    const check = o.querySelector('.pki-select-check');
                    if (check) check.remove();
                    if (isSelected) {
                        const checkIcon = document.createElement('i');
                        checkIcon.className = 'fa-solid fa-check pki-select-check';
                        o.appendChild(checkIcon);
                    }
                });
            }
            trigger.classList.remove('has-error');
        };

        if (optionsList) {
            optionsList.addEventListener('click', (e) => {
                const opt = e.target.closest('.pki-select-option');
                if (!opt) return;
                const val = opt.getAttribute('data-value') || opt.textContent.trim();
                setVal(val);
                closeDropdown();
                trigger.focus();
            });
        }

        hiddenInput.setCustomValue = setVal;
    };

    initJobIndustryPicker(modal);

    const chkDisability = modal.querySelector('#chkDisability');
    const disabilityGroup = modal.querySelector('#disabilityExcludedGroup');

    const updateDisabilityGroupVisibility = () => {
        if (disabilityGroup) {
            disabilityGroup.style.display = (chkDisability && chkDisability.checked) ? 'flex' : 'none';
        }
    };

    if (chkDisability) {
        chkDisability.addEventListener('change', updateDisabilityGroupVisibility);
        updateDisabilityGroupVisibility();
    }

    const syncRichText = () => {
        modal.querySelectorAll('[data-rich-editor]').forEach((editor) => {
            const area = editor.querySelector('.rich-area');
            const input = editor.querySelector('textarea');
            if (area && input) {
                const text = (area.innerText || '').replace(/\u00a0/g, ' ').trim();
                input.value = text ? area.innerHTML.trim() : '';
            }
        });
    };

    const setJobCreateMode = (mode, adminNotes = '') => {
        modal.dataset.jobFormMode = mode;
        const actionInput = modal.querySelector('input[name="job_action"]');
        if (actionInput) {
            actionInput.value = (mode === 'revise') ? 'revise' : 'save';
        }
        if (mode === 'revise') {
            if (titleEl) {
                titleEl.textContent = 'Revisi Lowongan';
            }
            if (subtitleEl) {
                subtitleEl.textContent = 'Data sebelumnya sudah terisi. Perbarui bagian yang diminta admin, lalu kirim ulang.';
            }
            if (btnSubmit) {
                btnSubmit.textContent = 'Kirim Ulang Revisi';
            }
            if (banner && bannerText) {
                const notes = String(adminNotes || '').trim();
                banner.hidden = notes === '';
                bannerText.textContent = notes;
            }
            return;
        }

        if (mode === 'edit' || mode === 'draft') {
            if (titleEl) {
                titleEl.textContent = 'Edit Lowongan';
            }
            if (subtitleEl) {
                subtitleEl.textContent = 'Data sebelumnya sudah terisi. Perbarui informasi lowongan, lalu simpan.';
            }
            if (btnSubmit) {
                btnSubmit.textContent = 'Simpan Lowongan';
            }
            if (banner) {
                banner.hidden = true;
            }
            if (bannerText) {
                bannerText.textContent = '';
            }
            return;
        }

        if (titleEl) {
            titleEl.textContent = 'Tambah Lowongan';
        }
        if (subtitleEl) {
            subtitleEl.textContent = 'Lengkapi form berikut untuk mengisi lowongan';
        }
        if (btnSubmit) {
            btnSubmit.textContent = 'Tambah Loker';
        }
        if (banner) {
            banner.hidden = true;
        }
        if (bannerText) {
            bannerText.textContent = '';
        }
    };

    const setFieldValue = (name, value) => {
        const field = form?.querySelector(`[name="${name}"]`);
        if (!field) {
            return;
        }
        let valStr = value == null ? '' : String(value);
        if (name === 'expiry_days' && valStr) {
            const num = parseInt(valStr, 10);
            if (num === 1) valStr = '30';
            else if (num === 2) valStr = '60';
            else if (num === 3) valStr = '90';
            else if (!valStr) valStr = '30';
        }
        if (name === 'industry') {
            const indInput = form?.querySelector('#jobIndustryInput');
            if (indInput && typeof indInput.setCustomValue === 'function') {
                indInput.setCustomValue(valStr);
                return;
            }
        }
        if (field.tagName === 'SELECT' && valStr) {
            const exists = Array.from(field.options).some((opt) => opt.value === valStr);
            if (!exists) {
                const opt = new Option(valStr, valStr, true, true);
                field.add(opt);
            }
        }
        field.value = valStr;
    };

    const setCheckboxGroup = (name, selected) => {
        const values = new Set((selected || []).map((item) => String(item)));
        form?.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
            input.checked = values.has(input.value);
        });
    };

    const setCheckbox = (name, checked) => {
        const input = form?.querySelector(`input[name="${name}"]`);
        if (input) {
            input.checked = Boolean(checked);
        }
    };

    const setRadio = (name, value) => {
        const valStr = value == null ? '' : String(value);
        const radio = form?.querySelector(`input[name="${name}"][value="${valStr}"]`);
        if (radio) {
            radio.checked = true;
        }
    };

    const setRichField = (name, html) => {
        const input = form?.querySelector(`textarea[name="${name}"]`);
        if (!input) {
            return;
        }
        input.value = html || '';
        const area = input.closest('[data-rich-editor]')?.querySelector('.rich-area');
        if (area) {
            area.innerHTML = html || '';
        }
    };

    const setChips = (key, items) => {
        const hidden = form?.querySelector(`[data-chip-value="${key}"]`);
        if (!hidden) {
            return;
        }
        hidden.value = (items || []).map((item) => String(item).trim()).filter(Boolean).join(',');
        if (typeof hidden.refreshChips === 'function') {
            hidden.refreshChips();
        }
    };

    const fillJobCreateForm = (data) => {
        if (!form || !data) {
            return;
        }

        setFieldValue('job_title', data.title);
        setRichField('job_description', data.description);
        setFieldValue('kbji_code', data.kbji_code);
        setFieldValue('job_location', data.location);
        setFieldValue('job_type', data.job_type);
        setFieldValue('job_field', data.job_field);
        setFieldValue('industry', data.industry);
        setCheckboxGroup('physical_condition[]', data.physical_conditions);
        setCheckboxGroup('gender[]', data.genders);
        setFieldValue('disability_excluded', data.disability_excluded);
        setFieldValue('salary_min', data.salary_min ? new Intl.NumberFormat('id-ID').format(data.salary_min) : '');
        setFieldValue('salary_max', data.salary_max ? new Intl.NumberFormat('id-ID').format(data.salary_max) : '');
        setCheckbox('show_salary', data.show_salary);
        setCheckbox('is_remote', data.is_remote);
        setRadio('is_limited', data.is_limited ? '1' : '0');
        setFieldValue('expiry_days', data.expiry_days);
        setFieldValue('quota', data.quota || 1);
        setFieldValue('education_required', data.education_required);
        setFieldValue('experience_required', data.experience_required);
        setCheckboxGroup('marital_status[]', data.marital_statuses);
        setFieldValue('age_min', data.age_min);
        setFieldValue('age_max', data.age_max);
        setRichField('special_requirements', data.special_requirements);
        setChips('skills', data.skills);
        if (Array.isArray(data.contacts) && data.contacts.length) {
            setChips('contacts', data.contacts);
        }
        syncRichText();
        const jobLocInput = form?.querySelector('#jobLocationInput');
        if (jobLocInput && typeof jobLocInput.updateDisplay === 'function') {
            jobLocInput.updateDisplay();
        }
    };

    const setStep = (next) => {
        current = next;
        steps.forEach((step) => {
            step.hidden = Number(step.dataset.jobStep) !== current;
        });
        labels.forEach((label) => {
            const index = Number(label.dataset.stepLabel);
            label.classList.toggle('active', index === current);
            label.classList.toggle('done', index < current);
            const bubble = label.querySelector('.step-badge');
            if (bubble) {
                bubble.innerHTML = index < current ? '<i class="fa-solid fa-check"></i>' : String(index);
            }
        });
        lines.forEach((line) => {
            line.classList.toggle('done', Number(line.dataset.stepLine) < current);
        });
        if (btnCancel) {
            btnCancel.hidden = current !== 1;
        }
        if (btnBack) {
            btnBack.hidden = current === 1;
        }
        if (btnNext) {
            btnNext.hidden = current === 3;
        }
        if (btnSubmit) {
            btnSubmit.hidden = current !== 3;
        }
        modal.querySelector('.modal-body')?.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const validateStep = (stepNumber) => {
        syncRichText();
        const panel = modal.querySelector(`[data-job-step="${stepNumber}"]`);
        if (!panel) {
            return true;
        }

        if (stepNumber === 1) {
            const jobLocInput = modal.querySelector('#jobLocationInput');
            const locTrigger = modal.querySelector('#jobLocationTrigger');
            if (jobLocInput && !jobLocInput.value.trim()) {
                locTrigger?.focus();
                locTrigger?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
            const jobIndustryInput = modal.querySelector('#jobIndustryInput');
            const indTrigger = modal.querySelector('#jobIndustryTrigger');
            if (jobIndustryInput && !jobIndustryInput.value.trim()) {
                indTrigger?.focus();
                indTrigger?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                indTrigger?.classList.add('has-error');
                return false;
            }
        }

        const fields = panel.querySelectorAll('input, select, textarea');
        for (const field of fields) {
            if (field.disabled || field.type === 'checkbox') {
                continue;
            }
            field.setCustomValidity('');
            if (!field.checkValidity()) {
                field.reportValidity();
                return false;
            }
        }

        for (const group of panel.querySelectorAll('[data-required-group]')) {
            if (!group.querySelector('input:checked')) {
                const first = group.querySelector('input');
                first.setCustomValidity(group.dataset.requiredGroup || 'Pilih minimal satu opsi.');
                first.reportValidity();
                first.setCustomValidity('');
                return false;
            }
        }

        const salaryMin = panel.querySelector('[name="salary_min"]');
        const salaryMax = panel.querySelector('[name="salary_max"]');
        if (salaryMin && salaryMax && salaryMin.value && salaryMax.value && Number(salaryMax.value.replace(/\D/g, '')) < Number(salaryMin.value.replace(/\D/g, ''))) {
            salaryMax.setCustomValidity('Gaji maksimal harus lebih besar atau sama dengan gaji minimal.');
            salaryMax.reportValidity();
            salaryMax.setCustomValidity('');
            return false;
        }

        const ageMin = panel.querySelector('[name="age_min"]');
        const ageMax = panel.querySelector('[name="age_max"]');
        if (ageMin && ageMax && ageMin.value && ageMax.value && Number(ageMax.value) < Number(ageMin.value)) {
            ageMax.setCustomValidity('Usia maksimal harus lebih besar atau sama dengan usia minimal.');
            ageMax.reportValidity();
            ageMax.setCustomValidity('');
            return false;
        }

        return true;
    };

    btnNext?.addEventListener('click', () => {
        if (validateStep(current)) {
            setStep(current + 1);
        }
    });

    btnBack?.addEventListener('click', () => {
        setStep(Math.max(1, current - 1));
    });

    form?.addEventListener('submit', (event) => {
        syncRichText();
        if (!validateStep(1)) {
            event.preventDefault();
            setStep(1);
            return;
        }
        if (!validateStep(2)) {
            event.preventDefault();
            setStep(2);
            return;
        }
        if (!validateStep(3)) {
            event.preventDefault();
            setStep(3);
        }
    });

    modal.addEventListener('modal:open', () => {
        if (modal.dataset.skipReset === 'true') {
            modal.dataset.skipReset = 'false';
            setStep(1);
            return;
        }

        modal.dataset.jobFormMode = 'create';
        form?.reset();
        const reviseId = document.getElementById('reviseJobId');
        if (reviseId) {
            reviseId.value = '';
        }
        modal.querySelectorAll('.rich-area').forEach((area) => {
            area.innerHTML = '';
        });
        syncRichText();
        modal.querySelectorAll('[data-chip-value]').forEach((field) => {
            if (typeof field.refreshChips === 'function') {
                field.refreshChips();
            }
        });
        setJobCreateMode('create');
        const jobLocInput = form?.querySelector('#jobLocationInput');
        if (jobLocInput && typeof jobLocInput.updateDisplay === 'function') {
            jobLocInput.updateDisplay();
        }
        const jobIndInput = form?.querySelector('#jobIndustryInput');
        if (jobIndInput && typeof jobIndInput.setCustomValue === 'function') {
            jobIndInput.setCustomValue('');
        }
        setStep(1);
    });

    window.openEditJobModal = (jobId, mode = 'edit') => {
        if (!jobId || String(jobId) === '0') {
            return;
        }
        const hidden = document.getElementById('reviseJobId');
        if (hidden) {
            hidden.value = String(jobId);
        }
        modal.dataset.skipReset = 'true';
        modal.style.display = 'flex';
        modal.classList.add('open');
        modal.dispatchEvent(new CustomEvent('modal:open'));

        fetch(`dashboard.php?job_json=${encodeURIComponent(jobId)}`)
            .then((response) => {
                if (!response.ok) {
                    throw new Error('not found');
                }
                return response.json();
            })
            .then((payload) => {
                if (!payload?.ok || !payload.data) {
                    throw new Error('invalid');
                }
                const isDraftMode = mode === 'edit' || payload.data.status === 'Draft';
                const formMode = isDraftMode ? 'edit' : 'revise';
                modal.dataset.jobFormMode = formMode;
                modal.dataset.skipReset = 'true';
                if (hidden) {
                    hidden.value = String(payload.data.id || jobId);
                }
                fillJobCreateForm(payload.data);
                setJobCreateMode(formMode, payload.data.admin_notes);
                modal.style.display = 'flex';
                modal.classList.add('open');
                modal.dispatchEvent(new CustomEvent('modal:open'));
            })
            .catch((err) => {
                console.error('Failed to load job data:', err);
                window.alert('Data lowongan tidak dapat dimuat. Silakan coba lagi.');
            });
    };

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-revise-job], [data-edit-draft], [data-edit-job]');
        if (!button) {
            return;
        }
        const jobId = button.dataset.reviseJob || button.dataset.editDraft || button.dataset.editJob;
        if (!jobId || String(jobId) === '0') {
            return;
        }
        const mode = button.dataset.reviseJob ? 'revise' : 'edit';
        window.openEditJobModal(jobId, mode);
    });

    setStep(1);
}

function initHashRouting(defaultPage = 'dashboard') {
    const dataPages = document.querySelectorAll('.page[data-page], .page[id^="page-"]');
    if (dataPages.length === 0) {
        return;
    }
    const initialPage = (window.location.hash || `#${defaultPage}`).slice(1);
    setActivePage(initialPage || defaultPage);

    window.addEventListener('hashchange', () => {
        const nextPage = (window.location.hash || `#${defaultPage}`).slice(1);
        setActivePage(nextPage || defaultPage);
    });
}

function normalizeDashboardPageContainer() {
    const content = document.querySelector('.app-layout-wrapper .main > .content');
    if (!content) {
        return;
    }

    // Invalid table/modal nesting can make the browser foster-parent later
    // sections outside .content. Put every routed page back in the one
    // scroll container before hash routing runs.
    document.querySelectorAll('.app-layout-wrapper .main > .page[data-page]').forEach((page) => {
        content.appendChild(page);
    });
}

function initSidebarToggle() {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.getElementById('sidebarToggle');
    if (sidebar && toggle && toggle.dataset.bound !== 'true') {
        toggle.dataset.bound = 'true';
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });
    }

    const railToggleBtn = document.getElementById('railToggleBtn');
    if (railToggleBtn && railToggleBtn.dataset.bound !== 'true') {
        railToggleBtn.dataset.bound = 'true';
        railToggleBtn.addEventListener('click', toggleDrawer);
    }

    const drawerCloseBtn = document.getElementById('drawerCloseBtn');
    if (drawerCloseBtn && drawerCloseBtn.dataset.bound !== 'true') {
        drawerCloseBtn.dataset.bound = 'true';
        drawerCloseBtn.addEventListener('click', closeDrawer);
    }

    const drawerBackdrop = document.getElementById('drawerBackdrop');
    if (drawerBackdrop && drawerBackdrop.dataset.bound !== 'true') {
        drawerBackdrop.dataset.bound = 'true';
        drawerBackdrop.addEventListener('click', closeDrawer);
    }
}

function openDrawer(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const drawer = document.getElementById('navDrawer');
    const backdrop = document.getElementById('drawerBackdrop');
    if (drawer) drawer.classList.add('open');
    if (backdrop) backdrop.classList.add('open');
}

function closeDrawer(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const drawer = document.getElementById('navDrawer');
    const backdrop = document.getElementById('drawerBackdrop');
    if (drawer) drawer.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
}

function toggleDrawer(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const drawer = document.getElementById('navDrawer');
    if (drawer && drawer.classList.contains('open')) {
        closeDrawer(e);
    } else {
        openDrawer(e);
    }
}

window.openDrawer = openDrawer;
window.closeDrawer = closeDrawer;
window.toggleDrawer = toggleDrawer;
window.openNavDrawer = openDrawer;
window.closeNavDrawer = closeDrawer;
window.toggleNavDrawer = toggleDrawer;

function bindAdminReviewForm(form) {
    if (!form || form.dataset.bound === 'true') {
        return;
    }
    form.dataset.bound = 'true';

    const notes = form.querySelector('[name="admin_notes"]');
    const reasonInput = form.querySelector('[data-note-reason]');
    const dropdown = form.querySelector('[data-reason-dropdown]');
    const trigger = dropdown?.querySelector('[data-reason-trigger]');
    const menu = dropdown?.querySelector('[data-reason-menu]');
    const label = dropdown?.querySelector('[data-reason-label]');
    const defaultApprove = form.dataset.defaultApprove || 'Lowongan telah memenuhi syarat dan disetujui untuk ditayangkan';
    const standardReasons = [...(dropdown?.querySelectorAll('[data-reason-option]') || [])]
        .map((option) => (option.dataset.reasonOption || option.textContent || '').trim())
        .filter(Boolean);

    const closeMenu = () => {
        if (!dropdown || !menu) {
            return;
        }
        dropdown.classList.remove('open');
        menu.hidden = true;
    };

    const insertReason = (reason) => {
        if (!notes || !reason) {
            return;
        }
        const current = notes.value.trim();
        if (!current || current === defaultApprove || standardReasons.includes(current)) {
            notes.value = reason;
        } else if (!current.includes(reason)) {
            notes.value = `${current}\n${reason}`;
        }
        if (reasonInput) {
            reasonInput.value = reason;
        }
        if (label) {
            label.textContent = reason;
        }
        dropdown?.querySelectorAll('[data-reason-option]').forEach((option) => {
            option.classList.toggle('is-selected', (option.dataset.reasonOption || '').trim() === reason);
        });
        notes.dispatchEvent(new Event('input', { bubbles: true }));
    };

    trigger?.addEventListener('click', (event) => {
        event.preventDefault();
        if (!dropdown || !menu) {
            return;
        }
        const willOpen = menu.hidden;
        document.querySelectorAll('[data-reason-menu]').forEach((other) => {
            other.hidden = true;
            other.closest('[data-reason-dropdown]')?.classList.remove('open');
        });
        menu.hidden = !willOpen;
        dropdown.classList.toggle('open', willOpen);
    });

    dropdown?.querySelectorAll('[data-reason-option]').forEach((option) => {
        option.addEventListener('click', () => {
            insertReason((option.dataset.reasonOption || option.textContent || '').trim());
            closeMenu();
        });
    });

    form.querySelectorAll('button[name="decision"]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!notes) {
                return;
            }
            if (button.value === 'approve') {
                notes.value = defaultApprove;
                return;
            }

            const selectedReason = (reasonInput?.value || '').trim();
            if (selectedReason) {
                insertReason(selectedReason);
            } else if (notes.value.trim() === defaultApprove) {
                notes.value = '';
            }
        });
    });
}

function initAdminJobReview() {
    document.querySelectorAll('[data-review-form]').forEach(bindAdminReviewForm);
}

function initJobReviewDrawer() {
    const drawer = document.querySelector('[data-job-review-drawer]');
    const body = document.querySelector('[data-job-review-body]');
    const title = document.getElementById('jobReviewTitle');
    if (!drawer || !body) {
        return;
    }

    const close = () => {
        drawer.hidden = true;
        document.body.style.overflow = '';
        body.replaceChildren();
    };

    const open = (jobId) => {
        const template = document.querySelector(`[data-job-review-template="${jobId}"]`);
        if (!template) {
            return;
        }
        const content = template.content.cloneNode(true);
        body.replaceChildren(content);
        const heading = body.querySelector('.job-review-summary h3');
        if (title && heading) {
            title.textContent = heading.textContent || 'Detail lengkap';
        }
        body.querySelectorAll('[data-review-form]').forEach(bindAdminReviewForm);
        drawer.hidden = false;
        document.body.style.overflow = 'hidden';
        body.scrollTop = 0;
    };

    document.querySelectorAll('[data-open-job-review]').forEach((button) => {
        button.addEventListener('click', () => {
            open(button.dataset.openJobReview);
        });
    });

    drawer.querySelectorAll('[data-close-job-review]').forEach((button) => {
        button.addEventListener('click', close);
    });

    drawer.addEventListener('click', (event) => {
        if (event.target === drawer) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !drawer.hidden) {
            close();
        }
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('[data-reason-dropdown]').forEach((dropdown) => {
            if (!dropdown.contains(event.target)) {
                dropdown.classList.remove('open');
                const menu = dropdown.querySelector('[data-reason-menu]');
                if (menu) {
                    menu.hidden = true;
                }
            }
        });
    });
}

function initSeekerJobFilters() {
    const extra = [...document.querySelectorAll('[data-extra-location]')];
    const toggle = document.querySelector('[data-toggle-locations]');
    if (toggle && extra.length) {
        toggle.addEventListener('click', () => {
            const hidden = extra[0].hidden;
            extra.forEach((item) => {
                item.hidden = !hidden;
            });
            toggle.textContent = hidden ? 'Lihat lebih sedikit' : 'Lihat lebih banyak';
        });
    }

    const search = document.querySelector('[data-filter-location]');
    search?.addEventListener('input', () => {
        const query = search.value.trim().toLowerCase();
        document.querySelectorAll('[data-location-options] .filter-check').forEach((label) => {
            const match = label.textContent.toLowerCase().includes(query);
            label.style.display = match || query === '' ? '' : 'none';
            if (query !== '' && match) {
                label.hidden = false;
            }
        });
    });
}

function initNotifications() {
    const wraps = document.querySelectorAll('.notif-wrap');
    if (!wraps.length) {
        return;
    }
    wraps.forEach((wrap) => {
        const button = wrap.querySelector('[data-notif-toggle], .notif');
        const panel = wrap.querySelector('.notif-panel');
        if (!button || !panel) {
            return;
        }
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const isCurrentlyHidden = panel.hidden;
            // Close other open panels first
            document.querySelectorAll('.notif-panel').forEach(p => { if (p !== panel) p.hidden = true; });
            panel.hidden = !isCurrentlyHidden;
            if (!panel.hidden) {
                fetch('notif-read.php').catch(() => {});
                button.classList.remove('has-unread');
            }
        });
        panel.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    });

    document.addEventListener('click', () => {
        document.querySelectorAll('.notif-panel').forEach(p => p.hidden = true);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.notif-panel').forEach(p => p.hidden = true);
        }
    });
}

function initPengajuanVerifikasiChart() {
    const wrap = document.getElementById('chartCanvasWrap');
    const svg = document.getElementById('pvChartSvg');
    const tooltip = document.getElementById('pvChartTooltip');
    const hoverLine = document.getElementById('pvHoverLine');
    const hoverDot = document.getElementById('pvHoverDot');
    if (!wrap || !svg || !tooltip) {
        return;
    }

    const monthlyData = [
        { month: 'Apr', x: 46, dotY: 167, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 0, Ditangguhkan: 0, Ditutup: 2, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'May', x: 154, dotY: 188, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 0, Ditangguhkan: 0, Ditutup: 0, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'Jun', x: 262, dotY: 178, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 0, Ditangguhkan: 0, Ditutup: 1, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'Jul', x: 370, dotY: 178, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 0, Ditangguhkan: 0, Ditutup: 1, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'Aug', x: 478, dotY: 114, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 1, Ditangguhkan: 0, Ditutup: 5, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'Sep', x: 586, dotY: 20, Draft: 0, Menunggu: 1, Revisi: 2, Ditolak: 1, Terjadwal: 0, Tayang: 11, Ditangguhkan: 0, Ditutup: 139, Kedaluwarsa: 7, Diblokir: 3 }
    ];

    const xTicks = svg.querySelectorAll('.pv-x-tick');

    function showTooltipForIndex(index) {
        if (index < 0 || index >= monthlyData.length) return;
        const d = monthlyData[index];

        document.getElementById('pvTtMonth').textContent = d.month;
        document.getElementById('pvTtDraft').textContent = d.Draft;
        document.getElementById('pvTtMenunggu').textContent = d.Menunggu;
        document.getElementById('pvTtRevisi').textContent = d.Revisi;
        document.getElementById('pvTtDitolak').textContent = d.Ditolak;
        document.getElementById('pvTtTerjadwal').textContent = d.Terjadwal;
        document.getElementById('pvTtTayang').textContent = d.Tayang;
        document.getElementById('pvTtDitangguhkan').textContent = d.Ditangguhkan;
        document.getElementById('pvTtDitutup').textContent = d.Ditutup;
        document.getElementById('pvTtKedaluwarsa').textContent = d.Kedaluwarsa;
        document.getElementById('pvTtDiblokir').textContent = d.Diblokir;

        // Position guideline & dot
        if (hoverLine) {
            hoverLine.setAttribute('x1', d.x);
            hoverLine.setAttribute('x2', d.x);
            hoverLine.setAttribute('opacity', '1');
        }
        if (hoverDot) {
            hoverDot.setAttribute('cx', d.x);
            hoverDot.setAttribute('cy', d.dotY);
            hoverDot.setAttribute('opacity', '1');
        }

        // Highlight x-axis tick
        xTicks.forEach((tick, i) => {
            if (i === index) {
                tick.classList.add('active');
                tick.setAttribute('font-weight', '700');
            } else {
                tick.classList.remove('active');
                tick.removeAttribute('font-weight');
            }
        });

        // Position tooltip relative to wrap
        const wrapRect = wrap.getBoundingClientRect();
        let posX = (d.x / 620) * wrapRect.width;
        let posY = 10;

        if (posX > wrapRect.width - 200) {
            tooltip.style.left = 'auto';
            tooltip.style.right = Math.max(10, wrapRect.width - posX + 15) + 'px';
        } else {
            tooltip.style.left = Math.max(10, posX + 15) + 'px';
            tooltip.style.right = 'auto';
        }
        tooltip.style.top = posY + 'px';
        tooltip.style.display = 'block';
    }

    function hideTooltip() {
        tooltip.style.display = 'none';
        if (hoverLine) hoverLine.setAttribute('opacity', '0');
        if (hoverDot) hoverDot.setAttribute('opacity', '0');
        xTicks.forEach(tick => {
            tick.classList.remove('active');
            tick.removeAttribute('font-weight');
        });
    }

    wrap.addEventListener('mousemove', (e) => {
        const wrapRect = wrap.getBoundingClientRect();
        const relX = (e.clientX - wrapRect.left) / wrapRect.width;
        const svgX = relX * 620;

        let closestIndex = 0;
        let minDiff = Infinity;
        monthlyData.forEach((d, idx) => {
            const diff = Math.abs(d.x - svgX);
            if (diff < minDiff) {
                minDiff = diff;
                closestIndex = idx;
            }
        });

        showTooltipForIndex(closestIndex);
    });

    wrap.addEventListener('mouseleave', () => {
        hideTooltip();
    });

    xTicks.forEach((tick, idx) => {
        tick.addEventListener('mouseenter', () => {
            showTooltipForIndex(idx);
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    normalizeDashboardPageContainer();
    bindPageSwitchers();
    bindModalsAndDrawers();
    if (typeof bindCascadingLocation === 'function') {
        bindCascadingLocation();
    }

    const defaultPage = document.body.dataset.defaultPage || 'dashboard';
    initHashRouting(defaultPage);
    initJobCreateWizard();
    initAdminJobReview();
    initJobReviewDrawer();
    initSeekerJobFilters();
    initSidebarToggle();
    initPopovers();
    initNotifications();
    initPengajuanVerifikasiChart();
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[char]));

    function fillList(target, items, mapper) {
        if (!target) {
            return;
        }
        if (!items || !items.length) {
            target.innerHTML = '<div class="tiny">Belum ada data.</div>';
            return;
        }
        target.innerHTML = items.map(mapper).join('');
    }

    function switchApplicantModalTab(tab) {
        const profilPane = document.getElementById('tabApplicantProfilPane');
        const aktivitasPane = document.getElementById('tabApplicantAktivitasPane');
        const profilBtn = document.getElementById('tabApplicantProfilBtn');
        const aktivitasBtn = document.getElementById('tabApplicantAktivitasBtn');
        
        if (profilPane && aktivitasPane && profilBtn && aktivitasBtn) {
            profilPane.style.display = (tab === 'profil') ? 'block' : 'none';
            aktivitasPane.style.display = (tab === 'aktivitas') ? 'block' : 'none';
            
            profilBtn.style.borderBottomColor = (tab === 'profil') ? '#0ea5e9' : 'transparent';
            profilBtn.style.color = (tab === 'profil') ? '#0ea5e9' : '#64748b';
            profilBtn.style.fontWeight = (tab === 'profil') ? '700' : '600';
            
            aktivitasBtn.style.borderBottomColor = (tab === 'aktivitas') ? '#0ea5e9' : 'transparent';
            aktivitasBtn.style.color = (tab === 'aktivitas') ? '#0ea5e9' : '#64748b';
            aktivitasBtn.style.fontWeight = (tab === 'aktivitas') ? '700' : '600';
        }
    }
    window.switchApplicantModalTab = switchApplicantModalTab;

    // Status Color Map
    const statusColorMap = {
        'Lamaran Masuk': '#f59e0b',
        'Sedang Dipelajari': '#f97316',
        'Wawancara': '#3b82f6',
        'Diterima': '#10b981',
        'Ditolak': '#ef4444'
    };

    function showToastNotification(message, type = 'success') {
        let toastContainer = document.getElementById('paskerToastContainer');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'paskerToastContainer';
            toastContainer.style.cssText = 'position:fixed; bottom:24px; right:24px; z-index:999999; display:flex; flex-direction:column; gap:10px; pointer-events:none;';
            document.body.appendChild(toastContainer);
        }
        const toast = document.createElement('div');
        toast.style.cssText = 'background:#0f172a; color:#fff; padding:12px 18px; border-radius:10px; font-size:13px; font-weight:600; box-shadow:0 10px 25px -5px rgba(0,0,0,0.2); display:flex; align-items:center; gap:10px; pointer-events:auto; transition:all 0.3s cubic-bezier(0.16,1,0.3,1); transform:translateY(20px); opacity:0;';
        const iconHtml = (type === 'success') 
            ? '<i class="fa-solid fa-circle-check" style="color:#10b981; font-size:16px;"></i>' 
            : '<i class="fa-solid fa-circle-exclamation" style="color:#f59e0b; font-size:16px;"></i>';
        toast.innerHTML = `${iconHtml} <span>${escapeHtml(message)}</span>`;
        toastContainer.appendChild(toast);
        
        requestAnimationFrame(() => {
            toast.style.transform = 'translateY(0)';
            toast.style.opacity = '1';
        });

        setTimeout(() => {
            toast.style.transform = 'translateY(20px)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3200);
    }
    window.showToastNotification = showToastNotification;

    function toggleCustomStatusDropdown(e) {
        if (e) e.stopPropagation();
        const menu = document.getElementById('customStatusDropdownMenu');
        if (!menu) return;
        const isShowing = menu.style.display === 'block';
        menu.style.display = isShowing ? 'none' : 'block';
    }
    window.toggleCustomStatusDropdown = toggleCustomStatusDropdown;

    function selectApplicantStatus(statusName, dotColor, skipAjax = false) {
        const elStatus = document.getElementById('applicantStatus');
        const dot = document.getElementById('customStatusDot');
        const label = document.getElementById('customStatusLabel');
        const menu = document.getElementById('customStatusDropdownMenu');
        const appId = document.getElementById('applicantId')?.value;
        const col = dotColor || statusColorMap[statusName] || '#f59e0b';

        if (elStatus) elStatus.value = statusName;
        if (label) label.textContent = statusName;
        if (dot) dot.style.background = col;

        document.querySelectorAll('.status-check-icon').forEach(icon => {
            icon.style.display = (icon.getAttribute('data-status') === statusName) ? 'block' : 'none';
        });

        const badge = document.getElementById('applicantStatusBadge');
        if (badge) {
            badge.textContent = statusName;
            if (statusName === 'Diterima') {
                badge.style.background = '#ecfdf5'; badge.style.color = '#059669'; badge.style.borderColor = '#a7f3d0';
            } else if (statusName === 'Ditolak') {
                badge.style.background = '#fef2f2'; badge.style.color = '#dc2626'; badge.style.borderColor = '#fecaca';
            } else if (statusName === 'Wawancara') {
                badge.style.background = '#eff6ff'; badge.style.color = '#2563eb'; badge.style.borderColor = '#bfdbfe';
            } else if (statusName === 'Sedang Dipelajari') {
                badge.style.background = '#fff7ed'; badge.style.color = '#ea580c'; badge.style.borderColor = '#fed7aa';
            } else {
                badge.style.background = '#fffbeb'; badge.style.color = '#d97706'; badge.style.borderColor = '#fde68a';
            }
        }

        if (menu) menu.style.display = 'none';

        if (!skipAjax && appId) {
            const formData = new FormData();
            formData.append('update_application_status', '1');
            formData.append('application_id', appId);
            formData.append('status', statusName);
            formData.append('ajax', '1');
            fetch('dashboard.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res && res.ok) {
                        moveCardToKanbanStage(appId, statusName);
                        if (typeof res.accepted_count !== 'undefined') {
                            updateJobQuotaDisplay(res.accepted_count, res.quota);
                        }
                        showToastNotification(`Status berhasil diubah ke "${statusName}"`);
                    }
                })
                .catch(() => {});
        }
    }
    window.selectApplicantStatus = selectApplicantStatus;

    function moveCardToKanbanStage(appId, targetStage) {
        const card = document.querySelector(`.kanban-card[data-app-id="${appId}"]`) || document.querySelector(`.app-card-item[data-app-id="${appId}"]`);
        if (card) {
            card.setAttribute('data-stage', targetStage);
            const targetZone = document.querySelector(`.kanban-drop-zone[data-stage="${targetStage}"]`);
            if (targetZone) {
                const emptyPlaceholder = targetZone.querySelector('.kanban-empty-placeholder');
                if (emptyPlaceholder) emptyPlaceholder.remove();
                targetZone.appendChild(card);
            }
        }

        // Keep status labels and classes in sync across Grid and List views
        const statusClassMap = {
            'Lamaran Masuk': 'in',
            'Sedang Dipelajari': 'review',
            'Wawancara': 'interview',
            'Diterima': 'hired',
            'Ditolak': 'rejected'
        };
        document.querySelectorAll(`.app-card-item[data-app-id="${appId}"] .tag`).forEach(tag => {
            tag.className = 'tag ' + (statusClassMap[targetStage] || 'in');
            tag.textContent = targetStage;
        });

        updateKanbanBadges();
    }
    window.moveCardToKanbanStage = moveCardToKanbanStage;

    function updateKanbanBadges() {
        document.querySelectorAll('.kanban-stage-col').forEach(col => {
            const stage = col.getAttribute('data-stage');
            const zone = col.querySelector('.kanban-drop-zone');
            if (!zone) return;
            const cards = zone.querySelectorAll('.kanban-card, .app-card-item');
            const badge = col.querySelector('.kanban-stage-badge');
            if (badge) badge.textContent = cards.length;
            
            if (cards.length === 0 && !zone.querySelector('.kanban-empty-placeholder')) {
                zone.innerHTML = '<div class="kanban-empty-placeholder" style="display:flex; align-items:center; justify-content:center; height:180px; color:#94a3b8; font-size:12.5px;">Tidak ada data.</div>';
            }
        });
    }
    window.updateKanbanBadges = updateKanbanBadges;

    // Drag and drop handlers
    window.isDraggingCard = false;

    function handleKanbanDragStart(e, el) {
        window.isDraggingCard = true;
        e.dataTransfer.setData('text/plain', el.getAttribute('data-app-id'));
        e.dataTransfer.setData('source-stage', el.getAttribute('data-stage'));
        el.classList.add('is-dragging');
        el.style.opacity = '0.4';
    }
    window.handleKanbanDragStart = handleKanbanDragStart;

    function handleKanbanDragEnd(e, el) {
        el.classList.remove('is-dragging');
        el.style.opacity = '1';
        setTimeout(() => { window.isDraggingCard = false; }, 150);
    }
    window.handleKanbanDragEnd = handleKanbanDragEnd;

    function handleKanbanDragOver(e) {
        e.preventDefault();
        const dropZone = e.currentTarget;
        dropZone.style.backgroundColor = '#ecfdf5';
        dropZone.style.borderColor = '#10b981';
    }
    window.handleKanbanDragOver = handleKanbanDragOver;

    function handleKanbanDragLeave(e) {
        const dropZone = e.currentTarget;
        dropZone.style.backgroundColor = '#f8fafc';
        dropZone.style.borderColor = '#e2e8f0';
    }
    window.handleKanbanDragLeave = handleKanbanDragLeave;

    function handleKanbanDrop(e, targetStage) {
        e.preventDefault();
        const dropZone = e.currentTarget;
        dropZone.style.backgroundColor = '#f8fafc';
        dropZone.style.borderColor = '#e2e8f0';

        const appId = e.dataTransfer.getData('text/plain');
        const sourceStage = e.dataTransfer.getData('source-stage');
        if (!appId || sourceStage === targetStage) return;

        moveCardToKanbanStage(appId, targetStage);

        const formData = new FormData();
        formData.append('update_application_status', '1');
        formData.append('application_id', appId);
        formData.append('status', targetStage);
        formData.append('ajax', '1');
        fetch('dashboard.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res && res.ok) {
                    if (typeof res.accepted_count !== 'undefined') {
                        updateJobQuotaDisplay(res.accepted_count, res.quota);
                    }
                    showToastNotification(`Status pelamar dipindahkan ke "${targetStage}"`);
                }
            })
            .catch(() => {});
    }
    window.handleKanbanDrop = handleKanbanDrop;

    function handleKanbanCardClick(e, appId) {
        if (window.isDraggingCard) return;
        openApplicantProfile(appId);
    }
    window.handleKanbanCardClick = handleKanbanCardClick;

    // Tag Modal Handlers
    function openApplicantTagModal() {
        const modal = document.getElementById('applicantTagModal');
        if (modal) modal.style.display = 'flex';
    }
    window.openApplicantTagModal = openApplicantTagModal;

    function closeApplicantTagModal() {
        const modal = document.getElementById('applicantTagModal');
        if (modal) modal.style.display = 'none';
    }
    window.closeApplicantTagModal = closeApplicantTagModal;

    function applyQuickTag(tagName) {
        const input = document.getElementById('customTagInput');
        if (input) input.value = tagName;
    }
    window.applyQuickTag = applyQuickTag;

    function saveApplicantTag() {
        const input = document.getElementById('customTagInput');
        const tagVal = (input ? input.value : '').trim();
        const appId = document.getElementById('applicantId')?.value;
        const tagDisplay = document.getElementById('applicantTag');
        if (tagDisplay) tagDisplay.textContent = tagVal || 'Belum ditandai';

        if (appId) {
            const card = document.querySelector(`.app-card-item[data-app-id="${appId}"]`);
            if (card) {
                const tagBadgeContainer = card.querySelector('.card-tag-badge');
                if (tagBadgeContainer) {
                    if (tagVal) {
                        tagBadgeContainer.style.display = 'block';
                        tagBadgeContainer.querySelector('span').textContent = tagVal;
                    } else {
                        tagBadgeContainer.style.display = 'none';
                    }
                }
            }
        }
        closeApplicantTagModal();
        showToastNotification('Tag pelamar berhasil disimpan');
    }
    window.saveApplicantTag = saveApplicantTag;

    // Interview Modal Handlers
    function openApplicantInterviewModal() {
        const modal = document.getElementById('applicantInterviewModal');
        const dateInput = document.getElementById('interviewDate');
        if (dateInput && !dateInput.value) {
            const tmr = new Date();
            tmr.setDate(tmr.getDate() + 1);
            dateInput.value = tmr.toISOString().split('T')[0];
        }
        if (modal) modal.style.display = 'flex';
    }
    window.openApplicantInterviewModal = openApplicantInterviewModal;

    function closeApplicantInterviewModal() {
        const modal = document.getElementById('applicantInterviewModal');
        if (modal) modal.style.display = 'none';
    }
    window.closeApplicantInterviewModal = closeApplicantInterviewModal;

    function saveApplicantInterview() {
        const date = document.getElementById('interviewDate')?.value;
        const time = document.getElementById('interviewTime')?.value;

        // Change status to Wawancara
        selectApplicantStatus('Wawancara', '#3b82f6');
        closeApplicantInterviewModal();
        showToastNotification(`Wawancara dijadwalkan pada ${date || 'besok'} pukul ${time || '09:00'} WIB`);
    }
    window.saveApplicantInterview = saveApplicantInterview;

    document.addEventListener('click', (e) => {
        const container = document.getElementById('customStatusDropdownContainer');
        const menu = document.getElementById('customStatusDropdownMenu');
        if (menu && container && !container.contains(e.target)) {
            menu.style.display = 'none';
        }
    });

    function openApplicantProfile(applicationId) {
        const modal = document.querySelector('[data-modal="applicant-profile"]');
        if (!modal) {
            return;
        }
        fetch('dashboard.php?applicant_json=' + encodeURIComponent(applicationId))
            .then((response) => response.json())
            .then((payload) => {
                if (!payload.ok) {
                    return;
                }
                const data = payload.data;
                const setElemText = (id, val, def) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = val || def || '-';
                };

                const elId = document.getElementById('applicantId');
                if (elId) elId.value = data.id;

                setElemText('applicantName', data.seeker_name, 'Muhamad Amar Basyari');
                setElemText('applicantAppliedDate', data.applied_date, '20 Sep 2026');
                setElemText('applicantActivityDate', data.applied_date, '20 Sep 2026');
                
                const curStatus = data.status || 'Lamaran Masuk';
                selectApplicantStatus(curStatus, statusColorMap[curStatus], true);

                const avatarEl = document.getElementById('applicantAvatar');
                if (avatarEl) {
                    avatarEl.src = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(data.seeker_name || 'Pencari Kerja') + '&background=0ea5e9&color=fff&size=128';
                }

                setElemText('applicantLocation', data.location, 'Karangmaja, Banjarharjo, KAB. BREBES, JAWA TENGAH');
                setElemText('applicantTag', data.tag, 'Belum ditandai');
                setElemText('applicantAbout', data.about, 'Saya adalah pribadi yang disiplin, bertanggung jawab, cepat belajar, dan mampu beradaptasi dengan lingkungan kerja baru serta bekerja secara individu maupun dalam tim.');
                setElemText('applicantBirthInfo', data.birth_info, 'Brebes, 19 Desember 2006');
                setElemText('applicantKtpAddress', data.ktp_address, 'Karangmaja, Banjarharjo, Brebes, Karangmaja, Banjarharjo, KAB. BREBES');
                setElemText('applicantDomicileAddress', data.domicile_address, 'Karangmaja, Banjarharjo, Brebes, Karangmaja, Banjarharjo, KAB. BREBES, JAWA TENGAH');

                // Education
                const eduContainer = document.getElementById('applicantEducation');
                if (eduContainer) {
                    if (data.profile && data.profile.education && data.profile.education.length > 0) {
                        eduContainer.innerHTML = data.profile.education.map(item => `
                            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:8px;">
                                <div style="font-size:12.5px; font-weight:700; color:#0f172a;">${escapeHtml(item.level || 'Pendidikan')}</div>
                                <div style="font-size:11.5px; color:#64748b; margin:2px 0;">🏛 ${escapeHtml(item.school_name || '')}</div>
                                <div style="font-size:11.5px; color:#64748b;">${escapeHtml(item.major || '')}</div>
                                <div style="font-size:11px; color:#94a3b8; margin-top:2px;">${escapeHtml(item.graduation_year || '')} ${item.gpa ? `· IPK: ${escapeHtml(item.gpa)}` : ''} ${item.location ? `· ${escapeHtml(item.location)}` : ''}</div>
                            </div>
                        `).join('');
                    } else {
                        eduContainer.innerHTML = '<div style="font-size:11.5px; color:#94a3b8;">Belum ada riwayat pendidikan yang tercatat.</div>';
                    }
                }

                // Trainings
                const trainContainer = document.getElementById('applicantTrainings');
                if (trainContainer) {
                    if (data.profile && data.profile.trainings && data.profile.trainings.length > 0) {
                        trainContainer.innerHTML = data.profile.trainings.map(item => `
                            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:8px;">
                                <div style="font-size:12.5px; font-weight:700; color:#0f172a;">${escapeHtml(item.training_name || 'Pelatihan')}</div>
                                <div style="font-size:11.5px; color:#64748b; margin:2px 0;">🏢 ${escapeHtml(item.organizer || '')}</div>
                                <div style="font-size:11px; color:#94a3b8;">${escapeHtml(item.year || '')} ${item.certificate ? `· ${escapeHtml(item.certificate)}` : ''} ${item.location ? `· ${escapeHtml(item.location)}` : ''}</div>
                            </div>
                        `).join('');
                    } else {
                        trainContainer.innerHTML = '<div style="font-size:11.5px; color:#94a3b8;">Belum ada riwayat pelatihan yang tercatat.</div>';
                    }
                }

                // Experience
                const expContainer = document.getElementById('applicantExperience');
                if (expContainer) {
                    if (data.profile && data.profile.experience && data.profile.experience.length > 0) {
                        expContainer.innerHTML = data.profile.experience.map(item => `
                            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:8px;">
                                <div style="font-size:12.5px; font-weight:700; color:#0f172a;">${escapeHtml(item.position || '')} - ${escapeHtml(item.company_name || '')}</div>
                                <div style="font-size:11.5px; color:#64748b; margin:2px 0;">${escapeHtml(item.duration || '')}</div>
                                ${item.notes ? `<div style="font-size:11.5px; color:#334155; margin-top:4px;">${escapeHtml(item.notes)}</div>` : ''}
                            </div>
                        `).join('');
                    } else {
                        expContainer.innerHTML = `
                            <div style="border:1px dashed #e2e8f0; border-radius:8px; padding:20px; text-align:center;">
                                <i class="fa-solid fa-briefcase" style="font-size:22px; color:#cbd5e1; margin-bottom:4px; display:block;"></i>
                                <span style="font-size:11.5px; color:#94a3b8;">Belum ada pengalaman kerja yang tercatat.</span>
                            </div>
                        `;
                    }
                }

                // Skills
                const skillsContainer = document.getElementById('applicantSkills');
                if (skillsContainer) {
                    if (data.profile && data.profile.skills && data.profile.skills.length > 0) {
                        skillsContainer.innerHTML = data.profile.skills.map(item => `
                            <span style="background:#fff; color:#334155; border:1px solid #cbd5e1; padding:3px 10px; border-radius:6px; font-size:11.5px; font-weight:500;">${escapeHtml(item.skill_name || item)}</span>
                        `).join('');
                    } else {
                        skillsContainer.innerHTML = '<span style="background:#fff; color:#334155; border:1px solid #cbd5e1; padding:3px 10px; border-radius:6px; font-size:11.5px; font-weight:500;">Bahasa Korea</span> <span style="background:#fff; color:#334155; border:1px solid #cbd5e1; padding:3px 10px; border-radius:6px; font-size:11.5px; font-weight:500;">Operator forklift</span>';
                    }
                }

                switchApplicantModalTab('profil');
                modal.style.display = 'flex';
                modal.classList.add('open');
                modal.onclick = (event) => {
                    if (event.target === modal) {
                        modal.style.display = 'none';
                        modal.classList.remove('open');
                    }
                };
            })
            .catch(() => {});
    }
    window.openApplicantProfile = openApplicantProfile;

    function closeApplicantProfileModal() {
        const modal = document.querySelector('[data-modal="applicant-profile"]');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('open');
            modal.dispatchEvent(new CustomEvent('modal:close'));
        }
    }
    window.closeApplicantProfileModal = closeApplicantProfileModal;

    document.querySelectorAll('[data-open-applicant]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            openApplicantProfile(trigger.dataset.openApplicant);
        });
    });

    const expandApplicants = document.querySelector('[data-expand-applicants]');
    if (expandApplicants) {
        expandApplicants.addEventListener('click', () => {
            document.querySelectorAll('.applicant-ready-list [hidden]').forEach((item) => {
                item.hidden = false;
            });
            expandApplicants.remove();
        });
    }

});

// Schedule View Switcher (Bulanan, Mingguan, Harian)
let currentScheduleView = 'bulanan';
let currentScheduleDate = new Date();

const INDO_MONTH_NAMES = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];
const INDO_MONTH_SHORT = [
    'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
    'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
];
const INDO_DAY_NAMES = [
    'Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'
];

function updateHarianTimeMarker() {
    const marker = document.getElementById('dayCurrentTimeMarker');
    const timePill = document.getElementById('dayTimePill');
    const container = document.querySelector('.day-view-container');
    if (!marker || !container) return;

    const now = new Date();
    const hours = now.getHours();
    const minutes = now.getMinutes();

    const hh = String(hours).padStart(2, '0');
    const mm = String(minutes).padStart(2, '0');
    if (timePill) timePill.textContent = `${hh}:${mm}`;

    const hourRows = container.querySelectorAll('.day-hour-row');
    if (hourRows.length > 0) {
        const rowHeight = hourRows[0].offsetHeight || 40;
        const totalMinutes = (hours * 60) + minutes;
        const topPx = (totalMinutes / 60) * rowHeight;
        marker.style.top = `${topPx}px`;
    }
}
window.updateHarianTimeMarker = updateHarianTimeMarker;

function isSameDay(d1, d2) {
    return d1.getFullYear() === d2.getFullYear() &&
           d1.getMonth() === d2.getMonth() &&
           d1.getDate() === d2.getDate();
}

function getMondayOfDate(d) {
    const dateCopy = new Date(d);
    const day = dateCopy.getDay();
    const diff = (day === 0 ? -6 : 1 - day);
    dateCopy.setDate(dateCopy.getDate() + diff);
    return dateCopy;
}

function updateSchedulePeriodTitle() {
    const titleEl = document.getElementById('schedPeriodTitle');
    if (!titleEl) return;

    if (currentScheduleView === 'bulanan') {
        const monthName = INDO_MONTH_NAMES[currentScheduleDate.getMonth()];
        const year = currentScheduleDate.getFullYear();
        titleEl.textContent = `${monthName} ${year}`;
    } else if (currentScheduleView === 'mingguan') {
        const monday = getMondayOfDate(currentScheduleDate);
        const sunday = new Date(monday);
        sunday.setDate(sunday.getDate() + 6);

        const mDay = monday.getDate();
        const mMonth = INDO_MONTH_SHORT[monday.getMonth()];
        const sDay = sunday.getDate();
        const sMonth = INDO_MONTH_SHORT[sunday.getMonth()];
        const year = sunday.getFullYear();

        if (monday.getMonth() === sunday.getMonth()) {
            titleEl.textContent = `${mDay} – ${sDay} ${sMonth} ${year}`;
        } else {
            titleEl.textContent = `${mDay} ${mMonth} – ${sDay} ${sMonth} ${year}`;
        }
    } else if (currentScheduleView === 'harian') {
        const dayName = INDO_DAY_NAMES[currentScheduleDate.getDay()];
        const dateNum = currentScheduleDate.getDate();
        const monthName = INDO_MONTH_NAMES[currentScheduleDate.getMonth()];
        const year = currentScheduleDate.getFullYear();
        titleEl.textContent = `${dayName}, ${dateNum} ${monthName} ${year}`;
    }
}

function renderScheduleCalendar() {
    updateSchedulePeriodTitle();

    const today = new Date();

    // 1. Render Bulanan Grid
    const monthBodyGrid = document.querySelector('#schedViewBulanan .month-body-grid');
    if (monthBodyGrid) {
        const year = currentScheduleDate.getFullYear();
        const month = currentScheduleDate.getMonth();

        const firstDayOfMonth = new Date(year, month, 1);
        let startDayOffset = firstDayOfMonth.getDay() - 1; // Monday = 0
        if (startDayOffset < 0) startDayOffset = 6;

        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        let totalCells = startDayOffset + daysInMonth;
        if (totalCells <= 35) totalCells = 35;
        else totalCells = 42;

        let gridHtml = '';
        for (let i = 0; i < totalCells; i++) {
            if (i < startDayOffset) {
                const prevDay = daysInPrevMonth - startDayOffset + 1 + i;
                gridHtml += `<div class="month-day-cell other-month"><span class="day-num">${prevDay}</span></div>`;
            } else if (i < startDayOffset + daysInMonth) {
                const dayNum = i - startDayOffset + 1;
                const cellDate = new Date(year, month, dayNum);
                const isTodayCell = isSameDay(cellDate, today);
                const badgeClass = isTodayCell ? 'day-num today-badge' : 'day-num';
                gridHtml += `<div class="month-day-cell"><span class="${badgeClass}">${dayNum}</span></div>`;
            } else {
                const nextDay = i - (startDayOffset + daysInMonth) + 1;
                gridHtml += `<div class="month-day-cell other-month"><span class="day-num">${nextDay}</span></div>`;
            }
        }
        monthBodyGrid.innerHTML = gridHtml;

        monthBodyGrid.querySelectorAll('.month-day-cell:not(.other-month)').forEach(cell => {
            cell.addEventListener('click', () => {
                monthBodyGrid.querySelectorAll('.day-num').forEach(d => d.classList.remove('today-badge'));
                const numEl = cell.querySelector('.day-num');
                if (numEl) numEl.classList.add('today-badge');
            });
        });
    }

    // 2. Render Mingguan Header
    const weekHeadRow = document.querySelector('#schedViewMingguan .week-head-row');
    if (weekHeadRow) {
        const monday = getMondayOfDate(currentScheduleDate);
        let weekHtml = '';
        for (let i = 0; i < 7; i++) {
            const curr = new Date(monday);
            curr.setDate(monday.getDate() + i);
            const dayNameShort = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'][i];
            const isTodayCell = isSameDay(curr, today);
            const badgeClass = isTodayCell ? 'week-head-num today-badge' : 'week-head-num';
            weekHtml += `
                <div class="week-head-cell">
                    <div class="week-head-name">${dayNameShort}</div>
                    <div class="${badgeClass}">${curr.getDate()}</div>
                </div>
            `;
        }
        weekHeadRow.innerHTML = weekHtml;

        weekHeadRow.querySelectorAll('.week-head-cell').forEach(cell => {
            cell.addEventListener('click', () => {
                weekHeadRow.querySelectorAll('.week-head-num').forEach(d => d.classList.remove('today-badge'));
                const numEl = cell.querySelector('.week-head-num');
                if (numEl) numEl.classList.add('today-badge');
            });
        });
    }
}
window.renderScheduleCalendar = renderScheduleCalendar;

function setScheduleView(mode) {
    currentScheduleView = mode;
    
    // Update pill buttons active state
    document.querySelectorAll('.sched-pill[data-sched-pill]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.schedPill === mode);
    });

    // Toggle panes
    const paneBulanan = document.getElementById('schedViewBulanan');
    const paneMingguan = document.getElementById('schedViewMingguan');
    const paneHarian = document.getElementById('schedViewHarian');

    if (paneBulanan) paneBulanan.style.display = (mode === 'bulanan') ? 'block' : 'none';
    if (paneMingguan) paneMingguan.style.display = (mode === 'mingguan') ? 'block' : 'none';
    if (paneHarian) {
        paneHarian.style.display = (mode === 'harian') ? 'block' : 'none';
        if (mode === 'harian') {
            setTimeout(updateHarianTimeMarker, 50);
        }
    }

    renderScheduleCalendar();
}

function navigateSchedule(delta) {
    if (currentScheduleView === 'bulanan') {
        currentScheduleDate.setMonth(currentScheduleDate.getMonth() + delta);
    } else if (currentScheduleView === 'mingguan') {
        currentScheduleDate.setDate(currentScheduleDate.getDate() + (delta * 7));
    } else if (currentScheduleView === 'harian') {
        currentScheduleDate.setDate(currentScheduleDate.getDate() + delta);
    }
    renderScheduleCalendar();
}

function resetScheduleToday() {
    currentScheduleDate = new Date();
    renderScheduleCalendar();
}

function toggleScheduleFilter(event) {
    if (event) event.stopPropagation();
    const popover = document.getElementById('schedFilterPopover');
    const btn = document.getElementById('schedFilterBtn');
    if (!popover) return;
    
    const isOpen = popover.classList.contains('open');
    popover.classList.toggle('open', !isOpen);
    if (btn) btn.classList.toggle('active', !isOpen);
}

function toggleFilterOption(itemEl, type) {
    if (!itemEl) return;
    itemEl.classList.toggle('active');
}

// Close filter popover on clicking outside
document.addEventListener('click', (e) => {
    const wrap = document.querySelector('.sched-filter-wrap');
    const popover = document.getElementById('schedFilterPopover');
    const btn = document.getElementById('schedFilterBtn');
    if (popover && wrap && !wrap.contains(e.target)) {
        popover.classList.remove('open');
        if (btn) btn.classList.remove('active');
    }
});

// Sidebar Rail Popovers (Tema Tampilan & Akun Pengguna)
function toggleThemeMenu(event) {
    if (event) event.stopPropagation();
    const popover = document.getElementById('railThemePopover');
    const accountPopover = document.getElementById('railAccountPopover');
    if (accountPopover) accountPopover.classList.remove('open');
    if (popover) {
        popover.classList.toggle('open');
    }
}

function toggleAccountMenu(event) {
    if (event) event.stopPropagation();
    const popover = document.getElementById('railAccountPopover');
    const themePopover = document.getElementById('railThemePopover');
    if (themePopover) themePopover.classList.remove('open');
    if (popover) {
        popover.classList.toggle('open');
    }
}

function closeRailPopovers() {
    const themePopover = document.getElementById('railThemePopover');
    const accountPopover = document.getElementById('railAccountPopover');
    if (themePopover) themePopover.classList.remove('open');
    if (accountPopover) accountPopover.classList.remove('open');
}

function selectThemeOption(theme) {
    setTheme(theme);
    closeRailPopovers();
}

function initPopovers() {
    const themeBtn = document.getElementById('themeToggleBtn');
    if (themeBtn && themeBtn.dataset.bound !== 'true') {
        themeBtn.dataset.bound = 'true';
        themeBtn.addEventListener('click', toggleThemeMenu);
    }

    const avatarBtn = document.getElementById('sidebarAvatar');
    if (avatarBtn && avatarBtn.dataset.bound !== 'true') {
        avatarBtn.dataset.bound = 'true';
        avatarBtn.addEventListener('click', toggleAccountMenu);
    }

    document.querySelectorAll('[data-theme-val]').forEach(btn => {
        if (btn.dataset.bound !== 'true') {
            btn.dataset.bound = 'true';
            btn.addEventListener('click', () => {
                selectThemeOption(btn.dataset.themeVal);
            });
        }
    });

    document.querySelectorAll('.sched-pill[data-sched-pill]').forEach(btn => {
        if (btn.dataset.bound !== 'true') {
            btn.dataset.bound = 'true';
            btn.addEventListener('click', () => {
                setScheduleView(btn.dataset.schedPill);
            });
        }
    });
}

// Global click outside listener for rail popovers & filter popover
document.addEventListener('click', (e) => {
    const wrapTheme = document.getElementById('railThemePopover');
    const btnTheme = document.getElementById('themeToggleBtn');
    const wrapAccount = document.getElementById('railAccountPopover');
    const btnAccount = document.getElementById('sidebarAvatar');

    if (wrapTheme && wrapTheme.classList.contains('open') && !wrapTheme.contains(e.target) && !btnTheme?.contains(e.target)) {
        wrapTheme.classList.remove('open');
    }
    if (wrapAccount && wrapAccount.classList.contains('open') && !wrapAccount.contains(e.target) && !btnAccount?.contains(e.target)) {
        wrapAccount.classList.remove('open');
    }
});

// Interactive date selection for schedule month & week views
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.month-day-cell:not(.other-month)').forEach(cell => {
        cell.addEventListener('click', () => {
            document.querySelectorAll('.month-day-cell .day-num').forEach(d => d.classList.remove('today-badge'));
            const numEl = cell.querySelector('.day-num');
            if (numEl) numEl.classList.add('today-badge');
        });
    });

    document.querySelectorAll('.week-head-cell').forEach(cell => {
        cell.addEventListener('click', () => {
            document.querySelectorAll('.week-head-cell .week-head-num').forEach(d => d.classList.remove('today-badge'));
            const numEl = cell.querySelector('.week-head-num');
            if (numEl) numEl.classList.add('today-badge');
        });
    });
});

window.setScheduleView = setScheduleView;
window.navigateSchedule = navigateSchedule;
window.resetScheduleToday = resetScheduleToday;

/* ─── FORM PROFIL PEMBERI KERJA INDIVIDU & HIERARCHICAL LOCATION SELECTOR ─── */

const PKI_FORM_LOCATIONS = {
    "Aceh": {
        "Kota Banda Aceh": {
            "Kuta Alam": ["Beurawe", "Bandar Baru", "Kota Baru", "Keuramat", "Lambaro Skep"],
            "Baiturrahman": ["Neusu Aceh", "Peunitia", "Ateuk Pabuat", "Sukaramai"],
            "Banda Raya": ["Lamlagang", "Geuceu Komplek", "Geuceu Ineum"],
            "Jaya Baru": ["Punge Blang Cut", "Lampoh Daya", "Empee Trieng"],
            "Syiah Kuala": ["Darussalam", "Kopelma Darussalam", "Ie Masen Kayee Adang"]
        },
        "Kota Sabang": {
            "Sukakarya": ["Aneuk Laot", "Iboih", "Krueng Raya"],
            "Sukajaya": ["Anoi Itam", "Balohan", "Cot Abeuk"]
        },
        "Kabupaten Aceh Besar": {
            "Ingin Jaya": ["Lambaro", "Aneyuk Batee", "Lubok Sukon"],
            "Darul Imarah": ["Lampeuneurut", "Punieu", "Gue Gajah"],
            "Lhoknga": ["Mon Ikeun", "Lampuuk"]
        },
        "Kabupaten Pidie": {
            "Sigli": ["Blang Paseh", "Kuala Pidie", "Kramat Luar"]
        }
    },
    "Sumatera Utara": {
        "Kota Medan": {
            "Medan Kota": ["Pasar Merah Timur", "Teladan Barat", "Pusat Pasar", "Siti Rejo I"],
            "Medan Petisah": ["Sekip", "Petisah Tengah", "Sei Sikambing D", "Silalas"],
            "Medan Barat": ["Kesawan", "Glugur Kota", "Karang Berombak"],
            "Medan Timur": ["Gugor", "Perintis", "Sidodadi"],
            "Medan Selayang": ["Padang Bulan Selayang I", "Sempakata", "Tanjung Sari"],
            "Medan Johor": ["Gedung Johor", "Pangkalan Mansyur", "Suka Maju"]
        },
        "Kota Binjai": {
            "Binjai Kota": ["Pekan Binjai", "Kartini", "Setia"],
            "Binjai Barat": ["Payaroba", "Limau Sundai"]
        },
        "Kabupaten Deli Serdang": {
            "Lubuk Pakam": ["Lubuk Pakam Pekan", "Sekip", "Bakaran Batu"],
            "Percut Sei Tuan": ["Tembung", "Saentis", "Sampali"],
            "Sunggal": ["Sunggal Kanan", "Helvetia", "Sei Semayang"],
            "Tanjung Morawa": ["Tanjung Morawa A", "Tanjung Morawa B", "Wono Giri"]
        }
    },
    "Sumatera Barat": {
        "Kota Padang": {
            "Padang Barat": ["Olo", "Kampung Jao", "Flamboyan Baru"],
            "Padang Timur": ["Sawahan", "Jati", "Ganting Parak Gadang"],
            "Padang Selatan": ["Mata Air", "Pasa Gadang", "Teluk Bayur"],
            "Kuranji": ["Kuranji", "Pasar Ambacang", "Anduring"]
        },
        "Kota Bukittinggi": {
            "Guguk Panjang": ["Tarigo", "Benteng Pasar Atas", "Kayu Kubu"],
            "Mandiangin Koto Selayan": ["Campago Ipuuh", "Pulai Anak Air"]
        }
    },
    "Riau": {
        "Kota Pekanbaru": {
            "Pekanbaru Kota": ["Simpang Empat", "Sumahilang", "Sukaramai"],
            "Tampan": ["Sidomulyo Barat", "Delima", "Tuah Karya"],
            "Marpoyan Damai": ["Tangkerang Tengah", "Sidomulyo Timur"],
            "Rumbai": ["Umban Sari", "Palas"]
        },
        "Kota Dumai": {
            "Dumai Timur": ["Teluk Binjai", "Buluh Kasap", "Jaya Mukti"]
        }
    },
    "Kepulauan Riau": {
        "Kota Batam": {
            "Batam Kota": ["Teluk Tering", "Belian", "Baloi Permai", "Sukajadi"],
            "Lubuk Baja": ["Nagoya", "Kampung Seraya", "Batu Selicin"],
            "Sekupang": ["Tiban Indah", "Sungai Harapan", "Tiban Lama"],
            "Batu Ampar": ["Jabu Subur", "Sungai Jodoh"]
        },
        "Kota Tanjungpinang": {
            "Tanjungpinang Kota": ["Tanjungpinang Kota", "Kampung Bugis"],
            "Bukit Bestari": ["Tanjung Ayun", "Dompak"]
        }
    },
    "Sumatera Selatan": {
        "Kota Palembang": {
            "Ilir Timur I": ["Demang Lebar Daun", "20 Ilir D I", "Sungai Buah"],
            "Ilir Barat I": ["Lorok Pakjo", "Demang Lebar Daun", "Bukit Lama"],
            "Seberang Ulu I": ["7 Ulu", "9/10 Ulu", "15 Ulu"],
            "Bukit Kecil": ["26 Ilir", "Talang Semut"],
            "Plaju": ["Plaju Ulu", "Plaju Darat"]
        }
    },
    "Bengkulu": {
        "Kota Bengkulu": {
            "Ratu Samban": ["Pengantungan", "Belakang Pondok", "Padang Jati"],
            "Teluk Segara": ["Pasar Baru", "Kampung Kelawi"]
        }
    },
    "Lampung": {
        "Kota Bandar Lampung": {
            "Tanjung Karang Pusat": ["Tanjung Karang", "Enggal", "Gotong Royong"],
            "Kedaton": ["Kedaton", "Labuhan Ratu", "Penengahan"],
            "Teluk Betung Selatan": ["Gedong Pakuon", "Pesawahan"]
        }
    },
    "DKI Jakarta": {
        "Kota Jakarta Selatan": {
            "Tebet": ["Bukit Duri", "Kebon Baru", "Manggarai", "Manggarai Selatan", "Menteng Dalam", "Tebet Barat", "Tebet Timur"],
            "Kebayoran Baru": ["Cipete Utara", "Gandaria Utara", "Gunung", "Kramat Pela", "Melawai", "Petogogan", "Pulo", "Rawa Barat", "Selong", "Senayan"],
            "Cilandak": ["Cilandak Barat", "Cipete Selatan", "Gandaria Selatan", "Lebak Bulus", "Pondok Labu"],
            "Setiabudi": ["Guntur", "Karet", "Karet Kuningan", "Karet Semanggi", "Kuningan Timur", "Menteng Atas", "Pasar Manggis", "Setiabudi"],
            "Pasar Minggu": ["Cilandak Timur", "Jati Padang", "Kebagusan", "Pasar Minggu", "Pejaten Barat", "Pejaten Timur", "Ragunan"],
            "Pancoran": ["Cikoko", "Duren Tiga", "Kalibata", "Pancoran", "Pengadegan", "Rawajati"],
            "Kebayoran Lama": ["Cipulir", "Grogol Selatan", "Grogol Utara", "Kebayoran Lama Selatan", "Kebayoran Lama Utara", "Pondok Pinang"],
            "Jagakarsa": ["Ciganjur", "Cipedak", "Jagakarsa", "Lenteng Agung", "Srengseng Sawah", "Tanjung Barat"]
        },
        "Kota Jakarta Pusat": {
            "Gambir": ["Cideng", "Duri Pulo", "Gambir", "Kebon Kelapa", "Petojo Selatan", "Petojo Utara"],
            "Tanah Abang": ["Bendungan Hilir", "Gelora", "Kampung Bali", "Karet Tengsin", "Kebon Kacang", "Kebon Melati", "Petamburan"],
            "Menteng": ["Cikini", "Gondangdia", "Kebon Sirih", "Menteng", "Pegangsaan"],
            "Kemayoran": ["Cempaka Baru", "Gunung Sahari Selatan", "Harapan Mulya", "Kebon Kosong", "Kemayoran", "Serdang", "Sumur Batu", "Utan Panjang"],
            "Sawah Besar": ["Gunung Sahari Utara", "Karang Anyar", "Kartini", "Mangga Dua Selatan", "Pasar Baru"],
            "Senen": ["Bungur", "Kenari", "Kramat", "Kwitang", "Paseban", "Senen"]
        },
        "Kota Jakarta Barat": {
            "Grogol Petamburan": ["Grogol", "Jelambar", "Jelambar Baru", "Tanjung Duren Selatan", "Tanjung Duren Utara", "Tomang", "Wijaya Kusuma"],
            "Kebon Jeruk": ["Duri Kepa", "Kebon Jeruk", "Kedoya Selatan", "Kedoya Utara", "Kelapa Dua", "Sukabumi Selatan", "Sukabumi Utara"],
            "Kembangan": ["Joglo", "Kembangan Selatan", "Kembangan Utara", "Meruya Selatan", "Meruya Utara", "Srengseng"],
            "Palmerah": ["Jatipulo", "Kemanggisan", "Kota Bambu Selatan", "Kota Bambu Utara", "Palmerah", "Slipi"],
            "Cengkareng": ["Cengkareng Barat", "Cengkareng Timur", "Duri Kosambi", "Kapuk", "Kedaung Kali Angke", "Rawa Buaya"]
        },
        "Kota Jakarta Timur": {
            "Jatinegara": ["Bali Mester", "Bidara Cina", "Cipinang Besar Selatan", "Cipinang Besar Utara", "Cipinang Cempedak", "Cipinang Muara", "Kampung Melayu"],
            "Duren Sawit": ["Duren Sawit", "Klender", "Malaka Jaya", "Malaka Sari", "Pondok Bambu", "Pondok Kelapa", "Pondok Kopi"],
            "Cakung": ["Cakung Barat", "Cakung Timur", "Jatinegara", "Penggilingan", "Pulogebang", "Rawa Terate", "Ujung Menteng"],
            "Kramat Jati": ["Balekambang", "Batu Ampar", "Cawang", "Cililitan", "Dukuh", "Kramat Jati", "Tengah"],
            "Pulogadung": ["Cipinang", "Jati", "Jatinegara Kaum", "Kayu Putih", "Pisangan Timur", "Pulogadung", "Rawamangun"]
        },
        "Kota Jakarta Utara": {
            "Kelapa Gading": ["Kelapa Gading Barat", "Kelapa Gading Timur", "Pegangsaan Dua"],
            "Penjaringan": ["Kamal Muara", "Kapuk Muara", "Pejagalan", "Penjaringan", "Pluit"],
            "Tanjung Priok": ["Kebon Bawang", "Papanggo", "Sungai Bambu", "Sunter Agung", "Sunter Jaya", "Tanjung Priok", "Warakas"]
        }
    },
    "Jawa Barat": {
        "Kota Bekasi": {
            "Bekasi Selatan": ["Pekayon Jaya", "Kayuringin Jaya", "Jatibening", "Jaka Setia", "Marga Jaya"],
            "Bekasi Timur": ["Aren Jaya", "Bekasi Jaya", "Duren Jaya", "Margahayu"],
            "Bekasi Barat": ["Kranji", "Kota Baru", "Bintara", "Bintara Jaya"],
            "Bekasi Utara": ["Harapan Baru", "Harapan Jaya", "Teluk Pucung", "Perwira"],
            "Rawalumbu": ["Bojong Rawalumbu", "Pengasinan", "Sepanjang Jaya"],
            "Pondok Gede": ["Jaticempaka", "Jatiwaringin", "Jatibening"],
            "Jatiasih": ["Jatiasih", "Jatikramat", "Jatimekar"],
            "Medan Satria": ["Medan Satria", "Pejuang", "Harapan Mulya"]
        },
        "Kabupaten Bekasi": {
            "Cikarang Pusat": ["Jayamukti", "Sukamahi", "Pasirranji"],
            "Cikarang Selatan": ["Cibatu", "Pasirsari", "Sukaresmi", "Serang"],
            "Cikarang Utara": ["Waluya", "Simpangan", "Mekarmukti"],
            "Tambun Selatan": ["Jatimulya", "Tambun", "Tridayajaya", "Mekarsari"],
            "Cibitung": ["Wanasari", "Gandasari"]
        },
        "Kota Bandung": {
            "Coblong": ["Dago", "Lebak Siliwangi", "Sadang Serang", "Sekeloa", "Cipaganti"],
            "Sukajadi": ["Pasteur", "Sukajadi", "Sukawarna", "Gegerkalongan"],
            "Sumur Bandung": ["Braga", "Kebon Pisang", "Merdeka"],
            "Bandung Wetan": ["Citarum", "Tamansari", "Cihapit"],
            "Cicendo": ["Pasirkaliki", "Arjuna", "Pajajaran"],
            "Lengkong": ["Malabar", "Cijagra", "Burangrang"],
            "Regol": ["Balonggede", "Ciateul", "Palleser"]
        },
        "Kota Depok": {
            "Beji": ["Beji", "Kukusan", "Pondok Cina", "Tanah Baru"],
            "Pancoran Mas": ["Depok", "Mampang", "Depok Jaya", "Rangkapan Jaya"],
            "Cimanggis": ["Tugu", "Pasir Gunung Selatan", "Mekarsari"],
            "Sukmajaya": ["Abadijaya", "Mekarjaya", "Baktijaya"]
        },
        "Kota Bogor": {
            "Bogor Tengah": ["Babakan", "Paledang", "Sempur", "Kebon Kelapa"],
            "Bogor Timur": ["Baranangsiang", "Katulampa", "Tajur"],
            "Bogor Selatan": ["Batutulis", "Lawanggintung", "Empang"],
            "Tanah Sareal": ["Tanah Sareal", "Kedung Badak", "Kebon Pedes"]
        },
        "Kabupaten Bogor": {
            "Cibinong": ["Cibinong", "Cirimekar", "Pakansari", "Nanggewer"],
            "Gunung Putri": ["Gunung Putri", "Tlajung Udik", "Cicadas"],
            "Bojonggede": ["Bojonggede", "Pabuaran"],
            "Parung": ["Parung", "Waru"]
        },
        "Kota Cimahi": {
            "Cimahi Utara": ["Cipageran", "Citeureup", "Pasirkaliki"],
            "Cimahi Tengah": ["Cimahi", "Karangmekar", "Padasuka"]
        }
    },
    "Banten": {
        "Kota Tangerang": {
            "Tangerang": ["Cikokol", "Babakan", "Buaran Indah", "Tanah Tinggi"],
            "Cipondoh": ["Cipondoh", "Petir", "Poris Plawad", "Poris Indah"],
            "Karawaci": ["Karawaci", "Cimone", "Bugel"],
            "Ciledug": ["Sudimara Barat", "Paninggilan"],
            "Pinang": ["Kunciran", "Pinang", "Sudimara Pinang"]
        },
        "Kota Tangerang Selatan": {
            "Serpong": ["Rawa Buntu", "Serpong", "Lengkong Gudang", "Lengkong Karya"],
            "Serpong Utara": ["Pakulonan", "Jelupang", "Pondok Jagung"],
            "Pondok Aren": ["Pondok Aren", "Bintaro", "Jurang Mangu Barat", "Pondok Betung"],
            "Pamulang": ["Pamulang Barat", "Pamulang Timur", "Pondok Benda"],
            "Ciputat": ["Ciputat", "Cipayung", "Sawah Besar"]
        },
        "Kabupaten Tangerang": {
            "Kelapa Dua": ["Kelapa Dua", "Bencongan", "Bojong Nangka"],
            "Curug": ["Curug Kulon", "Binong"],
            "Cikupa": ["Cikupa", "Talaga"],
            "Pasar Kemis": ["Pasar Kemis", "Kutajaya"]
        },
        "Kota Serang": {
            "Serang": ["Cipare", "Serang", "Kagungan", "Lontarbaru"],
            "Cipocok Jaya": ["Cipocok Jaya", "Banjaragung"]
        },
        "Kota Cilegon": {
            "Cilegon": ["Bagendung", "Ciwedus"],
            "Citangkil": ["Citangkil"]
        }
    },
    "Jawa Tengah": {
        "Kota Semarang": {
            "Semarang Tengah": ["Pekunden", "Sekyu", "Bangunharjo", "Pandansari"],
            "Semarang Selatan": ["Peterongan", "Randusari", "Pleburan", "Lamper Kidul"],
            "Gajahmungkur": ["Bendan Ngisor", "Petompon", "Sampangan", "Gajahmungkur"],
            "Semarang Barat": ["Karangayu", "Krobokan", "Cabean"],
            "Banyumanik": ["Srondol Kulon", "Pedalangan", "Pudakpayung"]
        },
        "Kota Surakarta": {
            "Banjarsari": ["Kadipiro", "Nusukan", "Timuran", "Manahan"],
            "Jebres": ["Jebres", "Purwodiningratan", "Mojosongo"],
            "Laweyan": ["Purwosari", "Kerten", "Sondakan"],
            "Pasar Kliwon": ["Kauman", "Kedung Lumbu"]
        },
        "Kabupaten Banyumas": {
            "Purwokerto Timur": ["Kranji", "Sokanegara"],
            "Purwokerto Selatan": ["Karangklesem"]
        },
        "Kabupaten Kudus": {
            "Kota Kudus": ["Demaan", "Glantengan"],
            "Jati": ["Getas Peformat"]
        }
    },
    "DI Yogyakarta": {
        "Kota Yogyakarta": {
            "Danurejan": ["Suryatmajan", "Bausasran"],
            "Gondokusuman": ["Terban", "Kotabaru", "Baciro"],
            "Jetis": ["Cokrodiningratan", "Gowongan"],
            "Umbulharjo": ["Muja Muju", "Giwangan", "Tahunan"],
            "Mantrijeron": ["Suryodiningratan", "Gedongkiwo"]
        },
        "Kabupaten Sleman": {
            "Depok": ["Caturtunggal", "Maguwoharjo", "Condongcatur"],
            "Mlati": ["Sinduadi", "Sendangadi"],
            "Gamping": ["Nogotirto", "Ambarketawang"]
        },
        "Kabupaten Bantul": {
            "Kasihan": ["Bangunjiwo", "Tirtonirmolo"],
            "Banguntapan": ["Banguntapan"]
        }
    },
    "Jawa Timur": {
        "Kota Surabaya": {
            "Tegalsari": ["Dr. Soetomo", "Kedungdoro", "Wonorejo", "Keputran"],
            "Gubeng": ["Gubeng", "Airlangga", "Kertajaya", "Mojo"],
            "Wonokromo": ["Darmo", "Sawunggaling", "Wonokromo", "Jagir"],
            "Sukolilo": ["Keputih", "Gebang Putih", "Nginden Jangkungan", "Menur Pumpungan"],
            "Rungkut": ["Kalirungkut", "Rungkut Kidul", "Medokan Ayu"],
            "Genteng": ["Embong Kaliasin", "Genteng"],
            "Jambangan": ["Jambangan", "Karah"]
        },
        "Kota Malang": {
            "Lowokwaru": ["Jatimulyo", "Ketawanggede", "Dinoyo", "Mojolangu", "Tunggulwulung"],
            "Klojen": ["Klojen", "Rampal Celaket", "Oro-Oro Dowo", "Kauman"],
            "Blimbing": ["Blimbing", "Purwantoro", "Arjosari"],
            "Sukun": ["Sukun", "Kebonsari"]
        },
        "Kabupaten Sidoarjo": {
            "Sidoarjo": ["Sidokumpul", "Magersari", "Lemahputro"],
            "Waru": ["Waru", "Tropodo", "Ngingas", "Medaeng"],
            "Gedangan": ["Gedangan", "Sawotratap"],
            "Taman": ["Sepanjang", "Kalijaten"]
        },
        "Kota Kediri": {
            "Kota": ["Kampung Dalem", "Pocanan"],
            "Mojoroto": ["Bandar Lor", "Bujel"]
        }
    },
    "Bali": {
        "Kota Denpasar": {
            "Denpasar Selatan": ["Sanur", "Renon", "Panjer", "Pedungan", "Sidakarya"],
            "Denpasar Barat": ["Dauh Puri", "Pemecutan", "Padangsambian"],
            "Denpasar Utara": ["Peguyangan", "Ubung"],
            "Denpasar Timur": ["Dangin Puri", "Sumerta"]
        },
        "Kabupaten Badung": {
            "Kuta": ["Kuta", "Legian", "Seminyak"],
            "Kuta Utara": ["Tibubeneng", "Kerobokan", "Canggu"],
            "Kuta Selatan": ["Jimbaran", "Benoa", "Ungasan", "Pecatu"],
            "Mengwi": ["Mengwi", "Kapal"]
        },
        "Kabupaten Gianyar": {
            "Ubud": ["Ubud", "Peliatan", "Sayan", "Petulu"],
            "Sukawati": ["Sukawati", "Batubulan"]
        }
    },
    "Nusa Tenggara Barat": {
        "Kota Mataram": {
            "Mataram": ["Mataram Barat", "Pejanggik"],
            "Selaparang": ["Monjok"],
            "Ampenan": ["Ampenan Selatan"]
        }
    },
    "Nusa Tenggara Timur": {
        "Kota Kupang": {
            "Oebobo": ["Oebobo", "Fatululi"],
            "Kelapa Lima": ["Kelapa Lima"]
        }
    },
    "Kalimantan Barat": {
        "Kota Pontianak": {
            "Pontianak Selatan": ["Benua Melayu Darat", "Akcaya"],
            "Pontianak Kota": ["Mariana", "Sungai Bangkong"]
        }
    },
    "Kalimantan Timur": {
        "Kota Balikpapan": {
            "Balikpapan Kota": ["Klandasan Ulu", "Damai"],
            "Balikpapan Selatan": ["Sepinggan", "Gunung Bahagia"],
            "Balikpapan Utara": ["Muara Rapak"],
            "Balikpapan Tengah": ["Gunung Sari"]
        },
        "Kota Samarinda": {
            "Samarinda Kota": ["Bugis", "Pelabuhan"],
            "Samarinda Ulu": ["Sidodadi", "Air Putih"],
            "Sungai Kunjang": ["Teluk Lerong Ulu", "Loa Bakung"]
        },
        "Nusantara (IKN)": {
            "Sepaku": ["Pemaluan", "Bumi Harapan", "Sepaku"]
        }
    },
    "Sulawesi Selatan": {
        "Kota Makassar": {
            "Ujung Pandang": ["Baraya", "Mangkura", "Sawerigading"],
            "Panakkukang": ["Masale", "Tamamaung", "Paropo", "Karampuang"],
            "Tamalate": ["Tanjung Merdeka", "Maccini Sombala", "Mangasa"],
            "Rappocini": ["Buakana", "Karunrung", "Kassi-Kassi"],
            "Biringkanaya": ["Daya", "Sudiang"]
        }
    },
    "Sulawesi Utara": {
        "Kota Manado": {
            "Wenang": ["Wenang Utara", "Teling Bawah"],
            "Malalayang": ["Malalayang Satu"]
        }
    },
    "Papua": {
        "Kota Jayapura": {
            "Jayapura Utara": ["Gurabesi", "Mandala"],
            "Abepura": ["Kota Baru", "Asano"]
        }
    }
};

let currentLocLevel = 1; // 1: Prov, 2: City, 3: District, 4: Village
let selProv = '';
let selCity = '';
let selDistrict = '';
let selVillage = '';
let pkiMapInstance = null;

function initHierarchicalLocationSelector() {
    const locInput = document.getElementById('hierarchicalLocationInput');
    const locDropdown = document.getElementById('hierarchicalLocDropdown');
    const locDisplay = document.getElementById('locDisplayValue');
    const locCaret = document.getElementById('locCaret');
    const breadcrumbs = document.getElementById('locBreadcrumbs');
    const optionsList = document.getElementById('locOptionsList');

    if (!locInput || !locDropdown) return;

    // Load saved values from hidden inputs
    selProv = document.getElementById('hiddenProvince')?.value || '';
    selCity = document.getElementById('hiddenCity')?.value || '';
    selDistrict = document.getElementById('hiddenDistrict')?.value || '';
    selVillage = document.getElementById('hiddenVillage')?.value || '';

    // Click trigger field
    locInput.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = locDropdown.style.display === 'block';
        if (isOpen) {
            closeLocDropdown();
        } else {
            openLocDropdown();
        }
    });

    // Prevent closing when clicking inside dropdown
    locDropdown.addEventListener('click', (e) => {
        e.stopPropagation();
    });

    // Close on click outside
    document.addEventListener('click', (e) => {
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

        // Check behavior requirement:
        // If already fully selected, open directly at Level 4 (Kelurahan / Desa)
        if (selProv && selCity && selDistrict && selVillage && PKI_FORM_LOCATIONS[selProv]?.[selCity]?.[selDistrict]) {
            currentLocLevel = 4;
        } else if (selProv && selCity && selDistrict && PKI_FORM_LOCATIONS[selProv]?.[selCity]?.[selDistrict]) {
            currentLocLevel = 4;
        } else if (selProv && selCity && PKI_FORM_LOCATIONS[selProv]?.[selCity]) {
            currentLocLevel = 3;
        } else if (selProv && PKI_FORM_LOCATIONS[selProv]) {
            currentLocLevel = 2;
        } else {
            currentLocLevel = 1;
        }

        renderLocationView();
    }

    function closeLocDropdown() {
        locDropdown.style.display = 'none';
        if (locCaret) {
            locCaret.classList.remove('fa-chevron-up');
            locCaret.classList.add('fa-chevron-down');
        }
    }

    function renderLocationView() {
        renderBreadcrumbs();
        renderOptions();
    }

    function renderBreadcrumbs() {
        if (!breadcrumbs) return;
        let html = '';

        if (currentLocLevel === 1) {
            html += `<span class="crumb-btn active" onclick="switchLocLevel(1)">Pilih Provinsi</span>`;
        } else if (currentLocLevel === 2) {
            html += `<span class="crumb-btn" onclick="switchLocLevel(1)">${selProv}</span> › `;
            html += `<span class="crumb-btn active" onclick="switchLocLevel(2)">Pilih Kabupaten / Kota</span>`;
        } else if (currentLocLevel === 3) {
            html += `<span class="crumb-btn" onclick="switchLocLevel(1)">${selProv}</span> › `;
            html += `<span class="crumb-btn" onclick="switchLocLevel(2)">${selCity}</span> › `;
            html += `<span class="crumb-btn active" onclick="switchLocLevel(3)">Pilih Kecamatan</span>`;
        } else if (currentLocLevel === 4) {
            html += `<span class="crumb-btn" onclick="switchLocLevel(1)">${selProv}</span> › `;
            html += `<span class="crumb-btn" onclick="switchLocLevel(2)">${selCity}</span> › `;
            html += `<span class="crumb-btn" onclick="switchLocLevel(3)">${selDistrict}</span> › `;
            html += `<span class="crumb-btn active" onclick="switchLocLevel(4)">Pilih Kelurahan / Desa</span>`;
        }
        breadcrumbs.innerHTML = html;
    }

    function renderOptions() {
        if (!optionsList) return;
        let html = '';

        if (currentLocLevel === 1) {
            const provinces = Object.keys(PKI_FORM_LOCATIONS);
            provinces.forEach(prov => {
                const isSel = prov === selProv;
                html += `
                    <div class="loc-opt-row ${isSel ? 'selected' : ''}" onclick="selectProv('${prov}')">
                        <div style="display:flex; align-items:center;">
                            <span class="radio-bullet"></span>
                            <span>${prov}</span>
                        </div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    </div>
                `;
            });
        } else if (currentLocLevel === 2) {
            const cities = Object.keys(PKI_FORM_LOCATIONS[selProv] || {});
            cities.forEach(city => {
                const isSel = city === selCity;
                html += `
                    <div class="loc-opt-row ${isSel ? 'selected' : ''}" onclick="selectCity('${city}')">
                        <div style="display:flex; align-items:center;">
                            <span class="radio-bullet"></span>
                            <span>${city}</span>
                        </div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    </div>
                `;
            });
        } else if (currentLocLevel === 3) {
            const districts = Object.keys(PKI_FORM_LOCATIONS[selProv]?.[selCity] || {});
            districts.forEach(dist => {
                const isSel = dist === selDistrict;
                html += `
                    <div class="loc-opt-row ${isSel ? 'selected' : ''}" onclick="selectDistrict('${dist}')">
                        <div style="display:flex; align-items:center;">
                            <span class="radio-bullet"></span>
                            <span>${dist}</span>
                        </div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    </div>
                `;
            });
        } else if (currentLocLevel === 4) {
            const villages = PKI_FORM_LOCATIONS[selProv]?.[selCity]?.[selDistrict] || [];
            villages.forEach(vill => {
                const isSel = vill === selVillage;
                html += `
                    <div class="loc-opt-row ${isSel ? 'selected' : ''}" onclick="selectVillage('${vill}')">
                        <div style="display:flex; align-items:center;">
                            <span class="radio-bullet"></span>
                            <span>${vill}</span>
                        </div>
                        ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                    </div>
                `;
            });
        }
        optionsList.innerHTML = html;
    }

    window.switchLocLevel = function(lvl) {
        currentLocLevel = lvl;
        renderLocationView();
    };

    window.selectProv = function(prov) {
        if (selProv !== prov) {
            selProv = prov;
            selCity = '';
            selDistrict = '';
            selVillage = '';
            updateHiddenFields();
        }
        currentLocLevel = 2;
        renderLocationView();
    };

    window.selectCity = function(city) {
        if (selCity !== city) {
            selCity = city;
            selDistrict = '';
            selVillage = '';
            updateHiddenFields();
        }
        currentLocLevel = 3;
        renderLocationView();
    };

    window.selectDistrict = function(dist) {
        if (selDistrict !== dist) {
            selDistrict = dist;
            selVillage = '';
            updateHiddenFields();
        }
        currentLocLevel = 4;
        renderLocationView();
    };

    window.selectVillage = function(vill) {
        selVillage = vill;
        updateHiddenFields();
        updateDisplayField();
        closeLocDropdown();
        autoGeocodeLocation();
    };

    function updateHiddenFields() {
        document.getElementById('hiddenProvince').value = selProv;
        document.getElementById('hiddenCity').value = selCity;
        document.getElementById('hiddenDistrict').value = selDistrict;
        document.getElementById('hiddenVillage').value = selVillage;
        document.getElementById('hiddenDomicileCityId').value = selCity;
    }

    function updateDisplayField() {
        if (selVillage && selDistrict && selCity && selProv) {
            locDisplay.textContent = `${selVillage}, ${selDistrict}, ${selCity}, ${selProv}`;
            locDisplay.classList.remove('placeholder');
        } else {
            locDisplay.textContent = 'Pilih lokasi domisili';
            locDisplay.classList.add('placeholder');
        }
    }
}

// Global Document Upload helper
window.removeUploadedDoc = function(type) {
    if (type === 'permit') {
        const container = document.getElementById('docPermitContainer');
        if (container) {
            container.innerHTML = `<input type="file" name="permit_document" id="inputPermitDoc" accept=".pdf,.jpg,.jpeg,.png" style="display:block; width:100%;" required>`;
        }
    } else if (type === 'photo') {
        const container = document.getElementById('docPhotoContainer');
        if (container) {
            container.innerHTML = `<input type="file" name="workplace_photo" id="inputWorkplacePhoto" accept=".jpg,.jpeg,.png,.webp" style="display:block; width:100%;">`;
        }
    }
};

function initEmployerProfileForm() {
    // NIK validation (16 digits only)
    const inputNik = document.getElementById('inputNik');
    const nikErr = document.getElementById('nikErrorMsg');

    if (inputNik) {
        inputNik.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 16);
            if (this.value.length === 16) {
                if (nikErr) nikErr.style.display = 'none';
            }
        });
        inputNik.addEventListener('blur', function() {
            if (this.value.length > 0 && this.value.length !== 16) {
                if (nikErr) nikErr.style.display = 'block';
            } else {
                if (nikErr) nikErr.style.display = 'none';
            }
        });
    }

    // SIAPKerja checkbox notices
    const cbSameLoc = document.getElementById('cbSameLocation');
    const cbSameAddr = document.getElementById('cbSameAddress');
    const noticeLoc = document.getElementById('siapkerjaLocNotice');
    const noticeAddr = document.getElementById('siapkerjaAddrNotice');

    if (cbSameLoc && noticeLoc) {
        cbSameLoc.addEventListener('change', function() {
            noticeLoc.style.display = this.checked ? 'block' : 'none';
        });
    }
    if (cbSameAddr && noticeAddr) {
        cbSameAddr.addEventListener('change', function() {
            noticeAddr.style.display = this.checked ? 'block' : 'none';
        });
    }

    // Address input triggers geocoding map
    const inputAddress = document.getElementById('inputAddress');
    if (inputAddress) {
        inputAddress.addEventListener('input', function() {
            autoGeocodeLocation();
        });
    }

    // Form submit validation
    const form = document.getElementById('formEmployerProfile');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (inputNik && inputNik.value.length !== 16) {
                e.preventDefault();
                if (nikErr) nikErr.style.display = 'block';
                inputNik.focus();
                alert('NIK harus terdiri dari 16 digit angka.');
                return false;
            }
            const cbConsent = document.getElementById('cbUserConsent');
            if (cbConsent && !cbConsent.checked) {
                e.preventDefault();
                alert('Anda harus menyetujui pernyataan persetujuan sebelum mengajukan verifikasi.');
                return false;
            }
        });
    }

    // Initial map check
    autoGeocodeLocation();
}

const CITY_COORDINATES = {
    "Kota Banda Aceh": [5.5483, 95.3238],
    "Kota Sabang": [5.8944, 95.3242],
    "Kab. Aceh Besar": [5.4628, 95.4216],
    "Kab. Pidie": [5.3831, 95.9602],
    "Kota Jakarta Selatan": [-6.2615, 106.8106],
    "Kota Jakarta Pusat": [-6.1805, 106.8284],
    "Kota Bekasi": [-6.2383, 106.9756],
    "Kota Bandung": [-6.9175, 107.6191],
    "Kota Semarang": [-6.9667, 110.4167],
    "Kota Surabaya": [-7.2575, 112.7521]
};

let pkiMarkerInstance = null;

function autoGeocodeLocation() {
    const hiddenVillage = document.getElementById('hiddenVillage')?.value;
    const hiddenCity = document.getElementById('hiddenCity')?.value;
    const hiddenProv = document.getElementById('hiddenProvince')?.value;
    const address = document.getElementById('inputAddress')?.value?.trim();

    const placeholder = document.getElementById('mapPlaceholder');
    const leafletBox = document.getElementById('leafletMap');
    const openLinkWrap = document.getElementById('mapOpenLinkWrapper');
    const btnOpenMap = document.getElementById('btnOpenMap');

    if (!placeholder || !leafletBox) return;

    // Peta HANYA dimuat jika Lokasi Domisili DAN Alamat Lengkap terisi!
    if ((hiddenVillage || hiddenCity) && address && address.length > 3) {
        placeholder.style.display = 'none';
        leafletBox.style.display = 'block';
        if (openLinkWrap) openLinkWrap.style.display = 'block';

        const fullQuery = `${address}, ${hiddenVillage || ''}, ${hiddenCity || ''}, ${hiddenProv || ''}, Indonesia`;
        if (btnOpenMap) {
            btnOpenMap.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(fullQuery)}`;
        }

        const targetCoords = CITY_COORDINATES[hiddenCity] || [-6.2088, 106.8456];

        const inputLat = document.getElementById('inputLat');
        const inputLng = document.getElementById('inputLng');
        if (inputLat) inputLat.value = targetCoords[0];
        if (inputLng) inputLng.value = targetCoords[1];

        // Initialize or update Leaflet map
        if (window.L && document.getElementById('leafletMap')) {
            if (!pkiMapInstance) {
                pkiMapInstance = L.map('leafletMap', {
                    dragging: true,
                    zoomControl: true,
                    scrollWheelZoom: false
                }).setView(targetCoords, 14);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '© OpenStreetMap'
                }).addTo(pkiMapInstance);

                pkiMarkerInstance = L.marker(targetCoords, { interactive: false }).addTo(pkiMapInstance);
            } else {
                pkiMapInstance.setView(targetCoords, 14);
                if (pkiMarkerInstance) {
                    pkiMarkerInstance.setLatLng(targetCoords);
                }
            }
            setTimeout(() => {
                pkiMapInstance.invalidateSize();
            }, 200);
        } else {
            leafletBox.innerHTML = `
                <div style="height:100%; width:100%; background:#e2e8f0; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:16px;">
                    <div style="font-size:28px; color:#ef4444; margin-bottom:4px;"><i class="fa-solid fa-location-dot"></i></div>
                    <div style="font-weight:700; color:#0f172a; font-size:13px;">${e(address)}</div>
                    <div style="font-size:11px; color:#475569; margin-top:2px;">${e(hiddenVillage || '')} ${e(hiddenCity || '')}, ${e(hiddenProv || '')}</div>
                </div>
            `;
        }
    } else {
        placeholder.style.display = 'flex';
        leafletBox.style.display = 'none';
        if (openLinkWrap) openLinkWrap.style.display = 'none';
    }
}

// Global helper to dismiss activation notification
window.dismissActivationNotif = function() {
    const banner = document.getElementById('notifAktivasiBanner');
    if (banner) {
        banner.style.display = 'none';
        localStorage.setItem('hide_notif_aktivasi_pki', '1');
    }
};

// Helper escape HTML string function for app.js
function e(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Job Options & Close Job Modal Handlers
function toggleJobLainnyaDropdown(e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('jobLainnyaDropdownMenu');
    if (!menu) return;
    const isShowing = menu.style.display === 'block';
    menu.style.display = isShowing ? 'none' : 'block';
}
window.toggleJobLainnyaDropdown = toggleJobLainnyaDropdown;

let currentCloseJobId = 0;
let currentCloseQuota = 1;
let currentCloseAccepted = 0;
let currentCloseRemaining = 1;

function openConfirmCloseJobModal(jobId, quota, acceptedCount) {
    currentCloseJobId = jobId || 0;
    currentCloseQuota = (typeof quota !== 'undefined' && quota !== null) ? parseInt(quota, 10) : 1;
    currentCloseAccepted = (typeof acceptedCount !== 'undefined' && acceptedCount !== null) ? parseInt(acceptedCount, 10) : 0;
    currentCloseRemaining = Math.max(0, currentCloseQuota - currentCloseAccepted);

    // Set hidden inputs in formConfirmCloseJob
    const inputJobId = document.getElementById('modalCloseJobId');
    if (inputJobId && currentCloseJobId) inputJobId.value = currentCloseJobId;

    // Set dynamic values in modalUnfulfilledQuotaReason
    const spanAccepted = document.getElementById('unfulfilledAcceptedCount');
    if (spanAccepted) spanAccepted.textContent = currentCloseAccepted;

    const spanQuota = document.getElementById('unfulfilledTotalQuota');
    if (spanQuota) spanQuota.textContent = currentCloseQuota;

    const spanRemaining = document.getElementById('unfulfilledRemainingQuota');
    if (spanRemaining) spanRemaining.textContent = currentCloseRemaining;

    const unfulfilledJobId = document.getElementById('modalUnfulfilledJobId');
    if (unfulfilledJobId && currentCloseJobId) unfulfilledJobId.value = currentCloseJobId;

    const unfulfilledRemaining = document.getElementById('modalUnfulfilledRemaining');
    if (unfulfilledRemaining) unfulfilledRemaining.value = currentCloseRemaining;

    // Open first modal (Konfirmasi)
    const modal = document.getElementById('modalConfirmCloseJob');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('open');
    }
}
window.openConfirmCloseJobModal = openConfirmCloseJobModal;

function closeConfirmCloseJobModal() {
    const modal = document.getElementById('modalConfirmCloseJob');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('open');
    }
}
window.closeConfirmCloseJobModal = closeConfirmCloseJobModal;

function updateJobQuotaDisplay(acceptedCount, quota) {
    if (typeof acceptedCount === 'undefined' || acceptedCount === null) return;
    const acc = parseInt(acceptedCount, 10);
    const q = quota ? parseInt(quota, 10) : (currentCloseQuota || 1);
    const pct = Math.min(100, Math.round((acc / q) * 100));

    currentCloseAccepted = acc;
    currentCloseQuota = q;
    currentCloseRemaining = Math.max(0, q - acc);

    // Update Progress Bar
    const bar = document.getElementById('jobQuotaProgressBar');
    if (bar) bar.style.width = pct + '%';

    // Update text spans
    const spanAcc = document.getElementById('jobQuotaAcceptedSpan');
    if (spanAcc) spanAcc.textContent = acc;

    const spanQ = document.getElementById('jobQuotaTotalSpan');
    if (spanQ) spanQ.textContent = q;

    // Update modal numbers
    const modalAcc = document.getElementById('unfulfilledAcceptedCount');
    if (modalAcc) modalAcc.textContent = acc;

    const modalQ = document.getElementById('unfulfilledTotalQuota');
    if (modalQ) modalQ.textContent = q;

    const modalRem = document.getElementById('unfulfilledRemainingQuota');
    if (modalRem) modalRem.textContent = currentCloseRemaining;

    const inputRem = document.getElementById('modalUnfulfilledRemaining');
    if (inputRem) inputRem.value = currentCloseRemaining;

    // Update button onclick
    const btnClose = document.getElementById('btnTutupLowonganDetail');
    if (btnClose) {
        btnClose.setAttribute('onclick', `openConfirmCloseJobModal(${currentCloseJobId || 0}, ${q}, ${acc})`);
    }
}
window.updateJobQuotaDisplay = updateJobQuotaDisplay;

function handleConfirmCloseJobContinue() {
    // If quota is not fulfilled (sisa_kuota > 0) -> switch to modal Kuota Belum Terpenuhi
    if (currentCloseRemaining > 0) {
        closeConfirmCloseJobModal();
        openUnfulfilledQuotaModal();
    } else {
        // Quota is completely filled -> submit standard close
        const form = document.getElementById('formConfirmCloseJob');
        if (form) {
            form.onsubmit = null;
            form.submit();
        }
    }
}
window.handleConfirmCloseJobContinue = handleConfirmCloseJobContinue;

function openUnfulfilledQuotaModal() {
    const modal = document.getElementById('modalUnfulfilledQuotaReason');
    if (modal) {
        const errDiv = document.getElementById('unfulfilledValidationError');
        if (errDiv) {
            errDiv.style.display = 'none';
            errDiv.textContent = '';
        }
        const checkboxes = modal.querySelectorAll('input[name="reasons[]"]');
        checkboxes.forEach(cb => cb.checked = false);
        const noteInputs = modal.querySelectorAll('.reason-note-input');
        noteInputs.forEach(input => {
            input.value = '';
            input.style.display = 'none';
        });
        modal.style.display = 'flex';
        modal.classList.add('open');
    }
}
window.openUnfulfilledQuotaModal = openUnfulfilledQuotaModal;

function closeUnfulfilledQuotaModal() {
    const modal = document.getElementById('modalUnfulfilledQuotaReason');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('open');
    }
}
window.closeUnfulfilledQuotaModal = closeUnfulfilledQuotaModal;

function toggleReasonNote(cb) {
    if (!cb) return;
    if (cb.value !== 'Lainnya') return;
    const parent = cb.closest('.reason-option-group');
    if (!parent) return;
    const textarea = parent.querySelector('.reason-note-input');
    if (!textarea) return;
    if (cb.checked) {
        textarea.style.display = 'block';
        textarea.focus();
    } else {
        textarea.style.display = 'none';
        textarea.value = '';
    }
}
window.toggleReasonNote = toggleReasonNote;

function toggleReasonLainnya(cb) {
    toggleReasonNote(cb);
}
window.toggleReasonLainnya = toggleReasonLainnya;

function submitUnfulfilledCloseJob(repostValue) {
    const modal = document.getElementById('modalUnfulfilledQuotaReason');
    if (!modal) return;

    const errDiv = document.getElementById('unfulfilledValidationError');
    const checkedReasons = modal.querySelectorAll('input[name="reasons[]"]:checked');

    // Validasi: minimal 1 alasan
    if (checkedReasons.length === 0) {
        if (errDiv) {
            errDiv.textContent = 'Mohon pilih minimal 1 alasan mengapa sisa kuota belum terpenuhi.';
            errDiv.style.display = 'block';
        }
        return;
    }

    // Validasi: jika Lainnya dipilih, wajib isi alasan manual
    const cbLainnya = modal.querySelector('input[name="reasons[]"][value="Lainnya"]');
    const txtLainnya = document.getElementById('reasonLainnyaInput');
    if (cbLainnya && cbLainnya.checked) {
        if (!txtLainnya || !txtLainnya.value.trim()) {
            if (errDiv) {
                errDiv.textContent = 'Opsi "Lainnya" dipilih. Mohon isi alasan secara manual.';
                errDiv.style.display = 'block';
            }
            if (txtLainnya) txtLainnya.focus();
            return;
        }
    }

    if (errDiv) {
        errDiv.style.display = 'none';
    }

    // Set repost value
    const inputRepost = document.getElementById('modalUnfulfilledRepost');
    if (inputRepost) {
        inputRepost.value = repostValue ? '1' : '0';
    }

    // Submit form
    const form = document.getElementById('formUnfulfilledQuota');
    if (form) {
        form.submit();
    }
}
window.submitUnfulfilledCloseJob = submitUnfulfilledCloseJob;

function testCloseJob(jobId, sisaKuota, quota, acceptedCount) {
    openConfirmCloseJobModal(jobId, quota, acceptedCount);
}
window.testCloseJob = testCloseJob;

function openJobPublicView(jobId) {
    const url = 'seeker.php?job_id=' + encodeURIComponent(jobId || '');
    window.open(url, '_blank');
    const menu = document.getElementById('jobLainnyaDropdownMenu');
    if (menu) menu.style.display = 'none';
}
window.openJobPublicView = openJobPublicView;

function copyJobLink(jobId) {
    const link = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '/') + 'seeker.php?job_id=' + encodeURIComponent(jobId || '');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(() => {
            if (window.showToastNotification) {
                window.showToastNotification('Link lowongan berhasil disalin!');
            } else {
                alert('Link lowongan berhasil disalin!');
            }
        }).catch(() => {
            prompt('Salin link lowongan berikut:', link);
        });
    } else {
        prompt('Salin link lowongan berikut:', link);
    }
    const menu = document.getElementById('jobLainnyaDropdownMenu');
    if (menu) menu.style.display = 'none';
}
window.copyJobLink = copyJobLink;

document.addEventListener('click', (evt) => {
    const container = document.getElementById('jobLainnyaDropdownContainer');
    const menu = document.getElementById('jobLainnyaDropdownMenu');
    if (menu && container && !container.contains(evt.target)) {
        menu.style.display = 'none';
    }
});

document.addEventListener('DOMContentLoaded', () => {
    initHierarchicalLocationSelector();
    initEmployerProfileForm();
    if (typeof renderScheduleCalendar === 'function') {
        renderScheduleCalendar();
    }
    if (localStorage.getItem('hide_notif_aktivasi_pki') === '1') {
        const banner = document.getElementById('notifAktivasiBanner');
        if (banner) banner.style.display = 'none';
    }
});
