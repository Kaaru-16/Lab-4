CREATE DATABASE IF NOT EXISTS task_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE task_db;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO categories (name) VALUES ('Coursework'), ('Study'), ('Personal')
    ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_name VARCHAR(255) NOT NULL,
    task_description TEXT NOT NULL,
    status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    due_at DATETIME DEFAULT NULL,
    priority ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    category VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'Coursework',
    CONSTRAINT tasks_category_fk FOREIGN KEY (category) REFERENCES categories (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
