#!/usr/bin/env php
<?php
/**
 * Отправка уведомлений НАСТОЯЩИМИ продюсерами (AI-тренер / администрация / тренер),
 * чтобы проверить единую систему «как в проде»: реальный флоу пишет и в чат, и в «колокол»,
 * и доставляет push по настройкам. Запуск: php scripts/send_real_test_notifications.php [userId]
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/env_loader.php';
require_once $baseDir . '/db_config.php';
require_once $baseDir . '/services/ChatService.php';
require_once $baseDir . '/services/PlanNotificationService.php';

$db = getDBConnection();
if (!$db) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$userId = (int) ($argv[1] ?? 1);
$today = date('Y-m-d');

$chat = new ChatService($db);
$plan = new PlanNotificationService($db);

echo "Реальная отправка через единую систему → user {$userId}\n\n";

// 1) AI-тренер РЕАЛЬНО пишет сообщение (chat.ai_message) → чат + колокол + push
$chat->addAIMessageToUser($userId, 'Привет! 🤖 Это твой AI-тренер. Проверяю единую систему уведомлений — это сообщение пришло и в чат, и в «колокол».');
echo "  ✓ AI-тренер написал в чат            (chat.ai_message)\n";

// 2) Сообщение от администрации (chat.admin_message)
$chat->sendAdminMessage($userId, $userId, 'Сообщение от администрации — реальная доставка через единую систему уведомлений.');
echo "  ✓ Сообщение от администрации          (chat.admin_message)\n";

// 3) Реальное обновление плана от тренера (plan.coach_updated)
$plan->notifyCoachPlanUpdated($userId, $userId, 'update', $today);
echo "  ✓ Тренер обновил план                 (plan.coach_updated)\n";

$unread = (int) ($db->query("SELECT COUNT(*) c FROM plan_notifications WHERE user_id={$userId} AND read_at IS NULL")->fetch_assoc()['c']);
echo "\nНепрочитанных в колоколе: {$unread}. Открой чат и «колокол» — всё пришло настоящими флоу.\n";
