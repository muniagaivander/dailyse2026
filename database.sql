CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','admin_kab','pengawas','pencacah','viewer_prov','viewer_kab') NOT NULL,
  kab_id VARCHAR(4) NULL,
  name VARCHAR(150) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS master_prov (
  id VARCHAR(2) PRIMARY KEY,
  kdprov VARCHAR(2) NOT NULL,
  nmprov VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS master_kab (
  id VARCHAR(4) PRIMARY KEY,
  prov_id VARCHAR(2) NOT NULL,
  kdkab VARCHAR(2) NOT NULL,
  nmkab VARCHAR(120) NOT NULL,
  INDEX (prov_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS master_kec (
  id VARCHAR(7) PRIMARY KEY,
  kab_id VARCHAR(4) NOT NULL,
  kdkec VARCHAR(3) NOT NULL,
  nmkec VARCHAR(120) NOT NULL,
  INDEX (kab_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS master_desa (
  id VARCHAR(10) PRIMARY KEY,
  kec_id VARCHAR(7) NOT NULL,
  kddesa VARCHAR(3) NOT NULL,
  nmdesa VARCHAR(150) NOT NULL,
  INDEX (kec_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS master_sls (
  id VARCHAR(14) PRIMARY KEY,
  desa_id VARCHAR(10) NOT NULL,
  kdsls VARCHAR(4) NOT NULL,
  nmsls VARCHAR(150) NOT NULL,
  INDEX (desa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS master_subsls (
  id VARCHAR(20) PRIMARY KEY,
  sls_id VARCHAR(14) NOT NULL,
  kdsubsls VARCHAR(2) NOT NULL,
  nmsubsls VARCHAR(150) NOT NULL,
  idsubls VARCHAR(20) NULL,
  pengawas_email VARCHAR(150) NULL,
  pencacah_email VARCHAR(150) NULL,
  INDEX (sls_id),
  INDEX (pengawas_email),
  INDEX (pencacah_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subsls_status (
  subsls_id VARCHAR(20) PRIMARY KEY,
  open_count INT NOT NULL DEFAULT 0,
  draft_count INT NOT NULL DEFAULT 0,
  submitted_by_pencacah INT NOT NULL DEFAULT 0,
  approved_by_pengawas INT NOT NULL DEFAULT 0,
  rejected_by_pengawas INT NOT NULL DEFAULT 0,
  target INT NOT NULL DEFAULT 0,
  last_update DATETIME NULL,
  updated_by VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subsls_completion_status (
  subsls_id VARCHAR(20) PRIMARY KEY,
  status_selesai ENUM('Belum Selesai','Selesai') NOT NULL DEFAULT 'Belum Selesai',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS daily_status (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tanggal DATE NOT NULL,
  subsls_id VARCHAR(20) NOT NULL,
  kab_id VARCHAR(4) NOT NULL,
  pengawas_email VARCHAR(150) NOT NULL,
  pencacah_email VARCHAR(150) NOT NULL,
  target INT NOT NULL DEFAULT 0,
  open_count INT NOT NULL DEFAULT 0,
  draft_count INT NOT NULL DEFAULT 0,
  submitted_by_pencacah INT NOT NULL DEFAULT 0,
  approved_by_pengawas INT NOT NULL DEFAULT 0,
  rejected_by_pengawas INT NOT NULL DEFAULT 0,
  submitted_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by VARCHAR(150) NOT NULL,
  UNIQUE KEY uniq_daily_subsls (tanggal, subsls_id),
  INDEX (tanggal),
  INDEX (kab_id),
  INDEX (pengawas_email),
  INDEX (pencacah_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS submit_locks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tanggal DATE NOT NULL,
  pengawas_email VARCHAR(150) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'SUBMITTED',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_lock (tanggal, pengawas_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (email, password_hash, role, kab_id, name, active)
VALUES ('6400@bps.go.id', '$2y$12$PV2IXEFC3UQ.qXFacpTwvubCw/VNxM/CrNoiSwcHzVSRH62GVHXXG', 'superadmin', NULL, 'Super Admin', 1)
ON DUPLICATE KEY UPDATE email = email;
