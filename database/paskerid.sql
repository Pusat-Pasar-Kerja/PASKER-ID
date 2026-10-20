CREATE DATABASE IF NOT EXISTS `paskerid` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `paskerid`;

DROP TABLE IF EXISTS kbji_data;
DROP TABLE IF EXISTS job_posts;
DROP TABLE IF EXISTS seeker_languages;
DROP TABLE IF EXISTS seeker_skills;
DROP TABLE IF EXISTS seeker_trainings;
DROP TABLE IF EXISTS seeker_educations;
DROP TABLE IF EXISTS seeker_experiences;
DROP TABLE IF EXISTS seeker_profiles;
DROP TABLE IF EXISTS employer_profiles;
DROP TABLE IF EXISTS users;

CREATE TABLE kbji_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode_kbji VARCHAR(20) NOT NULL UNIQUE,
    nama_jabatan VARCHAR(255) NOT NULL
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    domicile_city_id VARCHAR(120) NULL,
    city VARCHAR(120) NULL,
    profile_complete TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE employer_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    owner_name VARCHAR(120) NOT NULL,
    nik VARCHAR(30) NULL,
    profession VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    whatsapp VARCHAR(30) NULL,
    npwp VARCHAR(30) NULL,
    linkedin VARCHAR(255) NULL,
    facebook VARCHAR(255) NULL,
    instagram VARCHAR(255) NULL,
    social_media TEXT NULL,
    same_location_siapkerja TINYINT(1) DEFAULT 1,
    province VARCHAR(120) NOT NULL,
    city VARCHAR(120) NOT NULL,
    district VARCHAR(120) NULL,
    village VARCHAR(120) NULL,
    postal_code VARCHAR(20) NULL,
    same_address_siapkerja TINYINT(1) DEFAULT 1,
    address TEXT NOT NULL,
    address_detail TEXT NULL,
    latitude VARCHAR(50) NULL,
    longitude VARCHAR(50) NULL,
    doc_permission VARCHAR(255) NULL,
    doc_location_photo VARCHAR(255) NULL,
    description TEXT NULL,
    user_consent TINYINT(1) DEFAULT 0,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_status ENUM('NOT_SUBMITTED', 'PENDING', 'NEEDS_REVISION', 'APPROVED', 'REJECTED', 'SUSPENDED', 'TRANSITION_LIMITED', 'FULL_DISABLED') DEFAULT 'NOT_SUBMITTED',
    rejection_count INT DEFAULT 0,
    verifier_notes TEXT NULL,
    verification_checklist TEXT NULL,
    suspension_reason TEXT NULL,
    active_until DATETIME NULL DEFAULT NULL,
    last_activated_at DATETIME NULL DEFAULT NULL,
    extension_requested TINYINT(1) NOT NULL DEFAULT 0,
    extension_status ENUM('NONE', 'REQUESTED', 'APPROVED', 'REJECTED') DEFAULT 'NONE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_employer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    nik VARCHAR(30) NOT NULL,
    birth_place VARCHAR(120) NOT NULL,
    birth_date DATE NOT NULL,
    gender ENUM('Laki-laki', 'Perempuan') NOT NULL,
    marital_status ENUM('Kawin', 'Belum Kawin') NOT NULL,
    phone VARCHAR(30) NOT NULL,
    ktp_address TEXT NOT NULL,
    domicile_address TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_seeker_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_experiences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_name VARCHAR(150) NOT NULL,
    position VARCHAR(120) NOT NULL,
    duration VARCHAR(120) NOT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_exp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_trainings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    training_name VARCHAR(150) NOT NULL,
    organizer VARCHAR(150) NULL,
    year VARCHAR(20) NULL,
    certificate VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_training_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_educations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    level VARCHAR(80) NOT NULL,
    school_name VARCHAR(150) NOT NULL,
    major VARCHAR(120) NULL,
    graduation_year VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_education_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    skill_name VARCHAR(120) NOT NULL,
    level VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_skill_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE seeker_languages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    language_name VARCHAR(120) NOT NULL,
    proficiency VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_language_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE job_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(150) NOT NULL,
    job_type VARCHAR(80) NOT NULL,
    industry VARCHAR(120) NULL,
    entity_type ENUM('Perusahaan', 'Individu') DEFAULT 'Individu',
    status ENUM('Draft', 'Dikirim/Menunggu Verifikasi', 'Perlu Direvisi', 'Ditolak', 'Terjadwal Tayang', 'Tayang', 'Ditangguhkan', 'Ditutup', 'Kedaluwarsa', 'Diblokir') NOT NULL DEFAULT 'Draft',
    salary_min INT NULL,
    salary_max INT NULL,
    quota INT NOT NULL DEFAULT 1,
    accepted_count INT NOT NULL DEFAULT 0,
    kbji_code VARCHAR(20) NULL,
    details TEXT NULL,
    admin_notes TEXT NULL,
    revision_opened_at DATETIME NULL,
    parent_job_id INT NULL,
    unfulfilled_reason TEXT NULL,
    verifier_notes TEXT NULL,
    verification_checklist TEXT NULL,
    is_blacklisted TINYINT(1) DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_job_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO users (name, email, password_hash, role, domicile_city_id, city, profile_complete) VALUES
