<?php
require_once 'config.php';

// Проверяем авторизацию
if (!isLoggedIn()) {
    redirect('login.php');
}

$currentUser = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $uploadDir = 'uploads/avatars/';

    // Создаем папку для аватарок, если её нет
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {

            redirect('profile.php');
        }
    }

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] == 0) {
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        $fileType = $_FILES['avatar']['type'];
        $fileSize = $_FILES['avatar']['size'];

        // Проверяем тип файла
        if (!in_array($fileType, $allowedTypes)) {

            redirect('profile.php');
        }

        // Проверяем размер файла
        if ($fileSize > $maxSize) {

            redirect('profile.php');
        }

        // Проверяем, что это действительно изображение
        $imageInfo = getimagesize($_FILES['avatar']['tmp_name']);
        if (!$imageInfo) {

            redirect('profile.php');
        }

        // Генерируем уникальное имя файла
        $extension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $fileName = 'avatar_' . $currentUser['id'] . '_' . time() . '.' . $extension;
        $uploadPath = $uploadDir . $fileName;

        // Перемещаем файл
        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $uploadPath)) {
            // Удаляем старую аватарку
            if ($currentUser['avatar'] && file_exists($currentUser['avatar'])) {
                unlink($currentUser['avatar']);
            }

            // Обновляем путь к аватарке в базе данных
            $pdo = getDB();
            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $stmt->execute([$uploadPath, $currentUser['id']]);


        } else {

        }
    } else {

    }
}

if (isset($_POST['delete_avatar'])) {
    // Удаляем аватарку
    if ($currentUser['avatar'] && file_exists($currentUser['avatar'])) {
        unlink($currentUser['avatar']);
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
    $stmt->execute([$currentUser['id']]);


}

redirect('profile.php');
?>
