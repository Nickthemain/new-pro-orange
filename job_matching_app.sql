-- =====================================================================
-- Job Matching App — MySQL / MariaDB schema (import via phpMyAdmin)
-- Safe to import again: it never deletes tables or data.
-- Requires: MySQL 8.0.16+ or MariaDB 10.4+ (for CHECK constraints)
-- Tables: users, refresh_tokens, employer_profiles, seeker_profiles,
--         seeker_employment_types, seeker_industries, jobs, swipes, matches
-- =====================================================================

CREATE DATABASE IF NOT EXISTS job_matching_app
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE job_matching_app;

-- Safe to run more than once: existing tables and data are kept.

-- ---------------------------------------------------------------------
-- USERS — login / auth
-- role is NULL right after sign-up; set when the user picks
-- "Looking for an Employee" (employer) or "Looking for Work" (seeker)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email                 VARCHAR(255) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,          -- PHP password_hash() output, never plain text
  role                  ENUM('employer','seeker') NULL DEFAULT NULL,
  onboarding_step       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  onboarding_completed  TINYINT(1) NOT NULL DEFAULT 0,
  email_verified        TINYINT(1) NOT NULL DEFAULT 0,
  failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lock_until            DATETIME NULL,                  -- brute-force lockout
  last_login_at         DATETIME NULL,
  status                ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Remember me" / session tokens (store a hash, not the raw token)
CREATE TABLE IF NOT EXISTS refresh_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,                        -- SHA-256 hex
  expires_at  DATETIME NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- EMPLOYER PROFILE (5.1 step 3)
