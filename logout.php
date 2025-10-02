<?php
require_once 'config.php';

// Полностью очищаем все переменные сессии
$_SESSION = array();

// Уничтожаем cookie сессии если он существует
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Уничтожаем сессию
session_destroy();

// Создаем новую сессию для сообщения
session_start();
$_SESSION['success_message'] = 'Вы успешно вышли из системы';

// Перенаправляем на главную страницу
redirect('index.php');
?>
