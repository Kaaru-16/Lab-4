CREATE DATABASE IF NOT EXISTS task_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE task_db;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_name VARCHAR(255) NOT NULL,
    task_description TEXT NOT NULL,
    status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_at DATETIME DEFAULT NULL,
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    category VARCHAR(50) NOT NULL DEFAULT 'Coursework',
    reminder TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT tasks_category_delete_fk FOREIGN KEY (category) REFERENCES categories (name) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade the earlier Lab 5 schema in place when it is already installed.
SET @task_upgrade = IF(
    EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND CONSTRAINT_NAME = 'tasks_category_fk'
    ),
    'ALTER TABLE tasks ADD COLUMN reminder TINYINT(1) NOT NULL DEFAULT 0,
        DROP FOREIGN KEY tasks_category_fk,
        ADD CONSTRAINT tasks_category_delete_fk FOREIGN KEY (category) REFERENCES categories (name) ON DELETE CASCADE',
    'DO 0'
);
PREPARE task_upgrade FROM @task_upgrade;
EXECUTE task_upgrade;
DEALLOCATE PREPARE task_upgrade;

CREATE TABLE IF NOT EXISTS setup_steps (
    step VARCHAR(40) PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Install the starter board once, when setting up a new database.
START TRANSACTION;
INSERT IGNORE INTO setup_steps (step) VALUES ('starter_board');
SET @seed_board = ROW_COUNT() = 1
    AND NOT EXISTS (SELECT 1 FROM categories)
    AND NOT EXISTS (SELECT 1 FROM tasks);
SET @board_today = DATE(UTC_TIMESTAMP() + INTERVAL 8 HOUR);

INSERT INTO categories (name)
SELECT name FROM (
    SELECT 'Coursework' AS name
    UNION ALL SELECT 'Study'
    UNION ALL SELECT 'Personal'
) AS defaults
WHERE @seed_board;

INSERT INTO tasks (task_name, task_description, due_at, priority, category, status)
SELECT task_name, task_description, due_at, priority, category, status
FROM (
    SELECT 'Prepare Lab Activity 5' AS task_name,
           'Review the requirements and test the database-backed board.' AS task_description,
           TIMESTAMP(@board_today + INTERVAL 1 DAY, '10:00:00') AS due_at,
           'high' AS priority, 'Coursework' AS category, 'pending' AS status
    UNION ALL SELECT 'Review PHP forms', 'Read the notes on POST and validation.',
           TIMESTAMP(@board_today + INTERVAL 1 DAY, '13:00:00'), 'medium', 'Study', 'pending'
    UNION ALL SELECT 'Organize project files', 'Group references, screenshots, and notes.',
           TIMESTAMP(@board_today + INTERVAL 2 DAY, '09:00:00'), 'low', 'Personal', 'pending'
    UNION ALL SELECT 'Design the task manager', 'Refine the board, form, and task controls.',
           TIMESTAMP(@board_today - INTERVAL 1 DAY, '11:30:00'), 'high', 'Coursework', 'pending'
    UNION ALL SELECT 'Study PDO persistence', 'Review how the database saves tasks across sessions.',
           TIMESTAMP(@board_today - INTERVAL 1 DAY, '14:00:00'), 'medium', 'Study', 'pending'
    UNION ALL SELECT 'Read the lab brief', 'List the required CRUD operations.',
           TIMESTAMP(@board_today, '09:00:00'), 'low', 'Coursework', 'completed'
    UNION ALL SELECT 'Set up the workspace', 'Create the project folder and collect assets.',
           TIMESTAMP(@board_today, '09:00:00'), 'low', 'Coursework', 'completed'
) AS starter_tasks
WHERE @seed_board;

COMMIT;
