<?php
require_once 'config.php';

// Отправляем JSON заголовки
header('Content-Type: application/json');

// Проверяем авторизацию
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Требуется авторизация']);
    exit;
}

// Проверяем, что это POST запрос
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Неверный метод запроса']);
    exit;
}

// Проверка CSRF токена
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'CSRF токен недействителен']);
    exit;
}

// Получаем данные
$comment_id = (int)($_POST['comment_id'] ?? 0);
$like_type = $_POST['like_type'] ?? '';

// Валидация
if (!$comment_id || !in_array($like_type, ['like', 'dislike'])) {
    echo json_encode(['success' => false, 'message' => 'Неверные данные']);
    exit;
}

$currentUser = getCurrentUser();
$pdo = getDB();

try {
    // Проверяем, существует ли комментарий
    $stmt = $pdo->prepare("SELECT id FROM comments WHERE id = ?");
    $stmt->execute([$comment_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Комментарий не найден']);
        exit;
    }

    // Проверяем, есть ли уже лайк/дизлайк от этого пользователя
    $stmt = $pdo->prepare("SELECT like_type FROM comment_likes WHERE user_id = ? AND comment_id = ?");
    $stmt->execute([$currentUser['id'], $comment_id]);
    $existingLike = $stmt->fetch();

    if ($existingLike) {
        if ($existingLike['like_type'] === $like_type) {
            // Если пользователь ставит тот же лайк/дизлайк - убираем его
            $stmt = $pdo->prepare("DELETE FROM comment_likes WHERE user_id = ? AND comment_id = ?");
            $stmt->execute([$currentUser['id'], $comment_id]);
            $action = 'removed';
        } else {
            // Если пользователь меняет лайк на дизлайк или наоборот
            $stmt = $pdo->prepare("UPDATE comment_likes SET like_type = ? WHERE user_id = ? AND comment_id = ?");
            $stmt->execute([$like_type, $currentUser['id'], $comment_id]);
            $action = 'changed';
        }
    } else {
        // Добавляем новый лайк/дизлайк
        $stmt = $pdo->prepare("INSERT INTO comment_likes (user_id, comment_id, like_type) VALUES (?, ?, ?)");
        $stmt->execute([$currentUser['id'], $comment_id, $like_type]);
        $action = 'added';
    }

    // Получаем актуальные счетчики
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN like_type = 'like' THEN 1 ELSE 0 END) as likes_count,
            SUM(CASE WHEN like_type = 'dislike' THEN 1 ELSE 0 END) as dislikes_count
        FROM comment_likes
        WHERE comment_id = ?
    ");
    $stmt->execute([$comment_id]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    // Получаем текущий статус лайка пользователя
    $stmt = $pdo->prepare("SELECT like_type FROM comment_likes WHERE user_id = ? AND comment_id = ?");
    $stmt->execute([$currentUser['id'], $comment_id]);
    $userLike = $stmt->fetch();

    echo json_encode([
        'success' => true,
        'action' => $action,
        'likes_count' => (int)$counts['likes_count'],
        'dislikes_count' => (int)$counts['dislikes_count'],
        'user_like' => $userLike ? $userLike['like_type'] : null
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}
?>
