# Teacher Performance Evaluation System

PHP + SQLite – Ambo University style evaluation with roles, peer groups, course assignment, and weighted scores.

## Weights

| Source | Weight |
|--------|--------|
| Department Head | 30% |
| Peer (same group) | 20% |
| Students | 50% |

## Default login

- Username: `head`
- Password: `head123`

## Install (XAMPP)

See **INSTALL.txt** for full steps.

Quick:

1. Delete old `htdocs/teacher_evaluation` folder completely  
2. Extract this zip into `htdocs`  
3. Enable `pdo_sqlite` + `sqlite3` in php.ini, restart Apache  
4. Open `http://localhost/teacher_evaluation/public/setup.php?action=reset`  
5. Login at `http://localhost/teacher_evaluation/public/login.php`

## Workflow

1. Head logs in → **Users** → create instructors & students (set student batch)  
2. **Peer Groups** → create groups, add instructors  
3. **Courses** → create courses, assign to instructor + batch  
4. Head evaluates teachers (30%)  
5. Instructors peer-evaluate group members (20%)  
6. Students evaluate teachers assigned to their batch (50%)  
7. Head opens **Final Report** for weighted scores  

## Requirements

- PHP 8.0+ with PDO SQLite
