-- Suedsalat App - Datenbank-Schema (siehe 01-Brainstorming/Technik-Plan.md)
-- Einmalig auf Strato per phpMyAdmin ausfuehren.

CREATE TABLE IF NOT EXISTS admins (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret VARCHAR(64) NULL,
  totp_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  email_verified_at DATETIME NULL,
  role ENUM('owner','member') NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_verifications (
  id INT PRIMARY KEY AUTO_INCREMENT,
  admin_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  admin_id INT NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  admin_id INT NULL,
  ip_address VARCHAR(45) NOT NULL,
  succeeded BOOLEAN NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS episodes_cache (
  id INT PRIMARY KEY AUTO_INCREMENT,
  guid VARCHAR(255) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  audio_url VARCHAR(500) NOT NULL,
  image_url VARCHAR(500) NULL,
  duration VARCHAR(20) NULL,
  pub_date DATETIME NOT NULL,
  fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id INT PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  event_date DATE NOT NULL,
  event_time TIME NULL,
  event_end_time TIME NULL,
  description TEXT NULL,
  link VARCHAR(500) NULL,
  episode_guid VARCHAR(255) NULL,
  episode_timestamp_seconds INT NULL,
  image_path VARCHAR(500) NULL,
  created_by INT NOT NULL,
  created_via_feedback_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES admins(id),
  CONSTRAINT events_episode_guid_fk FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movie_tips (
  id INT PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  link VARCHAR(500) NULL,
  episode_guid VARCHAR(255) NULL,
  episode_timestamp_seconds INT NULL,
  image_path VARCHAR(500) NULL,
  created_by INT NOT NULL,
  created_via_feedback_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES admins(id),
  CONSTRAINT movie_tips_episode_guid_fk FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS location_tips (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  location VARCHAR(255) NOT NULL,
  description TEXT NULL,
  link VARCHAR(500) NULL,
  episode_guid VARCHAR(255) NULL,
  episode_timestamp_seconds INT NULL,
  image_path VARCHAR(500) NULL,
  created_by INT NOT NULL,
  created_via_feedback_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (created_by) REFERENCES admins(id),
  CONSTRAINT location_tips_episode_guid_fk FOREIGN KEY (episode_guid) REFERENCES episodes_cache(guid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nutzer-Rezensionen (Mikro-Bewertung 1-5 + optionaler Text) fuer Kino-/Filmtipps,
-- Termine und Locationtipps. Polymorph ueber tip_type+tip_id statt echtem FK, da
-- tip_id je nach Typ in eine andere Tabelle zeigt. Muss vor der oeffentlichen
-- Anzeige von einem Admin freigegeben werden (approved).
CREATE TABLE IF NOT EXISTS tip_reviews (
  id INT PRIMARY KEY AUTO_INCREMENT,
  tip_type ENUM('movie_tip','event','location_tip') NOT NULL,
  tip_id INT NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  review_text TEXT NULL,
  reviewer_name VARCHAR(100) NULL,
  device_id INT NULL,
  approved TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  approved_by INT NULL,
  FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES admins(id),
  INDEX tip_reviews_lookup_idx (tip_type, tip_id, approved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS photos (
  id INT PRIMARY KEY AUTO_INCREMENT,
  image_path VARCHAR(500) NOT NULL,
  media_type ENUM('photo','video') NOT NULL DEFAULT 'photo',
  description TEXT NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT NOT NULL,
  created_via_feedback_id INT NULL,
  FOREIGN KEY (created_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS screen_views (
  id INT PRIMARY KEY AUTO_INCREMENT,
  screen VARCHAR(20) NOT NULL,
  day DATE NOT NULL,
  count INT NOT NULL DEFAULT 0,
  UNIQUE KEY screen_day (screen, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS push_tokens (
  id INT PRIMARY KEY AUTO_INCREMENT,
  device_token VARCHAR(255) NOT NULL UNIQUE,
  platform ENUM('ios','android') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
  id INT PRIMARY KEY AUTO_INCREMENT,
  device_uuid VARCHAR(64) NOT NULL UNIQUE,
  platform ENUM('ios','android') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS refresh_tokens (
  id INT PRIMARY KEY AUTO_INCREMENT,
  token_hash VARCHAR(255) NOT NULL,
  subject_type ENUM('device') NOT NULL DEFAULT 'device',
  subject_id INT NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  replaced_by_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  INDEX token_hash_idx (token_hash),
  INDEX subject_idx (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
  id INT PRIMARY KEY AUTO_INCREMENT,
  bucket VARCHAR(50) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX bucket_ip_idx (bucket, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feedback_messages (
  id INT PRIMARY KEY AUTO_INCREMENT,
  sender_name VARCHAR(100) NULL,
  type ENUM('allgemein','termin_tipp','foto_vorschlag','kino_tipp','sprachnachricht','frage') NOT NULL DEFAULT 'allgemein',
  message TEXT NOT NULL,
  suggested_date DATE NULL,
  image_path VARCHAR(500) NULL,
  media_type ENUM('image','video','audio') NOT NULL DEFAULT 'image',
  consent_publish TINYINT(1) NOT NULL DEFAULT 0,
  photo_imported_at DATETIME NULL,
  event_created_at DATETIME NULL,
  movietip_created_at DATETIME NULL,
  status ENUM('offen','erledigt') NOT NULL DEFAULT 'offen',
  handled_by INT NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (handled_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Zusaetzliche Fotos bei Mehrfach-Einreichungen (das erste Foto bleibt zusaetzlich
-- in feedback_messages.image_path fuer Abwaertskompatibilitaet).
CREATE TABLE IF NOT EXISTS feedback_media (
  id INT PRIMARY KEY AUTO_INCREMENT,
  feedback_message_id INT NOT NULL,
  image_path VARCHAR(500) NOT NULL,
  imported_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (feedback_message_id) REFERENCES feedback_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
