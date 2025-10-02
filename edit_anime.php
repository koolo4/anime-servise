<?php
require_once 'config.php';

// Проверяем права администратора
requireAdmin();

$currentUser = getCurrentUser();
$anime_id = (int)($_GET['id'] ?? 0);

if (!$anime_id) {
    redirect('index.php');
}

$pdo = getDB();
$error = '';
$success = '';

// Получаем информацию об аниме
$stmt = $pdo->prepare("SELECT * FROM anime WHERE id = ?");
$stmt->execute([$anime_id]);
$anime = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$anime) {
    redirect('index.php');
}

// Обработка формы редактирования
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Проверка CSRF токена
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = 'CSRF токен недействителен. Обновите страницу и попробуйте снова.';
    } else {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $genres = $_POST['genres'] ?? [];
        $genre = formatGenres($genres);
        $year = (int)$_POST['year'];
        $studio = trim($_POST['studio']);
        $image_url = trim($_POST['image_url']);

        // Определяем способ добавления трейлера
        $trailer_method = $_POST['trailer_method'] ?? 'url';
        $trailer_url = $anime['trailer_url']; // сохраняем текущий
        $trailer_file_path = null;

        if (empty($title)) {
            $error = 'Название аниме обязательно для заполнения';
        } else {
            // Обработка трейлера
            if ($trailer_method === 'url') {
                $trailer_url = trim($_POST['trailer_url']);
            } elseif ($trailer_method === 'upload' && isset($_FILES['trailer_file']) && $_FILES['trailer_file']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = ['video/mp4', 'video/webm', 'video/avi'];
                $maxSize = 100 * 1024 * 1024; // 100MB
                $fileType = $_FILES['trailer_file']['type'];
                $fileSize = $_FILES['trailer_file']['size'];
                $fileTmp = $_FILES['trailer_file']['tmp_name'];
                $fileExt = strtolower(pathinfo($_FILES['trailer_file']['name'], PATHINFO_EXTENSION));

                if (!in_array($fileType, $allowedTypes)) {
                    $error = 'Неподдерживаемый формат видео. Разрешены: MP4, WebM, AVI';
                } elseif ($fileSize > $maxSize) {
                    $error = 'Файл слишком большой. Максимальный размер: 100MB';
                } else {
                    $uploadDir = 'uploads/trailers/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $newFileName = uniqid('trailer_', true) . '.' . $fileExt;
                    $destination = $uploadDir . $newFileName;
                    if (move_uploaded_file($fileTmp, $destination)) {
                        // Удаляем старый файл, если он был загружен
                        if ($anime['trailer_url'] && strpos($anime['trailer_url'], 'uploads/trailers/') !== false && file_exists($anime['trailer_url'])) {
                            unlink($anime['trailer_url']);
                        }
                        $trailer_url = $destination;
                    } else {
                        $error = 'Ошибка при загрузке видеофайла.';
                    }
                }
            }

            if (!$error) {
                // Обновляем аниме в базе данных
                $stmt = $pdo->prepare("
                    UPDATE anime
                    SET title = ?, description = ?, genre = ?, year = ?, studio = ?, image_url = ?, trailer_url = ?
                    WHERE id = ?
                ");

                if ($stmt->execute([$title, $description, $genre, $year, $studio, $image_url, $trailer_url, $anime_id])) {
                    $success = 'Аниме успешно обновлено!';
                    // Обновляем данные для отображения
                    $anime['title'] = $title;
                    $anime['description'] = $description;
                    $anime['genre'] = $genre;
                    $anime['year'] = $year;
                    $anime['studio'] = $studio;
                    $anime['image_url'] = $image_url;
                    $anime['trailer_url'] = $trailer_url;
                } else {
                    $error = 'Ошибка при обновлении аниме';
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
    <title>Редактировать аниме - <?php echo SITE_NAME; ?></title>
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

        /* Стили для радиокнопок трейлера */
        .trailer-method-selector {
            display: flex;
            gap: 25px;
            align-items: center;
            margin-bottom: 15px;
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
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1 class="logo"><a href="index.php"><?php echo SITE_NAME; ?></a></h1>
            <nav class="nav">
                <a href="index.php">Главная</a>
                <a href="add_anime.php">Добавить аниме</a>

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
            <div class="form-container">
                <h2>✏️ Редактировать аниме: <?php echo h($anime['title']); ?></h2>

                <?php if ($error): ?>
                    <div class="error"><?php echo $error; ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success"><?php echo $success; ?></div>
                <?php endif; ?>

                <form method="POST" class="add-anime-form" enctype="multipart/form-data">
                    <!-- CSRF защита -->
                    <input type="hidden" name="csrf_token" value="<?php echo h(generateCSRFToken()); ?>">
                    <div class="form-group">
                        <label for="title">Название аниме *</label>
                        <input type="text" id="title" name="title" value="<?php echo h($anime['title']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Описание</label>
                        <textarea id="description" name="description" rows="6" placeholder="Описание сюжета аниме..."><?php echo h($anime['description']); ?></textarea>
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
                                $selectedGenres = parseGenres($anime['genre']);
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
                        <label for="year">Год выпуска</label>
                        <input type="number" id="year" name="year" value="<?php echo $anime['year']; ?>" min="1900" max="2030" placeholder="2023">
                    </div>

                    <div class="form-group">
                        <label for="studio">Студия</label>
                        <input type="text" id="studio" name="studio" value="<?php echo h($anime['studio'] ?? ''); ?>" placeholder="Название студии...">
                    </div>

                    <div class="form-group">
                        <label for="image_url">URL изображения</label>
                        <input type="url" id="image_url" name="image_url" value="<?php echo h($anime['image_url']); ?>" placeholder="https://example.com/image.jpg">
                    </div>

                    <div class="form-group">
                        <label>Трейлер</label>
                        <div class="trailer-method-selector">
                            <label>
                                🔗 Вставить ссылку
                                <input type="radio" name="trailer_method" value="url" <?php if (!($anime['trailer_url'] && strpos($anime['trailer_url'], 'uploads/trailers/') === 0)) echo 'checked'; ?> onclick="toggleTrailerInput('url')">
                            </label>
                            <label>
                                📁 Загрузить файл
                                <input type="radio" name="trailer_method" value="upload" <?php if ($anime['trailer_url'] && strpos($anime['trailer_url'], 'uploads/trailers/') === 0) echo 'checked'; ?> onclick="toggleTrailerInput('upload')">
                            </label>
                        </div>
                        <div id="trailer_url_block" style="margin-top: 10px;<?php if ($anime['trailer_url'] && strpos($anime['trailer_url'], 'uploads/trailers/') === 0) echo 'display:none;'; ?>">
                            <input type="url" id="trailer_url" name="trailer_url" value="<?php echo h($anime['trailer_url'] && strpos($anime['trailer_url'], 'uploads/trailers/') === 0 ? '' : $anime['trailer_url']); ?>" placeholder="https://youtube.com/watch?v=... или https://rutube.ru/video/...">
                            <small class="form-hint">Поддерживается YouTube, Rutube, а также прямые ссылки на MP4 видео</small>
                        </div>
                        <div id="trailer_file_block" style="margin-top: 10px;<?php if (!($anime['trailer_url'] && strpos($anime['trailer_url'], 'uploads/trailers/') === 0)) echo 'display:none;'; ?>">
                            <input type="file" name="trailer_file" id="trailer_file" accept="video/mp4,video/webm,video/avi">
                            <small class="form-hint">Максимальный размер: 100MB. Разрешены: MP4, WebM, AVI</small>
                            <?php if ($anime['trailer_url'] && strpos($anime['trailer_url'], 'uploads/trailers/') === 0): ?>
                                <div style="margin-top: 8px;">
                                    <video src="<?php echo h($anime['trailer_url']); ?>" controls style="max-width: 320px; max-height: 180px;"></video>
                                    <div>
                                        <small>Загруженный трейлер: <?php echo basename($anime['trailer_url']); ?></small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">💾 Сохранить изменения</button>
                        <a href="anime.php?id=<?php echo $anime_id; ?>" class="btn btn-secondary">❌ Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 <?php echo SITE_NAME; ?>. У вас нет прав.</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
            updateButtonText();

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

            // Логика переключения между URL и загрузкой файла трейлера
            window.toggleTrailerInput = function(method) {
                var urlBlock = document.getElementById('trailer_url_block');
                var fileBlock = document.getElementById('trailer_file_block');
                if (method === 'url') {
                    urlBlock.style.display = '';
                    fileBlock.style.display = 'none';
                } else {
                    urlBlock.style.display = 'none';
                    fileBlock.style.display = '';
                }
            };

            // Инициализация трейлерных полей при загрузке
            (function() {
                var urlRadio = document.querySelector('input[name="trailer_method"][value="url"]');
                var uploadRadio = document.querySelector('input[name="trailer_method"][value="upload"]');
                if (urlRadio && urlRadio.checked) {
                    window.toggleTrailerInput('url');
                } else if (uploadRadio && uploadRadio.checked) {
                    window.toggleTrailerInput('upload');
                }
            })();
        });
    </script>
</body>
</html>
