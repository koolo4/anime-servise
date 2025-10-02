<?php
require_once 'config.php';

// Проверяем права администратора
requireAdmin();

$anime_id = (int)($_GET['id'] ?? 0);

if (!$anime_id) {
    redirect('index.php');
}

$pdo = getDB();

// Проверяем, существует ли аниме
$stmt = $pdo->prepare("SELECT title FROM anime WHERE id = ?");
$stmt->execute([$anime_id]);
$anime = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$anime) {
    redirect('index.php');
}

// Удаляем аниме и все связанные данные (рейтинги и комментарии удалятся автоматически через FOREIGN KEY CASCADE)
$stmt = $pdo->prepare("DELETE FROM anime WHERE id = ?");

if ($stmt->execute([$anime_id])) {
    // Успешно удалено - редирект на главную с сообщением
    session_start();
    $_SESSION['success_message'] = 'Аниме "' . $anime['title'] . '" успешно удалено!';
    redirect('index.php');
} else {
    // Ошибка удаления
    session_start();
    $_SESSION['error_message'] = 'Ошибка при удалении аниме!';
    redirect('anime.php?id=' . $anime_id);
}
?>
