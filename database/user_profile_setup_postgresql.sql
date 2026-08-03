-- =====================================================
-- User Profile Feature - Database Setup Script
-- PostgreSQL Version
-- =====================================================
-- This script creates tables for user experiences, 
-- educations, certifications, and adds the permission
-- =====================================================

-- Start Transaction
BEGIN;

-- =====================================================
-- 1. Create user_experiences table
-- =====================================================
CREATE TABLE IF NOT EXISTS user_experiences (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    job_title VARCHAR(255) NOT NULL,
    location VARCHAR(255) NULL,
    employment_type VARCHAR(20) NOT NULL DEFAULT 'full-time',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_current BOOLEAN NOT NULL DEFAULT false,
    description TEXT NULL,
    "order" INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT fk_user_experiences_user_id 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT chk_employment_type 
        CHECK (employment_type IN ('full-time', 'part-time', 'contract', 'freelance', 'internship'))
);

-- Create index on user_id for better performance
CREATE INDEX IF NOT EXISTS idx_user_experiences_user_id ON user_experiences(user_id);

-- =====================================================
-- 2. Create user_educations table
-- =====================================================
CREATE TABLE IF NOT EXISTS user_educations (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    school_name VARCHAR(255) NOT NULL,
    degree VARCHAR(255) NOT NULL,
    field_of_study VARCHAR(255) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_current BOOLEAN NOT NULL DEFAULT false,
    gpa VARCHAR(50) NULL,
    location VARCHAR(255) NULL,
    description TEXT NULL,
    "order" INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT fk_user_educations_user_id 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE
);

-- Create index on user_id for better performance
CREATE INDEX IF NOT EXISTS idx_user_educations_user_id ON user_educations(user_id);

-- =====================================================
-- 3. Create user_certifications table
-- =====================================================
CREATE TABLE IF NOT EXISTS user_certifications (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    issuing_organization VARCHAR(255) NOT NULL,
    issue_date DATE NOT NULL,
    expiry_date DATE NULL,
    credential_id VARCHAR(255) NULL,
    credential_url VARCHAR(500) NULL,
    description TEXT NULL,
    image_id BIGINT NULL,
    "order" INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT fk_user_certifications_user_id 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE,
    CONSTRAINT fk_user_certifications_image_id 
        FOREIGN KEY (image_id) 
        REFERENCES images(id) 
        ON DELETE SET NULL,
    CONSTRAINT chk_expiry_after_issue 
        CHECK (expiry_date IS NULL OR expiry_date >= issue_date)
);

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_user_certifications_user_id ON user_certifications(user_id);
CREATE INDEX IF NOT EXISTS idx_user_certifications_image_id ON user_certifications(image_id);

-- =====================================================
-- 4. Add users_profile_read permission
-- =====================================================
-- Check if permission already exists to avoid duplicates
INSERT INTO permissions (code, label, "group", created_at, updated_at)
SELECT 
    'users_profile_read',
    'Can view user profiles',
    'users',
    NOW(),
    NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM permissions WHERE code = 'users_profile_read'
);

-- Commit Transaction
COMMIT;

-- =====================================================
-- Verification Queries (Optional - Run these to verify)
-- =====================================================
-- SELECT * FROM user_experiences LIMIT 1;
-- SELECT * FROM user_educations LIMIT 1;
-- SELECT * FROM user_certifications LIMIT 1;
-- SELECT * FROM permissions WHERE code = 'users_profile_read';

