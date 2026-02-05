<?php
/**
 * Сервис отправки email
 * Поддерживает SMTP и PHP mail()
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

class EmailService {

    protected $host;
    protected $port;
    protected $username;
    protected $password;
    protected $encryption;
    protected $fromEmail;
    protected $fromName;
    protected $appUrl;
    protected $useSmtp;

    public function __construct() {
        if (!function_exists('env')) {
            require_once __DIR__ . '/../config/env_loader.php';
        }
        $this->host = env('MAIL_HOST', '');
        $this->port = (int) env('MAIL_PORT', 587);
        $this->username = env('MAIL_USERNAME', '');
        $this->password = env('MAIL_PASSWORD', '');
        $this->encryption = env('MAIL_ENCRYPTION', 'tls'); // tls, ssl, or empty
        $this->fromEmail = env('MAIL_FROM_ADDRESS', 'info@planrun.ru');
        $this->fromName = env('MAIL_FROM_NAME', 'PlanRun');
        $this->appUrl = rtrim(env('APP_URL', ''), '/');
        if (empty($this->appUrl) && isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $this->appUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        $this->useSmtp = !empty($this->host) && !empty($this->username);
    }

    /**
     * Отправить письмо
     * @param string $to Email получателя
     * @param string $subject Тема
     * @param string $bodyHtml HTML тело письма
     * @param string|null $bodyText Текстовое тело (опционально)
     * @return bool
     * @throws Exception
     */
    public function send($to, $subject, $bodyHtml, $bodyText = null) {
        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->Timeout = 10;
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $bodyHtml;
            if ($bodyText) {
                $mail->AltBody = $bodyText;
            }

            if ($this->useSmtp) {
                $mail->isSMTP();
                $mail->Host = $this->host;
                $mail->Port = $this->port;
                $mail->SMTPAuth = true;
                $mail->Username = $this->username;
                $mail->Password = $this->password;
                if ($this->encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($this->encryption === 'tls' || $this->encryption === 'starttls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }
                // Самоподписанный сертификат: в .env задать MAIL_VERIFY_PEER=0 (только для доверенной среды)
                $verifyPeer = function_exists('env') ? env('MAIL_VERIFY_PEER', '1') : '1';
                if ($verifyPeer === '0' || $verifyPeer === 'false') {
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ],
                    ];
                }
            } else {
                $mail->isMail();
            }

            $mail->send();
            return true;
        } catch (MailException $e) {
            throw new Exception('Не удалось отправить письмо. Попробуйте позже.');
        } catch (\Throwable $e) {
            throw new Exception('Не удалось отправить письмо. Попробуйте позже.');
        }
    }

    /**
     * Отправить письмо со ссылкой для сброса пароля
     */
    public function sendPasswordResetLink($toEmail, $username, $token, $expiresInMinutes = 60) {
        $resetUrl = $this->appUrl . '/reset-password?token=' . urlencode($token);
        $subject = 'Сброс пароля PlanRun';
        $bodyHtml = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family: sans-serif; line-height: 1.6; color: #333;">
  <p>Здравствуйте, ' . htmlspecialchars($username) . '!</p>
  <p>Вы запросили сброс пароля для аккаунта PlanRun.</p>
  <p>Перейдите по ссылке для установки нового пароля:</p>
  <p><a href="' . htmlspecialchars($resetUrl) . '" style="color: #2563eb; text-decoration: underline;">Сбросить пароль</a></p>
  <p>Ссылка действительна ' . $expiresInMinutes . ' минут.</p>
  <p>Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.</p>
  <p>— PlanRun</p>
</body>
</html>';
        $bodyText = "Здравствуйте!\n\nВы запросили сброс пароля PlanRun.\nСсылка: $resetUrl\nСсылка действительна $expiresInMinutes минут.\n\n— PlanRun";
        return $this->send($toEmail, $subject, $bodyHtml, $bodyText);
    }

    /**
     * Проверка: настроена ли отправка email
     */
    public function isConfigured() {
        return $this->useSmtp || function_exists('mail');
    }
}
