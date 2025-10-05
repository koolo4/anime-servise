<?php
require_once 'config.php';

$pdo = getDB();

// Получаем список доступных жанров
$availableGenres = getAvailableGenres();

// Получаем параметры сортировки и фильтрации
$sortBy = $_GET['sort'] ?? 'rating';
$sortOrder = $_GET['order'] ?? 'desc';
$genreFilters = $_GET['genres'] ?? []; // Изменено на массив жанров
$searchTitle = $_GET['search'] ?? ''; // Параметр поиска по названию

// Валидация параметров сортировки
$allowedSorts = ['rating', 'year', 'title', 'genre', 'created_at'];
$allowedOrders = ['asc', 'desc'];

if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'rating';
}
if (!in_array($sortOrder, $allowedOrders)) {
    $sortOrder = 'desc';
}

// Валидация жанров - удаляем недопустимые жанры из массива
if (!empty($genreFilters) && is_array($genreFilters)) {
    $genreFilters = array_filter($genreFilters, function($genre) use ($availableGenres) {
        return in_array($genre, $availableGenres);
    });
} else {
    $genreFilters = [];
}

// Строим запрос в зависимости от сортировки и фильтрации
$baseQuery = "
    SELECT a.*, AVG(r.overall_rating) as avg_rating, COUNT(r.overall_rating) as rating_count
    FROM anime a
    LEFT JOIN ratings r ON a.id = r.anime_id
";

// Добавляем фильтрацию по жанрам и поиску по названию
$whereClause = "";
$queryParams = [];
$conditions = [];

// Добавляем условие поиска по названию если введено
if (!empty($searchTitle)) {
    $conditions[] = "a.title LIKE ?";
    $queryParams[] = '%' . $searchTitle . '%';
}

// Добавляем фильтрацию по жанрам если выбраны
if (!empty($genreFilters)) {
    // Строим WHERE clause для поиска по множественным жанрам (AND логика)
    $genreConditions = [];
    foreach ($genreFilters as $genre) {
        // Для каждого жанра создаем условие, что он должен присутствовать
        $genreConditions[] = "(a.genre LIKE ? OR a.genre LIKE ? OR a.genre LIKE ? OR a.genre = ?)";
        $queryParams[] = $genre . ',%';        // Жанр в начале
        $queryParams[] = '%, ' . $genre . ',%'; // Жанр в середине
        $queryParams[] = '%, ' . $genre;       // Жанр в конце
        $queryParams[] = $genre;               // Единственный жанр
    }
    // Используем AND логику - все жанры должны присутствовать
    $conditions[] = "(" . implode(" AND ", $genreConditions) . ")";
}

// Формируем итоговый WHERE clause
if (!empty($conditions)) {
    $whereClause = " WHERE " . implode(" AND ", $conditions);
}

$groupClause = " GROUP BY a.id";

$orderClause = "";
switch ($sortBy) {
    case 'rating':
        $orderClause = "ORDER BY avg_rating $sortOrder, rating_count DESC";
        break;
    case 'year':
        $orderClause = "ORDER BY a.year $sortOrder, a.title ASC";
        break;
    case 'title':
        $orderClause = "ORDER BY a.title $sortOrder";
        break;
    case 'genre':
        $orderClause = "ORDER BY a.genre ASC, a.title ASC";
        break;
    case 'created_at':
        $orderClause = "ORDER BY a.created_at $sortOrder";
        break;
}

$fullQuery = $baseQuery . $whereClause . $groupClause . " " . $orderClause . " LIMIT 20";

if (!empty($queryParams)) {
    $stmt = $pdo->prepare($fullQuery);
    $stmt->execute($queryParams);
    $topAnime = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $topAnime = $pdo->query($fullQuery)->fetchAll(PDO::FETCH_ASSOC);
}

