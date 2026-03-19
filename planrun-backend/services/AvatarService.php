<?php
/**
 * Сервис обработки и раздачи аватаров.
 *
 * Хранение:
 * - основной файл: /uploads/avatars/avatar_<userId>_<timestamp>_<token>.<ext>
 * - варианты:      /uploads/avatars/avatar_<...>__sm.<ext>, __md.<ext>, __lg.<ext>
 */

require_once __DIR__ . '/../config/Logger.php';

class AvatarService {
    private const LOCAL_PATH_PREFIX = '/uploads/avatars/';
    private const LOCAL_FILE_PATTERN = '/^avatar_\d+_\d+(?:_[a-f0-9]{8})?\.(?:jpe?g|png|gif|webp)$/i';
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];
    private const VARIANT_SIZES = [
        'sm' => 96,
        'md' => 256,
        'lg' => 384,
    ];
    private const MAIN_SIZE = 512;
    private const MAX_UPLOAD_BYTES = 5242880; // 5 MiB
    private const MAX_PIXELS = 40000000;
    private const JPEG_QUALITY = 86;
    private const WEBP_QUALITY = 84;

    /**
     * Обработать загруженный файл, нормализовать изображение и создать миниатюры.
     *
     * @param array $file Элемент из $_FILES
     * @param int $userId ID пользователя
     * @return array{avatar_path: string, variants: array<string, string>}
     */
    public static function storeUploadedAvatar(array $file, int $userId): array {
        self::assertImageSupport();

        if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Файл не загружен или произошла ошибка');
        }

        if (($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Размер файла превышает 5MB');
        }

        $dir = self::ensureAvatarDir();
        [$sourceImage, $meta] = self::loadImageFromPath($file['tmp_name']);

        $mainRelativePath = '';
        $variants = [];
        $squareImage = null;

        try {
            $baseName = self::buildBaseName($userId);
            $mainExtension = self::preferredOutputExtension();
            $mainFileName = $baseName . '.' . $mainExtension;
            $mainRelativePath = self::LOCAL_PATH_PREFIX . $mainFileName;
            $mainAbsolutePath = $dir . $mainFileName;

            $squareImage = self::cropToSquare($sourceImage, $meta['width'], $meta['height']);

            self::writeResizedImage($squareImage, self::MAIN_SIZE, $mainAbsolutePath);

            foreach (self::VARIANT_SIZES as $variant => $size) {
                $variantFileName = self::buildVariantFileName($mainFileName, $variant);
                $variantAbsolutePath = $dir . $variantFileName;
                self::writeResizedImage($squareImage, $size, $variantAbsolutePath);
                $variants[$variant] = self::LOCAL_PATH_PREFIX . $variantFileName;
            }
        } finally {
            self::destroyImage($squareImage);
            self::destroyImage($sourceImage);
        }

        return [
            'avatar_path' => $mainRelativePath,
            'variants' => $variants,
        ];
    }

    /**
     * Удалить локальный аватар и все связанные миниатюры.
     */
    public static function deleteAvatarByPath($avatarPath): void {
        $fileName = self::extractLocalFileName($avatarPath);
        if ($fileName === null) {
            return;
        }

        foreach (self::listManagedAbsolutePaths($fileName) as $absolutePath) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    /**
     * Нормализовать avatar_path перед записью в БД.
     *
     * @param mixed $avatarPath
     * @return array{valid: bool, value: ?string}
     */
    public static function normalizeStoredAvatarPath($avatarPath): array {
        if ($avatarPath === null) {
            return ['valid' => true, 'value' => null];
        }

        if (!is_string($avatarPath)) {
            return ['valid' => false, 'value' => null];
        }

        $trimmed = trim($avatarPath);
        if ($trimmed === '') {
            return ['valid' => true, 'value' => null];
        }

        if (preg_match('#^https?://#i', $trimmed) && filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return ['valid' => true, 'value' => $trimmed];
        }

        $fileName = self::extractLocalFileName($trimmed);
        if ($fileName !== null) {
            return ['valid' => true, 'value' => self::LOCAL_PATH_PREFIX . $fileName];
        }

        return ['valid' => false, 'value' => null];
    }

    /**
     * Раздать аватар или его миниатюру.
     *
     * Возвращает true при успешной отправке файла. В этом случае метод завершает
     * выполнение через exit после readfile().
     */
    public static function serveRequestedAvatar($requestedFile, $requestedVariant = null): bool {
        $fileName = self::extractLocalFileName($requestedFile);
        if ($fileName === null) {
            return false;
        }

        $variant = self::normalizeVariantName($requestedVariant);
        $mainAbsolutePath = self::absolutePathForFileName($fileName);
        if (!is_file($mainAbsolutePath) || !is_readable($mainAbsolutePath)) {
            return false;
        }

        $pathToServe = $mainAbsolutePath;
        if ($variant !== 'full') {
            $variantPath = self::ensureVariantForFileName($fileName, $variant);
            if ($variantPath !== null && is_file($variantPath) && is_readable($variantPath)) {
                $pathToServe = $variantPath;
            }
        }

        self::sendFileResponse($pathToServe);
        return true;
    }

    /**
     * Создать отсутствующие миниатюры для уже существующего локального аватара.
     *
     * @param string $fileName Имя основного файла аватара
     * @return array<string, string> variant => absolutePath
     */
    public static function ensureAllVariantsForFileName(string $fileName): array {
        if (!self::isManagedAvatarFileName($fileName)) {
            return [];
        }

        $paths = [];
        foreach (array_keys(self::VARIANT_SIZES) as $variant) {
            $variantPath = self::ensureVariantForFileName($fileName, $variant);
            if ($variantPath !== null && is_file($variantPath)) {
                $paths[$variant] = $variantPath;
            }
        }
        return $paths;
    }

    public static function isManagedAvatarFileName(string $fileName): bool {
        return preg_match(self::LOCAL_FILE_PATTERN, $fileName) === 1;
    }

    private static function ensureVariantForFileName(string $fileName, string $variant): ?string {
        if (!isset(self::VARIANT_SIZES[$variant])) {
            return null;
        }

        $mainAbsolutePath = self::absolutePathForFileName($fileName);
        if (!is_file($mainAbsolutePath) || !is_readable($mainAbsolutePath)) {
            return null;
        }

        $variantAbsolutePath = self::absolutePathForVariant($fileName, $variant);
        if (is_file($variantAbsolutePath) && is_readable($variantAbsolutePath)) {
            return $variantAbsolutePath;
        }

        [$sourceImage, $meta] = self::loadImageFromPath($mainAbsolutePath);
        $squareImage = null;

        try {
            $squareImage = self::cropToSquare($sourceImage, $meta['width'], $meta['height']);
            self::writeResizedImage($squareImage, self::VARIANT_SIZES[$variant], $variantAbsolutePath);
        } catch (Throwable $e) {
            Logger::warning('Не удалось создать вариант аватара на лету', [
                'file' => $fileName,
                'variant' => $variant,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            self::destroyImage($squareImage);
            self::destroyImage($sourceImage);
        }

        return is_file($variantAbsolutePath) ? $variantAbsolutePath : null;
    }

    /**
     * @return array{0: GdImage, 1: array{width: int, height: int, mime: string}}
     */
    private static function loadImageFromPath(string $path): array {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new InvalidArgumentException('Файл не является корректным изображением');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $mime = strtolower((string) ($info['mime'] ?? ''));

        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Не удалось определить размеры изображения');
        }

        if (($width * $height) > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Изображение слишком большое для безопасной обработки');
        }

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('Недопустимый тип файла. Разрешены только изображения (JPEG, PNG, GIF, WebP)');
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (!$image) {
            throw new RuntimeException('Не удалось декодировать изображение');
        }

        if (function_exists('imagepalettetotruecolor')) {
            @imagepalettetotruecolor($image);
        }
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $image = self::applyExifOrientation($image, $path, $mime);

        return [$image, [
            'width' => imagesx($image),
            'height' => imagesy($image),
            'mime' => $mime,
        ]];
    }

    private static function applyExifOrientation($image, string $path, string $mime) {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        switch ($orientation) {
            case 2:
                if (function_exists('imageflip')) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                break;
            case 3:
                $image = imagerotate($image, 180, 0);
                break;
            case 4:
                if (function_exists('imageflip')) {
                    imageflip($image, IMG_FLIP_VERTICAL);
                }
                break;
            case 5:
                $image = imagerotate($image, -90, 0);
                if (function_exists('imageflip')) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                break;
            case 6:
                $image = imagerotate($image, -90, 0);
                break;
            case 7:
                $image = imagerotate($image, 90, 0);
                if (function_exists('imageflip')) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                break;
            case 8:
                $image = imagerotate($image, 90, 0);
                break;
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);
        return $image;
    }

    private static function cropToSquare($sourceImage, int $width, int $height) {
        $side = min($width, $height);
        $srcX = (int) floor(($width - $side) / 2);
        $srcY = (int) floor(($height - $side) / 2);

        $square = self::createTransparentCanvas($side, $side);
        if (!imagecopyresampled($square, $sourceImage, 0, 0, $srcX, $srcY, $side, $side, $side, $side)) {
            imagedestroy($square);
            throw new RuntimeException('Не удалось подготовить квадратный аватар');
        }

        return $square;
    }

    private static function writeResizedImage($sourceImage, int $targetSize, string $absolutePath): void {
        $resized = self::createTransparentCanvas($targetSize, $targetSize);

        try {
            $sourceSize = imagesx($sourceImage);
            if (!imagecopyresampled(
                $resized,
                $sourceImage,
                0,
                0,
                0,
                0,
                $targetSize,
                $targetSize,
                $sourceSize,
                $sourceSize
            )) {
                throw new RuntimeException('Не удалось изменить размер аватара');
            }

            self::saveImageAtomically($resized, $absolutePath);
        } finally {
            self::destroyImage($resized);
        }
    }

    private static function saveImageAtomically($image, string $absolutePath): void {
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог для аватаров');
        }

        $tmpPath = $absolutePath . '.tmp-' . bin2hex(random_bytes(4));
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $saved = match ($extension) {
            'webp' => function_exists('imagewebp') ? @imagewebp($image, $tmpPath, self::WEBP_QUALITY) : false,
            'png' => @imagepng($image, $tmpPath, 6),
            'jpg', 'jpeg' => self::saveJpegWithBackground($image, $tmpPath),
            default => false,
        };

        if (!$saved || !is_file($tmpPath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Не удалось сохранить аватар');
        }

        if (!@rename($tmpPath, $absolutePath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Не удалось опубликовать аватар');
        }

        @chmod($absolutePath, 0644);
    }

    private static function saveJpegWithBackground($image, string $path): bool {
        $width = imagesx($image);
        $height = imagesy($image);
        $background = imagecreatetruecolor($width, $height);
        if (!$background) {
            return false;
        }

        try {
            $white = imagecolorallocate($background, 255, 255, 255);
            imagefilledrectangle($background, 0, 0, $width, $height, $white);
            imagecopy($background, $image, 0, 0, 0, 0, $width, $height);
            return @imagejpeg($background, $path, self::JPEG_QUALITY);
        } finally {
            imagedestroy($background);
        }
    }

    private static function createTransparentCanvas(int $width, int $height) {
        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas) {
            throw new RuntimeException('Не удалось создать изображение в памяти');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);

        return $canvas;
    }

    private static function sendFileResponse(string $absolutePath): void {
        $mtime = filemtime($absolutePath) ?: time();
        $size = filesize($absolutePath);
        $etag = '"' . sha1($absolutePath . '|' . $mtime . '|' . $size) . '"';
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag)
            || (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && trim((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) === $lastModified)) {
            http_response_code(304);
            header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $lastModified);
            exit;
        }

        header('Content-Type: ' . self::mimeByPath($absolutePath));
        header('Content-Length: ' . (string) $size);
        header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModified);
        header('X-Content-Type-Options: nosniff');
        readfile($absolutePath);
        exit;
    }

    private static function mimeByPath(string $absolutePath): string {
        return match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private static function buildBaseName(int $userId): string {
        return 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4));
    }

    private static function buildVariantFileName(string $mainFileName, string $variant): string {
        $base = pathinfo($mainFileName, PATHINFO_FILENAME);
        return $base . '__' . $variant . '.' . self::preferredOutputExtension();
    }

    private static function absolutePathForVariant(string $mainFileName, string $variant): string {
        return self::avatarDir() . self::buildVariantFileName($mainFileName, $variant);
    }

    private static function absolutePathForFileName(string $fileName): string {
        return self::avatarDir() . $fileName;
    }

    /**
     * @return string[]
     */
    private static function listManagedAbsolutePaths(string $mainFileName): array {
        $paths = [self::absolutePathForFileName($mainFileName)];
        $base = pathinfo($mainFileName, PATHINFO_FILENAME);

        foreach (array_keys(self::VARIANT_SIZES) as $variant) {
            foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $extension) {
                $paths[] = self::avatarDir() . $base . '__' . $variant . '.' . $extension;
            }
        }

        return array_values(array_unique($paths));
    }

    private static function extractLocalFileName($avatarPath): ?string {
        if (!is_string($avatarPath)) {
            return null;
        }

        $trimmed = trim($avatarPath);
        if ($trimmed === '' || preg_match('#^https?://#i', $trimmed)) {
            return null;
        }

        $fileName = basename($trimmed);
        return self::isManagedAvatarFileName($fileName) ? $fileName : null;
    }

    private static function normalizeVariantName($variant): string {
        if (!is_string($variant)) {
            return 'full';
        }

        $normalized = strtolower(trim($variant));
        return isset(self::VARIANT_SIZES[$normalized]) ? $normalized : 'full';
    }

    private static function preferredOutputExtension(): string {
        return function_exists('imagewebp') ? 'webp' : 'jpg';
    }

    private static function ensureAvatarDir(): string {
        $dir = self::avatarDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог uploads/avatars');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Каталог uploads/avatars недоступен для записи');
        }
        return $dir;
    }

    private static function avatarDir(): string {
        return dirname(dirname(__DIR__)) . '/uploads/avatars/';
    }

    private static function assertImageSupport(): void {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is required for avatar processing');
        }
    }

    private static function destroyImage($image): void {
        if ($image instanceof GdImage) {
            imagedestroy($image);
        }
    }
}
