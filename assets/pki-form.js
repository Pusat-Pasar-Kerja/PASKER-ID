/**
 * PKI Form - Unified Client-Side Logic for Form Profil Pemberi Kerja Individu
 * Handles:
 * 1. Hierarchical location selector with Indonesian administrative boundaries and postal codes.
 * 2. SIAPKerja domicile checkbox autofill (keeps fields visible).
 * 3. Live Google Maps preview and "Buka di Maps" link.
 * 4. Custom Karirhub file & photo upload boxes.
 * 5. Strict per-field validation with red labels and red helper text on submit (inputs keep normal border).
 */

(function () {
    const PKI_LOCATION_DATABASE = {
        'Aceh': {
            'Kota Banda Aceh': {
                'Kuta Alam': { 'Beurawe': '23124', 'Bandar Baru': '23126', 'Kota Baru': '23125', 'Keuramat': '23123', 'Lambaro Skep': '23127' },
                'Baiturrahman': { 'Neusu Aceh': '23241', 'Peunitia': '23241', 'Ateuk Pabuat': '23241', 'Sukaramai': '23241' },
                'Banda Raya': { 'Lamlagang': '23239', 'Geuceu Komplek': '23239', 'Geuceu Ineum': '23239' },
                'Jaya Baru': { 'Punge Blang Cut': '23233', 'Lampoh Daya': '23233', 'Empee Trieng': '23233' },
                'Syiah Kuala': { 'Darussalam': '23111', 'Kopelma Darussalam': '23111', 'Ie Masen Kayee Adang': '23111' }
            },
            'Kota Sabang': {
                'Sukakarya': { 'Aneuk Laot': '23511', 'Iboih': '23523', 'Krueng Raya': '23511' },
                'Sukajaya': { 'Anoi Itam': '23521', 'Balohan': '23521', 'Cot Abeuk': '23521' }
            },
            'Kabupaten Aceh Besar': {
                'Ingin Jaya': { 'Lambaro': '23371', 'Aneyuk Batee': '23371', 'Lubok Sukon': '23371' },
                'Darul Imarah': { 'Lampeuneurut': '23238', 'Punieu': '23238', 'Gue Gajah': '23238' },
                'Lhoknga': { 'Mon Ikeun': '23353', 'Lampuuk': '23353' }
            },
            'Kabupaten Pidie': {
                'Sigli': { 'Blang Paseh': '24112', 'Kuala Pidie': '24113', 'Kramat Luar': '24114' }
            }
        },
        'Sumatera Utara': {
            'Kota Medan': {
                'Medan Kota': { 'Pasar Merah Timur': '20217', 'Teladan Barat': '20217', 'Pusat Pasar': '20212', 'Siti Rejo I': '20216' },
                'Medan Petisah': { 'Sekip': '20111', 'Petisah Tengah': '20112', 'Sei Sikambing D': '20114', 'Silalas': '20114' },
                'Medan Barat': { 'Kesawan': '20111', 'Glugur Kota': '20115', 'Karang Berombak': '20115' },
                'Medan Timur': { 'Gugor': '20235', 'Perintis': '20231', 'Sidodadi': '20234' },
                'Medan Selayang': { 'Padang Bulan Selayang I': '20131', 'Sempakata': '20131', 'Tanjung Sari': '20132' },
                'Medan Johor': { 'Gedung Johor': '20144', 'Pangkalan Mansyur': '20143', 'Suka Maju': '20146' },
                'Medan Helvetia': { 'Helvetia': '20124', 'Dwikora': '20123', 'Tanjung Gusta': '20125' }
            },
            'Kota Binjai': {
                'Binjai Kota': { 'Pekan Binjai': '20711', 'Kartini': '20712', 'Setia': '20713' },
                'Binjai Barat': { 'Payaroba': '20718', 'Limau Sundai': '20719' }
            },
            'Kabupaten Deli Serdang': {
                'Lubuk Pakam': { 'Lubuk Pakam Pekan': '20511', 'Sekip': '20512', 'Bakaran Batu': '20513' },
                'Percut Sei Tuan': { 'Tembung': '20371', 'Saentis': '20371', 'Sampali': '20371' },
                'Sunggal': { 'Sunggal Kanan': '20351', 'Helvetia': '20351', 'Sei Semayang': '20351' },
                'Tanjung Morawa': { 'Tanjung Morawa A': '20362', 'Tanjung Morawa B': '20362', 'Wono Giri': '20362' }
            }
        },
        'Sumatera Barat': {
            'Kota Padang': {
                'Padang Barat': { 'Olo': '25117', 'Kampung Jao': '25112', 'Flamboyan Baru': '25115' },
                'Padang Timur': { 'Sawahan': '25121', 'Jati': '25129', 'Ganting Parak Gadang': '25122' },
                'Padang Selatan': { 'Mata Air': '25211', 'Pasa Gadang': '25211', 'Teluk Bayur': '25213' },
                'Kuranji': { 'Kuranji': '25157', 'Pasar Ambacang': '25152', 'Anduring': '25151' }
            },
            'Kota Bukittinggi': {
                'Guguk Panjang': { 'Tarigo': '26115', 'Benteng Pasar Atas': '26113', 'Kayu Kubu': '26115' },
                'Mandiangin Koto Selayan': { 'Campago Ipuuh': '26117', 'Pulai Anak Air': '26116' }
            }
        },
        'Riau': {
            'Kota Pekanbaru': {
                'Pekanbaru Kota': { 'Simpang Empat': '28116', 'Sumahilang': '28111', 'Sukaramai': '28112' },
                'Tampan': { 'Sidomulyo Barat': '28294', 'Delima': '28291', 'Tuah Karya': '28293' },
                'Marpoyan Damai': { 'Tangkerang Tengah': '28282', 'Sidomulyo Timur': '28284' },
                'Rumbai': { 'Umban Sari': '28265', 'Palas': '28264' }
            },
            'Kota Dumai': {
                'Dumai Timur': { 'Teluk Binjai': '28812', 'Buluh Kasap': '28814', 'Jaya Mukti': '28815' }
            }
        },
        'Kepulauan Riau': {
            'Kota Batam': {
                'Batam Kota': { 'Teluk Tering': '29461', 'Belian': '29464', 'Baloi Permai': '29463', 'Sukajadi': '29462' },
                'Lubuk Baja': { 'Nagoya': '29444', 'Kampung Seraya': '29444', 'Batu Selicin': '29441' },
                'Sekupang': { 'Tiban Indah': '29424', 'Sungai Harapan': '29422', 'Tiban Lama': '29424' },
                'Batu Ampar': { 'Jabu Subur': '29452', 'Sungai Jodoh': '29453' }
            },
            'Kota Tanjungpinang': {
                'Tanjungpinang Kota': { 'Tanjungpinang Kota': '29111', 'Kampung Bugis': '29115' },
                'Bukit Bestari': { 'Tanjung Ayun': '29124', 'Dompak': '29124' }
            }
        },
        'Sumatera Selatan': {
            'Kota Palembang': {
                'Ilir Timur I': { 'Demang Lebar Daun': '30128', '20 Ilir D I': '30126', 'Sungai Buah': '30118' },
                'Ilir Barat I': { 'Lorok Pakjo': '30137', 'Bukit Lama': '30139' },
                'Seberang Ulu I': { '7 Ulu': '30252', '9/10 Ulu': '30251', '15 Ulu': '30257' },
                'Bukit Kecil': { '26 Ilir': '30134', 'Talang Semut': '30135' },
                'Plaju': { 'Plaju Ulu': '30266', 'Plaju Darat': '30268' }
            }
        },
        'DKI Jakarta': {
            'Kota Jakarta Selatan': {
                'Tebet': { 'Bukit Duri': '12840', 'Kebon Baru': '12830', 'Manggarai': '12850', 'Manggarai Selatan': '12860', 'Menteng Dalam': '12870', 'Tebet Barat': '12810', 'Tebet Timur': '12820' },
                'Kebayoran Baru': { 'Cipete Utara': '12150', 'Gandaria Utara': '12140', 'Gunung': '12120', 'Kramat Pela': '12130', 'Melawai': '12160', 'Petogogan': '12170', 'Pulo': '12160', 'Rawa Barat': '12180', 'Selong': '12110', 'Senayan': '12190' },
                'Cilandak': { 'Cilandak Barat': '12430', 'Cipete Selatan': '12410', 'Gandaria Selatan': '12420', 'Lebak Bulus': '12440', 'Pondok Labu': '12450' },
                'Setiabudi': { 'Guntur': '12980', 'Karet': '12920', 'Karet Kuningan': '12940', 'Karet Semanggi': '12930', 'Kuningan Timur': '12950', 'Menteng Atas': '12960', 'Pasar Manggis': '12970', 'Setiabudi': '12910' },
                'Pasar Minggu': { 'Cilandak Timur': '12560', 'Jati Padang': '12540', 'Kebagusan': '12520', 'Pasar Minggu': '12510', 'Pejaten Barat': '12510', 'Pejaten Timur': '12510', 'Ragunan': '12550' }
            },
            'Kota Jakarta Pusat': {
                'Gambir': { 'Cideng': '10150', 'Duri Pulo': '10140', 'Gambir': '10110', 'Kebon Kelapa': '10120', 'Petojo Selatan': '10160', 'Petojo Utara': '10130' },
                'Tanah Abang': { 'Bendungan Hilir': '10210', 'Gelora': '10270', 'Kampung Bali': '10250', 'Karet Tengsin': '10220', 'Kebon Kacang': '10240', 'Kebon Melati': '10230', 'Petamburan': '10260' },
                'Menteng': { 'Cikini': '10330', 'Gondangdia': '10350', 'Kebon Sirih': '10340', 'Menteng': '10310', 'Pegangsaan': '10320' },
                'Kemayoran': { 'Cempaka Baru': '10640', 'Gunung Sahari Selatan': '10610', 'Harapan Mulya': '10650', 'Kebon Kosong': '10630', 'Kemayoran': '10620', 'Serdang': '10650' }
            },
            'Kota Jakarta Barat': {
                'Grogol Petamburan': { 'Grogol': '11450', 'Jelambar': '11460', 'Jelambar Baru': '11460', 'Tanjung Duren Selatan': '11470', 'Tanjung Duren Utara': '11470', 'Tomang': '11440', 'Wijaya Kusuma': '11460' },
                'Kebon Jeruk': { 'Duri Kepa': '11510', 'Kebon Jeruk': '11530', 'Kedoya Selatan': '11520', 'Kedoya Utara': '11520', 'Kelapa Dua': '11550', 'Sukabumi Selatan': '11560', 'Sukabumi Utara': '11540' },
                'Kembangan': { 'Joglo': '11640', 'Kembangan Selatan': '11610', 'Kembangan Utara': '11610', 'Meruya Selatan': '11650', 'Meruya Utara': '11620', 'Srengseng': '11630' }
            },
            'Kota Jakarta Timur': {
                'Jatinegara': { 'Bali Mester': '13310', 'Bidara Cina': '13330', 'Cipinang Besar Selatan': '13340', 'Cipinang Besar Utara': '13340', 'Cipinang Cempedak': '13340', 'Cipinang Muara': '13420', 'Kampung Melayu': '13320' },
                'Duren Sawit': { 'Duren Sawit': '13440', 'Klender': '13470', 'Malaka Jaya': '13460', 'Malaka Sari': '13460', 'Pondok Bambu': '13430', 'Pondok Kelapa': '13450', 'Pondok Kopi': '13460' }
            },
            'Kota Jakarta Utara': {
                'Kelapa Gading': { 'Kelapa Gading Barat': '14240', 'Kelapa Gading Timur': '14240', 'Pegangsaan Dua': '14250' },
                'Penjaringan': { 'Kamal Muara': '14470', 'Kapuk Muara': '14460', 'Pejagalan': '14450', 'Penjaringan': '14440', 'Pluit': '14450' },
                'Tanjung Priok': { 'Kebon Bawang': '14320', 'Papanggo': '14340', 'Sungai Bambu': '14330', 'Sunter Agung': '14350', 'Sunter Jaya': '14360', 'Tanjung Priok': '14310', 'Warakas': '14370' }
            }
        },
        'Jawa Barat': {
            'Kota Bandung': {
                'Coblong': { 'Dago': '40135', 'Lebak Siliwangi': '40132', 'Sadang Serang': '40133', 'Sekeloa': '40134', 'Cipaganti': '40131' },
                'Sukajadi': { 'Pasteur': '40161', 'Sukajadi': '40162', 'Sukawarna': '40164', 'Gegerkalongan': '40153' },
                'Sumur Bandung': { 'Braga': '40111', 'Kebon Pisang': '40112', 'Merdeka': '40113' },
                'Bandung Wetan': { 'Citarum': '40115', 'Tamansari': '40116', 'Cihapit': '40114' },
                'Cicendo': { 'Pasirkaliki': '40171', 'Arjuna': '40172', 'Pajajaran': '40173' },
                'Lengkong': { 'Malabar': '40262', 'Cijagra': '40265', 'Burangrang': '40262' }
            },
            'Kota Bekasi': {
                'Bekasi Selatan': { 'Pekayon Jaya': '17148', 'Kayuringin Jaya': '17144', 'Jaka Setia': '17147', 'Marga Jaya': '17141' },
                'Bekasi Timur': { 'Aren Jaya': '17111', 'Bekasi Jaya': '17112', 'Duren Jaya': '17111', 'Margahayu': '17113' },
                'Bekasi Barat': { 'Kranji': '17135', 'Kota Baru': '17133', 'Bintara': '17134', 'Bintara Jaya': '17136' },
                'Bekasi Utara': { 'Harapan Baru': '17123', 'Harapan Jaya': '17124', 'Teluk Pucung': '17121', 'Perwira': '17122' },
                'Rawalumbu': { 'Bojong Rawalumbu': '17116', 'Pengasinan': '17115', 'Sepanjang Jaya': '17114' }
            },
            'Kabupaten Bekasi': {
                'Cikarang Pusat': { 'Jayamukti': '17530', 'Sukamahi': '17530', 'Pasirranji': '17530' },
                'Cikarang Selatan': { 'Cibatu': '17530', 'Pasirsari': '17530', 'Sukaresmi': '17530', 'Serang': '17530' },
                'Cikarang Utara': { 'Waluya': '17530', 'Simpangan': '17530', 'Mekarmukti': '17530' },
                'Tambun Selatan': { 'Jatimulya': '17510', 'Tambun': '17510', 'Tridayajaya': '17510', 'Mekarsari': '17510' }
            },
            'Kota Depok': {
                'Beji': { 'Beji': '16421', 'Kukusan': '16425', 'Pondok Cina': '16424', 'Tanah Baru': '16426' },
                'Pancoran Mas': { 'Depok': '16431', 'Mampang': '16433', 'Depok Jaya': '16432', 'Rangkapan Jaya': '16435' },
                'Cimanggis': { 'Tugu': '16451', 'Pasir Gunung Selatan': '16451', 'Mekarsari': '16452' }
            },
            'Kota Bogor': {
                'Bogor Tengah': { 'Babakan': '16128', 'Paledang': '16122', 'Sempur': '16129', 'Kebon Kelapa': '16125' },
                'Bogor Timur': { 'Baranangsiang': '16143', 'Katulampa': '16144', 'Tajur': '16141' },
                'Bogor Selatan': { 'Batutulis': '16133', 'Lawanggintung': '16134', 'Empang': '16132' }
            }
        },
        'Banten': {
            'Kota Tangerang': {
                'Tangerang': { 'Cikokol': '15117', 'Babakan': '15118', 'Buaran Indah': '15119', 'Tanah Tinggi': '15119' },
                'Cipondoh': { 'Cipondoh': '15148', 'Petir': '15147', 'Poris Plawad': '15141', 'Poris Indah': '15141' },
                'Karawaci': { 'Karawaci': '15115', 'Cimone': '15114', 'Bugel': '15113' }
            },
            'Kota Tangerang Selatan': {
                'Serpong': { 'Rawa Buntu': '15318', 'Serpong': '15311', 'Lengkong Gudang': '15321', 'Lengkong Karya': '15320' },
                'Serpong Utara': { 'Pakulonan': '15325', 'Jelupang': '15323', 'Pondok Jagung': '15326' },
                'Pondok Aren': { 'Pondok Aren': '15224', 'Bintaro': '15221', 'Jurang Mangu Barat': '15223', 'Pondok Betung': '15221' }
            }
        },
        'Jawa Tengah': {
            'Kota Semarang': {
                'Semarang Tengah': { 'Pekunden': '50134', 'Sekyu': '50133', 'Bangunharjo': '50139', 'Pandansari': '50139' },
                'Semarang Selatan': { 'Peterongan': '50242', 'Randusari': '50244', 'Pleburan': '50241', 'Lamper Kidul': '50249' },
                'Gajahmungkur': { 'Bendan Ngisor': '50233', 'Petompon': '50237', 'Sampangan': '50236', 'Gajahmungkur': '50232' }
            },
            'Kota Surakarta': {
                'Banjarsari': { 'Kadipiro': '57136', 'Nusukan': '57135', 'Timuran': '57131', 'Manahan': '57139' },
                'Jebres': { 'Jebres': '57126', 'Purwodiningratan': '57128', 'Mojosongo': '57127' },
                'Laweyan': { 'Purwosari': '57142', 'Kerten': '57143', 'Sondakan': '57147' }
            }
        },
        'DI Yogyakarta': {
            'Kota Yogyakarta': {
                'Danurejan': { 'Bausasran': '55211', 'Tegal Panggung': '55212', 'Suryatmajan': '55213' },
                'Gondomanan': { 'Ngupasan': '55122', 'Prawirodirjan': '55121' },
                'Kraton': { 'Panembahan': '55131', 'Kadipaten': '55132', 'Patehan': '55133' },
                'Umbulharjo': { 'Pandeyan': '55161', 'Sorosutan': '55162', 'Giwangan': '55163', 'Warungboto': '55164', 'Tahunan': '55167' }
            },
            'Kabupaten Sleman': {
                'Depok': { 'Caturtunggal': '55281', 'Maguwoharjo': '55282', 'Condongcatur': '55283' },
                'Mlati': { 'Sinduadi': '55284', 'Sendangadi': '55285', 'Tlogoadi': '55286' }
            }
        },
        'Jawa Timur': {
            'Kota Surabaya': {
                'Tegalsari': { 'Kedungdoro': '60261', 'Tegalsari': '60262', 'Wonorejo': '60263', 'Dr. Soetomo': '60264', 'Keputran': '60265' },
                'Genteng': { 'Embong Kaliasin': '60271', 'Ketabang': '60272', 'Kapasari': '60273', 'Peneleh': '60274', 'Genteng': '60275' },
                'Gubeng': { 'Gubeng': '60281', 'Mojo': '60285', 'Airlangga': '60286', 'Baratajaya': '60284' },
                'Wonokromo': { 'Darmo': '60241', 'Sawunggaling': '60242', 'Wonokromo': '60243', 'Jagir': '60244' }
            },
            'Kota Malang': {
                'Klojen': { 'Klojen': '65111', 'Rampal Celaket': '65111', 'Samaan': '65112', 'Penanggungan': '65113' },
                'Lowokwaru': { 'Lowokwaru': '65141', 'Jatimulyo': '65141', 'Ketawanggede': '65145', 'Dinoyo': '65144' }
            }
        },
        'Bali': {
            'Kota Denpasar': {
                'Denpasar Barat': { 'Pemecutan': '80119', 'Dauh Puri': '80113', 'Padangsambian': '80118' },
                'Denpasar Selatan': { 'Sanur': '80228', 'Renon': '80226', 'Panjer': '80225', 'Sesetan': '80223' },
                'Denpasar Utara': { 'Peguyangan': '80115', 'Tonja': '80239', 'Ubung': '80116' }
            },
            'Kabupaten Badung': {
                'Kuta': { 'Kuta': '80361', 'Legian': '80361', 'Seminyak': '80361' },
                'Kuta Utara': { 'Tibubeneng': '80361', 'Kerobokan': '80361', 'Canggu': '80361' },
                'Kuta Selatan': { 'Jimbaran': '80361', 'Benoa': '80361', 'Ungasan': '80361', 'Pecatu': '80361' }
            }
        }
    };

    // Standard SIAPKerja Domicile (used for autofill)
    const SIAPKERJA_DOMICILE = {
        province: 'Jawa Barat',
        city: 'Kota Bandung',
        district: 'Coblong',
        village: 'Dago',
        address: 'Jl. Ir. H. Juanda No. 120, RT 03/RW 01',
        postal_code: '40135'
    };

    window.PKI_LOCATION_DATABASE = PKI_LOCATION_DATABASE;
    window.PKI_SIAPKERJA_DOMICILE = SIAPKERJA_DOMICILE;

    let currentLocLevel = 1;
    let selProv = '';
    let selCity = '';
    let selDistrict = '';
    let selVillage = '';

    // Initialize all form features
    function initPkiForm() {
        const forms = [
            document.getElementById('formEmployerProfile'),
            document.getElementById('registrationForm'),
            document.getElementById('formPkiRegister')
        ].filter(Boolean);
        if (forms.length === 0) {
            const fallback = document.querySelector('form[action="dashboard.php"], form#registrationForm, form#formEmployerProfile');
            if (fallback) forms.push(fallback);
        }
        if (forms.length === 0) return;

        forms.forEach(f => f.setAttribute('novalidate', 'novalidate'));
        initLocationSelector();
        initProfessionSelector();
        initDomicileCheckbox();
        initAddressListeners();
        initPhoneWhatsappSync();
        initNpwpStatusSync();
        initSocialMediaRepeater();
        initUploadDragAndDrop();
        forms.forEach(f => initValidation(f));
        updateMapPreview();
    }

    // ─── 1b. Searchable Profession Selector (Matching Karirhub Look) ───
    const PERMIT_DOC_REQUIREMENTS = {
        'Praktik Dokter Umum': 'Surat Tanda Registrasi (STR) Dokter dan Surat Izin Praktik (SIP) sesuai tempat praktik.',
        'Praktik Dokter Gigi': 'STR Dokter Gigi dan SIP Dokter Gigi sesuai tempat praktik.',
        'Praktik Bidan': 'STR Bidan dan SIP Bidan sesuai tempat praktik.',
        'Praktik Keperawatan Mandiri': 'STR Perawat dan SIP Perawat sesuai tempat praktik.',
        'Praktik Psikolog': 'STR dan izin praktik psikolog sesuai lingkup layanan, seperti SILP atau SIP Psikolog Klinis.',
        'Layanan Fisioterapi': 'STR Fisioterapis dan SIP Fisioterapis sesuai tempat praktik.',
        'Praktik Terapis Wicara': 'STR dan SIP tenaga profesional yang memberikan terapi wicara.',
        'Layanan Akupunktur': 'NIB dan dokumen izin atau pendaftaran praktik sesuai jalur layanan.',
        'Layanan Terapi Tumbuh Kembang Anak': 'NIB, bukti kompetensi penanggung jawab terapi, serta izin praktik tenaga kesehatan/psikolog sesuai layanan.',
        'Layanan Konsultasi Gizi/Nutrisionis': 'Bukti kualifikasi gizi/nutrisi serta STR dan SIP apabila menyelenggarakan praktik profesi kesehatan.',

        'Praktik Notaris / PPAT': 'SK Pengangkatan Notaris/PPAT dan Berita Acara Sumpah/Janji Jabatan.',
        'Praktik Advokat/Pengacara / Konsultan Hukum': 'Kartu Tanda Anggota organisasi advokat dan Berita Acara Sumpah Advokat.',
        'Konsultan Pajak': 'Izin Praktik Konsultan Pajak, kartu izin yang berlaku, dan Sertifikat Konsultan Pajak.',
        'Jasa Akuntansi': 'Izin Akuntan Berpraktik atau dokumen izin Kantor Jasa Akuntansi apabila layanan yang diberikan mempersyaratkannya, serta bukti kompetensi.',
        'Konsultan Manajemen': 'Portofolio konsultasi/proposal layanan dan sertifikat keahlian atau riwayat pengalaman.',
        'Jasa Administrasi dan Pengurusan Dokumen': 'Contoh layanan administrasi dengan data pribadi disamarkan, bukti pengalaman, dan dokumen perizinan usaha apabila dipersyaratkan.',
        'Jasa Penerjemah': 'Portofolio terjemahan, sertifikat keahlian bahasa, serta SK/Berita Acara Sumpah apabila menawarkan jasa penerjemah tersumpah.',

        'Praktik Arsitek': 'Surat Tanda Registrasi Arsitek (STRA), lisensi arsitek apabila diwajibkan sesuai lingkup praktik, dan portofolio desain.',
        'Jasa Desain Interior': 'Izin praktik apabila dipersyaratkan, portofolio desain interior/gambar kerja, dan bukti kompetensi atau pengalaman.',
        'Jasa Drafter': 'STRA apabila dipersyaratkan sesuai layanan, portofolio gambar teknik/CAD, serta sertifikat pelatihan atau pengalaman drafter.',
        'Jasa Perbaikan/Bengkel Teknik (Instalasi Listrik & Elektronik)': 'SIUP, Surat Keterangan Usaha dari Kelurahan, portofolio/perintah kerja servis, dan sertifikat keterampilan teknis jika ada.',

        'Bimbingan Belajar': 'Contoh jadwal/silabus pembelajaran, bukti kualifikasi pengajar, dan izin lembaga apabila diwajibkan.',
        'Kursus Bahasa': 'Sertifikat kemampuan bahasa/kualifikasi pengajar dan silabus kursus.',
        'Kursus Musik': 'Sertifikat atau portofolio keterampilan musik serta silabus/jadwal pembelajaran.',
        'Kursus Tari': 'Portofolio kegiatan tari dan bukti pelatihan/pengalaman instruktur.',

        'Studio Foto dan Video': 'Portofolio produksi foto/video dan contoh katalog/layanan.',
        'Jasa Fotografi/Videografi': 'Portofolio foto/video dan contoh hasil pekerjaan.',
        'Jasa Dekorasi Acara': 'Portofolio dekorasi acara dan katalog paket jasa.',
        'Jasa Penjahit/Atelier': 'Portofolio hasil jahitan dan katalog layanan/produk.',
        'Jasa Grooming Hewan': 'Sertifikat pelatihan perawatan hewan jika ada dan contoh layanan.',
        'Jasa Desain Grafis': 'Portofolio desain grafis, contoh file hasil kerja atau tautan portofolio, dan bukti kompetensi jika ada.',
        'Jasa Florist/Dekorasi Bunga': 'SIUP dan katalog produk/jasa.',

        'Konten Kreator/Digital Creator': 'Dasbor akun, tautan akun/kanal konten yang dikelola, tangkapan layar hasil karya, serta portofolio atau media kit.',
        'Jasa Editing Video / Transfer Video': 'Portofolio video sebelum/sesudah editing dan contoh hasil akhir.',
        'Jasa Live Streaming / Host Live Streaming': 'Portofolio/tangkapan layar siaran, tautan kanal, dan contoh layanan.',
        'Studio Podcast': 'Tautan episode/portofolio dan katalog jasa produksi.',

        'Barbershop': 'Izin Usaha, portofolio hasil potong, dan sertifikat kursus jika ada.',
        'Makeup Artist': 'Portofolio hasil rias dengan izin model dan sertifikat pelatihan tata rias jika ada.',

        'Jasa Laundry': 'Daftar layanan dan tarif.',
        'Jasa Servis AC': 'Sertifikat pelatihan teknisi jika ada serta bukti pengalaman/hasil servis.'
    };

    function getPermitDocHelperHtml(profession) {
        const p = (profession || '').trim();
        if (p && PERMIT_DOC_REQUIREMENTS[p]) {
            return '<div class="pki-permit-doc-helper-wrap">' +
                '<div class="pki-permit-doc-title" style="font-weight:600; color:#334155; margin-bottom:2px;">Dokumen yang perlu diunggah:</div>' +
                '<div class="pki-permit-doc-requirement" style="color:#475569; margin-bottom:4px; line-height:1.45;">' + PERMIT_DOC_REQUIREMENTS[p] + '</div>' +
                '<div class="pki-permit-doc-format" style="color:#64748b; font-size:11px;">Format PDF/JPG, maksimal 2 MB per file.</div>' +
                '</div>';
        }
        return '<div class="pki-permit-doc-helper-wrap">' +
            '<div class="pki-permit-doc-prompt" style="color:#64748b; line-height:1.45; margin-bottom:3px;">Pilih Jenis Usaha Individu terlebih dahulu untuk melihat dokumen yang perlu diunggah.</div>' +
            '<div class="pki-permit-doc-format" style="color:#64748b; font-size:11px;">Format PDF/JPG, maksimal 2 MB per file.</div>' +
            '</div>';
    }

    window.PERMIT_DOC_REQUIREMENTS = PERMIT_DOC_REQUIREMENTS;
    window.getPermitDocHelperHtml = getPermitDocHelperHtml;

    function updatePermitDocHelper(profession) {
        const helper = document.getElementById('helper_permit_document');
        if (!helper) return;
        helper.classList.remove('error');
        helper.innerHTML = getPermitDocHelperHtml(profession);
    }

    function initProfessionSelector() {
        const trigger = document.getElementById('pki_profession_trigger');
        const dropdown = document.getElementById('pki_profession_dropdown');
        const searchInput = document.getElementById('pki_profession_search');
        const hiddenInput = document.getElementById('pki_profession');
        const display = document.getElementById('pki_profession_display');
        const optionsList = document.getElementById('pki_profession_options');
        const noResults = document.getElementById('pki_profession_no_results');

        if (!trigger || !dropdown) return;

        // Initialize helper on load
        if (hiddenInput && hiddenInput.value) {
            updatePermitDocHelper(hiddenInput.value);
        } else {
            updatePermitDocHelper('');
        }

        function openDropdown() {
            dropdown.style.display = 'block';
            trigger.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            if (searchInput) {
                searchInput.value = '';
                filterOptions('');
                setTimeout(() => searchInput.focus(), 40);
            }
        }

        function closeDropdown() {
            dropdown.style.display = 'none';
            trigger.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (dropdown.style.display === 'block') {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                e.preventDefault();
                openDropdown();
            }
        });

        dropdown.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        document.addEventListener('click', function (e) {
            if (!trigger.contains(e.target) && !dropdown.contains(e.target)) {
                closeDropdown();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && dropdown.style.display === 'block') {
                closeDropdown();
                trigger.focus();
            }
        });

        function filterOptions(query) {
            const q = query.toLowerCase().trim();
            if (!optionsList) return;
            const groups = optionsList.querySelectorAll('.pki-select-group');
            let totalVisible = 0;

            groups.forEach(group => {
                const options = group.querySelectorAll('.pki-select-option');
                let groupVisible = 0;

                options.forEach(opt => {
                    const val = (opt.getAttribute('data-value') || opt.textContent).toLowerCase();
                    if (!q || val.includes(q)) {
                        opt.style.display = 'flex';
                        groupVisible++;
                        totalVisible++;
                    } else {
                        opt.style.display = 'none';
                    }
                });

                if (groupVisible > 0) {
                    group.style.display = 'block';
                } else {
                    group.style.display = 'none';
                }
            });

            if (noResults) {
                noResults.style.display = totalVisible === 0 ? 'block' : 'none';
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                filterOptions(this.value);
            });
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const firstOpt = optionsList.querySelector('.pki-select-option:not([style*="display: none"])');
                    if (firstOpt) {
                        firstOpt.click();
                    }
                }
            });
        }

        if (optionsList) {
            optionsList.addEventListener('click', function (e) {
                const opt = e.target.closest('.pki-select-option');
                if (!opt) return;
                const val = opt.getAttribute('data-value') || opt.textContent.trim();

                if (hiddenInput) {
                    hiddenInput.value = val;
                }
                if (display) {
                    display.textContent = val;
                    display.classList.remove('is-placeholder');
                }

                optionsList.querySelectorAll('.pki-select-option').forEach(o => {
                    o.classList.remove('selected');
                    const check = o.querySelector('.pki-select-check');
                    if (check) check.remove();
                });
                opt.classList.add('selected');
                if (!opt.querySelector('.pki-select-check')) {
                    const check = document.createElement('i');
                    check.className = 'fa-solid fa-check pki-select-check';
                    opt.appendChild(check);
                }

                clearFieldError('profession');
                updatePermitDocHelper(val);
                closeDropdown();
                trigger.focus();
            });
        }
    }

    // ─── 1. Hierarchical Location Selector ───
    function initLocationSelector() {
        const locInput = document.getElementById('pki_hierarchicalLocationInput');
        const locDropdown = document.getElementById('pki_hierarchicalLocDropdown');
        const locDisplay = document.getElementById('pki_locDisplayValue');
        const locCaret = document.getElementById('pki_locCaret');
        const breadcrumbs = document.getElementById('pki_locBreadcrumbs');
        const optionsList = document.getElementById('pki_locOptionsList');

        if (!locInput || !locDropdown) return;

        selProv = document.getElementById('pki_hiddenProvince')?.value || '';
        selCity = document.getElementById('pki_hiddenCity')?.value || '';
        selDistrict = document.getElementById('pki_hiddenDistrict')?.value || '';
        selVillage = document.getElementById('pki_hiddenVillage')?.value || '';

        locInput.addEventListener('click', function (e) {
            e.stopPropagation();
            if (locDropdown.style.display === 'block') {
                closeDropdown();
            } else {
                openDropdown();
            }
        });

        locDropdown.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        document.addEventListener('click', function (e) {
            if (!locInput.contains(e.target) && !locDropdown.contains(e.target)) {
                closeDropdown();
            }
        });

        function openDropdown() {
            locDropdown.style.display = 'block';
            if (locCaret) {
                locCaret.classList.remove('fa-chevron-down');
                locCaret.classList.add('fa-chevron-up');
            }

            if (selProv && selCity && selDistrict && selVillage && PKI_LOCATION_DATABASE[selProv]?.[selCity]?.[selDistrict]) {
                currentLocLevel = 4;
            } else if (selProv && selCity && selDistrict && PKI_LOCATION_DATABASE[selProv]?.[selCity]?.[selDistrict]) {
                currentLocLevel = 4;
            } else if (selProv && selCity && PKI_LOCATION_DATABASE[selProv]?.[selCity]) {
                currentLocLevel = 3;
            } else if (selProv && PKI_LOCATION_DATABASE[selProv]) {
                currentLocLevel = 2;
            } else {
                currentLocLevel = 1;
            }

            renderView();
        }

        function closeDropdown() {
            locDropdown.style.display = 'none';
            if (locCaret) {
                locCaret.classList.remove('fa-chevron-up');
                locCaret.classList.add('fa-chevron-down');
            }
        }

        function renderView() {
            renderBreadcrumbs();
            renderOptions();
        }

        function renderBreadcrumbs() {
            if (!breadcrumbs) return;
            let html = '';
            if (currentLocLevel === 1) {
                html += `<span class="crumb-btn active" onclick="pkiSwitchLevel(1)">Pilih Provinsi</span>`;
            } else if (currentLocLevel === 2) {
                html += `<span class="crumb-btn" onclick="pkiSwitchLevel(1)">${selProv}</span> › `;
                html += `<span class="crumb-btn active" onclick="pkiSwitchLevel(2)">Pilih Kabupaten / Kota</span>`;
            } else if (currentLocLevel === 3) {
                html += `<span class="crumb-btn" onclick="pkiSwitchLevel(1)">${selProv}</span> › `;
                html += `<span class="crumb-btn" onclick="pkiSwitchLevel(2)">${selCity}</span> › `;
                html += `<span class="crumb-btn active" onclick="pkiSwitchLevel(3)">Pilih Kecamatan</span>`;
            } else if (currentLocLevel === 4) {
                html += `<span class="crumb-btn" onclick="pkiSwitchLevel(1)">${selProv}</span> › `;
                html += `<span class="crumb-btn" onclick="pkiSwitchLevel(2)">${selCity}</span> › `;
                html += `<span class="crumb-btn" onclick="pkiSwitchLevel(3)">${selDistrict}</span> › `;
                html += `<span class="crumb-btn active" onclick="pkiSwitchLevel(4)">Pilih Kelurahan / Desa</span>`;
            }
            breadcrumbs.innerHTML = html;
        }

        function renderOptions() {
            if (!optionsList) return;
            let html = '';

            if (currentLocLevel === 1) {
                const provinces = Object.keys(PKI_LOCATION_DATABASE);
                provinces.forEach(prov => {
                    const isSel = prov === selProv;
                    html += `
                        <div class="loc-opt-row ${isSel ? 'selected' : ''}" onclick="pkiSelectProv('${prov}')">
                            <div style="display:flex; align-items:center;">
                                <span class="radio-bullet"></span>
                                <span>${prov}</span>
                            </div>
                            ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                        </div>
                    `;
                });
            } else if (currentLocLevel === 2) {
                const cities = Object.keys(PKI_LOCATION_DATABASE[selProv] || {});
                cities.forEach(city => {
                    const isSel = city === selCity;
                    html += `
                        <div class="loc-opt-row ${isSel ? 'selected' : ''}" onclick="pkiSelectCity('${city}')">
                            <div style="display:flex; align-items:center;">
                                <span class="radio-bullet"></span>
                                <span>${city}</span>
                            </div>
                            ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                        </div>
                    `;
                });
            } else if (currentLocLevel === 3) {
                const districts = Object.keys(PKI_LOCATION_DATABASE[selProv]?.[selCity] || {});
                districts.forEach(dist => {
                    const isSel = dist === selDistrict;
                    html += `
                        <div class="loc-opt-row ${isSel ? 'selected' : ''}" onclick="pkiSelectDistrict('${dist}')">
                            <div style="display:flex; align-items:center;">
                                <span class="radio-bullet"></span>
                                <span>${dist}</span>
                            </div>
                            ${isSel ? '<span class="sel-tag">✓ Dipilih</span>' : ''}
                        </div>
                    `;
                });
            } else if (currentLocLevel === 4) {
                const villagesObj = PKI_LOCATION_DATABASE[selProv]?.[selCity]?.[selDistrict] || {};
                const villages = Object.keys(villagesObj);
                villages.forEach(vill => {
                    const isSel = vill === selVillage;
                    const postal = villagesObj[vill];
                    html += `
                        <div class="loc-opt-row ${isSel ? 'selected' : ''}" onclick="pkiSelectVillage('${vill}', '${postal}')">
                            <div style="display:flex; align-items:center;">
                                <span class="radio-bullet"></span>
                                <span>${vill}</span>
                            </div>
                            <span style="font-size:12px; color:#64748b;">${postal}</span>
                        </div>
                    `;
                });
            }
            optionsList.innerHTML = html;
        }

        window.pkiSwitchLevel = function (lvl) {
            currentLocLevel = lvl;
            renderView();
        };

        window.pkiSelectProv = function (prov) {
            if (selProv !== prov) {
                selProv = prov;
                selCity = '';
                selDistrict = '';
                selVillage = '';
                updateHiddenInputs();
            }
            currentLocLevel = 2;
            renderView();
        };

        window.pkiSelectCity = function (city) {
            if (selCity !== city) {
                selCity = city;
                selDistrict = '';
                selVillage = '';
                updateHiddenInputs();
            }
            currentLocLevel = 3;
            renderView();
        };

        window.pkiSelectDistrict = function (dist) {
            if (selDistrict !== dist) {
                selDistrict = dist;
                selVillage = '';
                updateHiddenInputs();
            }
            currentLocLevel = 4;
            renderView();
        };

        window.pkiSelectVillage = function (vill, postal) {
            selVillage = vill;
            updateHiddenInputs();

            if (locDisplay) {
                locDisplay.textContent = `${selVillage}, ${selDistrict}, ${selCity}, ${selProv}`;
                locDisplay.classList.remove('placeholder');
            }

            // Populate Postal Code
            const postalSelect = document.getElementById('pki_selectPostalCode');
            if (postalSelect) {
                postalSelect.innerHTML = `<option value="${postal}" selected>${postal}</option>`;
            }

            closeDropdown();
            clearFieldError('location');
            clearFieldError('postal_code');
            updateMapPreview();
        };

        function updateHiddenInputs() {
            setInputValue('pki_hiddenProvince', selProv);
            setInputValue('pki_hiddenCity', selCity);
            setInputValue('pki_hiddenDistrict', selDistrict);
            setInputValue('pki_hiddenVillage', selVillage);
            setInputValue('pki_hiddenDomicileCityId', selCity);
        }
    }

    // ─── 2. SIAPKerja Domicile Checkbox Autofill ───
    function initDomicileCheckbox() {
        const cb = document.getElementById('pki_cbSameLocation');
        if (!cb) return;

        function syncDomicileFields() {
            if (cb.checked) {
                // Fill fields with SIAPKerja domicile data
                selProv = SIAPKERJA_DOMICILE.province;
                selCity = SIAPKERJA_DOMICILE.city;
                selDistrict = SIAPKERJA_DOMICILE.district;
                selVillage = SIAPKERJA_DOMICILE.village;

                setInputValue('pki_hiddenProvince', selProv);
                setInputValue('pki_hiddenCity', selCity);
                setInputValue('pki_hiddenDistrict', selDistrict);
                setInputValue('pki_hiddenVillage', selVillage);
                setInputValue('pki_hiddenDomicileCityId', selCity);

                const locDisplay = document.getElementById('pki_locDisplayValue');
                if (locDisplay) {
                    locDisplay.textContent = `${selVillage}, ${selDistrict}, ${selCity}, ${selProv}`;
                    locDisplay.classList.remove('placeholder');
                }

                const addrInput = document.getElementById('pki_inputAddress');
                if (addrInput) {
                    addrInput.value = SIAPKERJA_DOMICILE.address;
                }

                const postalSelect = document.getElementById('pki_selectPostalCode');
                if (postalSelect) {
                    postalSelect.innerHTML = `<option value="${SIAPKERJA_DOMICILE.postal_code}" selected>${SIAPKERJA_DOMICILE.postal_code}</option>`;
                    postalSelect.value = SIAPKERJA_DOMICILE.postal_code;
                }

                // Clear any error states on these fields
                clearFieldError('location');
                clearFieldError('address');
                clearFieldError('postal_code');

                // Update live map
                updateMapPreview();
            } else {
                // When unchecked, clear all prefilled fields so user can fill them manually from scratch
                selProv = '';
                selCity = '';
                selDistrict = '';
                selVillage = '';

                setInputValue('pki_hiddenProvince', '');
                setInputValue('pki_hiddenCity', '');
                setInputValue('pki_hiddenDistrict', '');
                setInputValue('pki_hiddenVillage', '');
                setInputValue('pki_hiddenDomicileCityId', '');

                const locDisplay = document.getElementById('pki_locDisplayValue');
                if (locDisplay) {
                    locDisplay.textContent = 'Pilih lokasi tempat usaha / kegiatan';
                    locDisplay.classList.add('placeholder');
                }

                const addrInput = document.getElementById('pki_inputAddress');
                if (addrInput) {
                    addrInput.value = '';
                }

                const addrDetailInput = document.getElementById('pki_inputAddressDetail');
                if (addrDetailInput) {
                    addrDetailInput.value = '';
                }

                const postalSelect = document.getElementById('pki_selectPostalCode');
                if (postalSelect) {
                    postalSelect.innerHTML = '<option value="">Pilih kode pos</option>';
                    postalSelect.value = '';
                }

                currentLocLevel = 1;
                clearFieldError('location');
                clearFieldError('address');
                clearFieldError('postal_code');
                updateMapPreview();
            }
        }

        cb.addEventListener('change', syncDomicileFields);
        cb.addEventListener('click', function () {
            setTimeout(syncDomicileFields, 0);
        });

        // Add real-time input digit constraints for identity fields
        const enforceDigitsOnly = function (el, maxLen) {
            if (!el) return;
            el.setAttribute('inputmode', 'numeric');
            ['input', 'paste', 'keyup', 'change', 'blur'].forEach(ev => {
                el.addEventListener(ev, function () {
                    this.value = this.value.replace(/\D/g, '').slice(0, maxLen);
                });
            });
            el.addEventListener('keydown', function (e) {
                if (
                    ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(e.key) ||
                    (e.ctrlKey === true || e.metaKey === true)
                ) {
                    return;
                }
                if (e.key < '0' || e.key > '9') {
                    e.preventDefault();
                }
            });
        };

        enforceDigitsOnly(document.getElementById('pki_nik'), 16);
        enforceDigitsOnly(document.getElementById('pki_phone'), 15);
        enforceDigitsOnly(document.getElementById('pki_whatsapp'), 15);
    }

    // ─── 2b. Sync Phone with WhatsApp Checkbox ───
    function initPhoneWhatsappSync() {
        const phoneInput = document.getElementById('pki_phone');
        const waInput = document.getElementById('pki_whatsapp');
        const sameCheckbox = document.getElementById('pki_same_as_phone');

        if (!phoneInput || !waInput || !sameCheckbox) return;

        // Auto-check if both fields have identical non-empty value initially
        if (phoneInput.value && phoneInput.value === waInput.value) {
            sameCheckbox.checked = true;
        }

        sameCheckbox.addEventListener('change', function () {
            if (this.checked) {
                waInput.value = phoneInput.value;
                clearFieldError('whatsapp');
            }
        });

        // Mirror phone changes if checkbox is checked
        ['input', 'paste', 'change', 'keyup'].forEach(ev => {
            phoneInput.addEventListener(ev, function () {
                if (sameCheckbox.checked) {
                    waInput.value = phoneInput.value;
                    clearFieldError('whatsapp');
                }
            });
        });

        // Uncheck if user edits WhatsApp and value differs
        ['input', 'paste', 'keyup'].forEach(ev => {
            waInput.addEventListener(ev, function () {
                if (waInput.value !== phoneInput.value) {
                    sameCheckbox.checked = false;
                } else if (waInput.value && waInput.value === phoneInput.value) {
                    sameCheckbox.checked = true;
                }
            });
        });
    }

    // ─── 2c. Status NPWP Sync with NIK ───
    function initNpwpStatusSync() {
        const nikInput = document.getElementById('pki_nik');
        const npwpHidden = document.getElementById('pki_npwp');
        const npwpStatus = document.getElementById('pki_npwp_status');

        if (!npwpStatus || !npwpHidden) return;

        function syncNpwp() {
            if (npwpStatus.checked) {
                const nikVal = nikInput?.value?.trim() || '';
                npwpHidden.value = nikVal;
                clearFieldError('npwp');
            } else {
                npwpHidden.value = '';
            }
        }

        npwpStatus.addEventListener('change', syncNpwp);
        if (nikInput) {
            ['input', 'change', 'paste'].forEach(ev => {
                nikInput.addEventListener(ev, function () {
                    if (npwpStatus.checked) {
                        npwpHidden.value = this.value.trim();
                    }
                });
            });
        }

        if (npwpStatus.checked) {
            syncNpwp();
        }
    }

    // ─── 3. Address Listeners for Live Map ───
    function initAddressListeners() {
        const addrInput = document.getElementById('pki_inputAddress');
        if (addrInput) {
            ['input', 'change', 'keyup', 'paste', 'blur'].forEach(ev => {
                addrInput.addEventListener(ev, function () {
                    clearFieldError('address');
                    updateMapPreview();
                });
            });
        }

        const detailInput = document.getElementById('pki_inputAddressDetail');
        if (detailInput) {
            ['input', 'change', 'keyup', 'paste'].forEach(ev => {
                detailInput.addEventListener(ev, function () {
                    updateMapPreview();
                });
            });
        }

        const postalSelect = document.getElementById('pki_selectPostalCode');
        if (postalSelect) {
            postalSelect.addEventListener('change', function () {
                clearFieldError('postal_code');
                updateMapPreview();
            });
        }
    }

    // ─── 4. Live Google Maps Preview ───
    function updateMapPreview() {
        const village = document.getElementById('pki_hiddenVillage')?.value?.trim() || '';
        const district = document.getElementById('pki_hiddenDistrict')?.value?.trim() || '';
        const city = document.getElementById('pki_hiddenCity')?.value?.trim() || '';
        const prov = document.getElementById('pki_hiddenProvince')?.value?.trim() || '';
        const address = document.getElementById('pki_inputAddress')?.value?.trim() || '';

        const placeholder = document.getElementById('pki_mapPlaceholder');
        const mapWrapper = document.getElementById('pki_googleMapWrapper');
        const openLinkWrapper = document.getElementById('pki_mapOpenLinkWrapper');
        const btnOpenMap = document.getElementById('pki_btnOpenMap');
        const gmapIframe = document.getElementById('pki_gmapIframe');

        if (!placeholder || !mapWrapper) return;

        const hasLocation = Boolean(village || district || city || prov);
        const hasAddress = Boolean(address && address.length > 0);

        // Both location and address must be complete to show the map
        if (hasLocation && hasAddress) {
            placeholder.classList.add('is-hidden');
            placeholder.style.display = 'none';
            mapWrapper.classList.add('is-visible');
            mapWrapper.style.display = 'block';

            if (openLinkWrapper) {
                openLinkWrapper.classList.add('is-visible');
                openLinkWrapper.style.display = 'block';
            }

            const queryParts = [address, village, district, city, prov, 'Indonesia'].filter(Boolean);
            const fullQuery = queryParts.join(', ');

            if (btnOpenMap) {
                btnOpenMap.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(fullQuery)}`;
                btnOpenMap.innerHTML = 'Buka di Maps ↗';
            }

            if (gmapIframe) {
                const apiKey = window.PKI_CONFIG?.googleMapsApiKey;
                let embedUrl = '';
                if (apiKey) {
                    embedUrl = `https://www.google.com/maps/embed/v1/place?key=${encodeURIComponent(apiKey)}&q=${encodeURIComponent(fullQuery)}`;
                } else {
                    embedUrl = `https://maps.google.com/maps?q=${encodeURIComponent(fullQuery)}&output=embed`;
                }

                if (gmapIframe.getAttribute('data-last-query') !== fullQuery) {
                    gmapIframe.setAttribute('data-last-query', fullQuery);
                    gmapIframe.src = embedUrl;
                }
            }
        } else {
            placeholder.classList.remove('is-hidden');
            placeholder.style.display = 'flex';
            mapWrapper.classList.remove('is-visible');
            mapWrapper.style.display = 'none';

            if (openLinkWrapper) {
                openLinkWrapper.classList.remove('is-visible');
                openLinkWrapper.style.display = 'none';
            }

            if (gmapIframe) {
                gmapIframe.src = 'about:blank';
                gmapIframe.removeAttribute('data-last-query');
            }
        }
    }

    function formatUploadSize(bytes) {
        const b = Number(bytes) || 0;
        if (b >= 1024 * 1024) {
            return `${(b / (1024 * 1024)).toFixed(2)} MB`;
        }
        return `${(b / 1024).toFixed(2)} KB`;
    }

    function setUploadSizeInfo(fieldId, bytes) {
        const infoEl = document.getElementById(`helper_${fieldId}_size`);
        if (!infoEl) return;
        if (!bytes || bytes <= 0) {
            infoEl.textContent = '';
            infoEl.style.display = 'none';
            return;
        }
        infoEl.textContent = `Ukuran file yang di upload: ${formatUploadSize(bytes)}`;
        infoEl.style.display = 'block';
    }

    // ─── 5. Custom Upload Box Handlers ───
    window.pkiTriggerUpload = function (fieldId) {
        const fileInput = document.getElementById(`pki_input_${fieldId}`);
        if (fileInput) fileInput.click();
    };

    window.pkiHandleFileChange = function (input, fieldId) {
        if (!input.files || input.files.length === 0) return;
        const file = input.files[0];
        const textBox = document.getElementById(`pki_text_${fieldId}`);
        const btn = document.getElementById(`pki_btn_${fieldId}`);
        const box = document.getElementById(`pki_box_${fieldId}`);

        if (fieldId === 'permit_document') {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!['pdf', 'docx'].includes(ext) || file.size > 2 * 1024 * 1024) {
                input.value = '';
                setFieldError('permit_document', 'Format PDF, Docx. Ukuran Maksimal 2MB.');
                setUploadSizeInfo(fieldId, 0);
                return;
            }
            clearFieldError('permit_document');
        } else if (fieldId === 'workplace_photo' || fieldId === 'business_logo') {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!['png', 'jpg', 'jpeg'].includes(ext) || file.size > 2 * 1024 * 1024) {
                input.value = '';
                setFieldError(fieldId, 'Format PNG, JPG. Ukuran Maksimal 2MB.');
                setUploadSizeInfo(fieldId, 0);
                return;
            }
            clearFieldError(fieldId);
        }

        if (textBox) {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            const iconClass = fieldId !== 'permit_document'
                ? 'fa-image'
                : (ext === 'docx' ? 'fa-file-word' : 'fa-file-pdf');
            const iconColor = fieldId !== 'permit_document'
                ? '#0284c7'
                : (ext === 'docx' ? '#2563eb' : '#ef4444');
            textBox.innerHTML = `<i class="fa-solid ${iconClass}" style="color:${iconColor}; flex-shrink:0;"></i> <span class="pki-upload-filename">${file.name}</span>`;
            textBox.classList.add('has-file');
        }

        if (btn) btn.textContent = 'Ganti';

        // Add clear button if not already present
        if (box) {
            let clearBtn = box.querySelector('.pki-upload-clear-btn');
            if (!clearBtn) {
                const btnContainer = box.querySelector('div');
                if (btnContainer) {
                    clearBtn = document.createElement('button');
                    clearBtn.type = 'button';
                    clearBtn.className = 'pki-upload-clear-btn';
                    clearBtn.title = 'Hapus';
                    clearBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                    clearBtn.onclick = function (e) { pkiClearUpload(e, fieldId); };
                    btnContainer.insertBefore(clearBtn, btnContainer.firstChild);
                }
            }
        }

        clearFieldError(fieldId);
        setUploadSizeInfo(fieldId, file.size);
    };

    window.pkiClearUpload = function (event, fieldId) {
        if (event) event.stopPropagation();
        const input = document.getElementById(`pki_input_${fieldId}`);
        const existing = document.getElementById(`pki_existing_${fieldId}`);
        const textBox = document.getElementById(`pki_text_${fieldId}`);
        const btn = document.getElementById(`pki_btn_${fieldId}`);
        const box = document.getElementById(`pki_box_${fieldId}`);

        if (input) input.value = '';
        if (existing) existing.value = '';

        if (textBox) {
            textBox.classList.remove('has-file');
            textBox.innerHTML = '<span>Klik dan letakkan file di sini atau <strong style="color:#2563eb;">Pilih File</strong></span>';
        }

        if (btn) btn.textContent = 'Pilih File';

        if (box) {
            const clearBtn = box.querySelector('.pki-upload-clear-btn');
            if (clearBtn) clearBtn.remove();
        }
        setUploadSizeInfo(fieldId, 0);
        clearFieldError(fieldId);
    };

    // ─── 5a. Drag & Drop for Upload Boxes ───
    function initUploadDragAndDrop() {
        ['business_logo', 'permit_document', 'workplace_photo'].forEach(fieldId => {
            const box = document.getElementById(`pki_box_${fieldId}`);
            const input = document.getElementById(`pki_input_${fieldId}`);
            if (!box || !input) return;

            ['dragenter', 'dragover'].forEach(eventName => {
                box.addEventListener(eventName, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    box.style.borderColor = '#2563eb';
                    box.style.backgroundColor = '#eff6ff';
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                box.addEventListener(eventName, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    box.style.borderColor = '';
                    box.style.backgroundColor = '';
                }, false);
            });

            box.addEventListener('drop', function (e) {
                const dt = e.dataTransfer;
                if (dt && dt.files && dt.files.length > 0) {
                    input.files = dt.files;
                    pkiHandleFileChange(input, fieldId);
                }
            }, false);
        });
    }

    // ─── 5b. Social Media Repeater Manager ───
    function initSocialMediaRepeater() {
        const container = document.getElementById('pki_social_container');
        if (!container) return;

        const SOCIAL_PLATFORMS = [
            { name: 'LinkedIn', icon: 'fa-brands fa-linkedin', color: '#0a66c2', placeholder: 'username atau https://linkedin.com/in/...' },
            { name: 'Facebook', icon: 'fa-brands fa-facebook', color: '#1877f2', placeholder: '@username atau https://facebook.com/...' },
            { name: 'Instagram', icon: 'fa-brands fa-instagram', color: '#e1306c', placeholder: '@username atau https://instagram.com/...' },
            { name: 'TikTok', icon: 'fa-brands fa-tiktok', color: '#000000', placeholder: '@username atau https://tiktok.com/@...' }
        ];

        function getPlatformMeta(name) {
            return SOCIAL_PLATFORMS.find(p => p.name.toLowerCase() === (name || '').toLowerCase()) || SOCIAL_PLATFORMS[0];
        }

        function syncSocialValues() {
            const rows = container.querySelectorAll('.pki-social-row');
            let linkedin = '';
            let facebook = '';
            let instagram = '';
            let tiktok = '';
            let anyFilled = false;

            rows.forEach(row => {
                const select = row.querySelector('.pki-social-platform-select');
                const input = row.querySelector('.pki-social-handle-input');
                const plat = select ? select.value : '';
                const val = input ? input.value.trim() : '';

                if (val) {
                    anyFilled = true;
                    if (plat === 'LinkedIn' && !linkedin) linkedin = val;
                    else if (plat === 'Facebook' && !facebook) facebook = val;
                    else if (plat === 'Instagram' && !instagram) instagram = val;
                    else if (plat === 'TikTok' && !tiktok) tiktok = val;
                }
            });

            const elLi = document.getElementById('pki_linkedin');
            const elFb = document.getElementById('pki_facebook');
            const elIg = document.getElementById('pki_instagram');
            const elTt = document.getElementById('pki_tiktok');
            if (elLi) elLi.value = linkedin;
            if (elFb) elFb.value = facebook;
            if (elIg) elIg.value = instagram;
            if (elTt) elTt.value = tiktok;

            if (anyFilled) {
                clearFieldError('social');
            }
        }

        function updateActionButtons() {
            const rows = container.querySelectorAll('.pki-social-row');
            const total = rows.length;

            rows.forEach((row, idx) => {
                row.dataset.index = idx;
                const actions = row.querySelector('.pki-social-actions');
                if (!actions) return;

                const isLast = (idx === total - 1);
                let html = '';

                if (total > 1) {
                    html += `
                        <button type="button" class="pki-social-btn pki-social-btn-del" title="Hapus akun media sosial" aria-label="Hapus akun">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    `;
                }

                if (isLast && total < 4) {
                    html += `
                        <button type="button" class="pki-social-btn pki-social-btn-add" title="Tambah akun media sosial" aria-label="Tambah akun">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    `;
                }

                actions.innerHTML = html;
            });
        }

        function createRow(platformName, handleVal) {
            const meta = getPlatformMeta(platformName);
            const row = document.createElement('div');
            row.className = 'pki-social-row';

            let optionsHtml = '';
            SOCIAL_PLATFORMS.forEach(p => {
                const sel = (p.name === meta.name) ? ' selected' : '';
                optionsHtml += `<option value="${p.name}"${sel}>${p.name}</option>`;
            });

            row.innerHTML = `
                <div class="pki-social-select-wrap">
                    <span class="pki-social-platform-icon">
                        <i class="${meta.icon}" style="color:${meta.color};"></i>
                    </span>
                    <select name="social_platform[]" class="pki-social-platform-select" aria-label="Pilih platform media sosial">
                        ${optionsHtml}
                    </select>
                </div>
                <div class="pki-social-input-wrap">
                    <input type="text" name="social_handle[]" class="pki-social-handle-input" value="${handleVal || ''}" placeholder="${meta.placeholder}" autocomplete="off" aria-label="Username atau URL akun media sosial">
                </div>
                <div class="pki-social-actions"></div>
            `;
            return row;
        }

        container.addEventListener('change', function (e) {
            const select = e.target.closest('.pki-social-platform-select');
            if (!select) return;
            const row = select.closest('.pki-social-row');
            if (!row) return;

            const meta = getPlatformMeta(select.value);
            const iconEl = row.querySelector('.pki-social-platform-icon');
            if (iconEl) {
                iconEl.innerHTML = `<i class="${meta.icon}" style="color:${meta.color};"></i>`;
            }
            const input = row.querySelector('.pki-social-handle-input');
            if (input) {
                input.placeholder = meta.placeholder;
            }
            syncSocialValues();
        });

        container.addEventListener('input', function (e) {
            const input = e.target.closest('.pki-social-handle-input');
            if (!input) return;
            if (input.value.trim()) {
                input.classList.remove('is-invalid');
            }
            syncSocialValues();
        });

        container.addEventListener('click', function (e) {
            const addBtn = e.target.closest('.pki-social-btn-add');
            const delBtn = e.target.closest('.pki-social-btn-del');

            if (addBtn) {
                e.preventDefault();
                const rows = container.querySelectorAll('.pki-social-row');
                if (rows.length >= 4) return;

                const used = Array.from(rows).map(r => r.querySelector('.pki-social-platform-select')?.value);
                const nextPlat = SOCIAL_PLATFORMS.find(p => !used.includes(p.name)) || SOCIAL_PLATFORMS[0];

                const newRow = createRow(nextPlat.name, '');
                container.appendChild(newRow);
                updateActionButtons();
                syncSocialValues();

                const newInput = newRow.querySelector('.pki-social-handle-input');
                if (newInput) newInput.focus();
                return;
            }

            if (delBtn) {
                e.preventDefault();
                const row = delBtn.closest('.pki-social-row');
                if (!row) return;
                const rows = container.querySelectorAll('.pki-social-row');
                if (rows.length > 1) {
                    row.remove();
                    updateActionButtons();
                    syncSocialValues();
                }
                return;
            }
        });

        updateActionButtons();
        syncSocialValues();
    }

    // ─── 6. Strict Per-Field Validation on Submit ───
    const DEFAULT_HELPERS = {
        owner_name: 'Terisi otomatis dari akun SIAPKerja.',
        nik: 'Terisi otomatis dari akun SIAPKerja.',
        phone: 'Terisi otomatis dari akun SIAPKerja.',
        whatsapp: 'Gunakan nomor WhatsApp aktif.',
        profession: 'Pilih jenis usaha perorangan yang Anda jalankan.',
        npwp: '',
        brand_name: 'Nama brand atau merek usaha/kegiatan yang dijalankan.',
        business_logo: 'Format PNG, JPG. Ukuran Maksimal 2MB',
        location: 'Pilih lokasi secara berjenjang sampai Kelurahan/Desa.',
        address: 'Tuliskan alamat lengkap tempat usaha/kegiatan.',
        postal_code: 'Pilihan kode pos mengikuti lokasi yang dipilih.',
        address_detail: 'Opsional. Tambahkan informasi yang membantu mengenali lokasi.',
        permit_document: 'Format PDF, Docx. Ukuran Maksimal 2MB',
        workplace_photo: 'Format PNG, JPG. Ukuran Maksimal 2MB',
        social: 'Wajib mengisi minimal salah satu akun media sosial (LinkedIn, Facebook, Instagram, atau TikTok).',
        description: 'Deskripsikan secara singkat profil usaha, produk/layanan, atau kebutuhan rekrutmen.'
    };

    function getFieldElement(fieldId) {
        if (fieldId === 'profession') return document.getElementById('pki_profession_trigger') || document.getElementById('pki_profession');
        if (fieldId === 'npwp') return document.getElementById('pki_box_npwp') || document.getElementById('pki_npwp_status');
        if (fieldId === 'location') return document.getElementById('pki_hierarchicalLocationInput');
        if (fieldId === 'address') return document.getElementById('pki_inputAddress');
        if (fieldId === 'postal_code') return document.getElementById('pki_selectPostalCode');
        if (fieldId === 'permit_document') return document.getElementById('pki_box_permit_document');
        if (fieldId === 'workplace_photo') return document.getElementById('pki_box_workplace_photo');
        if (fieldId === 'business_logo') return document.getElementById('pki_box_business_logo');
        if (fieldId === 'social') return document.querySelector('.pki-social-row .pki-social-handle-input') || document.getElementById('pki_social_container');
        if (fieldId === 'user_consent') return document.getElementById('pki_user_consent');
        return document.getElementById(`pki_${fieldId}`) || document.getElementById(fieldId);
    }

    function setFieldError(fieldId, errorMsg) {
        if (fieldId === 'social') {
            const helper = document.getElementById('helper_social');
            if (helper) {
                helper.textContent = errorMsg;
                helper.style.setProperty('display', 'block', 'important');
                helper.classList.add('error');
            }
            document.querySelectorAll('.pki-social-handle-input').forEach(el => {
                if (!el.value.trim()) el.classList.add('is-invalid');
            });
            document.getElementById('pki_social_container')?.classList.add('has-error');
            return;
        }

        const label = document.getElementById(`label_${fieldId}`);
        const helper = document.getElementById(`helper_${fieldId}`);
        const el = getFieldElement(fieldId);
        const container = (label || helper || el)?.closest('.pki-field');

        if (label) label.classList.add('error');
        if (el) el.classList.add('error');
        if (container) container.classList.add('has-error');
        if (helper) {
            helper.classList.add('error');
            helper.textContent = errorMsg;
            helper.style.setProperty('display', 'block', 'important');
        }
    }

    function clearFieldError(fieldId) {
        if (fieldId === 'social') {
            const helper = document.getElementById('helper_social');
            if (helper) {
                helper.style.setProperty('display', 'none', 'important');
                helper.classList.remove('error');
            }
            document.querySelectorAll('.pki-social-handle-input.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            document.getElementById('pki_social_container')?.classList.remove('has-error');
            return;
        }

        const label = document.getElementById(`label_${fieldId}`);
        const helper = document.getElementById(`helper_${fieldId}`);
        const el = getFieldElement(fieldId);
        const container = (label || helper || el)?.closest('.pki-field');

        if (label) label.classList.remove('error');
        if (el) el.classList.remove('error');
        if (container) container.classList.remove('has-error');
        if (helper) {
            helper.classList.remove('error');
            if (fieldId === 'permit_document') {
                const curProf = document.getElementById('pki_profession')?.value || '';
                updatePermitDocHelper(curProf);
            } else if (fieldId === 'workplace_photo') {
                helper.innerHTML = '<div class="pki-permit-doc-helper-wrap"><div class="pki-permit-doc-title" style="font-weight:600; color:#334155; margin-bottom:2px;">Foto yang perlu diunggah:</div><div class="pki-permit-doc-requirement" style="color:#475569; margin-bottom:4px; line-height:1.45;">Foto tempat usaha, lokasi kegiatan, atau sarana pendukung usaha.</div><div class="pki-permit-doc-format" style="color:#64748b; font-size:11px;">Format PNG/JPG, maksimal 2 MB per file.</div></div>';
            } else {
                helper.textContent = DEFAULT_HELPERS[fieldId] || '';
            }
            if (fieldId === 'user_consent' || fieldId === 'npwp') {
                helper.style.display = 'none';
            }
        }
    }

    function initValidation(form) {
        // Live clear on input
        ['owner_name', 'phone', 'whatsapp', 'brand_name', 'description'].forEach(id => {
            const input = document.getElementById(`pki_${id}`);
            if (input) {
                input.addEventListener('input', () => clearFieldError(id));
            }
        });

        // NPWP Status Checkbox live clear
        const npwpStatusCb = document.getElementById('pki_npwp_status');
        if (npwpStatusCb) {
            npwpStatusCb.addEventListener('change', function () {
                if (this.checked) {
                    clearFieldError('npwp');
                }
            });
        }



        // NIK special check
        const nikInput = document.getElementById('pki_nik');
        if (nikInput) {
            nikInput.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 16);
                if (this.value.length === 16) {
                    clearFieldError('nik');
                }
            });
        }

        // Profession select
        const profSelect = document.getElementById('pki_profession');
        if (profSelect) {
            profSelect.addEventListener('change', () => clearFieldError('profession'));
        }

        // Consent checkbox
        const consentCb = document.getElementById('pki_user_consent');
        if (consentCb) {
            consentCb.addEventListener('change', function () {
                if (this.checked) {
                    clearFieldError('user_consent');
                    document.getElementById('pki_consent_statement_box')?.classList.remove('has-error');
                }
            });
        }

        // Multi-Step Wizard State & Navigation
        let currentStep = 1;
        const STEP_TITLES = {
            1: 'Identitas Perorangan',
            2: 'PROFIL USAHA / KEGIATAN PEMBERI KERJA',
            3: 'Dokumen & Bukti Tempat Usaha & Media Sosial',
            4: 'PERNYATAAN'
        };

        function updateStepPie(step) {
            const pieContainer = document.getElementById('pkiStepPie');
            if (!pieContainer) return;
            const circ = 56.54;
            const percent = Math.min(Math.max(step / 4, 0), 1);
            const offset = circ * (1 - percent);
            pieContainer.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                    <circle cx="12" cy="12" r="9" stroke="#cbd5e1" stroke-width="2.5" fill="none"></circle>
                    <circle cx="12" cy="12" r="9" stroke="#2563eb" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-dasharray="${circ}" stroke-dashoffset="${offset.toFixed(2)}" transform="rotate(-90 12 12)"></circle>
                </svg>
            `;
        }

        function goToStep(step) {
            if (step < 1) step = 1;
            if (step > 4) step = 4;
            currentStep = step;

            // Toggle Panes
            const panes = document.querySelectorAll('.pki-step-pane');
            panes.forEach(pane => {
                const paneStep = parseInt(pane.getAttribute('data-step') || '1', 10);
                if (paneStep === step) {
                    pane.classList.add('active');
                    pane.style.display = 'block';
                } else {
                    pane.classList.remove('active');
                    pane.style.display = 'none';
                }
            });

            // Update Header Title & Counter
            const titleEl = document.getElementById('pkiStepTitle');
            if (titleEl) {
                const activePane = document.querySelector(`.pki-step-pane[data-step="${step}"]`);
                titleEl.textContent = activePane?.getAttribute('data-step-title') || STEP_TITLES[step] || 'Identitas Perorangan';
            }

            const counterEl = document.getElementById('pkiStepCounter');
            if (counterEl) {
                counterEl.textContent = `Langkah ${step}/4`;
            }

            updateStepPie(step);

            // Update Navigation Buttons
            const btnPrev = document.getElementById('pkiBtnPrev');
            const btnNext = document.getElementById('pkiBtnNext');
            const btnSubmit = document.getElementById('btnSubmitProfile');
            const cancelWrapper = document.getElementById('pkiCancelWrapper');

            if (cancelWrapper) {
                cancelWrapper.style.setProperty('display', 'none', 'important');
            }
            if (btnPrev) {
                btnPrev.textContent = 'Kembali';
                btnPrev.style.setProperty('display', (step > 1) ? 'inline-flex' : 'none', 'important');
            }
            if (btnNext) {
                btnNext.textContent = 'Selanjutnya';
                btnNext.style.setProperty('display', (step < 4) ? 'inline-flex' : 'none', 'important');
            }
            if (btnSubmit) {
                btnSubmit.style.setProperty('display', (step === 4) ? 'inline-flex' : 'none', 'important');
            }

            if (step === 2) {
                setTimeout(updateMapPreview, 60);
            }

            // Smooth scroll up to top of container
            const modalBody = document.querySelector('.modal-body');
            if (modalBody && typeof modalBody.scrollTo === 'function') {
                modalBody.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                const card = document.querySelector('.registration-card, .profile-modal-preview, .modal-panel');
                if (card && typeof card.scrollIntoView === 'function') {
                    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        }

        function validateStep(stepNumber) {
            let hasError = false;
            let firstErrorElement = null;

            function flagError(fieldId, errorMsg, focusEl) {
                setFieldError(fieldId, errorMsg);
                if (!hasError) {
                    hasError = true;
                    firstErrorElement = focusEl;
                }
            }

            if (stepNumber === 1) {
                // 1. Nama Pemberi Kerja
                const ownerName = document.getElementById('pki_owner_name')?.value?.trim();
                if (!ownerName) {
                    flagError('owner_name', 'Nama Pemberi Kerja wajib diisi.', document.getElementById('pki_owner_name'));
                } else {
                    clearFieldError('owner_name');
                }

                // 2. NIK
                const nik = document.getElementById('pki_nik')?.value?.trim();
                if (!nik || nik.length !== 16) {
                    flagError('nik', 'NIK wajib diisi 16 digit angka.', document.getElementById('pki_nik'));
                } else {
                    clearFieldError('nik');
                }

                // 3. Nomor Telepon Aktif
                const phone = document.getElementById('pki_phone')?.value?.trim();
                if (!phone || phone.length < 10 || phone.length > 15) {
                    flagError('phone', 'Nomor telepon aktif wajib diisi (10–15 digit angka).', document.getElementById('pki_phone'));
                } else {
                    clearFieldError('phone');
                }

                // 4. Jenis Usaha Individu
                const profession = document.getElementById('pki_profession')?.value?.trim();
                if (!profession) {
                    flagError('profession', 'Pilih jenis usaha individu yang sesuai.', document.getElementById('pki_profession_trigger') || document.getElementById('pki_profession'));
                } else {
                    clearFieldError('profession');
                }

                // 5. Nomor WhatsApp
                const whatsapp = document.getElementById('pki_whatsapp')?.value?.trim();
                if (!whatsapp || whatsapp.length < 10 || whatsapp.length > 15) {
                    flagError('whatsapp', 'Nomor WhatsApp aktif wajib diisi (10–15 digit angka).', document.getElementById('pki_whatsapp'));
                } else {
                    clearFieldError('whatsapp');
                }

                // 6. Status NPWP
                const npwpStatus = document.getElementById('pki_npwp_status');
                if (!npwpStatus || !npwpStatus.checked) {
                    flagError('npwp', 'Status NPWP wajib dicentang untuk melanjutkan.', document.getElementById('pki_npwp_status'));
                } else {
                    const nikVal = document.getElementById('pki_nik')?.value?.trim() || '';
                    const npwpHidden = document.getElementById('pki_npwp');
                    if (npwpHidden) npwpHidden.value = nikVal;
                    clearFieldError('npwp');
                }
            } else if (stepNumber === 2) {
                // 1. Nama Brand Usaha (Wajib)
                const brandName = document.getElementById('pki_brand_name')?.value?.trim();
                if (!brandName) {
                    flagError('brand_name', 'Nama Brand Usaha wajib diisi.', document.getElementById('pki_brand_name'));
                } else {
                    clearFieldError('brand_name');
                }

                // 2. Logo Usaha (Wajib: file baru atau file yang sudah diunggah sebelumnya)
                const logoFileInput = document.getElementById('pki_input_business_logo');
                const existingLogo = document.getElementById('pki_existing_business_logo')?.value?.trim();
                const logoFile = logoFileInput?.files?.[0];
                const hasLogo = Boolean(logoFile) || Boolean(existingLogo);
                if (!hasLogo) {
                    flagError('business_logo', 'Logo Usaha wajib diunggah (PNG/JPG, maks 2MB).', document.getElementById('pki_box_business_logo'));
                } else if (logoFile) {
                    const logoExt = (logoFile.name.split('.').pop() || '').toLowerCase();
                    if (!['png', 'jpg', 'jpeg'].includes(logoExt) || logoFile.size > 2 * 1024 * 1024) {
                        flagError('business_logo', 'Format PNG, JPG. Ukuran Maksimal 2MB.', document.getElementById('pki_box_business_logo'));
                    } else {
                        clearFieldError('business_logo');
                    }
                } else {
                    clearFieldError('business_logo');
                }

                // 3. Deskripsi Singkat Usaha / Rekrutmen (Wajib)
                const desc = document.getElementById('pki_description')?.value?.trim();
                if (!desc) {
                    flagError('description', 'Deskripsi Singkat Usaha / Rekrutmen wajib diisi.', document.getElementById('pki_description'));
                } else {
                    clearFieldError('description');
                }

                // 4. Lokasi Tempat Usaha / Kegiatan
                const village = document.getElementById('pki_hiddenVillage')?.value?.trim();
                if (!village) {
                    flagError('location', 'Lokasi tempat usaha/kegiatan wajib dipilih secara berjenjang.', document.getElementById('pki_hierarchicalLocationInput'));
                } else {
                    clearFieldError('location');
                }

                // 5. Alamat Lengkap Tempat Usaha
                const address = document.getElementById('pki_inputAddress')?.value?.trim();
                if (!address) {
                    flagError('address', 'Alamat lengkap tempat usaha/kegiatan wajib diisi.', document.getElementById('pki_inputAddress'));
                } else {
                    clearFieldError('address');
                }

                // 6. Kode Pos
                const postalCode = document.getElementById('pki_selectPostalCode')?.value?.trim();
                if (!postalCode) {
                    flagError('postal_code', 'Kode pos wajib dipilih/diisi.', document.getElementById('pki_selectPostalCode'));
                } else {
                    clearFieldError('postal_code');
                }
            } else if (stepNumber === 3) {
                // 10. Dokumen Perizinan Usaha
                const permitFileInput = document.getElementById('pki_input_permit_document');
                const existingPermit = document.getElementById('pki_existing_permit_document')?.value?.trim();
                const permitFile = permitFileInput?.files?.[0];
                const hasPermit = Boolean(permitFile) || Boolean(existingPermit);
                if (!hasPermit) {
                    flagError('permit_document', 'Dokumen Perizinan Usaha wajib diunggah minimal 1 dokumen.', document.getElementById('pki_box_permit_document'));
                } else if (permitFile) {
                    const permitExt = (permitFile.name.split('.').pop() || '').toLowerCase();
                    if (!['pdf', 'docx', 'jpg', 'jpeg'].includes(permitExt) || permitFile.size > 2 * 1024 * 1024) {
                        flagError('permit_document', 'Format PDF/JPG, maksimal 2 MB per file.', document.getElementById('pki_box_permit_document'));
                    } else {
                        clearFieldError('permit_document');
                    }
                } else {
                    clearFieldError('permit_document');
                }

                // 11. Foto Bukti Tempat Usaha / Lokasi
                const photoFileInput = document.getElementById('pki_input_workplace_photo');
                const existingPhoto = document.getElementById('pki_existing_workplace_photo')?.value?.trim();
                const photoFile = photoFileInput?.files?.[0];
                const hasPhoto = Boolean(photoFile) || Boolean(existingPhoto);
                if (!hasPhoto) {
                    flagError('workplace_photo', 'Unggah minimal 1 foto tempat usaha/kegiatan.', document.getElementById('pki_box_workplace_photo'));
                } else if (photoFile) {
                    const photoExt = (photoFile.name.split('.').pop() || '').toLowerCase();
                    if (!['png', 'jpg', 'jpeg'].includes(photoExt) || photoFile.size > 2 * 1024 * 1024) {
                        flagError('workplace_photo', 'Format PNG, JPG. Ukuran Maksimal 2MB.', document.getElementById('pki_box_workplace_photo'));
                    } else {
                        clearFieldError('workplace_photo');
                    }
                } else {
                    clearFieldError('workplace_photo');
                }

                // 12. Media Sosial (Dropdown platform + satu field username/URL, minimal 1 akun)
                const socialContainer = document.getElementById('pki_social_container');
                const socialRows = socialContainer ? socialContainer.querySelectorAll('.pki-social-row') : [];
                let hasFilledSocial = false;
                socialRows.forEach(r => {
                    const handle = r.querySelector('.pki-social-handle-input')?.value?.trim();
                    if (handle) hasFilledSocial = true;
                });
                if (!hasFilledSocial) {
                    const firstInput = socialRows[0]?.querySelector('.pki-social-handle-input') || socialContainer;
                    flagError('social', 'Wajib mengisi minimal salah satu akun media sosial (LinkedIn, Facebook, Instagram, atau TikTok).', firstInput);
                } else {
                    clearFieldError('social');
                }
            } else if (stepNumber === 4) {
                // 13. Pernyataan
                const consent = document.getElementById('pki_user_consent')?.checked;
                const consentBox = document.getElementById('pki_consent_statement_box');
                if (!consent) {
                    if (consentBox) consentBox.classList.add('has-error');
                    flagError('user_consent', 'Anda harus menyetujui pernyataan sebelum mengajukan profil.', document.getElementById('pki_user_consent'));
                } else {
                    if (consentBox) consentBox.classList.remove('has-error');
                    clearFieldError('user_consent');
                }
            }

            if (hasError) {
                if (firstErrorElement) {
                    firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    if (typeof firstErrorElement.focus === 'function') {
                        firstErrorElement.focus();
                    }
                }
                return false;
            }
            return true;
        }

        // Attach Step Button Listeners
        const btnNext = document.getElementById('pkiBtnNext');
        if (btnNext && !btnNext.dataset.bound) {
            btnNext.dataset.bound = 'true';
            btnNext.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (validateStep(currentStep)) {
                    goToStep(currentStep + 1);
                }
            });
        }

        const btnPrev = document.getElementById('pkiBtnPrev');
        if (btnPrev && !btnPrev.dataset.bound) {
            btnPrev.dataset.bound = 'true';
            btnPrev.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (currentStep > 1) {
                    goToStep(currentStep - 1);
                }
            });
        }

        // Prevent Enter from bypassing step validation
        form.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.target && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                if (currentStep < 4) {
                    if (validateStep(currentStep)) {
                        goToStep(currentStep + 1);
                    }
                } else {
                    if (validateStep(4)) {
                        form.submit();
                    }
                }
            }
        });

        // Intercept Form Submit (Validates all steps sequentially)
        form.addEventListener('submit', function (e) {
            for (let s = 1; s <= 4; s++) {
                if (!validateStep(s)) {
                    e.preventDefault();
                    goToStep(s);
                    return false;
                }
            }
        });

        // Initialize to step 1
        goToStep(1);
    }

    function setInputValue(id, val) {
        const el = document.getElementById(id);
        if (el) el.value = val;
    }

    // Auto-boot when DOM is loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPkiForm);
    } else {
        initPkiForm();
    }
})();