// Получаем статистику сайта
$statsQuery = "
    SELECT
        (SELECT COUNT(*) FROM anime) as total_anime,
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM ratings) as total_ratings,
        (SELECT COUNT(*) FROM comments) as total_comments
";
$siteStats = $pdo->query($statsQuery)->fetch(PDO::FETCH_ASSOC);

// Получаем топ-5 аниме по рейтингу (отдельно от основного списка)
$topRatedQuery = "
    SELECT a.*, AVG(r.overall_rating) as avg_rating, COUNT(r.overall_rating) as rating_count
    FROM anime a
    JOIN ratings r ON a.id = r.anime_id
    GROUP BY a.id
    HAVING rating_count >= 2
    ORDER BY avg_rating DESC, rating_count DESC
    LIMIT 5
";
$topRatedAnime = $pdo->query($topRatedQuery)->fetchAll(PDO::FETCH_ASSOC);



// Получаем популярные жанры
$popularGenresQuery = "
    SELECT
        TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.genre, ',', numbers.n), ',', -1)) as genre,
        COUNT(*) as count,
        AVG(r.overall_rating) as avg_rating
    FROM anime a
    CROSS JOIN (
        SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
        UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
    ) numbers
    LEFT JOIN ratings r ON a.id = r.anime_id
    WHERE CHAR_LENGTH(a.genre) - CHAR_LENGTH(REPLACE(a.genre, ',', '')) >= numbers.n - 1
    GROUP BY genre
    HAVING count >= 2
    ORDER BY count DESC, avg_rating DESC
    LIMIT 12
";
$popularGenres = $pdo->query($popularGenresQuery)->fetchAll(PDO::FETCH_ASSOC);

// Получаем персональные рекомендации для авторизованных пользователей
$currentUser = getCurrentUser();
$recommendations = [];

