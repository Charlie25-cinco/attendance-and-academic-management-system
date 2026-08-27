-- =============================================================================
-- Balingasag Senior High School - Attendance and Academic Management System
-- SEED DATA
-- =============================================================================
-- Run after schema.sql has been imported.
-- All inserts use IGNORE for idempotency (safe to re-run).
-- =============================================================================

-- =============================================================================
-- DEFAULT SYSTEM ACCOUNTS
-- =============================================================================
-- Admin credentials must not be hardcoded in SQL.
-- Create the first admin with: composer run seed:admin
-- The password comes from FIRST_RUN_ADMIN_PASSWORD in .env.

-- =============================================================================
-- WEBSITE CONTENT (admin-managed school website pages)
-- =============================================================================
INSERT IGNORE INTO website_content (section_key, title, content) VALUES
('hero_title', 'Welcome to Balingasag Senior High School',
 'Nurturing excellence, building futures. A DepEd-accredited Senior High School in Balingasag, Misamis Oriental.'),
('about', 'About Our School',
 'Balingasag Senior High School (BSHS) is committed to providing quality education for Senior High School students in the municipality of Balingasag. We offer various tracks and strands aligned with the K to 12 curriculum of the Department of Education.'),
('contact_address', 'Address',
 'Balingasag, Misamis Oriental, Philippines'),
('contact_email', 'Email',
 'balingasagshs@deped.gov.ph'),
('contact_phone', 'Phone',
 '(088) 000-0000'),
('contact_hours', 'Office Hours',
 'Monday – Friday: 7:00 AM – 5:00 PM');

-- =============================================================================
-- ACADEMIC YEAR SETTINGS
-- =============================================================================
INSERT IGNORE INTO academic_year_settings (academic_year, grading_system) VALUES
('2025-2026', '4_quarter'),
('2026-2027', '3_term');

-- =============================================================================
-- SCHOOL SETTINGS
-- =============================================================================
INSERT IGNORE INTO school_settings (setting_key, setting_value) VALUES
('school_name', 'Balingasag Senior High School'),
('school_id', '341227'),
('district', 'Balingasag North'),
('division', 'Misamis Oriental'),
('region', 'Region X'),
('school_address', 'Balingasag, Misamis Oriental, Philippines');
