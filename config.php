<?php
// Включаем отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Настройки для загрузки больших файлов
ini_set('upload_max_filesize', '250M');
ini_set('post_max_size', '250M');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '600');
ini_set('max_input_time', '600');
ini_set('max_file_uploads', '20');

// Настройки сессий и буферизации
ini_set('session.gc_maxlifetime', '3600');
ini_set('output_buffering', '4096');
ini_set('implicit_flush', 'Off');

// Конфигурация базы данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'anime_service');
define('DB_USER', 'root');
define('DB_PASS', '');

// Настройки сайта
define('SITE_URL', 'http://localhost');
define('SITE_NAME', 'waka-waka');

// Инициализация сессий
session_start();

// Подключение к базе данных
function getDB() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("Ошибка подключения к базе данных: " . $e->getMessage());
    }
}

// Проверка авторизации
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Получение информации о текущем пользователе
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Функция для безопасного вывода HTML
function h($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Функция для конвертации размеров типа "250M" в байты
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;

    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }

    return $val;
}

// Функция для форматирования даты
function formatDate($date) {
    return date('d.m.Y H:i', strtotime($date));
}

// Функция для проверки роли администратора
function isAdmin() {
    $user = getCurrentUser();
    return $user && $user['role'] === 'admin';
}

// Функция для проверки прав администратора с редиректом
function requireAdmin() {
    if (!isAdmin()) {
        redirect('index.php');
    }
}

// Функция для редиректа
function redirect($url) {
    header("Location: $url");
    exit();
}

// Функция для получения доступных жанров
function getAvailableGenres() {
    return [
        'Экшен', 'Приключения', 'Фантастика', 'Фэнтези', 'Комедия', 'Драма',
        'Романтика', 'Ужасы', 'Мистика', 'Меха', 'Спорт', 'Музыка',
        'Исторический', 'Психологический', 'Слэш', 'Школа', 'Сёнэн', 'Сёдзё',
        'Исекай', 'Супергеройский', 'Полнометражный', 'Ова', 'Изометрия', 'Триллер'
    ];
}

// Функция для преобразования строки жанров в массив
function parseGenres($genresString) {
    if (empty($genresString)) {
        return [];
    }
    return array_map('trim', explode(',', $genresString));
}

// Функция для преобразования массива жанров в строку
function formatGenres($genresArray) {
    if (empty($genresArray)) {
        return '';
    }
    return implode(', ', $genresArray);
}

// ================ СИСТЕМА КАПЧИ ================

// Генерация символьной капчи из 5 символов/цифр
function generateCaptcha() {
    // Символы для генерации (исключаем похожие: 0, O, I, l, 1)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';

    // Генерируем 5 случайных символов
    for ($i = 0; $i < 5; $i++) {
        $code .= $chars[rand(0, strlen($chars) - 1)];
    }

    $_SESSION['captcha_answer'] = $code;
    $_SESSION['captcha_display'] = generateDistortedDisplay($code);

    return [
        'code' => $code,
        'display' => $_SESSION['captcha_display']
    ];
}

// Создание искаженного отображения кода
function generateDistortedDisplay($code) {
    $distorted = '';
    $chars = str_split($code);

    // Различные стили искажения
    $styles = [
        'color: #2d3748; font-size: 24px; letter-spacing: 8px; font-weight: bold;',
        'color: #4a5568; font-size: 22px; letter-spacing: 6px; font-weight: bold; text-shadow: 1px 1px 2px rgba(0,0,0,0.3);',
        'color: #1a202c; font-size: 26px; letter-spacing: 10px; font-weight: bold; transform: skew(-5deg);',
        'color: #2b6cb0; font-size: 23px; letter-spacing: 7px; font-weight: bold; text-decoration: line-through;'
    ];

    $selectedStyle = $styles[array_rand($styles)];

    // Добавляем случайные символы между основными
    $noise = ['·', '‧', '∙', '•', '◦'];

    for ($i = 0; $i < count($chars); $i++) {
        $distorted .= $chars[$i];

        // Добавляем шум между символами (иногда)
        if ($i < count($chars) - 1 && rand(0, 2) == 0) {
            $distorted .= '<span style="color: #cbd5e0; font-size: 12px;">' . $noise[array_rand($noise)] . '</span>';
        }
    }

    return '<span style="' . $selectedStyle . '">' . $distorted . '</span>';
}

// Проверка капчи
function validateCaptcha($userAnswer) {
    if (!isset($_SESSION['captcha_answer'])) {
        return false;
    }

    $isValid = strtoupper(trim($userAnswer)) === $_SESSION['captcha_answer'];

    // Очищаем капчу после проверки для безопасности
    unset($_SESSION['captcha_answer']);
    unset($_SESSION['captcha_display']);

    return $isValid;
}

