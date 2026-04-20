-- ============================================================
-- ACCESS IEAF Database Schema — v2
-- Reflects actual form fields from both PDF forms
-- Run: mysql -u iaefuser -p dev < schema.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    user_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(255) NOT NULL UNIQUE,
    given_name   VARCHAR(100),
    surname      VARCHAR(100),
    role         ENUM('student','faculty','admin') NOT NULL DEFAULT 'student',
    entra_oid    VARCHAR(36),
    active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT NOW(),
    updated_at   DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW(),
    INDEX idx_role (role),
    INDEX idx_entra (entra_oid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS courses (
    course_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    crn          VARCHAR(20) NOT NULL UNIQUE,
    course_name  VARCHAR(255) NOT NULL,
    section      VARCHAR(20),
    term         VARCHAR(20) NOT NULL,
    faculty_id   INT UNSIGNED,
    active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT NOW(),
    CONSTRAINT fk_course_faculty
        FOREIGN KEY (faculty_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_faculty (faculty_id),
    INDEX idx_term (term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enrollments (
    enrollment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id    INT UNSIGNED NOT NULL,
    course_id     INT UNSIGNED NOT NULL,
    term          VARCHAR(20) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT NOW(),
    UNIQUE KEY uq_enrollment (student_id, course_id, term),
    CONSTRAINT fk_enroll_student FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_enroll_course  FOREIGN KEY (course_id)  REFERENCES courses(course_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── IEAF Submissions ──────────────────────────────────────────────────────
-- form_type: 'standard' = ACCESS_IEAF.pdf
--            'essential' = ACCESS_IEAF_Essential_Abilities.pdf
-- Status flow: draft → submitted → approved | rejected
CREATE TABLE IF NOT EXISTS ieaf_submissions (
    submission_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id      INT UNSIGNED NOT NULL,
    submitted_by   INT UNSIGNED NOT NULL,
    form_type      ENUM('standard','essential') NOT NULL DEFAULT 'standard',
    status         ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
    approved_by    INT UNSIGNED,
    approved_at    DATETIME,
    rejection_note TEXT,
    created_at     DATETIME NOT NULL DEFAULT NOW(),
    updated_at     DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW(),
    CONSTRAINT fk_sub_course   FOREIGN KEY (course_id)   REFERENCES courses(course_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sub_faculty  FOREIGN KEY (submitted_by) REFERENCES users(user_id)   ON DELETE RESTRICT,
    CONSTRAINT fk_sub_admin    FOREIGN KEY (approved_by)  REFERENCES users(user_id)   ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_course (course_id),
    INDEX idx_faculty (submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Standard IEAF Fields (ACCESS_IEAF.pdf) ────────────────────────────────
CREATE TABLE IF NOT EXISTS ieaf_standard (
    submission_id               INT UNSIGNED PRIMARY KEY,
    -- Header
    instructor_name             VARCHAR(255),
    instructor_email            VARCHAR(255),
    course_name                 VARCHAR(255),
    -- Checkboxes
    previously_filled           TINYINT(1) DEFAULT 0,
    has_updates                 TINYINT(1) DEFAULT 0,
    -- Agreement
    agreement_yes               TINYINT(1) DEFAULT 0,   -- "Are you in agreement..."
    -- Free text fields
    makeup_activities           TEXT,       -- activities student may make up + timeline
    cannot_miss_activities      TEXT,       -- activities student cannot miss/make up
    CONSTRAINT fk_std_sub FOREIGN KEY (submission_id) REFERENCES ieaf_submissions(submission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Essential Abilities IEAF Fields (ACCESS_IEAF_Essential_Abilities.pdf) ─
CREATE TABLE IF NOT EXISTS ieaf_essential (
    submission_id                       INT UNSIGNED PRIMARY KEY,
    -- Header
    instructor_name                     VARCHAR(255),
    instructor_email                    VARCHAR(255),
    course_name                         VARCHAR(255),
    -- Checkboxes
    previously_filled                   TINYINT(1) DEFAULT 0,
    has_updates                         TINYINT(1) DEFAULT 0,
    -- Page 1 fields
    syllabus_attendance_policy          TEXT,   -- "What does your course description/syllabus say..."
    attendance_graded                   TINYINT(1),   -- Yes/No
    classroom_practices                 TEXT,   -- "What are classroom practices and policies..."
    attendance_consistently_applied     TINYINT(1),   -- Yes/No
    attendance_essential_explanation    TEXT,   -- "If yes, explain how this relates..."
    classroom_interaction               TINYINT(1),   -- Yes/No
    interaction_essential_explanation   TEXT,   -- "If yes, explain how this relates..."
    -- Page 2 fields
    student_contributions_significant   TINYINT(1),   -- Yes/No
    contributions_explanation           TEXT,
    participation_method_of_learning    TINYINT(1),   -- Yes/No
    participation_explanation           TEXT,
    peer_impact                         TEXT,   -- "How can other student's experience impact..."
    content_only_in_class               TINYINT(1),   -- Yes/No
    alternative_activities              TEXT,   -- "describe alternative activities..."
    CONSTRAINT fk_ess_sub FOREIGN KEY (submission_id) REFERENCES ieaf_submissions(submission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Submission History (audit log) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS submission_history (
    history_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    changed_by    INT UNSIGNED NOT NULL,
    action        VARCHAR(50) NOT NULL,
    snapshot      JSON,
    note          TEXT,
    created_at    DATETIME NOT NULL DEFAULT NOW(),
    CONSTRAINT fk_hist_sub  FOREIGN KEY (submission_id) REFERENCES ieaf_submissions(submission_id) ON DELETE CASCADE,
    CONSTRAINT fk_hist_user FOREIGN KEY (changed_by)    REFERENCES users(user_id)                  ON DELETE RESTRICT,
    INDEX idx_sub (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Email Log ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS email_log (
    log_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED,
    recipient     VARCHAR(255) NOT NULL,
    subject       VARCHAR(255),
    type          VARCHAR(50),
    sent_at       DATETIME NOT NULL DEFAULT NOW(),
    success       TINYINT(1) NOT NULL DEFAULT 1,
    error_msg     TEXT,
    INDEX idx_sub (submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
