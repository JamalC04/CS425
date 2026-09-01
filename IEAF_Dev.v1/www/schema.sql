-- ============================================================
-- ACCESS IEAF Portal — Production Schema
-- MariaDB 10.x+
-- Run: mysql -u iaefuser -p dev  < schema.sql   (dev first)
--      mysql -u iaefuser -p prod < schema.sql   (then prod)
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    user_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(255) NOT NULL UNIQUE,
    given_name VARCHAR(100) NOT NULL DEFAULT '',
    surname    VARCHAR(100) NOT NULL DEFAULT '',
    role       ENUM('student','faculty','admin') NOT NULL DEFAULT 'student',
    entra_oid  VARCHAR(36)  DEFAULT NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT NOW(),
    updated_at DATETIME     NOT NULL DEFAULT NOW() ON UPDATE NOW(),
    INDEX idx_role(role), INDEX idx_entra(entra_oid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS courses (
    course_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    crn         VARCHAR(20)  NOT NULL UNIQUE,
    course_name VARCHAR(255) NOT NULL,
    section     VARCHAR(20)  DEFAULT NULL,
    term        VARCHAR(20)  NOT NULL,
    faculty_id  INT UNSIGNED DEFAULT NULL,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT NOW(),
    CONSTRAINT fk_c_faculty FOREIGN KEY (faculty_id) REFERENCES users(user_id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_c_faculty(faculty_id), INDEX idx_c_term(term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enrollments (
    enrollment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id    INT UNSIGNED NOT NULL,
    course_id     INT UNSIGNED NOT NULL,
    term          VARCHAR(20)  NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT NOW(),
    UNIQUE KEY uq_enroll(student_id, course_id, term),
    CONSTRAINT fk_e_student FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_e_course  FOREIGN KEY (course_id)  REFERENCES courses(course_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ieaf_submissions (
    submission_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id      INT UNSIGNED NOT NULL,
    submitted_by   INT UNSIGNED NOT NULL,
    form_type      ENUM('standard','essential') NOT NULL DEFAULT 'standard',
    status         ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft',
    approved_by    INT UNSIGNED DEFAULT NULL,
    approved_at    DATETIME     DEFAULT NULL,
    rejection_note TEXT         DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT NOW(),
    updated_at     DATETIME     NOT NULL DEFAULT NOW() ON UPDATE NOW(),
    CONSTRAINT fk_s_course  FOREIGN KEY (course_id)    REFERENCES courses(course_id) ON DELETE RESTRICT,
    CONSTRAINT fk_s_faculty FOREIGN KEY (submitted_by) REFERENCES users(user_id)    ON DELETE RESTRICT,
    CONSTRAINT fk_s_admin   FOREIGN KEY (approved_by)  REFERENCES users(user_id)    ON DELETE SET NULL,
    INDEX idx_s_status(status), INDEX idx_s_course(course_id), INDEX idx_s_faculty(submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ieaf_standard (
    submission_id          INT UNSIGNED PRIMARY KEY,
    instructor_name        VARCHAR(255) DEFAULT NULL,
    instructor_email       VARCHAR(255) DEFAULT NULL,
    course_name            VARCHAR(255) DEFAULT NULL,
    previously_filled      TINYINT(1)   DEFAULT 0,
    has_updates            TINYINT(1)   DEFAULT 0,
    agreement_yes          TINYINT(1)   DEFAULT 0,
    makeup_activities      TEXT         DEFAULT NULL,
    cannot_miss_activities TEXT         DEFAULT NULL,
    CONSTRAINT fk_std_sub FOREIGN KEY (submission_id) REFERENCES ieaf_submissions(submission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ieaf_essential (
    submission_id                    INT UNSIGNED PRIMARY KEY,
    instructor_name                  VARCHAR(255) DEFAULT NULL,
    instructor_email                 VARCHAR(255) DEFAULT NULL,
    course_name                      VARCHAR(255) DEFAULT NULL,
    previously_filled                TINYINT(1)   DEFAULT 0,
    has_updates                      TINYINT(1)   DEFAULT 0,
    syllabus_attendance_policy       TEXT         DEFAULT NULL,
    attendance_graded                TINYINT(1)   DEFAULT NULL,
    classroom_practices              TEXT         DEFAULT NULL,
    attendance_consistently_applied  TINYINT(1)   DEFAULT NULL,
    attendance_essential_explanation TEXT         DEFAULT NULL,
    classroom_interaction            TINYINT(1)   DEFAULT NULL,
    interaction_essential_explanation TEXT        DEFAULT NULL,
    student_contributions_significant TINYINT(1)  DEFAULT NULL,
    contributions_explanation        TEXT         DEFAULT NULL,
    participation_method_of_learning TINYINT(1)  DEFAULT NULL,
    participation_explanation        TEXT         DEFAULT NULL,
    peer_impact                      TEXT         DEFAULT NULL,
    content_only_in_class            TINYINT(1)  DEFAULT NULL,
    alternative_activities           TEXT         DEFAULT NULL,
    CONSTRAINT fk_ess_sub FOREIGN KEY (submission_id) REFERENCES ieaf_submissions(submission_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS submission_history (
    history_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    changed_by    INT UNSIGNED NOT NULL,
    action        VARCHAR(50)  NOT NULL,
    snapshot      JSON         DEFAULT NULL,
    note          TEXT         DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT NOW(),
    CONSTRAINT fk_h_sub  FOREIGN KEY (submission_id) REFERENCES ieaf_submissions(submission_id) ON DELETE CASCADE,
    CONSTRAINT fk_h_user FOREIGN KEY (changed_by)    REFERENCES users(user_id) ON DELETE RESTRICT,
    INDEX idx_h_sub(submission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


SET FOREIGN_KEY_CHECKS = 1;
