<?php
require_once 'config.php';

$anime_id = (int)($_GET['id'] ?? 0);

if (!$anime_id) {
    redirect('index.php');
}

$pdo = getDB();
$currentUser = getCurrentUser();

// Получаем информацию об аниме
$animeQuery = "
    SELECT a.*, u.username as creator_name,
           AVG(r.overall_rating) as avg_rating,
           COUNT(r.overall_rating) as rating_count,
           AVG(r.story_rating) as avg_story,
           AVG(r.art_rating) as avg_art,
           AVG(r.characters_rating) as avg_characters,
           AVG(r.sound_rating) as avg_sound
    FROM anime a
    LEFT JOIN users u ON a.created_by = u.id
    LEFT JOIN ratings r ON a.id = r.anime_id
    WHERE a.id = ?
    GROUP BY a.id
";
$stmt = $pdo->prepare($animeQuery);
$stmt->execute([$anime_id]);
$anime = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$anime) {
    redirect('index.php');
}

// Получаем рейтинг текущего пользователя
$userRating = null;
if ($currentUser) {
    $stmt = $pdo->prepare("SELECT story_rating, art_rating, characters_rating, sound_rating, overall_rating FROM ratings WHERE user_id = ? AND anime_id = ?");
    $stmt->execute([$currentUser['id'], $anime_id]);
    $userRating = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Обработка добавления/обновления рейтинга
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['story_rating']) && $currentUser) {
    // Проверка CSRF токена
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('CSRF токен недействителен. Обновите страницу и попробуйте снова.');
    }

    $story_rating = (int)$_POST['story_rating'];
    $art_rating = (int)$_POST['art_rating'];
    $characters_rating = (int)$_POST['characters_rating'];
    $sound_rating = (int)$_POST['sound_rating'];

    // Проверяем валидность оценок
    if ($story_rating >= 1 && $story_rating <= 10 &&
        $art_rating >= 1 && $art_rating <= 10 &&
        $characters_rating >= 1 && $characters_rating <= 10 &&
        $sound_rating >= 1 && $sound_rating <= 10) {

        if ($userRating) {
            // Обновляем существующий рейтинг
            $stmt = $pdo->prepare("UPDATE ratings SET story_rating = ?, art_rating = ?, characters_rating = ?, sound_rating = ? WHERE user_id = ? AND anime_id = ?");
            $stmt->execute([$story_rating, $art_rating, $characters_rating, $sound_rating, $currentUser['id'], $anime_id]);
        } else {
            // Добавляем новый рейтинг
            $stmt = $pdo->prepare("INSERT INTO ratings (user_id, anime_id, story_rating, art_rating, characters_rating, sound_rating) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$currentUser['id'], $anime_id, $story_rating, $art_rating, $characters_rating, $sound_rating]);
        }

        // Перезагружаем страницу для обновления среднего рейтинга
        redirect("anime.php?id=$anime_id");
    }
}

// Обработка добавления комментария
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['comment']) && $currentUser) {
    // Проверка CSRF токена
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('CSRF токен недействителен. Обновите страницу и попробуйте снова.');
    }

    $comment = trim($_POST['comment']);

    if (!empty($comment)) {
        $stmt = $pdo->prepare("INSERT INTO comments (user_id, anime_id, comment) VALUES (?, ?, ?)");
        $stmt->execute([$currentUser['id'], $anime_id, $comment]);

        // Перезагружаем страницу
        redirect("anime.php?id=$anime_id");
    }
}

// Обработка удаления комментария (только для администраторов)
if (isset($_GET['delete_comment']) && isAdmin()) {
    $comment_id = (int)$_GET['delete_comment'];

    $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);

    // Перезагружаем страницу
    redirect("anime.php?id=$anime_id");
}

// Получаем комментарии с информацией о лайках
$commentsQuery = "
    SELECT c.*, u.username, u.avatar, u.id as user_id,
           COALESCE(likes.likes_count, 0) as likes_count,
           COALESCE(dislikes.dislikes_count, 0) as dislikes_count,
           user_likes.like_type as user_like_type
    FROM comments c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN (
        SELECT comment_id, COUNT(*) as likes_count
        FROM comment_likes
        WHERE like_type = 'like'
        GROUP BY comment_id
    ) likes ON c.id = likes.comment_id
    LEFT JOIN (
        SELECT comment_id, COUNT(*) as dislikes_count
        FROM comment_likes
        WHERE like_type = 'dislike'
        GROUP BY comment_id
    ) dislikes ON c.id = dislikes.comment_id
    LEFT JOIN comment_likes user_likes ON c.id = user_likes.comment_id AND user_likes.user_id = ?
    WHERE c.anime_id = ?
    ORDER BY c.created_at DESC
