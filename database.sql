-- Полная база данных для аниме сервиса (обновленная версия)
DROP DATABASE IF EXISTS anime_service;
CREATE DATABASE anime_service;
USE anime_service;

-- Таблица пользователей
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') DEFAULT 'user',
    avatar VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица аниме
CREATE TABLE anime (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    genre VARCHAR(100),
    year INT,
    studio VARCHAR(255) DEFAULT NULL,
    image_url VARCHAR(255),
    trailer_url VARCHAR(255) DEFAULT NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Таблица оценок (детализированная система)
CREATE TABLE ratings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    anime_id INT NOT NULL,
    story_rating INT CHECK (story_rating >= 1 AND story_rating <= 10),    -- Сюжет
    art_rating INT CHECK (art_rating >= 1 AND art_rating <= 10),          -- Рисовка
    characters_rating INT CHECK (characters_rating >= 1 AND characters_rating <= 10), -- Персонажи
    sound_rating INT CHECK (sound_rating >= 1 AND sound_rating <= 10),    -- Саундтреки
    overall_rating DECIMAL(3,1) GENERATED ALWAYS AS ((story_rating + art_rating + characters_rating + sound_rating) / 4) STORED, -- Общая оценка
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_anime (user_id, anime_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (anime_id) REFERENCES anime(id) ON DELETE CASCADE
);

-- Таблица комментариев
CREATE TABLE comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    anime_id INT NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (anime_id) REFERENCES anime(id) ON DELETE CASCADE
);

-- Таблица лайков комментариев
CREATE TABLE comment_likes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    comment_id INT NOT NULL,
    like_type ENUM('like', 'dislike') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_comment (user_id, comment_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
);

-- Таблица для отслеживания статусов аниме пользователей
CREATE TABLE user_anime_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    anime_id INT NOT NULL,
    status ENUM('planned', 'watching', 'completed', 'dropped') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_anime_status (user_id, anime_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (anime_id) REFERENCES anime(id) ON DELETE CASCADE
);

-- Таблица для отслеживания попыток входа и блокировок
CREATE TABLE login_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    failed_attempts INT DEFAULT 0,
    last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    blocked_until TIMESTAMP NULL,
    block_level INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_blocked_until (blocked_until)
);

-- Тестовые данные
INSERT INTO users (username, email, password, role) VALUES
('admin', 'admin@anime.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('otaku_master', 'otaku@anime.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user');

INSERT INTO anime (title, description, genre, year, created_by) VALUES
('Наруто', 'История о ниндзя, который мечтает стать Хокаге', 'Сёнэн, Экшен', 2002, 1),
('Атака титанов', 'Человечество борется с гигантскими титанами', 'Экшен, Драма', 2013, 1),
('Смерть Дефницы', 'Студент находит тетрадь смерти', 'Психологический триллер', 2006, 2),
('Твое имя', 'Романтическая история с элементами фантастики', 'Романтика, Фантастика', 2016, 2),
('Демонический разрушитель', 'Мальчик становится охотником на демонов', 'Сёнэн, Экшен', 2019, 1);

-- Тестовые детализированные оценки
INSERT INTO ratings (user_id, anime_id, story_rating, art_rating, characters_rating, sound_rating) VALUES
(1, 1, 9, 8, 10, 9),    -- Наруто: сюжет-9, рисовка-8, персонажи-10, звук-9
(1, 2, 10, 10, 9, 8),   -- Атака титанов: сюжет-10, рисовка-10, персонажи-9, звук-8
(1, 3, 10, 7, 9, 8),    -- Смерть Дефницы: сюжет-10, рисовка-7, персонажи-9, звук-8
(1, 4, 8, 10, 8, 10),   -- Твое имя: сюжет-8, рисовка-10, персонажи-8, звук-10
(1, 5, 8, 10, 8, 9),    -- Демонический разрушитель: сюжет-8, рисовка-10, персонажи-8, звук-9

(2, 1, 8, 7, 9, 8),     -- Наруто от второго пользователя
(2, 2, 9, 10, 8, 9),    -- Атака титанов
(2, 3, 10, 8, 10, 7),   -- Смерть Дефницы
(2, 4, 7, 9, 7, 9),     -- Твое имя
(2, 5, 6, 8, 7, 8);     -- Демонический разрушитель

INSERT INTO comments (user_id, anime_id, comment) VALUES
(1, 1, 'Классическое аниме! Наруто - лучший ниндзя!'),
(2, 2, 'Очень темное и атмосферное аниме. Рекомендую всем!'),
(1, 3, 'Гениальный психологический триллер. Лайт - злодей, но такой харизматичный!'),
(2, 4, 'Красивая анимация и трогательная история любви.'),
(1, 5, 'Отличная анимация боев, но сюжет мог быть лучше.');

-- Проверка создания всех таблиц
SHOW TABLES;
