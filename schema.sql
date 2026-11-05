-- Teacher Performance Evaluation System (Role-based)
PRAGMA foreign_keys = ON;

-- Users (login accounts)
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,              -- password_hash()
    full_name TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('head','instructor','student')),
    email TEXT,
    is_active INTEGER DEFAULT 1,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Instructors profile (linked to user with role=instructor)
CREATE TABLE IF NOT EXISTS instructors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    title TEXT DEFAULT '',
    department TEXT DEFAULT 'IT Department',
    school TEXT DEFAULT 'School of Informatics and Electrical Engineering',
    university TEXT DEFAULT 'AMBO UNIVERSITY',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Students profile
CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    batch TEXT,
    program TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Peer groups created by Head
CREATE TABLE IF NOT EXISTS peer_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_name TEXT NOT NULL,
    academic_year TEXT,
    created_by INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Members of peer groups (instructors only)
CREATE TABLE IF NOT EXISTS peer_group_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    instructor_id INTEGER NOT NULL,
    UNIQUE(group_id, instructor_id),
    FOREIGN KEY (group_id) REFERENCES peer_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE
);

-- Courses
CREATE TABLE IF NOT EXISTS courses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    course_code TEXT NOT NULL,
    course_title TEXT NOT NULL,
    semester TEXT,
    academic_year TEXT,
    batch TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Head assigns course to instructor (and optionally to a student batch)
CREATE TABLE IF NOT EXISTS course_assignments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id INTEGER NOT NULL,
    instructor_id INTEGER NOT NULL,
    academic_year TEXT,
    semester TEXT,
    batch TEXT,                         -- which student batch can evaluate
    UNIQUE(course_id, instructor_id, academic_year, semester),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE
);

-- Evaluation criteria (fixed 16)
CREATE TABLE IF NOT EXISTS criteria (
    id INTEGER PRIMARY KEY,
    category TEXT NOT NULL,
    category_name TEXT NOT NULL,
    criterion_text TEXT NOT NULL,
    sort_order INTEGER NOT NULL
);

-- Evaluations
CREATE TABLE IF NOT EXISTS evaluations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instructor_id INTEGER NOT NULL,           -- who is being evaluated
    evaluator_user_id INTEGER NOT NULL,       -- who submitted
    evaluator_type TEXT NOT NULL CHECK(evaluator_type IN ('head','peer','student')),
    course_id INTEGER,                        -- mainly for student evaluations
    academic_year TEXT,
    semester TEXT,
    strengths TEXT,
    improvements TEXT,
    total_score REAL,
    average_rating REAL,
    evaluation_date DATE DEFAULT (date('now')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_user_id) REFERENCES users(id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    -- prevent duplicate evaluation of same instructor by same person for same course/type
    UNIQUE(instructor_id, evaluator_user_id, evaluator_type, course_id)
);

CREATE TABLE IF NOT EXISTS evaluation_ratings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evaluation_id INTEGER NOT NULL,
    criterion_id INTEGER NOT NULL,
    rating INTEGER NOT NULL CHECK(rating BETWEEN 0 AND 5),
    FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
    FOREIGN KEY (criterion_id) REFERENCES criteria(id),
    UNIQUE(evaluation_id, criterion_id)
);

-- Seed criteria
INSERT OR IGNORE INTO criteria (id, category, category_name, criterion_text, sort_order) VALUES
(1,  'A', 'Instructional Skills', 'Clarity of course objectives, content, and explanations', 1),
(2,  'A', 'Instructional Skills', 'Course coverage and time management', 2),
(3,  'A', 'Instructional Skills', 'Teaching methods and student participation', 3),
(4,  'A', 'Instructional Skills', 'Response to questions and student support', 4),
(5,  'A', 'Instructional Skills', 'Tutorials, make-up classes, and consultation', 5),
(6,  'B', 'Professional Competency', 'Subject knowledge and professional development', 6),
(7,  'B', 'Professional Competency', 'Teaching materials preparation and improvement', 7),
(8,  'B', 'Professional Competency', 'Assessment methods, timely feedback, and continuous evaluation', 8),
(9,  'B', 'Professional Competency', 'Research, seminars, and community service participation', 9),
(10, 'B', 'Professional Competency', 'Academic advising, mentoring, and student guidance', 10),
(11, 'C', 'Professional Ethics and Teamwork', 'Punctuality, attendance, and effective use of time', 11),
(12, 'C', 'Professional Ethics and Teamwork', 'Respect, positive attitude, and professional conduct', 12),
(13, 'C', 'Professional Ethics and Teamwork', 'Cooperation, teamwork, and committee participation', 13),
(14, 'C', 'Professional Ethics and Teamwork', 'Compliance with university rules and guidelines', 14),
(15, 'C', 'Professional Ethics and Teamwork', 'Responsible use and sharing of university resources', 15),
(16, 'D', 'Overall Performance', 'Contribution to teaching quality and departmental goals', 16);

-- Default Department Head account
-- username: head  password: head123
INSERT OR IGNORE INTO users (id, username, password, full_name, role, email) VALUES
(1, 'head', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Department Head', 'head', 'head@ambo.edu.et');
-- Note: password hash above is for 'password'. We will set a proper one in PHP init.
