<?php
/**
 * Server-Sent Events (SSE) — универсальный real-time для чатов
 * Уведомления о непрочитанных сообщениях по всем типам: admin, ai, coach, direct
 * Держит соединение открытым и отправляет события при изменении счётчиков
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/session_init.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../planrun-backend/auth.php';
require_once __DIR__ . '/../planrun-backend/user_functions.php';
require_once __DIR__ . '/../planrun-backend/db_config.php';
require_once __DIR__ . '/../planrun-backend/config/constants.php';
require_once __DIR__ . '/../planrun-backend/repositories/ChatRepository.php';

// Только GET
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Проверка авторизации
if (!isAuthenticated()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = getCurrentUserId();
if (!$userId) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$currentUser = getCurrentUser();
$isAdmin = ($currentUser['role'] ?? '') === UserRoles::ADMIN;

// Освобождаем сессию, чтобы другие запросы могли её использовать
session_write_close();

$db = getDBConnection();
if (!$db) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error']);
    exit;
}

// SSE-заголовки
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Отключаем буферизацию
while (ob_get_level()) ob_end_clean();

set_time_limit(0);
ignore_user_abort(true);

$repository = new ChatRepository($db);
$lastSentJson = '';
$pingCounter = 0;

while (true) {
    $pingCounter++;
    $shouldPing = $pingCounter >= 15;

    if ($shouldPing) {
        if (!@$db->ping()) {
            break;
        }
    }

    try {
        $counts = $repository->getUnreadCounts((int)$userId);
        if ($isAdmin) {
            $adminModeUnread = $repository->getAdminUnreadCount();
            $counts['total'] = ($counts['total'] ?? 0) + $adminModeUnread;
            $counts['by_type'] = $counts['by_type'] ?? [];
            $counts['by_type']['admin_mode'] = $adminModeUnread;
        }
    } catch (Throwable $e) {
        break;
    }

    $dataJson = json_encode($counts);
    $shouldSend = ($dataJson !== $lastSentJson) || $shouldPing;

    if ($shouldSend) {
        echo "event: chat_unread\n";
        echo "data: {$dataJson}\n\n";
        if (!@flush()) {
            break;
        }

        if (connection_aborted()) {
            break;
        }

        $lastSentJson = $dataJson;
        if ($shouldPing) $pingCounter = 0;
    }

    sleep(2);
}

$db->close();
