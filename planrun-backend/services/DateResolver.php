<?php
/**
 * Резолвер дат: преобразует естественные выражения («завтра», «в среду», «через неделю»)
 * в Y-m-d. Использует timezone пользователя.
 */

class DateResolver {

    private static $dayNames = [
        'понедельник' => 1, 'пн' => 1, 'вторник' => 2, 'вт' => 2,
        'среда' => 3, 'ср' => 3, 'четверг' => 4, 'чт' => 4,
        'пятница' => 5, 'пт' => 5, 'суббота' => 6, 'сб' => 6,
        'воскресенье' => 7, 'вс' => 7,
    ];

    private static $monthNames = [
        'января' => 1, 'январь' => 1, 'февраля' => 2, 'февраль' => 2,
        'марта' => 3, 'март' => 3, 'апреля' => 4, 'апрель' => 4,
        'мая' => 5, 'июня' => 6, 'июнь' => 6, 'июля' => 7, 'июль' => 7,
        'августа' => 8, 'август' => 8, 'сентября' => 9, 'сентябрь' => 9,
        'октября' => 10, 'октябрь' => 10, 'ноября' => 11, 'ноябрь' => 11,
        'декабря' => 12, 'декабрь' => 12,
    ];

    /**
     * Пытается извлечь и разрешить дату из текста.
     * @param string $text Сообщение пользователя
     * @param DateTime $relativeTo Базовая дата (сегодня пользователя)
     * @return string|null Y-m-d или null
     */
    public function resolveFromText(string $text, DateTime $relativeTo): ?string {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        // Завтра, послезавтра, сегодня
        if (preg_match('/\bзавтра\b/u', $text)) {
            return (clone $relativeTo)->modify('+1 day')->format('Y-m-d');
        }
        if (preg_match('/\bпослезавтра\b/u', $text)) {
            return (clone $relativeTo)->modify('+2 days')->format('Y-m-d');
        }
        if (preg_match('/\bсегодня\b/u', $text)) {
            return $relativeTo->format('Y-m-d');
        }

        // Через N дней
        if (preg_match('/через\s+(\d+)\s+дн[ьяей]/u', $text, $m)) {
            $n = (int) $m[1];
            if ($n >= 0 && $n <= 365) {
                return (clone $relativeTo)->modify("+{$n} days")->format('Y-m-d');
            }
        }

        // Через неделю
        if (preg_match('/через\s+неделю/u', $text)) {
            return (clone $relativeTo)->modify('+1 week')->format('Y-m-d');
        }

        // В понедельник, на среду, следующую пятницу
        foreach (self::$dayNames as $name => $dow) {
            if (!preg_match('/\b' . preg_quote($name, '/') . '\b/u', $text)) {
                continue;
            }
            $isNext = (bool) preg_match('/следующ[ийуюае]+\s+' . preg_quote($name, '/') . '|' . preg_quote($name, '/') . '\s+на\s+следующей/u', $text)
                || preg_match('/на\s+следующ[ийуюае]+\s+' . preg_quote($name, '/') . '/u', $text);
            $base = clone $relativeTo;
            $base->setTime(0, 0, 0);
            $currentDow = (int) $base->format('N');
            $daysToAdd = $dow - $currentDow;
            if ($daysToAdd < 0) {
                $daysToAdd += 7;
            }
            if ($isNext) {
                $daysToAdd += 7;
            } elseif ($daysToAdd === 0) {
                return $base->format('Y-m-d');
            }
            return $base->modify("+{$daysToAdd} days")->format('Y-m-d');
        }

        // 15 февраля, 3 марта 2026
        if (preg_match('/(\d{1,2})\s+(' . implode('|', array_keys(self::$monthNames)) . ')(?:\s+(\d{4}))?/u', $text, $m)) {
            $day = (int) $m[1];
            $month = self::$monthNames[$m[2]] ?? null;
            $year = isset($m[3]) ? (int) $m[3] : (int) $relativeTo->format('Y');
            if ($month && $day >= 1 && $day <= 31 && $year >= 2020 && $year <= 2030) {
                $d = sprintf('%04d-%02d-%02d', $year, $month, $day);
                if (checkdate($month, $day, $year)) {
                    return $d;
                }
            }
        }

        return null;
    }

    /**
     * Проверяет, есть ли в тексте отсылка к дате (для добавления тренировки и т.п.)
     */
    public function hasDateReference(string $text): bool {
        $text = mb_strtolower($text);
        $patterns = [
            '/\bзавтра\b/u', '/\bпослезавтра\b/u', '/\bсегодня\b/u',
            '/через\s+\d+\s+дн/u', '/через\s+неделю/u',
            '/\b(понедельник|вторник|среда|четверг|пятница|суббота|воскресенье|пн|вт|ср|чт|пт|сб|вс)\b/u',
            '/\d{1,2}\s+(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)/u',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $text)) {
                return true;
            }
        }
        return false;
    }
}
