Kingspark College CBT System - MVP Setup Instructions
-------------------------------------------------------

1. Database Setup:
   - Open XAMPP Control Panel and start Apache and MySQL.
   - Go to phpMyAdmin (http://localhost/phpmyadmin).
   - Create a new database named `kingspark_cbt`.
   - Import the `database.sql` file provided in the root directory.

2. Database Connection:
   - Open `db.php` in the root directory.
   - Ensure the database credentials (host, dbname, user, password) match your local MySQL setup.

3. Admin Access:
   - Go to http://localhost/Kingspark/admin/login.php
   - Default Username: admin
   - Default Password: password

4. Student Access:
   - Go to http://localhost/Kingspark/index.php
   - Use the registration number and name of a student you registered via the Admin Panel.

5. Key Features:
   - Admin can manage classes, subjects, questions (MCQ and Theory), and exams.
   - Students can log in, view available exams, take exams with a countdown timer, and view results.
   - MCQ questions are auto-graded; Theory questions can be manually reviewed and graded by the admin.

Folder Structure:
- /admin: Admin panel pages.
- /includes: Common header and footer files.
- /assets: Logos and images.
- /: Student-facing pages and database connection.
