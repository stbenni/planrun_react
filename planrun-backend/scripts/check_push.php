<?php
/**
 * Диагностика push-уведомлений.
 * Запуск: php planrun-backend/scripts/check_push.php [user_id|username]
 * Без аргумента — проверка инфраструктуры. С user_id или username — данные пользователя.
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/services/PushNotificationService.php';

$userId = null;
$db = getDBConnection();
if (isset($argv[1]) && $argv[1] !== '') {
    if (is_numeric($argv[1])) {
        $userId = (int) $argv[1];
    } else {
        $username = trim($argv[1]);
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR username_slug = ?");
        $stmt->bind_param('ss', $username, $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $userId = (int) $row['id'];
        } else {
            echo "Пользователь не найден: {$username}\n";
            exit(1);
        }
    }
}

echo "=== Push-диагностика ===\n\n";

// 1. Firebase
$credsPath = env('FIREBASE_CREDENTIALS', '');
$credsJson = env('FIREBASE_CREDENTIALS_JSON', '');
if (empty($credsPath) && empty($credsJson)) {
    echo "❌ FIREBASE_CREDENTIALS и FIREBASE_CREDENTIALS_JSON не заданы в .env\n";
} else {
    echo "✓ Firebase credentials заданы\n";
}

// 2. kreait/firebase-php
if (class_exists(\Kreait\Firebase\Factory::class)) {
    echo "✓ kreait/firebase-php установлен\n";
} else {
    echo "❌ kreait/firebase-php не найден. composer require kreait/firebase-php\n";
}

// 3. Таблица push_tokens
$res = $db->query("SELECT COUNT(*) as c FROM push_tokens");
$row = $res ? $res->fetch_assoc() : null;
$totalTokens = (int) ($row['c'] ?? 0);
echo "push_tokens: {$totalTokens} записей";
if ($totalTokens === 0) {
    echo " ❌ Нет зарегистрированных устройств — push не будут доходить\n";
    echo "  → Откройте приложение на телефоне (APK), войдите, подождите 5–10 сек.\n";
    echo "  → Токен регистрируется при первом входе после авторизации.\n";
} else {
    echo " ✓\n";
}

// 4. Колонка push_chat_enabled
$col = $db->query("SHOW COLUMNS FROM users LIKE 'push_chat_enabled'");
if ($col && $col->num_rows > 0) {
    echo "✓ users.push_chat_enabled существует\n";
} else {
    echo "❌ users.push_chat_enabled отсутствует. Запустите migrate_all.php\n";
}

if ($userId) {
    $stmt = $db->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $nameRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $name = $nameRow['username'] ?? null;
    echo "\n--- Пользователь " . ($name ? "{$name} (ID {$userId})" : "ID {$userId}") . " ---\n";
    $push = new PushNotificationService($db);
    $tokens = $push->getUserTokens($userId);
    echo "FCM-токенов: " . count($tokens) . "\n";
    if (empty($tokens)) {
        echo "❌ Нет токенов. Устройство должно зарегистрироваться при входе в приложение (Capacitor).\n";
    }
    $allowed = $push->isPushAllowed($userId, 'chat');
    echo "push_chat_enabled: " . ($allowed ? 'да' : 'нет (отключено в настройках)') . "\n";
}

echo "\n";
