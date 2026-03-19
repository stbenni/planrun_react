<?php
/**
 * Создать миниатюры для уже существующих локальных аватаров.
 *
 * Запуск:
 *   php scripts/backfill_avatar_variants.php
 */

require_once __DIR__ . '/../services/AvatarService.php';

$avatarDir = dirname(dirname(__DIR__)) . '/uploads/avatars/';

if (!is_dir($avatarDir)) {
    fwrite(STDERR, "Каталог uploads/avatars не найден: {$avatarDir}\n");
    exit(1);
}

$files = glob($avatarDir . 'avatar_*.*') ?: [];
$processed = 0;
$skipped = 0;
$failed = 0;

foreach ($files as $absolutePath) {
    $fileName = basename($absolutePath);

    if (strpos($fileName, '__') !== false) {
        $skipped++;
        continue;
    }

    if (!AvatarService::isManagedAvatarFileName($fileName)) {
        $skipped++;
        continue;
    }

    try {
        $variants = AvatarService::ensureAllVariantsForFileName($fileName);
        $processed++;
        echo sprintf("[ok] %s -> %d variant(s)\n", $fileName, count($variants));
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, sprintf("[fail] %s -> %s\n", $fileName, $e->getMessage()));
    }
}

echo sprintf(
    "Готово. processed=%d skipped=%d failed=%d\n",
    $processed,
    $skipped,
    $failed
);

exit($failed > 0 ? 1 : 0);
