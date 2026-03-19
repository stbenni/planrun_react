<?php
/**
 * WarmupCooldownHelper — разминка/заминка по дистанции.
 *
 * Короткие дистанции (5k) получают укороченную разминку/заминку,
 * чтобы основная работа не терялась на фоне вспомогательного объёма.
 */

class WarmupCooldownHelper
{
    private const WARMUP = [
        '5k'       => 1.5,
        '10k'      => 1.75,
        'half'     => 2.0,
        'marathon' => 2.0,
    ];

    private const COOLDOWN = [
        '5k'       => 1.0,
        '10k'      => 1.25,
        'half'     => 1.5,
        'marathon' => 1.5,
    ];

    public static function warmup(string $raceDistance): float
    {
        return self::WARMUP[self::normalize($raceDistance)] ?? 2.0;
    }

    public static function cooldown(string $raceDistance): float
    {
        return self::COOLDOWN[self::normalize($raceDistance)] ?? 1.5;
    }

    private static function normalize(string $dist): string
    {
        $map = [
            'marathon' => 'marathon', '42.2k' => 'marathon', '42k' => 'marathon',
            'half' => 'half', '21.1k' => 'half', '21k' => 'half',
            '10k' => '10k', '10' => '10k',
            '5k' => '5k', '5' => '5k',
        ];
        return $map[strtolower($dist)] ?? 'half';
    }
}
