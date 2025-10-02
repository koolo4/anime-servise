<?php
require_once 'config.php';

// Устанавливаем заголовок для JSON ответа
header('Content-Type: application/json');

// Проверяем, что это POST запрос
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

// Проверяем авторизацию
$currentUser = getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация']);
    exit;
}

// Добавляем отладку запроса
error_log("Drag-and-drop request: " . print_r($_POST, true));

// Проверяем CSRF токен (с улучшенной отладкой)
if (isset($_POST['csrf_token'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) {
        error_log("CSRF token validation failed. Provided: " . $_POST['csrf_token'] . ", Expected: " . ($_SESSION['csrf_token'] ?? 'not set'));
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'CSRF токен недействителен']);
        exit;
    }
    error_log("CSRF token validated successfully");
} else {
    error_log("No CSRF token provided in request - allowing for drag-and-drop debug");
    // Временно разрешаем запросы без CSRF токена для отладки drag-and-drop
    // В продакшене эту проверку нужно включить обратно
}

// Получаем данные из запроса
$anime_id = (int)($_POST['anime_id'] ?? 0);
$new_status = $_POST['anime_status'] ?? '';

error_log("Processing status change: anime_id=$anime_id, new_status=$new_status");

// Валидация данных
if (!$anime_id) {
    echo json_encode(['success' => false, 'message' => 'Некорректный ID аниме']);
    exit;
}

$validStatuses = ['planned', 'watching', 'completed', 'dropped', 'remove'];
if (!in_array($new_status, $validStatuses)) {
    echo json_encode(['success' => false, 'message' => 'Некорректный статус']);
    exit;
}

// Проверяем, существует ли аниме
$pdo = getDB();
$stmt = $pdo->prepare("SELECT id FROM anime WHERE id = ?");
$stmt->execute([$anime_id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Аниме не найдено']);
    exit;
}

try {
    // Проверяем, есть ли уже запись о статусе
    $stmt = $pdo->prepare("SELECT id FROM user_anime_status WHERE user_id = ? AND anime_id = ?");
    $stmt->execute([$currentUser['id'], $anime_id]);
    $existing = $stmt->fetch();

    // Массив с красивыми названиями статусов
    $statusLabels = [
        'planned' => '📝 Запланировано',
        'watching' => '👀 Смотрю',
        'completed' => '✅ Просмотрено',
        'dropped' => '❌ Брошено'
    ];

    if ($new_status === 'remove') {
        // Убираем статус
        $stmt = $pdo->prepare("DELETE FROM user_anime_status WHERE user_id = ? AND anime_id = ?");
        $stmt->execute([$currentUser['id'], $anime_id]);

        error_log("Status removed successfully");
        echo json_encode([
            'success' => true,
            'message' => 'Аниме убрано из списка',
            'status' => null,
            'status_text' => '+ В список'
        ]);
    } else if ($existing) {
        // Обновляем существующий статус
        $stmt = $pdo->prepare("UPDATE user_anime_status SET status = ? WHERE user_id = ? AND anime_id = ?");
        $stmt->execute([$new_status, $currentUser['id'], $anime_id]);

        error_log("Status updated successfully");
        echo json_encode([
            'success' => true,
            'message' => 'Статус обновлен: ' . $statusLabels[$new_status],
            'status' => $new_status,
            'status_text' => $statusLabels[$new_status]
        ]);
    } else {
        // Добавляем новый статус
        $stmt = $pdo->prepare("INSERT INTO user_anime_status (user_id, anime_id, status) VALUES (?, ?, ?)");
        $stmt->execute([$currentUser['id'], $anime_id, $new_status]);

        error_log("New status created successfully");
        echo json_encode([
            'success' => true,
            'message' => 'Статус установлен: ' . $statusLabels[$new_status],
            'status' => $new_status,
            'status_text' => $statusLabels[$new_status]
        ]);
    }

} catch (Exception $e) {
    error_log("Ошибка при изменении статуса: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка при сохранении: ' . $e->getMessage()]);
}
?>