('Pandu Isdiyanto, S.T., M.M', 'admin@paskerid.test', '$2y$10$4Ub96pSJd1xdfdkRHCaWw.WbK19BOoTxiBqxEy7by6Gwub1dJBydm', 'admin', NULL, NULL, 1),
('Perorangan Demo', 'perorangan@paskerid.test', '$2y$10$4Ub96pSJd1xdfdkRHCaWw.WbK19BOoTxiBqxEy7by6Gwub1dJBydm', 'employer', NULL, NULL, 1),
('Pencari Kerja Demo', 'seeker@paskerid.test', '$2y$10$4Ub96pSJd1xdfdkRHCaWw.WbK19BOoTxiBqxEy7by6Gwub1dJBydm', 'seeker', NULL, NULL, 1);

INSERT INTO employer_profiles (
    user_id, owner_name, nik, profession, phone, whatsapp, npwp, linkedin, facebook, instagram,
    province, city, district, village, postal_code, address, address_detail, latitude, longitude,
    description, user_consent, verified, verification_status, active_until
) VALUES (
    2, 'Perorangan Demo', '3275012304890001', 'Kuliner & Katering', '08123456789', '08123456789', '12.345.678.9-012.000',
    'https://linkedin.com/in/perorangan-demo', 'https://facebook.com/perorangan.demo', 'https://instagram.com/perorangandemo',
    'Jawa Barat', 'Kota Bekasi', 'Bekasi Selatan', 'Pekayon Jaya', '17148', 'Jl. Ahmad Yani No. 12', 'Samping Indomaret Pekayon',
    '-6.241586', '106.992416', 'Usaha katering rumahan dan jasa konsultasi menu kuliner keluarga.', 1, 1, 'APPROVED', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 6 MONTH)
);

INSERT INTO job_posts (user_id, title, description, location, job_type, industry, entity_type, status, quota, accepted_count, kbji_code) VALUES
(2, 'Koki Masakan Tradisional', 'Membutuhkan koki berpengalaman untuk katering harian rumahan.', 'Pekayon Jaya, Bekasi Selatan, Kota Bekasi, Jawa Barat', 'Full Time', 'Kuliner', 'Individu', 'Tayang', 2, 1, '5120.01'),
(2, 'Asisten Rumah Tangga', 'Membantu kebersihan dan kerapian rumah tinggal.', 'Pekayon Jaya, Bekasi Selatan, Kota Bekasi, Jawa Barat', 'Full Time', 'Jasa Perorangan', 'Individu', 'Draft', 1, 0, '9111.01'),
(2, 'Staf Entri Data Katering', 'Mengelola data pesanan dan bahan makanan.', 'Pekayon Jaya, Bekasi Selatan, Kota Bekasi, Jawa Barat', 'Part Time', 'Administrasi', 'Individu', 'Dikirim/Menunggu Verifikasi', 1, 0, '4312.01');

INSERT INTO kbji_data (kode_kbji, nama_jabatan) VALUES
('2512.01', 'Pengembang Perangkat Lunak'),
('2512.02', 'Programmer (Programmer Komputer)'),
('2511.01', 'Analis Sistem Komputer'),
('5120.01', 'Koki'),
('5230.01', 'Kasir'),
('3322.01', 'Tenaga Penjualan (Sales)'),
('4111.01', 'Staf Administrasi Umum'),
('4312.01', 'Staf Entri Data'),
('2141.01', 'Insinyur Industri dan Produksi'),
('2421.01', 'Analis Manajemen'),
('3411.01', 'Petugas Bantuan Hukum'),
('5131.01', 'Pramusaji'),
('2411.01', 'Akuntan'),
('4311.01', 'Staf Akuntansi'),
('5411.01', 'Petugas Keamanan (Satpam)'),
('9111.01', 'Asisten Rumah Tangga'),
('8322.01', 'Pengemudi Mobil Barang (Sopir)'),
('3333.01', 'Agen Penyalur Tenaga Kerja'),
('2211.01', 'Dokter Umum'),
('2221.01', 'Perawat Profesional');

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(40) NOT NULL,
    job_id INT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS job_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    seeker_id INT NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Lamaran Masuk',
    accepted_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_job_seeker (job_id, seeker_id)
);
