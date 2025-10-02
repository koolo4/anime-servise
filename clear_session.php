<?php
// Принудительная очистка сессии
session_start();

// Полностью очищаем все переменные сессии
$_SESSION = array();

// Уничтожаем cookie сессии
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Уничтожаем сессию
session_destroy();

// Выводим сообщение
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сессия очищена</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin-top: 100px;
            background: #f5f5f5;
        }
        .message {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 20px;
            border-radius: 5px;
            max-width: 500px;
            margin: 0 auto;
        }
        .button {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            display: inline-block;
        }
    </style>
</head>
<body>
    <div class="message">
        <h2>✅ Сессия успешно очищена!</h2>
        <p>Все данные авторизации удалены. Теперь вы можете перейти на страницы регистрации или входа.</p>
        <a href="register.php" class="button">Регистрация</a>
        <a href="login.php" class="button">Вход</a>
        <a href="index.php" class="button">Главная</a>
    </div>
</body>
</html>