";
$stmt = $pdo->prepare($commentsQuery);
$stmt->execute([$currentUser ? $currentUser['id'] : 0, $anime_id]);
$comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем текущий статус аниме для пользователя
$userAnimeStatus = null;
if ($currentUser) {
    $stmt = $pdo->prepare("SELECT status FROM user_anime_status WHERE user_id = ? AND anime_id = ?");
    $stmt->execute([$currentUser['id'], $anime_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $userAnimeStatus = $result ? $result['status'] : null;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($anime['title']); ?> - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="style.css">
    <script>
        // Мгновенное применение темы для предотвращения мелькания
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            document.body.setAttribute('data-theme', savedTheme);
        })();
    </script>
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
            pointer-events: none;
        }

        .avatar-dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
            pointer-events: auto;
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

        .rating-criteria {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)));
            gap: 20px;
            margin: 20px 0;
        }

        /* === ИСПРАВЛЕНИЯ ДЛЯ ВИДЕО ПЛЕЕРА === */
        .video-player-wrapper {
            position: relative;
            width: 100%;
            max-width: 100%;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
        }
        .video-embed-container {
            position: relative;
            width: 100%;
            padding-bottom: 56.25%;
            height: 0;
        }
        .video-embed-container iframe {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%;
            border: 0;
        }
        .custom-video-player {
            position: relative;
            width: 100%;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            min-height: 220px;
        }
        .custom-video {
            width: 100%;
            display: block;
            background: #000;
            border-radius: 12px;
            min-height: 220px;
        }
        .video-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.35);
            z-index: 2;
            transition: opacity 0.2s;
        }
        .video-overlay.hidden {
            opacity: 0;
            pointer-events: none;
        }
        .play-button {
            background: none;
            border: none;
            cursor: pointer;
            outline: none;
            padding: 0;
        }
        .video-controls {
            position: absolute;
            left: 0; right: 0; bottom: 0;
            background: linear-gradient(0deg, rgba(0,0,0,0.7) 80%, rgba(0,0,0,0.0) 100%);
            padding: 10px 12px 6px 12px;
            z-index: 3;
            opacity: 1;
            transition: opacity 0.2s;
            box-sizing: border-box; /* ИСПРАВЛЕНИЕ: добавлен box-sizing */
        }
        .video-controls.hide {
            opacity: 0;
            pointer-events: none;
        }
        .progress-container {
            width: 100%;
            height: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            position: relative;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255,255,255,0.15);
            border-radius: 4px;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            background: #667eea;
            border-radius: 4px 0 0 4px;
            width: 0;
            transition: width 0.1s;
        }
        .progress-handle {
            position: absolute;
            top: 50%;
            left: 0;
            width: 14px;
            height: 14px;
            background: #fff;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            display: none;
        }
        .progress-bar:hover .progress-handle,
        .progress-bar:active .progress-handle {
            display: block;
        }
        .controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .controls-left, .controls-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .control-btn {
            background: none;
            border: none;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background 0.15s;
        }
        .control-btn:hover {
            background: rgba(102,126,234,0.15);
        }
        .time-display {
            color: #fff;
            font-size: 14px;
            margin-left: 8px;
            min-width: 80px;
            font-variant-numeric: tabular-nums;
        }
        .volume-container {
            display: flex;
            align-items: center;
            position: relative;
        }
        .volume-slider-container {
            width: 60px;
            margin-left: 4px;
        }
        .volume-slider {
            width: 60px;
            accent-color: #667eea;
        }
        .speed-btn {
            font-size: 15px;
            min-width: 36px;
        }
        .fullscreen-btn {
            font-size: 20px;
        }
        .loading-spinner {
            position: absolute;
            left: 50%; top: 50%;
            transform: translate(-50%, -50%);
            z-index: 10;
            display: none;
        }
        .loading-spinner.active {
            display: block;
        }
        .spinner {
            width: 36px; height: 36px;
            border: 4px solid #fff;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg);}
            100% { transform: rotate(360deg);}
        }

        /* ИСПРАВЛЕНИЯ: Адаптивные стили для видео контролов */
        @media (max-width: 600px) {
            .video-player-wrapper, .custom-video-player {
                min-height: 160px;
            }

            .video-controls {
                padding: 8px 6px 4px 6px; /* Уменьшенные отступы */
            }

            .controls-row {
                flex-wrap: wrap;
                gap: 5px;
            }

            .controls-left, .controls-right {
                gap: 5px;
            }

            .control-btn {
                font-size: 16px;
                padding: 2px 4px;
                min-width: 32px;
            }

            .time-display {
                font-size: 12px;
                min-width: 60px;
                margin-left: 4px;
            }

            .volume-slider-container {
                width: 40px;
                margin-left: 2px;
            }

            .volume-slider {
                width: 40px;
            }

            .speed-btn {
                font-size: 13px;
                min-width: 28px;
            }

            .fullscreen-btn {
                font-size: 16px;
            }
        }

        /* Дополнительные исправления для очень маленьких экранов */
        @media (max-width: 480px) {
            .video-controls {
                padding: 6px 4px 3px 4px;
            }

            .control-btn {
                font-size: 14px;
                padding: 1px 3px;
                min-width: 28px;
            }

            .time-display {
                font-size: 11px;
                min-width: 50px;
                margin-left: 2px;
            }

            .volume-slider-container {
                width: 30px;
                margin-left: 1px;
            }

            .volume-slider {
                width: 30px;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1 class="logo"><a href="index.php"><?php echo SITE_NAME; ?></a></h1>
            <nav class="nav">
                <?php if ($currentUser): ?>

                    <?php if (isAdmin()): ?>
                        <a href="add_anime.php">Добавить аниме</a>
                    <?php endif; ?>
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
                <?php else: ?>
                    <a href="login.php">Войти</a>
                    <a href="register.php">Регистрация</a>
                <?php endif; ?>
                <button class="theme-toggle" id="themeToggle" title="Переключить тему">
                    <span class="theme-icon sun">☀️</span>
                    <span class="theme-icon moon">🌙</span>
                </button>
            </nav>
        </div>
    </header>

    <main class="main">
        <div class="container">
            <div class="profile-container">
                <div style="display: grid; grid-template-columns: 300px 1fr; gap: 30px; align-items: start;">
                    <div>
                        <?php if ($anime['image_url']): ?>
                            <img src="<?php echo h($anime['image_url']); ?>" alt="<?php echo h($anime['title']); ?>" style="width: 100%; border-radius: 15px;">
                        <?php else: ?>
                            <div class="anime-image-placeholder" style="height: 400px; font-size: 64px;">🎌</div>
                        <?php endif; ?>

                        <?php if ($anime['trailer_url']): ?>
                            <div class="trailer-container" style="margin-top: 20px;">
                                <h3 class="trailer-title">🎬 Трейлер</h3>
                                <div class="video-player-wrapper">
                                    <?php
                                    $trailer_url = $anime['trailer_url'];
                                    $is_youtube = strpos($trailer_url, 'youtube.com') !== false || strpos($trailer_url, 'youtu.be') !== false;
                                    $is_rutube = strpos($trailer_url, 'rutube.ru') !== false;
                                    $is_uploaded_file = strpos($trailer_url, 'uploads/trailers/') !== false;

                                    if ($is_youtube):
                                        // Извлекаем ID видео для YouTube
                                        preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $trailer_url, $matches);
                                        $youtube_id = isset($matches[1]) ? $matches[1] : '';
                                        if ($youtube_id):
                                    ?>
                                        <div class="video-embed-container">
                                            <iframe class="trailer-video embedded-video"
                                                    src="https://www.youtube.com/embed/<?php echo $youtube_id; ?>?rel=0&amp;showinfo=0"
                                                    frameborder="0"
                                                    allowfullscreen>
                                            </iframe>
                                        </div>
                                    <?php
                                        endif;
                                    elseif ($is_rutube):
                                        // Извлекаем ID видео для Rutube
                                        preg_match('/rutube\.ru\/(?:video|play\/embed)\/([a-zA-Z0-9]+)/', $trailer_url, $matches);
                                        $rutube_id = isset($matches[1]) ? $matches[1] : '';
                                        if ($rutube_id):
                                    ?>
                                        <div class="video-embed-container">
                                            <iframe class="trailer-video embedded-video"
                                                    src="https://rutube.ru/play/embed/<?php echo $rutube_id; ?>"
                                                    frameborder="0"
                                                    allowfullscreen>
                                            </iframe>
                                        </div>
                                    <?php
                                        endif;
                                    else:
                                    ?>
                                        <div class="custom-video-player" id="customVideoPlayer">
                                            <video class="trailer-video custom-video" id="mainVideo" preload="metadata">
                                                <source src="<?php echo h($trailer_url); ?>" type="video/mp4">
                                                Ваш браузер не поддерживает воспроизведение видео.
                                            </video>

                                            <div class="video-overlay" id="videoOverlay">
                                                <button class="play-button" id="playButton">
                                                    <svg width="60" height="60" viewBox="0 0 60 60">
                                                        <circle cx="30" cy="30" r="28" fill="rgba(0,0,0,0.7)" stroke="white" stroke-width="2"/>
                                                        <polygon points="22,15 22,45 45,30" fill="white"/>
                                                    </svg>
                                                </button>
                                            </div>

                                            <div class="video-controls" id="videoControls">
                                                <div class="progress-container">
                                                    <div class="progress-bar" id="progressBar">
                                                        <div class="progress-fill" id="progressFill"></div>
                                                        <div class="progress-handle" id="progressHandle"></div>
                                                    </div>
                                                </div>

                                                <div class="controls-row">
                                                    <div class="controls-left">
                                                        <button class="control-btn play-pause-btn" id="playPauseBtn">
                                                            <span class="play-icon">▶</span>
                                                            <span class="pause-icon" style="display:none;">⏸</span>
                                                        </button>
                                                        <span class="time-display">
                                                            <span id="currentTime">0:00</span> / <span id="duration">0:00</span>
                                                        </span>
                                                    </div>

                                                    <div class="controls-right">
                                                        <div class="volume-container">
                                                            <button class="control-btn volume-btn" id="volumeBtn">🔊</button>
                                                            <div class="volume-slider-container">
                                                                <input type="range" class="volume-slider" id="volumeSlider" min="0" max="100" value="100">
                                                            </div>
                                                        </div>
                                                        <button class="control-btn speed-btn" id="speedBtn">1x</button>
                                                        <button class="control-btn fullscreen-btn" id="fullscreenBtn">⛶</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="loading-spinner" id="loadingSpinner">
                                                <div class="spinner"></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="anime-title-section" style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px;">
                            <h1 style="margin: 0; flex-grow: 1;"><?php echo h($anime['title']); ?></h1>
                            <?php if ($currentUser): ?>
                            <div class="anime-list-dropdown">
                                <button class="list-dropdown-toggle" id="listDropdownToggle">
                                    <span class="list-icon">📚</span>
                                    <span class="list-status">
                                        <?php
                                        if ($userAnimeStatus) {
                                            $statusLabels = [
                                                'planned' => '📝 Запланировано',
                                                'watching' => '👀 Смотрю',
                                                'completed' => '✅ Просмотрено',
                                                'dropped' => '❌ Брошено'
                                            ];
                                            echo $statusLabels[$userAnimeStatus] ?? $userAnimeStatus;
                                        } else {
                                            echo '+ В список';
                                        }
                                        ?>
                                    </span>
                                    <span class="dropdown-arrow">▼</span>
                                </button>
                                <div class="list-dropdown-menu" id="listDropdownMenu">
                                    <form method="POST" class="dropdown-form">
                                        <input type="hidden" name="anime_status" value="planned">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                                        <button type="submit" class="dropdown-item <?php echo ($userAnimeStatus === 'planned') ? 'active' : ''; ?>">
                                            📝 Запланировано
                                        </button>
                                    </form>
                                    <form method="POST" class="dropdown-form">
                                        <input type="hidden" name="anime_status" value="watching">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                                        <button type="submit" class="dropdown-item <?php echo ($userAnimeStatus === 'watching') ? 'active' : ''; ?>">
                                            👀 Смотрю
                                        </button>
                                    </form>
                                    <form method="POST" class="dropdown-form">
                                        <input type="hidden" name="anime_status" value="completed">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                                        <button type="submit" class="dropdown-item <?php echo ($userAnimeStatus === 'completed') ? 'active' : ''; ?>">
                                            ✅ Просмотрено
                                        </button>
                                    </form>
                                    <form method="POST" class="dropdown-form">
                                        <input type="hidden" name="anime_status" value="dropped">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                                        <button type="submit" class="dropdown-item <?php echo ($userAnimeStatus === 'dropped') ? 'active' : ''; ?>">
                                            ❌ Брошено
                                        </button>
                                    </form>
                                    <?php if ($userAnimeStatus): ?>
                                    <div class="dropdown-divider"></div>
                                    <form method="POST" class="dropdown-form">
                                        <input type="hidden" name="anime_status" value="remove">
                                        <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                                        <button type="submit" class="dropdown-item remove-item"
                                                onclick="return confirm('Убрать аниме из вашего списка?')">
                                            🗑️ Убрать из списка
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        <p><strong>Жанр:</strong> <?php echo h($anime['genre']); ?></p>
                        <p><strong>Год:</strong> <?php echo h($anime['year']); ?></p>
                        <p><strong>Студия:</strong> <?php echo h($anime['studio'] ?: 'Неизвестно'); ?></p>

                        <div style="margin: 20px 0;">
                            <div class="anime-rating">
                                <span class="rating-stars">⭐ <?php echo $anime['avg_rating'] ? round($anime['avg_rating'], 1) : 'Нет оценок'; ?></span>
                                <span class="rating-count">(<?php echo $anime['rating_count']; ?> оценок)</span>
                            </div>
                        </div>

                        <!-- Средние оценки по критериям -->
                        <?php if ($anime['avg_rating']): ?>
                        <div class="avg-ratings">
                            <div class="avg-rating-item">
                                <div class="criterion-icon">🎭</div>
                                <div>Сюжет</div>
                                <div class="avg-rating-value"><?php echo round($anime['avg_story'], 1); ?></div>
                            </div>
                            <div class="avg-rating-item">
                                <div class="criterion-icon">🎨</div>
                                <div>Рисовка</div>
                                <div class="avg-rating-value"><?php echo round($anime['avg_art'], 1); ?></div>
                            </div>
                            <div class="avg-rating-item">
                                <div class="criterion-icon">👥</div>
                                <div>Персонажи</div>
                                <div class="avg-rating-value"><?php echo round($anime['avg_characters'], 1); ?></div>
                            </div>
                            <div class="avg-rating-item">
                                <div class="criterion-icon">🎵</div>
                                <div>Саундтреки</div>
                                <div class="avg-rating-value"><?php echo round($anime['avg_sound'], 1); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div style="margin: 20px 0;">
                            <h3>Описание</h3>
                            <p><?php echo nl2br(h($anime['description'])); ?></p>
                        </div>

                        <?php if (isAdmin()): ?>
                            <div class="admin-controls">
                                <h4>🛠 Управление (Администратор)</h4>
                                <div style="display: flex; gap: 10px; margin-top: 10px;">
                                    <a href="edit_anime.php?id=<?php echo $anime['id']; ?>" class="btn btn-warning" class="btn-admin-edit">
                                        ✏️ Редактировать
                                    </a>
                                    <a href="delete_anime.php?id=<?php echo $anime['id']; ?>" class="btn btn-danger"
                                       onclick="return confirm('Вы уверены, что хотите удалить это аниме? Это действие нельзя отменить.')"
                                       class="btn-admin-delete">
                                        🗑️ Удалить
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Блок оценки на полную ширину страницы -->
            <?php if ($currentUser): ?>
                <section class="rating-block" style="margin: 40px 0;">
                    <div class="rating-container">
                        <h2 class="rating-title">Ваша оценка</h2>

                        <?php if ($userRating): ?>
                            <p class="user-rating-text">Вы оценили это аниме: <strong><?php echo round($userRating['overall_rating'], 1); ?>/10</strong></p>
                        <?php endif; ?>

                        <form method="POST" id="rating-form">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                            <div class="rating-grid">
                                <div class="rating-item">
                                    <div class="rating-icon">🎭</div>
                                    <div class="rating-info">
                                        <h3>Сюжет</h3>
                                        <div class="stars criterion-stars" data-criterion="story">
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                                <span class="star criterion-star <?php echo $i <= ($userRating['story_rating'] ?? 5) ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>">★</span>
                                            <?php endfor; ?>
                                        </div>
                                        <p class="rating-text">Оценка: <span id="story-value"><?php echo $userRating['story_rating'] ?? 5; ?></span>/10</p>
                                        <input type="hidden" name="story_rating" id="story_rating" value="<?php echo $userRating['story_rating'] ?? 5; ?>">
                                    </div>
                                </div>

                                <div class="rating-item">
                                    <div class="rating-icon">🎨</div>
                                    <div class="rating-info">
                                        <h3>Рисовка</h3>
                                        <div class="stars criterion-stars" data-criterion="art">
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                                <span class="star criterion-star <?php echo $i <= ($userRating['art_rating'] ?? 5) ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>">★</span>
                                            <?php endfor; ?>
                                        </div>
                                        <p class="rating-text">Оценка: <span id="art-value"><?php echo $userRating['art_rating'] ?? 5; ?></span>/10</p>
                                        <input type="hidden" name="art_rating" id="art_rating" value="<?php echo $userRating['art_rating'] ?? 5; ?>">
                                    </div>
                                </div>

                                <div class="rating-item">
                                    <div class="rating-icon">👥</div>
                                    <div class="rating-info">
                                        <h3>Персонажи</h3>
                                        <div class="stars criterion-stars" data-criterion="characters">
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                                <span class="star criterion-star <?php echo $i <= ($userRating['characters_rating'] ?? 5) ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>">★</span>
                                            <?php endfor; ?>
                                        </div>
                                        <p class="rating-text">Оценка: <span id="characters-value"><?php echo $userRating['characters_rating'] ?? 5; ?></span>/10</p>
                                        <input type="hidden" name="characters_rating" id="characters_rating" value="<?php echo $userRating['characters_rating'] ?? 5; ?>">
                                    </div>
                                </div>

                                <div class="rating-item">
                                    <div class="rating-icon">🎵</div>
                                    <div class="rating-info">
                                        <h3>Саундтреки</h3>
                                        <div class="stars criterion-stars" data-criterion="sound">
                                            <?php for ($i = 1; $i <= 10; $i++): ?>
                                                <span class="star criterion-star <?php echo $i <= ($userRating['sound_rating'] ?? 5) ? 'active' : ''; ?>" data-rating="<?php echo $i; ?>">★</span>
                                            <?php endfor; ?>
                                        </div>
                                        <p class="rating-text">Оценка: <span id="sound-value"><?php echo $userRating['sound_rating'] ?? 5; ?></span>/10</p>
                                        <input type="hidden" name="sound_rating" id="sound_rating" value="<?php echo $userRating['sound_rating'] ?? 5; ?>">
                                    </div>
                                </div>
                            </div>

                            <div style="text-align: center; margin-top: 30px;">
                                <div style="font-size: 20px; margin-bottom: 20px; color: #333;">
                                    Общая оценка: <span id="overall-rating" style="color: #667eea; font-weight: bold;"><?php echo $userRating ? round($userRating['overall_rating'], 1) : '5.0'; ?></span>/10
                                </div>
                                <button type="submit" class="btn btn-primary" style="padding: 15px 40px; font-size: 16px;"><?php echo $userRating ? 'Обновить оценку' : 'Оценить'; ?></button>
                            </div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <section class="comments-section">
                <h2>💬 Комментарии (<?php echo count($comments); ?>)</h2>

                <?php if ($currentUser): ?>
                    <div class="profile-container">
                        <h3>Добавить комментарий</h3>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                            <div class="form-group">
                                <textarea name="comment" rows="4" placeholder="Поделитесь своим мнением об этом аниме..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Отправить комментарий</button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($comments): ?>
                    <div class="comments-list">
                        <?php foreach ($comments as $comment): ?>
                            <div class="comment-item">
                                <div class="comment-header">
                                    <span class="comment-user">
                                        <a href="profile.php?user_id=<?php echo $comment['user_id']; ?>" class="user-profile-link">
                                            <?php if ($comment['avatar'] && file_exists($comment['avatar'])): ?>
                                                <img src="<?php echo h($comment['avatar']); ?>" alt="Аватарка <?php echo h($comment['username']); ?>" class="comment-avatar" style="width: 24px; height: 24px; border-radius: 50%; vertical-align: middle; margin-right: 8px;">
                                            <?php else: ?>
                                                <div class="comment-avatar" style="display: inline-block; width: 24px; height: 24px; border-radius: 50%; background: linear-gradient(135deg, #667eea, #764ba2); color: white; text-align: center; line-height: 24px; font-size: 12px; font-weight: bold; margin-right: 8px; vertical-align: middle;">
                                                    <?php echo strtoupper(substr($comment['username'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php echo h($comment['username']); ?>
                                        </a>
                                    </span>
                                    <span class="comment-date"><?php echo formatDate($comment['created_at']); ?></span>
                                    <?php if (isAdmin()): ?>
                                        <a href="anime.php?id=<?php echo $anime_id; ?>&delete_comment=<?php echo $comment['id']; ?>"
                                           onclick="return confirm('Удалить этот комментарий?')"
                                           style="color: #e74c3c; text-decoration: none; margin-left: 10px; font-size: 14px;">
                                            🗑️ Удалить
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="comment-text">
                                    <?php echo nl2br(h($comment['comment'])); ?>
                                </div>

                                <?php if ($currentUser): ?>
                                    <div class="comment-likes" data-comment-id="<?php echo $comment['id']; ?>">
                                        <button type="button" class="like-btn <?php echo ($comment['user_like_type'] === 'like') ? 'active' : ''; ?> "
                                                data-type="like" title="Нравится">
                                            👍 <span class="likes-count"><?php echo $comment['likes_count']; ?></span>
                                        </button>
                                        <button type="button" class="dislike-btn <?php echo ($comment['user_like_type'] === 'dislike') ? 'active' : ''; ?> "
                                                data-type="dislike" title="Не нравится">
                                            👎 <span class="dislikes-count"><?php echo $comment['dislikes_count']; ?></span>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="comment-likes-guest">
                                        <span class="like-display">👍 <?php echo $comment['likes_count']; ?></span>
                                        <span class="dislike-display">👎 <?php echo $comment['dislikes_count']; ?></span>
                                        <small style="color: #666; margin-left: 10px;">Войдите, чтобы поставить оценку</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="profile-container">
                        <p>Комментариев пока нет. Будьте первым!</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 <?php echo SITE_NAME; ?>. У вас нет прав.</p>
        </div>
    </footer>

    <?php if ($currentUser): ?>
    <script>
        // Интерактивные звездочки для детализированной системы рейтинга
        document.addEventListener('DOMContentLoaded', function() {
            // Функциональность всплывающего списка "Добавить в список"
            const dropdownToggle = document.getElementById('listDropdownToggle');
            const dropdownMenu = document.getElementById('listDropdownMenu');

            if (dropdownToggle && dropdownMenu) {
                dropdownToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const isOpen = dropdownMenu.classList.contains('show');

                    if (isOpen) {
                        dropdownMenu.classList.remove('show');
                        dropdownToggle.classList.remove('active');
                    } else {
                        dropdownMenu.classList.add('show');
                        dropdownToggle.classList.add('active');
                    }
                });

                // Закрыть при клике вне списка
                document.addEventListener('click', function(e) {
                    if (!dropdownToggle.contains(e.target) && !dropdownMenu.contains(e.target)) {
                        dropdownMenu.classList.remove('show');
                        dropdownToggle.classList.remove('active');
                    }
                });

                // Закрыть при нажатии Escape
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        dropdownMenu.classList.remove('show');
                        dropdownToggle.classList.remove('active');
                    }
                });

                // AJAX обработка форм смены статуса
                const statusForms = dropdownMenu.querySelectorAll('.dropdown-form');
                statusForms.forEach(form => {
                    form.addEventListener('submit', function(e) {
                        e.preventDefault();

                        const formData = new FormData(form);
                        formData.append('anime_id', <?php echo $anime_id; ?>);

                        // Показываем индикатор загрузки
                        const submitButton = form.querySelector('button[type="submit"]');
                        const originalText = submitButton.textContent;
                        submitButton.textContent = '⏳ Обновление...';
                        submitButton.disabled = true;

                        // Отправляем AJAX запрос
                        fetch('change_status.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Обновляем текст кнопки
                                const statusText = dropdownToggle.querySelector('.list-status');
                                statusText.textContent = data.status_text;

                                // Обновляем активные состояния
                                statusForms.forEach(f => {
                                    f.querySelector('button').classList.remove('active');
                                });

                                if (data.status) {
                                    submitButton.classList.add('active');
                                }

                                // Закрываем dropdown
                                dropdownMenu.classList.remove('show');
                                dropdownToggle.classList.remove('active');

                                // Показываем уведомление
                                showNotification(data.message, 'success');
                            } else {
                                showNotification(data.message, 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Ошибка:', error);
                            showNotification('Произошла ошибка при обновлении статуса', 'error');
                        })
                        .finally(() => {
                            // Восстанавливаем кнопку
                            submitButton.textContent = originalText;
                            submitButton.disabled = false;
                        });
                    });
                });
            }

            const criteriaContainers = document.querySelectorAll('.criterion-stars');

            criteriaContainers.forEach(container => {
                const criterion = container.dataset.criterion;
                const stars = container.querySelectorAll('.criterion-star');
                const input = document.getElementById(`${criterion}_rating`);
                const valueDisplay = document.getElementById(`${criterion}-value`);

                stars.forEach(star => {
                    star.addEventListener('click', function() {
                        const rating = parseInt(this.dataset.rating);
                        input.value = rating;
                        valueDisplay.textContent = rating;
                        updateStars(container, rating);
                        updateOverallRating();
                    });

                    star.addEventListener('mouseover', function() {
                        const rating = parseInt(this.dataset.rating);
                        updateStars(container, rating);
                    });
                });

                container.addEventListener('mouseleave', function() {
                    updateStars(container, parseInt(input.value));
                });
            });

            function updateStars(container, rating) {
                const stars = container.querySelectorAll('.criterion-star');
                stars.forEach((star, index) => {
                    if (index < rating) {
                        star.classList.add('active');
                    } else {
                        star.classList.remove('active');
                    }
                });
            }

            function updateOverallRating() {
                const storyRating = parseInt(document.getElementById('story_rating').value) || 0;
                const artRating = parseInt(document.getElementById('art_rating').value) || 0;
                const charactersRating = parseInt(document.getElementById('characters_rating').value) || 0;
                const soundRating = parseInt(document.getElementById('sound_rating').value) || 0;

                const overallRating = ((storyRating + artRating + charactersRating + soundRating) / 4).toFixed(1);
                document.getElementById('overall-rating').textContent = overallRating;
            }

            // Обработка лайков комментариев
            const likeButtons = document.querySelectorAll('.like-btn, .dislike-btn');

            likeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const commentLikesDiv = this.closest('.comment-likes');
                    const commentId = commentLikesDiv.dataset.commentId;
                    const likeType = this.dataset.type;

                    // Временно блокируем кнопки
                    const allBtns = commentLikesDiv.querySelectorAll('.like-btn, .dislike-btn');
                    allBtns.forEach(btn => btn.disabled = true);

                    // AJAX запрос
                    const formData = new FormData();
                    formData.append('comment_id', commentId);
                    formData.append('like_type', likeType);
                    formData.append('csrf_token', '<?php echo h(generateCSRFToken()); ?>');

                    fetch('like_comment.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Обновляем счетчики
                            const likesCount = commentLikesDiv.querySelector('.likes-count');
                            const dislikesCount = commentLikesDiv.querySelector('.dislikes-count');

                            likesCount.textContent = data.likes_count;
                            dislikesCount.textContent = data.dislikes_count;

                            // Обновляем активные состояния кнопок
                            const likeBtn = commentLikesDiv.querySelector('.like-btn');
                            const dislikeBtn = commentLikesDiv.querySelector('.dislike-btn');

                            likeBtn.classList.remove('active');
                            dislikeBtn.classList.remove('active');

                            if (data.user_like === 'like') {
                                likeBtn.classList.add('active');
                            } else if (data.user_like === 'dislike') {
                                dislikeBtn.classList.add('active');
                            }
                        } else {
                            alert('Ошибка: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Ошибка:', error);
                        alert('Произошла ошибка при обработке запроса');
                    })
                    .finally(() => {
                        // Разблокируем кнопки
                        allBtns.forEach(btn => btn.disabled = false);
                    });
                });
            });

            // Функция для показа уведомлений
            function showNotification(message, type = 'success') {
                // Создаем контейнер для уведомлений если его нет
                let container = document.getElementById('notification-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'notification-container';
                    container.style.cssText = `
                        position: fixed;
                        top: 20px;
                        right: 20px;
                        z-index: 10000;
                        pointer-events: none;
                    `;
                    document.body.appendChild(container);
                }

                // Создаем уведомление
                const notification = document.createElement('div');
                notification.className = `notification notification-${type}`;
                notification.style.cssText = `
                    background: ${type === 'success' ? 'var(--success-bg)' : 'var(--error-bg)'};
                    color: ${type === 'success' ? 'var(--success-text)' : 'var(--error-text)'};
                    border: 1px solid ${type === 'success' ? 'var(--success-border)' : 'var(--error-border)'};
                    padding: 12px 20px;
                    border-radius: 8px;
                    margin-bottom: 10px;
                    box-shadow: 0 4px 12px var(--shadow-color);
                    backdrop-filter: blur(10px);
                    transform: translateX(100%);
                    transition: transform 0.3s ease;
                    pointer-events: auto;
                    max-width: 300px;
                    word-wrap: break-word;
                `;

                notification.textContent = message;
                container.appendChild(notification);

                // Анимация появления
                setTimeout(() => {
                    notification.style.transform = 'translateX(0)';
                }, 10);

                // Автоматическое скрытие через 3 секунды
                setTimeout(() => {
                    notification.style.transform = 'translateX(100%)';
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 300);
                }, 3000);

                // Скрытие по клику
                notification.addEventListener('click', () => {
                    notification.style.transform = 'translateX(100%)';
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 300);
                });
            }
        });
    </script>
    <?php endif; ?>

    <!-- JavaScript для управления темами и dropdown аватарки - доступен всем пользователям -->
    <script>
        // Управление темами
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('themeToggle');
            const body = document.body;
            const html = document.documentElement;

            // Применяем сохраненную тему
            function applyTheme(theme) {
                body.setAttribute('data-theme', theme);
                html.setAttribute('data-theme', theme);
            }

            // Инициализация темы при загрузке
            const savedTheme = localStorage.getItem('theme') || 'light';
            applyTheme(savedTheme);

            // Обработчик переключения темы (только если кнопка существует)
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    const currentTheme = body.getAttribute('data-theme') || 'light';
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

                    applyTheme(newTheme);
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

            // === ИСПРАВЛЕННЫЙ ВИДЕО ПЛЕЕР ===
            const customPlayer = document.getElementById('customVideoPlayer');
            if (customPlayer) {
                const video = customPlayer.querySelector('#mainVideo');
                const overlay = customPlayer.querySelector('#videoOverlay');
                const playBtn = customPlayer.querySelector('#playButton');
                const controls = customPlayer.querySelector('#videoControls');
                const playPauseBtn = customPlayer.querySelector('#playPauseBtn');
                const playIcon = playPauseBtn.querySelector('.play-icon');
                const pauseIcon = playPauseBtn.querySelector('.pause-icon');
                const currentTimeEl = customPlayer.querySelector('#currentTime');
                const durationEl = customPlayer.querySelector('#duration');
                const progressBar = customPlayer.querySelector('#progressBar');
                const progressFill = customPlayer.querySelector('#progressFill');
                const progressHandle = customPlayer.querySelector('#progressHandle');
                const volumeBtn = customPlayer.querySelector('#volumeBtn');
                const volumeSlider = customPlayer.querySelector('#volumeSlider');
                const speedBtn = customPlayer.querySelector('#speedBtn');
                const fullscreenBtn = customPlayer.querySelector('#fullscreenBtn');
                const loadingSpinner = customPlayer.querySelector('#loadingSpinner');

                let hideControlsTimeout = null;
                let isDragging = false;
                let lastVolume = 1;

                function formatTime(sec) {
                    sec = Math.floor(sec);
                    const m = Math.floor(sec / 60);
                    const s = sec % 60;
                    return m + ':' + (s < 10 ? '0' : '') + s;
                }

                function showControls() {
                    controls.classList.remove('hide');
                    if (hideControlsTimeout) clearTimeout(hideControlsTimeout);
                    hideControlsTimeout = setTimeout(() => {
                        if (!video.paused && !isDragging) controls.classList.add('hide');
                    }, 2500);
                }

                function updatePlayPause() {
                    if (video.paused) {
                        playIcon.style.display = '';
                        pauseIcon.style.display = 'none';
                        overlay.classList.remove('hidden');
                    } else {
                        playIcon.style.display = 'none';
                        pauseIcon.style.display = '';
                        overlay.classList.add('hidden');
                    }
                }

                function updateProgress() {
                    const percent = (video.currentTime / video.duration) * 100;
                    progressFill.style.width = percent + '%';
                    progressHandle.style.left = percent + '%';
                    currentTimeEl.textContent = formatTime(video.currentTime);
                }

                function setProgress(e) {
                    const rect = progressBar.getBoundingClientRect();
                    const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
                    const percent = Math.max(0, Math.min(1, x / rect.width));
                    video.currentTime = percent * video.duration;
                }

                function updateVolumeIcon() {
                    if (video.muted || video.volume === 0) {
                        volumeBtn.textContent = '🔇';
                    } else if (video.volume < 0.5) {
                        volumeBtn.textContent = '🔉';
                    } else {
                        volumeBtn.textContent = '🔊';
                    }
                }

                function updateDuration() {
                    durationEl.textContent = formatTime(video.duration);
                }

                // Play/Pause
                playBtn.addEventListener('click', () => {
                    video.play();
                });
                overlay.addEventListener('click', () => {
                    video.play();
                });
                playPauseBtn.addEventListener('click', () => {
                    if (video.paused) video.play();
                    else video.pause();
                });
                video.addEventListener('play', updatePlayPause);
                video.addEventListener('pause', updatePlayPause);

                // Progress
                video.addEventListener('timeupdate', updateProgress);
                video.addEventListener('loadedmetadata', () => {
                    updateDuration();
                    updateProgress();
                });
                video.addEventListener('durationchange', updateDuration);

                // Progress bar click/drag
                progressBar.addEventListener('mousedown', e => {
                    isDragging = true;
                    setProgress(e);
                    showControls();
                    document.addEventListener('mousemove', onDrag);
                    document.addEventListener('mouseup', onStopDrag);
                });
                progressBar.addEventListener('touchstart', e => {
                    isDragging = true;
                    setProgress(e);
                    showControls();
                    document.addEventListener('touchmove', onDrag);
                    document.addEventListener('touchend', onStopDrag);
                });
                function onDrag(e) {
                    setProgress(e);
                }
                function onStopDrag(e) {
                    isDragging = false;
                    setProgress(e);
                    document.removeEventListener('mousemove', onDrag);
                    document.removeEventListener('mouseup', onStopDrag);
                    document.removeEventListener('touchmove', onDrag);
                    document.removeEventListener('touchend', onStopDrag);
                }

                // Volume
                volumeSlider.addEventListener('input', () => {
                    video.volume = volumeSlider.value / 100;
                    video.muted = video.volume === 0;
                    updateVolumeIcon();
                    if (video.volume > 0) lastVolume = video.volume;
                });
                volumeBtn.addEventListener('click', () => {
                    if (video.muted || video.volume === 0) {
                        video.muted = false;
                        // Восстановление громкости
                        if (lastVolume > 0) {
                            video.volume = lastVolume;
                            volumeSlider.value = lastVolume * 100;
                        } else {
                            video.volume = 1;
                            volumeSlider.value = 100;
                        }
                    } else {
                        video.muted = true;
                        volumeSlider.value = 0;
                    }
                    updateVolumeIcon();
                });
                video.addEventListener('volumechange', updateVolumeIcon);

                // Speed
                let speeds = [1, 1.25, 1.5, 2, 0.5, 0.75];
                let speedIndex = 0;
                speedBtn.addEventListener('click', () => {
                    speedIndex = (speedIndex + 1) % speeds.length;
                    video.playbackRate = speeds[speedIndex];
                    speedBtn.textContent = speeds[speedIndex] + 'x';
                });

                // Fullscreen
                fullscreenBtn.addEventListener('click', () => {
                    if (document.fullscreenElement) {
                        document.exitFullscreen();
                    } else {
                        customPlayer.requestFullscreen();
                    }
                });

                // Show/hide controls on mouse move
                customPlayer.addEventListener('mousemove', showControls);
                customPlayer.addEventListener('mouseleave', () => {
                    if (!video.paused) controls.classList.add('hide');
                });
                customPlayer.addEventListener('mouseenter', showControls);

                // Keyboard shortcuts
                customPlayer.tabIndex = 0;
                customPlayer.addEventListener('keydown', e => {
                    if (e.code === 'Space') {
                        e.preventDefault();
                        if (video.paused) video.play();
                        else video.pause();
                    } else if (e.code === 'ArrowRight') {
                        video.currentTime = Math.min(video.duration, video.currentTime + 5);
                    } else if (e.code === 'ArrowLeft') {
                        video.currentTime = Math.max(0, video.currentTime - 5);
                    } else if (e.code === 'KeyF') {
                        fullscreenBtn.click();
                    } else if (e.code === 'KeyM') {
                        volumeBtn.click();
                    }
                });

                // Loading spinner
                video.addEventListener('waiting', () => loadingSpinner.classList.add('active'));
                video.addEventListener('playing', () => loadingSpinner.classList.remove('active'));
                video.addEventListener('seeking', () => loadingSpinner.classList.add('active'));
                video.addEventListener('seeked', () => loadingSpinner.classList.remove('active'));
                video.addEventListener('canplay', () => loadingSpinner.classList.remove('active'));

                // Hide overlay on play
                video.addEventListener('play', () => {
                    overlay.classList.add('hidden');
                    showControls();
                });
                video.addEventListener('pause', () => {
                    overlay.classList.remove('hidden');
                    controls.classList.remove('hide');
                });

                // Hide controls after inactivity
                video.addEventListener('play', showControls);

                // Initial state
                updatePlayPause();
                updateVolumeIcon();
                speedBtn.textContent = speeds[speedIndex] + 'x';
            }
        });
    </script>
</body>
</html>
