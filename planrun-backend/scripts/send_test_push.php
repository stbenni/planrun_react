#!/usr/bin/env php
<?php
/**
 * Отправить тестовый push пользователю.
 * Запуск: php scripts/send_test_push.php <username|user_id>
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/PushNotificationService.php';

$arg = trim($argv[1] ?? '');
if ($arg === '') {
    echo "Использование: php scripts/send_test_push.php <username|user_id>\n";
    exit(1);
}

$db = getDBConnection();
if (!$db) {
    fwrite(STDERR, "Ошибка подключения к БД\n");
    exit(1);
}

$userId = null;
if (is_numeric($arg)) {
    $userId = (int) $arg;
} else {
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR username_slug = ?");
    $stmt->bind_param('ss', $arg, $arg);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $userId = (int) $row['id'];
    }
}

if (!$userId) {
    fwrite(STDERR, "Пользователь не найден: {$arg}\n");
    exit(1);
}

$push = new PushNotificationService($db);
$tokens = $push->getUserTokens($userId);
$allowed = $push->isPushAllowed($userId, 'chat');

if (empty($tokens)) {
    fwrite(STDERR, "Нет FCM-токенов у пользователя. Зарегистрируйте устройство через приложение.\n");
    exit(1);
}
if (!$allowed) {
    fwrite(STDERR, "push_chat_enabled отключено в настройках пользователя.\n");
    exit(1);
}

$ok = $push->sendToUser($userId, 'Тестовое уведомление', 'Это тестовый push от PlanRun. Всё работает!', [
    'type' => 'chat',
    'link' => '/chat'
]);

if ($ok) {
    echo "Push отправлен пользователю {$arg} (ID {$userId}), токенов: " . count($tokens) . "\n";
} else {
    fwrite(STDERR, "Firebase не инициализирован. Проверьте FIREBASE_CREDENTIALS или FIREBASE_CREDENTIALS_JSON в .env\n");
    exit(1);
}
