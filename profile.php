<?php
require_once 'config.php';

// Проверяем авторизацию
if (!isLoggedIn()) {
    redirect('login.php');
}

$currentUser = getCurrentUser();
$pdo = getDB();

// Обработка загрузки аватарки (только для собственного профиля)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar']) && isset($_FILES['avatar'])) {
    // Проверяем CSRF токен
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $avatarError = 'Ошибка безопасности. Попробуйте еще раз.';
    } else {
        $uploadFile = $_FILES['avatar'];

        // Проверяем, что файл загружен без ошибок
        if ($uploadFile['error'] === UPLOAD_ERR_OK) {
            // Проверяем размер файла (максимум 5MB)
            if ($uploadFile['size'] > 5 * 1024 * 1024) {
                $avatarError = 'Файл слишком большой. Максимальный размер: 5MB.';
            } else {
                // Проверяем тип файла
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $uploadFile['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedTypes)) {
                    $avatarError = 'Недопустимый тип файла. Разрешены: JPEG, PNG, GIF, WebP.';
                } else {
                    // Создаем папку для аватаров, если её нет
                    $avatarDir = 'uploads/avatars/';
                    if (!is_dir($avatarDir)) {
                        mkdir($avatarDir, 0755, true);
                    }

                    // Генерируем уникальное имя файла
                    switch($mimeType) {
                        case 'image/jpeg':
                            $extension = 'jpg';
                            break;
                        case 'image/png':
                            $extension = 'png';
                            break;
                        case 'image/gif':
                            $extension = 'gif';
                            break;
                        case 'image/webp':
                            $extension = 'webp';
                            break;
                        default:
                            $extension = 'jpg';
                            break;
                    }

                    $filename = 'avatar_' . $currentUser['id'] . '_' . time() . '.' . $extension;
                    $avatarPath = $avatarDir . $filename;

                    // Перемещаем загруженный файл
                    if (move_uploaded_file($uploadFile['tmp_name'], $avatarPath)) {
                        // Удаляем старый аватар, если он существует
                        if ($currentUser['avatar'] && file_exists($currentUser['avatar'])) {
                            unlink($currentUser['avatar']);
                        }

                        // Обновляем путь к аватару в базе данных
                        $updateStmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                        if ($updateStmt->execute([$avatarPath, $currentUser['id']])) {
                            $avatarSuccess = 'Аватар успешно обновлен!';
                            // Обновляем текущего пользователя
                            $currentUser['avatar'] = $avatarPath;
                        } else {
                            $avatarError = 'Ошибка при сохранении в базе данных.';
                            // Удаляем загруженный файл в случае ошибки
                            unlink($avatarPath);
                        }
                    } else {
                        $avatarError = 'Ошибка при загрузке файла.';
                    }
                }
            }
        } else {
            $avatarError = match($uploadFile['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой.',
                UPLOAD_ERR_PARTIAL => 'Файл загружен частично.',
                UPLOAD_ERR_NO_FILE => 'Файл не выбран.',
                default => 'Ошибка при загрузке файла.'
            };
        }
    }
}

// Определяем, чей профиль просматриваем
$profileUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $currentUser['id'];
$isOwnProfile = ($profileUserId === $currentUser['id']);

// Получаем информацию о пользователе, чей профиль просматриваем
if ($isOwnProfile) {
    $profileUser = $currentUser;
} else {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$profileUserId]);
    $profileUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profileUser) {
        redirect('index.php'); // Пользователь не найден
    }
}

// Получаем статистику пользователя
$commentsQuery = "SELECT COUNT(*) as comments_count FROM comments WHERE user_id = ?";
$stmt = $pdo->prepare($commentsQuery);
$stmt->execute([$profileUserId]);
$commentsCount = $stmt->fetchColumn();

// Подсчитываем аниме по статусам
$statusCounts = [];
$statusCountQuery = "SELECT status, COUNT(*) as count FROM user_anime_status WHERE user_id = ? GROUP BY status";
$stmt = $pdo->prepare($statusCountQuery);
$stmt->execute([$profileUserId]);
$statusResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($statusResults as $row) {
    $statusCounts[$row['status']] = $row['count'];
}

// Объединяем статистику
$stats = [
    'watched_count' => $statusCounts['completed'] ?? 0,
    'comments_count' => $commentsCount,
    'planned_count' => $statusCounts['planned'] ?? 0,
    'watching_count' => $statusCounts['watching'] ?? 0,
    'completed_count' => $statusCounts['completed'] ?? 0,
    'dropped_count' => $statusCounts['dropped'] ?? 0
];

// Получаем лучшее аниме пользователя
$bestAnimeQuery = "
    SELECT a.title, a.id, r.overall_rating
    FROM anime a
    JOIN ratings r ON a.id = r.anime_id
    WHERE r.user_id = ?
    ORDER BY r.overall_rating DESC, r.created_at ASC
    LIMIT 1
";
$stmt = $pdo->prepare($bestAnimeQuery);
$stmt->execute([$profileUserId]);
$bestAnime = $stmt->fetch(PDO::FETCH_ASSOC);