-- company, location, industry, position, employment type, salary,
-- job detail, logo
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employer_profiles (
  user_id          INT UNSIGNED PRIMARY KEY,            -- 1:1 with users
  company_name     VARCHAR(150) NOT NULL,
  city             VARCHAR(100) NULL,
  country          VARCHAR(100) NULL,
  industry         VARCHAR(100) NOT NULL,
  position         VARCHAR(150) NULL,
  employment_type  ENUM('full_time','part_time','contract','internship','freelance') NULL,
  salary_min       DECIMAL(12,2) NULL,
  salary_max       DECIMAL(12,2) NULL,
  salary_currency  CHAR(3) NOT NULL DEFAULT 'INR',
  salary_period    ENUM('hour','month','year') NOT NULL DEFAULT 'month',
  job_detail       TEXT NULL,
  logo_path        VARCHAR(500) NULL,                   -- file path/URL, not the image itself
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_emp_salary CHECK (salary_min IS NULL OR salary_max IS NULL OR salary_min <= salary_max),
  CONSTRAINT fk_emp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SEEKER PROFILE (5.2 step 3)
-- name, age, location, nationality, qualification, experience,
-- expected salary, employment type, industries, picture, CV
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS seeker_profiles (
  user_id               INT UNSIGNED PRIMARY KEY,       -- 1:1 with users
  full_name             VARCHAR(150) NOT NULL,
  date_of_birth         DATE NULL,                      -- store DOB, calculate age in queries
  city                  VARCHAR(100) NULL,
  country               VARCHAR(100) NULL,
  nationality           VARCHAR(100) NULL,
  qualification         VARCHAR(150) NULL,
  experience_years      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expected_salary_min   DECIMAL(12,2) NULL,
  expected_salary_max   DECIMAL(12,2) NULL,
  salary_currency       CHAR(3) NOT NULL DEFAULT 'INR',
  salary_period         ENUM('hour','month','year') NOT NULL DEFAULT 'month',
  picture_path          VARCHAR(500) NULL,
  cv_path               VARCHAR(500) NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_seek_salary CHECK (expected_salary_min IS NULL OR expected_salary_max IS NULL OR expected_salary_min <= expected_salary_max),
  CONSTRAINT fk_seek_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A seeker can want several employment types and several industries
CREATE TABLE IF NOT EXISTS seeker_employment_types (
  user_id          INT UNSIGNED NOT NULL,
  employment_type  ENUM('full_time','part_time','contract','internship','freelance') NOT NULL,
  PRIMARY KEY (user_id, employment_type),
  CONSTRAINT fk_set_user FOREIGN KEY (user_id) REFERENCES seeker_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seeker_industries (
  user_id   INT UNSIGNED NOT NULL,
  industry  VARCHAR(100) NOT NULL,
  PRIMARY KEY (user_id, industry),
  KEY idx_industry (industry),
  CONSTRAINT fk_si_user FOREIGN KEY (user_id) REFERENCES seeker_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- JOBS — posted from the Post Job tab
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jobs (
  id                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employer_id                INT UNSIGNED NOT NULL,
  title                      VARCHAR(150) NOT NULL,
  description                TEXT NULL,
  industry                   VARCHAR(100) NOT NULL,
  employment_type            ENUM('full_time','part_time','contract','internship','freelance') NOT NULL,
  salary_min                 DECIMAL(12,2) NULL,
  salary_max                 DECIMAL(12,2) NULL,
  salary_currency            CHAR(3) NOT NULL DEFAULT 'INR',
  salary_period              ENUM('hour','month','year') NOT NULL DEFAULT 'month',
  city                       VARCHAR(100) NULL,
  country                    VARCHAR(100) NULL,
  required_experience_years  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status                     ENUM('open','paused','closed') NOT NULL DEFAULT 'open',
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_jobs_employer (employer_id),
  KEY idx_jobs_feed (status, industry, employment_type),
  CONSTRAINT chk_job_salary CHECK (salary_min IS NULL OR salary_max IS NULL OR salary_min <= salary_max),
  CONSTRAINT fk_jobs_employer FOREIGN KEY (employer_id) REFERENCES employer_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SWIPES — one row per decision
-- seeker swipes on a job card, employer swipes/invites a seeker for a job
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS swipes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id    INT UNSIGNED NOT NULL,                    -- who swiped
  actor_role  ENUM('employer','seeker') NOT NULL,
  job_id      INT UNSIGNED NOT NULL,
  seeker_id   INT UNSIGNED NOT NULL,
  action      ENUM('right','left','invite') NOT NULL,   -- invite = from employer Search tab
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_one_decision (actor_role, job_id, seeker_id), -- blocks duplicate swipes
  KEY idx_swipes_seeker (seeker_id, actor_role),        -- "applied jobs" / "invitations"
  KEY idx_swipes_job (job_id, actor_role),              -- "watch for applicants"
  CONSTRAINT fk_swipes_actor  FOREIGN KEY (actor_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_swipes_job    FOREIGN KEY (job_id)    REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_swipes_seeker FOREIGN KEY (seeker_id) REFERENCES seeker_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- MATCHES — created when BOTH sides are interested
-- interview + video link only exist here (5.1 step 7)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS matches (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id                  INT UNSIGNED NOT NULL,
  employer_id             INT UNSIGNED NOT NULL,
  seeker_id               INT UNSIGNED NOT NULL,
  status                  ENUM('matched','interview_scheduled','hired','rejected','withdrawn') NOT NULL DEFAULT 'matched',
  interview_scheduled_at  DATETIME NULL,
  interview_video_link    VARCHAR(500) NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_match (job_id, seeker_id),
  KEY idx_matches_employer (employer_id, status),
  KEY idx_matches_seeker (seeker_id, status),
  CONSTRAINT fk_matches_job      FOREIGN KEY (job_id)      REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_matches_employer FOREIGN KEY (employer_id) REFERENCES employer_profiles(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_matches_seeker   FOREIGN KEY (seeker_id)   REFERENCES seeker_profiles(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NOTE: MySQL cannot stop an employer-role user from also getting a
-- seeker_profiles row. Check users.role in your PHP before inserting a profile.
