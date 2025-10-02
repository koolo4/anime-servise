<?php
require_once 'config.php';

// AJAX обновление CSRF токена
if (isset($_GET['ajax']) && $_GET['ajax'] == 'refresh_token') {
    // Принудительно создаем новый токен
    unset($_SESSION['csrf_token']);
    unset($_SESSION['csrf_token_time']);

    $newToken = generateCSRFToken();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'token' => $newToken,
        'timestamp' => time()
    ]);
    exit();
}

// Проверяем права администратора
requireAdmin();

$currentUser = getCurrentUser();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Получаем реальные лимиты сервера
    $maxUpload = ini_get('upload_max_filesize');
    $maxPost = ini_get('post_max_size');

    // Конвертируем в байты для сравнения
    function convertToBytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $num = (int)$val;
        switch($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }

    $maxUploadBytes = convertToBytes($maxUpload);
    $maxPostBytes = convertToBytes($maxPost);
    $effectiveLimit = min($maxUploadBytes, $maxPostBytes);

    // Проверка на превышение лимитов POST
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $uploadedSize = round($_SERVER['CONTENT_LENGTH'] / 1024 / 1024, 1);
        $maxSizeMB = round($effectiveLimit / 1024 / 1024, 1);
        $error = "Превышен максимальный размер POST данных ({$maxPost}). Попробуйте загрузить файл меньшего размера. Загружено: {$uploadedSize}MB, максимум: {$maxSizeMB}MB";
    }
    // Дополнительная проверка размера POST данных
    elseif (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > $effectiveLimit) {
        $uploadedSize = round($_SERVER['CONTENT_LENGTH'] / 1024 / 1024, 1);
        $maxSizeMB = round($effectiveLimit / 1024 / 1024, 1);
        $error = "Файл слишком большой. Максимальный размер: {$maxSizeMB}MB, ваш файл: {$uploadedSize}MB";
    }

    // Проверка CSRF токена (более мягкая проверка)
    if (!$error) {
        if (!isset($_POST['csrf_token'])) {
            $error = 'Отсутствует токен безопасности. Обновите страницу.';
        } elseif (!validateCSRFToken($_POST['csrf_token'])) {
            // Генерируем новый токен и даем пользователю второй шанс
            generateCSRFToken();
            $error = 'Токен безопасности устарел. Нажмите "Обновить токен безопасности" и попробуйте снова.';
        }
    }

    if (!$error) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $genres = $_POST['genres'] ?? [];
        $genre = formatGenres($genres);
        $year = (int)$_POST['year'];
        $studio = trim($_POST['studio']);
        $image_url = trim($_POST['image_url']);

        // Определяем способ добавления трейлера
        $trailer_method = $_POST['trailer_method'] ?? 'url';
        $trailer_url = null;
        $trailer_file_path = null;

        // Получаем детализированные оценки
        $story_rating = (int)$_POST['story_rating'];
        $art_rating = (int)$_POST['art_rating'];
        $characters_rating = (int)$_POST['characters_rating'];
        $sound_rating = (int)$_POST['sound_rating'];

        // Валидация
        if (empty($title) || empty($description) || empty($genre)) {
            $error = 'Заполните обязательные поля';
        } elseif ($year < 1900 || $year > date('Y') + 5) {
            $error = 'Некорректный год';
        } elseif ($story_rating < 1 || $story_rating > 10 ||
                  $art_rating < 1 || $art_rating > 10 ||
                  $characters_rating < 1 || $characters_rating > 10 ||
                  $sound_rating < 1 || $sound_rating > 10) {
            $error = 'Все оценки должны быть от 1 до 10';
        } else {
            // Обработка трейлера
            if ($trailer_method === 'url') {
                $trailer_url = trim($_POST['trailer_url']);
            } elseif ($trailer_method === 'upload' && isset($_FILES['trailer_file'])) {
                // Проверяем ошибки загрузки файла
                if ($_FILES['trailer_file']['error'] !== UPLOAD_ERR_OK) {
                    switch ($_FILES['trailer_file']['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                            $maxSize = ini_get('upload_max_filesize');
                            $error = "Файл превышает максимальный размер сервера ({$maxSize}). Попробуйте сжать видео или выберите файл меньшего размера.";
                            break;
                        case UPLOAD_ERR_FORM_SIZE:
                            $error = 'Файл превышает максимальный размер формы (250MB). Попробуйте сжать видео или выберите файл меньшего размера.';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $error = 'Загрузка была прервана. Проверьте интернет-соединение и попробуйте еще раз.';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $error = 'Файл не был выбран.';
                            break;
                        case UPLOAD_ERR_NO_TMP_DIR:
                            $error = 'Ошибка сервера: отсутствует временная папка.';
                            break;
                        case UPLOAD_ERR_CANT_WRITE:
                            $error = 'Ошибка сервера: не удается записать файл на диск.';
                            break;
                        default:
                            $error = 'Ошибка при загрузке файла (код: ' . $_FILES['trailer_file']['error'] . '). Попробуйте еще раз.';
                    }
                } else {
                $allowedTypes = ['video/mp4', 'video/webm', 'video/avi'];
                // Используем реальный лимит сервера вместо фиксированного
                $maxSize = $effectiveLimit; // Используем лимит, рассчитанный выше
                $maxSizeMB = round($maxSize / 1024 / 1024, 1);
                $fileType = $_FILES['trailer_file']['type'];
                $fileSize = $_FILES['trailer_file']['size'];
                $fileTmp = $_FILES['trailer_file']['tmp_name'];
                $fileExt = strtolower(pathinfo($_FILES['trailer_file']['name'], PATHINFO_EXTENSION));
                $fileSizeMB = round($fileSize / 1024 / 1024, 1);

                if (!in_array($fileType, $allowedTypes)) {
                    $error = 'Неподдерживаемый формат видео. Разрешены: MP4, WebM, AVI';
                } elseif ($fileSize > $maxSize) {
                    $error = "Файл слишком большой. Максимальный размер: {$maxSizeMB}MB, ваш файл: {$fileSizeMB}MB";
                } else {
                    $uploadDir = 'uploads/trailers/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $newFileName = uniqid('trailer_', true) . '.' . $fileExt;
                    $destination = $uploadDir . $newFileName;
                    if (move_uploaded_file($fileTmp, $destination)) {
                        $trailer_url = $destination;
                    } else {
                        $error = 'Ошибка при загрузке видеофайла.';
                    }
                }
                }
            }

            if (!$error) {
                $pdo = getDB();

                try {
                    $pdo->beginTransaction();

                    // Добавляем аниме
                    $stmt = $pdo->prepare("INSERT INTO anime (title, description, genre, year, studio, image_url, trailer_url, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$title, $description, $genre, $year, $studio ?: null, $image_url ?: null, $trailer_url ?: null, $currentUser['id']]);
                    $anime_id = $pdo->lastInsertId();

                    // Добавляем детализированную оценку пользователя
                    $stmt = $pdo->prepare("INSERT INTO ratings (user_id, anime_id, story_rating, art_rating, characters_rating, sound_rating) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$currentUser['id'], $anime_id, $story_rating, $art_rating, $characters_rating, $sound_rating]);

                    $pdo->commit();
                    $success = 'Аниме успешно добавлено!';

                    // Очищаем форму
                    $_POST = [];

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Ошибка при добавлении аниме: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавить аниме - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Стили для выпадающего списка аватарки */
        .avatar-dropdown {
            position: relative;
            display: inline-block;
        }

        .avatar-trigger {
            cursor: pointer;
        }

        .avatar-dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            min-width: 200px;
            z-index: 1000;
            margin-top: 8px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .avatar-dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            padding: 0;
        }

        .dropdown-button {
            display: block;
            width: 100%;
            padding: 12px 16px;
            background: none;
            border: none;
            text-align: left;
            cursor: pointer;
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            text-decoration: none;
            border-radius: 0;
        }

        .dropdown-button:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }

        .dropdown-button.delete:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .dropdown-item:first-child .dropdown-button {
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }

        .dropdown-item:last-child .dropdown-button {
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        /* Dark theme styles */
        [data-theme="dark"] .avatar-dropdown-menu {
            background: rgba(30, 30, 30, 0.95);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        [data-theme="dark"] .dropdown-button:hover {
            background: rgba(99, 102, 241, 0.2);
            color: #6366f1;
        }

        /* Стили для трейлера */
        .trailer-input-group {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .trailer-method-selector {
            display: flex;
            gap: 25px;
            align-items: center;
            margin-bottom: 10px;
        }
        .trailer-method-selector label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            cursor: pointer;
            margin: 0;
        }
        .trailer-method-selector input[type="radio"] {
            margin: 0;
        }
        .trailer-input {
            margin-top: 0;
        }
        .trailer-input input[type="url"],
        .trailer-input input[type="file"] {
            width: 100%;
        }
        .upload-progress {
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .progress-bar {
            width: 120px;
            height: 8px;
            background: #eee;
            border-radius: 5px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            width: 0%;
            transition: width 0.2s;
        }
        .progress-text {
            font-size: 0.95em;
            color: #333;
        }
        [data-theme="dark"] .progress-bar {
            background: #222;
        }
        [data-theme="dark"] .progress-text {
            color: #eee;
        }

        /* Стили для информации о больших файлах */
        .file-size-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 8px;
            font-size: 0.9em;
            display: none;
            align-items: center;
            gap: 8px;
        }

        .file-size-info.show {
            display: flex;
        }

        .file-size-info .size-icon {
            font-size: 1.2em;
        }

        .file-size-warning {
            background: linear-gradient(135deg, #ff7b7b 0%, #ff6b35 100%);
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 8px;
            font-size: 0.9em;
            display: none;
            align-items: flex-start;
            gap: 12px;
        }

        .file-size-warning.show {
            display: flex;
        }

        .file-size-warning .warning-icon {
            font-size: 1.4em;
            margin-top: 2px;
        }

        .file-size-warning .warning-content {
            flex: 1;
        }

        .file-size-warning .warning-title {
            font-weight: bold;
            margin-bottom: 4px;
        }

        .file-size-warning .warning-tips {
            font-size: 0.85em;
            opacity: 0.9;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1 class="logo"><a href="index.php"><?php echo SITE_NAME; ?></a></h1>
            <nav class="nav">
                <a href="index.php">Главная</a>

                <a href="logout.php">Выйти</a>
                <div class="user-info avatar-dropdown">
                    <div class="avatar-trigger" onclick="toggleAvatarDropdown()">
                        <span class="user-name"><?php echo h($currentUser['username']); ?></span>
                        <?php if ($currentUser['avatar'] && file_exists($currentUser['avatar'])): ?>
                            <img src="<?php echo h($currentUser['avatar']); ?>" alt="Аватарка <?php echo h($currentUser['username']); ?>" class="user-avatar">
                        <?php else: ?>
                            <div class="user-avatar" title="<?php echo h($currentUser['username']); ?>">
                                <?php echo strtoupper(substr($currentUser['username'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                        <div class="avatar-dropdown-menu" id="avatarDropdown">
                            <div class="dropdown-item">
                                <button type="button" class="dropdown-button" onclick="window.location.href='profile.php'">
                                    👤 Личный кабинет
                                </button>
                            </div>
                            <div class="dropdown-item">
                                <form action="upload_avatar.php" method="post" enctype="multipart/form-data" id="avatarForm">
                                    <input type="file" name="avatar" id="avatarInput" accept="image/*" style="display: none;" onchange="handleAvatarUpload(this)">
                                    <label for="avatarInput" class="dropdown-button">
                                        📁 Изменить аватарку
                                    </label>
                                </form>
                            </div>
                            <?php if ($currentUser['avatar']): ?>
                            <div class="dropdown-item">
                                <form action="upload_avatar.php" method="post">
                                    <button type="submit" name="delete_avatar" class="dropdown-button delete" onclick="return confirm('Удалить аватарку?')">
                                        🗑️ Удалить аватарку
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <button class="theme-toggle" id="themeToggle" title="Переключить тему">
                    <span class="theme-icon sun">☀️</span>
                    <span class="theme-icon moon">🌙</span>
                </button>
            </nav>
        </div>
    </header>

    <main class="main">
        <div class="container">
            <div class="form-container" style="max-width: 700px;">
                <h2>📺 Добавить аниме</h2>

                <?php if ($error): ?>
                    <div class="error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success"><?php echo h($success); ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                    <div style="text-align: right; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                        <div style="font-size: 12px; color: #666;">
                            📊 Лимиты сервера: загрузка до <?php echo min(ini_get('upload_max_filesize'), ini_get('post_max_size')); ?>
                        </div>
                        <button type="button" onclick="refreshCSRFToken()" style="background: none; border: 1px solid #ddd; padding: 5px 10px; border-radius: 5px; font-size: 12px; cursor: pointer;">
                            🔄 Обновить токен безопасности
                        </button>
                    </div>
                    <div class="form-group">
                        <label for="title">Название аниме: *</label>
                        <input type="text" id="title" name="title" required
                               value="<?php echo h($_POST['title'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="description">Описание: *</label>
                        <textarea id="description" name="description" required><?php echo h($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="genres">Жанры: *</label>
                        <div class="custom-multiselect" id="genreMultiselect">
                            <button type="button" class="multiselect-button" id="genreButton">
                                <span class="multiselect-button-text placeholder">Выберите жанры</span>
                                <span class="multiselect-arrow">▼</span>
                            </button>
                            <div class="multiselect-dropdown" id="genreDropdown" style="display: none;">
                                <input type="text" class="multiselect-search" placeholder="🔍 Поиск жанров..." id="genreSearch">
                                <div class="multiselect-option select-all" data-value="all">
                                    <div class="multiselect-checkbox"></div>
                                    <span class="multiselect-label">Выбрать все</span>
                                </div>
                                <div class="multiselect-option select-all" data-value="none">
                                    <div class="multiselect-checkbox"></div>
                                    <span class="multiselect-label">Очистить всё</span>
                                </div>
                                <?php
                                $availableGenres = getAvailableGenres();
                                $selectedGenres = $_POST['genres'] ?? [];
                                foreach ($availableGenres as $genre): ?>
                                    <div class="multiselect-option <?php echo in_array($genre, $selectedGenres) ? 'selected' : ''; ?>" data-value="<?php echo h($genre); ?>">
                                        <div class="multiselect-checkbox"></div>
                                        <span class="multiselect-label"><?php echo h($genre); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <!-- Скрытый оригинальный select для отправки формы -->
                        <select name="genres[]" id="genres" multiple class="genre-filter-select" required style="display: none;">
                            <?php foreach ($availableGenres as $genre): ?>
                                <option value="<?php echo h($genre); ?>" <?php echo in_array($genre, $selectedGenres) ? 'selected' : ''; ?>>
                                    <?php echo h($genre); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Выберите один или несколько жанров для аниме</small>
                    </div>

                    <div class="form-group">
                        <label for="year">Год выпуска:</label>
                        <input type="number" id="year" name="year"
                               min="1900" max="<?php echo date('Y') + 5; ?>"
                               value="<?php echo h($_POST['year'] ?? date('Y')); ?>">
                    </div>

                    <div class="form-group">
                        <label for="studio">Студия:</label>
                        <input type="text" id="studio" name="studio"
                               value="<?php echo h($_POST['studio'] ?? ''); ?>"
                               placeholder="Название студии...">
                    </div>

                    <div class="form-group">
                        <label for="image_url">URL изображения:</label>
                        <input type="url" id="image_url" name="image_url"
                               value="<?php echo h($_POST['image_url'] ?? ''); ?>"
                               placeholder="https://example.com/image.jpg">
                    </div>

                    <div class="form-group">
                        <label>Трейлер:</label>
                        <div class="trailer-input-group">
                            <div class="trailer-method-selector">
                                <label for="trailer_method_url">
                                    🔗 Вставить ссылку
                                    <input type="radio" id="trailer_method_url" name="trailer_method" value="url" <?php echo (!isset($_POST['trailer_method']) || $_POST['trailer_method'] === 'url') ? 'checked' : ''; ?>>
                                </label>

                                <label for="trailer_method_upload">
                                    📁 Загрузить файл
                                    <input type="radio" id="trailer_method_upload" name="trailer_method" value="upload" <?php echo (isset($_POST['trailer_method']) && $_POST['trailer_method'] === 'upload') ? 'checked' : ''; ?>>
                                </label>
                            </div>

                            <div class="trailer-input url-input" id="trailerUrlInput" style="<?php echo (!isset($_POST['trailer_method']) || $_POST['trailer_method'] === 'url') ? '' : 'display:none;'; ?>">
                                <input type="url" id="trailer_url" name="trailer_url"
                                       value="<?php echo h($_POST['trailer_url'] ?? ''); ?>"
                                       placeholder="https://youtube.com/watch?v=... или https://rutube.ru/video/...">
                                <small class="form-hint">Поддерживается YouTube, Rutube, а также прямые ссылки на MP4 видео</small>
                            </div>

                            <div class="trailer-input upload-input" id="trailerUploadInput" style="<?php echo (isset($_POST['trailer_method']) && $_POST['trailer_method'] === 'upload') ? '' : 'display:none;'; ?>">
                                <input type="file" id="trailer_file" name="trailer_file" accept="video/*">
                                <small class="form-hint">Поддерживаются форматы: MP4, WebM, AVI. Максимальный размер: <?php
                                    $maxUpload = ini_get('upload_max_filesize');
                                    $maxPost = ini_get('post_max_size');
                                    echo min($maxUpload, $maxPost);
                                ?></small>

                                <div class="file-size-info" id="fileSizeInfo">
                                    <span class="size-icon">📄</span>
                                    <span id="fileSizeText">Файл выбран</span>
                                </div>

                                <div class="file-size-warning" id="fileSizeWarning">
                                    <span class="warning-icon">⚠️</span>
                                    <div class="warning-content">
                                        <div class="warning-title">Большой файл</div>
                                        <div class="warning-tips">
                                            • Загрузка может занять несколько минут<br>
                                            • Не закрывайте вкладку во время загрузки<br>
                                            • Убедитесь в стабильном интернет-соединении
                                        </div>
                                    </div>
                                </div>

                                <div class="upload-progress" id="uploadProgress" style="display: none;">
                                    <div class="progress-bar">
                                        <div class="progress-fill" id="progressFill"></div>
                                    </div>
                                    <span class="progress-text" id="progressText">0%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ratings-section">

                        <div class="rating-categories">
                            <div class="rating-category">
                                <div class="category-header">
                                    <span class="category-icon">📖</span>
                                    <label class="category-label">Сюжет</label>
                                    <span class="category-value" id="story-display">8</span>
                                </div>
                                <div class="star-rating" data-category="story">
                                    <input type="hidden" name="story_rating" id="story_rating" value="<?php echo h($_POST['story_rating'] ?? '8'); ?>">
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <span class="star <?php echo $i <= ($_POST['story_rating'] ?? 8) ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>">⭐</span>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="rating-category">
                                <div class="category-header">
                                    <span class="category-icon">🎨</span>
                                    <label class="category-label">Рисовка</label>
                                    <span class="category-value" id="art-display">8</span>
                                </div>
                                <div class="star-rating" data-category="art">
                                    <input type="hidden" name="art_rating" id="art_rating" value="<?php echo h($_POST['art_rating'] ?? '8'); ?>">
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <span class="star <?php echo $i <= ($_POST['art_rating'] ?? 8) ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>">⭐</span>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="rating-category">
                                <div class="category-header">
                                    <span class="category-icon">👥</span>
                                    <label class="category-label">Персонажи</label>
                                    <span class="category-value" id="characters-display">8</span>
                                </div>
                                <div class="star-rating" data-category="characters">
                                    <input type="hidden" name="characters_rating" id="characters_rating" value="<?php echo h($_POST['characters_rating'] ?? '8'); ?>">
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <span class="star <?php echo $i <= ($_POST['characters_rating'] ?? 8) ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>">⭐</span>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="rating-category">
                                <div class="category-header">
                                    <span class="category-icon">🎵</span>
                                    <label class="category-label">Саундтреки</label>
                                    <span class="category-value" id="sound-display">8</span>
                                </div>
                                <div class="star-rating" data-category="sound">
                                    <input type="hidden" name="sound_rating" id="sound_rating" value="<?php echo h($_POST['sound_rating'] ?? '8'); ?>">
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <span class="star <?php echo $i <= ($_POST['sound_rating'] ?? 8) ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>">⭐</span>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="overall-rating-display">
                            <div class="overall-rating-card">
                                <span class="overall-icon">🌟</span>
                                <div class="overall-content">
                                    <span class="overall-label">Общая оценка</span>
                                    <span class="overall-value" id="overall-rating">8.0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <style>
                        .ratings-section {
                            background: var(--card-bg);
                            border-radius: 15px;
                            padding: 24px;
                            margin: 20px 0;
                            box-shadow: 0 8px 25px var(--shadow-color);
                            border: 1px solid rgba(255, 255, 255, 0.1);
                        }

                        .ratings-section h3 {
                            color: var(--text-primary);
                            font-size: 1.4em;
                            margin-bottom: 24px;
                            text-align: center;
                            font-weight: 600;
                        }

                        .rating-categories {
                            display: grid;
                            gap: 20px;
                            margin-bottom: 24px;
                        }

                        .rating-category {
                            background: var(--input-bg);
                            border-radius: 12px;
                            padding: 20px;
                            border: 1px solid var(--border-color);
                            transition: all 0.3s ease;
                        }

                        .rating-category:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 4px 15px var(--shadow-hover);
                            border-color: var(--border-focus);
                        }

                        .category-header {
                            display: flex;
                            align-items: center;
                            margin-bottom: 12px;
                            gap: 12px;
                        }

                        .category-icon {
                            font-size: 1.5em;
                            width: 32px;
                            text-align: center;
                        }

                        .category-label {
                            flex: 1;
                            font-weight: 600;
                            color: var(--text-primary);
                            font-size: 1.1em;
                        }

                        .category-value {
                            background: var(--primary-gradient);
                            color: white;
                            padding: 6px 12px;
                            border-radius: 20px;
                            font-weight: bold;
                            min-width: 40px;
                            text-align: center;
                            font-size: 0.9em;
                        }

                        .star-rating {
                            margin-left: 44px;
                        }

                        .stars {
                            display: flex;
                            gap: 4px;
                            flex-wrap: wrap;
                        }

                        .star {
                            font-size: 1.8em;
                            cursor: pointer;
                            transition: all 0.2s ease;
                            filter: grayscale(100%);
                            opacity: 0.4;
                            transform: scale(0.9);
                        }

                        .star.active,
                        .star.hover {
                            filter: grayscale(0%);
                            opacity: 1;
                            transform: scale(1);
                        }

                        .star:hover {
                            transform: scale(1.2);
                            filter: drop-shadow(0 0 8px #ffd700);
                        }

                        .overall-rating-display {
                            border-top: 2px solid var(--border-color);
                            padding-top: 20px;
                        }

                        .overall-rating-card {
                            background: var(--primary-gradient);
                            border-radius: 15px;
                            padding: 20px;
                            display: flex;
                            align-items: center;
                            gap: 16px;
                            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
                            transition: all 0.3s ease;
                        }

                        .overall-rating-card:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
                        }

                        .overall-icon {
                            font-size: 2.5em;
                            filter: drop-shadow(0 0 10px #ffd700);
                        }

                        .overall-content {
                            flex: 1;
                            color: white;
                        }

                        .overall-label {
                            display: block;
                            font-size: 1.1em;
                            opacity: 0.9;
                            margin-bottom: 4px;
                        }

                        .overall-value {
                            display: block;
                            font-size: 2.2em;
                            font-weight: bold;
                            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
                        }

                        /* Темная тема */
                        [data-theme="dark"] .rating-category {
                            background: rgba(255, 255, 255, 0.05);
                            border-color: rgba(255, 255, 255, 0.1);
                        }

                        [data-theme="dark"] .rating-category:hover {
                            background: rgba(255, 255, 255, 0.08);
                            border-color: var(--border-focus);
                        }

                        /* Адаптивность */
                        @media (max-width: 768px) {
                            .ratings-section {
                                padding: 16px;
                            }

                            .category-header {
                                flex-wrap: wrap;
                                gap: 8px;
                            }

                            .category-label {
                                min-width: 120px;
                            }

                            .stars {
                                gap: 2px;
                            }

                            .star {
                                font-size: 1.5em;
                            }

                            .overall-rating-card {
                                padding: 16px;
                                flex-direction: column;
                                text-align: center;
                                gap: 12px;
                            }

                            .overall-value {
                                font-size: 1.8em;
                            }
                        }
                    </style>

                    <button type="submit" class="btn btn-primary">Добавить аниме</button>
                </form>

                <p style="text-align: center; margin-top: 20px;">
                    <a href="profile.php">← Вернуться в личный кабинет</a>
                </p>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 <?php echo SITE_NAME; ?>. У вас нет прав.</p>
        </div>
    </footer>

    <script>
        // Получаем реальные лимиты сервера из PHP
        const SERVER_LIMITS = {
            maxUploadSize: <?php
                $maxUpload = ini_get('upload_max_filesize');
                $maxPost = ini_get('post_max_size');

                function convertToBytesJS($val) {
                    $val = trim($val);
                    $last = strtolower($val[strlen($val)-1]);
                    $num = (int)$val;
                    switch($last) {
                        case 'g': $num *= 1024;
                        case 'm': $num *= 1024;
                        case 'k': $num *= 1024;
                    }
                    return $num;
                }

                echo min(convertToBytesJS($maxUpload), convertToBytesJS($maxPost));
            ?>,
            maxUploadSizeMB: <?php echo round(min(convertToBytesJS($maxUpload), convertToBytesJS($maxPost)) / 1024 / 1024, 1); ?>,
            maxUploadDisplay: '<?php echo min($maxUpload, $maxPost); ?>'
        };

        document.addEventListener('DOMContentLoaded', function() {
            // --- STAR RATING LOGIC ---
            function updateStars(category, value) {
                const stars = document.querySelectorAll('.star-rating[data-category="' + category + '"] .star');
                stars.forEach(function(star, idx) {
                    if (idx < value) {
                        star.classList.add('active');
                    } else {
                        star.classList.remove('active');
                    }
                });
            }

            function setCategoryValue(category, value) {
                document.getElementById(category + '_rating').value = value;
                document.getElementById(category + '-display').textContent = value;
                updateStars(category, value);
                updateOverallRating();
            }

            function getCategoryValue(category) {
                return parseInt(document.getElementById(category + '_rating').value) || 8;
            }

            function updateOverallRating() {
                const story = getCategoryValue('story');
                const art = getCategoryValue('art');
                const characters = getCategoryValue('characters');
                const sound = getCategoryValue('sound');
                const overall = ((story + art + characters + sound) / 4).toFixed(1);
                document.getElementById('overall-rating').textContent = overall;
            }

            // Инициализация значений
            ['story', 'art', 'characters', 'sound'].forEach(function(category) {
                const value = getCategoryValue(category);
                updateStars(category, value);
                document.getElementById(category + '-display').textContent = value;
            });
            updateOverallRating();

            // Навешиваем обработчики на звезды
            document.querySelectorAll('.star-rating').forEach(function(ratingBlock) {
                const category = ratingBlock.getAttribute('data-category');
                const stars = ratingBlock.querySelectorAll('.star');
                stars.forEach(function(star, idx) {
                    // Наведение
                    star.addEventListener('mouseenter', function() {
                        stars.forEach(function(s, i) {
                            if (i <= idx) s.classList.add('hover');
                            else s.classList.remove('hover');
                        });
                        document.getElementById(category + '-display').textContent = idx + 1;
                    });
                    // Уход мыши
                    star.addEventListener('mouseleave', function() {
                        stars.forEach(function(s) { s.classList.remove('hover'); });
                        document.getElementById(category + '-display').textContent = getCategoryValue(category);
                    });
                    // Клик
                    star.addEventListener('click', function() {
                        setCategoryValue(category, idx + 1);
                    });
                });
            });

            // --- END STAR RATING LOGIC ---

            // Функциональность для пользовательского выпадающего списка жанров
            const multiselect = document.getElementById('genreButton');
            const dropdown = document.getElementById('genreDropdown');
            const buttonText = multiselect.querySelector('.multiselect-button-text');
            const arrow = multiselect.querySelector('.multiselect-arrow');
            const searchInput = document.getElementById('genreSearch');
            const originalSelect = document.getElementById('genres');

            // Получаем все опции и фильтруем программно
            const allOptions = dropdown.querySelectorAll('.multiselect-option');
            const options = Array.from(allOptions).filter(option =>
                !option.classList.contains('select-all') &&
                option.dataset.value !== 'all' &&
                option.dataset.value !== 'none'
            );
            const selectAllOption = dropdown.querySelector('[data-value="all"]');
            const clearAllOption = dropdown.querySelector('[data-value="none"]');

            let isOpen = false;

            // Обновляем текст кнопки на основе выбранных элементов
            function updateButtonText() {
                const selected = options.filter(option => option.classList.contains('selected'));
                const selectedValues = selected.map(option => option.dataset.value);

                if (selected.length === 0) {
                    buttonText.textContent = 'Выберите жанры';
                    buttonText.className = 'multiselect-button-text placeholder';
                } else if (selected.length === 1) {
                    buttonText.textContent = selected[0].querySelector('.multiselect-label').textContent;
                    buttonText.className = 'multiselect-button-text';
                } else if (selected.length <= 3) {
                    buttonText.textContent = selected.map(option =>
                        option.querySelector('.multiselect-label').textContent
                    ).join(', ');
                    buttonText.className = 'multiselect-button-text';
                } else {
                    buttonText.innerHTML = `Выбрано: <span class="multiselect-count">${selected.length}</span>`;
                    buttonText.className = 'multiselect-button-text';
                }

                // Синхронизируем с оригинальным select
                Array.from(originalSelect.options).forEach(option => {
                    option.selected = selectedValues.includes(option.value);
                });
            }

            // Открыть/закрыть выпадающий список
            function toggleDropdown() {
                isOpen = !isOpen;

                if (isOpen) {
                    dropdown.style.display = 'block';
                    // Небольшая задержка для плавной анимации
                    setTimeout(() => {
                        dropdown.classList.add('open');
                    }, 10);
                    multiselect.classList.add('active');
                    arrow.textContent = '▲';
                    searchInput.focus();
                } else {
                    dropdown.classList.remove('open');
                    multiselect.classList.remove('active');
                    arrow.textContent = '▼';
                    // Скрываем после завершения анимации
                    setTimeout(() => {
                        if (!isOpen) dropdown.style.display = 'none';
                    }, 300);
                }
            }

            // Закрыть выпадающий список
            function closeDropdown() {
                if (isOpen) {
                    isOpen = false;
                    dropdown.classList.remove('open');
                    multiselect.classList.remove('active');
                    arrow.textContent = '▼';
                    searchInput.value = '';
                    filterOptions('');
                    // Скрываем после завершения анимации
                    setTimeout(() => {
                        if (!isOpen) dropdown.style.display = 'none';
                    }, 300);
                }
            }

            // Фильтрация опций по поиску
            function filterOptions(searchTerm) {
                const term = searchTerm.toLowerCase().trim();

                // Если строка поиска пуста, показываем все опции
                if (term === '') {
                    // Показываем все обычные опции
                    options.forEach(option => {
                        option.style.display = 'flex';
                    });

                    // Показываем служебные опции
                    if (selectAllOption) selectAllOption.style.display = 'flex';
                    if (clearAllOption) clearAllOption.style.display = 'flex';

                    // Скрываем сообщение "Ничего не найдено"
                    const noResultsMessage = dropdown.querySelector('.multiselect-no-results');
                    if (noResultsMessage) {
                        noResultsMessage.style.display = 'none';
                    }
                    return;
                }

                // Всегда показываем служебные опции
                if (selectAllOption) selectAllOption.style.display = 'flex';
                if (clearAllOption) clearAllOption.style.display = 'flex';

                // Фильтруем только обычные опции жанров
                const hasVisibleOptions = options.some(option => {
                    const label = option.querySelector('.multiselect-label').textContent.toLowerCase();
                    const matches = label.startsWith(term);
                    option.style.display = matches ? 'flex' : 'none';
                    return matches;
                });

                // Показываем сообщение "Ничего не найдено"
                let noResultsMessage = dropdown.querySelector('.multiselect-no-results');
                if (!hasVisibleOptions) {
                    if (!noResultsMessage) {
                        noResultsMessage = document.createElement('div');
                        noResultsMessage.className = 'multiselect-no-results';
                        noResultsMessage.textContent = 'Ничего не найдено';
                        dropdown.appendChild(noResultsMessage);
                    }
                    noResultsMessage.style.display = 'block';
                } else if (noResultsMessage) {
                    noResultsMessage.style.display = 'none';
                }
            }

            // Выбрать все жанры
            function selectAll() {
                const visibleOptions = options.filter(option =>
                    option.style.display !== 'none'
                );

                visibleOptions.forEach(option => {
                    option.classList.add('selected');
                });
                updateButtonText();
            }

            // Очистить все выбранные жанры
            function clearAll() {
                options.forEach(option => {
                    option.classList.remove('selected');
                });
                updateButtonText();
            }

            // Обработчики событий
            multiselect.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleDropdown();
            });

            // Клик по опции
            options.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.stopPropagation();
                    this.classList.toggle('selected');
                    updateButtonText();
                });
            });

            // Обработчики для "Выбрать все" и "Очистить всё"
            if (selectAllOption) {
                selectAllOption.addEventListener('click', function(e) {
                    e.stopPropagation();
                    selectAll();
                });
            }

            if (clearAllOption) {
                clearAllOption.addEventListener('click', function(e) {
                    e.stopPropagation();
                    clearAll();
                });
            }

            // Поиск
            searchInput.addEventListener('input', function() {
                filterOptions(this.value);
            });

            searchInput.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            // Закрытие при клике вне элемента
            document.addEventListener('click', function(e) {
                if (!multiselect.contains(e.target) && !dropdown.contains(e.target)) {
                    closeDropdown();
                }
            });

            // Закрытие по Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && isOpen) {
                    closeDropdown();
                }
            });

            // Инициализация: устанавливаем правильное начальное состояние
            function initializeComponent() {
                // Убеждаемся, что dropdown скрыт
                dropdown.style.display = 'none';
                dropdown.classList.remove('open');
                multiselect.classList.remove('active');
                arrow.textContent = '▼';
                isOpen = false;

                // Обновляем текст кнопки на основе предварительно выбранных значений
                updateButtonText();

                // Очищаем поиск
                searchInput.value = '';
                filterOptions('');
            }

            // Запускаем инициализацию
            initializeComponent();

            // Управление темами
            const themeToggle = document.getElementById('themeToggle');
            const body = document.body;

            // Всегда загружаем сохраненную тему из localStorage
            const savedTheme = localStorage.getItem('theme') || 'light';
            body.setAttribute('data-theme', savedTheme);

            // Обработчик переключения темы (только если кнопка существует)
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    const currentTheme = body.getAttribute('data-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

                    body.setAttribute('data-theme', newTheme);
                    localStorage.setItem('theme', newTheme);

                    // Добавляем анимацию переключения
                    body.style.transition = 'all 0.3s ease';
                    setTimeout(() => {
                        body.style.transition = '';
                    }, 300);
                });
            }

            // Функции для управления выпадающим списком аватарки
            window.toggleAvatarDropdown = function() {
                const dropdown = document.getElementById('avatarDropdown');
                if (dropdown) {
                    dropdown.classList.toggle('show');
                }
            }

            // Закрыть dropdown при клике вне его
            document.addEventListener('click', function(event) {
                const dropdown = document.getElementById('avatarDropdown');
                const avatarDropdown = event.target.closest('.avatar-dropdown');

                if (!avatarDropdown && dropdown && dropdown.classList.contains('show')) {
                    dropdown.classList.remove('show');
                }
            });

            // Автоматическая отправка формы при выборе файла
            window.handleAvatarUpload = function(input) {
                if (input.files && input.files[0]) {
                    // Проверяем размер файла (5MB = 5 * 1024 * 1024 bytes)
                    const maxSize = 5 * 1024 * 1024;
                    if (input.files[0].size > maxSize) {
                        alert('Файл слишком большой. Максимальный размер: 5MB');
                        input.value = '';
                        return;
                    }

                    // Проверяем тип файла
                    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                    if (!allowedTypes.includes(input.files[0].type)) {
                        alert('Неподдерживаемый тип файла. Разрешены: JPEG, PNG, GIF, WebP');
                        input.value = '';
                        return;
                    }

                    // Отправляем форму
                    input.closest('form').submit();
                }
            }

            // --- Трейлер: переключение между URL и загрузкой файла ---
            const trailerMethodUrl = document.getElementById('trailer_method_url');
            const trailerMethodUpload = document.getElementById('trailer_method_upload');
            const trailerUrlInput = document.getElementById('trailerUrlInput');
            const trailerUploadInput = document.getElementById('trailerUploadInput');
            const trailerFileInput = document.getElementById('trailer_file');
            const uploadProgress = document.getElementById('uploadProgress');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');

            function updateTrailerInputVisibility() {
                if (trailerMethodUrl.checked) {
                    trailerUrlInput.style.display = '';
                    trailerUploadInput.style.display = 'none';
                } else {
                    trailerUrlInput.style.display = 'none';
                    trailerUploadInput.style.display = '';
                }
            }

            if (trailerMethodUrl && trailerMethodUpload) {
                trailerMethodUrl.addEventListener('change', updateTrailerInputVisibility);
                trailerMethodUpload.addEventListener('change', updateTrailerInputVisibility);
            }

            // Обработка выбора видеофайла с красивыми уведомлениями
            if (trailerFileInput) {
                const fileSizeInfo = document.getElementById('fileSizeInfo');
                const fileSizeWarning = document.getElementById('fileSizeWarning');
                const fileSizeText = document.getElementById('fileSizeText');

                trailerFileInput.addEventListener('change', function() {
                    // Скрываем все уведомления
                    if (fileSizeInfo) fileSizeInfo.classList.remove('show');
                    if (fileSizeWarning) fileSizeWarning.classList.remove('show');
                    if (uploadProgress) uploadProgress.style.display = 'none';

                    if (trailerFileInput.files && trailerFileInput.files[0]) {
                        const file = trailerFileInput.files[0];
                        const fileSizeMB = Math.round(file.size / 1024 / 1024);
                        const maxSize = SERVER_LIMITS.maxUploadSize;

                        // Проверка размера
                        if (file.size > maxSize) {
                            alert(`Файл слишком большой. Максимальный размер: ${SERVER_LIMITS.maxUploadDisplay}\nВаш файл: ${fileSizeMB}MB`);
                            trailerFileInput.value = '';
                            return;
                        }

                        // Проверка типа
                        const allowedTypes = ['video/mp4', 'video/webm', 'video/avi'];
                        if (!allowedTypes.includes(file.type)) {
                            alert('Неподдерживаемый формат видео. Разрешены: MP4, WebM, AVI');
                            trailerFileInput.value = '';
                            return;
                        }

                        // Показываем информацию о файле
                        if (fileSizeInfo && fileSizeText) {
                            if (fileSizeMB < 1) {
                                fileSizeText.textContent = `Файл выбран: ${Math.round(file.size / 1024)}KB`;
                            } else {
                                fileSizeText.textContent = `Файл выбран: ${fileSizeMB}MB`;
                            }
                            fileSizeInfo.classList.add('show');
                        }

                        // Показываем предупреждение для больших файлов (75% от лимита)
                        const warningThreshold = SERVER_LIMITS.maxUploadSizeMB * 0.75;
                        if (fileSizeMB > warningThreshold && fileSizeWarning) {
                            fileSizeWarning.classList.add('show');
                        }

                        // Показываем прогресс для очень больших файлов (90% от лимита)
                        const progressThreshold = SERVER_LIMITS.maxUploadSizeMB * 0.9;
                        if (fileSizeMB > progressThreshold && uploadProgress) {
                            uploadProgress.style.display = 'flex';
                            progressFill.style.width = '0%';

                            const estimatedTime = Math.round(fileSizeMB / 8); // примерно 8MB/сек
                            if (estimatedTime > 60) {
                                progressText.textContent = `Большой файл (${fileSizeMB}MB). Время загрузки: ~${Math.round(estimatedTime/60)} мин.`;
                            } else {
                                progressText.textContent = `Файл ${fileSizeMB}MB. Время загрузки: ~${estimatedTime} сек.`;
                            }

                            setTimeout(() => {
                                progressText.textContent = 'Готов к загрузке';
                                progressFill.style.width = '100%';
                            }, 1500);
                        }

                        console.log(`Выбран файл: ${file.name}, размер: ${fileSizeMB}MB`);
                    }
                });
            }

            // Инициализация трейлера
            updateTrailerInputVisibility();

            // Функция для обновления CSRF токена
            window.refreshCSRFToken = function() {
                fetch(window.location.pathname + '?ajax=refresh_token')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const tokenInput = document.querySelector('input[name="csrf_token"]');
                            if (tokenInput) {
                                tokenInput.value = data.token;
                            }
                            console.log('CSRF токен обновлен');
                            alert('Токен безопасности обновлен');
                        }
                    })
                    .catch(error => {
                        console.error('Ошибка обновления токена:', error);
                        alert('Ошибка обновления токена безопасности');
                    });
            };

            // Автоматически обновляем токен каждые 30 минут
            setInterval(refreshCSRFToken, 30 * 60 * 1000);

            // Обработка ошибок формы
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const trailerMethod = document.querySelector('input[name="trailer_method"]:checked');
                    const trailerFile = document.getElementById('trailer_file');

                    if (trailerMethod && trailerMethod.value === 'upload' && trailerFile && trailerFile.files[0]) {
                        const file = trailerFile.files[0];
                        const fileSizeMB = Math.round(file.size / 1024 / 1024);
                        const maxSize = SERVER_LIMITS.maxUploadSize;

                        if (file.size > maxSize) {
                            e.preventDefault();
                            alert('Файл слишком большой! Максимальный размер: ' + SERVER_LIMITS.maxUploadDisplay + '\n\nРазмер вашего файла: ' + fileSizeMB + 'MB');
                            return false;
                        }

                        // Предупреждение для больших файлов (75% от лимита)
                        const warningThreshold = SERVER_LIMITS.maxUploadSizeMB * 0.75;
                        if (fileSizeMB > warningThreshold) {
                            const confirmUpload = confirm(
                                `Вы загружаете большой файл (${fileSizeMB}MB).\n\n` +
                                `⏱️ Загрузка может занять несколько минут\n` +
                                `📱 Не закрывайте вкладку во время загрузки\n` +
                                `🌐 Убедитесь в стабильном интернет-соединении\n\n` +
                                `Продолжить загрузку?`
                            );

                            if (!confirmUpload) {
                                e.preventDefault();
                                return false;
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
