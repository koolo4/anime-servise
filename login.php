<?php
require_once 'config.php';

$error = '';

// Обработка AJAX запроса на обновление капчи
if (isset($_POST['action']) && $_POST['action'] === 'refresh_captcha') {
    refreshCaptchaAjax();
}

// Генерируем капчу при загрузке страницы
if (!isset($_SESSION['captcha_answer'])) {
    generateVisualCaptcha();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $captcha_answer = $_POST['captcha_answer'] ?? '';

    // Проверяем блокировку пользователя
    $blockInfo = isUserBlocked($username);
    if ($blockInfo) {
        $timeLeft = formatBlockedTime($blockInfo['blocked_until']);
        $error = "🚫 Аккаунт временно заблокирован из-за многократных неудачных попыток входа. Попробуйте через: $timeLeft";
        generateVisualCaptcha();
    } elseif (empty($username) || empty($password)) {
        $error = 'Заполните все поля';
        generateVisualCaptcha();
    } elseif (empty($captcha_answer)) {
        $error = 'Введите код капчи';
        generateVisualCaptcha();
    } elseif (!validateCaptcha($captcha_answer)) {
        $error = 'Неверный код капчи. Попробуйте снова.';
        generateVisualCaptcha();
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Успешный вход - сбрасываем попытки
            resetLoginAttempts($username);
            $_SESSION['user_id'] = $user['id'];
            // Очищаем капчу после успешного входа
            unset($_SESSION['captcha_answer']);
            unset($_SESSION['captcha_display']);
            redirect('index.php');
        } else {
            // Неудачная попытка - записываем её
            recordFailedLogin($username);

            // Проверяем, не заблокирован ли пользователь теперь
            $newBlockInfo = isUserBlocked($username);
            if ($newBlockInfo) {
                $blockDuration = getBlockDuration($newBlockInfo['level'] - 1);
                $error = "🚫 Слишком много неудачных попыток! Аккаунт заблокирован на $blockDuration мин.";
            } else {
                $nextBlockInfo = getNextBlockInfo($username);
                $error = "Неверное имя пользователя или пароль. $nextBlockInfo";
            }
            generateVisualCaptcha();
        }
    }
}

// Если пользователь уже авторизован, перенаправляем на главную
if (isLoggedIn()) {
    redirect('index.php');
}

// Получаем текущее отображение капчи
$captchaDisplay = getCaptchaDisplay();

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .captcha-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            text-align: center;
            color: white;
            font-weight: bold;
            border: 2px solid #5a67d8;
        }
        .captcha-question {
            font-size: 18px;
            margin-bottom: 10px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }
        .captcha-input {
            width: 80px !important;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            border: 2px solid #4c51bf;
            border-radius: 4px;
            padding: 8px;
        }
        .captcha-help {
            font-size: 12px;
            margin-top: 5px;
            opacity: 0.9;
        }

    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1 class="logo"><a href="index.php"><?php echo SITE_NAME; ?></a></h1>
            <nav class="nav">
                <a href="index.php">Главная</a>
                <a href="register.php">Регистрация</a>
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
                <h2>Вход в систему</h2>

                <?php if ($error): ?>
                    <div class="error"><?php echo h($error); ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="username">Имя пользователя или Email:</label>
                        <input type="text" id="username" name="username" required
                               value="<?php echo h($_POST['username'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="password">Пароль:</label>
                        <input type="password" id="password" name="password" required>
                    </div>

                    <!-- CAPTCHA -->
                    <div class="form-group">
                        <label for="captcha_answer">🔐 Защита от роботов</label>
                        <div class="captcha-container">
                            <div class="captcha-question" id="captcha-question">
                                <?php echo ($captchaDisplay ?? 'Обновите страницу'); ?>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: center; gap: 10px; margin: 10px 0;">
                                <input type="text" id="captcha_answer" name="captcha_answer"
                                       class="captcha-input" required placeholder="Введите код" maxlength="5"
                                       style="text-transform: uppercase; text-align: center; font-size: 18px; font-weight: bold; letter-spacing: 2px;">
                                <button type="button" onclick="refreshCaptcha()"
                                        style="background: #4c51bf; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 14px;"
                                        title="Обновить код">🔄</button>
                            </div>
                            <div class="captcha-help" id="captcha-help">
                                Введите 5 символов как показано выше
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Войти</button>
                </form>

                <p style="text-align: center; margin-top: 20px;">
                    Нет аккаунта? <a href="register.php">Зарегистрироваться</a>
                </p>


            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 <?php echo SITE_NAME; ?>. У вас нет прав.</p>
        </div>
    </footer>

    <script>
        function refreshCaptcha() {
            const questionDiv = document.getElementById('captcha-question');
            const captchaInput = document.getElementById('captcha_answer');
            const helpDiv = document.getElementById('captcha-help');

            // Показываем индикатор загрузки
            questionDiv.innerHTML = '<span style="color: #667eea; font-size: 18px;">🔄 Генерация нового кода...</span>';
            captchaInput.value = '';

            // Отправляем AJAX запрос
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=refresh_captcha'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        questionDiv.innerHTML = data.display;
                        helpDiv.innerHTML = data.instruction;
                    } else {
                        questionDiv.innerHTML = '<span style="color: #e53e3e;">Ошибка загрузки</span>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    questionDiv.innerHTML = '<span style="color: #e53e3e;">Ошибка загрузки</span>';
                    alert('Не удалось обновить капчу. Попробуйте перезагрузить страницу.');
                });
        }

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
    </script>
</body>
</html>