// Функция для получения аниме по статусу
function getUserAnimeByStatus($pdo, $userId, $status) {
    $queries = [
        'completed' => "
            SELECT a.*, r.overall_rating as rating, uas.created_at as rated_at, uas.status as watch_status
            FROM anime a
            JOIN user_anime_status uas ON a.id = uas.anime_id
            LEFT JOIN ratings r ON a.id = r.anime_id AND r.user_id = uas.user_id
            WHERE uas.user_id = ? AND uas.status = 'completed'
            ORDER BY uas.created_at DESC
        ",
        'planned' => "
            SELECT a.*, NULL as rating, uas.created_at as rated_at, uas.status as watch_status
            FROM anime a
            JOIN user_anime_status uas ON a.id = uas.anime_id
            WHERE uas.user_id = ? AND uas.status = 'planned'
            ORDER BY uas.created_at DESC
        ",
        'watching' => "
            SELECT a.*, r.overall_rating as rating, uas.created_at as rated_at, uas.status as watch_status
            FROM anime a
            JOIN user_anime_status uas ON a.id = uas.anime_id
            LEFT JOIN ratings r ON a.id = r.anime_id AND r.user_id = uas.user_id
            WHERE uas.user_id = ? AND uas.status = 'watching'
            ORDER BY uas.created_at DESC
        ",
        'dropped' => "
            SELECT a.*, r.overall_rating as rating, uas.created_at as rated_at, uas.status as watch_status
            FROM anime a
            JOIN user_anime_status uas ON a.id = uas.anime_id
            LEFT JOIN ratings r ON a.id = r.anime_id AND r.user_id = uas.user_id
            WHERE uas.user_id = ? AND uas.status = 'dropped'
            ORDER BY uas.created_at DESC
        "
    ];

    if (!isset($queries[$status])) {
        return [];
    }

    $stmt = $pdo->prepare($queries[$status]);
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// AJAX обработка
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $requestedTab = $_GET['tab'] ?? 'completed';
    $userAnime = getUserAnimeByStatus($pdo, $profileUserId, $requestedTab);

    if ($userAnime) {
        echo '<div class="anime-results-info">';
        echo '<p>Найдено: <strong>' . count($userAnime) . '</strong> аниме</p>';
        echo '</div>';
        echo '<div class="anime-grid">';
        foreach ($userAnime as $anime) {
            $draggableAttr = $isOwnProfile ? 'draggable="true"' : '';
            echo '<div class="anime-card" data-anime-id="' . $anime['id'] . '" ' . $draggableAttr . ' data-current-status="' . $requestedTab . '">';
            echo '<a href="anime.php?id=' . $anime['id'] . '" class="anime-image-link">';
            if ($anime['image_url']) {
                echo '<img src="' . h($anime['image_url']) . '" alt="' . h($anime['title']) . '" class="anime-image" />';
            } else {
                echo '<div class="anime-image-placeholder">🎌</div>';
            }
            echo '</a>';
            echo '<div class="anime-info">';
            echo '<h3><a href="anime.php?id=' . $anime['id'] . '">' . h($anime['title']) . '</a></h3>';
            echo '<p class="anime-genre">' . h($anime['genre']) . ' • ' . h($anime['year']) . '</p>';
            echo '<p class="anime-description">' . h(substr($anime['description'], 0, 100)) . '...</p>';
            echo '<div class="anime-rating">';
            if ($anime['rating']) {
                echo '<span class="rating-stars">⭐ ' . $anime['rating'] . '/10</span>';
                echo '<span class="rating-count">Оценено ' . formatDate($anime['rated_at']) . '</span>';
            } else {
                switch ($anime['watch_status']) {
                    case 'planned':
                        echo '<span class="status-badge planned">📝 Запланировано</span>';
                        break;
                    case 'watching':
                        echo '<span class="status-badge watching">👀 Смотрю</span>';
                        break;
                    case 'dropped':
                        echo '<span class="status-badge dropped">❌ Брошено</span>';
                        break;
                    default:
                        echo '<span class="status-badge">Добавлено ' . formatDate($anime['rated_at']) . '</span>';
                }
            }
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<div class="profile-container">';
        $userName = $isOwnProfile ? 'Вы' : h($profileUser['username']);
        switch ($requestedTab) {
            case 'completed':
                echo '<p>' . ($isOwnProfile ? 'Вы еще не посмотрели ни одного аниме.' : h($profileUser['username']) . ' не посмотрел(а) ни одного аниме.') . '</p>';
                break;
            case 'planned':
                echo '<p>' . ($isOwnProfile ? 'Вы еще не запланировали посмотреть ни одного аниме.' : h($profileUser['username']) . ' не запланировал(а) посмотреть ни одного аниме.') . '</p>';
                break;
            case 'watching':
                echo '<p>' . ($isOwnProfile ? 'Вы еще не начали смотреть ни одного аниме.' : h($profileUser['username']) . ' не начал(а) смотреть ни одного аниме.') . '</p>';
                break;
            case 'dropped':
                echo '<p>' . ($isOwnProfile ? 'Вы еще не бросили ни одного аниме.' : h($profileUser['username']) . ' не бросил(а) ни одного аниме.') . '</p>';
                break;
        }
        echo '</div>';
    }
    exit;
}

// Загружаем аниме для изначально активной вкладки
$activeTab = $_GET['tab'] ?? 'completed';
$userAnime = getUserAnimeByStatus($pdo, $profileUserId, $activeTab);

// Получаем комментарии пользователя
$userCommentsQuery = "
    SELECT c.*, a.title as anime_title
    FROM comments c
    JOIN anime a ON c.anime_id = a.id
    WHERE c.user_id = ?
    ORDER BY c.created_at DESC
    LIMIT 10
";
$stmt = $pdo->prepare($userCommentsQuery);
$stmt->execute([$profileUserId]);
$userComments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo generateCSRFToken(); ?>" />
    <title><?php echo $isOwnProfile ? 'Личный кабинет' : 'Профиль ' . h($profileUser['username']); ?> - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="style.css" />
    <style>
        /* Стили для вкладок статусов просмотра */
        .watch-status-tabs {
            margin: 30px 0;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        [data-theme="dark"] .watch-status-tabs {
            background: var(--card-bg);
        }

        .tabs-header {
            display: flex;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        [data-theme="dark"] .tabs-header {
            background: var(--primary-gradient);
        }

        .tab-button {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 10px;
            text-decoration: none;
            color: rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            position: relative;
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            border: none;
            background: none;
            cursor: pointer;
        }

        .tab-button:last-child {
            border-right: none;
        }

        .tab-button:hover {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        .tab-button.active {
            color: white;
            background: rgba(255, 255, 255, 0.2);
            box-shadow: inset 0 3px 0 0 #fff;
        }

        .tab-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .tab-text {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .tab-count {
            background: rgba(255, 255, 255, 0.3);
            color: white;
            font-size: 12px;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 12px;
            min-width: 20px;
            text-align: center;
        }

        .tab-button.active .tab-count {
            background: rgba(255, 255, 255, 0.4);
        }

        /* Стили для контента вкладок */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Индикатор загрузки */
        .loading {
            text-align: center;
            padding: 40px 20px;
            font-size: 16px;
            color: var(--text-secondary);
        }

        /* Стили для бейджей статуса */
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .status-badge.planned {
            background: #e3f2fd;
            color: #1976d2;
        }

        .status-badge.watching {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .status-badge.dropped {
            background: #ffebee;
            color: #d32f2f;
        }

        [data-theme="dark"] .status-badge.planned {
            background: rgba(25, 118, 210, 0.2);
            color: #90caf9;
        }

        [data-theme="dark"] .status-badge.watching {
            background: rgba(123, 31, 162, 0.2);
            color: #ce93d8;
        }

        [data-theme="dark"] .status-badge.dropped {
            background: rgba(211, 47, 47, 0.2);
            color: #ef5350;
        }

        /* Стили для drag-and-drop */
        .anime-card {
            cursor: grab;
            transition: all 0.3s ease;
        }

        .anime-card:active {
            cursor: grabbing;
        }

        .anime-card.dragging {
            opacity: 0.5;
            transform: rotate(5deg);
            z-index: 1000;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .tab-button.drag-over {
            background: rgba(255, 255, 255, 0.3) !important;
            transform: scale(1.05);
            animation: pulse 0.5s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.3); }
            50% { box-shadow: 0 0 0 10px rgba(255, 255, 255, 0.1); }
            100% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0); }
        }

        /* Индикатор перетаскивания */
        .drag-indicator {
            position: fixed;
            top: 10px;
            right: 10px;
            background: var(--primary-gradient);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            z-index: 9999;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s ease;
            pointer-events: none;
        }

        .drag-indicator.show {
            opacity: 1;
            transform: translateY(0);
        }

        /* Toast уведомления */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--primary-gradient);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            z-index: 9999;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
            max-width: 300px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast.success {
            background: linear-gradient(135deg, #28a745, #20c997);
        }

        .toast.error {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }

        /* Анимация исчезновения карточки */
        .anime-card.removing {
            opacity: 0;
            transform: scale(0.8);
            transition: all 0.3s ease;
        }

        /* Стили для drop zones */
        .tab-button.drop-zone {
            position: relative;
        }

        .tab-button.drop-zone::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border: 2px dashed transparent;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .tab-button.drop-zone.drag-over::after {
            border-color: rgba(255, 255, 255, 0.8);
            background: rgba(255, 255, 255, 0.1);
        }

        /* Стили для изменения аватарки */
        .profile-avatar-large {
            position: relative;
        }

        .avatar-change-btn {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: white;
            border: 3px solid white;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .avatar-change-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        [data-theme="dark"] .avatar-change-btn {
            border-color: var(--bg-color);
        }

        /* Сообщения об аватарке */
        .avatar-message {
            margin-top: 10px;
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
        }

        .avatar-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .avatar-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        [data-theme="dark"] .avatar-message.success {
            background: rgba(40, 167, 69, 0.2);
            color: #66bb6a;
            border-color: rgba(40, 167, 69, 0.3);
        }

        [data-theme="dark"] .avatar-message.error {
            background: rgba(220, 53, 69, 0.2);
            color: #ef5350;
            border-color: rgba(220, 53, 69, 0.3);
        }

        /* Модальное окно */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            position: relative;
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }

        [data-theme="dark"] .modal-content {
            background-color: var(--card-bg);
            color: var(--text-color);
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid #e9ecef;
            background: var(--primary-gradient);
            color: white;
            border-radius: 15px 15px 0 0;
        }

        [data-theme="dark"] .modal-header {
            border-bottom-color: var(--border-color);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .avatar-form {
            padding: 25px;
        }

        .avatar-preview-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border: 2px dashed #dee2e6;
        }

        [data-theme="dark"] .avatar-preview-section {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border-color);
        }

        .current-avatar h4,
        .new-avatar h4 {
            margin: 0 0 15px 0;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            color: var(--text-secondary);
        }

        .avatar-preview-current,
        .avatar-preview-new {
            width: 120px;
            height: 120px;
            margin: 0 auto;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }

        [data-theme="dark"] .avatar-preview-current,
        [data-theme="dark"] .avatar-preview-new {
            border-color: var(--border-color);
            background: var(--bg-color);
        }

        .avatar-preview-current img,
        .avatar-preview-new img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-gradient);
            color: white;
            font-size: 48px;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }

        [data-theme="dark"] .form-group input[type="file"] {
            background: var(--bg-color);
            border-color: var(--border-color);
            color: var(--text-color);
        }

        .form-group input[type="file"]:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-help {
            display: block;
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            margin-top: 25px;
        }

        [data-theme="dark"] .modal-footer {
            border-top-color: var(--border-color);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 120px;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        /* Адаптивность для мобильных устройств */
        @media (max-width: 768px) {
            .modal-content {
                margin: 10% auto;
                width: 95%;
            }

            .avatar-preview-section {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .modal-footer {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .tabs-header {
                flex-wrap: wrap;
            }

            .tab-button {
                flex-basis: 50%;
                padding: 15px 8px;
            }

            .tab-text {
                font-size: 12px;
            }

            .tab-icon {
                font-size: 20px;
                margin-bottom: 4px;
            }
        }

        @media (max-width: 480px) {
            .tab-button {
                flex-basis: 100%;
                flex-direction: row;
                justify-content: center;
                gap: 10px;
                padding: 12px;
            }

            .tab-icon {
                margin-bottom: 0;
                font-size: 18px;
            }

            /* Отключаем drag-and-drop на мобильных */
            .anime-card {
                cursor: pointer;
            }

            .anime-card[draggable="true"] {
                cursor: pointer;
            }

            .avatar-change-btn {
                width: 35px;
                height: 35px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <!-- Индикатор перетаскивания (только для собственного профиля) -->
    <?php if ($isOwnProfile): ?>
    <div id="dragIndicator" class="drag-indicator">
        Перетащите на нужную вкладку
    </div>
    <?php endif; ?>

    <!-- Контейнер для toast уведомлений -->
    <div id="toastContainer"></div>

    <header class="header">
        <div class="container">
            <h1 class="logo"><a href="index.php"><?php echo SITE_NAME; ?></a></h1>
            <nav class="nav">
                <?php if ($currentUser): ?>
                    <?php if (isAdmin()): ?>
                        <a href="add_anime.php">Добавить аниме</a>
                    <?php endif; ?>
                    <a href="logout.php">Выйти</a>
                    <div class="user-info">
                        <span class="user-name"><?php echo h($currentUser['username']); ?></span>
                        <?php if ($currentUser['avatar'] && file_exists($currentUser['avatar'])): ?>
                            <img src="<?php echo h($currentUser['avatar']); ?>" alt="Аватарка <?php echo h($currentUser['username']); ?>" class="user-avatar" />
                        <?php else: ?>
                            <div class="user-avatar" title="<?php echo h($currentUser['username']); ?>">
                                <?php echo strtoupper(substr($currentUser['username'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
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
                <h2>
                    <?php if ($isOwnProfile): ?>
                        🏠 Личный кабинет - <?php echo h($profileUser['username']); ?>
                    <?php else: ?>
                        👤 Профиль пользователя - <?php echo h($profileUser['username']); ?>
                    <?php endif; ?>
                </h2>

                <div class="profile-avatar-section">
                    <div class="profile-avatar-large">
                        <?php if ($profileUser['avatar'] && file_exists($profileUser['avatar'])): ?>
                            <img src="<?php echo h($profileUser['avatar']); ?>" alt="Аватарка <?php echo h($profileUser['username']); ?>" class="profile-avatar-img" />
                        <?php else: ?>
                            <div class="profile-avatar-placeholder" title="<?php echo h($profileUser['username']); ?>">
                                <?php echo strtoupper(substr($profileUser['username'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($isOwnProfile): ?>
                            <button type="button" class="avatar-change-btn" onclick="openAvatarModal()" title="Изменить аватар">
                                📷
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="profile-info">
                        <h3><?php echo h($profileUser['username']); ?></h3>
                        <p class="profile-role"><?php echo $profileUser['role'] === 'admin' ? '👑 Администратор' : '👤 Пользователь'; ?></p>
                        <p class="profile-date">Регистрация: <?php echo formatDate($profileUser['created_at']); ?></p>

                        <?php if ($isOwnProfile): ?>
                            <?php if (isset($avatarSuccess)): ?>
                                <div class="avatar-message success">✅ <?php echo h($avatarSuccess); ?></div>
                            <?php endif; ?>
                            <?php if (isset($avatarError)): ?>
                                <div class="avatar-message error">❌ <?php echo h($avatarError); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="user-stats">
                    <div class="stat-card">
                        <span class="stat-number"><?php echo $stats['watched_count']; ?></span>
                        <span class="stat-label">Просмотрено аниме</span>
                    </div>
                    <div class="stat-card best-anime">
                        <?php if ($bestAnime): ?>
                            <span class="stat-number">⭐ <?php echo $bestAnime['overall_rating']; ?>/10</span>
                            <span class="stat-label">
                                <a href="anime.php?id=<?php echo $bestAnime['id']; ?>">
                                    <?php echo h($bestAnime['title']); ?>
                                </a>
                            </span>
                            <small class="best-anime-desc">Лучшее аниме</small>
                        <?php else: ?>
                            <span class="stat-number">—</span>
                            <span class="stat-label">Лучшее аниме</span>
                            <small class="best-anime-desc">Не оценено</small>
                        <?php endif; ?>
                    </div>
                    <div class="stat-card">
                        <span class="stat-number"><?php echo $stats['comments_count']; ?></span>
                        <span class="stat-label">Комментариев</span>
                    </div>
                </div>
            </div>

            <!-- Вкладки статусов просмотра -->
            <section class="watch-status-tabs">
                <div class="tabs-header">
                    <button type="button" onclick="switchTab('completed')" class="tab-button drop-zone <?php echo $activeTab === 'completed' ? 'active' : ''; ?>" data-tab="completed" data-status="completed">
                        <span class="tab-icon">✅</span>
                        <span class="tab-text">Просмотренные</span>
                        <span class="tab-count" id="completed-count"><?php echo $stats['completed_count']; ?></span>
                    </button>
                    <button type="button" onclick="switchTab('planned')" class="tab-button drop-zone <?php echo $activeTab === 'planned' ? 'active' : ''; ?>" data-tab="planned" data-status="planned">
                        <span class="tab-icon">📝</span>
                        <span class="tab-text">Запланировано</span>
                        <span class="tab-count" id="planned-count"><?php echo $stats['planned_count']; ?></span>
                    </button>
                    <button type="button" onclick="switchTab('watching')" class="tab-button drop-zone <?php echo $activeTab === 'watching' ? 'active' : ''; ?>" data-tab="watching" data-status="watching">
                        <span class="tab-icon">👀</span>
                        <span class="tab-text">Смотрю</span>
                        <span class="tab-count" id="watching-count"><?php echo $stats['watching_count']; ?></span>
                    </button>
                    <button type="button" onclick="switchTab('dropped')" class="tab-button drop-zone <?php echo $activeTab === 'dropped' ? 'active' : ''; ?>" data-tab="dropped" data-status="dropped">
                        <span class="tab-icon">❌</span>
                        <span class="tab-text">Брошено</span>
                        <span class="tab-count" id="dropped-count"><?php echo $stats['dropped_count']; ?></span>
                    </button>
                </div>
            </section>

            <section class="user-anime">
                <div class="anime-header">
                    <h2 id="anime-section-title">🎬
                        <span id="section-title-text">
                            <?php
                            if ($isOwnProfile) {
                                switch ($activeTab) {
                                    case 'completed': echo 'Просмотренные аниме'; break;
                                    case 'planned': echo 'Запланированные аниме'; break;
                                    case 'watching': echo 'Смотрю сейчас'; break;
                                    case 'dropped': echo 'Брошенные аниме'; break;
                                    default: echo 'Мои аниме';
                                }
                            } else {
                                $userName = h($profileUser['username']);
                                switch ($activeTab) {
                                    case 'completed': echo "Аниме пользователя $userName (просмотренные)"; break;
                                    case 'planned': echo "Аниме пользователя $userName (запланированные)"; break;
                                    case 'watching': echo "Аниме пользователя $userName (смотрит сейчас)"; break;
                                    case 'dropped': echo "Аниме пользователя $userName (брошенные)"; break;
                                    default: echo "Аниме пользователя $userName";
                                }
                            }
                            ?>
                        </span>
                    </h2>
                </div>

                <!-- Контейнер для динамически загружаемого контента -->
                <div id="tab-content-container">
                    <?php if ($userAnime): ?>
                        <div class="anime-results-info">
                            <p>Найдено: <strong><?php echo count($userAnime); ?></strong> аниме</p>
                        </div>
                        <div class="anime-grid">
                            <?php foreach ($userAnime as $anime): ?>
                                <div class="anime-card" data-anime-id="<?php echo $anime['id']; ?>" <?php echo $isOwnProfile ? 'draggable="true"' : ''; ?> data-current-status="<?php echo $activeTab; ?>">
                                    <a href="anime.php?id=<?php echo $anime['id']; ?>" class="anime-image-link">
                                        <?php if ($anime['image_url']): ?>
                                            <img src="<?php echo h($anime['image_url']); ?>" alt="<?php echo h($anime['title']); ?>" class="anime-image" />
                                        <?php else: ?>
                                            <div class="anime-image-placeholder">🎌</div>
                                        <?php endif; ?>
                                    </a>
                                    <div class="anime-info">
                                        <h3><a href="anime.php?id=<?php echo $anime['id']; ?>"><?php echo h($anime['title']); ?></a></h3>
                                        <p class="anime-genre"><?php echo h($anime['genre']); ?> • <?php echo h($anime['year']); ?></p>
                                        <p class="anime-description"><?php echo h(substr($anime['description'], 0, 100)); ?>...</p>
                                        <div class="anime-rating">
                                            <?php if ($anime['rating']): ?>
                                                <span class="rating-stars">⭐ <?php echo $anime['rating']; ?>/10</span>
                                                <span class="rating-count">Оценено <?php echo formatDate($anime['rated_at']); ?></span>
                                            <?php else: ?>
                                                <?php
                                                switch ($anime['watch_status']) {
                                                    case 'planned':
                                                        echo '<span class="status-badge planned">📝 Запланировано</span>';
                                                        break;
                                                    case 'watching':
                                                        echo '<span class="status-badge watching">👀 Смотрю</span>';
                                                        break;
                                                    case 'dropped':
                                                        echo '<span class="status-badge dropped">❌ Брошено</span>';
                                                        break;
                                                    default:
                                                        echo '<span class="status-badge">Добавлено ' . formatDate($anime['rated_at']) . '</span>';
                                                }
                                                ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="profile-container">
                            <p>
                            <?php
                            switch ($activeTab) {
                                case 'completed':
                                    echo $isOwnProfile ? 'Вы еще не посмотрели ни одного аниме.' : h($profileUser['username']) . ' не посмотрел(а) ни одного аниме.';
                                    break;
                                case 'planned':
                                    echo $isOwnProfile ? 'Вы еще не запланировали посмотреть ни одного аниме.' : h($profileUser['username']) . ' не запланировал(а) посмотреть ни одного аниме.';
                                    break;
                                case 'watching':
                                    echo $isOwnProfile ? 'Вы еще не начали смотреть ни одного аниме.' : h($profileUser['username']) . ' не начал(а) смотреть ни одного аниме.';
                                    break;
                                case 'dropped':
                                    echo $isOwnProfile ? 'Вы еще не бросили ни одного аниме.' : h($profileUser['username']) . ' не бросил(а) ни одного аниме.';
                                    break;
                            }
                            ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="user-comments">
                <h2>💬 <?php echo $isOwnProfile ? 'Мои комментарии' : 'Комментарии пользователя ' . h($profileUser['username']); ?></h2>
                <?php if ($userComments): ?>
                    <div class="comments-list">
                        <?php foreach ($userComments as $comment): ?>
                            <div class="comment-item">
                                <div class="comment-header">
                                    <span class="comment-anime">о <a href="anime.php?id=<?php echo $comment['anime_id']; ?>"><?php echo h($comment['anime_title']); ?></a></span>
                                    <span class="comment-date"><?php echo formatDate($comment['created_at']); ?></span>
                                </div>
                                <div class="comment-text">
                                    <?php echo h($comment['comment']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="profile-container">
                        <p>
                        <?php
                        if ($isOwnProfile) {
                            echo 'Вы еще не оставили ни одного комментария.';
                        } else {
                            echo h($profileUser['username']) . ' не оставил(а) ни одного комментария.';
                        }
                        ?>
                        </p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- Модальное окно для изменения аватарки (только для собственного профиля) -->
    <?php if ($isOwnProfile): ?>
    <div id="avatarModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📷 Изменить аватар</h3>
                <button type="button" class="modal-close" onclick="closeAvatarModal()">&times;</button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="avatar-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="upload_avatar" value="1">

                <div class="avatar-preview-section">
                    <div class="current-avatar">
                        <h4>Текущий аватар:</h4>
                        <div class="avatar-preview-current">
                            <?php if ($currentUser['avatar'] && file_exists($currentUser['avatar'])): ?>
                                <img src="<?php echo h($currentUser['avatar']); ?>" alt="Текущий аватар" />
                            <?php else: ?>
                                <div class="avatar-placeholder">
                                    <?php echo strtoupper(substr($currentUser['username'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="new-avatar">
                        <h4>Новый аватар:</h4>
                        <div class="avatar-preview-new" id="avatarPreview">
                            <div class="avatar-placeholder">Выберите файл</div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="avatar">Выберите изображение:</label>
                    <input type="file" id="avatar" name="avatar" accept="image/*" required onchange="previewAvatar(this)">
                    <small class="form-help">
                        Форматы: JPEG, PNG, GIF, WebP<br>
                        Максимальный размер: 5MB
                    </small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAvatarModal()">Отмена</button>
                    <button type="submit" class="btn btn-primary">Сохранить аватар</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 <?php echo SITE_NAME; ?>. У вас нет прав.</p>
        </div>
    </footer>

    <script>
        // Текущая активная вкладка
        let currentTab = '<?php echo $activeTab; ?>';

        // Тексты заголовков для разных вкладок
        const tabTitles = {
            completed: '<?php echo $isOwnProfile ? "Просмотренные аниме" : "Аниме пользователя " . h($profileUser["username"]) . " (просмотренные)"; ?>',
            planned: '<?php echo $isOwnProfile ? "Запланированные аниме" : "Аниме пользователя " . h($profileUser["username"]) . " (запланированные)"; ?>',
            watching: '<?php echo $isOwnProfile ? "Смотрю сейчас" : "Аниме пользователя " . h($profileUser["username"]) . " (смотрит сейчас)"; ?>',
            dropped: '<?php echo $isOwnProfile ? "Брошенные аниме" : "Аниме пользователя " . h($profileUser["username"]) . " (брошенные)"; ?>'
        };

        // Функция для переключения вкладок без перезагрузки страницы
        function switchTab(tabName) {
            if (currentTab === tabName) return;

            // Убираем активный класс у всех кнопок
            const allTabButtons = document.querySelectorAll('.tab-button');
            allTabButtons.forEach(button => {
                button.classList.remove('active');
            });

            // Добавляем активный класс к выбранной кнопке
            const activeButton = document.querySelector(`[data-tab="${tabName}"]`);
            if (activeButton) {
                activeButton.classList.add('active');
            }

            // Обновляем заголовок секции
            const titleElement = document.getElementById('section-title-text');
            if (titleElement && tabTitles[tabName]) {
                titleElement.textContent = tabTitles[tabName];
            }

            // Сохраняем выбранную вкладку
            currentTab = tabName;
            sessionStorage.setItem('activeTab', tabName);

            // Загружаем данные для выбранной вкладки через AJAX
            loadTabContent(tabName);
        }

        // Функция для загрузки контента вкладки через AJAX
        function loadTabContent(tabName) {
            const contentContainer = document.getElementById('tab-content-container');
            if (!contentContainer) return;

            // Показываем индикатор загрузки
            contentContainer.innerHTML = '<div class="loading">Загрузка...</div>';

            // Формируем URL для AJAX запроса
            const currentUrl = new URL(window.location);
            currentUrl.searchParams.set('tab', tabName);
            currentUrl.searchParams.set('ajax', '1');

            // Выполняем AJAX запрос
            fetch(currentUrl.toString())
                .then(response => response.text())
                .then(data => {
                    contentContainer.innerHTML = data;

                    // Переинициализируем обработчики кликов для карточек аниме
                    initAnimeCardHandlers();

                    // ИСПРАВЛЕНИЕ: синхронизируем счетчик с реальным количеством карточек
                    syncTabCounter(tabName);
                })
                .catch(error => {
                    console.error('Ошибка загрузки:', error);
                    contentContainer.innerHTML = '<div class="profile-container"><p>Ошибка загрузки данных.</p></div>';
                });
        }

        // Функция для синхронизации счетчика вкладки с реальным количеством карточек
        function syncTabCounter(tabName) {
            const actualCardsCount = document.querySelectorAll('.anime-card').length;
            const counterElement = document.getElementById(tabName + '-count');

            if (counterElement) {
                counterElement.textContent = actualCardsCount;
                console.log(`Синхронизирован счетчик для ${tabName}: ${actualCardsCount}`);
            }
        }

        // Инициализация обработчиков кликов для карточек аниме
        function initAnimeCardHandlers() {
            const animeCards = document.querySelectorAll('.anime-card');
            animeCards.forEach(card => {
                card.style.cursor = 'pointer';

                // Удаляем старые обработчики
                card.removeEventListener('click', handleAnimeCardClick);
                // Добавляем новые
                card.addEventListener('click', handleAnimeCardClick);
            });
        }

        // Обработчик клика по карточке аниме
        function handleAnimeCardClick(e) {
            // Не перенаправляем, если клик был по ссылке
            if (e.target.tagName === 'A' || e.target.closest('a')) {
                return;
            }

            const animeId = this.getAttribute('data-anime-id');
            if (animeId) {
                window.location.href = `anime.php?id=${animeId}`;
            }
        }

        // Управление темами
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('themeToggle');
            const body = document.body;

            // Всегда загружаем сохраненную тему из localStorage
            const savedTheme = localStorage.getItem('theme') || 'light';
            body.setAttribute('data-theme', savedTheme);

            // Обработчик переключения темы
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

            // Восстанавливаем активную вкладку при загрузке страницы
            const savedTab = sessionStorage.getItem('activeTab');
            if (savedTab && savedTab !== currentTab && document.querySelector(`[data-tab="${savedTab}"]`)) {
                switchTab(savedTab);
            } else {
                // ИСПРАВЛЕНИЕ: синхронизируем счетчик текущей вкладки при первой загрузке
                syncTabCounter(currentTab);
            }

            // Инициализируем обработчики для карточек аниме
            initAnimeCardHandlers();

            // Инициализируем drag-and-drop только для собственного профиля
            <?php if ($isOwnProfile): ?>
            initDragAndDrop();
            <?php endif; ?>
        });

        <?php if ($isOwnProfile): ?>
        // Глобальные переменные для drag-and-drop
        let draggedCard = null;
        let sourceStatus = null;
        let isDragAndDropInitialized = false;

        // Функция для инициализации drag-and-drop
        function initDragAndDrop() {
            // Избегаем повторной инициализации
            if (isDragAndDropInitialized) return;

            console.log('Инициализация drag-and-drop...');

            // Добавляем обработчики на document (один раз)
            document.addEventListener('dragstart', handleDragStart);
            document.addEventListener('dragend', handleDragEnd);

            // Глобальный обработчик dragend для принудительной очистки эффектов
            document.addEventListener('dragend', function(e) {
                // Дополнительная очистка всех drag-over эффектов
                setTimeout(clearAllDragEffects, 50);
                // Принудительно скрываем индикатор
                setTimeout(hideDragIndicator, 100);
            });

            // Дополнительный обработчик для отмены перетаскивания
            document.addEventListener('dragcancel', function(e) {
                clearAllDragEffects();
                hideDragIndicator();
            });

            // Обработчик отпускания мыши для дополнительной защиты
            document.addEventListener('mouseup', function(e) {
                // Если мышь отпущена, но индикатор все еще виден, скрываем его
                setTimeout(() => {
                    if (!draggedCard) {
                        hideDragIndicator();
                    }
                }, 200);
            });

            // Добавляем обработчики для drop zones
            const dropZones = document.querySelectorAll('.tab-button.drop-zone');
            console.log('Найдено drop zones:', dropZones.length);

            dropZones.forEach(zone => {
                zone.addEventListener('dragover', handleDragOver);
                zone.addEventListener('drop', handleDrop);
                zone.addEventListener('dragenter', handleDragEnter);
                zone.addEventListener('dragleave', handleDragLeave);
            });

            isDragAndDropInitialized = true;
            console.log('Drag-and-drop инициализирован');
        }

        // Обработчик начала перетаскивания
        function handleDragStart(e) {
            console.log('Drag start triggered', e.target);

            // Ищем ближайшую anime-card, включая сам элемент
            const animeCard = e.target.closest('.anime-card');

            if (!animeCard) {
                console.log('Не найдена anime-card');
                return;
            }

            draggedCard = animeCard;
            sourceStatus = draggedCard.getAttribute('data-current-status');

            console.log('Начинаем перетаскивание:', {
                animeId: draggedCard.getAttribute('data-anime-id'),
                sourceStatus: sourceStatus
            });

            // Добавляем визуальные эффекты
            draggedCard.classList.add('dragging');

            // Показываем индикатор
            showDragIndicator();

            // Устанавливаем данные для передачи
            e.dataTransfer.setData('text/plain', '');
            e.dataTransfer.effectAllowed = 'move';
        }

        // Обработчик окончания перетаскивания
        function handleDragEnd(e) {
            if (!e.target.classList.contains('anime-card')) return;

            // Убираем визуальные эффекты
            e.target.classList.remove('dragging');

            // Принудительно убираем все drag-over эффекты
            clearAllDragEffects();

            // Дополнительная очистка с задержкой для гарантии
            setTimeout(() => {
                clearAllDragEffects();
            }, 100);

            // Скрываем индикатор
            hideDragIndicator();

            // Сбрасываем переменные
            draggedCard = null;
            sourceStatus = null;
        }

        // Обработчик наведения на drop zone
        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        }

        // Обработчик входа в drop zone
        function handleDragEnter(e) {
            if (!draggedCard) return;
            e.target.closest('.tab-button').classList.add('drag-over');
        }

        // Обработчик выхода из drop zone
        function handleDragLeave(e) {
            const dropZone = e.target.closest('.tab-button');
            if (dropZone && !dropZone.contains(e.relatedTarget)) {
                dropZone.classList.remove('drag-over');
            }
        }

        // Принудительная очистка drag-over эффектов (запасной механизм)
        function clearAllDragEffects() {
            document.querySelectorAll('.tab-button.drop-zone').forEach(zone => {
                zone.classList.remove('drag-over');
            });
        }

        // Обработчик drop
        function handleDrop(e) {
            e.preventDefault();
            console.log('Drop triggered', e.target);

            // Принудительно убираем все drag-over эффекты
            clearAllDragEffects();

            // Скрываем индикатор перетаскивания
            hideDragIndicator();

            if (!draggedCard) {
                console.log('Нет draggedCard');
                return;
            }

            const dropZone = e.target.closest('.tab-button.drop-zone');
            if (!dropZone) {
                console.log('Не найдена drop zone');
                return;
            }

            const targetStatus = dropZone.getAttribute('data-status');
            console.log('Drop на статус:', targetStatus, 'с:', sourceStatus);

            // Проверяем, что статус изменился
            if (sourceStatus === targetStatus) {
                showToast('Аниме уже находится в этой категории', 'info');
                return;
            }

            // Отправляем запрос на изменение статуса
            changeAnimeStatus(draggedCard.getAttribute('data-anime-id'), targetStatus, draggedCard);
        }

        // Функция для изменения статуса аниме
        function changeAnimeStatus(animeId, newStatus, cardElement) {
            console.log('changeAnimeStatus вызвана:', { animeId, newStatus });

            // Показываем индикатор загрузки на карточке
            cardElement.classList.add('removing');

            // Получаем CSRF токен
            const csrfToken = getCsrfToken();
            console.log('CSRF токен:', csrfToken);

            // Формируем данные для отправки
            const formData = new FormData();
            formData.append('anime_id', animeId);
            formData.append('anime_status', newStatus);
            formData.append('csrf_token', csrfToken);

            console.log('Отправляем AJAX запрос...');

            // Отправляем AJAX запрос
            fetch('change_status.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Ответ получен:', response.status, response.statusText);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Данные получены:', data);
                if (data.success) {
                    // Успешно изменили статус
                    showToast(data.message, 'success');

                    // ИСПРАВЛЕНИЕ: обновляем счетчики ДО удаления карточки
                    updateStatusCounts(newStatus);

                    // Удаляем карточку из текущей вкладки
                    setTimeout(() => {
                        cardElement.remove();
                        // Проверяем, нужно ли показать пустое сообщение
                        checkAndShowEmptyMessage();
                    }, 300);

                } else {
                    // Ошибка при изменении статуса
                    showToast(data.message || 'Произошла ошибка', 'error');
                    cardElement.classList.remove('removing');
                }
            })
            .catch(error => {
                console.error('Ошибка:', error);
                showToast('Произошла ошибка при изменении статуса', 'error');
                cardElement.classList.remove('removing');
            });
        }

        // Функция для получения CSRF токена
        function getCsrfToken() {
            const token = document.querySelector('meta[name="csrf-token"]');
            return token ? token.getAttribute('content') : null;
        }

        // Функция для обновления счетчиков статусов
        function updateStatusCounts(targetStatus = null) {
            // Уменьшаем счетчик текущей вкладки на 1
            const currentCountElement = document.getElementById(currentTab + '-count');
            if (currentCountElement) {
                const currentCount = parseInt(currentCountElement.textContent) || 0;
                currentCountElement.textContent = Math.max(0, currentCount - 1);
            }

            // Если указан целевой статус, увеличиваем его счетчик
            if (targetStatus && targetStatus !== currentTab) {
                const targetCountElement = document.getElementById(targetStatus + '-count');
                if (targetCountElement) {
                    const targetCount = parseInt(targetCountElement.textContent) || 0;
                    targetCountElement.textContent = targetCount + 1;
                }
            }
        }

        // Функция для проверки и показа пустого сообщения
        function checkAndShowEmptyMessage() {
            // Подсчитываем количество карточек в текущей вкладке ПОСЛЕ удаления
            const currentCards = document.querySelectorAll('.anime-card').length;

            if (currentCards === 0) {
                const container = document.getElementById('tab-content-container');
                const isOwnProfile = <?php echo $isOwnProfile ? 'true' : 'false'; ?>;
                const username = '<?php echo h($profileUser['username']); ?>';

                let message = '';
                switch (currentTab) {
                    case 'completed':
                        message = isOwnProfile ? 'Вы еще не посмотрели ни одного аниме.' : username + ' не посмотрел(а) ни одного аниме.';
                        break;
                    case 'planned':
                        message = isOwnProfile ? 'Вы еще не запланировали посмотреть ни одного аниме.' : username + ' не запланировал(а) посмотреть ни одного аниме.';
                        break;
                    case 'watching':
                        message = isOwnProfile ? 'Вы еще не начали смотреть ни одного аниме.' : username + ' не начал(а) смотреть ни одного аниме.';
                        break;
                    case 'dropped':
                        message = isOwnProfile ? 'Вы еще не бросили ни одного аниме.' : username + ' не бросил(а) ни одного аниме.';
                        break;
                }

                container.innerHTML = `<div class="profile-container"><p>${message}</p></div>`;
            }
        }

        // Функция для показа индикатора перетаскивания
        function showDragIndicator() {
            const indicator = document.getElementById('dragIndicator');
            if (indicator) {
                indicator.classList.add('show');
            }
        }

        // Функция для скрытия индикатора перетаскивания
        function hideDragIndicator() {
            const indicator = document.getElementById('dragIndicator');
            if (indicator) {
                indicator.classList.remove('show');
            }
        }

        <?php endif; ?>

        // Функция для показа toast уведомлений (общая для всех профилей)
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;

            container.appendChild(toast);

            // Показываем toast
            setTimeout(() => {
                toast.classList.add('show');
            }, 100);

            // Скрываем и удаляем toast через 3 секунды
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    if (container.contains(toast)) {
                        container.removeChild(toast);
                    }
                }, 300);
            }, 3000);
        }

        <?php if ($isOwnProfile): ?>
        // Функции для работы с модальным окном аватарки
        function openAvatarModal() {
            const modal = document.getElementById('avatarModal');
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        }

        function closeAvatarModal() {
            const modal = document.getElementById('avatarModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';

                // Сбрасываем предпросмотр
                const preview = document.getElementById('avatarPreview');
                const fileInput = document.getElementById('avatar');
                if (preview && fileInput) {
                    preview.innerHTML = '<div class="avatar-placeholder">Выберите файл</div>';
                    fileInput.value = '';
                }
            }
        }

        // Функция для предпросмотра аватарки
        function previewAvatar(input) {
            const preview = document.getElementById('avatarPreview');
            if (!preview) return;

            if (input.files && input.files[0]) {
                const file = input.files[0];

                // Проверяем размер файла (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    showToast('Файл слишком большой. Максимальный размер: 5MB', 'error');
                    input.value = '';
                    preview.innerHTML = '<div class="avatar-placeholder">Выберите файл</div>';
                    return;
                }

                // Проверяем тип файла
                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    showToast('Недопустимый тип файла. Разрешены: JPEG, PNG, GIF, WebP', 'error');
                    input.value = '';
                    preview.innerHTML = '<div class="avatar-placeholder">Выберите файл</div>';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Предпросмотр нового аватара">`;
                };
                reader.readAsDataURL(file);
            } else {
                preview.innerHTML = '<div class="avatar-placeholder">Выберите файл</div>';
            }
        }

        // Закрытие модального окна при клике вне его
        window.onclick = function(event) {
            const modal = document.getElementById('avatarModal');
            if (event.target === modal) {
                closeAvatarModal();
            }
        }

        // Закрытие модального окна по Escape
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('avatarModal');
                if (modal && modal.style.display === 'block') {
                    closeAvatarModal();
                }
            }
        });
        <?php endif; ?>

        <?php if ($isOwnProfile): ?>
        // Переопределяем функцию инициализации карточек для добавления drag-and-drop
        const originalInitAnimeCardHandlers = initAnimeCardHandlers;
        initAnimeCardHandlers = function() {
            console.log('initAnimeCardHandlers вызвана');
            originalInitAnimeCardHandlers();

            // Добавляем draggable атрибуты к новым карточкам
            const animeCards = document.querySelectorAll('.anime-card');
            console.log('Найдено карточек аниме:', animeCards.length);

            animeCards.forEach((card, index) => {
                if (!card.hasAttribute('draggable')) {
                    card.setAttribute('draggable', 'true');
                    console.log(`Добавлен draggable к карточке ${index + 1}`);
                }
                if (!card.hasAttribute('data-current-status')) {
                    card.setAttribute('data-current-status', currentTab);
                    console.log(`Добавлен data-current-status="${currentTab}" к карточке ${index + 1}`);
                }

                // Проверяем финальные атрибуты
                console.log(`Карточка ${index + 1}:`, {
                    draggable: card.getAttribute('draggable'),
                    currentStatus: card.getAttribute('data-current-status'),
                    animeId: card.getAttribute('data-anime-id')
                });
            });
        };
        <?php endif; ?>
    </script>
</body>
</html>