if ($currentUser) {
    // Получаем жанры, которые пользователь оценивал высоко
    $userPreferencesQuery = "
        SELECT
            TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(a.genre, ',', numbers.n), ',', -1)) as genre,
            AVG(r.overall_rating) as avg_user_rating,
            COUNT(*) as count
        FROM anime a
        JOIN ratings r ON a.id = r.anime_id
        CROSS JOIN (
            SELECT 1 n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
            UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8
        ) numbers
        WHERE r.user_id = ?
        AND CHAR_LENGTH(a.genre) - CHAR_LENGTH(REPLACE(a.genre, ',', '')) >= numbers.n - 1
        AND r.overall_rating >= 7
        GROUP BY genre
        ORDER BY avg_user_rating DESC, count DESC
        LIMIT 3
    ";

    $stmt = $pdo->prepare($userPreferencesQuery);
    $stmt->execute([$currentUser['id']]);
    $preferredGenres = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($preferredGenres) {
        $genreList = array_map(function($g) { return "'" . addslashes($g['genre']) . "'"; }, $preferredGenres);
        $genreListStr = implode(',', $genreList);

        // Получаем рекомендации на основе предпочтений
        $recommendationsQuery = "
            SELECT DISTINCT a.*, AVG(r.overall_rating) as avg_rating, COUNT(r.overall_rating) as rating_count
            FROM anime a
            LEFT JOIN ratings r ON a.id = r.anime_id
            LEFT JOIN ratings ur ON a.id = ur.anime_id AND ur.user_id = ?
            WHERE ur.user_id IS NULL
            AND (
                " . implode(' OR ', array_fill(0, count($preferredGenres), 'a.genre LIKE ?')) . "
            )
            GROUP BY a.id
            HAVING avg_rating >= 6.5 OR rating_count = 0
            ORDER BY avg_rating DESC, rating_count DESC
            LIMIT 6
        ";

        $recommendationParams = [$currentUser['id']];
        foreach ($preferredGenres as $genre) {
            $recommendationParams[] = '%' . $genre['genre'] . '%';
        }

        $stmt = $pdo->prepare($recommendationsQuery);
        $stmt->execute($recommendationParams);
        $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Получаем последние комментарии
$latestCommentsQuery = "
    SELECT c.*, u.username, u.avatar, u.id as user_id, a.title as anime_title
    FROM comments c
    JOIN users u ON c.user_id = u.id
    JOIN anime a ON c.anime_id = a.id
    ORDER BY c.created_at DESC
    LIMIT 5
";
$latestComments = $pdo->query($latestCommentsQuery)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
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

        [data-theme="dark"] .dropdown-button.delete:hover {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
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
            <?php
            // Отображаем сообщения об успешных операциях
            if (isset($_SESSION['success_message'])) {
                echo '<div class="success-message">';
                echo '✅ ' . h($_SESSION['success_message']);
                echo '</div>';
                unset($_SESSION['success_message']);
            }

            if (isset($_SESSION['error_message'])) {
                echo '<div class="error-message">';
                echo '❌ ' . h($_SESSION['error_message']);
                echo '</div>';
                unset($_SESSION['error_message']);
            }
            ?>

            <section class="hero">
                <h2>Добро пожаловать в мир аниме!</h2>
                <p>Открывайте новые аниме, делитесь мнениями и находите единомышленников</p>
            </section>

            <!-- Статистика сайта -->
            <section class="site-stats">
                <h2>📊 Статистика сайта</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">🎬</div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($siteStats['total_anime']); ?></div>
                            <div class="stat-label">Аниме в каталоге</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($siteStats['total_users']); ?></div>
                            <div class="stat-label">Пользователей</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">⭐</div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($siteStats['total_ratings']); ?></div>
                            <div class="stat-label">Оценок</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">💬</div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($siteStats['total_comments']); ?></div>
                            <div class="stat-label">Комментариев</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Топ аниме по рейтингу -->
            <?php if ($topRatedAnime): ?>
            <section class="top-rated-anime">
                <h2>🏆 Топ аниме по рейтингу</h2>
                <div class="top-anime-list">
                    <?php foreach ($topRatedAnime as $index => $anime): ?>
                        <div class="top-anime-item" data-anime-id="<?php echo $anime['id']; ?>">
                            <div class="rank-badge">#<?php echo $index + 1; ?></div>
                            <div class="anime-poster">
                                <?php if ($anime['image_url']): ?>
                                    <img src="<?php echo h($anime['image_url']); ?>" alt="<?php echo h($anime['title']); ?>">
                                <?php else: ?>
                                    <div class="anime-placeholder">🎌</div>
                                <?php endif; ?>
                            </div>
                            <div class="anime-details">
                                <h3><?php echo h($anime['title']); ?></h3>
                                <p class="anime-info"><?php echo h($anime['genre']); ?> • <?php echo h($anime['year']); ?></p>
                                <div class="rating-info">
                                    <span class="rating-score">⭐ <?php echo round($anime['avg_rating'], 1); ?></span>
                                    <span class="rating-votes">(<?php echo $anime['rating_count']; ?> оценок)</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- Персональные рекомендации для авторизованных пользователей -->
            <?php if ($currentUser && $recommendations): ?>
            <section class="recommendations">
                <h2>🎯 Рекомендации для вас</h2>
                <p class="recommendations-subtitle">На основе ваших оценок</p>
                <div class="anime-grid">
                    <?php foreach ($recommendations as $anime): ?>
                        <div class="anime-card" data-anime-id="<?php echo $anime['id']; ?>">
                            <?php if ($anime['image_url']): ?>
                                <img src="<?php echo h($anime['image_url']); ?>" alt="<?php echo h($anime['title']); ?>" class="anime-image">
                            <?php else: ?>
                                <div class="anime-image-placeholder">🎌</div>
                            <?php endif; ?>
                            <div class="anime-info">
                                <h3><?php echo h($anime['title']); ?></h3>
                                <p class="anime-genre"><?php echo h($anime['genre']); ?> • <?php echo h($anime['year']); ?></p>
                                <p class="anime-description"><?php echo h(substr($anime['description'], 0, 100)); ?>...</p>
                                <div class="anime-rating">
                                    <span class="rating-stars">⭐ <?php echo $anime['avg_rating'] ? round($anime['avg_rating'], 1) : 'Новое'; ?></span>
                                    <?php if ($anime['rating_count']): ?>
                                        <span class="rating-count">(<?php echo $anime['rating_count']; ?> оценок)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>



            <!-- Популярные жанры -->
            <?php if ($popularGenres): ?>
            <section class="popular-genres">
                <h2>🔥 Популярные жанры</h2>
                <div class="genres-cloud">
                    <?php foreach ($popularGenres as $genre): ?>
                        <a href="?sort=genre&genres[]=<?php echo urlencode($genre['genre']); ?>"
                           class="genre-tag"
                           data-count="<?php echo $genre['count']; ?>"
                           title="<?php echo $genre['count']; ?> аниме, средний рейтинг: <?php echo $genre['avg_rating'] ? round($genre['avg_rating'], 1) : 'не оценено'; ?>">
                            <?php echo h($genre['genre']); ?>
                            <span class="genre-count"><?php echo $genre['count']; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="top-anime">
                <div class="anime-header">
                    <h2>🎬 Каталог аниме
                    <?php if (!empty($searchTitle)): ?>
                        - "<?php echo h($searchTitle); ?>"
                    <?php endif; ?>
                    <?php if (!empty($genreFilters)): ?>
                        <?php echo !empty($searchTitle) ? ' (' : ' - '; ?><?php echo implode(', ', $genreFilters); ?><?php echo !empty($searchTitle) ? ')' : ''; ?>
                    <?php endif; ?>
                    </h2>
                    <div class="sorting-controls">
                        <form method="GET" class="sort-form" id="filterForm">
                            <?php if ($sortBy === 'genre'): ?>
                            <div class="sort-group <?php echo !empty($genreFilters) ? 'filter-active' : ''; ?>">
                                <label for="genres">Жанры:</label>
                                <div class="custom-multiselect">
                                    <div class="multiselect-overlay" id="genreOverlay"></div>
                                    <button type="button" class="multiselect-button" id="genreMultiselect">
                                        <span class="multiselect-button-text placeholder">Выберите жанр</span>
                                        <span class="multiselect-arrow">▼</span>
                                    </button>
                                    <div class="multiselect-dropdown" id="genreDropdown" style="display: none;">
                                        <input type="text" class="multiselect-search" placeholder="🔍 Поиск жанров..." id="genreSearch">
                                        <div class="multiselect-option select-all" data-value="none">
                                            <div class="multiselect-checkbox"></div>
                                            <span class="multiselect-label">Очистить всё</span>
                                        </div>
                                        <?php foreach ($availableGenres as $genre): ?>
                                            <div class="multiselect-option <?php echo in_array($genre, $genreFilters) ? 'selected' : ''; ?>" data-value="<?php echo h($genre); ?>">
                                                <div class="multiselect-checkbox"></div>
                                                <span class="multiselect-label"><?php echo h($genre); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- Скрытый оригинальный select для отправки формы -->
                                <select name="genres[]" id="genres" multiple class="genre-filter-select">
                                    <?php foreach ($availableGenres as $genre): ?>
                                        <option value="<?php echo h($genre); ?>" <?php echo in_array($genre, $genreFilters) ? 'selected' : ''; ?>>
                                            <?php echo h($genre); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            </div>
                            <?php endif; ?>
                            <div class="sort-group">
                                <label for="sort">Сортировать по:</label>
                                <select name="sort" id="sort" onchange="this.form.submit()">
                                    <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>Рейтингу</option>
                                    <option value="year" <?php echo $sortBy === 'year' ? 'selected' : ''; ?>>Году выпуска</option>
                                    <option value="title" <?php echo $sortBy === 'title' ? 'selected' : ''; ?>>Названию</option>
                                    <option value="genre" <?php echo $sortBy === 'genre' ? 'selected' : ''; ?>>Жанру</option>
                                    <option value="created_at" <?php echo $sortBy === 'created_at' ? 'selected' : ''; ?>>Дате добавления</option>
                                </select>
                            </div>
                            <?php if ($sortBy !== 'title' && $sortBy !== 'genre'): ?>
                            <div class="sort-group">
                                <label for="order">Порядок:</label>
                                <select name="order" id="order" onchange="this.form.submit()">
                                    <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>
                                        <?php
                                        echo $sortBy === 'rating' ? 'Высокие → Низкие' :
                                            ($sortBy === 'year' ? 'Новые → Старые' :
                                            ($sortBy === 'created_at' ? 'Новые → Старые' : 'Я → А'));
                                        ?>
                                    </option>
                                    <option value="asc" <?php echo $sortOrder === 'asc' ? 'selected' : ''; ?>>
                                        <?php
                                        echo $sortBy === 'rating' ? 'Низкие → Высокие' :
                                            ($sortBy === 'year' ? 'Старые → Новые' :
                                            ($sortBy === 'created_at' ? 'Старые → Новые' : 'А → Я'));
                                        ?>
                                    </option>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if ($sortBy === 'title'): ?>
                            <div class="sort-group search-group">
                                <button type="button" class="btn-search" id="searchToggle" title="Поиск по названию">
                                    🔍 Поиск
                                </button>
                                <div class="search-input-container" id="searchContainer" style="display: none;">
                                    <input type="text" name="search" id="searchInput" placeholder="Введите название аниме..."
                                           value="<?php echo h($searchTitle); ?>" class="search-input">
                                    <button type="submit" class="btn-search-submit" title="Найти">Найти</button>
                                    <?php if (!empty($searchTitle)): ?>
                                        <button type="button" class="btn-search-clear" onclick="clearSearch()" title="Очистить поиск">✖️</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($genreFilters) || !empty($searchTitle)): ?>
                                <div class="sort-group">
                                    <a href="?" class="btn-reset-filter" title="Сбросить все фильтры">
                                        ✖️ Сбросить
                                    </a>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                <?php if (!empty($genreFilters) || !empty($searchTitle)): ?>
                <div class="anime-results-info">
                    <p>Найдено: <strong><?php echo count($topAnime); ?></strong> аниме
                    <?php if (!empty($searchTitle)): ?>
                        по запросу "<strong><?php echo h($searchTitle); ?></strong>"
                    <?php endif; ?>
                    <?php if (!empty($genreFilters)): ?>
                        <?php echo !empty($searchTitle) ? ' ' : ''; ?>в жанрах: "<?php echo implode('", "', $genreFilters); ?>"
                    <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
                <div class="anime-grid">
                    <?php foreach ($topAnime as $anime): ?>
                        <div class="anime-card" data-anime-id="<?php echo $anime['id']; ?>">
                            <?php if ($anime['image_url']): ?>
                                <img src="<?php echo h($anime['image_url']); ?>" alt="<?php echo h($anime['title']); ?>" class="anime-image">
                            <?php else: ?>
                                <div class="anime-image-placeholder">🎌</div>
                            <?php endif; ?>
                            <div class="anime-info">
                                <h3><?php echo h($anime['title']); ?></h3>
                                <p class="anime-genre"><?php echo h($anime['genre']); ?> • <?php echo h($anime['year']); ?></p>
                                <p class="anime-description"><?php echo h(substr($anime['description'], 0, 100)); ?>...</p>
                                <div class="anime-rating">
                                    <span class="rating-stars">⭐ <?php echo $anime['avg_rating'] ? round($anime['avg_rating'], 1) : 'Нет оценок'; ?></span>
                                    <span class="rating-count">(<?php echo $anime['rating_count']; ?> оценок)</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="latest-comments">
                <h2>💬 Последние комментарии</h2>
                <div class="comments-list">
                    <?php foreach ($latestComments as $comment): ?>
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
                                <span class="comment-anime">о <a href="anime.php?id=<?php echo $comment['anime_id']; ?>"><?php echo h($comment['anime_title']); ?></a></span>
                                <span class="comment-date"><?php echo formatDate($comment['created_at']); ?></span>
                            </div>
                            <div class="comment-text">
                                <?php echo h($comment['comment']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if (!$currentUser): ?>
            <section class="cta">
                <h2>Присоединяйтесь к сообществу!</h2>
                <p>Регистрируйтесь, чтобы добавлять аниме в свой список, ставить оценки и оставлять комментарии</p>
                <div class="cta-buttons">
                    <a href="register.php" class="btn btn-primary">Регистрация</a>
                    <a href="login.php" class="btn btn-secondary">Вход</a>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 <?php echo SITE_NAME; ?>. Все права защищены.</p>
        </div>
    </footer>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const multiselect = document.getElementById('genreMultiselect');
            const dropdown = document.getElementById('genreDropdown');
            const overlay = document.getElementById('genreOverlay');

            // Проверяем существование элементов жанров перед использованием
            if (multiselect && dropdown && overlay) {
                const buttonText = multiselect.querySelector('.multiselect-button-text');
                const arrow = multiselect.querySelector('.multiselect-arrow');
                const searchInput = document.getElementById('genreSearch');
                const originalSelect = document.getElementById('genres');
            // Получаем все опции и фильтруем программно
            const allOptions = dropdown.querySelectorAll('.multiselect-option');
            const options = Array.from(allOptions).filter(option =>
                !option.classList.contains('select-all') &&
                option.dataset.value !== 'none'
            );
            const clearAllOption = dropdown.querySelector('[data-value="none"]');

            let isOpen = false;

            // Обновляем текст кнопки на основе выбранных элементов
            function updateButtonText() {
                const selected = options.filter(option => option.classList.contains('selected'));
                const selectedValues = selected.map(option => option.dataset.value);

                if (selected.length === 0) {
                    buttonText.textContent = 'Выберите жанр';
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
                    overlay.classList.add('active');
                    dropdown.style.display = 'block';
                    // Небольшая задержка для плавной анимации
                    setTimeout(() => {
                        dropdown.classList.add('open');
                    }, 10);
                    multiselect.classList.add('active');
                    arrow.textContent = '▲';
                    searchInput.focus();
                } else {
                    overlay.classList.remove('active');
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
                    overlay.classList.remove('active');
                    dropdown.classList.remove('open');
                    multiselect.classList.remove('active');
                    arrow.textContent = '▼';
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
                    if (clearAllOption) clearAllOption.style.display = 'flex';

                    // Скрываем сообщение "Ничего не найдено"
                    const noResultsMessage = dropdown.querySelector('.multiselect-no-results');
                    if (noResultsMessage) {
                        noResultsMessage.style.display = 'none';
                    }
                    return;
                }

                // Всегда показываем служебные опции
                if (clearAllOption) clearAllOption.style.display = 'flex';

                // Фильтруем только обычные опции жанров
                const hasVisibleOptions = options.some(option => {
                    const label = option.querySelector('.multiselect-label').textContent.toLowerCase();
                    const matches = label.startsWith(term);
                    option.style.display = matches ? 'flex' : 'none';

                    // Отладочная информация (можно убрать в продакшене)
                    if (term.length === 1) {
                        console.log(`Жанр: "${label}", поиск: "${term}", совпадение: ${matches}`);
                    }

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
                    // Автоматически отправляем форму при изменении выбора жанров
                    document.getElementById('filterForm').submit();
                });
            });

            // Обработчик для "Очистить всё"
            clearAllOption.addEventListener('click', function(e) {
                e.stopPropagation();
                clearAll();
                // Автоматически отправляем форму при очистке всех жанров
                document.getElementById('filterForm').submit();
            });

            // Поиск
            searchInput.addEventListener('input', function() {
                filterOptions(this.value);
            });

            searchInput.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            // Закрытие при клике на overlay
            overlay.addEventListener('click', function() {
                closeDropdown();
            });

            // Закрытие при клике вне элемента
            document.addEventListener('click', function(e) {
                if (!multiselect.contains(e.target) && !dropdown.contains(e.target) && !overlay.contains(e.target)) {
                    closeDropdown();
                }
            });

            // Закрытие по Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && isOpen) {
                    closeDropdown();
                }
            });

            // Навигация клавиатурой
            dropdown.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    const visibleOptions = Array.from(options).filter(option =>
                        option.style.display !== 'none'
                    );

                    if (visibleOptions.length === 0) return;

                    const focused = dropdown.querySelector('.multiselect-option:focus') ||
                                   dropdown.querySelector('.multiselect-option.focused');
                    let index = focused ? visibleOptions.indexOf(focused) : -1;

                    if (e.key === 'ArrowDown') {
                        index = (index + 1) % visibleOptions.length;
                    } else {
                        index = index <= 0 ? visibleOptions.length - 1 : index - 1;
                    }

                    // Убираем предыдущий фокус
                    if (focused) focused.classList.remove('focused');

                    // Устанавливаем новый фокус
                    visibleOptions[index].classList.add('focused');
                    visibleOptions[index].scrollIntoView({ block: 'nearest' });
                }

                if (e.key === 'Enter') {
                    e.preventDefault();
                    const focused = dropdown.querySelector('.multiselect-option.focused');
                    if (focused && !focused.classList.contains('select-all')) {
                        focused.click();
                    }
                }
            });

            // Инициализация: устанавливаем правильное начальное состояние
            function initializeComponent() {
                // Убеждаемся, что dropdown скрыт
                overlay.classList.remove('active');
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
            }

            // Обработка кликов по карточкам аниме
            document.querySelectorAll('.anime-card, .top-anime-item').forEach(card => {
                card.addEventListener('click', function() {
                    const animeId = this.getAttribute('data-anime-id');
                    if (animeId) {
                        window.location.href = `anime.php?id=${animeId}`;
                    }
                });

                // Добавляем стиль курсора для указания на кликабельность
                card.style.cursor = 'pointer';
            });

            // Функциональность поиска
            const searchToggle = document.getElementById('searchToggle');
            const searchContainer = document.getElementById('searchContainer');
            const searchInput = document.getElementById('searchInput');

            // Проверяем существование элементов поиска перед использованием
            if (searchToggle && searchContainer && searchInput) {
                // Показать/скрыть поле поиска при активном поиске
                if (searchInput.value.trim() !== '') {
                    searchContainer.style.display = 'flex';
                    searchToggle.textContent = '🔍 Скрыть';
                }

                searchToggle.addEventListener('click', function() {
                    if (searchContainer.style.display === 'none') {
                        searchContainer.style.display = 'flex';
                        searchInput.focus();
                        this.textContent = '🔍 Скрыть';
                    } else {
                        searchContainer.style.display = 'none';
                        this.textContent = '🔍 Поиск';
                    }
                });
            }

            // Функция очистки поиска
            window.clearSearch = function() {
                const currentUrl = new URL(window.location);
                currentUrl.searchParams.delete('search');
                window.location.href = currentUrl.toString();
            };

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
                dropdown.classList.toggle('show');
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
                        alert('Неподдерживаемый формат файла. Разрешены: JPG, PNG, GIF, WebP');
                        input.value = '';
                        return;
                    }

                    // Если все проверки прошли, отправляем форму
                    document.getElementById('avatarForm').submit();
                }
            }
        });
    </script>

</body>
</html>
