<?php
/**
 * ChatMediaService — хранение и раздача вложений чата (фото).
 * Файлы: /uploads/chat/chat_<userId>_<ts>_<token>.<ext>
 * Раздача — через api_v2 (get_chat_media), как у аватаров (не статикой).
 */

class ChatMediaService {
    private const SUBDIR = '/uploads/chat/';
    private const FILE_PATTERN = '/^chat_\d+_\d+_[a-f0-9]{8}\.(jpe?g|png|gif|webp|webm|ogg|m4a|mp3|mp4|wav)$/i';
    private const MAX_BYTES = 16 * 1024 * 1024; // 16 МБ (хватает на фото и голосовые)
    private const IMAGE_MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    // Аудио: тип объявляет клиент (MediaRecorder), серверный mime-детект для контейнеров ненадёжен.
    private const AUDIO_TYPE_EXT = [
        'audio/webm' => 'webm', 'video/webm' => 'webm',
        'audio/ogg' => 'ogg', 'audio/oga' => 'ogg',
        'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/aac' => 'm4a',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
    ];
    private const EXT_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
        'mp3' => 'audio/mpeg', 'mp4' => 'audio/mp4', 'wav' => 'audio/wav',
    ];

    public static function dir(): string {
        return dirname(dirname(__DIR__)) . self::SUBDIR;
    }

    public static function isValidFileName(string $name): bool {
        return preg_match(self::FILE_PATTERN, $name) === 1;
    }

    /**
     * Сохранить загруженное изображение. Возвращает дескриптор вложения.
     * @return array{file:string,kind:string,w:int,h:int}
     */
    public static function store(int $userId, array $file): array {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Файл не загружен');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Файл слишком большой (макс. 16 МБ)');
        }
        $tmp = $file['tmp_name'] ?? '';
        if (!$tmp || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('Некорректная загрузка');
        }

        // Изображение определяем надёжно через getimagesize; аудио — по заявленному типу.
        $kind = 'image';
        $width = 0;
        $height = 0;
        $info = @getimagesize($tmp);
        if ($info !== false && isset(self::IMAGE_MIME_EXT[$info['mime'] ?? ''])) {
            $ext = self::IMAGE_MIME_EXT[$info['mime']];
            $width = (int) ($info[0] ?? 0);
            $height = (int) ($info[1] ?? 0);
        } else {
            $type = strtolower(trim((string) ($file['type'] ?? '')));
            $type = explode(';', $type)[0]; // отрезаем "; codecs=opus"
            if (!isset(self::AUDIO_TYPE_EXT[$type])) {
                throw new InvalidArgumentException('Неподдерживаемый формат файла');
            }
            $kind = 'audio';
            $ext = self::AUDIO_TYPE_EXT[$type];
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог uploads/chat');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Каталог uploads/chat недоступен для записи');
        }

        $name = sprintf('chat_%d_%d_%s.%s', $userId, time(), bin2hex(random_bytes(4)), $ext);
        $dest = $dir . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Не удалось сохранить файл');
        }
        @chmod($dest, 0644);

        return ['file' => $name, 'kind' => $kind, 'w' => $width, 'h' => $height];
    }

    /**
     * Отдать файл вложения по имени. true при успешной отправке (метод делает exit-friendly readfile).
     */
    public static function serveRequested(string $requestedFile): bool {
        $name = basename(trim($requestedFile));
        if (!self::isValidFileName($name)) {
            return false;
        }
        $path = self::dir() . $name;
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = self::EXT_MIME[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        return true;
    }

    /**
     * Нормализовать дескриптор вложения из запроса клиента (доверяем только валидному имени файла,
     * который реально существует на сервере). Возвращает массив для metadata или null.
     */
    public static function sanitizeAttachment($attachment): ?array {
        if (!is_array($attachment)) return null;
        $name = isset($attachment['file']) ? basename((string) $attachment['file']) : '';
        if (!self::isValidFileName($name)) return null;
        if (!is_file(self::dir() . $name)) return null;
        $kind = ($attachment['kind'] ?? '') === 'audio' ? 'audio' : 'image';
        $out = ['kind' => $kind, 'file' => $name];
        if ($kind === 'audio') {
            $out['duration'] = max(0, (int) round((float) ($attachment['duration'] ?? 0)));
        } else {
            $out['w'] = max(0, (int) ($attachment['w'] ?? 0));
            $out['h'] = max(0, (int) ($attachment['h'] ?? 0));
        }
        return $out;
    }
}