// Получение текущего отображения капчи
function getCaptchaDisplay() {
    return $_SESSION['captcha_display'] ?? null;
}

// Генерация визуальной капчи с подсказками
function generateVisualCaptcha() {
    $captcha = generateCaptcha();

    $instructions = [
        'Введите символы как показано (без учета регистра)',
        'Перепишите код точно как видите',
        'Введите 5 символов из изображения выше',
        'Скопируйте код, игнорируя точки между символами'
    ];

    $instruction = $instructions[array_rand($instructions)];

    return [
        'display' => $captcha['display'],
        'instruction' => $instruction,
        'code' => $captcha['code']
    ];
}

// AJAX обновление капчи
function refreshCaptchaAjax() {
    if (isset($_POST['action']) && $_POST['action'] === 'refresh_captcha') {
        $captcha = generateVisualCaptcha();

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'display' => $captcha['display'],
            'instruction' => $captcha['instruction']
        ]);
        exit();
    }
}

// ================ CSRF ЗАЩИТА ================

// Генерация CSRF токена
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Проверка CSRF токена
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// HTML для скрытого поля с CSRF токеном
function csrfTokenField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

// ================ СИСТЕМА БЛОКИРОВКИ ПОЛЬЗОВАТЕЛЕЙ ================

// Настройки блокировки
define('MAX_LOGIN_ATTEMPTS', 5); // Максимум попыток до блокировки
define('BLOCK_DURATIONS', [5, 15, 30, 60, 120]); // Минуты блокировки по уровням

// Проверка, заблокирован ли пользователь
function isUserBlocked($username) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE username = ? AND blocked_until > NOW()");
    $stmt->execute([$username]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result ? $result : false;
}

// Форматирование времени блокировки
function formatBlockedTime($blockedUntil) {
    $now = new DateTime();
    $blockEnd = new DateTime($blockedUntil);
    $diff = $now->diff($blockEnd);

    if ($diff->i > 0) {
        return $diff->i . ' мин.';
    } else {
        return $diff->s . ' сек.';
    }
}

// Запись неудачной попытки входа
function recordFailedLogin($username) {
    $pdo = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Проверяем, есть ли запись для этого пользователя
    $stmt = $pdo->prepare("SELECT * FROM login_attempts WHERE username = ?");
    $stmt->execute([$username]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($attempt) {
        $failedAttempts = $attempt['failed_attempts'] + 1;
        $blockLevel = $attempt['block_level'];
        $blockedUntil = null;

        // Если достигли лимита попыток
        if ($failedAttempts >= MAX_LOGIN_ATTEMPTS) {
            $blockLevel++;
            $blockDuration = getBlockDuration($blockLevel - 1);
            $blockedUntil = date('Y-m-d H:i:s', time() + ($blockDuration * 60));
            $failedAttempts = 0; // Сбрасываем счетчик при блокировке
        }

        $stmt = $pdo->prepare("UPDATE login_attempts SET failed_attempts = ?, ip_address = ?, block_level = ?, blocked_until = ? WHERE username = ?");
        $stmt->execute([$failedAttempts, $ip, $blockLevel, $blockedUntil, $username]);
    } else {
        // Создаем новую запись
        $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address, failed_attempts) VALUES (?, ?, 1)");
        $stmt->execute([$username, $ip]);
    }
}

// Сброс попыток входа при успешном входе
function resetLoginAttempts($username) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE login_attempts SET failed_attempts = 0, blocked_until = NULL WHERE username = ?");
    $stmt->execute([$username]);
}

// Получение продолжительности блокировки по уровню
function getBlockDuration($level) {
    $durations = BLOCK_DURATIONS;
    if ($level >= count($durations)) {
        return end($durations);
    }
    return $durations[$level];
}

// Получение информации о следующей блокировке
function getNextBlockInfo($username) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT failed_attempts, block_level FROM login_attempts WHERE username = ?");
    $stmt->execute([$username]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attempt) {
        $remaining = MAX_LOGIN_ATTEMPTS - 1;
        return "Осталось попыток: $remaining";
    }

    $remaining = MAX_LOGIN_ATTEMPTS - $attempt['failed_attempts'];
    if ($remaining <= 0) {
        return "";
    }

    $nextBlockDuration = getBlockDuration($attempt['block_level']);
    return "Осталось попыток: $remaining. При превышении блокировка на $nextBlockDuration мин.";
}

?>
