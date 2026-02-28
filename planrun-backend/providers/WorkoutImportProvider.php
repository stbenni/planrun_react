<?php
/**
 * Интерфейс провайдера импорта тренировок (Huawei, Garmin, Strava и др.)
 */
interface WorkoutImportProvider {
    /**
     * ID провайдера: huawei, garmin, strava, polar
     */
    public function getProviderId(): string;

    /**
     * URL для OAuth авторизации. null если провайдер не использует OAuth (например GPX upload)
     */
    public function getOAuthUrl(string $state): ?string;

    /**
     * Обмен authorization code на токены. Сохраняет в integration_tokens.
     * @return array ['access_token', 'refresh_token', 'expires_at']
     */
    public function exchangeCodeForTokens(string $code, string $state): array;

    /**
     * Обновить access_token по refresh_token
     */
    public function refreshToken(int $userId): bool;

    /**
     * Получить тренировки за период
     * @return array Массив в формате [activity_type, start_time, end_time, duration_minutes, distance_km, avg_pace, avg_heart_rate, max_heart_rate, elevation_gain, external_id]
     */
    public function fetchWorkouts(int $userId, string $startDate, string $endDate): array;

    /**
     * Подключен ли провайдер для пользователя
     */
    public function isConnected(int $userId): bool;

    /**
     * Отвязать провайдера
     */
    public function disconnect(int $userId): void;
}
