-- =====================================================
-- User Profile Feature - Database Setup Script
-- MySQL/MariaDB Version
-- =====================================================
-- This script creates tables for user experiences, 
-- educations, certifications, and adds the permission
-- =====================================================

-- Start Transaction
START TRANSACTION;

-- =====================================================
-- 1. Create user_experiences table
-- =====================================================
CREATE TABLE IF NOT EXISTS user_experiences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    job_title VARCHAR(255) NOT NULL,
    location VARCHAR(255) NULL,
    employment_type ENUM('full-time', 'part-time', 'contract', 'freelance', 'internship') NOT NULL DEFAULT 'full-time',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    description TEXT NULL,
    `order` INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_user_experiences_user_id 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create index on user_id for better performance
CREATE INDEX idx_user_experiences_user_id ON user_experiences(user_id);

-- =====================================================
-- 2. Create user_educations table
-- =====================================================
CREATE TABLE IF NOT EXISTS user_educations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    school_name VARCHAR(255) NOT NULL,
    degree VARCHAR(255) NOT NULL,
    field_of_study VARCHAR(255) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    gpa VARCHAR(50) NULL,
    location VARCHAR(255) NULL,
    description TEXT NULL,
    `order` INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_user_educations_user_id 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create index on user_id for better performance
CREATE INDEX idx_user_educations_user_id ON user_educations(user_id);

-- =====================================================
-- 3. Create user_certifications table
-- =====================================================
CREATE TABLE IF NOT EXISTS user_certifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    issuing_organization VARCHAR(255) NOT NULL,
    issue_date DATE NOT NULL,
    expiry_date DATE NULL,
    credential_id VARCHAR(255) NULL,
    credential_url VARCHAR(500) NULL,
    description TEXT NULL,
    image_id BIGINT UNSIGNED NULL,
    `order` INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_user_certifications_user_id 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_user_certifications_image_id 
        FOREIGN KEY (image_id) 
        REFERENCES images(id) 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create indexes for better performance
CREATE INDEX idx_user_certifications_user_id ON user_certifications(user_id);
CREATE INDEX idx_user_certifications_image_id ON user_certifications(image_id);

-- =====================================================
-- 4. Add users_profile_read permission
-- =====================================================
-- Check if permission already exists to avoid duplicates
-- If the permission already exists, this will be ignored (no error)
INSERT IGNORE INTO permissions (code, label, `group`, created_at, updated_at)
VALUES (
    'users_profile_read',
    'Can view user profiles',
    'users',
    NOW(),
    NOW()
);

-- Alternative method (if INSERT IGNORE doesn't work):
-- INSERT INTO permissions (code, label, `group`, created_at, updated_at)
-- SELECT 
--     'users_profile_read',
--     'Can view user profiles',
--     'users',
--     NOW(),
--     NOW()
-- WHERE NOT EXISTS (
--     SELECT 1 FROM permissions WHERE code = 'users_profile_read'
-- );

-- Commit Transaction
COMMIT;

-- =====================================================
-- Verification Queries (Optional - Run these to verify)
-- =====================================================
-- SELECT * FROM user_experiences LIMIT 1;
-- SELECT * FROM user_educations LIMIT 1;
-- SELECT * FROM user_certifications LIMIT 1;
-- SELECT * FROM permissions WHERE code = 'users_profile_read';

