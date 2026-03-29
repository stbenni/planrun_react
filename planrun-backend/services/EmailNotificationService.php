<?php

require_once __DIR__ . '/BaseService.php';
require_once __DIR__ . '/EmailService.php';
require_once __DIR__ . '/../config/env_loader.php';

class EmailNotificationService extends BaseService {
    private function getUserEmail(int $userId): string {
        $stmt = $this->db->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return trim((string) ($user['email'] ?? ''));
    }

    private function buildActionUrl(string $rawLink): string {
        $appUrl = rtrim((string) env('APP_URL', ''), '/');
        if ($rawLink === '') {
            return '';
        }
        return preg_match('#^https?://#i', $rawLink)
            ? $rawLink
            : ($appUrl !== '' ? $appUrl . $rawLink : $rawLink);
    }

    public function sendToUser(int $userId, string $subject, string $body, array $options = []): bool {
        $email = $this->getUserEmail($userId);
        if ($email === '') {
            return false;
        }

        $mailer = new EmailService();
        if (!$mailer->isConfigured()) {
            return false;
        }

        $rawLink = trim((string) ($options['link'] ?? ''));
        $actionUrl = $this->buildActionUrl($rawLink);
        $actionLabel = trim((string) ($options['action_label'] ?? 'Открыть в PlanRun'));
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: sans-serif; line-height: 1.6; color: #1f2937;">';
        $html .= '<p><strong>' . $safeSubject . '</strong></p>';
        $html .= '<p>' . $safeBody . '</p>';
        if ($actionUrl !== '') {
            $html .= '<p><a href="' . htmlspecialchars($actionUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="color: #2563eb; text-decoration: underline;">' . htmlspecialchars($actionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';
        }
        $html .= '<p>PlanRun</p>';
        $html .= '</body></html>';

        $text = $subject . "\n\n" . $body;
        if ($actionUrl !== '') {
            $text .= "\n\n" . $actionLabel . ': ' . $actionUrl;
        }
        $text .= "\n\nPlanRun";

        try {
            return $mailer->send($email, $subject, $html, $text);
        } catch (Throwable $e) {
            Logger::warning('Email notification failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'subject' => $subject,
            ]);
            return false;
        }
    }

    public function sendDailyDigestToUser(int $userId, array $items, array $options = []): bool {
        $email = $this->getUserEmail($userId);
        if ($email === '' || empty($items)) {
            return false;
        }

        $mailer = new EmailService();
        if (!$mailer->isConfigured()) {
            return false;
        }

        $count = count($items);
        $subject = $count === 1
            ? 'Ежедневный дайджест PlanRun: 1 уведомление'
            : 'Ежедневный дайджест PlanRun: ' . $count . ' уведомлений';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family: sans-serif; line-height: 1.6; color: #1f2937;">';
        $html .= '<p><strong>' . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p>';
        $html .= '<p>Собрали для вас важные события за последнее время:</p>';
        $html .= '<ul style="padding-left: 20px;">';

        $textParts = [$subject, '', 'Собрали для вас важные события за последнее время:'];

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? 'Уведомление PlanRun'));
            $body = trim((string) ($item['body'] ?? ''));
            $link = $this->buildActionUrl(trim((string) ($item['link'] ?? '')));
            $actionLabel = 'Открыть в PlanRun';

            $html .= '<li style="margin-bottom: 16px;">';
            $html .= '<div><strong>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></div>';
            if ($body !== '') {
                $html .= '<div>' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</div>';
            }
            if ($link !== '') {
                $html .= '<div><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="color: #2563eb; text-decoration: underline;">' . htmlspecialchars($actionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></div>';
            }
            $html .= '</li>';

            $textParts[] = '- ' . $title;
            if ($body !== '') {
                $textParts[] = '  ' . $body;
            }
            if ($link !== '') {
                $textParts[] = '  ' . $actionLabel . ': ' . $link;
            }
        }

        $html .= '</ul><p>PlanRun</p></body></html>';
        $textParts[] = '';
        $textParts[] = 'PlanRun';
        $text = implode("\n", $textParts);

        try {
            return $mailer->send($email, $subject, $html, $text);
        } catch (Throwable $e) {
            Logger::warning('Email digest failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'items' => $count,
            ]);
            return false;
        }
    }
}
